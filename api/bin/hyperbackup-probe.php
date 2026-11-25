<?php
declare(strict_types=1);

/*
 * Diagnostic: show what the saved Hyper Backup task reports (field names and values), read-only.
 * Never prints the password or session id.   php api/bin/hyperbackup-probe.php [versions-limit]
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/backups.php';
require __DIR__ . '/../src/hyperbackup.php';

$c = hb_read() ?? exit("no Hyper Backup connection saved\n");
$limit = (int)($argv[1] ?? 6);
$show = function (string $title, callable $call): void {
    try { $d = $call(); echo "== $title\n", json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n"; }
    catch (Throwable $e) { echo "== $title FAILED: {$e->getMessage()}\n"; }
};
hb_session($c, function (string $sid) use ($c, $limit, $show) {
    $t = $c['task_id'];
    $show('task list', fn() => hb_call($c, ['api' => 'SYNO.Backup.Task', 'version' => 1, 'method' => 'list', 'additional' => json_encode(['last_bkp_time', 'next_bkp_time', 'last_bkp_result', 'is_modified', 'last_bkp_progress', 'last_bkp_success_version'])], $sid));
    $show('task get', fn() => hb_call($c, ['api' => 'SYNO.Backup.Task', 'version' => 1, 'method' => 'get', 'task_id' => $t, 'additional' => json_encode(['repository', 'schedule', 'last_bkp_time', 'last_bkp_result', 'last_bkp_success_version'])], $sid));
    $show('task status', fn() => hb_call($c, ['api' => 'SYNO.Backup.Task', 'version' => 1, 'method' => 'status', 'task_id' => $t, 'blOnline' => 'false', 'additional' => json_encode(['last_bkp_time', 'next_bkp_time', 'last_bkp_result', 'is_modified', 'last_bkp_progress', 'last_bkp_success_version', 'last_bkp_end_time', 'last_bkp_success_time', 'last_bkp_error'])], $sid));
    $show('version list', fn() => hb_call($c, ['api' => 'SYNO.Backup.Version', 'version' => 1, 'method' => 'list', 'task_id' => $t, 'offset' => 0, 'limit' => $limit], $sid));
    $show('version history', fn() => hb_call($c, ['api' => 'SYNO.Backup.Version.History', 'version' => 1, 'method' => 'list', 'task_id' => $t, 'offset' => 0, 'limit' => $limit], $sid));
    $show('backup log', fn() => hb_call($c, ['api' => 'SYNO.Core.SyslogClient.Log', 'version' => 1, 'method' => 'list', 'logtype' => 'backup', 'offset' => 0, 'limit' => 30, 'keyword' => $c['task_name']], $sid));
});
