<?php
// api/permissions.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

while (ob_get_level()) ob_end_clean();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config/database.php';

try {
    $db = getDBConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// ---------- Auto-ensure schema is correct (safe to run every time) ----------
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS permissions (
            role VARCHAR(50) NOT NULL,
            operations TEXT NOT NULL,
            PRIMARY KEY (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Make sure operations column is TEXT (not VARCHAR(255))
    $db->exec("ALTER TABLE permissions MODIFY operations TEXT NOT NULL");
} catch (Exception $e) {
    error_log("Schema check failed: " . $e->getMessage());
    // Don't die here — table probably already exists correctly
}

$method = $_SERVER['REQUEST_METHOD'];

// ---------- GET: return all role permissions ----------
if ($method === 'GET') {
    try {
        $stmt = $db->query("SELECT role, operations FROM permissions");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [
            'admin'   => [],
            'manager' => [],
            'member'  => [],
        ];

        foreach ($rows as $row) {
            $decoded = json_decode($row['operations'], true);
            $result[$row['role']] = is_array($decoded) ? $decoded : [];
        }

        echo json_encode([
            'status' => true,
            'permissions' => $result,
        ]);
    } catch (Exception $e) {
        error_log("GET error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// ---------- PUT: update permissions for a role ----------
if ($method === 'PUT') {
    $input = file_get_contents('php://input');
    error_log("PUT raw input: " . $input);

    $data = json_decode($input, true);
    error_log("PUT decoded: " . print_r($data, true));

    if (!$data || !isset($data['role']) || !isset($data['operations'])) {
        http_response_code(400);
        echo json_encode(['error' => 'role and operations are required']);
        exit();
    }

    $role = strtolower(trim($data['role']));
    $allowedRoles = ['admin', 'manager', 'member'];

    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid role: ' . $role]);
        exit();
    }

    if (!is_array($data['operations'])) {
        http_response_code(400);
        echo json_encode(['error' => 'operations must be an array']);
        exit();
    }

    // Sanitize: keep only strings
    $operations = array_values(array_filter($data['operations'], 'is_string'));
    $encoded = json_encode($operations);

    error_log("PUT role=$role, operations count=" . count($operations) . ", encoded=" . $encoded);

    try {
        // Upsert
        $stmt = $db->prepare("
            INSERT INTO permissions (role, operations)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE operations = VALUES(operations)
        ");
        $stmt->execute([$role, $encoded]);

        echo json_encode([
            'status' => true,
            'message' => "Permissions for $role updated",
            'role' => $role,
            'operations' => $operations,
        ]);
    } catch (Exception $e) {
        error_log("PUT DB error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'DB error: ' . $e->getMessage(),
            'role' => $role,
        ]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed: ' . $method]);