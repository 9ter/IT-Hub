<?php
declare(strict_types=1);
// Excel import into IT documents. TEMP tables only; original FM-IT workbooks are read, never written.
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/assets.php';
require __DIR__.'/../src/asset_import.php';
require __DIR__.'/../src/document_import.php';
$pdo=db();
foreach(['it_documents','it_document_signatures','it_document_logs','admins','tickets','ticket_borrow_decisions','ticket_archives'] as $table){
    $pdo->exec("CREATE TEMPORARY TABLE fixture_$table LIKE $table");$pdo->exec("ALTER TABLE fixture_$table RENAME TO $table");
}
$checks=0;$tmp=[];
function verify(bool $v,string $m):void{global $checks;if(!$v)throw new RuntimeException($m);$checks++;}
function rejects(callable $fn,int $status,string $contains=''):void{try{$fn();}catch(HttpError $e){verify($e->status===$status&&($contains===''||str_contains($e->getMessage(),$contains)),"HTTP $status expected, got {$e->status}: {$e->getMessage()}");return;}throw new RuntimeException("Expected HTTP $status");}
function xlsx(array $sheets):string{
    global $tmp;$path=tempnam(sys_get_temp_dir(),'ithub-docimp-test-');$tmp[]=$path;$zip=new ZipArchive();$zip->open($path,ZipArchive::OVERWRITE);
    $types='';$list='';$rels='';$n=0;
    foreach($sheets as $name=>$cells){
        $n++;$rows=[];
        foreach($cells as $addr=>$v){preg_match('/^([A-Z]+)(\d+)$/',$addr,$m);$rows[(int)$m[2]][]=is_int($v)||is_float($v)?"<c r=\"$addr\"><v>$v</v></c>":"<c r=\"$addr\" t=\"inlineStr\"><is><t>".htmlspecialchars((string)$v,ENT_XML1)."</t></is></c>";}
        ksort($rows);$xml='';foreach($rows as $r=>$c)$xml.="<row r=\"$r\">".implode('',$c).'</row>';
        $zip->addFromString("xl/worksheets/sheet$n.xml","<?xml version=\"1.0\"?><worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\"><sheetData>$xml</sheetData></worksheet>");
        $types.="<Override PartName=\"/xl/worksheets/sheet$n.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        $list.='<sheet name="'.htmlspecialchars($name,ENT_XML1)."\" sheetId=\"$n\" r:id=\"rId$n\"/>";
        $rels.="<Relationship Id=\"rId$n\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$n.xml\"/>";
    }
    $zip->addFromString('[Content_Types].xml',"<?xml version=\"1.0\"?><Types xmlns=\"http://schemas.openxmlformats.org/package/2006/content-types\"><Default Extension=\"rels\" ContentType=\"application/vnd.openxmlformats-package.relationships+xml\"/><Default Extension=\"xml\" ContentType=\"application/xml\"/><Override PartName=\"/xl/workbook.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml\"/>$types</Types>");
    $zip->addFromString('xl/workbook.xml',"<?xml version=\"1.0\"?><workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets>$list</sheets></workbook>");
    $zip->addFromString('xl/_rels/workbook.xml.rels',"<?xml version=\"1.0\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">$rels</Relationships>");
    $zip->close();return $path;
}
function template_cells(string $code):array{global $tmp;$p=tempnam(sys_get_temp_dir(),'ithub-docimp-tpl-');$tmp[]=$p;document_import_template_build($code,$p);$book=document_import_read($p);return array_column($book['sheets'],'cells','name');}
function field_row(array $cells,string $key):string{foreach($cells as $a=>$v)if(preg_match('/^A(\d+)$/',$a,$m)&&$v===$key)return $m[1];throw new RuntimeException("no row $key");}
$actor=['id'=>1,'display_name'=>'Fixture IT'];
$pdo->exec("INSERT INTO admins(id,username,password_hash,display_name) VALUES(1,'fixture','fixture','Fixture IT')");
try{
    // Every form except the approval-gated FM-IT-008 and the asset-register FM-IT-001 gets a valid generated template.
    foreach(document_forms() as $code=>$def){
        if(!empty($def['record'])||$code==='FM-IT-001'){rejects(fn()=>document_import_definition($code),409);continue;}
        $sheets=template_cells($code);$main=$sheets[DOCUMENT_IMPORT_FIELDS_SHEET];
        verify($main['A1']===DOCUMENT_IMPORT_MARKER&&$main['B1']===$code,"$code template marker");
        foreach($def['fields'] as $f)field_row($main,$f['key']);
        if($def['columns']){$keys=array_values(document_import_cell_columns($sheets[DOCUMENT_IMPORT_ROWS_SHEET],2));verify($keys===array_column($def['columns'],'key'),"$code template columns");}
        // A blank template imports as an empty draft without errors.
        $plan=document_import_plan($code,['sheets'=>array_map(fn($n,$c)=>['name'=>$n,'cells'=>$c],array_keys($sheets),$sheets),'date1904'=>false],str_repeat('a',64),'x.xlsx');
        verify($plan['summary']['errors']===0&&$plan['summary']['create']===1,"$code blank template has no errors");
    }
    $opts=template_cells('FM-IT-007')[DOCUMENT_IMPORT_OPTIONS_SHEET];
    verify(!in_array(PURCHASE_ITEMS['barcode_scanner'],$opts,true)&&in_array(PURCHASE_ITEMS['ink'],$opts,true),'Retired options hidden from dropdowns');

    // FM-IT-010 single-record form via template: labels, keys, Buddhist dates, multiselect.
    $main=template_cells('FM-IT-010')[DOCUMENT_IMPORT_FIELDS_SHEET];
    $set=fn(array $values)=>array_merge($main,...array_map(fn($k,$v)=>['C'.field_row($main,$k)=>$v],array_keys($values),$values));
    $file=xlsx([DOCUMENT_IMPORT_FIELDS_SHEET=>$set(['_document_date'=>'04/11/2569','requester'=>'ผู้ทดสอบ','department'=>'IT','actions'=>'ขอรีโมท / VPN, access','description'=>"บรรทัด1\nบรรทัด2",'device_kind'=>'notebook'])]);
    $plan=document_import_run('FM-IT-010',$file,'service.xlsx',$actor);
    verify($plan['source']==='template'&&$plan['can_apply'],'Template preview applies');
    verify(!isset($plan['documents'][0]['_save'])&&!isset($plan['documents'][0]['_key']),'Preview hides internals');
    $result=document_import_run('FM-IT-010',$file,'service.xlsx',$actor,$plan['preview_hash']);
    $d=document_detail($result['created'][0]['id']);
    verify($d['status']==='draft'&&$d['document_date']==='2026-11-04'&&!$d['signatures'],'Draft, date converted from พ.ศ., no signatures');
    verify($d['data']['fields']['actions']===['remote_vpn','access'],'Multiselect from labels and keys');
    verify($d['data']['fields']['description']==="บรรทัด1\nบรรทัด2"&&str_contains($d['source_note'],'service.xlsx'),'Multiline text and source note kept');
    verify(str_contains($d['source_note'],'ผลการดำเนินการ')===false&&count($d['logs'])===2,'Create + import logs');
    $again=document_import_run('FM-IT-010',$file,'service.xlsx',$actor);
    verify($again['summary']['duplicate']===1&&!$again['can_apply']&&$again['documents'][0]['duplicate']['id']===$d['id'],'Same file again is flagged duplicate');
    rejects(fn()=>document_import_run('FM-IT-010',$file,'service.xlsx',$actor,$again['preview_hash']),422);

    // Wrong form, invalid values, stale hash, unknown key.
    rejects(fn()=>document_import_run('FM-IT-009',$file,'s.xlsx',$actor),422,'FM-IT-010');
    $bad=document_import_run('FM-IT-010',xlsx([DOCUMENT_IMPORT_FIELDS_SHEET=>$set(['device_kind'=>'โทรศัพท์','_document_date'=>'31/02/2569'])]),'b.xlsx',$actor);
    verify(!$bad['can_apply']&&count($bad['documents'][0]['errors'])===2,'Invalid option and date reported');
    $main2=$main;$main2['A50']='not_a_field';$main2['C50']='x';
    verify(!document_import_run('FM-IT-010',xlsx([DOCUMENT_IMPORT_FIELDS_SHEET=>$main2]),'u.xlsx',$actor)['can_apply'],'Unknown key blocks');
    $f2=xlsx([DOCUMENT_IMPORT_FIELDS_SHEET=>$set(['requester'=>'อีกคน'])]);$p2=document_import_run('FM-IT-010',$f2,'2.xlsx',$actor);
    rejects(fn()=>document_import_run('FM-IT-010',$f2,'2.xlsx',$actor,str_repeat('0',64)),409);
    rejects(fn()=>document_import_run('FM-IT-010',xlsx(['Sheet1'=>['A1'=>'x']]),'n.xlsx',$actor),422,'ดาวน์โหลด');
    verify(document_import_run('FM-IT-010',$f2,'2.xlsx',$actor)['preview_hash']===$p2['preview_hash'],'Preview hash is stable');

    // Table form via template: select by label, numbers from Excel, quantity rule, row errors.
    $sheets=template_cells('FM-IT-007');$m7=$sheets[DOCUMENT_IMPORT_FIELDS_SHEET];$rows=$sheets[DOCUMENT_IMPORT_ROWS_SHEET];
    $cols=array_flip(document_import_cell_columns($rows,2));
    $rows[$cols['item'].'3']='หมึก';$rows[$cols['quantity'].'3']=2;$rows[$cols['spec'].'3']='HP 682';
    $rows[$cols['item'].'4']='notebook';$rows[$cols['quantity'].'4']='1.0';
    $m7['C'.field_row($m7,'urgency')]='ด่วน';$m7['C'.field_row($m7,'amount')]='1,234.5';$m7['C'.field_row($m7,'need_by')]=46330;
    $plan=document_import_run('FM-IT-007',xlsx([DOCUMENT_IMPORT_FIELDS_SHEET=>$m7,DOCUMENT_IMPORT_ROWS_SHEET=>$rows]),'p.xlsx',$actor);
    verify($plan['can_apply']&&$plan['documents'][0]['row_count']===2,'Two purchase rows parsed');
    verify(str_contains(implode(' ',$plan['documents'][0]['warnings']),'ยังไม่ได้กรอก'),'Missing required fields are warnings, not errors');
    $res=document_import_run('FM-IT-007',xlsx([DOCUMENT_IMPORT_FIELDS_SHEET=>$m7,DOCUMENT_IMPORT_ROWS_SHEET=>$rows]),'p.xlsx',$actor,$plan['preview_hash']);
    $d=document_detail($res['created'][0]['id']);
    verify($d['data']['rows'][0]['item']==='ink'&&$d['data']['rows'][0]['quantity']==='2'&&$d['data']['rows'][1]['quantity']==='1','Rows saved with keys and integers');
    verify($d['data']['fields']['urgency']==='urgent'&&$d['data']['fields']['amount']==='1234.5'&&$d['data']['fields']['need_by']==='2026-11-04','Select label, decimal, Excel serial date');
    verify($d['company']==='บริษัท อัตถสาร จำกัด','Default company');
    $rows[$cols['quantity'].'5']='0';$rows[$cols['item'].'5']='หมึก';
    $bad=document_import_run('FM-IT-007',xlsx([DOCUMENT_IMPORT_FIELDS_SHEET=>$m7,DOCUMENT_IMPORT_ROWS_SHEET=>$rows]),'p0.xlsx',$actor);
    verify(!$bad['can_apply']&&str_contains($bad['documents'][0]['errors'][0],'แถว 5'),'Row error names the Excel row');
    verify(!document_import_run('FM-IT-007',xlsx([DOCUMENT_IMPORT_FIELDS_SHEET=>$m7]),'nr.xlsx',$actor)['can_apply'],'Missing rows sheet blocks');
    $m11=template_cells('FM-IT-011')[DOCUMENT_IMPORT_FIELDS_SHEET];
    $m11['C'.field_row($m11,'time_in')]='04/11/2569 10:30';$m11['C'.field_row($m11,'time_out')]='04/11/2569 09:00';
    verify(!document_import_run('FM-IT-011',xlsx([DOCUMENT_IMPORT_FIELDS_SHEET=>$m11]),'v.xlsx',$actor)['can_apply'],'Cross-field time order checked');
    $m11['C'.field_row($m11,'time_out')]='2026-11-04 11:00';
    $p=document_import_run('FM-IT-011',$f11=xlsx([DOCUMENT_IMPORT_FIELDS_SHEET=>$m11]),'v.xlsx',$actor);
    $d=document_detail(document_import_run('FM-IT-011',$f11,'v.xlsx',$actor,$p['preview_hash'])['created'][0]['id']);
    verify($d['data']['fields']['time_in']==='2026-11-04 10:30:00','Datetime with Buddhist year');

    // Atomic: a failure on the second document leaves no first document.
    $before=(int)$pdo->query('SELECT COUNT(*) FROM it_documents')->fetchColumn();
    $orig=__DIR__.'/../../FM-IT/';
    $f002=glob($orig.'FM-IT-002 *.xlsx')[0];
    $plan=document_import_run('FM-IT-002',$f002,basename($f002),$actor);
    verify($plan['source']==='original'&&$plan['summary']['create']===3&&$plan['can_apply'],'Original FM-IT-002 gives one draft per sheet');
    // CHECK on the connection-local TEMP table only; makes the third sheet fail after two inserts.
    $pdo->exec("ALTER TABLE it_documents ADD CONSTRAINT fixture_fail CHECK (title NOT LIKE '%(2569)%')");
    $failed=false;try{document_import_run('FM-IT-002',$f002,basename($f002),$actor,$plan['preview_hash']);}catch(PDOException){$failed=true;}finally{$pdo->exec('ALTER TABLE it_documents DROP CONSTRAINT fixture_fail');}
    verify($failed&&(int)$pdo->query('SELECT COUNT(*) FROM it_documents')->fetchColumn()===$before&&!$pdo->inTransaction(),'Rollback keeps none of the batch');
    $res=document_import_run('FM-IT-002',$f002,basename($f002),$actor,$plan['preview_hash']);
    verify(count($res['created'])===3,'Original FM-IT-002 import creates three drafts');
    $d=document_detail($res['created'][2]['id']);
    verify($d['data']['fields']['year']==='2569'&&$d['data']['rows']&&str_contains($d['source_note'],'Sheet:'),'Year from sheet name, rows and sheet note');

    // Original report layouts match the existing CLI reader (row counts per sheet).
    $python=json_decode(shell_exec('python3 -I '.escapeshellarg(__DIR__.'/document_import_reference.py').' '.escapeshellarg($orig)),true);
    foreach(['FM-IT-005','FM-IT-006'] as $code){
        $path=glob($orig.$code.' *.xlsx')[0];$plan=document_import_run($code,$path,basename($path),$actor);
        $ours=array_map(fn($d)=>[$d['sheet'],$d['row_count']],$plan['documents']);
        verify($ours===$python[$code],"$code original layout matches CLI reader");
        verify($plan['summary']['errors']===0,"$code original imports without errors");
    }
    // Times stored as Excel fractions, including scientific notation, read as HH:MM:SS (CLI reader parity).
    verify(document_import_time_text('4.1678240740740745E-2')==='01:00:01'&&document_import_time_text('0.0416782407')==='01:00:01'&&document_import_time_text('01:00:01')==='01:00:01'&&document_import_time_text('1.5')==='1.5','Excel time fractions incl. E-notation');
    $legacyKey='xlsx:'.hash('sha256',basename($path).'#'.$python['FM-IT-006'][0][0]);
    $pdo->prepare("INSERT INTO it_documents(document_no,form_code,title,document_date,company,data,created_by,source_key) VALUES('IT-LEGACY','FM-IT-006','x','2026-10-28','x','{}',1,?)")->execute([$legacyKey]);
    $plan=document_import_run('FM-IT-006',$path,basename($path),$actor);
    verify(str_contains(implode(' ',$plan['documents'][0]['warnings']),'IT-LEGACY')&&$plan['documents'][0]['action']==='create','Earlier CLI import is warned, not blocked');
    rejects(fn()=>document_import_run('FM-IT-003',glob($orig.'FM-IT-003 *.xlsx')[0],'x.xlsx',$actor),422,'ดาวน์โหลด');
    rejects(fn()=>document_import_run('FM-IT-008',$f002,'x.xlsx',$actor),409);
    // FM-IT-001 is the asset register: original workbook and template download both point to /admin/assets.
    rejects(fn()=>document_import_run('FM-IT-001',glob($orig.'FM-IT-001 *.xlsx')[0],'x.xlsx',$actor),409,'ทะเบียนอุปกรณ์');
    rejects(fn()=>document_import_definition('FM-IT-001'),409,'ทะเบียนอุปกรณ์');
    $zip=new ZipArchive();$mac=xlsx(['a'=>['A1'=>'x']]);$zip->open($mac);$zip->addFromString('xl/vbaProject.bin','x');$zip->close();
    rejects(fn()=>document_import_read($mac),422,'Macro');
    $zip->open($mac);$zip->addFromString('xl/worksheets/sheet1.xml','<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "x">]><worksheet/>');$zip->close();
    rejects(fn()=>document_import_read($mac),422);
    echo "PASS document_import: $checks checks\n";
} finally { foreach($tmp as $p) @unlink($p); }
