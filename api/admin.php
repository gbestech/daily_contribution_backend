<?php
// api/admin.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

while (ob_get_level()) ob_end_clean();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-User-Role, X-User-Branch-Id, X-User-Id");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 86400");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/config/database.php';

try {
    $db = getDBConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'Database connection failed']);
    exit();
}
if (!$db) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'Database connection failed']);
    exit();
}

// ============================================================
// Request context
// ============================================================
$method   = $_SERVER['REQUEST_METHOD'];
$reqRole  = strtolower(trim($_SERVER['HTTP_X_USER_ROLE'] ?? ''));
$isAdmin  = in_array($reqRole, ['admin', 'administrator'], true);

// Read optional branch context from query / body / header
$queryBranchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$headerBranchId = isset($_SERVER['HTTP_X_USER_BRANCH_ID'])
    ? (int)$_SERVER['HTTP_X_USER_BRANCH_ID']
    : 0;

// ============================================================
// Helpers
// ============================================================
function jsonOut($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload);
    exit();
}

function readBody() {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function parseIdFromPath() {
    $uri  = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($uri, PHP_URL_PATH) ?? '';
    foreach (explode('/', $path) as $part) {
        if (ctype_digit($part)) return (int)$part;
    }
    return null;
}

/**
 * Resolve the branch for this request.
 * Priority: query → body → header.
 * Returns null if none of those yield a positive number.
 */
function resolveBranchId(int $queryId, int $headerId, array $body = []) {
    if ($queryId > 0) return $queryId;
    if (isset($body['branch_id']) && (int)$body['branch_id'] > 0) {
        return (int)$body['branch_id'];
    }
    if ($headerId > 0) return $headerId;
    return null;
}

/**
 * Does a branch exist?
 */
function branchExists(PDO $db, int $branchId): bool {
    $s = $db->prepare("SELECT id FROM branches WHERE id = ? LIMIT 1");
    $s->execute([$branchId]);
    return (bool)$s->fetchColumn();
}

/**
 * Does the admins table have a branch_id column?
 * Cached across the request.
 */
function adminsHasBranch(PDO $db): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $c = $db->query("SHOW COLUMNS FROM admins LIKE 'branch_id'");
        $has = $c->rowCount() > 0;
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

function formatAdmin($row) {
    return [
        'id'        => (int)$row['id'],
        'username'  => $row['username']  ?? '',
        'email'     => $row['email']     ?? '',
        'full_name' => $row['full_name'] ?? '',
        'phone'     => $row['phone']     ?? '',
        'role'      => $row['role']      ?? 'administrator',
        'branch_id' => isset($row['branch_id']) ? (int)$row['branch_id'] : null,
    ];
}

// ============================================================
// GET — return the currently-authenticated admin
//
// Priority:
//   1. X-User-Id header (set by the frontend from localStorage.user.id)
//   2. ?id=N query param
//   3. first admin row
//
// We do NOT auto-return a fabricated "admin 1" if the table is empty.
// ============================================================
if ($method === 'GET') {
    try {
        $targetId = 0;
        if (!empty($_SERVER['HTTP_X_USER_ID'])) {
            $targetId = (int)$_SERVER['HTTP_X_USER_ID'];
        } else if (isset($_GET['id']) && (int)$_GET['id'] > 0) {
            $targetId = (int)$_GET['id'];
        }

        $hasBranch = adminsHasBranch($db);
        $cols = "id, username, email, full_name, phone, role"
              . ($hasBranch ? ", branch_id" : "");

        if ($targetId > 0) {
            $stmt = $db->prepare("SELECT $cols FROM admins WHERE id = ? LIMIT 1");
            $stmt->execute([$targetId]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            // If not in admins, fall back to the members row for the same id
            if (!$admin) {
                try {
                    $s = $db->prepare(
                        "SELECT id, name AS full_name, email, phone, role,
                                " . ($hasBranch ? "branch_id" : "NULL AS branch_id") . "
                         FROM members WHERE id = ? LIMIT 1"
                    );
                    $s->execute([$targetId]);
                    $m = $s->fetch(PDO::FETCH_ASSOC);
                    if ($m) {
                        $m['username'] = $m['email'] ?? '';
                        $admin = $m;
                    }
                } catch (Exception $e) {
                    error_log("admin.php GET members fallback failed: " . $e->getMessage());
                }
            }
        } else {
            // No id passed — return the first admin (legacy behaviour)
            $stmt = $db->query("SELECT $cols FROM admins ORDER BY id ASC LIMIT 1");
            $admin = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        }

        if (!$admin) {
            jsonOut(['status' => false, 'error' => 'Admin not found'], 404);
        }

        // If we can infer a branch from the request, verify the admin's
        // branch matches before returning the row — otherwise a caller
        // with the wrong branch id gets a 404 rather than someone else's
        // profile.
        $reqBranch = resolveBranchId($queryBranchId, $headerBranchId);
        if ($hasBranch && $reqBranch !== null
            && isset($admin['branch_id']) && (int)$admin['branch_id'] > 0
            && (int)$admin['branch_id'] !== $reqBranch) {
            jsonOut([
                'status' => false,
                'error'  => 'Admin does not belong to the requested branch',
            ], 404);
        }

        jsonOut(array_merge(['status' => true], formatAdmin($admin)));
    } catch (Exception $e) {
        error_log("admin.php GET error: " . $e->getMessage());
        jsonOut(['status' => false, 'error' => 'Failed to fetch admin'], 500);
    }
}

// ============================================================
// PUT — update the admin's profile
// ============================================================
if ($method === 'PUT') {
    try {
        $body = readBody();
        if (!$body) {
            jsonOut(['status' => false, 'error' => 'Invalid JSON data'], 400);
        }

        // Determine which admin row to update
        $targetId = parseIdFromPath();
        if (!$targetId && !empty($_SERVER['HTTP_X_USER_ID'])) {
            $targetId = (int)$_SERVER['HTTP_X_USER_ID'];
        }
        if (!$targetId && isset($body['id']) && (int)$body['id'] > 0) {
            $targetId = (int)$body['id'];
        }
        if (!$targetId) {
            jsonOut(['status' => false, 'error' => 'Admin id required'], 400);
        }

        $hasBranch = adminsHasBranch($db);

        // Confirm the row exists. DO NOT create a default.
        $cols = "*";
        $stmt = $db->prepare("SELECT $cols FROM admins WHERE id = ? LIMIT 1");
        $stmt->execute([$targetId]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            jsonOut(['status' => false, 'error' => 'Admin not found'], 404);
        }

        // Branch guard: if the caller provided a branch, it must match
        // the admin's own branch.
        $reqBranch = resolveBranchId($queryBranchId, $headerBranchId, $body);
        if ($hasBranch && $reqBranch !== null
            && isset($admin['branch_id']) && (int)$admin['branch_id'] > 0
            && (int)$admin['branch_id'] !== $reqBranch) {
            jsonOut([
                'status' => false,
                'error'  => 'Admin does not belong to the requested branch',
            ], 403);
        }

        // Build the update
        $updateFields = [];
        $params = [];

        if (isset($body['username']) && trim($body['username']) !== '') {
            $updateFields[] = "username = ?";
            $params[] = trim($body['username']);
        }
        if (isset($body['email']) && trim($body['email']) !== '') {
            // Email uniqueness (case-insensitive, ignoring self)
            $ec = $db->prepare(
                "SELECT id FROM admins WHERE LOWER(email) = LOWER(?) AND id != ? LIMIT 1"
            );
            $ec->execute([trim($body['email']), $targetId]);
            if ($ec->fetchColumn()) {
                jsonOut(['status' => false, 'error' => 'Email already in use'], 409);
            }
            $updateFields[] = "email = ?";
            $params[] = trim($body['email']);
        }
        if (isset($body['full_name']) && trim($body['full_name']) !== '') {
            $updateFields[] = "full_name = ?";
            $params[] = trim($body['full_name']);
        }
        if (array_key_exists('phone', $body)) {
            $updateFields[] = "phone = ?";
            $params[] = trim((string)$body['phone']);
        }
        if (!empty($body['password'])) {
            $updateFields[] = "password = ?";
            $params[] = password_hash($body['password'], PASSWORD_DEFAULT);
        }

        // Only allow changing branch when the caller is an admin
        // AND the branch exists. Managers can't move themselves.
        if ($hasBranch && isset($body['branch_id']) && $isAdmin) {
            $newBranch = (int)$body['branch_id'];
            if ($newBranch > 0) {
                if (!branchExists($db, $newBranch)) {
                    jsonOut(['status' => false, 'error' => 'Branch not found'], 404);
                }
                $updateFields[] = "branch_id = ?";
                $params[] = $newBranch;
            }
        }

        if (empty($updateFields)) {
            jsonOut(['status' => false, 'error' => 'No fields to update'], 400);
        }

        $params[] = $targetId;
        $sql = "UPDATE admins SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $cols = "id, username, email, full_name, phone, role"
              . ($hasBranch ? ", branch_id" : "");
        $stmt = $db->prepare("SELECT $cols FROM admins WHERE id = ? LIMIT 1");
        $stmt->execute([$targetId]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);

        jsonOut([
            'status'  => true,
            'message' => 'Admin updated successfully',
            'admin'   => formatAdmin($updated),
        ]);
    } catch (PDOException $e) {
        error_log("admin.php PUT PDO error: " . $e->getMessage());
        jsonOut(['status' => false, 'error' => 'Database error'], 500);
    } catch (Exception $e) {
        error_log("admin.php PUT error: " . $e->getMessage());
        jsonOut(['status' => false, 'error' => 'Failed to update admin'], 500);
    }
}

// ============================================================
// POST — create an admin (branch-aware)
// ============================================================
if ($method === 'POST') {
    try {
        $body = readBody();
        if (!$body) jsonOut(['status' => false, 'error' => 'Invalid JSON'], 400);

        $username = trim($body['username'] ?? '');
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        if ($username === '' || $email === '' || $password === '') {
            jsonOut(['status' => false, 'error' => 'username, email, password required'], 400);
        }

        $hasBranch = adminsHasBranch($db);
        $branchId = null;
        if ($hasBranch) {
            $branchId = resolveBranchId($queryBranchId, $headerBranchId, $body);
            if ($branchId === null) {
                jsonOut(['status' => false, 'error' => 'branch_id is required'], 400);
            }
            if (!branchExists($db, $branchId)) {
                jsonOut(['status' => false, 'error' => 'Branch not found'], 404);
            }
        }

        // Email uniqueness
        $ec = $db->prepare("SELECT id FROM admins WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $ec->execute([$email]);
        if ($ec->fetchColumn()) {
            jsonOut(['status' => false, 'error' => 'Email already in use'], 409);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $role = $body['role'] ?? 'administrator';
        $phone = $body['phone'] ?? '';
        $fullName = $body['full_name'] ?? $username;

        if ($hasBranch) {
            $stmt = $db->prepare(
                "INSERT INTO admins (username, email, password, role, phone, full_name, branch_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$username, $email, $hash, $role, $phone, $fullName, $branchId]);
        } else {
            $stmt = $db->prepare(
                "INSERT INTO admins (username, email, password, role, phone, full_name, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$username, $email, $hash, $role, $phone, $fullName]);
        }

        $newId = (int)$db->lastInsertId();
        $cols = "id, username, email, full_name, phone, role"
              . ($hasBranch ? ", branch_id" : "");
        $stmt = $db->prepare("SELECT $cols FROM admins WHERE id = ? LIMIT 1");
        $stmt->execute([$newId]);

        jsonOut([
            'status'  => true,
            'message' => 'Admin created',
            'admin'   => formatAdmin($stmt->fetch(PDO::FETCH_ASSOC)),
        ], 201);
    } catch (Exception $e) {
        error_log("admin.php POST error: " . $e->getMessage());
        jsonOut(['status' => false, 'error' => 'Failed to create admin'], 500);
    }
}

// ============================================================
// DELETE — admin-scoped
// ============================================================
if ($method === 'DELETE') {
    try {
        if (!$isAdmin) {
            jsonOut(['status' => false, 'error' => 'Admin access required'], 403);
        }
        $id = parseIdFromPath();
        if (!$id) jsonOut(['status' => false, 'error' => 'Admin id required'], 400);

        $stmt = $db->prepare("DELETE FROM admins WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            jsonOut(['status' => false, 'error' => 'Admin not found'], 404);
        }

        jsonOut(['status' => true, 'message' => 'Admin deleted']);
    } catch (Exception $e) {
        error_log("admin.php DELETE error: " . $e->getMessage());
        jsonOut(['status' => false, 'error' => 'Failed to delete admin'], 500);
    }
}

jsonOut(['status' => false, 'error' => 'Method not allowed'], 405);