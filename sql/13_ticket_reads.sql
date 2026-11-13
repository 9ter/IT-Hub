-- Per-admin read state for the notification bell. Not evidence: rows follow their ticket/admin.
CREATE TABLE IF NOT EXISTS ticket_reads (
 ticket_id INT UNSIGNED NOT NULL,
 admin_id INT UNSIGNED NOT NULL,
 read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(ticket_id,admin_id),
 KEY ix_ticket_reads_admin(admin_id),
 CONSTRAINT fk_ticket_reads_ticket FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
 CONSTRAINT fk_ticket_reads_admin FOREIGN KEY(admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
