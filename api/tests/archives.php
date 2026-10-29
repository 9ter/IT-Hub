<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
$pdo=db();foreach(['tickets','ticket_logs','admins','employees','assets','maintenance_records','ticket_approvals','ticket_requester_signatures','ticket_it_signatures','ticket_receipt_signatures','ticket_borrow_decisions','ticket_archives','it_documents'] as $table){$pdo->exec("CREATE TEMPORARY TABLE f_$table LIKE $table");$pdo->exec("ALTER TABLE f_$table RENAME TO $table");}
$checks=0;function check(bool $v,string $label):void{global $checks;if(!$v)throw new RuntimeException($label);$checks++;}
$dir=sys_get_temp_dir().'/ithub-archive-'.bin2hex(random_bytes(8));mkdir($dir,0700);mkdir($dir.'/nas',0700);mkdir($dir.'/pdf',0700);
$GLOBALS['ithub_config']['nas']['state_dir']=$dir.'/nas';$GLOBALS['ithub_config']['archive']['state_dir']=$dir.'/pdf';
$mapping=['revision'=>str_repeat('a',32),'host'=>'192.168.1.10','share'=>'Fixture','folder'=>'IT','unc'=>'fixture','username'=>'fixture','domain'=>'','password'=>'FixtureSecret','verified_at'=>now()];
$save=function()use(&$mapping,$dir){$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);file_put_contents($dir.'/nas/mapping.enc',$nonce.sodium_crypto_secretbox(json_encode($mapping),$nonce,nas_key(true)));};$save();
$actor=['id'=>1,'display_name'=>'Fixture IT'];$pdo->exec("INSERT INTO admins(id,username,password_hash,display_name) VALUES(1,'fixture','fixture','Fixture IT')");$_SERVER['REMOTE_ADDR']='127.0.0.50';
try{
 $r=create_ticket(['type'=>'repair','requester_name'=>'Fixture requester','requester_dept'=>'Fixture','equipment'=>'Fixture computer','description'=>'Fixture problem'],null);$id=(int)$pdo->lastInsertId();$id=(int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();
 check(archive_status($id)===null,'No archive for an open request');
 update_ticket($id,['status'=>'done','resolution'=>'Fixture repaired'],$actor);$job=$pdo->query('SELECT * FROM ticket_archives')->fetch();
 check($job['status']==='pending'&&$job['target_revision']===$mapping['revision'],'Completion queues an immutable snapshot for mapped NAS');
 $snapshot=json_decode($job['snapshot'],true);check($snapshot['ticket']['status']==='done'&&$snapshot['ticket']['resolution']==='Fixture repaired','Snapshot includes completed result');
 check(!str_contains($job['snapshot'],'FixtureSecret')&&!isset($snapshot['ticket']['client_ip'],$snapshot['ticket']['nas_archive']),'Snapshot excludes secrets, IP and archive self-reference');
 archive_retry($id);check((int)$pdo->query('SELECT COUNT(*) FROM ticket_archives')->fetchColumn()===1,'Repeated queueing is idempotent');
 $renderCount=0;$uploadCount=0;
 $render=function($payload,$file)use(&$renderCount){$renderCount++;file_put_contents($file,'%PDF-1.4'.str_repeat('Fixture ',30));};
 $upload=function($input)use(&$uploadCount){$uploadCount++;check($input['sha256']===hash_file('sha256',$input['local_file']),'Transport receives verified SHA256 and private local PDF');check($input['subfolder']==='FM-IT-003','Transport receives a form-specific subfolder');};
 archive_run_job($job,$render,$upload);$sent=archive_status($id);check($sent['status']==='sent'&&$sent['sent_at']!==null,'Successful verification marks file sent');
 check($renderCount===1&&$uploadCount===1,'One renderer and one upload operation');
 check(str_ends_with($sent['filename'],'.pdf')&&!str_contains($sent['filename'],'Fixture requester'),'Remote filename contains form/ticket identity, not personal names');
 check($sent['relative_path']==='FM-IT-003/'.$sent['filename'],'UI metadata identifies the relative archive path without NAS credentials');
 update_ticket($id,['resolution'=>'New completed result'],$actor);$new=$pdo->query('SELECT * FROM ticket_archives ORDER BY id DESC LIMIT 1')->fetch();
 check($new['id']!==$job['id']&&json_decode($job['snapshot'],true)['ticket']['resolution']==='Fixture repaired','Editing a completed result queues a new immutable version');
 archive_run_job($new,$render,fn()=>throw new RuntimeException('จำลอง NAS ขัดข้อง'));check(archive_status($id)['status']==='failed','NAS failure is retained for retry');
 check(ticket_admin($id)['status']==='done','NAS failure never reopens or loses completed work');
 archive_retry($id);$retry=$pdo->query('SELECT * FROM ticket_archives ORDER BY id DESC LIMIT 1')->fetch();check($retry['status']==='pending'&&(int)$retry['attempts']===0,'Admin retry requeues failed work');
 $before=$renderCount;archive_run_job($retry,$render,$upload);check($renderCount===$before,'Retry reuses the cached PDF bytes');
 $mapping['revision']=str_repeat('b',32);$save();archive_retry($id);$changed=$pdo->query('SELECT * FROM ticket_archives ORDER BY id DESC LIMIT 1')->fetch();
 check($changed['id']!==$new['id']&&$changed['target_revision']===$mapping['revision'],'Explicit retry binds a new mapping revision');
 $mapping['revision']=str_repeat('c',32);$save();$before=$uploadCount;archive_run_job($changed,$render,$upload);check(archive_status($id)['status']==='failed'&&$uploadCount===$before,'Mapping change prevents sending to an unexpected destination');
 $mapping['revision']=str_repeat('a',32);$save();$restored=archive_retry($id);check($restored['id']!==$job['id']&&$restored['status']==='pending','Restoring a previous target still creates the current version rather than returning an older sent job');
 $count=(int)$pdo->query('SELECT COUNT(*) FROM ticket_archives')->fetchColumn();archive_retry($id);check((int)$pdo->query('SELECT COUNT(*) FROM ticket_archives')->fetchColumn()===$count,'Repeated retry deduplicates against the latest version');
 $mapping['revision']=str_repeat('c',32);$save();
 archive_retry($id);$reopen=$pdo->query('SELECT * FROM ticket_archives ORDER BY id DESC LIMIT 1')->fetch();update_ticket($id,['status'=>'in_progress'],$actor);archive_run_job($reopen,$render,$upload);
 check(archive_status($id)['status']==='obsolete','Reopened work is not exported as currently completed');
 try{archive_retry($id);throw new RuntimeException('Expected rejection');}catch(HttpError $e){check($e->status===409,'Only done tickets can retry archive');}
 check(!isset(ticket_public($r['ticket_no'])['nas_archive']),'Public tracking never exposes archive metadata');
 update_ticket($id,['status'=>'done'],$actor);$pdo->beginTransaction();$pdo->prepare('UPDATE tickets SET resolution=? WHERE id=?')->execute(['Rollback fixture',$id]);archive_enqueue($id);$pdo->rollBack();check(ticket_admin($id)['resolution']!=='Rollback fixture','Outbox and completed content roll back together');
 echo "PASS: $checks archive outbox checks; temporary tables, fake NAS and renderer only\n";
}finally{foreach(glob($dir.'/pdf/*') as $f)unlink($f);foreach(glob($dir.'/nas/*') as $f)unlink($f);rmdir($dir.'/pdf');rmdir($dir.'/nas');rmdir($dir);}
