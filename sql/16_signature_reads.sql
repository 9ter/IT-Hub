-- Per-admin read state for "signed" notifications in the admin bell (head/completion/purchase sign-offs).
-- Not evidence: rows follow their approval/admin. Signatures that existed before this migration start as read.
CREATE TABLE IF NOT EXISTS ticket_approval_reads (
 approval_id INT UNSIGNED NOT NULL,
 admin_id INT UNSIGNED NOT NULL,
 read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(approval_id,admin_id),
 KEY ix_approval_reads_admin(admin_id),
 CONSTRAINT fk_approval_reads_approval FOREIGN KEY(approval_id) REFERENCES ticket_approvals(id) ON DELETE CASCADE,
 CONSTRAINT fk_approval_reads_admin FOREIGN KEY(admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ticket_approval_reads (approval_id,admin_id)
SELECT a.id, ad.id FROM ticket_approvals a CROSS JOIN admins ad WHERE a.status IN ('approved','rejected');
