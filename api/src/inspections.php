<?php
declare(strict_types=1);

/*
 * FM-IT-002 ใบตรวจสอบโครงสร้างพื้นฐาน — a living yearly checklist edited in place.
 * One item list per Buddhist year; results are per item per month; each month keeps the
 * inspection date / inspector / reviewer printed in the footer rows of the original form.
 * Names are plain records like the paper form, never signatures or approvals.
 */
const INSPECTION_RESULTS = ['normal', 'abnormal', 'fixed'];
const INSPECTION_MAX_ITEMS = 1000;
const INSPECTION_ITEM_PLACEHOLDER = 'อุปกรณ์ (ต้นฉบับไม่ระบุประเภท)';

function inspection_year(mixed $value): int
{
    $year = is_int($value) ? $value : (is_string($value) && preg_match('/^\d{4}$/D', trim($value)) ? (int)trim($value) : 0);
    if ($year < 2500 || $year > 2700) fail('ปี พ.ศ. ไม่ถูกต้อง', 422);
    return $year;
}

function inspection_month(mixed $value): int
{
    $month = is_int($value) ? $value : (is_string($value) && preg_match('/^\d{1,2}$/D', $value) ? (int)$value : 0);
    if ($month < 1 || $month > 12) fail('เดือนไม่ถูกต้อง', 422);
    return $month;
}

function inspection_current_year(): int
{
    return (int)date('Y') + 543;
}

/** Whole year: items (with register details), results and monthly footer data. */
function inspection_get(array $query): array
{
    $years = db()->query('SELECT year, COUNT(*) items FROM infra_inspection_items GROUP BY year ORDER BY year DESC')->fetchAll();
    $year = isset($query['year']) && $query['year'] !== '' ? inspection_year($query['year']) : inspection_current_year();
    $items = db()->prepare(
        'SELECT i.id, i.sort_order, i.asset_id, i.asset_code, i.item, i.notes,
                a.asset_code current_code, a.brand_model, a.holder_name, a.holder_nickname, a.department, a.status asset_status,
                a.company, c.name category
           FROM infra_inspection_items i
           LEFT JOIN assets a ON a.id = i.asset_id
           LEFT JOIN asset_categories c ON c.id = a.category_id
          WHERE i.year = ? ORDER BY i.sort_order, i.id'
    );
    $items->execute([$year]);
    $rows = [];
    foreach ($items->fetchAll() as $r) {
        $id = (int)$r['id'];
        $rows[$id] = [
            'id' => $id, 'sort_order' => (int)$r['sort_order'], 'asset_id' => $r['asset_id'] === null ? null : (int)$r['asset_id'],
            'asset_code' => $r['asset_code'], 'item' => $r['item'], 'notes' => $r['notes'],
            'asset' => $r['asset_id'] === null ? null : [
                'code' => $r['current_code'], 'model' => $r['brand_model'], 'category' => $r['category'], 'company' => $r['company'],
                'holder' => trim(($r['holder_name'] ?? '') . ($r['holder_nickname'] ? " ({$r['holder_nickname']})" : '')) ?: null,
                'department' => $r['department'], 'status' => $r['asset_status'],
            ],
            'results' => [], // month => result; JSON object when filled, [] when empty
        ];
    }
    if ($rows) {
        $res = db()->prepare('SELECT r.item_id, r.month, r.result FROM infra_inspection_results r JOIN infra_inspection_items i ON i.id = r.item_id WHERE i.year = ?');
        $res->execute([$year]);
        $byItem = [];
        foreach ($res->fetchAll() as $r) $byItem[(int)$r['item_id']][(int)$r['month']] = $r['result'];
        foreach ($byItem as $id => $marks) if (isset($rows[$id])) $rows[$id]['results'] = $marks;
    }
    $months = db()->prepare(
        'SELECT m.month, m.inspected_on, m.inspector, m.reviewer, m.notes, m.updated_at, a.display_name updated_by_name
           FROM infra_inspection_months m LEFT JOIN admins a ON a.id = m.updated_by WHERE m.year = ? ORDER BY m.month'
    );
    $months->execute([$year]);
    $monthly = [];
    foreach ($months->fetchAll() as $m) {
        $monthly[(int)$m['month']] = ['inspected_on' => $m['inspected_on'], 'inspector' => $m['inspector'], 'reviewer' => $m['reviewer'],
            'notes' => $m['notes'], 'updated_at' => $m['updated_at'], 'updated_by_name' => $m['updated_by_name']];
    }
    $names = db()->query("SELECT DISTINCT name FROM (SELECT inspector name FROM infra_inspection_months UNION SELECT reviewer FROM infra_inspection_months) n WHERE name IS NOT NULL AND name <> '' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $form = document_forms()['FM-IT-002'];
    return [
        'year' => $year, 'current_year' => inspection_current_year(), 'current_month' => (int)date('n'),
        'years' => array_map(fn($y) => ['year' => (int)$y['year'], 'items' => (int)$y['items']], $years),
        'items' => array_values($rows), 'months' => $monthly, 'names' => $names,
        'form' => ['code' => $form['code'], 'title' => $form['title'], 'rev' => $form['rev']],
    ];
}

/** Save one month: changed marks (null clears) and the footer data, all-or-nothing. */
function inspection_save_month(string $yearValue, string $monthValue, array $body, array $admin): array
{
    $year = inspection_year($yearValue); $month = inspection_month($monthValue);
    $in = new Input($body);
    $date = $in->date('inspected_on', false, 'วันที่ตรวจ');
    if ($date !== null) {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        if (!checkdate($m, $d, $y) || $y < 1970 || $y > 2157) { $in->error('inspected_on', 'วันที่ตรวจไม่ถูกต้อง'); $date = null; }
    }
    $inspector = $in->str('inspector', 100, false, 'ผู้ตรวจสอบ');
    $reviewer = $in->str('reviewer', 100, false, 'ผู้ทบทวน');
    $notes = $in->str('notes', 500, false, 'หมายเหตุประจำเดือน');
    $results = $body['results'] ?? [];
    if (!is_array($results) || count($results) > INSPECTION_MAX_ITEMS) $in->error('results', 'ผลตรวจไม่ถูกต้อง');
    $changes = [];
    foreach (is_array($results) ? $results : [] as $id => $value) {
        if (!preg_match('/^\d+$/D', (string)$id) || ($value !== null && !in_array($value, INSPECTION_RESULTS, true))) { $in->error('results', 'ผลตรวจไม่ถูกต้อง'); break; }
        $changes[(int)$id] = $value;
    }
    $in->check();

    $pdo = db(); $pdo->beginTransaction();
    try {
        if ($changes) {
            $ids = array_keys($changes);
            $own = $pdo->prepare('SELECT id FROM infra_inspection_items WHERE year = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
            $own->execute([$year, ...$ids]);
            if (count($own->fetchAll()) !== count($ids)) fail('มีรายการที่ถูกลบหรือไม่อยู่ในปีนี้ กรุณาโหลดหน้าใหม่', 409);
            $set = $pdo->prepare('INSERT INTO infra_inspection_results (item_id, month, result, updated_by) VALUES (?, ?, ?, ?)
                                  ON DUPLICATE KEY UPDATE updated_by = IF(result <=> VALUES(result), updated_by, VALUES(updated_by)), result = VALUES(result)');
            $clear = $pdo->prepare('DELETE FROM infra_inspection_results WHERE item_id = ? AND month = ?');
            foreach ($changes as $id => $value) {
                if ($value === null) $clear->execute([$id, $month]);
                else $set->execute([$id, $month, $value, $admin['id']]);
            }
        }
        if ($in->has('inspected_on') || $in->has('inspector') || $in->has('reviewer') || $in->has('notes')) {
            if ($date === null && $inspector === null && $reviewer === null && $notes === null) {
                $pdo->prepare('DELETE FROM infra_inspection_months WHERE year = ? AND month = ?')->execute([$year, $month]);
            } else {
                $pdo->prepare('INSERT INTO infra_inspection_months (year, month, inspected_on, inspector, reviewer, notes, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE inspected_on = VALUES(inspected_on), inspector = VALUES(inspector), reviewer = VALUES(reviewer), notes = VALUES(notes), updated_by = VALUES(updated_by)')
                    ->execute([$year, $month, $date, $inspector, $reviewer, $notes, $admin['id']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return inspection_get(['year' => (string)$year]);
}

/** Validate an item body; links the register asset when the code exists. */
function inspection_item_input(array $body): array
{
    $in = new Input($body);
    $code = strtoupper((string)$in->str('asset_code', 30, false, 'หมายเลขเครื่อง'));
    if ($code !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $code)) $in->error('asset_code', 'หมายเลขเครื่องใช้ได้เฉพาะ A-Z, 0-9, - . _');
    $item = $in->str('item', 150, false, 'รายการตรวจสอบ');
    $notes = $in->str('notes', 500, false, 'หมายเหตุ');
    if ($code === '' && $item === null) $in->error('asset_code', 'กรุณากรอกหมายเลขเครื่องหรือรายการตรวจสอบ');
    $in->check();
    $assetId = null;
    if ($code !== '') {
        $a = db()->prepare('SELECT id FROM assets WHERE asset_code = ?');
        $a->execute([$code]);
        $assetId = ($v = $a->fetchColumn()) === false ? null : (int)$v;
    }
    return ['asset_code' => $code === '' ? null : $code, 'asset_id' => $assetId, 'item' => $item, 'notes' => $notes];
}

function inspection_duplicate(PDOException $e): never
{
    if (($e->errorInfo[1] ?? null) === 1062) fail('ข้อมูลไม่ถูกต้อง', 422, ['asset_code' => 'หมายเลขเครื่องนี้มีอยู่แล้วในปีนี้']);
    throw $e;
}

function inspection_add_item(string $yearValue, array $body, array $admin): array
{
    $year = inspection_year($yearValue); $data = inspection_item_input($body);
    $count = db()->prepare('SELECT COUNT(*), COALESCE(MAX(sort_order), 0) FROM infra_inspection_items WHERE year = ?');
    $count->execute([$year]);
    [$n, $max] = $count->fetch(PDO::FETCH_NUM);
    if ((int)$n >= INSPECTION_MAX_ITEMS) fail('รายการในปีนี้ครบ ' . INSPECTION_MAX_ITEMS . ' รายการแล้ว', 422);
    try {
        db()->prepare('INSERT INTO infra_inspection_items (year, sort_order, asset_id, asset_code, item, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$year, (int)$max + 1, $data['asset_id'], $data['asset_code'], $data['item'], $data['notes'], $admin['id']]);
    } catch (PDOException $e) { inspection_duplicate($e); }
    return inspection_get(['year' => (string)$year]);
}

function inspection_item_year(int $id): int
{
    $s = db()->prepare('SELECT year FROM infra_inspection_items WHERE id = ?');
    $s->execute([$id]);
    return (int)($s->fetchColumn() ?: fail('ไม่พบรายการ', 404));
}

function inspection_update_item(int $id, array $body): array
{
    $year = inspection_item_year($id); $data = inspection_item_input($body);
    try {
        db()->prepare('UPDATE infra_inspection_items SET asset_id = ?, asset_code = ?, item = ?, notes = ? WHERE id = ?')
            ->execute([$data['asset_id'], $data['asset_code'], $data['item'], $data['notes'], $id]);
    } catch (PDOException $e) { inspection_duplicate($e); }
    return inspection_get(['year' => (string)$year]);
}

/** Items that already hold results stay, so a recorded inspection is never erased by accident. */
function inspection_delete_item(int $id): array
{
    $year = inspection_item_year($id);
    $s = db()->prepare('SELECT COUNT(*) FROM infra_inspection_results WHERE item_id = ?');
    $s->execute([$id]);
    if ((int)$s->fetchColumn() > 0) fail('รายการนี้มีผลตรวจบันทึกไว้แล้ว ลบไม่ได้ (ล้างผลทุกเดือนก่อน หรือใช้หมายเหตุแทน)', 409);
    db()->prepare('DELETE FROM infra_inspection_items WHERE id = ?')->execute([$id]);
    return inspection_get(['year' => (string)$year]);
}

/**
 * Add missing items in one go: copy another year's list, or pull equipment from the register
 * (in use / spare / in repair; retired is skipped). Codes already in the year are skipped.
 */
function inspection_bulk_add(string $yearValue, array $body, array $admin): array
{
    $year = inspection_year($yearValue);
    $source = $body['source'] ?? '';
    if ($source === 'year') {
        $from = inspection_year($body['from_year'] ?? '');
        if ($from === $year) fail('เลือกปีต้นทางที่ไม่ใช่ปีเดียวกัน', 422);
        $s = db()->prepare('SELECT i.asset_id, COALESCE(a.asset_code, i.asset_code) asset_code, i.item FROM infra_inspection_items i LEFT JOIN assets a ON a.id = i.asset_id WHERE i.year = ? ORDER BY i.sort_order, i.id');
        $s->execute([$from]);
        $candidates = $s->fetchAll();
        if (!$candidates) fail("ปี $from ยังไม่มีรายการให้คัดลอก", 422);
    } elseif ($source === 'assets') {
        $company = $body['company'] ?? '';
        if (!in_array($company, ['', 'ATS', 'INNO'], true)) fail('บริษัทไม่ถูกต้อง', 422);
        $s = db()->prepare("SELECT a.id asset_id, a.asset_code, NULL item FROM assets a WHERE a.status <> 'retired' AND (? = '' OR a.company = ?) ORDER BY a.company, a.asset_code");
        $s->execute([$company, $company]);
        $candidates = $s->fetchAll();
    } else {
        fail('กรุณาเลือกแหล่งรายการ', 422);
    }
    $pdo = db(); $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare('SELECT asset_code, item FROM infra_inspection_items WHERE year = ? FOR UPDATE');
        $existing->execute([$year]);
        $have = []; $count = 0; $max = 0;
        foreach ($existing->fetchAll() as $r) { $count++; if ($r['asset_code'] !== null) $have[$r['asset_code']] = true; }
        $m = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM infra_inspection_items WHERE year = ?');
        $m->execute([$year]); $max = (int)$m->fetchColumn();
        $insert = $pdo->prepare('INSERT INTO infra_inspection_items (year, sort_order, asset_id, asset_code, item, notes, created_by) VALUES (?, ?, ?, ?, ?, NULL, ?)');
        $added = 0;
        foreach ($candidates as $c) {
            $code = $c['asset_code'];
            if ($code !== null && isset($have[$code])) continue;
            // Items without a code can only be copied into an empty year, otherwise they would duplicate.
            if ($code === null && $count > 0) continue;
            if ($count + $added >= INSPECTION_MAX_ITEMS) fail('รายการในปีนี้จะเกิน ' . INSPECTION_MAX_ITEMS . ' รายการ', 422);
            $assetId = $c['asset_id'] === null ? null : (int)$c['asset_id'];
            if ($assetId === null && $code !== null) {
                $a = $pdo->prepare('SELECT id FROM assets WHERE asset_code = ?'); $a->execute([$code]);
                $assetId = ($v = $a->fetchColumn()) === false ? null : (int)$v;
            }
            $insert->execute([$year, $max + ++$added, $assetId, $code, $c['item'], $admin['id']]);
            if ($code !== null) $have[$code] = true;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['added' => $added] + inspection_get(['year' => (string)$year]);
}

/* ---------- Import the original FM-IT-002 workbook (one sheet per year) ---------- */

/** Parse sheets named like "FM-IT-002(2569)": B item, C machine number, D..O months, P notes, footer rows. */
function inspection_import_parse(array $book): array
{
    $years = [];
    foreach ($book['sheets'] as $sheet) {
        $c = $sheet['cells'];
        $g = fn(string $a): string => trim($c[$a] ?? '');
        if (!preg_match('/(25\d\d)/', $sheet['name'], $y) && !preg_match('/(25\d\d)/', $g('D2'), $y)) continue;
        $year = (int)$y[1];
        $cols = str_split('DEFGHIJKLMNO');
        $items = []; $months = []; $warnings = []; $errors = [];
        $meta = ['วันที่ตรวจ' => 'inspected_on', 'ผู้ตรวจสอบ' => 'inspector', 'ผู้ทบทวน' => 'reviewer'];
        for ($r = 4; $r < 1200; $r++) {
            $label = $g("B$r"); $code = strtoupper($g("C$r"));
            if (isset($meta[$label])) {
                foreach ($cols as $i => $col) {
                    $v = $g("$col$r");
                    if ($v === '') continue;
                    if ($meta[$label] === 'inspected_on') {
                        try { $v = document_import_datetime($v, $book['date1904'], false); }
                        catch (HttpError) { $errors[] = "แถว $r เดือน " . ($i + 1) . ": วันที่ตรวจไม่ถูกต้อง ($v)"; continue; }
                    } elseif (mb_strlen($v) > 100) { $v = mb_substr($v, 0, 100); }
                    $months[$i + 1][$meta[$label]] = $v;
                }
                continue;
            }
            if (str_contains($label . $code, 'FM-IT-') || ($label === '' && $code === '')) continue;
            if ($code !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $code)) { $errors[] = "แถว $r: หมายเลขเครื่องไม่ถูกต้อง ($code)"; continue; }
            if ($code !== '' && isset($items[$code])) { $warnings[] = "แถว $r: หมายเลข $code ซ้ำในไฟล์ ใช้แถวแรก"; continue; }
            $row = ['row' => $r, 'asset_code' => $code === '' ? null : $code, 'item' => $label === '' ? null : mb_substr($label, 0, 150),
                'notes' => $g("P$r") === '' ? null : mb_substr($g("P$r"), 0, 500), 'results' => []];
            foreach ($cols as $i => $col) {
                $raw = $g("$col$r");
                if ($raw === '') continue;
                $mark = ['/' => 'normal', 'X' => 'abnormal', 'x' => 'abnormal', '✓' => 'fixed', '✔' => 'fixed'][$raw] ?? null;
                if ($mark === null) { $errors[] = "แถว $r เดือน " . ($i + 1) . ": เครื่องหมาย “{$raw}” ไม่รู้จัก (ใช้ / X ✓)"; continue; }
                $row['results'][$i + 1] = $mark;
            }
            $items[$code !== '' ? $code : "#row$r"] = $row;
        }
        if (isset($years[$year])) { $errors[] = "มีมากกว่าหนึ่ง Sheet ของปี $year"; }
        if (count($items) > INSPECTION_MAX_ITEMS) $errors[] = 'รายการเกิน ' . INSPECTION_MAX_ITEMS . ' รายการ';
        $years[$year] = ['year' => $year, 'sheet' => $sheet['name'], 'items' => $items, 'months' => $months, 'warnings' => $warnings, 'errors' => $errors];
    }
    if (!$years) fail('ไม่พบ Sheet ใบตรวจสอบที่มีปี พ.ศ. (เช่น FM-IT-002(2569))', 422);
    krsort($years);
    return $years;
}

/**
 * Merge a parsed workbook into the live checklist. Adds missing items, fills/overwrites marks
 * the file has, and sets monthly footer values the file has. Never removes items or clears marks.
 * Items without a machine number match an existing item with the same name and no number, in order.
 */
function inspection_import_plan(array $parsed): array
{
    $pdo = db(); $plans = []; $summary = ['years' => 0, 'add_items' => 0, 'set_results' => 0, 'set_months' => 0, 'errors' => 0, 'warnings' => 0];
    foreach ($parsed as $year => $p) {
        $s = $pdo->prepare('SELECT id, asset_code, item, notes FROM infra_inspection_items WHERE year = ? ORDER BY sort_order, id');
        $s->execute([$year]);
        $existing = $s->fetchAll();
        $byCode = []; $noCode = [];
        foreach ($existing as $e) { if ($e['asset_code'] !== null) $byCode[$e['asset_code']] = $e; else $noCode[$e['item'] ?? ''][] = $e; }
        $r = $pdo->prepare('SELECT r.item_id, r.month, r.result FROM infra_inspection_results r JOIN infra_inspection_items i ON i.id = r.item_id WHERE i.year = ?');
        $r->execute([$year]);
        $marks = [];
        foreach ($r->fetchAll() as $m) $marks[(int)$m['item_id']][(int)$m['month']] = $m['result'];
        $mm = $pdo->prepare('SELECT month, inspected_on, inspector, reviewer FROM infra_inspection_months WHERE year = ?');
        $mm->execute([$year]);
        $monthNow = [];
        foreach ($mm->fetchAll() as $m) $monthNow[(int)$m['month']] = $m;

        $add = []; $set = []; $changedResults = 0; $rows = [];
        foreach ($p['items'] as $item) {
            $nameKey = $item['item'] ?? '';
            $match = $item['asset_code'] !== null ? ($byCode[$item['asset_code']] ?? null) : (empty($noCode[$nameKey]) ? null : array_shift($noCode[$nameKey]));
            if ($match === null) {
                $add[] = $item;
                $rows[] = ['action' => 'add', 'asset_code' => $item['asset_code'], 'item' => $item['item'], 'results' => count($item['results'])];
                continue;
            }
            $id = (int)$match['id']; $n = 0;
            foreach ($item['results'] as $month => $mark) {
                if (($marks[$id][$month] ?? null) === $mark) continue;
                $set[] = [$id, $month, $mark]; $n++;
                if (isset($marks[$id][$month])) $changedResults++;
            }
            if ($n) $rows[] = ['action' => 'update', 'asset_code' => $item['asset_code'], 'item' => $item['item'] ?? $match['item'], 'results' => $n];
        }
        $monthSet = [];
        foreach ($p['months'] as $month => $values) {
            $now = $monthNow[$month] ?? ['inspected_on' => null, 'inspector' => null, 'reviewer' => null];
            $merged = array_merge(['inspected_on' => $now['inspected_on'], 'inspector' => $now['inspector'], 'reviewer' => $now['reviewer']], $values);
            if ($merged['inspected_on'] === $now['inspected_on'] && $merged['inspector'] === $now['inspector'] && $merged['reviewer'] === $now['reviewer']) continue;
            $monthSet[$month] = $merged;
        }
        if (count($existing) + count($add) > INSPECTION_MAX_ITEMS) $p['errors'][] = 'รายการรวมจะเกิน ' . INSPECTION_MAX_ITEMS . ' รายการ';
        $setCount = count($set) + array_sum(array_map(fn($i) => count($i['results']), $add));
        $plans[$year] = ['year' => $year, 'sheet' => $p['sheet'], 'existing_items' => count($existing), 'file_items' => count($p['items']),
            'add_items' => count($add), 'set_results' => $setCount, 'overwrite_results' => $changedResults, 'set_months' => count($monthSet),
            'months' => array_map(fn($m, $v) => ['month' => $m] + $v, array_keys($monthSet), $monthSet),
            'rows' => array_slice($rows, 0, 300), 'rows_total' => count($rows), 'warnings' => $p['warnings'], 'errors' => $p['errors'],
            '_add' => $add, '_set' => $set, '_months' => $monthSet];
        $summary['years']++; $summary['add_items'] += count($add); $summary['set_results'] += $setCount;
        $summary['set_months'] += count($monthSet); $summary['errors'] += count($p['errors']); $summary['warnings'] += count($p['warnings']);
    }
    $hash = hash('sha256', json_encode(array_map(fn($p) => [$p['year'], $p['_add'], $p['_set'], $p['_months']], $plans), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $changes = $summary['add_items'] + $summary['set_results'] + $summary['set_months'];
    return ['summary' => $summary, 'years' => array_values($plans), 'preview_hash' => $hash, 'can_apply' => $summary['errors'] === 0 && $changes > 0];
}

function inspection_import_upload(array $file, array $admin, bool $apply = false): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('อัปโหลดไม่สำเร็จ กรุณาเลือกไฟล์ .xlsx ขนาดไม่เกิน 5 MB', 422);
    if (!is_string($file['name'] ?? null) || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') fail('รองรับเฉพาะไฟล์ Excel .xlsx', 422);
    if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) fail('ไม่พบไฟล์ที่อัปโหลด', 422);
    return inspection_import_run($file['tmp_name'], $admin, $apply ? ($_POST['preview_hash'] ?? '') : null);
}

function inspection_import_strip(array $plan): array
{
    foreach ($plan['years'] as &$y) unset($y['_add'], $y['_set'], $y['_months']);
    return $plan;
}

function inspection_import_run(string $path, array $admin, ?string $previewHash = null): array
{
    $parsed = inspection_import_parse(document_import_read($path));
    if ($previewHash === null) return inspection_import_strip(inspection_import_plan($parsed));
    if (!is_string($previewHash) || !preg_match('/^[a-f0-9]{64}$/D', $previewHash)) fail('กรุณาตรวจสอบไฟล์ก่อนยืนยัน Import', 422);
    $pdo = db(); $pdo->beginTransaction();
    try {
        // Lock every year in the file so the plan cannot change underneath the apply.
        $lock = $pdo->prepare('SELECT id FROM infra_inspection_items WHERE year = ? FOR UPDATE');
        foreach (array_keys($parsed) as $year) $lock->execute([$year]);
        $plan = inspection_import_plan($parsed);
        if (!hash_equals($plan['preview_hash'], $previewHash)) fail('ข้อมูลหรือไฟล์เปลี่ยนหลังตรวจสอบ กรุณาตรวจสอบไฟล์อีกครั้ง', 409);
        if (!$plan['can_apply']) fail('ยังมีข้อผิดพลาด หรือไม่มีข้อมูลใหม่ให้นำเข้า', 422);
        $insert = $pdo->prepare('INSERT INTO infra_inspection_items (year, sort_order, asset_id, asset_code, item, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $mark = $pdo->prepare('INSERT INTO infra_inspection_results (item_id, month, result, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE result = VALUES(result), updated_by = VALUES(updated_by)');
        $month = $pdo->prepare('INSERT INTO infra_inspection_months (year, month, inspected_on, inspector, reviewer, updated_by) VALUES (?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE inspected_on = VALUES(inspected_on), inspector = VALUES(inspector), reviewer = VALUES(reviewer), updated_by = VALUES(updated_by)');
        $asset = $pdo->prepare('SELECT id FROM assets WHERE asset_code = ?');
        $maxSort = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM infra_inspection_items WHERE year = ?');
        foreach ($plan['years'] as $y) {
            $maxSort->execute([$y['year']]); $sort = (int)$maxSort->fetchColumn();
            foreach ($y['_add'] as $item) {
                $assetId = null;
                if ($item['asset_code'] !== null) { $asset->execute([$item['asset_code']]); $assetId = ($v = $asset->fetchColumn()) === false ? null : (int)$v; }
                $insert->execute([$y['year'], ++$sort, $assetId, $item['asset_code'], $item['item'], $item['notes'], $admin['id']]);
                $id = (int)$pdo->lastInsertId();
                foreach ($item['results'] as $m => $r) $mark->execute([$id, $m, $r, $admin['id']]);
            }
            foreach ($y['_set'] as [$id, $m, $r]) $mark->execute([$id, $m, $r, $admin['id']]);
            foreach ($y['_months'] as $m => $v) $month->execute([$y['year'], $m, $v['inspected_on'], $v['inspector'], $v['reviewer'], $admin['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return inspection_import_strip($plan) + ['applied' => true];
}
