<?php
declare(strict_types=1);

function admin_signature_save(int $id, array $body): array
{
    $in = new Input($body);
    $signature = validate_signature($in);
    $in->check();
    $s = db()->prepare('SELECT id FROM admins WHERE id=?');
    $s->execute([$id]);
    if (!$s->fetchColumn()) fail('ไม่พบผู้ดูแลระบบ',404);
    db()->prepare('UPDATE admins SET signature_data=?,signature_updated_at=NOW() WHERE id=?')->execute([$signature,$id]);
    return admin_list();
}

function admin_signature_delete(int $id): array
{
    $s = db()->prepare('SELECT id FROM admins WHERE id=?');
    $s->execute([$id]);
    if (!$s->fetchColumn()) fail('ไม่พบผู้ดูแลระบบ',404);
    db()->prepare('UPDATE admins SET signature_data=NULL,signature_updated_at=NOW() WHERE id=?')->execute([$id]);
    return admin_list();
}

function it_signature_for_ticket(array $ticket): ?array
{
    $s = db()->prepare('SELECT admin_id,signer_name,signature_data,signed_at,work_revision,resolution_snapshot
        FROM ticket_it_signatures WHERE ticket_id=? AND work_revision=?');
    $s->execute([$ticket['id'],$ticket['completion_revision']]);
    $signature = $s->fetch();
    if (!$signature || $signature['resolution_snapshot'] !== $ticket['resolution']) return null;
    unset($signature['resolution_snapshot']);
    return $signature;
}

/** Called with the ticket locked: the actor's profile is copied once per result revision. */
function it_signature_capture(array $ticket, array $actor, bool $required = false): ?array
{
    if ($existing = it_signature_for_ticket($ticket)) return $existing;
    if (trim((string)$ticket['resolution']) === '') {
        if ($required) fail('กรุณากรอกผลการดำเนินการก่อนลงนามเจ้าหน้าที่ IT',422);
        return null;
    }
    $s = db()->prepare('SELECT display_name,signature_data FROM admins WHERE id=? AND is_active=1');
    $s->execute([$actor['id']]);
    $profile = $s->fetch();
    if (!$profile || !$profile['signature_data']) {
        if ($required) fail('กรุณาเพิ่มลายเซ็นของคุณในหน้าผู้ดูแลระบบก่อน',422);
        return null;
    }
    db()->prepare('INSERT INTO ticket_it_signatures (ticket_id,work_revision,resolution_snapshot,admin_id,signer_name,signature_data,signed_ip)
        VALUES (?,?,?,?,?,?,?)')->execute([$ticket['id'],$ticket['completion_revision'],$ticket['resolution'],$actor['id'],$profile['display_name'],$profile['signature_data'],client_ip()]);
    return it_signature_for_ticket($ticket);
}

function it_ticket_sign(int $id, array $actor): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM tickets WHERE id=? FOR UPDATE');
        $s->execute([$id]);
        $ticket = $s->fetch() ?: fail('ไม่พบคำขอ',404);
        if (in_array($ticket['status'],['rejected','cancelled'],true)) fail('คำขอนี้ปฏิเสธหรือยกเลิกแล้ว',409);
        $head = approval_for_ticket($id);
        if ($head && $head['status'] !== 'approved') fail('ต้องได้รับอนุมัติจากหัวหน้าฝ่ายก่อนลงนามผลดำเนินการ',422);
        if (it_signature_for_ticket($ticket)) fail('ผลการดำเนินการรอบนี้มีลายเซ็นเจ้าหน้าที่ IT แล้ว',409);
        it_signature_capture($ticket,$actor,true);
        $pdo->prepare('INSERT INTO ticket_logs (ticket_id,admin_id,note,is_public) VALUES (?,?,?,0)')
            ->execute([$id,$actor['id'],'เจ้าหน้าที่ IT ยืนยันลงนามผลการดำเนินการด้วยลายเซ็นที่บันทึกไว้']);
        archive_enqueue($id);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ticket_admin($id);
}
