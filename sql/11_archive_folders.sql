-- Relative to the configured Map NAS directory; old flat files are relocated by the CLI.
ALTER TABLE ticket_archives ADD COLUMN IF NOT EXISTS relative_path VARCHAR(300) NULL AFTER filename;
