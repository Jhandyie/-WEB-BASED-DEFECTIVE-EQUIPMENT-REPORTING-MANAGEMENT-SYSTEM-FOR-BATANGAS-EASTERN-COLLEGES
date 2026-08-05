<?php
/**
 * One-time codes and remembered devices for reporters.
 *
 * The admin OTP path cannot be reused as it stands: requestLoginOTP() and
 * verifyOTP() both look the address up in `users`, and reporters deliberately
 * hold no account — the portal identifies them by their BEC email. Everything
 * underneath is reusable though, so this validates against the BEC directory
 * instead and then hands off to the same generator, store and mailer.
 *
 * Why bother, when the address is already checked against the directory: that
 * check proves the address *exists*, not that the person typing it can open it.
 * BEC addresses follow firstname.lastname, so anyone able to name a student
 * could file reports as them. A code sent to the mailbox is what closes that.
 *
 * And why remember the device afterwards: a code on every report is a tax
 * people come to resent, and a portal nobody uses reports no broken equipment.
 * Verify once, then a month of one-tap reporting.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/otp_helper.php';
require_once __DIR__ . '/bec_directory_helper.php';
require_once __DIR__ . '/rate_limiter.php';

const REPORTER_OTP_ROLE      = 'reporter';
const REPORTER_TRUST_COOKIE  = 'bec_reporter';
const REPORTER_TRUST_DAYS    = 30;

/**
 * The key used to sign remembered devices.
 *
 * Kept in data/, which .htaccess already blocks from HTTP, and generated on
 * first use so a fresh checkout needs no setup step. Losing it is harmless:
 * every remembered device simply has to verify once more.
 */
function reporterSigningKey(): string {
    static $key = null;
    if ($key !== null) { return $key; }
    $path = __DIR__ . '/../data/.reporter_signing_key';
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

/** Is this address someone BEC recognises? Same rule as the sign-in gate. */
function reporterEmailIsKnown(string $email): bool {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { return false; }
    $dirCount = function_exists('becdir_count') ? becdir_count() : 0;
    if ($dirCount > 0) {
        return becdir_email_exists($email)
            || (function_exists('becdir_is_system_user') && becdir_is_system_user($email));
    }
    return str_ends_with($email, '@bec.edu.ph');
}

/**
 * Send a fresh code. Returns ['ok'=>bool, 'message'=>string, 'dev_code'=>?string].
 *
 * The caller is told nothing about whether the address is known — that answer,
 * repeated, is how a roster gets confirmed one guess at a time.
 */
function reporterOtpSend(string $email): array {
    $email = strtolower(trim($email));

    // Per address and per connection: one stops a single mailbox being flooded,
    // the other stops a caller walking a list of addresses.
    try {
        RateLimiter::enforce('reporter_otp_send:' . $email, 5, 900);
        RateLimiter::enforce('reporter_otp_ip:' . RateLimiter::clientIp(), 20, 900);
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => 'Too many code requests. Please wait a few minutes and try again.'];
    }

    if (!reporterEmailIsKnown($email)) {
        // Deliberately the same reply as success. Nothing is sent.
        return ['ok' => true, 'message' => '', 'silent' => true];
    }

    // A code already on its way is better than a second one racing it: with
    // slow mail the reporter often reads the older message first.
    $recent = getRecentOtpSecondsAgo($email, 25);
    if ($recent !== null) {
        return ['ok' => true, 'message' => '', 'throttled' => true];
    }

    $otp = generateOTP();
    if (!storeOTP($email, $otp, REPORTER_OTP_ROLE)) {
        return ['ok' => false, 'message' => 'We could not start the sign-in just now. Please try again.'];
    }
    if (!sendOTPEmail($email, $otp, REPORTER_OTP_ROLE)) {
        error_log("Reporter OTP email failed for {$email}");
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === 'localhost' || str_starts_with($host, '127.0.0.1') || PHP_SAPI === 'cli') {
            // Local development has no working mailbox; the code is shown so the
            // flow can still be walked through. Never reached on the live host.
            return ['ok' => true, 'message' => 'Mail is not configured locally. Your code is ' . $otp, 'dev_code' => $otp];
        }
        return ['ok' => false, 'message' => 'We could not send the code right now. Please try again in a moment.'];
    }
    return ['ok' => true, 'message' => ''];
}

/**
 * Check a code. Returns ['ok'=>bool, 'message'=>string].
 * No account lookup — that is the whole point of this variant.
 */
function reporterOtpVerify(string $email, string $code): array {
    $email = strtolower(trim($email));
    $code  = preg_replace('/\D/', '', (string)$code);
    if ($email === '' || $code === '') {
        return ['ok' => false, 'message' => 'Please enter the 6-digit code from your email.'];
    }
    // Guessing a six-digit code is only hard if guessing is limited.
    try {
        RateLimiter::enforce('reporter_otp_try:' . $email, 6, 900);
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => 'Too many incorrect codes. Please request a new one in a few minutes.'];
    }

    $record = findEmailOtpRecord($email, $code, REPORTER_OTP_ROLE, 'valid');
    if (!$record) {
        if (findEmailOtpRecord($email, $code, REPORTER_OTP_ROLE, 'expired')) {
            return ['ok' => false, 'message' => 'That code has expired. Please request a new one.'];
        }
        if (findEmailOtpRecord($email, $code, REPORTER_OTP_ROLE, 'used')) {
            return ['ok' => false, 'message' => 'That code has already been used. Please request a new one.'];
        }
        return ['ok' => false, 'message' => 'That code is not correct. Please check your email and try again.'];
    }

    markOtpUsedById((int)($record['otp_id'] ?? 0));
    markEmailOtpUsed($email);   // burn the siblings, so none can be replayed
    RateLimiter::clear('reporter_otp_try:' . $email);
    RateLimiter::clear('reporter_otp_send:' . $email);
    return ['ok' => true, 'message' => ''];
}

/** Remember this browser for REPORTER_TRUST_DAYS, so the code is a one-off. */
function reporterTrustDevice(string $email): void {
    $email   = strtolower(trim($email));
    $expires = time() + (REPORTER_TRUST_DAYS * 86400);
    $payload = $email . '|' . $expires;
    $token   = $payload . '|' . hash_hmac('sha256', $payload, reporterSigningKey());
    $secure  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(REPORTER_TRUST_COOKIE, $token, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * The address this browser has already proved it can read, or ''.
 * hash_equals, so a wrong signature cannot be narrowed down by timing.
 */
function reporterTrustedEmail(): string {
    $raw = (string)($_COOKIE[REPORTER_TRUST_COOKIE] ?? '');
    if ($raw === '') { return ''; }
    $parts = explode('|', $raw);
    if (count($parts) !== 3) { return ''; }
    [$email, $expires, $sig] = $parts;
    $expected = hash_hmac('sha256', $email . '|' . $expires, reporterSigningKey());
    if (!hash_equals($expected, $sig)) { return ''; }
    if ((int)$expires < time()) { return ''; }
    // Someone can leave the college between one report and the next.
    if (!reporterEmailIsKnown($email)) { return ''; }
    return strtolower($email);
}

/** Forget this browser (used when the reporter says "not me"). */
function reporterForgetDevice(): void {
    setcookie(REPORTER_TRUST_COOKIE, '', [
        'expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    ]);
    unset($_COOKIE[REPORTER_TRUST_COOKIE]);
}
