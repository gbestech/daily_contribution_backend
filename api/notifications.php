<?php
// api/notifications.php
//
// Self-contained notification system:
//
//  1) Helpers: notifyUser / notifyBranchAdmins / notifyAllAdmins
//  2) HTTP router: GET list, POST create/mark_read/mark_all_read/delete
//  3) Auto-hooks: when this file is `require`-d by branches.php /
//     transactions.php / loans.php / expenses.php / members.php, it
//     registers a shutdown handler that inspects $_SERVER + request body
//     and creates the appropriate notification row(s) automatically.
//
// To activate: add `require_once __DIR__ . '/notifications.php';` at the
// top of the other endpoints. No other code changes needed.

// ============================================================
// 1) HELPERS — always declared
// ============================================================

if (!function_exists('notifyUser')) {
    function notifyUser(PDO $db, int $userId, string $type, string $title, string $message, ?string $link = null): void {
        if ($userId <= 0) return;
        try {
            $db->prepare(
                "INSERT INTO notifications (user_id, type, title, message, link, is_read)
                 VALUES (?, ?, ?, ?, ?, 0)"
            )->execute([$userId, $type, $title, $message, $link]);
        } catch (Throwable $e) {
            error_log("[notify] user={$userId} type={$type} failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('notifyBranchAdmins')) {
    function notifyBranchAdmins(PDO $db, int $branchId, string $type, string $title, string $message, ?string $link = null): void {
        if ($branchId <= 0) return;
        try {
            $stmt = $db->prepare(
                "SELECT id FROM admins
                 WHERE branch_id = ?
                   AND role IN ('admin','administrator','manager','branch_manager')"
            );
            $stmt->execute([$branchId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                notifyUser($db, (int)$adminId, $type, $title, $message, $link);
            }
        } catch (Throwable $e) {
            error_log("[notify-branch] branch={$branchId} type={$type} failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('notifyAllAdmins')) {
    function notifyAllAdmins(PDO $db, string $type, string $title, string $message, ?string $link = null): void {
        try {
            $stmt = $db->query(
                "SELECT id FROM admins
                 WHERE role IN ('admin','administrator','manager','branch_manager')"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                notifyUser($db, (int)$adminId, $type, $title, $message, $link);
            }
        } catch (Throwable $e) {
            error_log("[notify-all] type={$type} failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('normalizeNotification')) {
    function normalizeNotification(array $row): array {
        $row['id']      = isset($row['id']) ? (int)$row['id'] : 0;
        $row['user_id'] = isset($row['user_id']) ? (int)$row['user_id'] : 0;
        $row['is_read'] = ((int)($row['is_read'] ?? 0)) === 1;
        return $row;
    }
}

// ============================================================
// 2) HTTP ROUTER — runs only when this file is called directly
// ============================================================

$notifCalledDirectly = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);

if ($notifCalledDirectly) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With, X-User-Role, X-User-Branch-Id');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    require_once __DIR__ . '/config/database.php';

    try {
        $db = getDBConnection();
        if ($db) $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Throwable $e) {
        error_log("[notifications] DB connect failed: " . $e->getMessage());
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

    if ($method === 'GET') {
        $userId = intval($_GET['user_id'] ?? 0);
        $limit  = max(1, min(100, intval($_GET['limit'] ?? 30)));

        if (!$userId) {
            http_response_code(400);
            echo json_encode(['status' => false, 'message' => 'user_id required']);
            exit;
        }

        try {
            $stmt = $db->prepare(
                "SELECT * FROM notifications
                 WHERE user_id = :uid
                 ORDER BY created_at DESC, id DESC
                 LIMIT :lim"
            );
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = array_map('normalizeNotification', $stmt->fetchAll(PDO::FETCH_ASSOC));

            $unreadStmt = $db->prepare(
                "SELECT COUNT(*) FROM notifications
                 WHERE user_id = :uid AND (is_read = 0 OR is_read IS NULL)"
            );
            $unreadStmt->execute([':uid' => $userId]);
            $unread = (int)$unreadStmt->fetchColumn();
        } catch (Throwable $e) {
            error_log("[notifications] GET failed: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => false, 'error' => 'Query failed']);
            exit;
        }

        echo json_encode([
            'status'       => true,
            'user_id'      => $userId,
            'data'         => $rows,
            'unread_count' => $unread,
        ]);
        exit;
    }

    if ($method === 'POST') {
        $input  = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $input['action'] ?? '';
        $userId = intval($input['user_id'] ?? 0);

        if (!$userId) {
            http_response_code(400);
            echo json_encode(['status' => false, 'message' => 'user_id required']);
            exit;
        }

        $insertedId = null;

        try {
            switch ($action) {
                case 'mark_read':
                    $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid")
                       ->execute([':id' => intval($input['id'] ?? 0), ':uid' => $userId]);
                    break;

                case 'mark_all_read':
                    $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND (is_read = 0 OR is_read IS NULL)")
                       ->execute([':uid' => $userId]);
                    break;

                case 'delete':
                    $db->prepare("DELETE FROM notifications WHERE id = :id AND user_id = :uid")
                       ->execute([':id' => intval($input['id'] ?? 0), ':uid' => $userId]);
                    break;

                case 'create':
                    $db->prepare(
                        "INSERT INTO notifications (user_id, type, title, message, link, is_read)
                         VALUES (:uid, :type, :title, :msg, :link, 0)"
                    )->execute([
                        ':uid'   => $userId,
                        ':type'  => $input['type']    ?? 'system',
                        ':title' => $input['title']   ?? 'Notification',
                        ':msg'   => $input['message'] ?? '',
                        ':link'  => $input['link']    ?? null,
                    ]);
                    $insertedId = (int)$db->lastInsertId();
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(['status' => false, 'message' => 'Unknown action']);
                    exit;
            }
        } catch (Throwable $e) {
            error_log("[notifications] POST ($action) failed: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => false, 'error' => 'Save failed']);
            exit;
        }

        $out = ['status' => true];
        if ($insertedId !== null) $out['id'] = $insertedId;
        echo json_encode($out);
        exit;
    }

    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'Method not allowed']);
    exit;
}

// ============================================================
// 3) AUTO-HOOKS — when require-d by another endpoint, register a
//    shutdown handler that sniffs what just happened and inserts
//    the appropriate notification(s).
// ============================================================

// Read the JSON body ONCE now (before the caller consumes php://input).
// On a require-d flow, the caller already ran its own file_get_contents,
// so re-reading returns "" — fall back to any cached $GLOBALS value they
// may have set, or skip.
$__notifRaw  = '';
$__notifBody = [];
try {
    if (in_array($_SERVER['REQUEST_METHOD'] ?? '', ['POST', 'PUT'], true)) {
        // Some endpoints already read php://input; try a read and fall back.
        $__notifRaw = @file_get_contents('php://input');
        if (is_string($__notifRaw) && $__notifRaw !== '') {
            $__notifBody = json_decode($__notifRaw, true) ?: [];
        }
    }
} catch (Throwable $e) {
    // silent
}

$__notifScript = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
$__notifMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$__notifPath   = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

// Helper: extract the last numeric segment of the URL, if any (matches
// transactions.php/{id}/approve etc.)
$__notifPathId = 0;
if (preg_match('#/(\d+)(?:/[a-z]+)?/?$#i', $__notifPath, $m)) {
    $__notifPathId = (int)$m[1];
}

// Only hook if this is a write request and not the direct HTTP entrypoint
if (in_array($__notifMethod, ['POST', 'PUT', 'DELETE'], true) && !$notifCalledDirectly) {
    register_shutdown_function(function () use (
        $__notifScript,
        $__notifMethod,
        $__notifPath,
        $__notifPathId,
        $__notifBody
    ) {
        // Best-effort — anything that throws here is swallowed so it
        // never breaks the caller's response.
        try {
            require_once __DIR__ . '/config/database.php';
            $db = getDBConnection();
            if (!$db) return;
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (Throwable $e) {
            error_log('[notify-hook] DB failed: ' . $e->getMessage());
            return;
        }

        $lowerPath = strtolower($__notifPath);

        try {
            // ---------- BRANCHES ----------
            if ($__notifScript === 'branches.php') {
                if ($__notifMethod === 'POST') {
                    $name = trim((string)($__notifBody['name'] ?? ''));
                    if ($name !== '') {
                        notifyAllAdmins(
                            $db,
                            'system',
                            '🏢 New Branch Created',
                            'Branch "' . $name . '" was added to the system.',
                            '/admin/dashboard'
                        );
                    }
                }
            }

            // ---------- TRANSACTIONS ----------
            elseif ($__notifScript === 'transactions.php') {
                if ($__notifMethod === 'POST') {
                    // Member created a transaction → notify branch admins
                    $memberId = (int)($__notifBody['memberId'] ?? $__notifBody['member_id'] ?? 0);
                    $type     = $__notifBody['type'] ?? 'transaction';
                    $amount   = (float)($__notifBody['amount'] ?? 0);
                    $branchId = (int)($__notifBody['branch_id'] ?? 0);
                    $name     = $__notifBody['memberName'] ?? $__notifBody['member_name'] ?? 'A member';

                    if ($branchId > 0 && $amount > 0) {
                        notifyBranchAdmins(
                            $db,
                            $branchId,
                            'pending',
                            'New ' . ucfirst($type) . ' Request',
                            $name . ' submitted a ' . $type . ' of ₦' . number_format($amount, 2),
                            '/admin/pending'
                        );
                    }
                } elseif ($__notifMethod === 'PUT') {
                    // Approve or reject → notify the member
                    $txId = $__notifPathId;
                    if ($txId > 0) {
                        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ? LIMIT 1");
                        $stmt->execute([$txId]);
                        $tx = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($tx) {
                            $memberId = (int)($tx['member_id'] ?? 0);
                            $type     = $tx['type'] ?? 'transaction';
                            $amount   = (float)($tx['amount'] ?? 0);
                            $net      = (float)($tx['net_amount'] ?? $amount);
                            $charge   = (float)($tx['charge'] ?? 0);
                            $status   = strtolower($tx['status'] ?? '');

                            if ($status === 'approved') {
                                notifyUser(
                                    $db,
                                    $memberId,
                                    $type === 'withdrawal' ? 'withdrawal' : 'deposit',
                                    ucfirst($type) . ' Approved',
                                    'Your ' . $type . ' of ₦' . number_format($amount, 2) .
                                        ($charge > 0 ? ' (net ₦' . number_format($net, 2) . ')' : '') .
                                        ' has been approved.',
                                    '/member/history'
                                );
                            } elseif ($status === 'rejected') {
                                $reason = trim((string)($tx['rejection_reason'] ?? 'Not specified'));
                                notifyUser(
                                    $db,
                                    $memberId,
                                    'rejection',
                                    ucfirst($type) . ' Rejected',
                                    'Your ' . $type . ' of ₦' . number_format($amount, 2) .
                                        ' was rejected. Reason: ' . $reason,
                                    '/member/history'
                                );
                            }
                        }
                    }
                }
            }

            // ---------- LOANS ----------
            elseif ($__notifScript === 'loans.php') {
                if ($__notifMethod === 'POST') {
                    $amount   = (float)($__notifBody['amount'] ?? 0);
                    $branchId = (int)($__notifBody['branch_id'] ?? 0);
                    if ($amount > 0) {
                        notifyBranchAdmins(
                            $db,
                            $branchId,
                            'pending',
                            'New Loan Request',
                            'A loan of ₦' . number_format($amount, 2) . ' was requested.',
                            '/admin/pending'
                        );
                    }
                } elseif ($__notifMethod === 'PUT') {
                    $loanId = (int)($__notifBody['id'] ?? $__notifPathId);
                    if ($loanId > 0) {
                        $stmt = $db->prepare("SELECT * FROM loans WHERE id = ? LIMIT 1");
                        $stmt->execute([$loanId]);
                        $loan = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($loan) {
                            $loanUserId = (int)($loan['user_id'] ?? 0);
                            $loanAmount = (float)($loan['amount'] ?? 0);
                            $status     = strtolower($loan['status'] ?? '');
                            if ($status === 'approved') {
                                notifyUser($db, $loanUserId, 'loan', 'Loan Approved',
                                    'Your loan of ₦' . number_format($loanAmount, 2) . ' has been approved.',
                                    '/member/borrowing');
                            } elseif ($status === 'rejected') {
                                notifyUser($db, $loanUserId, 'rejection', 'Loan Rejected',
                                    'Your loan of ₦' . number_format($loanAmount, 2) . ' was rejected.',
                                    '/member/borrowing');
                            }
                        }
                    }
                }
            }

            // ---------- EXPENSES ----------
            elseif ($__notifScript === 'expenses.php') {
                if ($__notifMethod === 'POST') {
                    $title    = trim((string)($__notifBody['title'] ?? 'Expense'));
                    $amount   = (float)($__notifBody['amount'] ?? 0);
                    $branchId = (int)($__notifBody['branch_id'] ?? 0);
                    if ($amount > 0) {
                        notifyBranchAdmins(
                            $db,
                            $branchId,
                            'pending',
                            'New Expense Request',
                            $title . ' — ₦' . number_format($amount, 2) . ' submitted.',
                            '/admin/expenses'
                        );
                    }
                } elseif ($__notifMethod === 'PUT') {
                    $expId = $__notifPathId;
                    if ($expId > 0) {
                        $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ? LIMIT 1");
                        $stmt->execute([$expId]);
                        $exp = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($exp) {
                            $reqId  = (int)($exp['requested_by_id'] ?? $exp['user_id'] ?? 0);
                            $title  = $exp['title'] ?? 'Expense';
                            $amount = (float)($exp['amount'] ?? 0);
                            $status = strtolower($exp['status'] ?? '');
                            if ($status === 'approved' && $reqId > 0) {
                                notifyUser($db, $reqId, 'approval', 'Expense Approved',
                                    'Your expense "' . $title . '" of ₦' . number_format($amount, 2) . ' was approved.',
                                    '/admin/expenses');
                            } elseif ($status === 'rejected' && $reqId > 0) {
                                notifyUser($db, $reqId, 'rejection', 'Expense Rejected',
                                    'Your expense "' . $title . '" of ₦' . number_format($amount, 2) . ' was rejected.',
                                    '/admin/expenses');
                            }
                        }
                    }
                }
            }

            // ---------- MEMBERS ----------
            elseif ($__notifScript === 'members.php') {
                if ($__notifMethod === 'POST') {
                    $memberId = (int)$db->lastInsertId(); // may be 0 if not last inserted
                    $branchId = (int)($__notifBody['branch_id'] ?? 0);
                    $name     = trim((string)($__notifBody['name'] ?? 'A new member'));
                    $account  = trim((string)($__notifBody['accountNumber'] ?? $__notifBody['account_number'] ?? ''));

                    if ($memberId > 0) {
                        notifyUser($db, $memberId, 'member', 'Welcome to Baleeg',
                            'Your account has been created.' . ($account ? ' Account number: ' . $account : ''),
                            '/member/dashboard');
                    }
                    if ($branchId > 0) {
                        notifyBranchAdmins($db, $branchId, 'member', 'New Member Added',
                            $name . ' was added to your branch.',
                            '/admin/members');
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[notify-hook] failed: ' . $e->getMessage());
        }
    });
}