<?php
// api/notifications.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// Load the DB config (this file defines getDBConnection())
require_once __DIR__ . '/config/database.php';

// Get the PDO handle — same pattern as daily-marks.php
try {
    $db = getDBConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'DB connection failed']);
    exit;
}

if (!$db) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'DB connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET — list notifications
// ============================================================
if ($method === 'GET') {
    $userId = intval($_GET['user_id'] ?? 0);
    $limit  = min(100, intval($_GET['limit'] ?? 30));
    if (!$userId) {
        echo json_encode(['status' => false, 'message' => 'user_id required']);
        exit;
    }

    try {
        $stmt = $db->prepare(
            "SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT :lim"
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $unread = $db->prepare(
            "SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0"
        );
        $unread->execute([':uid' => $userId]);
    } catch (Throwable $e) {
        error_log("[notifications] GET failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => false, 'error' => 'Query failed']);
        exit;
    }

    echo json_encode([
        'status'       => true,
        'data'         => $rows,
        'unread_count' => (int) $unread->fetchColumn(),
    ]);
    exit;
}

// ============================================================
// POST — mark_read / mark_all_read / delete / create
// ============================================================
if ($method === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? '';
    $userId = intval($input['user_id'] ?? 0);

    if (!$userId) {
        echo json_encode(['status' => false, 'message' => 'user_id required']);
        exit;
    }

    try {
        switch ($action) {
            case 'mark_read':
                $stmt = $db->prepare(
                    "UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid"
                );
                $stmt->execute([':id' => intval($input['id'] ?? 0), ':uid' => $userId]);
                break;

            case 'mark_all_read':
                $stmt = $db->prepare(
                    "UPDATE notifications SET is_read = 1 WHERE user_id = :uid"
                );
                $stmt->execute([':uid' => $userId]);
                break;

            case 'delete':
                $stmt = $db->prepare(
                    "DELETE FROM notifications WHERE id = :id AND user_id = :uid"
                );
                $stmt->execute([':id' => intval($input['id'] ?? 0), ':uid' => $userId]);
                break;

            case 'create':
                $stmt = $db->prepare(
                    "INSERT INTO notifications (user_id, type, title, message, link)
                     VALUES (:uid, :type, :title, :msg, :link)"
                );
                $stmt->execute([
                    ':uid'   => $userId,
                    ':type'  => $input['type']    ?? 'system',
                    ':title' => $input['title']   ?? 'Notification',
                    ':msg'   => $input['message'] ?? '',
                    ':link'  => $input['link']    ?? null,
                ]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['status' => false, 'message' => 'Unknown action']);
                exit;
        }
    } catch (Throwable $e) {
        error_log("[notifications] POST failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => false, 'error' => 'Save failed']);
        exit;
    }

    echo json_encode(['status' => true]);
    exit;
}