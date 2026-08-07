#!/usr/bin/env python3
"""
Werkzeug fuer die manuelle Uebersetzung der Locale-Dateien.

Ergaenzung zu i18n-translate.py (Batch-API): dieses Skript ruft keine API auf,
sondern gibt offene Keys aus und spielt gelieferte Uebersetzungen zurueck.

    ./tools/i18n-manual.py status                      Fortschritt je Sprache
    ./tools/i18n-manual.py open <lang> [teilpfad] [-n N]   offene Keys ausgeben
    ./tools/i18n-manual.py set <lang> <verzeichnis>    Uebersetzungen einspielen
                                                       (JSON-Map ueber stdin)

Eigenschaften:
  * Quelle ist immer de.json, Englisch wird als Referenz mitgegeben
  * ein Key gilt als offen, wenn er fehlt oder woertlich dem Deutschen entspricht
  * die Einrueckung der Zieldatei bleibt erhalten (Dateien sind uneinheitlich
    mit 2 oder 4 Leerzeichen formatiert)
  * Platzhalter ({name}, {count}) werden geprueft: stimmen sie nicht mit dem
    Original ueberein, wird die Uebersetzung abgelehnt statt still verfaelscht
"""
import argparse
import collections
import json
import pathlib
import re
import sys

BASE = pathlib.Path(__file__).resolve().parent.parent
SRC = BASE / 'src'
PLACEHOLDER = re.compile(r'\{[^}]*\}')

LANGS = ['en', 'fr', 'nl', 'es', 'it', 'pt', 'pl', 'cs', 'ro', 'da',
         'sv', 'nb', 'fi', 'et', 'lv', 'lt', 'ru', 'uk', 'tr', 'zh']


def flatten(node, prefix=''):
    out = {}
    for key, value in node.items():
        if isinstance(value, dict):
            out.update(flatten(value, prefix + key + '.'))
        else:
            out[prefix + key] = value
    return out


def segments(node, prefix=''):
    """Gepunkteter Pfad -> tatsaechliche Schluesselfolge.

    Noetig, weil einzelne Locale-Dateien woertliche Punkt-Schluessel
    verwenden ("crm.whatsapp" als ein Name) statt Verschachtelung. Ein
    stumpfes split('.') wuerde die Struktur zerstoeren.
    """
    out = {}
    for key, value in node.items():
        path = prefix + key
        if isinstance(value, dict):
            out.update(segments(value, path + '.'))
        else:
            out[path] = (prefix.split('.')[:-1] if prefix else []) + [key]
    return out


def resolve(de_node, dotted):
    """Gepunkteten Pfad anhand der deutschen Struktur in echte Schluessel zerlegen.

    Einzelne Locale-Dateien verwenden woertliche Punkt-Schluessel
    ("crm.whatsapp" als ein Name) statt Verschachtelung. Ein stumpfes
    split('.') wuerde daraus zwei Ebenen machen und die Datei zerstoeren.
    """
    if dotted in de_node and not isinstance(de_node[dotted], dict):
        return [dotted]
    parts = dotted.split('.')
    for i in range(1, len(parts)):
        head = '.'.join(parts[:i])
        if isinstance(de_node.get(head), dict):
            rest = resolve(de_node[head], '.'.join(parts[i:]))
            if rest is not None:
                return [head] + rest
    return None


def indent_of(path):
    """Einrueckung der Datei ermitteln — die Dateien sind uneinheitlich."""
    if not path.exists():
        return 4
    for line in path.read_text(encoding='utf-8').split('\n')[1:]:
        match = re.match(r'^( +)"', line)
        if match:
            return len(match.group(1))
    return 4


def load(path):
    if not path.exists():
        return {}
    return flatten(json.loads(path.read_text(encoding='utf-8')))


def dirs():
    return sorted(p.parent for p in SRC.rglob('locales/de.json'))


def open_keys(lang, directory):
    """Offene Keys eines Verzeichnisses: (key, deutsch, englisch-oder-None)."""
    de = load(directory / 'de.json')
    target = load(directory / f'{lang}.json')
    en = load(directory / 'en.json') if lang != 'en' else {}
    result = []
    for key, value in de.items():
        if not isinstance(value, str):
            continue
        if key in target and target[key] != value:
            continue
        reference = en.get(key)
        result.append((key, value, reference if reference != value else None))
    return result


def cmd_status(args):
    rows = []
    for lang in LANGS:
        total = pending = 0
        for directory in dirs():
            de = load(directory / 'de.json')
            total += sum(1 for v in de.values() if isinstance(v, str))
            pending += len(open_keys(lang, directory))
        rows.append((lang, pending, total))
    print(f'{"":5}{"offen":>8}{"gesamt":>9}{"fertig":>9}')
    for lang, pending, total in rows:
        print(f'{lang:5}{pending:8}{total:9}{(total - pending) / total * 100:8.1f}%')
    print(f'\nOffen gesamt: {sum(r[1] for r in rows)}')


def cmd_open(args):
    hits = 0
    for directory in dirs():
        rel = str(directory.relative_to(BASE))
        if args.filter and args.filter not in rel:
            continue
        pending = open_keys(args.lang, directory)
        if not pending:
            continue
        print(f'### {rel}  ({len(pending)} offen)')
        for key, german, english in pending:
            if args.limit and hits >= args.limit:
                print('### (Ausgabe gekuerzt)')
                return
            entry = {'k': key, 'de': german}
            if english:
                entry['en'] = english
            print(json.dumps(entry, ensure_ascii=False))
            hits += 1


def cmd_set(args):
    directory = BASE / args.dir
    if not (directory / 'de.json').exists():
        sys.exit(f'Kein de.json in {directory}')
    payload = json.load(sys.stdin)
    de = load(directory / 'de.json')
    path = directory / f'{args.lang}.json'
    current = load(path)
    width = indent_of(path if path.exists() else directory / 'de.json')

    de_tree = json.loads((directory / 'de.json').read_text(encoding='utf-8'),
                         object_pairs_hook=collections.OrderedDict)
    accepted = collections.OrderedDict()
    written = rejected = 0
    problems = []
    for key, value in payload.items():
        if key not in de:
            problems.append(f'{key}: im Deutschen unbekannt')
            rejected += 1
            continue
        if sorted(PLACEHOLDER.findall(de[key])) != sorted(PLACEHOLDER.findall(value)):
            problems.append(f'{key}: Platzhalter weichen ab '
                            f'({PLACEHOLDER.findall(de[key])} vs {PLACEHOLDER.findall(value)})')
            rejected += 1
            continue
        accepted[key] = value
        written += 1

    # Nur die betroffenen Blaetter im vorhandenen Baum setzen. Die Datei wird
    # NICHT neu aufgebaut — sonst verschoeben sich Reihenfolge und Struktur.
    tree = (json.loads(path.read_text(encoding='utf-8'), object_pairs_hook=collections.OrderedDict)
            if path.exists() else collections.OrderedDict())
    for key, value in accepted.items():
        keys = resolve(de_tree, key)
        if keys is None:
            problems.append(f'{key}: Pfad in der deutschen Datei nicht auffindbar')
            written -= 1
            continue
        node = tree
        for part in keys[:-1]:
            if not isinstance(node.get(part), dict):
                node[part] = collections.OrderedDict()
            node = node[part]
        node[keys[-1]] = value

    # Dateiende beibehalten — nicht alle Dateien enden mit Zeilenumbruch
    tail = '\n' if (not path.exists() or path.read_text(encoding='utf-8').endswith('\n')) else ''
    path.write_text(json.dumps(tree, ensure_ascii=False, indent=width) + tail, encoding='utf-8')
    print(f'{args.lang}/{args.dir}: {written} geschrieben, {rejected} abgelehnt, '
          f'{len(open_keys(args.lang, directory))} weiterhin offen')
    for problem in problems:
        print('  ! ' + problem)


parser = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
sub = parser.add_subparsers(dest='cmd', required=True)
sub.add_parser('status').set_defaults(func=cmd_status)
p_open = sub.add_parser('open')
p_open.add_argument('lang')
p_open.add_argument('filter', nargs='?')
p_open.add_argument('-n', '--limit', type=int, default=0)
p_open.set_defaults(func=cmd_open)
p_set = sub.add_parser('set')
p_set.add_argument('lang')
p_set.add_argument('dir')
p_set.set_defaults(func=cmd_set)

args = parser.parse_args()
args.func(args)
