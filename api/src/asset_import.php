<?php
declare(strict_types=1);

const ASSET_IMPORT_MAX_BYTES = 5 * 1024 * 1024;
const ASSET_IMPORT_MAX_ROWS = 1000;

function asset_import_xml(ZipArchive $zip, string $name): SimpleXMLElement
{
    // Read members in place; never extract files or resolve external relationships.
    $raw = $zip->getFromName($name);
    if ($raw === false || strlen($raw) > 8 * 1024 * 1024 || str_contains($raw, "\0") || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $raw)) {
        fail('ไฟล์ Excel ไม่ถูกต้องหรือมี XML ที่ไม่รองรับ', 422);
    }
    $previous = libxml_use_internal_errors(true);
    try {
        $xml = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($xml === false) fail('อ่านโครงสร้าง Excel ไม่สำเร็จ', 422);
        return $xml;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

function asset_import_date(string $value, bool $date1904 = false): ?string
{
    if ($value === '' || $value === '-') return null;
    if (preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
        if ((float)$value > 300000) fail('วันที่ขึ้นทะเบียนอยู่นอกช่วงที่รองรับ', 422);
        $date = (new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30'))->modify('+' . (int)(float)$value . ' days');
        [$y, $m, $d] = [(int)$date->format('Y'), (int)$date->format('m'), (int)$date->format('d')];
    } elseif (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/D', $value, $parts)) {
        [$y, $m, $d] = [(int)$parts[3], (int)$parts[2], (int)$parts[1]];
    } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)) {
        [$y, $m, $d] = [(int)$parts[1], (int)$parts[2], (int)$parts[3]];
    } else {
        fail('วันที่ขึ้นทะเบียนไม่ถูกต้อง ใช้วันที่ Excel หรือ วว/ดด/ปปปป', 422);
    }
    if ($y >= 2400) $y -= 543;
    if ($y < 1970 || $y > 2100 || !checkdate($m, $d, $y)) fail('วันที่ขึ้นทะเบียนไม่ถูกต้อง', 422);
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/** Parse FM-IT-001 sheets (including hidden/filter-excluded rows), never formulas/signatures. */
function asset_import_read(string $path): array
{
    if (!is_file($path) || filesize($path) > ASSET_IMPORT_MAX_BYTES) fail('ไฟล์ต้องมีขนาดไม่เกิน 5 MB', 422);
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
        $strings = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $shared = asset_import_xml($zip, 'xl/sharedStrings.xml');
            foreach ($shared->children($ns)->si as $si) {
                $si->registerXPathNamespace('m', $ns);
                $strings[] = implode('', array_map('strval', $si->xpath('.//m:t')));
                if (count($strings) > 100000) fail('ข้อความใน Excel มีจำนวนมากเกินไป', 422);
            }
        }
        $relations = [];
        foreach (asset_import_xml($zip, 'xl/_rels/workbook.xml.rels')->children('http://schemas.openxmlformats.org/package/2006/relationships') as $rel) {
            $target = (string)$rel->attributes()['Target'];
            if ((string)$rel->attributes()['TargetMode'] === 'External') continue;
            $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            if (preg_match('#^xl/worksheets/[A-Za-z0-9_.-]+\.xml$#D', $target)) $relations[(string)$rel->attributes()['Id']] = $target;
        }
        $workbook = asset_import_xml($zip, 'xl/workbook.xml');
        $properties = $workbook->children($ns)->workbookPr;
        $date1904 = count($properties) > 0 && in_array((string)$properties->attributes()['date1904'], ['1', 'true'], true);
        $sheets = $workbook->children($ns)->sheets->sheet;
        if (count($sheets) > 16) fail('Excel ต้องมีไม่เกิน 16 Sheet', 422);
        $rows = []; $cellCount = 0;
        foreach ($sheets as $sheet) {
            $name = (string)$sheet->attributes()['name'];
            if (!preg_match('/\b(ATS|INNO)\b/i', $name, $company)) continue;
            $rid = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if (!isset($relations[$rid])) fail('ไม่พบข้อมูล Sheet ภายใน Excel', 422);
            $xml = asset_import_xml($zip, $relations[$rid]);
            $header = false;
            foreach ($xml->children($ns)->sheetData->row as $row) {
                $number = (int)$row->attributes()['r']; $cells = []; $formulas = [];
                foreach ($row->children($ns)->c as $cell) {
                    if (++$cellCount > 100000) fail('Excel มีจำนวนเซลล์มากเกินไป', 422);
                    if (!preg_match('/^([A-Z]+)\d+$/D', (string)$cell->attributes()['r'], $address)) fail('ตำแหน่งเซลล์ Excel ไม่ถูกต้อง', 422);
                    $col = $address[1];
                    if (!in_array($col, range('A', 'J'), true)) continue;
                    $child = $cell->children($ns);
                    if (isset($child->f)) $formulas[] = $col;
                    $type = (string)$cell->attributes()['t'];
                    if ($type === 's') {
                        $index = (string)$child->v;
                        if (!ctype_digit($index) || !array_key_exists((int)$index, $strings)) fail('ข้อความใน Excel เสียหาย', 422);
                        $value = $strings[(int)$index];
                    } elseif ($type === 'inlineStr') {
                        $cell->registerXPathNamespace('m', $ns);
                        $value = implode('', array_map('strval', $cell->xpath('./m:is//m:t')));
                    } else {
                        $value = (string)$child->v;
                    }
                    if (mb_strlen($value) > 5000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) fail('ข้อความในเซลล์ไม่ถูกต้องหรือยาวเกินไป', 422);
                    $cells[$col] = trim($value);
                }
                if (!$header) {
                    $norm = fn(string $text): string => preg_replace('/[\s\/]+/u', '', mb_strtolower($text));
                    if ($norm($cells['B'] ?? '') === 'หมายเลข') {
                        foreach (['C'=>'ผู้ครอบครอง', 'D'=>'ยี่ห้อรุ่น', 'E'=>'serialno', 'F'=>'หน่วยงานที่ใช้', 'G'=>'วันที่ขึ้นทะเบียน'] as $col=>$label) {
                            if ($norm($cells[$col] ?? '') !== $label) fail("Sheet $name: หัวตารางต้องตรงรูปแบบ FM-IT-001 (คอลัมน์ $col)", 422);
                        }
                        $header = true;
                    }
                    continue;
                }
                $context = "Sheet $name แถว $number";
                if (!ctype_digit($cells['A'] ?? '')) {
                    if (!empty($cells['D']) && preg_match('/^[A-Z0-9][A-Z0-9._-]*$/iD', $cells['B'] ?? '')) fail("$context: ลำดับต้องเป็นตัวเลข", 422);
                    continue; // Section labels/footer are not equipment.
                }
                if ($formulas) fail("$context: กรุณาเปลี่ยนสูตรเป็นค่าข้อมูลก่อน Import", 422);
                if (empty($cells['B']) || empty($cells['D'])) fail("$context: ต้องระบุหมายเลขและยี่ห้อ/รุ่น", 422);
                $nullable = fn(string $col): ?string => in_array($cells[$col] ?? '', ['', '-'], true) ? null : $cells[$col];
                try { $date = asset_import_date($cells['G'] ?? '', $date1904); }
                catch (HttpError $e) { fail("$context: " . $e->getMessage(), 422); }
                $rows[] = ['sheet'=>$name, 'row'=>$number, 'company'=>strtoupper($company[1]),
                    'asset_code'=>strtoupper($cells['B']), 'brand_model'=>$cells['D'], 'serial_no'=>$nullable('E'),
                    'holder_name'=>$nullable('C'), 'holder_nickname'=>$nullable('H'), 'department'=>$nullable('F'),
                    'registered_at'=>$date, 'type'=>$cells['I'] ?? '', 'source_notes'=>$nullable('J')];
                if (count($rows) > ASSET_IMPORT_MAX_ROWS) fail('นำเข้าได้ไม่เกิน 1,000 รายการต่อครั้ง', 422);
            }
            if (!$header) fail("Sheet $name: ไม่พบหัวตาราง FM-IT-001", 422);
        }
        if (!$rows) fail('ไม่พบรายการอุปกรณ์ กรุณาใช้ Sheet ที่มีชื่อ ATS หรือ INNO และหัวตาราง FM-IT-001', 422);
        return $rows;
    } finally { $zip->close(); }
}

function asset_import_serial(?string $value): string
{
    return strtoupper(trim($value ?? ''));
}

/** Pure plan: no writes and no hidden code-based replacement of physical equipment. */
function asset_import_plan(array $rows, array $assets, array $categories): array
{
    $codes = []; $serials = []; $categoryIds = array_column($categories, 'id', 'name');
    foreach ($assets as $asset) {
        $codes[strtoupper(trim($asset['asset_code']))] = $asset;
        if (($serial = asset_import_serial($asset['serial_no'])) !== '') $serials[$serial][] = $asset;
    }
    $seenCodes = []; $seenSerials = []; $matched = []; $entries = []; $errors = [];
    $summary = ['total'=>count($rows), 'created'=>0, 'updated'=>0, 'unchanged'=>0, 'renamed'=>0];
    foreach ($rows as $source) {
        try {
            $code = $source['asset_code']; $serial = asset_import_serial($source['serial_no']);
            if (isset($seenCodes[$code])) fail('หมายเลขทะเบียนซ้ำในไฟล์', 422);
            $seenCodes[$code] = true;
            if ($serial !== '' && isset($seenSerials[$serial])) fail('Serial ซ้ำในไฟล์', 422);
            if ($serial !== '') $seenSerials[$serial] = true;
            $old = null; $reason = 'รายการใหม่';
            if ($serial !== '') {
                $matches = $serials[$serial] ?? [];
                if (count($matches) > 1) fail('Serial ตรงกับอุปกรณ์ในระบบมากกว่า 1 รายการ กรุณาแก้ไขข้อมูลซ้ำก่อน', 422);
                $old = $matches[0] ?? null;
                $reason = 'Serial ตรงกัน';
                if (!$old && isset($codes[$code]) && !$codes[$code]['serial_no'] && $codes[$code]['brand_model'] === $source['brand_model']) {
                    $old = $codes[$code]; $reason = 'รหัสและรุ่นตรงกัน (เติม Serial)';
                }
            } else {
                if (isset($codes[$code]) && !$codes[$code]['serial_no']) {
                    $old = $codes[$code]; $reason = 'รหัสตรงกัน (ไม่มี Serial)';
                } else {
                    $matches = array_values(array_filter($assets, fn($a) => !$a['serial_no'] && $source['holder_name'] !== null && $source['department'] !== null
                        && $a['brand_model'] === $source['brand_model'] && $a['holder_name'] === $source['holder_name'] && $a['department'] === $source['department']));
                    if (count($matches) > 1) fail('ไม่สามารถยืนยันเครื่องที่ไม่มี Serial ได้ เพราะมีข้อมูลตรงกันหลายรายการ', 422);
                    $old = $matches[0] ?? null; $reason = 'รุ่น ผู้ครอบครอง และหน่วยงานตรงกัน (ไม่มี Serial)';
                }
            }
            if ($old && isset($matched[$old['id']])) fail('มีหลายแถวอ้างถึงอุปกรณ์เดียวกัน', 422);
            $data = $old ? array_intersect_key($old, array_flip(ASSET_FIELDS)) : array_fill_keys(ASSET_FIELDS, null);
            $data['asset_code'] = $code; $data['company'] = $source['company']; $data['brand_model'] = $source['brand_model'];
            $data['status'] ??= 'active';
            foreach (['serial_no', 'holder_name', 'holder_nickname', 'department', 'registered_at'] as $field) {
                if ($source[$field] !== null) $data[$field] = $source[$field];
            }
            $type = $source['type'];
            $category = match (true) {
                (bool)preg_match('/^(aio|all[- ]?in[- ]?one)/i', $type) => 'All-in-one',
                (bool)preg_match('/^(notebook|laptop)/i', $type) => 'Notebook',
                (bool)preg_match('/^(computer|pc)\b/i', $type) => 'PC',
                default => null,
            };
            if ($type !== '' && $category === null) fail('Type ต้องเป็น Notebook, Computer หรือ AIO (ต่อท้ายชื่อเครื่อง/หมายเหตุได้)', 422);
            if ($category !== null) $data['category_id'] = $categoryIds[$category] ?? fail('ไม่พบหมวด ' . $category . ' ในระบบ', 422);
            if ($data['category_id'] === null) fail('กรุณาระบุ Type เป็น Notebook, Computer หรือ AIO สำหรับรายการใหม่', 422);
            if (preg_match('/[\/,]\s*([A-Za-z][A-Za-z0-9-]{1,49})\s*$/D', $type, $host)) $data['hostname'] = strtoupper($host[1]);
            $notes = $data['notes'] ?? '';
            $extra = [];
            if ($source['source_notes']) $extra[] = 'หมายเหตุจากทะเบียน: ' . $source['source_notes'];
            if (preg_match('/[ก-๙]|\b\d{4}\b/u', $type)) $extra[] = 'Type ตามต้นฉบับ: ' . $type;
            if ($old && $old['asset_code'] !== $code) $extra[] = 'รหัสก่อนปรับทะเบียน FM-IT-001: ' . $old['asset_code'];
            foreach ($extra as $text) if (!str_contains("\n" . $notes . "\n", "\n" . $text . "\n")) $notes .= ($notes !== '' ? "\n" : '') . $text;
            $data['notes'] = $notes !== '' ? $notes : null;
            $data = asset_data($data);
            $changes = [];
            foreach (ASSET_FIELDS as $field) if (!$old || $old[$field] !== $data[$field]) $changes[$field] = ['before'=>$old[$field] ?? null, 'after'=>$data[$field]];
            $action = !$old ? 'created' : ($changes ? 'updated' : 'unchanged');
            $summary[$action]++;
            if ($old && $old['asset_code'] !== $code) $summary['renamed']++;
            if ($old) $matched[$old['id']] = $code;
            $entries[] = ['sheet'=>$source['sheet'], 'row'=>$source['row'], 'asset_code'=>$code, 'company'=>$source['company'],
                'id'=>$old['id'] ?? null, 'previous_code'=>$old['asset_code'] ?? null, 'brand_model'=>$data['brand_model'],
                'reason'=>$old ? $reason : 'รายการใหม่', 'action'=>$action, 'changes'=>$changes, 'data'=>$data];
        } catch (HttpError $e) {
            $errors[] = ['sheet'=>$source['sheet'], 'row'=>$source['row'], 'asset_code'=>$source['asset_code'],
                'message'=>implode(' / ', $e->errors ?: [$e->getMessage()])];
        }
    }
    foreach ($entries as $entry) {
        $occupant = $codes[$entry['asset_code']] ?? null;
        if ($occupant && $occupant['id'] !== $entry['id'] && (!isset($matched[$occupant['id']]) || $matched[$occupant['id']] === $occupant['asset_code'])) {
            $errors[] = ['sheet'=>$entry['sheet'], 'row'=>$entry['row'], 'asset_code'=>$entry['asset_code'],
                'message'=>'รหัสนี้เป็นของเครื่องอื่นที่มี Serial ต่างกัน กรุณาตรวจสอบรหัส/Serial หรือนำเข้าแถวเปลี่ยนรหัสของเครื่องเดิมให้ครบ'];
        }
    }
    // Recompute under a transaction on Apply; any intervening asset/category edit invalidates the preview.
    $hash = hash('sha256', json_encode([$rows, $assets, $categories], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    return ['summary'=>$summary, 'rows'=>$entries, 'errors'=>$errors, 'preview_hash'=>$hash, 'can_apply'=>!$errors];
}

function asset_import_run(array $rows, ?string $previewHash = null): array
{
    $pdo = db(); $apply = $previewHash !== null;
    if ($apply && !preg_match('/^[a-f0-9]{64}$/D', $previewHash)) fail('กรุณาตรวจสอบไฟล์ก่อนยืนยัน Import', 422);
    if ($apply) $pdo->beginTransaction();
    try {
        $assets = $pdo->query('SELECT * FROM assets ORDER BY id' . ($apply ? ' FOR UPDATE' : ''))->fetchAll();
        $categories = $pdo->query('SELECT * FROM asset_categories ORDER BY id' . ($apply ? ' FOR UPDATE' : ''))->fetchAll();
        $plan = asset_import_plan($rows, $assets, $categories);
        if ($apply) {
            if (!hash_equals($plan['preview_hash'], $previewHash)) fail('ข้อมูลหรือไฟล์เปลี่ยนหลังตรวจสอบ กรุณาตรวจสอบไฟล์อีกครั้ง', 409);
            if (!$plan['can_apply']) fail('ยังมีรายการที่ต้องแก้ไข กรุณาตรวจสอบไฟล์อีกครั้ง', 422);
            $stage = $pdo->prepare('UPDATE assets SET asset_code=? WHERE id=?');
            foreach ($plan['rows'] as $entry) {
                if ($entry['id'] !== null && $entry['previous_code'] !== $entry['asset_code']) {
                    $stage->execute(['TMP-' . bin2hex(random_bytes(10)), $entry['id']]);
                }
            }
            $renamedIds = [];
            foreach ($plan['rows'] as $entry) {
                if ($entry['action'] === 'unchanged') continue;
                $saved = asset_save($entry['id'], $entry['data']);
                if ($entry['id'] !== null && $entry['previous_code'] !== $entry['asset_code']) $renamedIds[] = $saved['id'];
            }
            $pdo->commit();
            return ['summary'=>$plan['summary'], 'renamed_ids'=>$renamedIds];
        }
        foreach ($plan['rows'] as &$entry) unset($entry['data']);
        unset($entry);
        return $plan;
    } catch (Throwable $e) {
        if ($apply && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function asset_import_upload(array $file, bool $apply = false): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('อัปโหลดไม่สำเร็จ กรุณาเลือกไฟล์ .xlsx ขนาดไม่เกิน 5 MB', 422);
    if (!is_string($file['name'] ?? null) || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') fail('รองรับเฉพาะไฟล์ Excel .xlsx', 422);
    if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) fail('ไม่พบไฟล์ที่อัปโหลด', 422);
    $hash = null;
    if ($apply) {
        $hash = $_POST['preview_hash'] ?? null;
        if (!is_string($hash) || $hash === '') fail('กรุณาตรวจสอบไฟล์ก่อนยืนยัน Import', 422);
    }
    // PHP owns and removes the upload temporary file after this request. No private workbook is published.
    return asset_import_run(asset_import_read($file['tmp_name']), $hash);
}

function asset_import_template(): never
{
    $path = tempnam(sys_get_temp_dir(), 'ithub-fm001-');
    if ($path === false) fail('ไม่สามารถสร้างไฟล์ตัวอย่างได้', 503);
    try {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) fail('ไม่สามารถสร้างไฟล์ตัวอย่างได้', 503);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="FM-IT-001 (ATS)" sheetId="1" r:id="rId1"/><sheet name="FM-IT-001 (INNO)" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>');
        $cells = '';
        foreach (['ลำดับ','หมายเลข','ผู้ครอบครอง','ยี่ห้อ / รุ่น','Serial No','หน่วยงานที่ใช้','วันที่ขึ้นทะเบียน','หมายเหตุ','Type','หมายเหตุ'] as $i=>$label) {
            $cells .= '<c r="' . chr(65 + $i) . '4" t="inlineStr"><is><t>' . htmlspecialchars($label, ENT_XML1, 'UTF-8') . '</t></is></c>';
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="8" customWidth="1"/><col min="2" max="10" width="25" customWidth="1"/></cols><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>ใบทะเบียนคอมพิวเตอร์ และสารสนเทศ</t></is></c></row><row r="4">' . $cells . '</row></sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/worksheets/sheet2.xml', $sheet);
        $zip->close();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="FM-IT-001-template.xlsx"');
        header('Cache-Control: no-store');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    } finally { @unlink($path); }
    exit;
}
