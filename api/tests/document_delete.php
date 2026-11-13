<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
function fixture_signature():string{$im=imagecreatetruecolor(600,200);imagefilledrectangle($im,0,0,599,199,imagecolorallocate($im,255,255,255));imagesetthickness($im,3);$ink=imagecolorallocate($im,15,23,42);imageline($im,40,150,180,30,$ink);imageline($im,180,30,330,140,$ink);ob_start();imagepng($im);$d=ob_get_clean();imagedestroy($im);return 'data:image/png;base64,'.base64_encode($d);}
$pdo=db();
foreach(['it_documents','it_document_signatures','it_document_logs','admins','employees','tickets','ticket_logs','ticket_edit_requests','ticket_request_edits','ticket_approvals','ticket_borrow_decisions','ticket_archives','ticket_requester_signatures','ticket_it_signatures','ticket_receipt_signatures','assets','maintenance_records'] as $table){
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
$id=submit_borrow();$ticket=borrow_decide($id,['decision'=>'approved'],$actor);$docId=(int)$ticket['borrow_approval']['document_id'];
$id2=submit_borrow(['item'=>'Second notebook']);$doc2=(int)borrow_decide($id2,['decision'=>'approved'],$actor)['borrow_approval']['document_id'];
$plain=document_detail($docId);
verify(!array_key_exists('deleted',$plain)&&!array_key_exists('deleted_at',$plain)&&!array_key_exists('delete_reason',$plain),'Live document payload has no delete columns (archive hash stays stable)');
$snapBefore=json_encode(archive_snapshot($id),JSON_UNESCAPED_UNICODE);
rejects(fn()=>document_delete($docId,['reason'=>''],$actor),422);
rejects(fn()=>document_delete($docId,['reason'=>'Active record'],$actor),409);
verify(document_list(['form_code'=>'FM-IT-008'])['total']===2,'Active record cannot be deleted while in use');
document_cancel($docId,$actor);
rejects(fn()=>document_delete(999999,['reason'=>'Missing'],$actor),404);
$r=document_delete($docId,['reason'=>'Test cleanup'],$actor);
verify($r['deleted']===true,'Cancelled record deleted');
$list=document_list(['form_code'=>'FM-IT-008']);
verify($list['total']===1&&(int)$list['data'][0]['id']===$doc2,'Deleted record hidden from default list');
verify(document_list(['form_code'=>'FM-IT-008','status'=>'cancelled'])['total']===0,'Deleted record hidden from cancelled filter');
$del=document_list(['form_code'=>'FM-IT-008','status'=>'deleted']);
verify($del['total']===1&&(int)$del['data'][0]['id']===$docId&&$del['data'][0]['delete_reason']==='Test cleanup'&&$del['data'][0]['deleted_by_name']===$actor['display_name'],'Deleted tab lists record with reason and actor');
verify(document_list(['status'=>'deleted','q'=>'nothing-matches-this'])['total']===0,'Search applies inside deleted tab');
$items=borrow_items(['documents'=>'all']);
verify(!in_array($docId,array_column($items['data'],'document_id'),true)&&$items['cancelled_documents']===0,'Combined items view ignores deleted records');
$view=document_detail($docId);
verify(($view['deleted']['reason']??null)==='Test cleanup'&&$view['deleted']['by_name']===$actor['display_name'],'Detail still opens deleted record with delete info');
rejects(fn()=>document_save($docId,[...$view,'title'=>'Edit deleted'],$actor),404);
rejects(fn()=>document_cancel($docId,$actor),404);
rejects(fn()=>document_delete($docId,['reason'=>'Twice'],$actor),404);
$snap=archive_snapshot($id);
verify(!isset($snap['document']['deleted'])&&$snap['document']['id']===$docId,'Ticket archive snapshot still builds and omits delete info');
verify((int)$pdo->query("SELECT COUNT(*) FROM ticket_borrow_decisions WHERE document_id=$docId")->fetchColumn()===1,'Borrow decision reference kept');
verify(ticket_admin($id)['borrow_approval']['document_id']===$docId,'Ticket still links the record');
$log=$pdo->query("SELECT note FROM it_document_logs WHERE document_id=$docId ORDER BY id DESC LIMIT 1")->fetchColumn();
verify($log==='ลบบันทึก: Test cleanup','Delete logged with reason');
rejects(fn()=>document_restore($doc2,$actor),404);
verify(document_restore($docId,$actor)['restored']===true,'Restore works');
$back=document_detail($docId);
verify(!isset($back['deleted'])&&$back['status']==='cancelled','Restored record is back as cancelled');
verify(document_list(['form_code'=>'FM-IT-008','status'=>'deleted'])['total']===0&&document_list(['form_code'=>'FM-IT-008'])['total']===2,'Restored record returns to list');
rejects(fn()=>document_restore($docId,$actor),404);
$pdo->exec("INSERT INTO it_documents(document_no,form_code,title,document_date,company,status,data,created_by) VALUES('IT-011-X','FM-IT-011','Other','2026-11-09','Fixture','cancelled','{\"fields\":{},\"rows\":[],\"signers\":{}}',1)");
rejects(fn()=>document_delete((int)$pdo->lastInsertId(),['reason'=>'Other form'],$actor),409);
echo "document_delete: {$checks} checks passed\n";
