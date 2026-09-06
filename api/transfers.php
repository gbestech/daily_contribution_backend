<?php
// api/transfers.php
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data']);
        exit();
    }
    
    $fromMemberId = $data['fromMemberId'] ?? null;
    $toMemberId = $data['toMemberId'] ?? null;
    $fromMemberName = $data['fromMemberName'] ?? null;
    $toMemberName = $data['toMemberName'] ?? null;
    $fromAccountNumber = $data['fromAccountNumber'] ?? null;
    $toAccountNumber = $data['toAccountNumber'] ?? null;
    $amount = $data['amount'] ?? null;
    $date = $data['date'] ?? null;
    $description = $data['description'] ?? "Transfer to $toMemberName";
    
    $missing = [];
    if (!$fromMemberId) $missing[] = 'fromMemberId';
    if (!$toMemberId) $missing[] = 'toMemberId';
    if (!$fromMemberName) $missing[] = 'fromMemberName';
    if (!$toMemberName) $missing[] = 'toMemberName';
    if (!$fromAccountNumber) $missing[] = 'fromAccountNumber';
    if (!$toAccountNumber) $missing[] = 'toAccountNumber';
    if (!$amount) $missing[] = 'amount';
    if (!$date) $missing[] = 'date';
    
    if (!empty($missing)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: ' . implode(', ', $missing)]);
        exit();
    }
    
    $db->beginTransaction();
    
    // Check if both members exist
    $stmt = $db->prepare("SELECT id, balance FROM members WHERE id = ?");
    $stmt->execute([$fromMemberId]);
    $fromMember = $stmt->fetch();
    
    if (!$fromMember) {
        http_response_code(404);
        echo json_encode(['error' => 'From member not found']);
        return;
    }
    
    $stmt->execute([$toMemberId]);
    $toMember = $stmt->fetch();
    
    if (!$toMember) {
        http_response_code(404);
        echo json_encode(['error' => 'To member not found']);
        return;
    }
    
    if ($amount > $fromMember['balance']) {
        http_response_code(400);
        echo json_encode(['error' => 'Insufficient balance']);
        return;
    }
    
    // Store the recipient ID in the description for easy lookup
    // Format: "Transfer to [Recipient Name] (ID: [Recipient ID])"
    $fullDescription = "Transfer to $toMemberName (ID: $toMemberId) | From: $fromMemberName | Amount: $amount";
    
    $stmt = $db->prepare("
        INSERT INTO transactions (member_id, member_name, account_number, type, amount, date, status, description)
        VALUES (?, ?, ?, 'transfer', ?, ?, 'pending', ?)
    ");
    
    $result = $stmt->execute([
        $fromMemberId,
        $fromMemberName,
        $fromAccountNumber,
        $amount,
        $date,
        $fullDescription
    ]);
    
    if (!$result) {
        throw new Exception('Failed to create transfer transaction');
    }
    
    $id = $db->lastInsertId();
    
    $db->commit();
    
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Transfer request submitted successfully',
        'transaction' => [
            'id' => $id,
            'fromMemberId' => $fromMemberId,
            'toMemberId' => $toMemberId,
            'fromMemberName' => $fromMemberName,
            'toMemberName' => $toMemberName,
            'amount' => $amount,
            'date' => $date,
            'description' => $fullDescription,
            'status' => 'pending'
        ]
    ]);
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create transfer: ' . $e->getMessage()]);
}
?>
