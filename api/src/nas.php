<?php
declare(strict_types=1);

// Application-level SMB mapping. No operating-system mount or elevated process.
function nas_state_dir(): string
{
    return rtrim((string)cfg('nas.state_dir', '/var/lib/it-hub/nas'), '/');
}

function nas_runtime_ready(): bool
{
    return function_exists('proc_open') && extension_loaded('sodium')
        && is_executable((string)cfg('nas.python', '/usr/bin/python3'))
        && is_file((string)cfg('nas.python_packages', '/var/lib/it-hub/python') . '/smbclient/__init__.py');
}

function nas_prepare_state(): void
{
    $dir = nas_state_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        fail('ยังไม่ได้เตรียมพื้นที่เก็บการตั้งค่า NAS กรุณาแจ้งผู้ดูแลเซิร์ฟเวอร์', 503);
    }
    if (!is_writable($dir)) {
        fail('ระบบไม่มีสิทธิ์บันทึกการตั้งค่า NAS กรุณาแจ้งผู้ดูแลเซิร์ฟเวอร์', 503);
    }
}

function nas_key(bool $create = false): string
{
    if (!extension_loaded('sodium')) fail('เซิร์ฟเวอร์ยังไม่รองรับการเข้ารหัสการตั้งค่า NAS', 503);
    $file = nas_state_dir() . '/secret.key';
    if (!is_file($file) && $create) {
        $handle = @fopen($file, 'x');
        if ($handle !== false) {
            @chmod($file, 0660);
            $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            $ok = fwrite($handle, $key) === strlen($key) && fflush($handle);
            fclose($handle);
            if (!$ok) { @unlink($file); fail('ไม่สามารถสร้างกุญแจเข้ารหัส NAS ได้', 503); }
        }
    }
    $key = @file_get_contents($file);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        fail('ไม่พบกุญแจสำหรับอ่านการตั้งค่า NAS กรุณาแจ้งผู้ดูแลเซิร์ฟเวอร์', 503);
    }
    return $key;
}

function nas_read(): ?array
{
    $file = nas_state_dir() . '/mapping.enc';
    if (!is_file($file)) return null;
    if (!extension_loaded('sodium')) fail('เซิร์ฟเวอร์ยังไม่รองรับการเข้ารหัสการตั้งค่า NAS', 503);
    $raw = @file_get_contents($file);
    if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        fail('อ่านการตั้งค่า NAS ไม่สำเร็จ กรุณาแจ้งผู้ดูแลเซิร์ฟเวอร์', 503);
    }
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), nas_key());
    $data = $plain === false ? null : json_decode($plain, true);
    if (!is_array($data) || !isset($data['revision'], $data['unc'], $data['host'], $data['share'], $data['username'], $data['password'], $data['verified_at'])) {
        fail('การตั้งค่า NAS เสียหายหรือกุญแจไม่ตรง กรุณาแจ้งผู้ดูแลเซิร์ฟเวอร์', 503);
    }
    return $data;
}

function nas_public(?array $mapping): ?array
{
    if ($mapping === null) return null;
    return array_intersect_key($mapping, array_flip(['revision', 'unc', 'host', 'share', 'folder', 'username', 'domain', 'verified_at']));
}

function nas_status(): array
{
    return ['mapping' => nas_public(nas_read()), 'runtime_ready' => nas_runtime_ready()];
}

function nas_check_revision(array $body, ?array $saved): void
{
    if (($body['revision'] ?? null) !== ($saved['revision'] ?? null)) {
        fail('การตั้งค่า NAS ถูกเปลี่ยนแล้ว กรุณาโหลดหน้าใหม่ก่อนดำเนินการ', 409);
    }
}

/** Accept only UNC paths to private IPv4 LAN hosts; fixed SMB port 445. */
function nas_parse(array $body, ?array $saved): array
{
    nas_check_revision($body, $saved);
    $in = new Input($body);
    $unc = str_replace('/', '\\', $in->str('unc', 2048, true, 'พาธ NAS') ?? '');
    $username = $in->str('username', 128, true, 'ชื่อผู้ใช้') ?? '';
    $domain = $in->str('domain', 128, false, 'โดเมน') ?? '';
    $parts = explode('\\', rtrim($unc, '\\'));
    $host = $parts[2] ?? '';
    $share = $parts[3] ?? '';
    $ip = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    $octets = explode('.', $host);
    $private = $ip !== false && ($octets[0] === '10'
        || ($octets[0] === '172' && (int)$octets[1] >= 16 && (int)$octets[1] <= 31)
        || ($octets[0] === '192' && $octets[1] === '168'));
    if (!str_starts_with($unc, '\\\\') || !$private || $share === '' || count($parts) < 4) {
        $in->error('unc', 'ใช้พาธ \\\\IP ภายในเครือข่าย\\ชื่อแชร์\\โฟลเดอร์ เช่น \\\\192.168.1.10\\Documents\\IT');
    }
    foreach (array_slice($parts, 3) as $part) {
        if ($part === '' || $part === '.' || $part === '..' || preg_match('/[\x00-\x1f\x7f:*?"<>|]/u', $part)) {
            $in->error('unc', 'พาธ NAS มีชื่อโฟลเดอร์ไม่ถูกต้อง ห้ามใช้ . หรือ .. หรืออักขระพิเศษ');
        }
    }
    if (preg_match('/[\x00-\x1f\x7f]/u', $username . $domain)) $in->error('username', 'ชื่อผู้ใช้หรือโดเมนมีอักขระไม่ถูกต้อง');
    $password = $body['password'] ?? '';
    if (!is_string($password) || strlen($password) > 2048 || str_contains($password, "\0")) {
        $in->error('password', 'รหัสผ่านไม่ถูกต้อง');
        $password = '';
    }
    if ($password === '') {
        // Never send a saved password to a different server, share or account.
        if ($saved !== null && strcasecmp($host, $saved['host']) === 0 && strcasecmp($share, $saved['share']) === 0
            && $username === $saved['username'] && $domain === ($saved['domain'] ?? '')) {
            $password = $saved['password'];
        } else {
            $in->error('password', 'กรุณากรอกรหัสผ่านสำหรับการเชื่อมต่อนี้');
        }
    }
    $in->check();
    $folder = implode('\\', array_slice($parts, 4));
    return ['unc' => '\\\\' . $host . '\\' . $share . ($folder !== '' ? '\\' . $folder : ''),
        'host' => $host, 'share' => $share, 'folder' => $folder, 'username' => $username, 'domain' => $domain, 'password' => $password];
}

/** Secrets travel only on the child process's stdin, never argv, env, files or logs. */
function nas_probe(array $mapping): array
{
    if (!nas_runtime_ready()) fail('เซิร์ฟเวอร์ยังไม่ได้ติดตั้งตัวเชื่อมต่อ NAS กรุณาแจ้งผู้ดูแลเซิร์ฟเวอร์', 503);
    $command = [(string)cfg('nas.python', '/usr/bin/python3'), '-I', __DIR__ . '/../bin/nas-smb.py', (string)cfg('nas.python_packages', '/var/lib/it-hub/python')];
    $pipes = [];
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PYTHONDONTWRITEBYTECODE' => '1']);
    if (!is_resource($process)) fail('เริ่มการเชื่อมต่อ NAS ไม่สำเร็จ', 503);
    $out = '';
    $err = '';
    $expired = false;
    try {
        $input = json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $written = 0;
        while ($written < strlen($input)) {
            $n = @fwrite($pipes[0], substr($input, $written));
            if (!$n) fail('ส่งข้อมูลให้ตัวเชื่อมต่อ NAS ไม่สำเร็จ', 503);
            $written += $n;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 22;
        do {
            $out .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
            $running = proc_get_status($process)['running'];
            if (strlen($out) > 262144 || strlen($err) > 8192 || microtime(true) > $deadline) {
                $expired = true;
                proc_terminate($process, 9);
                break;
            }
            if ($running) usleep(25000);
        } while ($running);
        $out .= stream_get_contents($pipes[1]);
    } finally {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($process);
    }
    if ($expired) fail('NAS ไม่ตอบสนองภายในเวลาที่กำหนด กรุณาตรวจสอบเครือข่ายแล้วลองใหม่', 504);
    $result = json_decode($out, true);
    if (!is_array($result) || !isset($result['ok'])) fail('ตัวเชื่อมต่อ NAS ทำงานไม่สำเร็จ กรุณาแจ้งผู้ดูแลเซิร์ฟเวอร์', 502);
    if ($result['ok'] !== true) {
        $messages = [
            'auth' => 'ชื่อผู้ใช้หรือรหัสผ่าน NAS ไม่ถูกต้อง หรือบัญชีถูกระงับ',
            'denied' => 'บัญชี NAS ไม่มีสิทธิ์อ่านแชร์หรือโฟลเดอร์นี้',
            'missing' => 'ไม่พบแชร์หรือโฟลเดอร์นี้บน NAS กรุณาตรวจสอบพาธ',
            'network' => 'เชื่อมต่อ NAS ไม่ได้ กรุณาตรวจสอบ IP เครือข่าย และบริการ SMB',
            'runtime' => 'ตัวเชื่อมต่อ NAS ยังไม่พร้อม กรุณาแจ้งผู้ดูแลเซิร์ฟเวอร์',
            'unsupported' => 'NAS ไม่รองรับการเชื่อมต่อ SMB ที่ระบบต้องการ',
        ];
        fail($messages[$result['code'] ?? ''] ?? 'อ่านโฟลเดอร์ NAS ไม่สำเร็จ กรุณาตรวจสอบสิทธิ์และพาธ', 422);
    }
    return ['entries' => $result['entries'], 'truncated' => (bool)$result['truncated'], 'checked_at' => now()];
}

function nas_test(array $body): array
{
    return ['listing' => nas_probe(nas_parse($body, nas_read()))];
}

function nas_locked(callable $action): array
{
    nas_prepare_state();
    $file = nas_state_dir() . '/state.lock';
    $lock = @fopen($file, 'c');
    if ($lock === false) fail('ไม่สามารถล็อกการตั้งค่า NAS ได้', 503);
    @chmod($file, 0660);
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) fail('มีการบันทึกการตั้งค่า NAS อยู่ กรุณาลองอีกครั้ง', 409);
        return $action();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function nas_save(array $body, array $actor): array
{
    return nas_locked(function () use ($body, $actor): array {
        $mapping = nas_parse($body, nas_read());
        $listing = nas_probe($mapping);
        $mapping += ['revision' => bin2hex(random_bytes(16)), 'verified_at' => $listing['checked_at'], 'saved_by' => (int)$actor['id']];
        $key = nas_key(true);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $raw = $nonce . sodium_crypto_secretbox(json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $nonce, $key);
        $file = nas_state_dir() . '/mapping.enc';
        $temp = tempnam(nas_state_dir(), '.mapping-');
        if ($temp === false) fail('บันทึกการตั้งค่า NAS ไม่สำเร็จ', 503);
        try {
            if (file_put_contents($temp, $raw) !== strlen($raw) || !chmod($temp, 0660) || !rename($temp, $file)) {
                fail('บันทึกการตั้งค่า NAS ไม่สำเร็จ', 503);
            }
        } finally {
            if (is_file($temp)) @unlink($temp);
        }
        return ['mapping' => nas_public($mapping), 'listing' => $listing, 'runtime_ready' => true];
    });
}

function nas_disconnect(array $body): array
{
    return nas_locked(function () use ($body): array {
        nas_check_revision($body, nas_read());
        $file = nas_state_dir() . '/mapping.enc';
        if (is_file($file) && !unlink($file)) fail('ลบการตั้งค่า NAS ไม่สำเร็จ', 503);
        return ['mapping' => null, 'runtime_ready' => nas_runtime_ready()];
    });
}
