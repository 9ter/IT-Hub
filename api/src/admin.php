<?php
declare(strict_types=1);

function dashboard(): array
{
    $pdo = db();
    $open = "'" . implode("','", TICKET_OPEN_STATUSES) . "'";

    $byStatus = $pdo->query('SELECT status, COUNT(*) AS n FROM tickets GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $openByType = $pdo->query("SELECT type, COUNT(*) AS n FROM tickets WHERE status IN ($open) GROUP BY type")->fetchAll(PDO::FETCH_KEY_PAIR);

    $month = $pdo->query("SELECT
            SUM(created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS created_month,
            SUM(status = 'done' AND finished_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS done_month,
            SUM(DATE(created_at) = CURDATE()) AS created_today,
            AVG(CASE WHEN status = 'done' AND finished_at >= CURDATE() - INTERVAL 30 DAY
                THEN TIMESTAMPDIFF(MINUTE, created_at, finished_at) END) AS avg_minutes_30d
        FROM tickets")->fetch();

    $queue = $pdo->query("SELECT t.id, t.ticket_no, t.type, t.title, t.status, t.priority, t.requester_name, t.requester_dept,
            t.created_at, a.asset_code, ad.display_name AS assigned_name
        FROM tickets t LEFT JOIN assets a ON a.id = t.asset_id AND t.type = 'repair' LEFT JOIN admins ad ON ad.id = t.assigned_to
        WHERE t.status IN ($open)
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

function ticket_list(array $q): array
{
    $where = [];
    $params = [];
    $status = (string)($q['status'] ?? '');
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
        $where[] = '(t.ticket_no LIKE ? OR t.title LIKE ? OR t.requester_name LIKE ? OR t.requester_dept LIKE ? OR a.asset_code LIKE ?)';
        array_push($params, ...array_fill(0, 5, '%' . $search . '%'));
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
            t.created_at, t.finished_at, a.asset_code, ad.display_name AS assigned_name,
            (SELECT ar.status FROM ticket_archives ar WHERE ar.ticket_id=t.id ORDER BY ar.id DESC LIMIT 1) AS nas_archive_status
        FROM tickets t LEFT JOIN assets a ON a.id = t.asset_id AND t.type = \'repair\' LEFT JOIN admins ad ON ad.id = t.assigned_to'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY t.created_at DESC, t.id DESC';

    return paginate($sql, $params, (int)($q['page'] ?? 1), (int)($q['per_page'] ?? 30));
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
