<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// api/config/database.php

header("Access-Control-Allow-Origin: https://daily-contribution-tjq7.onrender.com");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit(0);
}

function getDBConnection() {
    $host     = getenv('DB_HOST')     ?: 'localhost';
    $dbname   = getenv('DB_NAME')     ?: 'baleeg_db';
    $username = getenv('DB_USER')     ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    $port     = getenv('DB_PORT')     ?: '3306';

    $sslOptions = [];
    $caPath = getenv('DB_SSL_CA_PATH');
    if (!$caPath || !file_exists($caPath)) {
        $candidates = [
            dirname(__DIR__, 2) . '/ca.pem',
            __DIR__ . '/ca.pem',
        ];
        foreach ($candidates as $c) {
            if (file_exists($c)) { $caPath = $c; break; }
        }
    }
    if ($caPath && file_exists($caPath)) {
        $sslOptions = [
            PDO::MYSQL_ATTR_SSL_CA => $caPath,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        ];
    }

    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

    // ⚠️ TEMPORARY: no try/catch here. Any PDOException will bubble up
    // so db-check.php and members.php show the real error.
    $pdo = new PDO($dsn, $username, $password, $sslOptions);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}

function initializeDatabase($db) {
    if (!$db) return;

    $shouldInit = getenv('DB_INIT_SCHEMA');
    if ($shouldInit === '0' || $shouldInit === 'false') {
        return;
    }

    try {
        // ---------- branches ----------
        $db->exec("
            CREATE TABLE IF NOT EXISTS branches (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                code VARCHAR(50) DEFAULT NULL,
                address VARCHAR(255) DEFAULT NULL,
                phone VARCHAR(30) DEFAULT NULL,
                email VARCHAR(100) DEFAULT NULL,
                status VARCHAR(20) DEFAULT 'Active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $cnt = $db->query("SELECT COUNT(*) FROM branches")->fetchColumn();
        if ((int)$cnt === 0) {
            $db->exec("INSERT INTO branches (name, code, status) VALUES ('Main Branch', 'MAIN', 'Active')");
        }

        // ---------- members ----------
        $db->exec("
            CREATE TABLE IF NOT EXISTS members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_number VARCHAR(20) UNIQUE NOT NULL,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL,
                phone VARCHAR(20),
                membership_type VARCHAR(50) DEFAULT 'Standard',
                join_date DATE,
                status VARCHAR(20) DEFAULT 'Active',
                balance DECIMAL(10,2) DEFAULT 0,
                role VARCHAR(20) DEFAULT 'member',
                password VARCHAR(255) DEFAULT NULL,
                branch_id INT DEFAULT NULL,
                date_of_birth DATE DEFAULT NULL,
                gender VARCHAR(20) DEFAULT NULL,
                address VARCHAR(255) DEFAULT NULL,
                next_of_kin_name VARCHAR(100) DEFAULT NULL,
                next_of_kin_phone VARCHAR(30) DEFAULT NULL,
                next_of_kin_relationship VARCHAR(50) DEFAULT NULL,
                savings_plan VARCHAR(50) DEFAULT NULL,
                savings_method VARCHAR(50) DEFAULT NULL,
                item_name VARCHAR(100) DEFAULT NULL,
                duration VARCHAR(50) DEFAULT NULL,
                amount DECIMAL(10,2) DEFAULT NULL,
                total_amount DECIMAL(10,2) DEFAULT NULL,
                bank_name VARCHAR(100) DEFAULT NULL,
                bank_account_number VARCHAR(50) DEFAULT NULL,
                passport_photo VARCHAR(255) DEFAULT NULL,
                profile_completed TINYINT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_branch (branch_id),
                INDEX idx_email (email)
            )
        ");

        // ---------- transactions ----------
        $db->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                member_name VARCHAR(100),
                account_number VARCHAR(20),
                type VARCHAR(50) NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                charge DECIMAL(10,2) DEFAULT 0,
                rate DECIMAL(10,2) DEFAULT 0,
                net_amount DECIMAL(10,2) DEFAULT NULL,
                date DATE,
                status VARCHAR(20) DEFAULT 'pending',
                description TEXT,
                branch_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_member (member_id),
                INDEX idx_branch (branch_id)
            )
        ");

        // ---------- admins ----------
        $db->exec("
            CREATE TABLE IF NOT EXISTS admins (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(100) NOT NULL,
                password VARCHAR(255) DEFAULT NULL,
                role VARCHAR(50) DEFAULT 'admin',
                phone VARCHAR(30) DEFAULT NULL,
                full_name VARCHAR(100) DEFAULT NULL,
                branch_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch (PDOException $e) {
        error_log("Failed to initialize database: " . $e->getMessage());
    }
}

$db = getDBConnection();
if ($db) {
    initializeDatabase($db);
}