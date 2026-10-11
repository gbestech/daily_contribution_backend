<?php
// api/get-branch.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => false, 'error' => 'Method not allowed']);
    exit();
}

require_once __DIR__ . '/config/database.php';

try {
    $db = getDBConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'Database connection failed']);
    exit();
}

$id   = isset($_GET['id'])   ? (int)$_GET['id'] : 0;
$code = isset($_GET['code']) ? trim($_GET['code']) : '';

if ($id <= 0 && $code === '') {
    http_response_code(400);
    echo json_encode(['status' => false, 'error' => 'Provide id or code']);
    exit();
}

try {
    if ($id > 0) {
        $stmt = $db->prepare("SELECT id, code, name, status FROM branches WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
    } else {
        $stmt = $db->prepare("SELECT id, code, name, status FROM branches WHERE LOWER(code) = LOWER(?) LIMIT 1");
        $stmt->execute([$code]);
    }

    $branch = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$branch) {
        http_response_code(404);
        echo json_encode(['status' => false, 'error' => 'Branch not found']);
        exit();
    }

    if (strtolower($branch['status']) !== 'active') {
        http_response_code(403);
        echo json_encode([
            'status' => false,
            'error'  => 'This branch is not currently accepting registrations.',
        ]);
        exit();
    }

    echo json_encode([
        'status' => true,
        'branch' => [
            'id'   => (int)$branch['id'],
            'code' => $branch['code'],
            'name' => $branch['name'],
        ],
    ]);
} catch (Exception $e) {
    error_log("get-branch.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'Failed to load branch']);
}