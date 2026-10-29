<?php
declare(strict_types=1);

function completion_matches(?array $approval, array $ticket): bool
{
    return $approval !== null && $approval['work_revision'] !== null
        && (int)$approval['work_revision'] === (int)$ticket['completion_revision']
        && $approval['resolution_snapshot'] === $ticket['resolution'];
}

function completion_default_approver_id(): ?int
{
    $name = cfg('completion_default_approver_name','สมไชย อักษร');
    foreach (employee_approvers() as $employee) {
        if (preg_replace('/\s+/u',' ',trim($employee['full_name'])) === $name) return (int)$employee['id'];
    }
    return null;
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
        $s = $pdo->prepare('SELECT * FROM tickets WHERE id=? FOR UPDATE');
        $s->execute([$id]);
        $ticket = $s->fetch() ?: fail('ไม่พบคำขอ',404);
        if ($ticket['type'] !== 'service') fail('ขั้นตอนนี้ใช้กับคำขอ FM-IT-010',422);
        if (in_array($ticket['status'],TICKET_CLOSED_STATUSES,true)) fail('ต้องเปิดงานก่อนขอลายเซ็นรับรองผล',409);
        if ((approval_for_ticket($id)['status'] ?? '') !== 'approved') fail('ต้องให้หัวหน้าฝ่ายลงนามอนุมัติก่อนขอรับรองผล',422);
        $revision = (int)$ticket['completion_revision'] + ($ticket['resolution'] !== $resolution ? 1 : 0);
        $ticket['completion_revision'] = $revision;
        $ticket['resolution'] = $resolution;
        $latest = approval_for_ticket($id,'completion');
        $resend = $latest && $latest['status'] === 'pending' && completion_matches($latest,$ticket);
        if ($latest && $latest['status'] === 'approved' && completion_matches($latest,$ticket)) fail('ผอ./ผจก. ลงนามรับรองผลนี้แล้ว สามารถปิดงานได้',409);
        $pdo->prepare('UPDATE tickets SET resolution=?,completion_revision=? WHERE id=?')->execute([$resolution,$revision,$id]);
        it_signature_capture($ticket,$admin);
        if (!$resend) {
            $pdo->prepare('INSERT INTO ticket_approvals (ticket_id,stage,attempt,work_revision,resolution_snapshot,employee_id,approver_name,approver_email,approver_department,approver_company)
                VALUES (?,\'completion\',?,?,?,?,?,?,?,?)')->execute([$id,($latest['attempt'] ?? 0)+1,$revision,$resolution,$employee['id'],$employee['full_name'],$employee['email'],$employee['department'],$employee['company']]);
            $pdo->prepare('INSERT INTO ticket_logs (ticket_id,admin_id,note,is_public) VALUES (?,?,?,1)')
                ->execute([$id,$admin['id'],'IT ส่งผลการดำเนินการเพื่อขอลายเซ็น ผอ./ผจก. ก่อนปิดงาน']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    approval_send($id,(bool)$resend,(int)$employee['id'],'completion');
    return ticket_admin($id);
}
