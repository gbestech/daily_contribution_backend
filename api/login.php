<?php
// api/login.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

require_once __DIR__ . '/config/database.php';
$db = getDBConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

$email = $data['email'] ?? '';
$password = $data['password'] ?? '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'error' => 'Email and password are required']);
    exit();
}

try {
    // FIRST: Check admins table
    $stmt = $db->prepare("SELECT * FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($admin) {
        if ($password === $admin['password']) {
            echo json_encode([
                'status' => true,
                'message' => 'Login successful',
                'token' => 'admin_token_' . time(),
                'user' => [
                    'id' => $admin['id'],
                    'username' => $admin['username'],
                    'email' => $admin['email'],
                    'full_name' => $admin['username'],
                    'name' => $admin['username'],
                    'role' => 'administrator',
                    'phone' => '',
                    'address' => '',
                    'is_active' => true,
                    'balance' => 0,
                    'accountNumber' => 'ADMIN001',
                    'account_number' => 'ADMIN001',
                    'membership_number' => 'ADMIN001',
                    'joinDate' => date('Y-m-d'),
                    'join_date' => date('Y-m-d'),
                    'status' => 'Active'
                ]
            ]);
            exit();
        } else {
            http_response_code(401);
            echo json_encode(['status' => false, 'error' => 'Invalid password']);
            exit();
        }
    }
    
    // SECOND: Check members table
    $stmt = $db->prepare("SELECT * FROM members WHERE email = ?");
    $stmt->execute([$email]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($member) {
        echo json_encode([
            'status' => true,
            'message' => 'Login successful',
            'token' => 'member_token_' . time(),
            'user' => [
                'id' => $member['id'],
                'username' => $member['name'],
                'name' => $member['name'],
                'full_name' => $member['name'],
                'email' => $member['email'],
                'phone' => $member['phone'] ?? '',
                'role' => 'member',
                'address' => '',
                'is_active' => $member['status'] === 'Active' ? true : false,
                'balance' => floatval($member['balance'] ?? 0),
                'accountNumber' => $member['account_number'] ?? 'N/A',
                'account_number' => $member['account_number'] ?? 'N/A',
                'membership_number' => $member['account_number'] ?? 'N/A',
                'membershipType' => $member['membership_type'] ?? 'Standard',
                'membership_type' => $member['membership_type'] ?? 'Standard',
                'joinDate' => $member['join_date'] ?? date('Y-m-d'),
                'join_date' => $member['join_date'] ?? date('Y-m-d'),
                'status' => $member['status'] ?? 'Active'
            ]
        ]);
        exit();
    }
    
    // No user found
    http_response_code(401);
    echo json_encode(['status' => false, 'error' => 'Invalid email or password']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'Login failed: ' . $e->getMessage()]);
}
?>
