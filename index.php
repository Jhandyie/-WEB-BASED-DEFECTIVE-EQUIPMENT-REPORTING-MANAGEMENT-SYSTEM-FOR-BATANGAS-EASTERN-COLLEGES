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

    $cols = [];
    if ($colRes = $conn->query("SHOW COLUMNS FROM defect_reports")) {
        while ($c = $colRes->fetch_assoc()) { $cols[$c['Field']] = true; }
    }
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

<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
  --ink3: #9E8070;
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
.hero { text-align: center; padding: 4.2rem 0 3.4rem; }
.hero-eyebrow { display: inline-flex; align-items: center; gap: .5rem; margin-bottom: 1.3rem;
  padding: .4rem .9rem; border-radius: 20px; font-size: .66rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.4px;
  background: var(--maroon-soft); border: 1px solid rgba(123,29,29,.14); color: var(--maroon); }
.hero h1 { font-family: 'Fraunces', serif; font-weight: 700; font-size: 3.1rem; line-height: 1.08;
  letter-spacing: -.025em; color: var(--ink); max-width: 16ch; margin: 0 auto .9rem; }
.hero h1 em { font-style: italic; color: var(--maroon); }
.hero-sub { font-size: 1.04rem; line-height: 1.7; color: var(--ink2); max-width: 56ch; margin: 0 auto 2rem; }
.hero-cta { display: flex; flex-wrap: wrap; gap: .7rem; justify-content: center; margin-bottom: 1.8rem; }
.btn { display: inline-flex; align-items: center; gap: .55rem; padding: .9rem 1.6rem; border-radius: 12px;
  font-family: 'DM Sans', sans-serif; font-size: .95rem; font-weight: 600; cursor: pointer; transition: all .22s cubic-bezier(.22,1,.36,1); }
.btn-primary { background: var(--maroon-d); color: #fff; box-shadow: 0 4px 0 var(--maroon-dd), 0 8px 20px rgba(74,14,14,.25); }
.btn-primary:hover { background: var(--maroon); transform: translateY(-2px); box-shadow: 0 6px 0 var(--maroon-dd), 0 14px 28px rgba(74,14,14,.3); }
.btn-primary:active { transform: translateY(1px); box-shadow: 0 2px 0 var(--maroon-dd); }
.btn-ghost { background: var(--surface); color: var(--maroon-d); border: 1.5px solid var(--border); }
.btn-ghost:hover { border-color: var(--maroon); background: var(--maroon-soft); transform: translateY(-2px); }
.btn-arrow { width: 20px; height: 20px; background: rgba(255,255,255,.18); border-radius: 50%;
  display: flex; align-items: center; justify-content: center; font-size: .65rem; transition: transform .2s; }
.btn-primary:hover .btn-arrow { transform: translateX(3px); }
.hero-pills { display: flex; flex-wrap: wrap; gap: .5rem; justify-content: center; }
.hpill { display: inline-flex; align-items: center; gap: .4rem; padding: .35rem .8rem; border-radius: 20px;
  background: var(--surface); border: 1px solid var(--border); font-size: .72rem; font-weight: 600; color: var(--ink2); }
.hpill i { color: var(--gold); font-size: .68rem; }

/* ══ CREDIBILITY STRIP ══ */
.cred { border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); background: rgba(255,255,255,.5); }
.cred-in { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: .6rem 1.5rem; padding: 1rem 1.5rem; }
.cred-item { display: inline-flex; align-items: center; gap: .5rem; font-size: .73rem; font-weight: 600; color: var(--ink2); text-transform: uppercase; letter-spacing: .8px; }
.cred-item i { color: var(--gold); font-size: .82rem; }
.cred-sep { width: 4px; height: 4px; border-radius: 50%; background: var(--border); }
@media (max-width: 640px) { .cred-sep { display: none; } }

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

/* ══ MODULES / CAPABILITIES ══ */
.mod-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
.mod-card { display: flex; gap: .9rem; align-items: flex-start; background: var(--surface); border: 1px solid var(--border);
  border-radius: 14px; padding: 1.2rem 1.25rem; box-shadow: 0 2px 8px rgba(44,10,10,.05);
  transition: transform .2s, box-shadow .2s, border-color .2s; }
.mod-card:hover { transform: translateY(-3px); border-color: rgba(123,29,29,.2); box-shadow: 0 8px 20px rgba(74,14,14,.10); }
.mod-ic { width: 42px; height: 42px; border-radius: 11px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
  font-size: 1.05rem; color: var(--maroon); background: var(--maroon-soft); border: 1px solid rgba(123,29,29,.14); }
.mod-tx b { display: block; font-size: .95rem; font-weight: 700; color: var(--ink); margin-bottom: .2rem; letter-spacing: -.01em; }
.mod-tx span { display: block; font-size: .8rem; line-height: 1.55; color: var(--ink2); }
@media (max-width: 900px) { .mod-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 640px) { .mod-grid { grid-template-columns: 1fr; } }

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
.about-eyebrow { font-size: .64rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.6px; color: var(--gold); margin-bottom: .8rem; }
.about h2 { font-family: 'Fraunces', serif; font-weight: 700; font-size: 1.9rem; line-height: 1.2; letter-spacing: -.02em; margin-bottom: .9rem; }
.about p { font-size: .92rem; line-height: 1.75; color: rgba(255,255,255,.78); }
.about-points { display: flex; flex-direction: column; gap: .85rem; }
.about-point { display: flex; gap: .8rem; align-items: flex-start; }
.ap-ic { width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
  font-size: .9rem; background: rgba(201,150,12,.16); border: 1px solid rgba(201,150,12,.3); color: var(--gold); }
.about-point b { display: block; font-size: .9rem; font-weight: 600; color: #fff; }
.about-point span { display: block; font-size: .78rem; line-height: 1.5; color: rgba(255,255,255,.62); margin-top: .12rem; }

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
  .hero { padding: 3rem 0 2.4rem; }
  .hero h1 { font-size: 2.05rem; }
  .hero-sub { font-size: .95rem; }
  .rep-grid { grid-template-columns: 1fr; }
  .sec-title { font-size: 1.6rem; }
  .btn { width: 100%; justify-content: center; }
  .hero-cta { flex-direction: column; }
}
</style>
</head>
<body>
<div class="bg-glow-a"></div><div class="bg-glow-b"></div><div class="bg-grid"></div>

<div class="wrap">

  <!-- shared top navigation -->
  <?php $nav_active = 'home'; require __DIR__ . '/includes/site_nav.php'; ?>

  <!-- ══ HERO ══ -->
  <header class="hero">
    <div class="container">
      <span class="hero-eyebrow"><i class="fas fa-building-shield"></i> Batangas Eastern Colleges · Property Management Office</span>
      <h1>Defective Equipment Reporting, <em>handled by the PMO</em></h1>
      <p class="hero-sub">The official channel of Batangas Eastern Colleges for reporting, tracking, and resolving campus equipment concerns — verified and managed end-to-end by the Property Management Office.</p>
      <div class="hero-cta">
        <a class="btn btn-primary" href="student_index.php">Report defective equipment <span class="btn-arrow"><i class="fas fa-arrow-right"></i></span></a>
        <a class="btn btn-ghost" href="track_report.php"><i class="fas fa-magnifying-glass"></i> Track a report</a>
      </div>
      <div class="hero-pills">
        <span class="hpill"><i class="fas fa-id-card"></i> Official BEC access</span>
        <span class="hpill"><i class="fas fa-envelope"></i> Email confirmation</span>
        <span class="hpill"><i class="fas fa-route"></i> Tracked end-to-end</span>
        <span class="hpill"><i class="fas fa-user-shield"></i> Privacy-protected</span>
      </div>
    </div>
  </header>

  <!-- ══ CREDIBILITY STRIP ══ -->
  <div class="cred">
    <div class="container cred-in">
      <span class="cred-item"><i class="fas fa-certificate"></i> Official institutional system</span>
      <span class="cred-sep"></span>
      <span class="cred-item"><i class="fas fa-building-columns"></i> Property Management Office</span>
      <span class="cred-sep"></span>
      <span class="cred-item"><i class="fas fa-shield-halved"></i> Secure OTP-protected access</span>
      <span class="cred-sep"></span>
      <span class="cred-item"><i class="fas fa-award"></i> Batangas Eastern Colleges · Est. 1940</span>
    </div>
  </div>

  <!-- ══ PORTALS ══ -->
  <section class="section" id="portals">
    <div class="container">
      <div class="sec-head">
        <span class="sec-eyebrow"><span class="dot"></span> Get started</span>
        <h2 class="sec-title">Report equipment to the <em>PMO</em></h2>
        <p class="sec-sub">Sign in with your official Batangas Eastern Colleges email to file a defect report and follow it through to resolution.</p>
      </div>
      <div class="portal-grid portal-grid--single">
        <a class="portal-card" href="student_index.php">
          <div class="pc-ic"><i class="fas fa-user-graduate"></i></div>
          <div class="pc-title">Reporter Portal</div>
          <p class="pc-desc">For students, faculty, and staff — file a defect report with your official BEC email and track it through to resolution.</p>
          <span class="pc-enter">Enter portal <i class="fas fa-arrow-right"></i></span>
        </a>
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
        <div class="mod-card"><div class="mod-ic"><i class="fas fa-clipboard-list"></i></div><div class="mod-tx"><b>Defect Reporting</b><span>Reporters log equipment issues with photos, location, and priority.</span></div></div>
        <div class="mod-card"><div class="mod-ic"><i class="fas fa-clipboard-check"></i></div><div class="mod-tx"><b>Review &amp; Approval</b><span>The PMO verifies every report before any work begins.</span></div></div>
        <div class="mod-card"><div class="mod-ic"><i class="fas fa-people-carry-box"></i></div><div class="mod-tx"><b>Technician Assignment</b><span>Route cases to the right staff with balanced workloads.</span></div></div>
        <div class="mod-card"><div class="mod-ic"><i class="fas fa-screwdriver-wrench"></i></div><div class="mod-tx"><b>Work Orders</b><span>Technicians track repair progress through to completion.</span></div></div>
        <div class="mod-card"><div class="mod-ic"><i class="fas fa-boxes-stacked"></i></div><div class="mod-tx"><b>Inventory</b><span>Maintain equipment records, asset tags, and locations.</span></div></div>
        <div class="mod-card"><div class="mod-ic"><i class="fas fa-calendar-check"></i></div><div class="mod-tx"><b>Preventive Maintenance</b><span>Schedule recurring upkeep before equipment fails.</span></div></div>
        <div class="mod-card"><div class="mod-ic"><i class="fas fa-coins"></i></div><div class="mod-tx"><b>Budget Requests</b><span>Raise and track funding for repairs and parts.</span></div></div>
        <div class="mod-card"><div class="mod-ic"><i class="fas fa-chart-line"></i></div><div class="mod-tx"><b>Analytics &amp; Reports</b><span>Dashboards and exports for data-driven decisions.</span></div></div>
        <div class="mod-card"><div class="mod-ic"><i class="fas fa-robot"></i></div><div class="mod-tx"><b>AI Assistant (Becca)</b><span>Built-in guidance and troubleshooting, anytime.</span></div></div>
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
              <span class="ap-ic"><i class="fas fa-clipboard-check"></i></span>
              <div><b>Verification &amp; approval</b><span>Every report is reviewed by the PMO before work begins.</span></div>
            </div>
            <div class="about-point">
              <span class="ap-ic"><i class="fas fa-people-carry-box"></i></span>
              <div><b>Technician assignment</b><span>Cases are routed to the right personnel with balanced workloads.</span></div>
            </div>
            <div class="about-point">
              <span class="ap-ic"><i class="fas fa-chart-line"></i></span>
              <div><b>Accountability &amp; tracking</b><span>Status, history, and resolution are recorded end-to-end.</span></div>
            </div>
          </div>
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

  <!-- shared footer -->
  <?php require __DIR__ . '/includes/site_footer.php'; ?>

</div>

<?php require __DIR__ . '/includes/becca_widget.php'; ?>
</body>
</html>
