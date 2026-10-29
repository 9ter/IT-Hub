-- FM-IT-008: a public request becomes a register entry only after an IT Hub admin approves.
CREATE TABLE IF NOT EXISTS ticket_borrow_decisions (
 ticket_id INT UNSIGNED NOT NULL PRIMARY KEY,
 decision ENUM('approved','rejected') NOT NULL,
 admin_id INT UNSIGNED NULL,
 admin_name VARCHAR(150) NOT NULL,
 decision_note VARCHAR(2000) NULL,
 decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 document_id BIGINT UNSIGNED NULL UNIQUE,
 CONSTRAINT fk_borrow_decision_ticket FOREIGN KEY(ticket_id) REFERENCES tickets(id),
 CONSTRAINT fk_borrow_decision_admin FOREIGN KEY(admin_id) REFERENCES admins(id) ON DELETE SET NULL,
 CONSTRAINT fk_borrow_decision_document FOREIGN KEY(document_id) REFERENCES it_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
