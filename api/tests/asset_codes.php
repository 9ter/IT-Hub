<?php
declare(strict_types=1);

// Every write, including simulated concurrent requests, uses connection-local TEMP tables.
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/assets.php';
$pdo = db();
foreach (['assets', 'asset_categories', 'tickets', 'maintenance_records'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE `fixture_$table` LIKE `$table`");
    $pdo->exec("ALTER TABLE `fixture_$table` RENAME TO `$table`");
}
$checks = 0;
function check(bool $value, string $message): void
{
    global $checks;
    if (!$value) throw new RuntimeException($message);
    $checks++;
}
function rejects(callable $callback, string $field, string $message): void
{
    try {
        $callback();
    } catch (HttpError $e) {
        check($e->status === 422 && isset($e->errors[$field]), $message);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}

$a = asset_save(null, ['asset_code'=>' test-01 ', 'brand_model'=>'Fixture A', 'company'=>'ATS']);
$b = asset_save(null, ['asset_code'=>'TEST-02', 'brand_model'=>'Fixture B', 'company'=>'INNO']);
check($a['asset_code'] === 'TEST-01', 'Saves trim and uppercase before checking and storing');
check(asset_check_code(['asset_code'=>' test-01 ']) === ['available'=>false, 'asset_code'=>'TEST-01'], 'Availability normalizes and exposes only code and result');
check(asset_check_code(['asset_code'=>'TEST-03'])['available'], 'Unused code is available');
check(asset_check_code(['asset_code'=>'test-01', 'exclude_id'=>$a['id']])['available'], 'Edit excludes the current asset');
check(!asset_check_code(['asset_code'=>'TEST-01', 'exclude_id'=>$b['id']])['available'], 'Edit still detects another asset');
check(!asset_check_code(['asset_code'=>'TEST-01', 'company'=>'INNO'])['available'], 'Code uniqueness applies across companies');

$before = $pdo->query('SELECT * FROM assets ORDER BY id')->fetchAll();
rejects(fn()=>asset_save(null, ['asset_code'=>'test-01', 'brand_model'=>'Duplicate', 'company'=>'ATS']), 'asset_code', 'Duplicate create is rejected');
rejects(fn()=>asset_save(null, ['asset_code'=>' TEST-01 ', 'brand_model'=>'Duplicate', 'company'=>'INNO']), 'asset_code', 'Duplicate create across companies and surrounding spaces is rejected');
rejects(fn()=>asset_save((int)$b['id'], ['asset_code'=>'test-01', 'brand_model'=>'Duplicate update', 'company'=>'INNO']), 'asset_code', 'Duplicate update is rejected');
check($before === $pdo->query('SELECT * FROM assets ORDER BY id')->fetchAll(), 'Rejected saves do not change any inventory');
$same = asset_save((int)$a['id'], ['asset_code'=>' test-01 ', 'brand_model'=>'Fixture A', 'company'=>'ATS']);
check($same['id'] === $a['id'] && $same['asset_code'] === 'TEST-01', 'Saving the current registration preserves its asset ID');
$renamed = asset_save((int)$b['id'], ['asset_code'=>'test-03', 'brand_model'=>'Fixture B', 'company'=>'INNO']);
check($renamed['id'] === $b['id'] && asset_check_code(['asset_code'=>'TEST-02'])['available'], 'Edit can claim an unused code and release the previous code');

foreach ([[], ['asset_code'=>''], ['asset_code'=>' '], ['asset_code'=>'INVALID CODE'], ['asset_code'=>'-INVALID'], ['asset_code'=>str_repeat('A',31)], ['asset_code'=>['TEST-01']]] as $query) {
    rejects(fn()=>asset_check_code($query), 'asset_code', 'Availability rejects malformed or missing codes');
    rejects(fn()=>asset_save(null, $query + ['brand_model'=>'Invalid']), 'asset_code', 'Save uses identical code validation');
}
foreach (['0', '-1', 'invalid', ['1']] as $invalidId) {
    rejects(fn()=>asset_check_code(['asset_code'=>'TEST-01', 'exclude_id'=>$invalidId]), 'exclude_id', 'Invalid excluded ID is rejected');
}

// PDO's statement hook inserts a competing row after SELECT and before the actual write.
// It produces the real MySQL unique-key exception while keeping all state in TEMP assets.
final class AssetCodeRaceStatement extends PDOStatement
{
    public static ?Closure $beforeWrite = null;
    protected function __construct() {}
    public function execute(?array $params = null): bool
    {
        if (self::$beforeWrite !== null && preg_match('/^(INSERT INTO|UPDATE) assets\b/', $this->queryString)) {
            $callback = self::$beforeWrite;
            self::$beforeWrite = null;
            $callback();
        }
        return parent::execute($params);
    }
}
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [AssetCodeRaceStatement::class]);
try {
    AssetCodeRaceStatement::$beforeWrite = function () use ($pdo): void {
        $pdo->prepare('INSERT INTO assets (asset_code,brand_model) VALUES (?,?)')->execute(['LATE-CREATE','Race winner create']);
    };
    rejects(fn()=>asset_save(null, ['asset_code'=>'LATE-CREATE', 'brand_model'=>'Race loser create']), 'asset_code', 'A late insert collision returns the registration field error');
    check((int)$pdo->query("SELECT COUNT(*) FROM assets WHERE asset_code='LATE-CREATE'")->fetchColumn() === 1, 'Late insert collision creates only the competing row');
    check($pdo->query("SELECT brand_model FROM assets WHERE asset_code='LATE-CREATE'")->fetchColumn() === 'Race winner create', 'Late insert collision preserves the competing asset');

    AssetCodeRaceStatement::$beforeWrite = function () use ($pdo): void {
        $pdo->prepare('INSERT INTO assets (asset_code,brand_model) VALUES (?,?)')->execute(['LATE-UPDATE','Race winner update']);
    };
    rejects(fn()=>asset_save((int)$a['id'], ['asset_code'=>'LATE-UPDATE', 'brand_model'=>'Race loser update']), 'asset_code', 'A late update collision returns the registration field error');
    check(asset_detail((int)$a['id'])['asset_code'] === 'TEST-01', 'Late update collision preserves the original asset');
} finally {
    AssetCodeRaceStatement::$beforeWrite = null;
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
}

$pdo->exec('ALTER TABLE assets ADD UNIQUE KEY uq_assets_brand_test (brand_model)');
try {
    asset_save(null, ['asset_code'=>'UNUSED', 'brand_model'=>'Fixture A']);
    throw new RuntimeException('Expected unrelated unique constraint failure');
} catch (PDOException $e) {
    check((int)($e->errorInfo[1] ?? 0) === 1062 && str_contains((string)($e->errorInfo[2] ?? ''), 'uq_assets_brand_test'), 'Unrelated database constraint errors are not disguised as duplicate registration');
}
check(!asset_check_code(['asset_code'=>'LATE-CREATE'])['available'], 'Availability reflects registrations created after an earlier check');
echo "Asset code validation: $checks checks passed (TEMP tables only).\n";
