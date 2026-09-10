<?php
// api/members.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

while (ob_get_level()) {
    ob_end_clean();
}

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

switch ($method) {
    case 'GET':
        if ($id) {
            getMember($db, $id);
        } else {
            getMembers($db);
        }
        break;

    case 'POST':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (isset($data['action']) && isset($data['userIds'])) {
            bulkAction($db);
        } else {
            createMember($db);
        }
        break;

    case 'PUT':
        if ($id) {
            updateMember($db, $id);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Member ID required']);
        }
        break;

    case 'DELETE':
        if ($id) {
            deleteMember($db, $id);
        } else {
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
// HELPER — format member row for API output
// ============================================================
function formatMember($m) {
    return [
        'id' => $m['id'],
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
        'savings_plan' => $m['savings_plan'] ?? null,
        'bank_name' => $m['bank_name'] ?? null,
        'bank_account_number' => $m['bank_account_number'] ?? null,
        'passport_photo' => $m['passport_photo'] ?? null,
        'profile_completed' => intval($m['profile_completed'] ?? 0),
    ];
}

function getMembers($db) {
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'members'");
        if ($tableCheck->rowCount() == 0) {
            echo json_encode(['members' => []]);
            return;
        }

        $stmt = $db->query("SELECT * FROM members ORDER BY id DESC");
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = [];
        foreach ($members as $m) {
            $formatted[] = formatMember($m);
        }

        echo json_encode(['members' => $formatted]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch members: ' . $e->getMessage()]);
    }
}

function getMember($db, $id) {
    try {
        $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($member) {
            echo json_encode(['member' => formatMember($member)]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Member not found']);
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
// CREATE — minimal form, backend applies deposit charge if any balance given
// ============================================================
function createMember($db) {
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

        $checkStmt = $db->prepare("SELECT id FROM members WHERE email = ?");
        $checkStmt->execute([$email]);
        if ($checkStmt->rowCount() > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Email already exists']);
            return;
        }

        if ($phone) {
            $phoneCheckStmt = $db->prepare("SELECT id FROM members WHERE phone = ?");
            $phoneCheckStmt->execute([$phone]);
            if ($phoneCheckStmt->rowCount() > 0) {
                http_response_code(409);
                echo json_encode(['error' => 'Phone already exists']);
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
                INSERT INTO members (account_number, name, email, phone, membership_type, join_date, status, balance, role, password)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $result = $stmt->execute([
                $accountNumber, $name, $email, $phone, $membershipType,
                $joinDate, $status, $netInitialBalance, $role, $hashedPassword
            ]);

            if (!$result) {
                throw new Exception('Failed to insert member');
            }

            $id = $db->lastInsertId();

            if ($role === 'admin' || $role === 'administrator') {
                $tableCheck = $db->query("SHOW TABLES LIKE 'admins'");
                if ($tableCheck->rowCount() > 0) {
                    $insertAdmin = $db->prepare("
                        INSERT INTO admins (username, email, password, role, phone, full_name, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $insertAdmin->execute([
                        $name, $email, $hashedPassword, $role, $phone, $name
                    ]);
                }
            }

            if ($initialBalance > 0) {
                $tableCheck = $db->query("SHOW TABLES LIKE 'transactions'");
                if ($tableCheck->rowCount() > 0) {
                    $txnStmt = $db->prepare("
                        INSERT INTO transactions 
                            (member_id, member_name, account_number, type, amount, charge, rate, net_amount,
                             date, status, description)
                        VALUES (?, ?, ?, 'deposit', ?, ?, ?, ?, ?, 'approved', 'Initial balance')
                    ");
                    $txnStmt->execute([
                        $id, $name, $accountNumber, $initialBalance,
                        $chargeAmount, $chargeRate, $netInitialBalance, $joinDate
                    ]);
                }
            }

            $db->commit();

            $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$id]);
            $newUser = $stmt->fetch(PDO::FETCH_ASSOC);

            $formatted = formatMember($newUser);
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
// UPDATE — supports all profile fields + auto profile_completed
// ============================================================
function updateMember($db, $id) {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }

        $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$id]);
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$currentUser) {
            http_response_code(404);
            echo json_encode(['error' => 'Member not found']);
            return;
        }

        if (isset($data['email']) && !empty($data['email']) && $data['email'] !== $currentUser['email']) {
            $emailCheckStmt = $db->prepare("SELECT id FROM members WHERE email = ? AND id != ?");
            $emailCheckStmt->execute([$data['email'], $id]);
            if ($emailCheckStmt->rowCount() > 0) {
                http_response_code(409);
                echo json_encode(['error' => 'Email already exists']);
                return;
            }
        }

        if (isset($data['phone']) && !empty($data['phone'])) {
            $cleaned = preg_replace('/\D/', '', $data['phone']);
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
            $data['phone'] = $cleaned;

            if ($data['phone'] !== ($currentUser['phone'] ?? '')) {
                $phoneCheckStmt = $db->prepare("SELECT id FROM members WHERE phone = ? AND id != ?");
                $phoneCheckStmt->execute([$data['phone'], $id]);
                if ($phoneCheckStmt->rowCount() > 0) {
                    http_response_code(409);
                    echo json_encode(['error' => 'Phone already exists']);
                    return;
                }
            }
        }

        $updateFields = [];
        $params = [];
        $newHashedPassword = null;

        $simpleFields = [
            'name', 'email', 'phone', 'status',
            'date_of_birth', 'gender', 'address',
            'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship',
            'savings_plan', 'bank_name', 'bank_account_number', 'passport_photo'
        ];

        foreach ($simpleFields as $f) {
            if (isset($data[$f])) {
                $updateFields[] = "$f = ?";
                $params[] = empty($data[$f]) ? null : $data[$f];
            }
        }

        if (isset($data['balance'])) {
            $updateFields[] = "balance = ?";
            $params[] = floatval($data['balance']);
        }

        $oldRole = $currentUser['role'];
        $newRole = isset($data['role']) ? $data['role'] : $oldRole;

        if (isset($data['role'])) {
            $updateFields[] = "role = ?";
            $params[] = $data['role'];
        }

        if (isset($data['password']) && !empty($data['password'])) {
            $newHashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            $updateFields[] = "password = ?";
            $params[] = $newHashedPassword;
        }

        // Handle profile_completed auto-detection
        if (isset($data['profile_completed'])) {
            $updateFields[] = "profile_completed = ?";
            $params[] = intval($data['profile_completed']);
        } else {
            // Check if all required fields are present (existing + incoming)
            $merged = array_merge($currentUser, $data);
            $requiredComplete =
                !empty($merged['date_of_birth']) &&
                !empty($merged['gender']) &&
                !empty($merged['phone']) &&
                !empty($merged['address']) &&
                !empty($merged['next_of_kin_name']) &&
                !empty($merged['next_of_kin_phone']) &&
                !empty($merged['next_of_kin_relationship']) &&
                !empty($merged['savings_plan']) &&
                !empty($merged['passport_photo']);

            if ($requiredComplete && intval($currentUser['profile_completed']) !== 1) {
                $updateFields[] = "profile_completed = ?";
                $params[] = 1;
            }
        }

        if (empty($updateFields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            return;
        }

        $params[] = $id;

        $db->beginTransaction();

        try {
            $sql = "UPDATE members SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $result = $stmt->execute($params);

            if (!$result) {
                throw new Exception('Failed to update member');
            }

            $refreshStmt = $db->prepare("SELECT password FROM members WHERE id = ?");
            $refreshStmt->execute([$id]);
            $updatedMember = $refreshStmt->fetch(PDO::FETCH_ASSOC);
            $currentMemberPassword = $updatedMember['password'];

            $userName  = $data['name']  ?? $currentUser['name'];
            $userEmail = $data['email'] ?? $currentUser['email'];
            $userPhone = isset($data['phone']) ? $data['phone'] : $currentUser['phone'];

            $tableCheck = $db->query("SHOW TABLES LIKE 'admins'");
            $adminsTableExists = $tableCheck->rowCount() > 0;

            if ($adminsTableExists) {
                if ($newRole === 'admin' || $newRole === 'administrator') {
                    $adminPasswordToUse = ($newHashedPassword !== null)
                        ? $newHashedPassword
                        : $currentMemberPassword;

                    $checkAdmin = $db->prepare("SELECT id FROM admins WHERE email = ?");
                    $checkAdmin->execute([$userEmail]);

                    if ($checkAdmin->rowCount() > 0) {
                        $updateAdmin = $db->prepare("
                            UPDATE admins
                            SET username = ?, full_name = ?, phone = ?, password = ?, role = ?
                            WHERE email = ?
                        ");
                        $updateAdmin->execute([
                            $userName, $userName, $userPhone,
                            $adminPasswordToUse, $newRole, $userEmail
                        ]);
                    } else {
                        $insertAdmin = $db->prepare("
                            INSERT INTO admins (username, email, password, role, phone, full_name, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $insertAdmin->execute([
                            $userName, $userEmail, $adminPasswordToUse,
                            $newRole, $userPhone, $userName
                        ]);
                    }
                }
                else if (($oldRole === 'admin' || $oldRole === 'administrator') &&
                         ($newRole !== 'admin' && $newRole !== 'administrator')) {
                    $deleteAdmin = $db->prepare("DELETE FROM admins WHERE email = ?");
                    $deleteAdmin->execute([$userEmail]);
                }
            }

            $db->commit();

            $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$id]);
            $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Member updated successfully',
                'member' => formatMember($updatedUser)
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
// DELETE — soft delete if deleted_at column exists
// ============================================================
function deleteMember($db, $id) {
    try {
        $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'Member not found']);
            return;
        }

        $db->beginTransaction();

        try {
            $hasDeletedAt = false;
            try {
                $colCheck = $db->query("SHOW COLUMNS FROM members LIKE 'deleted_at'");
                $hasDeletedAt = $colCheck->rowCount() > 0;
            } catch (Exception $e) {}

            if ($hasDeletedAt) {
                $db->prepare("UPDATE members SET deleted_at = NOW(), status = 'Deleted' WHERE id = ?")
                   ->execute([$id]);
            } else {
                $tc = $db->query("SHOW TABLES LIKE 'transactions'");
                if ($tc->rowCount() > 0) {
                    $db->prepare("DELETE FROM transactions WHERE member_id = ?")->execute([$id]);
                }
                $tc = $db->query("SHOW TABLES LIKE 'loans'");
                if ($tc->rowCount() > 0) {
                    $db->prepare("DELETE FROM loans WHERE user_id = ?")->execute([$id]);
                }
                $db->prepare("DELETE FROM members WHERE id = ?")->execute([$id]);
            }

            $tableCheck = $db->query("SHOW TABLES LIKE 'admins'");
            if ($tableCheck->rowCount() > 0 && ($user['role'] === 'admin' || $user['role'] === 'administrator')) {
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

function bulkAction($db) {
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
                    $sql = "UPDATE members SET status = 'Active' WHERE id IN ($placeholders)";
                    $stmt = $db->prepare($sql);
                    $result = $stmt->execute($userIds);
                    $count = $stmt->rowCount();
                    $actionMessage = 'activated';
                    break;

                case 'suspend':
                    $sql = "UPDATE members SET status = 'Suspended' WHERE id IN ($placeholders)";
                    $stmt = $db->prepare($sql);
                    $result = $stmt->execute($userIds);
                    $count = $stmt->rowCount();
                    $actionMessage = 'suspended';
                    break;

                case 'delete':
                    $count = 0;
                    foreach ($userIds as $uid) {
                        $getUser = $db->prepare("SELECT * FROM members WHERE id = ?");
                        $getUser->execute([$uid]);
                        $u = $getUser->fetch(PDO::FETCH_ASSOC);
                        if (!$u) continue;

                        $colCheck = $db->query("SHOW COLUMNS FROM members LIKE 'deleted_at'");
                        if ($colCheck->rowCount() > 0) {
                            $db->prepare("UPDATE members SET deleted_at = NOW(), status = 'Deleted' WHERE id = ?")
                               ->execute([$uid]);
                        } else {
                            $db->prepare("DELETE FROM members WHERE id = ?")->execute([$uid]);
                        }

                        $tc = $db->query("SHOW TABLES LIKE 'admins'");
                        if ($tc->rowCount() > 0 && ($u['role'] === 'admin' || $u['role'] === 'administrator')) {
                            $db->prepare("DELETE FROM admins WHERE email = ?")->execute([$u['email']]);
                        }
                        $count++;
                    }
                    $result = true;
                    $actionMessage = 'deleted';
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
                    return;
            }

            if ($result) {
                $db->commit();
                echo json_encode([
                    'success' => true,
                    'message' => "$count user(s) $actionMessage successfully"
                ]);
            } else {
                $db->rollBack();
                http_response_code(500);
                echo json_encode(['error' => 'Failed to perform bulk action']);
            }

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
?>