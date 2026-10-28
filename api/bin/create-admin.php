<?php
// สร้างหรือรีเซ็ตรหัสผ่านผู้ดูแล
// ใช้: php api/bin/create-admin.php <username> "<ชื่อที่แสดง>"   (ระบบจะถามรหัสผ่าน)
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../src/bootstrap.php';

[$script, $username, $displayName] = $argv + [null, null, null];
if (!$username) {
    fwrite(STDERR, "ใช้: php {$script} <username> \"<ชื่อที่แสดง>\"\n");
    exit(1);
}

$password = getenv('ITHUB_ADMIN_PASSWORD') ?: null;
if ($password === null) {
    echo 'รหัสผ่าน (อย่างน้อย 8 ตัว): ';
    system('stty -echo');
    $password = trim((string)fgets(STDIN));
    system('stty echo');
    echo "\n";
}
if (mb_strlen($password) < 8) {
    fwrite(STDERR, "รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
db()->prepare('INSERT INTO admins (username, password_hash, display_name) VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), is_active = 1, display_name = COALESCE(?, display_name)')
    ->execute([$username, $hash, $displayName ?? $username, $displayName]);

echo "บันทึกผู้ดูแล {$username} เรียบร้อย\n";
