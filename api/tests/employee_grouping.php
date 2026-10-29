<?php
declare(strict_types=1);

// Connection-local copies only; no production directory or approval records change.
require __DIR__ . '/../src/bootstrap.php';
$pdo = db();
foreach (['employees', 'ticket_approvals'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE `fixture_$table` LIKE `$table`");
    $pdo->exec("ALTER TABLE `fixture_$table` RENAME TO `$table`");
}
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$person = ['id'=>1, 'source_user_id'=>100, 'source_company_id'=>10, 'full_name'=>'Test Person',
    'email'=>'person@example.com', 'phone'=>null, 'company'=>'Company A', 'department'=>'IT',
    'position'=>'Manager', 'is_active'=>1, 'can_approve'=>1, 'synced_at'=>'2026-10-28 08:00:00'];
$other = [...$person, 'id'=>2, 'source_company_id'=>20, 'company'=>'Company B', 'synced_at'=>'2026-10-28 09:00:00'];
$groups = employee_group_rows([$person, $other]);
check(count($groups) === 1 && $groups[0]['companies'] === ['Company A', 'Company B'], 'Identical memberships appear once with both companies');
check($groups[0]['member_ids'] === [1, 2] && $groups[0]['id'] === 1, 'Retains stable identity and underlying membership IDs');
check($groups[0]['synced_at'] === $other['synced_at'], 'Shows latest sync timestamp');
foreach (['full_name'=>'Another Person', 'email'=>'other@example.com', 'phone'=>'12345',
    'department'=>'Sales', 'position'=>'Staff', 'is_active'=>0, 'can_approve'=>0, 'source_user_id'=>200] as $field=>$value) {
    check(count(employee_group_rows([$person, [...$other, $field=>$value]])) === 2, "Different $field remains separate");
}
check(count(employee_group_rows([[...$person, 'source_user_id'=>null], [...$other, 'source_user_id'=>null]])) === 2, 'Manual entries are not merged based on name or email alone');
check(count(employee_group_rows([$person, [...$other, 'email'=>'PERSON@example.com', 'phone'=>'', 'department'=>' IT ']])) === 1, 'Normalizes email case and empty optional fields');
check(employee_group_rows([]) === [], 'Empty directory');
$insert = $pdo->prepare('INSERT INTO employees (id,source_user_id,source_company_id,full_name,email,phone,company,department,position,is_active,can_approve,synced_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ([$person, $other] as $row) $insert->execute(array_values($row));
$list = employee_list();
check(count($list) === 1 && $list[0]['member_ids'] === [1,2], 'Admin API returns grouped directory');
$public = employee_approvers();
check(count($public) === 1 && $public[0]['companies'] === ['Company A', 'Company B'], 'Public approver dropdown is deduplicated');
check(array_keys($public[0]) === ['id','full_name','company','department','position','companies'], 'Public dropdown has only allowed fields');
$pdo->exec("INSERT INTO ticket_approvals (ticket_id,employee_id,approver_name,approver_email,approver_company,status,signature_data,signed_at) VALUES (100,2,'Test Person','person@example.com','Company B','approved','fixture-signature',NOW())");
$snapshot = $pdo->query('SELECT * FROM ticket_approvals')->fetchAll();
$body = ['full_name'=>'Edited Person','email'=>'edited@example.com','phone'=>'555','company'=>'Company A',
    'department'=>'IT','position'=>'Manager','is_active'=>true,'can_approve'=>true,'apply_to_matching'=>true];
$saved = employee_save(1, $body);
check(count($saved) === 1 && $saved[0]['full_name'] === 'Edited Person', 'Grouped edit remains one item');
$members = $pdo->query('SELECT id,full_name,email,company FROM employees ORDER BY id')->fetchAll();
check(array_column($members, 'email') === ['edited@example.com','edited@example.com'], 'Grouped edits update both memberships');
check(array_column($members, 'company') === ['Company A','Company B'], 'Grouped edits preserve each company');
check($pdo->query('SELECT * FROM ticket_approvals')->fetchAll() === $snapshot, 'Signed approval references and snapshots remain unchanged');
try {
    employee_save(1, [...$body, 'company'=>'Unexpected Company']);
    throw new RuntimeException('Expected group company validation');
} catch (HttpError $e) {
    check($e->status === 422, 'Cannot replace multiple company memberships with one company field');
}
check(!$pdo->inTransaction() && count(employee_list()) === 1, 'Validation rolls back the grouped transaction');
employee_save(1, [...$body, 'can_approve'=>false]);
check(employee_approvers() === [], 'Revoking a grouped approver removes every membership from the dropdown');
employee_save(1, [...$body, 'can_approve'=>false, 'department'=>'Sales', 'apply_to_matching'=>false]);
check(count(employee_list()) === 2, 'Single-membership edits remain scoped to that membership');
check($pdo->query('SELECT * FROM ticket_approvals')->fetchAll() === $snapshot, 'Individual edits also preserve signed evidence');

// Exercise actual read-only source sync exclusively against temporary destination data.
$pdo->exec('DELETE FROM employees');
$synced = employee_sync();
$before = employee_list();
$raw = $pdo->query('SELECT * FROM employees ORDER BY id')->fetchAll();
$again = employee_sync();
check($synced['count'] === count($before) && $synced['membership_count'] === count($raw), 'Sync count matches grouped rows while reporting source memberships separately');
check(array_column($again['employees'], 'member_ids') === array_column($before, 'member_ids'), 'Repeated sync retains group membership IDs');
check(count($again['employees']) === count($before), 'Repeated sync does not reintroduce duplicate rows');
echo "PASS: $checks employee grouping checks (temporary tables; source read-only)\n";
