<?php
// api/transactions.php - Complete working file
// Branch-aware: deposits, withdrawals, transfers, payment slips, rejection reasons, deposit charges

error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
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
require_once __DIR__ . '/config/charges.php';

$db = getDBConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// ============================================================
// AUTO-MIGRATION: ensure needed columns exist
// ============================================================
ensureSchema($db);

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

$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$pathParts = explode('/', $path);
$id = null;

foreach ($pathParts as $part) {
    if (is_numeric($part)) {
        $id = $part;
        break;
    }
}

$action = null;
if (strpos($path, '/approve') !== false) {
    $action = 'approve';
} elseif (strpos($path, '/reject') !== false) {
    $action = 'reject';
}

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
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action or missing ID']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

// ============================================================
// SCHEMA HELPERS
// ============================================================
function ensureSchema($db) {
    try {
        $check = $db->query("SHOW TABLES LIKE 'transactions'");
        if ($check->rowCount() == 0) {
            return;
        }

        $cols = [];
        $stmt = $db->query("SHOW COLUMNS FROM transactions");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[strtolower($c['Field'])] = true;
        }

        $adds = [];

        // 🆕 Branch
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

        // Back-fill any NULL/0 branch_id rows to branch 1
        try {
            $db->exec("UPDATE transactions SET branch_id = 1 WHERE branch_id IS NULL OR branch_id = 0");
        } catch (Exception $e) { /* silent */ }
    } catch (Exception $e) {
        error_log("[transactions.php] Schema migration skipped: " . $e->getMessage());
    }
}

// ============================================================
// Normalize a stored slip value into a full data URL
// ============================================================
function normalizeSlipDataUrl($slip, $type) {
    if (empty($slip)) return null;
    $slip = preg_replace('/\s+/', '', $slip);
    if (strpos($slip, 'data:') === 0) return $slip;
    $mime = $type ?: 'image/jpeg';
    return 'data:' . $mime . ';base64,' . $slip;
}

// ============================================================
// GET — filtered by branch
// ============================================================
function getTransactions($db) {
    global $BRANCH_ID;
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'transactions'");
        if ($tableCheck->rowCount() == 0) {
            echo json_encode(['transactions' => []]);
            return;
        }

        $stmt = $db->prepare(
            "SELECT * FROM transactions WHERE branch_id = ? ORDER BY id DESC LIMIT 200"
        );
        $stmt->execute([$BRANCH_ID]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = [];
        foreach ($transactions as $t) {
            $formatted[] = [
                'id' => $t['id'],
                'branch_id' => isset($t['branch_id']) ? (int)$t['branch_id'] : null,
                'memberId' => $t['member_id'],
                'memberName' => $t['member_name'] ?? 'Unknown',
                'accountNumber' => $t['account_number'] ?? 'N/A',
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

                'payment_slip' => normalizeSlipDataUrl(
                    $t['payment_slip'] ?? null,
                    $t['payment_slip_type'] ?? null
                ),
                'payment_slip_name' => $t['payment_slip_name'] ?? null,
                'payment_slip_type' => $t['payment_slip_type'] ?? null,

                'rejection_reason' => $t['rejection_reason'] ?? null,
                'rejected_by' => $t['rejected_by'] ?? null,
                'rejected_at' => $t['rejected_at'] ?? null,
                'approved_at' => $t['approved_at'] ?? null,

                'created_at' => $t['created_at'] ?? null
            ];
        }

        echo json_encode(['transactions' => $formatted]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch transactions: ' . $e->getMessage()]);
    }
}

// ============================================================
// POST — create transaction (forces branch)
// ============================================================
function createTransaction($db) {
    global $BRANCH_ID;
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }

        $memberId      = $data['memberId'] ?? $data['member_id'] ?? null;
        $memberName    = $data['memberName'] ?? $data['member_name'] ?? 'Unknown';
        $accountNumber = $data['accountNumber'] ?? $data['account_number'] ?? 'N/A';
        $type          = $data['type'] ?? null;
        $amount        = floatval($data['amount'] ?? 0);
        $date          = $data['date'] ?? date('Y-m-d');
        $status        = $data['status'] ?? 'pending';
        $description   = $data['description'] ?? '';

        $toMemberId        = $data['toMemberId'] ?? $data['to_member_id'] ?? null;
        $toMemberName      = $data['toMemberName'] ?? $data['to_member_name'] ?? null;
        $fromMemberName    = $data['fromMemberName'] ?? $data['from_member_name'] ?? null;
        $fromAccountNumber = $data['fromAccountNumber'] ?? $data['from_account_number'] ?? null;
        $toAccountNumber   = $data['toAccountNumber'] ?? $data['to_account_number'] ?? null;

        $paymentSlip     = $data['payment_slip'] ?? null;
        $paymentSlipName = $data['payment_slip_name'] ?? null;
        $paymentSlipType = $data['payment_slip_type'] ?? null;

        if (!empty($paymentSlip)) {
            $paymentSlip = preg_replace('/\s+/', '', $paymentSlip);
        }

        if (!$memberId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required field: memberId']);
            return;
        }
        if (!$type) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required field: type']);
            return;
        }
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Amount must be greater than 0']);
            return;
        }

        // CHARGE — deposits only
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

        // 🆕 branch_id column added to INSERT
        $sql = "INSERT INTO transactions (
            branch_id,
            member_id, member_name, account_number, type, amount, charge, rate, net_amount,
            date, status, description,
            to_member_id, to_member_name, from_member_name, from_account_number, to_account_number,
            payment_slip, payment_slip_name, payment_slip_type
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $db->prepare($sql);
        $result = $stmt->execute([
            $BRANCH_ID,
            $memberId,
            $memberName,
            $accountNumber,
            $type,
            $amount,
            $charge,
            $rate,
            $netAmount,
            $date,
            $status,
            $description,
            $toMemberId,
            $toMemberName,
            $fromMemberName,
            $fromAccountNumber,
            $toAccountNumber,
            $paymentSlip,
            $paymentSlipName,
            $paymentSlipType
        ]);

        if ($result) {
            $id = $db->lastInsertId();
            echo json_encode([
                'success' => true,
                'message' => 'Transaction created successfully',
                'id' => $id,
                'transaction' => array_merge($data, [
                    'id' => $id,
                    'branch_id' => $BRANCH_ID,
                    'charge' => $charge,
                    'rate' => $rate,
                    'net_amount' => $netAmount,
                ])
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create transaction']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}

// ============================================================
// PUT /approve/{id} — branch-guarded
// ============================================================
function approveTransaction($db, $id) {
    global $BRANCH_ID;
    try {
        $db->beginTransaction();

        // Only approve a transaction that belongs to the current branch
        $stmt = $db->prepare(
            "SELECT * FROM transactions WHERE id = ? AND branch_id = ? AND status = 'pending'"
        );
        $stmt->execute([$id, $BRANCH_ID]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transaction) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found in this branch or already processed']);
            return;
        }

        $type       = $transaction['type'];
        $amount     = floatval($transaction['amount']);
        $charge     = floatval($transaction['charge'] ?? 0);
        $netAmount  = floatval($transaction['net_amount'] ?? $amount);
        $memberId   = $transaction['member_id'];

        $stmt = $db->prepare("UPDATE transactions SET status = 'approved', approved_at = NOW() WHERE id = ? AND branch_id = ?");
        $stmt->execute([$id, $BRANCH_ID]);

        $updatedBalance = 0;
        $recipientBalance = 0;

        if ($type === 'deposit') {
            $stmt = $db->prepare("UPDATE members SET balance = balance + ? WHERE id = ? AND branch_id = ?");
            $stmt->execute([$netAmount, $memberId, $BRANCH_ID]);

            $stmt = $db->prepare("SELECT balance FROM members WHERE id = ? AND branch_id = ?");
            $stmt->execute([$memberId, $BRANCH_ID]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            $updatedBalance = $member['balance'] ?? 0;

        } elseif ($type === 'withdrawal') {
            $stmt = $db->prepare("UPDATE members SET balance = balance - ? WHERE id = ? AND branch_id = ?");
            $stmt->execute([$amount, $memberId, $BRANCH_ID]);

            $stmt = $db->prepare("SELECT balance FROM members WHERE id = ? AND branch_id = ?");
            $stmt->execute([$memberId, $BRANCH_ID]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            $updatedBalance = $member['balance'] ?? 0;

        } elseif ($type === 'transfer') {
            $toMemberId   = $transaction['to_member_id'] ?? null;
            $toMemberName = $transaction['to_member_name'] ?? null;
            $recipientId  = null;

            if ($toMemberId) {
                $stmt = $db->prepare("SELECT id FROM members WHERE id = ? AND branch_id = ?");
                $stmt->execute([$toMemberId, $BRANCH_ID]);
                $recipient = $stmt->fetch();
                if ($recipient) $recipientId = $recipient['id'];
            } elseif ($toMemberName) {
                $stmt = $db->prepare("SELECT id FROM members WHERE name LIKE ? AND branch_id = ?");
                $stmt->execute(["%$toMemberName%", $BRANCH_ID]);
                $recipient = $stmt->fetch();
                if ($recipient) $recipientId = $recipient['id'];
            }

            if ($recipientId) {
                $stmt = $db->prepare("UPDATE members SET balance = balance - ? WHERE id = ? AND branch_id = ?");
                $stmt->execute([$amount, $memberId, $BRANCH_ID]);

                $stmt = $db->prepare("UPDATE members SET balance = balance + ? WHERE id = ? AND branch_id = ?");
                $stmt->execute([$amount, $recipientId, $BRANCH_ID]);

                $stmt = $db->prepare("SELECT balance FROM members WHERE id = ? AND branch_id = ?");
                $stmt->execute([$memberId, $BRANCH_ID]);
                $member = $stmt->fetch(PDO::FETCH_ASSOC);
                $updatedBalance = $member['balance'] ?? 0;

                $stmt = $db->prepare("SELECT balance FROM members WHERE id = ? AND branch_id = ?");
                $stmt->execute([$recipientId, $BRANCH_ID]);
                $rm = $stmt->fetch(PDO::FETCH_ASSOC);
                $recipientBalance = $rm['balance'] ?? 0;
            }
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Transaction approved successfully',
            'charge' => $charge,
            'net_amount' => $netAmount,
            'sender_balance' => $updatedBalance,
            'recipient_balance' => $recipientBalance
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to approve transaction: ' . $e->getMessage()]);
    }
}

// ============================================================
// PUT /reject/{id} — branch-guarded
// ============================================================
function rejectTransaction($db, $id) {
    global $BRANCH_ID;
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true) ?: [];

        $reason = $data['reason']
               ?? $data['rejection_reason']
               ?? $data['rejectionReason']
               ?? $data['admin_note']
               ?? null;

        $rejectedBy = $data['rejected_by']
                   ?? $data['rejectedBy']
                   ?? $data['admin_name']
                   ?? $data['approved_by']
                   ?? 'Admin';

        $stmt = $db->prepare(
            "SELECT * FROM transactions WHERE id = ? AND branch_id = ? AND status = 'pending'"
        );
        $stmt->execute([$id, $BRANCH_ID]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transaction) {
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found in this branch or already processed']);
            return;
        }

        $stmt = $db->prepare(
            "UPDATE transactions
             SET status = 'rejected',
                 rejection_reason = ?,
                 rejected_by = ?,
                 rejected_at = NOW()
             WHERE id = ? AND branch_id = ?"
        );
        $stmt->execute([$reason, $rejectedBy, $id, $BRANCH_ID]);

        echo json_encode([
            'success' => true,
            'message' => 'Transaction rejected successfully',
            'rejection_reason' => $reason,
            'rejected_by' => $rejectedBy
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to reject transaction: ' . $e->getMessage()]);
    }
}
?>