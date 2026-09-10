<?php
// api/transactions.php - Complete working file with configurable deposit charges

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
    $id = null;
    foreach ($pathParts as $part) {
        if (is_numeric($part)) {
            $id = $part;
            break;
        }
    }
} elseif (strpos($path, '/reject') !== false) {
    $action = 'reject';
    $id = null;
    foreach ($pathParts as $part) {
        if (is_numeric($part)) {
            $id = $part;
            break;
        }
    }
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

function getTransactions($db) {
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'transactions'");
        if ($tableCheck->rowCount() == 0) {
            echo json_encode(['transactions' => []]);
            return;
        }

        $stmt = $db->query("SELECT * FROM transactions ORDER BY id DESC LIMIT 100");
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = [];
        foreach ($transactions as $t) {
            $formatted[] = [
                'id' => $t['id'],
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
                'created_at' => $t['created_at'] ?? null
            ];
        }

        echo json_encode(['transactions' => $formatted]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch transactions: ' . $e->getMessage()]);
    }
}

function createTransaction($db) {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }

        error_log("Creating transaction with data: " . print_r($data, true));

        $memberId = $data['memberId'] ?? $data['member_id'] ?? null;
        $memberName = $data['memberName'] ?? $data['member_name'] ?? 'Unknown';
        $accountNumber = $data['accountNumber'] ?? $data['account_number'] ?? 'N/A';
        $type = $data['type'] ?? null;
        $amount = floatval($data['amount'] ?? 0);
        $date = $data['date'] ?? date('Y-m-d');
        $status = $data['status'] ?? 'pending';
        $description = $data['description'] ?? '';

        $toMemberId = $data['toMemberId'] ?? $data['to_member_id'] ?? null;
        $toMemberName = $data['toMemberName'] ?? $data['to_member_name'] ?? null;
        $fromMemberName = $data['fromMemberName'] ?? $data['from_member_name'] ?? null;
        $fromAccountNumber = $data['fromAccountNumber'] ?? $data['from_account_number'] ?? null;
        $toAccountNumber = $data['toAccountNumber'] ?? $data['to_account_number'] ?? null;

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

        // ============================================================
        // CHARGE CALCULATION — DEPOSITS ONLY
        // Reads rates from the `settings` table (charges key)
        // ============================================================
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

        error_log("Type=$type Amount=$amount → charge=$charge rate=$rate net=$netAmount");

        $sql = "INSERT INTO transactions (
            member_id, member_name, account_number, type, amount, charge, rate, net_amount,
            date, status, description,
            to_member_id, to_member_name, from_member_name, from_account_number, to_account_number
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $db->prepare($sql);
        $result = $stmt->execute([
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
            $toAccountNumber
        ]);

        if ($result) {
            $id = $db->lastInsertId();
            $data['id'] = $id;
            $data['charge'] = $charge;
            $data['rate'] = $rate;
            $data['net_amount'] = $netAmount;

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Transaction created successfully',
                'transaction' => $data
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

function approveTransaction($db, $id) {
    try {
        $db->beginTransaction();

        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ? AND status = 'pending'");
        $stmt->execute([$id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transaction) {
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found or already processed']);
            return;
        }

        $type = $transaction['type'];
        $amount = floatval($transaction['amount']);
        $charge = floatval($transaction['charge'] ?? 0);
        $netAmount = floatval($transaction['net_amount'] ?? $amount);
        $memberId = $transaction['member_id'];

        $stmt = $db->prepare("UPDATE transactions SET status = 'approved' WHERE id = ?");
        $stmt->execute([$id]);

        $updatedBalance = 0;
        $recipientBalance = 0;

        if ($type === 'deposit') {
            // Credit NET (amount − charge)
            $stmt = $db->prepare("UPDATE members SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$netAmount, $memberId]);
            error_log("Deposit approved: credited NET $netAmount (amount $amount, charge $charge) to member $memberId");

            $stmt = $db->prepare("SELECT balance FROM members WHERE id = ?");
            $stmt->execute([$memberId]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            $updatedBalance = $member['balance'];

        } elseif ($type === 'withdrawal') {
            $stmt = $db->prepare("UPDATE members SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$amount, $memberId]);
            error_log("Withdrawal approved: debited $amount from member $memberId");

            $stmt = $db->prepare("SELECT balance FROM members WHERE id = ?");
            $stmt->execute([$memberId]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            $updatedBalance = $member['balance'];

        } elseif ($type === 'transfer') {
            $toMemberId = $transaction['to_member_id'] ?? null;
            $toMemberName = $transaction['to_member_name'] ?? null;

            $recipientId = null;

            if ($toMemberId) {
                $stmt = $db->prepare("SELECT id FROM members WHERE id = ?");
                $stmt->execute([$toMemberId]);
                $recipient = $stmt->fetch();
                if ($recipient) {
                    $recipientId = $recipient['id'];
                }
            } elseif ($toMemberName) {
                $stmt = $db->prepare("SELECT id FROM members WHERE name LIKE ?");
                $stmt->execute(["%$toMemberName%"]);
                $recipient = $stmt->fetch();
                if ($recipient) {
                    $recipientId = $recipient['id'];
                }
            }

            if ($recipientId) {
                $stmt = $db->prepare("UPDATE members SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$amount, $memberId]);

                $stmt = $db->prepare("UPDATE members SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$amount, $recipientId]);

                $stmt = $db->prepare("SELECT balance FROM members WHERE id = ?");
                $stmt->execute([$memberId]);
                $member = $stmt->fetch(PDO::FETCH_ASSOC);
                $updatedBalance = $member['balance'];

                $stmt = $db->prepare("SELECT balance FROM members WHERE id = ?");
                $stmt->execute([$recipientId]);
                $recipientMember = $stmt->fetch(PDO::FETCH_ASSOC);
                $recipientBalance = $recipientMember['balance'];
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
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to approve transaction: ' . $e->getMessage()]);
    }
}

function rejectTransaction($db, $id) {
    try {
        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ? AND status = 'pending'");
        $stmt->execute([$id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transaction) {
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found or already processed']);
            return;
        }

        $stmt = $db->prepare("UPDATE transactions SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode([
            'success' => true,
            'message' => 'Transaction rejected successfully'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to reject transaction: ' . $e->getMessage()]);
    }
}
?>