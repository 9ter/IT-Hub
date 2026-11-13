<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
$pdo=db();
foreach(['it_documents','it_document_signatures','it_document_logs','admins','employees','tickets','ticket_logs','ticket_edit_requests','ticket_request_edits','ticket_approvals','ticket_borrow_decisions','ticket_archives','ticket_requester_signatures','ticket_it_signatures','ticket_receipt_signatures','assets','maintenance_records'] as $table){
    $pdo->exec("CREATE TEMPORARY TABLE fixture_$table LIKE $table");$pdo->exec("ALTER TABLE fixture_$table RENAME TO $table");
}
$checks=0;
function verify(bool $value,string $message):void{global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
function rejects(callable $fn,int $status):void{try{$fn();}catch(HttpError $e){verify($e->status===$status,'Expected '.$status.', got '.$e->status.': '.$e->getMessage());return;}throw new RuntimeException('Expected HTTP '.$status);}
function submit_borrow(array $changes=[]):int{global $pdo,$request;$r=create_ticket(array_replace($request,$changes),null);$s=$pdo->prepare('SELECT id FROM tickets WHERE ticket_no=?');$s->execute([$r['ticket_no']]);return (int)$s->fetchColumn();}
// All writes are connection-local temporary tables. No email is sent.
$actor=['id'=>1,'display_name'=>'Fixture IT'];
$pdo->exec("INSERT INTO admins(id,username,password_hash,display_name) VALUES(1,'fixture','fixture','Fixture IT')");
$GLOBALS['ithub_config']['smtp']['enabled']=false;$GLOBALS['ithub_config']['public_submit_limit']=100;$_SERVER['REMOTE_ADDR']='127.0.0.21';
$request=['type'=>'borrow','requester_name'=>'Fixture borrower','requester_dept'=>'Fixture dept','requester_email'=>'borrower@example.com',
    'description'=>'New engineer starts','borrow_items'=>[['device_kind'=>'notebook','item'=>'~notebook Mouse','quantity'=>1]],'start_date'=>'2026-11-10'];
$id=submit_borrow();$t=ticket_admin($id);
verify(is_string($t['request_edit_version']??null)&&!isset($t['request_edit_history']),'Pending borrow exposes edit version, no history yet');
$edit=['version'=>$t['request_edit_version'],'reason'=>'Split typo into two items','request'=>['borrow_items'=>[
    ['device_kind'=>'notebook','item'=>'Notebook','quantity'=>1],['device_kind'=>'other','item'=>'Mouse','quantity'=>1]],'end_date'=>'2026-12-31']];
rejects(fn()=>request_edit_admin($id,[...$edit,'reason'=>''],$actor),422);
rejects(fn()=>request_edit_admin($id,[...$edit,'request'=>['borrow_items'=>[]]],$actor),422);
rejects(fn()=>request_edit_admin($id,[...$edit,'request'=>['borrow_items'=>[['device_kind'=>'notebook','item'=>'','quantity'=>1]]]],$actor),422);
rejects(fn()=>request_edit_admin($id,[...$edit,'request'=>['borrow_items'=>[['device_kind'=>'notebook','item'=>'X','quantity'=>101]]]],$actor),422);
rejects(fn()=>request_edit_admin($id,[...$edit,'request'=>['end_date'=>'2026-11-01']],$actor),422);
rejects(fn()=>request_edit_admin($id,[...$edit,'request'=>['start_date'=>'2026-02-31']],$actor),422);
rejects(fn()=>request_edit_admin($id,[...$edit,'request'=>['requester_name'=>'Forged']],$actor),422);
rejects(fn()=>request_edit_admin($id,[...$edit,'request'=>['description'=>'New engineer starts']],$actor),422);
verify(ticket_admin($id)['title']==='เบิก-ยืม: ~notebook Mouse x1'&&!isset(ticket_admin($id)['request_edit_history']),'Rejected edits change nothing');
rejects(fn()=>request_edit_return($id,['version'=>$t['request_edit_version'],'reason'=>'x'],$actor),422);
$t=request_edit_admin($id,$edit,$actor);
verify(count($t['details']['borrow_items'])===2&&$t['details']['borrow_items'][1]['item']==='Mouse'&&$t['details']['device_kind']==='mixed','Items edited and kind recomputed');
verify($t['details']['end_date']==='2026-12-31'&&$t['details']['start_date']==='2026-11-10'&&$t['description']==='New engineer starts','Untouched fields carry over');
verify($t['title']==='เบิก-ยืม: Notebook x1 และอีก 1 รายการ'&&$t['status']==='new','Title updated, status unchanged');
verify(count($t['request_edit_history'])===1&&$t['request_edit_history'][0]['admin_id']===1&&json_decode($t['request_edit_history'][0]['before_snapshot'],true)['request']['borrow_items'][0]['item']==='~notebook Mouse','Audit keeps before/after and actor');
verify(ticket_public($t['ticket_no'])['borrow_approval_status']==='pending','Still pending for approval');
rejects(fn()=>request_edit_admin($id,$edit,$actor),409);
// Approval creates the register from the corrected data; afterwards editing is closed.
$t=borrow_decide($id,['decision'=>'approved'],$actor);
$doc=document_detail((int)$t['borrow_approval']['document_id']);
verify(count($doc['data']['rows'])===2&&$doc['data']['rows'][1]['item']==='Mouse'&&$doc['data']['fields']['end_date']==='2026-12-31','Register built from edited request');
verify(!isset($t['request_edit_version'])&&count($t['request_edit_history'])===1,'No edit after approval; history kept');
rejects(fn()=>request_edit_admin($id,[...$edit,'version'=>'x'],$actor),409);
// Legacy single-item request (no borrow_items list) edits cleanly.
$legacy=submit_borrow();
$pdo->prepare('UPDATE tickets SET details=? WHERE id=?')->execute([json_encode(['device_kind'=>'notebook','item'=>'Old notebook','quantity'=>2,'start_date'=>'2026-11-10','end_date'=>null]),$legacy]);
$t=ticket_admin($legacy);
rejects(fn()=>request_edit_admin($legacy,['version'=>$t['request_edit_version'],'reason'=>'same','request'=>['borrow_items'=>[['device_kind'=>'notebook','item'=>'Old notebook','quantity'=>2]]]],$actor),422);
$t=request_edit_admin($legacy,['version'=>$t['request_edit_version'],'reason'=>'qty','request'=>['borrow_items'=>[['device_kind'=>'notebook','item'=>'Old notebook','quantity'=>3]]]],$actor);
verify($t['details']['quantity']===3&&$t['details']['borrow_items'][0]['quantity']===3,'Legacy request edited');
// Rejected and cancelled requests are closed for editing.
$rejected=submit_borrow();$t=ticket_admin($rejected);borrow_decide($rejected,['decision'=>'rejected','decision_note'=>'No stock'],$actor);
rejects(fn()=>request_edit_admin($rejected,[...$edit,'version'=>$t['request_edit_version']],$actor),409);
echo "borrow request edits: $checks checks passed\n";
