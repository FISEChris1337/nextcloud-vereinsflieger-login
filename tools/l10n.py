# SPDX-License-Identifier: AGPL-3.0-or-later
"""Validate Nextcloud translation catalogs and generate their JavaScript files."""
from pathlib import Path
import argparse
import json
import re

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--check', action='store_true', help='Check generated files without changing them')
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
app = root / 'vereinsflieger_login'
catalog = json.loads((app / 'l10n/de.json').read_text(encoding='utf-8'))
translations = catalog['translations']
plural = catalog['pluralForm']
placeholders = lambda text: re.findall(r'%(?:\d+\$)?[sd]|\{[A-Za-z][A-Za-z0-9_]*\}', text)
for key, value in translations.items():
    if sorted(placeholders(key)) != sorted(placeholders(value)):
        raise RuntimeError(f'Placeholder mismatch: {key}')
    if '\ufffd' in key + value:
        raise RuntimeError('Invalid UTF-8 replacement character in catalog')
used = set()
for path in app.rglob('*'):
    if path.suffix not in {'.php', '.js'} or path.parent.name == 'l10n':
        continue
    source = path.read_text(encoding='utf-8')
    for match in re.finditer(r"(?:->t|translate|new ValidationException)\(\s*([\"'])((?:\\.|(?!\1)[^\\])*)\1", source):
        key = match[2].replace("\\'", "'").replace('\\"', '"').replace('\\\\', '\\')
        if key != 'vereinsflieger_login':
            used.add(key)
missing = used - translations.keys()
if missing:
    raise RuntimeError('Missing translations: ' + ', '.join(sorted(missing)))
for language in ['de', 'de_DE', 'en']:
    values = translations if language != 'en' else {key: key for key in translations}
    payload = {'translations': values, 'pluralForm': plural}
    contents = {
        '.json': json.dumps(payload, ensure_ascii=False, indent=2) + '\n',
        '.js': 'OC.L10N.register(\n    "vereinsflieger_login",\n    ' + json.dumps(values, ensure_ascii=False, indent=4)
               + ',\n    ' + json.dumps(plural) + '\n);\n',
    }
    for suffix, content in contents.items():
        target = app / 'l10n' / (language + suffix)
        if args.check:
            if target.read_text(encoding='utf-8') != content:
                raise RuntimeError('Generated catalog differs: ' + str(target.relative_to(root)))
        else:
            target.write_text(content, encoding='utf-8', newline='\n')
print(f'{len(translations)} translations checked for German and English; {len(used)} source messages covered.')
