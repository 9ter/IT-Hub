<?php
declare(strict_types=1);

// Every write uses connection-local TEMP tables.
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/assets.php';
require __DIR__ . '/../src/admin.php';
$pdo = db();
foreach (['tickets', 'ticket_logs', 'ticket_reads', 'ticket_archives', 'ticket_approvals', 'ticket_requester_signatures', 'ticket_it_signatures',
    'ticket_receipt_signatures', 'ticket_borrow_decisions', 'ticket_edit_requests', 'ticket_request_edits', 'it_documents', 'it_document_signatures', 'it_document_logs',
    'maintenance_records', 'assets', 'admins', 'employees'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE `fixture_$table` LIKE `$table`");
    $pdo->exec("ALTER TABLE `fixture_$table` RENAME TO `$table`");
}
$checks = 0;
function check(bool $value, string $message): void
{
    global $checks;
    if (!$value) throw new RuntimeException($message);
    $checks++;
}
function rejects(callable $fn, int $status, string $message): ?HttpError
{
    try {
        $fn();
    } catch (HttpError $e) {
        check($e->status === $status, "$message: expected $status, got {$e->status}");
        return $e;
    }
    throw new RuntimeException("$message: nothing was rejected");
}
function ticket(string $no, string $type, string $status, ?int $assetId = null): int
{
    db()->prepare('INSERT INTO tickets (ticket_no, type, title, status, requester_name, asset_id) VALUES (?, ?, ?, ?, \'Fixture\', ?)')
        ->execute([$no, $type, "Title $no", $status, $assetId]);
    return (int)db()->lastInsertId();
}
function ids(array $page): array
{
    return array_map(fn($t) => (int)$t['id'], $page['data']);
}
function count_of(string $sql): int
{
    return (int)db()->query($sql)->fetchColumn();
}

$pdo->exec("INSERT INTO admins (id, username, password_hash, display_name) VALUES (1, 'a', 'x', 'Fixture Admin')");
$admin = ['id' => 1, 'display_name' => 'Fixture Admin'];
$why = ['reason' => 'Entered by mistake'];
$noArchive = fn() => throw new RuntimeException('Deleted tickets must not reach the renderer');

// Visibility
$category = (int)$pdo->query('SELECT MIN(id) FROM asset_categories')->fetchColumn();
$pdo->exec("INSERT INTO assets (id, asset_code, category_id) VALUES (1, 'T-ASSET', $category)");
$repair = ticket('RP-1', 'repair', 'new', 1);
$kept = ticket('RP-2', 'repair', 'new', 1);
rejects(fn() => ticket_delete($repair, ['reason' => '   '], $admin), 422, 'A reason is required');
check(ticket_list(['status' => 'all'])['total'] === 2, 'A rejected delete leaves the ticket visible');
check(ticket_delete($repair, $why, $admin) === ['deleted' => true], 'Delete reports only the result');
rejects(fn() => ticket_delete($repair, $why, $admin), 404, 'A second delete finds nothing');
foreach (['open', 'all', 'new', ''] as $status) {
    check(ids(ticket_list(['status' => $status])) === [$kept], "Deleted ticket stays out of the '$status' list");
}
check(ticket_list(['status' => 'new'], 1)['total'] === 1, 'Deleted ticket stays out of the bell');
$bin = ticket_list(['status' => 'deleted']);
check(ids($bin) === [$repair], 'The deleted tab lists only deleted tickets');
check($bin['data'][0]['delete_reason'] === 'Entered by mistake' && $bin['data'][0]['deleted_by_name'] === 'Fixture Admin' && $bin['data'][0]['deleted_at'] !== null, 'The deleted tab shows who, when and why');
rejects(fn() => ticket_public('RP-1'), 404, 'The public tracker hides a deleted ticket');
rejects(fn() => ticket_admin($repair), 404, 'The admin detail hides a deleted ticket');
rejects(fn() => update_ticket($repair, ['status' => 'accepted'], $admin), 404, 'A stale tab cannot change a deleted ticket');
rejects(fn() => ticket_mark_read($repair, $admin), 404, 'A deleted ticket cannot be marked read');
$dash = dashboard();
check($dash['by_status']['new'] === 1 && $dash['open_total'] === 1 && $dash['created_today'] === 1 && count($dash['queue']) === 1, 'The dashboard leaves out deleted tickets');
check(array_column(asset_public('T-ASSET')['open_tickets'], 'ticket_no') === ['RP-2'], 'The QR page lists only live open repairs');
check(array_column(asset_detail(1)['tickets'], 'ticket_no') === ['RP-2'], 'Asset history leaves out deleted tickets');
check((int)asset_list(['status' => 'all'])['data'][0]['open_tickets'] === 1, 'The asset list counts only live open tickets');
rejects(fn() => ticket_restore($kept, $admin), 404, 'Only deleted tickets can be restored');
check(ticket_restore($repair, $admin) === ['restored' => true], 'Restore reports only the result');
check(ticket_list(['status' => 'deleted'])['total'] === 0 && ticket_list(['status' => 'all'])['total'] === 2, 'Restore returns the ticket to the list');
check(ticket_admin($repair)['ticket_no'] === 'RP-1' && ticket_public('RP-1')['ticket_no'] === 'RP-1', 'A restored ticket opens again');
$notes = array_column(ticket_logs($repair, false), 'note');
check(in_array('ลบรายการ: Entered by mistake', $notes, true) && in_array('กู้คืนรายการที่ลบ', $notes, true), 'Delete and restore are logged');
check(ticket_logs($repair, true) === [], 'Delete notes stay out of the public tracker');
check(!array_intersect(['deleted_at', 'deleted_by', 'delete_reason'], array_keys(ticket_admin($repair))), 'Deletion fields stay out of the ticket payload');

// Evidence and numbering
$pdo->beginTransaction();
$signedNo = next_ticket_no('PR');
$pdo->rollBack();
$signed = ticket($signedNo, 'purchase', 'new');
$pdo->prepare("INSERT INTO ticket_requester_signatures (ticket_id, requester_name, signature_data) VALUES (?, 'Fixture', 'data:image/png;base64,AA==')")->execute([$signed]);
$token = str_repeat('a', 64);
$pdo->exec("INSERT INTO employees (id, full_name, email, company, can_approve) VALUES (1, 'Head', 'head@example.com', 'Fixture', 1)");
$pdo->prepare("INSERT INTO ticket_approvals (ticket_id, stage, attempt, employee_id, approver_name, approver_email, approver_company, status, token_hash, expires_at) VALUES (?, 'request', 1, 1, 'Head', 'head@example.com', 'Fixture', 'pending', ?, NOW() + INTERVAL 1 DAY)")
    ->execute([$signed, hash('sha256', $token)]);
check(approval_view(['token' => $token])['ticket_no'] === $signedNo, 'The signing link opens before delete');
ticket_delete($signed, $why, $admin);
rejects(fn() => approval_view(['token' => $token]), 404, 'A pending signing link stops working after delete');
$image = imagecreatetruecolor(600, 200);
imagefilledrectangle($image, 0, 0, 599, 199, imagecolorallocate($image, 255, 255, 255));
imageline($image, 40, 100, 560, 120, imagecolorallocate($image, 15, 23, 42));
ob_start();
imagepng($image);
$png = 'data:image/png;base64,' . base64_encode((string)ob_get_clean());
rejects(fn() => approval_decide(['token' => $token, 'decision' => 'approved', 'consent' => true, 'signature_data' => $png]), 404, 'A pending signing link cannot sign after delete');
check(count_of('SELECT COUNT(*) FROM ticket_requester_signatures') === 1 && count_of('SELECT COUNT(*) FROM ticket_approvals') === 1, 'Delete keeps signatures and approval rows');
$pdo->beginTransaction();
check(next_ticket_no('PR') > $signedNo, 'A deleted ticket number is not issued again');
$pdo->rollBack();
ticket_restore($signed, $admin);
check(approval_view(['token' => $token])['ticket_no'] === $signedNo, 'The signing link works again after restore');

// Borrow records
$pdo->exec("INSERT INTO it_documents (id, document_no, form_code, title, document_date, company, status, data, created_by) VALUES (1, 'DOC-1', 'FM-IT-008', 'Loan', CURDATE(), 'ATS', 'in_progress', '{}', 1)");
$borrow = ticket('BR-1', 'borrow', 'accepted');
$pdo->prepare("INSERT INTO ticket_borrow_decisions (ticket_id, decision, admin_id, admin_name, document_id) VALUES (?, 'approved', 1, 'Fixture Admin', 1)")->execute([$borrow]);
$blocked = rejects(fn() => ticket_delete($borrow, $why, $admin), 409, 'A live loan record blocks delete');
check($blocked->errors['document_id'] === '1' && str_contains($blocked->getMessage(), 'DOC-1'), 'The block names the loan record');
check(ticket_admin($borrow)['ticket_no'] === 'BR-1', 'A blocked delete leaves the ticket live');
$pdo->exec("UPDATE it_documents SET status = 'cancelled' WHERE id = 1");
ticket_delete($borrow, $why, $admin);
check(ticket_list(['status' => 'deleted'])['total'] === 1, 'A cancelled loan record no longer blocks delete');
check(document_detail(1)['borrow_source'] === null, 'A cancelled record does not link to a deleted ticket');
$pending = ticket('BR-2', 'borrow', 'new');
check(ids(borrow_pending_requests([])) === [$pending], 'A live borrow request is in the queue');
ticket_delete($pending, $why, $admin);
check(borrow_pending_requests([])['total'] === 0, 'A deleted borrow request leaves the queue');
rejects(fn() => borrow_decide($pending, ['decision' => 'approved'], $admin), 404, 'A deleted borrow request cannot be approved');

// NAS queue
$done = ticket('RP-3', 'repair', 'done');
archive_enqueue($done);
$job = $pdo->query("SELECT * FROM ticket_archives WHERE ticket_id = $done")->fetch();
ticket_delete($done, $why, $admin);
check(archive_enqueue($done) === null, 'A deleted ticket cannot enter the NAS queue');
archive_run_job($job, $noArchive, $noArchive);
check($pdo->query("SELECT status FROM ticket_archives WHERE id = {$job['id']}")->fetchColumn() === 'obsolete', 'The worker skips a deleted ticket');
ticket_restore($done, $admin);
check($pdo->query("SELECT status FROM ticket_archives WHERE id = {$job['id']}")->fetchColumn() === 'pending', 'Restore puts the skipped job back in the queue');
$pdo->exec("UPDATE ticket_archives SET status = 'sent' WHERE id = {$job['id']}");
$before = count_of("SELECT COUNT(*) FROM ticket_archives WHERE ticket_id = $done");
ticket_delete($done, $why, $admin);
ticket_restore($done, $admin);
check(count_of("SELECT COUNT(*) FROM ticket_archives WHERE ticket_id = $done") === $before, 'Delete and restore do not create a new PDF version');
check($pdo->query("SELECT status FROM ticket_archives WHERE id = {$job['id']}")->fetchColumn() === 'sent', 'A sent PDF stays sent');

echo "PASS: $checks ticket delete checks; TEMPORARY tables only\n";
