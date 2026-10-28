<?php
declare(strict_types=1);

const TICKET_STATUSES = [
    'new' => 'รอรับเรื่อง',
    'accepted' => 'รับเรื่องแล้ว',
    'in_progress' => 'กำลังดำเนินการ',
    'waiting' => 'รออะไหล่/รอดำเนินการ',
    'done' => 'เสร็จสิ้น',
    'rejected' => 'ไม่อนุมัติ',
    'cancelled' => 'ยกเลิก',
];
const TICKET_OPEN_STATUSES = ['new', 'accepted', 'in_progress', 'waiting'];
const TICKET_CLOSED_STATUSES = ['done', 'rejected', 'cancelled'];

const TICKET_PRIORITIES = ['low' => 'ต่ำ', 'normal' => 'ปกติ', 'high' => 'สูง', 'urgent' => 'ด่วนมาก'];

const ASSET_STATUSES = ['active' => 'ใช้งาน', 'repair' => 'ส่งซ่อม', 'spare' => 'สำรอง', 'retired' => 'จำหน่าย/เลิกใช้'];

const SERVICE_ACTIONS = [
    'replace_device' => 'เปลี่ยนอุปกรณ์',
    'install' => 'Format / ติดตั้ง OS / ติดตั้งหรืออัปเดตโปรแกรม',
    'remote_vpn' => 'ขอรีโมท / VPN',
    'access' => 'ขอเพิ่มสิทธิ์ / เปลี่ยนสิทธิ์การเข้าระบบ',
    'other' => 'อื่นๆ',
];
const DEVICE_KINDS = ['computer' => 'Computer', 'notebook' => 'Notebook', 'other' => 'อื่นๆ'];
const EMAIL_COMPANIES = [
    'ats' => 'บริษัท อัตถสาร จำกัด',
    'ats_information' => 'บริษัท อัตถสาร จำกัด (INFORMATION)',
    'ats_innovation' => 'บริษัท อัตถสาร จำกัด (INNOVATION)',
];
const PURCHASE_ITEMS = [
    'desktop' => 'คอมพิวเตอร์ตั้งโต๊ะ (Desktop PC)',
    'notebook' => 'คอมพิวเตอร์พกพา (Notebook)',
    'monitor' => 'จอภาพ (Monitor)',
    'printer' => 'เครื่องพิมพ์ / สแกนเนอร์',
    'ups' => 'เครื่องสำรองไฟ (UPS)',
    'network' => 'อุปกรณ์เครือข่าย',
    'software' => 'ซอฟต์แวร์ / ลิขสิทธิ์',
    'accessories' => 'อุปกรณ์ต่อพ่วงอื่นๆ',
];

/**
 * ประเภทคำขอ — ตรงกับแบบฟอร์ม FM-IT
 * parse(): อ่านฟิลด์เฉพาะประเภทจาก Input คืนค่า [details, title อัตโนมัติ]
 */
function ticket_types(): array
{
    return [
        'repair' => [
            'label' => 'แจ้งซ่อม',
            'form' => 'FM-IT-003',
            'prefix' => 'RP',
            'parse' => function (Input $in, ?array $asset): array {
                $d = [
                    'job_type' => $in->enum('job_type', ['repair', 'general'], false, 'ประเภทงาน', 'repair'),
                    'equipment' => $in->str('equipment', 150, $asset === null, 'อุปกรณ์ที่เสีย'),
                    'found_at' => $in->datetime('found_at', false, 'วันเวลาที่พบปัญหา'),
                ];
                $what = $asset ? $asset['asset_code'] . ' ' . ($asset['brand_model'] ?? '') : (string)$d['equipment'];
                return [$d, 'แจ้งซ่อม: ' . trim($what)];
            },
        ],
        'service' => [
            'label' => 'ร้องขอดำเนินการด้าน IT',
            'form' => 'FM-IT-010',
            'prefix' => 'SV',
            'parse' => function (Input $in): array {
                $d = [
                    'position' => $in->str('position', 100, false, 'ตำแหน่ง'),
                    'device_kind' => $in->enum('device_kind', array_keys(DEVICE_KINDS), false, 'อุปกรณ์'),
                    'actions' => $in->set('actions', array_keys(SERVICE_ACTIONS), true, 'สิ่งที่ต้องการให้ดำเนินการ'),
                    'os_target' => $in->str('os_target', 100),
                    'other_text' => $in->str('other_text', 255),
                    'approver_name' => $in->str('approver_name', 150),
                ];
                $labels = array_map(fn($a) => SERVICE_ACTIONS[$a], $d['actions']);
                return [$d, 'ร้องขอ IT: ' . mb_substr(implode(', ', $labels), 0, 150)];
            },
        ],
        'email' => [
            'label' => 'ขอใช้ E-mail บริษัท',
            'form' => 'FM-IT-009',
            'prefix' => 'EM',
            'parse' => function (Input $in): array {
                $d = [
                    'name_th' => $in->str('name_th', 150, true, 'ชื่อ-นามสกุล (ไทย)'),
                    'name_en' => $in->str('name_en', 150, true, 'ชื่อ-นามสกุล (อังกฤษ)'),
                    'position' => $in->str('position', 100, true, 'ตำแหน่ง'),
                    'company' => $in->enum('company', array_keys(EMAIL_COMPANIES), true, 'บริษัท'),
                    'desired_email' => $in->str('desired_email', 100, false, 'ชื่อ E-mail ที่ต้องการ'),
                    'approver_name' => $in->str('approver_name', 150),
                ];
                return [$d, 'ขอ E-mail: ' . $d['name_en']];
            },
        ],
        'borrow' => [
            'label' => 'เบิก-ยืมอุปกรณ์',
            'form' => 'FM-IT-008',
            'prefix' => 'BR',
            'parse' => function (Input $in): array {
                $d = [
                    'device_kind' => $in->enum('device_kind', ['notebook', 'other'], true, 'ประเภทอุปกรณ์'),
                    'item' => $in->str('item', 150, true, 'อุปกรณ์ที่ต้องการ'),
                    'quantity' => $in->int('quantity', 1, 100, true, 'จำนวน'),
                    'start_date' => $in->date('start_date', true, 'วันที่เริ่มใช้'),
                    'end_date' => $in->date('end_date', false, 'วันที่คืน'),
                ];
                if ($d['start_date'] && $d['end_date'] && $d['end_date'] < $d['start_date']) {
                    $in->error('end_date', 'วันที่คืนต้องไม่ก่อนวันที่เริ่มใช้');
                }
                return [$d, 'เบิก-ยืม: ' . $d['item'] . ' x' . $d['quantity']];
            },
        ],
        'purchase' => [
            'label' => 'ขอซื้อครุภัณฑ์คอมพิวเตอร์',
            'form' => 'FM-IT-007',
            'prefix' => 'PR',
            'parse' => function (Input $in): array {
                $d = [
                    'position' => $in->str('position', 100, false, 'ตำแหน่ง'),
                    'urgent' => $in->bool('urgent'),
                    'need_by' => $in->date('need_by', false, 'ต้องการภายในวันที่'),
                    'items' => $in->set('items', array_keys(PURCHASE_ITEMS), true, 'ประเภทอุปกรณ์'),
                    'spec_cpu' => $in->str('spec_cpu', 100),
                    'spec_ram' => $in->str('spec_ram', 50),
                    'spec_storage' => $in->str('spec_storage', 50),
                    'spec_display' => $in->str('spec_display', 50),
                    'spec_other' => $in->str('spec_other', 255),
                ];
                $labels = array_map(fn($i) => explode(' (', PURCHASE_ITEMS[$i])[0], $d['items']);
                return [$d, 'ขอซื้อ: ' . mb_substr(implode(', ', $labels), 0, 150)];
            },
        ],
    ];
}

/** ข้อมูลตัวเลือกทั้งหมดสำหรับหน้าเว็บ */
function meta_payload(): array
{
    $types = [];
    foreach (ticket_types() as $key => $t) {
        $types[$key] = ['label' => $t['label'], 'form' => $t['form']];
    }
    return [
        'app_url' => cfg('app_url'),
        'ticket_types' => $types,
        'ticket_statuses' => TICKET_STATUSES,
        'ticket_priorities' => TICKET_PRIORITIES,
        'asset_statuses' => ASSET_STATUSES,
        'service_actions' => SERVICE_ACTIONS,
        'device_kinds' => DEVICE_KINDS,
        'email_companies' => EMAIL_COMPANIES,
        'purchase_items' => PURCHASE_ITEMS,
        'categories' => db()->query('SELECT id, name, icon FROM asset_categories ORDER BY sort_order, name')->fetchAll(),
    ];
}
