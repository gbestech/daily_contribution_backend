<?php
// api/security_helper.php
// Central place to read security settings + enforce password rules.

if (!function_exists('getSecuritySettings')) {
    function getSecuritySettings($db) {
        static $cached = null;
        if ($cached !== null) return $cached;

        $defaults = [
            'two_factor_auth'          => false,
            'session_timeout'          => 30,
            'max_login_attempts'       => 5,
            'password_expiry_days'     => 90,
            'require_strong_password'  => true,
        ];

        try {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'security'");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && !empty($row['setting_value'])) {
                $saved = json_decode($row['setting_value'], true);
                if (is_array($saved)) {
                    $cached = array_merge($defaults, $saved);
                    return $cached;
                }
            }
        } catch (Exception $e) {
            // fall through to defaults
        }

        $cached = $defaults;
        return $cached;
    }
}

if (!function_exists('validatePasswordStrength')) {
    /**
     * Returns [valid => bool, message => string]
     */
    function validatePasswordStrength($password, $requireStrong) {
        if (strlen($password) < 8) {
            return ['valid' => false, 'message' => 'Password must be at least 8 characters'];
        }
        if (!$requireStrong) {
            return ['valid' => true, 'message' => ''];
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain an uppercase letter'];
        }
        if (!preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain a number'];
        }
        if (!preg_match('/[!@#$%^&*(),.?":{}|<>_\-+=\[\]\\\/;`~]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain a special character'];
        }
        return ['valid' => true, 'message' => ''];
    }
}

if (!function_exists('isAccountLocked')) {
    function isAccountLocked($member) {
        if (empty($member['locked_until'])) return false;
        return strtotime($member['locked_until']) > time();
    }
}

if (!function_exists('isPasswordExpired')) {
    function isPasswordExpired($member, $expiryDays) {
        if (empty($expiryDays) || $expiryDays <= 0) return false;
        if (empty($member['password_changed_at'])) return true;
        $ageSeconds = time() - strtotime($member['password_changed_at']);
        return $ageSeconds > ($expiryDays * 86400);
    }
}