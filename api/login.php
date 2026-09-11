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

$email    = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['status' => false, 'error' => 'Email and password are required']);
    exit();
}

/**
 * Universal password check — works with BOTH hashed and plain values
 */
function passwordMatches($plainInput, $storedValue) {
    if ($storedValue === null || $storedValue === '') return false;

    // Detect bcrypt / argon hash
    $looksHashed =
        strlen($storedValue) >= 20 &&
        (
            str_starts_with($storedValue, '$2y$') ||
            str_starts_with($storedValue, '$2a$') ||
            str_starts_with($storedValue, '$2b$') ||
            str_starts_with($storedValue, '$argon2')
        );

    if ($looksHashed) {
        if (password_verify($plainInput, $storedValue)) return true;
        // fallback (dev only): allow plain match against the hash string
        return $plainInput === $storedValue;
    }

    // Plain-text compare
    return $plainInput === $storedValue;
}

try {
    // ---------- 1) ADMINS TABLE ----------
    $stmt = $db->prepare(
        "SELECT * FROM admins
         WHERE LOWER(email) = LOWER(?)
            OR LOWER(username) = LOWER(?)
            OR phone = ?
         LIMIT 1"
    );
    $stmt->execute([$email, $email, $email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin) {
        if (!passwordMatches($password, $admin['password'])) {
            http_response_code(401);
            echo json_encode(['status' => false, 'error' => 'Invalid password']);
            exit();
        }

        echo json_encode([
            'status' => true,
            'message' => 'Login successful',
            'token' => 'admin_token_' . time(),
            'user' => [
                'id'                 => (int)$admin['id'],
                'username'           => $admin['username'] ?? '',
                'email'              => $admin['email'] ?? '',
                'full_name'          => $admin['full_name'] ?? $admin['username'] ?? '',
                'name'               => $admin['full_name'] ?? $admin['username'] ?? '',
                'role'               => $admin['role'] ?? 'administrator',
                'phone'              => $admin['phone'] ?? '',
                'address'            => '',
                'is_active'          => true,
                'balance'            => 0,
                'accountNumber'      => 'ADMIN001',
                'account_number'     => 'ADMIN001',
                'membership_number'  => 'ADMIN001',
                'membershipType'     => 'Standard',
                'membership_type'    => 'Standard',
                'joinDate'           => date('Y-m-d'),
                'join_date'          => date('Y-m-d'),
                'status'             => 'Active',
                'profile_completed'  => 1,
                'passport_photo'     => null,
                'created_at'         => $admin['created_at'] ?? date('Y-m-d H:i:s'),
            ],
        ]);
        exit();
    }

    // ---------- 2) MEMBERS TABLE ----------
    $stmt = $db->prepare(
        "SELECT * FROM members
         WHERE LOWER(email) = LOWER(?)
            OR LOWER(name) = LOWER(?)
            OR phone = ?
         LIMIT 1"
    );
    $stmt->execute([$email, $email, $email]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($member) {
        if (!passwordMatches($password, $member['password'] ?? '')) {
            http_response_code(401);
            echo json_encode(['status' => false, 'error' => 'Invalid password']);
            exit();
        }

        echo json_encode([
            'status' => true,
            'message' => 'Login successful',
            'token' => 'member_token_' . time(),
            'user' => [
                'id'                 => (int)$member['id'],
                'username'           => $member['name'] ?? '',
                'name'               => $member['name'] ?? '',
                'full_name'          => $member['name'] ?? '',
                'email'              => $member['email'] ?? '',
                'phone'              => $member['phone'] ?? '',
                'role'               => $member['role'] ?? 'member',
                'address'            => $member['address'] ?? '',
                'is_active'          => ($member['status'] ?? '') === 'Active',
                'balance'            => floatval($member['balance'] ?? 0),
                'accountNumber'      => $member['account_number'] ?? 'N/A',
                'account_number'     => $member['account_number'] ?? 'N/A',
                'membership_number'  => $member['account_number'] ?? 'N/A',
                'membershipType'     => $member['membership_type'] ?? 'Standard',
                'membership_type'    => $member['membership_type'] ?? 'Standard',
                'joinDate'           => $member['join_date'] ?? date('Y-m-d'),
                'join_date'          => $member['join_date'] ?? date('Y-m-d'),
                'status'             => $member['status'] ?? 'Active',
                'profile_completed'  => (int)($member['profile_completed'] ?? 0),
                'passport_photo'     => $member['passport_photo'] ?? null,
                'created_at'         => $member['created_at'] ?? date('Y-m-d H:i:s'),
            ],
        ]);
        exit();
    }

    http_response_code(401);
    echo json_encode(['status' => false, 'error' => 'Invalid email or password']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'Login failed: ' . $e->getMessage()]);
}