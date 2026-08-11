<?php
// student/index.php
require_once __DIR__ . '/includes/session_bootstrap.php';
startPublicSession();
require_once __DIR__ . '/includes/bec_directory_helper.php';
require_once __DIR__ . '/includes/csrf.php';

$error = '';
// Equipment deep-link from a scanned QR code (carried through the BEC gate).
$eq = trim((string)($_GET['eq'] ?? $_POST['eq'] ?? ''));

require_once __DIR__ . '/includes/rate_limiter.php';
require_once __DIR__ . '/includes/reporter_otp.php';

/*
 * Signing in is now three possible states rather than one form.
 *
 * Checking the address against the directory only ever proved the address
 * exists — not that whoever typed it can open it. Since BEC addresses are
 * firstname.lastname, that was enough to file reports as anyone you could
 * name. A code sent to the mailbox is what closes it.
 *
 * It is asked for once. Verifying also remembers the browser for a month, so
 * the common case stays a single tap and the code does not become a toll on
 * every report.
 */
$stage   = 'signin';           // signin | verify | trusted
$notice  = '';
$devCode = '';
$trustedEmail = reporterTrustedEmail();
$trustedName  = $trustedEmail !== '' ? becdir_display_name(becdir_known_name($trustedEmail)) : '';
if ($trustedEmail !== '' && $trustedName === '') { $trustedName = $trustedEmail; }
$trustedFirst = $trustedName !== '' ? becdir_first_name($trustedName) : '';

// Seconds left on the code being verified, read from the record itself so the
// page can count down instead of repeating a fixed "3 minutes".
$otpSecondsLeft = 0;
$otpAskedAt     = (int)($_SESSION['otp_sent_at'] ?? 0);

$signIn = static function (string $email, string $typedName, string $eq): void {
    // The typed name is not evidence of anything. Where BEC holds a name on
    // file that is the one used, so the field cannot be used to claim to be
    // someone else; it survives only for people with no name on record.
    $onFile = trim(becdir_known_name($email));
    $_SESSION['guest_name']        = htmlspecialchars($onFile !== '' ? $onFile : $typedName, ENT_QUOTES, 'UTF-8');
    $_SESSION['guest_email']       = $email;
    $_SESSION['guest_name_source'] = $onFile !== '' ? 'directory' : 'self-declared';
    $_SESSION['guest_since']       = time();
    $_SESSION['guest_last']        = time();
    unset($_SESSION['otp_email'], $_SESSION['otp_name'], $_SESSION['otp_eq'], $_SESSION['otp_sent_at']);
    // An id issued before sign-in must not survive it.
    session_regenerate_id(true);
    header('Location: student_dashboard.php' . ($eq !== '' ? '?eq=' . urlencode($eq) : ''));
    exit();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Without this, another site could sign a visitor into the portal under an
    // address of its choosing. Shown as a form error, not a bare 403.
    $step = (string)($_POST['step'] ?? 'send');
    if (!csrf_check()) {
        $error = 'Your session expired. Please check your details and sign in again.';
        $stage = ($step === 'verify') ? 'verify' : 'signin';
    } elseif ($step === 'forget') {
        // The name is deliberately kept: someone correcting a typo in their
        // address should not have to type their name again as well.
        reporterForgetDevice();
        unset($_SESSION['otp_email'], $_SESSION['otp_eq'], $_SESSION['otp_sent_at']);
        header('Location: student_index.php' . ($eq !== '' ? '?eq=' . urlencode($eq) : ''));
        exit();
    } elseif ($step === 'trusted') {
        // This browser has already proved it can read that mailbox.
        if ($trustedEmail !== '') { $signIn($trustedEmail, $trustedName, $eq); }
        $error = 'That sign-in has expired. Please enter your BEC email to continue.';
    } elseif ($step === 'verify') {
        $stage   = 'verify';
        $pending = strtolower(trim((string)($_SESSION['otp_email'] ?? '')));
        if ($pending === '') {
            $stage = 'signin';
            $error = 'That sign-in timed out. Please start again.';
        } elseif (!empty($_POST['resend'])) {
            $res = reporterOtpSend($pending);
            if (!empty($res['dev_code'])) { $devCode = $res['dev_code']; }
            if (!$res['ok']) {
                $error = $res['message'];
            } elseif (!empty($res['throttled'])) {
                // Nothing was sent. Saying otherwise sends the reporter back to
                // an inbox that will not receive anything.
                $notice = 'Your code was sent moments ago — please check your inbox, including spam. '
                        . 'You can ask for another in ' . (int)$res['retry_in'] . ' seconds.';
            } else {
                $notice = 'A new code is on its way to ' . $pending . '. The previous one no longer works.';
            }
        } else {
            $res = reporterOtpVerify($pending, (string)($_POST['otp_code'] ?? ''));
            if ($res['ok']) {
                reporterTrustDevice($pending);                     // a month of one-tap
                $signIn($pending, (string)($_SESSION['otp_name'] ?? ''), (string)($_SESSION['otp_eq'] ?? $eq));
            }
            $error = $res['message'];
        }
    } else {
        $name  = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));

        // Nothing limited how often this form could be submitted, and its two
        // failure messages told apart "not in the directory" from "not a BEC
        // address" — together, a way to confirm which addresses are real people.
        try {
            RateLimiter::enforce('reporter_signin:' . RateLimiter::clientIp(), 12, 900);
        } catch (\Throwable $e) {
            $error = 'Too many sign-in attempts from this connection. Please wait a few minutes and try again.';
        }

        if ($error !== '') {
            // Already rejected — fall through and redisplay.
        } elseif ($name === '' || $email === '') {
            $error = 'Please enter both your name and email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($name) < 2) {
            $error = 'Please enter your full name.';
        } elseif (empty($_POST['privacy_consent'])) {
            $error = 'Please read and accept the Data Privacy notice to continue.';
        } else {
            $res = reporterOtpSend($email);
            if (!$res['ok']) {
                $error = $res['message'];
            } else {
                // Held in the session, not a hidden field, so the address the
                // code was sent to is the only one that can be verified.
                $_SESSION['otp_email']   = $email;
                $_SESSION['otp_name']    = $name;
                $_SESSION['otp_eq']      = $eq;
                $_SESSION['otp_sent_at'] = time();
                $stage   = 'verify';
                $devCode = (string)($res['dev_code'] ?? '');
                // Said the same way whether or not the address is known: a
                // different answer here is what makes a roster guessable.
                $notice  = 'If ' . $email . ' belongs to Batangas Eastern Colleges, a 6-digit code is on its way to it.';
            }
        }
    }
} elseif (!empty($_SESSION['otp_email']) && (time() - (int)($_SESSION['otp_sent_at'] ?? 0)) < 900) {
    $stage = 'verify';   // came back to the tab mid-verification
}

if ($stage === 'verify' && !empty($_SESSION['otp_email'])) {
    $exp = reporterOtpExpiresAt((string)$_SESSION['otp_email']);
    $otpSecondsLeft = $exp === null ? 0 : max(0, $exp - time());
    $otpAskedAt     = (int)($_SESSION['otp_sent_at'] ?? 0);
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Batangas Eastern Colleges · PMO Equipment Reporting Portal</title>
<link rel="icon" type="image/png" href="assets/logs.png">
<link rel="shortcut icon" href="assets/logs.png">
<link rel="apple-touch-icon" href="assets/logs.png">
<!-- Served from this server, not a CDN, so the reporter portal keeps its icons
     and typefaces when the campus connection is unavailable. -->
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="css/typography.css">
<style>
/* Root size bump — MUST stay in <head>. Setting this from an end-of-body
   include repaints, then re-lays-out, the whole page on every load. */
html{font-size:106.25%;scrollbar-gutter:stable;}

:root {
  /* Shared six-step scales — same steps as the admin pages, so a size or a
     gap means the same thing across the system. Nothing between them. */
  --fs-xs:.6rem;--fs-sm:.68rem;--fs-base:.76rem;--fs-md:.82rem;--fs-lg:.88rem;--fs-xl:.95rem;--fs-2xl:1.05rem;
  --sp-0:.125rem;--sp-1:.25rem;--sp-2:.5rem;--sp-3:.75rem;--sp-4:1rem;--sp-5:1.5rem;

  --maroon: #7B1D1D;
  --maroon-d: #4A0E0E;
  --maroon-dd: #2D0505;
  --maroon-soft: rgba(123,29,29,.08);
  --gold: #C9960C;
  --gold-bg: #FFFBEF;
  --ink: #1C1008;
  --ink2: #5C3838;
  --ink3: #755B4E;
  --paper: #F8F3EA;
  --surface: #FFFFFF;
  --border: #E8DDD0;
  --shadow: 0 2px 8px rgba(44,10,10,.06), 0 12px 40px rgba(44,10,10,.10);
}
*, *::before, *::after { margin:0; padding:0; box-sizing: border-box; }
body {
  font-family: 'DM Sans', sans-serif;
  background: var(--paper);
  min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding:var(--sp-5); position: relative; overflow-x: hidden;
}
html { -webkit-text-size-adjust: 100%; }

/* One focus ring for the whole page. Three controls further down defined their
   own, but everything else — every input, the submit button, the links in the
   brand panel — fell back to the browser default, which all but vanishes on
   this cream paper and inside the maroon panel. :focus-visible keeps it off
   mouse clicks; the narrower rules below still win where they apply. */
:focus-visible { outline: 3px solid var(--maroon); outline-offset: 3px; }
.brand :focus-visible { outline-color: #F0C040; }

/* Skip link — the brand panel puts a screenful of copy before the form on a
   narrow window, which is a long tab journey to reach the one thing this page
   is for. Off-screen until focused. */
.skip-link { position: absolute; left: .75rem; top: -3rem; z-index: 500;
  padding:var(--sp-3) var(--sp-4); border-radius: 0 0 10px 10px; background: var(--maroon);
  color: #fff; text-decoration: none; font-size:var(--fs-md); font-weight: 700;
  transition: top .18s ease; }
.skip-link:focus { top: 0; }
@media (prefers-reduced-motion: reduce) { .skip-link { transition: none; } }

body::before {
  content: ''; position: fixed; top: -180px; right: -180px;
  width: 520px; height: 520px; border-radius: 50%;
  background: radial-gradient(circle, rgba(201,150,12,.12) 0%, transparent 65%);
  pointer-events: none; z-index: 0;
}
body::after {
  content: ''; position: fixed; bottom: -140px; left: -140px;
  width: 420px; height: 420px; border-radius: 50%;
  background: radial-gradient(circle, rgba(123,29,29,.09) 0%, transparent 65%);
  pointer-events: none; z-index: 0;
}
.bg-grid {
  position: fixed; inset: 0; z-index: 0; pointer-events: none;
  background-image: radial-gradient(circle, rgba(123,29,29,.12) 1px, transparent 1px);
  background-size: 32px 32px;
  mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 0%, transparent 100%);
  -webkit-mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 0%, transparent 100%);
}
/* ══ LAYOUT: split brand + form ══ */
.shell {
  display: grid; grid-template-columns: 1.04fr .96fr;
  width: 100%; max-width: 940px;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 26px; box-shadow: var(--shadow);
  position: relative; z-index: 1; overflow: hidden;
  animation: riseIn .6s cubic-bezier(.22,1,.36,1) both;
}
@keyframes riseIn {
  from { opacity: 0; transform: translateY(28px) scale(.985); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}

/* LEFT — brand panel */
.brand {
  position: relative; overflow: hidden; color: #fff;
  padding:2.75rem 2.4rem 2.4rem;
  background:
    radial-gradient(120% 80% at 0% 0%, rgba(201,150,12,.20) 0%, transparent 42%),
    linear-gradient(155deg, rgba(45,5,5,.90) 0%, rgba(74,14,14,.80) 55%, rgba(123,29,29,.72) 100%),
    url('assets/Landing Page Background.jpg') center / cover no-repeat,
    url('assets/bec-background.jpg') center / cover no-repeat;
  /* The hero sat against the top of a tall panel, leaving a half-screen of empty
     photograph beneath it next to a form that was scrolling. Centring it puts
     the weight of the two columns on the same line. */
  display: flex; flex-direction: column; justify-content: space-between;
}
.brand::after {
  content: ''; position: absolute; inset: 0; pointer-events: none;
  background-image: radial-gradient(circle, rgba(255,255,255,.06) 1px, transparent 1px);
  background-size: 22px 22px;
  -webkit-mask-image: radial-gradient(ellipse 90% 70% at 28% 18%, #000 0%, transparent 75%);
  mask-image: radial-gradient(ellipse 90% 70% at 28% 18%, #000 0%, transparent 75%);
}
.brand-top { position: relative; z-index: 1; display: flex; align-items: center; gap:var(--sp-3); }
.brand-home { margin-left:auto; display: inline-flex; align-items: center; gap:var(--sp-2); flex-shrink: 0;
  padding:var(--sp-2) var(--sp-4); min-height: 42px; border-radius: 11px; white-space: nowrap;
  background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.26); color: #fff;
  font-size:var(--fs-md); font-weight: 600; text-decoration: none;
  -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px);
  transition: background .16s, transform .16s, border-color .16s; }
.brand-home:hover { background: rgba(255,255,255,.22); border-color: rgba(255,255,255,.45); transform:none; }
.brand-home i { color: #F0C040; font-size:var(--fs-md); }
.brand-seal {
  width: 46px; height: 46px; border-radius: 50%; flex-shrink: 0; background: #fff;
  display: flex; align-items: center; justify-content: center; overflow: hidden;
  box-shadow: 0 0 0 4px rgba(201,150,12,.3);
}
.brand-seal img { width: 100%; height: 100%; object-fit: cover; display: block; }
.brand-top strong { display: block; font-size:var(--fs-xl); font-weight: 700; letter-spacing: -.01em; }
.brand-top span { font-size:var(--fs-xs); text-transform: uppercase; letter-spacing: 1.8px; color: rgba(255,255,255,.6); }
.brand-hero { position: relative; z-index: 1; margin-top:2.4rem; }
.brand-tag {
  display: inline-flex; align-items: center; gap:var(--sp-2); margin-bottom:var(--sp-4);
  padding:var(--sp-1) var(--sp-3); border-radius: 20px; font-size:var(--fs-xs); font-weight: 600;
  letter-spacing: .5px; text-transform: uppercase;
  background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.16); color: rgba(255,255,255,.85);
}
.brand-hero h2 {
  font-family: 'Fraunces', serif; font-weight: 700; font-size: 1.9rem; line-height: 1.16;
  letter-spacing: -.02em; margin-bottom:var(--sp-3);
}
.brand-hero h2 em { font-style: italic; color: var(--gold); }
.brand-hero p { font-size:var(--fs-md); line-height: 1.65; color: rgba(255,255,255,.72); max-width: 34ch; }
.brand-feats { position: relative; z-index: 1; margin-top:auto; padding-top:2rem; display: flex; flex-direction: column; gap:var(--sp-3); }
.brand-feat { display: flex; align-items: center; gap:var(--sp-3); font-size:var(--fs-md); color: rgba(255,255,255,.9); }
.brand-feat .bf-ic {
  width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center; font-size:var(--fs-base);
  background: rgba(201,150,12,.18); border: 1px solid rgba(201,150,12,.32); color: var(--gold);
}
.brand-foot {
  position: relative; z-index: 1; margin-top:1.9rem; padding-top:var(--sp-4);
  border-top: 1px solid rgba(255,255,255,.12);
  font-size:var(--fs-sm); color: rgba(255,255,255,.5); line-height: 1.6;
}

/* brand process timeline — communicates the institutional workflow */
.brand-process { position: relative; z-index: 1; margin-top:auto; padding-top:2.1rem; }
.bp-label { display: flex; align-items: center; gap:var(--sp-2); margin-bottom:var(--sp-4); font-size:var(--fs-xs); font-weight: 700; text-transform: uppercase; letter-spacing: 1.6px; color: rgba(255,255,255,.52); }
.bp-label i { color: var(--gold); font-size:var(--fs-sm); }
.brand-steps { position: relative; display: flex; flex-direction: column; gap:var(--sp-4); }
.brand-steps::before { content: ''; position: absolute; left: 12.5px; top: 8px; bottom: 8px; width: 1.5px; background: linear-gradient(rgba(201,150,12,.55), rgba(255,255,255,.05)); }
.bs-item { position: relative; display: flex; gap:var(--sp-3); align-items: flex-start; }
.bs-num { width: 26px; height: 26px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-family: 'Fraunces', serif; font-size:var(--fs-base); font-weight: 700; color: var(--gold); background: var(--maroon-dd); border: 1.5px solid rgba(201,150,12,.5); position: relative; z-index: 1; }
.bs-item b { display: block; font-size:var(--fs-md); font-weight: 600; color: #fff; letter-spacing: -.01em; }
.bs-item i { display: block; font-style: normal; font-size:var(--fs-sm); color: rgba(255,255,255,.6); line-height: 1.5; margin-top:var(--sp-0); }

/* form eyebrow + trust strip */
.panel-eyebrow { display: inline-flex; align-items: center; gap:var(--sp-2); margin-bottom:var(--sp-3); font-size:var(--fs-xs); font-weight: 700; text-transform: uppercase; letter-spacing: 1.6px; color: var(--maroon); }
.panel-eyebrow .pe-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--gold); box-shadow: 0 0 0 3px var(--maroon-soft); }
.safety-note { display: flex; align-items: flex-start; gap:var(--sp-2); background: #FFF6F5; border: 1px solid #F3D2CD; border-left: 3px solid #C0392B; border-radius: 10px; padding:var(--sp-3) var(--sp-4); margin-bottom:var(--sp-4); font-size:var(--fs-base); color: var(--ink2); line-height: 1.55; }
.safety-note i { color: #C0392B; font-size:var(--fs-md); margin-top:var(--sp-0); flex-shrink: 0; }
.safety-note strong { color: #96271B; }
.trust-strip { display: flex; flex-wrap: wrap; justify-content: center; gap:var(--sp-2) var(--sp-4); margin-bottom:var(--sp-4); }
.trust-strip span { display: inline-flex; align-items: center; gap:var(--sp-1); font-size:var(--fs-sm); color: var(--ink3); font-weight: 500; }
.trust-strip i { color: var(--gold); font-size:var(--fs-xs); }

/* RIGHT — form panel */
.panel { padding:2.7rem 2.6rem 2.2rem; display: flex; flex-direction: column; }
.panel-title {
  font-family: 'Fraunces', serif; font-size: 1.55rem; font-weight: 700; color: var(--ink);
  line-height: 1.18; letter-spacing: -.02em; margin-bottom:var(--sp-2);
}
.panel-title em { font-style: italic; color: var(--maroon); }
.panel-sub { font-size:var(--fs-lg); color: var(--ink3); line-height: 1.6; margin-bottom:var(--sp-5); overflow-wrap: anywhere; }
.pills { display: flex; flex-wrap: wrap; gap:var(--sp-2); margin-bottom:var(--sp-5); }
.pill {
  display: inline-flex; align-items: center; gap:var(--sp-1);
  padding:var(--sp-1) var(--sp-2); background: var(--maroon-soft);
  border: 1px solid rgba(123,29,29,.12); border-radius: 20px;
  font-size:var(--fs-sm); color: var(--maroon); font-weight: 500;
}
.pill i { font-size:var(--fs-xs); }
.notice {
  display: flex; align-items: flex-start; gap:var(--sp-2);
  background: var(--gold-bg); border: 1px solid rgba(201,150,12,.25);
  border-left: 3px solid var(--gold); border-radius: 10px;
  padding:var(--sp-3) var(--sp-4); margin-bottom:1.6rem;
  font-size:var(--fs-base); color: var(--ink2); line-height: 1.55;
}
.notice i { color: var(--gold); font-size:var(--fs-base); margin-top:var(--sp-0); flex-shrink: 0; }
.fg { margin-bottom:var(--sp-4); }
/* Same size and tracking as the report form's .fl — "Full Name" must not
   change size between the two steps of one sign-in. */
.fl {
  display: block; font-size:var(--fs-base); font-weight: 600;
  color: var(--ink2); margin-bottom:var(--sp-2); text-transform: uppercase; letter-spacing: .8px;
}
.fl .req { color: var(--maroon); margin-left:var(--sp-0); }
.fi-wrap { position: relative; }
.fi-icon {
  position: absolute; left: .9rem; top: 50%; transform: translateY(-50%);
  color: var(--ink3); font-size:var(--fs-base); pointer-events: none; transition: color .18s;
}
.fi-wrap:focus-within .fi-icon { color: var(--maroon); }
.fi {
  width: 100%; padding:var(--sp-3) var(--sp-4) var(--sp-3) 2.5rem;
  border: 1.5px solid var(--border); border-radius: 11px;
  font-family: 'DM Sans', sans-serif; font-size:var(--fs-xl); color: var(--ink);
  background: #fff; transition: border-color .18s, box-shadow .18s;
  outline: none; -webkit-appearance: none;
}
.fi:focus { border-color: var(--maroon); box-shadow: 0 0 0 3.5px rgba(123,29,29,.09); }
.fi::placeholder { color: #C4AFA8; font-size:var(--fs-xl); }
.fi-hint { font-size:var(--fs-sm); color: var(--ink3); margin-top:var(--sp-1); display: block; line-height: 1.55; }
.fi-hint i { margin-right:var(--sp-1); }
.fi-hint strong { color: var(--ink2); white-space: nowrap; }
/* Friendly "about this portal" details (replaces the old button-like pills) */
.intro-card { background: #FBF8F1; border: 1px solid var(--border); border-radius: 14px; padding:var(--sp-3) var(--sp-4); margin-bottom:var(--sp-5); }
.intro-card[open] { padding-bottom:var(--sp-4); }
.intro-card > summary {
  font-family: 'Fraunces', serif; font-size:var(--fs-xl); font-weight: 600; color: var(--ink);
  display: flex; align-items: center; gap:var(--sp-2); cursor: pointer; list-style: none;
}
.intro-card > summary::-webkit-details-marker { display: none; }
.intro-card > summary > i:first-child { color: var(--gold); font-size:var(--fs-md); }
.intro-chev { margin-left:auto; font-size:var(--fs-sm); color: var(--ink3); transition: transform .2s ease; }
.intro-card[open] .intro-chev { transform: rotate(180deg); }
.intro-card > summary:focus-visible { outline: 2px solid var(--maroon); outline-offset: 3px; border-radius: 6px; }
@media (prefers-reduced-motion: reduce) { .intro-chev { transition: none; } }
.intro-list { display: flex; flex-direction: column; gap:var(--sp-2); margin-top:var(--sp-3); }
.intro-list li { list-style: none; display: flex; align-items: flex-start; gap:var(--sp-2); font-size:var(--fs-base); color: var(--ink2); line-height: 1.55; }
.intro-list > li > i { color: var(--maroon); font-size:var(--fs-md); margin-top:var(--sp-1); flex-shrink: 0; width: 1.05rem; text-align: center; }
.intro-list b { color: var(--ink); font-weight: 600; }
.alert {
  padding:var(--sp-3) var(--sp-4); border-radius: 10px; font-size:var(--fs-base); line-height: 1.5;
  margin-bottom:var(--sp-4); display: flex; align-items: flex-start; gap:var(--sp-2);
}
.alert-err { background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B; }
.alert-note { background: #FFFBEF; border: 1px solid rgba(201,150,12,.35); color: #7A5A00; }
.alert-ok { background: #F1F9F3; border: 1px solid #C6E6CF; color: #1A6A33; }
/* ── one-time code ─────────────────────────────────────────────────────
   Deliberately the same maroon-and-gold vocabulary as the sign-in step: the
   reporter has not left the college's portal, and a screen that looks like a
   different site is exactly where people abandon a sign-in. */
.otp-steps { display:flex; align-items:center; gap:var(--sp-2); margin-bottom:var(--sp-5);
  font-size:var(--fs-sm); font-weight:700; letter-spacing:.03em; text-transform:uppercase; }
.otp-step { display:inline-flex; align-items:center; gap:var(--sp-1); white-space:nowrap; }
.otp-step.done { color:#1A7A33; }
.otp-step.done i { font-size:var(--fs-xs); }
.otp-step.now { color:var(--maroon); }
.otp-step-line { flex:1; height:2px; border-radius:2px;
  background:linear-gradient(90deg,#1A7A33,var(--maroon)); opacity:.35; }
.otp-head { text-align:center; margin-bottom:var(--sp-5); }
.otp-head .otp-ic { width:58px; height:58px; border-radius:50%; margin:0 auto var(--sp-3);
  background:var(--gold-bg); border:1px solid rgba(201,150,12,.35); color:var(--gold);
  display:flex; align-items:center; justify-content:center; font-size:1.35rem; }
.otp-head h2 { font-family:'Fraunces',serif; font-size:1.45rem; font-weight:700;
  color:var(--ink); letter-spacing:-.015em; margin-bottom:var(--sp-2); }
.otp-head p { font-size:var(--fs-md); color:var(--ink3); line-height:1.65; max-width:34ch; margin:0 auto; }
.otp-to { display:inline-flex; align-items:center; gap:var(--sp-2); margin-top:var(--sp-4);
  padding:var(--sp-2) var(--sp-3); border-radius:20px; background:var(--maroon-soft);
  border:1px solid rgba(123,29,29,.14); font-size:var(--fs-base); font-weight:600;
  color:var(--maroon); overflow-wrap:anywhere; }
.otp-to i { font-size:var(--fs-sm); opacity:.8; }
.otp-input { width:100%; padding:var(--sp-4) var(--sp-4); border:1.5px solid var(--border); border-radius:12px;
  font-family:'Courier New',Courier,monospace; font-size:1.85rem; font-weight:700;
  letter-spacing:.5em; text-indent:.5em; text-align:center; color:var(--maroon);
  background:#fff; outline:none; transition:border-color .18s, box-shadow .18s; }
.otp-input::placeholder { color:#D9C6C0; letter-spacing:.5em; }
.otp-input:focus { border-color:var(--maroon); box-shadow:0 0 0 3.5px rgba(123,29,29,.09); }
.otp-input.is-bad { border-color:#C0392B; box-shadow:0 0 0 3.5px rgba(192,57,43,.10); }
.otp-actions { display:grid; grid-template-columns:1fr 1fr; gap:var(--sp-2); margin-top:var(--sp-4); }
.otp-actions form { margin:0; }
.otp-btn { width:100%; min-height:44px; display:inline-flex; align-items:center;
  justify-content:center; gap:var(--sp-2); padding:var(--sp-2) var(--sp-3); border-radius:11px;
  border:1.5px solid var(--border); background:#fff; cursor:pointer;
  font-family:'DM Sans',sans-serif; font-size:var(--fs-base); font-weight:600; color:var(--ink2);
  transition:border-color .16s, color .16s, background .16s; }
.otp-btn i { font-size:var(--fs-sm); color:var(--maroon); opacity:.8; }
.otp-btn:hover:not(:disabled) { border-color:var(--maroon); color:var(--maroon); background:var(--maroon-soft); }
.otp-btn:disabled { opacity:.5; cursor:not-allowed; }
.otp-foot { margin-top:var(--sp-4); padding-top:var(--sp-4); border-top:1px solid var(--border);
  font-size:var(--fs-sm); color:var(--ink3); line-height:1.6; text-align:center; }
.otp-foot i { color:var(--gold); margin-right:var(--sp-1); }
/* "Not you?" under the remembered-device card. Without this it rendered as a
   bare browser button — system font, grey chrome — under a styled maroon one. */
.otp-link { display:block; margin:var(--sp-3) auto 0; padding:var(--sp-2) var(--sp-2);
  background:none; border:none; cursor:pointer;
  font-family:'DM Sans',sans-serif; font-size:var(--fs-base); font-weight:600;
  color:var(--ink3); text-decoration:underline; text-underline-offset:2px;
  transition:color .16s; }
.otp-link:hover { color:var(--maroon); }
.otp-link:focus-visible { outline:2px solid var(--gold); outline-offset:2px; border-radius:6px; }
@media (max-width:400px){
  .otp-input { font-size:1.5rem; letter-spacing:.35em; text-indent:.35em; }
  .otp-actions { grid-template-columns:1fr; }
  .otp-steps { font-size:var(--fs-xs); }
}
/* ── remembered device ── */
.trust { display:flex;align-items:center;gap:var(--sp-3);padding:var(--sp-4) var(--sp-4);margin-bottom:var(--sp-5);
  background:#F4FAF5;border:1px solid #CFE6D4;border-left:3px solid #1A7A33;border-radius:14px; }
.trust-av { width:42px;height:42px;border-radius:50%;flex-shrink:0;background:var(--maroon-d);color:#fff;
  display:flex;align-items:center;justify-content:center;font-weight:700;font-size:var(--fs-xl); }
.trust-txt { flex:1;min-width:0;line-height:1.5; }
.trust-txt b { display:block;font-size:var(--fs-xl);color:var(--ink); }
.trust-txt span { display:block;font-size:var(--fs-base);color:var(--ink3);overflow-wrap:anywhere; }
.trust-go { width:100%;margin-top:var(--sp-1); }
@media (max-width:520px){ .trust { flex-wrap:wrap; } }
.alert i { font-size:var(--fs-md); margin-top:var(--sp-0); flex-shrink: 0; }

/* ── Data Privacy Notice: short consent line + expandable full text ──
   The full notice used to sit open above the submit button, pushing it off
   screen on a phone. The summary carries the consent; the panel holds the
   detail for anyone who wants it. */
.pv-block {
  margin:var(--sp-4) 0 var(--sp-4); border-radius: 12px;
  background: #FBF8F1; border: 1px solid var(--border); overflow: hidden;
}
.pv-consent {
  display: flex; align-items: flex-start; gap:var(--sp-2);
  padding:var(--sp-3) var(--sp-4) var(--sp-2);
  font-size:var(--fs-base); color: var(--ink2); line-height: 1.6; cursor: pointer;
}
.pv-consent input {
  width: 16px; height: 16px; flex-shrink: 0; margin-top:var(--sp-0); accent-color: #7B1D1D;
}
.pv-consent strong { color: var(--ink); }
.pv-toggle {
  display: flex; align-items: center; gap:var(--sp-2); width: 100%;
  padding:var(--sp-2) var(--sp-4) var(--sp-3); background: none; border: none;
  font-family: 'DM Sans', sans-serif; font-size:var(--fs-sm); font-weight: 700;
  letter-spacing: .04em; text-transform: uppercase;
  color: var(--maroon); cursor: pointer; text-align: left;
}
.pv-toggle .chev { margin-left:auto; font-size:var(--fs-sm); transition: transform .28s ease; }
.pv-toggle[aria-expanded="true"] .chev { transform: rotate(180deg); }
.pv-toggle:hover { color: var(--maroon-d); }
.pv-toggle:focus-visible { outline: 2px solid var(--gold); outline-offset: -2px; border-radius: 8px; }
.pv-panel {
  max-height: 0; opacity: 0; overflow: hidden;
  transition: max-height .34s ease, opacity .26s ease;
}
.pv-panel.open { max-height: 620px; opacity: 1; }
.pv-inner {
  padding:0 var(--sp-4) var(--sp-3); font-size:var(--fs-sm); color: var(--ink2); line-height: 1.65;
  border-top: 1px solid var(--border); padding-top:var(--sp-3); margin:0 0 0;
}
.pv-inner dt {
  font-size:var(--fs-xs); font-weight: 800; letter-spacing: .08em; text-transform: uppercase;
  color: var(--maroon); margin-top:var(--sp-3);
}
.pv-inner dt:first-child { margin-top:0; }
.pv-inner dd { margin:var(--sp-1) 0 0; }
.pv-inner a { color: var(--maroon); font-weight: 600; }
@media (prefers-reduced-motion: reduce) {
  .pv-panel { transition: none; }
  .pv-toggle .chev { transition: none; }
}
.btn-submit {
  width: 100%; margin-top:var(--sp-5); padding:var(--sp-4) var(--sp-5);
  background: var(--maroon-d); color: #fff; border: none; border-radius: 11px;
  font-family: 'DM Sans', sans-serif; font-size:var(--fs-xl); font-weight: 600; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap:var(--sp-2);
  transition: all .22s cubic-bezier(.22,1,.36,1);
  box-shadow:0 8px 20px rgba(74,14,14,.25);
  letter-spacing: -.01em; -webkit-appearance: none;
}
.btn-submit:hover { background: var(--maroon); transform:none; box-shadow:0 14px 28px rgba(74,14,14,.3); }
.btn-submit:active { transform:none; box-shadow:0 4px 10px rgba(74,14,14,.2); }
.btn-arrow {
  width: 20px; height: 20px; background: rgba(255,255,255,.18); border-radius: 50%;
  display: flex; align-items: center; justify-content: center; font-size:var(--fs-sm); transition: transform .2s;
}
.btn-submit:hover .btn-arrow { transform:none; }
.or-row {
  display: flex; align-items: center; gap:var(--sp-3); margin:var(--sp-5) 0;
  font-size:var(--fs-sm); color: var(--ink3); text-transform: uppercase; letter-spacing: 1.5px;
}
.or-row::before, .or-row::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.action-row { display: grid; grid-template-columns: 1fr 1fr; gap:var(--sp-3); margin-bottom:var(--sp-5); }
.action-btn {
  display: flex; flex-direction: column; align-items: center; gap:var(--sp-1);
  padding:var(--sp-3) var(--sp-2); border: 1.5px solid var(--border); border-radius: 11px;
  color: var(--ink2); font-size:var(--fs-base); font-weight: 500;
  text-decoration: none; transition: all .18s; background: #fff; text-align: center; line-height: 1.3;
}
.action-btn i { font-size:var(--fs-xl); color: var(--maroon); opacity: .75; transition: opacity .18s, transform .18s; }
.action-btn:hover { border-color: var(--maroon); color: var(--maroon); background: var(--maroon-soft); transform:none; box-shadow: 0 4px 12px rgba(123,29,29,.08); }
.action-btn:hover i { opacity: 1; transform:none; }
.footer-link { text-align: center; font-size:var(--fs-base); color: var(--ink3); padding-top:var(--sp-2); border-top: 1px solid var(--border); }
.footer-link a { color: var(--maroon); font-weight: 600; text-decoration: none; margin-left:var(--sp-1); cursor: pointer; }
.footer-link a:hover { text-decoration: underline; }

/* ══ FLOATING CHAT LAUNCHER (left side) ══ */
#chatFab {
  position: fixed; left: 1.35rem; bottom: 1.35rem; z-index: 9997;
  display: flex; align-items: center; justify-content: center;
  width: 62px; height: 62px; padding:0;
  background: linear-gradient(135deg, rgba(74,14,14,.9), rgba(45,5,5,.9));
  -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
  border: 2px solid rgba(201,150,12,.6);
  border-radius: 50%; cursor: pointer;
  box-shadow: 0 10px 30px rgba(44,10,10,.4), 0 0 20px rgba(201,150,12,.25);
  transition: transform .25s cubic-bezier(.22,1,.36,1), box-shadow .3s ease;
  animation: fabFloat 6s ease-in-out infinite, fabGlow 4.5s ease-in-out infinite;
}
#chatFab:hover { animation: none; transform:none; box-shadow: 0 16px 40px rgba(44,10,10,.5), 0 0 32px rgba(201,150,12,.5); }
#chatFab:active { transform: translateY(-1px) scale(1); }
#chatFab .fab-ic {
  width: 46px; height: 46px; border-radius: 50%; flex-shrink: 0; overflow: hidden;
  background: rgba(255,255,255,.14); border: 1.5px solid rgba(255,255,255,.25);
  display: flex; align-items: center; justify-content: center; position: relative;
  box-shadow: 0 0 14px rgba(201,150,12,.35);
}
#chatFab .fab-ic img { width: 100%; height: 100%; object-fit: cover; display: block; }
#chatFab .fab-ic i { color: #fff; font-size:var(--fs-2xl); }
#chatFab .fab-ic::after {
  content: ''; position: absolute; top: 1px; right: 1px;
  width: 10px; height: 10px; border-radius: 50%;
  background: #F0C040; border: 2px solid rgba(45,5,5,.9);
  box-shadow: 0 0 0 0 rgba(240,192,64,.7); animation: fabPulse 2.2s infinite;
}
#chatFab .fab-txt { display: none; }
@keyframes fabPulse { 0% { box-shadow: 0 0 0 0 rgba(240,192,64,.6); } 70% { box-shadow: 0 0 0 8px rgba(240,192,64,0); } 100% { box-shadow: 0 0 0 0 rgba(240,192,64,0); } }
@keyframes fabFloat { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
@keyframes fabGlow { 0%,100% { box-shadow: 0 10px 30px rgba(44,10,10,.4), 0 0 16px rgba(201,150,12,.2); } 50% { box-shadow: 0 12px 34px rgba(44,10,10,.46), 0 0 30px rgba(201,150,12,.45); } }
@media (prefers-reduced-motion: reduce) { #chatFab, #chatFab .fab-ic::after { animation: none; } }
@media (max-width: 560px) {
  #chatFab { width: 56px; height: 56px; left: 1rem;
    bottom: calc(1rem + env(safe-area-inset-bottom, 0px)); }
  #chatFab .fab-ic { width: 42px; height: 42px; }
}

/* ══ CHATBOT MODAL ══ */
#chatOverlay {
  position: fixed; inset: 0;
  background: rgba(20,5,5,.52);
  z-index: 9998;
  display: flex; align-items: flex-end; justify-content: flex-start;
  padding:var(--sp-5);
  opacity: 0; pointer-events: none;
  transition: opacity .22s ease;
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
}
#chatOverlay.open { opacity: 1; pointer-events: all; }
#chatModal {
  width: 100%; max-width: 385px;
  height: 570px; max-height: calc(100vh - 2.5rem); max-height: calc(100dvh - 2.5rem);
  background: var(--surface); border-radius: 20px;
  border: 1px solid var(--border);
  box-shadow: 0 10px 50px rgba(44,10,10,.25), 0 2px 10px rgba(44,10,10,.1);
  display: flex; flex-direction: column; overflow: hidden;
  transform: translateY(22px) scale(.97); opacity: 0;
  transition: transform .28s cubic-bezier(.22,1,.36,1), opacity .22s ease;
  position: relative; z-index: 9999;
}
#chatOverlay.open #chatModal { transform: translateY(0) scale(1); opacity: 1; }
#chatModal::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: linear-gradient(90deg, var(--maroon-dd), var(--maroon), var(--gold)); z-index: 2;
}
.ch {
  background: var(--maroon-d); padding:var(--sp-4) var(--sp-4) var(--sp-4);
  display: flex; align-items: center; gap:var(--sp-3); flex-shrink: 0;
}
.ch-av {
  width: 37px; height: 37px; border-radius: 50%;
  background: rgba(255,255,255,.14); border: 1.5px solid rgba(255,255,255,.18);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; position: relative;
}
.ch-av i { color: #fff; font-size:var(--fs-lg); }
.ch-av-dot {
  position: absolute; bottom: 1px; right: 1px;
  width: 9px; height: 9px; border-radius: 50%;
  background: #4ade80; border: 2px solid var(--maroon-d);
}
.ch-info { flex: 1; min-width: 0; }
.ch-name { font-size:var(--fs-lg); font-weight: 600; color: #fff; letter-spacing: -.01em; }
.ch-status { font-size:var(--fs-xs); color: rgba(255,255,255,.55); margin-top:1px; display: flex; align-items: center; gap:var(--sp-1); }
.ch-status::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: #4ade80; flex-shrink: 0; }
.ch-btns { display: flex; gap:var(--sp-2); }
.ch-btn {
  width: 29px; height: 29px; border-radius: 7px;
  background: rgba(255,255,255,.1); border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: rgba(255,255,255,.65); font-size:var(--fs-sm); transition: background .14s, color .14s;
}
.ch-btn:hover { background: rgba(255,255,255,.2); color: #fff; }
.lbar {
  padding:var(--sp-2) var(--sp-4); border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap:var(--sp-2); flex-shrink: 0;
}
.lbar-lbl { font-size:var(--fs-xs); color: var(--ink3); text-transform: uppercase; letter-spacing: .9px; font-weight: 600; }
.lbtn {
  padding:var(--sp-1) var(--sp-2); border-radius: 20px; font-size:var(--fs-xs); font-weight: 600;
  border: 1.5px solid var(--border); background: none; cursor: pointer;
  color: var(--ink3); font-family: 'DM Sans', sans-serif; transition: all .14s;
}
.lbtn.on { border-color: var(--maroon); color: var(--maroon); background: var(--maroon-soft); }
.msgs {
  flex: 1; overflow-y: auto; padding:var(--sp-4);
  display: flex; flex-direction: column; gap:var(--sp-2); scroll-behavior: smooth;
}
.msgs::-webkit-scrollbar { width: 3px; }
.msgs::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
.mrow {
  display: flex; gap:var(--sp-2); align-items: flex-end; max-width: 90%;
  animation: mIn .18s ease both;
}
@keyframes mIn { from { opacity:0; transform:translateY(5px); } to { opacity:1; transform:none; } }
.mrow.u { align-self: flex-end; flex-direction: row-reverse; }
.mrow.b { align-self: flex-start; }
.mav {
  width: 25px; height: 25px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; font-size:var(--fs-xs);
}
.mrow.b .mav { background: var(--maroon); color: #fff; }
.mrow.u .mav { background: var(--maroon-d); color: rgba(255,255,255,.7); }
.mcol { display: flex; flex-direction: column; max-width: 100%; }
.mbub {
  padding:var(--sp-2) var(--sp-3); border-radius: 14px;
  font-size:var(--fs-md); line-height: 1.62; word-break: break-word;
}
.mrow.b .mbub { background: #f4ede4; border: 1px solid var(--border); border-bottom-left-radius: 4px; color: var(--ink); }
.mrow.u .mbub { background: var(--maroon-d); color: #fff; border-bottom-right-radius: 4px; }
.mtime { font-size:var(--fs-xs); color: var(--ink3); margin-top:var(--sp-1); padding:0 var(--sp-0); }
.mrow.u .mtime { text-align: right; }
.trow { align-self: flex-start; display: flex; gap:var(--sp-2); align-items: flex-end; }
.tbub { padding:var(--sp-2) var(--sp-3); background: #f4ede4; border: 1px solid var(--border); border-radius: 14px; border-bottom-left-radius: 4px; }
.tdots { display: flex; gap:3px; align-items: center; }
.tdots span { width: 5px; height: 5px; border-radius: 50%; background: var(--ink3); animation: db 1.3s infinite; }
.tdots span:nth-child(2) { animation-delay: .2s; }
.tdots span:nth-child(3) { animation-delay: .4s; }
@keyframes db { 0%,80%,100%{opacity:.2;transform:scale(.85)} 40%{opacity:1;transform:scale(1)} }
.chips { display: flex; flex-wrap: wrap; gap:var(--sp-1); margin-top:var(--sp-2); }
.chip {
  font-size:var(--fs-sm); padding:var(--sp-1) var(--sp-3); border-radius: 18px;
  border: 1px solid rgba(123,29,29,.18); background: rgba(123,29,29,.05);
  color: var(--maroon); cursor: pointer; font-family: 'DM Sans', sans-serif;
  font-weight: 600; transition: all .15s; white-space: nowrap;
  text-transform: none; letter-spacing: 0; line-height: 1.25;
}
.chip:hover { border-color: var(--maroon); color: var(--maroon); background: var(--maroon-soft); transform:none; box-shadow: 0 2px 8px rgba(123,29,29,.12); }
.rcard {
  margin-top:var(--sp-2); padding:var(--sp-2) var(--sp-3); border-radius: 10px;
  background: var(--gold-bg); border: 1px solid rgba(201,150,12,.2);
  border-left: 3px solid var(--gold); font-size:var(--fs-base); color: var(--ink2); line-height: 1.5;
}
.rcard strong { display: block; font-size:var(--fs-base); color: var(--maroon-d); margin-bottom:var(--sp-1); font-weight: 600; }
.rcard-btn {
  display: inline-flex; align-items: center; gap:var(--sp-2);
  margin-top:var(--sp-2); padding:var(--sp-1) var(--sp-3); border-radius: 7px;
  font-size:var(--fs-sm); font-weight: 600; background: var(--maroon);
  color: #fff; border: none; cursor: pointer;
  font-family: 'DM Sans', sans-serif; text-decoration: none; transition: background .13s;
}
.rcard-btn:hover { background: var(--maroon-d); }
.chat-actions { display: flex; flex-wrap: wrap; gap:var(--sp-2); margin-top:var(--sp-2); }
.chat-link {
  display: inline-flex; align-items: center; gap:var(--sp-1);
  padding:var(--sp-1) var(--sp-3); border-radius: 999px;
  border: 1.5px solid var(--border); background: var(--surface);
  color: var(--maroon-d); text-decoration: none; font-size:var(--fs-sm); font-weight: 600;
  transition: all .14s;
}
.chat-link:hover { border-color: var(--maroon); background: var(--maroon-soft); transform:none; }
.inp {
  padding:var(--sp-3) var(--sp-3) var(--sp-3); border-top: 1px solid var(--border);
  background: var(--surface); flex-shrink: 0;
}
.inp-row { display: flex; gap:var(--sp-2); align-items: flex-end; }
.inp-wrap { flex: 1; }
.ci {
  width: 100%; padding:var(--sp-2) var(--sp-4);
  border: 1.5px solid var(--border); border-radius: 11px;
  font-family: 'DM Sans', sans-serif; font-size:var(--fs-xl); color: var(--ink);
  background: #fff; resize: none; outline: none;
  min-height: 40px; max-height: 88px; line-height: 1.45;
  transition: border-color .17s, box-shadow .17s; -webkit-appearance: none;
}
.ci:focus { border-color: var(--maroon); box-shadow: 0 0 0 3px rgba(123,29,29,.08); }
.ci::placeholder { color: #C4AFA8; }
.sbtn {
  width: 40px; height: 40px; border-radius: 10px;
  background: var(--maroon-d); border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size:var(--fs-md); flex-shrink: 0;
  box-shadow:none; transition: all .17s; -webkit-appearance: none;
}
.sbtn:hover { background: var(--maroon); transform:none; box-shadow:none; }
.sbtn:active { transform:none; box-shadow:none; }
.sbtn:disabled { opacity: .45; cursor: not-allowed; transform: none; }
.inp-meta { margin-top:var(--sp-2); font-size:var(--fs-xs); color: var(--ink3); display: flex; align-items: center; gap:var(--sp-1); }
.inp-meta i { font-size:var(--fs-xs); }

/* ── RESPONSIVE ── */
@media (max-width: 860px) {
  body { align-items: flex-start; padding-top:var(--sp-5); }
  .shell { grid-template-columns: 1fr; max-width: 470px; }
  .brand { padding:1.9rem 1.7rem 1.7rem; }
  .brand-hero { margin-top:var(--sp-5); }
  .brand-hero h2 { font-size: 1.55rem; }
  .brand-feats, .brand-process, .brand-foot { display: none; }
  .panel { padding:2rem 1.7rem 1.6rem; }
  .panel-title { font-size: 1.45rem; }
  .pills { gap:var(--sp-1); }
  .pill { font-size:var(--fs-sm); }
}
@media (max-width: 767px) {
  #chatOverlay { align-items: flex-end; justify-content: center; padding:0; }
  /* dvh = the *visible* viewport on phones (88vh overflows behind the browser
     URL bar, clipping the header and hiding the input row) */
  #chatModal { max-width: 100%; width: 100%; border-radius: 20px 20px 0 0;
    height: 88vh; height: 88dvh; max-height: 88vh; max-height: 88dvh; }

  /* ── touch ergonomics, part two: BECca's own controls ──────────────────
     The 44px pass further down covers the sign-in form but stopped at the
     chat panel, where the header buttons were 29px, the language toggles
     23px and Send 40px. Sizing every one to a full 44 would blow out the
     slim chat header, so these grow to the largest the panel carries
     comfortably and Send — the one that actually posts — gets the full 44.
     Deliberately not a hit-area pseudo-element: .ch-btns has only a 6px gap,
     so 44px overlays would overlap and Close would start eating New chat. */
  .ch-btn { width: 38px; height: 38px; font-size:var(--fs-md); }
  .lbtn { padding:var(--sp-2) var(--sp-3); font-size:var(--fs-sm); }
  .sbtn { width: 44px; height: 44px; font-size:var(--fs-xl); }
  .ci { min-height: 44px; }
  .chip { padding:var(--sp-2) var(--sp-3); font-size:var(--fs-base); }
}
@media (max-width: 560px) {
  /* clearance so the floating Becca orb never covers the footer links */
  body { padding-bottom:5.6rem; }
}
@media (max-width: 520px) {
  body { padding:var(--sp-4); align-items: flex-start; padding-top:var(--sp-4); padding-bottom:5.6rem; }
  .shell { border-radius: 20px; }
  /* Compact brand header so the form is reachable without a full-screen scroll.
     Horizontal padding matches .panel below (1.4rem): side by side on a laptop
     the two columns' gutters are independent, but stacked on a phone they read
     as one column, and 1.3 vs 1.4 put the college name 2px left of the form
     heading directly under it. The 860px breakpoint already keeps both at
     1.7rem — this one had drifted. */
  .brand { padding:var(--sp-5) var(--sp-5) var(--sp-5); }
  /* keep the header tidy: less letter-spacing + a compact Home button so
     the college name and subtitle don't wrap awkwardly beside it */
  .brand-top { gap:var(--sp-2); }
  .brand-top strong { font-size:var(--fs-lg); line-height: 1.2; }
  .brand-top span { letter-spacing: .7px; font-size:var(--fs-xs); }
  .brand-home { padding:var(--sp-2) var(--sp-3); font-size:var(--fs-base); min-height: 44px; }

  /* ── touch ergonomics on the page most people reach on a phone ──────────
     The privacy checkbox was 16x16 and is mandatory before a report can be
     filed — the smallest, most consequential target on the site. The label
     already wraps it, so the whole row is clickable; this makes the box itself
     big enough to hit, and gives the notice toggle and the footer links the
     44px WCAG 2.5.5 asks for. */
  .pv-consent input { width: 22px; height: 22px; margin-top:0; }
  .pv-consent { min-height: 44px; align-items: center; }
  .pv-toggle { min-height: 44px; }
  .foot-links a, .footer-link a { min-height: 44px; display: inline-flex; align-items: center; }
  .brand-hero { margin-top:var(--sp-4); }
  .brand-tag { font-size:var(--fs-xs); padding:var(--sp-1) var(--sp-2); margin-bottom:var(--sp-3); }
  .brand-hero h2 { font-size: 1.35rem; }
  .brand-hero p { font-size:var(--fs-base); max-width: none; }
  .panel { padding:1.7rem var(--sp-5) var(--sp-5); }
  .panel-title { font-size: 1.4rem; }
  .action-row { grid-template-columns: 1fr; }

  /* ── type floor ────────────────────────────────────────────────────────
     This is the page a reporter reaches on a phone, and its smallest type ran
     9.9-11.6px at 390px. The eyebrow/label styles keep their letter-spaced
     all-caps character at ~11px; the field hints and the input meta line are
     instructions someone has to follow while typing, so they go to 12px. */
  .brand-tag { font-size:var(--fs-sm); }
  /* the "Property Management Office" line beside the college name — 9.9px, the
     smallest real text on the page, and the same line site_nav.php raises on
     the public pages */
  .brand-top span { font-size:var(--fs-sm); }
  .bp-label, .inp-meta { font-size:var(--fs-sm); }
  .panel-eyebrow, .ch-status { font-size:var(--fs-sm); }
  .fi-hint { font-size:var(--fs-sm); }
}
@media (max-width: 390px) {
  body { padding:var(--sp-3); padding-top:var(--sp-4); }
  .shell { border-radius: 18px; }
  .brand-top strong { font-size:var(--fs-md); }
  .panel-title { font-size: 1.3rem; }
  /* .panel-sub was 12.2px here and it is the line that explains what the form
     wants; .notice likewise. Kept a step below the 520px rules above so the
     smallest phones still lose a little, but not below 12px. */
  .panel-sub, .notice, .action-btn, .footer-link { font-size:var(--fs-base); }
  .fi { font-size:var(--fs-xl); padding:var(--sp-3) var(--sp-4) var(--sp-3) 2.35rem; }
}
@media (max-height: 720px) {
  body { align-items: flex-start; padding-top:var(--sp-4); padding-bottom:var(--sp-4); }
}

/* ── DESKTOP / LAPTOP FULL-BLEED ──
   min-width rules only: these never touch the mobile view (<=860px). They make
   the layout fill the ENTIRE screen edge-to-edge (no side gaps, full height). */
@media (min-width: 861px) {
  body { padding:0; align-items: stretch; overflow-x: hidden; }
  .shell {
    max-width: none; width: 100%; min-height: 100vh;
    border-radius: 0; border: none; box-shadow: none;
    grid-template-columns: 1.1fr .9fr;
  }
  .brand { padding:4rem 4rem 3rem; }            /* left panel fills full height */
  .brand-seal { width: 56px; height: 56px; }
  .brand-top strong { font-size:var(--fs-2xl); }
  .brand-top span { font-size:var(--fs-sm); }
  .brand-hero { margin-top:3.2rem; }
  .brand-tag { font-size:var(--fs-sm); padding:var(--sp-2) var(--sp-4); }
  .brand-hero h2 { font-size: 2.6rem; }
  .brand-hero p { font-size:var(--fs-xl); max-width: 44ch; }
  .brand-feats { gap:var(--sp-4); padding-top:2.6rem; }
  .brand-feat { font-size:var(--fs-lg); }
  .brand-feat .bf-ic { width: 36px; height: 36px; font-size:var(--fs-md); }
  .brand-foot { font-size:var(--fs-sm); }
  /* right panel: vertically centre the form, cap its width so inputs stay readable */
  .panel { padding:3.5rem 4rem; justify-content: center; align-items: center; }
  .panel > * { width: 100%; max-width: 440px; }
  .panel-title { font-size: 2rem; }
  .panel-sub { font-size:var(--fs-xl); margin-bottom:1.7rem; }
  .pill { font-size:var(--fs-base); padding:var(--sp-1) var(--sp-3); }
  .notice { font-size:var(--fs-md); padding:var(--sp-3) var(--sp-4); }
  .fl { font-size:var(--fs-base); }
  .fi { padding:var(--sp-4) var(--sp-4) var(--sp-4) 2.6rem; font-size:var(--fs-xl); }
  .fi-icon { font-size:var(--fs-lg); left: 1rem; }
  .fi-hint { font-size:var(--fs-base); }
  .btn-submit { padding:var(--sp-4) var(--sp-5); font-size:var(--fs-2xl); }
  .action-btn { font-size:var(--fs-md); padding:var(--sp-4) var(--sp-3); }
  .action-btn i { font-size:var(--fs-2xl); }
  .footer-link { font-size:var(--fs-base); }
}
@media (min-width: 1280px) {
  .brand { padding:4.5rem 5.5rem 3.5rem; }
  .brand-hero { margin-top:3.6rem; }
  .brand-hero h2 { font-size: 3rem; }
  .brand-hero p { font-size:var(--fs-2xl); }
  .panel-title { font-size: 2.2rem; }
  .panel > * { max-width: 460px; }
}
@media (min-width: 1600px) {
  .brand { padding:5rem 8rem 4rem; }
  .brand-hero h2 { font-size: 3.3rem; }
}
</style>
</head>
<body>
<a class="skip-link" href="#main">Skip to the report form</a>
<div class="bg-grid" aria-hidden="true"></div>

<div class="shell">

  <!-- LEFT — BEC brand panel -->
  <aside class="brand">
    <div class="brand-top">
      <div class="brand-seal"><img src="assets/logs.png" alt="BEC logo"></div>
      <div>
        <strong>Batangas Eastern Colleges</strong>
        <span>Property Management Office</span>
      </div>
      <a class="brand-home" href="index.php"><i aria-hidden="true" class="fas fa-house"></i> Home</a>
    </div>
    <div class="brand-hero">
      <span class="brand-tag"><i aria-hidden="true" class="fas fa-building-shield"></i> Property Management Office · Official Portal</span>
      <h2>Defective Equipment <em>Reporting</em> System</h2>
      <p>The official channel of Batangas Eastern Colleges for reporting, tracking, and resolving campus equipment concerns — administered by the Property Management Office.</p>
    </div>
    <div class="brand-process">
      <div class="bp-label"><i aria-hidden="true" class="fas fa-diagram-project"></i> How your report is handled</div>
      <div class="brand-steps">
        <div class="bs-item"><span class="bs-num">1</span><div><b>Submit your report</b><i>Describe the defective equipment and its location.</i></div></div>
        <div class="bs-item"><span class="bs-num">2</span><div><b>PMO review &amp; assignment</b><i>The office verifies the request and assigns a technician.</i></div></div>
        <div class="bs-item"><span class="bs-num">3</span><div><b>Repair &amp; resolution</b><i>Follow the real-time status until the issue is resolved.</i></div></div>
        <div class="bs-item"><span class="bs-num">4</span><div><b>Email confirmation</b><i>You're notified by email at every key stage.</i></div></div>
      </div>
    </div>
    <div class="brand-foot">Property Management Office &middot; Main Campus, Annex 1 &amp; Annex 2 &nbsp;—&nbsp; Serving the Batangas Eastern Colleges community.</div>
  </aside>

  <!-- RIGHT — report entry form -->
  <main id="main" class="panel">
    <div class="panel-eyebrow"><span class="pe-dot"></span> Equipment Defect Reporting</div>
    <h1 class="panel-title">Report <em>defective campus equipment</em></h1>
    <?php /* The panel beside this already introduces the office and the system,
             so this said the same thing a second time in five lines. */ ?>
    <p class="panel-sub">Sign in with your official BEC details to report damaged or malfunctioning equipment. You will receive a ticket reference by email and can follow your report through to its repair.</p>
    <?php /* Collapsed by default. Open, this guidance ran to four paragraphs and
             pushed the Full Name field off the bottom of a laptop screen, so the
             first thing a reporter met on a sign-in page was a wall of text.
             It is one tap away, and repeated on the report form itself. */ ?>
    <details class="intro-card">
      <summary><i aria-hidden="true" class="fas fa-circle-info"></i> A few things to know<i aria-hidden="true" class="fas fa-chevron-down intro-chev"></i></summary>
      <ul class="intro-list">
        <li><i aria-hidden="true" class="fas fa-id-card"></i><span><b>Who may report.</b> Any Batangas Eastern Colleges student, faculty, or staff member with an official <b>@bec.edu.ph</b> email account may file a report.</span></li>
        <li><i aria-hidden="true" class="fas fa-clipboard-list"></i><span><b>What to prepare.</b> Identify the equipment, where it is located, and a short description of the problem. Adding a photo helps the technicians assess it faster.</span></li>
        <li><i aria-hidden="true" class="fas fa-route"></i><span><b>What happens after you submit.</b> The PMO reviews your report, assigns a technician, and carries out the repair. You are updated by email at every key stage and can track the status online at any time.</span></li>
        <li><i aria-hidden="true" class="fas fa-user-shield"></i><span><b>How your information is used.</b> Your details are used only to process and deliver your report, kept confidential in line with the Data Privacy Act of 2012 (RA 10173).</span></li>
      </ul>
    </details>
    <?php if ($notice !== ''): ?>
    <div class="alert alert-ok" role="status" aria-live="polite"><i aria-hidden="true" class="fas fa-paper-plane"></i> <?php echo htmlspecialchars($notice); ?></div>
    <?php endif; ?>
    <?php if (!$error && isset($_GET['expired'])): ?>
    <div class="alert alert-note">
      <i aria-hidden="true" class="fas fa-clock-rotate-left"></i>
      Your session ended after a period of inactivity. Please sign in again to continue — this keeps your details safe on shared computers.
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-err" role="alert" aria-live="assertive">
      <i aria-hidden="true" class="fas fa-exclamation-circle"></i>
      <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>
    <?php if ($stage === 'signin' && $trustedEmail !== ''): ?>
    <!-- This browser verified a code within the last 30 days. Offered as one
         tap rather than signing in silently: on a shared library machine the
         person sitting down is often not the person who verified. -->
    <div class="trust">
      <div class="trust-av"><?php
        // Initials of the given and family names, not the first two letters of
        // whatever the roster happened to put first.
        $__p = preg_split('/\s+/', trim($trustedName)) ?: [];
        $__i = '';
        foreach ([reset($__p), end($__p)] as $__w) { if ($__w !== false && $__w !== '') { $__i .= mb_substr($__w, 0, 1); } }
        echo htmlspecialchars(mb_strtoupper($__i !== '' ? $__i : 'B'));
      ?></div>
      <div class="trust-txt">
        <b><?php echo htmlspecialchars($trustedName); ?></b>
        <span><?php echo htmlspecialchars($trustedEmail); ?> · verified on this device</span>
      </div>
    </div>
    <form method="POST" action="">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="step" value="trusted">
      <input type="hidden" name="eq" value="<?php echo htmlspecialchars($eq, ENT_QUOTES); ?>">
      <button type="submit" class="btn-submit trust-go">
        Continue as <?php echo htmlspecialchars($trustedFirst !== '' ? $trustedFirst : $trustedName); ?>
        <span class="btn-arrow"><i aria-hidden="true" class="fas fa-arrow-right"></i></span>
      </button>
    </form>
    <form method="POST" action="" style="text-align:center;">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="step" value="forget">
      <button type="submit" class="otp-link">Not you? Sign in as someone else</button>
    </form>
    <?php endif; ?>

    <?php if ($stage === 'verify'): ?>
    <!-- Step two of two. The reporter has said who they are; this is where they
         show they can open the mailbox that belongs to that person. The address
         is read from the session, never from the page, so a code cannot be
         redirected to a different account. -->
    <div class="otp-steps" aria-hidden="true">
      <span class="otp-step done"><i aria-hidden="true" class="fas fa-check"></i> Your details</span>
      <span class="otp-step-line"></span>
      <span class="otp-step now">2 · Verify your email</span>
    </div>

    <div class="otp-head">
      <div class="otp-ic"><i aria-hidden="true" class="fas fa-envelope-open-text"></i></div>
      <h2>Confirm it&rsquo;s you</h2>
      <p>For your security, the Property Management Office sends a 6-digit
      verification code to your official college email before accepting a report.</p>
      <div class="otp-to">
        <i aria-hidden="true" class="fas fa-paper-plane"></i>
        <span><?php echo htmlspecialchars((string)($_SESSION['otp_email'] ?? '')); ?></span>
      </div>
    </div>

    <?php if ($devCode !== ''): ?>
    <div class="alert alert-note"><i aria-hidden="true" class="fas fa-flask"></i> Local setup, mail not configured &mdash; your code is <strong><?php echo htmlspecialchars($devCode); ?></strong>.</div>
    <?php endif; ?>

    <form method="POST" action="" id="otpForm" novalidate>
      <?php echo csrf_field(); ?>
      <input type="hidden" name="step" value="verify">
      <div class="fg">
        <label class="fl" for="otpCode">Verification Code <span class="req">*</span></label>
        <input type="text" name="otp_code" id="otpCode" class="otp-input" required
          inputmode="numeric" autocomplete="one-time-code" maxlength="6"
          pattern="[0-9]{6}" placeholder="------"
          aria-describedby="otpTimer" autofocus>
        <div class="fi-hint" id="otpTimer"
             data-left="<?php echo (int)$otpSecondsLeft; ?>"
             data-wait="<?php echo (int)max(0, REPORTER_OTP_RESEND_WAIT - (time() - $otpAskedAt)); ?>">
          <?php if ($otpSecondsLeft > 0): ?>
            <i aria-hidden="true" class="fas fa-clock"></i> This code is valid for a few more minutes.
          <?php else: ?>
            <i aria-hidden="true" class="fas fa-triangle-exclamation"></i> This code has expired &mdash; please request a new one.
          <?php endif; ?>
        </div>
      </div>
      <button type="submit" class="btn-submit" id="otpSubmit">
        Verify and continue
        <span class="btn-arrow"><i aria-hidden="true" class="fas fa-arrow-right"></i></span>
      </button>
    </form>

    <div class="otp-actions">
      <form method="POST" action="">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="step" value="verify">
        <input type="hidden" name="resend" value="1">
        <button type="submit" class="otp-btn" id="otpResend">
          <i aria-hidden="true" class="fas fa-rotate-right"></i> <span>Send a new code</span>
        </button>
      </form>
      <form method="POST" action="">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="step" value="forget">
        <button type="submit" class="otp-btn">
          <i aria-hidden="true" class="fas fa-pen"></i> <span>Change email address</span>
        </button>
      </form>
    </div>

    <p class="otp-foot">
      <i aria-hidden="true" class="fas fa-circle-info"></i>
      Can&rsquo;t find it? Check your spam or junk folder. Codes are sent only to
      addresses on record with Batangas Eastern Colleges.
    </p>
    <?php else: ?>
<form method="POST" action="" id="signinForm" onsubmit="if(window.AuthLoader)AuthLoader.show('Signing you in…','Preparing your report portal…');">
      <?php /* Rendered here, not left to the JS injector: every POST on this page
               is checked, and without a token the reporter is told their session
               expired with no way through. Sign-in must not depend on a script. */ ?>
      <?php echo csrf_field(); ?>
      <input type="hidden" name="eq" value="<?php echo htmlspecialchars($eq, ENT_QUOTES); ?>">
      <?php if ($eq !== ''): ?><div class="notice" style="margin-bottom:.6rem;"><i aria-hidden="true" class="fas fa-qrcode"></i> <span>You scanned an equipment QR — it will be pre-selected after you sign in.</span></div><?php endif; ?>
      <div class="fg">
        <label class="fl" for="signinName">Full Name <span class="req">*</span></label>
        <div class="fi-wrap">
          <i aria-hidden="true" class="fas fa-user fi-icon"></i>
          <input type="text" name="full_name" id="signinName" class="fi" placeholder="e.g. Maria Santos"
            value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
            autocomplete="name" maxlength="80" data-guard="alpha" required>
        </div>
        <div class="fi-hint"><i aria-hidden="true" class="fas fa-id-card"></i> If Batangas Eastern Colleges holds a name for your account, your report will carry that official name.</div>
      </div>
      <div class="fg">
        <label class="fl" for="signinEmail">Email Address <span class="req">*</span></label>
        <div class="fi-wrap">
          <i aria-hidden="true" class="fas fa-envelope fi-icon"></i>
          <?php /* The domain is matched case-insensitively: a phone that
                   autocapitalises to @BEC.edu.ph was blocked by the browser even
                   though the address is lowercased server-side and would be
                   accepted. `pattern` is anchored implicitly, so no trailing $. */ ?>
          <input type="email" name="email" id="signinEmail" class="fi" placeholder="juan.delacruz@bec.edu.ph"
            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
            autocomplete="email" maxlength="120" required
            pattern="[a-zA-Z0-9._%+\-]+@[Bb][Ee][Cc]\.[Ee][Dd][Uu]\.[Pp][Hh]"
            title="Use your official BEC email ending in @bec.edu.ph">
        </div>
        <div class="fi-hint"><i aria-hidden="true" class="fas fa-id-badge"></i> Use your official BEC account (<strong>@bec.edu.ph</strong>). Your ticket confirmation will be sent here.</div>
      </div>
      <div class="pv-block">
        <label class="pv-consent">
          <input type="checkbox" name="privacy_consent" value="1" required <?php echo !empty($_POST['privacy_consent']) ? 'checked' : ''; ?>>
          <span><strong>Data Privacy Notice.</strong> I agree that Batangas Eastern Colleges — Property Management Office may collect and process my <strong>name, email address, and report details</strong> for equipment maintenance and record-keeping, under the <strong>Data Privacy Act of 2012 (RA 10173)</strong>.</span>
        </label>
        <!-- Kept outside the <label> on purpose: inside it, every click would
             also toggle the consent checkbox. -->
        <button type="button" class="pv-toggle" id="pvToggle" aria-expanded="false" aria-controls="pvPanel">
          <i aria-hidden="true" class="fas fa-shield-halved"></i>
          <span id="pvToggleText">Read the full notice</span>
          <i aria-hidden="true" class="fas fa-chevron-down chev"></i>
        </button>
        <div class="pv-panel" id="pvPanel" role="region" aria-labelledby="pvToggle">
          <dl class="pv-inner">
            <dt>What we collect</dt>
            <dd>Your full name, your official <strong>@bec.edu.ph</strong> email address, your department or course, an optional contact number, and the details and photos you attach to a report.</dd>

            <dt>Why we collect it</dt>
            <dd>To identify the reporter, verify the report against the official BEC directory, assign a technician, send you status updates and your ticket number, and keep the Property Management Office's maintenance records.</dd>

            <dt>Who can see it</dt>
            <dd>Only PMO staff and the technician assigned to your report. Your details are never shared outside the institution, sold, or used for advertising. Public report listings show the equipment and status only — never your name or email.</dd>

            <dt>How long we keep it</dt>
            <dd>Reports are retained as part of the school's equipment maintenance history. You may ask the PMO to correct your details at any time.</dd>

            <dt>Your rights</dt>
            <dd>Under RA 10173 you may access, correct, or object to the processing of your personal information, and withdraw consent. To do so, contact the Property Management Office.</dd>
          </dl>
        </div>
      </div>
      <button type="submit" class="btn-submit">
        Continue to Report Submission
        <span class="btn-arrow"><i aria-hidden="true" class="fas fa-arrow-right"></i></span>
      </button>
    </form>
    <?php endif; ?>
    <div class="or-row">or</div>
    <div class="action-row">
      <a href="track_report.php" class="action-btn">
        <i aria-hidden="true" class="fas fa-ticket-alt"></i>Track existing report
      </a>
      <a href="public_reports.php" class="action-btn">
        <i aria-hidden="true" class="fas fa-table-list"></i>View all reports
      </a>
    </div>
    <div class="safety-note">
      <i aria-hidden="true" class="fas fa-triangle-exclamation"></i>
      <span><strong>Safety first.</strong> For urgent hazards that put people at risk — live electrical faults, fire, gas, or water leaks — contact the PMO or campus security in person or by phone <em>immediately</em>. Use this portal for non-emergency equipment concerns.</span>
    </div>
    <div class="trust-strip">
      <span><i aria-hidden="true" class="fas fa-lock"></i> Confidential</span>
      <span><i aria-hidden="true" class="fas fa-circle-check"></i> Official @bec.edu.ph only</span>
      <span><i aria-hidden="true" class="fas fa-route"></i> Tracked end-to-end</span>
    </div>
    <div class="footer-link">
      Need help?<a onclick="openChat()" role="button" tabindex="0">Contact support</a>
    </div>
  </main>
</div>


<!-- ══ FLOATING CHAT LAUNCHER (left) ══ -->
<button id="chatFab" type="button" onclick="openChat()" aria-label="Open BEC Support assistant">
  <span class="fab-ic"><img src="assets/Gemini_Generated_Image_e35zfue35zfue35z.png" alt="Becca" onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'fas fa-robot\'></i>'"></span>
  <span class="fab-txt"><b>Ask Becca</b><span>BEC Support AI</span></span>
</button>

<!-- ══ AI CHATBOT MODAL ══ -->
<div id="chatOverlay" onclick="overlayClick(event)">
  <div id="chatModal">

    <div class="ch">
      <div class="ch-av">
        <img src="assets/Gemini_Generated_Image_e35zfue35zfue35z.png" alt="Becca — BEC Support AI" style="width:100%;height:100%;object-fit:cover;display:block;border-radius:50%">
        <div class="ch-av-dot"></div>
      </div>
      <div class="ch-info">
        <div class="ch-name">Becca <span style="font-weight:500;opacity:.75;font-size:.82em;">· BEC Support AI</span></div>
        <div class="ch-status">Online &middot; Ready to help</div>
      </div>
      <div class="ch-btns">
        <button class="ch-btn" onclick="clearChat()" title="New chat" aria-label="Start a new chat"><i aria-hidden="true" class="fas fa-rotate-right"></i></button>
        <button class="ch-btn" onclick="closeChat()" title="Close" aria-label="Close chat"><i aria-hidden="true" class="fas fa-xmark"></i></button>
      </div>
    </div>

    <div class="lbar">
      <span class="lbar-lbl">Language:</span>
      <button class="lbtn on" id="lb-en" onclick="setLang('en')">English</button>
      <button class="lbtn"    id="lb-fil" onclick="setLang('fil')">Filipino</button>
    </div>

    <div class="msgs" id="msgs"></div>

    <div class="inp">
      <div class="inp-row">
        <div class="inp-wrap">
          <textarea class="ci" id="ci"
            placeholder="Type your message here…"
            rows="1"
            onkeydown="handleKey(event)"
            oninput="grow(this)"></textarea>
        </div>
        <button class="sbtn" id="sbtn" onclick="send()">
          <i aria-hidden="true" class="fas fa-paper-plane"></i>
        </button>
      </div>
      <div class="inp-meta"><i aria-hidden="true" class="fas fa-shield-alt"></i> AI-powered · BEC knowledge base · Urgent: ext. 215</div>
    </div>

  </div>
</div>


<script>

/* ── verification step ──────────────────────────────────────────────────
   All of this is decoration over a form that already works without it: the
   field submits, the buttons post, and the server is the one that decides. */
(function(){
  var input = document.getElementById('otpCode');
  if (!input) return;
  var form   = document.getElementById('otpForm'),
      submit = document.getElementById('otpSubmit'),
      timer  = document.getElementById('otpTimer'),
      resend = document.getElementById('otpResend');

  /* Codes arrive as "012345" but get pasted as "012 345" or "Code: 012345". */
  function clean(v){ return (v || '').replace(/\D/g, '').slice(0, 6); }
  input.addEventListener('input', function(){
    var before = input.value;
    input.value = clean(before);
    input.classList.remove('is-bad');
    // Six digits means there is nothing left to type or decide.
    if (input.value.length === 6 && form) { form.requestSubmit ? form.requestSubmit() : form.submit(); }
  });
  input.addEventListener('paste', function(e){
    var t = (e.clipboardData || window.clipboardData);
    if (!t) return;
    e.preventDefault();
    input.value = clean(t.getData('text'));
    input.dispatchEvent(new Event('input'));
  });
  if (form) form.addEventListener('submit', function(e){
    if (input.value.length !== 6) { e.preventDefault(); input.classList.add('is-bad'); input.focus(); return; }
    if (submit) { submit.disabled = true; submit.style.opacity = '.72'; }
  });

  /* The code's real remaining life, counted down rather than asserted once. */
  var left = parseInt(timer && timer.dataset.left || '0', 10);
  function paintTimer(){
    if (!timer) return;
    if (left <= 0) {
      timer.innerHTML = '<i aria-hidden="true" class="fas fa-triangle-exclamation"></i> This code has expired — please request a new one.';
      return;
    }
    var m = Math.floor(left / 60), sec = left % 60;
    timer.innerHTML = '<i aria-hidden="true" class="fas fa-clock"></i> This code expires in <strong>' +
      (m > 0 ? m + 'm ' : '') + (sec < 10 && m > 0 ? '0' : '') + sec + 's</strong>.';
    left--;
    setTimeout(paintTimer, 1000);
  }
  paintTimer();

  /* Resend is refused server-side for a few seconds after a send; saying so
     beats a button that looks live and quietly does nothing. */
  var wait = parseInt(timer && timer.dataset.wait || '0', 10);
  if (resend && wait > 0) {
    var label = resend.querySelector('span'), original = label ? label.textContent : '';
    resend.disabled = true;
    (function tick(){
      if (wait <= 0) { resend.disabled = false; if (label) label.textContent = original; return; }
      if (label) label.textContent = 'Send a new code (' + wait + 's)';
      wait--;
      setTimeout(tick, 1000);
    })();
  }
})();

/* ══════════════════════════════════════
   BEC SUPPORT AI  —  "Becca"
   A warm, intelligent equipment-support assistant.

   Routes through chat_proxy.php so the
   API key stays safely on the server.

   This build adds a consistent persona, emotional
   awareness, short-term memory, and natural varied
   phrasing so the bot feels alive and friendly —
   while staying honest about being an AI assistant.
══════════════════════════════════════ */

const PROXY = 'chat_proxy.php';

let lang    = 'en';
let history = [];
let busy    = false;
let greeted = false;

/* ── Becca's short-term memory ──
   Persona + lightweight context she "remembers"
   across the conversation, so she feels aware. */
const persona = {
  name: 'Becca',
  role_en: 'BEC Support AI',
  role_fil: 'BEC Support AI'
};

const memory = {
  userName: null,        // remembered if the user introduces themselves
  lastTopic: null,       // 'projector' | 'computer' | 'ac' | 'track' | ...
  mood: 'neutral',       // 'frustrated' | 'happy' | 'urgent' | 'neutral'
  turns: 0,              // how many times the user has spoken
  troubleshotTopics: []  // topics already walked through (avoid repeating)
};

/* pick a random variant so replies never feel scripted */
function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

/* ── Keep the panel above the on-screen keyboard (mobile) ──
   Ported from includes/becca_widget.php. This page carries its own copy of the
   assistant rather than requiring the shared widget, so the keyboard fix that
   landed there never reached the one page reporters are most likely to open on
   a phone: the composer sat underneath the keyboard and you could not see what
   you were typing.

   vh/dvh units don't shrink when the keyboard opens, so we pin the overlay to
   the *visual* viewport (the area above the keyboard) instead. NOTE: the
   overlay is `inset:0`, so right/bottom must be cleared to `auto` — with top
   and bottom both set the browser ignores our height and stretches it to full
   height, which was the original bug. */
function resetOverlayBox(overlay, modal) {
  overlay.style.top = overlay.style.left = '';
  overlay.style.right = overlay.style.bottom = '';
  overlay.style.width = overlay.style.height = '';
  if (modal) modal.style.height = '';
}
function fitToKeyboard() {
  const vv = window.visualViewport;
  const overlay = document.getElementById('chatOverlay');
  const modal = document.getElementById('chatModal');
  if (!vv || !overlay || !modal || !overlay.classList.contains('open')) return;
  if (window.innerWidth > 767) { resetOverlayBox(overlay, modal); return; } // desktop: leave CSS alone

  const keyboardOpen = (window.innerHeight - vv.height) > 120;
  if (keyboardOpen) {
    overlay.style.top    = vv.offsetTop + 'px';
    overlay.style.left   = vv.offsetLeft + 'px';
    overlay.style.right  = 'auto';
    overlay.style.bottom = 'auto';           // <-- critical: let height win
    overlay.style.width  = vv.width + 'px';
    overlay.style.height = vv.height + 'px';
    modal.style.height   = vv.height + 'px';
    const box = document.getElementById('msgs');
    if (box) box.scrollTop = box.scrollHeight; // keep latest message visible
  } else {
    resetOverlayBox(overlay, modal);
  }
}
if (window.visualViewport) {
  window.visualViewport.addEventListener('resize', fitToKeyboard);
  window.visualViewport.addEventListener('scroll', fitToKeyboard);
}

/* open / close */
function openChat() {
  document.getElementById('chatOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
  if (!greeted) { greeted = true; greet(); }
  const ci = document.getElementById('ci');
  // re-fit whenever the field gains focus (keyboard shows a moment later)
  ci.addEventListener('focus', function () { setTimeout(fitToKeyboard, 250); });
  setTimeout(() => { ci.focus(); fitToKeyboard(); }, 300);
}
function closeChat() {
  const overlay = document.getElementById('chatOverlay');
  overlay.classList.remove('open');
  document.body.style.overflow = '';
  resetOverlayBox(overlay, document.getElementById('chatModal')); // clear keyboard sizing
}
function overlayClick(e) {
  if (e.target === document.getElementById('chatOverlay')) closeChat();
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeChat(); });

/* language */
function setLang(l) {
  lang = l;
  document.getElementById('lb-en').classList.toggle('on', l === 'en');
  document.getElementById('lb-fil').classList.toggle('on', l === 'fil');
}

function detectInputLang(text) {
  const value = String(text || '').toLowerCase().trim();
  if (!value) return lang;

  const filipinoMatches = [
    /\b(kumusta|kamusta|musta|paano|bakit|saan|kailan|sino|ano|alin|gaano|pwede|puwede|gusto|kailangan|salamat|opo|po|hindi|oo|meron|wala|nasaan|pakitulong|patulong|paki|sira|gumagana|gumana|ayaw|nagloloko|mabagal|maingay|lumalamig|mainit|tagas|ingay|ayos|paayos|ipagawa|mag-report|mag track|mag-track|ireport|ulat|isyu|problema)\b/i,
    /\b(nag-|pag-|mag-|ma-|ipa-|ipag-|pinaka)[a-z-]*/i,
    /\b(ako|ikaw|kayo|kami|tayo|nila|namin|atin|ito|iyan|iyon|dito|doon)\b/i
  ].reduce((count, pattern) => count + ((value.match(pattern) || []).length), 0);

  return filipinoMatches >= 2 ? 'fil' : 'en';
}

/* ── Emotional / intent awareness ──
   Lets Becca respond to *how* the user feels,
   not just to keywords. */
function readMood(text) {
  const q = String(text || '').toLowerCase();
  if (/\b(asap|urgent|emergency|now na|right now|kanina pa|matagal na|spark|smoke|usok|kuryente|burning|amoy sunog|fire)\b/i.test(q)) return 'urgent';
  if (/\b(angry|frustrated|annoyed|ulit|paulit|nakakainis|inis|badtrip|stupid|useless|wala kayong|hindi gumagana parati|broken again|sira na naman|sawa na|pissed)\b/i.test(q)) return 'frustrated';
  if (/\b(thanks|thank you|salamat|maraming salamat|appreciate|galing|ang bilis|nice|great|helpful|the best|love it)\b/i.test(q)) return 'happy';
  return 'neutral';
}

/* Pull the user's name if they introduce themselves */
function captureName(text) {
  const m = String(text || '').match(/\b(?:i am|i'm|my name is|ako si|ako po si|me name is|this is)\s+([a-zA-ZñÑ][a-zA-ZñÑ.'-]{1,20})/i);
  if (m && m[1]) {
    const clean = m[1].replace(/[.'-]+$/, '');
    if (clean.length >= 2 && !/^(the|a|an|si|po|not|so|just|here|having|getting)$/i.test(clean)) {
      memory.userName = clean.charAt(0).toUpperCase() + clean.slice(1);
    }
  }
}

/* Is the user asking who/what Becca is? */
function isIdentityQuestion(text) {
  return /\b(who are you|what are you|are you (real|human|a (bot|robot|person|machine|ai)|alive|sentient|conscious)|sino ka|ano ka|tao ka ba|robot ka ba|totoo ka ba|buhay ka ba|may sarili kang isip|your name|anong pangalan mo|pangalan mo)\b/i.test(String(text || ''));
}

function chipLabel(key, currentLang = lang) {
  const labels = {
    report_projector: { en: 'Report a broken projector', fil: 'Mag-report ng sirang projector' },
    track: { en: 'Track my report', fil: 'I-track ang report ko' },
    computer: { en: "Computer won't start", fil: 'Hindi nagbubukas ang computer' },
    ac: { en: 'AC not cooling', fil: 'Hindi lumalamig ang aircon' },
    submit: { en: 'How do I submit a report?', fil: 'Paano mag-report?' },
    timeline: { en: 'How long are repairs?', fil: 'Gaano katagal ang repair?' },
    about_bec: { en: 'About BEC', fil: 'Tungkol sa BEC' }
  };
  return labels[key]?.[currentLang] || labels[key]?.en || key;
}

function chipSet(keys, currentLang = lang) {
  return keys.map(key => chipLabel(key, currentLang));
}

function actionLabel(type, currentLang = lang) {
  const labels = {
    create: { en: 'Create Report', fil: 'Gumawa ng Report' },
    tracker: { en: 'Open Tracker', fil: 'Buksan ang Tracker' },
    public: { en: 'Public Reports', fil: 'Mga Public Report' }
  };
  return labels[type]?.[currentLang] || labels[type]?.en || type;
}

/* greeting — warmer, with personality */
function greet() {
  const currentLang = lang;
  const msg = currentLang === 'fil'
    ? `Kumusta! 👋 Ako si ${persona.name}, ang inyong BEC Support AI. Parang katuwang ninyo ako dito — tutulungan ko kayo sa mga sirang kagamitan, pag-submit ng defect report, at troubleshooting. Ano ang maitutulong ko sa inyo ngayon?`
    : `Hi there! 👋 I'm ${persona.name}, your BEC Support AI. Think of me as your go-to buddy for equipment hiccups — broken gear, defect reports, and quick troubleshooting. What's giving you trouble today?`;
  const chips = chipSet(['report_projector', 'track', 'submit', 'about_bec'], currentLang);
  addMsg('b', msg, chips, false, [
    { label: actionLabel('create', currentLang), href: 'student_dashboard.php', icon: 'fa-plus' },
    { label: actionLabel('tracker', currentLang), href: 'track_report.php', icon: 'fa-search' },
    { label: actionLabel('public', currentLang), href: 'public_reports.php', icon: 'fa-list' }
  ]);
}

/* clear — also resets Becca's memory */
function clearChat() {
  history = []; greeted = false;
  memory.userName = null;
  memory.lastTopic = null;
  memory.mood = 'neutral';
  memory.turns = 0;
  memory.troubleshotTopics = [];
  document.getElementById('msgs').innerHTML = '';
  greet(); greeted = true;
}

function getChatActions(text, suggest, explicitActions = []) {
  if (Array.isArray(explicitActions) && explicitActions.length) return explicitActions;

  const q = String(text || '').toLowerCase();
  const currentLang = detectInputLang(text);
  const actions = [];
  if (suggest || /\b(report|submit|projector|computer|aircon|ac)\b/i.test(q)) {
    actions.push({ label: actionLabel('create', currentLang), href: 'student_dashboard.php', icon: 'fa-plus' });
  }
  if (/\b(track|ticket|status|report id|asset tag|equipment id)\b/i.test(q)) {
    actions.push({ label: actionLabel('tracker', currentLang), href: 'track_report.php', icon: 'fa-search' });
  }
  actions.push({ label: actionLabel('public', currentLang), href: 'public_reports.php', icon: 'fa-list' });

  const seen = new Set();
  return actions.filter(action => {
    if (seen.has(action.href)) return false;
    seen.add(action.href);
    return true;
  }).slice(0, 3);
}

/* add a message bubble */
function addMsg(role, text, chips, suggest, actions = []) {
  const box = document.getElementById('msgs');
  const row = document.createElement('div');
  row.className = 'mrow ' + (role === 'u' ? 'u' : 'b');
  const detectedLang = role === 'u' ? detectInputLang(text) : lang;

  const safe = esc(text).replace(/\n/g, '<br>');

  let extra = '';
  if (chips && chips.length) {
    extra += '<div class="chips">' +
      chips.map(c => `<button class="chip" onclick="quickSend(this.dataset.t)" data-t="${escAttr(c)}">${esc(c)}</button>`).join('') +
      '</div>';
  }
  if (role !== 'u') {
    const chatActions = getChatActions(text, suggest, actions);
    if (chatActions.length) {
      extra += '<div class="chat-actions">' +
        chatActions.map(action => `<a class="chat-link" href="${escAttr(action.href)}"><i aria-hidden="true" class="fas ${escAttr(action.icon || 'fa-arrow-right')}"></i>${esc(action.label || 'Open')}</a>`).join('') +
        '</div>';
    }
  }
  if (suggest) {
    extra += `<div class="rcard">
      <strong><i aria-hidden="true" class="fas fa-file-circle-exclamation" style="margin-right:.28rem"></i>${detectedLang === 'fil' ? 'Mag-submit ng Formal Report' : 'Submit a Formal Report'}</strong>
      ${detectedLang === 'fil'
        ? 'Mukhang kailangan ito ng aktuwal na pagtingin. Gumawa ng opisyal na defect report para sa facilities team.'
        : 'This issue likely needs hands-on attention. Create an official defect report for the facilities team.'}
      <br><a href="student_dashboard.php" class="rcard-btn"><i aria-hidden="true" class="fas fa-plus"></i> ${detectedLang === 'fil' ? 'Gumawa ng Report' : 'Create Report'}</a>
    </div>`;
  }

  const icon = role === 'u' ? 'fas fa-user' : 'fas fa-robot';
  row.innerHTML = `
    <div class="mav"><i class="${icon}"></i></div>
    <div class="mcol">
      <div class="mbub">${safe}${extra}</div>
      <div class="mtime">${now()}</div>
    </div>`;
  box.appendChild(row);
  box.scrollTop = box.scrollHeight;
}

/* typing dots */
function showTyping() {
  const box = document.getElementById('msgs');
  const row = document.createElement('div');
  row.className = 'trow'; row.id = 'typing';
  row.innerHTML = `
    <div class="mav" style="background:var(--maroon);color:#fff;width:25px;height:25px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.6rem;flex-shrink:0">
      <i aria-hidden="true" class="fas fa-robot"></i>
    </div>
    <div class="tbub"><div class="tdots"><span></span><span></span><span></span></div></div>`;
  box.appendChild(row);
  box.scrollTop = box.scrollHeight;
}
function hideTyping() { const el = document.getElementById('typing'); if (el) el.remove(); }

/* a small human-feeling pause that scales with reply length */
function humanDelay(message) {
  const len = String(message || '').length;
  return Math.min(1500, 350 + len * 6);
}

/* ── Mood-aware openers ──
   A short warm line Becca prepends based on feeling. */
function moodOpener(mood, isFil, name) {
  const who = name ? (isFil ? `${name}, ` : `${name}, `) : '';
  if (mood === 'frustrated') {
    return isFil
      ? pick([
          `Naiintindihan ko, ${who}nakaka-stress talaga 'to. 😟 Ayusin natin agad.`,
          `Pasensya na sa abala, ${who}gawin nating mabilis 'to.`,
          `Grabe, ${who}sana hindi na maulit 'yan. Tulungan kita ngayon.`
        ])
      : pick([
          `I hear you, ${who}that's genuinely frustrating. 😟 Let's sort it out fast.`,
          `Sorry you're dealing with this, ${who}let's get it handled.`,
          `Ugh, ${who}I get it — let me help you right away.`
        ]);
  }
  if (mood === 'urgent') {
    return isFil
      ? pick([
          `Mukhang urgent 'to, ${who}— kung may usok, amoy sunog, o kuryente, agad-agad i-unplug at tawagan ang facilities/security. 🚨`,
          `Sige, ${who}prayoridad natin 'to.`
        ])
      : pick([
          `This sounds urgent, ${who}— if there's smoke, a burning smell, or sparks, unplug it and call facilities/security right away. 🚨`,
          `Okay, ${who}let's treat this as a priority.`
        ]);
  }
  if (mood === 'happy') {
    return isFil
      ? pick([`Salamat din! 😊`, `Walang anuman, ${who}masaya akong nakatulong! 🙌`, `Ayos! Kayang-kaya natin 'yan. 😄`])
      : pick([`You're so welcome! 😊`, `Glad I could help, ${who}🙌`, `Anytime! Happy to help. 😄`]);
  }
  return '';
}

/* prepend an opener cleanly */
function withOpener(opener, body) {
  if (!opener) return body;
  return opener + '\n\n' + body;
}

function fallbackReply(text) {
  const q = String(text || '').toLowerCase();
  const isFil = lang === 'fil' || /\b(paano|kumusta|salamat|hindi|sira|aircon|gumana|report|ticket|ayos)\b/i.test(text);
  const name  = memory.userName;
  const mood  = memory.mood;
  const opener = moodOpener(mood, isFil, name);

  const wrap = (message, chips = [], suggest = false, topic = null) => {
    if (topic) { memory.lastTopic = topic; if (!memory.troubleshotTopics.includes(topic)) memory.troubleshotTopics.push(topic); }
    return { message: withOpener(opener, message), chips, suggest };
  };

  /* identity / consciousness questions — honest but warm */
  if (isIdentityQuestion(text)) {
    return wrap(
      isFil
        ? `Ako si ${persona.name} 😊 — isang AI assistant na ginawa para sa BEC Support. Hindi ako tao at wala akong tunay na damdamin, pero sinanay ako para maging matulungin, mabait, at maunawain. Ituring mo akong katuwang na laging handang tumulong sa equipment issues. Ano'ng maitutulong ko?`
        : `I'm ${persona.name} 😊 — an AI assistant built for BEC Support. I'm not a human and I don't have real feelings or consciousness, but I'm designed to be genuinely helpful, warm, and to actually understand what you need. Think of me as a reliable buddy for equipment issues. So — what can I help you with?`,
      chipSet(['report_projector', 'computer', 'ac', 'track'], isFil ? 'fil' : 'en')
    );
  }

  /* greetings / small talk */
  if (/\b(hello|hi|hey|kumusta|kamusta|good morning|good afternoon|good evening|yo|sup)\b/i.test(q)) {
    const hi = isFil
      ? pick([
          `Kumusta${name ? ', ' + name : ''}! 👋 Nandito ako para tumulong sa troubleshooting, pag-submit ng report, at pag-track ng ticket. Anong equipment issue meron ka?`,
          `Hi${name ? ', ' + name : ''}! 😊 Sabihin mo lang kung anong kagamitan ang may problema, aasikasuhin natin.`
        ])
      : pick([
          `Hey${name ? ', ' + name : ''}! 👋 I'm here for troubleshooting, submitting reports, and tracking tickets. What equipment is giving you trouble?`,
          `Hi${name ? ', ' + name : ''}! 😊 Just tell me what's acting up and we'll figure it out together.`
        ]);
    return wrap(hi, chipSet(['report_projector', 'track', 'computer', 'ac'], isFil ? 'fil' : 'en'));
  }

  /* pure thanks (no other topic) */
  if (/\b(thank you|thanks|salamat|ty|appreciate)\b/i.test(q) && !/\b(projector|computer|aircon|ac|report|track|submit)\b/i.test(q)) {
    return wrap(
      isFil
        ? pick([`Walang anuman${name ? ', ' + name : ''}! 😊 Andito lang ako kung may iba ka pang kailangan.`, `Kahit kailan${name ? ', ' + name : ''}! Ingat ka. 🙌`])
        : pick([`You're very welcome${name ? ', ' + name : ''}! 😊 I'm right here if anything else comes up.`, `Anytime${name ? ', ' + name : ''}! Take care. 🙌`]),
      chipSet(['report_projector', 'track', 'submit'], isFil ? 'fil' : 'en')
    );
  }

  if (/\b(projector|lcd|screen|display)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Para sa projector issue, subukan muna ito:\n- I-check ang power cable at HDMI/VGA connection\n- Pindutin ang Source/Input\n- I-restart ang projector at hintayin mag-warm up\nKung wala pa rin, gumawa ng defect report at ilagay ang room at equipment reference.'
        : 'For a projector issue, try these first:\n- Check the power cable and HDMI/VGA connection\n- Press Source/Input\n- Restart the projector and allow it to warm up\nIf it still fails, submit a defect report and include the room and equipment reference.',
      chipSet(['report_projector', 'submit', 'track'], isFil ? 'fil' : 'en'),
      true, 'projector'
    );
  }

  if (/\b(computer|pc|desktop|laptop|won't start|wont start|boot)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Kung hindi nagbubukas ang computer:\n- I-check kung naka-on ang power strip\n- I-hold ang power button ng 10 seconds\n- I-check ang power cable at monitor connection\nKapag hindi pa rin gumana, i-report ito kasama ang PC tag o equipment ID.'
        : 'If the computer will not start:\n- Check whether the power strip is on\n- Hold the power button for 10 seconds\n- Check the power cable and monitor connection\nIf it still will not work, report it with the PC tag or equipment ID.',
      chipSet(['computer', 'submit', 'track'], isFil ? 'fil' : 'en'),
      true, 'computer'
    );
  }

  if (/\b(ac|aircon|air con|cooling|not cooling)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Kung hindi lumalamig ang aircon:\n- Siguraduhing nasa Cool mode ito\n- I-check ang thermostat setting\n- Tingnan kung may bara ang vents\nKung mainit pa rin ang hangin o may tagas/ingay, i-submit na ito bilang report.'
        : 'If the AC is not cooling:\n- Make sure it is set to Cool mode\n- Check the thermostat setting\n- See whether the vents are blocked\nIf it still blows warm air or has leaks/noise, submit a report.',
      chipSet(['ac', 'submit', 'timeline'], isFil ? 'fil' : 'en'),
      true, 'ac'
    );
  }

  if (/\b(track|ticket|report id|status)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Maaari mong i-track ang report gamit ang ticket number, equipment ID, o asset tag sa `track_report.php`. Makikita roon ang report status at equipment status.'
        : 'You can track a report using the ticket number, equipment ID, or asset tag in `track_report.php`. It will show both the report status and the equipment status.',
      chipSet(['track', 'timeline', 'submit'], isFil ? 'fil' : 'en'),
      false, 'track'
    );
  }

  if (/\b(submit|report|paano mag-report|how to report|file a report)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Para mag-submit ng report:\n- Ilagay ang pangalan at email sa reporter portal\n- Piliin ang tamang equipment mula sa listahan\n- Ilagay ang location at malinaw na description\n- I-submit para makakuha ng ticket number sa screen at email'
        : 'To submit a report:\n- Enter your name and email in the reporter portal\n- Choose the correct equipment from the list\n- Fill in the location and a clear description\n- Submit to get your ticket number on screen and by email',
      chipSet(['submit', 'report_projector', 'track'], isFil ? 'fil' : 'en'),
      true, 'submit'
    );
  }

  if (/\b(repair|timeline|how long|gaano katagal)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Karaniwang repair timeline:\n- Minor issues: 1-2 working days\n- Major repairs: 3-7 working days\n- Critical safety issues: dapat ma-escalate agad\nMaaari mong i-check ang latest status sa tracking page.'
        : 'Typical repair timeline:\n- Minor issues: 1-2 working days\n- Major repairs: 3-7 working days\n- Critical safety issues: should be escalated immediately\nYou can check the latest status on the tracking page.',
      chipSet(['timeline', 'track', 'submit'], isFil ? 'fil' : 'en'),
      false, 'timeline'
    );
  }

  /* default — warm, context-aware (mentions what we last talked about) */
  const follow = memory.lastTopic
    ? (isFil ? ` Kanina, pinag-usapan natin ang ${memory.lastTopic} — balikan natin 'yon kung gusto mo.` : ` Earlier we were on your ${memory.lastTopic} — happy to pick that back up too.`)
    : '';
  return wrap(
    (isFil
      ? `Maaari kitang tulungan sa projector, computer, aircon, pag-submit ng defect report, at pag-track ng ticket. Ilarawan mo lang ang problema at bibigyan kita ng susunod na hakbang.`
      : `I can help with projector, computer, and AC issues, plus submitting defect reports and tracking tickets. Just describe what's happening and I'll point you to the next step.`) + follow,
    chipSet(['report_projector', 'computer', 'ac', 'track'], isFil ? 'fil' : 'en')
  );
}

/* send */
async function send() {
  if (busy) return;
  const ci   = document.getElementById('ci');
  const text = ci.value.trim();
  if (!text) return;
  setLang(detectInputLang(text));

  /* update Becca's awareness BEFORE replying */
  memory.turns += 1;
  memory.mood = readMood(text);
  captureName(text);

  ci.value = ''; ci.style.height = 'auto';
  addMsg('u', text, [], false);
  history.push({ role: 'user', content: text });

  busy = true;
  document.getElementById('sbtn').disabled = true;
  showTyping();

  try {
    /* give the server the persona + memory so the AI can stay in character */
    const res = await fetch(PROXY, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        messages: history,
        context: {
          persona: persona.name,
          lang,
          userName: memory.userName,
          mood: memory.mood,
          lastTopic: memory.lastTopic,
          turns: memory.turns
        }
      })
    });

    const data = await res.json();

    if (!res.ok || data.error) throw new Error(data.error || 'Server error ' + res.status);

    const clean   = (data.reply || '').replace('[SUGGEST_REPORT]', '').trim();
    const suggest = !!data.suggest;
    const chips   = Array.isArray(data.chips) ? data.chips : [];
    const actions = Array.isArray(data.actions) ? data.actions : [];
    if (!clean) throw new Error('Empty AI reply');

    history.push({ role: 'assistant', content: data.reply || clean });
    hideTyping();
    addMsg('b', clean, chips, suggest, actions);

  } catch (err) {
    /* offline brain — now mood- and memory-aware */
    const offline = fallbackReply(text);
    setTimeout(() => {
      hideTyping();
      history.push({ role: 'assistant', content: offline.message });
      addMsg('b', offline.message, offline.chips, offline.suggest);
    }, humanDelay(offline.message));
    console.error('[BEC Chat]', err);
  }

  busy = false;
  document.getElementById('sbtn').disabled = false;
  document.getElementById('ci').focus();
}

function quickSend(t) { document.getElementById('ci').value = t; send(); }
function handleKey(e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } }
function grow(el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 88) + 'px'; }
function now() { return new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }); }
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escAttr(s) { return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

/* Data Privacy Notice expander */
(function () {
  var btn = document.getElementById('pvToggle');
  var panel = document.getElementById('pvPanel');
  var label = document.getElementById('pvToggleText');
  if (!btn || !panel) return;
  btn.addEventListener('click', function () {
    var open = panel.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (label) label.textContent = open ? 'Hide the full notice' : 'Read the full notice';
  });
})();

</script>

<?php require __DIR__ . '/includes/site_ui.php'; ?>
<?php require __DIR__ . '/includes/csrf_inject.php'; ?>
<script src="assets/input_guard.js" defer></script>
<?php /* ?v=<mtime> so a changed loader reaches returning visitors: Apache sends
         no Cache-Control for these assets, so browsers happily serve a stale
         copy and the sign-in screen appears not to have changed at all. */ ?>
<script src="assets/auth_loader.js?v=<?php echo @filemtime(__DIR__ . '/assets/auth_loader.js') ?: '2'; ?>"></script>
</body>
</html>
