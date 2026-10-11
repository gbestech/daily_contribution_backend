<?php
// api/permissions.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

while (ob_get_level()) ob_end_clean();

// ---- CORS: pick ONE origin, don't mix * with credentials ----
$allowedOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedList = [
    'http://localhost:3000',
    'http://localhost:5173',
    // add production origin here
];
if (in_array($allowedOrigin, $allowedList, true)) {
    header("Access-Control-Allow-Origin: $allowedOrigin");
    header("Access-Control-Allow-Credentials: true");
}
header("Access-Control-Allow-Methods: GET, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
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
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// ============================================================
// Whitelist of valid operation keys.
// Keep this in sync with OPERATIONS in
// src/components/admin/Permissions.jsx
// ============================================================
$VALID_OPERATIONS = [
    // Dashboard
    'view_dashboard', 'view_broadsheet', 'view_reports',

    // Transactions
    'view_transactions', 'approve_transactions', 'reject_transactions',
    'create_deposit', 'create_withdrawal', 'create_transfer', 'view_history',

    // Expenses
    'view_expenses', 'manage_expenses', 'approve_expenses', 'delete_expenses',

    // Members
    'create_member', 'edit_member', 'delete_member', 'assign_role',
    'suspend_member',

    // Branches
    'view_branches', 'create_branch', 'edit_branch', 'delete_branch',
    'toggle_branch_status', 'copy_registration_link', 'assign_branch_manager',
    'switch_branch', 'view_all_branches',

    // Loans
    'view_loans', 'create_loan', 'approve_loan', 'reject_loan',
    'disburse_loan', 'record_repayment', 'manage_loan_settings',

    // Contributions
    'view_contributions', 'manage_contributions',
    'manage_contribution_settings',

    // Commission & Payments
    'view_commissions', 'manage_commissions', 'manage_commission_settings',
    'manage_payment_settings', 'manage_discount_settings', 'process_refunds',

    // Staff & System
    'manage_staff', 'manage_permissions', 'manage_settings',
    'manage_security', 'manage_suspension', 'view_audit_log',
];

$VALID_ROLES = ['admin', 'manager', 'member'];

// ------------------------------------------------------------
// Branch keys.
// ------------------------------------------------------------
$BRANCH_KEYS = [
    'view_branches', 'create_branch', 'edit_branch', 'delete_branch',
    'toggle_branch_status', 'copy_registration_link', 'assign_branch_manager',
    'switch_branch', 'view_all_branches',
];

// 🆕 The ONLY branch permissions a Manager may hold:
//    view their branch + edit it. Nothing else.
$MANAGER_BRANCH_KEYS = ['view_branches', 'edit_branch'];

// 🆕 Branch permissions a Manager must NEVER have
//    (create / delete / activate / link / assign manager / switch / view all).
$MANAGER_BLOCKED_KEYS = array_values(array_diff($BRANCH_KEYS, $MANAGER_BRANCH_KEYS));

// ============================================================
// Sane defaults per role, used when a branch has no rows yet.
// ============================================================
function defaultPermissionsForRole(string $role): array {
    // Every non-branch operation — Managers get this whole list.
    $nonBranchOps = [
        'view_dashboard', 'view_broadsheet', 'view_reports',
        'view_transactions', 'approve_transactions', 'reject_transactions',
        'create_deposit', 'create_withdrawal', 'create_transfer', 'view_history',
        'view_expenses', 'manage_expenses', 'approve_expenses', 'delete_expenses',
        'create_member', 'edit_member', 'delete_member', 'assign_role',
        'suspend_member',
        'view_loans', 'create_loan', 'approve_loan', 'reject_loan',
        'disburse_loan', 'record_repayment', 'manage_loan_settings',
        'view_contributions', 'manage_contributions',
        'manage_contribution_settings',
        'view_commissions', 'manage_commissions', 'manage_commission_settings',
        'manage_payment_settings', 'manage_discount_settings', 'process_refunds',
        'manage_staff', 'manage_permissions', 'manage_settings',
        'manage_security', 'manage_suspension', 'view_audit_log',
    ];

    // Branch operations — only Admin gets all of these by default.
    $branchOps = [
        'view_branches', 'create_branch', 'edit_branch', 'delete_branch',
        'toggle_branch_status', 'copy_registration_link', 'assign_branch_manager',
        'switch_branch', 'view_all_branches',
    ];

    switch ($role) {
        case 'admin':
            // Admin = everything
            return array_merge($nonBranchOps, $branchOps);

        case 'manager':
            // 🆕 Manager = everything non-branch + view/edit their branch
            return array_merge($nonBranchOps, ['view_branches', 'edit_branch']);

        case 'member':
        default:
            // Member = read-only + personal actions
            return [
                'view_dashboard',
                'view_transactions', 'create_deposit', 'create_withdrawal',
                'create_transfer', 'view_history',
                'view_loans', 'create_loan',
                'view_contributions',
            ];
    }
}

// ============================================================
// Schema bootstrap — safe to run on every request (idempotent).
// ============================================================
function ensurePermissionsSchema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS permissions (
            role VARCHAR(50) NOT NULL,
            branch_id INT NOT NULL DEFAULT 0,
            operations TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (role, branch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $cols = $db->query("SHOW COLUMNS FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
    $hasBranchId = in_array('branch_id', $cols, true);

    if (!$hasBranchId) {
        $db->exec("ALTER TABLE permissions
                   ADD COLUMN branch_id INT NOT NULL DEFAULT 0 FIRST");
    }

    $pk = $db->query("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'permissions'
          AND CONSTRAINT_NAME = 'PRIMARY'
        ORDER BY ORDINAL_POSITION
    ")->fetchAll(PDO::FETCH_COLUMN);

    $pkIsComposite = (count($pk) === 2 && $pk[0] === 'role' && $pk[1] === 'branch_id');
    if (!$pkIsComposite) {
        try { $db->exec("ALTER TABLE permissions DROP PRIMARY KEY"); } catch (Exception $e) { /* ignore */ }
        try { $db->exec("ALTER TABLE permissions ADD PRIMARY KEY (role, branch_id)"); } catch (Exception $e) { /* ignore */ }
    }
}

try {
    ensurePermissionsSchema($db);
} catch (Exception $e) {
    error_log("Schema bootstrap failed: " . $e->getMessage());
}

// ============================================================
// Auth: require a logged-in admin.
// Uncomment and adapt once you have a session helper.
// ============================================================
// require_once __DIR__ . '/config/auth.php';
// requireRole('admin');

$method = $_SERVER['REQUEST_METHOD'];

// ------------------------------------------------------------
// GET — return permissions for a branch (or global defaults)
// ------------------------------------------------------------
if ($method === 'GET') {
    $branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

    try {
        // 1) Try to load rows for this branch
        $stmt = $db->prepare("SELECT role, operations FROM permissions WHERE branch_id = ?");
        $stmt->execute([$branchId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            if (!in_array($row['role'], $VALID_ROLES, true)) continue;
            $decoded = json_decode($row['operations'], true);
            $result[$row['role']] = is_array($decoded) ? $decoded : [];
        }

        // 2) For any role missing, fall back to branch_id = 0 (global default)
        $missing = array_diff($VALID_ROLES, array_keys($result));
        if (!empty($missing)) {
            $placeholders = implode(',', array_fill(0, count($missing), '?'));
            $stmt = $db->prepare("SELECT role, operations FROM permissions
                                  WHERE branch_id = 0 AND role IN ($placeholders)");
            $stmt->execute(array_values($missing));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $decoded = json_decode($row['operations'], true);
                $result[$row['role']] = is_array($decoded) ? $decoded : [];
            }
        }

        // 3) For any role still missing, use hardcoded defaults
        foreach ($VALID_ROLES as $role) {
            if (!isset($result[$role])) {
                $result[$role] = defaultPermissionsForRole($role);
            }
        }

        // 🆕 Sanity filter: Managers may only hold view_branches + edit_branch.
        // Strip every OTHER branch key if one slipped in via an old save.
        if (isset($result['manager']) && is_array($result['manager'])) {
            $result['manager'] = array_values(array_filter(
                $result['manager'],
                function ($op) use ($MANAGER_BLOCKED_KEYS) {
                    return !in_array($op, $MANAGER_BLOCKED_KEYS, true);
                }
            ));
        }

        // Ensure output keys are always present & ordered
        $ordered = [
            'admin'   => $result['admin'],
            'manager' => $result['manager'],
            'member'  => $result['member'],
        ];

        echo json_encode([
            'status'      => true,
            'branch_id'   => $branchId,
            'permissions' => $ordered,
        ]);
    } catch (Exception $e) {
        error_log("GET error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load permissions']);
    }
    exit();
}

// ------------------------------------------------------------
// PUT — upsert permissions for one role in one branch
// ------------------------------------------------------------
if ($method === 'PUT') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !isset($data['role']) || !isset($data['operations'])) {
        http_response_code(400);
        echo json_encode(['error' => 'role and operations are required']);
        exit();
    }

    $role = strtolower(trim($data['role']));
    if (!in_array($role, $VALID_ROLES, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid role: ' . $role]);
        exit();
    }

    if (!is_array($data['operations'])) {
        http_response_code(400);
        echo json_encode(['error' => 'operations must be an array']);
        exit();
    }

    $branchId = 0;
    if (isset($data['branch_id']) && $data['branch_id'] !== null && $data['branch_id'] !== '') {
        $branchId = (int)$data['branch_id'];
    } elseif (isset($_GET['branch_id'])) {
        $branchId = (int)$_GET['branch_id'];
    }

    // Sanitize: keep only strings, drop empties, drop non-whitelisted keys,
    // de-duplicate. 🆕 For Managers, drop every branch key EXCEPT
    // view_branches and edit_branch.
    $seen = [];
    $clean = [];
    foreach ($data['operations'] as $op) {
        if (!is_string($op)) continue;
        $op = trim($op);
        if ($op === '') continue;
        if (!in_array($op, $VALID_OPERATIONS, true)) continue;

        // Hard rule: Manager may only view/edit branches.
        if ($role === 'manager' && in_array($op, $MANAGER_BLOCKED_KEYS, true)) {
            continue;
        }

        if (isset($seen[$op])) continue;
        $seen[$op] = true;
        $clean[] = $op;
    }

    $encoded = json_encode(array_values($clean));

    try {
        $stmt = $db->prepare("
            INSERT INTO permissions (role, branch_id, operations)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE operations = VALUES(operations)
        ");
        $stmt->execute([$role, $branchId, $encoded]);

        echo json_encode([
            'status'     => true,
            'message'    => "Permissions for $role updated",
            'role'       => $role,
            'branch_id'  => $branchId,
            'operations' => $clean,
        ]);
    } catch (Exception $e) {
        error_log("PUT DB error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update permissions']);
    }
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed: ' . $method]);
