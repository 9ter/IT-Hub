<?php
declare(strict_types=1);

/*
 * Read-only connection to Synology Hyper Backup (DSM Web API) for the FM-IT-005 backup log.
 * Settings and the DSM password are encrypted with the Map NAS key in the private state dir.
 * Synology DSM uses a self-signed certificate, so its public key is pinned when the admin saves
 * (the fingerprint is shown first) and every later call must present the same key.
 * Only read APIs are called (login, task list/status, version list, logout).
 */
const HB_AUTH_ERRORS = [
    400 => 'ชื่อผู้ใช้หรือรหัสผ่าน DSM ไม่ถูกต้อง', 401 => 'บัญชี DSM ถูกปิดใช้งาน', 402 => 'บัญชี DSM ไม่มีสิทธิ์เข้าใช้',
    403 => 'บัญชีนี้เปิดการยืนยันสองขั้นตอน (2FA) ใช้บัญชีที่ไม่มี 2FA', 404 => 'บัญชีนี้เปิดการยืนยันสองขั้นตอน (2FA) ใช้บัญชีที่ไม่มี 2FA',
    406 => 'DSM บังคับใช้ 2FA กับบัญชีนี้ ใช้บัญชีที่ไม่ถูกบังคับ 2FA', 407 => 'DSM บล็อก IP ของเซิร์ฟเวอร์ IT Hub (เข้าผิดหลายครั้ง)',
    408 => 'รหัสผ่าน DSM หมดอายุ', 409 => 'รหัสผ่าน DSM หมดอายุ', 410 => 'DSM บังคับให้เปลี่ยนรหัสผ่าน',
];

function hb_file(): string { return nas_state_dir() . '/hyperbackup.enc'; }

function hb_read(): ?array
{
    $file = hb_file();
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) fail('อ่านการตั้งค่า Hyper Backup ไม่สำเร็จ', 503);
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), nas_key());
    $data = $plain === false ? null : json_decode($plain, true);
    if (!is_array($data) || !isset($data['revision'], $data['host'], $data['port'], $data['username'], $data['password'], $data['pin'])) fail('การตั้งค่า Hyper Backup เสียหายหรือกุญแจไม่ตรง', 503);
    return $data;
}

function hb_write(?array $data): void
{
    nas_prepare_state();
    if ($data === null) { if (is_file(hb_file()) && !unlink(hb_file())) fail('ลบการตั้งค่า Hyper Backup ไม่สำเร็จ', 503); return; }
    $key = nas_key(true);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $raw = $nonce . sodium_crypto_secretbox(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $nonce, $key);
    $temp = tempnam(nas_state_dir(), '.hyperbackup-');
    if ($temp === false) fail('บันทึกการตั้งค่า Hyper Backup ไม่สำเร็จ', 503);
    try {
        if (file_put_contents($temp, $raw) !== strlen($raw) || !chmod($temp, 0660) || !rename($temp, hb_file())) fail('บันทึกการตั้งค่า Hyper Backup ไม่สำเร็จ', 503);
    } finally { if (is_file($temp)) @unlink($temp); }
}

/** What the admin page may see: never the password or the session. */
function hb_public(?array $c): ?array
{
    if ($c === null) return null;
    return array_intersect_key($c, array_flip(['revision', 'host', 'port', 'username', 'fingerprint', 'task_id', 'task_name', 'defaults', 'verified_at', 'last_sync']));
}

function hb_status(): array { return ['connection' => hb_public(hb_read())]; }

function hb_private_ipv4(string $host): bool
{
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return false;
    $o = array_map('intval', explode('.', $host));
    return $o[0] === 10 || ($o[0] === 172 && $o[1] >= 16 && $o[1] <= 31) || ($o[0] === 192 && $o[1] === 168);
}

/** Validated connection from the form; a blank password reuses the saved one only for the same host/port/account. */
function hb_parse(array $body, ?array $saved): array
{
    if ($saved !== null && ($body['revision'] ?? null) !== $saved['revision']) fail('การตั้งค่าถูกเปลี่ยนจากหน้าอื่น กรุณาโหลดใหม่', 409);
    $in = new Input($body);
    $host = $in->str('host', 64, true, 'IP ของ NAS') ?? '';
    $port = $in->int('port', 1, 65535, true, 'พอร์ต') ?? 0;
    $username = $in->str('username', 64, true, 'ชื่อผู้ใช้ DSM') ?? '';
    if ($host !== '' && !hb_private_ipv4($host)) $in->error('host', 'ใช้ IP ภายในเครือข่าย เช่น 192.168.1.10');
    if (preg_match('/[\x00-\x1f\x7f]/u', $username)) $in->error('username', 'ชื่อผู้ใช้มีอักขระไม่ถูกต้อง');
    $password = $body['password'] ?? '';
    if (!is_string($password) || strlen($password) > 512 || str_contains($password, "\0")) { $in->error('password', 'รหัสผ่านไม่ถูกต้อง'); $password = ''; }
    if ($password === '') {
        if ($saved !== null && $host === $saved['host'] && $port === (int)$saved['port'] && $username === $saved['username']) $password = $saved['password'];
        else $in->error('password', 'กรุณากรอกรหัสผ่าน DSM');
    }
    $in->check();
    return ['host' => $host, 'port' => $port, 'username' => $username, 'password' => $password];
}

/** Public-key pin (curl format) and SHA-256 certificate fingerprint of the DSM HTTPS port. */
function hb_certificate(string $host, int $port): array
{
    $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => false]]);
    $s = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
    if ($s === false) fail('เชื่อมต่อ DSM ไม่ได้ กรุณาตรวจสอบ IP / พอร์ต HTTPS (ปกติ 5001)', 422);
    $cert = stream_context_get_params($s)['options']['ssl']['peer_certificate'] ?? null;
    fclose($s);
    $key = $cert ? openssl_pkey_get_public($cert) : false;
    $pem = $key ? (openssl_pkey_get_details($key)['key'] ?? '') : '';
    $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
    if (!$cert || !$der) fail('อ่านใบรับรองของ DSM ไม่ได้', 422);
    return ['pin' => 'sha256//' . base64_encode(hash('sha256', $der, true)), 'fingerprint' => strtoupper(implode(':', str_split(openssl_x509_fingerprint($cert, 'sha256'), 2)))];
}

/** One DSM Web API call (POST, pinned key). Returns data or throws with a Thai message. */
function hb_call(array $c, array $params, ?string $sid = null): array
{
    $ch = curl_init("https://{$c['host']}:{$c['port']}/webapi/entry.cgi");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params + ($sid ? ['_sid' => $sid] : [])),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_PINNEDPUBLICKEY => $c['pin'],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    if ($errno === CURLE_SSL_PINNEDPUBKEYNOTMATCH) fail('ใบรับรองของ DSM เปลี่ยนไปจากที่บันทึกไว้ กรุณาตรวจสอบและบันทึกการเชื่อมต่อใหม่', 422);
    if ($body === false) fail('เชื่อมต่อ DSM ไม่ได้ กรุณาตรวจสอบเครือข่าย', 504);
    $json = json_decode((string)$body, true);
    if (!is_array($json)) fail('DSM ตอบกลับไม่ถูกต้อง', 502);
    if (empty($json['success'])) {
        $code = (int)($json['error']['code'] ?? 0);
        if (($params['api'] ?? '') === 'SYNO.API.Auth' && isset(HB_AUTH_ERRORS[$code])) fail(HB_AUTH_ERRORS[$code], 422);
        if (in_array($code, [105, 106, 119], true)) fail('บัญชี DSM ไม่มีสิทธิ์อ่าน Hyper Backup (ต้องอยู่ในกลุ่ม administrators)', 422);
        fail("DSM ปฏิเสธคำขอ ({$params['api']} รหัส $code)", 422);
    }
    return is_array($json['data'] ?? null) ? $json['data'] : [];
}

/** Log in, run $fn($sid), always log out. */
function hb_session(array $c, callable $fn): mixed
{
    $sid = (string)(hb_call($c, ['api' => 'SYNO.API.Auth', 'version' => 6, 'method' => 'login', 'account' => $c['username'],
        'passwd' => $c['password'], 'session' => 'ITHubBackup', 'format' => 'sid'])['sid'] ?? '');
    if ($sid === '') fail('เข้าสู่ระบบ DSM ไม่สำเร็จ', 422);
    try { return $fn($sid); }
    finally { try { hb_call($c, ['api' => 'SYNO.API.Auth', 'version' => 6, 'method' => 'logout', 'session' => 'ITHubBackup'], $sid); } catch (Throwable) {} }
}

function hb_tasks(array $c, string $sid): array
{
    $d = hb_call($c, ['api' => 'SYNO.Backup.Task', 'version' => 1, 'method' => 'list',
        'additional' => json_encode(['last_bkp_time', 'next_bkp_time', 'last_bkp_result', 'is_modified', 'last_bkp_progress'])], $sid);
    $list = $d['task_list'] ?? $d['tasks'] ?? [];
    return array_values(array_map(fn($t) => [
        'task_id' => (int)($t['task_id'] ?? 0), 'name' => (string)($t['name'] ?? ''), 'state' => (string)($t['state'] ?? ''),
        'last_bkp_time' => (string)($t['last_bkp_time'] ?? ''), 'last_bkp_result' => (string)($t['last_bkp_result'] ?? ''),
        'next_bkp_time' => (string)($t['next_bkp_time'] ?? ''),
    ], array_filter(is_array($list) ? $list : [], 'is_array')));
}

/** Test without saving: certificate fingerprint + Hyper Backup tasks the account can see. */
function hb_test(array $body): array
{
    $c = hb_parse($body, hb_read());
    $c += hb_certificate($c['host'], $c['port']);
    $tasks = hb_session($c, fn($sid) => hb_tasks($c, $sid));
    return ['fingerprint' => $c['fingerprint'], 'tasks' => $tasks, 'checked_at' => now()];
}

/** Save after a fresh test; the admin confirms the fingerprint seen in the test. */
function hb_save(array $body, array $actor): array
{
    return nas_locked(function () use ($body, $actor): array {
        $saved = hb_read();
        $c = hb_parse($body, $saved);
        $c += hb_certificate($c['host'], $c['port']);
        if (($body['fingerprint'] ?? '') !== $c['fingerprint']) fail('ใบรับรองของ DSM ไม่ตรงกับที่ตรวจสอบ กรุณากดทดสอบอีกครั้ง', 409);
        $tasks = hb_session($c, fn($sid) => hb_tasks($c, $sid));
        $taskId = (int)($body['task_id'] ?? 0);
        $task = current(array_filter($tasks, fn($t) => $t['task_id'] === $taskId));
        if (!$task) fail('กรุณาเลือกงาน Hyper Backup', 422, ['task_id' => 'กรุณาเลือกงาน Hyper Backup']);
        $in = new Input(is_array($body['defaults'] ?? null) ? $body['defaults'] : []);
        $defaults = [];
        foreach (['server' => [100, 'ชื่อ Server'], 'dataset' => [150, 'ข้อมูลที่ BACK UP'], 'source' => [150, 'แหล่งจัดเก็บข้อมูลหลัก'], 'device' => [150, 'อุปกรณ์สำรอง'], 'folder' => [255, 'Folder / File']] as $k => [$max, $label]) {
            $defaults[$k] = $in->str($k, $max, $k === 'dataset', $label);
        }
        $in->check();
        $defaults['server'] ??= BACKUP_DEFAULT_SERVER;
        $c += ['revision' => bin2hex(random_bytes(16)), 'task_id' => $taskId, 'task_name' => $task['name'], 'defaults' => $defaults,
            'verified_at' => now(), 'saved_by' => (int)$actor['id'], 'last_sync' => $saved['last_sync'] ?? null];
        hb_write($c);
        return ['connection' => hb_public($c)];
    });
}

function hb_disconnect(array $body): array
{
    return nas_locked(function () use ($body): array {
        $saved = hb_read();
        if ($saved !== null && ($body['revision'] ?? null) !== $saved['revision']) fail('การตั้งค่าถูกเปลี่ยนจากหน้าอื่น กรุณาโหลดใหม่', 409);
        hb_write(null);
        return ['connection' => null];
    });
}

const HB_MAKER = 'ระบบอัตโนมัติ (Hyper Backup)';
const HB_WEEKDAY = 5; // ISO weekday recorded in FM-IT-005 (Friday), same as the paper log
const HB_WINDOW_DAYS = 35;

/** Target used size (KiB from the API) as shown in DSM, e.g. 847.99 GB (binary units). */
function hb_size_text(?int $kib): ?string
{
    return $kib === null || $kib <= 0 ? null : sprintf('%.2f GB', $kib / 1048576);
}

/** "2026/11/25 01:00:13" (NAS local time) → [Y-m-d, H:i:s] */
function hb_local(?string $value): ?array
{
    if (!is_string($value) || !preg_match('#^(\d{4})/(\d{2})/(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$#', $value, $m)) return null;
    return ["$m[1]-$m[2]-$m[3]", sprintf('%s:%s:%s', $m[4], $m[5], $m[6] ?? '00')];
}

/**
 * Pure planning: which Friday rows to add from Hyper Backup versions.
 * - each Friday version → one row with its start/finish time; failed runs get a note
 * - the size is the target's current size, so it is filled only for the newest version started today
 *   (the job runs each morning right after the 01:00 backup); otherwise left blank with a note
 * - a past Friday on/after $since with no version at all → a row noting there was no backup
 * Existing runs (same date/server/dataset) are skipped, never overwritten.
 */
function hb_plan(array $versions, ?int $sizeKib, string $today, string $since, array $defaults, array $existingKeys, string $taskName): array
{
    $rows = []; $byDate = [];
    usort($versions, fn($a, $b) => strcmp((string)($b['start_time_local'] ?? ''), (string)($a['start_time_local'] ?? '')));
    $newest = $versions[0]['version_id'] ?? null;
    $base = ['server' => $defaults['server'] ?: BACKUP_DEFAULT_SERVER, 'dataset' => $defaults['dataset'], 'source' => $defaults['source'] ?? null,
        'device' => $defaults['device'] ?? null, 'folder' => $defaults['folder'] ?? null, 'maker' => HB_MAKER, 'acknowledger' => null];
    $from = date('Y-m-d', strtotime("$today -" . HB_WINDOW_DAYS . ' days'));
    foreach ($versions as $v) {
        $start = hb_local($v['start_time_local'] ?? null);
        if (!$start || $start[0] < $from || $start[0] > $today || (int)date('N', strtotime($start[0])) !== HB_WEEKDAY || isset($byDate[$start[0]])) continue;
        $byDate[$start[0]] = true;
        $end = hb_local($v['complete_time_local'] ?? null);
        $ok = ($v['status'] ?? '') === 'success';
        $notes = [];
        if (!$ok) $notes[] = 'Hyper Backup ไม่สำเร็จ (' . ($v['status'] ?? 'ไม่ทราบสถานะ') . ')';
        $size = ($v['version_id'] ?? null) === $newest && $start[0] === $today && $ok ? hb_size_text($sizeKib) : null;
        if ($size === null && $ok) $notes[] = 'ไม่มีขนาดไฟล์ (ระบบอ่านขนาดได้เฉพาะเช้าวันที่ Backup)';
        $rows[] = ['backup_date' => $start[0], 'started_at' => $start[1], 'finished_at' => $end[1] ?? null, 'data_size' => $size, 'backup_size' => $size,
            'notes' => $notes ? implode(' · ', $notes) : null, 'source_note' => "Hyper Backup $taskName version " . ($v['version_id'] ?? '?')] + $base;
    }
    for ($d = max($since, $from); $d < $today; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        if ((int)date('N', strtotime($d)) !== HB_WEEKDAY || isset($byDate[$d])) continue;
        $rows[] = ['backup_date' => $d, 'started_at' => null, 'finished_at' => null, 'data_size' => null, 'backup_size' => null,
            'notes' => 'ไม่มีการ Backup (ไม่พบรายการใน Hyper Backup)', 'source_note' => "Hyper Backup $taskName: ไม่พบ version"] + $base;
    }
    $rows = array_values(array_filter($rows, fn($r) => !isset($existingKeys[backup_key($r)])));
    usort($rows, fn($a, $b) => strcmp($a['backup_date'], $b['backup_date']));
    return $rows;
}

function hb_fetch(array $c): array
{
    return hb_session($c, function (string $sid) use ($c): array {
        $v = hb_call($c, ['api' => 'SYNO.Backup.Version', 'version' => 2, 'method' => 'list', 'task_id' => $c['task_id'], 'offset' => 0, 'limit' => 60], $sid);
        $t = hb_call($c, ['api' => 'SYNO.Backup.Target', 'version' => 1, 'method' => 'get', 'task_id' => $c['task_id'], 'additional' => json_encode(['used_size'])], $sid);
        return ['versions' => array_values(array_filter($v['version_info_list'] ?? [], 'is_array')),
            'size_kib' => isset($t['used_size']) && is_numeric($t['used_size']) ? (int)$t['used_size'] : null];
    });
}

/** Read Hyper Backup and add the new Friday rows. $adminId null = the morning job. */
function hb_sync(?int $adminId, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $c = hb_read() ?? fail('ยังไม่ได้เชื่อมต่อ Hyper Backup', 422);
    $result = ['at' => now(), 'added' => 0, 'ok' => false, 'message' => ''];
    try {
        $fetched = hb_fetch($c);
        $pdo = db(); $pdo->beginTransaction();
        try {
            $existing = [];
            foreach ($pdo->query('SELECT backup_date, server, dataset FROM server_backups FOR UPDATE')->fetchAll() as $r) $existing[backup_key($r)] = true;
            $rows = hb_plan($fetched['versions'], $fetched['size_kib'], $today, substr((string)$c['verified_at'], 0, 10), $c['defaults'], $existing, $c['task_name']);
            $added = backup_apply_adds(['sources' => [['_add' => $rows]]], $adminId);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        $result = ['at' => now(), 'added' => $added, 'ok' => true, 'message' => $added ? "เพิ่ม $added แถว" : 'ไม่มีรายการใหม่',
            'dates' => array_column($rows, 'backup_date'), 'size' => hb_size_text($fetched['size_kib'])];
    } catch (HttpError $e) {
        $result['message'] = $e->getMessage();
    }
    nas_locked(function () use ($result): array {
        if ($c = hb_read()) { $c['last_sync'] = array_intersect_key($result, array_flip(['at', 'added', 'ok', 'message'])); hb_write($c); }
        return [];
    });
    if (!$result['ok']) fail('ดึงข้อมูลจาก Hyper Backup ไม่สำเร็จ: ' . $result['message'], 422);
    return $result;
}
