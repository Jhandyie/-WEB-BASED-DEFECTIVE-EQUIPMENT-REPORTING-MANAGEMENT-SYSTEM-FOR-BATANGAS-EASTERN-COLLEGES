<?php
/**
 * "Keep me signed in on this device" for the admin portal.
 *
 * The admin sign-in asks for a password and then an emailed six-digit code,
 * every single time. On a shared office machine that is right. On the laptop an
 * administrator opens fifteen times a day it is a toll: the code takes as long
 * to arrive as the work takes to do, and the habit it teaches is to leave the
 * dashboard open all day rather than sign out — which is worse for security
 * than the thing the code was protecting.
 *
 * So: verify once, and for the next day this browser is not asked for a code
 * again. What is *not* skipped is the password — every sign-in still verifies
 * it in full, and every rate limit still applies. Only the second factor is
 * remembered, and only on the browser that earned it.
 *
 * This is the same shape as includes/reporter_otp.php's device trust, with a
 * much shorter window (a day, against a reporter's month) because an admin
 * session reaches every report, every user and the database backups.
 *
 * Deliberately NOT the same thing as config/demo_access.php: that names
 * addresses that may always skip the code, from any browser, with nothing
 * proved. This remembers one browser that has already read the mailbox.
 */

const ADMIN_TRUST_COOKIE = 'bec_admin_trust';
const ADMIN_TRUST_HOURS  = 24;

/**
 * The key used to sign remembered devices.
 *
 * Lives in data/, which .htaccess already blocks from HTTP, and is generated on
 * first use so a fresh checkout needs no setup step. Losing it is harmless —
 * every remembered browser simply has to enter one more code.
 */
function adminTrustSigningKey(): string {
    static $key = null;
    if ($key !== null) { return $key; }
    $path = __DIR__ . '/../data/.admin_signing_key';
    if (is_file($path)) {
        $key = trim((string)@file_get_contents($path));
        if ($key !== '') { return $key; }
    }
    $key = bin2hex(random_bytes(32));
    if (!is_dir(dirname($path))) { @mkdir(dirname($path), 0775, true); }
    @file_put_contents($path, $key, LOCK_EX);
    @chmod($path, 0600);
    return $key;
}

/** Remember this browser for ADMIN_TRUST_HOURS, so the next sign-in needs no code. */
function adminTrustDevice(string $email): void {
    $email   = strtolower(trim($email));
    if ($email === '') { return; }
    $expires = time() + (ADMIN_TRUST_HOURS * 3600);
    $payload = $email . '|' . $expires;
    $token   = $payload . '|' . hash_hmac('sha256', $payload, adminTrustSigningKey());
    $secure  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(ADMIN_TRUST_COOKIE, $token, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[ADMIN_TRUST_COOKIE] = $token;
}

/**
 * Has this browser already proved it can read that mailbox, within the window?
 *
 * Bound to the address, so remembering one administrator's device does not let
 * a different account skip its code on the same machine. hash_equals, so a
 * wrong signature cannot be narrowed down by timing.
 */
function adminTrustedFor(string $email): bool {
    $email = strtolower(trim($email));
    if ($email === '') { return false; }
    $raw = (string)($_COOKIE[ADMIN_TRUST_COOKIE] ?? '');
    if ($raw === '') { return false; }
    $parts = explode('|', $raw);
    if (count($parts) !== 3) { return false; }
    [$who, $expires, $sig] = $parts;
    $expected = hash_hmac('sha256', $who . '|' . $expires, adminTrustSigningKey());
    if (!hash_equals($expected, $sig)) { return false; }
    if ((int)$expires < time()) { return false; }
    return hash_equals(strtolower($who), $email);
}

/** Seconds left on this browser's trust, or 0. Used to tell the admin where they stand. */
function adminTrustSecondsLeft(string $email): int {
    if (!adminTrustedFor($email)) { return 0; }
    $parts = explode('|', (string)($_COOKIE[ADMIN_TRUST_COOKIE] ?? ''));
    return max(0, (int)($parts[1] ?? 0) - time());
}

/** Forget this browser — signing out of a machine that is not yours. */
function adminForgetDevice(): void {
    setcookie(ADMIN_TRUST_COOKIE, '', [
        'expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    ]);
    unset($_COOKIE[ADMIN_TRUST_COOKIE]);
}
