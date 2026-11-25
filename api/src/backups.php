<?php
declare(strict_types=1);

/*
 * FM-IT-005 รายงานการ BACK UP ข้อมูล SERVER — a running log, one row per backup run.
 * Admins add / edit rows any time (like the FM-IT-008 borrow records) and print the yearly
 * form; there is no "create document" step. maker / acknowledger are plain names as on the
 * paper form, never signatures or approvals.
 */
const BACKUP_TEXT_FIELDS = [
    'server' => ['ชื่อ Server', 100], 'dataset' => ['ข้อมูลที่ BACK UP', 150], 'data_size' => ['ขนาดไฟล์จริง', 40],
    'source' => ['แหล่งจัดเก็บข้อมูลหลัก', 150], 'device' => ['อุปกรณ์สำรอง', 150], 'folder' => ['Folder / File', 255],
    'backup_size' => ['ขนาดไฟล์ BACK UP', 40], 'maker' => ['จัดทำ', 100], 'acknowledger' => ['รับทราบ', 100], 'notes' => ['หมายเหตุ', 500],
];
const BACKUP_DEFAULT_SERVER = 'SERVER';
const BACKUP_MAX_IMPORT_ROWS = 2000;
const BACKUP_COLUMNS = 'id, backup_date, server, dataset, data_size, source, device, folder, backup_size, started_at, finished_at, maker, acknowledger, notes';

function backup_current_year(): int
{
    return (int)date('Y') + 543;
}

function backup_year(mixed $value): int
{
    $year = is_int($value) ? $value : (is_string($value) && preg_match('/^\d{4}$/D', trim($value)) ? (int)trim($value) : 0);
    if ($year < 2500 || $year > 2700) fail('ปี พ.ศ. ไม่ถูกต้อง', 422);
    return $year;
}

/** HH:MM or HH:MM:SS → HH:MM:SS; null when empty or not a clock time. */
function backup_time(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/^(\d{1,2})[:.](\d{2})(?:[:.](\d{2}))?$/D', trim($value), $m)) return null;
    [$h, $i, $s] = [(int)$m[1], (int)$m[2], (int)($m[3] ?? 0)];
    return $h > 23 || $i > 59 || $s > 59 ? null : sprintf('%02d:%02d:%02d', $h, $i, $s);
}

/** Validated row from the admin form. */
function backup_input(array $body): array
{
    $errors = []; $row = [];
    $date = is_string($body['backup_date'] ?? null) ? trim($body['backup_date']) : '';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 2000 || (int)$m[1] > 2100) {
        $errors['backup_date'] = 'กรุณาระบุวันที่ Backup';
    }
    $row['backup_date'] = $date;
    foreach (BACKUP_TEXT_FIELDS as $key => [$label, $max]) {
        $raw = $body[$key] ?? null;
        if ($raw !== null && !is_string($raw) && !is_int($raw) && !is_float($raw)) { $errors[$key] = "$label ไม่ถูกต้อง"; continue; }
        $value = trim((string)$raw);
        if (mb_strlen($value) > $max) $errors[$key] = "$label ยาวเกิน $max ตัวอักษร";
        $row[$key] = $value === '' ? null : $value;
    }
    $row['server'] ??= BACKUP_DEFAULT_SERVER;
    if ($row['dataset'] === null) $errors['dataset'] = 'กรุณาระบุข้อมูลที่ BACK UP';
    foreach (['started_at' => 'เวลาเริ่ม', 'finished_at' => 'เวลาเสร็จ'] as $key => $label) {
        $raw = is_string($body[$key] ?? null) ? trim($body[$key]) : '';
        $row[$key] = $raw === '' ? null : backup_time($raw);
        if ($raw !== '' && $row[$key] === null) $errors[$key] = "$label ใช้รูปแบบ ชช:นน หรือ ชช:นน:วว";
    }
    if ($errors) fail('กรุณาตรวจสอบข้อมูล', 422, $errors);
    return $row;
}

function backup_public(array $r): array
{
    return [
        'id' => (int)$r['id'], 'backup_date' => $r['backup_date'], 'server' => $r['server'], 'dataset' => $r['dataset'],
        'data_size' => $r['data_size'], 'source' => $r['source'], 'device' => $r['device'], 'folder' => $r['folder'],
        'backup_size' => $r['backup_size'], 'started_at' => $r['started_at'], 'finished_at' => $r['finished_at'],
        'maker' => $r['maker'], 'acknowledger' => $r['acknowledger'], 'notes' => $r['notes'],
        'updated_at' => $r['updated_at'] ?? null, 'updated_by_name' => $r['updated_by_name'] ?? null,
    ];
}

/** One Buddhist year of the log, plus what the add form needs (last entry, suggestions). */
function backup_get(array $query): array
{
    $pdo = db();
    $years = array_map(fn($y) => ['year' => (int)$y['year'], 'count' => (int)$y['n']],
        $pdo->query('SELECT YEAR(backup_date) + 543 year, COUNT(*) n FROM server_backups GROUP BY YEAR(backup_date) ORDER BY year DESC')->fetchAll());
    $year = isset($query['year']) && $query['year'] !== '' ? backup_year($query['year']) : backup_current_year();
    $s = $pdo->prepare('SELECT b.' . str_replace(', ', ', b.', BACKUP_COLUMNS) . ', b.updated_at, a.display_name updated_by_name
                          FROM server_backups b LEFT JOIN admins a ON a.id = b.updated_by
                         WHERE b.backup_date BETWEEN ? AND ? ORDER BY b.backup_date, b.server, b.dataset, b.id');
    $s->execute([sprintf('%04d-01-01', $year - 543), sprintf('%04d-12-31', $year - 543)]);
    $rows = array_map('backup_public', $s->fetchAll());
    $last = $pdo->query('SELECT ' . BACKUP_COLUMNS . ' FROM server_backups ORDER BY backup_date DESC, id DESC LIMIT 1')->fetch();
    $suggest = [];
    foreach (['server', 'dataset', 'source', 'device', 'folder', 'maker', 'acknowledger'] as $key) {
        $suggest[$key] = $pdo->query("SELECT $key FROM server_backups WHERE $key IS NOT NULL AND $key <> '' GROUP BY $key ORDER BY MAX(backup_date) DESC LIMIT 30")->fetchAll(PDO::FETCH_COLUMN);
    }
    return [
        'year' => $year, 'current_year' => backup_current_year(), 'years' => $years, 'rows' => $rows,
        'servers' => array_values(array_unique(array_column($rows, 'server'))),
        'last' => $last ? backup_public($last) : null, 'suggest' => $suggest,
        'form' => document_forms()['FM-IT-005'],
    ];
}

function backup_find(int $id, bool $lock = false): array
{
    $s = db()->prepare('SELECT ' . BACKUP_COLUMNS . ' FROM server_backups WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $s->execute([$id]);
    return $s->fetch() ?: fail('ไม่พบบันทึก Backup', 404);
}

function backup_duplicate(PDOException $e, array $row): never
{
    if (($e->errorInfo[1] ?? null) === 1062 && str_contains((string)$e->getMessage(), 'uq_server_backup_run')) {
        fail('มีบันทึกนี้แล้ว', 422, ['backup_date' => "มีบันทึก Backup “{$row['dataset']}” ของ {$row['server']} วันที่นี้แล้ว แก้ไขแถวเดิมแทน"]);
    }
    throw $e;
}

function backup_create(array $body, array $admin): array
{
    $row = backup_input($body);
    try {
        db()->prepare('INSERT INTO server_backups (backup_date, server, dataset, data_size, source, device, folder, backup_size, started_at, finished_at, maker, acknowledger, notes, created_by, updated_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([...backup_values($row), $admin['id'], $admin['id']]);
    } catch (PDOException $e) { backup_duplicate($e, $row); }
    return backup_public(backup_find((int)db()->lastInsertId()));
}

function backup_values(array $row): array
{
    return [$row['backup_date'], $row['server'], $row['dataset'], $row['data_size'], $row['source'], $row['device'], $row['folder'],
        $row['backup_size'], $row['started_at'], $row['finished_at'], $row['maker'], $row['acknowledger'], $row['notes']];
}

function backup_update(int $id, array $body, array $admin): array
{
    $row = backup_input($body);
    $pdo = db(); $pdo->beginTransaction();
    try {
        backup_find($id, true);
        $pdo->prepare('UPDATE server_backups SET backup_date = ?, server = ?, dataset = ?, data_size = ?, source = ?, device = ?, folder = ?, backup_size = ?,
                              started_at = ?, finished_at = ?, maker = ?, acknowledger = ?, notes = ?, updated_by = ? WHERE id = ?')
            ->execute([...backup_values($row), $admin['id'], $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException) backup_duplicate($e, $row);
        throw $e;
    }
    return backup_public(backup_find($id));
}

function backup_delete(int $id): array
{
    backup_find($id);
    db()->prepare('DELETE FROM server_backups WHERE id = ?')->execute([$id]);
    return ['deleted' => true];
}

/**
 * Turn a row from the original Excel / the old FM-IT-005 drafts (date dd/mm/พ.ศ., times as text)
 * into a log row. A 3-digit year that matches the end of the sheet year (19/12/568 in sheet 2568)
 * takes the sheet year; the original text is kept in notes. Anything else is an error, not a guess.
 */
function backup_from_source(array $raw, int $sheetYear, string $server): array
{
    $text = fn(string $k): ?string => ($v = trim((string)($raw[$k] ?? ''))) === '' ? null : $v;
    $notes = $text('notes'); $extra = []; $errors = [];
    $notes = $notes === null ? null : (trim((string)preg_replace('/\s*\[ตรวจทาน[^\]]*\]/u', '', $notes)) ?: null);
    $dateText = $text('date') ?? '';
    $date = null;
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{3,4})$/D', $dateText, $m)) {
        $y = (int)$m[3];
        if (strlen($m[3]) === 3 && str_ends_with((string)$sheetYear, $m[3])) { $y = $sheetYear; $extra[] = "ต้นฉบับพิมพ์ ว/ด/ป เป็น $dateText"; }
        if ($y >= 2400) $y -= 543;
        if ($y >= 2000 && $y <= 2100 && checkdate((int)$m[2], (int)$m[1], $y)) $date = sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]);
    }
    if ($date === null) $errors[] = 'ว/ด/ป ไม่ถูกต้อง (' . ($dateText === '' ? 'ว่าง' : $dateText) . ')';
    $times = [];
    foreach (['started' => 'เวลาเริ่ม', 'finished' => 'เวลาเสร็จ'] as $k => $label) {
        $v = $text($k);
        $times[$k] = $v === null ? null : backup_time($v);
        if ($v !== null && $times[$k] === null) $extra[] = "ต้นฉบับ{$label}: $v";
    }
    if ($extra) $notes = trim(($notes ?? '') . ' [' . implode(' · ', $extra) . ']');
    $row = ['backup_date' => $date, 'server' => $server, 'dataset' => $text('dataset'), 'data_size' => $text('size'),
        'source' => $text('source'), 'device' => $text('device'), 'folder' => $text('folder'), 'backup_size' => $text('backup_size'),
        'started_at' => $times['started'], 'finished_at' => $times['finished'], 'maker' => $text('maker'),
        'acknowledger' => $text('acknowledger'), 'notes' => $notes];
    if ($row['dataset'] === null) $errors[] = 'ไม่มีข้อมูลที่ BACK UP';
    foreach (BACKUP_TEXT_FIELDS as $key => [$label, $max]) {
        if ($row[$key] !== null && mb_strlen($row[$key]) > $max) $errors[] = "$label ยาวเกิน $max ตัวอักษร";
    }
    return ['row' => $row, 'errors' => $errors];
}

function backup_key(array $row): string
{
    return $row['backup_date'] . '|' . mb_strtolower($row['server']) . '|' . mb_strtolower((string)$row['dataset']);
}

/**
 * Compare source rows with the log: new runs are added; runs already in the log are never
 * overwritten (edits made in IT Hub win), differences are only reported.
 * $sources: [['label' => sheet/document, 'year' => int, 'rows' => [[raw, source_note], ...]], ...]
 */
function backup_merge_plan(array $sources): array
{
    $existing = [];
    foreach (db()->query('SELECT ' . BACKUP_COLUMNS . ' FROM server_backups')->fetchAll() as $r) $existing[backup_key($r)] = $r;
    $compare = ['data_size', 'source', 'device', 'folder', 'backup_size', 'started_at', 'finished_at', 'maker', 'acknowledger', 'notes'];
    $plans = []; $seen = []; $summary = ['add' => 0, 'same' => 0, 'differs' => 0, 'errors' => 0, 'warnings' => 0];
    foreach ($sources as $src) {
        $p = ['label' => $src['label'], 'year' => $src['year'], 'file_rows' => count($src['rows']), 'add' => 0, 'same' => 0, 'differs' => 0,
            'rows' => [], 'errors' => [], 'warnings' => [], '_add' => []];
        foreach ($src['rows'] as $n => [$raw, $sourceNote, $line]) {
            $c = backup_from_source($raw, $src['year'], $src['server'] ?? BACKUP_DEFAULT_SERVER);
            if ($c['errors']) { foreach ($c['errors'] as $e) $p['errors'][] = "$line: $e"; continue; }
            $row = $c['row'] + ['source_note' => $sourceNote];
            $key = backup_key($row);
            if (isset($seen[$key])) { $p['warnings'][] = "$line: ซ้ำกับ {$seen[$key]} (วันที่/Server/ข้อมูลเดียวกัน) ใช้แถวแรก"; continue; }
            $seen[$key] = "{$src['label']} $line";
            if (isset($existing[$key])) {
                $diff = array_values(array_filter($compare, fn($k) => (string)($existing[$key][$k] ?? '') !== (string)($row[$k] ?? '')));
                if ($diff) { $p['differs']++; $p['rows'][] = ['action' => 'differs', 'line' => $line, 'fields' => $diff] + backup_brief($row); }
                else $p['same']++;
                continue;
            }
            $p['add']++; $p['_add'][] = $row;
            $p['rows'][] = ['action' => 'add', 'line' => $line, 'fields' => []] + backup_brief($row);
        }
        if (count($src['rows']) > BACKUP_MAX_IMPORT_ROWS) $p['errors'][] = 'แถวเกิน ' . BACKUP_MAX_IMPORT_ROWS . ' แถว';
        $p['rows_total'] = count($p['rows']); $p['rows'] = array_slice($p['rows'], 0, 400);
        foreach (['add', 'same', 'differs'] as $k) $summary[$k] += $p[$k];
        $summary['errors'] += count($p['errors']); $summary['warnings'] += count($p['warnings']);
        $plans[] = $p;
    }
    $hash = hash('sha256', json_encode(array_map(fn($p) => $p['_add'], $plans), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    return ['summary' => $summary, 'sources' => $plans, 'preview_hash' => $hash, 'can_apply' => $summary['errors'] === 0 && $summary['add'] > 0];
}

function backup_brief(array $row): array
{
    return ['backup_date' => $row['backup_date'], 'server' => $row['server'], 'dataset' => $row['dataset'], 'data_size' => $row['data_size'],
        'started_at' => $row['started_at'], 'finished_at' => $row['finished_at'], 'notes' => $row['notes']];
}

function backup_strip(array $plan): array
{
    foreach ($plan['sources'] as &$p) unset($p['_add']);
    return $plan;
}

/** Insert the planned new rows; caller holds the transaction. */
function backup_apply_adds(array $plan, ?int $adminId): int
{
    $insert = db()->prepare('INSERT INTO server_backups (backup_date, server, dataset, data_size, source, device, folder, backup_size, started_at, finished_at, maker, acknowledger, notes, source_note, created_by, updated_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $n = 0;
    foreach ($plan['sources'] as $p) foreach ($p['_add'] as $row) {
        $insert->execute([...backup_values($row), mb_substr((string)$row['source_note'], 0, 255), $adminId, $adminId]);
        $n++;
    }
    return $n;
}

/** Original FM-IT-005 workbook: one Sheet per year (name has the year), data from row 6, columns A–L. */
function backup_import_sources(array $book): array
{
    $sources = [];
    foreach ($book['sheets'] as $sheet) {
        $c = $sheet['cells'];
        $g = fn(string $a): string => trim($c[$a] ?? '');
        if (!preg_match('/(25\d\d)/', $sheet['name'], $y) && !preg_match('/(25\d\d)/', $g('A2'), $y)) continue;
        $rows = [];
        for ($r = 6; $r < 6 + BACKUP_MAX_IMPORT_ROWS + 1; $r++) {
            if (str_starts_with($g("A$r"), 'FM-IT-')) break;
            if ($g("B$r") === '') continue;
            $raw = ['date' => document_import_date_text($g("A$r"), $book['date1904']), 'dataset' => $g("B$r"), 'size' => $g("C$r"),
                'source' => $g("D$r"), 'device' => $g("E$r"), 'folder' => $g("F$r"), 'backup_size' => $g("G$r"),
                'started' => document_import_time_text($g("H$r")), 'finished' => document_import_time_text($g("I$r")),
                'maker' => $g("J$r"), 'acknowledger' => $g("K$r"), 'notes' => $g("L$r")];
            $rows[] = [$raw, "Import Excel Sheet {$sheet['name']} แถว $r", "แถว $r"];
        }
        $sources[] = ['label' => 'Sheet ' . $sheet['name'], 'year' => (int)$y[1], 'server' => BACKUP_DEFAULT_SERVER, 'rows' => $rows];
    }
    if (!$sources) fail('ไม่พบ Sheet รายงาน Backup ที่มีปี พ.ศ. (เช่น 2569)', 422);
    return $sources;
}

function backup_import_upload(array $file, array $admin, bool $apply = false): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('อัปโหลดไม่สำเร็จ กรุณาเลือกไฟล์ .xlsx ขนาดไม่เกิน 5 MB', 422);
    if (!is_string($file['name'] ?? null) || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') fail('รองรับเฉพาะไฟล์ Excel .xlsx', 422);
    if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) fail('ไม่พบไฟล์ที่อัปโหลด', 422);
    return backup_import_run($file['tmp_name'], $admin, $apply ? ($_POST['preview_hash'] ?? '') : null);
}

function backup_import_run(string $path, array $admin, ?string $previewHash = null): array
{
    $sources = backup_import_sources(document_import_read($path));
    if ($previewHash === null) return backup_strip(backup_merge_plan($sources));
    if (!is_string($previewHash) || !preg_match('/^[a-f0-9]{64}$/D', $previewHash)) fail('กรุณาตรวจสอบไฟล์ก่อนยืนยัน Import', 422);
    $pdo = db(); $pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM server_backups FOR UPDATE')->fetchAll();
        $plan = backup_merge_plan($sources);
        if (!hash_equals($plan['preview_hash'], $previewHash)) fail('ข้อมูลหรือไฟล์เปลี่ยนหลังตรวจสอบ กรุณาตรวจสอบไฟล์อีกครั้ง', 409);
        if (!$plan['can_apply']) fail('ยังมีข้อผิดพลาด หรือไม่มีข้อมูลใหม่ให้นำเข้า', 422);
        backup_apply_adds($plan, (int)$admin['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return backup_strip($plan) + ['applied' => true];
}
