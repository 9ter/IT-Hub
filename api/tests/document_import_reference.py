#!/usr/bin/env python3
"""Print per-sheet row counts from the existing CLI reader, as a parity reference for document_import.php."""
import importlib.util
import json
import sys
from pathlib import Path

spec = importlib.util.spec_from_file_location('reader', Path(__file__).resolve().parent.parent / 'bin' / 'read-fm-workbooks.py')
reader = importlib.util.module_from_spec(spec)
spec.loader.exec_module(reader)
out = {}
for code in ['FM-IT-001', 'FM-IT-002', 'FM-IT-005', 'FM-IT-006']:
    out[code] = []
    for path in sorted(Path(sys.argv[1]).glob(code + ' *.xlsx'))[:1]:
        for name, cells in reader.workbooks(path):
            _, rows, _ = reader.parse_record(code, name, cells)
            if rows:
                out[code].append([name, len(rows)])
print(json.dumps(out, ensure_ascii=False))
