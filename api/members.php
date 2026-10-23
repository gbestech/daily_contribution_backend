<?php
// api/members.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

while (ob_get_level()) {
    ob_end_clean();
}

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
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// ============================================================
// REQUEST CONTEXT
// ============================================================
$method  = $_SERVER['REQUEST_METHOD'];
$reqRole = strtolower(trim($_SERVER['HTTP_X_USER_ROLE'] ?? ''));
$isAdmin = in_array($reqRole, ['admin', 'administrator'], true);
$isManager = in_array($reqRole, ['manager', 'branch_manager'], true);

$FETCH_ALL = isset($_GET['all']) && $_GET['all'] === '1';
if ($FETCH_ALL && !$isAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required for ?all=1']);
    exit();
}

// ============================================================
// BRANCH RESOLUTION — STRICT. No silent fallback.
// ============================================================
$requestedBranchId = 0;

if (isset($_GET['branch_id']) && (int)$_GET['branch_id'] > 0) {
    $requestedBranchId = (int)$_GET['branch_id'];
}

if ($requestedBranchId <= 0 && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $rawBody = file_get_contents('php://input');
    $bodyData = json_decode($rawBody, true);
    if (is_array($bodyData) && !empty($bodyData['branch_id']) && (int)$bodyData['branch_id'] > 0) {
        $requestedBranchId = (int)$bodyData['branch_id'];
    }
}

if ($requestedBranchId <= 0 && !empty($_SERVER['HTTP_X_USER_BRANCH_ID'])) {
    $hdr = (int)$_SERVER['HTTP_X_USER_BRANCH_ID'];
    if ($hdr > 0) $requestedBranchId = $hdr;
}

if (!$FETCH_ALL && $requestedBranchId <= 0) {
    http_response_code(400);
    echo json_encode([
        'error' => 'branch_id is required. Pass ?branch_id=N or X-User-Branch-Id.',
    ]);
    exit();
}

if (!$FETCH_ALL) {
    $b = $db->prepare("SELECT id FROM branches WHERE id = ? LIMIT 1");
    $b->execute([$requestedBranchId]);
    if (!$b->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Branch not found']);
        exit();
    }
}

$BRANCH_ID = $requestedBranchId;

// ============================================================
// PATH / ID
// ============================================================
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$pathParts = explode('/', $path);
$id = null;
foreach ($pathParts as $part) {
    if (is_numeric($part)) { $id = (int)$part; break; }
}

// ============================================================
// ROUTER
// ============================================================
switch ($method) {
    case 'GET':
        if ($id) getMember($db, $id);
        else     getMembers($db);
        break;

    case 'POST':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (is_array($data) && isset($data['action']) && isset($data['userIds'])) {
            bulkAction($db);
        } else {
            createMember($db);
        }
        break;

    case 'PUT':
        if ($id) updateMember($db, $id);
        else {
            http_response_code(400);
            echo json_encode(['error' => 'Member ID required']);
        }
        break;

    case 'DELETE':
        if ($id) deleteMember($db, $id);
        else {
            http_response_code(400);
            echo json_encode(['error' => 'Member ID required']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

// ============================================================
// Helpers
// ============================================================
function formatMember($m) {
    return [
        'id' => (int)$m['id'],
        'accountNumber' => $m['account_number'] ?? 'N/A',
        'account_number' => $m['account_number'] ?? 'N/A',
        'name' => $m['name'] ?? 'Unknown',
        'full_name' => $m['name'] ?? 'Unknown',
        'email' => $m['email'] ?? '',
        'phone' => $m['phone'] ?? '',
        'membershipType' => $m['membership_type'] ?? 'Standard',
        'membership_type' => $m['membership_type'] ?? 'Standard',
        'joinDate' => $m['join_date'] ?? date('Y-m-d'),
        'join_date' => $m['join_date'] ?? date('Y-m-d'),
        'status' => $m['status'] ?? 'Active',
        'balance' => floatval($m['balance'] ?? 0),
        'role' => $m['role'] ?? 'member',
        'date_of_birth' => $m['date_of_birth'] ?? null,
        'gender' => $m['gender'] ?? null,
        'address' => $m['address'] ?? null,
        'next_of_kin_name' => $m['next_of_kin_name'] ?? null,
        'next_of_kin_phone' => $m['next_of_kin_phone'] ?? null,
        'next_of_kin_relationship' => $m['next_of_kin_relationship'] ?? null,
        'savings_plan'    => $m['savings_plan']    ?? null,
        'savings_method'  => $m['savings_method']  ?? null,
        'item_name'       => $m['item_name']       ?? null,
        'duration'        => $m['duration']        ?? null,
        'amount'          => isset($m['amount']) ? floatval($m['amount']) : null,
        'total_amount'    => isset($m['total_amount']) ? floatval($m['total_amount']) : null,
        'bank_name' => $m['bank_name'] ?? null,
        'bank_account_number' => $m['bank_account_number'] ?? null,
        'passport_photo' => $m['passport_photo'] ?? null,
        'profile_completed' => intval($m['profile_completed'] ?? 0),
        'branch_id' => isset($m['branch_id']) ? (int)$m['branch_id'] : null,
    ];
}

// ============================================================
// GET ALL
// ============================================================
function getMembers($db) {
    global $BRANCH_ID, $FETCH_ALL;
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'members'");
        if ($tableCheck->rowCount() == 0) {
            echo json_encode(['members' => [], 'branch_id' => $BRANCH_ID, 'all' => 0]);
            return;
        }

        if (!empty($FETCH_ALL)) {
            $stmt = $db->prepare("SELECT * FROM members ORDER BY id DESC");
            $stmt->execute();
        } else {
            $stmt = $db->prepare("SELECT * FROM members WHERE branch_id = ? ORDER BY id DESC");
            $stmt->execute([$BRANCH_ID]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formatted = array_map('formatMember', $rows);

        echo json_encode([
            'members'   => $formatted,
            'branch_id' => $BRANCH_ID,
            'all'       => !empty($FETCH_ALL) ? 1 : 0,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch members: ' . $e->getMessage()]);
    }
}

// ============================================================
// GET ONE
// ============================================================
function getMember($db, $id) {
    global $BRANCH_ID, $FETCH_ALL;
    try {
        if (!empty($FETCH_ALL)) {
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$id]);
        } else {
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ? AND branch_id = ?");
            $stmt->execute([$id, $BRANCH_ID]);
        }
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($member) {
            echo json_encode(['member' => formatMember($member), 'branch_id' => $BRANCH_ID]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Member not found in this branch']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch member: ' . $e->getMessage()]);
    }
}

function startsWith($haystack, $needle) {
    return substr($haystack, 0, strlen($needle)) === $needle;
}

// ============================================================
// CREATE
// ============================================================
function createMember($db) {
    global $BRANCH_ID;
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }

        $accountNumber = $data['accountNumber'] ?? $data['account_number'] ?? null;
        $name = $data['name'] ?? null;
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        $phone = $data['phone'] ?? null;
        $role = $data['role'] ?? 'member';
        $initialBalance = floatval($data['balance'] ?? 0);

        if (!$accountNumber || !$name || !$email || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            return;
        }

        if ($phone && !empty($phone)) {
            $cleaned = preg_replace('/\D/', '', $phone);
            if (strlen($cleaned) !== 11) {
                http_response_code(400);
                echo json_encode(['error' => 'Phone must be 11 digits']);
                return;
            }
            if (!startsWith($cleaned, '0')) {
                http_response_code(400);
                echo json_encode(['error' => 'Phone must start with 0']);
                return;
            }
            $phone = $cleaned;
        }

        $checkStmt = $db->prepare("SELECT id FROM members WHERE email = ? AND branch_id = ?");
        $checkStmt->execute([$email, $BRANCH_ID]);
        if ($checkStmt->rowCount() > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Email already exists in this branch']);
            return;
        }

        if ($phone) {
            $pc = $db->prepare("SELECT id FROM members WHERE phone = ? AND branch_id = ?");
            $pc->execute([$phone, $BRANCH_ID]);
            if ($pc->rowCount() > 0) {
                http_response_code(409);
                echo json_encode(['error' => 'Phone already exists in this branch']);
                return;
            }
        }

        $membershipType = $data['membershipType'] ?? $data['membership_type'] ?? 'Standard';
        $joinDate = $data['joinDate'] ?? $data['join_date'] ?? date('Y-m-d');
        $status = $data['status'] ?? 'Active';

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $netInitialBalance = $initialBalance;
        $chargeAmount = 0;
        $chargeRate = 0;
        if ($initialBalance > 0) {
            $chargeInfo = calculateCharge($initialBalance, $db);
            $chargeAmount = $chargeInfo['charge'];
            $chargeRate = $chargeInfo['rate'];
            $netInitialBalance = $chargeInfo['net'];
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO members
                    (account_number, name, email, phone, membership_type, join_date, status, balance, role, password, branch_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $accountNumber, $name, $email, $phone, $membershipType,
                $joinDate, $status, $netInitialBalance, $role, $hashedPassword,
                $BRANCH_ID
            ]);
            $id = $db->lastInsertId();

            if ($role === 'admin' || $role === 'administrator') {
                $tc = $db->query("SHOW TABLES LIKE 'admins'");
                if ($tc->rowCount() > 0) {
                    $cc = $db->query("SHOW COLUMNS FROM admins LIKE 'branch_id'");
                    if ($cc->rowCount() > 0) {
                        $ia = $db->prepare("INSERT INTO admins (username, email, password, role, phone, full_name, branch_id, created_at)
                                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                        $ia->execute([$name, $email, $hashedPassword, $role, $phone, $name, $BRANCH_ID]);
                    } else {
                        $ia = $db->prepare("INSERT INTO admins (username, email, password, role, phone, full_name, created_at)
                                            VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        $ia->execute([$name, $email, $hashedPassword, $role, $phone, $name]);
                    }
                }
            }

            if ($initialBalance > 0) {
                $tc = $db->query("SHOW TABLES LIKE 'transactions'");
                if ($tc->rowCount() > 0) {
                    $cc = $db->query("SHOW COLUMNS FROM transactions LIKE 'branch_id'");
                    if ($cc->rowCount() > 0) {
                        $ts = $db->prepare("INSERT INTO transactions
                            (member_id, member_name, account_number, type, amount, charge, rate, net_amount, date, status, description, branch_id)
                            VALUES (?, ?, ?, 'deposit', ?, ?, ?, ?, ?, 'approved', 'Initial balance', ?)");
                        $ts->execute([$id, $name, $accountNumber, $initialBalance, $chargeAmount, $chargeRate, $netInitialBalance, $joinDate, $BRANCH_ID]);
                    } else {
                        $ts = $db->prepare("INSERT INTO transactions
                            (member_id, member_name, account_number, type, amount, charge, rate, net_amount, date, status, description)
                            VALUES (?, ?, ?, 'deposit', ?, ?, ?, ?, ?, 'approved', 'Initial balance')");
                        $ts->execute([$id, $name, $accountNumber, $initialBalance, $chargeAmount, $chargeRate, $netInitialBalance, $joinDate]);
                    }
                }
            }

            $db->commit();

            $stmt = $db->prepare("SELECT * FROM members WHERE id = ? AND branch_id = ?");
            $stmt->execute([$id, $BRANCH_ID]);
            $formatted = formatMember($stmt->fetch(PDO::FETCH_ASSOC));
            $formatted['initial_charge'] = $chargeAmount;
            $formatted['initial_net'] = $netInitialBalance;

            http_response_code(201);
            echo json_encode(['member' => $formatted]);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } catch (PDOException $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}

// ============================================================
// UPDATE
// ============================================================
function updateMember($db, $id) {
    global $BRANCH_ID, $FETCH_ALL;
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }

        if (!empty($FETCH_ALL)) {
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$id]);
        } else {
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ? AND branch_id = ?");
            $stmt->execute([$id, $BRANCH_ID]);
        }
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            http_response_code(404);
            echo json_encode(['error' => 'Member not found in this branch']);
            return;
        }

        $rowBranchId = (int)($current['branch_id'] ?? $BRANCH_ID);

        if (isset($data['email']) && $data['email'] !== $current['email']) {
            $ec = $db->prepare("SELECT id FROM members WHERE email = ? AND id != ? AND branch_id = ?");
            $ec->execute([$data['email'], $id, $rowBranchId]);
            if ($ec->rowCount() > 0) {
                http_response_code(409);
                echo json_encode(['error' => 'Email already exists']);
                return;
            }
        }

        if (isset($data['phone']) && !empty($data['phone'])) {
            $cleaned = preg_replace('/\D/', '', $data['phone']);
            if (strlen($cleaned) !== 11 || !startsWith($cleaned, '0')) {
                http_response_code(400);
                echo json_encode(['error' => 'Phone must be 11 digits starting with 0']);
                return;
            }
            $data['phone'] = $cleaned;
            if ($cleaned !== ($current['phone'] ?? '')) {
                $pc = $db->prepare("SELECT id FROM members WHERE phone = ? AND id != ? AND branch_id = ?");
                $pc->execute([$cleaned, $id, $rowBranchId]);
                if ($pc->rowCount() > 0) {
                    http_response_code(409);
                    echo json_encode(['error' => 'Phone already exists']);
                    return;
                }
            }
        }

        $updateFields = [];
        $params = [];
        $newHashedPassword = null;

        $simple = [
            'name','email','phone','status',
            'date_of_birth','gender','address',
            'next_of_kin_name','next_of_kin_phone','next_of_kin_relationship',
            'savings_plan','savings_method','item_name','duration',
            'bank_name','bank_account_number','passport_photo',
        ];
        foreach ($simple as $f) {
            if (array_key_exists($f, $data)) {
                $updateFields[] = "$f = ?";
                $v = $data[$f];
                if (is_string($v) && trim($v) === '') $v = null;
                $params[] = $v;
            }
        }
        if (array_key_exists('amount', $data)) {
            $updateFields[] = "amount = ?";
            $params[] = floatval($data['amount'] ?? 0);
        }
        if (array_key_exists('total_amount', $data)) {
            $updateFields[] = "total_amount = ?";
            $params[] = floatval($data['total_amount'] ?? 0);
        }
        if (array_key_exists('balance', $data)) {
            $updateFields[] = "balance = ?";
            $params[] = floatval($data['balance']);
        }

        $oldRole = $current['role'];
        $newRole = $data['role'] ?? $oldRole;
        if (isset($data['role'])) {
            $updateFields[] = "role = ?";
            $params[] = $data['role'];
        }

        if (!empty($data['password'])) {
            $newHashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            $updateFields[] = "password = ?";
            $params[] = $newHashedPassword;
        }

        if (isset($data['profile_completed'])) {
            $updateFields[] = "profile_completed = ?";
            $params[] = intval($data['profile_completed']);
        } else {
            $merged = array_merge($current, $data);
            $complete =
                !empty($merged['date_of_birth']) &&
                !empty($merged['gender']) &&
                !empty($merged['phone']) &&
                !empty($merged['address']) &&
                !empty($merged['next_of_kin_name']) &&
                !empty($merged['next_of_kin_phone']) &&
                !empty($merged['next_of_kin_relationship']) &&
                !empty($merged['savings_plan']) &&
                !empty($merged['passport_photo']);
            if ($complete && intval($current['profile_completed']) !== 1) {
                $updateFields[] = "profile_completed = ?";
                $params[] = 1;
            }
        }

        if (empty($updateFields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            return;
        }

        if (!empty($FETCH_ALL)) {
            $params[] = $id;
            $whereSql = "WHERE id = ?";
        } else {
            $params[] = $id;
            $params[] = $BRANCH_ID;
            $whereSql = "WHERE id = ? AND branch_id = ?";
        }

        $db->beginTransaction();
        try {
            $sql = "UPDATE members SET " . implode(", ", $updateFields) . " " . $whereSql;
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $refresh = $db->prepare("SELECT password FROM members WHERE id = ?");
            $refresh->execute([$id]);
            $currentHash = $refresh->fetchColumn();

            $userName  = $data['name']  ?? $current['name'];
            $userEmail = $data['email'] ?? $current['email'];
            $userPhone = $data['phone'] ?? $current['phone'];

            $tc = $db->query("SHOW TABLES LIKE 'admins'");
            if ($tc->rowCount() > 0) {
                if ($newRole === 'admin' || $newRole === 'administrator') {
                    $hash = $newHashedPassword ?? $currentHash;
                    $check = $db->prepare("SELECT id FROM admins WHERE email = ?");
                    $check->execute([$userEmail]);
                    if ($check->rowCount() > 0) {
                        $db->prepare("UPDATE admins SET username = ?, full_name = ?, phone = ?, password = ?, role = ? WHERE email = ?")
                           ->execute([$userName, $userName, $userPhone, $hash, $newRole, $userEmail]);
                    } else {
                        $db->prepare("INSERT INTO admins (username, email, password, role, phone, full_name, created_at)
                                      VALUES (?, ?, ?, ?, ?, ?, NOW())")
                           ->execute([$userName, $userEmail, $hash, $newRole, $userPhone, $userName]);
                    }
                } elseif (($oldRole === 'admin' || $oldRole === 'administrator')
                          && !in_array($newRole, ['admin','administrator'], true)) {
                    $db->prepare("DELETE FROM admins WHERE email = ?")->execute([$userEmail]);
                }
            }

            $db->commit();

            $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode([
                'success' => true,
                'message' => 'Member updated successfully',
                'member' => formatMember($stmt->fetch(PDO::FETCH_ASSOC))
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } catch (PDOException $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}

// ============================================================
// DELETE
// ============================================================
function deleteMember($db, $id) {
    global $BRANCH_ID, $FETCH_ALL;
    try {
        if (!empty($FETCH_ALL)) {
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$id]);
        } else {
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ? AND branch_id = ?");
            $stmt->execute([$id, $BRANCH_ID]);
        }
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'Member not found in this branch']);
            return;
        }

        $db->beginTransaction();
        try {
            $hasDeletedAt = false;
            try {
                $cc = $db->query("SHOW COLUMNS FROM members LIKE 'deleted_at'");
                $hasDeletedAt = $cc->rowCount() > 0;
            } catch (Exception $e) {}

            if ($hasDeletedAt) {
                $db->prepare("UPDATE members SET deleted_at = NOW(), status = 'Deleted' WHERE id = ?")->execute([$id]);
            } else {
                $tc = $db->query("SHOW TABLES LIKE 'transactions'");
                if ($tc->rowCount() > 0) $db->prepare("DELETE FROM transactions WHERE member_id = ?")->execute([$id]);
                $tc = $db->query("SHOW TABLES LIKE 'loans'");
                if ($tc->rowCount() > 0) $db->prepare("DELETE FROM loans WHERE user_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM members WHERE id = ?")->execute([$id]);
            }

            $tc = $db->query("SHOW TABLES LIKE 'admins'");
            if ($tc->rowCount() > 0 && in_array($user['role'], ['admin','administrator'], true)) {
                $db->prepare("DELETE FROM admins WHERE email = ?")->execute([$user['email']]);
            }

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Member deleted successfully']);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete member: ' . $e->getMessage()]);
    }
}

// ============================================================
// BULK ACTION
// ============================================================
function bulkAction($db) {
    global $BRANCH_ID;
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data || !isset($data['userIds']) || !isset($data['action'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid request']);
            return;
        }
        $userIds = $data['userIds'];
        $action = $data['action'];
        if (empty($userIds)) {
            http_response_code(400);
            echo json_encode(['error' => 'No users selected']);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));

        $db->beginTransaction();
        try {
            switch ($action) {
                case 'activate':
                    $stmt = $db->prepare("UPDATE members SET status = 'Active' WHERE branch_id = ? AND id IN ($placeholders)");
                    $stmt->execute(array_merge([$BRANCH_ID], $userIds));
                    $count = $stmt->rowCount();
                    $msg = 'activated';
                    break;
                case 'suspend':
                    $stmt = $db->prepare("UPDATE members SET status = 'Suspended' WHERE branch_id = ? AND id IN ($placeholders)");
                    $stmt->execute(array_merge([$BRANCH_ID], $userIds));
                    $count = $stmt->rowCount();
                    $msg = 'suspended';
                    break;
                case 'delete':
                    $count = 0;
                    foreach ($userIds as $uid) {
                        $getUser = $db->prepare("SELECT * FROM members WHERE id = ? AND branch_id = ?");
                        $getUser->execute([$uid, $BRANCH_ID]);
                        $u = $getUser->fetch(PDO::FETCH_ASSOC);
                        if (!$u) continue;

                        $cc = $db->query("SHOW COLUMNS FROM members LIKE 'deleted_at'");
                        if ($cc->rowCount() > 0) {
                            $db->prepare("UPDATE members SET deleted_at = NOW(), status = 'Deleted' WHERE id = ? AND branch_id = ?")
                               ->execute([$uid, $BRANCH_ID]);
                        } else {
                            $db->prepare("DELETE FROM members WHERE id = ? AND branch_id = ?")->execute([$uid, $BRANCH_ID]);
                        }

                        $tc = $db->query("SHOW TABLES LIKE 'admins'");
                        if ($tc->rowCount() > 0 && in_array($u['role'], ['admin','administrator'], true)) {
                            $db->prepare("DELETE FROM admins WHERE email = ?")->execute([$u['email']]);
                        }
                        $count++;
                    }
                    $msg = 'deleted';
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
                    return;
            }

            $db->commit();
            echo json_encode(['success' => true, 'message' => "$count user(s) $msg successfully"]);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } catch (PDOException $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}