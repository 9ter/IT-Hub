<?php
declare(strict_types=1);

// Every write uses connection-local TEMP tables.
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/admin.php';
$pdo = db();
foreach (['tickets', 'ticket_reads', 'ticket_archives', 'assets', 'admins'] as $table) {
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
function ticket(string $no, string $status): int
{
    db()->prepare("INSERT INTO tickets (ticket_no, type, title, status, requester_name) VALUES (?, 'repair', ?, ?, 'Fixture')")
        ->execute([$no, $no, $status]);
    return (int)db()->lastInsertId();
}
function ids(array $page): array
{
    return array_map(fn($t) => (int)$t['id'], $page['data']);
}

$a = ['id' => 1];
$b = ['id' => 2];
$first = ticket('T-1', 'new');
$second = ticket('T-2', 'new');
$accepted = ticket('T-3', 'accepted');
$new = ['status' => 'new'];

check(ticket_list($new, 1)['total'] === 2, 'Every new ticket starts unread');
check(ticket_mark_read($first, $a) === ['read' => true], 'Marking read reports only the result');
$unread = ticket_list($new, 1);
check($unread['total'] === 1 && ids($unread) === [$second], 'A read ticket leaves the unread count and list');
check(ticket_list($new, 2)['total'] === 2, 'Read state belongs to one admin only');
check(ticket_list($new)['total'] === 2, 'The normal ticket list still shows read tickets');
ticket_mark_read($first, $a);
check((int)$pdo->query('SELECT COUNT(*) FROM ticket_reads')->fetchColumn() === 1, 'Reading twice keeps one row');
check(ticket_list($new, 1)['total'] === 1, 'Reading twice does not change the count');

ticket_mark_read($accepted, $a);
check(ticket_list($new, 1)['total'] === 1, 'Reading a ticket outside the filter leaves the count alone');
$third = ticket('T-4', 'new');
check(ids(ticket_list($new, 1)) === [$third, $second], 'A ticket arriving later is unread');
$pdo->prepare("UPDATE tickets SET status = 'accepted' WHERE id = ?")->execute([$second]);
check(ids(ticket_list($new, 2)) === [$third, $first], 'An accepted ticket leaves the unread list even if never read');

$before = (int)$pdo->query('SELECT COUNT(*) FROM ticket_reads')->fetchColumn();
try {
    ticket_mark_read(999999, $a);
    throw new RuntimeException('Expected missing ticket to fail');
} catch (HttpError $e) {
    check($e->status === 404, 'A missing ticket is rejected');
}
check((int)$pdo->query('SELECT COUNT(*) FROM ticket_reads')->fetchColumn() === $before, 'A rejected read writes nothing');

echo "ticket_reads: $checks checks passed\n";
