<?php
// api/daily-marks.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

while (ob_get_level()) {
    ob_end_clean();
}

// ---------- CORS ----------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: " . $origin);
header("Vary: Origin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-User-Role, X-User-Branch-Id, X-Requested-With");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ---------- DB — same pattern as members.php ----------
require_once __DIR__ . '/config/database.php';

try {
    $db = getDBConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

if (!$db) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'DB connection failed']);
    exit;
}

// ---------- auto-create table ----------
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS member_daily_marks (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            member_id    INT NOT NULL,
            branch_id    INT NULL,
            mark_date    DATE NOT NULL,
            marked       TINYINT(1) NOT NULL DEFAULT 1,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_member_date (member_id, mark_date),
            INDEX idx_member (member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'Create table failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET — marks for a member
// ============================================================
if ($method === 'GET') {
    $memberId = isset($_GET['member_id']) ? (int)$_GET['member_id'] : 0;
    if ($memberId <= 0) {
        echo json_encode(['status' => true, 'marks' => []]);
        exit;
    }

    $stmt = $db->prepare(
        "SELECT mark_date FROM member_daily_marks
         WHERE member_id = :mid AND marked = 1
         ORDER BY mark_date ASC"
    );
    $stmt->execute([':mid' => $memberId]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $marks = array_map(function ($d) {
        return substr((string)$d, 0, 10);
    }, $rows ?: []);

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

    $memberId = isset($body['member_id']) ? (int)$body['member_id'] : 0;
    $markDate = isset($body['date']) ? trim((string)$body['date']) : '';
    $marked   = !empty($body['marked']);
    $branchId = isset($body['branch_id']) ? (int)$body['branch_id'] : null;

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

    echo json_encode(['status' => true, 'marked' => $marked, 'date' => $markDate]);
    exit;
}

http_response_code(405);
echo json_encode(['status' => false, 'error' => 'Method not allowed']);