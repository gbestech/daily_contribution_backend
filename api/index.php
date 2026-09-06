<?php
// api/index.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS HEADERS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 86400");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json; charset=UTF-8");

echo json_encode([
    'status' => true,
    'message' => 'Baleeg API is running',
    'version' => '1.0.0',
    'endpoints' => [
        'admin' => '/api/admin.php',
        'members' => '/api/members.php',
        'transactions' => '/api/transactions.php',
        'transfers' => '/api/transfers.php'
    ]
]);
?>
