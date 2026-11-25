<?php
declare(strict_types=1);

// Hyper Backup connection settings: validation, encrypted storage, privacy. Uses a temp state dir; no DSM login.
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/backups.php';
require __DIR__ . '/../src/hyperbackup.php';
$dir = sys_get_temp_dir() . '/hbtest-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$GLOBALS['ithub_config']['nas']['state_dir'] = $dir;
$checks = 0;
function check(bool $v, string $m): void { global $checks; if (!$v) throw new RuntimeException($m); $checks++; }
function fails(callable $fn, int $status, string $m): HttpError
{
    try { $fn(); } catch (HttpError $e) { check($e->status === $status, "$m (got {$e->status}: {$e->getMessage()})"); return $e; }
    throw new RuntimeException("Expected failure: $m");
}
try {
    $e = fails(fn() => hb_parse(['host' => '8.8.8.8', 'port' => 5001, 'username' => 'u', 'password' => 'p'], null), 422, 'public IP rejected');
    check(isset($e->errors['host']), 'host error');
    $e = fails(fn() => hb_parse(['host' => '192.168.1.20', 'port' => '70000', 'username' => '', 'password' => ''], null), 422, 'port/user/password');
    check(isset($e->errors['port'], $e->errors['username'], $e->errors['password']), 'field errors');
    $c = hb_parse(['host' => '192.168.1.20', 'port' => '5001', 'username' => 'ithub', 'password' => 'secret one'], null);
    check($c['port'] === 5001 && $c['password'] === 'secret one', 'parsed');
    check(hb_read() === null && hb_status()['connection'] === null, 'nothing saved yet');
    $saved = $c + ['pin' => 'sha256//x', 'fingerprint' => 'AA', 'revision' => 'r1', 'task_id' => 3, 'task_name' => 'atthasan', 'defaults' => ['server' => 'SERVER', 'dataset' => 'atthasan'], 'verified_at' => now(), 'last_sync' => null];
    hb_write($saved);
    check(!str_contains((string)file_get_contents("$dir/hyperbackup.enc"), 'secret one'), 'password not stored in clear');
    check((fileperms("$dir/hyperbackup.enc") & 0777) === 0660, 'file mode 0660');
    check(hb_read()['password'] === 'secret one', 'encrypted round trip');
    $pub = hb_status()['connection'];
    check(!isset($pub['password']) && !isset($pub['pin']) && $pub['task_name'] === 'atthasan', 'public view hides password/pin');
    $again = hb_parse(['revision' => 'r1', 'host' => '192.168.1.20', 'port' => 5001, 'username' => 'ithub', 'password' => ''], hb_read());
    check($again['password'] === 'secret one', 'blank password reuses saved for same account');
    $e = fails(fn() => hb_parse(['revision' => 'r1', 'host' => '192.168.1.21', 'port' => 5001, 'username' => 'ithub', 'password' => ''], hb_read()), 422, 'saved password not sent to another host');
    check(isset($e->errors['password']), 'password required for new host');
    fails(fn() => hb_parse(['revision' => 'old', 'host' => '192.168.1.20', 'port' => 5001, 'username' => 'ithub', 'password' => 'x'], hb_read()), 409, 'stale revision');
    fails(fn() => hb_disconnect(['revision' => 'old']), 409, 'disconnect needs current revision');
    check(hb_disconnect(['revision' => 'r1'])['connection'] === null && hb_read() === null, 'disconnect removes settings');
    // pinned TLS against a local non-DSM port fails cleanly
    fails(fn() => hb_certificate('127.0.0.1', 1), 422, 'unreachable DSM');
    // --- planner (no network)
    $v = fn(string $id, string $start, string $end, string $status = 'success') => ['version_id' => $id, 'start_time_local' => $start, 'complete_time_local' => $end, 'status' => $status];
    $defaults = ['server' => 'SERVER', 'dataset' => 'atthasan', 'source' => 'NAS_ATS', 'device' => 'BACKUP_ATS', 'folder' => 'atthasan'];
    $versions = [$v('750', '2026/11/27 01:00:13', '2026/11/27 01:15:08'), $v('749', '2026/11/26 01:00:10', '2026/11/26 01:10:00'), $v('740', '2026/11/20 01:00:02', '2026/11/20 03:00:01'),
        $v('733', '2026/11/13 01:00:03', '2026/11/13 01:20:00', 'fail'), $v('600', '2026/09/04 01:00:00', '2026/09/04 01:10:00')];
    check(hb_size_text(889165605) === '847.97 GB' && hb_size_text(null) === null && hb_size_text(0) === null, 'KiB shown as GB like DSM');
    $plan = hb_plan($versions, 889165605, '2026-11-27', '2026-11-01', $defaults, [], 'ATS-BACKUP-SERVER-1');
    $by = array_column($plan, null, 'backup_date');
    check(array_keys($by) === ['2026-11-06', '2026-11-13', '2026-11-20', '2026-11-27'], 'Fridays only, within window, plus missing Friday 06/11 ' . implode(',', array_keys($by)));
    check($by['2026-11-27']['data_size'] === '847.97 GB' && $by['2026-11-27']['backup_size'] === '847.97 GB' && $by['2026-11-27']['notes'] === null, 'today\'s newest run gets the size');
    check($by['2026-11-27']['started_at'] === '01:00:13' && $by['2026-11-27']['finished_at'] === '01:15:08', 'start/finish from version');
    check($by['2026-11-27']['maker'] === HB_MAKER && $by['2026-11-27']['acknowledger'] === null && $by['2026-11-27']['dataset'] === 'atthasan', 'maker = system, acknowledger blank, defaults');
    check($by['2026-11-20']['data_size'] === null && str_contains($by['2026-11-20']['notes'], 'ไม่มีขนาด'), 'older run: no size, noted');
    check(str_contains($by['2026-11-13']['notes'], 'ไม่สำเร็จ (fail)'), 'failed run noted');
    check($by['2026-11-06']['started_at'] === null && str_contains($by['2026-11-06']['notes'], 'ไม่มีการ Backup'), 'missing Friday noted');
    check(str_contains($by['2026-11-27']['source_note'], 'version 750'), 'source note has version');
    $sat = hb_plan($versions, 889165605, '2026-11-28', '2026-11-01', $defaults, [], 'T');
    check(array_column($sat, null, 'backup_date')['2026-11-27']['data_size'] === null, 'synced a day late: Friday row has no size');
    $skip = hb_plan($versions, 1, '2026-11-27', '2026-11-25', $defaults, [backup_key(['backup_date' => '2026-11-20', 'server' => 'SERVER', 'dataset' => 'atthasan']) => true], 'T');
    check(array_column($skip, 'backup_date') === ['2026-11-13', '2026-11-27'], 'existing run skipped; no missing rows before $since');
    $fri = hb_plan([], null, '2026-11-27', '2026-11-27', $defaults, [], 'T');
    check($fri === [], 'today\'s Friday without a version yet is not flagged (backup may still run)');
    check(hb_local('2026/11/25 01:00') === ['2026-11-25', '01:00:00'] && hb_local('bad') === null, 'local time parse');
} finally {
    foreach (glob("$dir/{,.}*", GLOB_BRACE) ?: [] as $f) if (is_file($f)) unlink($f);
    @rmdir($dir);
}
echo "hyperbackup: $checks checks passed\n";
