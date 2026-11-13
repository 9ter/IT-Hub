<?php
declare(strict_types=1);

/*
 * Import Excel into IT documents as drafts only. Never infers signatures, approvals or signers;
 * FM-IT-008 records stay behind the Admin IT Hub borrow approval.
 */
const DOCUMENT_IMPORT_MAX_BYTES = 5 * 1024 * 1024;
const DOCUMENT_IMPORT_MARKER = 'IT-HUB-DOCUMENT-IMPORT';
const DOCUMENT_IMPORT_ORIGINAL = ['FM-IT-002', 'FM-IT-005', 'FM-IT-006'];
const DOCUMENT_IMPORT_FIELDS_SHEET = 'ข้อมูลเอกสาร';
const DOCUMENT_IMPORT_ROWS_SHEET = 'รายการ';
const DOCUMENT_IMPORT_OPTIONS_SHEET = 'ตัวเลือก';

function document_import_definition(string $code): array
{
    $def = document_forms()[$code] ?? fail('ไม่พบแบบฟอร์ม', 404);
    if (!empty($def['record'])) fail('FM-IT-008 ต้องส่งคำขอเบิก–ยืมให้ Admin IT Hub อนุมัติก่อน จึงไม่รองรับ Import Excel', 409);
    // FM-IT-001 is the live asset register (/admin/assets); importing it here would only make a detached draft copy.
    if ($code === 'FM-IT-001') fail('FM-IT-001 คือทะเบียนอุปกรณ์ กรุณาใช้ Import Excel ในหน้าทะเบียนอุปกรณ์ เพื่อปรับทะเบียนจริง', 409);
    return $def;
}

function document_import_default_company(string $code): string
{
    return $code === 'FM-IT-007.1' ? 'บริษัท อัตถสาร อินโนเวชั่น จำกัด' : 'บริษัท อัตถสาร จำกัด';
}

/** Read every sheet as address => trimmed text (cached values), bounded like the asset import. */
function document_import_read(string $path): array
{
    if (!is_file($path) || filesize($path) > DOCUMENT_IMPORT_MAX_BYTES) fail('ไฟล์ต้องมีขนาดไม่เกิน 5 MB', 422);
    if (!class_exists(ZipArchive::class) || !extension_loaded('simplexml')) fail('เซิร์ฟเวอร์ยังไม่รองรับการอ่าน Excel', 503);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) fail('กรุณาเลือกไฟล์ Excel .xlsx ที่สมบูรณ์', 422);
    try {
        if ($zip->numFiles > 1024) fail('ไฟล์ Excel มีส่วนประกอบมากเกินไป', 422);
        $bytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $bytes += $stat['size'];
            if ($bytes > 32 * 1024 * 1024 || $stat['size'] > 8 * 1024 * 1024) fail('ข้อมูลภายใน Excel มีขนาดใหญ่เกินไป', 422);
            if (preg_match('/vbaProject\.bin$/i', $stat['name'])) fail('ไม่รองรับไฟล์ Excel ที่มี Macro', 422);
        }
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $text = function (SimpleXMLElement $node) use ($ns): string {
            // Plain and rich-text runs only; phonetic guide text (rPh) is not cell content.
            $node->registerXPathNamespace('m', $ns);
            return implode('', array_map('strval', $node->xpath('./m:t | ./m:r/m:t')));
        };
        $strings = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            foreach (asset_import_xml($zip, 'xl/sharedStrings.xml')->children($ns)->si as $si) {
                $strings[] = $text($si);
                if (count($strings) > 200000) fail('ข้อความใน Excel มีจำนวนมากเกินไป', 422);
            }
        }
        $relations = [];
        foreach (asset_import_xml($zip, 'xl/_rels/workbook.xml.rels')->children('http://schemas.openxmlformats.org/package/2006/relationships') as $rel) {
            if ((string)$rel->attributes()['TargetMode'] === 'External') continue;
            $target = (string)$rel->attributes()['Target'];
            $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            if (preg_match('#^xl/worksheets/[A-Za-z0-9_.-]+\.xml$#D', $target)) $relations[(string)$rel->attributes()['Id']] = $target;
        }
        $workbook = asset_import_xml($zip, 'xl/workbook.xml');
        $properties = $workbook->children($ns)->workbookPr;
        $date1904 = count($properties) > 0 && in_array((string)$properties->attributes()['date1904'], ['1', 'true'], true);
        $list = $workbook->children($ns)->sheets->sheet;
        if (count($list) > 32) fail('Excel ต้องมีไม่เกิน 32 Sheet', 422);
        $sheets = []; $cellCount = 0;
        foreach ($list as $sheet) {
            $rid = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if (!isset($relations[$rid])) continue; // chart sheets and external parts are not data
            $cells = [];
            foreach (asset_import_xml($zip, $relations[$rid])->children($ns)->sheetData->row as $row) {
                foreach ($row->children($ns)->c as $cell) {
                    if (++$cellCount > 200000) fail('Excel มีจำนวนเซลล์มากเกินไป', 422);
                    $address = (string)$cell->attributes()['r'];
                    if (!preg_match('/^[A-Z]{1,3}[1-9]\d{0,6}$/D', $address)) fail('ตำแหน่งเซลล์ Excel ไม่ถูกต้อง', 422);
                    $child = $cell->children($ns); $type = (string)$cell->attributes()['t'];
                    if ($type === 's') {
                        $index = (string)$child->v;
                        if (!ctype_digit($index) || !array_key_exists((int)$index, $strings)) fail('ข้อความใน Excel เสียหาย', 422);
                        $value = $strings[(int)$index];
                    } elseif ($type === 'inlineStr') {
                        $value = isset($child->is) ? $text($child->is) : '';
                    } elseif ($type === 'e') {
                        $value = '';
                    } else {
                        $value = (string)$child->v;
                    }
                    if (mb_strlen($value) > 12000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) fail("เซลล์ $address มีข้อความยาวเกินไปหรือมีอักขระที่ไม่รองรับ", 422);
                    $value = trim(str_replace("\r\n", "\n", $value));
                    if ($value !== '') $cells[$address] = $value;
                }
            }
            $sheets[] = ['name' => (string)$sheet->attributes()['name'], 'cells' => $cells];
        }
        return ['sheets' => $sheets, 'date1904' => $date1904];
    } finally { $zip->close(); }
}

/** Excel serial, d/m/yyyy (พ.ศ. or ค.ศ.) or yyyy-mm-dd, with an optional HH:MM time. */
function document_import_datetime(string $value, bool $date1904, bool $withTime): ?string
{
    if ($value === '' || $value === '-') return null;
    $time = '00:00';
    if (preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
        if ((float)$value < 1 || (float)$value > 300000) fail('วันที่อยู่นอกช่วงที่รองรับ', 422);
        $seconds = (int)round((float)$value * 86400);
        $date = (new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30'))->modify("+$seconds seconds");
        [$y, $m, $d] = [(int)$date->format('Y'), (int)$date->format('m'), (int)$date->format('d')];
        $time = $date->format('H:i');
    } elseif (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})(?:[ T]+(\d{1,2})[:.](\d{2})(?::\d{2})?)?$/D', $value, $p)) {
        [$y, $m, $d] = [(int)$p[3], (int)$p[2], (int)$p[1]];
        if (isset($p[4])) $time = sprintf('%02d:%s', (int)$p[4], $p[5]);
    } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::\d{2})?)?$/D', $value, $p)) {
        [$y, $m, $d] = [(int)$p[1], (int)$p[2], (int)$p[3]];
        if (isset($p[4])) $time = $p[4] . ':' . $p[5];
    } else {
        fail($withTime ? 'วันเวลาไม่ถูกต้อง ใช้ วว/ดด/ปปปป ชช:นน' : 'วันที่ไม่ถูกต้อง ใช้ วว/ดด/ปปปป', 422);
    }
    if ($y >= 2400) $y -= 543;
    [$h, $i] = array_map('intval', explode(':', $time));
    if ($y < 1970 || $y > 2100 || !checkdate($m, $d, $y) || $h > 23 || $i > 59) fail('วันที่ไม่ถูกต้อง', 422);
    return sprintf('%04d-%02d-%02d', $y, $m, $d) . ($withTime ? sprintf(' %02d:%02d', $h, $i) : '');
}

/** Keep the year as written (Buddhist years entered as AD stay readable), like the CLI reader. */
function document_import_date_text(string $value, bool $date1904 = false): string
{
    if (preg_match('/^\d+(?:\.\d+)?$/D', $value) && (float)$value > 20000 && (float)$value < 300000) {
        $date = (new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30'))->modify('+' . (int)(float)$value . ' days');
        $year = (int)$date->format('Y');
        return $date->format('d/m/') . ($year >= 2400 ? $year : $year + 543);
    }
    return $value;
}

function document_import_time_text(string $value): string
{
    // Excel may store a time as 0.0416… or in scientific notation (4.16E-2); same rule as the CLI reader.
    if (preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?$/D', $value) && (float)$value >= 0 && (float)$value < 1) {
        $s = (int)round((float)$value * 86400);
        return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s, 60) % 60, $s % 60);
    }
    return $value;
}

function document_import_option(array $field, string $value): string
{
    $norm = fn(string $s): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
    foreach ($field['options'] as $key => $label) {
        if ($norm((string)$key) === $norm($value) || $norm($label) === $norm($value)) return (string)$key;
    }
    fail('ไม่มีตัวเลือก “' . mb_substr($value, 0, 60) . '” ให้เลือกจากรายการ: ' . implode(', ', $field['options']), 422);
}

/** Convert one Excel cell to the value document_value() expects. */
function document_import_value(array $field, string $raw, bool $date1904): mixed
{
    if ($raw === '') return $field['type'] === 'multiselect' ? [] : null;
    switch ($field['type']) {
        case 'select': return document_import_option($field, $raw);
        case 'multiselect':
            $whole = null;
            try { $whole = document_import_option($field, $raw); } catch (HttpError) {}
            if ($whole !== null) return [$whole];
            return array_values(array_unique(array_map(fn($part) => document_import_option($field, $part),
                array_filter(array_map('trim', preg_split('/[,;\n]+/u', $raw)), 'strlen'))));
        case 'date': return document_import_datetime($raw, $date1904, false);
        case 'datetime-local': return document_import_datetime($raw, $date1904, true);
        case 'number':
            if (preg_match('/^(\d+)(?:\.0+)?$/D', $raw, $m)) return $m[1];
            fail('กรุณากรอกตัวเลขจำนวนเต็ม', 422);
        case 'decimal':
            $plain = str_replace(',', '', $raw);
            if (!is_numeric($plain) || (float)$plain < 0) fail('กรุณากรอกตัวเลขที่ไม่ติดลบ', 422);
            return rtrim(rtrim(number_format(round((float)$plain, 2), 2, '.', ''), '0'), '.');
    }
    // Text columns that hold dates/times in the original layouts: keep the written year.
    if (preg_match('/^(date|registered)$/', $field['key'])) return document_import_date_text($raw, $date1904);
    if (preg_match('/^(started|finished)$/', $field['key'])) return document_import_time_text($raw);
    return $raw;
}

/** Validate one value with the catalog rules; returns [value, error|null]. Drafts don't enforce required. */
function document_import_check(array $field, string $raw, bool $date1904): array
{
    try {
        $value = document_import_value($field, $raw, $date1904);
        return [document_value($field, [$field['key'] => $value], false), null];
    } catch (HttpError $e) {
        return [null, $field['label'] . ': ' . implode(' / ', $e->errors ?: [$e->getMessage()])];
    }
}

function document_import_cell_columns(array $cells, int $row): array
{
    $out = [];
    foreach ($cells as $address => $value) {
        if (preg_match('/^([A-Z]+)(\d+)$/D', $address, $m) && (int)$m[2] === $row) $out[$m[1]] = $value;
    }
    return $out;
}

/** Parse the IT Hub template (one document per workbook). */
function document_import_from_template(array $def, array $book, array $markerSheet): array
{
    $code = $def['code']; $cells = $markerSheet['cells']; $date1904 = $book['date1904'];
    $errors = []; $warnings = []; $fields = [];
    $byKey = [];
    foreach ($def['fields'] as $f) $byKey[$f['key']] = $f;
    $meta = ['_title' => '', '_document_date' => '', '_company' => ''];
    $maxRow = 0;
    foreach (array_keys($cells) as $address) $maxRow = max($maxRow, (int)preg_replace('/^[A-Z]+/', '', $address));
    for ($r = 4; $r <= $maxRow; $r++) {
        $key = $cells["A$r"] ?? ''; $raw = $cells["C$r"] ?? '';
        if ($key === '') { if ($raw !== '') $errors[] = "แถว $r: ไม่มีรหัสช่องในคอลัมน์ A"; continue; }
        if (array_key_exists($key, $meta)) { $meta[$key] = $raw; continue; }
        if (!isset($byKey[$key])) { $errors[] = "แถว $r: ไม่รู้จักรหัสช่อง $key (อย่าแก้คอลัมน์ A)"; continue; }
        [$value, $error] = document_import_check($byKey[$key], $raw, $date1904);
        if ($error) $errors[] = "แถว $r · $error"; else $fields[$key] = $value;
    }
    $title = $meta['_title'] !== '' ? $meta['_title'] : $def['title'];
    if (mb_strlen($title) > 200) { $errors[] = 'ชื่อเอกสารต้องไม่เกิน 200 ตัวอักษร'; $title = mb_substr($title, 0, 200); }
    $company = $meta['_company'] !== '' ? $meta['_company'] : document_import_default_company($code);
    if (mb_strlen($company) > 200) { $errors[] = 'ชื่อบริษัทต้องไม่เกิน 200 ตัวอักษร'; $company = mb_substr($company, 0, 200); }
    $date = date('Y-m-d');
    if ($meta['_document_date'] !== '') {
        try { $date = document_import_datetime($meta['_document_date'], $date1904, false); }
        catch (HttpError $e) { $errors[] = 'วันที่เอกสาร: ' . $e->getMessage(); }
    }

    $rows = [];
    if ($def['columns']) {
        $sheet = null;
        foreach ($book['sheets'] as $s) if ($s['name'] === DOCUMENT_IMPORT_ROWS_SHEET) $sheet = $s;
        if (!$sheet) {
            $errors[] = 'ไม่พบ Sheet “' . DOCUMENT_IMPORT_ROWS_SHEET . '” กรุณาใช้แบบฟอร์ม Import ที่ดาวน์โหลดจากระบบ';
        } else {
            $columns = []; $known = array_column($def['columns'], null, 'key');
            foreach (document_import_cell_columns($sheet['cells'], 2) as $col => $key) {
                if (!isset($known[$key])) { $errors[] = "Sheet รายการ: ไม่รู้จักรหัสคอลัมน์ $key ในแถว 2 (อย่าแก้แถว 2)"; continue; }
                $columns[$col] = $known[$key];
            }
            if (!$columns && !$errors) $errors[] = 'Sheet รายการ: ไม่พบรหัสคอลัมน์ในแถว 2';
            $byRow = [];
            foreach ($sheet['cells'] as $address => $value) {
                preg_match('/^([A-Z]+)(\d+)$/D', $address, $m);
                if ((int)$m[2] >= 3 && isset($columns[$m[1]])) $byRow[(int)$m[2]][$m[1]] = $value;
            }
            ksort($byRow);
            if (count($byRow) > 1000) $errors[] = 'Sheet รายการ: นำเข้าได้ไม่เกิน 1,000 แถว';
            foreach (array_slice($byRow, 0, 1000, true) as $r => $values) {
                $item = [];
                foreach ($columns as $col => $column) {
                    [$value, $error] = document_import_check($column, $values[$col] ?? '', $date1904);
                    if ($error) $errors[] = "Sheet รายการ แถว $r · $error"; else $item[$column['key']] = $value;
                }
                $rows[] = $item;
            }
        }
    }
    return [[
        'sheet' => $markerSheet['name'], 'title' => $title, 'company' => $company, 'document_date' => $date,
        'fields' => $fields, 'rows' => $rows, 'warnings' => $warnings, 'errors' => $errors, 'key_part' => 'template',
    ]];
}

/** Original FM-IT report layouts (one draft per sheet); same rules as api/bin/read-fm-workbooks.py. */
function document_import_from_original(array $def, array $book): array
{
    $code = $def['code']; $date1904 = $book['date1904']; $records = [];
    foreach ($book['sheets'] as $sheet) {
        $c = $sheet['cells']; $name = $sheet['name'];
        $g = fn(string $a): string => $c[$a] ?? '';
        $fields = []; $rows = []; $warnings = [];
        if (preg_match('/25\d\d/', $name, $y)) $year = $y[0];
        elseif (preg_match('/ปี\s*(\d{2})/u', $name, $y)) $year = '25' . $y[1];
        else $year = '';
        if ($code === 'FM-IT-002') {
            $fields['year'] = $year; $meta = ['วันที่ตรวจ', 'ผู้ตรวจสอบ', 'ผู้ทบทวน']; $months = str_split('DEFGHIJKLMNO');
            for ($r = 4; $r < 1200; $r++) {
                if (in_array($g("B$r"), $meta, true)) continue;
                $asset = $g("C$r"); $item = $g("B$r");
                if (str_starts_with($asset, 'FM-IT-') || ($asset === '' && $item === '') || str_contains($item, 'FM-IT-')) continue;
                $row = ['item' => $item !== '' ? $item : 'อุปกรณ์ (ต้นฉบับไม่ระบุประเภท)', 'asset_code' => $asset, 'notes' => $g("P$r")];
                if ($asset === '') $row['notes'] = trim($row['notes'] . ' ต้นฉบับไม่ระบุหมายเลขเครื่อง');
                foreach ($months as $i => $col) {
                    $raw = $g("$col$r");
                    $row['m' . ($i + 1)] = ['/' => 'normal', 'X' => 'abnormal', 'x' => 'abnormal', '✓' => 'fixed'][$raw] ?? '';
                    if ($raw !== '' && $row['m' . ($i + 1)] === '') $row['notes'] .= ' เดือน ' . ($i + 1) . ": $raw";
                }
                $rows[] = $row;
            }
            $details = [];
            foreach ($c as $address => $label) {
                if (!preg_match('/^B(\d+)$/D', $address, $m) || !in_array($label, $meta, true)) continue;
                foreach ($months as $i => $col) {
                    $value = $g($col . $m[1]);
                    if ($value === '') continue;
                    $details[] = 'เดือน ' . ($i + 1) . " — $label: " . ($label === 'วันที่ตรวจ' ? document_import_date_text($value, $date1904) : $value);
                }
            }
            $fields['inspection_metadata'] = implode("\n", $details);
            $warnings[] = 'เครื่องหมาย / และ X นำเข้าตามข้อความในเซลล์ เส้นทับ/สีใน Excel ต้องตรวจทานเอง ไม่ถือเป็นผลรับรองหรือลายเซ็น';
        } elseif ($code === 'FM-IT-005') {
            $fields = ['year' => $year, 'server' => 'SERVER'];
            for ($r = 6; $r < 1200; $r++) {
                if ($g("B$r") === '' || str_starts_with($g("A$r"), 'FM-IT-')) continue;
                $date = document_import_date_text($g("A$r"), $date1904);
                $row = ['date' => $date, 'dataset' => $g("B$r"), 'size' => $g("C$r"), 'source' => $g("D$r"), 'device' => $g("E$r"),
                    'folder' => $g("F$r"), 'backup_size' => $g("G$r"), 'started' => document_import_time_text($g("H$r")),
                    'finished' => document_import_time_text($g("I$r")), 'maker' => $g("J$r"), 'acknowledger' => $g("K$r"), 'notes' => $g("L$r")];
                if ($date !== '' && !preg_match('/^\d{2}\/\d{2}\/25\d{2}$/D', $date)) {
                    $row['notes'] = trim($row['notes'] . " [ตรวจทานวันที่ต้นทาง: $date]");
                    $warnings[] = 'มีวันที่ต้นทางไม่สมบูรณ์ เก็บข้อความเดิมพร้อมหมายเหตุ ไม่เดาปีที่ถูกต้อง';
                }
                foreach ([$row['started'], $row['finished']] as $t) {
                    if ($t !== '' && !preg_match('/^\d{2}:\d{2}:\d{2}$/D', $t)) { $row['notes'] .= ' [ตรวจทานเวลาที่ต้นทางพิมพ์เป็นข้อความ]'; break; }
                }
                $rows[] = $row;
            }
        } elseif ($code === 'FM-IT-006' && $year !== '') {
            $fields = ['year' => $year, 'team' => 'ฝ่ายเทคโนโลยีสารสนเทศ']; $frequency = $responsible = '';
            for ($r = 6; $r < 28; $r++) {
                $task = $g("D$r");
                if ($task === '') continue;
                $frequency = $g("EA$r") ?: $frequency; $responsible = $g("EI$r") ?: $responsible;
                $rows[] = ['task' => $task, 'frequency' => $frequency, 'responsible' => $responsible];
            }
            $warnings[] = 'นำเข้าหัวข้อ ความถี่ และผู้รับผิดชอบตามต้นฉบับ ตาราง P/A ที่ใช้เส้น/ลายพื้นให้ตรวจทาน Excel และกรอกสัปดาห์ก่อนรับรอง ไม่ตีความสีเป็นงานที่ทำเสร็จ';
        }
        if (!$rows) continue;
        $errors = [];
        $known = array_column([...$def['fields'], ...$def['columns']], null, 'key');
        foreach ($fields as $key => $value) {
            try { $fields[$key] = document_value($known[$key], [$key => $value === '' ? null : $value], false); }
            catch (HttpError $e) { $errors[] = $known[$key]['label'] . ': ' . implode(' / ', $e->errors ?: [$e->getMessage()]); }
        }
        $columns = array_column($def['columns'], null, 'key');
        foreach ($rows as $i => $row) foreach ($row as $key => $value) {
            try { $rows[$i][$key] = document_value($columns[$key], [$key => $value === '' ? null : $value], false); }
            catch (HttpError $e) { $errors[] = 'รายการที่ ' . ($i + 1) . ' · ' . $columns[$key]['label'] . ': ' . implode(' / ', $e->errors ?: [$e->getMessage()]); }
        }
        $records[] = ['sheet' => $name, 'title' => mb_substr("$code — $name (ข้อมูลจาก Excel)", 0, 200),
            'company' => stripos($name, 'INNO') !== false ? 'บริษัท อัตถสาร อินโนเวชั่น จำกัด' : 'บริษัท อัตถสาร จำกัด',
            'document_date' => date('Y-m-d'), 'fields' => $fields, 'rows' => $rows,
            'warnings' => array_values(array_unique($warnings)), 'errors' => $errors, 'key_part' => 'sheet:' . $name];
    }
    return $records;
}

function document_import_display(array $field, mixed $value): string
{
    if (is_array($value)) return implode(', ', array_map(fn($v) => $field['options'][$v] ?? $v, $value));
    if ($value === null || $value === '') return '';
    if ($field['type'] === 'select') return $field['options'][$value] ?? (string)$value;
    if (in_array($field['type'], ['date', 'datetime-local'], true) && preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}:\d{2}))?/', (string)$value, $m)) {
        return $m[3] . '/' . $m[2] . '/' . ((int)$m[1] + 543) . (isset($m[4]) ? ' ' . $m[4] : '');
    }
    return (string)$value;
}

/** Pure plan with duplicate detection; applying re-runs it inside a transaction. */
function document_import_plan(string $code, array $book, string $fileHash, string $fileName): array
{
    $def = document_import_definition($code);
    $marker = null;
    foreach ($book['sheets'] as $sheet) if (($sheet['cells']['A1'] ?? '') === DOCUMENT_IMPORT_MARKER) $marker = $sheet;
    if ($marker) {
        $fileCode = $marker['cells']['B1'] ?? '';
        if ($fileCode !== $code) fail("ไฟล์นี้เป็นแบบฟอร์ม Import ของ " . ($fileCode ?: 'แบบอื่น') . " ไม่ใช่ $code", 422);
        $source = 'template'; $records = document_import_from_template($def, $book, $marker);
    } elseif (in_array($code, DOCUMENT_IMPORT_ORIGINAL, true)) {
        $source = 'original'; $records = document_import_from_original($def, $book);
        if (!$records) fail("ไม่พบข้อมูลตามรูปแบบต้นฉบับ $code กรุณาใช้ไฟล์ต้นฉบับ หรือดาวน์โหลดแบบฟอร์ม Import จากระบบ", 422);
    } else {
        fail("ไม่ใช่แบบฟอร์ม Import ของ $code กรุณาดาวน์โหลดแบบฟอร์ม Excel สำหรับ Import แล้วกรอกข้อมูล", 422);
    }
    if (count($records) > 32) fail('นำเข้าได้ไม่เกิน 32 เอกสารต่อไฟล์', 422);

    $find = db()->prepare('SELECT id,document_no FROM it_documents WHERE source_key=?');
    $documents = []; $summary = ['total' => count($records), 'create' => 0, 'duplicate' => 0, 'errors' => 0];
    $allFields = array_column($def['fields'], null, 'key');
    foreach ($records as $rec) {
        $key = 'xlsx:' . hash('sha256', $fileHash . '#' . $code . '#' . $rec['key_part']);
        $find->execute([$key]); $duplicate = $find->fetch() ?: null;
        // Drafts made by the earlier CLI used "file name#sheet"; flag them so staff don't import twice by mistake.
        $legacy = null;
        if ($source === 'original') {
            $find->execute(['xlsx:' . hash('sha256', $fileName . '#' . $rec['sheet'])]); $legacy = $find->fetch() ?: null;
        }
        $data = ['fields' => $rec['fields'], 'rows' => $rec['rows'], 'signers' => []];
        $errors = $rec['errors'];
        if (!$errors) {
            try { $data = document_data($def, $data, null); }
            catch (HttpError $e) { $errors[] = implode(' / ', $e->errors ?: [$e->getMessage()]); }
        }
        $missing = [];
        foreach ($def['fields'] as $f) if ($f['required'] && $f['phase'] === 'request' && in_array($data['fields'][$f['key']] ?? null, [null, '', []], true)) $missing[] = $f['label'];
        $warnings = $rec['warnings'];
        if ($missing) $warnings[] = 'ยังไม่ได้กรอก: ' . implode(', ', $missing) . ' (กรอกในระบบก่อนส่งลงนาม)';
        if ($def['columns'] && !$data['rows']) $warnings[] = 'ยังไม่มีรายการในตาราง';
        if ($legacy && !$duplicate) $warnings[] = "Sheet นี้จากไฟล์ชื่อเดียวกันเคยนำเข้าแล้วเป็น {$legacy['document_no']} ตรวจว่าไม่ซ้ำก่อนยืนยัน";
        $action = $errors ? 'error' : ($duplicate ? 'duplicate' : 'create');
        $summary[$action === 'error' ? 'errors' : $action]++;
        $fieldsView = [];
        foreach ($data['fields'] as $k => $v) {
            $text = isset($allFields[$k]) ? document_import_display($allFields[$k], $v) : '';
            if ($text !== '') $fieldsView[] = ['label' => $allFields[$k]['label'], 'value' => mb_strimwidth($text, 0, 400, '…')];
        }
        $rowsView = array_map(function ($row) use ($def) {
            $out = [];
            foreach ($def['columns'] as $col) $out[] = mb_strimwidth(document_import_display($col, $row[$col['key']] ?? null), 0, 120, '…');
            return $out;
        }, array_slice($data['rows'], 0, 5));
        $documents[] = ['sheet' => $rec['sheet'], 'title' => $rec['title'], 'company' => $rec['company'], 'document_date' => $rec['document_date'],
            'fields' => $fieldsView, 'columns' => array_column($def['columns'], 'label'), 'rows_preview' => $rowsView, 'row_count' => count($data['rows']),
            'warnings' => array_values(array_unique($warnings)), 'errors' => $errors, 'action' => $action,
            'duplicate' => $duplicate ? ['id' => (int)$duplicate['id'], 'document_no' => $duplicate['document_no']] : null,
            '_save' => ['form_code' => $code, 'title' => $rec['title'], 'document_date' => $rec['document_date'], 'company' => $rec['company'], 'data' => $data],
            '_key' => $key];
    }
    $hash = hash('sha256', json_encode([$code, $fileHash, $source, array_map(fn($d) => [$d['_key'], $d['action'], $d['_save']], $documents)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    return ['form_code' => $code, 'source' => $source, 'summary' => $summary, 'documents' => $documents,
        'preview_hash' => $hash, 'can_apply' => $summary['errors'] === 0 && $summary['create'] > 0];
}

function document_import_upload(array $file, array $actor, bool $apply = false): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('อัปโหลดไม่สำเร็จ กรุณาเลือกไฟล์ .xlsx ขนาดไม่เกิน 5 MB', 422);
    if (!is_string($file['name'] ?? null) || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') fail('รองรับเฉพาะไฟล์ Excel .xlsx', 422);
    if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) fail('ไม่พบไฟล์ที่อัปโหลด', 422);
    $code = $_POST['form_code'] ?? '';
    if (!is_string($code) || !isset(document_forms()[$code])) fail('กรุณาเลือกแบบฟอร์ม', 422);
    $name = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename($file['name'])), 0, 150);
    return document_import_run($code, $file['tmp_name'], $name, $actor, $apply ? ($_POST['preview_hash'] ?? '') : null);
}

function document_import_run(string $code, string $path, string $fileName, array $actor, ?string $previewHash = null): array
{
    $book = document_import_read($path); $fileHash = hash_file('sha256', $path);
    if ($previewHash === null) {
        $plan = document_import_plan($code, $book, $fileHash, $fileName);
        foreach ($plan['documents'] as &$d) unset($d['_save'], $d['_key']);
        unset($d);
        return $plan;
    }
    if (!is_string($previewHash) || !preg_match('/^[a-f0-9]{64}$/D', $previewHash)) fail('กรุณาตรวจสอบไฟล์ก่อนยืนยัน Import', 422);
    $pdo = db(); $pdo->beginTransaction();
    try {
        $plan = document_import_plan($code, $book, $fileHash, $fileName);
        if (!hash_equals($plan['preview_hash'], $previewHash)) fail('ข้อมูลหรือไฟล์เปลี่ยนหลังตรวจสอบ กรุณาตรวจสอบไฟล์อีกครั้ง', 409);
        if (!$plan['can_apply']) fail('ยังมีรายการที่ต้องแก้ไข หรือไม่มีเอกสารใหม่ให้นำเข้า', 422);
        $created = [];
        foreach ($plan['documents'] as $d) {
            if ($d['action'] !== 'create') continue;
            $note = "นำเข้าฉบับร่างจาก Excel: $fileName" . ($plan['source'] === 'original' ? " / Sheet: {$d['sheet']}" : '') . "\nSHA-256: $fileHash";
            foreach ($d['warnings'] as $w) $note .= "\n$w";
            $saved = document_save(null, [...$d['_save'], 'source_note' => mb_substr($note, 0, 2000)], $actor, $d['_key']);
            document_log((int)$saved['id'], 'นำเข้าจาก Excel เป็นฉบับร่าง ไม่มีการรับรองหรือลงนามย้อนหลัง', (int)$actor['id']);
            $created[] = ['id' => (int)$saved['id'], 'document_no' => $saved['document_no'], 'title' => $saved['title']];
        }
        $pdo->commit();
        return ['summary' => $plan['summary'], 'created' => $created];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (($e->errorInfo[1] ?? null) === 1062) fail('ไฟล์นี้เพิ่งถูกนำเข้าแล้ว กรุณาตรวจสอบไฟล์อีกครั้ง', 409);
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function document_import_xml_text(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function document_import_col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

function document_import_hint(array $f): string
{
    $hint = match ($f['type']) {
        'date' => 'วว/ดด/ปปปป (พ.ศ. หรือ ค.ศ.)',
        'datetime-local' => 'วว/ดด/ปปปป ชช:นน',
        'select' => 'เลือกจากรายการ',
        'multiselect' => 'เลือกได้หลายค่า คั่นด้วยจุลภาค: ' . implode(', ', $f['options']),
        'number' => 'ตัวเลขจำนวนเต็ม',
        'decimal' => 'ตัวเลข ทศนิยมไม่เกิน 2 ตำแหน่ง',
        'email' => 'E-mail',
        default => '',
    };
    $notes = array_filter([$hint, $f['required'] ? 'จำเป็นก่อนส่งลงนาม' : '', $f['phase'] === 'completion' ? 'กรอกหลังดำเนินการ' : '']);
    return implode(' · ', $notes);
}

function document_import_template(string $code): never
{
    document_import_definition($code);
    $path = tempnam(sys_get_temp_dir(), 'ithub-doc-');
    if ($path === false) fail('ไม่สามารถสร้างไฟล์แบบฟอร์มได้', 503);
    try {
        document_import_template_build($code, $path);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $code . '-import.xlsx"');
        header('Cache-Control: private, no-store');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    } finally { @unlink($path); }
    exit;
}

/** Per-form import workbook generated from the catalog (dropdowns for choice fields). */
function document_import_template_build(string $code, string $path): void
{
    $def = document_import_definition($code);
    $retired = [...PURCHASE_ITEMS_RETIRED, ...SERVICE_ACTIONS_RETIRED];
    $cell = fn(string $ref, string $value, int $style = 0): string => '<c r="' . $ref . '" t="inlineStr"' . ($style ? " s=\"$style\"" : '') . '><is><t xml:space="preserve">' . document_import_xml_text($value) . '</t></is></c>';
    $row = fn(int $r, string $cells, ?float $height = null): string => "<row r=\"$r\"" . ($height ? " ht=\"$height\" customHeight=\"1\"" : '') . ">$cells</row>";

    // Options sheet: one column per choice field, labels only (the parser also accepts keys).
    $optionCols = []; $optionRows = [];
    $addOptions = function (array $f) use (&$optionCols, &$optionRows, $retired): string {
        $i = count($optionCols); $col = document_import_col($i);
        $labels = array_values(array_diff_key($f['options'], array_flip($retired)));
        $optionCols[] = $f['label'];
        foreach ($labels as $j => $label) $optionRows[$j + 2][$col] = $label;
        return "'" . DOCUMENT_IMPORT_OPTIONS_SHEET . "'!\$$col\$2:\$$col\$" . (count($labels) + 1);
    };

    $rows = [
        $row(1, $cell('A1', DOCUMENT_IMPORT_MARKER, 3) . $cell('B1', $code, 1) . $cell('C1', $def['title'], 1)),
        $row(2, $cell('A2', 'กรอกเฉพาะคอลัมน์ C (ข้อมูล) ห้ามแก้คอลัมน์ A · ลายเซ็นและผู้ลงนามเลือกในระบบหลังนำเข้า · นำเข้าเป็นฉบับร่าง', 3)),
        $row(3, $cell('A3', 'รหัสช่อง', 1) . $cell('B3', 'หัวข้อ', 1) . $cell('C3', 'ข้อมูล', 1) . $cell('D3', 'คำแนะนำ', 1)),
    ];
    $validations = [];
    $meta = [['_title', 'ชื่อเอกสาร', $def['title'], 'เว้นว่างได้ ใช้ชื่อแบบฟอร์ม'], ['_document_date', 'วันที่เอกสาร', '', 'วว/ดด/ปปปป เว้นว่าง = วันที่นำเข้า'], ['_company', 'บริษัท', document_import_default_company($code), '']];
    $r = 4;
    foreach ($meta as [$key, $label, $value, $hint]) {
        $rows[] = $row($r, $cell("A$r", $key, 3) . $cell("B$r", $label) . ($value !== '' ? $cell("C$r", $value, 2) : '') . ($hint !== '' ? $cell("D$r", $hint, 3) : ''));
        $r++;
    }
    foreach ($def['fields'] as $f) {
        $rows[] = $row($r, $cell("A$r", $f['key'], 3) . $cell("B$r", $f['label'] . ($f['required'] ? ' *' : '')) . (($hint = document_import_hint($f)) !== '' ? $cell("D$r", $hint, 3) : ''), $f['type'] === 'textarea' ? 45 : null);
        if ($f['type'] === 'select') $validations[] = "<dataValidation type=\"list\" allowBlank=\"1\" showErrorMessage=\"1\" sqref=\"C$r\"><formula1>" . document_import_xml_text($addOptions($f)) . '</formula1></dataValidation>';
        $r++;
    }
    $dv = fn(array $v): string => $v ? '<dataValidations count="' . count($v) . '">' . implode('', $v) . '</dataValidations>' : '';
    $sheets = [[DOCUMENT_IMPORT_FIELDS_SHEET, '<sheetViews><sheetView workbookViewId="0"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols><col min="1" max="1" width="18" customWidth="1"/><col min="2" max="2" width="36" customWidth="1"/><col min="3" max="3" width="48" style="2" customWidth="1"/><col min="4" max="4" width="42" customWidth="1"/></cols>'
        . '<sheetData>' . implode('', $rows) . '</sheetData>' . $dv($validations), false]];

    if ($def['columns']) {
        $labels = ''; $keys = ''; $cols = ''; $validations = [];
        foreach ($def['columns'] as $i => $c) {
            $col = document_import_col($i);
            $labels .= $cell("{$col}1", $c['label'] . ($c['required'] ? ' *' : ''), 1);
            $keys .= $cell("{$col}2", $c['key'], 3);
            $width = $c['type'] === 'textarea' ? 40 : (in_array($c['type'], ['select', 'text'], true) ? 22 : 14);
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . "\" width=\"$width\" style=\"2\" customWidth=\"1\"/>";
            if ($c['type'] === 'select') $validations[] = "<dataValidation type=\"list\" allowBlank=\"1\" showErrorMessage=\"1\" sqref=\"{$col}3:{$col}1002\"><formula1>" . document_import_xml_text($addOptions($c)) . '</formula1></dataValidation>';
        }
        $sheets[] = [DOCUMENT_IMPORT_ROWS_SHEET, '<sheetViews><sheetView workbookViewId="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . "<cols>$cols</cols><sheetData>" . $row(1, $labels, 32) . $row(2, $keys) . '</sheetData>' . $dv($validations), false];
    }
    if ($optionCols) {
        $data = $row(1, implode('', array_map(fn($label, $i) => $cell(document_import_col($i) . '1', $label, 1), $optionCols, array_keys($optionCols))));
        ksort($optionRows);
        foreach ($optionRows as $n => $values) {
            ksort($values);
            $data .= $row($n, implode('', array_map(fn($col, $v) => $cell("$col$n", $v), array_keys($values), $values)));
        }
        $sheets[] = [DOCUMENT_IMPORT_OPTIONS_SHEET, '<cols><col min="1" max="' . count($optionCols) . '" width="30" customWidth="1"/></cols><sheetData>' . $data . '</sheetData>', true];
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) fail('ไม่สามารถสร้างไฟล์แบบฟอร์มได้', 503);
    $types = ''; $sheetList = ''; $rels = '';
    foreach ($sheets as $i => [$name, $body, $hidden]) {
        $n = $i + 1;
        $types .= "<Override PartName=\"/xl/worksheets/sheet$n.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        $sheetList .= '<sheet name="' . document_import_xml_text($name) . "\" sheetId=\"$n\"" . ($hidden ? ' state="hidden"' : '') . " r:id=\"rId$n\"/>";
        $rels .= "<Relationship Id=\"rId$n\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$n.xml\"/>";
        $zip->addFromString("xl/worksheets/sheet$n.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $body . '</worksheet>');
    }
    $styleRel = count($sheets) + 1;
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $types . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $sheetList . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . "<Relationship Id=\"rId$styleRel\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/></Relationships>");
    // 0 default · 1 bold header with fill · 2 text-format input (keeps dates as typed) · 3 grey hint
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3"><font><sz val="11"/><name val="Tahoma"/></font><font><b/><sz val="11"/><name val="Tahoma"/></font><font><sz val="9"/><color rgb="FF64748B"/><name val="Tahoma"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    if (!$zip->close()) fail('ไม่สามารถสร้างไฟล์แบบฟอร์มได้', 503);
}
