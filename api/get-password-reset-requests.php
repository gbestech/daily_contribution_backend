<?php
// api/get-password-reset-requests.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
$db = getDBConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'Database connection failed']);
    exit();
}

try {
    // Optional filter: ?status=pending|completed|rejected|all
    $status = $_GET['status'] ?? 'pending';
    $allowedStatuses = ['pending', 'completed', 'rejected', 'all'];

    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'pending';
    }

    if ($status === 'all') {
        $stmt = $db->prepare(
            "SELECT id, email, user_type, status, requested_at,
                    handled_at, handled_by, notes
             FROM password_reset_requests
             ORDER BY
                CASE WHEN status = 'pending' THEN 0 ELSE 1 END,
                requested_at DESC
             LIMIT 100"
        );
        $stmt->execute();
    } else {
        $stmt = $db->prepare(
            "SELECT id, email, user_type, status, requested_at,
                    handled_at, handled_by, notes
             FROM password_reset_requests
             WHERE status = ?
             ORDER BY requested_at DESC
             LIMIT 100"
        );
        $stmt->execute([$status]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get counts for badges
    $countStmt = $db->prepare(
        "SELECT status, COUNT(*) as count
         FROM password_reset_requests
         GROUP BY status"
    );
    $countStmt->execute();
    $countRows = $countStmt->fetchAll(PDO::FETCH_ASSOC);

    $counts = ['pending' => 0, 'completed' => 0, 'rejected' => 0];
    foreach ($countRows as $c) {
        $counts[$c['status']] = (int)$c['count'];
    }

    echo json_encode([
        'status' => true,
        'requests' => $rows,
        'counts' => $counts,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'error' => 'Failed to fetch requests: ' . $e->getMessage(),
    ]);
}