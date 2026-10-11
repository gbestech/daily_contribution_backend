<?php
// api/loan_payments.php — branch-aware
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

if (!$pdo) {
    echo json_encode(["error" => "Database connection failed"]);
    exit();
}

// ============================================================
// BRANCH CONTEXT
// ============================================================
$requestedBranchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

if ($requestedBranchId <= 0) {
    try {
        $row = $pdo->query("SELECT id FROM branches WHERE status = 'active' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $requestedBranchId = $row ? (int)$row['id'] : 1;
    } catch (Exception $e) {
        $requestedBranchId = 1;
    }
}

$BRANCH_ID = $requestedBranchId;

// ============================================================
// AUTO-MIGRATION: ensure branch_id exists on loan_payments
// ============================================================
try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'loan_payments'");
    if ($tableCheck->rowCount() > 0) {
        $cols = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM loan_payments");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[strtolower($c['Field'])] = true;
        }
        if (!isset($cols['branch_id'])) {
            $pdo->exec("ALTER TABLE loan_payments ADD COLUMN branch_id INT NOT NULL DEFAULT 1 AFTER id");
        }
        $pdo->exec("UPDATE loan_payments SET branch_id = 1 WHERE branch_id IS NULL OR branch_id = 0");
    }
} catch (Exception $e) {
    // silent
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$response = [];

// Log the request
error_log("=== LOAN PAYMENT REQUEST ===");
error_log("Method: " . $method);
error_log("Branch: " . $BRANCH_ID);
error_log("Input: " . print_r($input, true));

switch($method) {
    case 'GET':
        if (isset($_GET['loan_id'])) {
            $loan_id = intval($_GET['loan_id']);
            $stmt = $pdo->prepare(
                "SELECT * FROM loan_payments WHERE loan_id = ? AND branch_id = ? ORDER BY payment_date DESC"
            );
            $stmt->execute([$loan_id, $BRANCH_ID]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response = ["payments" => $payments];
        } elseif (isset($_GET['user_id'])) {
            $user_id = intval($_GET['user_id']);
            $stmt = $pdo->prepare("
                SELECT lp.*, l.amount as loan_amount, l.total_payable
                FROM loan_payments lp
                JOIN loans l ON lp.loan_id = l.id
                WHERE l.user_id = ? AND lp.branch_id = ?
                ORDER BY lp.payment_date DESC
            ");
            $stmt->execute([$user_id, $BRANCH_ID]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response = ["payments" => $payments];
        } else {
            $stmt = $pdo->prepare("
                SELECT lp.*, m.name as member_name
                FROM loan_payments lp
                JOIN members m ON lp.user_id = m.id
                WHERE lp.branch_id = ?
                ORDER BY lp.payment_date DESC
            ");
            $stmt->execute([$BRANCH_ID]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response = ["payments" => $payments];
        }
        break;

    case 'POST':
        // Make a payment
        $loan_id = isset($input['loan_id']) ? intval($input['loan_id']) : 0;
        $user_id = isset($input['user_id']) ? intval($input['user_id']) : 0;
        $amount = isset($input['amount']) ? floatval($input['amount']) : 0;
        $payment_date = isset($input['payment_date']) ? $input['payment_date'] : date('Y-m-d H:i:s');
        $note = isset($input['note']) ? $input['note'] : '';

        error_log("Payment Request - Loan ID: $loan_id, User ID: $user_id, Amount: $amount");

        if (!$loan_id || !$user_id || $amount <= 0) {
            echo json_encode(["error" => "Missing required fields"]);
            exit();
        }

        try {
            $pdo->beginTransaction();

            // Only fetch loans within the current branch
            $stmt = $pdo->prepare("SELECT * FROM loans WHERE id = ? AND user_id = ? AND branch_id = ?");
            $stmt->execute([$loan_id, $user_id, $BRANCH_ID]);
            $loan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$loan) {
                $pdo->rollBack();
                echo json_encode(["error" => "Loan not found in this branch"]);
                exit();
            }

            error_log("Loan found: " . print_r($loan, true));

            $current_total_paid = floatval($loan['total_paid'] ?? 0);
            $total_payable = floatval($loan['total_payable']);
            $remaining = $total_payable - $current_total_paid;

            error_log("Current total paid: $current_total_paid");
            error_log("Total payable: $total_payable");
            error_log("Remaining: $remaining");
            error_log("New payment amount: $amount");

            if ($remaining <= 0.01) {
                $pdo->rollBack();
                echo json_encode(["error" => "This loan is already fully paid. Remaining: ₦0.00"]);
                exit();
            }

            if ($amount > $remaining + 0.01) {
                $pdo->rollBack();
                echo json_encode(["error" => "Amount exceeds remaining balance: ₦" . number_format($remaining, 2)]);
                exit();
            }

            if ($loan['status'] !== 'approved' && $loan['status'] !== 'active') {
                $pdo->rollBack();
                echo json_encode(["error" => "Loan is not approved yet. Status: " . $loan['status']]);
                exit();
            }

            // Deduct payment from member's balance (branch-guarded)
            $stmt = $pdo->prepare(
                "UPDATE members SET balance = balance - ? WHERE id = ? AND branch_id = ?"
            );
            $stmt->execute([$amount, $user_id, $BRANCH_ID]);
            $rowsAffected = $stmt->rowCount();
            error_log("Balance update affected $rowsAffected rows");

            // Insert payment record (with branch_id)
            $stmt = $pdo->prepare("
                INSERT INTO loan_payments (branch_id, loan_id, user_id, amount, payment_date, note, status)
                VALUES (?, ?, ?, ?, ?, ?, 'completed')
            ");
            $stmt->execute([$BRANCH_ID, $loan_id, $user_id, $amount, $payment_date, $note]);
            $payment_id = $pdo->lastInsertId();
            error_log("Payment record inserted with ID: $payment_id");

            // Update total paid (branch-guarded)
            $new_total_paid = $current_total_paid + $amount;
            $stmt = $pdo->prepare("UPDATE loans SET total_paid = ? WHERE id = ? AND branch_id = ?");
            $stmt->execute([$new_total_paid, $loan_id, $BRANCH_ID]);
            error_log("Loan total_paid updated from $current_total_paid to $new_total_paid");

            $new_remaining = $total_payable - $new_total_paid;
            error_log("New remaining: $new_remaining");

            if ($new_remaining <= 0.01) {
                $stmt = $pdo->prepare("
                    UPDATE loans
                    SET status = 'completed',
                        updated_at = NOW()
                    WHERE id = ? AND branch_id = ?
                ");
                $stmt->execute([$loan_id, $BRANCH_ID]);
                $status = 'completed';
                error_log("Loan status updated to completed");
            } else {
                $status = $loan['status'];
            }

            $pdo->commit();

            // Get updated balance (branch-guarded)
            $stmt = $pdo->prepare("SELECT balance FROM members WHERE id = ? AND branch_id = ?");
            $stmt->execute([$user_id, $BRANCH_ID]);
            $balance = $stmt->fetch(PDO::FETCH_ASSOC);

            error_log("New balance: " . ($balance['balance'] ?? 0));

            $response = [
                "success" => true,
                "message" => "Payment successful",
                "payment_id" => $payment_id,
                "amount_paid" => $amount,
                "total_paid" => $new_total_paid,
                "remaining" => $new_remaining,
                "status" => $status,
                "new_balance" => $balance['balance'] ?? 0,
                "branch_id" => $BRANCH_ID
            ];

        } catch(PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("PDO Error: " . $e->getMessage());
            $response = ["error" => "Database error: " . $e->getMessage()];
        }
        break;

    default:
        $response = ["error" => "Method not allowed"];
        break;
}

echo json_encode($response);
?>