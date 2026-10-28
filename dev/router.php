<?php
// สำหรับทดสอบบนเครื่อง: php -S 127.0.0.1:8099 dev/router.php
// /it-hub/api/* → PHP API, ที่เหลือ → ไฟล์จาก web/dist (SPA fallback ไป index.html)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/it-hub/api/')) {
    require __DIR__ . '/../api/public/index.php';
    return true;
}
$dist = realpath(__DIR__ . '/../web/dist');
$rel = substr($path, strlen('/it-hub'));
$file = $dist !== false ? realpath($dist . $rel) : false;
if ($file && is_file($file) && str_starts_with($file, $dist)) {
    $types = ['js' => 'text/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'png' => 'image/png', 'ico' => 'image/x-icon', 'webmanifest' => 'application/manifest+json'];
    header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? mime_content_type($file)));
    readfile($file);
    return true;
}
if ($dist !== false && is_file($dist . '/index.html')) {
    readfile($dist . '/index.html');
    return true;
}
http_response_code(404);
echo 'web/dist not built';
