<?php
declare(strict_types=1);

const ASSET_FIELDS = ['asset_code', 'category_id', 'company', 'brand_model', 'serial_no', 'hostname', 'holder_name', 'holder_nickname',
    'department', 'location', 'status', 'registered_at', 'warranty_until', 'specs', 'notes'];

function asset_list(array $q): array
{
    $where = [];
    $params = [];
    if (($search = trim((string)($q['q'] ?? ''))) !== '') {
        $where[] = '(a.asset_code LIKE ? OR a.brand_model LIKE ? OR a.serial_no LIKE ? OR a.hostname LIKE ?
            OR a.holder_name LIKE ? OR a.holder_nickname LIKE ? OR a.department LIKE ?)';
        array_push($params, ...array_fill(0, 7, '%' . $search . '%'));
    }
    if (!empty($q['category_id'])) {
        $where[] = 'a.category_id = ?';
        $params[] = (int)$q['category_id'];
    }
    if (!empty($q['status']) && isset(ASSET_STATUSES[$q['status']])) {
        $where[] = 'a.status = ?';
        $params[] = $q['status'];
    }
    if (!empty($q['company'])) {
        $where[] = 'a.company = ?';
        $params[] = (string)$q['company'];
    }

    $sql = "SELECT a.id, a.asset_code, a.company, a.brand_model, a.serial_no, a.hostname, a.holder_name, a.holder_nickname,
            a.department, a.location, a.status, a.registered_at, c.name AS category_name,
            (SELECT COUNT(*) FROM tickets t WHERE t.asset_id = a.id AND t.status IN ('" . implode("','", TICKET_OPEN_STATUSES) . "')) AS open_tickets
        FROM assets a LEFT JOIN asset_categories c ON c.id = a.category_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY a.asset_code';

    if (!empty($q['all'])) {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return ['data' => $stmt->fetchAll()];
    }
    return paginate($sql, $params, (int)($q['page'] ?? 1), (int)($q['per_page'] ?? 50));
}

function asset_detail(int $id): array
{
    $stmt = db()->prepare('SELECT a.*, c.name AS category_name FROM assets a LEFT JOIN asset_categories c ON c.id = a.category_id WHERE a.id = ?');
    $stmt->execute([$id]);
    $asset = $stmt->fetch() ?: fail('ไม่พบอุปกรณ์', 404);

    $stmt = db()->prepare('SELECT id, ticket_no, type, title, status, created_at, finished_at FROM tickets WHERE asset_id = ? ORDER BY created_at DESC LIMIT 50');
    $stmt->execute([$id]);
    $asset['tickets'] = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT m.*, t.ticket_no FROM maintenance_records m LEFT JOIN tickets t ON t.id = m.ticket_id
        WHERE m.asset_id = ? ORDER BY m.performed_on DESC, m.id DESC');
    $stmt->execute([$id]);
    $asset['maintenance'] = $stmt->fetchAll();

    return $asset;
}

function asset_save(?int $id, array $body): array
{
    $in = new Input($body);
    $row = [
        'asset_code' => strtoupper((string)$in->str('asset_code', 30, true, 'หมายเลขทะเบียน')),
        'category_id' => $in->int('category_id', 1, PHP_INT_MAX, false, 'หมวด'),
        'company' => $in->str('company', 20) ?? 'ATS',
        'brand_model' => $in->str('brand_model', 150, true, 'ยี่ห้อ/รุ่น'),
        'serial_no' => $in->str('serial_no', 100),
        'hostname' => $in->str('hostname', 50),
        'holder_name' => $in->str('holder_name', 150),
        'holder_nickname' => $in->str('holder_nickname', 50),
        'department' => $in->str('department', 150),
        'location' => $in->str('location', 150),
        'status' => $in->enum('status', array_keys(ASSET_STATUSES), false, 'สถานะ', 'active'),
        'registered_at' => $in->date('registered_at', false, 'วันที่ขึ้นทะเบียน'),
        'warranty_until' => $in->date('warranty_until', false, 'วันหมดประกัน'),
        'specs' => $in->str('specs', 5000),
        'notes' => $in->str('notes', 5000),
    ];
    if ($row['asset_code'] !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $row['asset_code'])) {
        $in->error('asset_code', 'หมายเลขทะเบียนใช้ได้เฉพาะ A-Z, 0-9, - . _');
    }
    $dup = db()->prepare('SELECT id FROM assets WHERE asset_code = ? AND id <> ?');
    $dup->execute([$row['asset_code'], $id ?? 0]);
    if ($dup->fetchColumn()) {
        $in->error('asset_code', 'หมายเลขทะเบียนนี้มีอยู่แล้ว');
    }
    $in->check();

    if ($id === null) {
        db()->prepare('INSERT INTO assets (' . implode(', ', ASSET_FIELDS) . ') VALUES (' . implode(', ', array_fill(0, count(ASSET_FIELDS), '?')) . ')')
            ->execute(array_map(fn($f) => $row[$f], ASSET_FIELDS));
        $id = (int)db()->lastInsertId();
    } else {
        asset_detail($id); // 404 ถ้าไม่มี
        db()->prepare('UPDATE assets SET ' . implode(', ', array_map(fn($f) => "$f = ?", ASSET_FIELDS)) . ' WHERE id = ?')
            ->execute([...array_map(fn($f) => $row[$f], ASSET_FIELDS), $id]);
    }
    return asset_detail($id);
}

function asset_delete(int $id): array
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM tickets WHERE asset_id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        fail('อุปกรณ์นี้มีประวัติคำขอแล้ว ลบไม่ได้ — ให้เปลี่ยนสถานะเป็น "จำหน่าย/เลิกใช้" แทน', 409);
    }
    db()->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]);
    return ['ok' => true];
}

function maintenance_add(int $assetId, array $body, array $admin): array
{
    asset_detail($assetId);
    $in = new Input($body);
    $row = [
        'kind' => $in->enum('kind', ['BM', 'PM'], true, 'ประเภท'),
        'performed_on' => $in->date('performed_on', true, 'วันที่'),
        'detail' => $in->str('detail', 5000, true, 'รายละเอียด'),
        'duration' => $in->str('duration', 50),
        'performed_by' => $in->str('performed_by', 100) ?? $admin['display_name'],
    ];
    $in->check();
    db()->prepare('INSERT INTO maintenance_records (asset_id, kind, performed_on, detail, duration, performed_by) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$assetId, ...array_values($row)]);
    return asset_detail($assetId);
}

function maintenance_delete(int $assetId, int $recordId): array
{
    db()->prepare('DELETE FROM maintenance_records WHERE id = ? AND asset_id = ?')->execute([$recordId, $assetId]);
    return asset_detail($assetId);
}
