ALTER TABLE admins
  ADD COLUMN signature_data MEDIUMTEXT NULL,
  ADD COLUMN signature_updated_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS ticket_it_signatures (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  work_revision INT UNSIGNED NOT NULL,
  resolution_snapshot TEXT NOT NULL,
  admin_id INT UNSIGNED NOT NULL,
  signer_name VARCHAR(100) NOT NULL,
  signature_data MEDIUMTEXT NOT NULL,
  signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  signed_ip VARCHAR(45) NULL,
  UNIQUE KEY uq_it_signature_revision (ticket_id,work_revision),
  CONSTRAINT fk_it_signature_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_it_signature_admin FOREIGN KEY (admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
