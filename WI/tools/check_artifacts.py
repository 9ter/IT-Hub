#!/usr/bin/env python3
"""Validate the prepared documents and write a SHA-256 delivery manifest.
Requires the standard pdfinfo/pdftotext utilities, not access to the application.
"""
import hashlib
import json
import re
import struct
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[1]
results = json.loads((root / 'validation/test-results.json').read_text())
assert len(results) == 12 and all(item['exit_code'] == 0 for item in results)
checks = sum(int(re.search(r'(\d+) .*?checks', item['summary'][0]).group(1)) for item in results)
assert checks == 568
browser = json.loads((root / 'validation/browser-results.json').read_text())
assert len(browser['checks']) == 28 and not browser['errors'] and not browser['unexpected']
assert all(item['result'] == 'pass' for item in browser['checks'])

pdfs = []
for name, expected in [('WI-IT-003', 27), ('SYSTEM-CHECK-2026-10-28', 4)]:
    info = subprocess.check_output(['pdfinfo', str(root / (name + '.pdf'))], text=True)
    count = int(re.search(r'Pages:\s+(\d+)', info).group(1))
    assert count == expected and '(A4)' in info
    text = subprocess.check_output(['pdftotext', '-layout', str(root / (name + '.pdf')), '-'], text=True)
    pages = [page.strip() for page in text.split('\f') if page.strip()]
    assert len(pages) == expected and all(len(page) > 150 for page in pages)
    assert 'ระบบ IT Hub' in text
    source = (root / (name + '.md')).read_text()
    for filename in re.findall(r'!\[.*?\]\((.*?)\)', source):
        assert (root / filename).is_file()
    document = (root / (name + '.html')).read_text()
    assert all(url.startswith('data:image/') for url in re.findall(r'<img src="([^"]+)"', document))
    assert 'data:font/woff2;base64,' in document
    pdfs.append({'file': name + '.pdf', 'pages': count, 'size': 'A4', 'searchable_thai': True, 'blank_pages': 0})

screenshots = []
for file in sorted((root / 'images').glob('*.png')):
    width, height = struct.unpack('>II', file.read_bytes()[16:24])
    assert width > 0 and height > 0
    screenshots.append({'file': file.name, 'width': width, 'height': height})
assert len(screenshots) == len(browser['screenshots']) == 31
layout = json.loads((root / 'validation/print-layout.json').read_text())
assert not layout['errors']
assert all(not section['overflow'] for doc in layout['result'] for section in doc['sections'])

# Covers every source, image, font, report, summary, and build tool in the package.
files = []
for file in sorted(root.rglob('*')):
    if file.is_file() and file.name != 'artifact-checks.json' and file.suffix != '.zip':
        files.append({'file': file.relative_to(root).as_posix(), 'bytes': file.stat().st_size, 'sha256': hashlib.sha256(file.read_bytes()).hexdigest()})
manifest = {'date': '2026-10-28', 'document_updated': '2026-10-29', 'document_code': 'WI-IT-003', 'document_revision': '00', 'test_suites': 12, 'automated_checks': checks, 'browser_checks': 28,
            'pdfs': pdfs, 'screenshots': screenshots, 'offline_html': True,
            'visual_review': {'wi_pages': [1, 2, 6, 14, 17, 19, 25, 26, 27], 'report_pages': [1, 2, 3, 4],
                              'note': 'Original content reviewed on 2026-10-28; revised WI-IT-003 Rev.00 cover visually checked on 2026-10-29 with blank preparer/reviewer fields.'},
            'files': files}
(root / 'validation/artifact-checks.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')
print(f'PASS: {checks} automated checks, 28 browser checks, 27+4 A4 PDF pages, {len(screenshots)} screenshots; SHA-256 recorded for {len(files)} files')
