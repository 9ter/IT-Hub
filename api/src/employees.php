<?php
declare(strict_types=1);

function employee_list(): array
{
    return employee_group_rows(db()->query('SELECT * FROM employees ORDER BY source_company_id, company, department, full_name, id')->fetchAll());
}

/** Group identical source identities while preserving membership IDs used by approvals. */
function employee_group_rows(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        // A matching name alone is not enough to merge different people or manual entries.
        $key = json_encode([$row['source_user_id'] ?: 'manual-' . $row['id'],
            trim($row['full_name']), mb_strtolower(trim($row['email'] ?? '')),
            trim($row['phone'] ?? ''), trim($row['department'] ?? ''), trim($row['position'] ?? ''),
            (int)$row['is_active'], (int)$row['can_approve']], JSON_UNESCAPED_UNICODE);
        if (!isset($groups[$key])) {
            $groups[$key] = [...$row, 'companies' => [], 'member_ids' => []];
        }
        $group = &$groups[$key];
        if (!in_array($row['company'], $group['companies'], true)) $group['companies'][] = $row['company'];
        $group['member_ids'][] = (int)$row['id'];
        if (($row['synced_at'] ?? '') > ($group['synced_at'] ?? '')) $group['synced_at'] = $row['synced_at'];
        unset($group);
    }
    $result = array_values($groups);
    usort($result, fn($a, $b) => [$a['company'], $a['department'], $a['full_name'], $a['id']] <=> [$b['company'], $b['department'], $b['full_name'], $b['id']]);
    return $result;
}

function employee_approvers(): array
{
    // Email/phone and the rest of the employee roster stay behind admin authentication.
    $rows = array_filter(employee_list(), fn($row) => $row['is_active'] && $row['can_approve'] && !empty($row['email']));
    return array_values(array_map(fn($row) => array_intersect_key($row,
        array_flip(['id', 'full_name', 'company', 'companies', 'department', 'position'])), $rows));
}

function employee_save(?int $id, array $body): array
{
    $in = new Input($body);
    $name = $in->str('full_name', 150, true, 'ชื่อ-นามสกุล');
    $email = $in->str('email', 150, false, 'E-mail');
    $phone = $in->str('phone', 50);
    $company = $in->str('company', 255, true, 'บริษัท');
    $department = $in->str('department', 255);
    $position = $in->str('position', 255);
    $active = (int)$in->bool('is_active', true);
    $approve = (int)$in->bool('can_approve');
    if ($email !== null && (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email))) {
        $in->error('email', 'E-mail ไม่ถูกต้อง');
    }
    if ($approve && !$email) $in->error('email', 'ผู้อนุมัติต้องมี E-mail');
    $in->check();
    $values = [$name, $email, $phone, $company, $department, $position, $active, $approve];
    if ($id === null) {
        db()->prepare('INSERT INTO employees (full_name,email,phone,company,department,position,is_active,can_approve) VALUES (?,?,?,?,?,?,?,?)')->execute($values);
    } else {
        $db = db();
        $db->beginTransaction();
        try {
            $rows = $db->query('SELECT * FROM employees ORDER BY source_company_id, id FOR UPDATE')->fetchAll();
            $existing = null;
            foreach ($rows as $row) if ((int)$row['id'] === $id) $existing = $row;
            if (!$existing) fail('ไม่พบพนักงาน', 404);
            $ids = [$id];
            if ($in->bool('apply_to_matching')) {
                foreach (employee_group_rows($rows) as $group) {
                    if (in_array($id, $group['member_ids'], true)) $ids = $group['member_ids'];
                }
            }
            $multiple = count($ids) > 1;
            if ($multiple && $company !== $existing['company']) fail('รายการรวมหลายบริษัท กรุณาคงบริษัทเดิมไว้', 422);
            $save = $db->prepare('UPDATE employees SET full_name=?,email=?,phone=?,company=?,department=?,position=?,is_active=?,can_approve=? WHERE id=?');
            foreach ($rows as $row) {
                if (!in_array((int)$row['id'], $ids, true)) continue;
                $memberValues = $values;
                if ($multiple) $memberValues[3] = $row['company'];
                $save->execute([...$memberValues, $row['id']]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
    return employee_list();
}

function employee_sync(): array
{
    $source = cfg('employee_source');
    if (!$source) fail('ยังไม่ได้ตั้งค่าการเชื่อมต่อ E-Procurement', 503);
    try {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $source['host'], $source['port'], $source['name']), $source['user'], $source['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        // Read only. Preserve a separate row for each company membership.
        $rows = $pdo->query("SELECT u.id source_user_id, c.id source_company_id, u.full_name, u.email, u.phone,
            c.name company, d.name department, p.name position,
            (u.is_active=1 AND u.deleted_at IS NULL AND c.is_active=1 AND c.deleted_at IS NULL) is_active,
            (EXISTS(SELECT 1 FROM purchase_departments md WHERE md.company_id=c.id AND md.manager_user_id=u.id AND md.is_active=1 AND md.deleted_at IS NULL)
             OR EXISTS(SELECT 1 FROM purchase_approval_workflow_steps ws JOIN purchase_approval_workflows w ON w.id=ws.workflow_id
                 WHERE w.company_id=c.id AND ws.approver_user_id=u.id AND w.is_active=1 AND w.deleted_at IS NULL)) can_approve
            FROM purchase_users u JOIN purchase_user_companies uc ON uc.user_id=u.id
            JOIN purchase_companies c ON c.id=uc.company_id
            LEFT JOIN purchase_departments d ON d.id=uc.department_id AND d.company_id=c.id AND d.is_active=1 AND d.deleted_at IS NULL
            LEFT JOIN purchase_department_positions p ON p.id=uc.position_id AND p.company_id=c.id AND p.is_active=1 AND p.deleted_at IS NULL")->fetchAll();
    } catch (Throwable $e) {
        fail('อ่านข้อมูล E-Procurement ไม่สำเร็จ กรุณาตรวจการเชื่อมต่อ', 503);
    }
    if (!$rows) fail('ต้นทางไม่มีข้อมูลพนักงาน จึงยังไม่เปลี่ยนข้อมูลใน IT Hub', 422);
    $db = db();
    $db->beginTransaction();
    try {
        // A manual directory entry is never affected by a source sync.
        $db->exec('UPDATE employees SET is_active=0 WHERE source_user_id IS NOT NULL');
        $save = $db->prepare('INSERT INTO employees (source_user_id,source_company_id,full_name,email,phone,company,department,position,is_active,can_approve,synced_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),email=VALUES(email),phone=VALUES(phone),company=VALUES(company),
            department=VALUES(department),position=VALUES(position),is_active=VALUES(is_active),synced_at=NOW()');
        foreach ($rows as $row) {
            $validEmail = filter_var($row['email'], FILTER_VALIDATE_EMAIL) ? $row['email'] : null;
            $save->execute([$row['source_user_id'], $row['source_company_id'], mb_substr($row['full_name'],0,150), $validEmail,
                $row['phone'], $row['company'], $row['department'], $row['position'], (int)$row['is_active'], $validEmail ? (int)$row['can_approve'] : 0]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    $employees = employee_list();
    return ['count' => count($employees), 'membership_count' => count($rows), 'employees' => $employees];
}
