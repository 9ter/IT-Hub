<?php
declare(strict_types=1);

const LOGIN_MAX_FAILS = 5;
const LOGIN_WINDOW_MINUTES = 15;

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('ithub_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => cfg('base_path', '/'),
        'secure' => ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '43200');
    session_start();
}

function current_admin(): ?array
{
    static $admin = false;
    if ($admin !== false) {
        return $admin;
    }
    start_session();
    $admin = null;
    if (!empty($_SESSION['admin_id'])) {
        $stmt = db()->prepare('SELECT id, username, display_name FROM admins WHERE id = ? AND is_active = 1');
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch() ?: null;
        if ($admin === null) {
            unset($_SESSION['admin_id']);
        }
    }
    return $admin;
}

function require_admin(): array
{
    return current_admin() ?? fail('กรุณาเข้าสู่ระบบ', 401);
}

function login(string $username, string $password): array
{
    $ip = client_ip();
    $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE');
    $stmt->execute([$ip]);
    if ((int)$stmt->fetchColumn() >= LOGIN_MAX_FAILS) {
        fail('เข้าสู่ระบบผิดหลายครั้ง กรุณารอ ' . LOGIN_WINDOW_MINUTES . ' นาที', 429);
    }

    $stmt = db()->prepare('SELECT * FROM admins WHERE username = ? AND is_active = 1');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        db()->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)')->execute([$ip, mb_substr($username, 0, 50)]);
        fail('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 401);
    }

    if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
    }
    db()->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([$admin['id']]);
    db()->prepare('DELETE FROM login_attempts WHERE ip = ? OR attempted_at < NOW() - INTERVAL 1 DAY')->execute([$ip]);

    start_session();
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$admin['id'];

    return ['id' => (int)$admin['id'], 'username' => $admin['username'], 'display_name' => $admin['display_name']];
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
    setcookie('ithub_sid', '', ['expires' => time() - 3600, 'path' => cfg('base_path', '/')]);
}

function validate_password(Input $in, string $key, bool $required = true): ?string
{
    $password = $in->str($key, 200, $required, 'รหัสผ่าน');
    if ($password !== null && mb_strlen($password) < 8) {
        $in->error($key, 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
    }
    return $password;
}
