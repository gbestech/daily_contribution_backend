<?php
// api/settings.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

while (ob_get_level()) ob_end_clean();

// ============================================================
// CORS
// ============================================================
$allowedOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedList = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:5174',
    'http://127.0.0.1:5174',
];
if (in_array($allowedOrigin, $allowedList, true)) {
    header("Access-Control-Allow-Origin: $allowedOrigin");
    header("Access-Control-Allow-Credentials: true");
} else if (empty($_COOKIE)) {
    header("Access-Control-Allow-Origin: *");
}
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-User-Role, X-User-Branch-Id");
header("Access-Control-Max-Age: 86400");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config/database.php';

try {
    $db = getDBConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    error_log("settings.php DB connect error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'Database connection failed']);
    exit();
}

// ============================================================
// Resolve branch id (URL wins; body may override for POST)
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
    'items',
];

// ============================================================
// Helpers
// ============================================================
function sanitizeSavingsMethods($value) {
    return in_array($value, ['cash', 'item', 'both'], true) ? $value : 'both';
}

/**
 * Default "items" settings.
 */
function defaultItems() {
    return [
        'durations'              => ['6 months', '1 year'],
        'savings_plans'          => ['Daily', 'Weekly', 'Monthly', 'Random'],
        'allow_multi_item'       => true,
        'money_check_mode'       => 'strict',
        'require_duration_match' => true,
        'savings_methods'        => 'both',
    ];
}

/**
 * Clean a list of strings: trim, drop blanks, drop duplicates, reindex.
 */
function cleanStringList($list) {
    if (!is_array($list)) return [];
    $out = [];
    foreach ($list as $v) {
        $s = trim((string)$v);
        if ($s !== '' && !in_array($s, $out, true)) {
            $out[] = $s;
        }
    }
    return $out;
}

/**
 * Normalise the "items" settings block.
 * $fillDefaults = true  → empty durations / plans fall back to defaults (used when READING)
 * $fillDefaults = false → empty lists stay empty (used when VALIDATING a save)
 */
function normalizeItems($items, $fillDefaults = true) {
    $defaults = defaultItems();
    if (!is_array($items)) $items = [];

    $out = array_merge($defaults, $items);

    $out['durations']     = cleanStringList($items['durations']     ?? $defaults['durations']);
    $out['savings_plans'] = cleanStringList($items['savings_plans'] ?? $defaults['savings_plans']);

    if ($fillDefaults) {
        if (count($out['durations']) === 0)     $out['durations']     = $defaults['durations'];
        if (count($out['savings_plans']) === 0) $out['savings_plans'] = $defaults['savings_plans'];
    }

    $out['allow_multi_item']       = filter_var($out['allow_multi_item'], FILTER_VALIDATE_BOOLEAN);
    $out['require_duration_match'] = filter_var($out['require_duration_match'], FILTER_VALIDATE_BOOLEAN);
    $out['money_check_mode']       = in_array($out['money_check_mode'], ['strict', 'min', 'off'], true)
        ? $out['money_check_mode']
        : 'strict';
    $out['savings_methods']        = sanitizeSavingsMethods($out['savings_methods'] ?? null);

    return $out;
}

/**
 * Save one settings section for one branch.
 * Does NOT rely on a UNIQUE index: it explicitly checks (setting_key, branch_id),
 * then UPDATEs or INSERTs. A row that belongs to another branch is never touched.
 */
function upsertSetting(PDO $db, $key, array $value, $branchId) {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);

    $chk = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ? AND branch_id = ?");
    $chk->execute([$key, $branchId]);

    if ((int)$chk->fetchColumn() > 0) {
        $u = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ? AND branch_id = ?");
        $u->execute([$json, $key, $branchId]);
    } else {
        $i = $db->prepare("INSERT INTO settings (setting_key, setting_value, branch_id) VALUES (?, ?, ?)");
        $i->execute([$key, $json, $branchId]);
    }
}

/**
 * Insert a default row ONLY if the branch has none. Never overwrites anything.
 */
function insertSettingIfMissing(PDO $db, $key, array $value, $branchId) {
    try {
        $chk = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ? AND branch_id = ?");
        $chk->execute([$key, $branchId]);
        if ((int)$chk->fetchColumn() === 0) {
            $i = $db->prepare("INSERT INTO settings (setting_key, setting_value, branch_id) VALUES (?, ?, ?)");
            $i->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE), $branchId]);
        }
    } catch (Exception $e) {
        error_log("settings.php insertSettingIfMissing($key) failed: " . $e->getMessage());
    }
}

function uniqueIndexHint() {
    return "The settings table has a UNIQUE index on setting_key only. It must be unique on "
         . "(setting_key, branch_id). Run: SHOW INDEX FROM settings; then drop the old unique index "
         . "and run: ALTER TABLE settings ADD UNIQUE KEY uniq_key_branch (setting_key, branch_id);";
}

// ============================================================
// GET
// ============================================================
if ($method === 'GET') {
    try {
        if (isset($_GET['key']) && $_GET['key'] !== '') {
            $key = $_GET['key'];
            $stmt = $db->prepare("SELECT * FROM settings WHERE setting_key = ? AND branch_id = ? LIMIT 1");
            $stmt->execute([$key, $BRANCH_ID]);
            $setting = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($setting) {
                $val = json_decode($setting['setting_value'], true);
                if ($key === 'items') $val = normalizeItems($val);
                echo json_encode([
                    'status'    => true,
                    'branch_id' => $BRANCH_ID,
                    'data'      => [$setting['setting_key'] => $val],
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['status' => false, 'error' => 'Setting not found']);
            }
            exit();
        }

        $stmt = $db->prepare("SELECT * FROM settings WHERE branch_id = ?");
        $stmt->execute([$BRANCH_ID]);
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($settings as $setting) {
            $result[$setting['setting_key']] = json_decode($setting['setting_value'], true);
        }

        // general fallback (from the branch record) — not persisted
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

        // loan fallback
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
            insertSettingIfMissing($db, 'loan', $result['loan'], $BRANCH_ID);
        }

        // charges fallback
        if (!isset($result['charges'])) {
            $result['charges'] = [
                'enabled'              => true,
                'threshold_amount'     => 200000,
                'below_threshold_rate' => 1.0,
                'above_threshold_rate' => 0.4,
            ];
            insertSettingIfMissing($db, 'charges', $result['charges'], $BRANCH_ID);
        }

        // items fallback
        if (!isset($result['items']) || !is_array($result['items'])) {
            $result['items'] = defaultItems();
            insertSettingIfMissing($db, 'items', $result['items'], $BRANCH_ID);
        }

        // Always return a clean, complete items block
        $result['items'] = normalizeItems($result['items']);

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
// POST — save sections
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

        // Optional branch_id inside body — only if URL didn't have one
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

        // Validate + normalise "items"
        $savedItems = null;
        if (isset($data['items'])) {
            if (!is_array($data['items'])) {
                http_response_code(400);
                echo json_encode(['status' => false, 'error' => "Value for 'items' must be an object"]);
                exit();
            }
            $clean = normalizeItems($data['items'], false);

            if (count($clean['durations']) === 0) {
                http_response_code(400);
                echo json_encode(['status' => false, 'error' => 'At least one duration must be enabled']);
                exit();
            }
            if (count($clean['savings_plans']) === 0) {
                http_response_code(400);
                echo json_encode(['status' => false, 'error' => 'At least one savings plan must be enabled']);
                exit();
            }

            $data['items'] = $clean;
            $savedItems = $clean;
        }

        $db->beginTransaction();

        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                throw new Exception("Value for '$key' must be an object/array");
            }
            upsertSetting($db, $key, $value, $BRANCH_ID);
        }

        $db->commit();

        echo json_encode([
            'status'    => true,
            'message'   => 'Settings saved successfully',
            'branch_id' => $BRANCH_ID,
            'updated'   => count($data),
            'items'     => $savedItems,   // authoritative copy of what was stored
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("settings.php POST PDO error: " . $e->getMessage());
        http_response_code(500);
        $isDup = ($e->getCode() === '23000');
        echo json_encode([
            'status' => false,
            'error'  => $isDup ? uniqueIndexHint() : 'Failed to save settings',
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
// PUT — update one section
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
        if (!is_array($value)) {
            http_response_code(400);
            echo json_encode(['status' => false, 'error' => 'Value must be an object/array']);
            exit();
        }

        if ($key === 'items') {
            $value = normalizeItems($value, false);
            if (count($value['durations']) === 0 || count($value['savings_plans']) === 0) {
                http_response_code(400);
                echo json_encode(['status' => false, 'error' => 'At least one duration and one savings plan must be enabled']);
                exit();
            }
        }

        upsertSetting($db, $key, $value, $BRANCH_ID);

        echo json_encode([
            'status'    => true,
            'message'   => 'Setting updated successfully',
            'key'       => $key,
            'branch_id' => $BRANCH_ID,
            'value'     => $value,
        ]);
    } catch (PDOException $e) {
        error_log("settings.php PUT PDO error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status' => false,
            'error'  => ($e->getCode() === '23000') ? uniqueIndexHint() : 'Failed to update setting',
        ]);
    } catch (Exception $e) {
        error_log("settings.php PUT error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => false, 'error' => 'Failed to update setting']);
    }
    exit();
}

// ============================================================
// DELETE — remove one section
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
