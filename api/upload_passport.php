<?php
// api/upload_passport.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

require_once __DIR__ . '/config/database.php';

try {
    $db = getDBConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB connection failed']);
    exit();
}

if (!isset($_FILES['passport'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit();
}

$memberId = isset($_POST['memberId']) ? intval($_POST['memberId']) : 0;

if ($memberId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'memberId required']);
    exit();
}

$stmt = $db->prepare("SELECT id FROM members WHERE id = ?");
$stmt->execute([$memberId]);
if ($stmt->rowCount() === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Member not found']);
    exit();
}

$file = $_FILES['passport'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Upload error code ' . $file['error']]);
    exit();
}

if ($file['size'] > 3 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File too large (max 3MB)']);
    exit();
}

$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowed)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Use JPG, PNG, or WebP']);
    exit();
}

$uploadDir = __DIR__ . '/uploads/passports/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext = 'jpg';
if ($mime === 'image/png') $ext = 'png';
if ($mime === 'image/webp') $ext = 'webp';

$filename = 'passport_' . $memberId . '_' . time() . '.' . $ext;
$filepath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    exit();
}

// Delete old passport if present
try {
    $stmt = $db->prepare("SELECT passport_photo FROM members WHERE id = ?");
    $stmt->execute([$memberId]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($old && !empty($old['passport_photo'])) {
        $oldPath = $uploadDir . $old['passport_photo'];
        if (file_exists($oldPath)) {
            @unlink($oldPath);
        }
    }
} catch (Exception $e) {}

$stmt = $db->prepare("UPDATE members SET passport_photo = ? WHERE id = ?");
$stmt->execute([$filename, $memberId]);

echo json_encode([
    'success' => true,
    'message' => 'Passport uploaded successfully',
    'filename' => $filename,
    'url' => '/api/uploads/passports/' . $filename
]);