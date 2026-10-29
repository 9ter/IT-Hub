<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
umask(0007);
if(!is_dir(archive_dir())&&!mkdir(archive_dir(),0770,true))exit(1);
$lock=fopen(archive_dir().'/worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
try{
    if(in_array('--backfill',$argv,true)){
        foreach(db()->query("SELECT id FROM tickets WHERE status='done' ORDER BY id")->fetchAll() as $row){db()->beginTransaction();try{archive_enqueue((int)$row['id']);db()->commit();}catch(Throwable $e){db()->rollBack();throw $e;}}
    }
    db()->exec("UPDATE ticket_archives SET status='failed',last_error='ตัวส่งเอกสารถูกขัดจังหวะ ระบบจะลองใหม่',next_attempt_at=NOW() WHERE status='processing' AND started_at<NOW()-INTERVAL 10 MINUTE");
    $jobs=db()->query("SELECT * FROM ticket_archives WHERE status IN ('pending','failed') AND attempts<8 AND next_attempt_at<=NOW() ORDER BY id LIMIT 5")->fetchAll();
    foreach($jobs as $job)archive_run_job($job);
    echo 'processed='.count($jobs).PHP_EOL;
}catch(Throwable){fwrite(STDERR,"Archive worker failed; check database/runtime configuration\n");$failed=true;}
finally{flock($lock,LOCK_UN);fclose($lock);}
if(!empty($failed))exit(1);
