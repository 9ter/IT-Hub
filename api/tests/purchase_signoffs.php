<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
$pdo=db();foreach(['tickets','ticket_logs','ticket_approvals','ticket_requester_signatures','ticket_it_signatures','ticket_receipt_signatures','ticket_borrow_decisions','ticket_archives','it_documents','admins','employees','assets','maintenance_records'] as $table){$pdo->exec("CREATE TEMPORARY TABLE fixture_$table LIKE $table");$pdo->exec("ALTER TABLE fixture_$table RENAME TO $table");}
$checks=0;
function verify(bool $v,string $label):void{global $checks;if(!$v)throw new RuntimeException($label);$checks++;}
function rejects(callable $fn,int $status):void{try{$fn();}catch(HttpError $e){verify($e->status===$status,'Expected '.$status.', got '.$e->status.': '.$e->getMessage());return;}throw new RuntimeException('Expected HTTP '.$status);}
function image_fixture():string{$im=imagecreatetruecolor(600,200);imagefilledrectangle($im,0,0,599,199,imagecolorallocate($im,255,255,255));imagesetthickness($im,4);$c=imagecolorallocate($im,15,23,42);imageline($im,30,140,180,30,$c);imageline($im,180,30,350,150,$c);imageline($im,350,150,520,50,$c);ob_start();imagepng($im);return 'data:image/png;base64,'.base64_encode(ob_get_clean());}
function token_fixture(string $path):string{return json_decode(file_get_contents($path),true)['token'];}
$capture=tempnam(sys_get_temp_dir(),'ithub-purchase-signoffs-');$process=proc_open(['python3',__DIR__.'/smtp_fixture.py',$capture],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
try{
 $port=(int)fgets($pipes[1]);verify($port>0,'SMTP loopback sink');$GLOBALS['ithub_config']['smtp']=['enabled'=>true,'host'=>'127.0.0.1','port'=>$port,'encryption'=>'','username'=>'fixture','password'=>'fixture','from_email'=>'it-hub@example.com'];$_SERVER['REMOTE_ADDR']='127.0.0.25';
 $png=image_fixture();$actor=['id'=>1,'display_name'=>'Actual IT'];$other=['id'=>2,'display_name'=>'Assigned IT'];
 $s=$pdo->prepare("INSERT INTO admins(id,username,password_hash,display_name,signature_data) VALUES(?,?,'fixture',?,?)");$s->execute([1,'actual','Actual IT',$png]);$s->execute([2,'assigned','Assigned IT',$png]);
 $pdo->exec("INSERT INTO employees(id,full_name,email,company,can_approve) VALUES(1,'Fixture Head','head@example.com','Fixture',1),(2,'Other Approver','other@example.com','Fixture',1)");
 $create=create_ticket(['type'=>'purchase','requester_name'=>'Fixture Requester','requester_dept'=>'Fixture Department','description'=>'Fixture purchase','items'=>['notebook'],'approver_id'=>1,'requester_signature_data'=>$png],null);$id=(int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();$headToken=token_fixture($capture);
 $approve=['decision'=>'approved','consent'=>true,'signature_data'=>$png];
 rejects(fn()=>receipt_ticket_sign($id,['consent'=>true],$actor),422);
 rejects(fn()=>purchase_signature_request($id,['role'=>'director','approver_id'=>1],$actor),422);
 approval_decide([...$approve,'token'=>$headToken]);update_ticket($id,['assigned_to'=>2],$actor);
 rejects(fn()=>receipt_ticket_sign($id,[],$actor),422);
 $t=receipt_ticket_sign($id,['consent'=>true],$actor);$receipt=$t['receipt_signature'];verify($receipt['admin_id']===1&&$receipt['signer_name']==='Actual IT'&&$t['assigned_to']===2,'Receipt captures actor rather than assignee');
 verify($t['resolution']===null&&$t['status']==='new'&&$receipt['signature_data']===$png,'Receipt does not require work result or alter status');
 rejects(fn()=>receipt_ticket_sign($id,['consent'=>true],$other),409);
 $t=update_ticket($id,['status'=>'accepted'],$other);verify($t['receipt_signature']===$receipt,'Later reception cannot replace first signed recipient');
 $proc=['vendor'=>'Fixture Vendor','vendor_phone'=>'0100000000','quotation'=>'QUO-TEST','quotation_date'=>'2026-10-28','amount'=>'1000.00','vat'=>'included'];
 rejects(fn()=>purchase_signature_request($id,['role'=>'invalid','approver_id'=>1],$actor),422);
 rejects(fn()=>purchase_signature_request($id,['role'=>'director','approver_id'=>999999],$actor),422);
 rejects(fn()=>purchase_signature_request($id,['role'=>'director','approver_id'=>1,'purchase_fields'=>[...$proc,'amount'=>'-1']],$actor),422);
 rejects(fn()=>purchase_signature_request($id,['role'=>'director','approver_id'=>1,'purchase_fields'=>[...$proc,'quotation_date'=>'2026-02-31']],$actor),422);
 rejects(fn()=>purchase_signature_request($id,['role'=>'director','approver_id'=>1,'work_revision'=>999],$actor),409);
 $tokens=[];
 foreach(PURCHASE_SIGNING_ROLES as $role=>$def){
  $t=purchase_signature_request($id,['role'=>$role,'approver_id'=>1,'purchase_fields'=>$proc,'work_revision'=>ticket_admin($id)['completion_revision']],$actor);$tokens[$role]=token_fixture($capture);$a=$t['purchase_approvals'][$role];
  verify($a['stage']===$def['stage']&&$a['current']&&$a['status']==='pending','Independent pending role '.$role);
  $mail=json_decode(file_get_contents($capture),true);verify(str_contains($mail['body'],$def['label'])&&str_contains($mail['body'],'QUO-TEST')&&str_contains($mail['body'],'1000.00')&&str_contains($mail['body'],'FM-IT-007'),'Role and procurement snapshot in email '.$role);
 }
 verify($t['purchase_fields']===$proc&&$t['approval']['approver_name']==='Fixture Head','Procurement stored without changing initial approver');
 $t=update_ticket($id,['status'=>'done'],$actor);verify($t['status']==='done'&&!$t['completion_required'],'Close allowed with all three extra signatures pending');
 foreach($tokens as $role=>$token){$view=approval_view(['token'=>$token]);verify($view['approval_role']===PURCHASE_SIGNING_ROLES[$role]['label']&&$view['purchase_fields']['amount']==='1000.00','Pending invitation remains usable after done '.$role);}
 rejects(fn()=>purchase_signature_request($id,['role'=>'director','approver_id'=>2],$actor),429);
 $pdo->prepare("UPDATE ticket_approvals SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE ticket_id=? AND stage='purchase_director'")->execute([$id]);
 $t=purchase_signature_request($id,['role'=>'director','approver_id'=>2],$actor);$director=token_fixture($capture);
 verify($t['purchase_fields']===$proc&&$t['resolution']===null,'Resend with omitted fields preserves saved content');
 verify($t['purchase_approvals']['director']['employee_id']===2&&$t['approval']['employee_id']===1,'Resend can change only the chosen role');
 rejects(fn()=>approval_view(['token'=>$tokens['director']]),404);
 rejects(fn()=>approval_decide([...$approve,'token'=>$director,'consent'=>false]),422);
 approval_decide([...$approve,'token'=>$director]);verify(ticket_admin($id)['status']==='done','Director signature never changes closed status');
 rejects(fn()=>approval_decide([...$approve,'token'=>$director]),409);
 rejects(fn()=>purchase_signature_request($id,['role'=>'director','approver_id'=>2],$actor),409);
 rejects(fn()=>approval_decide([...$approve,'token'=>$tokens['purchasing'],'decision'=>'rejected']),422);
 approval_decide([...$approve,'token'=>$tokens['purchasing'],'decision'=>'rejected','decision_note'=>'Fixture needs correction']);verify(ticket_admin($id)['status']==='done','Purchasing rejection retains done status');
 approval_decide([...$approve,'token'=>$tokens['md']]);$t=ticket_admin($id);verify($t['purchase_approvals']['md']['signature_data']===$png&&$t['purchase_approvals']['director']['signature_data']===$png,'Signed role images available to print');
 $old=$t['purchase_approvals']['purchasing'];$t=purchase_signature_request($id,['role'=>'purchasing','approver_id'=>2],$actor);$pendingToken=token_fixture($capture);verify($t['purchase_approvals']['purchasing']['attempt']===2,'Rejected role can request new attempt after closure');
 $s=$pdo->prepare('SELECT signature_data,status FROM ticket_approvals WHERE id=?');$s->execute([$old['id']]);verify($s->fetch()===['signature_data'=>$png,'status'=>'rejected'],'Prior rejection evidence preserved');
 $previousRevision=$t['completion_revision'];$t=update_ticket($id,['purchase_fields'=>[...$proc,'amount'=>'2000.00'],'work_revision'=>$previousRevision],$actor);
 verify($t['status']==='done'&&$t['completion_revision']===$previousRevision+1,'Changing closed procurement starts a new signing revision without opening work');
 foreach($t['purchase_approvals'] as $a)verify(!$a['current'],'Stale role excluded from current form');
 rejects(fn()=>approval_view(['token'=>$pendingToken]),409);
 rejects(fn()=>update_ticket($id,['purchase_fields'=>$proc,'work_revision'=>$previousRevision],$actor),409);
 $t=update_ticket($id,['purchase_fields'=>$proc],$actor);verify(!$t['purchase_approvals']['director']['current'],'Restoring old amount does not revive old signature');
 verify($t['receipt_signature']===$receipt,'Procurement edits preserve original recipient');
 $pdo->exec('UPDATE admins SET display_name=\'Renamed IT\',signature_data=NULL WHERE id=1');verify(ticket_admin($id)['receipt_signature']===$receipt,'Changing/deleting admin profile cannot alter receipt snapshot');
 $GLOBALS['ithub_config']['smtp']['enabled']=false;$t=purchase_signature_request($id,['role'=>'md','approver_id'=>2],$actor);verify($t['status']==='done'&&$t['purchase_approvals']['md']['mail_status']==='failed','Mail failure preserves closed status and pending role');
 $GLOBALS['ithub_config']['smtp']['enabled']=true;$t=purchase_signature_request($id,['role'=>'director','approver_id'=>2],$actor);$cancelToken=token_fixture($capture);update_ticket($id,['status'=>'cancelled'],$actor);rejects(fn()=>approval_view(['token'=>$cancelToken]),409);rejects(fn()=>purchase_signature_request($id,['role'=>'director','approver_id'=>2],$actor),409);
 $t=update_ticket($id,['status'=>'in_progress'],$actor);verify($t['receipt_signature']===$receipt&&!$t['purchase_approvals']['director']['current'],'Reopening invalidates sign-offs but preserves reception');
 verify(!isset($t['receipt_signature']['signed_ip'],$t['purchase_approvals']['director']['token_hash'],$t['purchase_approvals']['director']['signed_ip']),'Private signature transport excludes tokens and IP');
 $pub=ticket_public($create['ticket_no']);verify(!isset($pub['receipt_signature'],$pub['purchase_approvals'],$pub['purchase_fields']),'Public tracker excludes receipt, procurement and additional signatures');
 echo "PASS: $checks purchase sign-off checks; temporary tables and loopback SMTP only\n";
}finally{proc_terminate($process);foreach($pipes as $p)fclose($p);proc_close($process);@unlink($capture);}
