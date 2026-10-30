<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/admin.php';
$pdo=db();
foreach(['employees','tickets','ticket_logs','ticket_approvals','ticket_requester_signatures','ticket_it_signatures','ticket_receipt_signatures','ticket_borrow_decisions','ticket_archives','it_documents','maintenance_records','admins','assets'] as $table){$pdo->exec("CREATE TEMPORARY TABLE fixture_$table LIKE $table");$pdo->exec("ALTER TABLE fixture_$table RENAME TO $table");}
$checks=0;
function verify(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
function rejects(callable $fn,int $status):void{try{$fn();}catch(HttpError $e){verify($e->status===$status,'Expected '.$status.', got '.$e->status.': '.$e->getMessage());return;}throw new RuntimeException('Expected HTTP '.$status);}
function drawing(bool $blank=false):string{$im=imagecreatetruecolor(600,200);imagefilledrectangle($im,0,0,599,199,imagecolorallocate($im,255,255,255));if(!$blank){imagesetthickness($im,4);$ink=imagecolorallocate($im,15,23,42);imageline($im,40,140,190,30,$ink);imageline($im,190,30,360,150,$ink);imageline($im,360,150,520,50,$ink);}ob_start();imagepng($im);return 'data:image/png;base64,'.base64_encode(ob_get_clean());}
$capture=tempnam(sys_get_temp_dir(),'ithub-purchase-test-');$process=proc_open(['python3',__DIR__.'/smtp_fixture.py',$capture],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
try{
    $port=(int)fgets($pipes[1]);verify($port>0,'Loopback SMTP starts');
    $GLOBALS['ithub_config']['smtp']=['enabled'=>true,'host'=>'127.0.0.1','port'=>$port,'encryption'=>'','username'=>'fixture','password'=>'fixture','from_email'=>'it-hub@example.com'];
    $_SERVER['REMOTE_ADDR']='127.0.0.20';$png=drawing();$actor=['id'=>1,'display_name'=>'Fixture IT'];
    $pdo->prepare("INSERT INTO admins(id,username,password_hash,display_name,signature_data) VALUES(1,'fixture','fixture','Fixture IT',?)")->execute([$png]);
    employee_save(null,['full_name'=>'Fixture Head','email'=>'head@example.com','company'=>'Fixture Company','department'=>'Fixture Department','can_approve'=>true]);$head=(int)$pdo->query('SELECT MAX(id) FROM employees')->fetchColumn();
    $body=['type'=>'purchase','requester_name'=>'Fixture Requester','requester_dept'=>'Fixture Department','requester_email'=>'requester@example.com','position'=>'Fixture Position','description'=>'Fixture purchase purpose','urgent'=>true,'need_by'=>'2026-11-15','items'=>['notebook','monitor'],'spec_cpu'=>'Fixture CPU','spec_ram'=>'32 GB','spec_storage'=>'1 TB','spec_display'=>'27 inches','spec_other'=>'Fixture specification','requester_signature_data'=>$png,'approver_id'=>$head,'approver_name'=>'Forged display name','asset_code'=>'NOT-A-REAL-ASSET','location'=>'Must not copy QR'];
    $bad=$body;unset($bad['approver_id']);rejects(fn()=>create_ticket($bad,null),422);
    $bad=$body;unset($bad['requester_signature_data']);rejects(fn()=>create_ticket($bad,null),422);
    rejects(fn()=>create_ticket([...$body,'requester_signature_data'=>drawing(true)],null),422);
    rejects(fn()=>create_ticket([...$body,'approver_id'=>999999],null),422);
    employee_save($head,['full_name'=>'Fixture Head','email'=>'head@example.com','company'=>'Fixture Company','can_approve'=>false]);rejects(fn()=>create_ticket($body,null),422);
    employee_save($head,['full_name'=>'Fixture Head','email'=>'head@example.com','company'=>'Fixture Company','can_approve'=>true]);
    verify((int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn()===0,'Invalid submissions create no tickets');
    verify((int)$pdo->query('SELECT COUNT(*) FROM ticket_requester_signatures')->fetchColumn()===0,'Invalid submissions create no signatures');
    $created=create_ticket($body,null);$id=(int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();$t=ticket_admin($id);
    verify(str_starts_with($created['ticket_no'],'PR-')&&$created['mail_status']==='sent','Purchase uses PR number and sends approval mail');
    verify($t['requester_signature']['requester_name']==='Fixture Requester'&&$t['requester_signature']['signature_data']===$png&&$t['requester_signature']['signed_at']!==null,'Requester name/image/server timestamp snapshotted');
    verify(!isset($t['requester_signature']['signed_ip']),'Requester IP excluded from payload');
    verify($t['details']['approver_name']==='Fixture Head'&&$t['approval']['approver_email']==='head@example.com','Recipient identity comes from directory');
    verify($t['asset_id']===null&&$t['asset_code']===null&&$t['location']===null,'Purchase never acquires QR context');
    verify($t['priority']==='high'&&$t['details']['need_by']===$body['need_by'],'Urgency and date preserved');
    verify($t['print_form']['code']==='FM-IT-007'&&$t['print_form']['orientation']==='portrait'&&str_contains($t['print_form']['rev'],'Rev.03'),'Direct print receives FM007 source template');
    verify(count($t['print_form']['roles'])===6&&$t['print_form']['roles'][1]['label']===ticket_approval_role('purchase'),'Source signature slots and specific head role supplied');
    $mail=json_decode(file_get_contents($capture),true);$token=$mail['token'];
    verify(str_contains($mail['body'],'FM-IT-007 Rev.03')&&!str_contains($mail['body'],'FM-IT-010'),'Email names correct form');
    foreach(['Fixture CPU','32 GB','1 TB','27 inches','Fixture specification','2026-11-15','Fixture purchase purpose','ผู้มีอำนาจอนุมัติต้นสังกัด'] as $expected)verify(str_contains($mail['body'],$expected),'Email includes purchase context '.$expected);
    verify(str_contains($mail['to'],'head@example.com')&&str_contains($mail['from'],'it-hub@example.com'),'Correct sender and chosen recipient');
    $s=$pdo->prepare('SELECT token_hash FROM ticket_approvals WHERE ticket_id=?');$s->execute([$id]);verify($s->fetchColumn()===hash('sha256',$token),'Only hashed token stored');
    $view=approval_view(['token'=>$token]);verify($view['type']==='purchase'&&json_decode($view['details'],true)['spec_ram']==='32 GB','Reviewer receives purchase type and complete specification');
    $public=ticket_public($created['ticket_no']);verify($public['approval_status']==='pending'&&!isset($public['requester_signature'],$public['approval'],$public['print_form']),'Public tracking hides signature/contact/print data');
    verify(!isset(ticket_list([])['data'][0]['requester_signature']),'Lists omit signature');
    foreach(['accepted','in_progress','done'] as $status)rejects(fn()=>update_ticket($id,['status'=>$status],$actor),422);
    // The existing E-Procurement confirmation path also applies to purchase approvals.
    $pdo->exec('CREATE TEMPORARY TABLE purchase_users(id INT,email VARCHAR(150),signature_data MEDIUMTEXT,is_active INT,deleted_at DATETIME NULL)');$pdo->exec('CREATE TEMPORARY TABLE purchase_user_companies(user_id INT,company_id INT)');$pdo->exec('CREATE TEMPORARY TABLE purchase_companies(id INT,is_active INT,deleted_at DATETIME NULL)');
    $pdo->exec('INSERT INTO purchase_companies VALUES(10,1,NULL)');$pdo->exec('INSERT INTO purchase_user_companies VALUES(100,10)');$pdo->prepare('INSERT INTO purchase_users VALUES(100,\'head@example.com\',?,1,NULL)')->execute([$png]);$pdo->prepare('UPDATE employees SET source_user_id=100,source_company_id=10 WHERE id=?')->execute([$head]);
    $view=approval_view(['token'=>$token],$pdo);verify($view['saved_signature_data']===$png,'Assigned approver sees verified source signature');
    $decision=['token'=>$token,'decision'=>'approved','consent'=>true,'use_saved_signature'=>true,'saved_signature_hash'=>$view['saved_signature_hash']];
    rejects(fn()=>approval_decide([...$decision,'consent'=>false],$pdo),422);rejects(fn()=>approval_decide([...$decision,'saved_signature_hash'=>str_repeat('0',64)],$pdo),409);
    approval_decide($decision,$pdo);$t=ticket_admin($id);verify($t['approval']['status']==='approved'&&$t['approval']['signature_data']===$png,'Approval signature stored only on explicit confirmation');
    rejects(fn()=>approval_decide($decision,$pdo),409);
    $t=update_ticket($id,['status'=>'in_progress'],$actor);verify($t['status']==='in_progress','IT may start after approval');
    $t=update_ticket($id,['status'=>'done','resolution'=>'Fixture completed purchase'],$actor);verify($t['status']==='done'&&!$t['completion_required'],'No additional completion gate added to purchase');
    verify($t['it_signature']['signature_data']===$png,'Existing IT snapshot available for receiver print slot');
    employee_save($head,['full_name'=>'Renamed head','email'=>'head@example.com','company'=>'Fixture Company','can_approve'=>true]);verify(ticket_admin($id)['approval']['approver_name']==='Fixture Head','Later directory edits cannot change signed identity');
    $r=create_ticket($body,null);$rid=(int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();$rejectToken=json_decode(file_get_contents($capture),true)['token'];
    rejects(fn()=>approval_decide(['token'=>$rejectToken,'decision'=>'rejected','consent'=>true,'signature_data'=>$png]),422);
    approval_decide(['token'=>$rejectToken,'decision'=>'rejected','decision_note'=>'Fixture rejected','consent'=>true,'signature_data'=>$png]);verify(ticket_admin($rid)['status']==='rejected','Rejection closes request with signed reason');
    $GLOBALS['ithub_config']['smtp']['enabled']=false;$r=create_ticket($body,null);$rid=(int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();verify($r['mail_status']==='failed'&&ticket_admin($rid)['requester_signature']['signature_data']===$png,'SMTP failure retains ticket and requester signature for retry');
    $pdo->exec("INSERT INTO tickets(ticket_no,type,title,requester_name,details) VALUES('PR-LEGACY-001','purchase','Legacy purchase','Legacy Requester','{\"items\":[\"notebook\"]}')");$legacy=ticket_admin((int)$pdo->lastInsertId());verify($legacy['approval']===null&&$legacy['requester_signature']===null&&$legacy['print_form']['code']==='FM-IT-007','Legacy purchase prints source form without fabricated signatures');
    echo "PASS: $checks purchase approval checks; temporary tables and loopback SMTP only\n";
}finally{proc_terminate($process);foreach($pipes as $pipe)fclose($pipe);proc_close($process);@unlink($capture);}
