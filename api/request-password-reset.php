<?php
// api/request-password-reset.php
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
    echo json_encode(['status' => false, 'error' => 'Method not allowed']);
    exit();
}

require_once __DIR__ . '/config/database.php';
$db = getDBConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'Database connection failed']);
    exit();
}

// ---- Read input ----
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$email = strtolower(trim($data['email'] ?? ''));

// ---- Validate email format ----
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        'status' => false,
        'error'  => 'Please enter a valid email address.'
    ]);
    exit();
}

try {
    // ---- 1) Check if the email exists in admins OR members ----
    $userType = null;

    $stmt = $db->prepare("SELECT id FROM admins WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        $userType = 'admin';
    } else {
        $stmt = $db->prepare("SELECT id FROM members WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            $userType = 'member';
        }
    }

    // ---- 2) If no account found, we still return success ----
    // This is a security measure: we don't reveal which emails exist.
    if (!$userType) {
        echo json_encode([
            'status'  => true,
            'message' => 'If an account with that email exists, an admin will reset your password shortly.'
        ]);
        exit();
    }

    // ---- 3) Check if there's already a pending request for this email ----
    $stmt = $db->prepare(
        "SELECT id FROM password_reset_requests
         WHERE LOWER(email) = LOWER(?) AND status = 'pending'
         LIMIT 1"
    );
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo json_encode([
            'status'  => true,
            'message' => 'A reset request is already pending for this email. Please wait for an admin to respond.'
        ]);
        exit();
    }

    // ---- 4) Insert the new request ----
    $stmt = $db->prepare(
        "INSERT INTO password_reset_requests (email, user_type, status)
         VALUES (?, ?, 'pending')"
    );
    $stmt->execute([$email, $userType]);

    echo json_encode([
        'status'  => true,
        'message' => 'Your request has been received. An admin will reset your password shortly.'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'error'  => 'Request failed: ' . $e->getMessage()
    ]);
}