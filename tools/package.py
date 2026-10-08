# SPDX-License-Identifier: AGPL-3.0-or-later
"""Build a deterministic app archive without credentials, tests, or Git metadata."""
from pathlib import Path
import gzip
import hashlib
import io
import tarfile
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1]
app = root / 'vereinsflieger_login'
version = ET.parse(app / 'appinfo/info.xml').getroot().findtext('version')
assert version and all(c.isdigit() or c == '.' for c in version)
dist = root / 'dist'
dist.mkdir(exist_ok=True)
archive = dist / f'vereinsflieger_login-{version}.tar.gz'
payload = io.BytesIO()
with tarfile.open(fileobj=payload, mode='w', format=tarfile.PAX_FORMAT) as tar:
    for path in sorted(app.rglob('*')):
        if not path.is_file():
            continue
        if path.is_symlink() or path.suffix in {'.key', '.pem', '.log'} or path.name.startswith('.env'):
            raise RuntimeError(f'Unexpected file in app: {path.name}')
        name = 'vereinsflieger_login/' + path.relative_to(app).as_posix()
        info = tarfile.TarInfo(name)
        body = path.read_bytes()
        info.size = len(body)
        info.mtime = 0
        info.mode = 0o644
        info.uid = info.gid = 0
        info.uname = info.gname = ''
        tar.addfile(info, io.BytesIO(body))
with archive.open('wb') as output:
    with gzip.GzipFile(filename='', fileobj=output, mode='wb', mtime=0) as compressed:
        compressed.write(payload.getvalue())
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_name(archive.name + '.sha256').write_text(f'{digest}  {archive.name}\n', encoding='utf-8')
print(f'Built {archive.name}; SHA-256 {digest}')
