<?php
header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/config/database.php';

echo "PHP: " . PHP_VERSION . "\n";
echo "DB_HOST: " . (getenv('DB_HOST') ?: '(not set)') . "\n";
echo "DB_PORT: " . (getenv('DB_PORT') ?: '(not set)') . "\n";
echo "DB_NAME: " . (getenv('DB_NAME') ?: '(not set)') . "\n";
echo "DB_USER: " . (getenv('DB_USER') ?: '(not set)') . "\n";
echo "DB_PASSWORD set: " . (getenv('DB_PASSWORD') ? 'yes' : 'no') . "\n";
echo "DB_SSL_CA_PATH: " . (getenv('DB_SSL_CA_PATH') ?: '(not set)') . "\n";
echo "DB_INIT_SCHEMA: " . (getenv('DB_INIT_SCHEMA') ?: '(not set)') . "\n";
echo "ca.pem at /var/www/html/ca.pem: " . (file_exists('/var/www/html/ca.pem') ? 'yes' : 'no') . "\n";
echo "ca.pem at api/config/ca.pem: " . (file_exists(__DIR__ . '/config/ca.pem') ? 'yes' : 'no') . "\n";
echo "\n";

$db = getDBConnection();
if (!$db) {
    echo "RESULT: getDBConnection() returned NULL — connection failed.\n";
    exit;
}

echo "RESULT: Connected OK\n";

try {
    $v = $db->query("SELECT VERSION() AS v")->fetch();
    echo "Server version: " . $v['v'] . "\n";

    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables: " . (count($tables) ? implode(', ', $tables) : '(none)') . "\n";

    $hasBranches = $db->query("SHOW TABLES LIKE 'branches'")->rowCount() > 0;
    echo "branches table: " . ($hasBranches ? 'EXISTS' : 'MISSING') . "\n";
} catch (Throwable $e) {
    echo "QUERY ERROR: " . $e->getMessage() . "\n";
}