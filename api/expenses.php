<?php
// api/expenses.php — branch-aware
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

while (ob_get_level()) ob_end_clean();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/config/database.php';

try { $db = getDBConnection(); }
catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

if (!$db) { http_response_code(500); echo json_encode(['error' => 'Database connection failed']); exit(); }

// ============================================================
// BRANCH CONTEXT
// ============================================================
$requestedBranchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

if ($requestedBranchId <= 0) {
    try {
        $row = $db->query("SELECT id FROM branches WHERE status = 'active' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $requestedBranchId = $row ? (int)$row['id'] : 1;
    } catch (Exception $e) {
        $requestedBranchId = 1;
    }
}

$BRANCH_ID = $requestedBranchId;

// ============================================================
// AUTO-MIGRATION: ensure branch_id exists on the expenses table
// ============================================================
try {
    $tableCheck = $db->query("SHOW TABLES LIKE 'expenses'");
    if ($tableCheck->rowCount() > 0) {
        $cols = [];
        $stmt = $db->query("SHOW COLUMNS FROM expenses");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[strtolower($c['Field'])] = true;
        }
        if (!isset($cols['branch_id'])) {
            $db->exec("ALTER TABLE expenses ADD COLUMN branch_id INT NOT NULL DEFAULT 1 AFTER id");
        }
        // Back-fill any orphans
        $db->exec("UPDATE expenses SET branch_id = 1 WHERE branch_id IS NULL OR branch_id = 0");
    }
} catch (Exception $e) {
    // silent
}

$method = $_SERVER['REQUEST_METHOD'];

// Extract ID and sub-action (approve / reject) from URL
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$id = null;
$subAction = null;
foreach ($pathParts as $i => $part) {
    if (is_numeric($part)) {
        $id = (int)$part;
        if (isset($pathParts[$i + 1])) $subAction = strtolower($pathParts[$i + 1]);
        break;
    }
}

/**
 * Safely read JSON body — reads php://input ONLY ONCE.
 */
function getJsonBody($allowEmpty = false) {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        if ($allowEmpty) return [];
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON', 'details' => 'Empty request body']);
        exit();
    }
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'error'   => 'Invalid JSON',
            'details' => json_last_error_msg(),
            'snippet' => substr($raw, 0, 200),
        ]);
        exit();
    }
    return $data;
}

function formatExpense($e) {
    return [
        'id'               => (int)$e['id'],
        'branch_id'        => isset($e['branch_id']) ? (int)$e['branch_id'] : null, // 🆕
        'category'         => $e['category'] ?? 'Other',
        'description'      => $e['description'] ?? '',
        'amount'           => (float)($e['amount'] ?? 0),
        'expense_date'     => $e['expense_date'] ?? null,
        'payment_method'   => $e['payment_method'] ?? 'Cash',
        'reference'        => $e['reference'] ?? null,
        'vendor'           => $e['vendor'] ?? null,
        'status'           => $e['status'] ?? 'approved',
        'recorded_by'      => $e['recorded_by'] ?? null,
        'approved_by'      => $e['approved_by'] ?? null,
        'approved_at'      => $e['approved_at'] ?? null,
        'rejection_reason' => $e['rejection_reason'] ?? null,
        'created_at'       => $e['created_at'] ?? null,
        'updated_at'       => $e['updated_at'] ?? null,
    ];
}

switch ($method) {

    // ---------------- GET ----------------
    case 'GET':
        try {
            if ($id) {
                $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ? AND branch_id = ?");
                $stmt->execute([$id, $BRANCH_ID]);
                $exp = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$exp) { http_response_code(404); echo json_encode(['error' => 'Expense not found in this branch']); exit(); }
                echo json_encode(['expense' => formatExpense($exp)]);
                break;
            }

            $where = ["branch_id = ?"]; $params = [$BRANCH_ID];

            if (!empty($_GET['from']))     { $where[] = "expense_date >= ?"; $params[] = $_GET['from']; }
            if (!empty($_GET['to']))       { $where[] = "expense_date <= ?"; $params[] = $_GET['to']; }
            if (!empty($_GET['category'])) { $where[] = "category = ?";      $params[] = $_GET['category']; }
            if (!empty($_GET['status']))   { $where[] = "status = ?";        $params[] = $_GET['status']; }

            $sql = "SELECT * FROM expenses WHERE " . implode(" AND ", $where);
            $sql .= " ORDER BY expense_date DESC, id DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $formatted = array_map('formatExpense', $rows);

            $total = 0; $pendingTotal = 0;
            foreach ($formatted as $f) {
                $total += $f['amount'];
                if ($f['status'] === 'pending') $pendingTotal += $f['amount'];
            }

            echo json_encode([
                'status'        => true,
                'expenses'      => $formatted,
                'total'         => $total,
                'pending_total' => $pendingTotal,
                'count'         => count($formatted),
                'branch_id'     => $BRANCH_ID,
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ---------------- POST ----------------
    case 'POST':
        try {
            $data = getJsonBody(false);

            $category    = trim($data['category'] ?? '');
            $description = trim($data['description'] ?? '');
            $amount      = floatval($data['amount'] ?? 0);
            $date        = $data['expense_date'] ?? date('Y-m-d');
            $methodVal   = $data['payment_method'] ?? 'Cash';
            $reference   = $data['reference'] ?? null;
            $vendor      = $data['vendor'] ?? null;
            $recorded_by = $data['recorded_by'] ?? null;
            $recorded_by_role = strtolower($data['recorded_by_role'] ?? 'member');

            // Fallback mapping
            if ($category === '' && !empty($data['title'])) {
                $category = 'General';
            }
            if ($recorded_by === null && !empty($data['requested_by'])) {
                $recorded_by = $data['requested_by'];
            }
            if ($description === '' && !empty($data['title'])) {
                $description = $data['title'];
            }
            if ($category === '') $category = 'General';

            if ($amount <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'A positive amount is required']);
                exit();
            }

            // Admins can auto-approve; others go to pending
            $isAdmin = in_array($recorded_by_role, ['admin', 'administrator'], true);
            $status  = $isAdmin && ($data['auto_approve'] ?? true) ? 'approved' : 'pending';
            $approved_by = ($isAdmin && $status === 'approved') ? $recorded_by : null;
            $approved_at = ($isAdmin && $status === 'approved') ? date('Y-m-d H:i:s') : null;

            // 🆕 branch_id included in the INSERT
            $stmt = $db->prepare("
                INSERT INTO expenses
                    (branch_id, category, description, amount, expense_date, payment_method,
                     reference, vendor, status, recorded_by, approved_by, approved_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $BRANCH_ID,
                $category, $description, $amount, $date, $methodVal,
                $reference, $vendor, $status, $recorded_by, $approved_by, $approved_at
            ]);

            $newId = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ?");
            $stmt->execute([$newId]);
            $newExpense = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status'  => true,
                'message' => $status === 'approved'
                    ? 'Expense recorded and approved'
                    : 'Expense recorded — pending admin approval',
                'expense' => formatExpense($newExpense),
                'branch_id' => $BRANCH_ID,
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ---------------- PUT ----------------
    case 'PUT':
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Expense ID required']); exit(); }

        try {
            // -------- APPROVE / REJECT sub-actions --------
            if ($subAction === 'approve' || $subAction === 'reject') {
                $data = getJsonBody(true);

                // 🆕 Only fetch if it belongs to the current branch
                $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ? AND branch_id = ?");
                $stmt->execute([$id, $BRANCH_ID]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Expense not found in this branch']);
                    exit();
                }

                $newStatus   = $subAction === 'approve' ? 'approved' : 'rejected';
                $approved_by = $data['approved_by'] ?? 'Admin';
                $approved_at = date('Y-m-d H:i:s');
                $reason     = $subAction === 'reject'
                    ? ($data['rejection_reason'] ?? null)
                    : null;

                // 🆕 branch-guarded UPDATE
                $stmt = $db->prepare("
                    UPDATE expenses
                    SET status = ?,
                        approved_by = ?,
                        approved_at = ?,
                        rejection_reason = ?
                    WHERE id = ? AND branch_id = ?
                ");
                $stmt->execute([$newStatus, $approved_by, $approved_at, $reason, $id, $BRANCH_ID]);

                $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ?");
                $stmt->execute([$id]);
                $updated = $stmt->fetch(PDO::FETCH_ASSOC);

                echo json_encode([
                    'status'  => true,
                    'success' => true,
                    'message' => $newStatus === 'approved' ? 'Expense approved' : 'Expense rejected',
                    'expense' => formatExpense($updated),
                ]);
                break;
            }

            // -------- Generic PUT (full update) --------
            $data = getJsonBody(false);

            // 🆕 branch-guarded lookup
            $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ? AND branch_id = ?");
            $stmt->execute([$id, $BRANCH_ID]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Expense not found in this branch']); exit(); }

            $fields = []; $params = [];

            $simple = ['category','description','amount','expense_date',
                       'payment_method','reference','vendor','status','recorded_by'];

            foreach ($simple as $f) {
                if (array_key_exists($f, $data)) {
                    $fields[] = "$f = ?";
                    $params[] = ($f === 'amount') ? floatval($data[$f]) : $data[$f];
                }
            }

            if (isset($data['status'])) {
                if ($data['status'] === 'approved') {
                    $fields[] = "approved_by = ?";
                    $params[] = $data['approved_by'] ?? 'Admin';
                    $fields[] = "approved_at = ?";
                    $params[] = date('Y-m-d H:i:s');
                    $fields[] = "rejection_reason = NULL";
                } elseif ($data['status'] === 'rejected') {
                    $fields[] = "rejection_reason = ?";
                    $params[] = $data['rejection_reason'] ?? null;
                    $fields[] = "approved_by = ?";
                    $params[] = $data['approved_by'] ?? 'Admin';
                    $fields[] = "approved_at = ?";
                    $params[] = date('Y-m-d H:i:s');
                }
            }

            if (!$fields) { http_response_code(400); echo json_encode(['error' => 'No fields to update']); exit(); }

            // 🆕 append branch guard to the WHERE clause
            $params[] = $id;
            $params[] = $BRANCH_ID;
            $sql = "UPDATE expenses SET " . implode(', ', $fields) . " WHERE id = ? AND branch_id = ?";
            $db->prepare($sql)->execute($params);

            $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ?");
            $stmt->execute([$id]);
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status'  => true,
                'success' => true,
                'message' => 'Expense updated',
                'expense' => formatExpense($updated),
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ---------------- DELETE ----------------
    case 'DELETE':
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Expense ID required']); exit(); }
        try {
            // 🆕 branch-guarded DELETE
            $stmt = $db->prepare("DELETE FROM expenses WHERE id = ? AND branch_id = ?");
            $stmt->execute([$id, $BRANCH_ID]);
            if ($stmt->rowCount() === 0) { http_response_code(404); echo json_encode(['error' => 'Expense not found in this branch']); exit(); }
            echo json_encode(['status' => true, 'success' => true, 'message' => 'Expense deleted']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}