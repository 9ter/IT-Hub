<?php
declare(strict_types=1);

// FM-IT-005 backup log. Every write uses connection-local TEMP tables (created from sql/17 when the real table is missing).
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/asset_import.php';
require __DIR__ . '/../src/document_import.php';
require __DIR__ . '/../src/backups.php';
$pdo = db();
$ddl = file_get_contents(__DIR__ . '/../../sql/17_server_backups.sql');
$ddl = preg_replace(['/^--.*$/m', '/^\s*CONSTRAINT fk_.*\n/m'], '', $ddl);
$ddl = preg_replace('/,(\s*\))/', '$1', $ddl);
$ddl = str_replace('CREATE TABLE server_backups', 'CREATE TEMPORARY TABLE server_backups', $ddl);
$pdo->exec($ddl);
foreach (['admins'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE `fixture_$table` LIKE `$table`");
    $pdo->exec("ALTER TABLE `fixture_$table` RENAME TO `$table`");
}
$pdo->exec("INSERT INTO admins (id, username, password_hash, display_name) VALUES (1, 'a', 'x', 'แอดมิน ทดสอบ')");
$admin = ['id' => 1, 'display_name' => 'แอดมิน ทดสอบ'];
$checks = 0;
function check(bool $value, string $message): void
{
    global $checks;
    if (!$value) throw new RuntimeException($message);
    $checks++;
}
function fails(callable $fn, int $status, string $message): HttpError
{
    try { $fn(); } catch (HttpError $e) { check($e->status === $status, "$message (got {$e->status}: {$e->getMessage()})"); return $e; }
    throw new RuntimeException("Expected failure: $message");
}
/** Minimal original-layout workbook with inline strings. */
function workbook(array $sheets): string
{
    $path = tempnam(sys_get_temp_dir(), 'bkp') . '.xlsx';
    $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $ct = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $wb = ''; $rels = '';
    $n = 0;
    foreach ($sheets as $name => $cells) {
        $n++;
        $ct .= "<Override PartName=\"/xl/worksheets/sheet$n.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        $wb .= "<sheet name=\"" . htmlspecialchars((string)$name) . "\" sheetId=\"$n\" r:id=\"rId$n\"/>";
        $rels .= "<Relationship Id=\"rId$n\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$n.xml\"/>";
        $rows = [];
        foreach ($cells as $addr => $v) { preg_match('/^([A-Z]+)(\d+)$/', $addr, $m); $rows[(int)$m[2]][$addr] = $v; }
        ksort($rows);
        $xml = '';
        foreach ($rows as $r => $cs) {
            $xml .= "<row r=\"$r\">";
            foreach ($cs as $addr => $v) $xml .= is_int($v) ? "<c r=\"$addr\"><v>$v</v></c>" : "<c r=\"$addr\" t=\"inlineStr\"><is><t>" . htmlspecialchars($v) . '</t></is></c>';
            $xml .= '</row>';
        }
        $zip->addFromString("xl/worksheets/sheet$n.xml", '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $xml . '</sheetData></worksheet>');
    }
    $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wb . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
    $zip->close();
    return $path;
}

$base = ['backup_date' => '2026-11-20', 'server' => '', 'dataset' => ' atthasan ', 'data_size' => '870.45 GB', 'source' => 'NAS_ATS',
    'device' => 'BACKUP_ATS', 'folder' => 'atthasan', 'backup_size' => '870.45 GB', 'started_at' => '1:00', 'finished_at' => '03:00:01',
    'maker' => 'ผู้จัดทำ ก', 'acknowledger' => 'ผู้รับทราบ ข', 'notes' => ''];

// --- validation
$e = fails(fn() => backup_create(['backup_date' => '2026-02-30', 'dataset' => ''] , $admin), 422, 'invalid date + missing dataset');
check(isset($e->errors['backup_date'], $e->errors['dataset']), 'field errors for date and dataset');
$e = fails(fn() => backup_create(['started_at' => '25:00', 'finished_at' => 'abc'] + $base, $admin), 422, 'bad times');
check(isset($e->errors['started_at'], $e->errors['finished_at']), 'field errors for both times');
fails(fn() => backup_create(['dataset' => str_repeat('ก', 151)] + $base, $admin), 422, 'dataset too long');
fails(fn() => backup_create(['maker' => ['x']] + $base, $admin), 422, 'array value rejected');
check((int)$pdo->query('SELECT COUNT(*) FROM server_backups')->fetchColumn() === 0, 'rejected writes stored nothing');

// --- create / normalise / duplicate
$a = backup_create($base, $admin);
check($a['server'] === 'SERVER' && $a['dataset'] === 'atthasan', 'default server + trimmed dataset');
check($a['started_at'] === '01:00:00' && $a['finished_at'] === '03:00:01', 'times normalised to HH:MM:SS');
check($a['notes'] === null, 'empty notes stored as null');
$e = fails(fn() => backup_create($base, $admin), 422, 'same date/server/dataset rejected');
check(isset($e->errors['backup_date']), 'duplicate reported on date field');
$b = backup_create(['dataset' => 'erp', 'backup_date' => '2026-11-13'] + $base, $admin);
$c = backup_create(['backup_date' => '2025-12-26'] + $base, $admin);
$d = backup_create(['server' => 'SERVER-2'] + $base, $admin);
check($d['server'] === 'SERVER-2', 'same day different server allowed');

// --- list by Buddhist year
$y = backup_get(['year' => '2569']);
check($y['year'] === 2569 && count($y['rows']) === 3, 'year 2569 lists its 3 rows');
check($y['rows'][0]['backup_date'] === '2026-11-13', 'sorted by date');
check($y['servers'] === ['SERVER', 'SERVER-2'], 'servers of the year');
check(array_column($y['years'], 'year') === [2569, 2568], 'years with counts');
check($y['last']['backup_date'] === '2026-11-20', 'last entry for defaults');
check(in_array('atthasan', $y['suggest']['dataset'], true) && in_array('ผู้จัดทำ ก', $y['suggest']['maker'], true), 'suggestions');
check($y['form']['code'] === 'FM-IT-005', 'form definition for print');
check($y['rows'][1]['updated_by_name'] === 'แอดมิน ทดสอบ', 'updated by name');
check(count(backup_get(['year' => '2568'])['rows']) === 1, 'year 2568 has 1 row');
check(backup_get([])['year'] === backup_current_year(), 'default current year');
fails(fn() => backup_get(['year' => '69']), 422, 'bad year');

// --- update / delete
$u = backup_update($b['id'], ['notes' => 'ไฟดับ', 'started_at' => '', 'dataset' => 'erp', 'backup_date' => '2026-11-13'] + $base, $admin);
check($u['notes'] === 'ไฟดับ' && $u['started_at'] === null, 'update notes and clear time');
fails(fn() => backup_update($b['id'], $base, $admin), 422, 'update into an existing run rejected');
check(backup_find($b['id'])['dataset'] === 'erp', 'rejected update left row unchanged');
fails(fn() => backup_update(99999, $base, $admin), 404, 'update missing');
check(backup_delete($d['id'])['deleted'] === true, 'delete');
fails(fn() => backup_delete($d['id']), 404, 'delete twice');

// --- source conversion (Excel / old drafts)
$conv = backup_from_source(['date' => '19/12/568', 'dataset' => 'atthasan', 'started' => '01:002', 'finished' => '01:03:07', 'notes' => '[ตรวจทานวันที่ต้นทาง: 19/12/568]'], 2568, 'SERVER');
check($conv['errors'] === [] && $conv['row']['backup_date'] === '2025-12-19', '3-digit year takes sheet year');
check($conv['row']['started_at'] === null && $conv['row']['finished_at'] === '01:03:07', 'malformed time kept out of the column');
check(str_contains($conv['row']['notes'], '19/12/568') && str_contains($conv['row']['notes'], '01:002') && !str_contains($conv['row']['notes'], 'ตรวจทาน'), 'original text kept in notes, old marker replaced');
check(backup_from_source(['date' => '19/12/567', 'dataset' => 'x'], 2568, 'SERVER')['errors'] !== [], 'non-matching 3-digit year is an error');
check(backup_from_source(['date' => '31/02/2569', 'dataset' => 'x'], 2569, 'SERVER')['errors'] !== [], 'impossible date is an error');
check(backup_from_source(['date' => '02/01/2569', 'dataset' => ''], 2569, 'SERVER')['errors'] !== [], 'missing dataset is an error');
check(backup_from_source(['date' => '24/10/2568', 'dataset' => 'a', 'notes' => 'ไฟดับ ไม่มีการ Backup'], 2568, 'SERVER')['row']['notes'] === 'ไฟดับ ไม่มีการ Backup', 'plain notes untouched');

// --- import original layout
$head = ['A1' => 'รายงานการ BACK UP ข้อมูล SERVER', 'A2' => 'ประจำปี ……2569……', 'A4' => 'ว/ด/ป', 'B5' => 'ข้อมูลที่'];
$row = fn(int $r, $date, string $size, string $start = '4.1689814814814811E-2') => ["A$r" => $date, "B$r" => 'atthasan', "C$r" => $size, "D$r" => 'NAS_ATS', "E$r" => 'BACKUP_ATS', "F$r" => 'atthasan', "G$r" => $size, "H$r" => $start, "I$r" => '0.12546296296296297', "J$r" => 'ผู้จัดทำ ก', "K$r" => 'ผู้รับทราบ ข'];
// 244652 = 30/10/2569 as Excel stores it (serial with the Buddhist year); 2026-11-20 already exists in the log with other values.
$file = workbook(['2569' => $head + $row(6, 244652, '902.41 GB') + $row(7, '20/11/2569', '999 GB') + $row(8, '27/11/2569', '880 GB') + $row(9, '27/11/2569', '881 GB') + ['A10' => 'FM-IT-005,REV:00']]);
$p = backup_import_run($file, $admin);
check($p['summary']['add'] === 2 && $p['summary']['differs'] === 1 && $p['summary']['errors'] === 0, 'preview: 2 new, 1 differs');
check($p['summary']['warnings'] === 1, 'duplicate row in file warned');
$add = array_values(array_filter($p['sources'][0]['rows'], fn($r) => $r['action'] === 'add'));
check($add[0]['backup_date'] === '2026-10-30' && $add[0]['started_at'] === '01:00:02' && $add[0]['finished_at'] === '03:00:40', 'serial date + time converted');
check(!isset($p['sources'][0]['_add']), 'internal plan stripped');
check($p['can_apply'] === true, 'can apply');
fails(fn() => backup_import_run($file, $admin, str_repeat('0', 64)), 409, 'stale hash');
$done = backup_import_run($file, $admin, $p['preview_hash']);
check(!empty($done['applied']), 'applied');
check(count(backup_get(['year' => '2569'])['rows']) === 4, 'two rows added');
check(backup_find($a['id'])['data_size'] === '870.45 GB', 'existing run not overwritten by import');
$src = $pdo->query("SELECT source_note, created_by FROM server_backups WHERE backup_date = '2026-10-30'")->fetch();
check(str_contains($src['source_note'], 'แถว 6') && (int)$src['created_by'] === 1, 'source note + creator recorded');
$again = backup_import_run($file, $admin);
check($again['summary']['add'] === 0 && $again['can_apply'] === false, 'second import adds nothing');
fails(fn() => backup_import_run($file, $admin, $again['preview_hash']), 422, 'nothing to apply');
$bad = workbook(['2569' => $head + $row(6, '31/02/2569', '1 GB') + $row(7, '01/12/2569', '1 GB')]);
$pb = backup_import_run($bad, $admin);
check($pb['summary']['errors'] === 1 && $pb['can_apply'] === false, 'bad date blocks import');
$none = workbook(['Sheet1' => ['A1' => 'x']]);
fails(fn() => backup_import_run($none, $admin), 422, 'no year sheet');
// rollback: a failure in the middle of apply leaves nothing behind
$roll = workbook(['2569' => $head + $row(6, '04/12/2569', '1 GB') + $row(7, '11/12/2569', '1 GB')]);
$pr = backup_import_run($roll, $admin);
$pdo->exec("ALTER TABLE server_backups ADD CONSTRAINT chk_test CHECK (backup_date <> '2026-12-11')");
try { backup_import_run($roll, $admin, $pr['preview_hash']); throw new RuntimeException('expected constraint failure'); } catch (PDOException) { $checks++; }
check((int)$pdo->query("SELECT COUNT(*) FROM server_backups WHERE backup_date = '2026-12-04'")->fetchColumn() === 0, 'failed import rolled back');
check(!$pdo->inTransaction(), 'no open transaction');

// --- real files, read-only: the original workbook parses without errors
$real = __DIR__ . '/../../FM-IT/FM-IT-005 รายงานการ BACK UP ข้อมูล.xlsx';
if (is_file($real)) {
    $sources = backup_import_sources(document_import_read($real));
    $errs = 0; $n = 0;
    foreach ($sources as $s) foreach ($s['rows'] as [$raw]) { $n++; $errs += count(backup_from_source($raw, $s['year'], 'SERVER')['errors']); }
    check($n > 0 && $errs === 0, "original FM-IT-005 workbook: $n rows convert without errors");
}
foreach ([$file, $bad, $none, $roll] as $f) @unlink($f);
echo "backups: $checks checks passed\n";
