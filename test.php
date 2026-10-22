<?php
header("Content-Type: text/plain");
echo "PHP: " . PHP_VERSION . "\n";
echo "pdo_mysql: " . (extension_loaded("pdo_mysql") ? "OK" : "MISSING") . "\n";
echo "mysqli: " . (extension_loaded("mysqli") ? "OK" : "MISSING") . "\n";
echo "DB_HOST: " . (getenv("DB_HOST") ?: "(not set)") . "\n";
