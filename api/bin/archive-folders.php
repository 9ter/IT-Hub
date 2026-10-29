<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
// Shares the worker lock so cron cannot upload concurrently with reorganization.
$lock=fopen(archive_dir().'/worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Archive worker is busy; try again\n");exit(1);}
$moved=0;$failed=0;$skipped=0;
try{
    $jobs=db()->query("SELECT * FROM ticket_archives WHERE status='sent' AND relative_path IS NULL ORDER BY id")->fetchAll();
    foreach($jobs as $job){
        try{
            $mapping=nas_read();if(!$mapping||$mapping['revision']!==$job['target_revision']){$skipped++;continue;}
            $payload=json_decode($job['snapshot'],true,512,JSON_THROW_ON_ERROR);$subfolder=ticket_types()[$payload['ticket']['type']]['form'];
            if(!str_starts_with($job['filename'],$subfolder.'_')||!$job['pdf_sha256']||!$job['pdf_size'])throw new RuntimeException('Invalid archive identity');
            archive_process([(string)cfg('nas.python','/usr/bin/python3'),'-I',__DIR__.'/nas-upload.py',(string)cfg('nas.python_packages','/var/lib/it-hub/python')],
                [...$mapping,'operation'=>'relocate','subfolder'=>$subfolder,'filename'=>$job['filename'],'size'=>(int)$job['pdf_size'],'sha256'=>$job['pdf_sha256']],60);
            db()->prepare("UPDATE ticket_archives SET relative_path=? WHERE id=? AND status='sent' AND relative_path IS NULL")->execute([$subfolder.'/'.$job['filename'],$job['id']]);$moved++;
        }catch(Throwable){$failed++;fwrite(STDERR,'Folder migration failed for archive '.(int)$job['id'].PHP_EOL);}
    }
    echo json_encode(['moved'=>$moved,'failed'=>$failed,'skipped_other_mapping'=>$skipped]).PHP_EOL;
}finally{flock($lock,LOCK_UN);fclose($lock);}
if($failed)exit(1);
