<?php
// api/members.php
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

switch ($method) {
    case 'GET':
        if ($id) {
            getMember($db, $id);
        } else {
            getMembers($db);
        }
        break;
        
    case 'POST':
        createMember($db);
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
        
        if (!$accountNumber) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required field: accountNumber']);
            return;
        }
        
        if (!$name) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required field: name']);
            return;
        }
        
        if (!$email) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required field: email']);
            return;
        }
        
        $phone = $data['phone'] ?? '';
        $membershipType = $data['membershipType'] ?? $data['membership_type'] ?? 'Standard';
        $joinDate = $data['joinDate'] ?? $data['join_date'] ?? date('Y-m-d');
        $status = $data['status'] ?? 'Active';
        $balance = floatval($data['balance'] ?? 0);
        $role = $data['role'] ?? 'member';
        
        $hashedPassword = $password ? password_hash($password, PASSWORD_DEFAULT) : password_hash('password123', PASSWORD_DEFAULT);
        
        $stmt = $db->prepare("
            INSERT INTO members (account_number, name, email, phone, membership_type, join_date, status, balance, role, password)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $accountNumber,
            $name,
            $email,
            $phone,
            $membershipType,
            $joinDate,
            $status,
            $balance,
            $role,
            $hashedPassword
        ]);
        
        if ($result) {
            $id = $db->lastInsertId();
            $data['id'] = $id;
            
            http_response_code(201);
            echo json_encode(['member' => $data]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to insert member']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}

function updateMember($db, $id) {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }
        
        // Check if password is provided
        $hasPassword = !empty($data['password']);
        
        // First, get current user data to preserve fields not being updated
        $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$id]);
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$currentUser) {
            http_response_code(404);
            echo json_encode(['error' => 'Member not found']);
            return;
        }
        
        // Build update query dynamically
        $updateFields = [];
        $params = [];
        
        // Always update these fields if provided
        if (isset($data['name']) && !empty($data['name'])) {
            $updateFields[] = "name = ?";
            $params[] = $data['name'];
        } else {
            $updateFields[] = "name = ?";
            $params[] = $currentUser['name'];
        }
        
        if (isset($data['email']) && !empty($data['email'])) {
            $updateFields[] = "email = ?";
            $params[] = $data['email'];
        } else {
            $updateFields[] = "email = ?";
            $params[] = $currentUser['email'];
        }
        
        if (isset($data['phone'])) {
            $updateFields[] = "phone = ?";
            $params[] = $data['phone'];
        } else {
            $updateFields[] = "phone = ?";
            $params[] = $currentUser['phone'] ?? '';
        }
        
        if (isset($data['membershipType']) || isset($data['membership_type'])) {
            $membershipType = $data['membershipType'] ?? $data['membership_type'] ?? 'Standard';
            $updateFields[] = "membership_type = ?";
            $params[] = $membershipType;
        }
        
        if (isset($data['status'])) {
            $updateFields[] = "status = ?";
            $params[] = $data['status'];
        }
        
        if (isset($data['balance'])) {
            $updateFields[] = "balance = ?";
            $params[] = floatval($data['balance']);
        }
        
        if (isset($data['role'])) {
            $updateFields[] = "role = ?";
            $params[] = $data['role'];
        }
        
        // Update password if provided
        if ($hasPassword) {
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            $updateFields[] = "password = ?";
            $params[] = $hashedPassword;
        }
        
        // Add ID to params
        $params[] = $id;
        
        // Build and execute query
        $sql = "UPDATE members SET " . implode(", ", $updateFields) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute($params);
        
        if ($result) {
            // Fetch updated user
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
            
            echo json_encode(['member' => $formatted]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update member']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}

function deleteMember($db, $id) {
    try {
        $stmt = $db->prepare("DELETE FROM members WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['message' => 'Member deleted successfully']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete member: ' . $e->getMessage()]);
    }
}
?>
