<?php
declare(strict_types=1);

function archive_dir(): string {return rtrim((string)cfg('archive.state_dir',dirname(nas_state_dir()).'/archive'),'/');}
function archive_status(int $id): ?array
{
    $s=db()->prepare('SELECT id,status,filename,relative_path,attempts,sent_at,last_error,created_at FROM ticket_archives WHERE ticket_id=? ORDER BY id DESC LIMIT 1');$s->execute([$id]);return $s->fetch() ?: null;
}

function archive_snapshot(int $id): array
{
    $t=ticket_admin($id);
    unset($t['nas_archive'],$t['client_ip'],$t['updated_at']);$t['logs']=[];
    $types=[];foreach(ticket_types() as $key=>$type)$types[$key]=['label'=>$type['label'],'form'=>$type['form']];
    $payload=['ticket'=>$t,'meta'=>['app_url'=>'','ticket_types'=>$types,'ticket_statuses'=>TICKET_STATUSES,'ticket_priorities'=>TICKET_PRIORITIES,
        'asset_statuses'=>ASSET_STATUSES,'service_actions'=>SERVICE_ACTIONS,'device_kinds'=>DEVICE_KINDS,'email_companies'=>EMAIL_COMPANIES,'purchase_items'=>PURCHASE_ITEMS,'categories'=>[]]];
    if($t['type']==='borrow' && !empty($t['borrow_approval']['document_id'])) {
        $payload['document']=document_detail((int)$t['borrow_approval']['document_id']);$payload['document']['logs']=[];unset($payload['document']['updated_at']);
        $payload['definition']=document_forms()['FM-IT-008'];
    }
    return $payload;
}

/** Call under a ticket/document mutation transaction. Never contact NAS here. */
function archive_enqueue(int $id): ?array
{
    $pdo=db();$own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT status FROM tickets WHERE id=? FOR UPDATE');$s->execute([$id]);if($s->fetchColumn()!=='done'){if($own)$pdo->commit();return null;}
        try{$revision=nas_read()['revision']??'';}catch(Throwable){$revision='';}
        $snapshot=json_encode(archive_snapshot($id),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$hash=hash('sha256',$snapshot);
        $s=$pdo->prepare('SELECT id,snapshot_hash,target_revision,status FROM ticket_archives WHERE ticket_id=? ORDER BY id DESC LIMIT 1');$s->execute([$id]);$latest=$s->fetch();
        if($latest&&$latest['snapshot_hash']===$hash&&$latest['target_revision']===$revision){
            if($latest['status']==='obsolete')$pdo->prepare("UPDATE ticket_archives SET status='pending',attempts=0,next_attempt_at=NOW(),last_error=NULL WHERE id=?")->execute([$latest['id']]);
        }else $pdo->prepare('INSERT INTO ticket_archives(ticket_id,snapshot_hash,target_revision,snapshot) VALUES(?,?,?,?)')->execute([$id,$hash,$revision,$snapshot]);
        if($own)$pdo->commit();return archive_status($id);
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function archive_retry(int $id): array
{
    $pdo=db();$pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT status FROM tickets WHERE id=? FOR UPDATE');$s->execute([$id]);if($s->fetchColumn()!=='done')fail('ส่งเอกสารไป NAS ได้เฉพาะงานที่เสร็จสิ้นแล้ว',409);
        $job=archive_enqueue($id);
        if($job['status']!=='sent'&&$job['status']!=='processing')$pdo->prepare("UPDATE ticket_archives SET status='pending',attempts=0,next_attempt_at=NOW(),last_error=NULL WHERE id=?")->execute([$job['id']]);
        $pdo->commit();return archive_status($id);
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
}

/** Bounded child process; fixed error messages never disclose credentials/paths. */
function archive_process(array $command,array $input,int $timeout): array
{
    $p=@proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('เริ่มตัวจัดเก็บเอกสารไม่ได้ กรุณาแจ้งผู้ดูแลระบบ');
    $json=json_encode($input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$out='';$err='';$expired=false;
    try{
        $written=0;while($written<strlen($json)){$n=@fwrite($pipes[0],substr($json,$written));if(!$n)throw new RuntimeException('ส่งข้อมูลเอกสารให้ตัวจัดเก็บไม่ได้');$written+=$n;}fclose($pipes[0]);
        stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$until=microtime(true)+$timeout;
        do{$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);$running=proc_get_status($p)['running'];
            if(strlen($out)>65536||strlen($err)>8192||microtime(true)>$until){$expired=true;proc_terminate($p,9);break;}if($running)usleep(25000);
        }while($running);$out.=stream_get_contents($pipes[1]);
    }finally{foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($p);}
    if($expired)throw new RuntimeException('การสร้างหรือส่งเอกสารเกินเวลาที่กำหนด ระบบจะลองใหม่');
    $result=json_decode($out,true);if(!is_array($result)||empty($result['ok'])){
        $code=$result['code']??'';
        throw new RuntimeException(match($code){'denied'=>'บัญชี NAS ไม่มีสิทธิ์เขียนหรือเปลี่ยนชื่อไฟล์ในโฟลเดอร์นี้','auth'=>'ยืนยันบัญชี NAS ไม่สำเร็จ กรุณาตรวจ Map NAS','conflict'=>'ชื่อไฟล์บน NAS ซ้ำและข้อมูลไม่ตรงกัน ระบบไม่เขียนทับ','mapping'=>'Map NAS เปลี่ยนแล้ว กรุณากดส่งอีกครั้งเพื่อใช้ปลายทางปัจจุบัน',default=>'สร้าง PDF หรือส่งไฟล์ไป NAS ไม่สำเร็จ กรุณาตรวจการเชื่อมต่อและสิทธิ์'});
    }
    return $result;
}

function archive_run_job(array $job,?callable $render=null,?callable $upload=null): void
{
    $id=(int)$job['id'];$pdo=db();
    try{
        $s=$pdo->prepare('SELECT status,(SELECT MAX(id) FROM ticket_archives WHERE ticket_id=t.id) latest FROM tickets t WHERE id=?');$s->execute([$job['ticket_id']]);$current=$s->fetch();
        if(!$current||$current['status']!=='done'||(int)$current['latest']!==$id){$pdo->prepare("UPDATE ticket_archives SET status='obsolete' WHERE id=?")->execute([$id]);return;}
        $pdo->prepare("UPDATE ticket_archives SET status='processing',attempts=attempts+1,started_at=NOW() WHERE id=?")->execute([$id]);
        $mapping=nas_read();if(!$mapping)throw new RuntimeException('ยังไม่ได้ตั้งค่า Map NAS กรุณาตั้งค่าก่อนแล้วกดส่งเอกสารอีกครั้ง');
        if($mapping['revision']!==$job['target_revision'])throw new RuntimeException('Map NAS เปลี่ยนแล้ว กรุณากดส่งเอกสารอีกครั้งเพื่อใช้ปลายทางปัจจุบัน');
        $payload=json_decode($job['snapshot'],true,512,JSON_THROW_ON_ERROR);$t=$payload['ticket'];
        $subfolder=ticket_types()[$t['type']]['form'];
        $filename=$subfolder.'_'.$t['ticket_no'].'_'.$id.'.pdf';
        if(!preg_match('/^[A-Z0-9._-]+\.pdf$/D',$filename))throw new RuntimeException('เลขที่เอกสารไม่ถูกต้อง');
        $file=archive_dir().'/'.$id.'.pdf';
        if(!is_file($file)){
            if($render)$render($payload,$file);
            else archive_process([(string)cfg('archive.node','/usr/bin/node'),__DIR__.'/../bin/archive-render.cjs'],['payload'=>$payload,'output'=>$file,
                'runtime'=>(string)cfg('archive.runtime',archive_dir().'/node_modules/playwright'),'chromium'=>(string)cfg('archive.chromium',archive_dir().'/chromium/chrome'),
                'dist'=>dirname(__DIR__,2).'/web/dist'],90);
        }
        $size=filesize($file);if($size<100||$size>26214400||file_get_contents($file,false,null,0,5)!=='%PDF-')throw new RuntimeException('ไฟล์ PDF ไม่สมบูรณ์');
        @chmod($file,0660);$sha=hash_file('sha256',$file);
        $pdo->prepare('UPDATE ticket_archives SET filename=?,relative_path=?,pdf_sha256=?,pdf_size=? WHERE id=?')->execute([$filename,$subfolder.'/'.$filename,$sha,$size,$id]);
        $input=[...$mapping,'subfolder'=>$subfolder,'local_file'=>$file,'filename'=>$filename,'sha256'=>$sha,'size'=>$size];
        if($upload)$upload($input);else archive_process([(string)cfg('nas.python','/usr/bin/python3'),'-I',__DIR__.'/../bin/nas-upload.py',(string)cfg('nas.python_packages','/var/lib/it-hub/python')],$input,60);
        $pdo->prepare("UPDATE ticket_archives SET status='sent',sent_at=NOW(),last_error=NULL WHERE id=?")->execute([$id]);
    }catch(Throwable $e){
        $message=$e instanceof RuntimeException?$e->getMessage():'สร้างหรือส่งเอกสารไม่สำเร็จ กรุณาลองใหม่';
        // Never persist subprocess output, SQL exceptions, private paths or secrets.
        if(str_contains($message,'/')||str_contains($message,'SQLSTATE'))$message='สร้างหรือส่งเอกสารไม่สำเร็จ กรุณาลองใหม่';
        $pdo->prepare("UPDATE ticket_archives SET status='failed',last_error=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE) WHERE id=?")->execute([mb_substr($message,0,500),$id]);
    }
}
