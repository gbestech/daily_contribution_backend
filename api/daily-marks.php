<?php
// api/daily-marks.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Don't override error_log unless you know the path is writable — let php.ini decide
// ini_set('error_log', __DIR__ . '/error.log');

while (ob_get_level()) {
    ob_end_clean();
}

// ---------- CORS — must be the FIRST thing that runs ----------
$allowedOrigins = [
    'http://localhost:3000',
    'http://localhost:5173',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:5173',
    // 'https://your-production-domain.com',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$originAllowed = $origin !== '' && in_array($origin, $allowedOrigins, true);

if ($originAllowed) {
    header("Access-Control-Allow-Origin: $origin");
    header("Vary: Origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header(
        "Access-Control-Allow-Headers: Content-Type, Accept, Authorization, " .
        "X-User-Role, X-User-Branch-Id, X-Requested-With"
    );
    header("Access-Control-Max-Age: 86400");
}

// Preflight — return 204 with no body, no Content-Type
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code($originAllowed ? 204 : 403);
    exit();
}

// Reject non-whitelisted origins early
if (!$originAllowed) {
    http_response_code(403);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(['status' => false, 'error' => 'Origin not allowed']);
    exit();
}

// Now safe to set Content-Type for real responses
header("Content-Type: application/json; charset=utf-8");

// ---------- DB ----------
require_once __DIR__ . '/config/database.php';

try {
    $db = getDBConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'DB connection failed']);
    exit;
}

if (!$db) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'DB connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET — marks for a member
// ============================================================
if ($method === 'GET') {
    $memberId = isset($_GET['member_id']) ? (int) $_GET['member_id'] : 0;
    if ($memberId <= 0) {
        echo json_encode(['status' => true, 'marks' => []]);
        exit;
    }

    try {
        $stmt = $db->prepare(
            "SELECT mark_date FROM member_daily_marks
             WHERE member_id = :mid AND marked = 1
             ORDER BY mark_date ASC"
        );
        $stmt->execute([':mid' => $memberId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log("[daily-marks] GET failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => false, 'error' => 'Query failed']);
        exit;
    }

    $marks = array_map(fn($d) => substr((string) $d, 0, 10), $rows ?: []);
    echo json_encode(['status' => true, 'marks' => $marks]);
    exit;
}

// ============================================================
// POST — toggle a mark
// ============================================================
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['status' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    $memberId = isset($body['member_id']) ? (int) $body['member_id'] : 0;
    $markDate = isset($body['date']) ? trim((string) $body['date']) : '';
    $marked   = !empty($body['marked']);
    $branchId = isset($body['branch_id']) ? (int) $body['branch_id'] : null;

    if ($memberId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => false, 'error' => 'member_id required']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $markDate)) {
        http_response_code(400);
        echo json_encode(['status' => false, 'error' => 'date must be YYYY-MM-DD']);
        exit;
    }

    $today = (new DateTime('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d');
    if ($markDate > $today) {
        http_response_code(400);
        echo json_encode(['status' => false, 'error' => 'Cannot mark a future date']);
        exit;
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO member_daily_marks (member_id, branch_id, mark_date, marked)
            VALUES (:mid, :bid, :dt, :mk)
            ON DUPLICATE KEY UPDATE marked = VALUES(marked), branch_id = VALUES(branch_id)
        ");
        $stmt->execute([
            ':mid' => $memberId,
            ':bid' => $branchId,
            ':dt'  => $markDate,
            ':mk'  => $marked ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        error_log("[daily-marks] POST failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => false, 'error' => 'Save failed']);
        exit;
    }

    echo json_encode(['status' => true, 'marked' => $marked, 'date' => $markDate]);
    exit;
}

http_response_code(405);
echo json_encode(['status' => false, 'error' => 'Method not allowed']);