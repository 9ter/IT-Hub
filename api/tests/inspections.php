<?php
declare(strict_types=1);

// FM-IT-002 yearly inspection checklist. Every write uses connection-local TEMP tables.
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/asset_import.php';
require __DIR__ . '/../src/document_import.php';
require __DIR__ . '/../src/inspections.php';
$pdo = db();
foreach (['infra_inspection_items', 'infra_inspection_results', 'infra_inspection_months', 'assets', 'asset_categories', 'admins'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE `fixture_$table` LIKE `$table`");
    $pdo->exec("ALTER TABLE `fixture_$table` RENAME TO `$table`");
}
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
function row(array $d, string $code): ?array
{
    foreach ($d['items'] as $i) if ($i['asset_code'] === $code) return $i;
    return null;
}
/** Minimal original-layout workbook with inline strings. */
function workbook(array $sheets): string
{
    $path = tempnam(sys_get_temp_dir(), 'insp') . '.xlsx';
    $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $ct = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $wb = ''; $rels = '';
    $n = 0;
    foreach ($sheets as $name => $cells) {
        $n++;
        $ct .= "<Override PartName=\"/xl/worksheets/sheet$n.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        $wb .= "<sheet name=\"" . htmlspecialchars($name) . "\" sheetId=\"$n\" r:id=\"rId$n\"/>";
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

$pdo->exec("INSERT INTO admins (id, username, password_hash, display_name) VALUES (1, 'a', 'x', 'Admin A'), (2, 'b', 'x', 'Admin B')");
$pdo->exec("INSERT INTO asset_categories (id, name) VALUES (1, 'Notebook'), (2, 'PC')");
$pdo->exec("INSERT INTO assets (id, asset_code, category_id, company, brand_model, holder_name, department, status) VALUES
  (1, 'NB-01-001', 1, 'ATS', 'Dell 3420', 'คนหนึ่ง', 'ฝ่ายบัญชี', 'active'),
  (2, 'NB-01-002', 1, 'INNO', 'HP 240', NULL, 'ฝ่ายผลิต', 'spare'),
  (3, 'PC-01-001', 2, 'ATS', 'Custom', NULL, NULL, 'repair'),
  (4, 'PC-01-009', 2, 'ATS', 'Old', NULL, NULL, 'retired')");
$a = ['id' => 1, 'display_name' => 'Admin A']; $b = ['id' => 2, 'display_name' => 'Admin B'];

// Empty year and input validation
$d = inspection_get(['year' => '2570']);
check($d['year'] === 2570 && $d['items'] === [] && $d['years'] === [], 'An unused year is empty and not listed');
check(inspection_get([])['year'] === (int)date('Y') + 543, 'Default year is the current Buddhist year');
check($d['form']['code'] === 'FM-IT-002' && $d['form']['rev'] !== '', 'Form code and revision come from the catalog');
fails(fn() => inspection_get(['year' => '2026']), 422, 'A Christian-era year is rejected');
fails(fn() => inspection_get(['year' => '25x9']), 422, 'A malformed year is rejected');

// Bulk add from the register: retired skipped, company filter
$d = inspection_bulk_add('2570', ['source' => 'assets', 'company' => 'ATS'], $a);
check($d['added'] === 2 && array_column($d['items'], 'asset_code') === ['NB-01-001', 'PC-01-001'], 'Register pull takes ATS non-retired equipment only');
$d = inspection_bulk_add('2570', ['source' => 'assets', 'company' => ''], $a);
check($d['added'] === 1 && row($d, 'NB-01-002') !== null && count($d['items']) === 3, 'Pulling again adds only the missing machine');
check(inspection_bulk_add('2570', ['source' => 'assets', 'company' => ''], $a)['added'] === 0, 'Pulling a third time adds nothing');
$nb = row($d, 'NB-01-001');
check($nb['asset']['category'] === 'Notebook' && $nb['asset']['model'] === 'Dell 3420' && $nb['asset']['department'] === 'ฝ่ายบัญชี', 'Items show register details');
fails(fn() => inspection_bulk_add('2570', ['source' => 'assets', 'company' => 'XYZ'], $a), 422, 'Unknown company is rejected');
fails(fn() => inspection_bulk_add('2570', ['source' => 'nope'], $a), 422, 'Unknown source is rejected');

// Single items
$d = inspection_add_item('2570', ['asset_code' => ' sw-01-001 ', 'item' => 'Switch', 'notes' => 'ห้อง Server'], $a);
$sw = row($d, 'SW-01-001');
check($sw !== null && $sw['asset_id'] === null && $sw['item'] === 'Switch' && $sw['sort_order'] === 4, 'A code outside the register is kept, uppercased and appended');
$d = inspection_add_item('2570', ['item' => 'UPS ห้อง Server'], $a);
check(count($d['items']) === 5 && end($d['items'])['asset_code'] === null, 'An item may have no machine number');
$e = fails(fn() => inspection_add_item('2570', ['asset_code' => 'nb-01-001'], $a), 422, 'A duplicate code in one year is rejected');
check(isset($e->errors['asset_code']), 'Duplicate error points at the code');
fails(fn() => inspection_add_item('2570', ['asset_code' => '', 'item' => ''], $a), 422, 'An empty item is rejected');
fails(fn() => inspection_add_item('2570', ['asset_code' => 'bad code!'], $a), 422, 'An invalid code is rejected');
check(count(inspection_add_item('2571', ['asset_code' => 'NB-01-001'], $a)['items']) === 1, 'The same code may appear in another year');
$d = inspection_update_item($sw['id'], ['asset_code' => 'PC-01-009', 'item' => '', 'notes' => '']);
$moved = row($d, 'PC-01-009');
check($moved['asset_id'] === 4 && $moved['item'] === null && $moved['notes'] === null, 'Editing to a register code links the asset');
fails(fn() => inspection_update_item($moved['id'], ['asset_code' => 'NB-01-002']), 422, 'Editing into a duplicate code is rejected');
fails(fn() => inspection_update_item(999999, ['asset_code' => 'X1']), 404, 'Editing a missing item is 404');

// Monthly results
$ids = array_column($d['items'], 'id', 'asset_code');
$d = inspection_save_month('2570', '4', ['results' => [$ids['NB-01-001'] => 'normal', $ids['NB-01-002'] => 'abnormal'], 'inspected_on' => '2027-04-22', 'inspector' => ' Admin A ', 'reviewer' => 'หัวหน้า', 'notes' => ''], $a);
check(row($d, 'NB-01-001')['results'] == [4 => 'normal'] && row($d, 'NB-01-002')['results'] == [4 => 'abnormal'], 'Marks are saved for the month');
check($d['months'][4]['inspected_on'] === '2027-04-22' && $d['months'][4]['inspector'] === 'Admin A' && $d['months'][4]['reviewer'] === 'หัวหน้า' && $d['months'][4]['updated_by_name'] === 'Admin A', 'Monthly footer is saved trimmed with the editor');
check($d['names'] === ['Admin A', 'หัวหน้า'], 'Names are offered for the next month');
$d = inspection_save_month('2570', '4', ['results' => [$ids['NB-01-002'] => 'fixed', $ids['NB-01-001'] => null]], $b);
check(row($d, 'NB-01-002')['results'] == [4 => 'fixed'] && row($d, 'NB-01-001')['results'] == [], 'Marks change and clear');
check($d['months'][4]['inspector'] === 'Admin A', 'Saving marks only keeps the footer');
check((string)$pdo->query("SELECT updated_by FROM infra_inspection_results WHERE item_id = {$ids['NB-01-002']} AND month = 4")->fetchColumn() === '2', 'Changed mark records who changed it');
inspection_save_month('2570', '4', ['results' => [$ids['NB-01-002'] => 'fixed']], $a);
check((string)$pdo->query("SELECT updated_by FROM infra_inspection_results WHERE item_id = {$ids['NB-01-002']} AND month = 4")->fetchColumn() === '2', 'Re-saving the same mark keeps the original editor');
$d = inspection_save_month('2570', '10', ['results' => [$ids['NB-01-001'] => 'normal']], $a);
check(row($d, 'NB-01-001')['results'] == [10 => 'normal'] && !isset($d['months'][10]), 'Months are independent');
$d = inspection_save_month('2570', '4', ['inspected_on' => null, 'inspector' => '', 'reviewer' => '', 'notes' => ''], $a);
check(!isset($d['months'][4]) && row($d, 'NB-01-002')['results'] == [4 => 'fixed'], 'Clearing the footer removes it but keeps marks');
fails(fn() => inspection_save_month('2570', '13', ['results' => []], $a), 422, 'Month 13 is rejected');
fails(fn() => inspection_save_month('2570', '4', ['results' => [$ids['NB-01-001'] => 'good']], $a), 422, 'Unknown mark is rejected');
fails(fn() => inspection_save_month('2570', '4', ['inspected_on' => '2027-02-30'], $a), 422, 'Impossible date is rejected');
fails(fn() => inspection_save_month('2570', '4', ['inspector' => str_repeat('ก', 101)], $a), 422, 'Long inspector name is rejected');
$other = row(inspection_get(['year' => '2571']), 'NB-01-001')['id'];
$before = $pdo->query('SELECT COUNT(*) FROM infra_inspection_results')->fetchColumn();
fails(fn() => inspection_save_month('2570', '5', ['results' => [$ids['NB-01-001'] => 'normal', $other => 'normal'], 'inspector' => 'X'], $a), 409, 'An item from another year is rejected');
check($pdo->query('SELECT COUNT(*) FROM infra_inspection_results')->fetchColumn() === $before && !isset(inspection_get(['year' => '2570'])['months'][5]), 'A rejected save writes nothing');

// Deleting
fails(fn() => inspection_delete_item($ids['NB-01-002']), 409, 'An item with results cannot be deleted');
$d = inspection_delete_item($ids['PC-01-009']);
check(row($d, 'PC-01-009') === null && count($d['items']) === 4, 'An item without results is deleted');

// Copy a year
$d = inspection_bulk_add('2572', ['source' => 'year', 'from_year' => '2570'], $a);
check($d['added'] === 4 && array_sum(array_map(fn($i) => count($i['results']), $d['items'])) === 0 && !$d['months'], 'Copying takes items only, no results or footer');
check(end($d['items'])['asset_code'] === null && end($d['items'])['item'] === 'UPS ห้อง Server', 'Items without a code copy into an empty year');
check(inspection_bulk_add('2572', ['source' => 'year', 'from_year' => '2570'], $a)['added'] === 0, 'Copying again adds nothing, even items without a code');
fails(fn() => inspection_bulk_add('2572', ['source' => 'year', 'from_year' => '2572'], $a), 422, 'Copying from the same year is rejected');
fails(fn() => inspection_bulk_add('2572', ['source' => 'year', 'from_year' => '2599'], $a), 422, 'Copying from an empty year is rejected');
$pdo->exec("UPDATE assets SET asset_code = 'NB-02-001' WHERE id = 1");
$d = inspection_get(['year' => '2570']);
check(row($d, 'NB-01-001')['asset']['code'] === 'NB-02-001', 'A renamed register code is shown next to the recorded one');
check(inspection_bulk_add('2573', ['source' => 'year', 'from_year' => '2570'], $a)['items'][0]['asset_code'] === 'NB-02-001', 'Copying uses the current register code');
$pdo->exec("UPDATE assets SET asset_code = 'NB-01-001' WHERE id = 1");

// Import the original layout
$file = workbook([
    'FM-IT-002(2570)' => [
        'B2' => 'รายการตรวจสอบ', 'C2' => 'หมายเลขเครื่อง', 'D2' => 'ผลตรวจสอบประจำปี : 2570',
        'C4' => 'NB-01-001', 'G4' => '/', 'M4' => '/',
        'C5' => 'NB-01-002', 'G5' => 'X',
        'B6' => 'Notebook', 'C6' => 'NB-09-999', 'G6' => '/', 'P6' => 'เครื่องยืม',
        'B7' => 'UPS ห้อง Server', 'G7' => '✓',
        'B8' => 'วันที่ตรวจ', 'G8' => 244461, 'M8' => '22/10/2570',
        'B9' => 'ผู้ตรวจสอบ ', 'G9' => 'ธนภัทร',
        'B10' => 'ผู้ทบทวน', 'G10' => 'วิศวะนนท์',
        'B11' => 'FM-IT-002, REV:00',
    ],
    'Sheet2' => ['A1' => 'ignored'],
]);
$preview = inspection_import_run($file, $a);
$y = $preview['years'][0];
check($preview['summary']['years'] === 1 && $y['year'] === 2570 && $y['file_items'] === 4, 'Import reads the year sheet and skips footer/other sheets');
check($y['add_items'] === 1 && $y['set_results'] === 4 && $y['overwrite_results'] === 1 && $y['set_months'] === 2, 'Preview counts new items, only changed marks (one overwrite) and months');
check(!isset($y['_add']) && $preview['can_apply'] && preg_match('/^[a-f0-9]{64}$/', $preview['preview_hash']) === 1, 'Preview hides internals and can apply');
check($y['months'][0]['month'] === 4 && $y['months'][0]['inspected_on'] === '2026-04-22' && $y['months'][1]['inspected_on'] === '2027-10-22', 'Serial and Buddhist dates convert');
inspection_save_month('2570', '7', ['inspector' => 'Mid-edit'], $b);
fails(fn() => inspection_import_run($file, $a, str_repeat('0', 64)), 409, 'A wrong hash is rejected');
$state = $pdo->query('SELECT COUNT(*) FROM infra_inspection_items')->fetchColumn();
$applied = inspection_import_run($file, $a, $preview['preview_hash']);
check(!empty($applied['applied']), 'Apply with the preview hash succeeds');
$d = inspection_get(['year' => '2570']);
check(row($d, 'NB-01-001')['results'] == [4 => 'normal', 10 => 'normal'] && row($d, 'NB-01-002')['results'] == [4 => 'abnormal'], 'Marks are filled and overwritten from the file');
$new = row($d, 'NB-09-999');
check($new !== null && $new['item'] === 'Notebook' && $new['notes'] === 'เครื่องยืม' && $new['results'] == [4 => 'normal'], 'A new machine is added with its marks and notes');
$ups = array_values(array_filter($d['items'], fn($i) => $i['asset_code'] === null));
check(count($ups) === 1 && $ups[0]['results'] == [4 => 'fixed'], 'An item without a code matches by name instead of duplicating');
check(row($d, 'PC-01-001') !== null && row($d, 'SW-01-001') === null, 'Items missing from the file are kept');
check($d['months'][4]['inspector'] === 'ธนภัทร' && $d['months'][4]['reviewer'] === 'วิศวะนนท์' && $d['months'][10]['inspector'] === null && $d['months'][7]['inspector'] === 'Mid-edit', 'Footer values merge per month');
$again = inspection_import_run($file, $a);
check(!$again['can_apply'] && $again['summary']['add_items'] === 0 && $again['summary']['set_results'] === 0, 'Importing the same file again has nothing to apply');
fails(fn() => inspection_import_run($file, $a, $preview['preview_hash']), 409, 'An old preview hash cannot apply twice');

$bad = workbook(['FM-IT-002(2570)' => ['C4' => 'NB-01-001', 'G4' => 'OK', 'B8' => 'วันที่ตรวจ', 'G8' => '31/02/2570']]);
$p = inspection_import_run($bad, $a);
check(!$p['can_apply'] && count($p['years'][0]['errors']) === 2, 'Unknown marks and bad dates block the import');
fails(fn() => inspection_import_run($bad, $a, $p['preview_hash']), 422, 'A blocked plan cannot apply');
fails(fn() => inspection_import_run(workbook(['Sheet1' => ['A1' => 'x']]), $a), 422, 'A workbook without a year sheet is rejected');

// Rollback: force a constraint failure mid-apply
$roll = workbook(['FM-IT-002(2574)' => ['C4' => 'AA-01-001', 'G4' => '/', 'C5' => 'AA-01-002', 'G5' => '/']]);
$p = inspection_import_run($roll, $a);
$pdo->exec("ALTER TABLE infra_inspection_results ADD CONSTRAINT chk_fixture_fail CHECK (item_id < " . ((int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM infra_inspection_items')->fetchColumn() + 2) . ")");
try { inspection_import_run($roll, $a, $p['preview_hash']); throw new RuntimeException('Expected forced failure'); } catch (PDOException) { $checks++; }
check(inspection_get(['year' => '2574'])['items'] === [], 'A failure mid-apply rolls the whole import back');
$pdo->exec('ALTER TABLE infra_inspection_results DROP CONSTRAINT chk_fixture_fail');

foreach ([$file, $bad, $roll] as $f) @unlink($f);
echo "inspections: $checks checks passed\n";
