<?php
/**
 * csrf.php — CSRF protection helpers.
 *
 * Usage in an HTML form:
 *     <form method="post">
 *         <?php echo csrf_field(); ?>
 *         ...
 *     </form>
 *
 * Usage in a fetch()/AJAX request — send the token either as the
 * `csrf_token` field or the `X-CSRF-Token` header. The token is
 * exposed to JS via a <meta name="csrf-token"> tag (see csrf_meta()).
 *
 * Server-side, call requireCsrf() at the top of any POST handler that
 * changes state. It returns true on success; on failure it sends a 419
 * response and exits (or, for JSON endpoints, a JSON error).
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        return generateCSRFToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_meta')) {
    function csrf_meta(): string {
        return '<meta name="csrf-token" content="'
            . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('verifyCSRFToken')) {
    // Legacy throwing API (kept for backward compatibility).
    function verifyCSRFToken($token) {
        if (empty($_SESSION['csrf_token']) || !is_string($token)
            || !hash_equals($_SESSION['csrf_token'], $token)) {
            throw new Exception('Invalid CSRF token');
        }
        return true;
    }
}

if (!function_exists('csrf_check')) {
    /** Non-throwing validation. Returns true/false. Reads field or header. */
    function csrf_check(): bool {
        $token = $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? ($_GET['csrf_token'] ?? '');
        return !empty($_SESSION['csrf_token'])
            && is_string($token)
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('requireCsrf')) {
    /**
     * Enforce CSRF on the current request. Only acts on unsafe methods.
     * @param bool $json  When true, emits a JSON error instead of HTML.
     */
    function requireCsrf(bool $json = false): bool {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }
        if (csrf_check()) {
            return true;
        }
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'This page was open too long, so nothing was sent. Please reload the page and try again.']);
        } else {
            // Plain words and a way back: the usual cause is a page left open
            // overnight, and "security token" meant nothing to the people who
            // hit it. Still a 403, still nothing saved.
            echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<title>Please try again</title>'
               . '<div style="font:16px/1.5 system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 16px;color:#2b1a1a">'
               . '<p><strong>This page was open too long, so nothing was sent.</strong></p>'
               . '<p>Go back, reload the page, and try again.</p>'
               . '<p><a href="javascript:history.back()" style="color:#7B1D1D">&larr; Go back</a></p></div>';
        }
        exit();
    }
}
