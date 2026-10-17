<?php
// api/transactions.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

while (ob_get_level()) ob_end_clean();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-User-Role, X-User-Branch-Id, X-User-Id");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 86400");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/charges.php';

try {
    $db = getDBConnection();
} catch (Exception $e) {
    error_log("transactions.php DB connect error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}
if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

ensureSchema($db);

// ============================================================
// Helpers
// ============================================================
function jsonOut($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload);
    exit();
}

function readBody() {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/**
 * Resolve branch id from query / body / header.
 * If `$required` is true and nothing resolves, 400.
 */
function resolveBranchId(array $body = [], bool $required = true) {
    $id = 0;
    if (isset($_GET['branch_id']) && (int)$_GET['branch_id'] > 0) {
        $id = (int)$_GET['branch_id'];
    } elseif (isset($body['branch_id']) && (int)$body['branch_id'] > 0) {
        $id = (int)$body['branch_id'];
    } elseif (isset($_SERVER['HTTP_X_USER_BRANCH_ID']) && (int)$_SERVER['HTTP_X_USER_BRANCH_ID'] > 0) {
        $id = (int)$_SERVER['HTTP_X_USER_BRANCH_ID'];
    }

    if ($required && $id <= 0) {
        jsonOut([
            'error' => 'branch_id is required (?branch_id=N or X-User-Branch-Id)',
        ], 400);
    }
    return $id;
}

function branchExists(PDO $db, int $branchId): bool {
    $s = $db->prepare("SELECT id FROM branches WHERE id = ? LIMIT 1");
    $s->execute([$branchId]);
    return (bool)$s->fetchColumn();
}

// ============================================================
// Schema migration — runs ONCE, gated by a marker row
// ============================================================
function ensureSchema(PDO $db) {
    try {
        $check = $db->query("SHOW TABLES LIKE 'transactions'");
        if ($check->rowCount() == 0) return;

        $cols = [];
        $stmt = $db->query("SHOW COLUMNS FROM transactions");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[strtolower($c['Field'])] = true;
        }

        $adds = [];

        if (!isset($cols['branch_id'])) {
            $adds[] = "ADD COLUMN branch_id INT NOT NULL DEFAULT 1 AFTER id";
        }
        if (!isset($cols['payment_slip'])) {
            $adds[] = "ADD COLUMN payment_slip LONGTEXT NULL";
        }
        if (!isset($cols['payment_slip_name'])) {
            $adds[] = "ADD COLUMN payment_slip_name VARCHAR(255) NULL";
        }
        if (!isset($cols['payment_slip_type'])) {
            $adds[] = "ADD COLUMN payment_slip_type VARCHAR(100) NULL";
        }
        if (!isset($cols['rejection_reason'])) {
            $adds[] = "ADD COLUMN rejection_reason TEXT NULL";
        }
        if (!isset($cols['rejected_by'])) {
            $adds[] = "ADD COLUMN rejected_by VARCHAR(255) NULL";
        }
        if (!isset($cols['rejected_at'])) {
            $adds[] = "ADD COLUMN rejected_at DATETIME NULL";
        }
        if (!isset($cols['approved_at'])) {
            $adds[] = "ADD COLUMN approved_at DATETIME NULL";
        }
        if (!isset($cols['charge'])) {
            $adds[] = "ADD COLUMN charge DECIMAL(15,2) NOT NULL DEFAULT 0";
        }
        if (!isset($cols['rate'])) {
            $adds[] = "ADD COLUMN rate DECIMAL(10,4) NOT NULL DEFAULT 0";
        }
        if (!isset($cols['net_amount'])) {
            $adds[] = "ADD COLUMN net_amount DECIMAL(15,2) NOT NULL DEFAULT 0";
        }

        if (!empty($adds)) {
            $sql = "ALTER TABLE transactions " . implode(", ", $adds);
            $db->exec($sql);
            error_log("[transactions.php] Schema updated: " . $sql);
        }

        // One-time back-fill. Only runs if the marker setting is absent.
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS app_migrations (
                    name VARCHAR(100) PRIMARY KEY,
                    ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $marker = 'transactions_branch_backfill_v1';
            $check = $db->prepare("SELECT 1 FROM app_migrations WHERE name = ? LIMIT 1");
            $check->execute([$marker]);
            if (!$check->fetchColumn()) {
                // Back-fill any NULL/0 branch_id rows to the member's branch,
                // then to branch 1 for truly orphaned rows.
                $db->exec("
                    UPDATE transactions t
                    JOIN members m ON m.id = t.member_id
                    SET t.branch_id = m.branch_id
                    WHERE (t.branch_id IS NULL OR t.branch_id = 0)
                      AND m.branch_id IS NOT NULL
                ");
                $db->exec("
                    UPDATE transactions
                    SET branch_id = 1
                    WHERE branch_id IS NULL OR branch_id = 0
                ");
                $db->prepare("INSERT INTO app_migrations (name) VALUES (?)")
                   ->execute([$marker]);
                error_log("[transactions.php] Back-filled branch_id on transactions");
            }
        } catch (Exception $e) {
            error_log("[transactions.php] Back-fill skipped: " . $e->getMessage());
        }
    } catch (Exception $e) {
        error_log("[transactions.php] Schema migration skipped: " . $e->getMessage());
    }
}

// ============================================================
// Slip normalizer
// ============================================================
function normalizeSlipDataUrl($slip, $type) {
    if (empty($slip)) return null;
    $slip = preg_replace('/\s+/', '', $slip);
    if (strpos($slip, 'data:') === 0) return $slip;
    $mime = $type ?: 'image/jpeg';
    return 'data:' . $mime . ';base64,' . $slip;
}

// ============================================================
// Path parsing
// ============================================================
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));

$id = null;
foreach ($pathParts as $part) {
    if (ctype_digit($part)) { $id = (int)$part; break; }
}

$action = null;
if (strpos($path, '/approve') !== false) $action = 'approve';
elseif (strpos($path, '/reject') !== false) $action = 'reject';

// ============================================================
// Router
// ============================================================
switch ($method) {
    case 'GET':
        getTransactions($db);
        break;

    case 'POST':
        createTransaction($db);
        break;

    case 'PUT':
        if ($action === 'approve' && $id) {
            approveTransaction($db, $id);
        } elseif ($action === 'reject' && $id) {
            rejectTransaction($db, $id);
        } else {
            jsonOut(['error' => 'Invalid action or missing ID'], 400);
        }
        break;

    case 'DELETE':
        if ($id) deleteTransaction($db, $id);
        else jsonOut(['error' => 'Transaction id required'], 400);
        break;

    default:
        jsonOut(['error' => 'Method not allowed'], 405);
}

// ============================================================
// GET — strict branch scoping, joined with member info
// ============================================================
function getTransactions(PDO $db) {
    $branchId = resolveBranchId();
    if (!branchExists($db, $branchId)) {
        jsonOut(['error' => 'Branch not found'], 404);
    }

    try {
        $status = isset($_GET['status']) ? trim($_GET['status']) : '';
        $type   = isset($_GET['type'])   ? trim($_GET['type'])   : '';
        $limit  = isset($_GET['limit'])  ? max(1, min(1000, (int)$_GET['limit'])) : 200;

        $sql = "SELECT t.*,
                       m.name           AS live_member_name,
                       m.phone          AS live_member_phone,
                       m.account_number AS live_account_number
                FROM transactions t
                LEFT JOIN members m ON m.id = t.member_id
                WHERE t.branch_id = ?";
        $params = [$branchId];

        if ($status !== '') { $sql .= " AND t.status = ?"; $params[] = $status; }
        if ($type   !== '') { $sql .= " AND t.type = ?";   $params[] = $type;   }

        $sql .= " ORDER BY t.id DESC LIMIT " . (int)$limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = array_map(function ($t) {
            return [
                'id' => (int)$t['id'],
                'branch_id' => isset($t['branch_id']) ? (int)$t['branch_id'] : null,
                'memberId' => $t['member_id'] ?? null,
                'memberName' => $t['live_member_name'] ?? $t['member_name'] ?? 'Unknown',
                'memberPhone' => $t['live_member_phone'] ?? '',
                'accountNumber' => $t['live_account_number'] ?? $t['account_number'] ?? 'N/A',
                'type' => $t['type'],
                'amount' => floatval($t['amount']),
                'charge' => floatval($t['charge'] ?? 0),
                'rate' => floatval($t['rate'] ?? 0),
                'net_amount' => floatval($t['net_amount'] ?? $t['amount']),
                'date' => $t['date'],
                'status' => $t['status'] ?? 'pending',
                'description' => $t['description'] ?? '',
                'toMemberId' => $t['to_member_id'] ?? null,
                'toMemberName' => $t['to_member_name'] ?? null,
                'fromMemberName' => $t['from_member_name'] ?? null,
                'fromAccountNumber' => $t['from_account_number'] ?? null,
                'toAccountNumber' => $t['to_account_number'] ?? null,
                'payment_slip' => normalizeSlipDataUrl($t['payment_slip'] ?? null, $t['payment_slip_type'] ?? null),
                'payment_slip_name' => $t['payment_slip_name'] ?? null,
                'payment_slip_type' => $t['payment_slip_type'] ?? null,
                'rejection_reason' => $t['rejection_reason'] ?? null,
                'rejected_by' => $t['rejected_by'] ?? null,
                'rejected_at' => $t['rejected_at'] ?? null,
                'approved_at' => $t['approved_at'] ?? null,
                'created_at' => $t['created_at'] ?? null,
            ];
        }, $rows);

        jsonOut([
            'transactions' => $formatted,
            'branch_id' => $branchId,
            'count' => count($formatted),
        ]);
    } catch (Exception $e) {
        error_log("transactions.php GET error: " . $e->getMessage());
        jsonOut(['error' => 'Failed to fetch transactions'], 500);
    }
}

// ============================================================
// POST — the MEMBER'S branch is authoritative
// ============================================================
function createTransaction(PDO $db) {
    $data = readBody();
    if (!$data) jsonOut(['error' => 'Invalid JSON data'], 400);

    $memberId = (int)($data['memberId'] ?? $data['member_id'] ?? 0);
    $type     = $data['type'] ?? null;
    $amount   = floatval($data['amount'] ?? 0);

    if ($memberId <= 0) jsonOut(['error' => 'Missing required field: memberId'], 400);
    if (!$type)          jsonOut(['error' => 'Missing required field: type'], 400);
    if ($amount <= 0)    jsonOut(['error' => 'Amount must be greater than 0'], 400);

    try {
        // Look up the member — their branch is what we trust
        $m = $db->prepare(
            "SELECT id, name, phone, email, account_number, branch_id
             FROM members WHERE id = ? LIMIT 1"
        );
        $m->execute([$memberId]);
        $member = $m->fetch(PDO::FETCH_ASSOC);
        if (!$member) jsonOut(['error' => 'Member not found'], 404);

        $memberBranchId = (int)($member['branch_id'] ?? 0);
        if ($memberBranchId <= 0) {
            jsonOut(['error' => 'Member has no branch assigned'], 400);
        }

        // If the client explicitly asked for a different branch, reject.
        $reqBranch = resolveBranchId($data, false);
        if ($reqBranch > 0 && $reqBranch !== $memberBranchId) {
            jsonOut([
                'error' => "branch_id mismatch: member belongs to branch {$memberBranchId}",
            ], 400);
        }

        $BRANCH_ID = $memberBranchId;

        // Prefer live member data
        $memberName    = $data['memberName']    ?? $data['member_name']    ?? $member['name']           ?? 'Unknown';
        $accountNumber = $data['accountNumber'] ?? $data['account_number'] ?? $member['account_number'] ?? 'N/A';
        $date        = $data['date']        ?? date('Y-m-d');
        $status      = $data['status']      ?? 'pending';
        $description = $data['description'] ?? '';

        $toMemberId        = $data['toMemberId']        ?? $data['to_member_id']        ?? null;
        $toMemberName      = $data['toMemberName']      ?? $data['to_member_name']      ?? null;
        $fromMemberName    = $data['fromMemberName']    ?? $data['from_member_name']    ?? null;
        $fromAccountNumber = $data['fromAccountNumber'] ?? $data['from_account_number'] ?? null;
        $toAccountNumber   = $data['toAccountNumber']   ?? $data['to_account_number']   ?? null;

        $paymentSlip     = $data['payment_slip']      ?? null;
        $paymentSlipName = $data['payment_slip_name'] ?? null;
        $paymentSlipType = $data['payment_slip_type'] ?? null;
        if (!empty($paymentSlip)) {
            $paymentSlip = preg_replace('/\s+/', '', $paymentSlip);
        }

        if ($type === 'deposit') {
            $chargeInfo = calculateCharge($amount, $db);
            $charge     = $chargeInfo['charge'];
            $rate       = $chargeInfo['rate'];
            $netAmount  = $chargeInfo['net'];
        } else {
            $charge    = 0.00;
            $rate      = 0.0000;
            $netAmount = $amount;
        }

        $sql = "INSERT INTO transactions (
            branch_id,
            member_id, member_name, account_number, type, amount, charge, rate, net_amount,
            date, status, description,
            to_member_id, to_member_name, from_member_name, from_account_number, to_account_number,
            payment_slip, payment_slip_name, payment_slip_type
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $db->prepare($sql);
        $ok = $stmt->execute([
            $BRANCH_ID,
            $memberId, $memberName, $accountNumber, $type,
            $amount, $charge, $rate, $netAmount,
            $date, $status, $description,
            $toMemberId, $toMemberName, $fromMemberName, $fromAccountNumber, $toAccountNumber,
            $paymentSlip, $paymentSlipName, $paymentSlipType,
        ]);

        if (!$ok) jsonOut(['error' => 'Failed to create transaction'], 500);

        $newId = (int)$db->lastInsertId();

        jsonOut([
            'success' => true,
            'message' => 'Transaction created successfully',
            'id' => $newId,
            'branch_id' => $BRANCH_ID,
            'transaction' => array_merge($data, [
                'id' => $newId,
                'branch_id' => $BRANCH_ID,
                'charge' => $charge,
                'rate' => $rate,
                'net_amount' => $netAmount,
            ]),
        ], 201);
    } catch (PDOException $e) {
        error_log("transactions.php POST PDO error: " . $e->getMessage());
        jsonOut(['error' => 'Database error'], 500);
    } catch (Exception $e) {
        error_log("transactions.php POST error: " . $e->getMessage());
        jsonOut(['error' => 'Failed to create transaction'], 500);
    }
}

// ============================================================
// PUT /approve/{id} — locked + branch-guarded
// ============================================================
function approveTransaction(PDO $db, int $id) {
    $branchId = resolveBranchId();
    if (!branchExists($db, $branchId)) {
        jsonOut(['error' => 'Branch not found'], 404);
    }

    try {
        $db->beginTransaction();

        // Lock the row while we approve
        $stmt = $db->prepare(
            "SELECT * FROM transactions
             WHERE id = ? AND branch_id = ?
             FOR UPDATE"
        );
        $stmt->execute([$id, $branchId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transaction) {
            $db->rollBack();
            jsonOut(['error' => 'Transaction not found in this branch'], 404);
        }
        if ($transaction['status'] !== 'pending') {
            $db->rollBack();
            jsonOut([
                'error' => 'Only pending transactions can be approved',
                'current_status' => $transaction['status'],
            ], 409);
        }

        $type      = $transaction['type'];
        $amount    = floatval($transaction['amount']);
        $charge    = floatval($transaction['charge'] ?? 0);
        $netAmount = floatval($transaction['net_amount'] ?? $amount);
        $memberId  = $transaction['member_id'];
        $txBranch  = (int)$transaction['branch_id'];

        $up = $db->prepare(
            "UPDATE transactions
             SET status = 'approved', approved_at = NOW()
             WHERE id = ? AND branch_id = ? AND status = 'pending'"
        );
        $up->execute([$id, $txBranch]);
        if ($up->rowCount() === 0) {
            $db->rollBack();
            jsonOut(['error' => 'Transaction was already processed'], 409);
        }

        $updatedBalance   = 0;
        $recipientBalance = 0;

        if ($type === 'deposit') {
            $s = $db->prepare("UPDATE members SET balance = balance + ? WHERE id = ? AND branch_id = ?");
            $s->execute([$netAmount, $memberId, $txBranch]);

            $s = $db->prepare("SELECT balance FROM members WHERE id = ? AND branch_id = ?");
            $s->execute([$memberId, $txBranch]);
            $updatedBalance = (float)($s->fetchColumn() ?: 0);

        } elseif ($type === 'withdrawal') {
            $s = $db->prepare("UPDATE members SET balance = balance - ? WHERE id = ? AND branch_id = ?");
            $s->execute([$amount, $memberId, $txBranch]);

            $s = $db->prepare("SELECT balance FROM members WHERE id = ? AND branch_id = ?");
            $s->execute([$memberId, $txBranch]);
            $updatedBalance = (float)($s->fetchColumn() ?: 0);

        } elseif ($type === 'transfer') {
            $toMemberId   = $transaction['to_member_id'] ?? null;
            $toMemberName = $transaction['to_member_name'] ?? null;
            $recipientId  = null;

            if ($toMemberId) {
                $s = $db->prepare("SELECT id FROM members WHERE id = ? AND branch_id = ?");
                $s->execute([$toMemberId, $txBranch]);
                $r = $s->fetch();
                if ($r) $recipientId = (int)$r['id'];
            } elseif ($toMemberName) {
                $s = $db->prepare("SELECT id FROM members WHERE name LIKE ? AND branch_id = ?");
                $s->execute(["%$toMemberName%", $txBranch]);
                $r = $s->fetch();
                if ($r) $recipientId = (int)$r['id'];
            }

            if ($recipientId) {
                $db->prepare("UPDATE members SET balance = balance - ? WHERE id = ? AND branch_id = ?")
                   ->execute([$amount, $memberId, $txBranch]);
                $db->prepare("UPDATE members SET balance = balance + ? WHERE id = ? AND branch_id = ?")
                   ->execute([$amount, $recipientId, $txBranch]);

                $s = $db->prepare("SELECT balance FROM members WHERE id = ? AND branch_id = ?");
                $s->execute([$memberId, $txBranch]);
                $updatedBalance = (float)($s->fetchColumn() ?: 0);

                $s = $db->prepare("SELECT balance FROM members WHERE id = ? AND branch_id = ?");
                $s->execute([$recipientId, $txBranch]);
                $recipientBalance = (float)($s->fetchColumn() ?: 0);
            }
        }

        $db->commit();

        jsonOut([
            'success' => true,
            'message' => 'Transaction approved successfully',
            'branch_id' => $txBranch,
            'charge' => $charge,
            'net_amount' => $netAmount,
            'sender_balance' => $updatedBalance,
            'recipient_balance' => $recipientBalance,
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("transactions.php approve error: " . $e->getMessage());
        jsonOut(['error' => 'Failed to approve transaction'], 500);
    }
}

// ============================================================
// PUT /reject/{id}
// ============================================================
function rejectTransaction(PDO $db, int $id) {
    $body = readBody();
    $branchId = resolveBranchId($body);

    $reason = trim(
        $body['reason']
        ?? $body['rejection_reason']
        ?? $body['rejectionReason']
        ?? $body['admin_note']
        ?? ''
    );
    if ($reason === '') jsonOut(['error' => 'Rejection reason is required'], 400);

    $rejectedBy = trim(
        $body['rejected_by']
        ?? $body['rejectedBy']
        ?? $body['admin_name']
        ?? 'Admin'
    );

    try {
        $db->beginTransaction();

        $stmt = $db->prepare(
            "SELECT * FROM transactions
             WHERE id = ? AND branch_id = ? AND status = 'pending'
             FOR UPDATE"
        );
        $stmt->execute([$id, $branchId]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tx) {
            $db->rollBack();
            jsonOut(['error' => 'Transaction not found in this branch or already processed'], 404);
        }

        $db->prepare(
            "UPDATE transactions
             SET status = 'rejected',
                 rejection_reason = ?,
                 rejected_by = ?,
                 rejected_at = NOW()
             WHERE id = ? AND branch_id = ?"
        )->execute([$reason, $rejectedBy, $id, $branchId]);

        $db->commit();

        jsonOut([
            'success' => true,
            'message' => 'Transaction rejected successfully',
            'branch_id' => $branchId,
            'rejection_reason' => $reason,
            'rejected_by' => $rejectedBy,
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("transactions.php reject error: " . $e->getMessage());
        jsonOut(['error' => 'Failed to reject transaction'], 500);
    }
}

// ============================================================
// DELETE /{id}
// ============================================================
function deleteTransaction(PDO $db, int $id) {
    $branchId = resolveBranchId();
    try {
        $stmt = $db->prepare("DELETE FROM transactions WHERE id = ? AND branch_id = ?");
        $stmt->execute([$id, $branchId]);
        if ($stmt->rowCount() === 0) {
            jsonOut(['error' => 'Transaction not found in this branch'], 404);
        }
        jsonOut(['success' => true, 'message' => 'Transaction deleted', 'id' => $id]);
    } catch (Exception $e) {
        error_log("transactions.php delete error: " . $e->getMessage());
        jsonOut(['error' => 'Failed to delete transaction'], 500);
    }
}