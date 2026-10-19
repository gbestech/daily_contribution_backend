<?php
// api/items.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

while (ob_get_level()) { ob_end_clean(); }

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 86400");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/config/database.php';

try { $db = getDBConnection(); }
catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
    exit();
}
if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

// Path-based id: /api/items.php/12
$path     = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segments = explode('/', trim($path, '/'));
$idFromPath = null;
if (count($segments) >= 3 && ctype_digit(end($segments))) {
    $idFromPath = (int) end($segments);
}

// Branch from query (React sends ?branch_id=)
$branchId = isset($_GET['branch_id']) ? (int) $_GET['branch_id'] : 0;

try {
    switch ($method) {
        // ------------------------------------------------------------
        // GET: admin list (branch-scoped) OR member list (?member_id=)
        // ------------------------------------------------------------
        case 'GET':
            // ---------- MEMBER MODE ----------
            if (!empty($_GET['member_id'])) {
                $memberId = (int) $_GET['member_id'];

                // Resolve member's branch (fallback to query branch_id)
                $mStmt = $db->prepare("SELECT branch_id FROM members WHERE id = ?");
                $mStmt->execute([$memberId]);
                $member = $mStmt->fetch(PDO::FETCH_ASSOC);
                if (!$member) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Member not found']);
                    exit();
                }
                $memberBranchId = (int)($member['branch_id'] ?? 0) ?: $branchId;

                // Only visible items in the member's branch, plus checked flag
                $stmt = $db->prepare("
                    SELECT i.id, i.name, i.description, i.value, i.active,
                           COALESCE(mi.checked, 0) AS checked,
                           mi.checked_at
                    FROM items i
                    LEFT JOIN member_items mi
                           ON mi.item_id = i.id AND mi.member_id = :member_id
                    WHERE i.active = 1
                      AND (:branch_id = 0 OR i.branch_id = :branch_id)
                    ORDER BY i.name ASC
                ");
                $stmt->bindValue(':member_id', $memberId, PDO::PARAM_INT);
                $stmt->bindValue(':branch_id', $memberBranchId, PDO::PARAM_INT);
                $stmt->execute();

                echo json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                exit();
            }

            // ---------- ADMIN MODE ----------
            $stmt = $db->prepare("
                SELECT id, name, description, value, active, branch_id, created_at
                FROM items
                WHERE (:branch_id = 0 OR branch_id = :branch_id)
                ORDER BY name ASC
            ");
            $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
            $stmt->execute();
            echo json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit();

        // ------------------------------------------------------------
        // POST: create item (admin)
        // ------------------------------------------------------------
        case 'POST':
            $name = trim($input['name'] ?? '');
            if ($name === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Item name is required']);
                exit();
            }
            $description = trim($input['description'] ?? '');
            $value       = (float)($input['value'] ?? 0);
            $active      = isset($input['active']) ? (int)$input['active'] : 1;
            $createBranch = $branchId ?: (int)($input['branch_id'] ?? 0);

            if (!$createBranch) {
                http_response_code(400);
                echo json_encode(['error' => 'branch_id required']);
                exit();
            }

            $stmt = $db->prepare("
                INSERT INTO items (name, description, value, active, branch_id)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $description, $value, $active, $createBranch]);
            echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
            exit();

        // ------------------------------------------------------------
        // PUT: update item (admin) or toggle active
        // ------------------------------------------------------------
        case 'PUT':
            $id = $idFromPath ?: (int)($input['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Item id required']);
                exit();
            }

            // Partial update — toggle active
            if (array_key_exists('active', $input) && count($input) === 1) {
                $stmt = $db->prepare("
                    UPDATE items SET active = ?
                    WHERE id = ? AND (:branch_id = 0 OR branch_id = :branch_id)
                ");
                $stmt->execute([(int)$input['active'], $id, ':branch_id' => 0]);
                // Rewrite with named branch properly (PDO quirk):
                $stmt = $db->prepare("
                    UPDATE items SET active = :active
                    WHERE id = :id AND (:branch_id = 0 OR branch_id = :branch_id2)
                ");
                $stmt->execute([
                    ':active'      => (int)$input['active'],
                    ':id'          => $id,
                    ':branch_id'   => $branchId,
                    ':branch_id2'  => $branchId,
                ]);
                echo json_encode(['success' => true]);
                exit();
            }

            // Full update
            $name = trim($input['name'] ?? '');
            if ($name === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Item name is required']);
                exit();
            }
            $stmt = $db->prepare("
                UPDATE items
                SET name = :name, description = :desc, value = :val
                WHERE id = :id AND (:branch_id = 0 OR branch_id = :branch_id2)
            ");
            $stmt->execute([
                ':name'       => $name,
                ':desc'       => trim($input['description'] ?? ''),
                ':val'        => (float)($input['value'] ?? 0),
                ':id'         => $id,
                ':branch_id'  => $branchId,
                ':branch_id2' => $branchId,
            ]);
            echo json_encode(['success' => true]);
            exit();

        // ------------------------------------------------------------
        // DELETE: remove item + its member links
        // ------------------------------------------------------------
        case 'DELETE':
            $id = $idFromPath ?: (int)($input['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Item id required']);
                exit();
            }
            $db->beginTransaction();
            try {
                $db->prepare("DELETE FROM member_items WHERE item_id = ?")->execute([$id]);
                $stmt = $db->prepare("
                    DELETE FROM items
                    WHERE id = :id AND (:branch_id = 0 OR branch_id = :branch_id2)
                ");
                $stmt->execute([
                    ':id'         => $id,
                    ':branch_id'  => $branchId,
                    ':branch_id2' => $branchId,
                ]);
                $db->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            exit();

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}