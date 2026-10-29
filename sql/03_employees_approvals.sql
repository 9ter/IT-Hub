-- Additive migration: employee directory and FM-IT-010 approval snapshots.
CREATE TABLE IF NOT EXISTS employees (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_user_id BIGINT UNSIGNED NULL,
  source_company_id BIGINT UNSIGNED NULL,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  company VARCHAR(255) NOT NULL,
  department VARCHAR(255) NULL,
  position VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  can_approve TINYINT(1) NOT NULL DEFAULT 0,
  synced_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_employee_source (source_user_id, source_company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_approvals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  approver_name VARCHAR(150) NOT NULL,
  approver_email VARCHAR(150) NOT NULL,
  approver_department VARCHAR(255) NULL,
  approver_company VARCHAR(255) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  token_hash CHAR(64) NULL,
  expires_at DATETIME NULL,
  mail_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  mail_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  mail_sent_at DATETIME NULL,
  mail_error VARCHAR(255) NULL,
  signature_data MEDIUMTEXT NULL,
  decision_note TEXT NULL,
  signed_at DATETIME NULL,
  signed_ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_approval_ticket (ticket_id),
  UNIQUE KEY uq_approval_token (token_hash),
  CONSTRAINT fk_approval_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_approval_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
