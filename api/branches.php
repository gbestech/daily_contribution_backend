<?php
// api/branches.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

while (ob_get_level()) {
    ob_end_clean();
}

// ============================================================
// CORS — reflect origin, allow custom headers, answer preflight
// before doing anything else.
// ============================================================
$allowedOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedList = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:5174',
    'http://127.0.0.1:5174',
    // add your production origin here
];
if (in_array($allowedOrigin, $allowedList, true)) {
    header("Access-Control-Allow-Origin: $allowedOrigin");
    header("Access-Control-Allow-Credentials: true");
} else if (empty($_COOKIE)) {
    // Only safe for anonymous requests — never mix `*` with credentials.
    header("Access-Control-Allow-Origin: *");
}
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-User-Role, X-User-Branch-Id");
header("Access-Control-Max-Age: 86400");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config/database.php';

try {
    $db = getDBConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// ============================================================
// Ensure branches table exists (safe / idempotent)
// ============================================================
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS branches (
          id          INT AUTO_INCREMENT PRIMARY KEY,
          code        VARCHAR(20)  NOT NULL UNIQUE,
          name        VARCHAR(120) NOT NULL,
          address     VARCHAR(255) DEFAULT NULL,
          phone       VARCHAR(30)  DEFAULT NULL,
          email       VARCHAR(120) DEFAULT NULL,
          manager     VARCHAR(120) DEFAULT NULL,
          status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
          created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to prepare branches table: ' . $e->getMessage()]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

$pathParts = explode('/', $path);
$id = null;
foreach ($pathParts as $part) {
    if (is_numeric($part)) {
        $id = $part;
        break;
    }
}

switch ($method) {
    case 'GET':
        if ($id) {
            getBranch($db, $id);
        } else {
            getBranches($db);
        }
        break;

    case 'POST':
        createBranch($db);
        break;

    case 'PUT':
        if ($id) {
            updateBranch($db, $id);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Branch ID required']);
        }
        break;

    case 'DELETE':
        if ($id) {
            deleteBranch($db, $id);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Branch ID required']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

// ============================================================
// HELPER — format a branch row
// ============================================================
function formatBranch($b) {
    return [
        'id'         => $b['id'] ?? null,
        'code'       => $b['code'] ?? '',
        'name'       => $b['name'] ?? '',
        'address'    => $b['address'] ?? null,
        'phone'      => $b['phone'] ?? null,
        'email'      => $b['email'] ?? null,
        'manager'    => $b['manager'] ?? null,
        'status'     => $b['status'] ?? 'active',
        'created_at' => $b['created_at'] ?? null,
        'updated_at' => $b['updated_at'] ?? null,
    ];
}

// ============================================================
// GET ALL
// ============================================================
function getBranches($db) {
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'branches'");
        if ($tableCheck->rowCount() == 0) {
            echo json_encode(['branches' => []]);
            return;
        }

        $stmt = $db->query("SELECT * FROM branches ORDER BY id ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = [];
        foreach ($rows as $row) {
            $formatted[] = formatBranch($row);
        }

        echo json_encode(['branches' => $formatted]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch branches: ' . $e->getMessage()]);
    }
}

// ============================================================
// GET ONE
// ============================================================
function getBranch($db, $id) {
    try {
        $stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
        $stmt->execute([$id]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($branch) {
            echo json_encode(['branch' => formatBranch($branch)]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Branch not found']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch branch: ' . $e->getMessage()]);
    }
}

// ============================================================
// CREATE
// ============================================================
function createBranch($db) {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }

        $code    = trim($data['code'] ?? '');
        $name    = trim($data['name'] ?? '');
        $address = trim($data['address'] ?? '');
        $phone   = trim($data['phone'] ?? '');
        $email   = trim($data['email'] ?? '');
        $manager = trim($data['manager'] ?? '');

        if ($code === '' || $name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Branch code and name are required']);
            return;
        }

        $check = $db->prepare("SELECT id FROM branches WHERE code = ?");
        $check->execute([$code]);
        if ($check->rowCount() > 0) {
            http_response_code(409);
            echo json_encode(['error' => "Branch code '{$code}' already exists"]);
            return;
        }

        $stmt = $db->prepare("
            INSERT INTO branches (code, name, address, phone, email, manager, status)
            VALUES (?, ?, ?, ?, ?, ?, 'active')
        ");
        $result = $stmt->execute([
            $code,
            $name,
            $address ?: null,
            $phone   ?: null,
            $email   ?: null,
            $manager ?: null,
        ]);

        if (!$result) {
            throw new Exception('Failed to insert branch');
        }

        $newId = $db->lastInsertId();
        $stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
        $stmt->execute([$newId]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'branch'  => formatBranch($branch),
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}

// ============================================================
// UPDATE
// ============================================================
function updateBranch($db, $id) {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }

        $stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            http_response_code(404);
            echo json_encode(['error' => 'Branch not found']);
            return;
        }

        // ---- Server-side ownership check for managers ----
        // Frontend sends X-User-Role and X-User-Branch-Id. If a manager
        // tries to edit a branch that isn't theirs, block it.
        $callerRole   = strtolower(trim($_SERVER['HTTP_X_USER_ROLE'] ?? ''));
        $callerBranch = isset($_SERVER['HTTP_X_USER_BRANCH_ID'])
            ? (int)$_SERVER['HTTP_X_USER_BRANCH_ID']
            : null;

        $isAdmin   = $callerRole === 'admin' || $callerRole === 'administrator';
        $isManager = $callerRole === 'manager' || $callerRole === 'branch_manager';

        if ($isManager) {
            if ($callerBranch === null || $callerBranch !== (int)$id) {
                http_response_code(403);
                echo json_encode(['error' => 'Managers can only edit their own branch']);
                return;
            }
            // Strip fields managers must not change
            unset($data['code']);
            unset($data['status']);
            unset($data['manager']);
        }

        if (isset($data['code'])) {
            $code = trim($data['code']);
            if ($code === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Branch code cannot be empty']);
                return;
            }
            if ($code !== $current['code']) {
                $dup = $db->prepare("SELECT id FROM branches WHERE code = ? AND id != ?");
                $dup->execute([$code, $id]);
                if ($dup->rowCount() > 0) {
                    http_response_code(409);
                    echo json_encode(['error' => "Branch code '{$code}' already exists"]);
                    return;
                }
            }
        }

        $allowedFields = [
            'code', 'name', 'address', 'phone', 'email', 'manager', 'status',
        ];

        $updateFields = [];
        $params = [];

        foreach ($allowedFields as $f) {
            if (array_key_exists($f, $data)) {
                $updateFields[] = "$f = ?";
                $val = $data[$f];
                if (is_string($val) && trim($val) === '') {
                    $val = null;
                }
                $params[] = $val;
            }
        }

        if (empty($updateFields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            return;
        }

        $params[] = $id;

        $sql = "UPDATE branches SET " . implode(", ", $updateFields) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute($params);

        if (!$result) {
            throw new Exception('Failed to update branch');
        }

        $stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
        $stmt->execute([$id]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Branch updated successfully',
            'branch'  => formatBranch($branch),
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}

// ============================================================
// DELETE
// ============================================================
function deleteBranch($db, $id) {
    try {
        $stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
        $stmt->execute([$id]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$branch) {
            http_response_code(404);
            echo json_encode(['error' => 'Branch not found']);
            return;
        }

        $stmt = $db->prepare("DELETE FROM branches WHERE id = ?");
        $result = $stmt->execute([$id]);

        if (!$result) {
            throw new Exception('Failed to delete branch');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Branch deleted successfully',
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete branch: ' . $e->getMessage()]);
    }
}
?>