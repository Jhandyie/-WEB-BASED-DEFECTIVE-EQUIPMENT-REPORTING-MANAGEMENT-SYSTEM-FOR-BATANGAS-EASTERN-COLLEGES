<?php
/**
 * index.php — Public landing page / front door for the
 * BEC PMO Defective Equipment Reporting Management System.
 *
 * Becomes the automatic homepage (Apache DirectoryIndex). Routes each
 * audience to its portal and shows a live preview of public reports.
 * Read-only; no authentication required.
 */
require_once __DIR__ . '/config/database.php';

if (!function_exists('lp_e')) {
    function lp_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* Bucket a raw report status into a friendly label + colour for the badge. */
function lp_status_meta(string $status): array {
    $s = strtolower(trim($status));
    if (in_array($s, ['completed', 'verified', 'closed'], true)) return ['Resolved',    '#1A7A33', 'rgba(26,122,51,.10)'];
    if ($s === 'in_progress')                                    return ['In Progress', '#1F6F8B', 'rgba(31,111,139,.10)'];
    if (in_array($s, ['assigned', 'approved'], true))            return ['Assigned',    '#9A6B00', 'rgba(201,150,12,.14)'];
    return ['Open', '#7B1D1D', 'rgba(123,29,29,.08)'];
}

/* ── Public-reports preview (latest 4) — mirrors the visibility guard in includes/public_reports.php ── */
$previewReports = [];
try {
    $conn = getDBConnection();

    // The shared helper caches its result for the request, so anything else on
    // the page that needs the schema reuses it instead of asking again.
    $cols = getTableColumns('defect_reports');
    if (isset($cols['is_public'])) {
        $publicFilter = "dr.is_public = true";
    } elseif (isset($cols['admin_approval_status'])) {
        $publicFilter = "dr.admin_approval_status = 'approved'";
    } else {
        $publicFilter = "dr.status IN ('reported','assigned','in_progress','completed','verified','closed')";
    }

    $sql = "SELECT dr.report_id, e.equipment_name, e.location, dr.status, dr.priority, dr.report_date
            FROM defect_reports dr
            JOIN equipment e ON dr.equipment_id = e.equipment_id
            WHERE {$publicFilter}
            ORDER BY dr.report_date DESC
            LIMIT 4";
    if ($prRes = $conn->query($sql)) {
        while ($row = $prRes->fetch_assoc()) { $previewReports[] = $row; }
    }
} catch (Throwable $e) {
    // Landing page must always render; a DB hiccup just hides the preview.
    error_log('index.php public preview failed: ' . $e->getMessage());
    $previewReports = [];
}

$year = date('Y');

/* Absolute base URL for social-share meta — works in any deployment (localhost or live host) */
$lpScheme  = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$lpHost    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$lpDir     = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$lpBaseUrl = $lpScheme . '://' . $lpHost . $lpDir;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Official Batangas Eastern Colleges · Property Management Office portal for reporting, tracking, and resolving defective campus equipment.">
<meta name="theme-color" content="#4A0E0E">
<title>Batangas Eastern Colleges · PMO Equipment Reporting System</title>

<!-- Open Graph / social share -->
<meta property="og:type" content="website">
<meta property="og:site_name" content="BEC PMO Equipment Reporting System">
<meta property="og:title" content="Batangas Eastern Colleges · PMO Equipment Reporting System">
<meta property="og:description" content="The official Property Management Office channel for reporting, tracking, and resolving campus equipment concerns at Batangas Eastern Colleges.">
<meta property="og:url" content="<?php echo lp_e($lpBaseUrl . '/'); ?>">
<meta property="og:image" content="<?php echo lp_e($lpBaseUrl . '/assets/logs.png'); ?>">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="Batangas Eastern Colleges · PMO Equipment Reporting System">
<meta name="twitter:description" content="The official PMO channel for reporting, tracking, and resolving campus equipment concerns.">
<meta name="twitter:image" content="<?php echo lp_e($lpBaseUrl . '/assets/logs.png'); ?>">

<!-- Served from this server, not a CDN: on venue Wi-Fi that drops, the landing
     page lost every icon and fell back to a generic system font. -->
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="css/typography.css">

<!-- Favicons -->
<link rel="icon" type="image/png" href="assets/logs.png">
<link rel="apple-touch-icon" href="assets/logs.png">
<link rel="shortcut icon" href="assets/logs.png">
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
html { -webkit-text-size-adjust: 100%; scroll-behavior: smooth; }
body {
  font-family: 'DM Sans', sans-serif;
  background: var(--paper);
  color: var(--ink);
  min-height: 100vh;
  position: relative;
  overflow-x: hidden;
}
a { text-decoration: none; color: inherit; }

/* ambient background decor */
.bg-glow-a, .bg-glow-b, .bg-grid { position: fixed; pointer-events: none; z-index: 0; }
.bg-glow-a { top: -200px; right: -160px; width: 540px; height: 540px; border-radius: 50%;
  background: radial-gradient(circle, rgba(201,150,12,.12) 0%, transparent 65%); }
.bg-glow-b { bottom: -180px; left: -160px; width: 460px; height: 460px; border-radius: 50%;
  background: radial-gradient(circle, rgba(123,29,29,.08) 0%, transparent 65%); }
.bg-grid { inset: 0;
  background-image: radial-gradient(circle, rgba(123,29,29,.10) 1px, transparent 1px);
  background-size: 34px 34px;
  -webkit-mask-image: radial-gradient(ellipse 75% 70% at 50% 30%, black 0%, transparent 100%);
  mask-image: radial-gradient(ellipse 75% 70% at 50% 30%, black 0%, transparent 100%); }

.wrap { position: relative; z-index: 1; }
.container { width: 100%; max-width: 1140px; margin: 0 auto; padding: 0 1.5rem; }

/* NAV + FOOTER styles live in the shared includes (includes/site_nav.php & includes/site_footer.php) */

/* ══ HERO ══ */
.hero { position: relative; display: flex; align-items: center; min-height: min(90vh, 780px); padding: 5rem 0; color: #fff; overflow: hidden; }
/* On phones 90vh counts the strip under the collapsing address bar, so the hero
   stands taller than the screen and the page jolts when the bar hides. dvh
   tracks the space actually visible; browsers without it keep the vh rule. */
@supports (height: 100dvh) { .hero { min-height: min(90dvh, 780px); } }
.hero::before { content: ''; position: absolute; inset: 0; z-index: 0;
  background:
    linear-gradient(100deg, rgba(44, 2, 2, 0.9) 0%, rgba(38, 2, 2, 0.82) 32%, rgba(55,9,9,.56) 55%, rgba(74,14,14,.22) 76%, rgba(74,14,14,0) 100%),
    linear-gradient(0deg, rgba(26,4,4,.55) 0%, rgba(26,4,4,0) 28%),
    url('assets/Landing Page Background.jpg') center 42% / cover no-repeat,
    url('assets/bec-background.jpg') center / cover no-repeat;
  transform: scale(1.1) translate3d(0, var(--hero-par, 0px), 0); will-change: transform; }
.hero .container { position: relative; z-index: 1; }
.hero-content {
    max-width: 620px;
    text-align: left;
    margin-left: calc(-1 * min(80px, max(0px, (100vw - 1280px) / 2)));   /* pull left on wide screens; never clips on smaller ones */
    transition: transform .3s cubic-bezier(.22,1,.36,1);
}.hero .container{
    max-width: 1280px;
    padding-left: 2rem;
    padding-right: 2rem;
}
@keyframes heroRise { from { opacity: 0; transform: perspective(900px) translateY(26px) rotateX(8deg); } to { opacity: 1; transform: perspective(900px) translateY(0) rotateX(0); } }
.hero-content > * { opacity: 0; animation: heroRise .8s cubic-bezier(.22,1,.36,1) forwards; transform-origin: 20% bottom; }
.hero-content > *:nth-child(1) { animation-delay: .05s; }
.hero-content > *:nth-child(2) { animation-delay: .16s; }
.hero-content > *:nth-child(3) { animation-delay: .27s; }
.hero-content > *:nth-child(4) { animation-delay: .38s; }
.hero-content > *:nth-child(5) { animation-delay: .49s; }
@media (prefers-reduced-motion: reduce) { .hero-content > * { opacity: 1; animation: none; } }
/* atmospheric floating particles */
.hero-orb { position: absolute; border-radius: 50%; filter: blur(1px); pointer-events: none; z-index: 0; opacity: .5; will-change: transform; transition: transform .3s cubic-bezier(.22,1,.36,1); }
.hero-orb.o1 { width: 14px; height: 14px; background: radial-gradient(circle, #F0C040, transparent 70%); top: 24%; left: 54%; animation: orbFloat 9s ease-in-out infinite; }
.hero-orb.o2 { width: 9px;  height: 9px;  background: radial-gradient(circle, #fff, transparent 70%); top: 66%; left: 72%; animation: orbFloat 12s ease-in-out infinite reverse; }
.hero-orb.o3 { width: 18px; height: 18px; background: radial-gradient(circle, rgba(201,150,12,.8), transparent 70%); top: 42%; left: 84%; animation: orbFloat 14s ease-in-out infinite; }
@keyframes orbFloat { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-16px); } }
/* CTA ripple micro-interaction */
.btn { position: relative; overflow: hidden; }
.btn-ripple { position: absolute; border-radius: 50%; background: rgba(255,255,255,.4); transform: scale(0); animation: btnRipple .6s ease-out; pointer-events: none; }
@keyframes btnRipple { to { transform: scale(2.3); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .hero-orb { animation: none; } }
.hero-eyebrow { display: inline-flex; align-items: center; gap: .5rem; margin-bottom: 1.3rem;
  padding: .42rem .95rem; border-radius: 20px; font-size: .66rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.4px;
  background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.3); color: #fff; -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px); }
.hero-eyebrow i { color: var(--gold); }
.hero h1 { font-family: 'Fraunces', serif; font-weight: 700; font-size: 3.5rem; line-height: 1.06;
  letter-spacing: -.025em; color: #fff; max-width: 15ch; margin: 0 0 1rem; text-shadow: 0 2px 30px rgba(0,0,0,.45); }
.hero h1 em { font-style: italic; color: #F0C040; }
.hero-sub { font-size: 1.06rem; line-height: 1.7; color: rgba(255,255,255,.9); max-width: 50ch; margin: 0 0 2rem; text-shadow: 0 1px 14px rgba(0,0,0,.4); }
.hero-cta { display: flex; flex-wrap: wrap; gap: .7rem; justify-content: flex-start; margin-bottom: 1.9rem; }
.btn { display: inline-flex; align-items: center; gap: .55rem; padding: .9rem 1.6rem; border-radius: 12px;
  font-family: 'DM Sans', sans-serif; font-size: .95rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: all .22s cubic-bezier(.22,1,.36,1); }
.btn-primary { background: var(--gold); color: var(--maroon-dd); box-shadow: 0 4px 0 #9A6B00, 0 10px 24px rgba(0,0,0,.3); }
.btn-primary:hover { background: #E0AC1E; transform: translateY(-2px); box-shadow: 0 6px 0 #9A6B00, 0 16px 30px rgba(0,0,0,.35); }
.btn-primary:active { transform: translateY(1px); box-shadow: 0 2px 0 #9A6B00; }
.btn-ghost { background: rgba(255,255,255,.12); color: #fff; border: 1.5px solid rgba(255,255,255,.55); -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px); }
.btn-ghost:hover { background: rgba(255,255,255,.22); border-color: #fff; transform: translateY(-2px); }
.btn-arrow { width: 20px; height: 20px; background: rgba(0,0,0,.15); border-radius: 50%;
  display: flex; align-items: center; justify-content: center; font-size: .65rem; transition: transform .2s; }
.btn-primary:hover .btn-arrow { transform: translateX(3px); }
.hero-pills { display: flex; flex-wrap: wrap; gap: .5rem; justify-content: flex-start; padding-top: 1.4rem; border-top: 1px solid rgba(255,255,255,.16); }
.hpill { display: inline-flex; align-items: center; gap: .4rem; padding: .4rem .85rem; border-radius: 20px;
  background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.28); font-size: .72rem; font-weight: 600; color: #fff; -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px); }
.hpill i { color: var(--gold); font-size: .68rem; }

/* ══ SCROLL-REVEAL + 3D TILT (subtle, professional) ══ */
.reveal { opacity: 0; transform: translateY(26px); transition: opacity .6s cubic-bezier(.22,1,.36,1), transform .6s cubic-bezier(.22,1,.36,1); }
.reveal.in { opacity: 1; transform: none; }
.tilt { will-change: transform; }
/* scroll progress bar */
.scroll-prog { position: fixed; top: 0; left: 0; height: 3px; width: 100%; transform: scaleX(0); transform-origin: left; z-index: 400;
  background: linear-gradient(90deg, var(--maroon), var(--gold)); box-shadow: 0 0 8px rgba(201,150,12,.5); pointer-events: none; }
/* cursor spotlight on cards */
.portal-card::after, .mod-card::after { content: ''; position: absolute; inset: 0; border-radius: inherit; pointer-events: none; z-index: 0;
  opacity: 0; transition: opacity .25s ease; background: radial-gradient(240px circle at var(--mx, 50%) var(--my, 50%), rgba(201,150,12,.15), transparent 62%); }
.portal-card:hover::after, .mod-card:hover::after { opacity: 1; }
.mod-card { position: relative; }
.portal-card > *, .mod-card > * { position: relative; z-index: 1; }
@media (prefers-reduced-motion: reduce) { .reveal { opacity: 1 !important; transform: none !important; transition: none; } }

/* ══ CREDIBILITY STRIP ══ */
.cred { border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); background: rgba(255,255,255,.5); }
.cred-in { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: .6rem 1.5rem; padding: .65rem 1.5rem; }
.cred-item { display: inline-flex; align-items: center; gap: .5rem; font-size: .73rem; font-weight: 600; color: var(--ink2); text-transform: uppercase; letter-spacing: .8px; }
.cred-item i { color: var(--gold); font-size: .82rem; }
.cred-sep { width: 4px; height: 4px; border-radius: 50%; background: var(--border); }
@media (max-width: 640px) {
  /* keep the wrapped, centered, dot-separated layout (like desktop),
     just tighter so it reads cleanly on phones/tablets */
  .cred-in { gap: .5rem .95rem; padding: .6rem 1.1rem; }
  .cred-item { font-size: .7rem; letter-spacing: .4px; }
  .cred-item i { font-size: .82rem; }
}
/* very narrow phones: items stack one-per-row, so drop the trailing dots */
@media (max-width: 479px) {
  .cred-sep { display: none; }
}

/* ══ SECTION SCAFFOLD ══ */
.section { padding: 3.6rem 0; }
.sec-head { text-align: center; max-width: 60ch; margin: 0 auto 2.4rem; }
.sec-eyebrow { display: inline-flex; align-items: center; gap: .5rem; font-size: .64rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 1.6px; color: var(--maroon); margin-bottom: .7rem; }
.sec-eyebrow .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--gold); box-shadow: 0 0 0 3px var(--maroon-soft); }
.sec-title { font-family: 'Fraunces', serif; font-weight: 700; font-size: 2rem; letter-spacing: -.02em; color: var(--ink); line-height: 1.15; }
.sec-title em { font-style: italic; color: var(--maroon); }
.sec-sub { font-size: .96rem; color: var(--ink2); line-height: 1.65; margin-top: .7rem; }

/* ══ PORTALS ══ */
.portal-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.1rem; }
.portal-grid--single { grid-template-columns: minmax(0, 460px); justify-content: center; }
.portal-card { position: relative; display: flex; flex-direction: column; background: var(--surface);
  border: 1px solid var(--border); border-radius: 18px; padding: 1.8rem 1.6rem; box-shadow: var(--shadow);
  transition: transform .22s cubic-bezier(.22,1,.36,1), box-shadow .22s, border-color .22s; overflow: hidden; }
.portal-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: linear-gradient(90deg, var(--maroon-d), var(--maroon) 60%, var(--gold)); transform: scaleX(0); transform-origin: left; transition: transform .25s; }
.portal-card:hover { transform: translateY(-5px); border-color: rgba(123,29,29,.25); box-shadow: 0 10px 24px rgba(74,14,14,.14), 0 20px 50px rgba(74,14,14,.10); }
.portal-card:hover::before { transform: scaleX(1); }
.pc-ic { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center;
  font-size: 1.3rem; color: var(--maroon); background: var(--maroon-soft); border: 1px solid rgba(123,29,29,.14); margin-bottom: 1.1rem; }
.pc-title { font-family: 'Fraunces', serif; font-size: 1.22rem; font-weight: 700; color: var(--ink); margin-bottom: .35rem; }
.pc-desc { font-size: .86rem; line-height: 1.6; color: var(--ink2); flex: 1; margin-bottom: 1.2rem; }
.pc-enter { display: inline-flex; align-items: center; gap: .45rem; align-self: flex-start;
  font-size: .85rem; font-weight: 700; color: var(--maroon); }
.pc-enter i { transition: transform .2s; }
.portal-card:hover .pc-enter i { transform: translateX(4px); }
/* Get-started split CTA: portal card + numbered how-it-works steps */
.cta-portal { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1.05fr); gap: 1.4rem; align-items: stretch; max-width: 900px; margin: 0 auto; }
.cta-portal .cta-main { justify-content: center; }
.cta-steps { list-style: none; display: flex; flex-direction: column; gap: .7rem; margin: 0; padding: 0; }
.cta-steps li { display: flex; gap: .85rem; align-items: flex-start; background: var(--surface); border: 1px solid var(--border);
  border-radius: 14px; padding: 1rem 1.1rem; transition: transform .22s cubic-bezier(.22,1,.36,1), border-color .22s, box-shadow .22s; }
.cta-steps li:hover { transform: translateX(5px); border-color: rgba(123,29,29,.2); box-shadow: 0 6px 16px rgba(74,14,14,.08); }
.cs-n { width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
  font-family: 'Outfit', sans-serif; font-weight: 800; font-size: .85rem; color: #fff;
  background: linear-gradient(135deg, var(--maroon-d), var(--maroon)); box-shadow: 0 3px 8px rgba(123,29,29,.25); }
.cs-tx b { display: block; font-size: .9rem; font-weight: 700; color: var(--ink); }
.cs-tx span { display: block; font-size: .8rem; line-height: 1.5; color: var(--ink2); margin-top: .15rem; }
.cs-tx strong { color: var(--maroon); font-weight: 700; }
@media (max-width: 760px) { .cta-portal { grid-template-columns: 1fr; } }

/* ══ MODULES / CAPABILITIES ══ */
.mod-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }
.mod-card { position: relative; overflow: hidden; display: flex; gap: .9rem; align-items: flex-start; background: var(--surface); border: 1px solid var(--border);
  border-radius: 16px; padding: 1.3rem 1.3rem; box-shadow: 0 2px 8px rgba(44,10,10,.05);
  transition: transform .26s cubic-bezier(.22,1,.36,1), box-shadow .26s, border-color .26s; }
.mod-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 3px;
  background: linear-gradient(90deg, var(--maroon-d), var(--maroon) 60%, var(--gold)); transform: scaleX(0); transform-origin: left; transition: transform .3s ease; }
.mod-card::after { content: ''; position: absolute; top: -30px; right: -30px; width: 96px; height: 96px; border-radius: 50%; background: var(--maroon); opacity: 0; transition: opacity .3s, transform .3s; }
.mod-card:hover { transform: translateY(-5px); border-color: rgba(123,29,29,.2); box-shadow: 0 14px 30px rgba(74,14,14,.13); }
.mod-card:hover::before { transform: scaleX(1); }
.mod-card:hover::after { opacity: .05; transform: scale(1.4); }
.mod-card.feat { grid-column: span 2; background: linear-gradient(135deg, var(--surface) 55%, var(--maroon-soft)); border-color: rgba(123,29,29,.16); }
.mod-card.feat .mod-ic { background: linear-gradient(135deg, var(--maroon-d), var(--maroon)); color: #fff; border-color: transparent; box-shadow: 0 4px 12px rgba(123,29,29,.28); }
.mod-ic { position: relative; z-index: 1; width: 46px; height: 46px; border-radius: 13px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; color: var(--maroon); background: var(--maroon-soft); border: 1px solid rgba(123,29,29,.14); box-shadow: 0 3px 0 rgba(74,14,14,.06);
  transition: transform .26s, background .26s, color .26s, border-color .26s; }
.mod-card:hover .mod-ic { transform: rotate(-8deg) scale(1.1); background: linear-gradient(135deg, var(--maroon-d), var(--maroon)); color: #fff; border-color: transparent; }
.mod-tx { position: relative; z-index: 1; }
.mod-tx b { display: block; font-size: .97rem; font-weight: 700; color: var(--ink); margin-bottom: .25rem; letter-spacing: -.01em; }
.mod-tx span { display: block; font-size: .8rem; line-height: 1.55; color: var(--ink2); }
@media (max-width: 900px) { .mod-grid { grid-template-columns: repeat(2, 1fr); } .mod-card.feat { grid-column: span 2; } }
@media (max-width: 640px) { .mod-grid { grid-template-columns: 1fr; } .mod-card.feat { grid-column: auto; } }

/* ══ ABOUT THE PMO (dark band) ══ */
.about { position: relative; overflow: hidden; color: #fff; border-radius: 26px; margin: 1rem 0;
  padding: 3.2rem 2.8rem;
  background: radial-gradient(120% 90% at 0% 0%, rgba(201,150,12,.2) 0%, transparent 45%),
              linear-gradient(155deg, var(--maroon-dd) 0%, var(--maroon-d) 55%, var(--maroon) 120%); }
.about::after { content: ''; position: absolute; inset: 0; pointer-events: none;
  background-image: radial-gradient(circle, rgba(255,255,255,.06) 1px, transparent 1px); background-size: 22px 22px;
  -webkit-mask-image: radial-gradient(ellipse 80% 70% at 80% 15%, #000 0%, transparent 75%);
  mask-image: radial-gradient(ellipse 80% 70% at 80% 15%, #000 0%, transparent 75%); }
.about-grid { position: relative; z-index: 1; display: grid; grid-template-columns: 1.1fr .9fr; gap: 2.4rem; align-items: center; }
/* The brand gold reads at 2.42:1 on a cream panel — fine as a dark-background
   accent (it clears 6:1 in the footer), too pale for text on light. This is the
   same gold taken down to a shade that passes AA. */
.about-eyebrow { font-size: .64rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.6px; color: #8A6400; margin-bottom: .8rem; }
.about h2 { font-family: 'Fraunces', serif; font-weight: 700; font-size: 1.9rem; line-height: 1.2; letter-spacing: -.02em; margin-bottom: .9rem; }
.about p { font-size: .92rem; line-height: 1.75; color: rgba(255,255,255,.78); }
.about-points { display: flex; flex-direction: column; gap: .7rem; }
.about-point { display: flex; gap: .85rem; align-items: flex-start; padding: .9rem 1rem; border-radius: 14px;
  background: rgba(255,255,255,.045); border: 1px solid rgba(255,255,255,.08);
  transition: transform .24s cubic-bezier(.22,1,.36,1), background .24s, border-color .24s; }
.about-point:hover { transform: translateX(5px); background: rgba(255,255,255,.08); border-color: rgba(201,150,12,.38); }
.ap-ic { width: 48px; height: 48px; border-radius: 13px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
  font-size: 1.15rem; color: #2D0505;
  background: linear-gradient(140deg, #F0C040, var(--gold));
  border: 1px solid rgba(255,255,255,.25); box-shadow: 0 4px 16px rgba(201,150,12,.3);
  transition: transform .24s, filter .24s; }
.about-point:hover .ap-ic { transform: scale(1.1) rotate(-6deg); filter: brightness(1.08); }
.ap-ic i { display: block; line-height: 1; }
/* scope to the text column only — a bare `.about-point span` also hits the
   .ap-ic icon tile (it's a span) and breaks its flex centering */
.about-point > div b { display: block; font-size: .9rem; font-weight: 700; color: #fff; }
.about-point > div span { display: block; font-size: .78rem; line-height: 1.5; color: rgba(255,255,255,.66); margin-top: .15rem; }

/* ══ PUBLIC REPORTS PREVIEW ══ */
.rep-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }
.rep-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 1.15rem 1.15rem 1.25rem;
  box-shadow: 0 2px 8px rgba(44,10,10,.05); transition: transform .2s, box-shadow .2s, border-color .2s; }
.rep-card:hover { transform: translateY(-3px); border-color: rgba(123,29,29,.2); box-shadow: 0 8px 20px rgba(74,14,14,.10); }
.rc-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: .7rem; }
.rc-id { font-size: .72rem; font-weight: 700; color: var(--ink3); letter-spacing: .02em; }
.badge { font-size: .62rem; font-weight: 700; padding: .22rem .55rem; border-radius: 20px; letter-spacing: .02em; }
.rc-eq { font-family: 'Fraunces', serif; font-size: 1.02rem; font-weight: 700; color: var(--ink); line-height: 1.3; margin-bottom: .45rem;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.rc-meta { display: flex; align-items: center; gap: .4rem; font-size: .76rem; color: var(--ink2); }
.rc-meta i { color: var(--ink3); font-size: .72rem; }
.rc-date { margin-top: .55rem; font-size: .7rem; color: var(--ink3); display: flex; align-items: center; gap: .35rem; }
.rep-empty { grid-column: 1 / -1; text-align: center; padding: 2.4rem; color: var(--ink3);
  background: var(--surface); border: 1px dashed var(--border); border-radius: 14px; font-size: .9rem; }
.rep-all { text-align: center; margin-top: 1.8rem; }
.rep-all a { display: inline-flex; align-items: center; gap: .5rem; font-size: .9rem; font-weight: 700; color: var(--maroon); }
.rep-all a i { transition: transform .2s; }
.rep-all a:hover i { transform: translateX(4px); }

/* ══ SHOWCASE / 3D DASHBOARD ══ */
.showcase-in { display: grid; grid-template-columns: 1fr 1fr; gap: 3rem; align-items: center; }
.showcase-copy .sec-eyebrow { justify-content: flex-start; }
.showcase-copy .sec-title { max-width: 18ch; }
.showcase-feats { display: flex; flex-direction: column; gap: .7rem; margin-top: 1.4rem; }
.showcase-feats span { display: inline-flex; align-items: center; gap: .6rem; font-size: .88rem; font-weight: 600; color: var(--ink2); }
.showcase-feats i { color: var(--gold); width: 18px; text-align: center; }
.showcase-visual { position: relative; perspective: 1400px; min-height: 340px; display: flex; align-items: center; justify-content: center; transition: transform .3s cubic-bezier(.22,1,.36,1); }
.dash3d { width: 100%; max-width: 420px; border-radius: 18px; overflow: hidden; background: rgba(255,255,255,.72);
  -webkit-backdrop-filter: blur(12px); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,.7);
  box-shadow: 0 30px 60px rgba(74,14,14,.22), 0 10px 24px rgba(74,14,14,.14);
  transform: rotateY(-15deg) rotateX(7deg); transform-style: preserve-3d; animation: dashFloat 7s ease-in-out infinite; }
.dash-bar { display: flex; align-items: center; gap: .4rem; padding: .7rem .9rem; background: linear-gradient(135deg, var(--maroon-d), var(--maroon)); }
.dash-bar span { width: 9px; height: 9px; border-radius: 50%; background: rgba(255,255,255,.5); }
.dash-bar b { margin-left: .5rem; color: #fff; font-size: .72rem; font-weight: 600; letter-spacing: .02em; }
.dash-body { padding: 1rem; }
.dash-tiles { display: grid; grid-template-columns: repeat(3,1fr); gap: .6rem; margin-bottom: .9rem; }
.dtile { background: var(--maroon-soft); border: 1px solid rgba(123,29,29,.1); border-radius: 10px; padding: .7rem .4rem; text-align: center; }
.dtile i { color: var(--maroon); font-size: 1rem; }
.dtile small { display: block; margin-top: .3rem; font-size: .55rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--ink3); }
.dash-chart { display: flex; align-items: flex-end; gap: .45rem; height: 68px; padding: 0 .2rem .5rem; margin-bottom: .8rem; border-bottom: 1px solid var(--border); }
.dash-chart i { flex: 1; height: var(--h); border-radius: 5px 5px 0 0; background: linear-gradient(180deg, var(--gold), rgba(201,150,12,.35)); animation: barGrow 1s ease-out both; transform-origin: bottom; }
.dash-rows { display: flex; flex-direction: column; gap: .55rem; }
.drow { display: flex; align-items: center; gap: .55rem; }
.ddot { width: 8px; height: 8px; border-radius: 50%; background: var(--maroon); flex-shrink: 0; }
.dline { height: 8px; border-radius: 5px; background: linear-gradient(90deg, var(--border), rgba(232,221,208,.35)); flex: 1; }
.dline.w70 { max-width: 70%; } .dline.w85 { max-width: 85%; } .dline.w60 { max-width: 60%; }
.chip { font-size: .55rem; font-weight: 700; padding: .18rem .5rem; border-radius: 20px; text-transform: uppercase; letter-spacing: .4px; white-space: nowrap; }
.chip.open { background: #FDECEC; color: #B4232A; } .chip.prog { background: #FFF4DA; color: #9A6B00; } .chip.done { background: #E7F6EC; color: #1E7A44; }
.dash-notif { position: absolute; display: inline-flex; align-items: center; gap: .45rem; padding: .5rem .8rem; border-radius: 12px;
  background: #fff; border: 1px solid var(--border); box-shadow: 0 12px 28px rgba(74,14,14,.16); font-size: .74rem; font-weight: 600; color: var(--ink); z-index: 2; }
.dash-notif i { font-size: .7rem; color: var(--maroon); }
.dash-notif.n1 { top: 4%; right: 0; animation: notifFloat 5s ease-in-out infinite; }
.dash-notif.n2 { bottom: 6%; left: 0; animation: notifFloat 6s ease-in-out .8s infinite; }
.dash-notif.n2 i { color: #1E7A44; }
@keyframes dashFloat { 0%,100% { transform: rotateY(-15deg) rotateX(7deg) translateY(0); } 50% { transform: rotateY(-15deg) rotateX(7deg) translateY(-12px); } }
@keyframes notifFloat { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-9px); } }
@keyframes barGrow { from { transform: scaleY(0); } to { transform: scaleY(1); } }

/* ══ LIVE SYSTEM STATUS ══ */
.status-widget { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem 1.5rem;
  background: rgba(255,255,255,.7); -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
  border: 1px solid var(--border); border-radius: 16px; padding: 1.1rem 1.5rem; box-shadow: 0 4px 16px rgba(44,10,10,.06); }
.sw-main { display: flex; align-items: center; gap: .8rem; }
.sw-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
.sw-dot.ok { background: #22c55e; box-shadow: 0 0 0 0 rgba(34,197,94,.6); animation: swPulse 2s infinite; }
.sw-dot.bad { background: #ef4444; }
.sw-main b { display: block; font-size: .95rem; font-weight: 700; color: var(--ink); }
.sw-main > div span { display: block; font-size: .76rem; color: var(--ink3); }
.sw-items { display: flex; flex-wrap: wrap; gap: .5rem 1.4rem; }
.sw-item { display: inline-flex; align-items: center; gap: .5rem; font-size: .82rem; font-weight: 600; color: var(--ink2); }
.sw-item i { color: var(--ink3); }
.sw-item em { font-style: normal; font-weight: 700; }
.sw-item em.ok { color: #1E7A44; } .sw-item em.bad { color: #B4232A; }
@keyframes swPulse { 0% { box-shadow: 0 0 0 0 rgba(34,197,94,.5); } 70% { box-shadow: 0 0 0 8px rgba(34,197,94,0); } 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); } }
@media (max-width: 860px) {
  .showcase-in { grid-template-columns: 1fr; gap: 2rem; }
  .dash3d { transform: rotateY(0) rotateX(0); animation: none; max-width: 400px; }
  .dash-notif.n1 { right: 2%; } .dash-notif.n2 { left: 2%; }
}
@media (prefers-reduced-motion: reduce) { .dash3d, .dash-notif, .dash-chart i, .sw-dot.ok { animation: none; } }

/* ══ BECCA AI SHOWCASE ══ */
.becca-in { display: grid; grid-template-columns: 1fr 1fr; gap: 3rem; align-items: center; }
.becca-copy .sec-eyebrow { justify-content: flex-start; }
.becca-copy .sec-title { max-width: 16ch; }
.becca-feats { display: flex; flex-direction: column; gap: .6rem; margin: 1.3rem 0 1.6rem; }
.becca-feats span { display: inline-flex; align-items: center; gap: .6rem; font-size: .88rem; font-weight: 600; color: var(--ink2); }
.becca-feats i { color: var(--gold); width: 18px; text-align: center; }
.becca-visual { display: flex; justify-content: center; }
.becca-card { width: 100%; max-width: 380px; border-radius: 20px; overflow: hidden;
  background: rgba(255,255,255,.8); -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px);
  border: 1px solid rgba(255,255,255,.6); box-shadow: 0 24px 54px rgba(74,14,14,.2), 0 6px 16px rgba(74,14,14,.12);
  animation: notifFloat 6s ease-in-out infinite; }
.bc-head { display: flex; align-items: center; gap: .6rem; padding: .8rem .9rem; background: linear-gradient(135deg, var(--maroon-dd), var(--maroon)); }
.bc-av { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; flex-shrink: 0; border: 1.5px solid rgba(201,150,12,.6); box-shadow: 0 0 12px rgba(201,150,12,.4); }
.bc-av img, .bc-mav img { width: 100%; height: 100%; object-fit: cover; display: block; }
.bc-head b { display: block; font-size: .85rem; color: #fff; font-weight: 700; }
.bc-head small { display: flex; align-items: center; gap: .35rem; font-size: .64rem; color: rgba(255,255,255,.62); }
.bc-dot { width: 6px; height: 6px; border-radius: 50%; background: #4ade80; }
.bc-body { padding: 1rem .9rem; display: flex; flex-direction: column; gap: .6rem; background: var(--paper); }
.bc-msg { font-size: .82rem; line-height: 1.55; padding: .55rem .8rem; border-radius: 14px; max-width: 85%; }
.bc-msg.u { align-self: flex-end; background: var(--maroon-d); color: #fff; border-bottom-right-radius: 4px; }
.bc-msg.b { background: #fff; border: 1px solid var(--border); color: var(--ink); border-bottom-left-radius: 4px; }
.bc-row { display: flex; gap: .45rem; align-items: flex-end; max-width: 92%; }
.bc-mav { width: 24px; height: 24px; border-radius: 50%; overflow: hidden; flex-shrink: 0; border: 1px solid rgba(201,150,12,.4); }
.bc-typing { display: flex; gap: 4px; align-items: center; padding: .6rem .8rem; background: #fff; border: 1px solid var(--border); border-radius: 14px; border-bottom-left-radius: 4px; }
.bc-typing span { width: 6px; height: 6px; border-radius: 50%; background: var(--maroon); animation: bcType 1.3s infinite; }
.bc-typing span:nth-child(2) { animation-delay: .2s; } .bc-typing span:nth-child(3) { animation-delay: .4s; }
@keyframes bcType { 0%,80%,100% { opacity:.2; transform: scale(.85); } 40% { opacity:1; transform: scale(1); } }
@media (max-width: 860px) { .becca-in { grid-template-columns: 1fr; gap: 2rem; } }
@media (prefers-reduced-motion: reduce) { .becca-card { animation: none; } }

/* ══ FAQ ══ */
.faq-wrap { max-width: 760px; margin: 0 auto; display: flex; flex-direction: column; gap: .7rem; }
.faq-item { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; box-shadow: 0 2px 8px rgba(44,10,10,.05); overflow: hidden; transition: border-color .2s, box-shadow .2s; }
.faq-item[open] { border-color: rgba(123,29,29,.25); box-shadow: 0 8px 22px rgba(74,14,14,.10); }
.faq-item summary { display: flex; align-items: center; gap: .8rem; padding: 1.05rem 1.2rem; cursor: pointer; list-style: none; font-weight: 700; font-size: .95rem; color: var(--ink); user-select: none; }
.faq-item summary::-webkit-details-marker { display: none; }
.faq-item summary .fq-ic { width: 30px; height: 30px; flex-shrink: 0; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: .8rem; color: var(--maroon); background: var(--maroon-soft); border: 1px solid rgba(123,29,29,.12); }
.faq-item summary .fq-ch { margin-left: auto; color: var(--ink3); font-size: .8rem; transition: transform .25s; }
.faq-item[open] summary .fq-ch { transform: rotate(180deg); }
.faq-item[open] summary { color: var(--maroon-d); }
.faq-a { padding: 0 1.2rem 1.15rem 3.9rem; font-size: .88rem; line-height: 1.7; color: var(--ink2); }
.faq-a a { color: var(--maroon); font-weight: 700; text-decoration: underline; }
.faq-a strong { color: var(--ink); }
.faq-toggle { display: none; }
@media (max-width: 640px) {
  .faq-a { padding-left: 1.2rem; }
  /* top 3 questions first; the rest behind a premium expander */
  .faq-wrap:not(.expanded) .faq-item.faq-more { display: none; }
  .faq-toggle { display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
    margin: .2rem auto 0; padding: .7rem 1.4rem; min-height: 44px; border-radius: 999px;
    background: var(--surface); border: 1.5px solid var(--border); color: var(--maroon);
    font-family: 'DM Sans', sans-serif; font-size: .84rem; font-weight: 700; cursor: pointer;
    box-shadow: 0 2px 8px rgba(44,10,10,.05); transition: border-color .2s, box-shadow .2s; }
  .faq-toggle:hover { border-color: rgba(123,29,29,.3); box-shadow: 0 6px 16px rgba(74,14,14,.1); }
  .faq-toggle i { font-size: .72rem; transition: transform .25s; }
  .faq-wrap.expanded .faq-toggle i { transform: rotate(180deg); }
}

/* (footer styles: see includes/site_footer.php) */

/* ══ RESPONSIVE ══ */
@media (max-width: 900px) {
  .portal-grid { grid-template-columns: 1fr; }
  .rep-grid { grid-template-columns: repeat(2, 1fr); }
  .about { padding: 2.4rem 1.8rem; }
  .about-grid { grid-template-columns: 1fr; gap: 1.6rem; }
  .hero h1 { font-size: 2.5rem; }
}
@media (max-width: 640px) {
  .hero { min-height: auto; padding: 2.8rem 0 2.4rem; }
  .hero::before { background:
    linear-gradient(180deg, rgba(38,4,4,.86) 0%, rgba(45,5,5,.78) 45%, rgba(74,14,14,.9) 100%),
    url('assets/Landing Page Background.jpg') center 40% / cover no-repeat,
    url('assets/bec-background.jpg') center / cover no-repeat; }
  .hero-content { max-width: 100%; }
  /* calmer type scale on phones — the long headline was overwhelming at 2.15rem */
  .hero h1 { font-size: 1.7rem; line-height: 1.2; max-width: none; }
  .hero-sub { font-size: .88rem; margin-bottom: 1.4rem; }
  .hero-eyebrow { font-size: .58rem; padding: .36rem .8rem; margin-bottom: 1rem; }
  /* recent public reports: compact 2x2 mini-cards instead of a tall single column */
  .rep-grid { grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: .6rem; }
  .rep-card { padding: .75rem .8rem .85rem; border-radius: 12px; min-width: 0; overflow: hidden; }
  .rc-top { margin-bottom: .45rem; gap: .3rem; }
  /* The ticket number, its status and the date are what a person actually reads
     here, and they were the smallest type on the page at 9-10px. The number was
     also being cut off ("BEC-2026-0000…") because it shared a row with the
     status badge inside a 164px card, so on phones the two now stack and the
     number gets the full width. The decorative eyebrows and pills stay small
     by design. */
  .rc-top { flex-direction: column; align-items: flex-start; gap: .25rem; }
  .rc-id { font-size: .72rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .badge { font-size: .62rem; padding: .2rem .45rem; flex-shrink: 0; }
  .rc-eq { font-size: .86rem; margin-bottom: .3rem; }
  .rc-meta { font-size: .68rem; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .rc-date { font-size: .72rem; margin-top: .4rem; }
  .sec-title { font-size: 1.35rem; }
  .btn { width: 100%; justify-content: center; padding: .82rem 1.4rem; font-size: .9rem; }
  .hero-cta { flex-direction: column; }

  /* ── touch ergonomics ──────────────────────────────────────────────
     WCAG 2.5.5 and both platform guidelines put the smallest comfortable
     touch target at 44px; most links here sat at 20-39px, close enough to
     each other that a thumb catches the wrong one. The padding is vertical
     only, so nothing moves visually — the tappable area just grows. */
  /* The shared nav and footer carry their own 44px rules (site_nav.php,
     site_footer.php) so every public page gets them, not just this one. These
     are the selectors that only exist on the landing page. */
  .rep-all a,
  .faq-a a,
  a.link-more { min-height: 44px; display: inline-flex; align-items: center; }
  /* Room for the notch in landscape. */
  .container { padding-left: max(1.1rem, env(safe-area-inset-left));
               padding-right: max(1.1rem, env(safe-area-inset-right)); }

  /* ── tighter rhythm = less scrolling on phones (mockups kept) ── */
  .section { padding: 2.3rem 0; }
  .sec-head { margin-bottom: 1.5rem; }
  .sec-sub { font-size: .86rem; }
  .container { padding: 0 1.1rem; }
  .about { padding: 2rem 1.4rem; margin: 0; }
  .about h2 { font-size: 1.3rem; }
  .about p { font-size: .86rem; }
  /* mockup floating chips: keep them clear of the mockup's title bar */
  .dash-notif { font-size: .68rem; padding: .4rem .65rem; }
  .dash-notif.n1 { top: -14px; right: 0; }
  .dash-notif.n2 { bottom: -10px; left: 0; }
  /* compact capability cards — same content, less height */
  .mod-card { padding: .95rem 1rem; gap: .75rem; border-radius: 14px; }
  .mod-ic { width: 40px; height: 40px; font-size: .95rem; border-radius: 11px; }
  .mod-tx b { font-size: .9rem; margin-bottom: .15rem; }
  .mod-tx span { font-size: .76rem; }
  .mod-grid { gap: .65rem; }
  /* full-label pills — exactly 2 on top + 1 centered below.
     Font scales with the viewport so the pair always fits, as big as possible. */
  .hero .container { padding-left: .9rem; padding-right: .9rem; }
  .hero-pills { display: grid; grid-template-columns: 1fr 1fr; gap: .45rem .35rem;
    padding-top: 1.15rem; justify-items: center; }
  .hpill { font-size: clamp(.55rem, 2.42vw, .7rem); padding: .48rem .5rem; white-space: nowrap;
    gap: .3rem; letter-spacing: 0; justify-content: center; width: 100%;
    border-color: rgba(255,255,255,.34); }
  .hpill:nth-child(3) { grid-column: 1 / -1; width: auto; padding: .48rem 1.05rem; }
  .hpill i { font-size: .92em; }
}
</style>
</head>
<body>
<div class="bg-glow-a"></div><div class="bg-glow-b"></div><div class="bg-grid"></div>

<div class="scroll-prog" id="scrollProg"></div>
<div class="wrap">

  <!-- shared top navigation -->
  <?php $nav_active = 'home'; require __DIR__ . '/includes/site_nav.php'; ?>

  <!-- ══ HERO ══ -->
  <header class="hero">
    <span class="hero-orb o1"></span><span class="hero-orb o2"></span><span class="hero-orb o3"></span>
    <div class="container">
      <div class="hero-content">
      <span class="hero-eyebrow"><i class="fas fa-building-shield"></i> Batangas Eastern Colleges · Property Management Office</span>
      <h1>Defective Equipment Reporting &amp; Maintenance Management, <em>handled by the PMO</em></h1>
      <p class="hero-sub">The official Batangas Eastern Colleges channel for reporting, tracking, and resolving campus equipment concerns — verified and managed end-to-end by the Property Management Office.</p>
      <div class="hero-cta">
        <a class="btn btn-primary" href="student_index.php">Report defective equipment <span class="btn-arrow"><i class="fas fa-arrow-right"></i></span></a>
        <a class="btn btn-ghost" href="track_report.php"><i class="fas fa-magnifying-glass"></i> Track a report</a>
      </div>
      <div class="hero-pills">
        <span class="hpill"><i class="fas fa-certificate"></i> Official institutional system</span>
        <span class="hpill"><i class="fas fa-building-columns"></i> Property Management Office</span>
        <span class="hpill"><i class="fas fa-shield-halved"></i> Secure OTP-protected access</span>
      </div>
      </div>
    </div>
  </header>

  <!-- ══ CREDIBILITY STRIP ══ -->
  <div class="cred">
    <div class="container cred-in">
      <span class="cred-item"><i class="fas fa-award"></i> Batangas Eastern Colleges · Est. 1940</span>
    </div>
  </div>

  <!-- ══ SHOWCASE / 3D DASHBOARD ══ -->
  <section class="section showcase">
    <div class="container showcase-in">
      <div class="showcase-copy">
        <span class="sec-eyebrow"><span class="dot"></span> The platform</span>
        <h2 class="sec-title">One dashboard for the <em>entire equipment lifecycle</em></h2>
        <p class="sec-sub">Report, review, assign, repair, and resolve — the PMO tracks every case from submission to sign-off, with real-time status and email updates at each step.</p>
        <div class="showcase-feats">
          <span><i class="fas fa-bolt"></i> Real-time status tracking</span>
          <span><i class="fas fa-envelope-circle-check"></i> Automated email confirmations</span>
          <span><i class="fas fa-shield-halved"></i> Secure, OTP-protected access</span>
        </div>
      </div>
      <div class="showcase-visual">
        <div class="dash3d">
          <div class="dash-bar"><span></span><span></span><span></span><b>PMO Dashboard</b></div>
          <div class="dash-body">
            <div class="dash-tiles">
              <div class="dtile"><i class="fas fa-inbox"></i><small>Open</small></div>
              <div class="dtile"><i class="fas fa-gears"></i><small>In Progress</small></div>
              <div class="dtile"><i class="fas fa-circle-check"></i><small>Resolved</small></div>
            </div>
            <div class="dash-chart"><i style="--h:42%"></i><i style="--h:68%"></i><i style="--h:54%"></i><i style="--h:86%"></i><i style="--h:60%"></i><i style="--h:94%"></i></div>
            <div class="dash-rows">
              <div class="drow"><span class="ddot"></span><span class="dline w70"></span><em class="chip prog">In Progress</em></div>
              <div class="drow"><span class="ddot"></span><span class="dline w85"></span><em class="chip done">Resolved</em></div>
              <div class="drow"><span class="ddot"></span><span class="dline w60"></span><em class="chip open">Open</em></div>
            </div>
          </div>
        </div>
        <div class="dash-notif n1"><i class="fas fa-bell"></i> New report received</div>
        <div class="dash-notif n2"><i class="fas fa-check"></i> Report resolved</div>
      </div>
    </div>
  </section>
  <script>
  (function () {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || window.matchMedia('(pointer: coarse)').matches) return;
    var host = document.querySelector('.showcase'), sc = document.querySelector('.showcase-visual');
    if (!host || !sc) return;
    host.addEventListener('mousemove', function (e) {
      var r = host.getBoundingClientRect();
      var x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
      sc.style.transform = 'translate(' + (x * 14).toFixed(1) + 'px,' + (y * 12).toFixed(1) + 'px)';
    });
    host.addEventListener('mouseleave', function () { sc.style.transform = ''; });
  })();
  </script>

  <!-- ══ PORTALS ══ -->
  <section class="section" id="portals">
    <div class="container">
      <div class="sec-head">
        <span class="sec-eyebrow"><span class="dot"></span> Get started</span>
        <h2 class="sec-title">Report equipment to the <em>PMO</em></h2>
        <p class="sec-sub">Sign in with your official Batangas Eastern Colleges email to file a defect report and follow it through to resolution.</p>
      </div>
      <div class="cta-portal">
        <a class="portal-card cta-main" href="student_index.php">
          <div class="pc-ic"><i class="fas fa-user-graduate"></i></div>
          <div class="pc-title">Reporter Portal</div>
          <p class="pc-desc">For students, faculty, and staff — file a defect report with your official BEC email and track it through to resolution.</p>
          <span class="pc-enter">Enter portal <i class="fas fa-arrow-right"></i></span>
        </a>
        <ul class="cta-steps">
          <li><span class="cs-n">1</span><div class="cs-tx"><b>Sign in with your BEC email</b><span>Use your official <strong>@bec.edu.ph</strong> account — no separate registration needed.</span></div></li>
          <li><span class="cs-n">2</span><div class="cs-tx"><b>Describe the problem</b><span>Add the equipment, location, priority, and attach photo or video evidence.</span></div></li>
          <li><span class="cs-n">3</span><div class="cs-tx"><b>Track it to resolution</b><span>Get email updates as the PMO reviews, assigns, and completes the repair.</span></div></li>
        </ul>
      </div>
    </div>
  </section>

  <!-- ══ MODULES / CAPABILITIES ══ -->
  <section class="section">
    <div class="container">
      <div class="sec-head">
        <span class="sec-eyebrow"><span class="dot"></span> One platform</span>
        <h2 class="sec-title">Everything the PMO needs, <em>in one system</em></h2>
        <p class="sec-sub">From the moment a defect is reported to its final resolution — the platform covers the full equipment-management workflow.</p>
      </div>
      <div class="mod-grid">
        <div class="mod-card feat"><div class="mod-ic"><i class="fas fa-clipboard-list"></i></div><div class="mod-tx"><b>Defect Reporting</b><span>Reporters log equipment issues with photo or video evidence, location, and priority.</span></div></div>
        <div class="mod-card"><div class="mod-ic"><i class="fas fa-clipboard-check"></i></div><div class="mod-tx"><b>Review &amp; Approval</b><span>The PMO verifies every report before any work begins.</span></div></div>
        <div class="mod-card"><div class="mod-ic"><i class="fas fa-people-carry-box"></i></div><div class="mod-tx"><b>Technician Assignment</b><span>Cases route to the right unit — PMO or ITSO — with balanced technician workloads.</span></div></div>
        <div class="mod-card"><div class="mod-ic"><i class="fas fa-screwdriver-wrench"></i></div><div class="mod-tx"><b>Work Orders</b><span>Technicians track repair progress through to completion.</span></div></div>
        <div class="mod-card feat"><div class="mod-ic"><i class="fas fa-boxes-stacked"></i></div><div class="mod-tx"><b>Inventory</b><span>Maintain equipment records, asset tags, and locations.</span></div></div>
        <div class="mod-card"><div class="mod-ic"><i class="fas fa-calendar-check"></i></div><div class="mod-tx"><b>Preventive Maintenance</b><span>Schedule recurring upkeep before equipment fails.</span></div></div>
        <div class="mod-card"><div class="mod-ic"><i class="fas fa-database"></i></div><div class="mod-tx"><b>Backup &amp; Recovery</b><span>Automated snapshots with one-click data recovery.</span></div></div>
        <div class="mod-card"><div class="mod-ic"><i class="fas fa-chart-line"></i></div><div class="mod-tx"><b>Analytics &amp; Reports</b><span>Dashboards and exports for data-driven decisions.</span></div></div>
        <div class="mod-card feat"><div class="mod-ic"><i class="fas fa-robot"></i></div><div class="mod-tx"><b>AI Assistant (Becca)</b><span>Built-in guidance and troubleshooting, anytime.</span></div></div>
      </div>
    </div>
  </section>

  <!-- ══ ABOUT THE PMO ══ -->
  <section class="section">
    <div class="container">
      <div class="about">
        <div class="about-grid">
          <div>
            <div class="about-eyebrow">About the Property Management Office</div>
            <h2>Safeguarding the institution's equipment and facilities</h2>
            <p>The Property Management Office (PMO) of Batangas Eastern Colleges is responsible for the custody, maintenance, and accountability of institutional equipment and facilities. This system gives the campus community a single, transparent channel to report defective equipment — and gives the PMO the tools to verify, assign, and resolve each case efficiently.</p>
          </div>
          <div class="about-points">
            <div class="about-point">
              <span class="ap-ic"><i class="fas fa-shield-halved"></i></span>
              <div><b>Verification &amp; approval</b><span>Every report is reviewed by the PMO before work begins.</span></div>
            </div>
            <div class="about-point">
              <span class="ap-ic"><i class="fas fa-user-gear"></i></span>
              <div><b>Technician assignment</b><span>Cases are routed to the right personnel with balanced workloads.</span></div>
            </div>
            <div class="about-point">
              <span class="ap-ic"><i class="fas fa-timeline"></i></span>
              <div><b>Accountability &amp; tracking</b><span>Status, history, and resolution are recorded end-to-end.</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ══ BECCA AI SHOWCASE ══ -->
  <section class="section becca-show">
    <div class="container becca-in">
      <div class="becca-copy">
        <span class="sec-eyebrow"><span class="dot"></span> AI Assistant</span>
        <h2 class="sec-title">Meet <em>Becca</em>, your PMO support assistant</h2>
        <p class="sec-sub">Becca answers questions, guides you through filing a report, and helps troubleshoot common equipment issues — anytime, in English or Filipino.</p>
        <div class="becca-feats">
          <span><i class="fas fa-comments"></i> Instant answers &amp; step-by-step guidance</span>
          <span><i class="fas fa-language"></i> English &amp; Filipino</span>
          <span><i class="fas fa-clock"></i> Available 24/7</span>
        </div>
        <button class="btn btn-primary" type="button" onclick="if(window.openChat)openChat()">Ask Becca <span class="btn-arrow"><i class="fas fa-arrow-right"></i></span></button>
      </div>
      <div class="becca-visual">
        <div class="becca-card">
          <div class="bc-head">
            <span class="bc-av"><img src="assets/Gemini_Generated_Image_e35zfue35zfue35z.png" alt="Becca" width="180" height="260" loading="lazy" decoding="async"></span>
            <div><b>Becca</b><small><span class="bc-dot"></span> Online · BEC Support AI</small></div>
          </div>
          <div class="bc-body">
            <div class="bc-msg u">How do I report a broken projector?</div>
            <div class="bc-row">
              <span class="bc-mav"><img src="assets/Gemini_Generated_Image_e35zfue35zfue35z.png" alt="" width="180" height="260" loading="lazy" decoding="async"></span>
              <div class="bc-msg b">Sign in on the report page, choose the equipment and room, attach a photo, and set the priority. You'll get an email as soon as the PMO reviews it. 👍</div>
            </div>
            <div class="bc-row">
              <span class="bc-mav"><img src="assets/Gemini_Generated_Image_e35zfue35zfue35z.png" alt="" width="180" height="260" loading="lazy" decoding="async"></span>
              <div class="bc-typing"><span></span><span></span><span></span></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ══ LIVE SYSTEM STATUS ══ -->
  <?php
    $sysOk = false; $lastActivity = null;
    try {
      $sconn = getDBConnection();
      if ($sconn) {
        $sysOk = true;
        $sres = @$sconn->query("SELECT MAX(report_date) AS m FROM defect_reports");
        if ($sres) { $srow = $sres->fetch_assoc(); if (!empty($srow['m'])) { $lastActivity = $srow['m']; } }
      }
    } catch (\Throwable $e) { $sysOk = false; }
    $lastAgo = '';
    if ($lastActivity) {
      $t = strtotime($lastActivity);
      if ($t) {
        $d = max(60, time() - $t);
        if ($d < 3600) { $lastAgo = floor($d / 60) . 'm ago'; }
        elseif ($d < 86400) { $lastAgo = floor($d / 3600) . 'h ago'; }
        else { $lastAgo = floor($d / 86400) . 'd ago'; }
      }
    }
  ?>
  <section class="section" style="padding-top:0;">
    <div class="container">
      <div class="status-widget">
        <div class="sw-main">
          <span class="sw-dot <?php echo $sysOk ? 'ok' : 'bad'; ?>"></span>
          <div>
            <b><?php echo $sysOk ? 'All systems operational' : 'Service temporarily degraded'; ?></b>
            <span>Live status of the PMO equipment reporting platform</span>
          </div>
        </div>
        <div class="sw-items">
          <span class="sw-item"><i class="fas fa-database"></i> Database <em class="<?php echo $sysOk ? 'ok' : 'bad'; ?>"><?php echo $sysOk ? 'Connected' : 'Unavailable'; ?></em></span>
          <span class="sw-item"><i class="fas fa-clipboard-list"></i> Reporting <em class="<?php echo $sysOk ? 'ok' : 'bad'; ?>"><?php echo $sysOk ? 'Online' : 'Offline'; ?></em></span>
          <?php if ($lastAgo): ?><span class="sw-item"><i class="fas fa-clock"></i> Last activity <em><?php echo htmlspecialchars($lastAgo); ?></em></span><?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- ══ PUBLIC REPORTS PREVIEW ══ -->
  <section class="section">
    <div class="container">
      <div class="sec-head">
        <span class="sec-eyebrow"><span class="dot"></span> Transparency</span>
        <h2 class="sec-title">Recent <em>public reports</em></h2>
        <p class="sec-sub">A live look at equipment concerns recently logged in the system.</p>
      </div>
      <div class="rep-grid">
        <?php if (empty($previewReports)): ?>
          <div class="rep-empty"><i class="fas fa-inbox"></i> &nbsp;No public reports to display yet.</div>
        <?php else: foreach ($previewReports as $r):
          [$badgeLabel, $badgeColor, $badgeBg] = lp_status_meta((string)($r['status'] ?? ''));
          $when = !empty($r['report_date']) ? date('M j, Y', strtotime((string)$r['report_date'])) : '';
        ?>
          <a class="rep-card" href="public_reports.php">
            <div class="rc-top">
              <span class="rc-id"><?php echo lp_e($r['report_id'] ?? '—'); ?></span>
              <span class="badge" style="color:<?php echo $badgeColor; ?>;background:<?php echo $badgeBg; ?>;"><?php echo lp_e($badgeLabel); ?></span>
            </div>
            <div class="rc-eq"><?php echo lp_e($r['equipment_name'] ?? 'Equipment'); ?></div>
            <div class="rc-meta"><i class="fas fa-location-dot"></i> <?php echo lp_e($r['location'] ?? 'Not specified'); ?></div>
            <?php if ($when !== ''): ?><div class="rc-date"><i class="fas fa-clock"></i> <?php echo lp_e($when); ?></div><?php endif; ?>
          </a>
        <?php endforeach; endif; ?>
      </div>
      <div class="rep-all"><a href="public_reports.php">View all public reports <i class="fas fa-arrow-right"></i></a></div>
    </div>
  </section>

  <!-- ══ FAQ ══ -->
  <section class="section" id="faq">
    <div class="container">
      <div class="sec-head">
        <span class="sec-eyebrow"><span class="dot"></span> Quick answers</span>
        <h2 class="sec-title">How it works <em>for you</em></h2>
        <p class="sec-sub">The most common questions from first-time reporters — answered in a minute.</p>
      </div>
      <div class="faq-wrap">
        <details class="faq-item">
          <summary><span class="fq-ic"><i class="fas fa-user-check"></i></span> Who can report defective equipment?<i class="fas fa-chevron-down fq-ch"></i></summary>
          <div class="faq-a">Any <strong>student, teacher, or staff member</strong> of Batangas Eastern Colleges. You only need your official <strong>BEC email address</strong> — it's verified at sign-in so reports always come from the campus community.</div>
        </details>
        <details class="faq-item">
          <summary><span class="fq-ic"><i class="fas fa-key"></i></span> Do I need to create an account?<i class="fas fa-chevron-down fq-ch"></i></summary>
          <div class="faq-a">No. Enter your <strong>name and BEC email</strong> and you're in — no password, no registration. (PMO administrators and technicians have their own secured logins.)</div>
        </details>
        <details class="faq-item">
          <summary><span class="fq-ic"><i class="fas fa-qrcode"></i></span> What's the fastest way to report?<i class="fas fa-chevron-down fq-ch"></i></summary>
          <div class="faq-a"><strong>Scan the QR sticker</strong> on the equipment — the report form opens with that exact unit already selected. Just describe the problem and submit. No sticker? Use <a href="student_index.php">Report defective equipment</a> and search for the item.</div>
        </details>
        <details class="faq-item faq-more">
          <summary><span class="fq-ic"><i class="fas fa-route"></i></span> How do I follow up on my report?<i class="fas fa-chevron-down fq-ch"></i></summary>
          <div class="faq-a">You get a <strong>ticket number</strong> on screen and by email the moment you submit. Enter it on the <a href="track_report.php">Track Report</a> page anytime to see the live status — and you'll receive an email at every stage: received, approved, technician assigned, and repaired. Once fixed, we'll ask you to confirm the issue is really resolved.</div>
        </details>
        <details class="faq-item faq-more">
          <summary><span class="fq-ic"><i class="fas fa-stopwatch"></i></span> How fast will it be repaired?<i class="fas fa-chevron-down fq-ch"></i></summary>
          <div class="faq-a"><?php
            require_once __DIR__ . '/config/sla.php';
            $faqH = becSlaHours();
            $faqD = static fn($h) => rtrim(rtrim(number_format($h / 24, 1), '0'), '.');
          ?>Every report gets a priority with a target timeline: <strong>critical ≈ <?php echo $faqD($faqH['critical']); ?> day(s)</strong>, high ≈ <?php echo $faqD($faqH['high']); ?> days, medium ≈ <?php echo $faqD($faqH['medium']); ?> days, and low ≈ <?php echo $faqD($faqH['low']); ?> days. Reports that pass their target are automatically escalated to the PMO.</div>
        </details>
        <details class="faq-item faq-more">
          <summary><span class="fq-ic"><i class="fas fa-user-shield"></i></span> Is my information safe?<i class="fas fa-chevron-down fq-ch"></i></summary>
          <div class="faq-a">Yes. Your name, email, and report details are used <strong>solely for maintenance and follow-ups</strong>, in line with the <strong>Data Privacy Act of 2012 (RA 10173)</strong> — you give explicit consent at sign-in. Publicly visible reports show only the equipment and status, never your personal details.</div>
        </details>
        <button class="faq-toggle" type="button" id="faqToggle" aria-expanded="false">
          <i class="fas fa-chevron-down"></i> <span>More questions</span>
        </button>
      </div>
      <script>
      (function () {
        var t = document.getElementById('faqToggle');
        if (!t) return;
        t.addEventListener('click', function () {
          var wrap = t.closest('.faq-wrap');
          var open = wrap.classList.toggle('expanded');
          t.setAttribute('aria-expanded', open ? 'true' : 'false');
          t.querySelector('span').textContent = open ? 'Show fewer' : 'More questions';
        });
      })();
      </script>
    </div>
  </section>

  <!-- shared footer -->
  <?php require __DIR__ . '/includes/site_footer.php'; ?>

</div>

<script>
(function () {
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  // stagger cards within each grid
  document.querySelectorAll('.portal-grid,.mod-grid,.rep-grid').forEach(function (grid) {
    Array.prototype.forEach.call(grid.children, function (c, i) { c.style.transitionDelay = (i * 60) + 'ms'; });
  });
  // scroll-reveal
  var els = document.querySelectorAll('.sec-head,.portal-card,.mod-card,.about,.rep-card,.rep-all,.cred,.showcase-copy,.status-widget,.becca-copy,.faq-item');
  if (reduce || !('IntersectionObserver' in window)) {
    els.forEach(function (el) { el.classList.add('reveal', 'in'); });
  } else {
    els.forEach(function (el) { el.classList.add('reveal'); });
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { threshold: .12, rootMargin: '0px 0px -40px 0px' });
    els.forEach(function (el) { io.observe(el); });
  }
  // safety net: never leave content hidden if the observer misbehaves
  setTimeout(function () { document.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('in'); }); }, 1500);
  // subtle 3D tilt on cards (pointer devices only)
  if (!reduce && !window.matchMedia('(pointer: coarse)').matches) {
    document.querySelectorAll('.portal-card,.mod-card').forEach(function (card) {
      card.classList.add('tilt');
      card.addEventListener('mousemove', function (e) {
        var r = card.getBoundingClientRect();
        var px = (e.clientX - r.left) / r.width - 0.5, py = (e.clientY - r.top) / r.height - 0.5;
        card.style.transform = 'perspective(720px) rotateY(' + (px * 5).toFixed(2) + 'deg) rotateX(' + (-py * 5).toFixed(2) + 'deg) translateY(-4px)';
      });
      card.addEventListener('mouseleave', function () { card.style.transform = ''; });
    });
  }
})();
</script>
<script>
(function () {
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hero = document.querySelector('.hero');
  if (hero && !reduce && !window.matchMedia('(pointer: coarse)').matches) {
    var orbs = hero.querySelectorAll('.hero-orb');
    var content = hero.querySelector('.hero-content');
    hero.addEventListener('mousemove', function (e) {
      var r = hero.getBoundingClientRect();
      var x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
      orbs.forEach(function (o, i) { var d = (i + 1) * 12; o.style.transform = 'translate(' + (x * d).toFixed(1) + 'px,' + (y * d).toFixed(1) + 'px)'; });
      if (content) content.style.transform = 'translate(' + (x * 6).toFixed(1) + 'px,' + (y * 5).toFixed(1) + 'px)';
    });
    hero.addEventListener('mouseleave', function () {
      orbs.forEach(function (o) { o.style.transform = ''; });
      if (content) content.style.transform = '';
    });
  }
  // ripple micro-interaction on buttons
  document.querySelectorAll('.btn').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      var r = btn.getBoundingClientRect(), size = Math.max(r.width, r.height);
      var s = document.createElement('span');
      s.className = 'btn-ripple';
      s.style.width = s.style.height = size + 'px';
      s.style.left = (e.clientX - r.left - size / 2) + 'px';
      s.style.top = (e.clientY - r.top - size / 2) + 'px';
      btn.appendChild(s);
      setTimeout(function () { s.remove(); }, 600);
    });
  });
})();
</script>
<script>
(function () {
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  // Skip the hero parallax on touch devices — moving the background image on
  // every scroll frame looks laggy/juddery on phones.
  var noParallax = reduce || window.matchMedia('(pointer: coarse)').matches;
  var bar = document.getElementById('scrollProg');
  var hero = document.querySelector('.hero');
  function onScroll() {
    var y = window.pageYOffset || document.documentElement.scrollTop || 0;
    if (bar) { var docH = document.documentElement.scrollHeight - window.innerHeight; bar.style.transform = 'scaleX(' + (docH > 0 ? Math.min(y / docH, 1) : 0).toFixed(4) + ')'; }
    if (hero && !noParallax) { hero.style.setProperty('--hero-par', Math.min(y * 0.15, 60).toFixed(1) + 'px'); }
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll, { passive: true });
  onScroll();
  // cursor spotlight on cards
  if (!reduce && !window.matchMedia('(pointer: coarse)').matches) {
    document.querySelectorAll('.portal-card,.mod-card').forEach(function (card) {
      card.addEventListener('mousemove', function (e) {
        var r = card.getBoundingClientRect();
        card.style.setProperty('--mx', (e.clientX - r.left) + 'px');
        card.style.setProperty('--my', (e.clientY - r.top) + 'px');
      });
    });
  }
})();
</script>
<!-- ══ BACK TO TOP ══ -->
<style>
#toTop{position:fixed;right:1.35rem;bottom:1.35rem;z-index:9996;width:48px;height:48px;border-radius:14px;
  display:flex;align-items:center;justify-content:center;cursor:pointer;
  background:linear-gradient(135deg,var(--maroon-d),var(--maroon));color:#fff;border:1px solid rgba(201,150,12,.4);
  box-shadow:0 8px 24px rgba(74,14,14,.32),0 0 0 1px rgba(255,255,255,.06) inset;
  opacity:0;visibility:hidden;transform:translateY(14px) scale(.9);pointer-events:none;
  transition:opacity .28s ease,transform .28s cubic-bezier(.22,1,.36,1),visibility .28s,box-shadow .2s;}
#toTop.show{opacity:1;visibility:visible;transform:none;pointer-events:auto;}
#toTop:hover{box-shadow:0 12px 30px rgba(74,14,14,.42),0 0 20px rgba(201,150,12,.35);transform:translateY(-3px);}
#toTop:active{transform:translateY(0);}
#toTop i{font-size:1rem;color:#F0C040;}
@media(max-width:560px){#toTop{width:46px;height:46px;right:1rem;bottom:1.2rem;}}
</style>
<button id="toTop" type="button" aria-label="Back to top"><i class="fas fa-arrow-up"></i></button>
<script>(function(){
  var b=document.getElementById('toTop');if(!b)return;
  var reduce=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function t(){b.classList.toggle('show',(window.pageYOffset||document.documentElement.scrollTop||0)>560);}
  window.addEventListener('scroll',t,{passive:true});t();
  b.addEventListener('click',function(){window.scrollTo({top:0,behavior:reduce?'auto':'smooth'});});
})();</script>

<?php require __DIR__ . '/includes/site_transitions.php'; ?>
<?php require __DIR__ . '/includes/becca_widget.php'; ?>
</body>
</html>
