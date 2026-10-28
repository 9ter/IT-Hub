<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/assets.php';
require __DIR__ . '/../src/admin.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

set_exception_handler(function (Throwable $e): void {
    if ($e instanceof HttpError) {
        json_out(['message' => $e->getMessage(), 'errors' => (object)$e->errors], $e->status);
    }
    error_log('[it-hub] ' . $e);
    json_out(['message' => cfg('debug') ? $e->getMessage() : 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่หรือแจ้ง IT'], 500);
});

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = '/' . ltrim(substr($path, (int)strpos($path, '/api/') + 4), '/');

// คำขอที่เปลี่ยนข้อมูลต้องมี header นี้ — เบราว์เซอร์จะส่งข้ามโดเมนไม่ได้ถ้าไม่ผ่าน CORS (กัน CSRF)
if ($method !== 'GET' && ($_SERVER['HTTP_X_IT_HUB'] ?? '') !== '1') {
    fail('Forbidden', 403);
}

$r = new Router();

// ---------- สาธารณะ (ไม่ต้อง login) ----------
$r->get('/meta', fn() => meta_payload());
$r->get('/assets/{code}', fn(string $code) => asset_public($code));
$r->post('/tickets', fn() => create_ticket(request_body(), $_FILES['photo'] ?? null));
$r->get('/track/{code}', fn(string $no) => ticket_public($no));

// ---------- เข้าสู่ระบบ ----------
$r->post('/auth/login', function () {
    $in = new Input(request_body());
    $username = $in->str('username', 50, true, 'ชื่อผู้ใช้');
    $password = $in->str('password', 200, true, 'รหัสผ่าน');
    $in->check();
    return login($username, $password);
});
$r->post('/auth/logout', function () {
    logout();
    return ['ok' => true];
});
$r->get('/auth/me', fn() => ['admin' => current_admin()]);
$r->post('/auth/password', fn() => change_own_password(request_body(), require_admin()));

// ---------- ผู้ดูแล ----------
$r->get('/admin/dashboard', function () {
    require_admin();
    return dashboard();
});

$r->get('/admin/tickets', function () {
    require_admin();
    return ticket_list($_GET);
});
$r->get('/admin/tickets/{id}', function (int $id) {
    require_admin();
    return ticket_admin($id);
});
$r->patch('/admin/tickets/{id}', fn(int $id) => update_ticket($id, request_body(), require_admin()));
$r->get('/admin/tickets/{id}/photo', function (int $id) {
    require_admin();
    $stmt = db()->prepare('SELECT photo_path FROM tickets WHERE id = ?');
    $stmt->execute([$id]);
    send_upload((string)($stmt->fetchColumn() ?: fail('ไม่มีรูป', 404)));
});

$r->get('/admin/assets', function () {
    require_admin();
    return asset_list($_GET);
});
$r->post('/admin/assets', function () {
    require_admin();
    return asset_save(null, request_body());
});
$r->get('/admin/assets/{id}', function (int $id) {
    require_admin();
    return asset_detail($id);
});
$r->put('/admin/assets/{id}', function (int $id) {
    require_admin();
    return asset_save($id, request_body());
});
$r->delete('/admin/assets/{id}', function (int $id) {
    require_admin();
    return asset_delete($id);
});
$r->post('/admin/assets/{id}/maintenance', fn(int $id) => maintenance_add($id, request_body(), require_admin()));
$r->delete('/admin/assets/{id}/maintenance/{id}', function (int $assetId, int $recordId) {
    require_admin();
    return maintenance_delete($assetId, $recordId);
});

$r->get('/admin/admins', function () {
    require_admin();
    return admin_list();
});
$r->post('/admin/admins', fn() => admin_save(null, request_body(), require_admin()));
$r->put('/admin/admins/{id}', fn(int $id) => admin_save($id, request_body(), require_admin()));

$r->dispatch($method, $path);
