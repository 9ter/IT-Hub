#!/usr/bin/env python3
"""Build offline, self-contained Thai WI HTML from the adjacent Markdown.

Run from any directory: python3 WI/tools/build_wi.py
PDF printing uses Chromium; see ../README.md. No network or application writes.
This renderer implements only the Markdown constructs used in these documents.
"""
import base64
import html
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def inline(value):
    value = html.escape(value)
    value = re.sub(r'`([^`]+)`', r'<code>\1</code>', value)
    value = re.sub(r'\*\*([^*]+)\*\*', r'<strong>\1</strong>', value)
    value = re.sub(r'\[([^\]]+)\]\(([^)]+)\)', r'<a href="\2">\1</a>', value)
    return value


def data_url(path, mime):
    return 'data:' + mime + ';base64,' + base64.b64encode(path.read_bytes()).decode()


def blocks(lines):
    out, i = [], 0
    while i < len(lines):
        line = lines[i].strip()
        if not line:
            i += 1
            continue
        match = re.fullmatch(r'!\[(.*?)\]\((.*?)\)', line)
        if match:
            caption, filename = match.groups()
            source = (ROOT / filename).resolve()
            if not source.is_relative_to(ROOT) or not source.is_file():
                raise ValueError('Missing/invalid image: ' + filename)
            out.append(f'<figure><img src="{data_url(source,"image/png")}" alt="{inline(caption)}"><figcaption>{inline(caption)}</figcaption></figure>')
        elif line.startswith('## '):
            out.append('<h2>' + inline(line[3:]) + '</h2>')
        elif line.startswith('|'):
            rows = []
            while i < len(lines) and lines[i].strip().startswith('|'):
                cells = [c.strip() for c in lines[i].strip().strip('|').split('|')]
                if not all(re.fullmatch(r'[-: ]+', c) for c in cells):
                    tag = 'th' if not rows else 'td'
                    rows.append('<tr>' + ''.join(f'<{tag}>{inline(c)}</{tag}>' for c in cells) + '</tr>')
                i += 1
            out.append('<table>' + ''.join(rows) + '</table>')
            continue
        elif re.match(r'^\d+\. ', line):
            items = []
            while i < len(lines) and re.match(r'^\d+\. ', lines[i].strip()):
                items.append('<li>' + inline(re.sub(r'^\d+\. ', '', lines[i].strip())) + '</li>')
                i += 1
            out.append('<ol>' + ''.join(items) + '</ol>')
            continue
        elif line.startswith('> '):
            out.append('<aside>' + inline(line[2:]) + '</aside>')
        else:
            out.append('<p>' + inline(line) + '</p>')
        i += 1
    return '\n'.join(out)


fonts = ''
for weight in [400, 700]:
    for subset, unicode in [('thai', 'U+0E00-0E7F,U+200B-200D,U+25CC'), ('latin', 'U+0000-00FF,U+2000-206F,U+20AC,U+2122,U+2190-21FF')]:
        source = ROOT / f'fonts/noto-sans-thai-{subset}-{weight}-normal.woff2'
        fonts += f'@font-face{{font-family:WIThai;font-weight:{weight};src:url("{data_url(source,"font/woff2")}") format("woff2");unicode-range:{unicode};}}\n'

CSS = '''
*{box-sizing:border-box}html{background:#e6ebf1;color:#1c2939;font-family:WIThai,sans-serif;font-size:10.2pt;line-height:1.65}
body{margin:0}a{color:#155e9c;text-decoration:none}code{font-family:monospace;font-size:9pt;overflow-wrap:anywhere}
.toolbar{position:sticky;top:0;z-index:2;background:#102c45;color:white;padding:12px 24px;display:flex;gap:24px;align-items:center}.toolbar a{color:white}.toolbar button{margin-left:auto;border:0;border-radius:6px;padding:8px 16px;cursor:pointer}
.page{width:210mm;min-height:285mm;margin:18px auto;background:white;padding:14mm 15mm 12mm;box-shadow:0 3px 18px #102c4520;position:relative;break-before:page}
.page:first-of-type{break-before:auto}.eyebrow{font-size:8pt;color:#60758a;border-bottom:1px solid #c9d7e4;padding-bottom:6px;letter-spacing:.3px}
h1{font-size:18pt;line-height:1.4;color:#123c5c;margin:14px 0 12px}h2{font-size:19pt;line-height:1.4;margin:6px 0 16px;color:#0e6183}p{margin:10px 0}ol{padding-left:24px;margin:10px 0}li{padding-left:5px;margin:5px 0}
table{border-collapse:collapse;width:100%;margin:12px 0;font-size:9.2pt;line-height:1.6}th,td{border:1px solid #cfdae3;padding:7px 9px;text-align:left;vertical-align:top}th{background:#e9f1f8;color:#123c5c}tr:nth-child(even) td{background:#f8fafc}
aside{border-left:4px solid #d6a64d;background:#fff8e8;padding:9px 12px;font-size:9.3pt;margin:14px 0;break-inside:avoid}
figure{margin:16px 0 10px;break-inside:avoid}figure img{display:block;width:100%;height:auto;max-height:132mm;object-fit:contain;border:1px solid #cfdae3;border-radius:5px;background:#f8fafc}figcaption{text-align:center;color:#596c7c;font-size:8.5pt;margin-top:5px}
.cover h1{font-size:32pt;margin-top:18px}.cover figure img{max-height:100mm}.cover table{font-size:9pt}.cover aside{font-size:8.5pt}.page-no{font-size:8pt;color:#687c8d;text-align:right;border-top:1px solid #d6e0e8;margin-top:16px;padding-top:5px}.contents ol{columns:1;line-height:1.8;list-style:none;padding-left:0}.contents a{color:#123c5c}
@page{size:A4;margin:6mm 0 9mm}@media print{html{background:white;font-size:10.2pt}.toolbar{display:none}.page{margin:0;width:210mm;min-height:278mm;padding:8mm 15mm 8mm;box-shadow:none}.page-no{display:none}figure img{max-height:130mm}a{color:inherit}a[href^="http"]{overflow-wrap:anywhere}}
@media screen and (max-width:850px){.page{width:100%;min-height:auto;margin:10px 0;padding:20px}.toolbar{padding:10px;font-size:12px}.cover h1{font-size:26pt}table{font-size:9pt}.page{overflow-wrap:anywhere}}
'''


def build(name, contents=False, revision='00'):
    source = ROOT / (name + '.md')
    sections = re.split(r'^# ', source.read_text(), flags=re.M)[1:]
    rendered, headings = [], []
    for i, section in enumerate(sections):
        title, _, body = section.partition('\n')
        headings.append(title)
        cls = 'page cover' if i == 0 else 'page'
        rendered.append(f'<section class="{cls}" id="section-{i}"><div class="eyebrow">IT HUB • {name} • Rev.{revision} • 28/10/2569</div><h1>{inline(title)}</h1>{blocks(body.splitlines())}<div class="page-no">{i+1} / {len(sections)}</div></section>')
    if contents:
        toc = '<section class="page contents" id="contents"><div class="eyebrow">IT HUB • WI-IT-003 • สารบัญ</div><h1>สารบัญ</h1><ol>'
        toc += ''.join(f'<li><a href="#section-{i}">{inline(t)}</a></li>' for i, t in enumerate(headings[1:], 1))
        toc += '</ol><aside>กดหัวข้อเพื่อไปยังขั้นตอน ภาพใช้ข้อมูลตัวอย่าง รายงานตรวจระบบแยกอยู่ในชุดเอกสารเดียวกัน</aside></section>'
        rendered.insert(1, toc)
    title = f'คู่มือ {name} Rev.{revision} ระบบ IT Hub' if contents else 'รายงานตรวจระบบ IT Hub'
    document = f'<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{title}</title><style>{fonts}{CSS}</style></head><body><nav class="toolbar"><strong>{title}</strong><a href="#contents">สารบัญ</a><button onclick="window.print()">พิมพ์ / บันทึก PDF</button></nav>{"".join(rendered)}</body></html>'
    (ROOT / (name + '.html')).write_text(document)
    print(f'Built {name}.html: {len(rendered)} sections; all images/fonts embedded')


if __name__ == '__main__':
    build('WI-IT-003', contents=True, revision='00')
    if (ROOT / 'SYSTEM-CHECK-2026-10-28.md').exists():
        build('SYSTEM-CHECK-2026-10-28')
