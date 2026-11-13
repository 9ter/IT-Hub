<?php
declare(strict_types=1);

/*
 * One-time move of FM-IT-002 drafts (it_documents, one per year) into the yearly inspection
 * checklist tables. Dry run by default; --apply writes. Years that already have items are skipped,
 * so running it again changes nothing. The source drafts are left untouched.
 *
 *   php api/bin/migrate-fm002-documents.php [--apply]
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/inspections.php';

$apply = in_array('--apply', $argv, true);
$pdo = db();
$docs = $pdo->query("SELECT id, document_no, status, data FROM it_documents WHERE form_code = 'FM-IT-002' AND status <> 'cancelled' ORDER BY id")->fetchAll();
$byYear = [];
foreach ($docs as $doc) {
    $data = json_decode($doc['data'], true, 512, JSON_THROW_ON_ERROR);
    $year = (int)($data['fields']['year'] ?? 0);
    if ($year < 2500) { echo "skip {$doc['document_no']}: no year\n"; continue; }
    // Several drafts of one year: the most recently edited one wins (ordered by id, later replaces earlier).
    $byYear[$year] = ['doc' => $doc, 'data' => $data];
}
$asset = $pdo->prepare('SELECT id FROM assets WHERE asset_code = ?');
$plan = [];
foreach ($byYear as $year => ['doc' => $doc, 'data' => $data]) {
    $has = $pdo->prepare('SELECT COUNT(*) FROM infra_inspection_items WHERE year = ?');
    $has->execute([$year]);
    if ((int)$has->fetchColumn() > 0) { echo "skip $year: already has items\n"; continue; }
    $items = [];
    foreach ($data['rows'] as $row) {
        $code = strtoupper(trim((string)($row['asset_code'] ?? '')));
        $item = trim((string)($row['item'] ?? ''));
        if ($item === INSPECTION_ITEM_PLACEHOLDER) $item = '';
        $notes = trim(str_replace('ต้นฉบับไม่ระบุหมายเลขเครื่อง', '', (string)($row['notes'] ?? '')));
        $results = [];
        for ($m = 1; $m <= 12; $m++) {
            $v = $row["m$m"] ?? null;
            if (in_array($v, INSPECTION_RESULTS, true)) $results[$m] = $v;
        }
        $assetId = null;
        if ($code !== '') { $asset->execute([$code]); $assetId = ($v = $asset->fetchColumn()) === false ? null : (int)$v; }
        $items[] = ['asset_code' => $code ?: null, 'asset_id' => $assetId, 'item' => $item ?: null, 'notes' => $notes ?: null, 'results' => $results];
    }
    $months = [];
    $labels = ['วันที่ตรวจ' => 'inspected_on', 'ผู้ตรวจสอบ' => 'inspector', 'ผู้ทบทวน' => 'reviewer'];
    foreach (preg_split('/\R/u', (string)($data['fields']['inspection_metadata'] ?? '')) as $line) {
        if (!preg_match('/^เดือน\s+(\d{1,2})\s+—\s+(วันที่ตรวจ|ผู้ตรวจสอบ|ผู้ทบทวน):\s*(.+)$/u', trim($line), $p)) continue;
        $value = trim($p[3]);
        if ($labels[$p[2]] === 'inspected_on') {
            if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/D', $value, $d)) { echo "warn $year: date '$value' kept out\n"; continue; }
            $y = (int)$d[3] >= 2400 ? (int)$d[3] - 543 : (int)$d[3];
            if (!checkdate((int)$d[2], (int)$d[1], $y)) { echo "warn $year: date '$value' invalid\n"; continue; }
            $value = sprintf('%04d-%02d-%02d', $y, $d[2], $d[1]);
        }
        $months[(int)$p[1]][$labels[$p[2]]] = mb_substr($value, 0, 100);
    }
    $plan[$year] = ['document' => $doc['document_no'], 'items' => $items, 'months' => $months];
    $marks = array_sum(array_map(fn($i) => count($i['results']), $items));
    $linked = count(array_filter($items, fn($i) => $i['asset_id'] !== null));
    echo "$year from {$doc['document_no']}: items " . count($items) . " (linked to register $linked), marks $marks, months " . json_encode($months, JSON_UNESCAPED_UNICODE) . "\n";
}
if (!$apply) { echo "dry run — add --apply to write\n"; exit(0); }

$pdo->beginTransaction();
try {
    $insert = $pdo->prepare('INSERT INTO infra_inspection_items (year, sort_order, asset_id, asset_code, item, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, NULL)');
    $mark = $pdo->prepare('INSERT INTO infra_inspection_results (item_id, month, result, updated_by) VALUES (?, ?, ?, NULL)');
    $month = $pdo->prepare('INSERT INTO infra_inspection_months (year, month, inspected_on, inspector, reviewer, notes, updated_by) VALUES (?, ?, ?, ?, ?, ?, NULL)');
    foreach ($plan as $year => $p) {
        foreach ($p['items'] as $i => $item) {
            $insert->execute([$year, $i + 1, $item['asset_id'], $item['asset_code'], $item['item'], $item['notes']]);
            $id = (int)$pdo->lastInsertId();
            foreach ($item['results'] as $m => $r) $mark->execute([$id, $m, $r]);
        }
        foreach ($p['months'] as $m => $v) $month->execute([$year, $m, $v['inspected_on'] ?? null, $v['inspector'] ?? null, $v['reviewer'] ?? null, "ย้ายจากฉบับร่าง {$p['document']}"]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
echo "applied " . count($plan) . " year(s)\n";
