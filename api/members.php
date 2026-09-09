<?php
// api/members.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Clean any output buffers
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
            $formatted[] = [
                'id' => $m['id'],
                'accountNumber' => $m['account_number'] ?? 'N/A',
                'name' => $m['name'] ?? 'Unknown',
                'email' => $m['email'] ?? '',
                'phone' => $m['phone'] ?? '',
                'membershipType' => $m['membership_type'] ?? 'Standard',
                'joinDate' => $m['join_date'] ?? date('Y-m-d'),
                'status' => $m['status'] ?? 'Active',
                'balance' => floatval($m['balance'] ?? 0),
                'role' => $m['role'] ?? 'member'
            ];
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
            $formatted = [
                'id' => $member['id'],
                'accountNumber' => $member['account_number'] ?? 'N/A',
                'name' => $member['name'] ?? 'Unknown',
                'email' => $member['email'] ?? '',
                'phone' => $member['phone'] ?? '',
                'membershipType' => $member['membership_type'] ?? 'Standard',
                'joinDate' => $member['join_date'] ?? date('Y-m-d'),
                'status' => $member['status'] ?? 'Active',
                'balance' => floatval($member['balance'] ?? 0),
                'role' => $member['role'] ?? 'member'
            ];
            echo json_encode(['member' => $formatted]);
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
        
        if (!$accountNumber || !$name || !$email || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            return;
        }
        
        // Validate phone
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
        
        // Check duplicates
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
        $balance = floatval($data['balance'] ?? 0);
        
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $db->beginTransaction();
        
        try {
            $stmt = $db->prepare("
                INSERT INTO members (account_number, name, email, phone, membership_type, join_date, status, balance, role, password)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $accountNumber, $name, $email, $phone, $membershipType,
                $joinDate, $status, $balance, $role, $hashedPassword
            ]);
            
            if (!$result) {
                throw new Exception('Failed to insert member');
            }
            
            $id = $db->lastInsertId();
            
            // If admin, add to admins table
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
            
            $db->commit();
            
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$id]);
            $newUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $formatted = [
                'id' => $newUser['id'],
                'accountNumber' => $newUser['account_number'] ?? 'N/A',
                'name' => $newUser['name'] ?? 'Unknown',
                'email' => $newUser['email'] ?? '',
                'phone' => $newUser['phone'] ?? '',
                'membershipType' => $newUser['membership_type'] ?? 'Standard',
                'joinDate' => $newUser['join_date'] ?? date('Y-m-d'),
                'status' => $newUser['status'] ?? 'Active',
                'balance' => floatval($newUser['balance'] ?? 0),
                'role' => $newUser['role'] ?? 'member'
            ];
            
            http_response_code(201);
            echo json_encode(['member' => $formatted]);
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        
    } catch (PDOException $e) {
        if ($db && $db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        if ($db && $db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}

// ============================================================
// FIXED: updateMember - PROPERLY syncs admin users
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
        
        // Get current user
        $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$id]);
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$currentUser) {
            http_response_code(404);
            echo json_encode(['error' => 'Member not found']);
            return;
        }
        
        // Check email uniqueness
        if (isset($data['email']) && !empty($data['email']) && $data['email'] !== $currentUser['email']) {
            $emailCheckStmt = $db->prepare("SELECT id FROM members WHERE email = ? AND id != ?");
            $emailCheckStmt->execute([$data['email'], $id]);
            if ($emailCheckStmt->rowCount() > 0) {
                http_response_code(409);
                echo json_encode(['error' => 'Email already exists']);
                return;
            }
        }
        
        // Validate phone
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
        
        // Build update query
        $updateFields = [];
        $params = [];
        $hashedPassword = null;
        
        if (isset($data['name']) && !empty($data['name'])) {
            $updateFields[] = "name = ?";
            $params[] = $data['name'];
        }
        
        if (isset($data['email']) && !empty($data['email'])) {
            $updateFields[] = "email = ?";
            $params[] = $data['email'];
        }
        
        if (isset($data['phone'])) {
            $updateFields[] = "phone = ?";
            $params[] = empty($data['phone']) ? null : $data['phone'];
        }
        
        if (isset($data['status'])) {
            $updateFields[] = "status = ?";
            $params[] = $data['status'];
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
        
        // Hash password if provided
        if (isset($data['password']) && !empty($data['password'])) {
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            $updateFields[] = "password = ?";
            $params[] = $hashedPassword;
        }
        
        if (empty($updateFields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            return;
        }
        
        $params[] = $id;
        
        $db->beginTransaction();
        
        try {
            // Update members table
            $sql = "UPDATE members SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $result = $stmt->execute($params);
            
            if (!$result) {
                throw new Exception('Failed to update member');
            }
            
            // ============================================================
            // CRITICAL: Sync with admins table
            // ============================================================
            $userName = $data['name'] ?? $currentUser['name'];
            $userEmail = $data['email'] ?? $currentUser['email'];
            $userPhone = isset($data['phone']) ? $data['phone'] : $currentUser['phone'];
            
            // Get the password from the database (after update)
            $refreshStmt = $db->prepare("SELECT password FROM members WHERE id = ?");
            $refreshStmt->execute([$id]);
            $updatedMember = $refreshStmt->fetch(PDO::FETCH_ASSOC);
            $userPassword = $updatedMember['password'];
            
            $tableCheck = $db->query("SHOW TABLES LIKE 'admins'");
            $adminsTableExists = $tableCheck->rowCount() > 0;
            
            if ($adminsTableExists) {
                // CASE: User is being set to admin
                if ($newRole === 'admin' || $newRole === 'administrator') {
                    $checkAdmin = $db->prepare("SELECT id FROM admins WHERE email = ?");
                    $checkAdmin->execute([$userEmail]);
                    
                    if ($checkAdmin->rowCount() > 0) {
                        // UPDATE existing admin with the SAME password
                        $updateAdmin = $db->prepare("
                            UPDATE admins 
                            SET username = ?, 
                                full_name = ?, 
                                phone = ?, 
                                password = ?,
                                role = ?
                            WHERE email = ?
                        ");
                        $updateAdmin->execute([
                            $userName,
                            $userName,
                            $userPhone,
                            $userPassword,
                            $newRole,
                            $userEmail
                        ]);
                    } else {
                        // INSERT new admin with the SAME password
                        $insertAdmin = $db->prepare("
                            INSERT INTO admins (username, email, password, role, phone, full_name, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $insertAdmin->execute([
                            $userName,
                            $userEmail,
                            $userPassword,
                            $newRole,
                            $userPhone,
                            $userName
                        ]);
                    }
                    $checkAdmin->close();
                } 
                // CASE: User was admin but now role changed to something else
                else if (($oldRole === 'admin' || $oldRole === 'administrator') && 
                         ($newRole !== 'admin' && $newRole !== 'administrator')) {
                    $deleteAdmin = $db->prepare("DELETE FROM admins WHERE email = ?");
                    $deleteAdmin->execute([$userEmail]);
                }
            }
            
            $db->commit();
            
            // Return updated user
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$id]);
            $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $formatted = [
                'id' => $updatedUser['id'],
                'accountNumber' => $updatedUser['account_number'] ?? 'N/A',
                'name' => $updatedUser['name'] ?? 'Unknown',
                'email' => $updatedUser['email'] ?? '',
                'phone' => $updatedUser['phone'] ?? '',
                'membershipType' => $updatedUser['membership_type'] ?? 'Standard',
                'joinDate' => $updatedUser['join_date'] ?? date('Y-m-d'),
                'status' => $updatedUser['status'] ?? 'Active',
                'balance' => floatval($updatedUser['balance'] ?? 0),
                'role' => $updatedUser['role'] ?? 'member'
            ];
            
            echo json_encode([
                'success' => true,
                'message' => 'Member updated successfully',
                'member' => $formatted
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        
    } catch (PDOException $e) {
        if ($db && $db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        if ($db && $db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}

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
            $tableCheck = $db->query("SHOW TABLES LIKE 'admins'");
            $adminsTableExists = $tableCheck->rowCount() > 0;
            
            if ($adminsTableExists && ($user['role'] === 'admin' || $user['role'] === 'administrator')) {
                $deleteAdmin = $db->prepare("DELETE FROM admins WHERE email = ?");
                $deleteAdmin->execute([$user['email']]);
            }
            
            $stmt = $db->prepare("DELETE FROM members WHERE id = ?");
            $stmt->execute([$id]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Member deleted successfully'
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        if ($db && $db->inTransaction()) {
            $db->rollBack();
        }
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
                    $actionMessage = 'activated';
                    break;
                    
                case 'suspend':
                    $sql = "UPDATE members SET status = 'Suspended' WHERE id IN ($placeholders)";
                    $stmt = $db->prepare($sql);
                    $result = $stmt->execute($userIds);
                    $actionMessage = 'suspended';
                    break;
                    
                case 'delete':
                    $getUsers = $db->prepare("SELECT * FROM members WHERE id IN ($placeholders)");
                    $getUsers->execute($userIds);
                    $usersToDelete = $getUsers->fetchAll(PDO::FETCH_ASSOC);
                    
                    $tableCheck = $db->query("SHOW TABLES LIKE 'admins'");
                    $adminsTableExists = $tableCheck->rowCount() > 0;
                    
                    if ($adminsTableExists) {
                        foreach ($usersToDelete as $user) {
                            if ($user['role'] === 'admin' || $user['role'] === 'administrator') {
                                $deleteAdmin = $db->prepare("DELETE FROM admins WHERE email = ?");
                                $deleteAdmin->execute([$user['email']]);
                            }
                        }
                    }
                    
                    $sql = "DELETE FROM members WHERE id IN ($placeholders)";
                    $stmt = $db->prepare($sql);
                    $result = $stmt->execute($userIds);
                    $actionMessage = 'deleted';
                    break;
                    
                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
                    return;
            }
            
            if ($result) {
                $count = $stmt->rowCount();
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
        if ($db && $db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        if ($db && $db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}
?>