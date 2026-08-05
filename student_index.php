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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Without this, another site could sign a visitor into the portal under a
    // name and email of its choosing, and any report they filed afterwards
    // would carry that identity. Shown as a form error, not a bare 403.
    if (!csrf_check()) {
        $error = 'Your session expired. Please check your details and sign in again.';
    }
    $name  = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    // Nothing limited how often this form could be submitted, and its failure
    // message said outright whether an address was in the college directory —
    // between them, an easy way to walk a guessed list of names and learn which
    // ones are real people at BEC. BEC addresses follow firstname.lastname, so
    // that list is cheap to generate.
    if ($error === '') {
        try {
            RateLimiter::enforce('reporter_signin:' . RateLimiter::clientIp(), 12, 900);
        } catch (\Throwable $e) {
            $error = 'Too many sign-in attempts from this connection. Please wait a few minutes and try again.';
        }
    }

    // Verify against the official BEC directory (#1). If a directory has been
    // imported, the email must exist in it. Otherwise fall back to the @bec.edu.ph domain.
    $allowedDomain = '@bec.edu.ph';
    $emailLower = strtolower($email);
    $dirCount = function_exists('becdir_count') ? becdir_count() : 0;
    // Staff (PMO, technicians, faculty) hold BEC accounts without always
    // appearing in the imported student directory. They are among the most
    // likely people to spot broken equipment, so a system account counts as
    // valid identification here just as a directory entry does.
    $knownToBec = $dirCount > 0
        ? (becdir_email_exists($emailLower)
           || (function_exists('becdir_is_system_user') && becdir_is_system_user($emailLower)))
        : (substr($emailLower, -strlen($allowedDomain)) === $allowedDomain);

    if ($error !== '') {
        // CSRF or the rate limit already rejected this — fall through to redisplay.
    } elseif (empty($name) || empty($email)) {
        $error = 'Please enter both your name and email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!$knownToBec) {
        // One message for "not in the directory" and for "not a BEC address":
        // told apart, the two answers confirm which addresses exist.
        $error = 'We could not verify that email against official Batangas Eastern Colleges records. '
               . 'Please check your @bec.edu.ph address, or contact the Property Management Office if you believe this is a mistake.';
    } elseif (strlen($name) < 2) {
        $error = 'Please enter your full name.';
    } elseif (empty($_POST['privacy_consent'])) {
        $error = 'Please read and accept the Data Privacy notice to continue.';
    } else {
        // The typed name is not evidence of anything — the portal never checked
        // it, so "any name + a classmate's address" was accepted and the report
        // carried the classmate's identity. Where BEC holds a name on file, that
        // is the one used, and the field stops being a way to claim to be
        // someone else.
        $onFile = function_exists('becdir_known_name') ? trim(becdir_known_name($emailLower)) : '';
        $_SESSION['guest_name']      = htmlspecialchars($onFile !== '' ? $onFile : $name, ENT_QUOTES, 'UTF-8');
        $_SESSION['guest_email']     = $emailLower;
        // Recorded so the PMO can tell a verified reporter from a self-declared
        // one when a report looks doubtful.
        $_SESSION['guest_name_source'] = $onFile !== '' ? 'directory' : 'self-declared';
        $_SESSION['guest_since']     = time();
        $_SESSION['guest_last']      = time();

        // A session id handed out before sign-in must not survive it, or an id
        // planted in advance would still be valid once it carries an identity.
        session_regenerate_id(true);

        RateLimiter::clear('reporter_signin:' . RateLimiter::clientIp());
        header('Location: student_dashboard.php' . ($eq !== '' ? '?eq=' . urlencode($eq) : ''));
        exit();
    }
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
:root {
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
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
body {
  font-family: 'DM Sans', sans-serif;
  background: var(--paper);
  min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding: 1.5rem; position: relative; overflow-x: hidden;
}
html { -webkit-text-size-adjust: 100%; }
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
  padding: 2.75rem 2.4rem 2.4rem;
  background:
    radial-gradient(120% 80% at 0% 0%, rgba(201,150,12,.20) 0%, transparent 42%),
    linear-gradient(155deg, rgba(45,5,5,.90) 0%, rgba(74,14,14,.80) 55%, rgba(123,29,29,.72) 100%),
    url('assets/Landing Page Background.jpg') center / cover no-repeat,
    url('assets/bec-background.jpg') center / cover no-repeat;
  display: flex; flex-direction: column;
}
.brand::after {
  content: ''; position: absolute; inset: 0; pointer-events: none;
  background-image: radial-gradient(circle, rgba(255,255,255,.06) 1px, transparent 1px);
  background-size: 22px 22px;
  -webkit-mask-image: radial-gradient(ellipse 90% 70% at 28% 18%, #000 0%, transparent 75%);
  mask-image: radial-gradient(ellipse 90% 70% at 28% 18%, #000 0%, transparent 75%);
}
.brand-top { position: relative; z-index: 1; display: flex; align-items: center; gap: .75rem; }
.brand-home { margin-left: auto; display: inline-flex; align-items: center; gap: .42rem; flex-shrink: 0;
  padding: .58rem .92rem; min-height: 42px; border-radius: 11px; white-space: nowrap;
  background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.26); color: #fff;
  font-size: .8rem; font-weight: 600; text-decoration: none;
  -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px);
  transition: background .16s, transform .16s, border-color .16s; }
.brand-home:hover { background: rgba(255,255,255,.22); border-color: rgba(255,255,255,.45); transform: translateY(-1px); }
.brand-home i { color: #F0C040; font-size: .82rem; }
.brand-seal {
  width: 46px; height: 46px; border-radius: 50%; flex-shrink: 0; background: #fff;
  display: flex; align-items: center; justify-content: center; overflow: hidden;
  box-shadow: 0 0 0 4px rgba(201,150,12,.3);
}
.brand-seal img { width: 100%; height: 100%; object-fit: cover; display: block; }
.brand-top strong { display: block; font-size: .92rem; font-weight: 700; letter-spacing: -.01em; }
.brand-top span { font-size: .62rem; text-transform: uppercase; letter-spacing: 1.8px; color: rgba(255,255,255,.6); }
.brand-hero { position: relative; z-index: 1; margin-top: 2.4rem; }
.brand-tag {
  display: inline-flex; align-items: center; gap: .4rem; margin-bottom: 1rem;
  padding: .32rem .72rem; border-radius: 20px; font-size: .63rem; font-weight: 600;
  letter-spacing: .5px; text-transform: uppercase;
  background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.16); color: rgba(255,255,255,.85);
}
.brand-hero h2 {
  font-family: 'Fraunces', serif; font-weight: 700; font-size: 1.9rem; line-height: 1.16;
  letter-spacing: -.02em; margin-bottom: .7rem;
}
.brand-hero h2 em { font-style: italic; color: var(--gold); }
.brand-hero p { font-size: .83rem; line-height: 1.65; color: rgba(255,255,255,.72); max-width: 34ch; }
.brand-feats { position: relative; z-index: 1; margin-top: auto; padding-top: 2rem; display: flex; flex-direction: column; gap: .8rem; }
.brand-feat { display: flex; align-items: center; gap: .7rem; font-size: .8rem; color: rgba(255,255,255,.9); }
.brand-feat .bf-ic {
  width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center; font-size: .76rem;
  background: rgba(201,150,12,.18); border: 1px solid rgba(201,150,12,.32); color: var(--gold);
}
.brand-foot {
  position: relative; z-index: 1; margin-top: 1.9rem; padding-top: 1.2rem;
  border-top: 1px solid rgba(255,255,255,.12);
  font-size: .66rem; color: rgba(255,255,255,.5); line-height: 1.6;
}

/* brand process timeline — communicates the institutional workflow */
.brand-process { position: relative; z-index: 1; margin-top: auto; padding-top: 2.1rem; }
.bp-label { display: flex; align-items: center; gap: .45rem; margin-bottom: 1.05rem; font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.6px; color: rgba(255,255,255,.52); }
.bp-label i { color: var(--gold); font-size: .66rem; }
.brand-steps { position: relative; display: flex; flex-direction: column; gap: 1.05rem; }
.brand-steps::before { content: ''; position: absolute; left: 12.5px; top: 8px; bottom: 8px; width: 1.5px; background: linear-gradient(rgba(201,150,12,.55), rgba(255,255,255,.05)); }
.bs-item { position: relative; display: flex; gap: .85rem; align-items: flex-start; }
.bs-num { width: 26px; height: 26px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-family: 'Fraunces', serif; font-size: .76rem; font-weight: 700; color: var(--gold); background: var(--maroon-dd); border: 1.5px solid rgba(201,150,12,.5); position: relative; z-index: 1; }
.bs-item b { display: block; font-size: .82rem; font-weight: 600; color: #fff; letter-spacing: -.01em; }
.bs-item i { display: block; font-style: normal; font-size: .715rem; color: rgba(255,255,255,.6); line-height: 1.5; margin-top: .12rem; }

/* form eyebrow + trust strip */
.panel-eyebrow { display: inline-flex; align-items: center; gap: .5rem; margin-bottom: .7rem; font-size: .64rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.6px; color: var(--maroon); }
.panel-eyebrow .pe-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--gold); box-shadow: 0 0 0 3px var(--maroon-soft); }
.safety-note { display: flex; align-items: flex-start; gap: .6rem; background: #FFF6F5; border: 1px solid #F3D2CD; border-left: 3px solid #C0392B; border-radius: 10px; padding: .7rem .9rem; margin-bottom: 1rem; font-size: .73rem; color: var(--ink2); line-height: 1.55; }
.safety-note i { color: #C0392B; font-size: .82rem; margin-top: .1rem; flex-shrink: 0; }
.safety-note strong { color: #96271B; }
.trust-strip { display: flex; flex-wrap: wrap; justify-content: center; gap: .4rem 1.1rem; margin-bottom: 1rem; }
.trust-strip span { display: inline-flex; align-items: center; gap: .35rem; font-size: .66rem; color: var(--ink3); font-weight: 500; }
.trust-strip i { color: var(--gold); font-size: .62rem; }

/* RIGHT — form panel */
.panel { padding: 2.7rem 2.6rem 2.2rem; display: flex; flex-direction: column; }
.panel-title {
  font-family: 'Fraunces', serif; font-size: 1.55rem; font-weight: 700; color: var(--ink);
  line-height: 1.18; letter-spacing: -.02em; margin-bottom: .4rem;
}
.panel-title em { font-style: italic; color: var(--maroon); }
.panel-sub { font-size: .9rem; color: var(--ink3); line-height: 1.6; margin-bottom: 1.5rem; overflow-wrap: anywhere; }
.pills { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: 1.5rem; }
.pill {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .28rem .62rem; background: var(--maroon-soft);
  border: 1px solid rgba(123,29,29,.12); border-radius: 20px;
  font-size: .68rem; color: var(--maroon); font-weight: 500;
}
.pill i { font-size: .6rem; }
.notice {
  display: flex; align-items: flex-start; gap: .6rem;
  background: var(--gold-bg); border: 1px solid rgba(201,150,12,.25);
  border-left: 3px solid var(--gold); border-radius: 10px;
  padding: .7rem .9rem; margin-bottom: 1.6rem;
  font-size: .76rem; color: var(--ink2); line-height: 1.55;
}
.notice i { color: var(--gold); font-size: .78rem; margin-top: .12rem; flex-shrink: 0; }
.fg { margin-bottom: 1.1rem; }
.fl {
  display: block; font-size: .72rem; font-weight: 600;
  color: var(--ink2); margin-bottom: .4rem; text-transform: uppercase; letter-spacing: .9px;
}
.fl .req { color: var(--maroon); margin-left: .12rem; }
.fi-wrap { position: relative; }
.fi-icon {
  position: absolute; left: .9rem; top: 50%; transform: translateY(-50%);
  color: var(--ink3); font-size: .78rem; pointer-events: none; transition: color .18s;
}
.fi-wrap:focus-within .fi-icon { color: var(--maroon); }
.fi {
  width: 100%; padding: .76rem 1rem .76rem 2.5rem;
  border: 1.5px solid var(--border); border-radius: 11px;
  font-family: 'DM Sans', sans-serif; font-size: 1rem; color: var(--ink);
  background: #fff; transition: border-color .18s, box-shadow .18s;
  outline: none; -webkit-appearance: none;
}
.fi:focus { border-color: var(--maroon); box-shadow: 0 0 0 3.5px rgba(123,29,29,.09); }
.fi::placeholder { color: #C4AFA8; font-size: .95rem; }
.fi-hint { font-size: .68rem; color: var(--ink3); margin-top: .28rem; display: block; line-height: 1.55; }
.fi-hint i { margin-right: .3rem; }
.fi-hint strong { color: var(--ink2); white-space: nowrap; }
/* Friendly "about this portal" details (replaces the old button-like pills) */
.intro-card { background: #FBF8F1; border: 1px solid var(--border); border-radius: 14px; padding: 1rem 1.1rem 1.05rem; margin-bottom: 1.5rem; }
.intro-card h3 { font-family: 'Fraunces', serif; font-size: .95rem; font-weight: 600; color: var(--ink); margin-bottom: .7rem; display: flex; align-items: center; gap: .45rem; }
.intro-card h3 i { color: var(--gold); font-size: .85rem; }
.intro-list { display: flex; flex-direction: column; gap: .62rem; }
.intro-list li { list-style: none; display: flex; align-items: flex-start; gap: .62rem; font-size: .78rem; color: var(--ink2); line-height: 1.55; }
.intro-list > li > i { color: var(--maroon); font-size: .8rem; margin-top: .2rem; flex-shrink: 0; width: 1.05rem; text-align: center; }
.intro-list b { color: var(--ink); font-weight: 600; }
.alert {
  padding: .7rem .9rem; border-radius: 10px; font-size: .78rem; line-height: 1.5;
  margin-bottom: 1.1rem; display: flex; align-items: flex-start; gap: .5rem;
}
.alert-err { background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B; }
.alert-note { background: #FFFBEF; border: 1px solid rgba(201,150,12,.35); color: #7A5A00; }
.alert i { font-size: .8rem; margin-top: .1rem; flex-shrink: 0; }

/* ── Data Privacy Notice: short consent line + expandable full text ──
   The full notice used to sit open above the submit button, pushing it off
   screen on a phone. The summary carries the consent; the panel holds the
   detail for anyone who wants it. */
.pv-block {
  margin: .9rem 0 1rem; border-radius: 12px;
  background: #FBF8F1; border: 1px solid var(--border); overflow: hidden;
}
.pv-consent {
  display: flex; align-items: flex-start; gap: .6rem;
  padding: .75rem .9rem .6rem;
  font-size: .74rem; color: var(--ink2); line-height: 1.6; cursor: pointer;
}
.pv-consent input {
  width: 16px; height: 16px; flex-shrink: 0; margin-top: .15rem; accent-color: #7B1D1D;
}
.pv-consent strong { color: var(--ink); }
.pv-toggle {
  display: flex; align-items: center; gap: .45rem; width: 100%;
  padding: .5rem .9rem .68rem; background: none; border: none;
  font-family: 'DM Sans', sans-serif; font-size: .7rem; font-weight: 700;
  letter-spacing: .04em; text-transform: uppercase;
  color: var(--maroon); cursor: pointer; text-align: left;
}
.pv-toggle .chev { margin-left: auto; font-size: .66rem; transition: transform .28s ease; }
.pv-toggle[aria-expanded="true"] .chev { transform: rotate(180deg); }
.pv-toggle:hover { color: var(--maroon-d); }
.pv-toggle:focus-visible { outline: 2px solid var(--gold); outline-offset: -2px; border-radius: 8px; }
.pv-panel {
  max-height: 0; opacity: 0; overflow: hidden;
  transition: max-height .34s ease, opacity .26s ease;
}
.pv-panel.open { max-height: 620px; opacity: 1; }
.pv-inner {
  padding: 0 .9rem .85rem; font-size: .72rem; color: var(--ink2); line-height: 1.65;
  border-top: 1px solid var(--border); padding-top: .75rem; margin: 0 0 0;
}
.pv-inner dt {
  font-size: .64rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase;
  color: var(--maroon); margin-top: .7rem;
}
.pv-inner dt:first-child { margin-top: 0; }
.pv-inner dd { margin: .2rem 0 0; }
.pv-inner a { color: var(--maroon); font-weight: 600; }
@media (prefers-reduced-motion: reduce) {
  .pv-panel { transition: none; }
  .pv-toggle .chev { transition: none; }
}
.btn-submit {
  width: 100%; margin-top: 1.4rem; padding: .88rem 1.5rem;
  background: var(--maroon-d); color: #fff; border: none; border-radius: 11px;
  font-family: 'DM Sans', sans-serif; font-size: .95rem; font-weight: 600; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: .55rem;
  transition: all .22s cubic-bezier(.22,1,.36,1);
  box-shadow: 0 4px 0 var(--maroon-dd), 0 8px 20px rgba(74,14,14,.25);
  letter-spacing: -.01em; -webkit-appearance: none;
}
.btn-submit:hover { background: var(--maroon); transform: translateY(-2px); box-shadow: 0 6px 0 var(--maroon-dd), 0 14px 28px rgba(74,14,14,.3); }
.btn-submit:active { transform: translateY(1px); box-shadow: 0 2px 0 var(--maroon-dd), 0 4px 10px rgba(74,14,14,.2); }
.btn-arrow {
  width: 20px; height: 20px; background: rgba(255,255,255,.18); border-radius: 50%;
  display: flex; align-items: center; justify-content: center; font-size: .65rem; transition: transform .2s;
}
.btn-submit:hover .btn-arrow { transform: translateX(3px); }
.or-row {
  display: flex; align-items: center; gap: .75rem; margin: 1.35rem 0;
  font-size: .67rem; color: var(--ink3); text-transform: uppercase; letter-spacing: 1.5px;
}
.or-row::before, .or-row::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.action-row { display: grid; grid-template-columns: 1fr 1fr; gap: .65rem; margin-bottom: 1.25rem; }
.action-btn {
  display: flex; flex-direction: column; align-items: center; gap: .35rem;
  padding: .75rem .6rem; border: 1.5px solid var(--border); border-radius: 11px;
  color: var(--ink2); font-size: .74rem; font-weight: 500;
  text-decoration: none; transition: all .18s; background: #fff; text-align: center; line-height: 1.3;
}
.action-btn i { font-size: .95rem; color: var(--maroon); opacity: .75; transition: opacity .18s, transform .18s; }
.action-btn:hover { border-color: var(--maroon); color: var(--maroon); background: var(--maroon-soft); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(123,29,29,.08); }
.action-btn:hover i { opacity: 1; transform: scale(1.1); }
.footer-link { text-align: center; font-size: .73rem; color: var(--ink3); padding-top: .5rem; border-top: 1px solid var(--border); }
.footer-link a { color: var(--maroon); font-weight: 600; text-decoration: none; margin-left: .25rem; cursor: pointer; }
.footer-link a:hover { text-decoration: underline; }

/* ══ FLOATING CHAT LAUNCHER (left side) ══ */
#chatFab {
  position: fixed; left: 1.35rem; bottom: 1.35rem; z-index: 9997;
  display: flex; align-items: center; justify-content: center;
  width: 62px; height: 62px; padding: 0;
  background: linear-gradient(135deg, rgba(74,14,14,.9), rgba(45,5,5,.9));
  -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
  border: 2px solid rgba(201,150,12,.6);
  border-radius: 50%; cursor: pointer;
  box-shadow: 0 10px 30px rgba(44,10,10,.4), 0 0 20px rgba(201,150,12,.25);
  transition: transform .25s cubic-bezier(.22,1,.36,1), box-shadow .3s ease;
  animation: fabFloat 6s ease-in-out infinite, fabGlow 4.5s ease-in-out infinite;
}
#chatFab:hover { animation: none; transform: translateY(-4px) scale(1.06); box-shadow: 0 16px 40px rgba(44,10,10,.5), 0 0 32px rgba(201,150,12,.5); }
#chatFab:active { transform: translateY(-1px) scale(1); }
#chatFab .fab-ic {
  width: 46px; height: 46px; border-radius: 50%; flex-shrink: 0; overflow: hidden;
  background: rgba(255,255,255,.14); border: 1.5px solid rgba(255,255,255,.25);
  display: flex; align-items: center; justify-content: center; position: relative;
  box-shadow: 0 0 14px rgba(201,150,12,.35);
}
#chatFab .fab-ic img { width: 100%; height: 100%; object-fit: cover; display: block; }
#chatFab .fab-ic i { color: #fff; font-size: 1.05rem; }
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
  padding: 1.25rem;
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
  background: var(--maroon-d); padding: .9rem 1rem .88rem;
  display: flex; align-items: center; gap: .7rem; flex-shrink: 0;
}
.ch-av {
  width: 37px; height: 37px; border-radius: 50%;
  background: rgba(255,255,255,.14); border: 1.5px solid rgba(255,255,255,.18);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; position: relative;
}
.ch-av i { color: #fff; font-size: .9rem; }
.ch-av-dot {
  position: absolute; bottom: 1px; right: 1px;
  width: 9px; height: 9px; border-radius: 50%;
  background: #4ade80; border: 2px solid var(--maroon-d);
}
.ch-info { flex: 1; min-width: 0; }
.ch-name { font-size: .88rem; font-weight: 600; color: #fff; letter-spacing: -.01em; }
.ch-status { font-size: .64rem; color: rgba(255,255,255,.55); margin-top: 1px; display: flex; align-items: center; gap: .3rem; }
.ch-status::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: #4ade80; flex-shrink: 0; }
.ch-btns { display: flex; gap: .38rem; }
.ch-btn {
  width: 29px; height: 29px; border-radius: 7px;
  background: rgba(255,255,255,.1); border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: rgba(255,255,255,.65); font-size: .72rem; transition: background .14s, color .14s;
}
.ch-btn:hover { background: rgba(255,255,255,.2); color: #fff; }
.lbar {
  padding: .48rem .88rem; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: .5rem; flex-shrink: 0;
}
.lbar-lbl { font-size: .62rem; color: var(--ink3); text-transform: uppercase; letter-spacing: .9px; font-weight: 600; }
.lbtn {
  padding: .2rem .62rem; border-radius: 20px; font-size: .64rem; font-weight: 600;
  border: 1.5px solid var(--border); background: none; cursor: pointer;
  color: var(--ink3); font-family: 'DM Sans', sans-serif; transition: all .14s;
}
.lbtn.on { border-color: var(--maroon); color: var(--maroon); background: var(--maroon-soft); }
.msgs {
  flex: 1; overflow-y: auto; padding: .88rem;
  display: flex; flex-direction: column; gap: .62rem; scroll-behavior: smooth;
}
.msgs::-webkit-scrollbar { width: 3px; }
.msgs::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
.mrow {
  display: flex; gap: .48rem; align-items: flex-end; max-width: 90%;
  animation: mIn .18s ease both;
}
@keyframes mIn { from { opacity:0; transform:translateY(5px); } to { opacity:1; transform:none; } }
.mrow.u { align-self: flex-end; flex-direction: row-reverse; }
.mrow.b { align-self: flex-start; }
.mav {
  width: 25px; height: 25px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; font-size: .6rem;
}
.mrow.b .mav { background: var(--maroon); color: #fff; }
.mrow.u .mav { background: var(--maroon-d); color: rgba(255,255,255,.7); }
.mcol { display: flex; flex-direction: column; max-width: 100%; }
.mbub {
  padding: .58rem .82rem; border-radius: 14px;
  font-size: .81rem; line-height: 1.62; word-break: break-word;
}
.mrow.b .mbub { background: #f4ede4; border: 1px solid var(--border); border-bottom-left-radius: 4px; color: var(--ink); }
.mrow.u .mbub { background: var(--maroon-d); color: #fff; border-bottom-right-radius: 4px; }
.mtime { font-size: .6rem; color: var(--ink3); margin-top: .2rem; padding: 0 .15rem; }
.mrow.u .mtime { text-align: right; }
.trow { align-self: flex-start; display: flex; gap: .48rem; align-items: flex-end; }
.tbub { padding: .52rem .82rem; background: #f4ede4; border: 1px solid var(--border); border-radius: 14px; border-bottom-left-radius: 4px; }
.tdots { display: flex; gap: 3px; align-items: center; }
.tdots span { width: 5px; height: 5px; border-radius: 50%; background: var(--ink3); animation: db 1.3s infinite; }
.tdots span:nth-child(2) { animation-delay: .2s; }
.tdots span:nth-child(3) { animation-delay: .4s; }
@keyframes db { 0%,80%,100%{opacity:.2;transform:scale(.85)} 40%{opacity:1;transform:scale(1)} }
.chips { display: flex; flex-wrap: wrap; gap: .32rem; margin-top: .48rem; }
.chip {
  font-size: .72rem; padding: .34rem .74rem; border-radius: 18px;
  border: 1px solid rgba(123,29,29,.18); background: rgba(123,29,29,.05);
  color: var(--maroon); cursor: pointer; font-family: 'DM Sans', sans-serif;
  font-weight: 600; transition: all .15s; white-space: nowrap;
  text-transform: none; letter-spacing: 0; line-height: 1.25;
}
.chip:hover { border-color: var(--maroon); color: var(--maroon); background: var(--maroon-soft); transform: translateY(-1px); box-shadow: 0 2px 8px rgba(123,29,29,.12); }
.rcard {
  margin-top: .52rem; padding: .62rem .78rem; border-radius: 10px;
  background: var(--gold-bg); border: 1px solid rgba(201,150,12,.2);
  border-left: 3px solid var(--gold); font-size: .76rem; color: var(--ink2); line-height: 1.5;
}
.rcard strong { display: block; font-size: .74rem; color: var(--maroon-d); margin-bottom: .25rem; font-weight: 600; }
.rcard-btn {
  display: inline-flex; align-items: center; gap: .38rem;
  margin-top: .42rem; padding: .36rem .82rem; border-radius: 7px;
  font-size: .71rem; font-weight: 600; background: var(--maroon);
  color: #fff; border: none; cursor: pointer;
  font-family: 'DM Sans', sans-serif; text-decoration: none; transition: background .13s;
}
.rcard-btn:hover { background: var(--maroon-d); }
.chat-actions { display: flex; flex-wrap: wrap; gap: .38rem; margin-top: .52rem; }
.chat-link {
  display: inline-flex; align-items: center; gap: .34rem;
  padding: .34rem .78rem; border-radius: 999px;
  border: 1.5px solid var(--border); background: var(--surface);
  color: var(--maroon-d); text-decoration: none; font-size: .71rem; font-weight: 600;
  transition: all .14s;
}
.chat-link:hover { border-color: var(--maroon); background: var(--maroon-soft); transform: translateY(-1px); }
.inp {
  padding: .72rem .85rem .78rem; border-top: 1px solid var(--border);
  background: var(--surface); flex-shrink: 0;
}
.inp-row { display: flex; gap: .52rem; align-items: flex-end; }
.inp-wrap { flex: 1; }
.ci {
  width: 100%; padding: .6rem .88rem;
  border: 1.5px solid var(--border); border-radius: 11px;
  font-family: 'DM Sans', sans-serif; font-size: 1rem; color: var(--ink);
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
  color: #fff; font-size: .85rem; flex-shrink: 0;
  box-shadow: 0 3px 0 var(--maroon-dd); transition: all .17s; -webkit-appearance: none;
}
.sbtn:hover { background: var(--maroon); transform: translateY(-1px); box-shadow: 0 4px 0 var(--maroon-dd); }
.sbtn:active { transform: translateY(1px); box-shadow: 0 1px 0 var(--maroon-dd); }
.sbtn:disabled { opacity: .45; cursor: not-allowed; transform: none; }
.inp-meta { margin-top: .38rem; font-size: .62rem; color: var(--ink3); display: flex; align-items: center; gap: .28rem; }
.inp-meta i { font-size: .58rem; }

/* ── RESPONSIVE ── */
@media (max-width: 860px) {
  body { align-items: flex-start; padding-top: 1.25rem; }
  .shell { grid-template-columns: 1fr; max-width: 470px; }
  .brand { padding: 1.9rem 1.7rem 1.7rem; }
  .brand-hero { margin-top: 1.4rem; }
  .brand-hero h2 { font-size: 1.55rem; }
  .brand-feats, .brand-process, .brand-foot { display: none; }
  .panel { padding: 2rem 1.7rem 1.6rem; }
  .panel-title { font-size: 1.45rem; }
  .pills { gap: .35rem; }
  .pill { font-size: .66rem; }
}
@media (max-width: 767px) {
  #chatOverlay { align-items: flex-end; justify-content: center; padding: 0; }
  /* dvh = the *visible* viewport on phones (88vh overflows behind the browser
     URL bar, clipping the header and hiding the input row) */
  #chatModal { max-width: 100%; width: 100%; border-radius: 20px 20px 0 0;
    height: 88vh; height: 88dvh; max-height: 88vh; max-height: 88dvh; }
}
@media (max-width: 560px) {
  /* clearance so the floating Becca orb never covers the footer links */
  body { padding-bottom: 5.6rem; }
}
@media (max-width: 520px) {
  body { padding: 1rem; align-items: flex-start; padding-top: 1rem; padding-bottom: 5.6rem; }
  .shell { border-radius: 20px; }
  /* compact brand header so the form is reachable without a full-screen scroll */
  .brand { padding: 1.4rem 1.3rem 1.3rem; }
  /* keep the header tidy: less letter-spacing + a compact Home button so
     the college name and subtitle don't wrap awkwardly beside it */
  .brand-top { gap: .6rem; }
  .brand-top strong { font-size: .9rem; line-height: 1.2; }
  .brand-top span { letter-spacing: .7px; font-size: .58rem; }
  .brand-home { padding: .5rem .74rem; font-size: .75rem; min-height: 44px; }

  /* ── touch ergonomics on the page most people reach on a phone ──────────
     The privacy checkbox was 16x16 and is mandatory before a report can be
     filed — the smallest, most consequential target on the site. The label
     already wraps it, so the whole row is clickable; this makes the box itself
     big enough to hit, and gives the notice toggle and the footer links the
     44px WCAG 2.5.5 asks for. */
  .pv-consent input { width: 22px; height: 22px; margin-top: 0; }
  .pv-consent { min-height: 44px; align-items: center; }
  .pv-toggle { min-height: 44px; }
  .foot-links a, .footer-link a { min-height: 44px; display: inline-flex; align-items: center; }
  .brand-hero { margin-top: 1rem; }
  .brand-tag { font-size: .58rem; padding: .28rem .6rem; margin-bottom: .7rem; }
  .brand-hero h2 { font-size: 1.35rem; }
  .brand-hero p { font-size: .78rem; max-width: none; }
  .panel { padding: 1.7rem 1.4rem 1.4rem; }
  .panel-title { font-size: 1.4rem; }
  .action-row { grid-template-columns: 1fr; }
}
@media (max-width: 390px) {
  body { padding: .75rem; padding-top: 1rem; }
  .shell { border-radius: 18px; }
  .brand-top strong { font-size: .84rem; }
  .panel-title { font-size: 1.3rem; }
  .panel-sub, .notice, .action-btn, .footer-link { font-size: .72rem; }
  .fi { font-size: 1rem; padding: .72rem .9rem .72rem 2.35rem; }
}
@media (max-height: 720px) {
  body { align-items: flex-start; padding-top: 1rem; padding-bottom: 1rem; }
}

/* ── DESKTOP / LAPTOP FULL-BLEED ──
   min-width rules only: these never touch the mobile view (<=860px). They make
   the layout fill the ENTIRE screen edge-to-edge (no side gaps, full height). */
@media (min-width: 861px) {
  body { padding: 0; align-items: stretch; overflow-x: hidden; }
  .shell {
    max-width: none; width: 100%; min-height: 100vh;
    border-radius: 0; border: none; box-shadow: none;
    grid-template-columns: 1.1fr .9fr;
  }
  .brand { padding: 4rem 4rem 3rem; }            /* left panel fills full height */
  .brand-seal { width: 56px; height: 56px; }
  .brand-top strong { font-size: 1.05rem; }
  .brand-top span { font-size: .68rem; }
  .brand-hero { margin-top: 3.2rem; }
  .brand-tag { font-size: .7rem; padding: .4rem .9rem; }
  .brand-hero h2 { font-size: 2.6rem; }
  .brand-hero p { font-size: .96rem; max-width: 44ch; }
  .brand-feats { gap: 1.05rem; padding-top: 2.6rem; }
  .brand-feat { font-size: .9rem; }
  .brand-feat .bf-ic { width: 36px; height: 36px; font-size: .85rem; }
  .brand-foot { font-size: .72rem; }
  /* right panel: vertically centre the form, cap its width so inputs stay readable */
  .panel { padding: 3.5rem 4rem; justify-content: center; align-items: center; }
  .panel > * { width: 100%; max-width: 440px; }
  .panel-title { font-size: 2rem; }
  .panel-sub { font-size: .94rem; margin-bottom: 1.7rem; }
  .pill { font-size: .73rem; padding: .32rem .72rem; }
  .notice { font-size: .81rem; padding: .82rem 1rem; }
  .fl { font-size: .75rem; }
  .fi { padding: .88rem 1rem .88rem 2.6rem; font-size: .95rem; }
  .fi-icon { font-size: .86rem; left: 1rem; }
  .fi-hint { font-size: .73rem; }
  .btn-submit { padding: 1.02rem 1.5rem; font-size: 1.02rem; }
  .action-btn { font-size: .8rem; padding: .88rem .7rem; }
  .action-btn i { font-size: 1.05rem; }
  .footer-link { font-size: .78rem; }
}
@media (min-width: 1280px) {
  .brand { padding: 4.5rem 5.5rem 3.5rem; }
  .brand-hero { margin-top: 3.6rem; }
  .brand-hero h2 { font-size: 3rem; }
  .brand-hero p { font-size: 1.02rem; }
  .panel-title { font-size: 2.2rem; }
  .panel > * { max-width: 460px; }
}
@media (min-width: 1600px) {
  .brand { padding: 5rem 8rem 4rem; }
  .brand-hero h2 { font-size: 3.3rem; }
}
</style>
</head>
<body>
<div class="bg-grid"></div>

<div class="shell">

  <!-- LEFT — BEC brand panel -->
  <aside class="brand">
    <div class="brand-top">
      <div class="brand-seal"><img src="assets/logs.png" alt="BEC logo"></div>
      <div>
        <strong>Batangas Eastern Colleges</strong>
        <span>Property Management Office</span>
      </div>
      <a class="brand-home" href="index.php"><i class="fas fa-house"></i> Home</a>
    </div>
    <div class="brand-hero">
      <span class="brand-tag"><i class="fas fa-building-shield"></i> Property Management Office · Official Portal</span>
      <h2>Defective Equipment <em>Reporting</em> System</h2>
      <p>The official channel of Batangas Eastern Colleges for reporting, tracking, and resolving campus equipment concerns — administered by the Property Management Office.</p>
    </div>
    <div class="brand-process">
      <div class="bp-label"><i class="fas fa-diagram-project"></i> How your report is handled</div>
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
  <main class="panel">
    <div class="panel-eyebrow"><span class="pe-dot"></span> Equipment Defect Reporting</div>
    <h1 class="panel-title">Report <em>defective campus equipment</em></h1>
    <p class="panel-sub">Welcome to the official Property Management Office portal of Batangas Eastern Colleges. Use this page to report any damaged or malfunctioning equipment on campus. Sign in with your official BEC details below; once you submit, a ticket reference is sent to your email so you can follow your report from review all the way to its resolution.</p>
    <div class="intro-card">
      <h3><i class="fas fa-circle-info"></i> A few things to know</h3>
      <ul class="intro-list">
        <li><i class="fas fa-id-card"></i><span><b>Who may report.</b> Any Batangas Eastern Colleges student, faculty, or staff member with an official <b>@bec.edu.ph</b> email account may file a report.</span></li>
        <li><i class="fas fa-clipboard-list"></i><span><b>What to prepare.</b> Identify the equipment, where it is located, and a short description of the problem. Adding a photo helps the technicians assess it faster.</span></li>
        <li><i class="fas fa-route"></i><span><b>What happens after you submit.</b> The PMO reviews your report, assigns a technician, and carries out the repair. You are updated by email at every key stage and can track the status online at any time.</span></li>
        <li><i class="fas fa-user-shield"></i><span><b>How your information is used.</b> Your details are used only to process and deliver your report, kept confidential in line with the Data Privacy Act of 2012 (RA 10173).</span></li>
      </ul>
    </div>
    <?php if (!$error && isset($_GET['expired'])): ?>
    <div class="alert alert-note">
      <i class="fas fa-clock-rotate-left"></i>
      Your session ended after a period of inactivity. Please sign in again to continue — this keeps your details safe on shared computers.
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-err">
      <i class="fas fa-exclamation-circle"></i>
      <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>
    <form method="POST" action="" id="signinForm" onsubmit="if(window.AuthLoader)AuthLoader.show('Signing you in…','Preparing your report portal…');">
      <input type="hidden" name="eq" value="<?php echo htmlspecialchars($eq, ENT_QUOTES); ?>">
      <?php if ($eq !== ''): ?><div class="notice" style="margin-bottom:.6rem;"><i class="fas fa-qrcode"></i> <span>You scanned an equipment QR — it will be pre-selected after you sign in.</span></div><?php endif; ?>
      <div class="fg">
        <label class="fl">Full Name <span class="req">*</span></label>
        <div class="fi-wrap">
          <i class="fas fa-user fi-icon"></i>
          <input type="text" name="full_name" class="fi" placeholder="e.g. Maria Santos"
            value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
            autocomplete="name" maxlength="80" data-guard="alpha" required>
        </div>
        <div class="fi-hint"><i class="fas fa-id-card"></i> If Batangas Eastern Colleges holds a name for your account, your report will carry that official name.</div>
      </div>
      <div class="fg">
        <label class="fl">Email Address <span class="req">*</span></label>
        <div class="fi-wrap">
          <i class="fas fa-envelope fi-icon"></i>
          <input type="email" name="email" class="fi" placeholder="juan.delacruz@bec.edu.ph"
            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
            autocomplete="email" maxlength="120" required
            pattern="[a-zA-Z0-9._%+\-]+@bec\.edu\.ph$"
            title="Use your official BEC email ending in @bec.edu.ph">
        </div>
        <div class="fi-hint"><i class="fas fa-id-badge"></i> Use your official BEC account (<strong>@bec.edu.ph</strong>). Your ticket confirmation will be sent here.</div>
      </div>
      <div class="pv-block">
        <label class="pv-consent">
          <input type="checkbox" name="privacy_consent" value="1" required <?php echo !empty($_POST['privacy_consent']) ? 'checked' : ''; ?>>
          <span><strong>Data Privacy Notice.</strong> I agree that Batangas Eastern Colleges — Property Management Office may collect and process my <strong>name, email address, and report details</strong> for equipment maintenance and record-keeping, under the <strong>Data Privacy Act of 2012 (RA 10173)</strong>.</span>
        </label>
        <!-- Kept outside the <label> on purpose: inside it, every click would
             also toggle the consent checkbox. -->
        <button type="button" class="pv-toggle" id="pvToggle" aria-expanded="false" aria-controls="pvPanel">
          <i class="fas fa-shield-halved"></i>
          <span id="pvToggleText">Read the full notice</span>
          <i class="fas fa-chevron-down chev"></i>
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
        <span class="btn-arrow"><i class="fas fa-arrow-right"></i></span>
      </button>
    </form>
    <div class="or-row">or</div>
    <div class="action-row">
      <a href="track_report.php" class="action-btn">
        <i class="fas fa-ticket-alt"></i>Track existing report
      </a>
      <a href="public_reports.php" class="action-btn">
        <i class="fas fa-table-list"></i>View all reports
      </a>
    </div>
    <div class="safety-note">
      <i class="fas fa-triangle-exclamation"></i>
      <span><strong>Safety first.</strong> For urgent hazards that put people at risk — live electrical faults, fire, gas, or water leaks — contact the PMO or campus security in person or by phone <em>immediately</em>. Use this portal for non-emergency equipment concerns.</span>
    </div>
    <div class="trust-strip">
      <span><i class="fas fa-lock"></i> Confidential</span>
      <span><i class="fas fa-circle-check"></i> Official @bec.edu.ph only</span>
      <span><i class="fas fa-route"></i> Tracked end-to-end</span>
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
        <button class="ch-btn" onclick="clearChat()" title="New chat" aria-label="Start a new chat"><i class="fas fa-rotate-right"></i></button>
        <button class="ch-btn" onclick="closeChat()" title="Close" aria-label="Close chat"><i class="fas fa-xmark"></i></button>
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
          <i class="fas fa-paper-plane"></i>
        </button>
      </div>
      <div class="inp-meta"><i class="fas fa-shield-alt"></i> AI-powered · BEC knowledge base · Urgent: ext. 215</div>
    </div>

  </div>
</div>


<script>
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

/* open / close */
function openChat() {
  document.getElementById('chatOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
  if (!greeted) { greeted = true; greet(); }
  setTimeout(() => document.getElementById('ci').focus(), 300);
}
function closeChat() {
  document.getElementById('chatOverlay').classList.remove('open');
  document.body.style.overflow = '';
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
        chatActions.map(action => `<a class="chat-link" href="${escAttr(action.href)}"><i class="fas ${escAttr(action.icon || 'fa-arrow-right')}"></i>${esc(action.label || 'Open')}</a>`).join('') +
        '</div>';
    }
  }
  if (suggest) {
    extra += `<div class="rcard">
      <strong><i class="fas fa-file-circle-exclamation" style="margin-right:.28rem"></i>${detectedLang === 'fil' ? 'Mag-submit ng Formal Report' : 'Submit a Formal Report'}</strong>
      ${detectedLang === 'fil'
        ? 'Mukhang kailangan ito ng aktuwal na pagtingin. Gumawa ng opisyal na defect report para sa facilities team.'
        : 'This issue likely needs hands-on attention. Create an official defect report for the facilities team.'}
      <br><a href="student_dashboard.php" class="rcard-btn"><i class="fas fa-plus"></i> ${detectedLang === 'fil' ? 'Gumawa ng Report' : 'Create Report'}</a>
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
      <i class="fas fa-robot"></i>
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

<?php require __DIR__ . '/includes/site_reveal.php'; ?>
<?php require __DIR__ . '/includes/site_transitions.php'; ?>
<?php require __DIR__ . '/includes/csrf_inject.php'; ?>
<script src="assets/input_guard.js" defer></script>
<?php /* ?v=<mtime> so a changed loader reaches returning visitors: Apache sends
         no Cache-Control for these assets, so browsers happily serve a stale
         copy and the sign-in screen appears not to have changed at all. */ ?>
<script src="assets/auth_loader.js?v=<?php echo @filemtime(__DIR__ . '/assets/auth_loader.js') ?: '2'; ?>"></script>
</body>
</html>
