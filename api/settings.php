<?php
// api/settings.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config/database.php';
$db = getDBConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// GET: Fetch all settings or specific setting
if ($method === 'GET') {
    try {
        // Check if a specific setting key is requested
        if (isset($_GET['key']) && !empty($_GET['key'])) {
            $key = $_GET['key'];
            $stmt = $db->prepare("SELECT * FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $setting = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($setting) {
                echo json_encode([
                    'status' => true, 
                    'data' => [
                        $setting['setting_key'] => json_decode($setting['setting_value'], true)
                    ]
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Setting not found']);
            }
            exit();
        }
        
        // Fetch all settings
        $stmt = $db->query("SELECT * FROM settings");
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($settings as $setting) {
            $result[$setting['setting_key']] = json_decode($setting['setting_value'], true);
        }
        
        // If loan settings don't exist, add default values
        if (!isset($result['loan'])) {
            $result['loan'] = [
                'min_membership_days' => 180,
                'max_borrow_percentage' => 50,
                'interest_rate' => 5,
                'max_duration_months' => 6,
                'min_loan_amount' => 100,
                'max_loan_amount' => 1000000,
                'enable_loan_requests' => true,
                'require_admin_approval' => true,
                'auto_approve_small_loans' => false,
                'small_loan_threshold' => 5000,
                'late_payment_penalty' => 10,
                'grace_period_days' => 7
            ];
            
            // Insert default loan settings into database
            $defaultLoanJson = json_encode($result['loan']);
            $insertStmt = $db->prepare("
                INSERT INTO settings (setting_key, setting_value) 
                VALUES ('loan', ?) 
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $insertStmt->execute([$defaultLoanJson, $defaultLoanJson]);
            $insertStmt->close();
        }
        
        echo json_encode(['status' => true, 'data' => $result]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch settings: ' . $e->getMessage()]);
    }
    exit();
}

// POST: Save settings
if ($method === 'POST') {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            exit();
        }
        
        // Validate data structure - ADDED 'loan' to allowed keys
        $allowedKeys = ['general', 'contribution', 'commission', 'payment', 'discount', 'security', 'suspension', 'loan'];
        $invalidKeys = array_diff(array_keys($data), $allowedKeys);
        
        if (!empty($invalidKeys)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid setting keys: ' . implode(', ', $invalidKeys)]);
            exit();
        }
        
        $db->beginTransaction();
        $updatedCount = 0;
        
        foreach ($data as $key => $value) {
            // Ensure value is an array
            if (!is_array($value)) {
                throw new Exception("Value for '$key' must be an object/array");
            }
            
            $jsonValue = json_encode($value);
            $stmt = $db->prepare("
                INSERT INTO settings (setting_key, setting_value) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $stmt->execute([$key, $jsonValue, $jsonValue]);
            $updatedCount += $stmt->rowCount();
        }
        
        $db->commit();
        echo json_encode([
            'status' => true, 
            'message' => 'Settings saved successfully',
            'updated' => $updatedCount,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save settings: ' . $e->getMessage()]);
    }
    exit();
}

// PUT: Update single setting
if ($method === 'PUT') {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data || !isset($data['key']) || !isset($data['value'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing key or value']);
            exit();
        }
        
        $key = $data['key'];
        $value = $data['value'];
        
        // Validate key - ADDED 'loan'
        $allowedKeys = ['general', 'contribution', 'commission', 'payment', 'discount', 'security', 'suspension', 'loan'];
        if (!in_array($key, $allowedKeys)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid setting key']);
            exit();
        }
        
        $jsonValue = json_encode($value);
        $stmt = $db->prepare("
            INSERT INTO settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = ?
        ");
        $stmt->execute([$key, $jsonValue, $jsonValue]);
        
        echo json_encode([
            'status' => true, 
            'message' => 'Setting updated successfully',
            'key' => $key
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update setting: ' . $e->getMessage()]);
    }
    exit();
}

// DELETE: Remove a setting
if ($method === 'DELETE') {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data || !isset($data['key'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Setting key required']);
            exit();
        }
        
        $key = $data['key'];
        $stmt = $db->prepare("DELETE FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        
        echo json_encode([
            'status' => true, 
            'message' => 'Setting deleted successfully',
            'key' => $key
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete setting: ' . $e->getMessage()]);
    }
    exit();
}

// If no method matched
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>