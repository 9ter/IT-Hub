-- Request corrections preserve original signatures and record every before/after snapshot.
CREATE TABLE IF NOT EXISTS ticket_edit_requests (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id INT UNSIGNED NOT NULL,
 created_by INT UNSIGNED NOT NULL,
 reason TEXT NOT NULL,
 requester_email VARCHAR(150) NOT NULL,
 token_hash CHAR(64) NOT NULL,
 status ENUM('pending','submitted','superseded') NOT NULL DEFAULT 'pending',
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 submitted_at DATETIME NULL,
 mail_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
 mail_error VARCHAR(255) NULL,
 UNIQUE KEY uq_edit_request_token(token_hash),
 KEY ix_edit_request_ticket(ticket_id,id),
 CONSTRAINT fk_edit_request_ticket FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
 CONSTRAINT fk_edit_request_admin FOREIGN KEY(created_by) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_request_edits (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id INT UNSIGNED NOT NULL,
 admin_id INT UNSIGNED NULL,
 edit_request_id INT UNSIGNED NULL,
 reason TEXT NOT NULL,
 before_snapshot MEDIUMTEXT NOT NULL,
 after_snapshot MEDIUMTEXT NOT NULL,
 work_revision INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_request_edit_ticket(ticket_id,id),
 CONSTRAINT fk_request_edit_ticket FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
 CONSTRAINT fk_request_edit_admin FOREIGN KEY(admin_id) REFERENCES admins(id),
 CONSTRAINT fk_request_edit_return FOREIGN KEY(edit_request_id) REFERENCES ticket_edit_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
