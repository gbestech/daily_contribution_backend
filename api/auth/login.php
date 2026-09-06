<?php
// Set CORS headers so React frontend can communicate with backend
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request from React
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Ensure the endpoint only accepts POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Method not allowed. Use POST."
    ]);
    exit();
}

// ------------------------------------------------------------------
// Database Connection Setup
// ------------------------------------------------------------------
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "baleeg_db"; // Replace with your actual database name

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Database connection failed: " . $conn->connect_error
    ]);
    exit();
}

// Read raw JSON body from React request
$input_data = json_decode(file_get_contents("php://input"), true);

$email = trim($input_data['email'] ?? '');
$password = trim($input_data['password'] ?? '');

// Validate input
if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Email and password are required."
    ]);
    exit();
}

// ------------------------------------------------------------------
// Fetch user from MySQL Database using Prepared Statements
// ------------------------------------------------------------------
$sql = "SELECT id, username, email, password, full_name, role, phone, address, is_active, created_at, updated_at FROM users WHERE email = ? LIMIT 1";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Query preparation failed."]);
    exit();
}

$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // User not found
    http_response_code(401);
    echo json_encode([
        "status" => false,
        "error" => "Invalid email or password"
    ]);
    exit();
}

$user = $result->fetch_assoc();

// ------------------------------------------------------------------
// Verify Password
// ------------------------------------------------------------------
// Note: Use password_verify($password, $user['password']) in production for hashed passwords
$isPasswordValid = password_verify($password, $user['password']) || ($password === $user['password']);

if (!$isPasswordValid) {
    http_response_code(401);
    echo json_encode([
        "status" => false,
        "error" => "Invalid email or password"
    ]);
    exit();
}

if ((int)$user['is_active'] === 0) {
    http_response_code(403);
    echo json_encode([
        "status" => false,
        "error" => "Account is inactive. Please contact admin."
    ]);
    exit();
}

// Remove sensitive password hash before returning user object
unset($user['password']);

// Generate a session token (Use JWT or a secure random token)
$token = bin2hex(random_bytes(32));

// ------------------------------------------------------------------
// Return JSON Response Matching React Component Requirements
// ------------------------------------------------------------------
http_response_code(200);
echo json_encode([
    "status" => true,
    "message" => "Login successful",
    "data" => [
        "user" => [
            "id" => (int)$user['id'],
            "username" => $user['username'],
            "email" => $user['email'],
            "full_name" => $user['full_name'],
            "role" => $user['role'], // e.g., 'admin' or 'member'
            "phone" => $user['phone'],
            "address" => $user['address'],
            "is_active" => (int)$user['is_active'],
            "created_at" => $user['created_at'],
            "updated_at" => $user['updated_at']
        ],
        "token" => $token
    ]
]);

$stmt->close();
$conn->close();