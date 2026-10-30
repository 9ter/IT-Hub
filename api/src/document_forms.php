<?php
declare(strict_types=1);

/** A single catalog drives input validation, editing, approval roles and printed columns. */
function document_forms(): array
{
    $f = fn(string $key, string $label, string $type='text', bool $required=false, string $phase='request', array $options=[]) =>
        compact('key','label','type','required','phase','options');
    $role = fn(string $key,string $label,string $phase='completion',string $kind='employee',bool $authority=true) =>
        compact('key','label','phase','kind','authority');
    $months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $basic = [$f('requester','ผู้ร้องขอ','text',true),$f('position','ตำแหน่ง'),$f('department','ฝ่าย / แผนก','text',true)];
    $requestRoles = [$role('requester','ผู้ร้องขอ','request','employee',false),$role('head','หัวหน้าฝ่าย / ผู้อนุมัติ','request')];
    $it = $role('it','เจ้าหน้าที่เทคโนโลยีสารสนเทศ','completion','admin');
    $director = $role('director','ผอ./ผจก. ฝ่ายสนับสนุนและติดตั้ง');
    $md = $role('md','ผู้ช่วยกรรมการผู้จัดการ');
    $def = fn(string $code,string $title,string $rev,string $orientation,array $fields,array $columns,array $roles=[],string $tablePhase='request') =>
        compact('code','title','rev','orientation','fields','columns','roles','tablePhase');
    $forms = [
        'FM-IT-001'=>$def('FM-IT-001','ใบทะเบียนคอมพิวเตอร์ และสารสนเทศ','Rev.01, 04/11/2569','landscape',[
            $f('department','หน่วยงาน'),$f('notes','หมายเหตุ','textarea'),
        ],[$f('asset_code','หมายเลข','text',true),$f('holder','ผู้ครอบครอง'),$f('model','ยี่ห้อ / รุ่น'),$f('serial','Serial No.'),$f('department','หน่วยงานที่ใช้'),$f('registered','วันที่ขึ้นทะเบียน'),$f('notes','หมายเหตุ'),$f('type','Type')],[
            $role('it','เจ้าหน้าที่ฝ่าย IT','completion','admin'),$role('director','ผู้อำนวยการฝ่าย'),
        ]),
        'FM-IT-002'=>$def('FM-IT-002','ใบตรวจสอบโครงสร้างพื้นฐาน','Rev.01, 04/11/2569','landscape',[
            $f('year','ประจำปี พ.ศ.','number',true),$f('inspected_on','วันที่ตรวจ','date'),$f('inspection_metadata','วันที่ / ผู้ตรวจ / ผู้ทบทวนรายเดือนจากต้นทาง','textarea'),$f('notes','หมายเหตุ','textarea'),
        ],[$f('item','รายการตรวจสอบ','text',true),$f('asset_code','หมายเลขเครื่อง'),
            ...array_map(fn($m,$i)=>$f('m'.($i+1),$m,'select',false,'request',['normal'=>'/ ปกติ','abnormal'=>'X ผิดปกติ','fixed'=>'✓ แก้ไขแล้ว']),$months,array_keys($months)),
            $f('notes','หมายเหตุ')],[$role('it','ผู้ตรวจสอบ','completion','admin'),$role('reviewer','ผู้ทบทวน')]),
        'FM-IT-003'=>$def('FM-IT-003','ใบแจ้งซ่อมเครื่องคอมพิวเตอร์','Rev.03, 04/11/2569','portrait',[
            ...$basic,$f('job_type','ประเภทงาน','select',true,'request',['repair'=>'งานซ่อม','general'=>'งานทั่วไป']),
            $f('equipment','อุปกรณ์ที่เสีย','text',true),$f('asset_code','รหัสอุปกรณ์'),$f('location','สถานที่ตั้ง'),$f('found_at','วันเวลาที่พบปัญหา','datetime-local'),
            $f('description','รายละเอียดปัญหา / อาการเบื้องต้น','textarea',true),
            $f('fix_method','วิธีแก้ไข','select',true,'completion',['internal'=>'ดำเนินการภายใน (ฝ่าย IT)','outsource'=>'ส่งซ่อมหน่วยงานภายนอก']),
            $f('vendor','หน่วยงานภายนอก','text',false,'completion'),$f('result','รายละเอียดการแก้ปัญหา','textarea',true,'completion'),
            $f('started_at','เริ่มดำเนินการ','datetime-local',true,'completion'),$f('finished_at','แล้วเสร็จ','datetime-local',true,'completion'),
            $f('acceptance','ตรวจรับและส่งมอบงาน','select',true,'completion',['normal'=>'เครื่องสามารถใช้งานได้ปกติ','abnormal'=>'ยังไม่สามารถใช้งานได้']),
            $f('acceptance_note','หมายเหตุการตรวจรับ','textarea',false,'completion'),
        ],[],[...$requestRoles,$role('receiver','เจ้าหน้าที่ผู้รับแจ้ง','request','admin'),$it,$role('acceptor','ผู้แจ้งซ่อม / ผู้ตรวจรับงาน','completion','employee',false),$director,$md]),
        'FM-IT-004'=>$def('FM-IT-004','ใบประวัติโครงสร้างพื้นฐาน','Rev.01, 04/11/2569','landscape',[
            $f('equipment','ชื่ออุปกรณ์','text',true),$f('asset_code','หมายเลข','text',true),$f('type','ประเภท'),$f('model','ยี่ห้อ / รุ่น'),$f('department','ประจำหน่วยงาน'),$f('registered','วันที่รับเข้า'),
        ],[$f('date','วันที่','text',true),$f('detail','รายละเอียดการบำรุงรักษา','textarea',true),$f('kind','ประเภทการซ่อม','select',true,'request',['BM'=>'ซ่อมเมื่อเสีย (BM)','PM'=>'ซ่อมเพื่อป้องกัน (PM)']),$f('duration','ระยะเวลาในการซ่อม'),$f('responsible','ผู้รับผิดชอบ'),$f('notes','หมายเหตุ')]),
        'FM-IT-005'=>$def('FM-IT-005','รายงานการ BACK UP ข้อมูล SERVER','Rev.01, 04/11/2569','landscape',[
            $f('year','ประจำปี พ.ศ.','number',true),$f('server','ชื่อ Server','text',true),
        ],[$f('date','ว/ด/ป','text',true),$f('dataset','ข้อมูลที่ BACK UP','text',true),$f('size','ขนาดไฟล์จริง'),$f('source','แหล่งจัดเก็บข้อมูลหลัก'),$f('device','อุปกรณ์สำรอง'),$f('folder','Folder / File'),$f('backup_size','ขนาดไฟล์ BACK UP'),$f('started','เวลาเริ่ม'),$f('finished','เวลาเสร็จ'),$f('maker','จัดทำ'),$f('acknowledger','รับทราบ'),$f('notes','หมายเหตุ')],[
            $role('it','ผู้จัดทำ','completion','admin'),$role('reviewer','ผู้รับทราบ'),
        ]),
        'FM-IT-006'=>$def('FM-IT-006','แผนงานบำรุงรักษาเครื่องคอมพิวเตอร์ และการสำรองข้อมูล','Rev.01, 04/11/2569','landscape',[
            $f('year','ประจำปี พ.ศ.','number',true),$f('team','ทีมดำเนินงาน','text',true),$f('updated','วันที่ปรับปรุง','date'),$f('notes','หมายเหตุ','textarea'),
        ],[$f('task','หัวข้อการดำเนินงาน','textarea',true),$f('frequency','ความถี่'),$f('responsible','ผู้รับผิดชอบ'),
            ...array_map(fn($m,$i)=>$f('m'.($i+1),$m.' (P/A สัปดาห์ 1–4)'),$months,array_keys($months))
        ],[$role('it','ผู้จัดทำ','completion','admin'),$role('reviewer','ผู้ทบทวน'),$role('approver','ผู้อนุมัติ')]),
        'FM-IT-007'=>$def('FM-IT-007','ใบขอซื้อครุภัณฑ์คอมพิวเตอร์','Rev.03, 04/11/2569','portrait',[
            ...$basic,$f('urgency','ความต้องการ','select',true,'request',['normal'=>'ไม่ด่วน','urgent'=>'ด่วน']),$f('need_by','ต้องการภายในวันที่','date'),
            $f('purpose','วัตถุประสงค์ในการใช้งาน','textarea',true),$f('cpu','CPU'),$f('ram','RAM (GB)'),$f('storage','SSD / HDD (GB / TB)'),$f('display','Display (นิ้ว)'),$f('other','รายละเอียดอื่นๆ','textarea'),
            $f('vendor','ซื้อจากบริษัท / ร้าน','text',true,'completion'),$f('vendor_phone','โทรศัพท์','text',false,'completion'),
            $f('quotation','ใบเสนอราคาเลขที่','text',true,'completion'),$f('quotation_date','ลงวันที่','date',true,'completion'),
            $f('amount','รวมเป็นเงิน (บาท)','decimal',true,'completion'),$f('vat','ภาษี','select',true,'completion',['excluded'=>'ราคาไม่รวม VAT','included'=>'ราคารวม VAT']),
            $f('result','บันทึกสั่งการ / ผลการดำเนินการ','textarea',true,'completion'),
        ],[$f('item','ประเภทอุปกรณ์','select',true,'request',PURCHASE_ITEMS),$f('spec','รายละเอียดสเปก','textarea'),$f('quantity','จำนวน','number',true)],[
            ...$requestRoles,$role('receiver','ผู้รับเรื่อง (ฝ่ายเทคโนโลยีสารสนเทศ)','request','admin'),$director,$role('purchasing','ผู้ตรวจสอบ (ผจก.ฝ่ายจัดซื้อ)'),$md,
        ]),
        'FM-IT-008'=>$def('FM-IT-008','แบบฟอร์มใบเบิก-คืนอุปกรณ์ IT','Rev.01, 04/11/2569','landscape',[
            $f('device_kind','ประเภท','select',true,'request',['notebook'=>'Notebook','other'=>'อื่นๆ','mixed'=>'Notebook และอุปกรณ์อื่นๆ']),$f('start_date','วันที่เริ่มใช้','date',true),$f('end_date','กำหนดคืน','date'),$f('notes','หมายเหตุ','textarea'),
        ],[$f('department','ฝ่าย / แผนก','text',true),$f('user','ผู้ขอใช้','text',true),$f('purpose','วัตถุประสงค์ใช้งาน','textarea',true),$f('device_kind','ประเภทอุปกรณ์','select',false,'request',['notebook'=>'Notebook','other'=>'อื่นๆ']),$f('item','อุปกรณ์','text',true),$f('quantity','จำนวน','number',true),$f('serial','Serial No.'),$f('date','วันที่ใช้งาน','date'),
            $f('issuer','เจ้าหน้าที่ IT ผู้จ่าย','text',true),$f('head','ต้นสังกัด'),$f('returned_at','วันที่คืนจริง','date'),$f('returner','ผู้คืน'),$f('receiver','เจ้าหน้าที่ IT ผู้รับคืน'),$f('return_condition','สภาพอุปกรณ์เมื่อคืน','textarea'),
        ]),
        'FM-IT-009'=>$def('FM-IT-009','แบบฟอร์มขอใช้ E-mail บริษัท','Rev.02, 04/11/2569','portrait',[
            $f('name_th','ชื่อ-นามสกุล (ภาษาไทย)','text',true),$f('name_en','ชื่อ-นามสกุล (ภาษาอังกฤษ)','text',true),$f('position','ตำแหน่ง','text',true),$f('department','ฝ่าย / แผนก','text',true),
            $f('email_company','บริษัท','select',true,'request',EMAIL_COMPANIES),$f('desired_email','ชื่อ E-mail ที่ต้องการ'),$f('purpose','วัตถุประสงค์ในการใช้งาน','textarea',true),
            $f('assigned_email','E-mail ที่ได้รับ','email',true,'completion'),$f('result','ผลการดำเนินการ','textarea',true,'completion'),
        ],[],[$role('requester','ผู้ขอใช้','request','employee',false),$role('head','หัวหน้าแผนก','request'),$role('manager','ผู้จัดการฝ่าย','request'),$it,$director]),
        'FM-IT-010'=>$def('FM-IT-010','แบบฟอร์มร้องขอดำเนินการด้าน IT (IT Request Form)','Rev.02, 04/11/2569','portrait',[
            ...$basic,$f('device_kind','อุปกรณ์','select',false,'request',DEVICE_KINDS),$f('actions','ต้องการให้ดำเนินการ','multiselect',true,'request',SERVICE_ACTIONS),$f('os','OS / โปรแกรมที่ต้องการ'),$f('other','อื่นๆ'),
            $f('description','รายละเอียดการร้องขอ','textarea',true),$f('result','ผลการดำเนินการ','textarea',true,'completion'),
        ],[],[...$requestRoles,$it,$director,$md]),
        'FM-IT-011'=>$def('FM-IT-011','แบบฟอร์มสำหรับผู้มาติดต่อ IT','Rev.02, 04/11/2569','portrait',[
            $f('visitor','ชื่อ-นามสกุล ผู้มาติดต่อ','text',true),$f('visitor_company','บริษัท / หน่วยงานต้นสังกัด','text',true),$f('phone','เบอร์โทรศัพท์','text',true),
            $f('time_in','วันเวลาเข้า','datetime-local',true),$f('time_out','วันเวลาออก','datetime-local',true),$f('purpose','วัตถุประสงค์ในการติดต่อ','textarea',true),
            $f('work_types','ประเภทงาน','multiselect',true,'request',['network'=>'Network & Server','hardware'=>'Hardware Maintenance','software'=>'Software & System Update','delivery'=>'IT Equipment Delivery','other'=>'อื่นๆ']),
            $f('other','งานอื่นๆ'),$f('result','สรุปผลการดำเนินการ / หมายเหตุ','textarea',true),
        ],[],[$role('visitor','ผู้มาติดต่อ / ผู้ปฏิบัติงาน','completion','external',false),$role('it','เจ้าหน้าที่ IT / ผู้ควบคุมงาน','completion','admin'),$role('director','ผู้อนุมัติ / ผู้รับทราบ (ผอ./ผจก.)')]),
    ];
    $forms['FM-IT-007.1'] = [...$forms['FM-IT-007'],'code'=>'FM-IT-007.1','title'=>'ใบขอซื้อครุภัณฑ์คอมพิวเตอร์ (INNO — ยังไม่ขึ้นทะเบียน)','rev'=>'Rev.01, 11/08/2568 (INNO — ยังไม่ขึ้นทะเบียน)'];
    $forms['FM-IT-007.1']['fields'] = [...$forms['FM-IT-007']['fields'],$f('software','Software Support','textarea')];
    $forms['FM-IT-007.1']['roles']=array_map(fn($r)=>$r['key']==='director'?[...$r,'label'=>'ผจก.ฝ่ายเทคโนโลยีสารสนเทศ']:$r,$forms['FM-IT-007']['roles']);
    // FM-IT-008 is an editable issue/return register, not an approval document.
    $forms['FM-IT-008']['record'] = true;
    ksort($forms);
    return $forms;
}
