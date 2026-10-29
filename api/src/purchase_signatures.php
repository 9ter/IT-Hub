<?php
declare(strict_types=1);

const PURCHASE_SIGNING_ROLES = [
    'director'=>['stage'=>'purchase_director','label'=>'ผอ./ผจก. ฝ่ายสนับสนุนและติดตั้ง'],
    'purchasing'=>['stage'=>'purchase_purchasing','label'=>'ผู้ตรวจสอบ (ผจก.ฝ่ายจัดซื้อ)'],
    'md'=>['stage'=>'purchase_md','label'=>'ผู้ช่วยกรรมการผู้จัดการ'],
];

function purchase_stage(string $stage): bool
{
    return in_array($stage,array_column(PURCHASE_SIGNING_ROLES,'stage'),true);
}

function purchase_stage_label(string $stage): string
{
    foreach(PURCHASE_SIGNING_ROLES as $r) if($r['stage']===$stage)return $r['label'];
    return '';
}

function purchase_fields(array $ticket): array
{
    $details=is_array($ticket['details'])?$ticket['details']:json_decode($ticket['details']?:'{}',true);
    $data=is_array($details['procurement']??null)?$details['procurement']:[];
    return array_intersect_key($data,array_flip(['vendor','vendor_phone','quotation','quotation_date','amount','vat']))+
        array_fill_keys(['vendor','vendor_phone','quotation','quotation_date','amount','vat'],null);
}

function purchase_fields_parse(mixed $data): array
{
    if(!is_array($data))fail('ข้อมูลจัดซื้อไม่ถูกต้อง',422);
    $in=new Input($data);
    $result=[
        'vendor'=>$in->str('vendor',200,false,'บริษัท / ร้าน'),
        'vendor_phone'=>$in->str('vendor_phone',50,false,'โทรศัพท์ผู้ขาย'),
        'quotation'=>$in->str('quotation',150,false,'เลขที่ใบเสนอราคา'),
        'quotation_date'=>$in->date('quotation_date',false,'วันที่ใบเสนอราคา'),
        'amount'=>$in->str('amount',20,false,'รวมเป็นเงิน'),
        'vat'=>$in->enum('vat',['included','excluded'],false,'VAT'),
    ];
    if($result['quotation_date'] && date('Y-m-d',strtotime($result['quotation_date']))!==$result['quotation_date'])$in->error('quotation_date','วันที่ใบเสนอราคาไม่ถูกต้อง');
    if($result['amount']!==null && !preg_match('/^\d{1,12}(\.\d{1,2})?$/D',$result['amount']))$in->error('amount','จำนวนเงินต้องเป็นตัวเลขที่ไม่ติดลบ และทศนิยมไม่เกิน 2 ตำแหน่ง');
    $in->check();return $result;
}

function purchase_snapshot(array $ticket): string
{
    $request=is_array($ticket['details'])?$ticket['details']:json_decode($ticket['details']?:'{}',true);
    unset($request['procurement']);
    return json_encode(['ticket_no'=>$ticket['ticket_no'],'description'=>$ticket['description'],
        'requester_name'=>$ticket['requester_name'],'requester_dept'=>$ticket['requester_dept'],
        'request'=>$request,'procurement'=>purchase_fields($ticket),'resolution'=>$ticket['resolution']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}

function purchase_approval_matches(?array $approval,array $ticket): bool
{
    return $approval!==null && $ticket['type']==='purchase' && purchase_stage($approval['stage'])
        && (int)$approval['work_revision']===(int)$ticket['completion_revision']
        && $approval['resolution_snapshot']===purchase_snapshot($ticket);
}

function purchase_approval_check(array $approval,array $ticket): void
{
    if($ticket['type']!=='purchase' || in_array($ticket['ticket_status']??$ticket['status'],['rejected','cancelled'],true))fail('ใบขอซื้อนี้ไม่พร้อมให้ลงนาม',409);
    $latest=approval_for_ticket((int)$approval['ticket_id'],$approval['stage']);
    $head=approval_for_ticket((int)$approval['ticket_id']);
    if(!$latest || (int)$latest['id']!==(int)$approval['id'] || !purchase_approval_matches($approval,$ticket) || ($head && $head['status']!=='approved')) {
        fail('ข้อมูลจัดซื้อเปลี่ยนแล้วหรือคำขอลงนามถูกแทนที่ กรุณาให้ IT ส่งลิงก์ใหม่',409);
    }
}

function purchase_approvals_for_ticket(array $ticket): array
{
    $out=[];
    foreach(PURCHASE_SIGNING_ROLES as $key=>$role){
        $a=approval_for_ticket((int)$ticket['id'],$role['stage']);
        $out[$key]=$a?[...$a,'current'=>purchase_approval_matches($a,$ticket)]:null;
    }
    return $out;
}

function receipt_signature_for_ticket(int $id): ?array
{
    $s=db()->prepare('SELECT admin_id,signer_name,signature_data,signed_at FROM ticket_receipt_signatures WHERE ticket_id=?');$s->execute([$id]);return $s->fetch()?:null;
}

/** Called with the ticket locked; the first actual IT recipient remains on the form. */
function receipt_signature_capture(array $ticket,array $actor,bool $required=false): ?array
{
    if($ticket['type']!=='purchase')return null;
    if($existing=receipt_signature_for_ticket((int)$ticket['id']))return $existing;
    $s=db()->prepare('SELECT display_name,signature_data FROM admins WHERE id=? AND is_active=1');$s->execute([$actor['id']]);$p=$s->fetch();
    if(!$p || !$p['signature_data']){
        if($required)fail('กรุณาเพิ่มลายเซ็นของคุณในหน้าผู้ดูแลระบบก่อน',422);
        return null;
    }
    db()->prepare('INSERT INTO ticket_receipt_signatures(ticket_id,admin_id,signer_name,signature_data,signed_ip) VALUES(?,?,?,?,?)')
        ->execute([$ticket['id'],$actor['id'],$p['display_name'],$p['signature_data'],client_ip()]);
    return receipt_signature_for_ticket((int)$ticket['id']);
}

function receipt_ticket_sign(int $id,array $body,array $actor): array
{
    $in=new Input($body);if(!$in->bool('consent'))fail('กรุณายืนยันการลงนามผู้รับเรื่อง',422);
    $pdo=db();$pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT * FROM tickets WHERE id=? FOR UPDATE');$s->execute([$id]);$t=$s->fetch()?:fail('ไม่พบคำขอ',404);
        if($t['type']!=='purchase')fail('ช่องผู้รับเรื่องนี้ใช้กับใบขอซื้อ',422);
        if(in_array($t['status'],['rejected','cancelled'],true))fail('คำขอนี้ปฏิเสธหรือยกเลิกแล้ว',409);
        $head=approval_for_ticket($id);if($head && $head['status']!=='approved')fail('ต้องได้รับอนุมัติต้นสังกัดก่อนลงนามผู้รับเรื่อง',422);
        if(receipt_signature_for_ticket($id))fail('ใบขอซื้อนี้มีลายเซ็นผู้รับเรื่องแล้ว',409);
        receipt_signature_capture($t,$actor,true);
        $pdo->prepare('INSERT INTO ticket_logs(ticket_id,admin_id,note,is_public) VALUES(?,?,?,0)')->execute([$id,$actor['id'],'Admin IT Hub ยืนยันลงนามผู้รับเรื่องด้วยลายเซ็นของตน']);
        archive_enqueue($id);
        $pdo->commit();
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
    return ticket_admin($id);
}

/** Each of the three roles signs independently; none is a ticket completion gate. */
function purchase_signature_request(int $id,array $body,array $actor): array
{
    $in=new Input($body);$role=$in->enum('role',array_keys(PURCHASE_SIGNING_ROLES),true,'ตำแหน่งผู้ลงนาม');
    $employee=approval_employee($in);$resolution=$in->str('resolution',5000,false,'ผลการดำเนินการ');$in->check();
    $proc=array_key_exists('purchase_fields',$body)?purchase_fields_parse($body['purchase_fields']):null;
    $pdo=db();$pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT * FROM tickets WHERE id=? FOR UPDATE');$s->execute([$id]);$t=$s->fetch()?:fail('ไม่พบคำขอ',404);
        if($t['type']!=='purchase')fail('ขั้นตอนนี้ใช้กับใบขอซื้อ FM-IT-007',422);
        if(isset($body['work_revision']) && (int)$body['work_revision']!==(int)$t['completion_revision'])fail('ข้อมูลใบขอซื้อเปลี่ยนแล้ว กรุณาตรวจสถานะและโหลดข้อมูลใหม่',409);
        if(in_array($t['status'],['rejected','cancelled'],true))fail('ใบขอซื้อนี้ไม่พร้อมส่งขอลงนาม',409);
        $head=approval_for_ticket($id);if($head && $head['status']!=='approved')fail('ต้องได้รับอนุมัติต้นสังกัดก่อนขออีก 3 ลายเซ็น',422);
        $proc??=purchase_fields($t);if(!$in->has('resolution'))$resolution=$t['resolution'];
        $changed=$resolution!==$t['resolution'] || $proc!==purchase_fields($t);
        $t['completion_revision']=(int)$t['completion_revision']+($changed?1:0);$t['resolution']=$resolution;
        $details=json_decode($t['details']?:'{}',true);$details['procurement']=$proc;$t['details']=json_encode($details,JSON_UNESCAPED_UNICODE);
        $stage=PURCHASE_SIGNING_ROLES[$role]['stage'];$latest=approval_for_ticket($id,$stage);
        $resend=$latest && $latest['status']==='pending' && purchase_approval_matches($latest,$t);
        if($latest && $latest['status']==='approved' && purchase_approval_matches($latest,$t))fail('ตำแหน่งนี้ลงนามสำหรับข้อมูลปัจจุบันแล้ว',409);
        // Enforce the resend interval before committing any draft changes.
        if($resend && $latest['expires_at'] && strtotime($latest['expires_at'])-7*86400>time()-60)fail('กรุณารอ 1 นาทีก่อนส่งอีกครั้ง',429);
        $pdo->prepare('UPDATE tickets SET details=?,resolution=?,completion_revision=? WHERE id=?')->execute([$t['details'],$resolution,$t['completion_revision'],$id]);
        receipt_signature_capture($t,$actor);
        if(!$resend){
            $pdo->prepare("INSERT INTO ticket_approvals(ticket_id,stage,attempt,work_revision,resolution_snapshot,employee_id,approver_name,approver_email,approver_department,approver_company) VALUES(?,?,?,?,?,?,?,?,?,?)")
                ->execute([$id,$stage,($latest['attempt']??0)+1,$t['completion_revision'],purchase_snapshot($t),$employee['id'],$employee['full_name'],$employee['email'],$employee['department'],$employee['company']]);
            $pdo->prepare('INSERT INTO ticket_logs(ticket_id,admin_id,note,is_public) VALUES(?,?,?,0)')->execute([$id,$actor['id'],'IT ขอ '.PURCHASE_SIGNING_ROLES[$role]['label'].' ลงนามเอกสารจัดซื้อ (ปิดงานได้โดยไม่ต้องรอ)']);
        }
        $pdo->commit();
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
    archive_enqueue($id);
    approval_send($id,(bool)$resend,(int)$employee['id'],$stage);
    return ticket_admin($id);
}
