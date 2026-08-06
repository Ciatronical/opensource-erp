#!/usr/bin/env python3
"""
Massenübersetzung der vue-i18n Locale-Dateien über die Anthropic Batch-API.

Ablauf:
    ./tools/i18n-translate.py plan                 # fehlende Keys ermitteln
    ./tools/i18n-translate.py submit               # Batch abschicken
    ./tools/i18n-translate.py status               # Fortschritt abfragen
    ./tools/i18n-translate.py apply                # Ergebnisse einspielen

Die Locale-Dateien liegen neben den Views (src/**/locales/{de,en,...}.json).
de.json ist die Quelle. Ein Key gilt als offen, wenn er in der Zielsprache
fehlt oder dort noch wörtlich dem deutschen Text entspricht.

Platzhalter ({name}, {count}) werden vor dem Schreiben geprüft: stimmt die
Menge der Platzhalter nicht mit dem Original überein, wird die Übersetzung
verworfen und der Key bleibt offen.
"""

import argparse
import json
import os
import re
import sys
import time
from pathlib import Path

BASE = Path(__file__).resolve().parent.parent
SRC = BASE / 'src'
WORK = BASE / 'tools' / '.i18n-translate'

# Das anthropic-SDK liegt in einem venv neben dem Arbeitsverzeichnis. Fehlt es
# im laufenden Interpreter, wird einmalig mit dem venv-Python neu gestartet.
_VENV_PY = WORK / 'venv' / 'bin' / 'python'
if not os.environ.get('_I18N_REEXEC') and _VENV_PY.exists():
    try:
        import anthropic  # noqa: F401
    except ImportError:
        os.environ['_I18N_REEXEC'] = '1'
        os.execv(str(_VENV_PY), [str(_VENV_PY), str(Path(__file__).resolve())] + sys.argv[1:])

MODEL = 'claude-sonnet-5'
CHUNK = 120            # Keys pro Request
REFERENCE_MAX = 40     # bereits übersetzte Beispiele als Terminologie-Referenz
MAX_TOKENS = 16000

LANGS = {
    'en': 'Englisch',
    'fr': 'Französisch',
    'es': 'Spanisch',
    'it': 'Italienisch',
    'pt': 'Portugiesisch',
    'nl': 'Niederländisch',
    'pl': 'Polnisch',
    'cs': 'Tschechisch',
    'ro': 'Rumänisch',
    'da': 'Dänisch',
    'sv': 'Schwedisch',
    'nb': 'Norwegisch (Bokmål)',
    'fi': 'Finnisch',
    'et': 'Estnisch',
    'lv': 'Lettisch',
    'lt': 'Litauisch',
    'ru': 'Russisch',
    'uk': 'Ukrainisch',
    'tr': 'Türkisch',
    'zh': 'Chinesisch (vereinfacht)',
}

PLACEHOLDER = re.compile(r'\{[^}]*\}')

SCHEMA = {
    'type': 'object',
    'properties': {
        'translations': {
            'type': 'array',
            'items': {
                'type': 'object',
                'properties': {
                    'k': {'type': 'string'},
                    'v': {'type': 'string'},
                },
                'required': ['k', 'v'],
                'additionalProperties': False,
            },
        },
    },
    'required': ['translations'],
    'additionalProperties': False,
}


def system_prompt(lang_name):
    return f"""Du übersetzt die Oberflächentexte einer ERP-Software aus dem Deutschen ins {lang_name}.

Du bekommst eine Liste von Einträgen mit "k" (Schlüsselpfad) und "v" (deutscher Text).
Gib zu jedem Eintrag denselben "k" zurück und in "v" die Übersetzung.

Regeln:
- Platzhalter in geschweiften Klammern ({{name}}, {{count}}, {{format}}) bleiben unverändert und vollzählig erhalten. Ihre Position im Satz darf sich ändern, ihre Schreibweise nicht.
- Der Schlüsselpfad gibt den fachlichen Kontext an, etwa "AccountingView.bookings.taxAmount" für Buchhaltung oder "CarEditView" für Fahrzeugverwaltung. Nutze ihn zur Disambiguierung.
- Es sind UI-Texte: Buttons, Feldbeschriftungen, Platzhalter, Meldungen. Übersetze idiomatisch und knapp, nicht wörtlich. Beschriftungen bleiben kurz, damit sie ins Layout passen.
- Fachbegriffe aus Buchhaltung, Fakturierung, Bankwesen und Fahrzeughandel in der in der Zielsprache üblichen Fachsprache wiedergeben.
- Nicht gendern. Verwende die in der Zielsprache übliche generische Form.
- Produktnamen, Formate und Abkürzungen unverändert lassen: PDF, CSV, XML, IBAN, BIC, SEPA, XRechnung, ZUGFeRD, DATEV, API, URL, E-Mail.
- Ist ein Begriff in der Zielsprache identisch mit dem deutschen (etwa "Import", "Server", "OK"), gib ihn unverändert zurück.
- Übersetze jeden Eintrag. Lasse keinen aus und füge keinen hinzu."""


def flatten(obj, prefix=''):
    out = {}
    if isinstance(obj, dict):
        for k, v in obj.items():
            out.update(flatten(v, f'{prefix}.{k}' if prefix else k))
    elif isinstance(obj, str):
        out[prefix] = obj
    return out


def set_nested(obj, path, value):
    keys = path.split('.')
    for k in keys[:-1]:
        if not isinstance(obj.get(k), dict):
            obj[k] = {}
        obj = obj[k]
    obj[keys[-1]] = value


def load_json(path):
    if not path.exists():
        return {}
    with open(path, encoding='utf-8') as f:
        return json.load(f)


def write_json(path, data):
    # Bestehende Dateien behalten ihre Konvention, neue bekommen den Newline.
    trailing = path.read_bytes().endswith(b'\n') if path.exists() else True
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=4)
        if trailing:
            f.write('\n')


def locale_dirs():
    return sorted(d for d in SRC.rglob('locales') if (d / 'de.json').exists())


def translatable(value):
    """URL-Pfade (routes.*) bleiben ausgespart.

    Der Router baut seine Pfade aus i18n.global.t('routes.…') und haengt bei
    17 Routen noch '/:id(\\d+)' an. Eine uebersetzte Route mit Leerzeichen,
    Umlaut oder abschliessendem Slash zerlegt die Navigation. Fehlende Keys
    fallen ueber fallbackLocale: 'en' auf die englischen Pfade zurueck.
    """
    return not value.startswith('/')


def collect(only_lang=None, only_view=None):
    """Ermittelt alle offenen Keys als Liste von Requests."""
    requests = []
    n = 0
    for d in locale_dirs():
        rel = str(d.relative_to(BASE))
        if only_view and only_view not in rel:
            continue
        de = flatten(load_json(d / 'de.json'))
        if not de:
            continue
        for lang in LANGS:
            if only_lang and lang != only_lang:
                continue
            tgt = flatten(load_json(d / f'{lang}.json'))
            missing = [k for k in de
                       if translatable(de[k]) and (k not in tgt or tgt[k] == de[k])]
            if not missing:
                continue
            reference = [
                {'k': k, 'de': de[k], lang: tgt[k]}
                for k in de
                if k in tgt and tgt[k] != de[k]
            ][:REFERENCE_MAX]
            for i in range(0, len(missing), CHUNK):
                requests.append({
                    'id': f'r{n}',
                    'dir': rel,
                    'lang': lang,
                    'keys': missing[i:i + CHUNK],
                    'reference': reference,
                })
                n += 1
    return requests


def build_params(req, de):
    items = [{'k': k, 'v': de[k]} for k in req['keys']]
    parts = []
    if req['reference']:
        parts.append(
            'Bereits vorhandene Übersetzungen aus demselben Modul — halte dich '
            'terminologisch daran:\n'
            + json.dumps(req['reference'], ensure_ascii=False, indent=1)
        )
    parts.append(
        'Übersetze diese Einträge:\n'
        + json.dumps(items, ensure_ascii=False, indent=1)
    )
    return {
        'model': MODEL,
        'max_tokens': MAX_TOKENS,
        'system': system_prompt(LANGS[req['lang']]),
        'thinking': {'type': 'disabled'},
        'output_config': {
            'effort': 'low',
            'format': {'type': 'json_schema', 'schema': SCHEMA},
        },
        'messages': [{'role': 'user', 'content': '\n\n'.join(parts)}],
    }


def placeholders_ok(source, translated):
    if sorted(PLACEHOLDER.findall(source)) != sorted(PLACEHOLDER.findall(translated)):
        return False
    if source.count('|') != translated.count('|'):
        return False
    return True


# ---------------------------------------------------------------- subcommands

def cmd_plan(args):
    requests = collect(args.lang, args.view)
    total = sum(len(r['keys']) for r in requests)
    WORK.mkdir(parents=True, exist_ok=True)
    (WORK / 'manifest.json').write_text(
        json.dumps(requests, ensure_ascii=False), encoding='utf-8')

    per_lang = {}
    for r in requests:
        per_lang[r['lang']] = per_lang.get(r['lang'], 0) + len(r['keys'])
    print(f'{len(requests)} Requests, {total} Keys\n')
    print(f'{"Sprache":10} {"offen":>8}')
    for lang, count in sorted(per_lang.items(), key=lambda x: -x[1]):
        print(f'{lang:10} {count:>8}')
    print(f'\nManifest: {WORK / "manifest.json"}')


def cmd_submit(args):
    import anthropic
    requests = json.loads((WORK / 'manifest.json').read_text(encoding='utf-8'))
    if args.limit:
        requests = requests[:args.limit]

    de_cache = {}
    payload = []
    for r in requests:
        if r['dir'] not in de_cache:
            de_cache[r['dir']] = flatten(load_json(BASE / r['dir'] / 'de.json'))
        payload.append({
            'custom_id': r['id'],
            'params': build_params(r, de_cache[r['dir']]),
        })

    client = anthropic.Anthropic()
    batch = client.messages.batches.create(requests=payload)
    (WORK / 'batch_id').write_text(batch.id, encoding='utf-8')
    print(f'Batch {batch.id} — {len(payload)} Requests, Status {batch.processing_status}')


def cmd_status(args):
    import anthropic
    batch_id = (WORK / 'batch_id').read_text(encoding='utf-8').strip()
    client = anthropic.Anthropic()
    while True:
        batch = client.messages.batches.retrieve(batch_id)
        c = batch.request_counts
        print(f'{batch.processing_status}  '
              f'verarbeitet={c.processing} ok={c.succeeded} '
              f'fehler={c.errored} abgelaufen={c.expired}')
        if batch.processing_status == 'ended' or not args.wait:
            break
        time.sleep(30)


def cmd_apply(args):
    import anthropic
    batch_id = (WORK / 'batch_id').read_text(encoding='utf-8').strip()
    manifest = {r['id']: r
                for r in json.loads((WORK / 'manifest.json').read_text(encoding='utf-8'))}
    client = anthropic.Anthropic()

    updates = {}       # (dir, lang) -> {key: value}
    rejected, errors, missing_keys = [], [], 0

    for result in client.messages.batches.results(batch_id):
        req = manifest.get(result.custom_id)
        if req is None:
            continue
        if result.result.type != 'succeeded':
            errors.append((result.custom_id, result.result.type))
            continue

        msg = result.result.message
        if msg.stop_reason == 'max_tokens':
            errors.append((result.custom_id, 'max_tokens'))
            continue

        text = ''.join(b.text for b in msg.content if b.type == 'text')
        try:
            data = json.loads(text)['translations']
        except (json.JSONDecodeError, KeyError, TypeError):
            errors.append((result.custom_id, 'unparsebar'))
            continue

        de = flatten(load_json(BASE / req['dir'] / 'de.json'))
        got = {t['k']: t['v'] for t in data}
        bucket = updates.setdefault((req['dir'], req['lang']), {})
        for key in req['keys']:
            if key not in got:
                missing_keys += 1
                continue
            value = got[key]
            if not value.strip() or not placeholders_ok(de[key], value):
                rejected.append((req['lang'], key))
                continue
            bucket[key] = value

    if args.dry_run:
        print(f'{sum(len(v) for v in updates.values())} Übersetzungen bereit '
              f'(nicht geschrieben — --dry-run)')
    else:
        for (rel, lang), values in sorted(updates.items()):
            path = BASE / rel / f'{lang}.json'
            data = load_json(path)
            for key, value in values.items():
                set_nested(data, key, value)
            write_json(path, data)
        print(f'{sum(len(v) for v in updates.values())} Übersetzungen in '
              f'{len(updates)} Dateien geschrieben')

    if missing_keys:
        print(f'{missing_keys} Keys wurden vom Modell nicht zurückgeliefert')
    if rejected:
        print(f'{len(rejected)} verworfen (Platzhalter/leer):')
        for lang, key in rejected[:20]:
            print(f'  {lang}  {key}')
        if len(rejected) > 20:
            print(f'  ... und {len(rejected) - 20} weitere')
    if errors:
        print(f'{len(errors)} fehlgeschlagene Requests:')
        for cid, why in errors[:20]:
            print(f'  {cid}: {why}')

    if rejected or errors or missing_keys:
        print('\nOffene Keys bleiben stehen — erneut "plan" und "submit" laufen lassen.')


def main():
    p = argparse.ArgumentParser(description=__doc__,
                                formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest='cmd', required=True)

    sp = sub.add_parser('plan', help='offene Keys ermitteln')
    sp.add_argument('--lang', help='nur diese Sprache')
    sp.add_argument('--view', help='nur Views, deren Pfad diesen Text enthält')
    sp.set_defaults(func=cmd_plan)

    sp = sub.add_parser('submit', help='Batch abschicken')
    sp.add_argument('--limit', type=int, help='nur die ersten N Requests (Testlauf)')
    sp.set_defaults(func=cmd_submit)

    sp = sub.add_parser('status', help='Batch-Status')
    sp.add_argument('--wait', action='store_true', help='bis zum Ende pollen')
    sp.set_defaults(func=cmd_status)

    sp = sub.add_parser('apply', help='Ergebnisse einspielen')
    sp.add_argument('--dry-run', action='store_true', help='nur prüfen, nicht schreiben')
    sp.set_defaults(func=cmd_apply)

    args = p.parse_args()
    args.func(args)


if __name__ == '__main__':
    sys.exit(main())
