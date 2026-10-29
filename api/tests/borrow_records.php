<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
$pdo=db();
foreach(['it_documents','it_document_signatures','it_document_logs','admins','employees','tickets','ticket_logs','ticket_approvals','ticket_borrow_decisions','ticket_archives','ticket_requester_signatures','ticket_it_signatures','ticket_receipt_signatures','assets','maintenance_records'] as $table){
    $pdo->exec("CREATE TEMPORARY TABLE fixture_$table LIKE $table");$pdo->exec("ALTER TABLE fixture_$table RENAME TO $table");
}
$checks=0;
function verify(bool $value,string $message):void{global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
function rejects(callable $fn,int $status):void{try{$fn();}catch(HttpError $e){verify($e->status===$status,'Expected '.$status.', got '.$e->status.': '.$e->getMessage());return;}throw new RuntimeException('Expected HTTP '.$status);}
function submit_borrow(array $changes=[]):int{global $pdo,$request;$r=create_ticket(array_replace($request,$changes),null);$s=$pdo->prepare('SELECT id FROM tickets WHERE ticket_no=?');$s->execute([$r['ticket_no']]);return (int)$s->fetchColumn();}
// All writes are connection-local temporary tables. No email is sent.
$actor=['id'=>1,'display_name'=>'Fixture approving IT'];$other=['id'=>2,'display_name'=>'Other IT'];
$pdo->exec("INSERT INTO admins(id,username,password_hash,display_name) VALUES(1,'fixture','fixture','Fixture approving IT'),(2,'other','fixture','Other IT')");
$GLOBALS['ithub_config']['smtp']['enabled']=false;$GLOBALS['ithub_config']['public_submit_limit']=100;$_SERVER['REMOTE_ADDR']='127.0.0.20';
$request=['type'=>'borrow','requester_name'=>'Fixture borrower','requester_dept'=>'Fixture department','requester_email'=>'borrower@example.com',
    'description'=>'Fixture borrowing purpose','device_kind'=>'notebook','item'=>'Fixture notebook','quantity'=>2,'start_date'=>'2026-10-28','end_date'=>'2026-11-01'];
$id=submit_borrow(['status'=>'done','borrow_approval'=>['status'=>'approved'],'document_id'=>123]);$ticket=ticket_admin($id);
verify($ticket['status']==='new'&&$ticket['borrow_approval']['status']==='pending','Public submission cannot approve itself');
verify((int)$pdo->query('SELECT COUNT(*) FROM it_documents')->fetchColumn()===0,'Pending request is not a register entry');
verify((int)$pdo->query('SELECT COUNT(*) FROM ticket_borrow_decisions')->fetchColumn()===0,'No decision recorded before actual IT approval');
verify(borrow_pending_requests([])['total']===1,'Pending request appears in IT approval queue');
verify(ticket_public($ticket['ticket_no'])['borrow_approval_status']==='pending','Requester tracker shows waiting for IT');
foreach(['accepted','in_progress','waiting','done','rejected'] as $status)rejects(fn()=>update_ticket($id,['status'=>$status],$actor),422);
$ticket=update_ticket($id,['priority'=>'high','note'=>'Fixture review note','note_public'=>false],$actor);
verify($ticket['status']==='new'&&$ticket['borrow_approval']['status']==='pending','IT may triage without implicitly approving');
rejects(fn()=>document_prefill(['form_code'=>'FM-IT-008','ticket_id'=>$id]),409);
rejects(fn()=>document_save(null,['form_code'=>'FM-IT-008','title'=>'Bypass','document_date'=>'2026-10-28','company'=>'Fixture','data'=>[]],$actor),409);
rejects(fn()=>borrow_decide($id,['decision'=>'invented'],$actor),422);
rejects(fn()=>borrow_decide($id,['decision'=>'rejected'],$actor),422);
rejects(fn()=>submit_borrow(['start_date'=>'2026-02-31']),422);
rejects(fn()=>submit_borrow(['end_date'=>'2026-02-31']),422);
$pdo->prepare('UPDATE tickets SET assigned_to=2 WHERE id=?')->execute([$id]);
$ticket=borrow_decide($id,['decision'=>'approved','decision_note'=>'Fixture approved'],$actor);$docId=(int)$ticket['borrow_approval']['document_id'];$d=document_detail($docId);
verify($ticket['status']==='accepted'&&$ticket['borrow_approval']['status']==='approved','Explicit IT approval accepts request');
verify($docId>0&&(int)$pdo->query('SELECT COUNT(*) FROM it_documents')->fetchColumn()===1,'Approval creates exactly one FM008 register entry');
verify($d['status']==='in_progress'&&!$d['signatures'],'Register remains editable without fake document signatures');
verify($d['data']['rows'][0]['user']===$request['requester_name']&&$d['data']['rows'][0]['quantity']==='2','Approved borrower/item/quantity copied from server-side request');
verify($d['data']['rows'][0]['issuer']===$actor['display_name']&&$ticket['borrow_approval']['admin_name']===$actor['display_name'],'Actual approving admin retained, not assigned technician');
verify($d['borrow_source']['ticket_id']===$id&&$d['borrow_source']['admin_name']===$actor['display_name'],'Register links back to approval evidence');
verify(borrow_pending_requests([])['total']===0,'Approved request leaves pending queue');
$again=borrow_decide($id,['decision'=>'approved','decision_note'=>'Overwrite attempt'],$other);
verify($again['borrow_approval']['document_id']===$docId&&$again['borrow_approval']['admin_name']===$actor['display_name'],'Repeated click retains original document and approving actor');
verify((int)$pdo->query('SELECT COUNT(*) FROM it_documents')->fetchColumn()===1,'Repeated approval never duplicates the register');
rejects(fn()=>borrow_decide($id,['decision'=>'rejected','decision_note'=>'Change result'],$actor),409);
rejects(fn()=>document_copy($docId,$actor),409);
rejects(fn()=>document_prefill(['form_code'=>'FM-IT-008','ticket_id'=>$id]),409);
$bad=$d;$bad['data']['rows'][0]['quantity']='0';rejects(fn()=>document_save($docId,$bad,$actor),422);
$bad=$d;$bad['data']['rows'][0]['quantity']='3';rejects(fn()=>document_save($docId,$bad,$actor),409);
$bad=$d;$bad['data']['rows'][]=$bad['data']['rows'][0];rejects(fn()=>document_save($docId,$bad,$actor),409);
$bad=$d;$bad['data']['rows'][0]['user']='Another borrower';rejects(fn()=>document_save($docId,$bad,$actor),409);
$bad=$d;$bad['data']['fields']['end_date']='2026-11-02';rejects(fn()=>document_save($docId,$bad,$actor),409);
$bad=$d;$bad['data']['rows'][0]['returned_at']='2026-02-31';rejects(fn()=>document_save($docId,$bad,$actor),422);
$bad=$d;$bad['data']['rows'][0]['returned_at']='2026-10-30';rejects(fn()=>document_save($docId,$bad,$actor),422);
$bad=$d;$bad['data']['rows'][0]['receiver']='IT';rejects(fn()=>document_save($docId,$bad,$actor),422);
$bad=$d;$bad['data']['rows'][0]=array_replace($bad['data']['rows'][0],['returned_at'=>'2026-10-27','returner'=>'Fixture returner','receiver'=>'Fixture receiver','return_condition'=>'Normal']);rejects(fn()=>document_save($docId,$bad,$actor),422);
$d['data']['rows'][0]=array_replace($d['data']['rows'][0],['serial'=>'FIXTURE-SERIAL','returned_at'=>'2026-11-02','returner'=>'Fixture returner','receiver'=>'Fixture receiver','return_condition'=>'Normal']);
$before=$d;$d=document_save($docId,$d,$other);
verify($d['data']['rows'][0]['returned_at']==='2026-11-02','Actual return may be overdue and recorded by another IT admin');
verify($d['borrow_source']['admin_name']===$actor['display_name'],'Updating returns never overwrites original approval');
rejects(fn()=>document_save($docId,$before,$actor),409);
$list=document_list(['form_code'=>'FM-IT-008','q'=>'FIXTURE-SERIAL']);verify($list['total']===1&&$list['data'][0]['record_summary']===['outstanding'=>0,'returned'=>1],'Approved record searchable with accurate return summary');
verify(!isset($list['data'][0]['record_data'],$list['data'][0]['data']),'List does not disclose raw record payload');
rejects(fn()=>document_submit($docId,['version'=>$d['version']],$actor),409);
$ticket=update_ticket($id,['status'=>'done'],$actor);verify($ticket['status']==='done','Approved borrowing can progress and close');
verify(ticket_public($ticket['ticket_no'])['borrow_approval_status']==='approved','Tracker shows approved without disclosing register details');
verify(!isset(ticket_public($ticket['ticket_no'])['borrow_approval'],ticket_public($ticket['ticket_no'])['borrow_source']),'No private approval/register metadata in tracker');
// Rejection never adds a register row, and requires a reason.
$rejectedId=submit_borrow();$rejected=borrow_decide($rejectedId,['decision'=>'rejected','decision_note'=>'Fixture unavailable'],$other);
verify($rejected['status']==='rejected'&&$rejected['borrow_approval']['document_id']===null,'Rejected request has no register document');
verify((int)$pdo->query('SELECT COUNT(*) FROM it_documents')->fetchColumn()===1,'Rejection does not create a register entry');
verify(ticket_public($rejected['ticket_no'])['borrow_approval_status']==='rejected','Requester sees rejection');
rejects(fn()=>borrow_decide($rejectedId,['decision'=>'approved'],$actor),409);
$cancelId=submit_borrow();update_ticket($cancelId,['status'=>'cancelled'],$actor);rejects(fn()=>borrow_decide($cancelId,['decision'=>'approved'],$actor),409);
verify(borrow_pending_requests([])['total']===0,'Cancelled and rejected requests are absent from pending queue');
$otherType=create_ticket(['type'=>'repair','requester_name'=>'Fixture','requester_dept'=>'Fixture','equipment'=>'Fixture','description'=>'Fixture repair'],null);$otherId=(int)$pdo->query("SELECT id FROM tickets WHERE type='repair'")->fetchColumn();rejects(fn()=>borrow_decide($otherId,['decision'=>'approved'],$actor),422);
// A register write failure rolls back both ticket status and decision.
$rollbackId=submit_borrow();$pdo->prepare("INSERT INTO it_documents(document_no,form_code,title,document_date,company,data,source_key) VALUES('COLLISION','FM-IT-008','Fixture',CURRENT_DATE(),'Fixture','{}',?)")->execute(['borrow-ticket:'.$rollbackId]);
try{borrow_decide($rollbackId,['decision'=>'approved'],$actor);throw new RuntimeException('Expected duplicate source failure');}catch(PDOException $e){verify((int)$e->errorInfo[1]===1062,'Fixture forces document uniqueness failure');}
verify(ticket_admin($rollbackId)['status']==='new'&&ticket_admin($rollbackId)['borrow_approval']['status']==='pending','Creation failure leaves request pending, with no approval recorded');
$pdo->exec("DELETE FROM it_documents WHERE document_no='COLLISION'");$approvedAgain=borrow_decide($rollbackId,['decision'=>'approved'],$actor);
verify($approvedAgain['borrow_approval']['document_id']!==null,'Request can be approved successfully after failure is resolved');
verify((int)$pdo->query('SELECT COUNT(*) FROM it_document_signatures')->fetchColumn()===0,'IT approval creates no invented signature or email invitations');
// Several devices share one request/approval/document and return independently.
$items=[['device_kind'=>'notebook','item'=>'Fixture notebook A','quantity'=>2],['device_kind'=>'other','item'=>'Fixture projector B','quantity'=>3]];
foreach([[],null,'invalid',['named'=>$items[0]],array_fill(0,51,$items[0])] as $invalid)rejects(fn()=>submit_borrow(['borrow_items'=>$invalid]),422);
foreach([
    'not-a-row',[],['device_kind'=>'mixed','item'=>'Fixture','quantity'=>1],['device_kind'=>'other','item'=>'   ','quantity'=>1],
    ['device_kind'=>'other','item'=>str_repeat('A',151),'quantity'=>1],['device_kind'=>'other','item'=>'Fixture','quantity'=>0],
    ['device_kind'=>'other','item'=>'Fixture','quantity'=>101],['device_kind'=>'other','item'=>'Fixture','quantity'=>1.5],
] as $invalid)rejects(fn()=>submit_borrow(['borrow_items'=>[$items[0],$invalid]]),422);
try{submit_borrow(['borrow_items'=>[$items[0],['device_kind'=>'other','item'=>'','quantity'=>0]]]);throw new RuntimeException('Expected nested validation');}
catch(HttpError $e){verify($e->status===422&&isset($e->errors['borrow_items.1.item'],$e->errors['borrow_items.1.quantity']),'Validation errors identify the exact row and fields');}
$multiId=submit_borrow(['borrow_items'=>$items,'item'=>'Ignored old item','quantity'=>99]);$multi=ticket_admin($multiId);
verify($multi['details']['borrow_items']===$items&&$multi['details']['device_kind']==='mixed','All rows retained, with per-device type and quantity');
verify(str_contains($multi['title'],'Fixture notebook A')&&str_contains($multi['title'],'อีก 1 รายการ'),'Title summarizes multiple items without replacing full details');
$beforeCount=(int)$pdo->query('SELECT COUNT(*) FROM it_documents')->fetchColumn();
verify($multi['borrow_approval']['status']==='pending','Multiple devices still require explicit IT approval');
$multi=borrow_decide($multiId,['decision'=>'approved'],$actor);$multiDocId=(int)$multi['borrow_approval']['document_id'];$md=document_detail($multiDocId);
verify((int)$pdo->query('SELECT COUNT(*) FROM it_documents')->fetchColumn()===$beforeCount+1&&count($md['data']['rows'])===2,'One approval creates one document containing every item');
verify($md['data']['fields']['device_kind']==='mixed'&&$md['data']['rows'][0]['quantity']==='2'&&$md['data']['rows'][1]['quantity']==='3'&&$md['data']['rows'][1]['device_kind']==='other','Mixed device types and individual quantities reach FM008');
$repeated=borrow_decide($multiId,['decision'=>'approved'],$other);verify($repeated['borrow_approval']['document_id']===$multiDocId,'Multiple-item approval remains idempotent');
$bad=$md;$bad['data']['rows'][1]['quantity']='4';rejects(fn()=>document_save($multiDocId,$bad,$actor),409);
$bad=$md;$bad['data']['rows'][1]['device_kind']='notebook';rejects(fn()=>document_save($multiDocId,$bad,$actor),409);
$bad=$md;$bad['data']['rows']=array_reverse($bad['data']['rows']);rejects(fn()=>document_save($multiDocId,$bad,$actor),409);
$bad=$md;array_pop($bad['data']['rows']);rejects(fn()=>document_save($multiDocId,$bad,$actor),409);
$md['data']['rows'][0]=array_replace($md['data']['rows'][0],['returned_at'=>'2026-10-30','returner'=>'Fixture returner A','receiver'=>'Fixture IT A','return_condition'=>'Normal A','serial'=>'SERIAL-A']);
$md=document_save($multiDocId,$md,$actor);
verify($md['data']['rows'][1]['returned_at']===null&&$md['data']['rows'][0]['returned_at']==='2026-10-30','Returning one item does not mark the other as returned');
$list=document_list(['form_code'=>'FM-IT-008','q'=>$md['document_no']]);verify($list['data'][0]['record_summary']===['outstanding'=>1,'returned'=>1],'Mixed return state is summarized per item');
$md['data']['rows'][1]=array_replace($md['data']['rows'][1],['returned_at'=>'2026-11-02','returner'=>'Fixture returner B','receiver'=>'Fixture IT B','return_condition'=>'Normal B','serial'=>'SERIAL-B']);
$md=document_save($multiDocId,$md,$other);
verify($md['data']['rows'][0]['serial']==='SERIAL-A'&&$md['data']['rows'][1]['serial']==='SERIAL-B'&&$md['data']['rows'][0]['returned_at']==='2026-10-30','Later returns preserve earlier device details and dates');
$count=(int)$pdo->query('SELECT COUNT(*) FROM it_documents')->fetchColumn();$multiRejected=submit_borrow(['borrow_items'=>$items]);borrow_decide($multiRejected,['decision'=>'rejected','decision_note'=>'Fixture unavailable'],$actor);
verify((int)$pdo->query('SELECT COUNT(*) FROM it_documents')->fetchColumn()===$count,'Rejecting a multi-item request stores no register rows');
$maxId=submit_borrow(['borrow_items'=>array_fill(0,50,$items[0])]);$max=borrow_decide($maxId,['decision'=>'approved'],$actor);
verify(count(document_detail((int)$max['borrow_approval']['document_id'])['data']['rows'])===50,'All 50 allowed rows survive approval without truncation');
// Truly old stored requests with no list remain readable and approvable as one item.
$legacyId=submit_borrow();$s=$pdo->prepare('SELECT details FROM tickets WHERE id=?');$s->execute([$legacyId]);$legacy=json_decode($s->fetchColumn(),true);unset($legacy['borrow_items']);$encoded=json_encode($legacy,JSON_UNESCAPED_UNICODE);
$pdo->prepare('UPDATE tickets SET details=? WHERE id=?')->execute([$encoded,$legacyId]);
verify(count(ticket_admin($legacyId)['details']['borrow_items'])===1,'Legacy flat details are presented as one item');$s->execute([$legacyId]);verify($s->fetchColumn()===$encoded,'Reading a legacy request does not rewrite stored details');
$legacyApproved=borrow_decide($legacyId,['decision'=>'approved'],$actor);
verify(count(document_detail((int)$legacyApproved['borrow_approval']['document_id'])['data']['rows'])===1,'Legacy flat request still approves into one record row');
document_cancel($docId,$actor);rejects(fn()=>document_save($docId,$d,$actor),409);
echo "PASS: $checks borrow approval/register checks; temporary tables only, no email\n";
