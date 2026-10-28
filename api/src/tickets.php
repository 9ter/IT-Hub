<?php
declare(strict_types=1);

function find_asset_by_code(string $code): ?array
{
    $stmt = db()->prepare('SELECT a.*, c.name AS category_name, c.icon AS category_icon
        FROM assets a LEFT JOIN asset_categories c ON c.id = a.category_id WHERE a.asset_code = ?');
    $stmt->execute([strtoupper(trim($code))]);
    return $stmt->fetch() ?: null;
}

/** ข้อมูลอุปกรณ์ที่แสดงให้คนทั่วไปเห็นเมื่อสแกน QR (ไม่มี serial / ชื่อจริง / หมายเหตุภายใน) */
function asset_public(string $code): array
{
    $asset = find_asset_by_code($code) ?? fail('ไม่พบอุปกรณ์รหัส ' . $code, 404);

    $stmt = db()->prepare("SELECT ticket_no, type, status, created_at FROM tickets
        WHERE asset_id = ? AND status IN ('" . implode("','", TICKET_OPEN_STATUSES) . "') ORDER BY created_at DESC");
    $stmt->execute([$asset['id']]);
    $open = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT kind, performed_on FROM maintenance_records WHERE asset_id = ? ORDER BY performed_on DESC, id DESC LIMIT 5');
    $stmt->execute([$asset['id']]);

    return [
        'asset_code' => $asset['asset_code'],
        'category' => $asset['category_name'],
        'category_icon' => $asset['category_icon'],
        'company' => $asset['company'],
        'brand_model' => $asset['brand_model'],
        'holder_nickname' => $asset['holder_nickname'],
        'department' => $asset['department'],
        'location' => $asset['location'],
        'status' => $asset['status'],
        'open_tickets' => $open,
        'recent_maintenance' => $stmt->fetchAll(),
    ];
}

function next_ticket_no(string $prefix): string
{
    $base = $prefix . '-' . thai_year_month() . '-';
    $stmt = db()->prepare('SELECT ticket_no FROM tickets WHERE ticket_no LIKE ? ORDER BY ticket_no DESC LIMIT 1 FOR UPDATE');
    $stmt->execute([$base . '%']);
    $last = $stmt->fetchColumn();
    $seq = $last ? (int)substr($last, strlen($base)) + 1 : 1;
    return $base . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

function create_ticket(array $body, ?array $photo): array
{
    $ip = client_ip();
    $limit = (int)cfg('public_submit_limit', 10);
    $stmt = db()->prepare('SELECT COUNT(*) FROM tickets WHERE client_ip = ? AND created_at > NOW() - INTERVAL 10 MINUTE');
    $stmt->execute([$ip]);
    if ((int)$stmt->fetchColumn() >= $limit) {
        fail('ส่งคำขอบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่', 429);
    }

    $in = new Input($body);
    $type = $in->enum('type', array_keys(ticket_types()), true, 'ประเภทคำขอ');
    $in->check();
    $def = ticket_types()[$type];

    $asset = null;
    if ($code = $in->str('asset_code', 30)) {
        $asset = find_asset_by_code($code);
        if ($asset === null) {
            $in->error('asset_code', 'ไม่พบอุปกรณ์รหัส ' . $code);
        }
    }

    $row = [
        'requester_name' => $in->str('requester_name', 150, true, 'ชื่อผู้แจ้ง'),
        'requester_dept' => $in->str('requester_dept', 150, true, 'ฝ่าย/แผนก'),
        'requester_phone' => $in->str('requester_phone', 50, false, 'เบอร์ติดต่อ'),
        'requester_email' => $in->str('requester_email', 150, false, 'E-mail'),
        'location' => $in->str('location', 150, false, 'สถานที่'),
        'description' => $in->str('description', 5000, true, $type === 'repair' ? 'อาการ/รายละเอียดปัญหา' : 'รายละเอียด/วัตถุประสงค์'),
    ];
    if ($row['requester_email'] !== null && !filter_var($row['requester_email'], FILTER_VALIDATE_EMAIL)) {
        $in->error('requester_email', 'E-mail ไม่ถูกต้อง');
    }
    [$details, $title] = $def['parse']($in, $asset);
    $in->check();

    $photoPath = ($photo && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) ? store_upload($photo) : null;

    $priority = ($type === 'purchase' && $details['urgent']) ? 'high' : 'normal';
    $location = $row['location'] ?? $asset['location'] ?? null;

    $pdo = db();
    for ($attempt = 0; ; $attempt++) {
        $pdo->beginTransaction();
        try {
            $ticketNo = next_ticket_no($def['prefix']);
            $pdo->prepare('INSERT INTO tickets (ticket_no, type, title, description, details, asset_id, requester_name, requester_dept,
                    requester_phone, requester_email, location, priority, photo_path, client_ip)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$ticketNo, $type, mb_substr($title, 0, 200), $row['description'], json_encode($details, JSON_UNESCAPED_UNICODE),
                    $asset['id'] ?? null, $row['requester_name'], $row['requester_dept'], $row['requester_phone'], $row['requester_email'],
                    $location, $priority, $photoPath, $ip]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO ticket_logs (ticket_id, to_status, note) VALUES (?, ?, ?)')
                ->execute([$id, 'new', 'ยื่นคำขอโดย ' . $row['requester_name']]);
            $pdo->commit();
            break;
        } catch (PDOException $e) {
            $pdo->rollBack();
            // เลขที่ซ้ำจากการส่งพร้อมกัน — ลองใหม่
            if ($e->errorInfo[1] !== 1062 || $attempt >= 3) {
                throw $e;
            }
        }
    }

    return ['ticket_no' => $ticketNo, 'type' => $type, 'title' => $title];
}

function ticket_logs(int $ticketId, bool $publicOnly): array
{
    $stmt = db()->prepare('SELECT l.id, l.from_status, l.to_status, l.note, l.is_public, l.created_at, a.display_name AS admin_name
        FROM ticket_logs l LEFT JOIN admins a ON a.id = l.admin_id
        WHERE l.ticket_id = ?' . ($publicOnly ? ' AND l.is_public = 1' : '') . ' ORDER BY l.created_at, l.id');
    $stmt->execute([$ticketId]);
    return $stmt->fetchAll();
}

/** หน้าติดตามสถานะสำหรับคนทั่วไป — ไม่แสดงข้อมูลส่วนตัวของผู้แจ้ง */
function ticket_public(string $ticketNo): array
{
    $stmt = db()->prepare('SELECT t.id, t.ticket_no, t.type, t.title, t.status, t.priority, t.resolution,
            t.created_at, t.started_at, t.finished_at, a.asset_code
        FROM tickets t LEFT JOIN assets a ON a.id = t.asset_id WHERE t.ticket_no = ?');
    $stmt->execute([strtoupper(trim($ticketNo))]);
    $ticket = $stmt->fetch() ?: fail('ไม่พบคำขอเลขที่ ' . $ticketNo, 404);

    $ticket['logs'] = array_map(fn($l) => array_diff_key($l, ['is_public' => 1, 'id' => 1]), ticket_logs((int)$ticket['id'], true));
    if ($ticket['status'] !== 'done') {
        $ticket['resolution'] = null;
    }
    unset($ticket['id']);
    return $ticket;
}

function ticket_admin(int $id): array
{
    $stmt = db()->prepare('SELECT t.*, a.asset_code, a.brand_model AS asset_model, ad.display_name AS assigned_name
        FROM tickets t
        LEFT JOIN assets a ON a.id = t.asset_id
        LEFT JOIN admins ad ON ad.id = t.assigned_to
        WHERE t.id = ?');
    $stmt->execute([$id]);
    $ticket = $stmt->fetch() ?: fail('ไม่พบคำขอ', 404);
    $ticket['details'] = json_decode((string)$ticket['details'], true) ?: [];
    $ticket['has_photo'] = $ticket['photo_path'] !== null;
    unset($ticket['photo_path']);
    $ticket['logs'] = ticket_logs($id, false);
    return $ticket;
}

function format_duration(string $from, string $to): string
{
    $minutes = max(0, intdiv(strtotime($to) - strtotime($from), 60));
    $days = intdiv($minutes, 1440);
    $hours = intdiv($minutes % 1440, 60);
    $mins = $minutes % 60;
    $parts = array_filter([$days ? "$days วัน" : '', $hours ? "$hours ชม." : '', $mins ? "$mins นาที" : '']);
    return $parts ? implode(' ', $parts) : 'ไม่ถึง 1 นาที';
}

function update_ticket(int $id, array $body, array $admin): array
{
    $stmt = db()->prepare('SELECT * FROM tickets WHERE id = ?');
    $stmt->execute([$id]);
    $ticket = $stmt->fetch() ?: fail('ไม่พบคำขอ', 404);

    $in = new Input($body);
    $changes = [];

    if ($in->has('status')) {
        $changes['status'] = $in->enum('status', array_keys(TICKET_STATUSES), true, 'สถานะ');
    }
    if ($in->has('priority')) {
        $changes['priority'] = $in->enum('priority', array_keys(TICKET_PRIORITIES), true, 'ความสำคัญ');
    }
    if ($in->has('assigned_to')) {
        $assignee = $in->int('assigned_to', 1);
        if ($assignee !== null) {
            $check = db()->prepare('SELECT COUNT(*) FROM admins WHERE id = ? AND is_active = 1');
            $check->execute([$assignee]);
            if (!$check->fetchColumn()) {
                $in->error('assigned_to', 'ไม่พบผู้รับผิดชอบ');
            }
        }
        $changes['assigned_to'] = $assignee;
    }
    if ($in->has('resolution')) {
        $changes['resolution'] = $in->str('resolution', 5000, false, 'ผลการดำเนินการ');
    }
    if ($in->has('fix_method')) {
        $changes['fix_method'] = $in->enum('fix_method', ['internal', 'outsource']);
    }
    if ($in->has('outsource_vendor')) {
        $changes['outsource_vendor'] = $in->str('outsource_vendor', 150, false, 'ร้าน/บริษัทภายนอก');
    }
    $note = $in->str('note', 2000, false, 'บันทึก');
    $notePublic = $in->bool('note_public', true);
    $in->check();

    $newStatus = $changes['status'] ?? $ticket['status'];
    $statusChanged = $newStatus !== $ticket['status'];

    if ($statusChanged && $newStatus === 'done' && $ticket['type'] === 'repair'
        && trim((string)($changes['resolution'] ?? $ticket['resolution'])) === '') {
        fail('กรุณากรอกรายละเอียดการแก้ปัญหาก่อนปิดงานซ่อม', 422, ['resolution' => 'กรุณากรอกรายละเอียดการแก้ปัญหา']);
    }

    if ($statusChanged) {
        if (in_array($newStatus, ['in_progress', 'waiting', 'done'], true) && $ticket['started_at'] === null) {
            $changes['started_at'] = now();
        }
        if (in_array($newStatus, TICKET_CLOSED_STATUSES, true)) {
            $changes['finished_at'] = now();
        } elseif ($ticket['finished_at'] !== null) {
            $changes['finished_at'] = null; // เปิดงานใหม่
        }
        // รับงานโดยยังไม่มีผู้รับผิดชอบ → มอบหมายให้คนที่กด
        if ($newStatus !== 'new' && ($changes['assigned_to'] ?? $ticket['assigned_to']) === null) {
            $changes['assigned_to'] = $admin['id'];
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($changes) {
            $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($changes)));
            $pdo->prepare("UPDATE tickets SET $set WHERE id = ?")->execute([...array_values($changes), $id]);
        }
        if ($statusChanged || $note !== null) {
            $pdo->prepare('INSERT INTO ticket_logs (ticket_id, admin_id, from_status, to_status, note, is_public) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$id, $admin['id'], $statusChanged ? $ticket['status'] : null, $statusChanged ? $newStatus : null,
                    $note, $statusChanged ? 1 : (int)$notePublic]);
        }
        // ปิดงานซ่อมที่ผูกกับอุปกรณ์ → บันทึกประวัติ BM (FM-IT-004)
        if ($statusChanged && $newStatus === 'done' && $ticket['type'] === 'repair' && $ticket['asset_id']) {
            $finished = $changes['finished_at'];
            $resolution = (string)($changes['resolution'] ?? $ticket['resolution']);
            $method = $changes['fix_method'] ?? $ticket['fix_method'];
            $vendor = $changes['outsource_vendor'] ?? $ticket['outsource_vendor'];
            if ($method === 'outsource' && $vendor) {
                $resolution .= ' (ส่งซ่อม: ' . $vendor . ')';
            }
            $pdo->prepare('INSERT INTO maintenance_records (asset_id, ticket_id, kind, performed_on, detail, duration, performed_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$ticket['asset_id'], $id, 'BM', substr($finished, 0, 10), $resolution,
                    format_duration($changes['started_at'] ?? $ticket['started_at'] ?? $ticket['created_at'], $finished), $admin['display_name']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ticket_admin($id);
}
