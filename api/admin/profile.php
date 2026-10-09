<?php
// api/admin/profile.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ---- DB connection ----
require_once __DIR__ . '/../config/database.php'; // adjust path if different

function respond($code, $data) {
    http_response_code($code);
    echo json_encode($data);
    exit();
}

// ---- Resolve target admin id ----
$adminId = null;
if (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) {
    $adminId = (int)$_GET['id'];
} elseif (isset($_GET['user_id']) && ctype_digit((string)$_GET['user_id'])) {
    $adminId = (int)$_GET['user_id'];
}

// ============================================================
// GET — return admin profile
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        if ($adminId !== null) {
            $stmt = $pdo->prepare(
                "SELECT id, username, email, phone, full_name, role,
                        avatar, address, bio, date_of_birth, gender,
                        created_at, updated_at
                 FROM admins
                 WHERE id = :id
                 LIMIT 1"
            );
            $stmt->execute([':id' => $adminId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Fallback: first administrator
            $stmt = $pdo->query(
                "SELECT id, username, email, phone, full_name, role,
                        avatar, address, bio, date_of_birth, gender,
                        created_at, updated_at
                 FROM admins
                 WHERE role IN ('admin','administrator')
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$row) {
            respond(404, ['error' => 'Admin not found']);
        }

        respond(200, [
            'id'            => (int)$row['id'],
            'username'      => $row['username'],
            'full_name'     => $row['full_name'],
            'email'         => $row['email'],
            'phone'         => $row['phone'],
            'role'          => $row['role'],
            'avatar'        => $row['avatar'],
            'address'       => $row['address'],
            'bio'           => $row['bio'],
            'date_of_birth' => $row['date_of_birth'],
            'gender'        => $row['gender'],
            'created_at'    => $row['created_at'],
            'updated_at'    => $row['updated_at'],
        ]);
    } catch (Throwable $e) {
        respond(500, [
            'error'  => 'Failed to load admin profile',
            'detail' => $e->getMessage(),
        ]);
    }
}

// ============================================================
// PUT / POST — update admin profile
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true);

        if (!is_array($body)) {
            respond(400, ['error' => 'Invalid JSON body']);
        }

        $targetId = isset($body['id']) && ctype_digit((string)$body['id'])
            ? (int)$body['id']
            : $adminId;

        if ($targetId === null) {
            respond(400, ['error' => 'Admin id is required']);
        }

        // Whitelisted fields — role and password are NOT editable here
        $allowed = [
            'username', 'email', 'phone', 'full_name',
            'avatar', 'address', 'bio', 'date_of_birth', 'gender',
        ];

        $updates = [];
        $params  = [':id' => $targetId];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $updates[] = "$field = :$field";
                $params[":$field"] = $body[$field];
            }
        }

        if (empty($updates)) {
            respond(400, ['error' => 'No valid fields to update']);
        }

        $sql  = "UPDATE admins SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Return the fresh row
        $stmt = $pdo->prepare(
            "SELECT id, username, email, phone, full_name, role,
                    avatar, address, bio, date_of_birth, gender,
                    created_at, updated_at
             FROM admins
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $targetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        respond(200, [
            'status'  => true,
            'message' => 'Profile updated',
            'profile' => $row,
        ]);
    } catch (Throwable $e) {
        respond(500, [
            'error'  => 'Failed to update admin profile',
            'detail' => $e->getMessage(),
        ]);
    }
}

// Any other method
respond(405, ['error' => 'Method not allowed']);