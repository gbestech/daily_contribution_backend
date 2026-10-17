<?php
// api/members.php
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
require_once __DIR__ . '/config/charges.php';

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
// Request context
// ============================================================
$method  = $_SERVER['REQUEST_METHOD'];
$reqRole = strtolower(trim($_SERVER['HTTP_X_USER_ROLE'] ?? ''));
$isAdmin = in_array($reqRole, ['admin', 'administrator'], true);

$FETCH_ALL = isset($_GET['all']) && $_GET['all'] === '1';
if ($FETCH_ALL && !$isAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required for ?all=1']);
    exit();
}

// ============================================================
// 🔑 Resolve branch id — now OPTIONAL everywhere
//   - ?branch_id=N   → filter by that branch
//   - (missing)      → return ALL members (no 400)
//   - ?all=1         → return ALL members (admin only)
// ============================================================
$requestedBranchId = 0;

if (isset($_GET['branch_id']) && (int)$_GET['branch_id'] > 0) {
    $requestedBranchId = (int)$_GET['branch_id'];
} elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $rawBody = file_get_contents('php://input');
    $bodyData = json_decode($rawBody, true);
    if (is_array($bodyData) && !empty($bodyData['branch_id']) && (int)$bodyData['branch_id'] > 0) {
        $requestedBranchId = (int)$bodyData['branch_id'];
    }
} elseif (isset($_SERVER['HTTP_X_USER_BRANCH_ID']) && (int)$_SERVER['HTTP_X_USER_BRANCH_ID'] > 0) {
    $requestedBranchId = (int)$_SERVER['HTTP_X_USER_BRANCH_ID'];
}

// No branch ⇒ no 400. Just verify the branch exists when one IS given.
if ($requestedBranchId > 0) {
    $b = $db->prepare("SELECT id FROM branches WHERE id = ? LIMIT 1");
    $b->execute([$requestedBranchId]);
    if (!$b->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Branch not found']);
        exit();
    }
}

$BRANCH_ID = $requestedBranchId;

// ============================================================
// Parse path → id
// ============================================================
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);
$pathParts = explode('/', trim((string)$path, '/'));
$id = null;
foreach ($pathParts as $part) {
    if (ctype_digit($part)) { $id = (int)$part; break; }
}

// ============================================================
// Router
// ============================================================
switch ($method) {
    case 'GET':
        if ($id) getMember($db, $id);
        else     getMembers($db);
        break;

    case 'POST':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (is_array($data) && isset($data['action']) && isset($data['userIds'])) {
            bulkAction($db);
        } else {
            createMember($db);
        }
        break;

    case 'PUT':
        if ($id) updateMember($db, $id);
        else jsonErr(400, 'Member ID required');
        break;

    case 'DELETE':
        if ($id) deleteMember($db, $id);
        else jsonErr(400, 'Member ID required');
        break;

    default:
        jsonErr(405, 'Method not allowed');
}

function jsonErr($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit();
}

function formatMember($m) {
    return [
        'id' => (int)$m['id'],
        'accountNumber' => $m['account_number'] ?? 'N/A',
        'account_number' => $m['account_number'] ?? 'N/A',
        'name' => $m['name'] ?? 'Unknown',
        'full_name' => $m['name'] ?? 'Unknown',
        'email' => $m['email'] ?? '',
        'phone' => $m['phone'] ?? '',
        'membershipType' => $m['membership_type'] ?? 'Standard',
        'membership_type' => $m['membership_type'] ?? 'Standard',
        'joinDate' => $m['join_date'] ?? date('Y-m-d'),
        'join_date' => $m['join_date'] ?? date('Y-m-d'),
        'status' => $m['status'] ?? 'Active',
        'balance' => floatval($m['balance'] ?? 0),
        'role' => $m['role'] ?? 'member',
        'date_of_birth' => $m['date_of_birth'] ?? null,
        'gender' => $m['gender'] ?? null,
        'address' => $m['address'] ?? null,
        'next_of_kin_name' => $m['next_of_kin_name'] ?? null,
        'next_of_kin_phone' => $m['next_of_kin_phone'] ?? null,
        'next_of_kin_relationship' => $m['next_of_kin_relationship'] ?? null,
        'savings_plan'    => $m['savings_plan']    ?? null,
        'savings_method'  => $m['savings_method']  ?? null,
        'item_name'       => $m['item_name']       ?? null,
        'duration'        => $m['duration']        ?? null,
        'amount'          => isset($m['amount']) ? floatval($m['amount']) : null,
        'total_amount'    => isset($m['total_amount']) ? floatval($m['total_amount']) : null,
        'bank_name' => $m['bank_name'] ?? null,
        'bank_account_number' => $m['bank_account_number'] ?? null,
        'passport_photo' => $m['passport_photo'] ?? null,
        'profile_completed' => intval($m['profile_completed'] ?? 0),
        'branch_id' => isset($m['branch_id']) ? (int)$m['branch_id'] : null,
    ];
}

// ============================================================
// GET list — branch filter is OPTIONAL
// ============================================================
function getMembers($db) {
    global $BRANCH_ID, $FETCH_ALL;
    try {
        $tc = $db->query("SHOW TABLES LIKE 'members'");
        if ($tc->rowCount() === 0) {
            echo json_encode([
                'members'   => [],
                'branch_id' => $BRANCH_ID > 0 ? $BRANCH_ID : null,
                'all'       => $FETCH_ALL ? 1 : 0,
            ]);
            return;
        }

        // 🔑 No branch supplied OR ?all=1 → return everything.
        //    Otherwise → filter by the branch.
        if ($FETCH_ALL || $BRANCH_ID === 0) {
            $stmt = $db->prepare("SELECT * FROM members ORDER BY id DESC");
            $stmt->execute();
        } else {
            $stmt = $db->prepare("SELECT * FROM members WHERE branch_id = ? ORDER BY id DESC");
            $stmt->execute([$BRANCH_ID]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formatted = array_map('formatMember', $rows);

        echo json_encode([
            'members'   => $formatted,
            'branch_id' => $BRANCH_ID > 0 ? $BRANCH_ID : null,
            'all'       => $FETCH_ALL ? 1 : 0,
            'count'     => count($formatted),
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch members: ' . $e->getMessage()]);
    }
}

// ============================================================
// GET one
// ============================================================
function getMember($db, $id) {
    global $BRANCH_ID;
    try {
        if ($BRANCH_ID > 0) {
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ? AND branch_id = ? LIMIT 1");
            $stmt->execute([$id, $BRANCH_ID]);
        } else {
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
        }
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($m) {
            echo json_encode(['member' => formatMember($m)]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Member not found']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch member: ' . $e->getMessage()]);
    }
}

function startsWith($h, $n) {
    return substr($h, 0, strlen($n)) === $n;
}

// ============================================================
// POST create — branch_id is optional
// ============================================================
function createMember($db) {
    global $BRANCH_ID;
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) jsonErr(400, 'Invalid JSON data');

        $accountNumber = $data['accountNumber'] ?? $data['account_number'] ?? null;
        $name          = $data['name'] ?? null;
        $email         = $data['email'] ?? null;
        $password      = $data['password'] ?? null;
        $phone         = $data['phone'] ?? null;
        $role          = $data['role'] ?? 'member';
        $balance       = floatval($data['balance'] ?? 0);

        if (!$accountNumber || !$name || !$email || !$password) {
            jsonErr(400, 'Missing required fields');
        }

        if ($phone) {
            $cleaned = preg_replace('/\D/', '', $phone);
            if (strlen($cleaned) !== 11 || !startsWith($cleaned, '0')) {
                jsonErr(400, 'Phone must be 11 digits starting with 0');
            }
            $phone = $cleaned;
        }

        // Uniqueness — scoped by branch only when a branch is present
        if ($BRANCH_ID > 0) {
            $check = $db->prepare("SELECT id FROM members WHERE email = ? AND branch_id = ?");
            $check->execute([$email, $BRANCH_ID]);
            if ($check->rowCount() > 0) jsonErr(409, 'Email already exists in this branch');

            if ($phone) {
                $pc = $db->prepare("SELECT id FROM members WHERE phone = ? AND branch_id = ?");
                $pc->execute([$phone, $BRANCH_ID]);
                if ($pc->rowCount() > 0) jsonErr(409, 'Phone already exists in this branch');
            }
        } else {
            $check = $db->prepare("SELECT id FROM members WHERE email = ?");
            $check->execute([$email]);
            if ($check->rowCount() > 0) jsonErr(409, 'Email already exists');

            if ($phone) {
                $pc = $db->prepare("SELECT id FROM members WHERE phone = ?");
                $pc->execute([$phone]);
                if ($pc->rowCount() > 0) jsonErr(409, 'Phone already exists');
            }
        }

        $membershipType = $data['membershipType'] ?? $data['membership_type'] ?? 'Standard';
        $joinDate       = $data['joinDate'] ?? $data['join_date'] ?? date('Y-m-d');
        $status         = $data['status'] ?? 'Active';
        $hashed         = password_hash($password, PASSWORD_DEFAULT);

        $net = $balance;
        $charge = 0.0; $rate = 0.0;
        if ($balance > 0) {
            $ci = calculateCharge($balance, $db);
            $charge = $ci['charge']; $rate = $ci['rate']; $net = $ci['net'];
        }

        // 🔑 NULL when no branch id supplied
        $branchValue = $BRANCH_ID > 0 ? $BRANCH_ID : null;

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO members
                    (account_number, name, email, phone, membership_type, join_date, status, balance, role, password, branch_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $accountNumber, $name, $email, $phone, $membershipType,
                $joinDate, $status, $net, $role, $hashed, $branchValue
            ]);
            $newId = $db->lastInsertId();

            if (in_array($role, ['admin','administrator'], true)) {
                $tc = $db->query("SHOW TABLES LIKE 'admins'");
                if ($tc->rowCount() > 0) {
                    $cc = $db->query("SHOW COLUMNS FROM admins LIKE 'branch_id'");
                    if ($cc->rowCount() > 0) {
                        $db->prepare("INSERT INTO admins (username, email, password, role, phone, full_name, branch_id, created_at)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
                           ->execute([$name, $email, $hashed, $role, $phone, $name, $branchValue]);
                    } else {
                        $db->prepare("INSERT INTO admins (username, email, password, role, phone, full_name, created_at)
                                      VALUES (?, ?, ?, ?, ?, ?, NOW())")
                           ->execute([$name, $email, $hashed, $role, $phone, $name]);
                    }
                }
            }

            // Initial-balance transaction only if we have a branch to store
            if ($balance > 0 && $branchValue !== null) {
                $tc = $db->query("SHOW TABLES LIKE 'transactions'");
                if ($tc->rowCount() > 0) {
                    $db->prepare("INSERT INTO transactions
                        (member_id, member_name, account_number, type, amount, charge, rate, net_amount, date, status, description, branch_id)
                        VALUES (?, ?, ?, 'deposit', ?, ?, ?, ?, ?, 'approved', 'Initial balance', ?)")
                       ->execute([$newId, $name, $accountNumber, $balance, $charge, $rate, $net, $joinDate, $branchValue]);
                }
            }

            $db->commit();

            $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$newId]);
            $formatted = formatMember($stmt->fetch(PDO::FETCH_ASSOC));
            $formatted['initial_charge'] = $charge;
            $formatted['initial_net'] = $net;

            http_response_code(201);
            echo json_encode(['member' => $formatted]);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}

// ============================================================
// PUT update
// ============================================================
function updateMember($db, $id) {
    global $BRANCH_ID;
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) jsonErr(400, 'Invalid JSON data');

        if ($BRANCH_ID > 0) {
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ? AND branch_id = ? LIMIT 1");
            $stmt->execute([$id, $BRANCH_ID]);
        } else {
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
        }
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current) jsonErr(404, 'Member not found');

        $rowBranchId = (int)($current['branch_id'] ?? 0);

        if (isset($data['email']) && $data['email'] !== $current['email']) {
            if ($rowBranchId > 0) {
                $ec = $db->prepare("SELECT id FROM members WHERE email = ? AND id != ? AND branch_id = ?");
                $ec->execute([$data['email'], $id, $rowBranchId]);
            } else {
                $ec = $db->prepare("SELECT id FROM members WHERE email = ? AND id != ?");
                $ec->execute([$data['email'], $id]);
            }
            if ($ec->rowCount() > 0) jsonErr(409, 'Email already exists');
        }

        if (isset($data['phone']) && $data['phone'] !== '') {
            $cleaned = preg_replace('/\D/', '', $data['phone']);
            if (strlen($cleaned) !== 11 || !startsWith($cleaned, '0')) {
                jsonErr(400, 'Phone must be 11 digits starting with 0');
            }
            $data['phone'] = $cleaned;
            if ($cleaned !== ($current['phone'] ?? '')) {
                if ($rowBranchId > 0) {
                    $pc = $db->prepare("SELECT id FROM members WHERE phone = ? AND id != ? AND branch_id = ?");
                    $pc->execute([$cleaned, $id, $rowBranchId]);
                } else {
                    $pc = $db->prepare("SELECT id FROM members WHERE phone = ? AND id != ?");
                    $pc->execute([$cleaned, $id]);
                }
                if ($pc->rowCount() > 0) jsonErr(409, 'Phone already exists');
            }
        }

        $fields = [];
        $params = [];

        $simple = [
            'name','email','phone','status',
            'date_of_birth','gender','address',
            'next_of_kin_name','next_of_kin_phone','next_of_kin_relationship',
            'savings_plan','savings_method','item_name','duration',
            'bank_name','bank_account_number','passport_photo'
        ];
        foreach ($simple as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $v = $data[$f];
                if (is_string($v) && trim($v) === '') $v = null;
                $params[] = $v;
            }
        }
        foreach (['amount','total_amount','balance'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = floatval($data[$f] ?? 0);
            }
        }

        $oldRole = $current['role'];
        $newRole = $data['role'] ?? $oldRole;
        if (isset($data['role'])) {
            $fields[] = "role = ?";
            $params[] = $data['role'];
        }

        $newHash = null;
        if (!empty($data['password'])) {
            $newHash = password_hash($data['password'], PASSWORD_DEFAULT);
            $fields[] = "password = ?";
            $params[] = $newHash;
        }

        if (isset($data['profile_completed'])) {
            $fields[] = "profile_completed = ?";
            $params[] = intval($data['profile_completed']);
        }

        // Allow the caller to move a member to a branch
        if (isset($data['branch_id']) && (int)$data['branch_id'] > 0) {
            $fields[] = "branch_id = ?";
            $params[] = (int)$data['branch_id'];
        }

        if (empty($fields)) jsonErr(400, 'No fields to update');

        $params[] = $id;
        $sql = "UPDATE members SET " . implode(', ', $fields) . " WHERE id = ?";
        $db->prepare($sql)->execute($params);

        $refresh = $db->prepare("SELECT password FROM members WHERE id = ?");
        $refresh->execute([$id]);
        $currentHash = $refresh->fetchColumn();

        $userName  = $data['name']  ?? $current['name'];
        $userEmail = $data['email'] ?? $current['email'];
        $userPhone = $data['phone'] ?? $current['phone'];

        $tc = $db->query("SHOW TABLES LIKE 'admins'");
        if ($tc->rowCount() > 0) {
            if (in_array($newRole, ['admin','administrator'], true)) {
                $hash = $newHash ?? $currentHash;
                $check = $db->prepare("SELECT id FROM admins WHERE email = ?");
                $check->execute([$userEmail]);
                if ($check->rowCount() > 0) {
                    $db->prepare("UPDATE admins SET username=?, full_name=?, phone=?, password=?, role=? WHERE email=?")
                       ->execute([$userName, $userName, $userPhone, $hash, $newRole, $userEmail]);
                } else {
                    $db->prepare("INSERT INTO admins (username, email, password, role, phone, full_name, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, NOW())")
                       ->execute([$userName, $userEmail, $hash, $newRole, $userPhone, $userName]);
                }
            } elseif (in_array($oldRole, ['admin','administrator'], true)
                      && !in_array($newRole, ['admin','administrator'], true)) {
                $db->prepare("DELETE FROM admins WHERE email = ?")->execute([$userEmail]);
            }
        }

        $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode([
            'success' => true,
            'message' => 'Member updated successfully',
            'member'  => formatMember($stmt->fetch(PDO::FETCH_ASSOC)),
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}

// ============================================================
// DELETE
// ============================================================
function deleteMember($db, $id) {
    global $BRANCH_ID;
    try {
        if ($BRANCH_ID > 0) {
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ? AND branch_id = ? LIMIT 1");
            $stmt->execute([$id, $BRANCH_ID]);
        } else {
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
        }
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) jsonErr(404, 'Member not found');

        $db->beginTransaction();
        try {
            $cc = $db->query("SHOW COLUMNS FROM members LIKE 'deleted_at'");
            if ($cc->rowCount() > 0) {
                $db->prepare("UPDATE members SET deleted_at = NOW(), status = 'Deleted' WHERE id = ?")
                   ->execute([$id]);
            } else {
                $tc = $db->query("SHOW TABLES LIKE 'transactions'");
                if ($tc->rowCount() > 0) {
                    $db->prepare("DELETE FROM transactions WHERE member_id = ?")->execute([$id]);
                }
                $tc = $db->query("SHOW TABLES LIKE 'loans'");
                if ($tc->rowCount() > 0) {
                    $db->prepare("DELETE FROM loans WHERE user_id = ?")->execute([$id]);
                }
                $db->prepare("DELETE FROM members WHERE id = ?")->execute([$id]);
            }

            $tc = $db->query("SHOW TABLES LIKE 'admins'");
            if ($tc->rowCount() > 0 && in_array($user['role'], ['admin','administrator'], true)) {
                $db->prepare("DELETE FROM admins WHERE email = ?")->execute([$user['email']]);
            }

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Member deleted successfully']);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete member: ' . $e->getMessage()]);
    }
}

// ============================================================
// Bulk action — branch filter applied only when a branch is set
// ============================================================
function bulkAction($db) {
    global $BRANCH_ID;
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['userIds']) || !isset($data['action'])) {
            jsonErr(400, 'Invalid request');
        }
        $userIds = $data['userIds'];
        $action  = $data['action'];
        if (empty($userIds)) jsonErr(400, 'No users selected');

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));

        $db->beginTransaction();
        try {
            $branchClause = $BRANCH_ID > 0 ? "branch_id = ? AND " : "";
            $branchParams = $BRANCH_ID > 0 ? [$BRANCH_ID] : [];

            switch ($action) {
                case 'activate':
                    $stmt = $db->prepare("UPDATE members SET status = 'Active' WHERE {$branchClause}id IN ($placeholders)");
                    $stmt->execute(array_merge($branchParams, $userIds));
                    $count = $stmt->rowCount();
                    $msg = 'activated';
                    break;
                case 'suspend':
                    $stmt = $db->prepare("UPDATE members SET status = 'Suspended' WHERE {$branchClause}id IN ($placeholders)");
                    $stmt->execute(array_merge($branchParams, $userIds));
                    $count = $stmt->rowCount();
                    $msg = 'suspended';
                    break;
                case 'delete':
                    $count = 0;
                    foreach ($userIds as $uid) {
                        $g = $db->prepare("SELECT * FROM members WHERE id = ?" . ($BRANCH_ID > 0 ? " AND branch_id = ?" : ""));
                        $g->execute($BRANCH_ID > 0 ? [$uid, $BRANCH_ID] : [$uid]);
                        $u = $g->fetch(PDO::FETCH_ASSOC);
                        if (!$u) continue;

                        $cc = $db->query("SHOW COLUMNS FROM members LIKE 'deleted_at'");
                        if ($cc->rowCount() > 0) {
                            $db->prepare("UPDATE members SET deleted_at = NOW(), status = 'Deleted' WHERE id = ?")
                               ->execute([$uid]);
                        } else {
                            $db->prepare("DELETE FROM members WHERE id = ?")->execute([$uid]);
                        }

                        $tc = $db->query("SHOW TABLES LIKE 'admins'");
                        if ($tc->rowCount() > 0 && in_array($u['role'], ['admin','administrator'], true)) {
                            $db->prepare("DELETE FROM admins WHERE email = ?")->execute([$u['email']]);
                        }
                        $count++;
                    }
                    $msg = 'deleted';
                    break;
                default:
                    jsonErr(400, 'Invalid action');
            }

            $db->commit();
            echo json_encode(['success' => true, 'message' => "$count user(s) $msg successfully"]);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}