<?php
// api/expenses.php
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
 * Returns [] if the body is empty (e.g. approve/reject requests).
 * Sends a 400 and exits if the body exists but is malformed.
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
                $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ?");
                $stmt->execute([$id]);
                $exp = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$exp) { http_response_code(404); echo json_encode(['error' => 'Expense not found']); exit(); }
                echo json_encode(['expense' => formatExpense($exp)]);
                break;
            }

            $where = []; $params = [];

            if (!empty($_GET['from']))     { $where[] = "expense_date >= ?"; $params[] = $_GET['from']; }
            if (!empty($_GET['to']))       { $where[] = "expense_date <= ?"; $params[] = $_GET['to']; }
            if (!empty($_GET['category'])) { $where[] = "category = ?";      $params[] = $_GET['category']; }
            if (!empty($_GET['status']))   { $where[] = "status = ?";        $params[] = $_GET['status']; }

            $sql = "SELECT * FROM expenses";
            if ($where) $sql .= " WHERE " . implode(" AND ", $where);
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

            // Fallback: if the frontend uses title/requested_by, map them
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

            $stmt = $db->prepare("
                INSERT INTO expenses
                    (category, description, amount, expense_date, payment_method,
                     reference, vendor, status, recorded_by, approved_by, approved_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
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
                // No body required — read it only if present (for optional rejection_reason)
                $data = getJsonBody(true);

                $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ?");
                $stmt->execute([$id]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Expense not found']);
                    exit();
                }

                $newStatus   = $subAction === 'approve' ? 'approved' : 'rejected';
                $approved_by = $data['approved_by'] ?? 'Admin';
                $approved_at = date('Y-m-d H:i:s');
                $reason     = $subAction === 'reject'
                    ? ($data['rejection_reason'] ?? null)
                    : null;

                $stmt = $db->prepare("
                    UPDATE expenses
                    SET status = ?,
                        approved_by = ?,
                        approved_at = ?,
                        rejection_reason = ?
                    WHERE id = ?
                ");
                $stmt->execute([$newStatus, $approved_by, $approved_at, $reason, $id]);

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

            $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Expense not found']); exit(); }

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

            $params[] = $id;
            $sql = "UPDATE expenses SET " . implode(', ', $fields) . " WHERE id = ?";
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
            $stmt = $db->prepare("DELETE FROM expenses WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) { http_response_code(404); echo json_encode(['error' => 'Expense not found']); exit(); }
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