-- FM-IT-005 รายงานการ BACK UP ข้อมูล SERVER as a running log (one row per backup run),
-- like the FM-IT-008 borrow records: add / edit rows and print the yearly form, no "create document".
-- Names in maker / acknowledger are plain records like the paper form, never signatures.
CREATE TABLE server_backups (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  backup_date DATE NOT NULL COMMENT 'ว/ด/ป (ค.ศ.)',
  server VARCHAR(100) NOT NULL DEFAULT 'SERVER' COMMENT 'ชื่อ Server',
  dataset VARCHAR(150) NOT NULL COMMENT 'ข้อมูลที่ BACK UP',
  data_size VARCHAR(40) NULL COMMENT 'ขนาดไฟล์จริง',
  source VARCHAR(150) NULL COMMENT 'แหล่งจัดเก็บข้อมูลหลัก',
  device VARCHAR(150) NULL COMMENT 'อุปกรณ์สำรอง',
  folder VARCHAR(255) NULL COMMENT 'Folder / File',
  backup_size VARCHAR(40) NULL COMMENT 'ขนาดไฟล์ BACK UP',
  started_at CHAR(8) NULL COMMENT 'เวลาเริ่ม HH:MM:SS',
  finished_at CHAR(8) NULL COMMENT 'เวลาเสร็จ HH:MM:SS',
  maker VARCHAR(100) NULL COMMENT 'จัดทำ',
  acknowledger VARCHAR(100) NULL COMMENT 'รับทราบ',
  notes VARCHAR(500) NULL,
  source_note VARCHAR(255) NULL COMMENT 'ที่มาของแถว (ย้ายจากเอกสาร / Import)',
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_server_backup_run (backup_date, server, dataset),
  KEY idx_server_backups_date (backup_date, id),
  CONSTRAINT fk_server_backups_created FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
  CONSTRAINT fk_server_backups_updated FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
