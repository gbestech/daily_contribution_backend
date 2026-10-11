<?php
// api/settings.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

while (ob_get_level()) ob_end_clean();

// ============================================================
// CORS — reflect the exact origin. Never combine `*` with
//       credentials. Allow the custom headers the frontend
//       sends (X-User-Role, X-User-Branch-Id).
// ============================================================
$allowedOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedList = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:5174',
    'http://127.0.0.1:5174',
    // add your production origin here
];

if (in_array($allowedOrigin, $allowedList, true)) {
    header("Access-Control-Allow-Origin: $allowedOrigin");
    header("Access-Control-Allow-Credentials: true");
} else if (empty($_COOKIE)) {
    // Safe fallback for anonymous requests: no credentials involved.
    header("Access-Control-Allow-Origin: *");
}
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-User-Role, X-User-Branch-Id");
header("Access-Control-Max-Age: 86400");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header("Content-Type: application/json; charset=UTF-8");

// Optional: uncomment to log every request while debugging.
// file_put_contents(
//     __DIR__ . '/cors_debug.log',
//     date('c') . ' ' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' .
//     ($_SERVER['REQUEST_URI'] ?? '?') . ' origin=' .
//     ($_SERVER['HTTP_ORIGIN'] ?? '(none)') . "\n",
//     FILE_APPEND
// );

// Answer preflight immediately, before touching the DB or body.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config/database.php';

try {
    $db = getDBConnection();
} catch (Exception $e) {
    error_log("settings.php DB connect error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'Database connection failed']);
    exit();
}

// ============================================================
// Resolve which branch this request is for.
// Priority: ?branch_id= query → first active branch → 1
// ============================================================
$requestedBranchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

if ($requestedBranchId <= 0) {
    try {
        $row = $db
            ->query("SELECT id FROM branches WHERE status = 'active' ORDER BY id ASC LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);
        $requestedBranchId = $row ? (int)$row['id'] : 1;
    } catch (Exception $e) {
        $requestedBranchId = 1;
    }
}

$BRANCH_ID = $requestedBranchId;
$method = $_SERVER['REQUEST_METHOD'];

$ALLOWED_KEYS = [
    'general',
    'contribution',
    'commission',
    'payment',
    'discount',
    'security',
    'suspension',
    'loan',
    'charges',
];

// ============================================================
// GET — return every setting for this branch.
// ============================================================
if ($method === 'GET') {
    try {
        // Single-key fetch
        if (isset($_GET['key']) && $_GET['key'] !== '') {
            $key = $_GET['key'];
            $stmt = $db->prepare("SELECT * FROM settings WHERE setting_key = ? AND branch_id = ? LIMIT 1");
            $stmt->execute([$key, $BRANCH_ID]);
            $setting = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($setting) {
                echo json_encode([
                    'status'    => true,
                    'branch_id' => $BRANCH_ID,
                    'data'      => [
                        $setting['setting_key'] => json_decode($setting['setting_value'], true),
                    ],
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['status' => false, 'error' => 'Setting not found']);
            }
            exit();
        }

        // Full fetch for this branch
        $stmt = $db->prepare("SELECT * FROM settings WHERE branch_id = ?");
        $stmt->execute([$BRANCH_ID]);
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($settings as $setting) {
            $result[$setting['setting_key']] = json_decode($setting['setting_value'], true);
        }

        // ---- 'general' fallback from the branch's own record ----
        if (!isset($result['general'])) {
            $template = [
                'shop_name'    => '',
                'shop_email'   => '',
                'shop_phone'   => '',
                'shop_address' => '',
                'currency'     => 'NGN',
                'timezone'     => 'Africa/Lagos',
                'date_format'  => 'DD/MM/YYYY',
            ];

            try {
                $b = $db->prepare("SELECT name, email, phone, address FROM branches WHERE id = ? LIMIT 1");
                $b->execute([$BRANCH_ID]);
                $branch = $b->fetch(PDO::FETCH_ASSOC);
                if ($branch) {
                    $template['shop_name']    = $branch['name']    ?? '';
                    $template['shop_email']   = $branch['email']   ?? '';
                    $template['shop_phone']   = $branch['phone']   ?? '';
                    $template['shop_address'] = $branch['address'] ?? '';
                }
            } catch (Exception $e) {
                error_log("settings.php branch template error: " . $e->getMessage());
            }

            $result['general'] = $template;
        }

        // ---- ensure 'loan' exists ----
        if (!isset($result['loan'])) {
            $result['loan'] = [
                'min_membership_days'      => 180,
                'max_borrow_percentage'    => 50,
                'interest_rate'            => 5,
                'max_duration_months'      => 6,
                'min_loan_amount'          => 100,
                'max_loan_amount'          => 1000000,
                'enable_loan_requests'     => true,
                'require_admin_approval'   => true,
                'auto_approve_small_loans' => false,
                'small_loan_threshold'     => 5000,
                'late_payment_penalty'     => 10,
                'grace_period_days'        => 7,
            ];
            $json = json_encode($result['loan']);
            $ins = $db->prepare("
                INSERT INTO settings (setting_key, setting_value, branch_id)
                VALUES ('loan', ?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $ins->execute([$json, $BRANCH_ID]);
        }

        // ---- ensure 'charges' exists ----
        if (!isset($result['charges'])) {
            $result['charges'] = [
                'enabled'              => true,
                'threshold_amount'     => 200000,
                'below_threshold_rate' => 1.0,
                'above_threshold_rate' => 0.4,
            ];
            $json = json_encode($result['charges']);
            $ins = $db->prepare("
                INSERT INTO settings (setting_key, setting_value, branch_id)
                VALUES ('charges', ?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $ins->execute([$json, $BRANCH_ID]);
        }

        echo json_encode([
            'status'    => true,
            'branch_id' => $BRANCH_ID,
            'data'      => $result,
        ]);
    } catch (Exception $e) {
        error_log("settings.php GET error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => false, 'error' => 'Failed to fetch settings']);
    }
    exit();
}

// ============================================================
// POST — save every section the client sent.
// ============================================================
if ($method === 'POST') {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['status' => false, 'error' => 'Invalid JSON data']);
            exit();
        }

        // branch_id may arrive in the body — read it, then strip it
        // so it's never treated as a setting section.
        if (
            isset($data['branch_id']) &&
            (int)$data['branch_id'] > 0 &&
            !isset($_GET['branch_id'])
        ) {
            $BRANCH_ID = (int)$data['branch_id'];
        }
        unset($data['branch_id']);

        $invalidKeys = array_diff(array_keys($data), $ALLOWED_KEYS);
        if (!empty($invalidKeys)) {
            http_response_code(400);
            echo json_encode([
                'status' => false,
                'error'  => 'Invalid setting keys: ' . implode(', ', $invalidKeys),
            ]);
            exit();
        }

        $db->beginTransaction();
        $updatedCount = 0;

        $stmt = $db->prepare("
            INSERT INTO settings (setting_key, setting_value, branch_id)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");

        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                throw new Exception("Value for '$key' must be an object/array");
            }
            $jsonValue = json_encode($value);
            $stmt->execute([$key, $jsonValue, $BRANCH_ID]);
            $updatedCount += $stmt->rowCount();
        }

        $db->commit();

        echo json_encode([
            'status'    => true,
            'message'   => 'Settings saved successfully',
            'branch_id' => $BRANCH_ID,
            'updated'   => $updatedCount,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("settings.php POST error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => false, 'error' => 'Failed to save settings']);
    }
    exit();
}

// ============================================================
// PUT — update one section.
// ============================================================
if ($method === 'PUT') {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!is_array($data) || !isset($data['key']) || !isset($data['value'])) {
            http_response_code(400);
            echo json_encode(['status' => false, 'error' => 'Missing key or value']);
            exit();
        }

        $key   = $data['key'];
        $value = $data['value'];

        if (!in_array($key, $ALLOWED_KEYS, true)) {
            http_response_code(400);
            echo json_encode(['status' => false, 'error' => 'Invalid setting key']);
            exit();
        }

        $jsonValue = json_encode($value);
        $stmt = $db->prepare("
            INSERT INTO settings (setting_key, setting_value, branch_id)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$key, $jsonValue, $BRANCH_ID]);

        echo json_encode([
            'status'    => true,
            'message'   => 'Setting updated successfully',
            'key'       => $key,
            'branch_id' => $BRANCH_ID,
        ]);
    } catch (Exception $e) {
        error_log("settings.php PUT error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => false, 'error' => 'Failed to update setting']);
    }
    exit();
}

// ============================================================
// DELETE — remove one section for a branch.
// ============================================================
if ($method === 'DELETE') {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!is_array($data) || !isset($data['key'])) {
            http_response_code(400);
            echo json_encode(['status' => false, 'error' => 'Setting key required']);
            exit();
        }

        $key = $data['key'];
        $stmt = $db->prepare("DELETE FROM settings WHERE setting_key = ? AND branch_id = ?");
        $stmt->execute([$key, $BRANCH_ID]);

        echo json_encode([
            'status'    => true,
            'message'   => 'Setting deleted successfully',
            'key'       => $key,
            'branch_id' => $BRANCH_ID,
        ]);
    } catch (Exception $e) {
        error_log("settings.php DELETE error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => false, 'error' => 'Failed to delete setting']);
    }
    exit();
}

http_response_code(405);
echo json_encode(['status' => false, 'error' => 'Method not allowed']);