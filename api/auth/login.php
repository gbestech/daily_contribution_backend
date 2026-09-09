<?php
// api/login.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed"]);
    exit();
}

// Database connection
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "baleeg_db";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database connection failed"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);
$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Email and password required"]);
    exit();
}

// ================================================================
// FIRST: CHECK ADMINS TABLE
// ================================================================
$sql = "SELECT * FROM admins WHERE email = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    
    $passwordValid = false;
    $storedPassword = $user['password'];
    
    // DEBUG: Log what we're checking
    error_log("Checking password for admin: " . $email);
    error_log("Stored password: " . substr($storedPassword, 0, 20) . "...");
    error_log("Input password: " . $password);
    
    // Check if stored password is hashed (starts with $2y$)
    if (strpos($storedPassword, '$2y$') === 0) {
        // This is a hashed password - use password_verify
        if (password_verify($password, $storedPassword)) {
            $passwordValid = true;
            error_log("Password verified with password_verify!");
        } else {
            error_log("password_verify failed");
        }
    } else {
        // This is a plain text password - direct comparison
        if ($password === $storedPassword) {
            $passwordValid = true;
            error_log("Password matched directly!");
            // Upgrade to hashed password
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $updateStmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
            $updateStmt->bind_param("si", $newHash, $user['id']);
            $updateStmt->execute();
            $updateStmt->close();
            error_log("Password upgraded to hash: " . $newHash);
        } else {
            error_log("Direct comparison failed");
        }
    }
    
    if ($passwordValid) {
        // Get member data if exists
        $balance = 0;
        $accountNumber = 'ADMIN-' . $user['id'];
        $memberStmt = $conn->prepare("SELECT * FROM members WHERE email = ?");
        $memberStmt->bind_param("s", $email);
        $memberStmt->execute();
        $memberResult = $memberStmt->get_result();
        
        if ($memberResult->num_rows > 0) {
            $member = $memberResult->fetch_assoc();
            $balance = floatval($member['balance'] ?? 0);
            $accountNumber = $member['account_number'] ?? 'ADMIN-' . $user['id'];
        }
        $memberStmt->close();
        
        $userData = [
            'id' => (int)$user['id'],
            'username' => $user['username'] ?? $user['full_name'] ?? 'Admin',
            'name' => $user['full_name'] ?? $user['username'] ?? 'Admin',
            'full_name' => $user['full_name'] ?? $user['username'] ?? 'Admin',
            'email' => $user['email'],
            'phone' => $user['phone'] ?? '',
            'role' => $user['role'] ?? 'admin',
            'status' => 'Active',
            'balance' => $balance,
            'accountNumber' => $accountNumber,
            'account_number' => $accountNumber,
            'is_active' => 1,
            'isAdmin' => true
        ];
        
        $token = bin2hex(random_bytes(32));
        
        http_response_code(200);
        echo json_encode([
            "status" => true,
            "message" => "Login successful",
            "user" => $userData,
            "token" => $token
        ]);
        exit();
    } else {
        error_log("Admin password validation failed for: " . $email);
    }
}
$stmt->close();

// ================================================================
// SECOND: CHECK MEMBERS TABLE
// ================================================================
$sql = "SELECT * FROM members WHERE email = ? OR phone = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $email, $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    
    $passwordValid = false;
    $storedPassword = $user['password'];
    
    // Check if stored password is hashed
    if (strpos($storedPassword, '$2y$') === 0) {
        if (password_verify($password, $storedPassword)) {
            $passwordValid = true;
        }
    } else {
        if ($password === $storedPassword) {
            $passwordValid = true;
            // Upgrade to hashed
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $updateStmt = $conn->prepare("UPDATE members SET password = ? WHERE id = ?");
            $updateStmt->bind_param("si", $newHash, $user['id']);
            $updateStmt->execute();
            $updateStmt->close();
        }
    }
    
    if ($passwordValid) {
        // Check status
        if (($user['status'] ?? 'Active') !== 'Active') {
            http_response_code(403);
            echo json_encode([
                "status" => false,
                "error" => "Account is " . ($user['status'] ?? 'Inactive')
            ]);
            exit();
        }
        
        $isAdmin = ($user['role'] === 'admin' || $user['role'] === 'administrator');
        
        // If admin, sync to admins table
        if ($isAdmin) {
            // Get the latest password
            $refreshStmt = $conn->prepare("SELECT password FROM members WHERE id = ?");
            $refreshStmt->execute([$user['id']]);
            $refreshResult = $refreshStmt->get_result();
            if ($refreshResult->num_rows > 0) {
                $refreshData = $refreshResult->fetch_assoc();
                $user['password'] = $refreshData['password'];
            }
            $refreshStmt->close();
            
            $checkAdmin = $conn->prepare("SELECT id FROM admins WHERE email = ?");
            $checkAdmin->bind_param("s", $user['email']);
            $checkAdmin->execute();
            $adminResult = $checkAdmin->get_result();
            
            if ($adminResult->num_rows == 0) {
                $insertAdmin = $conn->prepare("
                    INSERT INTO admins (username, email, password, role, phone, full_name, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $insertAdmin->bind_param(
                    "ssssss",
                    $user['name'],
                    $user['email'],
                    $user['password'],
                    $user['role'],
                    $user['phone'] ?? '',
                    $user['name']
                );
                $insertAdmin->execute();
                $insertAdmin->close();
            } else {
                $updateAdmin = $conn->prepare("
                    UPDATE admins 
                    SET password = ?, 
                        full_name = ?, 
                        phone = ?
                    WHERE email = ?
                ");
                $updateAdmin->bind_param(
                    "sss",
                    $user['password'],
                    $user['name'],
                    $user['phone'] ?? '',
                    $user['email']
                );
                $updateAdmin->execute();
                $updateAdmin->close();
            }
            $checkAdmin->close();
        }
        
        $userData = [
            'id' => (int)$user['id'],
            'username' => $user['name'] ?? 'User',
            'name' => $user['name'] ?? 'User',
            'full_name' => $user['full_name'] ?? $user['name'] ?? 'User',
            'email' => $user['email'],
            'phone' => $user['phone'] ?? '',
            'role' => $user['role'] ?? 'member',
            'status' => $user['status'] ?? 'Active',
            'balance' => floatval($user['balance'] ?? 0),
            'accountNumber' => $user['account_number'] ?? 'N/A',
            'account_number' => $user['account_number'] ?? 'N/A',
            'membershipType' => $user['membership_type'] ?? 'Standard',
            'joinDate' => $user['join_date'] ?? date('Y-m-d'),
            'is_active' => 1,
            'isAdmin' => $isAdmin
        ];
        
        $token = bin2hex(random_bytes(32));
        
        http_response_code(200);
        echo json_encode([
            "status" => true,
            "message" => "Login successful",
            "user" => $userData,
            "token" => $token
        ]);
        exit();
    }
}
$stmt->close();

// ================================================================
// LOGIN FAILED
// ================================================================
error_log("Login failed for: " . $email);
http_response_code(401);
echo json_encode([
    "status" => false,
    "error" => "Invalid password"
]);

$conn->close();
?>