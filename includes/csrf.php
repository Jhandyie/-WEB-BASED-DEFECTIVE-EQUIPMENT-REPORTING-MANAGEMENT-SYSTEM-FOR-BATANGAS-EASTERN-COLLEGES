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
            echo json_encode(['success' => false, 'message' => 'Security token invalid or expired. Please refresh and try again.']);
        } else {
            echo 'Security token invalid or expired. Please go back, refresh the page, and try again.';
        }
        exit();
    }
}
