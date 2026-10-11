<?php
// api/loans.php — branch-aware
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Use the config file
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
// AUTO-MIGRATION: ensure branch_id exists on the loans table
// ============================================================
try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'loans'");
    if ($tableCheck->rowCount() > 0) {
        $cols = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM loans");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[strtolower($c['Field'])] = true;
        }
        if (!isset($cols['branch_id'])) {
            $pdo->exec("ALTER TABLE loans ADD COLUMN branch_id INT NOT NULL DEFAULT 1 AFTER id");
        }
        // Back-fill any orphans
        $pdo->exec("UPDATE loans SET branch_id = 1 WHERE branch_id IS NULL OR branch_id = 0");
    }
} catch (Exception $e) {
    // Silent — the INSERT/SELECT below will fail loudly if the column isn't there
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$response = [];

// Helper function to convert days to readable format
function getDaysToMonthsDisplay($days) {
    if ($days < 30) {
        return $days . " day" . ($days !== 1 ? 's' : '');
    }
    $months = floor($days / 30);
    $remainingDays = $days % 30;
    if ($remainingDays === 0) {
        return $months . " month" . ($months !== 1 ? 's' : '');
    }
    return $months . " month" . ($months !== 1 ? 's' : '') . ", " . $remainingDays . " day" . ($remainingDays !== 1 ? 's' : '');
}

// Handle different request methods
switch($method) {
    case 'GET':
        // Get loans for a specific user
        if (isset($_GET['user_id'])) {
            $user_id = intval($_GET['user_id']);

            try {
                $stmt = $pdo->prepare(
                    "SELECT * FROM loans WHERE user_id = ? AND branch_id = ? ORDER BY request_date DESC"
                );
                $stmt->execute([$user_id, $BRANCH_ID]);
                $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $response = ["loans" => $loans];
            } catch(PDOException $e) {
                $response = ["error" => "Failed to fetch loans: " . $e->getMessage()];
            }
        } else {
            // Get all loans (admin) — scoped to branch
            try {
                $stmt = $pdo->prepare(
                    "SELECT l.*, m.name as member_name, m.account_number as account_number
                     FROM loans l
                     LEFT JOIN members m ON l.user_id = m.id
                     WHERE l.branch_id = ?
                     ORDER BY l.request_date DESC"
                );
                $stmt->execute([$BRANCH_ID]);
                $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $response = ["loans" => $loans];
            } catch(PDOException $e) {
                $response = ["error" => "Failed to fetch all loans: " . $e->getMessage()];
            }
        }
        break;

    case 'POST':
        // Create new loan request
        $user_id = isset($input['user_id']) ? intval($input['user_id']) : 0;
        $amount = isset($input['amount']) ? floatval($input['amount']) : 0;
        $interest = isset($input['interest']) ? floatval($input['interest']) : 0;
        $total_payable = isset($input['total_payable']) ? floatval($input['total_payable']) : 0;
        $duration_months = isset($input['duration_months']) ? intval($input['duration_months']) : 6;
        $monthly_payment = isset($input['monthly_payment']) ? floatval($input['monthly_payment']) : 0;
        $status = isset($input['status']) ? $input['status'] : 'pending';
        $request_date = isset($input['request_date']) ? $input['request_date'] : date('Y-m-d H:i:s');

        if (!$user_id || !$amount || !$total_payable) {
            echo json_encode(["error" => "Missing required fields"]);
            exit();
        }

        // Check if user exists in the current branch and get their savings
        try {
            // Check what columns exist in members table
            $columns = $pdo->query("SHOW COLUMNS FROM members")->fetchAll(PDO::FETCH_COLUMN);

            $selectFields = "id, name, balance";
            if (in_array('created_at', $columns)) {
                $selectFields .= ", created_at";
            } elseif (in_array('join_date', $columns)) {
                $selectFields .= ", join_date as created_at";
            }

            $stmt = $pdo->prepare(
                "SELECT $selectFields FROM members WHERE id = ? AND branch_id = ?"
            );
            $stmt->execute([$user_id, $BRANCH_ID]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$member) {
                echo json_encode(["error" => "User not found in this branch"]);
                exit();
            }

            // Check membership duration if we have a date
            $days_active = 999; // Default: eligible
            if (isset($member['created_at'])) {
                $join_date = new DateTime($member['created_at']);
                $today = new DateTime();
                $diff = $today->diff($join_date);
                $days_active = $diff->days;
            }

            // Get loan settings from database
            $settingsStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'loan'");
            $settingsStmt->execute();
            $settingsRow = $settingsStmt->fetch(PDO::FETCH_ASSOC);

            $loanSettings = [];
            $minMembershipDays = 180;
            $maxLoansPerMember = 3;
            $loanCooldownDays = 30;

            if ($settingsRow) {
                $loanSettings = json_decode($settingsRow['setting_value'], true);
                $minMembershipDays = $loanSettings['min_membership_days'] ?? 180;
                $maxLoansPerMember = $loanSettings['max_loans_per_member'] ?? 3;
                $loanCooldownDays = $loanSettings['loan_cooldown_days'] ?? 30;
            }

            // Check eligibility (membership days)
            if ($days_active < $minMembershipDays) {
                $monthsNeeded = ceil($minMembershipDays / 30);
                $currentMonths = floor($days_active / 30);
                echo json_encode([
                    "error" => "Member must be active for at least " . $monthsNeeded . " months. Current: " . $currentMonths . " months (" . $days_active . " days)"
                ]);
                exit();
            }

            // ============================================================
            // LOAN LIMIT CHECKS — scoped to branch
            // ============================================================

            // Check 1: Count total loans for this member in this branch
            $countStmt = $pdo->prepare(
                "SELECT COUNT(*) as total_loans FROM loans WHERE user_id = ? AND branch_id = ?"
            );
            $countStmt->execute([$user_id, $BRANCH_ID]);
            $loanCount = $countStmt->fetch(PDO::FETCH_ASSOC);
            $totalLoans = $loanCount['total_loans'] ?? 0;

            if ($totalLoans >= $maxLoansPerMember) {
                echo json_encode([
                    "error" => "You have reached the maximum loan limit of " . $maxLoansPerMember . " loans. Please complete your existing loans before applying for a new one."
                ]);
                exit();
            }

            // Check 2: Count active loans in this branch
            $activeStmt = $pdo->prepare(
                "SELECT COUNT(*) as active_loans FROM loans WHERE user_id = ? AND branch_id = ? AND status IN ('pending', 'approved', 'active')"
            );
            $activeStmt->execute([$user_id, $BRANCH_ID]);
            $activeCount = $activeStmt->fetch(PDO::FETCH_ASSOC);
            $activeLoans = $activeCount['active_loans'] ?? 0;

            if ($activeLoans > 0) {
                echo json_encode([
                    "error" => "You have " . $activeLoans . " active loan(s). Please complete them before requesting a new loan."
                ]);
                exit();
            }

            // Check 3: Cooldown period (if loan_cooldown_days > 0) — scoped to branch
            if ($loanCooldownDays > 0) {
                $cooldownStmt = $pdo->prepare("
                    SELECT approval_date, status
                    FROM loans
                    WHERE user_id = ? AND branch_id = ? AND status IN ('completed', 'approved')
                    ORDER BY approval_date DESC
                    LIMIT 1
                ");
                $cooldownStmt->execute([$user_id, $BRANCH_ID]);
                $lastLoan = $cooldownStmt->fetch(PDO::FETCH_ASSOC);

                if ($lastLoan && isset($lastLoan['approval_date'])) {
                    $lastApproval = new DateTime($lastLoan['approval_date']);
                    $today = new DateTime();
                    $diff = $today->diff($lastApproval);
                    $daysSinceLastLoan = $diff->days;

                    if ($daysSinceLastLoan < $loanCooldownDays) {
                        $remainingDays = $loanCooldownDays - $daysSinceLastLoan;
                        echo json_encode([
                            "error" => "You must wait " . getDaysToMonthsDisplay($remainingDays) . " before requesting another loan. Cooldown period: " . getDaysToMonthsDisplay($loanCooldownDays)
                        ]);
                        exit();
                    }
                }
            }

            // ============================================================
            // END OF LOAN LIMIT CHECKS
            // ============================================================

            // Check if amount exceeds 50% of savings/balance
            $savings = floatval($member['balance'] ?: 0);
            $max_borrow = $savings * 0.5;

            if ($amount > $max_borrow) {
                echo json_encode(["error" => "Cannot borrow more than 50% of savings. Maximum: ₦" . number_format($max_borrow, 2)]);
                exit();
            }

            // Ensure loans table exists
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'loans'");
            if ($tableCheck->rowCount() == 0) {
                $pdo->exec("
                    CREATE TABLE loans (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        branch_id INT NOT NULL DEFAULT 1,
                        user_id INT NOT NULL,
                        amount DECIMAL(15,2) NOT NULL,
                        interest DECIMAL(15,2) DEFAULT 0,
                        total_payable DECIMAL(15,2) NOT NULL,
                        duration_months INT DEFAULT 6,
                        monthly_payment DECIMAL(15,2) DEFAULT 0,
                        status VARCHAR(50) DEFAULT 'pending',
                        request_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                        approval_date DATETIME,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES members(id) ON DELETE CASCADE
                    )
                ");
            }

            // Insert loan with branch_id
            $stmt = $pdo->prepare(
                "INSERT INTO loans (branch_id, user_id, amount, interest, total_payable, duration_months, monthly_payment, status, request_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $result = $stmt->execute([
                $BRANCH_ID,
                $user_id,
                $amount,
                $interest,
                $total_payable,
                $duration_months,
                $monthly_payment,
                $status,
                $request_date
            ]);

            if ($result) {
                $loan_id = $pdo->lastInsertId();
                $response = [
                    "success" => true,
                    "message" => "Loan request submitted successfully. Waiting for admin approval.",
                    "loan_id" => $loan_id,
                    "status" => "pending",
                    "branch_id" => $BRANCH_ID,
                    "loan_limits" => [
                        "total_loans" => $totalLoans + 1,
                        "max_allowed" => $maxLoansPerMember,
                        "remaining" => $maxLoansPerMember - ($totalLoans + 1)
                    ]
                ];
            } else {
                $response = ["error" => "Failed to submit loan request"];
            }
        } catch(PDOException $e) {
            $response = ["error" => "Database error: " . $e->getMessage()];
        }
        break;

    case 'PUT':
        // Update loan status (admin action) — scoped to branch
        $loan_id = isset($input['id']) ? intval($input['id']) : 0;
        $new_status = isset($input['status']) ? $input['status'] : null;

        if (!$loan_id || !$new_status) {
            $response = ["error" => "Loan ID and status are required"];
            break;
        }

        try {
            // Only fetch loans that belong to the current branch
            $stmt = $pdo->prepare("SELECT * FROM loans WHERE id = ? AND branch_id = ?");
            $stmt->execute([$loan_id, $BRANCH_ID]);
            $loan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$loan) {
                $response = ["error" => "Loan not found in this branch"];
                break;
            }

            // If approving, credit the member's balance
            if ($new_status == 'approved' && $loan['status'] != 'approved') {
                $stmt = $pdo->prepare(
                    "UPDATE members SET balance = balance + ? WHERE id = ? AND branch_id = ?"
                );
                $stmt->execute([$loan['amount'], $loan['user_id'], $BRANCH_ID]);
            }

            // Update loan status
            $stmt = $pdo->prepare(
                "UPDATE loans SET status = ?, updated_at = NOW(), approval_date = NOW() WHERE id = ? AND branch_id = ?"
            );
            $result = $stmt->execute([$new_status, $loan_id, $BRANCH_ID]);

            if ($result) {
                $response = ["success" => true, "message" => "Loan status updated successfully"];
            } else {
                $response = ["error" => "Failed to update loan status"];
            }
        } catch(PDOException $e) {
            $response = ["error" => "Database error: " . $e->getMessage()];
        }
        break;

    case 'DELETE':
        // Delete loan (admin action) — scoped to branch
        $loan_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        if (!$loan_id) {
            $response = ["error" => "Loan ID is required"];
            break;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM loans WHERE id = ? AND branch_id = ?");
            $result = $stmt->execute([$loan_id, $BRANCH_ID]);

            if ($result && $stmt->rowCount() > 0) {
                $response = ["success" => true, "message" => "Loan deleted successfully"];
            } else {
                $response = ["error" => "Loan not found in this branch"];
            }
        } catch(PDOException $e) {
            $response = ["error" => "Database error: " . $e->getMessage()];
        }
        break;

    default:
        $response = ["error" => "Method not allowed"];
        break;
}

echo json_encode($response);
?>