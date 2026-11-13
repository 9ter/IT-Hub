-- Hide a ticket without destroying its signatures, logs or NAS history. Ticket numbers stay reserved.
ALTER TABLE tickets
 ADD COLUMN deleted_at DATETIME NULL,
 ADD COLUMN deleted_by INT UNSIGNED NULL,
 ADD COLUMN delete_reason VARCHAR(255) NULL,
 ADD CONSTRAINT fk_tickets_deleted_by FOREIGN KEY(deleted_by) REFERENCES admins(id) ON DELETE SET NULL;
