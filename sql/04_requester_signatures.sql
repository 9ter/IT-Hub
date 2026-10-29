-- Keep requester signatures private and separate from public ticket payloads.
CREATE TABLE IF NOT EXISTS ticket_requester_signatures (
  ticket_id INT UNSIGNED NOT NULL PRIMARY KEY,
  requester_name VARCHAR(150) NOT NULL,
  signature_data MEDIUMTEXT NOT NULL,
  signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  signed_ip VARCHAR(45) NULL,
  CONSTRAINT fk_requester_signature_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
