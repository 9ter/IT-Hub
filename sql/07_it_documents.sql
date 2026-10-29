-- FM-IT document records are separate from live service tickets.
CREATE TABLE IF NOT EXISTS it_documents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 document_no VARCHAR(40) NOT NULL UNIQUE,
 form_code VARCHAR(20) NOT NULL,
 title VARCHAR(200) NOT NULL,
 document_date DATE NOT NULL,
 company VARCHAR(200) NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'draft',
 version INT UNSIGNED NOT NULL DEFAULT 1,
 data LONGTEXT NOT NULL,
 source_key VARCHAR(255) NULL UNIQUE,
 source_note TEXT NULL,
 created_by INT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX(form_code, document_date), INDEX(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS it_document_signatures (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 document_id BIGINT UNSIGNED NOT NULL,
 role_key VARCHAR(40) NOT NULL,
 role_label VARCHAR(150) NOT NULL,
 phase VARCHAR(20) NOT NULL,
 sequence_no INT NOT NULL,
 employee_id INT UNSIGNED NULL,
 admin_id INT UNSIGNED NULL,
 signer_name VARCHAR(150) NOT NULL,
 signer_email VARCHAR(150) NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'waiting',
 token_hash CHAR(64) NULL UNIQUE,
 expires_at DATETIME NULL,
 sent_at DATETIME NULL,
 mail_status VARCHAR(20) NOT NULL DEFAULT 'pending',
 mail_error VARCHAR(200) NULL,
 snapshot_hash CHAR(64) NOT NULL,
 signature_data MEDIUMTEXT NULL,
 decision_note TEXT NULL,
 signed_at DATETIME NULL,
 signed_ip VARCHAR(45) NULL,
 UNIQUE(document_id, role_key), INDEX(document_id,phase,sequence_no),
 FOREIGN KEY(document_id) REFERENCES it_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS it_document_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 document_id BIGINT UNSIGNED NOT NULL,
 admin_id INT UNSIGNED NULL,
 note VARCHAR(2000) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX(document_id), FOREIGN KEY(document_id) REFERENCES it_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
