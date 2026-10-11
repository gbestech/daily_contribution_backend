<?php
// api/login.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

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

/* ================= SECURITY HELPERS ================= */

function passwordMatches($plainInput, $storedValue) {
    if ($storedValue === null || $storedValue === '') return false;

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
        return $plainInput === $storedValue;
    }

    return $plainInput === $storedValue;
}

function getSecuritySettings($db) {
    static $cached = null;
    if ($cached !== null) return $cached;

    $defaults = [
        'two_factor_auth'         => false,
        'session_timeout'         => 30,
        'max_login_attempts'      => 5,
        'password_expiry_days'    => 90,
        'require_strong_password' => true,
    ];

    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'security'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['setting_value'])) {
            $saved = json_decode($row['setting_value'], true);
            if (is_array($saved)) {
                $cached = array_merge($defaults, $saved);
                return $cached;
            }
        }
    } catch (Exception $e) { }

    $cached = $defaults;
    return $cached;
}

function isAccountLocked($row) {
    if (empty($row['locked_until'])) return false;
    return strtotime($row['locked_until']) > time();
}

function isPasswordExpired($row, $expiryDays) {
    if (empty($expiryDays) || $expiryDays <= 0) return false;
    if (empty($row['password_changed_at'])) return true;
    return (time() - strtotime($row['password_changed_at'])) > ($expiryDays * 86400);
}

function handleFailedAttempt($db, $table, $id, $maxAttempts) {
    if (!in_array($table, ['admins', 'members'], true)) return null;

    $sel = $db->prepare("SELECT failed_login_attempts FROM {$table} WHERE id = ? LIMIT 1");
    $sel->execute([$id]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    $attempts = ((int)($row['failed_login_attempts'] ?? 0)) + 1;

    if ($attempts >= $maxAttempts) {
        $lockedUntil = date('Y-m-d H:i:s', time() + 900);
        $upd = $db->prepare("UPDATE {$table} SET failed_login_attempts = ?, locked_until = ? WHERE id = ?");
        $upd->execute([$attempts, $lockedUntil, $id]);
        return [
            'locked'       => true,
            'locked_until' => $lockedUntil,
            'attempts'     => $attempts,
        ];
    }

    $upd = $db->prepare("UPDATE {$table} SET failed_login_attempts = ? WHERE id = ?");
    $upd->execute([$attempts, $id]);

    return [
        'locked'    => false,
        'attempts'  => $attempts,
        'remaining' => $maxAttempts - $attempts,
    ];
}

function resetFailedAttempts($db, $table, $id) {
    if (!in_array($table, ['admins', 'members'], true)) return;
    $upd = $db->prepare("UPDATE {$table} SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?");
    $upd->execute([$id]);
}

/**
 * Look up branch name/code for a given branch_id.
 */
function fetchBranchInfo($db, $branchId) {
    if (!$branchId) return null;
    try {
        $stmt = $db->prepare(
            "SELECT id, name, code, status FROM branches WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$branchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'id'     => (int)$row['id'],
            'name'   => $row['name']   ?? null,
            'code'   => $row['code']   ?? null,
            'status' => $row['status'] ?? null,
        ];
    } catch (Exception $e) {
        error_log("login.php fetchBranchInfo error: " . $e->getMessage());
        return null;
    }
}

/**
 * Copy branch fields (id/name/code) into a user payload.
 */
function attachBranchFields(array $userPayload, $db) {
    $branchId =
        $userPayload['branch_id'] ??
        $userPayload['branchId'] ??
        null;

    $branchId = $branchId !== null ? (int)$branchId : null;

    $userPayload['branch_id'] = $branchId;
    $userPayload['branch']    = null;

    if ($branchId) {
        $info = fetchBranchInfo($db, $branchId);
        if ($info) {
            $userPayload['branch']      = $info;
            $userPayload['branch_name'] = $info['name'];
            $userPayload['branch_code'] = $info['code'];
        }
    }

    return $userPayload;
}

/* ================= LOGIN FLOW ================= */

$security = getSecuritySettings($db);
$maxAttempts = (int)$security['max_login_attempts'];

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
        if (isAccountLocked($admin)) {
            http_response_code(423);
            echo json_encode([
                'status'       => false,
                'error'        => 'Account locked due to too many failed attempts. Try again later.',
                'locked_until' => $admin['locked_until'],
            ]);
            exit();
        }

        if (!passwordMatches($password, $admin['password'])) {
            $result = handleFailedAttempt($db, 'admins', (int)$admin['id'], $maxAttempts);

            if (!empty($result['locked'])) {
                http_response_code(423);
                echo json_encode([
                    'status'       => false,
                    'error'        => "Too many failed attempts. Account locked for 15 minutes.",
                    'locked_until' => $result['locked_until'],
                ]);
                exit();
            }

            http_response_code(401);
            echo json_encode([
                'status'    => false,
                'error'     => 'Invalid password. ' . $result['remaining'] . ' attempt(s) remaining.',
            ]);
            exit();
        }

        resetFailedAttempts($db, 'admins', (int)$admin['id']);

        $userPayload = [
            'id'                 => (int)$admin['id'],
            'username'           => $admin['username'] ?? '',
            'email'              => $admin['email'] ?? '',
            'full_name'          => $admin['full_name'] ?? $admin['username'] ?? '',
            'name'               => $admin['full_name'] ?? $admin['username'] ?? '',
            'role'               => $admin['role'] ?? 'administrator',
            'branch_id'          => isset($admin['branch_id']) ? (int)$admin['branch_id'] : null,
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
        ];

        $userPayload = attachBranchFields($userPayload, $db);

        echo json_encode([
            'status'  => true,
            'message' => 'Login successful',
            'token'   => 'admin_token_' . time(),
            'user'    => $userPayload,
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
        if (isAccountLocked($member)) {
            http_response_code(423);
            echo json_encode([
                'status'       => false,
                'error'        => 'Account locked due to too many failed attempts. Try again later.',
                'locked_until' => $member['locked_until'],
            ]);
            exit();
        }

        if (($member['status'] ?? '') === 'Suspended') {
            http_response_code(403);
            echo json_encode([
                'status' => false,
                'error'  => 'Account suspended. Contact admin.',
                'reason' => $member['suspension_reason'] ?? null,
            ]);
            exit();
        }

        if (!passwordMatches($password, $member['password'] ?? '')) {
            $result = handleFailedAttempt($db, 'members', (int)$member['id'], $maxAttempts);

            if (!empty($result['locked'])) {
                http_response_code(423);
                echo json_encode([
                    'status'       => false,
                    'error'        => "Too many failed attempts. Account locked for 15 minutes.",
                    'locked_until' => $result['locked_until'],
                ]);
                exit();
            }

            http_response_code(401);
            echo json_encode([
                'status'    => false,
                'error'     => 'Invalid password. ' . $result['remaining'] . ' attempt(s) remaining.',
            ]);
            exit();
        }

        resetFailedAttempts($db, 'members', (int)$member['id']);

        $passwordExpired = isPasswordExpired($member, (int)$security['password_expiry_days']);

        $userPayload = [
            'id'                 => (int)$member['id'],
            'username'           => $member['name'] ?? '',
            'name'               => $member['name'] ?? '',
            'full_name'          => $member['name'] ?? '',
            'email'              => $member['email'] ?? '',
            'phone'              => $member['phone'] ?? '',
            'role'               => $member['role'] ?? 'member',
            'branch_id'          => isset($member['branch_id']) ? (int)$member['branch_id'] : null,
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
        ];

        $userPayload = attachBranchFields($userPayload, $db);

        echo json_encode([
            'status'  => true,
            'message' => 'Login successful',
            'token'   => 'member_token_' . time(),
            'password_expired' => $passwordExpired,
            'user'    => $userPayload,
        ]);
        exit();
    }

    http_response_code(401);
    echo json_encode(['status' => false, 'error' => 'Invalid email or password']);

} catch (Exception $e) {
    error_log("login.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'Login failed. Please try again.']);
}