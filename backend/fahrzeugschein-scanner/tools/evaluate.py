#!/usr/bin/env python3
"""
Misst die Trefferquote des lokalen Scanners gegen die Ergebnisse des bisherigen
Scanners (fs_scans_lxcars, verknuepft ueber die FIN mit cars_lxcars.filename).

Aufruf:
  tools/evaluate.py --data /home/work/opensource-erp/backend/data/ap_rebuild \
      --db ap_rebuild [--max 50] [--fields vin,hsn,...] [--dump <dir>]

Die Ground-Truth wird per psql exportiert (Zugangsdaten aus ~/.pgpass).
"""
import argparse
import csv
import io
import logging
import os
import re
import subprocess
import sys
import time

logging.disable(logging.CRITICAL)
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from fsscanner.imageio import load_file  # noqa: E402
from fsscanner.pipeline import Scanner  # noqa: E402
from fsscanner.register import RegistrationError  # noqa: E402

DEFAULT_FIELDS = ["registrationNumber", "vin", "hsn", "field_2_2", "field_3", "ez", "hu", "d1", "d3",
                  "name1", "firstname", "address1", "address2", "j", "field_4", "p1", "p2_p4", "p3",
                  "field_10", "field_14", "field_14_1", "v9", "field_5_1", "field_5_2", "d2_1", "k",
                  "field_15_1", "field_15_2", "g", "f1", "f2", "field_22", "creation_date", "creation_city",
                  "document_id"]


def norm(s):
    s = (s or "").upper()
    s = s.replace("Ä", "AE").replace("Ö", "OE").replace("Ü", "UE").replace("ß", "SS")
    s = re.sub(r"[^A-Z0-9]", "", s)
    return s


def ground_truth(db):
    sql = ("SELECT DISTINCT ON (c.c_id) c.c_id, c.filename, s.* FROM cars_lxcars c "
           "JOIN fs_scans_lxcars s ON upper(s.vin)=upper(c.c_fin) ORDER BY c.c_id, s.itime DESC")
    out = subprocess.run(["psql", "-U", "postgres", "-h", "localhost", "-d", db, "-Atc",
                          f"\\copy ({sql}) TO STDOUT WITH CSV HEADER"], capture_output=True, text=True, check=True)
    return list(csv.DictReader(io.StringIO(out.stdout)))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--data", required=True)
    ap.add_argument("--db", default="ap_rebuild")
    ap.add_argument("--max", type=int, default=0)
    ap.add_argument("--fields", default="")
    ap.add_argument("--dump", default=None, help="Verzeichnis fuer Abweichungen (Text)")
    args = ap.parse_args()
    fields = args.fields.split(",") if args.fields else DEFAULT_FIELDS

    rows = ground_truth(args.db)
    docs = []
    for r in rows:
        for sub in (os.path.join(args.data, r["filename"] or "x"), os.path.join(args.data, "fahrzeuge", r["c_id"], "fahrzeugschein"),
                    os.path.join(args.data, "fahrzeuge", r["c_id"])):
            p = os.path.join(sub, "original.jpg")
            if os.path.isfile(p):
                docs.append((r, p))
                break
    if args.max:
        docs = docs[:args.max]
    print(f"{len(docs)} Dokumente mit Original + Ground-Truth")

    scanner = Scanner()
    hits = {f: 0 for f in fields}
    total = {f: 0 for f in fields}
    failed = 0
    times = []
    diffs = []
    for i, (r, p) in enumerate(docs):
        try:
            t = time.time()
            res = scanner.scan_image(load_file(p), with_images=False)
            times.append(time.time() - t)
        except RegistrationError as e:
            failed += 1
            print(f"  {r['c_id']}: nicht registriert ({e})")
            continue
        data = res["data"]
        for f in fields:
            gt = r.get(f.lower() if f != "registrationNumber" else "registrationnumber", "") or ""
            if norm(gt) == "":
                continue
            total[f] += 1
            got = data.get(f, "")
            if norm(got) == norm(gt):
                hits[f] += 1
            else:
                diffs.append((r["c_id"], f, gt, got))
        if (i + 1) % 10 == 0:
            print(f"  [{i+1}/{len(docs)}] {sum(hits.values())}/{sum(total.values())} Treffer, "
                  f"{sum(times)/max(1,len(times)):.1f}s/Dok", flush=True)

    print(f"\nRegistrierung fehlgeschlagen: {failed}/{len(docs)}, Zeit/Dok: {sum(times)/max(1,len(times)):.1f}s")
    print(f"{'Feld':20s} {'Treffer':>8s} {'gesamt':>7s} {'Quote':>6s}")
    for f in fields:
        if total[f]:
            print(f"{f:20s} {hits[f]:8d} {total[f]:7d} {100*hits[f]/total[f]:5.1f}%")
    all_h, all_t = sum(hits.values()), sum(total.values())
    print(f"{'GESAMT':20s} {all_h:8d} {all_t:7d} {100*all_h/max(1,all_t):5.1f}%")
    if args.dump:
        os.makedirs(args.dump, exist_ok=True)
        with open(os.path.join(args.dump, "diffs.tsv"), "w", encoding="utf-8") as fh:
            for cid, f, gt, got in diffs:
                fh.write(f"{cid}\t{f}\t{gt!r}\t{got!r}\n")
        print("Abweichungen:", os.path.join(args.dump, "diffs.tsv"))


if __name__ == "__main__":
    main()
