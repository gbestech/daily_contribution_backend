<?php
// api/admin.php
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

// For debugging - log the request
error_log("Admin API called: " . $requestUri);

// Handle GET request for profile
if ($method === 'GET') {
    // Get first admin
    $stmt = $db->prepare("SELECT id, username, email, full_name, phone, role FROM admins LIMIT 1");
    $stmt->execute();
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($admin) {
        echo json_encode($admin);
    } else {
        // Return default admin data if none exists
        echo json_encode([
            'id' => 1,
            'username' => 'admin',
            'email' => 'admin@admin.com',
            'full_name' => 'Admin User',
            'phone' => '',
            'role' => 'administrator'
        ]);
    }
    exit();
}

// Handle PUT request for updating admin
if ($method === 'PUT') {
    try {
        // Get the ID from the URL
        $pathParts = explode('/', $requestUri);
        $id = null;
        foreach ($pathParts as $part) {
            if (is_numeric($part)) {
                $id = $part;
                break;
            }
        }
        
        // If no ID found, use 1 as default
        if (!$id) {
            $id = 1;
        }
        
        error_log("Updating admin with ID: " . $id);
        
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        error_log("Received data: " . print_r($data, true));
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            exit();
        }
        
        // Check if admin exists
        $stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->execute([$id]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin) {
            // Insert default admin if not exists
            error_log("Admin not found, creating default");
            $stmt = $db->prepare("
                INSERT INTO admins (id, username, email, full_name, phone, role) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$id, 'admin', 'admin@admin.com', 'Admin User', '', 'administrator']);
            
            // Refetch
            $stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
            $stmt->execute([$id]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Build update query
        $updateFields = [];
        $params = [];
        
        if (isset($data['username']) && !empty($data['username'])) {
            $updateFields[] = "username = ?";
            $params[] = $data['username'];
        }
        
        if (isset($data['email']) && !empty($data['email'])) {
            $updateFields[] = "email = ?";
            $params[] = $data['email'];
        }
        
        if (isset($data['full_name']) && !empty($data['full_name'])) {
            $updateFields[] = "full_name = ?";
            $params[] = $data['full_name'];
        }
        
        if (isset($data['phone'])) {
            $updateFields[] = "phone = ?";
            $params[] = $data['phone'];
        }
        
        if (isset($data['password']) && !empty($data['password'])) {
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            $updateFields[] = "password = ?";
            $params[] = $hashedPassword;
        }
        
        if (empty($updateFields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            exit();
        }
        
        $params[] = $id;
        $sql = "UPDATE admins SET " . implode(", ", $updateFields) . " WHERE id = ?";
        
        error_log("Update SQL: " . $sql);
        error_log("Params: " . print_r($params, true));
        
        $stmt = $db->prepare($sql);
        $result = $stmt->execute($params);
        
        if ($result) {
            // Fetch updated admin
            $stmt = $db->prepare("SELECT id, username, email, full_name, phone, role FROM admins WHERE id = ?");
            $stmt->execute([$id]);
            $updatedAdmin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => true,
                'message' => 'Admin updated successfully',
                'admin' => $updatedAdmin
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update admin']);
        }
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        error_log("Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// If no route matched
http_response_code(404);
echo json_encode(['error' => 'Endpoint not found']);
?>
