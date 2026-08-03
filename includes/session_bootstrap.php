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

if (!function_exists('becHardenSessionCookie')) {
    /**
     * Apply the cookie flags every session on this site should carry.
     *
     * HttpOnly keeps the id out of reach of any script that manages to run on
     * the page, and SameSite=Lax stops the cookie riding along on a request
     * another site makes. Must be called before session_start().
     */
    function becHardenSessionCookie(): void {
        if (session_status() === PHP_SESSION_ACTIVE) { return; }
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('startPublicSession')) {
    /**
     * The reporter portal's session, on PHP's default cookie name.
     *
     * These pages called session_start() directly, so their cookie went out
     * with no flags at all — the one session on the site a stranger can obtain,
     * and the only one that was readable by script and sent cross-site.
     */
    function startPublicSession(): void {
        if (session_status() === PHP_SESSION_ACTIVE) { return; }
        becHardenSessionCookie();
        session_start();
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
