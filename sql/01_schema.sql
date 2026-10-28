-- IT Hub — โครงสร้างตาราง (รันใน database ithub)

SET NAMES utf8mb4;

-- ผู้ดูแลระบบ (login แยกจาก ERP)
CREATE TABLE IF NOT EXISTS `admins` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name`  VARCHAR(100) NOT NULL,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login_at` DATETIME     NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- บันทึก login ผิด (กัน brute force)
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`           VARCHAR(45)  NOT NULL,
  `username`     VARCHAR(50)  NULL,
  `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_ip` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- หมวดอุปกรณ์
CREATE TABLE IF NOT EXISTS `asset_categories` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(50)  NOT NULL,
  `icon`       VARCHAR(30)  NOT NULL DEFAULT 'device',
  `sort_order` INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asset_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ทะเบียนอุปกรณ์ (FM-IT-001)
CREATE TABLE IF NOT EXISTS `assets` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_code`      VARCHAR(30)  NOT NULL COMMENT 'หมายเลขทะเบียน เช่น MD-01-001 (ใช้ใน QR)',
  `category_id`     INT UNSIGNED NULL,
  `company`         VARCHAR(20)  NOT NULL DEFAULT 'ATS' COMMENT 'ATS / INNO',
  `brand_model`     VARCHAR(150) NULL,
  `serial_no`       VARCHAR(100) NULL,
  `hostname`        VARCHAR(50)  NULL,
  `holder_name`     VARCHAR(150) NULL COMMENT 'ผู้ครอบครอง',
  `holder_nickname` VARCHAR(50)  NULL,
  `department`      VARCHAR(150) NULL COMMENT 'หน่วยงานที่ใช้',
  `location`        VARCHAR(150) NULL,
  `status`          ENUM('active','repair','spare','retired') NOT NULL DEFAULT 'active',
  `registered_at`   DATE         NULL,
  `warranty_until`  DATE         NULL,
  `specs`           TEXT         NULL,
  `notes`           TEXT         NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assets_code` (`asset_code`),
  KEY `idx_assets_category` (`category_id`),
  KEY `idx_assets_status` (`status`),
  CONSTRAINT `fk_assets_category` FOREIGN KEY (`category_id`) REFERENCES `asset_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- คำขอ/แจ้งซ่อมทุกประเภท
--   repair   = ใบแจ้งซ่อม (FM-IT-003)
--   service  = ร้องขอดำเนินการด้าน IT (FM-IT-010)
--   email    = ขอใช้ E-mail (FM-IT-009)
--   borrow   = เบิก-คืนอุปกรณ์ (FM-IT-008)
--   purchase = ขอซื้อครุภัณฑ์คอมพิวเตอร์ (FM-IT-007)
-- ข้อมูลเฉพาะของแต่ละประเภทเก็บใน `details` (JSON)
CREATE TABLE IF NOT EXISTS `tickets` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_no`       VARCHAR(20)  NOT NULL COMMENT 'เช่น RP-6910-0001',
  `type`            ENUM('repair','service','email','borrow','purchase') NOT NULL,
  `title`           VARCHAR(200) NOT NULL,
  `description`     TEXT         NULL,
  `details`         JSON         NULL,
  `asset_id`        INT UNSIGNED NULL,
  `requester_name`  VARCHAR(150) NOT NULL,
  `requester_dept`  VARCHAR(150) NULL,
  `requester_phone` VARCHAR(50)  NULL,
  `requester_email` VARCHAR(150) NULL,
  `location`        VARCHAR(150) NULL,
  `priority`        ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `status`          ENUM('new','accepted','in_progress','waiting','done','rejected','cancelled') NOT NULL DEFAULT 'new',
  `assigned_to`     INT UNSIGNED NULL,
  `resolution`      TEXT         NULL COMMENT 'รายละเอียดการแก้ปัญหา / ผลการดำเนินการ',
  `fix_method`      ENUM('internal','outsource') NULL,
  `outsource_vendor` VARCHAR(150) NULL,
  `photo_path`      VARCHAR(255) NULL,
  `client_ip`       VARCHAR(45)  NULL,
  `started_at`      DATETIME     NULL,
  `finished_at`     DATETIME     NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tickets_no` (`ticket_no`),
  KEY `idx_tickets_status` (`status`),
  KEY `idx_tickets_type` (`type`),
  KEY `idx_tickets_asset` (`asset_id`),
  KEY `idx_tickets_created` (`created_at`),
  KEY `idx_tickets_ip_created` (`client_ip`, `created_at`),
  CONSTRAINT `fk_tickets_asset`    FOREIGN KEY (`asset_id`)    REFERENCES `assets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tickets_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ไทม์ไลน์ของคำขอ (เปลี่ยนสถานะ / บันทึก)
CREATE TABLE IF NOT EXISTS `ticket_logs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`   INT UNSIGNED NOT NULL,
  `admin_id`    INT UNSIGNED NULL,
  `from_status` VARCHAR(20)  NULL,
  `to_status`   VARCHAR(20)  NULL,
  `note`        TEXT         NULL,
  `is_public`   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = บันทึกภายใน IT เท่านั้น',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_logs_ticket` (`ticket_id`),
  CONSTRAINT `fk_ticket_logs_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ticket_logs_admin`  FOREIGN KEY (`admin_id`)  REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ประวัติบำรุงรักษาอุปกรณ์ (FM-IT-004)  BM = ซ่อมเมื่อเสีย, PM = ซ่อมเพื่อป้องกัน
CREATE TABLE IF NOT EXISTS `maintenance_records` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_id`     INT UNSIGNED NOT NULL,
  `ticket_id`    INT UNSIGNED NULL,
  `kind`         ENUM('BM','PM') NOT NULL,
  `performed_on` DATE         NOT NULL,
  `detail`       TEXT         NOT NULL,
  `duration`     VARCHAR(50)  NULL,
  `performed_by` VARCHAR(100) NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_maint_asset` (`asset_id`, `performed_on`),
  CONSTRAINT `fk_maint_asset`  FOREIGN KEY (`asset_id`)  REFERENCES `assets` (`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_maint_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `asset_categories` (`name`, `icon`, `sort_order`) VALUES
  ('Notebook',    'laptop',  1),
  ('PC',          'desktop', 2),
  ('All-in-one',  'desktop', 3),
  ('Monitor',     'monitor', 4),
  ('Printer',     'printer', 5),
  ('Network',     'network', 6),
  ('UPS',         'power',   7),
  ('Server',      'server',  8),
  ('อื่นๆ',        'device',  99);
