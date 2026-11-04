<?php
declare(strict_types=1);

const REQUEST_EDIT_FIELDS = ['description','position','urgent','need_by','items','spec_cpu','spec_ram','spec_storage','spec_display','spec_other'];

function request_edit_allowed(array $ticket): void
{
    if ($ticket['type'] !== 'purchase') fail('การแก้ไขนี้ใช้กับใบขอซื้อ FM-IT-007', 422);
    if (in_array($ticket['status'], ['rejected','cancelled'], true)) fail('คำขอนี้ถูกปฏิเสธหรือยกเลิกแล้ว', 409);
}

function request_edit_version(array $ticket): string
{
    $s=db()->prepare('SELECT COALESCE(MAX(id),0) FROM ticket_request_edits WHERE ticket_id=?');
    $s->execute([$ticket['id']]);
    return hash('sha256',purchase_snapshot($ticket).':'.$ticket['completion_revision'].':'.$ticket['status'].':'.$s->fetchColumn());
}

function request_edit_check_version(array $ticket, array $body): void
{
    if (!is_string($body['version']??null) || !hash_equals(request_edit_version($ticket),$body['version'])) {
        fail('รายละเอียดคำขอเปลี่ยนแล้ว กรุณาโหลดข้อมูลใหม่ก่อนแก้ไข',409);
    }
}

function request_edit_latest(int $id): ?array
{
    $s=db()->prepare('SELECT id,reason,requester_email,status,expires_at,created_at,submitted_at,mail_status,mail_error FROM ticket_edit_requests WHERE ticket_id=? ORDER BY id DESC LIMIT 1');
    $s->execute([$id]);return $s->fetch()?:null;
}

function request_edit_history(int $id): array
{
    $s=db()->prepare('SELECT e.id,e.admin_id,e.edit_request_id,e.reason,e.before_snapshot,e.after_snapshot,e.created_at,a.display_name AS admin_name FROM ticket_request_edits e LEFT JOIN admins a ON a.id=e.admin_id WHERE e.ticket_id=? ORDER BY e.id DESC');
    $s->execute([$id]);return $s->fetchAll();
}

/** Only explicitly audited request corrections may bridge original signing snapshots. */
function request_edit_preserves_approval(array $approval,array $ticket): bool
{
    $s=db()->prepare('SELECT before_snapshot,after_snapshot FROM ticket_request_edits WHERE ticket_id=? AND work_revision=? ORDER BY id');
    $s->execute([$ticket['ticket_id']??$ticket['id'],$ticket['completion_revision']]);
    $snapshot=$approval['resolution_snapshot'];
    foreach($s->fetchAll() as $edit) if($edit['before_snapshot']===$snapshot)$snapshot=$edit['after_snapshot'];
    return $snapshot===purchase_snapshot($ticket);
}

/** Caller holds the ticket lock. Identity, workflow, procurement and signatures cannot be edited here. */
function request_edit_apply(array $ticket,array $body,?array $admin,?int $returnId=null): void
{
    request_edit_allowed($ticket);request_edit_check_version($ticket,$body);
    $data=$body['request']??null;
    if(!is_array($data) || array_diff(array_keys($data),REQUEST_EDIT_FIELDS))fail('รายละเอียดคำขอไม่ถูกต้อง',422);
    $old=json_decode($ticket['details']?:'{}',true);
    $input=array_replace(array_intersect_key($old,array_flip(REQUEST_EDIT_FIELDS)),['description'=>$ticket['description']],$data);
    $in=new Input($input);
    $description=$in->str('description',5000,true,'รายละเอียด / วัตถุประสงค์');
    if(isset($input['items']) && (!is_array($input['items']) || array_filter($input['items'],fn($v)=>!is_string($v)||!array_key_exists($v,PURCHASE_ITEMS))))$in->error('items','ประเภทอุปกรณ์ไม่ถูกต้อง');
    if(isset($input['urgent'])&&!is_bool($input['urgent']))$in->error('urgent','ความเร่งด่วนไม่ถูกต้อง');
    [$parsed,$title]=ticket_types()['purchase']['parse']($in);
    if($parsed['need_by']&&date('Y-m-d',strtotime($parsed['need_by']))!==$parsed['need_by'])$in->error('need_by','วันที่ไม่ถูกต้อง');
    $reason=(new Input($body))->str('reason',2000);
    if($admin&& !$reason)$in->error('reason','กรุณาระบุเหตุผลการแก้ไข');
    $in->check();
    $details=json_encode(array_replace($old,$parsed),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $next=[...$ticket,'description'=>$description,'details'=>$details];
    $before=purchase_snapshot($ticket);$after=purchase_snapshot($next);
    if($before!==$after){
        db()->prepare('UPDATE tickets SET description=?,details=?,title=? WHERE id=?')->execute([$description,$details,mb_substr($title,0,200),$ticket['id']]);
        db()->prepare('INSERT INTO ticket_request_edits(ticket_id,admin_id,edit_request_id,reason,before_snapshot,after_snapshot,work_revision) VALUES(?,?,?,?,?,?,?)')
            ->execute([$ticket['id'],$admin['id']??null,$returnId,$admin?$reason:'ผู้ขอแก้ไขตามที่ IT ส่งกลับ',$before,$after,$ticket['completion_revision']]);
    }
    db()->prepare('INSERT INTO ticket_logs(ticket_id,admin_id,note,is_public) VALUES(?,?,?,1)')
        ->execute([$ticket['id'],$admin['id']??null,($admin?'Admin แก้ไขรายละเอียดคำขอ':'ผู้ขอส่งรายละเอียดที่แก้ไขกลับแล้ว').' โดยคงลายเซ็นเดิม']);
}

function request_edit_admin(int $id,array $body,array $admin): array
{
    $pdo=db();$pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT * FROM tickets WHERE id=? FOR UPDATE');$s->execute([$id]);$ticket=$s->fetch()?:fail('ไม่พบคำขอ',404);
        request_edit_apply($ticket,$body,$admin);
        archive_enqueue($id);$pdo->commit();
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
    return ticket_admin($id);
}

function request_edit_return(int $id,array $body,array $admin): array
{
    $in=new Input($body);$reason=$in->str('reason',2000,true,'เหตุผลที่ส่งกลับ');$in->check();
    $pdo=db();$pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT * FROM tickets WHERE id=? FOR UPDATE');$s->execute([$id]);$ticket=$s->fetch()?:fail('ไม่พบคำขอ',404);
        request_edit_allowed($ticket);request_edit_check_version($ticket,$body);
        if(!filter_var($ticket['requester_email'],FILTER_VALIDATE_EMAIL))fail('คำขอนี้ไม่มีอีเมลผู้ขอที่ถูกต้อง Admin สามารถแก้ไขรายละเอียดให้ได้',422);
        $previous=request_edit_latest($id);
        if($previous&&strtotime($previous['created_at'])>time()-60&&$previous['mail_status']!=='failed')fail('กรุณารอ 1 นาทีก่อนส่งกลับอีกครั้ง',429);
        $token=bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE ticket_edit_requests SET status='superseded' WHERE ticket_id=? AND status='pending'")->execute([$id]);
        $pdo->prepare('INSERT INTO ticket_edit_requests(ticket_id,created_by,reason,requester_email,token_hash,expires_at) VALUES(?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 7 DAY))')
            ->execute([$id,$admin['id'],$reason,$ticket['requester_email'],hash('sha256',$token)]);
        $returnId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO ticket_logs(ticket_id,admin_id,note,is_public) VALUES(?,?,?,1)")->execute([$id,$admin['id'],'IT ส่งกลับให้ผู้ขอแก้ไขรายละเอียด โดยคงลายเซ็นเดิม']);
        $pdo->commit();
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
    $status='sent';$error=null;
    try{
        $mail=approval_mailer();$mail->addAddress($ticket['requester_email'],$ticket['requester_name']);
        $mail->Subject='[IT Hub] ส่งกลับแก้ไขรายละเอียด '.$ticket['ticket_no'];
        $url=rtrim(cfg('app_url'),'/').'/request-edit#'.$token;
        $mail->Body="เรียน {$ticket['requester_name']}\n\nIT ส่งคำขอ {$ticket['ticket_no']} กลับเพื่อแก้ไขรายละเอียด\nเหตุผล: $reason\n\nเปิดเพื่อแก้ไขและส่งกลับ:\n$url\n\nระบบคงลายเซ็นเดิม ไม่ต้องลงนามใหม่\nลิงก์ใช้ได้ 7 วัน ใช้ส่งกลับได้ครั้งเดียว กรุณาอย่าส่งต่อลิงก์นี้\n";
        $mail->send();
    }catch(Throwable){$status='failed';$error='ส่งอีเมลไม่สำเร็จ กรุณาตรวจ SMTP แล้วกดส่งกลับอีกครั้ง';}
    $pdo->prepare('UPDATE ticket_edit_requests SET mail_status=?,mail_error=? WHERE id=?')->execute([$status,$error,$returnId]);
    return ticket_admin($id);
}

function request_edit_by_token(array $body): array
{
    $token=$body['token']??null;
    if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))fail('ลิงก์ไม่ถูกต้องหรือหมดอายุ',404);
    $s=db()->prepare('SELECT * FROM ticket_edit_requests WHERE token_hash=?');$s->execute([hash('sha256',$token)]);
    $request=$s->fetch()?:fail('ลิงก์ไม่ถูกต้องหรือหมดอายุ',404);
    if($request['status']!=='pending')fail('ลิงก์นี้ส่งกลับแล้วหรือถูกแทนที่ กรุณาติดต่อ IT หากต้องแก้ไขเพิ่มเติม',409);
    if(strtotime($request['expires_at'])<time())fail('ลิงก์หมดอายุ กรุณาติดต่อ IT เพื่อส่งกลับใหม่',410);
    return $request;
}

function request_edit_view(array $body): array
{
    $request=request_edit_by_token($body);
    $s=db()->prepare('SELECT * FROM tickets WHERE id=?');$s->execute([$request['ticket_id']]);$ticket=$s->fetch()?:fail('ไม่พบคำขอ',404);
    request_edit_allowed($ticket);$details=json_decode($ticket['details']?:'{}',true);
    return ['ticket_no'=>$ticket['ticket_no'],'requester_name'=>$ticket['requester_name'],'reason'=>$request['reason'],'expires_at'=>$request['expires_at'],
        'version'=>request_edit_version($ticket),'request'=>['description'=>$ticket['description'],...array_intersect_key($details,array_flip(REQUEST_EDIT_FIELDS))]];
}

function request_edit_submit(array $body): array
{
    $request=request_edit_by_token($body);$id=(int)$request['ticket_id'];$pdo=db();$pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT * FROM tickets WHERE id=? FOR UPDATE');$s->execute([$id]);$ticket=$s->fetch()?:fail('ไม่พบคำขอ',404);
        $request=request_edit_by_token($body); // Recheck after the ticket lock to prevent replay/resend races.
        request_edit_apply($ticket,$body,null,(int)$request['id']);
        $pdo->prepare("UPDATE ticket_edit_requests SET status='submitted',submitted_at=NOW() WHERE id=?")->execute([$request['id']]);
        archive_enqueue($id);$pdo->commit();
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
    return ['ticket_no'=>$ticket['ticket_no'],'message'=>'ส่งรายละเอียดที่แก้ไขกลับให้ IT แล้ว โดยคงลายเซ็นเดิม'];
}
