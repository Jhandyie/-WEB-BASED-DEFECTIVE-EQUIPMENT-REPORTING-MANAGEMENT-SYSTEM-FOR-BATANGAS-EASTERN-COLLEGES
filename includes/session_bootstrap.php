<?php
// Role-scoped session bootstrap for parallel logins across dashboards.

if (!function_exists('becDetectSessionContext')) {
    function becDetectSessionContext() {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = basename($script);

        if (strpos($script, '/admin/') !== false || strpos($base, 'admin_') === 0) {
            return 'admin';
        }
        if (strpos($script, '/technician/') !== false || strpos($base, 'technician_') === 0) {
            return 'technician';
        }
        if (strpos($script, '/student/') !== false || strpos($base, 'student_') === 0) {
            return 'student';
        }
        return 'main';
    }
}

if (!function_exists('becSessionNameForContext')) {
    function becSessionNameForContext($context) {
        switch ($context) {
            case 'admin':
                return 'BECSESSID_ADMIN';
            case 'technician':
                return 'BECSESSID_TECH';
            case 'student':
                return 'BECSESSID_STUDENT';
            default:
                return 'BECSESSID_MAIN';
        }
    }
}

if (!function_exists('startRoleSession')) {
    function startRoleSession($context = 'auto') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if ($context === 'auto' || $context === null || $context === '') {
            $context = becDetectSessionContext();
        }

        $sessionName = becSessionNameForContext($context);
        if (session_name() !== $sessionName) {
            session_name($sessionName);
        }

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}
?>
