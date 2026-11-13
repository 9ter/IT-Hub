-- FM-IT-002 ใบตรวจสอบโครงสร้างพื้นฐาน as a living yearly checklist (edit in place, no "create document").
-- One list of items per Buddhist year, one result per item per month, and the monthly
-- inspection date / inspector / reviewer shown in the footer rows of the original form.
CREATE TABLE infra_inspection_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  year SMALLINT UNSIGNED NOT NULL COMMENT 'ปี พ.ศ.',
  sort_order INT NOT NULL DEFAULT 0,
  asset_id INT UNSIGNED NULL,
  asset_code VARCHAR(30) NULL COMMENT 'หมายเลขเครื่องตามที่บันทึกในปีนั้น',
  item VARCHAR(150) NULL COMMENT 'รายการตรวจสอบ',
  notes VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inspection_item_code (year, asset_code),
  KEY idx_inspection_items_year (year, sort_order, id),
  KEY idx_inspection_items_asset (asset_id),
  CONSTRAINT chk_inspection_item_year CHECK (year BETWEEN 2500 AND 2700),
  CONSTRAINT fk_inspection_items_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL,
  CONSTRAINT fk_inspection_items_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE infra_inspection_results (
  item_id INT UNSIGNED NOT NULL,
  month TINYINT UNSIGNED NOT NULL,
  result ENUM('normal','abnormal','fixed') NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (item_id, month),
  CONSTRAINT chk_inspection_result_month CHECK (month BETWEEN 1 AND 12),
  CONSTRAINT fk_inspection_results_item FOREIGN KEY (item_id) REFERENCES infra_inspection_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_inspection_results_admin FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE infra_inspection_months (
  year SMALLINT UNSIGNED NOT NULL,
  month TINYINT UNSIGNED NOT NULL,
  inspected_on DATE NULL,
  inspector VARCHAR(100) NULL COMMENT 'ผู้ตรวจสอบ',
  reviewer VARCHAR(100) NULL COMMENT 'ผู้ทบทวน',
  notes VARCHAR(500) NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (year, month),
  CONSTRAINT chk_inspection_month CHECK (month BETWEEN 1 AND 12),
  CONSTRAINT fk_inspection_months_admin FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
