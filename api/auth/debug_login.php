<?php
// api/debug_login.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "baleeg_db";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed: ' . $conn->connect_error]));
}

// Get email from request
$input = json_decode(file_get_contents("php://input"), true);
$email = $input['email'] ?? '';
$password = $input['password'] ?? '';

if (empty($email) || empty($password)) {
    echo json_encode(['error' => 'Email and password required']);
    exit();
}

echo "=== DEBUG LOGIN ===\n\n";

// 1. Check members table
$sql = "SELECT * FROM members WHERE email = ? OR phone = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $email, $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo "✅ User found in members table:\n";
    echo "ID: " . $user['id'] . "\n";
    echo "Email: " . $user['email'] . "\n";
    echo "Name: " . $user['name'] . "\n";
    echo "Role: " . $user['role'] . "\n";
    echo "Password hash: " . $user['password'] . "\n";
    echo "Password hash length: " . strlen($user['password']) . "\n";
    echo "Is hash valid? " . (password_get_info($user['password'])['algo'] ? 'YES' : 'NO') . "\n";
    
    // Test password_verify
    $verifyResult = password_verify($password, $user['password']);
    echo "password_verify('" . $password . "', hash) = " . ($verifyResult ? 'TRUE ✅' : 'FALSE ❌') . "\n";
    
    // Test direct comparison
    echo "Direct comparison: " . ($password === $user['password'] ? 'MATCH' : 'NO MATCH') . "\n";
    
    // Try to re-hash and compare
    $testHash = password_hash($password, PASSWORD_DEFAULT);
    echo "Test hash of same password: " . $testHash . "\n";
    echo "Test hash verify: " . (password_verify($password, $testHash) ? 'TRUE ✅' : 'FALSE ❌') . "\n";
    
    if ($verifyResult) {
        echo "\n✅ LOGIN WOULD SUCCEED!\n";
    } else {
        echo "\n❌ LOGIN WOULD FAIL - Password mismatch\n";
        echo "Possible causes:\n";
        echo "1. Password was double-hashed (frontend hashed + backend hashed)\n";
        echo "2. Wrong password entered\n";
        echo "3. Different password was used when creating the account\n";
    }
} else {
    echo "❌ User NOT found in members table\n";
}

$stmt->close();

// 2. Check admins table
$sql = "SELECT * FROM admins WHERE email = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo "\n✅ User found in admins table:\n";
    echo "ID: " . $user['id'] . "\n";
    echo "Email: " . $user['email'] . "\n";
    echo "Role: " . $user['role'] . "\n";
    echo "Password hash: " . $user['password'] . "\n";
    
    $verifyResult = password_verify($password, $user['password']);
    echo "password_verify() = " . ($verifyResult ? 'TRUE ✅' : 'FALSE ❌') . "\n";
} else {
    echo "\n❌ User NOT found in admins table\n";
}

$stmt->close();
$conn->close();
?>