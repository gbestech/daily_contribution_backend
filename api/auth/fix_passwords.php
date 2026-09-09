<?php
// api/fix_passwords.php
header("Content-Type: application/json; charset=UTF-8");

$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "baleeg_db";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed: ' . $conn->connect_error]));
}

// Get all users
$sql = "SELECT id, email, name, role, password FROM members";
$result = $conn->query($sql);

$fixed = [];
$skipped = [];

while ($row = $result->fetch_assoc()) {
    // Check if password is already properly hashed
    $info = password_get_info($row['password']);
    
    if ($info['algo'] === 0) {
        // Not hashed - plain text or invalid
        // Try to re-hash if it looks like a password
        if (strlen($row['password']) < 60) {
            $newHash = password_hash($row['password'], PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE members SET password = ? WHERE id = ?");
            $update->bind_param("si", $newHash, $row['id']);
            $update->execute();
            
            $fixed[] = [
                'id' => $row['id'],
                'email' => $row['email'],
                'old_password' => $row['password'],
                'new_hash' => $newHash
            ];
        }
    } else {
        $skipped[] = [
            'id' => $row['id'],
            'email' => $row['email'],
            'reason' => 'Already hashed'
        ];
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Fixed ' . count($fixed) . ' passwords',
    'fixed' => $fixed,
    'skipped' => $skipped
], JSON_PRETTY_PRINT);

$conn->close();
?>