<?php
// api/config/notify-helpers.php
// Loaded automatically from config/database.php.
// Provides notifyUser / notifyBranchAdmins / notifyAllAdmins.

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