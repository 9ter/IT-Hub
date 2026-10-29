#!/usr/bin/env python3
"""Read historical FM-IT records, never signatures, using only Python's standard library.

The JSON output contains private workbook data. Keep it outside the web/Git roots.
Blank request templates are catalog definitions, not fabricated request records.
"""
import argparse
import datetime as dt
import hashlib
import json
import re
from pathlib import Path
from zipfile import ZipFile
from xml.etree import ElementTree as ET

NS = {'m': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}


def workbooks(path):
    with ZipFile(path) as archive:
        strings = []
        if 'xl/sharedStrings.xml' in archive.namelist():
            strings = [''.join(t.text or '' for t in s.findall('.//m:t', NS))
                       for s in ET.fromstring(archive.read('xl/sharedStrings.xml')).findall('m:si', NS)]
        relations = {r.attrib['Id']: r.attrib['Target'] for r in ET.fromstring(archive.read('xl/_rels/workbook.xml.rels'))}
        for sheet in ET.fromstring(archive.read('xl/workbook.xml')).findall('m:sheets/m:sheet', NS):
            target = relations[sheet.attrib['{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id']]
            filename = target.lstrip('/') if target.startswith('/') else 'xl/' + target
            root = ET.fromstring(archive.read(filename))
            cells = {}
            for c in root.findall('.//m:sheetData/m:row/m:c', NS):
                v, inline = c.find('m:v', NS), c.find('m:is', NS)
                value = (strings[int(v.text)] if c.get('t') == 's' and v is not None
                         else ''.join(inline.itertext()) if inline is not None
                         else v.text if v is not None else '')
                cells[c.attrib['r']] = str(value).strip()
            yield sheet.attrib['name'], cells


def date_text(value):
    """Keep the year as written in Excel (including Buddhist years entered as AD)."""
    if re.fullmatch(r'\d+(\.\d+)?', value or '') and float(value) > 20000:
        date = dt.datetime(1899, 12, 30) + dt.timedelta(days=float(value))
        year = date.year if date.year >= 2400 else date.year + 543
        return f'{date.day:02d}/{date.month:02d}/{year}'
    return value


def time_text(value):
    try:
        n = float(value)
        if 0 <= n < 1:
            seconds = round(n * 86400)
            return f'{seconds // 3600:02d}:{seconds // 60 % 60:02d}:{seconds % 60:02d}'
    except ValueError:
        pass
    return value


def parse_record(code, name, c):
    fields, rows, warnings = {}, [], []
    year = re.search(r'25\d\d', name)
    if not year:
        short = re.search(r'ปี\s*(\d{2})', name)
        year_text = '25' + short[1] if short else ''
    else:
        year_text = year[0]
    if code == 'FM-IT-001':
        for r in range(4, 200):
            if not c.get(f'A{r}', '').isdigit() or not c.get(f'B{r}'):
                continue
            rows.append(dict(asset_code=c[f'B{r}'], holder=c.get(f'C{r}', ''), model=c.get(f'D{r}', ''),
                             serial=c.get(f'E{r}', ''), department=c.get(f'F{r}', ''),
                             registered=date_text(c.get(f'G{r}', '')), type=c.get(f'I{r}', ''),
                             notes=' / '.join(filter(None, [c.get(f'H{r}'), c.get(f'J{r}')]))))
        warnings.append('เป็นสำเนาทะเบียนใน Excel ไม่เพิ่มอุปกรณ์ซ้ำและไม่ทับทะเบียนปัจจุบันของ IT Hub')
    elif code == 'FM-IT-002':
        fields['year'] = year_text
        for r in range(4, 200):
            if c.get(f'B{r}') in ['วันที่ตรวจ', 'ผู้ตรวจสอบ', 'ผู้ทบทวน']:
                continue
            asset, item = c.get(f'C{r}', ''), c.get(f'B{r}', '')
            if asset.startswith('FM-IT-') or (not asset and not item):
                continue
            if 'FM-IT-' in item:
                continue
            row = {'item': item or 'อุปกรณ์ (ต้นฉบับไม่ระบุประเภท)', 'asset_code': asset, 'notes': c.get(f'P{r}', '')}
            if not asset:
                row['notes'] = (row['notes'] + ' ต้นฉบับไม่ระบุหมายเลขเครื่อง').strip()
            for m, col in enumerate('DEFGHIJKLMNO', 1):
                raw = c.get(f'{col}{r}', '')
                row[f'm{m}'] = {'/': 'normal', 'X': 'abnormal', 'x': 'abnormal', '✓': 'fixed'}.get(raw, '')
                if raw and not row[f'm{m}']:
                    row['notes'] += f' เดือน {m}: {raw}'
            rows.append(row)
        details = []
        for addr, label in c.items():
            if addr.startswith('B') and label in ['วันที่ตรวจ', 'ผู้ตรวจสอบ', 'ผู้ทบทวน']:
                r = int(addr[1:])
                for m, col in enumerate('DEFGHIJKLMNO', 1):
                    if c.get(f'{col}{r}'):
                        value = date_text(c[f'{col}{r}']) if label == 'วันที่ตรวจ' else c[f'{col}{r}']
                        details.append(f'เดือน {m} — {label}: {value}')
        fields['inspection_metadata'] = '\n'.join(details)
        warnings.append('เครื่องหมาย / และ X นำเข้าตามข้อความในเซลล์ เส้นทับ/สีใน Excel ต้องตรวจทานเอง ไม่ถือเป็นผลรับรองหรือลายเซ็น')
    elif code == 'FM-IT-005':
        fields.update(year=year_text, server='SERVER')
        for r in range(6, 200):
            if not c.get(f'B{r}') or c.get(f'A{r}', '').startswith('FM-IT-'):
                continue
            date = date_text(c.get(f'A{r}', ''))
            row = dict(date=date, dataset=c.get(f'B{r}', ''), size=c.get(f'C{r}', ''), source=c.get(f'D{r}', ''),
                       device=c.get(f'E{r}', ''), folder=c.get(f'F{r}', ''), backup_size=c.get(f'G{r}', ''),
                       started=time_text(c.get(f'H{r}', '')), finished=time_text(c.get(f'I{r}', '')),
                       maker=c.get(f'J{r}', ''), acknowledger=c.get(f'K{r}', ''), notes=c.get(f'L{r}', ''))
            if date and not re.fullmatch(r'\d{2}/\d{2}/25\d{2}', date):
                row['notes'] = (row['notes'] + f' [ตรวจทานวันที่ต้นทาง: {date}]').strip()
                warnings.append('มีวันที่ต้นทางไม่สมบูรณ์ เก็บข้อความเดิมพร้อมหมายเหตุ ไม่เดาปีที่ถูกต้อง')
            if any(t and not re.fullmatch(r'\d{2}:\d{2}:\d{2}', t) for t in [row['started'], row['finished']]):
                row['notes'] += ' [ตรวจทานเวลาที่ต้นทางพิมพ์เป็นข้อความ]'
            rows.append(row)
    elif code == 'FM-IT-006' and year_text:
        fields.update(year=year_text, team='ฝ่ายเทคโนโลยีสารสนเทศ')
        frequency = responsible = ''
        for r in range(6, 28):
            task = c.get(f'D{r}', '')
            if not task:
                continue
            frequency = c.get(f'EA{r}') or frequency
            responsible = c.get(f'EI{r}') or responsible
            rows.append(dict(task=task, frequency=frequency, responsible=responsible))
        warnings.append('นำเข้าหัวข้อ ความถี่ และผู้รับผิดชอบตามต้นฉบับ ตาราง P/A ที่ใช้เส้น/ลายพื้นให้ตรวจทาน Excel และกรอกสัปดาห์ก่อนรับรอง ไม่ตีความสีเป็นงานที่ทำเสร็จ')
    return fields, rows, list(dict.fromkeys(warnings))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('directory', type=Path)
    parser.add_argument('output', type=Path)
    args = parser.parse_args()
    records = []
    for code in ['FM-IT-001', 'FM-IT-002', 'FM-IT-005', 'FM-IT-006']:
        for path in sorted(args.directory.glob(code + ' *.xlsx')):
            for name, cells in workbooks(path):
                fields, rows, warnings = parse_record(code, name, cells)
                if not rows:
                    continue
                company = 'บริษัท อัตถสาร อินโนเวชั่น จำกัด' if 'INNO' in name else 'บริษัท อัตถสาร จำกัด'
                key = 'xlsx:' + hashlib.sha256((path.name + '#' + name).encode()).hexdigest()
                note = f'นำเข้าฉบับร่างจาก {path.name} / Sheet: {name}\nSHA-256: {hashlib.sha256(path.read_bytes()).hexdigest()}\n' + '\n'.join(warnings)
                records.append(dict(form_code=code, title=f'{code} — {name} (ข้อมูลจาก Excel)', company=company,
                                    document_date=dt.date.today().isoformat(), source_key=key, source_note=note,
                                    data=dict(fields=fields, rows=rows, signers={})))
    args.output.write_text(json.dumps(records, ensure_ascii=False, indent=2))
    args.output.chmod(0o600)
    print(f'Prepared {len(records)} draft documents, {sum(len(r["data"]["rows"]) for r in records)} rows; no signatures or approvals inferred.')


if __name__ == '__main__':
    main()
