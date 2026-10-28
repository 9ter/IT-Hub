-- IT Hub — สร้างฐานข้อมูล (ต้องรันด้วย root ใน phpMyAdmin)
-- แสดงใน phpMyAdmin เป็น ithub (แยกนอกกลุ่ม erp)

CREATE DATABASE IF NOT EXISTS `ithub`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `ithub`.* TO `atthasan`@`localhost`;
FLUSH PRIVILEGES;
