<?php
declare(strict_types=1);

function completion_matches(?array $approval, array $ticket): bool
{
    return $approval !== null && $approval['work_revision'] !== null
        && (int)$approval['work_revision'] === (int)$ticket['completion_revision']
        && $approval['resolution_snapshot'] === $ticket['resolution'];
}

/** FM-IT-003: ผอ./ผจก. signs the repair result independently of closing the ticket (FM-IT-010 stays a gate). */
function completion_optional(array $ticket): bool
{
    return $ticket['type'] === 'repair';
}

function approver_id_by_name(string $name): ?int
{
    $name = preg_replace('/\s+/u',' ',trim($name));
    if ($name === '') return null;
    foreach (employee_approvers() as $employee) {
        if (preg_replace('/\s+/u',' ',trim($employee['full_name'])) === $name) return (int)$employee['id'];
    }
    return null;
}

function completion_default_approver_id(): ?int
{
    return approver_id_by_name((string)cfg('completion_default_approver_name','สมไชย อักษร'));
}

/** Default recipient per FM-IT-007 signing role (IT may still choose someone else). */
function purchase_default_approver_ids(): array
{
    $names = (array)cfg('purchase_default_approver_names',['director'=>'วิศวะนนท์ อรุณเจริญพร','md'=>'สมไชย อักษร']);
    $ids = [];
    foreach (array_keys(PURCHASE_SIGNING_ROLES) as $role) $ids[$role] = approver_id_by_name((string)($names[$role] ?? ''));
    return $ids;
}

/** Save the result and request its signature; each changed version keeps its own evidence. */
function completion_request(int $id, array $body, array $admin): array
{
    $in = new Input($body);
    $employee = approval_employee($in);
    $resolution = $in->str('resolution',5000,true,'ผลการดำเนินการ');
    $in->check();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM tickets WHERE id=? AND deleted_at IS NULL FOR UPDATE');
        $s->execute([$id]);
        $ticket = $s->fetch() ?: fail('ไม่พบคำขอ',404);
        $optional = completion_optional($ticket);
        if ($ticket['type'] !== 'service' && !$optional) fail('ขั้นตอนนี้ใช้กับคำขอ FM-IT-010 และใบแจ้งซ่อม FM-IT-003',422);
        if ($optional) {
            // Repair sign-off is not a closing gate: it may be requested after done, but never rewrites a closed result.
            if (in_array($ticket['status'],['rejected','cancelled'],true)) fail('ใบแจ้งซ่อมนี้ปฏิเสธหรือยกเลิกแล้ว',409);
            if ($ticket['status'] === 'done' && $ticket['resolution'] !== $resolution) fail('งานปิดแล้ว ต้องเปิดงานใหม่ก่อนแก้ผลการดำเนินการ',409);
        } else {
            if (in_array($ticket['status'],TICKET_CLOSED_STATUSES,true)) fail('ต้องเปิดงานก่อนขอลายเซ็นรับรองผล',409);
            if ((approval_for_ticket($id)['status'] ?? '') !== 'approved') fail('ต้องให้หัวหน้าฝ่ายลงนามอนุมัติก่อนขอรับรองผล',422);
        }
        $revision = (int)$ticket['completion_revision'] + ($ticket['resolution'] !== $resolution ? 1 : 0);
        $ticket['completion_revision'] = $revision;
        $ticket['resolution'] = $resolution;
        $latest = approval_for_ticket($id,'completion');
        $resend = $latest && $latest['status'] === 'pending' && completion_matches($latest,$ticket);
        if ($latest && $latest['status'] === 'approved' && completion_matches($latest,$ticket)) fail($optional ? 'ผอ./ผจก. ลงนามรับรองผลนี้แล้ว' : 'ผอ./ผจก. ลงนามรับรองผลนี้แล้ว สามารถปิดงานได้',409);
        if ($resend && $latest['expires_at'] && strtotime($latest['expires_at'])-7*86400 > time()-60) fail('กรุณารอ 1 นาทีก่อนส่งอีกครั้ง',429);
        $pdo->prepare('UPDATE tickets SET resolution=?,completion_revision=? WHERE id=?')->execute([$resolution,$revision,$id]);
        it_signature_capture($ticket,$admin);
        if (!$resend) {
            $pdo->prepare('INSERT INTO ticket_approvals (ticket_id,stage,attempt,work_revision,resolution_snapshot,employee_id,approver_name,approver_email,approver_department,approver_company)
                VALUES (?,\'completion\',?,?,?,?,?,?,?,?)')->execute([$id,($latest['attempt'] ?? 0)+1,$revision,$resolution,$employee['id'],$employee['full_name'],$employee['email'],$employee['department'],$employee['company']]);
            $pdo->prepare('INSERT INTO ticket_logs (ticket_id,admin_id,note,is_public) VALUES (?,?,?,1)')
                ->execute([$id,$admin['id'],$optional ? 'IT ขอ ผอ./ผจก. ลงนามรับรองผลงานซ่อม (ปิดงานได้โดยไม่ต้องรอ)' : 'IT ส่งผลการดำเนินการเพื่อขอลายเซ็น ผอ./ผจก. ก่อนปิดงาน']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    approval_send($id,(bool)$resend,(int)$employee['id'],'completion');
    return ticket_admin($id);
}
