<?php
declare(strict_types=1);

/*
 * Move the rows of FM-IT-005 drafts (it_documents) into the backup log (server_backups).
 * Dry run by default; --apply writes in one transaction. Runs already in the log (same date,
 * server and data set) are skipped and never overwritten, so running it again only adds rows
 * typed into the old drafts afterwards. The source drafts are left untouched.
 *
 *   php api/bin/migrate-fm005-documents.php [--apply]
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/backups.php';

$apply = in_array('--apply', $argv, true);
$pdo = db();
$docs = $pdo->query("SELECT id, document_no, status, data FROM it_documents
                      WHERE form_code = 'FM-IT-005' AND status <> 'cancelled' AND deleted_at IS NULL ORDER BY id")->fetchAll();
$sources = [];
foreach ($docs as $doc) {
    $data = json_decode($doc['data'], true, 512, JSON_THROW_ON_ERROR);
    $year = (int)($data['fields']['year'] ?? 0);
    if ($year < 2500) { echo "skip {$doc['document_no']}: no year\n"; continue; }
    $server = trim((string)($data['fields']['server'] ?? '')) ?: BACKUP_DEFAULT_SERVER;
    $rows = [];
    foreach ($data['rows'] ?? [] as $i => $raw) {
        if (trim((string)($raw['dataset'] ?? '')) === '' && trim((string)($raw['date'] ?? '')) === '') continue;
        $n = $i + 1;
        $rows[] = [$raw, "ย้ายจาก {$doc['document_no']} รายการที่ $n", "รายการที่ $n"];
    }
    $sources[] = ['label' => "{$doc['document_no']} (ปี $year)", 'year' => $year, 'server' => $server, 'rows' => $rows];
}
if (!$sources) { echo "no FM-IT-005 drafts\n"; exit(0); }

$pdo->beginTransaction();
try {
    $pdo->query('SELECT id FROM server_backups FOR UPDATE')->fetchAll();
    $plan = backup_merge_plan($sources);
    foreach ($plan['sources'] as $p) {
        echo "{$p['label']}: rows {$p['file_rows']} · add {$p['add']} · same {$p['same']} · differs {$p['differs']}\n";
        foreach ($p['errors'] as $e) echo "  ERROR $e\n";
        foreach ($p['warnings'] as $w) echo "  warn  $w\n";
        foreach ($p['rows'] as $r) if ($r['action'] === 'differs') echo "  differs {$r['line']} {$r['backup_date']}: " . implode(',', $r['fields']) . "\n";
        foreach ($p['rows'] as $r) if ($r['action'] === 'add' && $r['notes']) echo "  note  {$r['line']} {$r['backup_date']}: {$r['notes']}\n";
    }
    if ($plan['summary']['errors']) throw new RuntimeException('errors found, nothing written');
    if (!$apply) { $pdo->rollBack(); echo "dry run — add {$plan['summary']['add']} rows with --apply\n"; exit(0); }
    $n = backup_apply_adds($plan, null);
    $pdo->commit();
    echo "applied: added $n rows\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
