<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
$pdo=db();
foreach(['tickets','ticket_logs','ticket_edit_requests','ticket_request_edits','ticket_approvals','ticket_requester_signatures','ticket_it_signatures','ticket_receipt_signatures','ticket_borrow_decisions','ticket_archives','it_documents','admins','employees','assets','maintenance_records'] as $table){$pdo->exec("CREATE TEMPORARY TABLE fixture_$table LIKE $table");$pdo->exec("ALTER TABLE fixture_$table RENAME TO $table");}
$checks=0;
function verify(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function rejects(callable $fn,int $status):void{try{$fn();}catch(HttpError $e){verify($e->status===$status,"Expected $status, got {$e->status}: {$e->getMessage()}");return;}throw new RuntimeException("Expected $status");}
function drawing():string{$im=imagecreatetruecolor(600,200);imagefilledrectangle($im,0,0,599,199,imagecolorallocate($im,255,255,255));imagesetthickness($im,4);$c=imagecolorallocate($im,15,23,42);imageline($im,30,140,180,30,$c);imageline($im,180,30,350,150,$c);imageline($im,350,150,520,50,$c);ob_start();imagepng($im);return 'data:image/png;base64,'.base64_encode(ob_get_clean());}
function token(string $path):string{return json_decode(file_get_contents($path),true)['token'];}
function signatures(int $id):string{$s=db()->prepare('SELECT * FROM ticket_approvals WHERE ticket_id=? ORDER BY id');$s->execute([$id]);$a=$s->fetchAll();$s=db()->prepare('SELECT * FROM ticket_requester_signatures WHERE ticket_id=?');$s->execute([$id]);$r=$s->fetchAll();$s=db()->prepare('SELECT * FROM ticket_receipt_signatures WHERE ticket_id=?');$s->execute([$id]);return hash('sha256',json_encode([$a,$r,$s->fetchAll()]));}
$capture=tempnam(sys_get_temp_dir(),'ithub-edit-test-');$process=proc_open(['python3',__DIR__.'/smtp_fixture.py',$capture],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
try{
 $port=(int)fgets($pipes[1]);verify($port>0,'Loopback SMTP');$GLOBALS['ithub_config']['smtp']=['enabled'=>true,'host'=>'127.0.0.1','port'=>$port,'encryption'=>'','username'=>'fixture','password'=>'fixture','from_email'=>'it-hub@example.com'];$_SERVER['REMOTE_ADDR']='127.0.0.27';
 $png=drawing();$actor=['id'=>1,'display_name'=>'Fixture IT'];
 $pdo->prepare("INSERT INTO admins(id,username,password_hash,display_name,signature_data) VALUES(1,'fixture','fixture','Fixture IT',?)")->execute([$png]);
 $pdo->exec("INSERT INTO employees(id,full_name,email,company,can_approve) VALUES(1,'Fixture Head','head@example.com','Fixture',1)");
 $created=create_ticket(['type'=>'purchase','requester_name'=>'Fixture Requester','requester_dept'=>'Fixture Dept','requester_email'=>'requester@example.com','description'=>'Original request','items'=>['notebook'],'approver_id'=>1,'requester_signature_data'=>$png],null);
 $id=(int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();$approve=['decision'=>'approved','consent'=>true,'signature_data'=>$png];approval_decide([...$approve,'token'=>token($capture)]);
 $t=update_ticket($id,['status'=>'in_progress'],$actor);$tokens=[];
 foreach(['director','purchasing','md'] as $role){$t=purchase_signature_request($id,['role'=>$role,'approver_id'=>1],$actor);$tokens[$role]=token($capture);}
 approval_decide([...$approve,'token'=>$tokens['director']]);approval_decide([...$approve,'token'=>$tokens['md']]);$t=ticket_admin($id);$signatureHash=signatures($id);$revision=$t['completion_revision'];
 $edit=['version'=>$t['request_edit_version'],'reason'=>'Correct equipment','request'=>['description'=>'Corrected request','items'=>['ink'],'spec_other'=>'Black toner']];
 $t=request_edit_admin($id,$edit,$actor);
 verify($t['description']==='Corrected request'&&$t['details']['items']===['ink']&&$t['title']==='ขอซื้อ: หมึก','Admin edits request, equipment and title');
 verify($t['completion_revision']===$revision&&signatures($id)===$signatureHash,'All signature rows and revision unchanged');
 foreach($t['purchase_approvals'] as $a)verify($a['current'],'Existing approved/pending sign-off stays current');
 verify(count($t['request_edit_history'])===1&&$t['request_edit_history'][0]['admin_id']===1,'Audit before/after and admin actor');
 verify(json_decode($t['request_edit_history'][0]['before_snapshot'],true)['description']==='Original request','Original signed details preserved');
 rejects(fn()=>request_edit_admin($id,$edit,$actor),409);
 rejects(fn()=>request_edit_admin($id,[...$edit,'version'=>$t['request_edit_version'],'reason'=>''], $actor),422);
 rejects(fn()=>request_edit_admin($id,[...$edit,'version'=>$t['request_edit_version'],'request'=>['requester_name'=>'Forged']],$actor),422);
 rejects(fn()=>request_edit_admin($id,[...$edit,'version'=>$t['request_edit_version'],'request'=>['items'=>['invalid']]],$actor),422);
 rejects(fn()=>request_edit_admin($id,[...$edit,'version'=>$t['request_edit_version'],'request'=>['need_by'=>'2026-02-31']],$actor),422);
 verify(count(ticket_admin($id)['request_edit_history'])===1&&signatures($id)===$signatureHash,'Rejected edits roll back');
 // Pending signer links continue to work after the audited request correction.
 approval_decide([...$approve,'token'=>$tokens['purchasing']]);$t=ticket_admin($id);verify($t['purchase_approvals']['purchasing']['status']==='approved','Existing pending link remains valid');$signatureHash=signatures($id);
 $t=request_edit_return($id,['version'=>$t['request_edit_version'],'reason'=>'Add exact toner model'],$actor);$editToken=token($capture);
 verify($t['request_edit_return']['mail_status']==='sent','Return email sent to loopback');
 $mail=json_decode(file_get_contents($capture),true);verify(str_contains($mail['to'],'requester@example.com')&&str_contains($mail['body'],'/request-edit#'.$editToken)&&str_contains($mail['body'],'Add exact toner model'),'Correct return recipient, reason and fragment link');
 $s=$pdo->query('SELECT token_hash FROM ticket_edit_requests ORDER BY id DESC LIMIT 1')->fetchColumn();verify($s===hash('sha256',$editToken)&&!str_contains(json_encode($t),$editToken),'Token hashed and omitted from admin payload');
 verify(!isset(ticket_public($created['ticket_no'])['request_edit_return']),'Public tracking exposes no return links or private details');
 rejects(fn()=>request_edit_view(['token'=>'invalid']),404);rejects(fn()=>request_edit_view(['token'=>str_repeat('0',64)]),404);
 $view=request_edit_view(['token'=>$editToken]);verify($view['reason']==='Add exact toner model'&&$view['request']['description']==='Corrected request','Recipient sees correction reason and current request');
 verify(!isset($view['approval'],$view['signature_data'],$view['requester_email'],$view['request']['procurement']),'Recipient payload excludes signatures, contacts and procurement');
 $body=['token'=>$editToken,'version'=>$view['version'],'request'=>['description'=>'Requester final detail','spec_other'=>'HP toner fixture']];
 rejects(fn()=>request_edit_submit([...$body,'request'=>['resolution'=>'Unauthorized']]),422);
 $out=request_edit_submit($body);$t=ticket_admin($id);
 verify($out['ticket_no']===$created['ticket_no']&&$t['description']==='Requester final detail','Requester submits corrected details');
 verify($t['request_edit_return']['status']==='submitted'&&$t['request_edit_return']['submitted_at']!==null,'Return marked submitted');
 verify(count($t['request_edit_history'])===2&&$t['request_edit_history'][0]['admin_id']===null,'Requester edit is audited separately');
 verify(signatures($id)===$signatureHash&&$t['completion_revision']===$revision,'Requester correction preserves all original signature evidence');
 foreach($t['purchase_approvals'] as $a)verify($a['current'],'Signature stays current after correction chain');
 rejects(fn()=>request_edit_submit($body),409);rejects(fn()=>request_edit_view(['token'=>$editToken]),409);
 $pdo->exec('UPDATE ticket_edit_requests SET created_at=DATE_SUB(NOW(),INTERVAL 2 MINUTE)');
 $t=request_edit_return($id,['version'=>$t['request_edit_version'],'reason'=>'Second return'],$actor);$oldToken=token($capture);
 rejects(fn()=>request_edit_return($id,['version'=>$t['request_edit_version'],'reason'=>'Too soon'],$actor),429);
 $pdo->exec('UPDATE ticket_edit_requests SET created_at=DATE_SUB(NOW(),INTERVAL 2 MINUTE)');
 $t=request_edit_return($id,['version'=>$t['request_edit_version'],'reason'=>'Replacement return'],$actor);$newToken=token($capture);
 rejects(fn()=>request_edit_view(['token'=>$oldToken]),409);
 $view=request_edit_view(['token'=>$newToken]);
 $t=request_edit_admin($id,['version'=>$t['request_edit_version'],'reason'=>'Concurrent admin change','request'=>['description'=>'Concurrent version']],$actor);
 rejects(fn()=>request_edit_submit(['token'=>$newToken,'version'=>$view['version'],'request'=>['description'=>'Stale overwrite']]),409);
 verify(request_edit_view(['token'=>$newToken])['request']['description']==='Concurrent version','Conflict keeps link usable to reload');
 $pdo->exec("UPDATE ticket_edit_requests SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE status='pending'");rejects(fn()=>request_edit_view(['token'=>$newToken]),410);
 $GLOBALS['ithub_config']['smtp']['enabled']=false;$pdo->exec('UPDATE ticket_edit_requests SET created_at=DATE_SUB(NOW(),INTERVAL 2 MINUTE)');
 $t=request_edit_return($id,['version'=>$t['request_edit_version'],'reason'=>'Mail failure'],$actor);verify($t['request_edit_return']['mail_status']==='failed','SMTP failure is visible and recoverable');
 $t=request_edit_return($id,['version'=>$t['request_edit_version'],'reason'=>'Retry mail failure'],$actor);verify($t['request_edit_return']['mail_status']==='failed','Failed mail permits immediate retry');
 $pdo->exec("ALTER TABLE ticket_request_edits ADD CONSTRAINT fixture_atomic_edit CHECK(reason <> 'Force transaction failure')");
 $beforeFailure=ticket_admin($id);
 try{request_edit_admin($id,['version'=>$beforeFailure['request_edit_version'],'reason'=>'Force transaction failure','request'=>['description'=>'Must roll back']],$actor);throw new RuntimeException('Expected database constraint failure');}
 catch(PDOException){verify(true,'Database failure exercised after ticket update');}
 $afterFailure=ticket_admin($id);
 verify($afterFailure['description']===$beforeFailure['description']&&$afterFailure['request_edit_history']===$beforeFailure['request_edit_history']&&signatures($id)===$signatureHash,'Late database failure rolls back details and audit without touching signatures');
 // Corrections must not waive the separate procurement/work revision rules.
 $t=update_ticket($id,['purchase_fields'=>['amount'=>'123.00']],$actor);foreach($t['purchase_approvals'] as $a)verify(!$a['current'],'Procurement changes still invalidate old procurement sign-off');
 $pdo->exec("UPDATE tickets SET status='cancelled' WHERE id=$id");$t=ticket_admin($id);rejects(fn()=>request_edit_admin($id,['version'=>$t['request_edit_version'],'reason'=>'Invalid','request'=>['description'=>'Invalid']],$actor),409);
 $pdo->exec("UPDATE tickets SET status='in_progress',requester_email=NULL WHERE id=$id");$t=ticket_admin($id);rejects(fn()=>request_edit_return($id,['version'=>$t['request_edit_version'],'reason'=>'No recipient'],$actor),422);
 echo "PASS: $checks request correction checks; TEMPORARY tables and loopback SMTP only\n";
}finally{proc_terminate($process);foreach($pipes as $pipe)fclose($pipe);proc_close($process);@unlink($capture);}
