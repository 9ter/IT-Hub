<?php
// คัดลอกเป็น config.php แล้วแก้ค่าให้ตรงกับเซิร์ฟเวอร์
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'ithub',
        'user' => 'atthasan',
        'pass' => '',
    ],

    // URL เต็มที่มือถือใช้เปิดหน้า IT Hub (ใช้สร้าง QR Code)
    'app_url' => 'https://abms.ddns.net:8445/it-hub',

    // path ที่ Apache ตั้ง Alias ไว้ (ใช้กับ cookie)
    'base_path' => '/it-hub',

    'upload_dir' => __DIR__ . '/../storage/uploads',
    'upload_max_mb' => 8,

    // จำกัดจำนวนคำขอจากผู้ใช้ทั่วไปต่อ IP ใน 10 นาที
    'public_submit_limit' => 10,

    'debug' => false,
];
