<?php
declare(strict_types=1);

function dashboard(): array
{
    $pdo = db();
    $open = "'" . implode("','", TICKET_OPEN_STATUSES) . "'";

    $byStatus = $pdo->query('SELECT status, COUNT(*) AS n FROM tickets WHERE deleted_at IS NULL GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $openByType = $pdo->query("SELECT type, COUNT(*) AS n FROM tickets WHERE deleted_at IS NULL AND status IN ($open) GROUP BY type")->fetchAll(PDO::FETCH_KEY_PAIR);

    $month = $pdo->query("SELECT
            SUM(created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS created_month,
            SUM(status = 'done' AND finished_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS done_month,
            SUM(DATE(created_at) = CURDATE()) AS created_today,
            AVG(CASE WHEN status = 'done' AND finished_at >= CURDATE() - INTERVAL 30 DAY
                THEN TIMESTAMPDIFF(MINUTE, created_at, finished_at) END) AS avg_minutes_30d
        FROM tickets WHERE deleted_at IS NULL")->fetch();

    $queue = $pdo->query("SELECT t.id, t.ticket_no, t.type, t.title, t.status, t.priority, t.requester_name, t.requester_dept,
            t.created_at, a.asset_code, ad.display_name AS assigned_name
        FROM tickets t LEFT JOIN assets a ON a.id = t.asset_id AND t.type = 'repair' LEFT JOIN admins ad ON ad.id = t.assigned_to
        WHERE t.deleted_at IS NULL AND t.status IN ($open)
        ORDER BY FIELD(t.priority, 'urgent', 'high', 'normal', 'low'), FIELD(t.status, 'new') DESC, t.created_at
        LIMIT 15")->fetchAll();

    $assets = $pdo->query('SELECT c.name, COUNT(a.id) AS n FROM asset_categories c LEFT JOIN assets a ON a.category_id = c.id AND a.status <> \'retired\'
        GROUP BY c.id HAVING n > 0 ORDER BY n DESC')->fetchAll(PDO::FETCH_KEY_PAIR);

    return [
        'by_status' => array_map('intval', $byStatus),
        'open_by_type' => array_map('intval', $openByType),
        'open_total' => array_sum(array_map('intval', array_intersect_key($byStatus, array_flip(TICKET_OPEN_STATUSES)))),
        'created_today' => (int)$month['created_today'],
        'created_month' => (int)$month['created_month'],
        'done_month' => (int)$month['done_month'],
        'avg_minutes_30d' => $month['avg_minutes_30d'] === null ? null : (int)round((float)$month['avg_minutes_30d']),
        'queue' => $queue,
        'assets_by_category' => array_map('intval', $assets),
    ];
}

function ticket_list(array $q, ?int $unreadBy = null): array
{
    $where = [];
    $params = [];
    if ($unreadBy !== null) {
        $where[] = 'NOT EXISTS (SELECT 1 FROM ticket_reads r WHERE r.ticket_id = t.id AND r.admin_id = ?)';
        $params[] = $unreadBy;
    }
    $status = (string)($q['status'] ?? '');
    $where[] = $status === 'deleted' ? 't.deleted_at IS NOT NULL' : 't.deleted_at IS NULL';
    if ($status === 'open') {
        $where[] = "t.status IN ('" . implode("','", TICKET_OPEN_STATUSES) . "')";
    } elseif (isset(TICKET_STATUSES[$status])) {
        $where[] = 't.status = ?';
        $params[] = $status;
    }
    if (isset(ticket_types()[$q['type'] ?? ''])) {
        $where[] = 't.type = ?';
        $params[] = $q['type'];
    }
    if (!empty($q['assigned_to'])) {
        $where[] = $q['assigned_to'] === 'none' ? 't.assigned_to IS NULL' : 't.assigned_to = ?';
        if ($q['assigned_to'] !== 'none') {
            $params[] = (int)$q['assigned_to'];
        }
    }
    if (($search = trim((string)($q['q'] ?? ''))) !== '') {
        $where[] = '(t.ticket_no LIKE ? OR t.title LIKE ? OR t.requester_name LIKE ? OR t.requester_dept LIKE ? OR a.asset_code LIKE ?'
            . ' OR JSON_UNQUOTE(JSON_EXTRACT(t.details, \'$.requester_company.name\')) LIKE ?)';
        array_push($params, ...array_fill(0, 6, '%' . $search . '%'));
    }
    if (!empty($q['from'])) {
        $where[] = 't.created_at >= ?';
        $params[] = $q['from'] . ' 00:00:00';
    }
    if (!empty($q['to'])) {
        $where[] = 't.created_at <= ?';
        $params[] = $q['to'] . ' 23:59:59';
    }

    $sql = 'SELECT t.id, t.ticket_no, t.type, t.title, t.status, t.priority, t.requester_name, t.requester_dept,
            JSON_UNQUOTE(JSON_EXTRACT(t.details, \'$.requester_company.name\')) AS requester_company,
            t.created_at, t.finished_at, t.deleted_at, t.delete_reason, a.asset_code, ad.display_name AS assigned_name, del.display_name AS deleted_by_name,
            (SELECT ar.status FROM ticket_archives ar WHERE ar.ticket_id=t.id ORDER BY ar.id DESC LIMIT 1) AS nas_archive_status
        FROM tickets t LEFT JOIN assets a ON a.id = t.asset_id AND t.type = \'repair\' LEFT JOIN admins ad ON ad.id = t.assigned_to LEFT JOIN admins del ON del.id = t.deleted_by'
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY t.created_at DESC, t.id DESC';

    return paginate($sql, $params, (int)($q['page'] ?? 1), (int)($q['per_page'] ?? 30));
}

function ticket_mark_read(int $id, array $admin): array
{
    $stmt = db()->prepare('SELECT 1 FROM tickets WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) {
        fail('ไม่พบรายการ', 404);
    }
    db()->prepare('INSERT IGNORE INTO ticket_reads (ticket_id, admin_id) VALUES (?, ?)')->execute([$id, (int)$admin['id']]);
    return ['read' => true];
}

/** Signed (approved/rejected) approvals of any stage that this admin has not seen yet — for the bell. Last 7 days only. */
function signature_notices(int $adminId): array
{
    $from = "FROM ticket_approvals a JOIN tickets t ON t.id=a.ticket_id AND t.deleted_at IS NULL
        WHERE a.status IN ('approved','rejected') AND a.signed_at >= NOW() - INTERVAL 7 DAY
        AND NOT EXISTS (SELECT 1 FROM ticket_approval_reads r WHERE r.approval_id=a.id AND r.admin_id=?)";
    $s = db()->prepare("SELECT COUNT(*) $from");
    $s->execute([$adminId]);
    $total = (int)$s->fetchColumn();
    $s = db()->prepare("SELECT a.id,a.ticket_id,a.stage,a.status,a.approver_name,a.signed_at,t.ticket_no,t.type,t.title $from ORDER BY a.signed_at DESC,a.id DESC LIMIT 10");
    $s->execute([$adminId]);
    $rows = array_map(function (array $r): array {
        $r['id'] = (int)$r['id'];
        $r['ticket_id'] = (int)$r['ticket_id'];
        $r['role'] = purchase_stage($r['stage']) ? purchase_stage_label($r['stage'])
            : ($r['stage'] === 'completion' ? 'ผอ./ผจก. ฝ่ายสนับสนุนและติดตั้ง' : ticket_approval_role($r['type']));
        return $r;
    }, $s->fetchAll());
    return ['data' => $rows, 'total' => $total];
}

function signature_notices_mark_read(int $ticketId, array $admin): array
{
    db()->prepare("INSERT IGNORE INTO ticket_approval_reads (approval_id,admin_id)
        SELECT a.id, ? FROM ticket_approvals a JOIN tickets t ON t.id=a.ticket_id AND t.deleted_at IS NULL
        WHERE a.ticket_id=? AND a.status IN ('approved','rejected')")->execute([(int)$admin['id'], $ticketId]);
    return ['read' => true];
}

/** Soft delete: signatures, logs and NAS files are evidence, and the ticket number must never be reissued. */
function ticket_delete(int $id, array $body, array $admin): array
{
    $in = new Input($body);
    $reason = $in->str('reason', 255, true, 'เหตุผลที่ลบ');
    $in->check();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM tickets WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
        $stmt->execute([$id]);
        $stmt->fetchColumn() ?: fail('ไม่พบรายการ', 404);
        $stmt = $pdo->prepare("SELECT d.id, d.document_no FROM ticket_borrow_decisions b JOIN it_documents d ON d.id = b.document_id WHERE b.ticket_id = ? AND d.status <> 'cancelled'");
        $stmt->execute([$id]);
        if ($record = $stmt->fetch()) {
            fail("คำขอนี้มีบันทึกเบิก–ยืม {$record['document_no']} ที่ยังใช้งานอยู่ กรุณายกเลิกบันทึกก่อนแล้วลบอีกครั้ง", 409, ['document_id' => (string)$record['id']]);
        }
        $pdo->prepare('UPDATE tickets SET deleted_at = NOW(), deleted_by = ?, delete_reason = ? WHERE id = ?')->execute([$admin['id'], $reason, $id]);
        $pdo->prepare('INSERT INTO ticket_logs (ticket_id, admin_id, note, is_public) VALUES (?, ?, ?, 0)')->execute([$id, $admin['id'], 'ลบรายการ: ' . $reason]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['deleted' => true];
}

function ticket_restore(int $id, array $admin): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM tickets WHERE id = ? AND deleted_at IS NOT NULL FOR UPDATE');
        $stmt->execute([$id]);
        $stmt->fetchColumn() ?: fail('ไม่พบรายการที่ลบไว้', 404);
        $pdo->prepare('UPDATE tickets SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL WHERE id = ?')->execute([$id]);
        $pdo->prepare('INSERT INTO ticket_logs (ticket_id, admin_id, note, is_public) VALUES (?, ?, ?, 0)')->execute([$id, $admin['id'], 'กู้คืนรายการที่ลบ']);
        archive_enqueue($id); // The worker marks jobs obsolete while a ticket is deleted.
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['restored' => true];
}

function admin_list(): array
{
    return db()->query('SELECT id, username, display_name, is_active, last_login_at, created_at, signature_data, signature_updated_at FROM admins ORDER BY display_name')->fetchAll();
}

function admin_save(?int $id, array $body, array $me): array
{
    $in = new Input($body);
    $displayName = $in->str('display_name', 100, true, 'ชื่อที่แสดง');
    $isActive = $in->bool('is_active', true);

    if ($id === null) {
        $username = $in->str('username', 50, true, 'ชื่อผู้ใช้');
        if ($username !== null && !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
            $in->error('username', 'ชื่อผู้ใช้ใช้ได้เฉพาะ a-z, 0-9, . _ - (3 ตัวขึ้นไป)');
        }
        $password = validate_password($in, 'password');
        $dup = db()->prepare('SELECT COUNT(*) FROM admins WHERE username = ?');
        $dup->execute([$username]);
        if ($dup->fetchColumn()) {
            $in->error('username', 'ชื่อผู้ใช้นี้มีอยู่แล้ว');
        }
        $in->check();
        db()->prepare('INSERT INTO admins (username, password_hash, display_name, is_active) VALUES (?, ?, ?, ?)')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $displayName, (int)$isActive]);
    } else {
        $password = validate_password($in, 'password', false);
        if ($id === $me['id'] && !$isActive) {
            $in->error('is_active', 'ปิดการใช้งานบัญชีตัวเองไม่ได้');
        }
        $in->check();
        db()->prepare('UPDATE admins SET display_name = ?, is_active = ? WHERE id = ?')->execute([$displayName, (int)$isActive, $id]);
        if ($password !== null) {
            db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        }
    }
    return admin_list();
}

function change_own_password(array $body, array $me): array
{
    $in = new Input($body);
    $current = $in->str('current_password', 200, true, 'รหัสผ่านปัจจุบัน');
    $new = validate_password($in, 'new_password');
    $in->check();

    $stmt = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
    $stmt->execute([$me['id']]);
    if (!password_verify($current, (string)$stmt->fetchColumn())) {
        fail('รหัสผ่านปัจจุบันไม่ถูกต้อง', 422, ['current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
    }
    db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
    return ['ok' => true];
}
