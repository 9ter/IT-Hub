<?php
declare(strict_types=1);

function ticket_approval_role(string $type): string
{
    return $type==='purchase'?'ผู้มีอำนาจอนุมัติต้นสังกัด (หน./ผจก.)':'หัวหน้าฝ่าย / ผู้อนุมัติ';
}

function ticket_approval_summary(array $ticket): string
{
    $d=json_decode($ticket['details'] ?: '{}',true);
    if ($ticket['type']!=='purchase') return implode(', ',array_map(fn($k)=>SERVICE_ACTIONS[$k]??$k,$d['actions']??[]));
    $lines=[implode(', ',array_map(fn($k)=>PURCHASE_ITEMS[$k]??$k,$d['items']??[])),
        'ความต้องการ: '.(!empty($d['urgent'])?'ด่วน':'ไม่ด่วน')];
    foreach(['need_by'=>'ต้องการภายในวันที่','position'=>'ตำแหน่งผู้ขอ','spec_cpu'=>'CPU','spec_ram'=>'RAM','spec_storage'=>'SSD/HDD','spec_display'=>'ขนาดจอ','spec_other'=>'สเปกอื่นๆ'] as $key=>$label) {
        if (!empty($d[$key])) $lines[]=$label.': '.$d[$key];
    }
    return implode("\n",$lines);
}

function approval_for_ticket(int $id, string $stage = 'request'): ?array
{
    $stmt = db()->prepare('SELECT id,stage,attempt,work_revision,resolution_snapshot,employee_id,approver_name,approver_email,approver_department,approver_company,status,expires_at,
        mail_status,mail_attempts,mail_sent_at,mail_error,signature_data,decision_note,signed_at FROM ticket_approvals WHERE ticket_id=? AND stage=? ORDER BY attempt DESC LIMIT 1');
    $stmt->execute([$id,$stage]);
    return $stmt->fetch() ?: null;
}

function approval_employee(Input $in): ?array
{
    $id = $in->int('approver_id', 1, PHP_INT_MAX, true, 'หัวหน้าฝ่าย / ผู้อนุมัติ');
    $stmt = db()->prepare('SELECT * FROM employees WHERE id=? AND is_active=1 AND can_approve=1');
    $stmt->execute([$id]);
    $employee = $stmt->fetch();
    if (!$employee || !filter_var($employee['email'], FILTER_VALIDATE_EMAIL)) {
        $in->error('approver_id', 'กรุณาเลือกผู้อนุมัติที่ใช้งานอยู่และมี E-mail ถูกต้อง');
    }
    return $employee ?: null;
}

function approval_create(int $ticketId, array $employee): void
{
    // Snapshot recipient identity so later directory changes cannot alter a signed record.
    db()->prepare('INSERT INTO ticket_approvals (ticket_id,employee_id,approver_name,approver_email,approver_department,approver_company) VALUES (?,?,?,?,?,?)')
        ->execute([$ticketId,$employee['id'],$employee['full_name'],$employee['email'],$employee['department'],$employee['company']]);
}

function approval_mailer(): \PHPMailer\PHPMailer\PHPMailer
{
    require_once __DIR__ . '/../vendor/autoload.php';
    if (!cfg('smtp.enabled')) throw new RuntimeException('SMTP_DISABLED');
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = cfg('smtp.host');
    $mail->Port = (int)cfg('smtp.port', 465);
    $mail->SMTPAuth = true;
    $mail->Username = cfg('smtp.username');
    $mail->Password = cfg('smtp.password');
    $mail->SMTPSecure = cfg('smtp.encryption', 'ssl');
    $mail->Timeout = 15;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom(cfg('smtp.from_email'), 'IT Hub');
    return $mail;
}

function approval_send(int $ticketId, bool $resend = false, ?int $employeeId = null, string $stage = 'request'): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM tickets WHERE id=? FOR UPDATE');
        $s->execute([$ticketId]);
        $ticket = $s->fetch() ?: fail('ไม่พบคำขอ', 404);
        $s = $pdo->prepare('SELECT * FROM ticket_approvals WHERE ticket_id=? AND stage=? ORDER BY attempt DESC LIMIT 1 FOR UPDATE');
        $s->execute([$ticketId,$stage]);
        $a = $s->fetch() ?: fail('คำขอนี้ไม่มีขั้นตอนอนุมัติ', 404);
        $purchaseExtra=$ticket['type']==='purchase'&&purchase_stage($stage);
        if ($a['status'] !== 'pending' || (in_array($ticket['status'], TICKET_CLOSED_STATUSES, true)&&!($purchaseExtra&&$ticket['status']==='done'))) fail('คำขอนี้ไม่อยู่ระหว่างรออนุมัติ', 409);
        if($purchaseExtra)purchase_approval_check($a,$ticket);
        if ($stage === 'completion' && (!completion_matches($a,$ticket) || (approval_for_ticket($ticketId)['status'] ?? '') !== 'approved')) {
            fail('ผลการดำเนินการเปลี่ยนแล้ว กรุณาส่งขอลายเซ็น ผอ./ผจก. ใหม่',409);
        }
        if ($resend && $a['expires_at'] && strtotime($a['expires_at']) - 7*86400 > time()-60) fail('กรุณารอ 1 นาทีก่อนส่งอีกครั้ง', 429);
        if ($employeeId !== null) {
            $in = new Input(['approver_id' => $employeeId]);
            $employee = approval_employee($in);
            $in->check();
            $a['employee_id'] = $employee['id'];
            $a['approver_name'] = $employee['full_name'];
            $a['approver_email'] = $employee['email'];
            $a['approver_department'] = $employee['department'];
            $a['approver_company'] = $employee['company'];
            $pdo->prepare('UPDATE ticket_approvals SET employee_id=?,approver_name=?,approver_email=?,approver_department=?,approver_company=? WHERE id=?')
                ->execute([$a['employee_id'],$a['approver_name'],$a['approver_email'],$a['approver_department'],$a['approver_company'],$a['id']]);
            if ($stage === 'request') {
                $details = json_decode($ticket['details'] ?: '{}', true);
                $details['approver_name'] = $a['approver_name'];
                $pdo->prepare('UPDATE tickets SET details=? WHERE id=?')->execute([json_encode($details,JSON_UNESCAPED_UNICODE),$ticketId]);
            }
        }
        $s = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE id=? AND is_active=1 AND can_approve=1 AND email=?');
        $s->execute([$a['employee_id'],$a['approver_email']]);
        if (!$s->fetchColumn()) fail('ผู้อนุมัติถูกปิดใช้งานหรือเปลี่ยน E-mail กรุณาตรวจข้อมูลพนักงาน', 409);
        $token = bin2hex(random_bytes(32));
        $url = rtrim(cfg('app_url'), '/') . '/approve#' . $token;
        $status = 'sent';
        $error = null;
        try {
            $mail = approval_mailer();
            $mail->addAddress($a['approver_email'], $a['approver_name']);
            $label = $purchaseExtra?purchase_stage_label($stage):($stage === 'completion' ? 'ผอ./ผจก. ฝ่ายสนับสนุนและติดตั้ง' : ticket_approval_role($ticket['type']));
            $mail->Subject = '[IT Hub] ' . ($stage === 'completion' ? 'ขอลงนามรับรองผลก่อนปิดงาน ' : 'ขอลงนามอนุมัติ ') . $ticket['ticket_no'];
            $form = $ticket['type']==='purchase'?'FM-IT-007 Rev.02':'FM-IT-010 Rev.01';
            $summary = ticket_approval_summary($ticket);
            $work = $stage === 'completion' ? "\n\nผลการดำเนินการที่ขอรับรองก่อนปิดงาน:\n{$a['resolution_snapshot']}" : '';
            if($purchaseExtra){
                $snapshot=json_decode($a['resolution_snapshot'],true);$lines=[];
                foreach(['vendor'=>'บริษัท / ร้าน','vendor_phone'=>'โทรศัพท์ผู้ขาย','quotation'=>'ใบเสนอราคาเลขที่','quotation_date'=>'วันที่ใบเสนอราคา','amount'=>'รวมเป็นเงิน (บาท)','vat'=>'VAT'] as $key=>$caption){$v=$snapshot['procurement'][$key]??null;if($v!==null)$lines[]=$caption.': '.($key==='vat'?($v==='included'?'รวม VAT':'ไม่รวม VAT'):$v);}
                $work="\n\nข้อมูลจัดซื้อที่ขอลงนาม:\n".implode("\n",$lines)."\nผลการดำเนินการ:\n".($snapshot['resolution']??'')."\n\nการลงนามเอกสารแยกจากสถานะงาน IT และลงนามได้หลังปิดงาน";
            }
            $mail->Body = "เรียน {$a['approver_name']}\n\nขอลงนาม $label ตามแบบฟอร์ม $form\nเลขที่ {$ticket['ticket_no']}\nผู้ร้องขอ: {$ticket['requester_name']}\nฝ่าย/แผนก: {$ticket['requester_dept']}\nรายการ: $summary\nรายละเอียด: {$ticket['description']}$work\n\nเปิดเพื่อตรวจสอบและลงลายเซ็น:\n$url\n\nลิงก์ใช้ได้ 7 วัน ใช้ลงนามได้ครั้งเดียว กรุณาอย่าส่งต่อลิงก์นี้\nการเปิดอีเมลหรือลิงก์ยังไม่ถือว่าอนุมัติ ต้องลงนามและกดยืนยันในหน้าเว็บ\n";
            $mail->send();
        } catch (Throwable $e) {
            // Never persist SMTP server messages: they can contain credentials or recipient details.
            $status = 'failed';
            $error = 'ส่งอีเมลไม่สำเร็จ กรุณาตรวจ SMTP แล้วกดส่งใหม่';
        }
        $pdo->prepare('UPDATE ticket_approvals SET token_hash=?,expires_at=DATE_ADD(NOW(),INTERVAL 7 DAY),mail_status=?,mail_attempts=mail_attempts+1,mail_error=?,mail_sent_at=? WHERE id=?')
            ->execute([hash('sha256', $token),$status,$error,$status === 'sent' ? now() : null,$a['id']]);
        $pdo->prepare('INSERT INTO ticket_logs (ticket_id,note,is_public) VALUES (?,?,0)')->execute([$ticketId,$status === 'sent' ? ($stage === 'completion' ? 'ส่งอีเมลขอลงนาม ผอ./ผจก. รับรองผลก่อนปิดงานแล้ว' : 'ส่งอีเมลขอลงนามผู้อนุมัติแล้ว') : $error]);
        $pdo->commit();
        return ['mail_status' => $status];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function approval_by_token(string $token): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) fail('ลิงก์ไม่ถูกต้องหรือหมดอายุ', 404);
    $s = db()->prepare('SELECT a.*,t.ticket_no,t.type,t.title,t.description,t.details,t.requester_name,t.requester_dept,t.status ticket_status,
        t.created_at ticket_created_at,t.completion_revision,t.resolution,ast.asset_code,ast.brand_model asset_model
        FROM ticket_approvals a JOIN tickets t ON t.id=a.ticket_id LEFT JOIN assets ast ON ast.id=t.asset_id AND t.type=\'repair\' WHERE a.token_hash=?');
    $s->execute([hash('sha256', $token)]);
    $a = $s->fetch() ?: fail('ลิงก์ไม่ถูกต้องหรือหมดอายุ', 404);
    if ($a['status'] !== 'pending') fail('คำขอนี้ลงนามแล้ว ไม่สามารถใช้ลิงก์ซ้ำได้', 409);
    if (!$a['expires_at'] || strtotime($a['expires_at']) < time()) fail('ลิงก์หมดอายุ กรุณาติดต่อ IT เพื่อส่งอีเมลใหม่', 410);
    $purchaseExtra=$a['type']==='purchase'&&purchase_stage($a['stage']);
    if (in_array($a['ticket_status'], TICKET_CLOSED_STATUSES, true)&&!($purchaseExtra&&$a['ticket_status']==='done')) fail('คำขอนี้ปิดหรือยกเลิกแล้ว', 409);
    if($purchaseExtra)purchase_approval_check($a,$a);
    if ($a['stage'] === 'completion') {
        $latest = approval_for_ticket((int)$a['ticket_id'],'completion');
        if (!$latest || (int)$latest['id'] !== (int)$a['id'] || !completion_matches($a,$a)
            || (approval_for_ticket((int)$a['ticket_id'])['status'] ?? '') !== 'approved') {
            fail('คำขอรับรองผลนี้ถูกแทนที่หรือผลการดำเนินการเปลี่ยนแล้ว กรุณาให้ IT ส่งลิงก์ใหม่',409);
        }
    }
    $s = db()->prepare('SELECT COUNT(*) FROM employees WHERE id=? AND is_active=1 AND can_approve=1 AND email=?');
    $s->execute([$a['employee_id'],$a['approver_email']]);
    if (!$s->fetchColumn()) fail('ผู้อนุมัติไม่พร้อมใช้งาน กรุณาติดต่อ IT', 409);
    return $a;
}

function approval_view(array $body, ?PDO $signatureSource = null): array
{
    $a = approval_by_token(is_string($body['token'] ?? null) ? $body['token'] : '');
    // Token is only accepted in a POST body, never in an access-log URL.
    $view = array_intersect_key($a, array_flip(['type','stage','resolution_snapshot','ticket_no','title','description','details','requester_name','requester_dept','ticket_created_at','asset_code','asset_model','approver_name','approver_company','approver_department','expires_at']));
    $signature = employee_saved_signature((int)$a['employee_id'],$a['approver_email'],$signatureSource);
    if($a['type']==='purchase'&&purchase_stage($a['stage'])){
        $snapshot=json_decode($a['resolution_snapshot'],true);
        $view['approval_role']=purchase_stage_label($a['stage']);
        $view['purchase_fields']=$snapshot['procurement'];
        $view['resolution_snapshot']=$snapshot['resolution'];
    }
    return [...$view,'saved_signature_data'=>$signature,'saved_signature_hash'=>$signature !== null ? hash('sha256',$signature) : null];
}

function approval_decide(array $body, ?PDO $signatureSource = null): array
{
    $in = new Input($body);
    $decision = $in->enum('decision', ['approved','rejected'], true, 'ผลการอนุมัติ');
    $note = $in->str('decision_note', 2000, $decision === 'rejected', 'เหตุผลที่ไม่อนุมัติ');
    if (!$in->bool('consent')) $in->error('consent', 'กรุณายืนยันการลงนาม');
    $useSaved = $in->bool('use_saved_signature');
    $expectedHash = $useSaved ? $in->str('saved_signature_hash',64,true,'ลายเซ็นที่ตรวจสอบแล้ว') : null;
    $signature = $useSaved ? null : validate_signature($in);
    $in->check();
    $token = is_string($body['token'] ?? null) ? $body['token'] : '';
    $a = approval_by_token($token);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT status FROM tickets WHERE id=? FOR UPDATE');
        $s->execute([$a['ticket_id']]);
        $s = $pdo->prepare('SELECT id FROM ticket_approvals WHERE id=? FOR UPDATE');
        $s->execute([$a['id']]);
        $a = approval_by_token($token); // Recheck after acquiring locks; concurrent decisions cannot both succeed.
        if ($useSaved) {
            $signature = employee_saved_signature((int)$a['employee_id'],$a['approver_email'],$signatureSource);
            if ($signature === null) fail('ไม่พบลายเซ็นเดิม กรุณาวาดลายเซ็นใหม่เพื่อยืนยัน',409);
            if (!hash_equals(hash('sha256',$signature),$expectedHash)) fail('ลายเซ็นต้นทางเปลี่ยนแล้ว กรุณาโหลดหน้าใหม่และตรวจสอบอีกครั้ง',409);
        }
        $pdo->prepare('UPDATE ticket_approvals SET status=?,signature_data=?,decision_note=?,signed_at=NOW(),signed_ip=? WHERE id=?')
            ->execute([$decision,$signature,$note,client_ip(),$a['id']]);
        $rejectTicket = $decision === 'rejected' && $a['stage'] === 'request';
        if ($rejectTicket) $pdo->prepare("UPDATE tickets SET status='rejected',finished_at=NOW() WHERE id=?")->execute([$a['ticket_id']]);
        $purchaseExtra=$a['type']==='purchase'&&purchase_stage($a['stage']);
        $pdo->prepare('INSERT INTO ticket_logs (ticket_id,from_status,to_status,note,is_public) VALUES (?,?,?,?,1)')
            ->execute([$a['ticket_id'],$rejectTicket ? $a['ticket_status'] : null,$rejectTicket ? 'rejected' : null,
                $purchaseExtra ? purchase_stage_label($a['stage']).($decision==='approved'?' ลงนามเอกสารจัดซื้อแล้ว':' ไม่รับรองเอกสารจัดซื้อ (สถานะงานไม่เปลี่ยน)') : ($a['stage'] === 'completion'
                    ? ($decision === 'approved' ? 'ผอ./ผจก. ลงนามรับรองผลการดำเนินการแล้ว พร้อมให้ IT ปิดงาน' : 'ผอ./ผจก. ไม่รับรองผลการดำเนินการ กรุณาให้ IT ตรวจสอบและขอลายเซ็นใหม่')
                    : ticket_approval_role($a['type']).($decision === 'approved' ? 'ลงนามอนุมัติแล้ว' : 'ลงนามไม่อนุมัติ'))]);
        if ($useSaved) {
            $pdo->prepare('INSERT INTO ticket_logs (ticket_id,note,is_public) VALUES (?,?,0)')
                ->execute([$a['ticket_id'],'ผู้อนุมัติยืนยันใช้ลายเซ็นที่บันทึกไว้ใน E-Procurement']);
        }
        archive_enqueue((int)$a['ticket_id']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok'=>true,'status'=>$decision];
}
