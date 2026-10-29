-- Independent purchase sign-offs may finish after the ticket is closed.
ALTER TABLE ticket_approvals MODIFY COLUMN stage ENUM('request','completion','purchase_director','purchase_purchasing','purchase_md') NOT NULL DEFAULT 'request';

CREATE TABLE IF NOT EXISTS ticket_receipt_signatures (
 ticket_id INT UNSIGNED PRIMARY KEY,
 admin_id INT UNSIGNED NOT NULL,
 signer_name VARCHAR(100) NOT NULL,
 signature_data MEDIUMTEXT NOT NULL,
 signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 signed_ip VARCHAR(45) NULL,
 CONSTRAINT fk_receipt_ticket FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
 CONSTRAINT fk_receipt_admin FOREIGN KEY(admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
