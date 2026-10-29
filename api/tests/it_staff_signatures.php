<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/admin.php';
$pdo=db();
foreach(['admins','tickets','ticket_logs','ticket_approvals','ticket_requester_signatures','ticket_it_signatures','ticket_receipt_signatures','ticket_borrow_decisions','ticket_archives','it_documents','maintenance_records','assets'] as $table){
    $pdo->exec("CREATE TEMPORARY TABLE `fixture_$table` LIKE `$table`");
    $pdo->exec("ALTER TABLE `fixture_$table` RENAME TO `$table`");
}
$checks=0;
function check(bool $value,string $message):void{global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
function rejects(callable $fn,int $status):void{try{$fn();}catch(HttpError $e){check($e->status===$status,'Unexpected status '.$e->status);return;}throw new RuntimeException('Expected rejection '.$status);}
function drawing(bool $blank=false,bool $variant=false):string{
    $im=imagecreatetruecolor(600,200);imagefilledrectangle($im,0,0,599,199,imagecolorallocate($im,255,255,255));
    if(!$blank){imagesetthickness($im,3);$ink=imagecolorallocate($im,15,23,42);imageline($im,40,150,180,30,$ink);imageline($im,180,30,330,140,$ink);if($variant)imageline($im,330,140,520,50,$ink);}
    ob_start();imagepng($im);$data=ob_get_clean();imagedestroy($im);return 'data:image/png;base64,'.base64_encode($data);
}
$pdo->exec("INSERT INTO admins (id,username,password_hash,display_name) VALUES (1,'fixture-a','fixture-only','Fixture IT One'),(2,'fixture-b','fixture-only','Fixture IT Two')");
$_SERVER['REMOTE_ADDR']='127.0.0.4';$one=['id'=>1,'display_name'=>'Untrusted caller name'];$two=['id'=>2,'display_name'=>'Fixture IT Two'];$sig=drawing();$newSig=drawing(false,true);
rejects(fn()=>admin_signature_save(1,['signature_data'=>'']),422);
rejects(fn()=>admin_signature_save(1,['signature_data'=>drawing(true)]),422);
rejects(fn()=>admin_signature_save(1,['signature_data'=>'data:image/svg+xml;base64,PHN2Zy8+']),422);
rejects(fn()=>admin_signature_save(999,['signature_data'=>$sig]),404);
$list=admin_signature_save(1,['signature_data'=>$sig]);
$profile=array_values(array_filter($list,fn($a)=>$a['id']===1))[0];
check($profile['signature_data']===$sig && $profile['signature_updated_at']!==null,'Profile signature and server time saved');
check(!isset($profile['password_hash']),'Admin list never includes password hash');
admin_signature_save(2,['signature_data'=>$newSig]);
$body=['type'=>'repair','requester_name'=>'Fixture Requester','requester_dept'=>'Fixture Department','equipment'=>'Fixture Device','description'=>'Fixture repair'];
$create=create_ticket($body,null);$id=(int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();
rejects(fn()=>it_ticket_sign($id,$one),422);
update_ticket($id,['resolution'=>'Fixture work result'],$one);
$signed=it_ticket_sign($id,$one)['it_signature'];
check($signed['signer_name']==='Fixture IT One' && $signed['signature_data']===$sig && $signed['admin_id']===1,'Explicit signing uses authenticated actor profile, not caller name or assignee');
check($signed['signed_at']!==null && !isset($signed['signed_ip']),'Private signature payload has server time and excludes IP');
rejects(fn()=>it_ticket_sign($id,$two),409);
$pdo->exec("UPDATE admins SET display_name='Updated Fixture Name' WHERE id=1");admin_signature_save(1,['signature_data'=>$newSig]);
check(ticket_admin($id)['it_signature']===$signed,'Changing profile name/signature leaves signed document unchanged');
admin_signature_delete(1);
check(ticket_admin($id)['it_signature']===$signed,'Deleting profile signature preserves document evidence');
update_ticket($id,['assigned_to'=>1],$one);
$done=update_ticket($id,['status'=>'done'],$two);
check($done['it_signature']===$signed,'Another admin closing does not replace existing IT signer');
check(!isset(ticket_public($create['ticket_no'])['it_signature']),'Public tracker does not expose IT signature');
check(!isset(ticket_list([])['data'][0]['it_signature']) && !isset(dashboard()['queue'][0]['it_signature']),'Lists and dashboard have no signature image');
$reopened=update_ticket($id,['status'=>'in_progress'],$two);
check($reopened['it_signature']===null,'Reopen hides old signature for a new work revision');
$done=update_ticket($id,['status'=>'done'],$two);
check($done['it_signature']['signer_name']==='Fixture IT Two' && $done['it_signature']['signature_data']===$newSig,'Close automatically snapshots actual acting admin');
check($done['assigned_to']===1 && $done['it_signature']['admin_id']===2,'Never borrow the assigned admin signature when another admin performs the action');
check((int)$pdo->query('SELECT COUNT(*) FROM ticket_it_signatures')->fetchColumn()===2,'Historical IT signatures kept across revisions');
$another=create_ticket($body,null);$id2=(int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();
update_ticket($id2,['resolution'=>'Another fixture result'],$one);
rejects(fn()=>it_ticket_sign($id2,$one),422);
$old=update_ticket($id2,['status'=>'done'],$one);
check($old['it_signature']===null,'Missing profile signature does not invent an image or change legacy close behavior');
admin_signature_save(1,['signature_data'=>$sig]);
check(it_ticket_sign($id2,$one)['it_signature']['signature_data']===$sig,'Existing completed work can explicitly add own signature after registration');
rejects(fn()=>admin_signature_delete(999),404);
check((int)$pdo->query('SELECT COUNT(*) FROM ticket_it_signatures')->fetchColumn()===3,'Explicit signature never creates extra revisions');
echo "PASS: $checks IT signature checks; temporary tables only; no email sent.\n";
