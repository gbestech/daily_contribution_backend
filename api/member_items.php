<?php
// api/member_items.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

while (ob_get_level()) {
    ob_end_clean();
}

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 86400");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/config/database.php';

try {
    $db = getDBConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    if ($method === 'POST') {
        $memberId = (int)($input['member_id'] ?? 0);
        $itemId   = (int)($input['item_id'] ?? 0);
        $checked  = (int)($input['checked'] ?? 1);

        if (!$memberId || !$itemId) {
            http_response_code(400);
            echo json_encode(['error' => 'member_id and item_id required']);
            exit();
        }

        if ($checked) {
            $stmt = $db->prepare("
                INSERT INTO member_items (member_id, item_id, checked)
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE checked = 1, checked_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$memberId, $itemId]);
        } else {
            $stmt = $db->prepare("DELETE FROM member_items WHERE member_id = ? AND item_id = ?");
            $stmt->execute([$memberId, $itemId]);
        }

        echo json_encode(['success' => true]);

    } elseif ($method === 'GET') {
        $memberId = (int)($_GET['member_id'] ?? 0);
        if (!$memberId) {
            http_response_code(400);
            echo json_encode(['error' => 'member_id required']);
            exit();
        }
        $stmt = $db->prepare("
            SELECT i.*, mi.checked, mi.checked_at
            FROM member_items mi
            JOIN items i ON i.id = mi.item_id
            WHERE mi.member_id = ?
            ORDER BY i.name
        ");
        $stmt->execute([$memberId]);
        echo json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}