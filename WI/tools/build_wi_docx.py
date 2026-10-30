#!/usr/bin/env python3
"""Build WI-IT-003 from the user-supplied Word template (stdlib only).

Source: WI/wi-content.json. PDF must be rendered from this Word document.
The supplied template's styles, section, paragraph and table formatting are
copied directly; the E-Procurement body, media and personal names are replaced.
"""
import argparse
from copy import deepcopy
import hashlib
import io
import json
from pathlib import Path
import re
import struct
from xml.etree import ElementTree as E
from zipfile import ZipFile, ZIP_DEFLATED

ROOT = Path(__file__).resolve().parents[1]
W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
PKG = 'http://schemas.openxmlformats.org/package/2006/relationships'
WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing'
A = 'http://schemas.openxmlformats.org/drawingml/2006/main'
PIC = 'http://schemas.openxmlformats.org/drawingml/2006/picture'
NS = {'w': W, 'r': R, 'wp': WP, 'a': A, 'pic': PIC}
q = lambda name: '{' + NS[name.split(':')[0]] + '}' + name.split(':')[1]


def xml(data):
    for prefix, uri in E.iterparse(io.BytesIO(data), events=['start-ns']):
        E.register_namespace(uri[0], uri[1])
    return E.fromstring(data)


def serialize(root, original):
    # Retain namespace declarations referenced only by mc:Ignorable as well.
    out = E.tostring(root, encoding='unicode')
    opening = out[:out.index('>')]
    for prefix, uri in re.findall(r'xmlns:([\w]+)="([^"]+)"', original.decode()):
        if 'xmlns:' + prefix + '=' not in opening:
            opening += ' xmlns:' + prefix + '="' + uri + '"'
    return ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
            + opening + out[out.index('>'):]).encode()


class Builder:
    def __init__(self, template):
        self.template = template
        with ZipFile(template) as z:
            self.parts = {n: z.read(n) for n in z.namelist()}
        self.doc = xml(self.parts['word/document.xml'])
        self.body = self.doc.find('w:body', NS)
        self.sample = list(self.body)
        self.section = deepcopy(self.sample[-1])
        self.rels = xml(self.parts['word/_rels/document.xml.rels'])
        for r in list(self.rels):
            if r.get('Type', '').endswith('/image'):
                self.rels.remove(r)
        self.parts = {n: b for n, b in self.parts.items() if not n.startswith('word/media/')}
        self.media = {}
        self.drawing_id = 0
        self.planned_pages = 0
        self.starts = {}
        self.md = []
        for child in list(self.body):
            self.body.remove(child)

    def para(self, text='', sample=19, pagebreak=None, bookmark=None):
        source = self.sample[sample]
        p = E.Element(q('w:p'))
        pr = source.find('w:pPr', NS)
        if pr is not None:
            p.append(deepcopy(pr))
        else:
            pr = E.SubElement(p, q('w:pPr'))
        pr = p.find('w:pPr', NS)
        if pagebreak is not None:
            for b in pr.findall('w:pageBreakBefore', NS):
                pr.remove(b)
            if pagebreak:
                E.SubElement(pr, q('w:pageBreakBefore'))
        if bookmark is not None:
            E.SubElement(p, q('w:bookmarkStart'), {q('w:id'): str(bookmark), q('w:name'): 'ITHUB_SECTION_' + str(bookmark)})
        run = E.SubElement(p, q('w:r'))
        rp = source.find('.//w:rPr', NS)
        if rp is not None:
            run.append(deepcopy(rp))
        for i, line in enumerate(str(text).split('\n')):
            if i:
                E.SubElement(run, q('w:br'))
            E.SubElement(run, q('w:t'), {'{http://www.w3.org/XML/1998/namespace}space': 'preserve'}).text = line
        if bookmark is not None:
            E.SubElement(p, q('w:bookmarkEnd'), {q('w:id'): str(bookmark)})
        return p

    def cell_text(self, cell, text):
        source = cell.find('w:p', NS)
        if source is None:
            source = self.para('')
        p = deepcopy(source)
        for e in list(p):
            if e.tag != q('w:pPr'):
                p.remove(e)
        rp = source.find('.//w:rPr', NS)
        run = E.SubElement(p, q('w:r'))
        if rp is not None:
            run.append(deepcopy(rp))
        for i, line in enumerate(str(text).split('\n')):
            if i:
                E.SubElement(run, q('w:br'))
            E.SubElement(run, q('w:t'), {'{http://www.w3.org/XML/1998/namespace}space': 'preserve'}).text = line
        for old in list(cell):
            if old.tag != q('w:tcPr'):
                cell.remove(old)
        cell.append(p)

    def table(self, rows, sample=None):
        sample = sample if sample is not None else (28 if len(rows[0]) == 2 else 44)
        t = deepcopy(self.sample[sample])
        original_rows = t.findall('w:tr', NS)
        for row in original_rows:
            t.remove(row)
        for i, values in enumerate(rows):
            row = deepcopy(original_rows[0 if i == 0 else 1])
            cells = row.findall('w:tc', NS)
            assert len(cells) == len(values), (sample, len(cells), len(values))
            for cell, value in zip(cells, values):
                self.cell_text(cell, value)
            t.append(row)
        # Copied Word paragraph/revision IDs must not be reused in clones.
        self.strip_ids(t)
        return t

    @staticmethod
    def strip_ids(root):
        for el in root.iter():
            for attr in list(el.attrib):
                if attr.split('}')[-1] in ('paraId', 'textId', 'anchorId', 'editId') or 'rsid' in attr:
                    del el.attrib[attr]

    def figure(self, block):
        path = ROOT / block['file']
        data = path.read_bytes()
        width, height = struct.unpack('>II', data[16:24])
        if path.name not in self.media:
            rid = 'rIdITHUBImage' + str(len(self.media) + 1)
            self.media[path.name] = rid
            E.SubElement(self.rels, '{' + PKG + '}Relationship',
                         {'Id': rid, 'Type': R + '/image', 'Target': 'media/' + path.name})
            self.parts['word/media/' + path.name] = data
        rid = self.media[path.name]
        self.drawing_id += 1
        p = deepcopy(self.sample[55])
        self.strip_ids(p)
        for el in p.findall('.//w:lastRenderedPageBreak', NS):
            for parent in p.iter():
                if el in list(parent):
                    parent.remove(el)
                    break
        scale = min(10090 * 635 / width, 92 * 36000 / height)
        cx, cy = round(width * scale), round(height * scale)
        for el in p.findall('.//wp:extent', NS) + p.findall('.//a:ext', NS):
            el.set('cx', str(cx)); el.set('cy', str(cy))
        for el in p.findall('.//wp:docPr', NS) + p.findall('.//pic:cNvPr', NS):
            el.set('id', str(self.drawing_id)); el.set('name', path.stem)
            el.set('descr', block['caption'])
        p.find('.//a:blip', NS).set(q('r:embed'), rid)
        self.body.append(p)
        self.body.append(self.para('ภาพที่ ' + str(self.drawing_id) + ' ' + block['caption'], 56))
        if block.get('focus'):
            self.body.append(self.para(block['focus'], 57))

    def append_block(self, b):
        kind = b['type']
        if kind in ('p', 'h'):
            self.body.append(self.para(b['text'], 18 if kind == 'h' else 19))
            self.md.append(('### ' if kind == 'h' else '') + b['text'] + '\n')
        elif kind == 'steps':
            for i, text in enumerate(b['items'], b.get('start', 1)):
                self.body.append(self.para(str(i) + '. ' + text, 58))
                self.md.append(str(i) + '. ' + text)
            self.md.append('')
        elif kind == 'table':
            self.body.append(self.table(b['rows']))
            self.body.append(self.para('', 4))
            self.md.append('| ' + ' | '.join(b['rows'][0]) + ' |')
            self.md.append('| ' + ' | '.join(['---'] * len(b['rows'][0])) + ' |')
            for row in b['rows'][1:]:
                self.md.append('| ' + ' | '.join(str(x).replace('\n', ' / ') for x in row) + ' |')
            self.md.append('')
        elif kind == 'figure':
            self.figure(b)
            self.md.append('![' + b['caption'] + '](' + b['file'] + ')\n')
            if b.get('focus'):
                self.md.append(b['focus'] + '\n')
        else:
            raise ValueError(kind)

    def build(self, content, pagination=None):
        rows = [['รายการ', 'รายละเอียด'], ['หมายเลขเอกสาร', 'WI-IT-003'], ['แก้ไขครั้งที่', '00'],
                ['วันที่จัดทำ / ตรวจสอบ', '29 ตุลาคม 2569'], ['วันที่มีผลบังคับใช้', ''],
                ['หน่วยงานเจ้าของ', 'ฝ่ายเทคโนโลยีสารสนเทศ']]
        self.body.append(self.para('คู่มือการใช้งานระบบ IT Hub', 0))
        self.body.append(self.para('WORK INSTRUCTIONS • ศูนย์บริการเทคโนโลยีสารสนเทศ', 1))
        self.body.append(self.para('เอกสารควบคุมสำหรับทบทวน', 2))
        self.body.append(self.table(rows, 3))
        self.body.append(self.para('', 4))
        self.body.append(self.para('จัดทำตามรูปแบบเอกสาร WI-IT-002 โดยใช้ข้อมูล ขั้นตอน เมนู และภาพประกอบของระบบ IT Hub สำหรับผู้ร้องขอ ผู้อนุมัติ และเจ้าหน้าที่ IT', 5))
        self.body.append(self.table([['ผู้จัดทำ', 'ผู้ทบทวน', 'ผู้อนุมัติ'], ['ชื่อ', 'ชื่อ', 'ชื่อ'],
                                     ['ตำแหน่ง', 'ตำแหน่ง', 'ตำแหน่ง'], ['วันที่', 'วันที่', 'วันที่']], 6))
        self.body.append(self.para('', 7))
        self.body.append(self.para('ภาพประกอบจากหน้าจอ IT Hub ใช้ข้อมูลสมมติและจำลองผลตอบกลับเพื่อแสดงขั้นตอน ไม่มีการสร้างหรือเปลี่ยนใบงานจริงระหว่างปรับเอกสาร', 8))
        self.body.append(self.para('จุดเข้าใช้งาน: https://abms.ddns.net:8445/it-hub/\nปรับรูปแบบเอกสาร: 29 ตุลาคม 2569 • ผลตรวจระบบเดิม: 28 ตุลาคม 2569', 9))
        self.md = ['# WI-IT-003 — คู่มือการใช้งานระบบ IT Hub\n', 'ฉบับแก้ไข 00 • ปรับเอกสาร 29 ตุลาคม 2569\n',
                   'ใช้รูปแบบ WI-IT-002 E-Procurement.docx; ผู้จัดทำ ผู้ทบทวน ผู้อนุมัติ และวันที่มีผลบังคับใช้เว้นว่าง\n']
        self.body.append(self.para('สารบัญและวิธีใช้คู่มือ', 10))
        self.body.append(self.para('เลือกอ่านตามหน้าที่และขั้นตอนงาน', 11))
        page = 3
        for i, section in enumerate(content['sections']):
            self.starts[i] = page
            page += len(section['pages'])
        self.planned_pages = page - 1
        toc = self.table([['หัวข้อ', 'หน้า']] + [[s['title'], str((pagination or self.starts).get(str(i), self.starts[i]))]
                                               for i, s in enumerate(content['sections'])], 12)
        for i, row in enumerate(toc.findall('w:tr', NS)[1:]):
            cell = row.findall('w:tc', NS)[1]
            p = cell.find('w:p', NS)
            run = p.find('w:r', NS)
            p.remove(run)
            fld = E.SubElement(p, q('w:fldSimple'), {q('w:instr'): 'PAGEREF ITHUB_SECTION_' + str(i) + ' \\h'})
            fld.append(run)
        self.body.append(toc)
        self.body.append(self.para('', 13))
        self.body.append(self.para('ผู้ร้องขออ่านขั้นตอนบริการที่เลือก ผู้อนุมัติอ่านขั้นลงนาม เจ้าหน้าที่ IT อ่านการรับเรื่อง ปิดงาน ทะเบียน เอกสาร และ NAS ภาพใช้ข้อมูลตัวอย่าง ไม่ใช่หลักฐานรายการจริง', 14))
        self.body.append(self.para('ผลตรวจระบบวันที่ 28 ตุลาคม 2569 อยู่ในรายงาน SYSTEM-CHECK-2026-10-28 ส่วนขั้นตอน Import Excel และตรวจรหัสซ้ำทบทวนจากโค้ดวันที่ 29 ตุลาคม 2569 การปรับเอกสารครั้งนี้ไม่ได้ทดสอบธุรกรรมจริงซ้ำ', 15))
        for i, section in enumerate(content['sections']):
            self.md.append('# ' + section['title'] + '\n')
            self.md.append(section['lead'] + '\n')
            for j, blocks in enumerate(section['pages']):
                self.body.append(self.para(section['title'] + (' (ต่อ)' if j else ''), 10, bookmark=i if not j else None))
                if not j:
                    self.body.append(self.para(section['lead'], 54))
                for b in blocks:
                    self.append_block(b)
        self.body.append(self.section)
        self.parts['word/document.xml'] = serialize(self.doc, self.parts['word/document.xml'])
        self.parts['word/_rels/document.xml.rels'] = E.tostring(self.rels, encoding='utf-8', xml_declaration=True)
        hdr = xml(self.parts['word/header1.xml'])
        for cell, value in zip(hdr.findall('.//w:tc', NS), ['WORK INSTRUCTIONS\nIT HUB', 'คู่มือการใช้งานระบบ IT Hub\nจัดทำ 29 ตุลาคม 2569', 'WI-IT-003\nแก้ไขครั้งที่ 00']):
            self.cell_text(cell, value)
        self.parts['word/header1.xml'] = serialize(hdr, self.parts['word/header1.xml'])
        ftr = xml(self.parts['word/footer1.xml'])
        for t in ftr.findall('.//w:t', NS):
            if t.text:
                t.text = t.text.replace('WI-IT-002', 'WI-IT-003')
        texts = ftr.findall('.//w:t', NS)
        texts[-1].text = str(self.planned_pages)
        self.parts['word/footer1.xml'] = serialize(ftr, self.parts['word/footer1.xml'])
        if 'docProps/core.xml' in self.parts:
            core = xml(self.parts['docProps/core.xml'])
            for el in core:
                key = el.tag.split('}')[-1]
                if key == 'title': el.text = 'WI-IT-003 คู่มือการใช้งานระบบ IT Hub'
                elif key in ('creator', 'lastModifiedBy', 'description', 'subject', 'keywords'): el.text = ''
            self.parts['docProps/core.xml'] = E.tostring(core, encoding='utf-8', xml_declaration=True)
        if 'docProps/app.xml' in self.parts:
            app = xml(self.parts['docProps/app.xml'])
            for el in app.iter():
                key = el.tag.split('}')[-1]
                if key == 'Pages': el.text = str(self.planned_pages)
                elif key in ('Company', 'Manager'): el.text = ''
            self.parts['docProps/app.xml'] = E.tostring(app, encoding='utf-8', xml_declaration=True)
        settings = xml(self.parts['word/settings.xml'])
        update = settings.find('w:updateFields', NS)
        if update is None: update = E.SubElement(settings, q('w:updateFields'))
        update.set(q('w:val'), 'true')
        self.parts['word/settings.xml'] = serialize(settings, self.parts['word/settings.xml'])
        target = ROOT / 'WI-IT-003.docx'
        with ZipFile(target, 'w', ZIP_DEFLATED) as z:
            for name, data in self.parts.items(): z.writestr(name, data)
        (ROOT / 'WI-IT-003.md').write_text('\n'.join(self.md).rstrip() + '\n')
        plan = {'template': self.template.name, 'template_sha256': hashlib.sha256(self.template.read_bytes()).hexdigest(),
                'pages': self.planned_pages, 'sections': len(content['sections']), 'embedded_images': len(self.media),
                'section_starts': {str(i): (pagination or self.starts).get(str(i), v) for i, v in self.starts.items()}}
        (ROOT / 'validation/word-build.json').write_text(json.dumps(plan, ensure_ascii=False, indent=2) + '\n')
        print(json.dumps(plan, ensure_ascii=False))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--template', type=Path, default=ROOT.parent / 'WI-IT-002 E-Procurement.docx')
    parser.add_argument('--pagination', type=Path)
    args = parser.parse_args()
    content = json.loads((ROOT / 'wi-content.json').read_text())
    pagination = json.loads(args.pagination.read_text()) if args.pagination else None
    Builder(args.template).build(content, pagination)


if __name__ == '__main__':
    main()
