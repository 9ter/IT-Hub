<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/assets.php';
$pdo=db();
foreach(['it_documents','it_document_signatures','it_document_logs','employees','admins','assets','asset_categories','tickets','ticket_logs','ticket_approvals','ticket_requester_signatures','ticket_it_signatures','ticket_receipt_signatures','ticket_borrow_decisions','ticket_archives','maintenance_records'] as $table){
    $pdo->exec("CREATE TEMPORARY TABLE fixture_$table LIKE $table");$pdo->exec("ALTER TABLE fixture_$table RENAME TO $table");
}
$checks=0;
function verify(bool $v,string $message):void{global $checks;if(!$v)throw new RuntimeException($message);$checks++;}
function rejects(callable $fn,int $status):void{try{$fn();}catch(HttpError $e){verify($e->status===$status,'HTTP expected '.$status.' got '.$e->status.': '.$e->getMessage());return;}throw new RuntimeException('Expected HTTP '.$status);}
function signature_fixture():string{$im=imagecreatetruecolor(600,200);imagefilledrectangle($im,0,0,599,199,imagecolorallocate($im,255,255,255));imagesetthickness($im,4);$ink=imagecolorallocate($im,15,23,42);imageline($im,30,160,170,30,$ink);imageline($im,170,30,350,150,$ink);imageline($im,350,150,520,50,$ink);ob_start();imagepng($im);$png=ob_get_clean();return 'data:image/png;base64,'.base64_encode($png);}
function token_fixture(string $capture):string{$mail=json_decode(file_get_contents($capture),true);preg_match('/\/document-approve#([a-f0-9]{64})/',$mail['body'],$m);return $m[1]??throw new RuntimeException('No document token in local sink');}
$capture=tempnam(sys_get_temp_dir(),'ithub-documents-test-');
$process=proc_open(['python3',__DIR__.'/smtp_fixture.py',$capture],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
try{
    $port=(int)fgets($pipes[1]);verify($port>0,'SMTP local sink starts');
    $GLOBALS['ithub_config']['smtp']=['enabled'=>true,'host'=>'127.0.0.1','port'=>$port,'encryption'=>'','username'=>'fixture','password'=>'fixture','from_email'=>'it-hub@example.com'];
    $_SERVER['REMOTE_ADDR']='127.0.0.10';$png=signature_fixture();$actor=['id'=>1,'display_name'=>'Fixture IT'];
    $pdo->prepare("INSERT INTO admins(id,username,password_hash,display_name,signature_data) VALUES(1,'fixture','fixture','Fixture IT',?)")->execute([$png]);
    $pdo->exec("INSERT INTO admins(id,username,password_hash,display_name) VALUES(2,'fixture2','fixture','Other IT')");
    $pdo->exec("INSERT INTO employees(id,full_name,email,company,is_active,can_approve) VALUES (1,'Fixture Requester','requester@example.com','Fixture',1,0),(2,'Fixture Head','head@example.com','Fixture',1,1),(3,'Fixture Director','director@example.com','Fixture',1,1)");
    $base=['form_code'=>'FM-IT-009','title'=>'Fixture email document','document_date'=>'2026-10-28','company'=>'Fixture Company',
        'data'=>['fields'=>['name_th'=>'Fixture Requester','name_en'=>'Fixture Requester','position'=>'Fixture','department'=>'Fixture','email_company'=>'ats','purpose'=>'Fixture purpose'],
        'rows'=>[],'signers'=>['requester'=>['id'=>1],'head'=>['id'=>2],'manager'=>['id'=>3],'it'=>['id'=>1],'director'=>['id'=>3]]]];
    verify(count(document_forms())===12,'Catalog covers 001–011 plus INNO');
    rejects(fn()=>document_save(null,[...$base,'form_code'=>'FM-IT-099'],$actor),422);
    rejects(fn()=>document_save(null,[...$base,'document_date'=>'2026-02-31'],$actor),422);
    $d=document_save(null,$base,$actor);$id=(int)$d['id'];
    verify($d['status']==='draft'&&!$d['signatures'],'Draft never creates or fakes a signature');
    verify(!isset($d['data']['fields']['password']),'No temporary email password storage');
    rejects(fn()=>document_submit($id,['version'=>999],$actor),409);
    $d=document_submit($id,['version'=>$d['version']],$actor);
    verify($d['status']==='request_review'&&count($d['signatures'])===3,'Three request signature roles from email template');
    $token=token_fixture($capture);$view=document_approval_view(['token'=>$token]);
    verify($view['role_label']==='ผู้ขอใช้'&&$view['document']['data']['fields']['purpose']==='Fixture purpose','Full current document shown to correct token holder');
    verify(!isset($view['document']['data']['signers'],$view['document']['signatures'][0]['signer_email']),'Reviewer payload excludes contact roster and mail internals');
    $pdo->exec('CREATE TEMPORARY TABLE purchase_users (id INT,email VARCHAR(150),is_active INT,deleted_at DATETIME NULL,signature_data MEDIUMTEXT)');
    $pdo->exec('CREATE TEMPORARY TABLE purchase_user_companies (user_id INT,company_id INT)');
    $pdo->exec('CREATE TEMPORARY TABLE purchase_companies (id INT,is_active INT,deleted_at DATETIME NULL)');
    $pdo->exec('INSERT INTO purchase_companies VALUES(10,1,NULL)');$pdo->exec('INSERT INTO purchase_user_companies VALUES(100,10)');
    $pdo->prepare('INSERT INTO purchase_users VALUES(100,\'requester@example.com\',1,NULL,?)')->execute([$png]);
    $pdo->exec('UPDATE employees SET source_user_id=100,source_company_id=10 WHERE id=1');
    verify(employee_saved_signature(1,'requester@example.com',$pdo)===null,'Existing ticket helper still requires approver privilege by default');
    $saved=document_approval_view(['token'=>$token],$pdo);
    verify($saved['saved_signature_data']===$png&&$saved['saved_signature_hash']===hash('sha256',$png),'Assigned requester may explicitly use their own verified E-Procurement signature');
    rejects(fn()=>document_decide(['token'=>$token,'decision'=>'approved','consent'=>true,'use_saved_signature'=>true,'saved_signature_hash'=>str_repeat('0',64)],null,null,$pdo),409);
    rejects(fn()=>document_decide(['token'=>$token,'decision'=>'approved','signature_data'=>$png,'consent'=>false]),422);
    rejects(fn()=>document_save($id,$d,$actor),409);
    rejects(fn()=>document_decide(['decision'=>'approved','consent'=>true],$actor,$id),403);
    $approve=['decision'=>'approved','signature_data'=>$png,'consent'=>true];
    document_decide(['token'=>$token,'decision'=>'approved','consent'=>true,'use_saved_signature'=>true,'saved_signature_hash'=>$saved['saved_signature_hash']],null,null,$pdo);
    verify(document_detail($id)['signatures'][0]['signature_data']===$png,'Confirmed saved requester signature snapshotted with the document');
    rejects(fn()=>document_decide([...$approve,'token'=>$token]),409);
    $head=token_fixture($capture);verify($head!==$token,'Next role receives a distinct token');
    $pdo->prepare('UPDATE it_document_signatures SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE token_hash=?')->execute([hash('sha256',$head)]);
    rejects(fn()=>document_approval_view(['token'=>$head]),410);
    document_send_pending($id,true);$headNew=token_fixture($capture);
    rejects(fn()=>document_approval_view(['token'=>$head]),404);
    rejects(fn()=>document_send_pending($id,true),429);
    document_decide([...$approve,'token'=>$headNew]);$manager=token_fixture($capture);
    document_decide([...$approve,'token'=>$manager]);$d=document_detail($id);
    verify($d['status']==='in_progress','Head and department manager must sign before IT result');
    $changed=$d;$changed['data']['fields']['purpose']='Altered signed request';rejects(fn()=>document_save($id,$changed,$actor),409);
    rejects(fn()=>document_submit($id,['version'=>$d['version']],$actor),422);
    $d['data']['fields']['assigned_email']='fixture.r@atthasan.com';$d['data']['fields']['result']='Created fixture account';
    $d=document_save($id,$d,$actor);$old=$d;
    $d=document_save($id,$d,$actor);rejects(fn()=>document_save($id,$old,$actor),409);
    $d=document_submit($id,['version'=>$d['version']],$actor);
    verify($d['status']==='completion_review'&&$d['signatures'][3]['status']==='pending','IT signature becomes active before director');
    rejects(fn()=>document_decide([...$approve],['id'=>2],$id),403);
    $d=document_decide(['decision'=>'approved','consent'=>true],$actor,$id);
    verify($d['signatures'][3]['signature_data']===$png&&$d['signatures'][3]['signer_name']==='Fixture IT','Actual IT account signature is snapshotted only on confirmation');
    $director=token_fixture($capture);$s=$pdo->prepare('SELECT token_hash FROM it_document_signatures WHERE document_id=? AND role_key=\'director\'');$s->execute([$id]);
    verify($s->fetchColumn()!==$director,'Only token hashes are persisted');
    document_decide([...$approve,'token'=>$director]);$done=document_detail($id);
    verify($done['status']==='approved'&&count(array_filter($done['signatures'],fn($s)=>$s['status']==='approved'))===5,'All five required roles signed before approval');
    $pdo->exec('UPDATE admins SET signature_data=NULL WHERE id=1');
    verify(document_detail($id)['signatures'][3]['signature_data']===$png,'Deleting profile does not change signed document');
    rejects(fn()=>document_save($id,$done,$actor),409);rejects(fn()=>document_cancel($id,$actor),409);
    $copy=document_copy($id,$actor);verify($copy['status']==='draft'&&!$copy['signatures']&&$copy['id']!==$id,'New copy keeps content but requires every signature again');
    // A rejection preserves evidence; cancellation and stale hashes invalidate links.
    $r=document_save(null,$base,$actor);$r=document_submit((int)$r['id'],['version'=>$r['version']],$actor);$t=token_fixture($capture);
    rejects(fn()=>document_decide([...$approve,'token'=>$t,'decision'=>'rejected']),422);
    document_decide([...$approve,'token'=>$t,'decision'=>'rejected','decision_note'=>'Fixture rejection']);
    verify(document_detail((int)$r['id'])['status']==='rejected'&&document_detail((int)$r['id'])['signatures'][0]['signature_data']===$png,'Rejected evidence retained with reason');
    $r=document_save(null,$base,$actor);$r=document_submit((int)$r['id'],['version'=>$r['version']],$actor);$t=token_fixture($capture);
    document_cancel((int)$r['id'],$actor);rejects(fn()=>document_decide([...$approve,'token'=>$t]),409);
    $r=document_save(null,$base,$actor);$r=document_submit((int)$r['id'],['version'=>$r['version']],$actor);$t=token_fixture($capture);
    $data=$r['data'];$data['fields']['purpose']='Tampered outside editor';$pdo->prepare('UPDATE it_documents SET data=? WHERE id=?')->execute([json_encode($data),$r['id']]);rejects(fn()=>document_approval_view(['token'=>$t]),409);
    $GLOBALS['ithub_config']['smtp']['enabled']=false;
    $r=document_save(null,$base,$actor);$r=document_submit((int)$r['id'],['version'=>$r['version']],$actor);
    verify($r['status']==='request_review'&&$r['signatures'][0]['mail_status']==='failed'&&!$r['signatures'][0]['signature_data'],'SMTP failure retains draft evidence and pending invitation without signing');
    // History has no approval gate; malformed rows and report values still validate.
    $history=['form_code'=>'FM-IT-004','title'=>'Fixture history','document_date'=>'2026-10-28','company'=>'Fixture','data'=>['fields'=>['equipment'=>'Fixture device','asset_code'=>'TEST-001'],'rows'=>[['date'=>'28/10/2569','detail'=>'Fixture maintenance','kind'=>'PM']]]];
    $h=document_save(null,$history,$actor);$h=document_submit((int)$h['id'],['version'=>$h['version']],$actor);verify($h['status']==='approved'&&!$h['signatures'],'History finalizes without invented approval roles');
    $bad=$history;$bad['data']['rows']='invalid';rejects(fn()=>document_save(null,$bad,$actor),422);
    $bad=$history;$bad['data']['rows'][0]['kind']='invented';rejects(fn()=>document_save(null,$bad,$actor),422);
    $bad=$base;$bad['data']['signers']['head']=['id'=>1];$b=document_save(null,$bad,$actor);rejects(fn()=>document_submit((int)$b['id'],['version'=>$b['version']],$actor),422);
    verify(!document_detail((int)$b['id'])['signatures'],'Invalid role assignment rolls back all signature creation');
    // Every catalog schema can be created as a draft without artificial signatures.
    foreach(document_forms() as $form){
        if(!empty($form['record'])){rejects(fn()=>document_save(null,['form_code'=>$form['code'],'title'=>'Fixture record','document_date'=>'2026-10-28','company'=>'Fixture','data'=>[]],$actor),409);continue;}
        $a=document_save(null,['form_code'=>$form['code'],'title'=>'Fixture '.$form['code'],'document_date'=>'2026-10-28','company'=>'Fixture','data'=>[]],$actor);verify($a['status']==='draft'&&!$a['signatures'],'Draft '.$form['code']);
    }
    $pdo->prepare('UPDATE admins SET signature_data=? WHERE id=1')->execute([$png]);
    // Exercise every template's actual role order, both phases, and final gate.
    foreach(document_forms() as $form){
        if(!empty($form['record'])){verify(!$form['roles'],'Borrow record is generated only by IT approval, not document signatures');continue;}
        $fields=[];$row=[];$signers=[];
        $sample=function(array $f):mixed{return match($f['type']){
            'select'=>array_key_first($f['options']),'multiselect'=>[array_key_first($f['options'])],
            'date'=>'2026-10-28','datetime-local'=>'2026-10-28T10:30','number'=>$f['key']==='year'?'2569':'1','decimal'=>'1000.00','email'=>'fixture@example.com',default=>'Fixture '.$f['label']};};
        foreach($form['fields'] as $f)$fields[$f['key']]=$sample($f);
        foreach($form['columns'] as $c)$row[$c['key']]=$sample($c);
        foreach($form['roles'] as $r)$signers[$r['key']]=$r['kind']==='external'?['name'=>'External Fixture','email'=>'external@example.com']:['id'=>$r['kind']==='admin'?1:($r['authority']?2:1)];
        $a=document_save(null,['form_code'=>$form['code'],'title'=>'Full fixture '.$form['code'],'document_date'=>'2026-10-28','company'=>'Fixture','data'=>['fields'=>$fields,'rows'=>$row?[$row]:[],'signers'=>$signers]],$actor);
        $expected=array_column($form['roles'],'key');$visited=[];
        for($step=0;$step<20 && $a['status']!=='approved';$step++){
            if(in_array($a['status'],['draft','in_progress'],true)){$a=document_submit((int)$a['id'],['version'=>$a['version']],$actor);continue;}
            $pending=array_values(array_filter($a['signatures'],fn($s)=>$s['status']==='pending'))[0];$visited[]=$pending['role_key'];
            if($pending['admin_id'])$a=document_decide(['decision'=>'approved','consent'=>true],$actor,(int)$a['id']);
            else{
                $fixtureToken=bin2hex(random_bytes(32));$pdo->prepare('UPDATE it_document_signatures SET token_hash=?,expires_at=DATE_ADD(NOW(),INTERVAL 7 DAY) WHERE id=?')->execute([hash('sha256',$fixtureToken),$pending['id']]);
                document_decide([...$approve,'token'=>$fixtureToken]);$a=document_detail((int)$a['id']);
            }
        }
        verify($a['status']==='approved'&&$visited===$expected,'Full ordered workflow '.$form['code']);
    }
    echo "PASS: $checks document workflow checks; temporary tables and local SMTP only\n";
}finally{proc_terminate($process);foreach($pipes as $p)fclose($p);proc_close($process);@unlink($capture);}
