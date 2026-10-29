<?php
declare(strict_types=1);

// All writes use connection-local temporary tables. No real inventory or tickets are changed.
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/assets.php';
require __DIR__ . '/../src/asset_import.php';
$pdo = db();
foreach (['assets', 'asset_categories', 'tickets', 'maintenance_records'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE `fixture_$table` LIKE `$table`");
    $pdo->exec("ALTER TABLE `fixture_$table` RENAME TO `$table`");
}
$pdo->exec("INSERT INTO asset_categories (id,name) VALUES (1,'Notebook'),(2,'PC'),(3,'All-in-one')");
$checks = 0;
function check(bool $value, string $message): void {
    global $checks;
    if (!$value) throw new RuntimeException($message);
    $checks++;
}
function rejects(callable $callback, int $status, string $message): void {
    try { $callback(); } catch (HttpError $e) { check($e->status === $status, $message); return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}
function source(string $code, ?string $serial, array $extra = []): array {
    return array_replace(['sheet'=>'FM-IT-001 (ATS)', 'row'=>5, 'company'=>'ATS', 'asset_code'=>$code, 'brand_model'=>'Fixture laptop',
        'serial_no'=>$serial, 'holder_name'=>'Fixture Holder', 'holder_nickname'=>'Fixture', 'department'=>'IT',
        'registered_at'=>'2025-01-11', 'type'=>'Notebook', 'source_notes'=>null], $extra);
}
function fixture_workbook(string $path, array $rows, array $options = []): void {
    $z = new ZipArchive(); $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $name = htmlspecialchars($options['name'] ?? 'FM-IT-001 (ATS)', ENT_XML1, 'UTF-8');
    $z->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . (($options['no_properties'] ?? false) ? '' : '<workbookPr date1904="' . ($options['date1904'] ?? '0') . '"/>') . '<sheets><sheet name="'.$name.'" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $target = $options['target'] ?? 'worksheets/sheet1.xml';
    $z->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="'.$target.'"/></Relationships>');
    $headers = ['ลำดับ','หมายเลข','ผู้ครอบครอง','ยี่ห้อ / รุ่น','Serial No','หน่วยงานที่ใช้','วันที่ขึ้นทะเบียน','หมายเหตุ','Type','หมายเหตุ'];
    if (isset($options['bad_header'])) $headers[4] = 'Invalid header';
    $all = [4=>$headers] + $rows; $xml = '';
    foreach ($all as $r=>$values) {
        $xml .= '<row r="'.$r.'"'.($r === 250 ? ' hidden="1"' : '').'>';
        foreach ($values as $i=>$value) {
            $addr = chr(65+$i).$r;
            if (($options['shared'] ?? false) && $i === 3 && $r !== 4) $xml .= '<c r="'.$addr.'" t="s"><v>0</v></c>';
            else $xml .= '<c r="'.$addr.'" t="inlineStr">'.(($options['formula'] ?? false) && $i===3 && $r!==4 ? '<f>1+1</f>' : '').'<is><t>'.htmlspecialchars((string)$value,ENT_XML1,'UTF-8').'</t></is></c>';
        }
        $xml .= '</row>';
    }
    $prefix = $options['doctype'] ?? '';
    $z->addFromString('xl/worksheets/sheet1.xml', $prefix.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>');
    if ($options['shared'] ?? false) $z->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><r><t>Fixture </t></r><r><t>laptop</t></r></si></sst>');
    if ($options['macro'] ?? false) $z->addFromString('xl/vbaProject.bin', 'fixture');
    if ($options['oversize'] ?? false) $z->addFromString('oversize.xml', str_repeat('x', 8 * 1024 * 1024 + 1));
    $z->close();
}

check(asset_import_date('243994') === '2025-01-11', 'Buddhist year Excel date');
check(asset_import_date('18/7/2568') === '2025-07-18', 'Buddhist text date');
check(asset_import_date('2025-07-18') === '2025-07-18', 'ISO date');
check(asset_import_date('44206', true) === '2025-01-11', '1904 workbook date system');
check(asset_import_date('-') === null, 'Blank registration date');
rejects(fn()=>asset_import_date('31/02/2568'), 422, 'Invalid calendar date');
rejects(fn()=>asset_import_date('bad date'), 422, 'Invalid text date');
rejects(fn()=>asset_import_date('999999999'), 422, 'Unbounded Excel date');
rejects(fn()=>asset_import_date('29/02/2568'), 422, 'Leap day is validated after Buddhist-year conversion');

$path = tempnam(sys_get_temp_dir(), 'ithub-xlsx-test-');
try {
    $data = [1,'TEST-001','Fixture Holder','Fixture laptop','SERIAL-1','IT','243994','Fixture','Notebook','Fixture note'];
    fixture_workbook($path, [5=>$data, 250=>[2,'TEST-002','Fixture Holder','Fixture laptop','SERIAL-2','IT','18/7/2568','Fixture','AIO','']], ['shared'=>true]);
    $read = asset_import_read($path);
    check(count($read) === 2 && $read[1]['row'] === 250, 'Reads hidden rows beyond old filter/range');
    check($read[0]['brand_model'] === 'Fixture laptop', 'Rich shared strings');
    check($read[0]['registered_at'] === '2025-01-11' && $read[1]['registered_at'] === '2025-07-18', 'Both workbook date representations');
    fixture_workbook($path, [5=>$data], ['name'=>'FM-IT-001 (INNO)']);
    check(asset_import_read($path)[0]['company'] === 'INNO', 'INNO identity from sheet name');
    fixture_workbook($path, [5=>$data], ['no_properties'=>true]);
    check(asset_import_read($path)[0]['registered_at'] === '2025-01-11', 'Workbook with no workbookPr uses the normal date system without warnings');
    foreach ([['bad_header'=>true], ['formula'=>true], ['macro'=>true], ['target'=>'../../etc/passwd'], ['doctype'=>'<!DOCTYPE worksheet [<!ENTITY a SYSTEM "file:///etc/passwd">]>'], ['oversize'=>true]] as $options) {
        fixture_workbook($path, [5=>$data], $options);
        rejects(fn()=>asset_import_read($path), 422, 'Rejects unsafe/invalid workbook: ' . implode(',', array_keys($options)));
    }
    fixture_workbook($path, [5=>array_replace($data,[6=>'31/02/2568'])]);
    rejects(fn()=>asset_import_read($path), 422, 'Invalid date includes row context');
    fixture_workbook($path, [5=>array_replace($data,[0=>''])]);
    rejects(fn()=>asset_import_read($path), 422, 'Does not silently skip equipment with a missing sequence');
    fixture_workbook($path, []);
    rejects(fn()=>asset_import_read($path), 422, 'Empty workbook');
    fixture_workbook($path, array_fill(5,1001,$data));
    rejects(fn()=>asset_import_read($path), 422, 'Enforces 1,000-row limit');
    file_put_contents($path,'not a ZIP');
    rejects(fn()=>asset_import_read($path), 422, 'Fake XLSX');
    file_put_contents($path,str_repeat('x',ASSET_IMPORT_MAX_BYTES+1));
    rejects(fn()=>asset_import_read($path), 422, 'Upload size limit');
} finally { unlink($path); }

$base = ['category_id'=>1, 'company'=>'ATS', 'brand_model'=>'Fixture laptop', 'holder_name'=>'Fixture Holder',
    'holder_nickname'=>'Fixture', 'department'=>'IT', 'registered_at'=>'2025-01-11', 'status'=>'active'];
$a = asset_save(null, $base + ['asset_code'=>'A','serial_no'=>'SERIAL-A','notes'=>'Existing note']);
$b = asset_save(null, array_replace($base,['asset_code'=>'B','serial_no'=>'SERIAL-B','status'=>'repair','location'=>'Floor 1','warranty_until'=>'2028-01-01','specs'=>'Existing specs']));
$c = asset_save(null, array_replace($base,['asset_code'=>'C','serial_no'=>null,'brand_model'=>'Fixture assembled PC','category_id'=>2]));
$outside = asset_save(null, $base + ['asset_code'=>'OUTSIDE','serial_no'=>'SERIAL-OUTSIDE']);
$pdo->exec("INSERT INTO tickets (ticket_no,type,title,asset_id,requester_name) VALUES ('FIXTURE-1','repair','Fixture repair',1,'Fixture Holder')");
$pdo->exec("INSERT INTO maintenance_records (asset_id,kind,performed_on,detail) VALUES (1,'PM','2025-01-11','Fixture maintenance')");
$ticketsBefore = $pdo->query('SELECT * FROM tickets')->fetchAll();
$maintenanceBefore = $pdo->query('SELECT * FROM maintenance_records')->fetchAll();
$rows = [source('B','SERIAL-A'), source('A','SERIAL-B',['holder_name'=>null,'registered_at'=>null,'holder_nickname'=>'Updated']),
    source('NEW','SERIAL-NEW',['company'=>'INNO','type'=>'AIO/TESTHOST','source_notes'=>'New note']),
    source('E',null,['brand_model'=>'Fixture assembled PC','type'=>'Computer'])];
$snapshot = $pdo->query('SELECT * FROM assets ORDER BY id')->fetchAll();
$p = asset_import_run($rows);
check($p['can_apply'] && $p['summary'] === ['total'=>4,'created'=>1,'updated'=>3,'unchanged'=>0,'renamed'=>3], 'Preview identifies serial-based swap, new device, and no-serial rename');
check(!isset($p['rows'][0]['data']), 'Preview omits internal write payload');
check($snapshot === $pdo->query('SELECT * FROM assets ORDER BY id')->fetchAll(), 'Preview makes no writes');
rejects(fn()=>asset_import_run($rows,'invalid'), 422, 'Apply requires a valid preview hash');
rejects(fn()=>asset_import_run($rows,str_repeat('0',64)), 409, 'Wrong preview is rejected');
$changedFile = $rows; $changedFile[0]['holder_nickname']='Different file';
rejects(fn()=>asset_import_run($changedFile,$p['preview_hash']), 409, 'Changed file requires new preview');
$pdo->exec("UPDATE assets SET location='Changed concurrently' WHERE asset_code='OUTSIDE'");
rejects(fn()=>asset_import_run($rows,$p['preview_hash']), 409, 'Concurrent asset edit invalidates preview');
$outsideAfter = $pdo->query("SELECT * FROM assets WHERE asset_code='OUTSIDE'")->fetch();
$p = asset_import_run($rows);
$result = asset_import_run($rows,$p['preview_hash']);
check($result['summary']['created'] === 1 && $result['renamed_ids'] === [1,2,3], 'Apply summary and renamed QR IDs');
$aa=asset_detail(1);$bb=asset_detail(2);$cc=asset_detail(3);
check($aa['asset_code']==='B' && $aa['serial_no']==='SERIAL-A' && $bb['asset_code']==='A' && $bb['serial_no']==='SERIAL-B', 'Swapped codes retain physical identities');
check($cc['asset_code']==='E' && $cc['serial_no']===null, 'No-serial identity retains its ID');
check($bb['status']==='repair' && $bb['location']==='Floor 1' && $bb['warranty_until']==='2028-01-01' && $bb['specs']==='Existing specs', 'Keeps operational fields absent from Excel');
check($bb['holder_name']==='Fixture Holder' && $bb['registered_at']==='2025-01-11', 'Blank optional cells preserve existing values');
check(str_contains($aa['notes'],'Existing note') && str_contains($aa['notes'],'รหัสก่อนปรับทะเบียน'), 'Retains notes and records code change');
$new=$pdo->query("SELECT * FROM assets WHERE asset_code='NEW'")->fetch();
check($new['company']==='INNO' && $new['category_id']===3 && $new['hostname']==='TESTHOST', 'Maps company/type/hostname');
check($pdo->query("SELECT * FROM assets WHERE asset_code='OUTSIDE'")->fetch()===$outsideAfter, 'Leaves outside inventory unchanged');
check($pdo->query('SELECT * FROM tickets')->fetchAll()===$ticketsBefore && $pdo->query('SELECT * FROM maintenance_records')->fetchAll()===$maintenanceBefore, 'Preserves ticket/maintenance records and references');
$again=asset_import_run($rows);
check($again['summary']['unchanged']===4 && $again['summary']['created']===0 && $again['summary']['updated']===0, 'Repeated import is idempotent');
check($pdo->query("SELECT COUNT(*) FROM assets WHERE asset_code LIKE 'TMP-%'")->fetchColumn()===0, 'No staging codes remain');

$duplicate = [$rows[0],$rows[0]];
check(!asset_import_run($duplicate)['can_apply'], 'Duplicate workbook code blocks entire import');
$duplicate = [$rows[0],array_replace($rows[0],['asset_code'=>'OTHER'])];
check(!asset_import_run($duplicate)['can_apply'], 'Duplicate workbook serial blocks entire import');
check(!asset_import_run([source('A','UNRELATED-SERIAL')])['can_apply'], 'Cannot replace occupied code with another physical device');
check(!asset_import_run([source('A',null)])['can_apply'], 'Cannot erase/change serial identity with a blank cell');
check(!asset_import_run([source('BAD CODE','S-NEW')])['can_apply'], 'Invalid code reported in preview');
check(!asset_import_run([source('VALID','S-NEW',['holder_name'=>str_repeat('x',151)])])['can_apply'], 'Field lengths validated before applying');
check(!asset_import_run([source('VALID','S-NEW',['type'=>'unknown'])])['can_apply'], 'Unknown Type is actionable');
check(!asset_import_run([source('VALID','S-NEW',['type'=>''])])['can_apply'], 'New equipment needs Type');
$pdo->exec("INSERT INTO assets (asset_code,serial_no,brand_model,category_id) VALUES ('DUPLICATE','SERIAL-A','Fixture laptop',1)");
check(!asset_import_run([$rows[0]])['can_apply'], 'Ambiguous existing serial is never guessed');
$pdo->exec("DELETE FROM assets WHERE asset_code='DUPLICATE'");
$pdo->exec("INSERT INTO assets (asset_code,brand_model,holder_name,department,category_id) VALUES ('BLANK-DUPLICATE','Fixture assembled PC','Fixture Holder','IT',2)");
check(!asset_import_run([source('MOVED',null,['brand_model'=>'Fixture assembled PC','type'=>'Computer'])])['can_apply'], 'Ambiguous no-serial match blocks import');
$pdo->exec("DELETE FROM assets WHERE asset_code='BLANK-DUPLICATE'");
$blocked = asset_import_run([source('A','UNRELATED-SERIAL')]);
rejects(fn()=>asset_import_run([source('A','UNRELATED-SERIAL')],$blocked['preview_hash']),422,'Conflicted preview cannot be applied');

// Force a late database failure after staging and saving an earlier row to prove full rollback.
$pdo->exec("ALTER TABLE assets ADD CONSTRAINT fixture_block CHECK (brand_model <> 'Blocked')");
$rollbackRows = [source('RENAMED','SERIAL-A'),source('BLOCKED','SERIAL-BLOCKED',['brand_model'=>'Blocked'])];
$p = asset_import_run($rollbackRows);
$snapshot = $pdo->query('SELECT * FROM assets ORDER BY id')->fetchAll();
try { asset_import_run($rollbackRows,$p['preview_hash']); throw new RuntimeException('Expected database failure'); }
catch (PDOException $e) { check(true, 'Database failure propagated'); }
check(!$pdo->inTransaction() && $pdo->query('SELECT * FROM assets ORDER BY id')->fetchAll()===$snapshot, 'Late failure rolls back both staging codes and saved rows');
$pdo->exec('ALTER TABLE assets DROP CONSTRAINT fixture_block');

// Normal asset editing still rejects duplicates and invalid values after validation extraction.
rejects(fn()=>asset_save(null,$base+['asset_code'=>'a']),422,'Ordinary asset save retains duplicate-code protection');
rejects(fn()=>asset_save(null,$base+['asset_code'=>'INVALID SPACE']),422,'Ordinary save retains format validation');
echo "PASS: $checks asset import checks (temporary tables; no real inventory writes)\n";
