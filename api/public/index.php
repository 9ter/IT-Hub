<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/assets.php';
require __DIR__ . '/../src/asset_import.php';
require __DIR__ . '/../src/document_import.php';
require __DIR__ . '/../src/inspections.php';
require __DIR__ . '/../src/backups.php';
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
$r->get('/approvers', fn() => employee_approvers());
$r->get('/companies', fn() => public_companies());
$r->get('/companies/{id}/logo', fn(int $id) => company_logo_send($id));
$r->post('/approval/view', fn() => approval_view(request_body()));
$r->post('/approval/decide', fn() => approval_decide(request_body()));
$r->post('/request-edit/view', fn() => request_edit_view(request_body()));
$r->post('/request-edit/submit', fn() => request_edit_submit(request_body()));
$r->post('/document-approval/view', fn() => document_approval_view(request_body()));
$r->post('/document-approval/decide', fn() => document_decide(request_body()));

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
$r->get('/admin/nas', function () { require_admin(); return nas_status(); });
$r->post('/admin/nas/test', function () { require_admin(); return nas_test(request_body()); });
$r->post('/admin/nas', fn() => nas_save(request_body(), require_admin()));
$r->post('/admin/nas/disconnect', function () { require_admin(); return nas_disconnect(request_body()); });
$r->get('/admin/document-forms', function () { require_admin(); return ['forms'=>document_forms(),'statuses'=>DOCUMENT_STATUSES]; });
$r->get('/admin/document-templates/{code}', function (string $code) { require_admin(); document_template($code); });
$r->get('/admin/document-import/template/{code}', function (string $code) { require_admin(); document_import_template($code); });
$r->post('/admin/document-import/preview', fn() => document_import_upload($_FILES['file'] ?? [], require_admin()));
$r->post('/admin/document-import/apply', fn() => document_import_upload($_FILES['file'] ?? [], require_admin(), true));
$r->get('/admin/documents', function () { require_admin(); return document_list($_GET); });
$r->get('/admin/document-prefill', function () { require_admin(); return document_prefill($_GET); });
$r->get('/admin/borrow-requests', function () { require_admin(); return borrow_pending_requests($_GET); });
$r->get('/admin/borrow-items', function () { require_admin(); return borrow_items($_GET); });
// FM-IT-002 ใบตรวจสอบโครงสร้างพื้นฐาน (living yearly checklist)
$r->get('/admin/backups', function () { require_admin(); return backup_get($_GET); });
$r->post('/admin/backups/import/preview', fn() => backup_import_upload($_FILES['file'] ?? [], require_admin()));
$r->post('/admin/backups/import/apply', fn() => backup_import_upload($_FILES['file'] ?? [], require_admin(), true));
$r->post('/admin/backups', fn() => backup_create(request_body(), require_admin()));
$r->put('/admin/backups/{id}', fn(int $id) => backup_update($id, request_body(), require_admin()));
$r->delete('/admin/backups/{id}', function (int $id) { require_admin(); return backup_delete($id); });
$r->get('/admin/inspections', function () { require_admin(); return inspection_get($_GET); });
$r->post('/admin/inspections/import/preview', fn() => inspection_import_upload($_FILES['file'] ?? [], require_admin()));
$r->post('/admin/inspections/import/apply', fn() => inspection_import_upload($_FILES['file'] ?? [], require_admin(), true));
$r->put('/admin/inspections/items/{id}', function (int $id) { require_admin(); return inspection_update_item($id, request_body()); });
$r->delete('/admin/inspections/items/{id}', function (int $id) { require_admin(); return inspection_delete_item($id); });
$r->post('/admin/inspections/{year}/months/{month}', fn(string $year, string $month) => inspection_save_month($year, $month, request_body(), require_admin()));
$r->post('/admin/inspections/{year}/items/bulk', fn(string $year) => inspection_bulk_add($year, request_body(), require_admin()));
$r->post('/admin/inspections/{year}/items', fn(string $year) => inspection_add_item($year, request_body(), require_admin()));
$r->post('/admin/tickets/{id}/archive/retry', function(int $id) {require_admin();return archive_retry($id);});
$r->post('/admin/tickets/{id}/borrow-approval', fn(int $id) => borrow_decide($id,request_body(),require_admin()));
$r->post('/admin/documents', fn() => document_save(null,request_body(),require_admin()));
$r->get('/admin/documents/{id}', function (int $id) { require_admin(); return document_detail($id); });
$r->put('/admin/documents/{id}', fn(int $id) => document_save($id,request_body(),require_admin()));
$r->post('/admin/documents/{id}/submit', fn(int $id) => document_submit($id,request_body(),require_admin()));
$r->post('/admin/documents/{id}/sign', fn(int $id) => document_decide(request_body(),require_admin(),$id));
$r->post('/admin/documents/{id}/resend', function (int $id) { require_admin(); document_send_pending($id,true); return document_detail($id); });
$r->post('/admin/documents/{id}/copy', fn(int $id) => document_copy($id,require_admin()));
$r->post('/admin/documents/{id}/cancel', fn(int $id) => document_cancel($id,require_admin()));
$r->delete('/admin/documents/{id}', fn(int $id) => document_delete($id,request_body(),require_admin()));
$r->post('/admin/documents/{id}/restore', fn(int $id) => document_restore($id,require_admin()));
$r->get('/admin/dashboard', function () {
    require_admin();
    return dashboard();
});

$r->get('/admin/tickets', function () {
    $admin = require_admin();
    return ticket_list($_GET, empty($_GET['unread']) ? null : (int)$admin['id']);
});
$r->post('/admin/tickets/{id}/read', fn(int $id) => ticket_mark_read($id, require_admin()));
$r->get('/admin/signature-notices', fn() => signature_notices((int)require_admin()['id']));
$r->post('/admin/tickets/{id}/signatures/read', fn(int $id) => signature_notices_mark_read($id, require_admin()));
$r->post('/admin/tickets/{id}/restore', fn(int $id) => ticket_restore($id, require_admin()));
$r->post('/admin/tickets/{id}/request-edit', fn(int $id) => request_edit_admin($id,request_body(),require_admin()));
$r->post('/admin/tickets/{id}/return-to-requester', fn(int $id) => request_edit_return($id,request_body(),require_admin()));
$r->post('/admin/tickets/{id}/approval/resend', function (int $id) {
    require_admin();
    $in = new Input(request_body());
    $employeeId = $in->int('approver_id', 1);
    $in->check();
    approval_send($id, true, $employeeId);
    return ticket_admin($id);
});
$r->post('/admin/tickets/{id}/completion-approval', fn(int $id) => completion_request($id,request_body(),require_admin()));
$r->post('/admin/tickets/{id}/it-signature', fn(int $id) => it_ticket_sign($id,require_admin()));
$r->post('/admin/tickets/{id}/receipt-signature', fn(int $id) => receipt_ticket_sign($id,request_body(),require_admin()));
$r->post('/admin/tickets/{id}/purchase-signature', fn(int $id) => purchase_signature_request($id,request_body(),require_admin()));
$r->get('/admin/employees', function () { require_admin(); return employee_list(); });
$r->post('/admin/employees/sync', function () { require_admin(); return employee_sync(); });
$r->post('/admin/employees', function () { require_admin(); return employee_save(null, request_body()); });
$r->put('/admin/employees/{id}', function (int $id) { require_admin(); return employee_save($id, request_body()); });
$r->get('/admin/tickets/{id}', function (int $id) {
    require_admin();
    return ticket_admin($id);
});
$r->patch('/admin/tickets/{id}', fn(int $id) => update_ticket($id, request_body(), require_admin()));
$r->delete('/admin/tickets/{id}', fn(int $id) => ticket_delete($id, request_body(), require_admin()));
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
$r->get('/admin/assets/check-code', function () { require_admin(); return asset_check_code($_GET); });
$r->get('/admin/assets/import/template', function () { require_admin(); asset_import_template(); });
$r->post('/admin/assets/import/preview', function () { require_admin(); return asset_import_upload($_FILES['file'] ?? []); });
$r->post('/admin/assets/import/apply', function () { require_admin(); return asset_import_upload($_FILES['file'] ?? [], true); });
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
$r->put('/admin/admins/{id}/signature', function (int $id) {
    require_admin();
    return admin_signature_save($id,request_body());
});
$r->delete('/admin/admins/{id}/signature', function (int $id) {
    require_admin();
    return admin_signature_delete($id);
});

$r->dispatch($method, $path);
