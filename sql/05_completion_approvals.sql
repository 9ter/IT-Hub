-- Preserve existing request approvals and allow immutable completion review attempts.
ALTER TABLE tickets ADD COLUMN completion_revision INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE ticket_approvals
  ADD COLUMN stage ENUM('request','completion') NOT NULL DEFAULT 'request' AFTER ticket_id,
  ADD COLUMN attempt INT UNSIGNED NOT NULL DEFAULT 1 AFTER stage,
  ADD COLUMN work_revision INT UNSIGNED NULL AFTER attempt,
  ADD COLUMN resolution_snapshot TEXT NULL AFTER work_revision,
  DROP INDEX uq_approval_ticket,
  ADD UNIQUE KEY uq_approval_ticket_stage_attempt (ticket_id,stage,attempt);
