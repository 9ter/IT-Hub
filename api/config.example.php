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

    // Private paths outside the repository/web root. The PHP-FPM user needs write access to state_dir.
    'nas' => [
        'state_dir' => '/var/lib/it-hub/nas',
        'python' => '/usr/bin/python3',
        'python_packages' => '/var/lib/it-hub/python',
    ],

    // Background PDF export. Default state_dir is a sibling of nas.state_dir: ../archive.
    'archive' => [
        'state_dir' => '/var/lib/it-hub/archive',
        'node' => '/usr/bin/node',
        'runtime' => '/var/lib/it-hub/archive/node_modules/playwright',
        'chromium' => '/var/lib/it-hub/archive/chromium/chrome',
    ],

    // จำกัดจำนวนคำขอจากผู้ใช้ทั่วไปต่อ IP ใน 10 นาที
    'public_submit_limit' => 10,
    'completion_default_approver_name' => 'สมไชย อักษร',

    // Credentials belong only in the ignored local config.php.
    'smtp' => [
        'enabled' => false,
        'host' => 'smtp.hostinger.com',
        'port' => 465,
        'encryption' => 'ssl',
        'username' => '',
        'password' => '',
        'from_email' => 'IT.HUB@atthasan.com',
    ],
    // Read-only source. Sync never writes into E-Procurement.
    'employee_source' => [
        'host' => '127.0.0.1', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => '',
    ],

    'debug' => false,
];
