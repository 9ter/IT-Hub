<?php
declare(strict_types=1);

/*
 * Morning job: read Synology Hyper Backup and add new Friday runs to the FM-IT-005 log.
 * cron: 0 6 * * *  php /var/www/IT-Hub/api/bin/backup-sync.php
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/backups.php';
require __DIR__ . '/../src/hyperbackup.php';

if (hb_read() === null) { echo date('c'), " not connected\n"; exit(0); }
try {
    $r = hb_sync(null);
    echo date('c'), " ok added {$r['added']}", $r['dates'] ? ' (' . implode(', ', $r['dates']) . ')' : '', " size {$r['size']}\n";
} catch (HttpError $e) {
    echo date('c'), ' failed: ', $e->getMessage(), "\n";
    exit(1);
}
