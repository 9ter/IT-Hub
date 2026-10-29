<?php
declare(strict_types=1);

/** Shared validation for both requester and approver drawings. */
function validate_signature(Input $in, string $key = 'signature_data', string $label = 'ลายเซ็น'): ?string
{
    $signature = $in->str($key, 180000, true, $label);
    if (!$signature || !preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#D', $signature, $match)) {
        $in->error($key, 'กรุณาวาด' . $label . 'ก่อนยืนยัน');
        return null;
    }
    $bytes = base64_decode($match[1], true);
    $size = $bytes ? @getimagesizefromstring($bytes) : false;
    if (!$size || $size[2] !== IMAGETYPE_PNG || $size[0] < 100 || $size[1] < 40 || $size[0] > 1200 || $size[1] > 600) {
        $in->error($key, 'รูปแบบ' . $label . 'ไม่ถูกต้อง');
        return null;
    }
    $img = @imagecreatefromstring($bytes);
    $ink = 0;
    if ($img) {
        for ($y=0;$y<$size[1];$y++) for ($x=0;$x<$size[0];$x++) {
            $c = imagecolorsforindex($img, imagecolorat($img,$x,$y));
            if ($c['alpha'] < 100 && min($c['red'],$c['green'],$c['blue']) < 220) $ink++;
        }
        imagedestroy($img);
    }
    if ($ink < 50) $in->error($key, 'กรุณาวาด' . $label . 'ก่อนยืนยัน');
    return $signature;
}

function requester_signature_for_ticket(int $id): ?array
{
    $stmt = db()->prepare('SELECT requester_name,signature_data,signed_at FROM ticket_requester_signatures WHERE ticket_id=?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}
