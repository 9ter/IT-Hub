-- Hide an IT document (FM-IT-008 borrow record) without destroying its rows, logs or the borrow decision that references it.
ALTER TABLE it_documents
 ADD COLUMN deleted_at DATETIME NULL,
 ADD COLUMN deleted_by INT UNSIGNED NULL,
 ADD COLUMN delete_reason VARCHAR(255) NULL,
 ADD CONSTRAINT fk_it_documents_deleted_by FOREIGN KEY(deleted_by) REFERENCES admins(id) ON DELETE SET NULL;
