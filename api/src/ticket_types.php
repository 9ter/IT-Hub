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

const PURCHASE_QUANTITY_MAX = 999;

/** Validate one quantity per selected purchase item; keys follow the selected item order. */
function purchase_item_quantities(Input $in, array $items): array
{
    $raw=$in->value('item_quantities');
    if ($raw!==null && !is_array($raw)) $raw=[];
    $quantities=[];$missing=false;
    foreach ($items as $item) {
        $value=$raw[$item]??null;
        $n=is_bool($value)||is_array($value)?false:filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>PURCHASE_QUANTITY_MAX]]);
        if ($n===false) {$in->error('item_quantities.'.$item,'กรุณาระบุจำนวนเป็นจำนวนเต็ม 1–'.PURCHASE_QUANTITY_MAX);$missing=true;continue;}
        $quantities[$item]=$n;
    }
    if ($missing) $in->error('items','กรุณาระบุจำนวนของอุปกรณ์ที่เลือกให้ครบทุกรายการ');
    return $quantities;
}

const PURCHASE_ITEM_DETAIL_MAX = 255;

/** Optional free text per selected item (model, colour, spec); blanks are not stored. */
function purchase_item_details(Input $in, array $items): array
{
    $raw=$in->value('item_details');
    if ($raw!==null && !is_array($raw)) {$in->error('item_details','รายละเอียดอุปกรณ์ไม่ถูกต้อง');return [];}
    $details=[];
    foreach ($items as $item) {
        $value=$raw[$item]??null;
        if ($value===null) continue;
        if (!is_string($value)) {$in->error('item_details.'.$item,'รายละเอียดไม่ถูกต้อง');continue;}
        $value=trim($value);
        if ($value==='') continue;
        if (mb_strlen($value)>PURCHASE_ITEM_DETAIL_MAX) {$in->error('item_details.'.$item,'รายละเอียดยาวเกิน '.PURCHASE_ITEM_DETAIL_MAX.' ตัวอักษร');continue;}
        $details[$item]=$value;
    }
    return $details;
}

const PURCHASE_ITEM_OTHER_MAX = 100;

/** "อื่นๆ" needs the requester's own equipment type; stored only while that item is selected. */
function purchase_item_other(Input $in, array $items): ?string
{
    return in_array('other',$items,true)?$in->str('item_other',PURCHASE_ITEM_OTHER_MAX,true,'ประเภทอุปกรณ์ที่ต้องการ (อื่นๆ)'):null;
}

/** Item name as shown to people: "อื่นๆ: เครื่องฉายภาพ" for the free-text item. */
function purchase_item_label(string $key, array $details): string
{
    $other=is_string($details['item_other']??null)?trim($details['item_other']):'';
    return $key==='other'&&$other!==''?'อื่นๆ: '.$other:(PURCHASE_ITEMS[$key]??$key);
}

/** "หมึก จำนวน 2 (HP 682 สีดำ), ..." — requests created before quantities existed show the item name only. */
function purchase_items_text(array $details, bool $short = false): string
{
    $quantities=is_array($details['item_quantities']??null)?$details['item_quantities']:[];
    $itemDetails=is_array($details['item_details']??null)?$details['item_details']:[];
    return implode(', ',array_map(function($key)use($quantities,$itemDetails,$short,$details){
        $label=purchase_item_label((string)$key,$details);
        if ($short && $key!=='other') $label=explode(' (',$label)[0];
        $text=$label.(isset($quantities[$key])?($short?' x':' จำนวน ').$quantities[$key]:'');
        return !$short && is_string($itemDetails[$key]??null) && $itemDetails[$key]!==''?$text.' ('.$itemDetails[$key].')':$text;
    },is_array($details['items']??null)?$details['items']:[]));
}

/** Read old single-item borrow requests without rewriting their stored data. */
function borrow_items_for_details(array $details): array
{
    return $details['borrow_items']??[['device_kind'=>$details['device_kind']??'other','item'=>$details['item']??'','quantity'=>$details['quantity']??1]];
}

const TICKET_PRIORITIES = ['low' => 'ต่ำ', 'normal' => 'ปกติ', 'high' => 'สูง', 'urgent' => 'ด่วนมาก'];

const ASSET_STATUSES = ['active' => 'ใช้งาน', 'repair' => 'ส่งซ่อม', 'spare' => 'สำรอง', 'retired' => 'จำหน่าย/เลิกใช้'];

const SERVICE_ACTIONS = [
    'replace_device' => 'เปลี่ยนอุปกรณ์',
    'install' => 'Format / ติดตั้ง OS / ติดตั้งหรืออัปเดตโปรแกรม',
    'remote_vpn' => 'ขอรีโมท / VPN',
    'access' => 'ขอเพิ่มสิทธิ์ / เปลี่ยนสิทธิ์การเข้าระบบ',
    'company_email' => 'ขอ E-mail บริษัท',
    'other' => 'อื่นๆ',
];
/** No longer offered on new requests; kept so requests that chose them still read and print correctly. */
const SERVICE_ACTIONS_RETIRED = ['company_email'];
const DEVICE_KINDS = ['computer' => 'Computer', 'notebook' => 'Notebook', 'other' => 'อื่นๆ'];
const EMAIL_COMPANIES = [
    'ats' => 'บริษัท อัตถสาร จำกัด',
    'ats_information' => 'บริษัท อัตถสาร จำกัด (INFORMATION)',
    'ats_innovation' => 'บริษัท อัตถสาร จำกัด (INNOVATION)',
];
const PURCHASE_ITEMS = [
    'desktop' => 'คอมพิวเตอร์ตั้งโต๊ะ (Desktop PC)',
    'notebook' => 'คอมพิวเตอร์พกพา (Notebook)',
    'all_in_one' => 'คอมพิวเตอร์ All-in-One',
    'monitor' => 'จอภาพ (Monitor)',
    'printer' => 'เครื่องพิมพ์ / สแกนเนอร์',
    'thermal_printer' => 'Thermal Printer',
    'barcode_scanner' => 'Barcode Scanner',
    'barcode_scanner_wired' => 'Barcode Scanner มีสาย',
    'barcode_scanner_wireless' => 'Barcode Scanner ไร้สาย',
    'ink' => 'หมึก',
    'ups' => 'เครื่องสำรองไฟ (UPS)',
    'network' => 'อุปกรณ์เครือข่าย',
    'memory_device' => 'อุปกรณ์หน่วยความจำ',
    'desk_phone' => 'โทรศัพท์ตั้งโต๊ะ',
    'software' => 'ซอฟต์แวร์ / ลิขสิทธิ์',
    'accessories' => 'อุปกรณ์ต่อพ่วงอื่นๆ',
    'other' => 'อื่นๆ',
];
/** Kept in PURCHASE_ITEMS so existing requests stay readable and editable; hidden from the picker unless already selected. */
const PURCHASE_ITEMS_RETIRED = ['barcode_scanner'];

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
                $items=[];
                if ($in->has('borrow_items')) {
                    foreach($in->rows('borrow_items',50,'รายการอุปกรณ์') as $i=>$row) {
                        if (!is_array($row)) {$in->error('borrow_items.'.$i,'ข้อมูลรายการไม่ถูกต้อง');continue;}
                        $entry=new Input($row);
                        $item=['device_kind'=>$entry->enum('device_kind',['notebook','other'],true,'ประเภทอุปกรณ์'),
                            'item'=>$entry->str('item',150,true,'อุปกรณ์ที่ต้องการ'),'quantity'=>$entry->int('quantity',1,100,true,'จำนวน')];
                        try {$entry->check();$items[]=$item;} catch(HttpError $e) {foreach($e->errors as $key=>$message)$in->error('borrow_items.'.$i.'.'.$key,$message);}
                    }
                } else {
                    $items[]=['device_kind'=>$in->enum('device_kind',['notebook','other'],true,'ประเภทอุปกรณ์'),
                        'item'=>$in->str('item',150,true,'อุปกรณ์ที่ต้องการ'),'quantity'=>$in->int('quantity',1,100,true,'จำนวน')];
                }
                $first=$items[0]??['device_kind'=>null,'item'=>null,'quantity'=>null];
                $d = [
                    'borrow_items'=>$items,
                    'device_kind'=>count(array_unique(array_column($items,'device_kind')))>1?'mixed':$first['device_kind'],
                    'item'=>$first['item'],
                    'quantity'=>$first['quantity'],
                    'start_date' => $in->date('start_date', true, 'วันที่เริ่มใช้'),
                    'end_date' => $in->date('end_date', false, 'วันที่คืน'),
                ];
                if ($d['start_date'] && $d['end_date'] && $d['end_date'] < $d['start_date']) {
                    $in->error('end_date', 'วันที่คืนต้องไม่ก่อนวันที่เริ่มใช้');
                }
                foreach (['start_date','end_date'] as $key) if ($d[$key] && date('Y-m-d',strtotime($d[$key]))!==$d[$key]) $in->error($key,'วันที่ไม่ถูกต้อง');
                return [$d, 'เบิก-ยืม: ' . $d['item'] . ' x' . $d['quantity'].(count($items)>1?' และอีก '.(count($items)-1).' รายการ':'')];
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
                    'item_quantities' => [],
                    'item_details' => [],
                    'spec_cpu' => $in->str('spec_cpu', 100),
                    'spec_ram' => $in->str('spec_ram', 50),
                    'spec_storage' => $in->str('spec_storage', 50),
                    'spec_display' => $in->str('spec_display', 50),
                    'spec_other' => $in->str('spec_other', 255),
                ];
                $d['item_quantities'] = purchase_item_quantities($in, $d['items']);
                $d['item_details'] = purchase_item_details($in, $d['items']);
                $other = purchase_item_other($in, $d['items']);
                if ($other !== null) $d['item_other'] = $other;
                return [$d, 'ขอซื้อ: ' . mb_substr(purchase_items_text($d, true), 0, 150)];
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
        'service_actions_retired' => SERVICE_ACTIONS_RETIRED,
        'device_kinds' => DEVICE_KINDS,
        'email_companies' => EMAIL_COMPANIES,
        'purchase_items' => PURCHASE_ITEMS,
        'purchase_items_retired' => PURCHASE_ITEMS_RETIRED,
        'categories' => db()->query('SELECT id, name, icon FROM asset_categories ORDER BY sort_order, name')->fetchAll(),
    ];
}
