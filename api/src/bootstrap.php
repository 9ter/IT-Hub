<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Bangkok');

require __DIR__ . '/helpers.php';
require __DIR__ . '/Input.php';
require __DIR__ . '/Router.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/ticket_types.php';
require __DIR__ . '/tickets.php';

$GLOBALS['ithub_config'] = require (getenv('ITHUB_CONFIG') ?: __DIR__ . '/../config.php');

function cfg(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['ithub_config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', cfg('db.host'), cfg('db.port'), cfg('db.name'));
        $pdo = new PDO($dsn, cfg('db.user'), cfg('db.pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+07:00'");
    }
    return $pdo;
}
