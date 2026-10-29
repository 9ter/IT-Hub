<?php
declare(strict_types=1);

const DOCUMENT_STATUSES = ['draft'=>'ฉบับร่าง','request_review'=>'รอลงนามคำขอ','in_progress'=>'ดำเนินการ / บันทึกผล','completion_review'=>'รอลงนามรับรอง','approved'=>'รับรองครบแล้ว','rejected'=>'ไม่อนุมัติ / ไม่รับรอง','cancelled'=>'ยกเลิก'];

function document_get(int $id, bool $lock=false): array
{
    $s=db()->prepare('SELECT * FROM it_documents WHERE id=?'.($lock?' FOR UPDATE':''));$s->execute([$id]);
    $d=$s->fetch() ?: fail('ไม่พบเอกสาร',404);
    $d['data']=json_decode($d['data'],true,512,JSON_THROW_ON_ERROR);
    return $d;
}

function document_detail(int $id): array
{
    $d=document_get($id);
    $s=db()->prepare('SELECT id,role_key,role_label,phase,sequence_no,employee_id,admin_id,signer_name,signer_email,status,expires_at,sent_at,mail_status,mail_error,signature_data,decision_note,signed_at FROM it_document_signatures WHERE document_id=? ORDER BY sequence_no');
    $s->execute([$id]);$d['signatures']=$s->fetchAll();
    $s=db()->prepare('SELECT l.note,l.created_at,a.display_name admin_name FROM it_document_logs l LEFT JOIN admins a ON a.id=l.admin_id WHERE document_id=? ORDER BY l.id');
    $s->execute([$id]);$d['logs']=$s->fetchAll();
    if ($d['form_code']==='FM-IT-008') {
        $s=db()->prepare("SELECT b.ticket_id,t.ticket_no,b.admin_name,b.decided_at FROM ticket_borrow_decisions b JOIN tickets t ON t.id=b.ticket_id WHERE b.document_id=? AND b.decision='approved'");
        $s->execute([$id]);$d['borrow_source']=$s->fetch() ?: null;
    }
    return $d;
}

function document_list(array $q): array
{
    $where=[];$args=[];
    foreach(['form_code','status'] as $key) if (!empty($q[$key])) {$where[]="$key=?";$args[]=(string)$q[$key];}
    if (trim((string)($q['q']??''))!=='') {$where[]="(document_no LIKE ? OR title LIKE ? OR company LIKE ? OR (form_code='FM-IT-008' AND data LIKE ?))";array_push($args,...array_fill(0,4,'%'.trim($q['q']).'%'));}
    $result=paginate("SELECT id,document_no,form_code,title,document_date,company,status,version,source_note,updated_at,CASE WHEN form_code='FM-IT-008' THEN data ELSE NULL END record_data FROM it_documents".($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id DESC',$args,(int)($q['page']??1),24);
    foreach($result['data'] as &$d) {
        if ($d['record_data']!==null) {
            $rows=json_decode($d['record_data'],true,512,JSON_THROW_ON_ERROR)['rows']??[];
            $returned=count(array_filter($rows,fn($r)=>!empty($r['returned_at'])));
            $d['record_summary']=['outstanding'=>count($rows)-$returned,'returned'=>$returned];
        }
        unset($d['record_data']);
    } unset($d);
    return $result;
}

function document_log(int $id,string $note,?int $adminId=null): void
{
    db()->prepare('INSERT INTO it_document_logs (document_id,admin_id,note) VALUES (?,?,?)')->execute([$id,$adminId,$note]);
}

function document_value(array $field,array $values,bool $required): mixed
{
    $key=$field['key'];$in=new Input($values);$req=$required && $field['required'];
    if ($field['type']==='multiselect') $value=$in->set($key,array_keys($field['options']),$req,$field['label']);
    elseif ($field['type']==='select') $value=$in->enum($key,array_keys($field['options']),$req,$field['label']);
    elseif ($field['type']==='date') {
        $value=$in->date($key,$req,$field['label']);
        if ($value && date('Y-m-d',strtotime($value))!==$value) $in->error($key,'วันที่ไม่ถูกต้อง');
    }
    elseif ($field['type']==='datetime-local') {
        $value=$in->datetime($key,$req,$field['label']);
        if ($value!==null && str_replace('T',' ',substr((string)$values[$key],0,16))!==substr($value,0,16)) $in->error($key,'วันเวลาที่ระบุไม่ถูกต้อง');
    }
    else {
        $value=$in->str($key,$field['type']==='textarea'?12000:1000,$req,$field['label']);
        if ($value!==null && $field['type']==='email' && !filter_var($value,FILTER_VALIDATE_EMAIL)) $in->error($key,'E-mail ไม่ถูกต้อง');
        if ($value!==null && in_array($field['type'],['number','decimal'],true) && !preg_match($field['type']==='number'?'/^\d{1,8}$/D':'/^\d{1,12}(\.\d{1,2})?$/D',$value)) $in->error($key,'กรุณากรอกตัวเลขที่ไม่ติดลบ');
        if ($value!==null && $field['type']==='number' && (int)$value<1) $in->error($key,'จำนวนต้องมากกว่า 0');
    }
    $in->check();return $value;
}

function document_data(array $def,array $body,?string $validatePhase=null): array
{
    $fields=is_array($body['fields']??null)?$body['fields']:[];
    $rows=$body['rows']??[];$signers=$body['signers']??[];
    if (!is_array($rows) || count($rows)>1000 || !array_is_list($rows)) fail('รายการตารางต้องไม่เกิน 1,000 แถว',422);
    if (!is_array($signers)) fail('ข้อมูลผู้ลงนามไม่ถูกต้อง',422);
    $clean=['fields'=>[],'rows'=>[],'signers'=>[]];
    foreach($def['fields'] as $f) $clean['fields'][$f['key']]=document_value($f,$fields,$validatePhase==='completion'||$validatePhase===$f['phase']);
    foreach($rows as $row) {
        if (!is_array($row)) fail('ข้อมูลรายการไม่ถูกต้อง',422);
        $item=[];foreach($def['columns'] as $c) $item[$c['key']]=document_value($c,$row,$validatePhase==='completion'||$validatePhase===$def['tablePhase']);
        if (array_filter($item,fn($v)=>$v!==null && $v!=='' && $v!==[])) $clean['rows'][]=$item;
    }
    if ($validatePhase && $def['columns'] && !$clean['rows']) fail('กรุณาเพิ่มรายการอย่างน้อย 1 แถว',422);
    foreach($def['roles'] as $r) {
        $selection=$signers[$r['key']]??[];
        if (!is_array($selection)) fail('ข้อมูลผู้ลงนามไม่ถูกต้อง',422);
        $in=new Input($selection);
        if ($r['kind']==='external') {
            $name=$in->str('name',150,false);$email=$in->str('email',150,false);
            if ($email && !filter_var($email,FILTER_VALIDATE_EMAIL)) $in->error('email','E-mail ผู้มาติดต่อไม่ถูกต้อง');
            $clean['signers'][$r['key']]=compact('name','email');
        } else {
            $personId=$in->int('id',1);$clean['signers'][$r['key']]=['id'=>$personId];
        }
        $in->check();
    }
    foreach([['started_at','finished_at'],['time_in','time_out'],['start_date','end_date']] as [$start,$end]) {
        if (!empty($clean['fields'][$start]) && !empty($clean['fields'][$end]) && $clean['fields'][$end]<$clean['fields'][$start]) fail('วันที่หรือเวลาสิ้นสุดต้องไม่ก่อนเริ่มต้น',422,[$end=>'วันที่หรือเวลาสิ้นสุดต้องไม่ก่อนเริ่มต้น']);
    }
    if (!empty($def['record'])) foreach($clean['rows'] as $i=>$row) {
        $start=$row['date']?:($clean['fields']['start_date']??null);
        if (!empty($row['returned_at'])) {
            if ($start && $row['returned_at']<$start) fail('แถว '.($i+1).': วันที่คืนต้องไม่ก่อนวันที่ใช้งาน',422);
            foreach(['returner','receiver','return_condition'] as $key) if (empty($row[$key])) fail('แถว '.($i+1).': กรุณาระบุผู้คืน เจ้าหน้าที่ผู้รับคืน และสภาพอุปกรณ์ให้ครบ',422);
        } elseif (!empty($row['returner']) || !empty($row['receiver']) || !empty($row['return_condition'])) fail('แถว '.($i+1).': กรุณาระบุวันที่คืนจริง',422);
    }
    return $clean;
}

function document_request_snapshot(array $d): array
{
    $def=document_forms()[$d['form_code']];$values=[];
    foreach($def['fields'] as $f) if ($f['phase']==='request') $values[$f['key']]=$d['data']['fields'][$f['key']]??null;
    $signers=[];foreach($def['roles'] as $r) if ($r['phase']==='request') $signers[$r['key']]=$d['data']['signers'][$r['key']]??null;
    return ['form_code'=>$d['form_code'],'title'=>$d['title'],'document_date'=>$d['document_date'],'company'=>$d['company'],'fields'=>$values,'rows'=>$d['data']['rows'],'signers'=>$signers];
}

function document_hash(array $d,string $phase): string
{
    return hash('sha256',json_encode($phase==='request'?document_request_snapshot($d):[$d['form_code'],$d['title'],$d['document_date'],$d['company'],$d['data']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function document_save(?int $id,array $body,array $actor,?string $sourceKey=null): array
{
    $pdo=db();$pdo->beginTransaction();
    try {
        $old=$id!==null?document_get($id,true):null;
        if ($old && !in_array($old['status'],['draft','in_progress'],true)) fail('เอกสารนี้ล็อกแล้ว หากต้องแก้ไขให้สร้างฉบับใหม่',409);
        $in=new Input($body);
        $code=$old['form_code']??$in->enum('form_code',array_keys(document_forms()),true,'แบบฟอร์ม');$in->check();
        $def=document_forms()[$code];
        if (!$old && !empty($def['record'])) fail('ต้องส่งคำขอเบิก–ยืมให้ Admin IT Hub อนุมัติก่อน ระบบจะเพิ่มเข้าบันทึกให้อัตโนมัติ',409);
        $d=['form_code'=>$code,'title'=>$in->str('title',200,true,'ชื่อเอกสาร'),'document_date'=>$in->date('document_date',true,'วันที่เอกสาร'),'company'=>$in->str('company',200,true,'บริษัท')];
        $version=$in->int('version',1,PHP_INT_MAX,$old!==null,'รุ่นข้อมูล');$in->check();
        if (date('Y-m-d',strtotime($d['document_date']))!==$d['document_date']) fail('วันที่เอกสารไม่ถูกต้อง',422);
        if ($old && (int)$old['version']!==$version) fail('มีการแก้ไขเอกสารแล้ว กรุณาโหลดใหม่',409);
        $record=!empty($def['record']);
        $d['data']=document_data($def,is_array($body['data']??null)?$body['data']:[],$record?'request':null);
        if ($record && $old) borrow_record_validate_update($old,$d['data']);
        if (!$record && $old && $old['status']==='in_progress' && document_request_snapshot($d)!==document_request_snapshot($old)) fail('ข้อมูลคำขอลงนามแล้ว แก้ไขได้เฉพาะผลดำเนินการและผู้รับรองผล',409);
        if ($old) {
            $sourceNote=$old['status']==='draft'&&$in->has('source_note')?$in->str('source_note',2000):$old['source_note'];$in->check();
            $pdo->prepare('UPDATE it_documents SET title=?,document_date=?,company=?,data=?,source_note=?,version=version+1 WHERE id=?')->execute([$d['title'],$d['document_date'],$d['company'],json_encode($d['data'],JSON_UNESCAPED_UNICODE),$sourceNote,$id]);
            document_log($id,$record?'แก้ไขบันทึกเบิก–คืนอุปกรณ์':'บันทึกการแก้ไขเอกสาร',(int)$actor['id']);
        } else {
            // Random suffix avoids concurrent allocation collisions; the form code remains searchable.
            $no='IT-'.str_replace('FM-IT-','',$code).'-'.thai_year_month().'-'.strtoupper(bin2hex(random_bytes(4)));
            $sourceNote=$in->str('source_note',2000);$in->check();
            $pdo->prepare('INSERT INTO it_documents (document_no,form_code,title,document_date,company,data,created_by,source_note,source_key) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$no,$code,$d['title'],$d['document_date'],$d['company'],json_encode($d['data'],JSON_UNESCAPED_UNICODE),$actor['id'],$sourceNote,$sourceKey]);
            $id=(int)$pdo->lastInsertId();document_log($id,$record?'สร้างบันทึกเบิก–ยืมอุปกรณ์':'สร้างฉบับร่าง',(int)$actor['id']);
        }
        if ($record) $pdo->prepare("UPDATE it_documents SET status='in_progress' WHERE id=?")->execute([$id]);
        if($record){$s=$pdo->prepare('SELECT ticket_id FROM ticket_borrow_decisions WHERE document_id=?');$s->execute([$id]);$sourceId=$s->fetchColumn();if($sourceId)archive_enqueue((int)$sourceId);}
        $pdo->commit();return document_detail($id);
    } catch(Throwable $e) {$pdo->rollBack();throw $e;}
}

function document_resolve_signer(array $role,array $choice): array
{
    if ($role['kind']==='external') {
        $in=new Input($choice);$name=$in->str('name',150,true,'ชื่อผู้มาติดต่อ');$email=$in->str('email',150,true,'E-mail ผู้มาติดต่อ');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) $in->error('email','E-mail ไม่ถูกต้อง');$in->check();
        return ['employee_id'=>null,'admin_id'=>null,'signer_name'=>$name,'signer_email'=>$email];
    }
    if ($role['kind']==='admin') {
        $s=db()->prepare('SELECT id,display_name FROM admins WHERE id=? AND is_active=1');$s->execute([$choice['id']??null]);
        $p=$s->fetch() ?: fail('กรุณาเลือกบัญชีเจ้าหน้าที่สำหรับ '.$role['label'],422);
        return ['employee_id'=>null,'admin_id'=>(int)$p['id'],'signer_name'=>$p['display_name'],'signer_email'=>null];
    }
    $s=db()->prepare('SELECT id,full_name,email,can_approve FROM employees WHERE id=? AND is_active=1');$s->execute([$choice['id']??null]);
    $p=$s->fetch();
    if (!$p || !filter_var($p['email'],FILTER_VALIDATE_EMAIL) || ($role['authority'] && !$p['can_approve'])) fail('กรุณาเลือกพนักงานที่มีอีเมลและสิทธิ์ถูกต้องสำหรับ '.$role['label'],422);
    return ['employee_id'=>(int)$p['id'],'admin_id'=>null,'signer_name'=>$p['full_name'],'signer_email'=>$p['email']];
}

function document_next(int $id,string $phase): void
{
    $s=db()->prepare("SELECT * FROM it_document_signatures WHERE document_id=? AND phase=? AND status IN ('waiting','pending') ORDER BY sequence_no LIMIT 1");$s->execute([$id,$phase]);
    $next=$s->fetch();
    if (!$next) {
        $status=$phase==='request'?'in_progress':'approved';
        db()->prepare('UPDATE it_documents SET status=?,version=version+1 WHERE id=?')->execute([$status,$id]);
        document_log($id,$phase==='request'?'ลงนามคำขอครบแล้ว สามารถบันทึกผลดำเนินการ':'ลงนามรับรองเอกสารครบทุกตำแหน่ง');return;
    }
    if ($next['status']==='waiting') db()->prepare("UPDATE it_document_signatures SET status='pending' WHERE id=?")->execute([$next['id']]);
}

function document_submit(int $id,array $body,array $actor): array
{
    $pdo=db();$pdo->beginTransaction();
    try {
        $d=document_get($id,true);$def=document_forms()[$d['form_code']];
        if (!empty($def['record'])) fail('แบบฟอร์มนี้ใช้บันทึกเบิก–คืน กรุณาบันทึกข้อมูลได้โดยตรง',409);
        if (!in_array($d['status'],['draft','in_progress'],true)) fail('เอกสารไม่อยู่ในขั้นตอนส่งลงนาม',409);
        if ((int)($body['version']??0)!==(int)$d['version']) fail('เอกสารเปลี่ยนแล้ว กรุณาโหลดใหม่',409);
        $hasRequest=(bool)array_filter($def['roles'],fn($r)=>$r['phase']==='request');
        $phase=$d['status']==='draft' && $hasRequest?'request':'completion';
        document_data($def,$d['data'],$phase);
        $roles=array_filter($def['roles'],fn($r)=>$r['phase']===$phase);
        $sequence=0;
        foreach($def['roles'] as $r) {
            $sequence++;if ($r['phase']!==$phase) continue;
            $p=document_resolve_signer($r,$d['data']['signers'][$r['key']]??[]);
            $pdo->prepare('INSERT INTO it_document_signatures (document_id,role_key,role_label,phase,sequence_no,employee_id,admin_id,signer_name,signer_email,snapshot_hash) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id,$r['key'],$r['label'],$phase,$sequence,$p['employee_id'],$p['admin_id'],$p['signer_name'],$p['signer_email'],document_hash($d,$phase)]);
        }
        $pdo->prepare('UPDATE it_documents SET status=?,version=version+1 WHERE id=?')->execute([$phase.'_review',$id]);
        document_log($id,'ส่งขอลงนาม'.($phase==='request'?'คำขอ':'รับรองเอกสาร').'ตามลำดับ',(int)$actor['id']);
        document_next($id,$phase);$pdo->commit();
    } catch(Throwable $e) {$pdo->rollBack();throw $e;}
    document_send_pending($id);return document_detail($id);
}

/** Rotate the current invitation only; failures retain the document and can be retried. */
function document_send_pending(int $id,bool $resend=false): void
{
    $pdo=db();$pdo->beginTransaction();
    try {
        $d=document_get($id,true);
        if (!in_array($d['status'],['request_review','completion_review'],true)) {$pdo->commit();return;}
        $phase=$d['status']==='request_review'?'request':'completion';
        $s=$pdo->prepare("SELECT * FROM it_document_signatures WHERE document_id=? AND phase=? AND status='pending' ORDER BY sequence_no LIMIT 1 FOR UPDATE");$s->execute([$id,$phase]);$a=$s->fetch();
        if (!$a || $a['admin_id']) {$pdo->commit();return;}
        if (!$resend && $a['token_hash']) {$pdo->commit();return;}
        if ($a['expires_at'] && strtotime($a['expires_at'])-7*86400>time()-60) fail('กรุณารอ 1 นาทีก่อนส่งอีกครั้ง',429);
        try {document_check_identity($a,$d);} catch (HttpError $e) {
            $pdo->prepare("UPDATE it_document_signatures SET mail_status='failed',mail_error=?,token_hash=NULL WHERE id=?")->execute(['ผู้รับไม่พร้อมใช้งานหรืออีเมลเปลี่ยน กรุณาสร้างฉบับใหม่หลังตรวจรายชื่อ',$a['id']]);
            document_log($id,'ระงับการส่งลิงก์ เนื่องจากข้อมูลผู้ลงนามไม่พร้อมใช้งาน');$pdo->commit();return;
        }
        $token=bin2hex(random_bytes(32));$url=rtrim(cfg('app_url'),'/').'/document-approve#'.$token;
        $mailStatus='sent';$mailError=null;
        try {
            $mail=approval_mailer();$mail->addAddress($a['signer_email'],$a['signer_name']);
            $mail->Subject='[IT Hub] ขอลงนาม '.$a['role_label'].' '.$d['document_no'];
            $mail->Body="เรียน {$a['signer_name']}\n\nขอลงนามในตำแหน่ง {$a['role_label']}\n{$d['form_code']} — {$d['title']}\nเลขที่ {$d['document_no']}\nบริษัท {$d['company']}\nวันที่ {$d['document_date']}\n\nกรุณาตรวจสอบเอกสารฉบับเต็มและยืนยันลายเซ็น:\n$url\n\nลิงก์ใช้ได้ 7 วัน ลงนามได้ครั้งเดียว การเปิดลิงก์ไม่ถือเป็นการลงนาม กรุณาอย่าส่งต่อลิงก์\n";
            $mail->send();
        } catch(Throwable $e) {$mailStatus='failed';$mailError='ส่งอีเมลไม่สำเร็จ กรุณาตรวจ SMTP แล้วส่งใหม่';}
        $pdo->prepare('UPDATE it_document_signatures SET token_hash=?,expires_at=DATE_ADD(NOW(),INTERVAL 7 DAY),sent_at=?,mail_status=?,mail_error=? WHERE id=?')->execute([hash('sha256',$token),$mailStatus==='sent'?now():null,$mailStatus,$mailError,$a['id']]);
        document_log($id,$mailStatus==='sent'?'ส่งอีเมลขอลงนาม '.$a['role_label']:$mailError);$pdo->commit();
    } catch(Throwable $e) {$pdo->rollBack();throw $e;}
}

function document_check_identity(array $a,array $d): void
{
    if (!hash_equals($a['snapshot_hash'],document_hash($d,$a['phase']))) fail('ข้อมูลเอกสารไม่ตรงกับฉบับที่ส่งลงนาม',409);
    if ($a['employee_id']) {
        $r=array_values(array_filter(document_forms()[$d['form_code']]['roles'],fn($r)=>$r['key']===$a['role_key']))[0];
        $p=document_resolve_signer($r,['id'=>$a['employee_id']]);
        if ($p['signer_email']!==$a['signer_email']) fail('อีเมลพนักงานเปลี่ยนแล้ว กรุณาติดต่อ IT เพื่อสร้างฉบับใหม่',409);
    }
}

function document_token(string $token,bool $lock=false): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) fail('ลิงก์ไม่ถูกต้องหรือหมดอายุ',404);
    $s=db()->prepare('SELECT * FROM it_document_signatures WHERE token_hash=?'.($lock?' FOR UPDATE':''));$s->execute([hash('sha256',$token)]);$a=$s->fetch() ?: fail('ลิงก์ไม่ถูกต้องหรือหมดอายุ',404);
    if ($a['status']!=='pending') fail('ลิงก์นี้ลงนามแล้วหรือยังไม่ถึงลำดับ',409);
    if (!$a['expires_at'] || strtotime($a['expires_at'])<time()) fail('ลิงก์หมดอายุ กรุณาให้ IT ส่งใหม่',410);
    $d=document_get((int)$a['document_id'],$lock);
    if ($d['status']!==$a['phase'].'_review') fail('เอกสารนี้ยกเลิกหรือพ้นขั้นตอนลงนามแล้ว',409);
    document_check_identity($a,$d);
    return [$a,$d];
}

function document_approval_view(array $body,?PDO $source=null): array
{
    [$a,$d]=document_token(is_string($body['token']??null)?$body['token']:'');
    $sig=$a['employee_id']?employee_saved_signature((int)$a['employee_id'],$a['signer_email'],$source,false):null;
    $view=document_detail((int)$d['id']);unset($view['data']['signers'],$view['logs'],$view['created_by'],$view['source_key']);
    foreach($view['signatures'] as &$step) unset($step['signer_email'],$step['employee_id'],$step['admin_id'],$step['mail_error']);unset($step);
    return ['document'=>$view,'definition'=>document_forms()[$d['form_code']],'role_label'=>$a['role_label'],'signer_name'=>$a['signer_name'],'expires_at'=>$a['expires_at'],'saved_signature_data'=>$sig,'saved_signature_hash'=>$sig?hash('sha256',$sig):null];
}

function document_decide(array $body,?array $actor=null,?int $documentId=null,?PDO $source=null): array
{
    $in=new Input($body);$decision=$in->enum('decision',['approved','rejected'],true,'ผลการพิจารณา');
    $note=$in->str('decision_note',2000,$decision==='rejected','เหตุผลที่ไม่รับรอง');
    if (!$in->bool('consent')) $in->error('consent','กรุณายืนยันการลงนาม');
    $in->check();
    $token=is_string($body['token']??null)?$body['token']:'';
    $initial=$actor?null:document_token($token);
    $pdo=db();$pdo->beginTransaction();
    try {
        if ($actor) {
            $d=document_get($documentId,true);
            if (!in_array($d['status'],['request_review','completion_review'],true)) fail('เอกสารไม่อยู่ระหว่างลงนาม',409);
            $s=$pdo->prepare("SELECT * FROM it_document_signatures WHERE document_id=? AND status='pending' ORDER BY sequence_no LIMIT 1 FOR UPDATE");$s->execute([$documentId]);$a=$s->fetch();
            if (!$a || (int)$a['admin_id']!==(int)$actor['id']) fail('เฉพาะบัญชีเจ้าหน้าที่ที่เลือกไว้จึงลงนามตำแหน่งนี้ได้',403);
            document_check_identity($a,$d);
            $s=$pdo->prepare('SELECT signature_data FROM admins WHERE id=? AND is_active=1');$s->execute([$actor['id']]);$signature=$s->fetchColumn();
            if (!$signature) fail('กรุณาบันทึกลายเซ็นของคุณในหน้าผู้ดูแลระบบก่อน',422);
        } else {
            // Current locking reads after the document lock prevent a stale repeatable-read decision.
            document_get((int)$initial[1]['id'],true);[$a,$d]=document_token($token,true);
            $useSaved=$in->bool('use_saved_signature');
            if ($useSaved) {
                $signature=$a['employee_id']?employee_saved_signature((int)$a['employee_id'],$a['signer_email'],$source,false):null;
                $expected=$in->str('saved_signature_hash',64,true);$in->check();
                if (!$signature || !hash_equals(hash('sha256',$signature),$expected)) fail('ลายเซ็นต้นทางเปลี่ยนแล้ว กรุณาโหลดใหม่และตรวจสอบอีกครั้ง',409);
            } else {$signature=validate_signature($in);$in->check();}
        }
        $pdo->prepare('UPDATE it_document_signatures SET status=?,signature_data=?,decision_note=?,signed_at=NOW(),signed_ip=? WHERE id=?')->execute([$decision,$signature,$note,client_ip(),$a['id']]);
        document_log((int)$d['id'],$a['role_label'].' ยืนยัน'.($decision==='approved'?'ลงนาม / รับรอง':'ไม่อนุมัติ / ไม่รับรอง'),$actor['id']??null);
        if ($decision==='rejected') $pdo->prepare("UPDATE it_documents SET status='rejected',version=version+1 WHERE id=?")->execute([$d['id']]);
        else document_next((int)$d['id'],$a['phase']);
        $pdo->commit();
    } catch(Throwable $e) {$pdo->rollBack();throw $e;}
    document_send_pending((int)$d['id']);
    return $actor?document_detail((int)$d['id']):['status'=>$decision];
}

function document_cancel(int $id,array $actor): array
{
    $pdo=db();$pdo->beginTransaction();
    try {
        $d=document_get($id,true);
        if (in_array($d['status'],['approved','cancelled'],true)) fail('เอกสารนี้รับรองครบแล้วหรือยกเลิกแล้ว',409);
        $pdo->prepare("UPDATE it_documents SET status='cancelled',version=version+1 WHERE id=?")->execute([$id]);
        document_log($id,'ยกเลิกเอกสาร — ลิงก์ที่ยังไม่ลงนามถูกระงับ',(int)$actor['id']);
        if($d['form_code']==='FM-IT-008'){$s=$pdo->prepare('SELECT ticket_id FROM ticket_borrow_decisions WHERE document_id=?');$s->execute([$id]);$sourceId=$s->fetchColumn();if($sourceId)archive_enqueue((int)$sourceId);}
        $pdo->commit();
    } catch(Throwable $e) {$pdo->rollBack();throw $e;}
    return document_detail($id);
}

function document_copy(int $id,array $actor): array
{
    $old=document_get($id);
    if ($old['form_code']==='FM-IT-008') fail('การเบิก–ยืมครั้งใหม่ต้องส่งคำขอให้ Admin IT Hub อนุมัติก่อน',409);
    $copy=document_save(null,[...$old,'title'=>mb_substr($old['title'],0,180).' (ฉบับใหม่)'],$actor);
    db()->prepare('UPDATE it_documents SET source_note=? WHERE id=?')->execute(['สร้างฉบับใหม่จาก '.$old['document_no'].' — ต้องลงนามใหม่ทุกตำแหน่ง',$copy['id']]);
    return document_detail((int)$copy['id']);
}

/** Read-only prefill. Source signatures are never copied to a newly editable document. */
function document_prefill(array $q): array
{
    $code=(string)($q['form_code']??'');$def=document_forms()[$code]??fail('แบบฟอร์มไม่ถูกต้อง',422);
    if (!empty($def['record'])) fail('ระบบสร้างบันทึกเบิก–ยืมจากคำขอที่ Admin IT Hub อนุมัติแล้ว กรุณาเปิดบันทึกจากใบคำขอ',409);
    $fields=[];$rows=[];$note='';
    if ($code==='FM-IT-001') {
        $assets=asset_list(['all'=>1,'company'=>$q['company']??''])['data'];
        foreach($assets as $a) $rows[]=['asset_code'=>$a['asset_code'],'holder'=>$a['holder_name'],'model'=>$a['brand_model'],'serial'=>$a['serial_no'],'department'=>$a['department'],'registered'=>$a['registered_at'],'notes'=>$a['holder_nickname'],'type'=>$a['category_name']];
        $note='สำเนาทะเบียนอุปกรณ์จาก IT Hub ณ '.now().' ไม่เปลี่ยนข้อมูลทะเบียนต้นทาง';
    }
    if ($code==='FM-IT-004' && !empty($q['asset_id'])) {
        $a=asset_detail((int)$q['asset_id']);$fields=['equipment'=>$a['brand_model'],'asset_code'=>$a['asset_code'],'type'=>$a['category_name'],'model'=>$a['brand_model'],'department'=>$a['department'],'registered'=>$a['registered_at']];
        foreach($a['maintenance'] as $m) $rows[]=['date'=>$m['performed_on'],'detail'=>$m['detail'],'kind'=>$m['kind'],'duration'=>$m['duration'],'responsible'=>$m['performed_by'],'notes'=>$m['ticket_no']];
        $note='สำเนาประวัติอุปกรณ์ '.$a['asset_code'].' ณ '.now();
    }
    if (!empty($q['ticket_id'])) {
        $t=ticket_admin((int)$q['ticket_id']);
        if (ticket_types()[$t['type']]['form']!==$code) fail('ประเภทใบงานไม่ตรงกับแบบฟอร์ม',422);
        $v=$t['details'];$fields=['requester'=>$t['requester_name'],'department'=>$t['requester_dept'],'position'=>$v['position']??null,'description'=>$t['description'],'purpose'=>$t['description'],'result'=>$t['resolution']];
        if ($t['type']==='repair') $fields += ['equipment'=>$v['equipment']?:$t['asset_model'],'asset_code'=>$t['asset_code'],'location'=>$t['location'],'found_at'=>$v['found_at']??null,'job_type'=>$v['job_type']??'repair','fix_method'=>$t['fix_method'],'vendor'=>$t['outsource_vendor'],'started_at'=>$t['started_at'],'finished_at'=>$t['finished_at']];
        if ($t['type']==='email') $fields += ['name_th'=>$v['name_th'],'name_en'=>$v['name_en'],'email_company'=>$v['company'],'desired_email'=>$v['desired_email']];
        if ($t['type']==='borrow') {$fields += ['device_kind'=>$v['device_kind'],'start_date'=>$v['start_date'],'end_date'=>$v['end_date']];$rows[]=['department'=>$t['requester_dept'],'user'=>$t['requester_name'],'purpose'=>$t['description'],'item'=>$v['item'],'quantity'=>(string)$v['quantity'],'date'=>$v['start_date']];}
        if ($t['type']==='purchase') {$fields += ['urgency'=>$v['urgent']?'urgent':'normal','need_by'=>$v['need_by'],'cpu'=>$v['spec_cpu'],'ram'=>$v['spec_ram'],'storage'=>$v['spec_storage'],'display'=>$v['spec_display'],'other'=>$v['spec_other']];foreach($v['items'] as $item) $rows[]=['item'=>$item,'quantity'=>'1'];}
        if ($t['type']==='service') $fields += ['device_kind'=>$v['device_kind'],'actions'=>$v['actions'],'os'=>$v['os_target'],'other'=>$v['other_text']];
        $note='สำเนาจากใบงาน '.$t['ticket_no'].(!empty($def['record'])?' — กรุณาตรวจทานข้อมูลก่อนบันทึกเบิก–คืน':' — ต้องตรวจทานและขอลงนามใหม่ ไม่คัดลอกลายเซ็นจากใบงานเดิม');
    }
    return ['data'=>document_data($def,['fields'=>$fields,'rows'=>$rows]),'source_note'=>$note];
}

function document_template(string $code): never
{
    if (!isset(document_forms()[$code])) fail('ไม่พบแบบฟอร์ม',404);
    $files=glob(dirname(__DIR__,2).'/FM-IT/'.$code.' *.xlsx');$file=$files[0]??null;
    if (!$file || !is_file($file)) fail('ไม่พบไฟล์ต้นฉบับ',404);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode(basename($file)));
    header('Cache-Control: private, no-store');header('Content-Length: '.filesize($file));readfile($file);exit;
}
