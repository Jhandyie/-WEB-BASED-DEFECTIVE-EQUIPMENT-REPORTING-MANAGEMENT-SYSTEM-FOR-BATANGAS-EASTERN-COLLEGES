<?php
/**
 * api/forgot_password.php — retired.
 *
 * This was a legacy, pre-hardening forgot-password endpoint: no CSRF check, no
 * rate limiting, a username-exists oracle (different messages for a known vs.
 * unknown admin), a hardcoded personal fallback email address baked into the
 * source, and a "success" path for database admins that only wrote to the
 * server log and never actually sent mail. It was superseded by the
 * CSRF-protected, rate-limited, non-enumerating forgot_password action in
 * admin/admin_login_process.php, which the live sign-in page
 * (admin/admin_login_otp.html) has called since before this repository's
 * history begins — this file was never linked from it and was only reachable
 * by a stranger who guessed or read the URL out of docs/USER_MANUAL.md.
 *
 * Left in place, rather than deleted, so any stale bookmark or old
 * documentation link gets an explicit answer instead of a 404 or — worse —
 * a silently-working unauthenticated password-reset trigger.
 *
 * Use admin/admin_login_otp.html → "Forgot password?" instead.
 */
header('Content-Type: application/json');
http_response_code(410); // Gone
echo json_encode([
    'success' => false,
    'message' => 'This page has moved. Please use "Forgot password?" on the admin sign-in page.',
]);
exit();
