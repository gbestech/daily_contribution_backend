<?php
header("Content-Type: text/plain");
require __DIR__ . "/api/config/database.php";
if (!$db) { echo "CONNECTION FAILED (see Render logs)\n"; exit; }
try {
  $v = $db->query("SELECT VERSION() AS v")->fetch();
  echo "Connected OK. Server version: " . $v["v"] . "\n";
  $t = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
  echo "Tables: " . (count($t) ? implode(", ", $t) : "(none yet)") . "\n";
} catch (Throwable $e) {
  echo "QUERY FAILED: " . $e->getMessage() . "\n";
}
