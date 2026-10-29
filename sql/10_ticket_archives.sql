-- Durable outbox: completing a ticket and queuing its document share one transaction.
CREATE TABLE IF NOT EXISTS ticket_archives (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id INT UNSIGNED NOT NULL,
 snapshot_hash CHAR(64) NOT NULL,
 target_revision VARCHAR(32) NOT NULL DEFAULT '',
 snapshot LONGTEXT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 filename VARCHAR(200) NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 started_at DATETIME NULL,
 sent_at DATETIME NULL,
 last_error VARCHAR(500) NULL,
 pdf_sha256 CHAR(64) NULL,
 pdf_size INT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX archive_ticket_latest(ticket_id,id),
 INDEX(status,next_attempt_at),
 FOREIGN KEY(ticket_id) REFERENCES tickets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
