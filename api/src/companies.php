<?php
declare(strict_types=1);

/*
 * บริษัทให้ผู้ใช้เลือกก่อนเข้า IT Hub — อ่านจาก E-Procurement (purchase_companies) แบบอ่านอย่างเดียว
 * ใช้การเชื่อมต่อ employee_source เดิม ไม่เขียนกลับต้นทาง
 *
 * ตารางต้นทางมีบริษัททดสอบหลงอยู่ (ไม่มีโลโก้) จึงแสดงเฉพาะบริษัทที่เปิดใช้งานและมีโลโก้
 * หรือกำหนดรายการรหัสเองใน config: 'public_company_codes' => ['ATS', 'ATSINNO']
 */

const COMPANY_LOGO_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

function company_source(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c = cfg('employee_source');
    if (!$c || empty($c['name'])) fail('ยังไม่ได้ตั้งค่าการเชื่อมต่อระบบจัดซื้อ', 503);
    try {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']), $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 3,
        ]);
    } catch (Throwable $e) {
        error_log('[it-hub] company source: ' . $e->getMessage());
        fail('เชื่อมต่อระบบจัดซื้อไม่สำเร็จ กรุณาลองใหม่', 503);
    }
    return $pdo;
}

/** @return array<int, array{id:int, code:string, name:string, name_en:?string, logo_version:?string}> */
function public_companies(?PDO $source = null): array
{
    $source ??= company_source();
    $codes = cfg('public_company_codes');
    $where = 'is_active = 1 AND deleted_at IS NULL';
    $params = [];
    if (is_array($codes) && $codes) {
        $where .= ' AND code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
        $params = array_values(array_map('strval', $codes));
    } else {
        $where .= ' AND logo_data IS NOT NULL AND LENGTH(logo_data) > 0';
    }
    $s = $source->prepare("SELECT id, code, name, name_en, logo_mime, IF(logo_data IS NULL, NULL, SHA1(logo_data)) AS logo_hash
        FROM purchase_companies WHERE $where ORDER BY id");
    $s->execute($params);
    return array_map(fn(array $r) => [
        'id' => (int)$r['id'],
        'code' => (string)$r['code'],
        'name' => (string)$r['name'],
        'name_en' => ($r['name_en'] ?? '') !== '' ? (string)$r['name_en'] : null,
        // frontend ประกอบ URL เอง: <api>/companies/{id}/logo?v=<logo_version>
        'logo_version' => $r['logo_hash'] && in_array($r['logo_mime'], COMPANY_LOGO_MIMES, true) ? substr((string)$r['logo_hash'], 0, 12) : null,
    ], $s->fetchAll());
}

function public_company(int $id, ?PDO $source = null): ?array
{
    foreach (public_companies($source) as $c) if ($c['id'] === $id) return $c;
    return null;
}

/** ส่งโลโก้เฉพาะบริษัทที่อยู่ในรายการให้เลือก (ไม่เปิดโลโก้ของบริษัทอื่นในต้นทาง) */
function company_logo_send(int $id): never
{
    if (!public_company($id)) fail('ไม่พบบริษัท', 404);
    $s = company_source()->prepare('SELECT logo_data, logo_mime FROM purchase_companies WHERE id = ?');
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row || !$row['logo_data'] || !in_array($row['logo_mime'], COMPANY_LOGO_MIMES, true)) fail('ไม่มีโลโก้', 404);
    header('Content-Type: ' . $row['logo_mime']);
    header('Content-Length: ' . strlen($row['logo_data']));
    header('Cache-Control: public, max-age=86400');
    header("Content-Security-Policy: default-src 'none'");
    echo $row['logo_data'];
    exit;
}

/**
 * บริษัทที่ผู้แจ้งเลือกไว้ (ไม่บังคับ — แท็บเก่าไม่ส่งมา) เก็บชื่อ/รหัสเป็น snapshot ในรายละเอียดคำขอ
 * ถ้าระบบจัดซื้อเชื่อมต่อไม่ได้ขณะส่งคำขอ จะไม่ขวางการแจ้งงาน
 */
function requester_company(Input $in): ?array
{
    $raw = $in->value('company_id');
    if ($raw === null || $raw === '') return null;
    if (!is_numeric($raw) || (int)$raw <= 0 || (string)(int)$raw !== trim((string)$raw)) {
        $in->error('company_id', 'บริษัทไม่ถูกต้อง');
        return null;
    }
    try {
        $company = public_company((int)$raw);
    } catch (Throwable $e) {
        error_log('[it-hub] requester company lookup: ' . $e->getMessage());
        return null;
    }
    if (!$company) {
        $in->error('company_id', 'ไม่พบบริษัทที่เลือก กรุณาเลือกบริษัทใหม่');
        return null;
    }
    return ['id' => $company['id'], 'code' => $company['code'], 'name' => $company['name']];
}

/** สำหรับเครื่องสร้าง PDF ออฟไลน์ (archive): รายการบริษัท + โลโก้ base64 ถ้าต้นทางอ่านไม่ได้คืนค่าว่าง ฉบับพิมพ์จะใช้โลโก้สำรอง */
function company_archive_bundle(): array
{
    try {
        $list = public_companies();
        $logos = [];
        $s = company_source()->prepare('SELECT logo_data, logo_mime FROM purchase_companies WHERE id = ?');
        foreach ($list as $c) {
            if (!$c['logo_version']) continue;
            $s->execute([$c['id']]);
            $row = $s->fetch();
            if ($row && $row['logo_data']) $logos[(string)$c['id']] = ['mime' => $row['logo_mime'], 'data' => base64_encode($row['logo_data'])];
        }
        return ['companies' => $list, 'logos' => (object)$logos];
    } catch (Throwable $e) {
        error_log('[it-hub] archive company bundle: ' . $e->getMessage());
        return ['companies' => null, 'logos' => (object)[]];
    }
}
