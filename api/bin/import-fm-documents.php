<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require __DIR__.'/../src/bootstrap.php';
if (empty($argv[1])) {fwrite(STDERR,"Usage: php api/bin/import-fm-documents.php /private/records.json [--apply]\n");exit(1);}
$records=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$apply=in_array('--apply',$argv,true);$created=0;$skipped=0;$rowCount=0;
$actor=db()->query('SELECT id,display_name FROM admins WHERE is_active=1 ORDER BY id LIMIT 1')->fetch() ?: fail('ต้องมีผู้ดูแลระบบก่อนนำเข้า',422);
foreach($records as $r){
    if (!in_array($r['form_code'],['FM-IT-001','FM-IT-002','FM-IT-005','FM-IT-006'],true)) throw new RuntimeException('Not a historical report');
    if (!preg_match('/^xlsx:[a-f0-9]{64}$/D',$r['source_key'])) throw new RuntimeException('Invalid import key');
    $s=db()->prepare('SELECT id FROM it_documents WHERE source_key=?');$s->execute([$r['source_key']]);
    if ($s->fetchColumn()) {$skipped++;continue;}
    document_data(document_forms()[$r['form_code']],$r['data']);
    if ($apply){
        // Each document is a new draft. No mutation of assets, old tickets, or their signatures.
        $d=document_save(null,$r,$actor,$r['source_key']);
        document_log((int)$d['id'],'นำเข้าจาก Excel เป็นฉบับร่าง ไม่มีการรับรองหรือลงนามย้อนหลัง');
    }
    $created++;$rowCount+=count($r['data']['rows']);
}
echo ($apply?'IMPORTED':'DRY RUN').": $created drafts, $rowCount rows, $skipped existing source sheets skipped; no mail sent.\n";
