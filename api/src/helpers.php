<?php
declare(strict_types=1);

final class HttpError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}

function fail(string $message, int $status = 400, array $errors = []): never
{
    throw new HttpError($message, $status, $errors);
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** อ่าน body: JSON ปกติ หรือ multipart ที่ส่ง JSON มาในฟิลด์ payload */
function request_body(): array
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    if (isset($_POST['payload'])) {
        $body = json_decode((string)$_POST['payload'], true);
    } else {
        $raw = file_get_contents('php://input');
        $body = $raw === '' ? [] : json_decode($raw, true);
    }
    if (!is_array($body)) {
        fail('รูปแบบข้อมูลไม่ถูกต้อง');
    }
    return $body;
}

function client_ip(): string
{
    // Apache reverse proxy ของ ERP ไม่ได้อยู่หน้า IT Hub จึงใช้ REMOTE_ADDR ตรงๆ
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/** อักษรย่อปี พ.ศ. 2 หลัก + เดือน เช่น 6910 */
function thai_year_month(): string
{
    return sprintf('%02d%s', ((int)date('Y') + 543) % 100, date('m'));
}

function paginate(string $sql, array $params, int $page, int $perPage): array
{
    $perPage = max(1, min(100, $perPage));
    $page = max(1, $page);

    $count = db()->prepare("SELECT COUNT(*) FROM ($sql) AS t");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $stmt = db()->prepare($sql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
    $stmt->execute($params);

    return ['data' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}

/** เก็บรูปที่อัปโหลด คืนค่า path ภายใต้ upload_dir */
function store_upload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        fail('อัปโหลดรูปไม่สำเร็จ');
    }
    if ($file['size'] > cfg('upload_max_mb', 8) * 1024 * 1024) {
        fail('รูปมีขนาดใหญ่เกิน ' . cfg('upload_max_mb', 8) . ' MB');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if ($ext === null || @getimagesize($file['tmp_name']) === false) {
        fail('รองรับเฉพาะรูป JPG, PNG, WEBP');
    }

    $relative = date('Y/m/') . bin2hex(random_bytes(16)) . '.' . $ext;
    $target = cfg('upload_dir') . '/' . $relative;
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true)) {
        fail('ไม่สามารถบันทึกรูปได้', 500);
    }
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        fail('ไม่สามารถบันทึกรูปได้', 500);
    }
    return $relative;
}

function send_upload(string $relative): never
{
    $base = realpath(cfg('upload_dir'));
    $path = realpath(cfg('upload_dir') . '/' . $relative);
    if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
        fail('ไม่พบไฟล์', 404);
    }
    header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($path));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
