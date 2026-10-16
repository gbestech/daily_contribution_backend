<?php
// api/items.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

while (ob_get_level()) {
    ob_end_clean();
}

// ============================================================
// CORS — echo origin (fixes "* + credentials" conflict)
// ============================================================
$__origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($__origin !== '') {
    header("Access-Control-Allow-Origin: $__origin");
    header("Vary: Origin");
} else {
    header("Access-Control-Allow-Origin: *");
}
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
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
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// ===== AUTO-CREATE TABLES =====
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NOT NULL DEFAULT 1,
            name VARCHAR(150) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            value DECIMAL(12,2) DEFAULT 0.00,
            active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_branch_id (branch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS member_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            item_id INT NOT NULL,
            checked TINYINT(1) DEFAULT 1,
            checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_member_item (member_id, item_id),
            INDEX (member_id),
            INDEX (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    try {
        $db->exec("ALTER TABLE items ADD COLUMN branch_id INT NOT NULL DEFAULT 1");
    } catch (Exception $e) { /* column already exists */ }
} catch (Exception $e) {
    // Tables probably already exist — safe to ignore
}

// ===== BRANCH CONTEXT =====
$requestedBranchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

if ($requestedBranchId <= 0) {
    try {
        $row = $db->query("SELECT id FROM branches WHERE status = 'active' ORDER BY id ASC LIMIT 1")
                  ->fetch(PDO::FETCH_ASSOC);
        $requestedBranchId = $row ? (int)$row['id'] : 1;
    } catch (Exception $e) {
        $requestedBranchId = 1;
    }
}
$BRANCH_ID = $requestedBranchId;

$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Extract numeric id from URL: /api/items.php/123  OR  ?id=123
$id = null;
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int)$_GET['id'];
} else {
    $pathParts = explode('/', $path);
    foreach ($pathParts as $part) {
        if (is_numeric($part)) {
            $id = (int)$part;
            break;
        }
    }
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    switch ($method) {

        // ============ GET ============
        case 'GET':
            $memberId = isset($_GET['member_id']) ? (int)$_GET['member_id'] : null;

            if ($memberId) {
                $stmt = $db->prepare("
                    SELECT i.*,
                           COALESCE(mi.checked, 0) AS checked,
                           mi.checked_at
                    FROM items i
                    LEFT JOIN member_items mi
                        ON mi.item_id = i.id AND mi.member_id = ?
                    WHERE i.active = 1 AND i.branch_id = ?
                    ORDER BY i.name ASC
                ");
                $stmt->execute([$memberId, $BRANCH_ID]);
                echo json_encode([
                    'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                    'branch_id' => $BRANCH_ID,
                ]);
            } else {
                $stmt = $db->prepare("SELECT * FROM items WHERE branch_id = ? ORDER BY name ASC");
                $stmt->execute([$BRANCH_ID]);
                echo json_encode([
                    'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                    'branch_id' => $BRANCH_ID,
                ]);
            }
            break;

        // ============ POST ============
        case 'POST':
            if (empty($input['name'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Item name is required']);
                exit();
            }
            $stmt = $db->prepare("
                INSERT INTO items (name, description, value, active, branch_id)
                VALUES (?, ?, ?, 1, ?)
            ");
            $stmt->execute([
                trim($input['name']),
                trim($input['description'] ?? ''),
                floatval($input['value'] ?? 0),
                $BRANCH_ID,
            ]);
            $newId = $db->lastInsertId();

            $row = $db->prepare("SELECT * FROM items WHERE id = ? AND branch_id = ?");
            $row->execute([$newId, $BRANCH_ID]);
            echo json_encode([
                'success' => true,
                'item' => $row->fetch(PDO::FETCH_ASSOC)
            ]);
            break;

        // ============ PUT ============
        case 'PUT':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Item id is required']);
                exit();
            }

            $check = $db->prepare("SELECT id FROM items WHERE id = ? AND branch_id = ?");
            $check->execute([$id, $BRANCH_ID]);
            if (!$check->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(404);
                echo json_encode(['error' => 'Item not found in this branch']);
                exit();
            }

            $fields = [];
            $params = [];
            foreach (['name', 'description', 'value', 'active'] as $f) {
                if (array_key_exists($f, $input)) {
                    $fields[] = "$f = ?";
                    $params[] = $input[$f];
                }
            }
            if (!$fields) {
                http_response_code(400);
                echo json_encode(['error' => 'Nothing to update']);
                exit();
            }
            $params[] = $id;
            $params[] = $BRANCH_ID;
            $stmt = $db->prepare(
                "UPDATE items SET " . implode(", ", $fields) . " WHERE id = ? AND branch_id = ?"
            );
            $stmt->execute($params);

            $row = $db->prepare("SELECT * FROM items WHERE id = ? AND branch_id = ?");
            $row->execute([$id, $BRANCH_ID]);
            echo json_encode([
                'success' => true,
                'item' => $row->fetch(PDO::FETCH_ASSOC)
            ]);
            break;

        // ============ DELETE ============
        case 'DELETE':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Item id is required']);
                exit();
            }

            $check = $db->prepare("SELECT id FROM items WHERE id = ? AND branch_id = ?");
            $check->execute([$id, $BRANCH_ID]);
            if (!$check->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(404);
                echo json_encode(['error' => 'Item not found in this branch']);
                exit();
            }

            $db->prepare("DELETE FROM member_items WHERE item_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM items WHERE id = ? AND branch_id = ?")
               ->execute([$id, $BRANCH_ID]);
            echo json_encode(['success' => true]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    error_log("items.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Request failed']);
}