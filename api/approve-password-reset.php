<?php
// api/approve-password-reset.php
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

$input = file_get_contents('php://input');
$data = json_decode($input, true);

$requestId = (int)($data['request_id'] ?? 0);
$handledBy = trim($data['handled_by'] ?? 'Admin');

if ($requestId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => false, 'error' => 'Request ID is required']);
    exit();
}

/**
 * Generate a temporary password
 * Format: Tmp@ + 6 random alphanumeric chars + !  (e.g. Tmp@a7B9kQ!)
 */
function generateTempPassword($length = 8) {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return 'Tmp@' . $password . '!';
}

try {
    // ---- 1) Fetch the request row ----
    $stmt = $db->prepare(
        "SELECT id, email, user_type, status
         FROM password_reset_requests
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        http_response_code(404);
        echo json_encode(['status' => false, 'error' => 'Request not found']);
        exit();
    }

    if ($request['status'] === 'completed') {
        http_response_code(400);
        echo json_encode(['status' => false, 'error' => 'This request has already been completed']);
        exit();
    }

    $email = $request['email'];
    $userType = $request['user_type']; // 'admin' or 'member'

    // ---- 2) Verify the user still exists ----
    $table = $userType === 'admin' ? 'admins' : 'members';

    // Only these table names are allowed — prevents SQL injection
    if (!in_array($table, ['admins', 'members'], true)) {
        http_response_code(400);
        echo json_encode(['status' => false, 'error' => 'Invalid user type']);
        exit();
    }

    $check = $db->prepare("SELECT id FROM {$table} WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $check->execute([$email]);
    $user = $check->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // User was deleted after requesting. Mark request as rejected.
        $upd = $db->prepare(
            "UPDATE password_reset_requests
             SET status = 'rejected', handled_at = NOW(), handled_by = ?, notes = ?
             WHERE id = ?"
        );
        $upd->execute([$handledBy, 'User no longer exists', $requestId]);

        http_response_code(404);
        echo json_encode([
            'status' => false,
            'error'  => 'The user account no longer exists. Request marked as rejected.'
        ]);
        exit();
    }

    // ---- 3) Generate a temporary password ----
    $tempPassword = generateTempPassword(8);
    $hashed = password_hash($tempPassword, PASSWORD_DEFAULT);

    // ---- 4) Update the user's password + reset lockout ----
    $upd = $db->prepare(
        "UPDATE {$table}
         SET password = ?,
             failed_login_attempts = 0,
             locked_until = NULL
         WHERE id = ?"
    );
    $upd->execute([$hashed, $user['id']]);

    // ---- 5) Mark the request as completed ----
    $markDone = $db->prepare(
        "UPDATE password_reset_requests
         SET status = 'completed',
             handled_at = NOW(),
             handled_by = ?,
             notes = ?
         WHERE id = ?"
    );
    $markDone->execute([
        $handledBy,
        'Password reset by ' . $handledBy,
        $requestId,
    ]);

    // ---- 6) Return the temp password to the admin ----
    echo json_encode([
        'status'        => true,
        'email'         => $email,
        'user_type'     => $userType,
        'temp_password' => $tempPassword,
        'message'       => 'Password reset. Share this temporary password with the user.',
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'error'  => 'Failed to reset password: ' . $e->getMessage(),
    ]);
}