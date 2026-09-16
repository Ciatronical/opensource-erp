#!/usr/bin/env python3
"""
Eigenes Erkennungsmodell (CRNN + CTC) fuer OCR-B-Zeilen der Zulassungsbescheinigung.

Daten: echte Ausschnitte mit Text (train_pairs.json) + synthetische Zeilen aus der
Glyphenbank. Eingabe Graubild Hoehe 32, variable Breite. Ausgabe: Zeichenfolge.
CPU-tauglich (kleines Netz), Export als ONNX fuer den Dienst.

Aufruf:
  ocr_train/train_crnn.py --pairs train_pairs.json --bank glyphs_clean.npz --out crnn.onnx
      [--epochs 12] [--synth 40000]
"""
import argparse
import json
import os
import random
import sys
import time

import cv2
import numpy as np
import torch
import torch.nn as nn
import torch.nn.functional as F

sys.path.insert(0, os.path.dirname(__file__))
from synth import Synth, H  # noqa: E402

CHARS = " !\"#$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_abcdefghijklmnopqrstuvwxyzÄÖÜßäöü"
C2I = {c: i + 1 for i, c in enumerate(CHARS)}  # 0 = CTC-Blank


def encode(text):
    return [C2I[c] for c in text if c in C2I]


def load_real(pairs_path, val_frac=0.1):
    pairs = json.load(open(pairs_path))
    rng = random.Random(7)
    # Validierung dokumentweise trennen (Ordner)
    docs = sorted(set(os.path.dirname(p) for p, _ in pairs))
    rng.shuffle(docs)
    val_docs = set(docs[:max(1, int(val_frac * len(docs)))])
    tr, va = [], []
    for p, t in pairs:
        img = cv2.imread(p)
        if img is None:
            continue
        gray = np.max(img, axis=2)
        gray = cv2.resize(gray, (max(8, int(gray.shape[1] * H / gray.shape[0])), H), interpolation=cv2.INTER_AREA)
        (va if os.path.dirname(p) in val_docs else tr).append((gray, t))
    return tr, va


def augment_real(gray, rng):
    img = gray.astype(np.float32) * rng.uniform(0.8, 1.15) + rng.uniform(-20, 20)
    if rng.random() < 0.4:
        img = cv2.GaussianBlur(img, (0, 0), rng.uniform(0.3, 1.0))
    img = img + np.random.normal(0, rng.uniform(0, 6), img.shape)
    if rng.random() < 0.3:
        pad = rng.randrange(0, 8)
        img = cv2.copyMakeBorder(img, 0, 0, pad, rng.randrange(0, 8), cv2.BORDER_REPLICATE)
        img = cv2.resize(img, (max(8, int(img.shape[1] * H / img.shape[0])), H))
    return np.clip(img, 0, 255).astype(np.uint8)


class CRNN(nn.Module):
    def __init__(self, n_classes):
        super().__init__()
        def block(i, o, pool):
            layers = [nn.Conv2d(i, o, 3, padding=1), nn.BatchNorm2d(o), nn.ReLU(inplace=True)]
            if pool:
                layers.append(nn.MaxPool2d(pool))
            return layers
        # klein gehalten fuer CPU-Training: ~0,3 M Parameter
        self.cnn = nn.Sequential(
            *block(1, 24, (2, 2)),     # 16 x W/2
            *block(24, 48, (2, 2)),    # 8 x W/4
            *block(48, 96, (2, 1)),    # 4 x W/4
            *block(96, 96, (2, 1)),    # 2 x W/4
            *block(96, 128, (2, 1)),   # 1 x W/4
        )
        self.rnn = nn.LSTM(128, 96, num_layers=2, bidirectional=True, batch_first=True, dropout=0.1)
        self.fc = nn.Linear(192, n_classes)

    def forward(self, x):            # x: B x 1 x 32 x W
        f = self.cnn(x)              # B x C x 1 x W/4
        f = f.squeeze(2).permute(0, 2, 1)  # B x T x C
        out, _ = self.rnn(f)
        return self.fc(out)          # B x T x classes


def batchify(samples):
    """samples: Liste (gray HxW, text) -> Tensor B x 1 x H x Wmax (rechts aufgefuellt), Ziele."""
    wmax = max(s[0].shape[1] for s in samples)
    wmax = int(np.ceil(wmax / 4) * 4)
    x = np.full((len(samples), 1, H, wmax), 0.5, np.float32)
    targets, lengths = [], []
    for i, (g, t) in enumerate(samples):
        w = g.shape[1]
        x[i, 0, :, :w] = (255 - g.astype(np.float32)) / 255.0  # Tinte = hoch
        x[i, 0, :, w:] = 0.0
        enc = encode(t)
        targets.extend(enc)
        lengths.append(len(enc))
    return torch.from_numpy(x), torch.tensor(targets, dtype=torch.long), torch.tensor(lengths, dtype=torch.long)


def decode(logits):
    """Greedy-CTC-Dekodierung: B x T x C -> Texte."""
    pred = logits.argmax(dim=2).cpu().numpy()
    texts = []
    for seq in pred:
        out, prev = [], 0
        for p in seq:
            if p != prev and p != 0:
                out.append(CHARS[p - 1])
            prev = p
        texts.append("".join(out))
    return texts


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--pairs", required=True)
    ap.add_argument("--bank", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--epochs", type=int, default=12)
    ap.add_argument("--synth", type=int, default=40000)
    ap.add_argument("--batch", type=int, default=64)
    ap.add_argument("--threads", type=int, default=8)
    ap.add_argument("--resume", default=None, help="Gewichte (.pt) zum Weitertrainieren")
    ap.add_argument("--lr", type=float, default=1e-3)
    ap.add_argument("--real-rep", type=int, default=3, help="Wiederholungen der echten Paare je Epoche")
    args = ap.parse_args()
    torch.set_num_threads(args.threads)
    rng = random.Random(3)

    tr, va = load_real(args.pairs)
    words = sorted(set(w for _, t in tr for w in t.split() if len(w) >= 3 and w.isalpha()))
    synth = Synth(args.bank, words)
    print(f"echt: {len(tr)} Training / {len(va)} Validierung, Woerter: {len(words)}, synthetisch: {args.synth}", flush=True)

    model = CRNN(len(CHARS) + 1)
    if args.resume and os.path.isfile(args.resume):
        model.load_state_dict(torch.load(args.resume, map_location="cpu"))
        print("weiter ab", args.resume, flush=True)
    opt = torch.optim.Adam(model.parameters(), lr=args.lr)
    ctc = nn.CTCLoss(blank=0, zero_infinity=True)
    n_per_epoch = args.synth + args.real_rep * len(tr)
    # konstante Lernrate, am Ende abgesenkt (wenige Schritte auf CPU -> kein langer Warmup)
    total = args.epochs * (n_per_epoch // args.batch + 1)
    sched = torch.optim.lr_scheduler.LambdaLR(opt, lambda st: 1.0 if st < 0.7 * total else 0.3)

    def val():
        model.eval()
        ok, tot = 0, 0
        with torch.no_grad():
            for i in range(0, len(va), 64):
                chunk = va[i:i + 64]
                x, _, _ = batchify(chunk)
                texts = decode(model(x))
                for (g, t), p in zip(chunk, texts):
                    ok += (p.replace(" ", "") == t.replace(" ", "")); tot += 1
        model.train()
        return ok / max(1, tot)

    step = 0
    for ep in range(args.epochs):
        t0 = time.time()
        # Epoche: synthetische Zeilen + echte (3x, augmentiert), gemischt, nach Breite gebuckelt
        samples = [("s", None)] * args.synth + [("r", i) for i in range(len(tr)) for _ in range(args.real_rep)]
        rng.shuffle(samples)
        losses = []
        # Bilder in Bloecken erzeugen, nach Breite sortieren und daraus Batches bilden
        # (weniger Auffuellung -> deutlich schnellere Schritte)
        chunk = args.batch * 16
        for ci in range(0, len(samples), chunk):
            prepared = []
            for kind, idx in samples[ci:ci + chunk]:
                if kind == "s":
                    t = synth.text()
                    if not t.strip():
                        continue
                    prepared.append((synth.render(t), t))
                else:
                    g, t = tr[idx]
                    prepared.append((augment_real(g, rng), t))
            prepared = [b for b in prepared if len(encode(b[1])) > 0 and b[0].shape[1] // 4 >= len(encode(b[1]))
                        and b[0].shape[1] <= 480]
            prepared.sort(key=lambda b: b[0].shape[1])
            batches = [prepared[i:i + args.batch] for i in range(0, len(prepared), args.batch)]
            rng.shuffle(batches)
            for batch in batches:
                if not batch:
                    continue
                self_batch = batch
                x, targets, lengths = batchify(batch)
                logits = model(x)                         # B x T x C
                logp = F.log_softmax(logits, dim=2).permute(1, 0, 2)  # T x B x C
                in_len = torch.full((x.shape[0],), logits.shape[1], dtype=torch.long)
                loss = ctc(logp, targets, in_len, lengths)
                opt.zero_grad()
                loss.backward()
                nn.utils.clip_grad_norm_(model.parameters(), 5.0)
                opt.step()
                try:
                    sched.step()
                except ValueError:
                    pass
                losses.append(float(loss))
                step += 1
                if step % 100 == 0:
                    print(f"  ep {ep+1} step {step} loss {np.mean(losses[-100:]):.3f}", flush=True)
        acc = val()
        print(f"Epoche {ep+1}/{args.epochs}: loss {np.mean(losses):.3f}, Validierung exakt {100*acc:.1f}% ({time.time()-t0:.0f}s)", flush=True)
        torch.save(model.state_dict(), args.out.replace(".onnx", ".pt"))

    # ONNX-Export (dynamische Breite)
    model.eval()
    dummy = torch.zeros(1, 1, H, 128)
    torch.onnx.export(model, dummy, args.out, input_names=["image"], output_names=["logits"],
                      dynamic_axes={"image": {0: "batch", 3: "width"}, "logits": {0: "batch", 1: "time"}}, opset_version=17, dynamo=False)
    json.dump({"chars": CHARS, "height": H}, open(args.out + ".json", "w"), ensure_ascii=False)
    print("exportiert:", args.out)


if __name__ == "__main__":
    main()
