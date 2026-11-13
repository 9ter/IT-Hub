<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
$pdo = db();
foreach (['employees','tickets','ticket_logs','ticket_edit_requests','ticket_request_edits','ticket_approvals','ticket_requester_signatures','ticket_it_signatures','ticket_receipt_signatures','ticket_borrow_decisions','ticket_archives','it_documents','maintenance_records','admins','assets'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE `fixture_$table` LIKE `$table`");
    $pdo->exec("ALTER TABLE `fixture_$table` RENAME TO `$table`");
}
$checks = 0;
function verify(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function rejects(callable $fn, int $status): void {
    try { $fn(); } catch (HttpError $e) { verify($e->status === $status,'Unexpected HTTP status '.$e->status); return; }
    throw new RuntimeException('Expected HTTP '.$status);
}
function draw_signature(): string {
    $im=imagecreatetruecolor(600,200);
    imagefilledrectangle($im,0,0,599,199,imagecolorallocate($im,255,255,255));
    imagesetthickness($im,3);$ink=imagecolorallocate($im,15,23,42);
    imageline($im,30,150,180,30,$ink);imageline($im,180,30,300,150,$ink);imageline($im,300,150,480,70,$ink);
    ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($im);
    return 'data:image/png;base64,'.base64_encode($png);
}
$capture=tempnam(sys_get_temp_dir(),'ithub-completion-test-');
$process=proc_open(['python3',__DIR__.'/smtp_fixture.py',$capture],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
try {
    $port=(int)fgets($pipes[1]);verify($port>0,'SMTP fixture starts');
    $GLOBALS['ithub_config']['smtp']=['enabled'=>true,'host'=>'127.0.0.1','port'=>$port,'encryption'=>'','username'=>'fixture','password'=>'fixture','from_email'=>'it-hub@example.com'];
    $GLOBALS['ithub_config']['completion_default_approver_name']='Fixture Director';
    $_SERVER['REMOTE_ADDR']='127.0.0.3';
    $person=['full_name'=>'Fixture Head','email'=>'head@example.com','company'=>'Fixture Company','department'=>'Fixture Department','can_approve'=>true];
    employee_save(null,$person);$headId=(int)$pdo->query('SELECT MAX(id) FROM employees')->fetchColumn();
    employee_save(null,[...$person,'full_name'=>'Fixture Director','email'=>'director@example.com']);$directorId=(int)$pdo->query('SELECT MAX(id) FROM employees')->fetchColumn();
    verify(completion_default_approver_id()===$directorId,'Configured default signer resolves to active employee');
    $signature=draw_signature();$admin=['id'=>1,'display_name'=>'Fixture Admin'];
    $pdo->prepare('INSERT INTO admins (id,username,password_hash,display_name,signature_data) VALUES (1,\'fixture\',\'fixture-only\',\'Fixture Admin\',?)')->execute([$signature]);
    $create=create_ticket(['type'=>'service','requester_name'=>'Fixture Requester','requester_dept'=>'Fixture Department','description'=>'Fixture request','actions'=>['company_email'],'approver_id'=>$headId,'requester_signature_data'=>$signature],null);
    $id=(int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();$headToken=json_decode(file_get_contents($capture),true)['token'];
    $body=['approver_id'=>$directorId,'resolution'=>'Fixture completed work version one'];
    rejects(fn()=>completion_request($id,$body,$admin),422);
    verify(approval_for_ticket($id,'completion')===null,'No completion request before initial approval');
    $decision=['decision'=>'approved','consent'=>true,'signature_data'=>$signature];
    approval_decide([...$decision,'token'=>$headToken]);update_ticket($id,['status'=>'in_progress'],$admin);
    rejects(fn()=>update_ticket($id,['status'=>'done'],$admin),422);
    rejects(fn()=>update_ticket($id,['status'=>'done','resolution'=>'Result without signature'],$admin),422);
    rejects(fn()=>completion_request($id,[...$body,'resolution'=>''],$admin),422);
    rejects(fn()=>completion_request($id,[...$body,'approver_id'=>9999999],$admin),422);
    $t=completion_request($id,$body,$admin);$first=$t['completion_approval'];$mail=json_decode(file_get_contents($capture),true);$token1=$mail['token'];
    verify($t['completion_required'] && $t['completion_approval_current'],'Service requires current completion review');
    verify($t['it_signature']['signer_name']==='Fixture Admin' && $t['it_signature']['signature_data']===$signature,'Submitting work automatically snapshots IT staff signature');
    verify($first['stage']==='completion' && $first['status']==='pending' && (int)$first['attempt']===1,'Separate completion attempt created');
    verify($first['resolution_snapshot']===$body['resolution'] && (int)$first['employee_id']===$directorId,'Result and selected director snapshotted');
    verify(str_contains($mail['to'],'director@example.com') && str_contains($mail['subject'],'รับรองผล') && str_contains($mail['body'],$body['resolution']),'Mail sent to director with result');
    verify($t['approval']['approver_name']==='Fixture Head' && $t['approval']['status']==='approved' && $t['details']['approver_name']==='Fixture Head','Initial approval and name unaffected');
    verify(ticket_public($create['ticket_no'])['approval_status']==='approved','Public request approval remains distinct');
    $view=approval_view(['token'=>$token1]);verify($view['stage']==='completion' && $view['resolution_snapshot']===$body['resolution'],'Reviewer sees stage and result');
    verify(ticket_admin($id)['status']==='in_progress','Viewing result does not close work');
    rejects(fn()=>update_ticket($id,['status'=>'done'],$admin),422);
    rejects(fn()=>approval_decide([...$decision,'token'=>$token1,'consent'=>false]),422);
    rejects(fn()=>approval_decide([...$decision,'token'=>$token1,'decision'=>'rejected']),422);
    approval_decide([...$decision,'token'=>$token1,'decision'=>'rejected','decision_note'=>'Fixture needs correction']);
    verify(ticket_admin($id)['status']==='in_progress' && approval_for_ticket($id)['status']==='approved','Completion rejection keeps work open and initial approval intact');
    rejects(fn()=>update_ticket($id,['status'=>'done'],$admin),422);
    rejects(fn()=>approval_decide([...$decision,'token'=>$token1]),409);
    $t=completion_request($id,$body,$admin);$token2=json_decode(file_get_contents($capture),true)['token'];
    verify((int)$t['completion_approval']['attempt']===2,'Rejected review gets a new attempt');
    $s=$pdo->prepare('SELECT status,signature_data FROM ticket_approvals WHERE id=?');$s->execute([$first['id']]);$old=$s->fetch();
    verify($old['status']==='rejected' && $old['signature_data']===$signature,'Rejected signature evidence preserved');
    rejects(fn()=>completion_request($id,$body,$admin),429);
    update_ticket($id,['resolution'=>'Fixture completed work version two'],$admin);
    verify(ticket_admin($id)['it_signature']===null,'Changed result no longer displays previous IT signature');
    rejects(fn()=>approval_view(['token'=>$token2]),409);
    rejects(fn()=>approval_decide([...$decision,'token'=>$token2]),409);
    $body['resolution']='Fixture completed work version two';
    $t=completion_request($id,$body,$admin);$token3=json_decode(file_get_contents($capture),true)['token'];
    verify($t['it_signature']['work_revision']===$t['completion_revision'],'New work submission captures current IT signature revision');
    verify((int)$t['completion_approval']['attempt']===3,'Changed result gets a fresh attempt');
    $pdo->prepare('UPDATE ticket_approvals SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?')->execute([$t['completion_approval']['id']]);
    rejects(fn()=>approval_view(['token'=>$token3]),410);
    // Resending may choose another authorized signer and must only rotate this stage's token.
    $t=completion_request($id,[...$body,'approver_id'=>$headId],$admin);$token4=json_decode(file_get_contents($capture),true)['token'];
    verify((int)$t['completion_approval']['attempt']===3 && (int)$t['completion_approval']['employee_id']===$headId,'Pending resend changes completion signer without new attempt');
    rejects(fn()=>approval_view(['token'=>$token3]),404);
    verify($t['details']['approver_name']==='Fixture Head' && $t['approval']['status']==='approved','Completion resend cannot alter initial stage');
    approval_decide([...$decision,'token'=>$token4]);
    verify(ticket_admin($id)['status']==='in_progress','Director approval enables IT close without auto-closing');
    rejects(fn()=>approval_decide([...$decision,'token'=>$token4]),409);
    rejects(fn()=>completion_request($id,$body,$admin),409);
    $done=update_ticket($id,['status'=>'done'],$admin);
    verify($done['status']==='done' && $done['finished_at']!==null && $done['completion_approval_current'],'IT can finish only after matching signature');
    rejects(fn()=>update_ticket($id,['resolution'=>'Edit closed result'],$admin),422);
    $reopen=update_ticket($id,['status'=>'in_progress'],$admin);
    verify(!$reopen['completion_approval_current'],'Reopening invalidates previous closure approval');
    rejects(fn()=>update_ticket($id,['status'=>'done'],$admin),422);
    $t=completion_request($id,$body,$admin);$token5=json_decode(file_get_contents($capture),true)['token'];
    verify((int)$t['completion_approval']['attempt']===4,'Reopened work needs fresh signature even with same text');
    update_ticket($id,['status'=>'cancelled'],$admin);
    rejects(fn()=>approval_decide([...$decision,'token'=>$token5]),409);
    rejects(fn()=>completion_request($id,$body,$admin),409);
    update_ticket($id,['status'=>'in_progress'],$admin);
    $GLOBALS['ithub_config']['smtp']['enabled']=false;
    $t=completion_request($id,[...$body,'resolution'=>'Fixture completed work version three'],$admin);
    verify($t['completion_approval']['mail_status']==='failed' && $t['resolution']==='Fixture completed work version three','SMTP failure retains result and pending stage');
    verify($t['status']==='in_progress' && !$t['completion_approval']['signature_data'],'SMTP failure cannot mark finished or sign');
    $count=(int)$pdo->query("SELECT COUNT(*) FROM ticket_approvals WHERE stage='completion'")->fetchColumn();
    verify($count===5,'Every decision/version keeps its own evidence');
    // FM-IT-003: requester signs on submit; ผอ./ผจก. sign-off is optional and may finish after closing.
    $GLOBALS['ithub_config']['smtp']['enabled']=true;
    $repairBody=['type'=>'repair','requester_name'=>'Fixture Requester','requester_dept'=>'Fixture Department','description'=>'Fixture repair','equipment'=>'Fixture device'];
    rejects(fn()=>create_ticket($repairBody,null),422);
    rejects(fn()=>create_ticket([...$repairBody,'requester_signature_data'=>'data:image/svg+xml;base64,PHN2Zy8+'],null),422);
    $repair=create_ticket([...$repairBody,'requester_signature_data'=>$signature],null);
    $repairId=(int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();
    verify(!isset($repair['mail_status']) && approval_for_ticket($repairId)===null,'Repair has no approver before IT starts');
    $rt=ticket_admin($repairId);
    verify($rt['requester_signature']['signature_data']===$signature && $rt['requester_signature']['requester_name']==='Fixture Requester','Repair requester signature stored');
    verify(!$rt['completion_required'],'Repair sign-off is not a closing gate');
    verify(!array_key_exists('requester_signature',ticket_public($repair['ticket_no'])),'Public tracker hides repair signature');
    verify(update_ticket($repairId,['status'=>'in_progress'],$admin)['status']==='in_progress','Repair starts without approval');
    rejects(fn()=>completion_request($repairId,[...$body,'resolution'=>''],$admin),422);
    $rt=completion_request($repairId,[...$body,'resolution'=>'Fixture repair fixed'],$admin);$rmail=json_decode(file_get_contents($capture),true);$rtoken=$rmail['token'];
    verify($rt['completion_approval']['status']==='pending' && $rt['status']==='in_progress','Repair sign-off requested while open');
    verify(str_contains($rmail['to'],'director@example.com') && str_contains($rmail['subject'],'งานซ่อม') && str_contains($rmail['body'],'FM-IT-003') && str_contains($rmail['body'],'Fixture repair fixed'),'Repair sign-off mail names FM-IT-003 and result');
    $done=update_ticket($repairId,['status'=>'done'],$admin);
    verify($done['status']==='done' && $done['completion_approval']['status']==='pending','Repair closes without waiting for ผอ./ผจก.');
    $rview=approval_view(['token'=>$rtoken]);
    verify($rview['type']==='repair' && $rview['resolution_snapshot']==='Fixture repair fixed','Signer can view after repair closed');
    $archivesBefore=(int)$pdo->query("SELECT COUNT(*) FROM ticket_archives WHERE ticket_id=$repairId")->fetchColumn();
    approval_decide([...$decision,'token'=>$rtoken]);
    $rt=ticket_admin($repairId);
    verify($rt['status']==='done' && $rt['completion_approval']['status']==='approved' && $rt['completion_approval_current'],'Post-close sign-off recorded and current');
    verify((int)$pdo->query("SELECT COUNT(*) FROM ticket_archives WHERE ticket_id=$repairId")->fetchColumn()===$archivesBefore+1,'Post-close signature queues a new archive version');
    rejects(fn()=>approval_decide([...$decision,'token'=>$rtoken]),409);
    rejects(fn()=>completion_request($repairId,[...$body,'resolution'=>'Fixture repair fixed'],$admin),409);
    rejects(fn()=>completion_request($repairId,[...$body,'resolution'=>'Edited after close'],$admin),409);
    // Rejection never changes repair status; a closed repair can be re-requested with the same result.
    $repair2=create_ticket([...$repairBody,'requester_signature_data'=>$signature],null);$repair2Id=(int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();
    update_ticket($repair2Id,['status'=>'done','resolution'=>'Second fixture fix'],$admin);
    $r2=completion_request($repair2Id,[...$body,'resolution'=>'Second fixture fix'],$admin);$r2token=json_decode(file_get_contents($capture),true)['token'];
    verify($r2['status']==='done' && $r2['completion_approval']['status']==='pending','Sign-off can be requested after closing');
    approval_decide([...$decision,'token'=>$r2token,'decision'=>'rejected','decision_note'=>'Fixture objection']);
    verify(ticket_admin($repair2Id)['status']==='done','Repair rejection keeps status');
    update_ticket($repair2Id,['status'=>'cancelled'],$admin);
    rejects(fn()=>completion_request($repair2Id,[...$body,'resolution'=>'Second fixture fix'],$admin),409);
    echo "PASS: $checks completion checks; temporary tables and loopback SMTP only.\n";
} finally {
    proc_terminate($process);foreach($pipes as $pipe)fclose($pipe);proc_close($process);unlink($capture);
}
