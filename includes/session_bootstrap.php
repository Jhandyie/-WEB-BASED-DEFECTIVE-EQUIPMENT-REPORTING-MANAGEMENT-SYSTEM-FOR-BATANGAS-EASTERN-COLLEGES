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

if (!function_exists('becGuestSessionActive')) {
    /**
     * Is the reporter's sign-in still good — and keep it fresh if so.
     *
     * `guest_since` was written at sign-in and never looked at again, so a
     * reporter session lasted until the browser was closed. On the shared
     * machines in a lab or library that meant the next person to sit down was
     * still signed in as whoever used it last, and any report they filed went
     * out under that person's name and email.
     *
     * Idle timeout, not a fixed expiry: someone working through a long report
     * with photos should not be thrown out mid-way.
     */
    function becGuestSessionActive(int $idleSeconds = 28800, int $maxSeconds = 86400): bool {
        if (empty($_SESSION['guest_email'])) { return false; }
        $now   = time();
        $since = (int)($_SESSION['guest_since'] ?? 0);
        $last  = (int)($_SESSION['guest_last'] ?? $since);
        // Sessions predating this check carry no timestamps; treat them as fresh
        // rather than logging everyone out the moment it ships.
        if ($since === 0) { $_SESSION['guest_since'] = $now; $_SESSION['guest_last'] = $now; return true; }
        if (($now - $last) > $idleSeconds || ($now - $since) > $maxSeconds) {
            becEndGuestSession();
            return false;
        }
        $_SESSION['guest_last'] = $now;
        return true;
    }
}

if (!function_exists('becEndGuestSession')) {
    /** Drop the reporter's identity, leaving the rest of the session alone. */
    function becEndGuestSession(): void {
        unset($_SESSION['guest_name'], $_SESSION['guest_email'], $_SESSION['guest_since'],
              $_SESSION['guest_last'], $_SESSION['guest_name_source']);
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
