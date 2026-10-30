#!/usr/bin/env python3
"""Validate the delivered Word/PDF and record hashes; no application access."""
import hashlib
import json
from pathlib import Path
import re
import subprocess
import unicodedata
from xml.etree import ElementTree as E
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[1]
NS = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
      'wp': 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
      'a': 'http://schemas.openxmlformats.org/drawingml/2006/main'}
SHA = lambda b: hashlib.sha256(b).hexdigest()
norm = lambda s: re.sub(r'\s+', '', unicodedata.normalize('NFKC', s))
content = json.loads((ROOT / 'wi-content.json').read_text())
build = json.loads((ROOT / 'validation/word-build.json').read_text())
template = ROOT.parent / build['template']
assert SHA(template.read_bytes()) == build['template_sha256'], 'Template changed'
figures = [b for s in content['sections'] for p in s['pages'] for b in p if b['type'] == 'figure']

with ZipFile(ROOT / 'WI-IT-003.docx') as doc, ZipFile(template) as original:
    assert doc.testzip() is None
    # These are the exact formatting resources from the user-supplied template.
    unchanged = ['word/styles.xml', 'word/fontTable.xml', 'word/theme/theme1.xml']
    for name in unchanged:
        assert doc.read(name) == original.read(name), name
    root = E.fromstring(doc.read('word/document.xml'))
    old = E.fromstring(original.read('word/document.xml'))
    assert E.tostring(root.find('w:body/w:sectPr', NS)) == E.tostring(old.find('w:body/w:sectPr', NS))
    body = root.find('w:body', NS)
    # Cover metadata and all three signoff columns have empty values.
    controls = body.findall('w:tbl', NS)[0].findall('w:tr', NS)
    assert ''.join(controls[4].findall('w:tc', NS)[1].itertext()) == ''
    signoff = body.findall('w:tbl', NS)[1]
    assert [[''.join(c.itertext()) for c in row.findall('w:tc', NS)]
            for row in signoff.findall('w:tr', NS)] == [
                ['ผู้จัดทำ', 'ผู้ทบทวน', 'ผู้อนุมัติ'], ['ชื่อ'] * 3, ['ตำแหน่ง'] * 3, ['วันที่'] * 3]
    media = [n for n in doc.namelist() if n.startswith('word/media/')]
    assert len(media) == len({b['file'] for b in figures}) == build['embedded_images'] == 21
    for b in figures:
        assert doc.read('word/media/' + Path(b['file']).name) == (ROOT / b['file']).read_bytes()
    drawings = root.findall('.//wp:docPr', NS)
    assert len(drawings) == len({d.get('id') for d in drawings}) == len(figures)
    assert len(root.findall('.//w:bookmarkStart', NS)) == len(content['sections'])
    for node in root.iter():
        assert not any(k.split('}')[-1] in ('anchorId', 'editId') for k in node.attrib)
    for n in doc.namelist():
        if n.endswith('.rels'):
            assert all(r.get('TargetMode') != 'External' for r in E.fromstring(doc.read(n)))

pdf = ROOT / 'WI-IT-003.pdf'
info = subprocess.check_output(['pdfinfo', str(pdf)], text=True)
count = int(re.search(r'Pages:\s+(\d+)', info).group(1))
assert count == build['pages'] == 33 and '(A4)' in info
text = subprocess.check_output(['pdftotext', '-layout', str(pdf), '-'], text=True)
pages = [p for p in text.split('\f') if p.strip()]
assert len(pages) == count
expected = ['คู่มือการใช้งานระบบ IT Hub', 'สารบัญและวิธีใช้คู่มือ']
for section in content['sections']:
    expected += [section['title'] + (' (ต่อ)' if i else '') for i in range(len(section['pages']))]
for i, (page, title) in enumerate(zip(pages, expected), 1):
    assert norm(title) in norm(page), (i, title)
    assert re.search(r'หน้า\s+' + str(i) + r'\s*/\s*' + str(count), page), ('footer', i)
    assert 'Rev.00' in norm(page) and 'WI-IT-003' in page
    assert len(page.strip()) > 150
for i, section in enumerate(content['sections']):
    start = build['section_starts'][str(i)]
    line = next(line for line in pages[1].splitlines() if norm(section['title']) in norm(line))
    assert re.search(r'\s' + str(start) + r'\s*$', line), ('TOC', section['title'], start, line)
    assert norm(section['title']) in norm(pages[start - 1])
assert '8446' not in text and 'WI-IT-HUB-001' not in text and 'Rev.01' not in text
assert 'Import Excel' in text and '5 MB' in text

# Verify text stays inside the page margins, and body content stays above footer.
bbox = E.fromstring(subprocess.check_output(['pdftotext', '-bbox-layout', str(pdf), '-']))
X = {'x': 'http://www.w3.org/1999/xhtml'}
layout_pages = []
for i, page in enumerate(bbox.findall('.//x:page', X), 1):
    words = page.findall('.//x:word', X)
    assert words
    assert all(float(w.get('xMin')) >= 44 and float(w.get('xMax')) <= 552 for w in words), ('horizontal overflow', i)
    body_words = [w for w in words if 100 < float(w.get('yMin')) < 800]
    assert body_words
    assert max(float(w.get('yMax')) for w in body_words) < 795, ('body overflow', i)
    layout_pages.append({'page': i, 'title': expected[i - 1],
                         'body_bottom_pt': round(max(float(w.get('yMax')) for w in body_words), 2),
                         'overflow': False})

report = ROOT / 'SYSTEM-CHECK-2026-10-28.pdf'
assert re.search(r'Pages:\s+4\b', subprocess.check_output(['pdfinfo', str(report)], text=True))
assert len(list((ROOT / 'images').glob('*.png'))) == 31
layout = {'date': '2026-10-29', 'source': 'WI-IT-003.docx', 'renderer': 'LibreOffice 26.2 / TH Sarabun New',
          'pages': count, 'sections': len(content['sections']), 'embedded_images': len(figures),
          'formatting_matches_template': unchanged + ['section page geometry'],
          'blank_signoff_values': True, 'toc_and_footers_verified': True, 'pages_checked': layout_pages,
          'visual_review_pages': [1, 2, 6, 10, 24, 25, 30, 33],
          'system_tests_rerun': False}
(ROOT / 'validation/word-layout.json').write_text(json.dumps(layout, ensure_ascii=False, indent=2) + '\n')
files = [{'file': p.relative_to(ROOT).as_posix(), 'bytes': p.stat().st_size, 'sha256': SHA(p.read_bytes())}
         for p in sorted(ROOT.rglob('*')) if p.is_file() and p.name != 'artifact-checks.json'
         and p.suffix != '.zip' and '__pycache__' not in p.parts]
manifest = {'date': '2026-10-29', 'document_code': 'WI-IT-003', 'document_revision': '00',
            'template': build['template'], 'template_sha256': build['template_sha256'],
            'pdf_pages': count, 'historical_report_date': '2026-10-28', 'historical_report_pages': 4,
            'system_tests_rerun': False, 'files': files}
(ROOT / 'validation/artifact-checks.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')
print(f'PASS: template styles/page geometry, blank signoffs, {count} PDF pages, all TOC/footers, 21 embedded images, {len(files)} SHA-256 hashes')
