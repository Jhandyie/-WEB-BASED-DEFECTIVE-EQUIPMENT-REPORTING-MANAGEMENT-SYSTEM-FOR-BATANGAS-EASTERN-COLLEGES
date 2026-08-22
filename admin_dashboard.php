
<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once 'includes/auth.php';
requireRole('admin');
require_once 'config/database.php';
require_once 'file_storage_helpers.php';
require_once 'includes/notification_helper.php';
require_once __DIR__ . '/includes/sla_helper.php';
require_once __DIR__ . '/includes/preventive_helper.php';
@runSlaEscalationSweep();             // auto-escalate overdue reports (idempotent)
@runPreventiveMaintenanceSweep();     // generate due preventive-maintenance tasks (idempotent)

$admin_name  = $_SESSION['fullname'] ?? 'Administrator';
$admin_first = explode(' ', $admin_name)[0];

// -- CORE DATA ---------------------------------
$allReports        = getAllDefectReports();
// PMO/ITSO: scope this admin's overview to their own unit (un-triaged reports still show).
$adminUnit = adminUnitForUser($_SESSION['user_id'] ?? '');
if ($adminUnit !== '') {
    $allReports = array_values(array_filter($allReports, function ($r) use ($adminUnit) {
        $d = (string)($r['department_assigned'] ?? '');
        return $d === $adminUnit || $d === '';
    }));
}
$notificationCount = getUnreadNotificationCount(null);
$userCount         = getTotalUserCount();
function dashboardSummarizeText(?string $text, int $limit): string
{
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    if ($text === '') {
        return 'No description provided.';
    }
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, max(1, $limit - 1))) . '...';
}

function dashboardToWebPath(string $path): string
{
    $clean = str_replace('\\', '/', trim($path));
    if ($clean === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $clean)) {
        return $clean;
    }

    $root = str_replace('\\', '/', realpath(__DIR__) ?: __DIR__);
    $real = realpath($path);
    if ($real !== false) {
        $real = str_replace('\\', '/', $real);
        if (str_starts_with($real, $root . '/')) {
            return ltrim(substr($real, strlen($root)), '/');
        }
    }

    return ltrim($clean, '/');
}

function dashboardInferReportPhotos(array $row): array
{
    $photos = [];

    foreach (['photo_path', 'photo_url', 'photo', 'image_path'] as $photoCol) {
        if (!empty($row[$photoCol])) {
            $photos[] = dashboardToWebPath((string)$row[$photoCol]);
        }
    }

    foreach (['defect_photos', 'photo_paths', 'photos'] as $jsonCol) {
        if (empty($row[$jsonCol])) {
            continue;
        }
        $raw = $row[$jsonCol];
        if (is_array($raw)) {
            foreach ($raw as $p) {
                $photos[] = dashboardToWebPath((string)$p);
            }
            continue;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                foreach ($decoded as $p) {
                    $photos[] = dashboardToWebPath((string)$p);
                }
            } else {
                $photos[] = dashboardToWebPath($raw);
            }
        }
    }

    // Shared, once-per-request directory index instead of a glob per report —
    // globbing per row made this page time out once the school had a few
    // hundred reports on file (see becReportPhotoFiles in config/database.php).
    foreach (becReportPhotoFiles((string)($row['report_id'] ?? '')) as $match) {
        $photos[] = dashboardToWebPath($match);
    }

    $photos = array_values(array_unique(array_filter($photos, static function ($path): bool {
        if (!is_string($path) || trim($path) === '') {
            return false;
        }
        if (preg_match('#^https?://#i', $path)) {
            return true;
        }
        return is_file(__DIR__ . '/' . ltrim($path, '/'));
    })));

    return $photos;
}

// -- STATS - focused on defect reporting spec --
$totalReports    = count($allReports);
$pendingReports  = count(array_filter($allReports, fn($r) => $r['status'] === 'reported'));
$approvedReports = count(array_filter($allReports, fn($r) => $r['status'] === 'assigned'));
$inProgReports   = count(array_filter($allReports, fn($r) => $r['status'] === 'in_progress'));
$completedRep    = count(array_filter($allReports, fn($r) => in_array($r['status'], ['completed', 'verified', 'closed'])));
$rejectedRep     = count(array_filter($allReports, fn($r) => $r['status'] === 'rejected'));
$criticalRep     = count(array_filter($allReports, fn($r) => $r['priority'] === 'critical' && !in_array($r['status'], ['completed','verified','closed'])));

$unread = $notificationCount;

// -- SMART KPIs (decision-support) -------------
// Average resolution time (days) for resolved reports.
$resDays = [];
foreach ($allReports as $r) {
    if (!in_array($r['status'], ['completed','verified','closed'], true)) continue;
    $start = strtotime((string)($r['report_date'] ?? ''));
    $end   = strtotime((string)($r['completion_date'] ?? ''));
    if ($start && $end && $end >= $start) { $resDays[] = ($end - $start) / 86400; }
}
$avgResolution  = $resDays ? round(array_sum($resDays) / count($resDays), 1) : null;
$completionRate = $totalReports ? (int)round($completedRep / $totalReports * 100) : 0;
$activeRepairs  = $approvedReports + $inProgReports; // assigned + in progress

// SLA breaches: still-open reports older than a priority-based threshold (in days).
// Single source of truth: config/sla.php (same windows the technician portal shows).
require_once __DIR__ . '/config/sla.php';
$slaThresholds = becSlaDaysMap();
$nowTs = time();
$overdueReports = 0;
foreach ($allReports as $r) {
    if (in_array($r['status'], ['completed','verified','closed','rejected'], true)) continue;
    $thr   = $slaThresholds[strtolower((string)($r['priority'] ?? 'medium'))] ?? 5;
    $start = strtotime((string)($r['report_date'] ?? ''));
    if ($start && ($nowTs - $start) / 86400 > $thr) { $overdueReports++; }
}

// -- TECHNICIAN PERFORMANCE --------------------
$technicianStats = [];
try {
    $tconn = getDBConnection();
    $tres = $tconn->query("SELECT user_id, fullname FROM users WHERE role = 'technician' AND COALESCE(status,'active') = 'active' ORDER BY fullname");
    if ($tres) {
        while ($t = $tres->fetch_assoc()) {
            $technicianStats[(string)$t['user_id']] = [
                'name' => $t['fullname'] !== '' ? $t['fullname'] : (string)$t['user_id'],
                'open' => 0, 'completed' => 0, 'res_days' => [],
            ];
        }
    }
} catch (\Throwable $e) { /* no technicians yet */ }

foreach ($allReports as $r) {
    $tid = (string)($r['assigned_to'] ?? '');
    if ($tid === '' || !isset($technicianStats[$tid])) continue;
    $st = (string)($r['status'] ?? '');
    if (in_array($st, ['assigned','in_progress','waiting_for_materials'], true)) {
        $technicianStats[$tid]['open']++;
    } elseif (in_array($st, ['completed','verified','closed'], true)) {
        $technicianStats[$tid]['completed']++;
        $s = strtotime((string)($r['report_date'] ?? ''));
        $e = strtotime((string)($r['completion_date'] ?? ''));
        if ($s && $e && $e >= $s) { $technicianStats[$tid]['res_days'][] = ($e - $s) / 86400; }
    }
}
foreach ($technicianStats as &$ts) {
    $ts['avg']   = $ts['res_days'] ? round(array_sum($ts['res_days']) / count($ts['res_days']), 1) : null;
    $ts['total'] = $ts['open'] + $ts['completed'];
}
unset($ts);
uasort($technicianStats, static fn($a, $b) => ($b['completed'] <=> $a['completed']) ?: ($b['open'] <=> $a['open']));
$maxTechLoad = 1;
foreach ($technicianStats as $ts) { $maxTechLoad = max($maxTechLoad, $ts['total']); }

// -- ASSET HEALTH ------------------------------
$assetCounts = ['total' => 0, 'operational' => 0, 'repair' => 0, 'defective' => 0];
$topDefectiveAssets = [];
try {
    $aconn = getDBConnection();
    $cres = $aconn->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN LOWER(COALESCE(status,'')) IN ('available','operational','in_use','in-use','reserved') THEN 1 ELSE 0 END) AS operational,
            SUM(CASE WHEN LOWER(COALESCE(status,'')) IN ('maintenance','under_maintenance') THEN 1 ELSE 0 END) AS repair,
            SUM(CASE WHEN LOWER(COALESCE(status,'')) IN ('defective','faulty','damaged') THEN 1 ELSE 0 END) AS defective
        FROM equipment
        WHERE LOWER(COALESCE(status,'')) <> 'deleted'
    ");
    if ($cres && ($cr = $cres->fetch_assoc())) {
        $assetCounts = [
            'total'       => (int)($cr['total'] ?? 0),
            'operational' => (int)($cr['operational'] ?? 0),
            'repair'      => (int)($cr['repair'] ?? 0),
            'defective'   => (int)($cr['defective'] ?? 0),
        ];
    }
    $ares = $aconn->query("
        SELECT COALESCE(NULLIF(e.equipment_name,''),'Unknown') AS name,
               COALESCE(NULLIF(e.location,''),'—') AS location,
               COALESCE(NULLIF(e.status,''),'') AS status,
               COUNT(dr.report_id) AS reports
        FROM defect_reports dr
        JOIN equipment e ON e.equipment_id = dr.equipment_id
        GROUP BY e.equipment_id, e.equipment_name, e.location, e.status
        ORDER BY reports DESC
        LIMIT 5
    ");
    if ($ares) { while ($a = $ares->fetch_assoc()) { $topDefectiveAssets[] = $a; } }
} catch (\Throwable $e) { /* equipment table empty / not ready */ }
$maxAssetReports = 1;
foreach ($topDefectiveAssets as $a) { $maxAssetReports = max($maxAssetReports, (int)$a['reports']); }
// Health score: starts from the asset's current status, then eases down per defect report.
// Operational=100 base, Under maintenance=70, Defective=45; −8 pts per report; clamped 5–100.
function assetHealthScore(int $reports, string $status = ''): int {
    $s = strtolower(trim($status));
    $base = 100;
    if (in_array($s, ['defective', 'faulty', 'damaged'], true)) { $base = 45; }
    elseif (in_array($s, ['maintenance', 'under_maintenance'], true)) { $base = 70; }
    return max(5, min(100, $base - $reports * 8));
}

// -- PRIORITY ALERTS ---------------------------
$priorityAlerts = array_values(array_filter($allReports, fn($r) =>
    in_array($r['priority'], ['critical','high']) &&
    !in_array($r['status'], ['completed','verified','closed','rejected'])
));
usort($priorityAlerts, static function (array $a, array $b): int {
    $priorityRank = ['critical' => 0, 'urgent' => 1, 'high' => 2, 'medium' => 3, 'low' => 4];
    $aRank = $priorityRank[strtolower((string)($a['priority'] ?? 'low'))] ?? 99;
    $bRank = $priorityRank[strtolower((string)($b['priority'] ?? 'low'))] ?? 99;
    if ($aRank !== $bRank) {
        return $aRank <=> $bRank;
    }
    return strtotime((string)($b['report_date'] ?? '')) <=> strtotime((string)($a['report_date'] ?? ''));
});

// -- RECENT DEFECT REPORTS ---------------------
$recentReports = array_values(array_filter($allReports, fn($r) =>
    !in_array($r['status'], ['completed','verified','closed'])
));
usort($recentReports, static fn($a, $b) => strtotime((string)($b['report_date'] ?? '')) <=> strtotime((string)($a['report_date'] ?? '')));
$recentReports = array_slice($recentReports, 0, 6);

$recentReportDetails = [];
foreach ($recentReports as $row) {
    $detailed = $row;
    $detailed['photos'] = dashboardInferReportPhotos($row);
    $recentReportDetails[(string)$row['report_id']] = $detailed;
}

// -- CHART DATA --------------------------------
$defectTrends      = getDefectsOverTime(7);
$reservationTrends = getReservationsOverTime(7);
$equipmentUsage    = getEquipmentUsageOverTime(7);

$sysStats = getSystemStatistics();
$equipDist = [
    ['label'=>'Available',    'value'=>$sysStats['available_equipment']  ?? 0, 'color'=>'#22C55E'],
    ['label'=>'In Use',       'value'=>$sysStats['in_use_equipment']     ?? 0, 'color'=>'#3B82F6'],
    ['label'=>'Maintenance',  'value'=>$sysStats['maintenance_equipment']?? 0, 'color'=>'#F59E0B'],
    ['label'=>'Defective',    'value'=>$sysStats['defective_equipment']  ?? 0, 'color'=>'#EF4444'],
];

// -- HELPER FUNCTIONS --------------------------
function stCls($s){
    return [
        'reported'    => 'pend',
        'assigned'    => 'prog',
        'in_progress' => 'prog',
        'completed'   => 'done',
        'verified'    => 'done',
        'closed'      => 'done',
        'rejected'    => 'rej',
    ][$s] ?? 'pend';
}

function stLbl($s){
    return [
        'reported'    => 'Reported',
        'assigned'    => 'Assigned',
        'in_progress' => 'In Progress',
        'completed'   => 'Completed',
        'verified'    => 'Verified',
        'closed'      => 'Closed',
        'rejected'    => 'Rejected',
    ][$s] ?? ucfirst(str_replace('_', ' ', (string)$s));
}
function prCls($p){return['critical'=>'crit','high'=>'hi','medium'=>'med','low'=>'lo'][$p]??'lo';}
function prLbl($p){return ucfirst($p??'-');}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="icon" type="image/png" href="assets/logs.png">
<title>Admin Dashboard - BEC Equipment Management</title>
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="css/typography.css">
<script src="assets/vendor/js/chart.umd.min.js"></script>
<link rel="stylesheet" href="assets/css/admin-shell.css">
<style>

/* =======================================================
   BEC ADMIN DASHBOARD - Refined Industrial Theme
   Batangas Eastern Colleges  -  Equipment Management
   Fonts: Outfit (display) + DM Sans (body)
======================================================= */
:root{
  /* Brand */
  --m1:#2D0505; --m2:#4A0E0E; --m3:#7B1D1D; --m4:#9B2C2C;
  --g1:#92600A; --g2:#D4A017; --g3:#F0C040; --gp:#FEF9E7;
  /* Surface */
  --bg:#F4EFE6; --s1:#FFFFFF; --s2:#FAF7F0; --s3:#F2EAD9;
  --bdr:#E5D9C6; --bdr2:#D0C0A8;
  /* Text */
  --t1:#1A0808; --t2:#5C3838; --t3:#9C7A7A; --t4:#C8ABAB;
  /* Status */
  --pend-bg:#FEF9E7; --pend-c:#92600A;
  --prog-bg:#EFF6FF; --prog-c:#1D4ED8;
  --done-bg:#F0FDF4; --done-c:#15803D;
  --rej-bg:#FFF1F2;  --rej-c:#BE123C;
  --crit-bg:#FFF7ED; --crit-c:#C2410C;
  --high-bg:#FFFBEB; --high-c:#B45309;
  --med-bg:#EFF6FF;  --med-c:#1D4ED8;
  --low-bg:#F0FDF4;  --low-c:#15803D;
  /* Depth */
  --sh1:0 1px 3px rgba(45,5,5,.06),0 1px 2px rgba(45,5,5,.04);
  --sh2:0 4px 12px rgba(45,5,5,.08),0 2px 4px rgba(45,5,5,.05);
  --sh3:0 12px 32px rgba(45,5,5,.12),0 4px 8px rgba(45,5,5,.07);
  --sh3d:0 6px 0 rgba(45,5,5,.15),0 10px 28px rgba(45,5,5,.10);
  --sh3dh:0 10px 0 rgba(45,5,5,.18),0 16px 40px rgba(45,5,5,.14);
  --r1:8px;--r2:12px;--r3:18px;--r4:24px;
  /* --sb is the name the shared shell and sidebar_autohide.js use; --sidebar is
     kept as an alias because this page's own layout rules still reference it.
     Both must stay equal or the content offset won't match the sidebar. */
  --sb:262px;
  --sidebar:262px;
}

*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{
  font-family:'DM Sans',sans-serif;
  background:var(--bg);
  color:var(--t1);min-height:100vh;overflow-x:hidden;
  /* subtle noise texture */
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.025'/%3E%3C/svg%3E");
}

/* sidebar styling lives in assets/css/admin-shell.css */

/* -- TOPBAR ------------------------------------- */
.main{margin-left:var(--sidebar);min-height:100vh;display:flex;flex-direction:column;}
/* .topbar comes from assets/css/admin-shell.css — this page had its own copy at
   60px/1.875rem, two pixels off every other admin header. */
.tb-left{display:flex;align-items:center;gap:.6rem;}
.mob-btn{display:none;background:none;border:none;font-size:1.1rem;cursor:pointer;color:var(--t2);padding:.2rem;}
.pg-title{font-family:'Outfit',sans-serif;font-weight:700;font-size:1rem;color:var(--t1);}
.bc{font-size:.68rem;color:var(--t3);display:flex;align-items:center;gap:.25rem;}
.bc a{color:var(--t3);text-decoration:none;}.bc a:hover{color:var(--m3);}
.bc i{font-size:.55rem;}
.tb-right{display:flex;align-items:center;gap:.625rem;}
.date-chip{
  background:var(--s2);border:1px solid var(--bdr);
  border-radius:30px;padding:.3rem .85rem;
  font-size:.7rem;color:var(--t2);font-weight:600;
  display:flex;align-items:center;gap:.32rem;
}
.date-chip i{color:var(--g2);font-size:.72rem;}
.tb-btn{
  width:36px;height:36px;
  background:var(--s2);border:1px solid var(--bdr);
  border-radius:var(--r1);
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;color:var(--t2);font-size:.88rem;
  transition:all .18s;box-shadow:none;
  text-decoration:none;position:relative;
}
.tb-btn:hover{background:var(--m3);color:#fff;transform:none;box-shadow:none;}
.npip{position:absolute;top:5px;right:5px;width:8px;height:8px;background:var(--g2);border-radius:50%;border:2px solid var(--s1);animation:pip 2s ease-in-out infinite;}
@keyframes pip{0%,100%{transform:scale(1);}50%{transform:scale(1.3);}}

/* Export btn */
.exp-btn{
  display:inline-flex;align-items:center;gap:.4rem;
  padding:.45rem 1rem;
  background:linear-gradient(135deg,var(--g2),var(--g3));
  color:var(--m1);border:none;border-radius:var(--r1);
  font-family:'DM Sans',sans-serif;font-size:.78rem;font-weight:700;
  cursor:pointer;transition:all .2s;
  box-shadow:none;
}
.exp-btn:hover{transform:none;box-shadow:none;}
.exp-wrap{position:relative;display:inline-block;}
.exp-menu{position:absolute;top:calc(100% + 8px);right:0;z-index:300;min-width:252px;background:#fff;border:1px solid var(--bdr,#E8DDD0);border-radius:12px;box-shadow:0 14px 36px rgba(44,10,10,.22);padding:.4rem;display:none;}
.exp-menu.open{display:block;animation:mUp .15s ease;}
.exp-menu-label{font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--t3,#9E8070);padding:.55rem .7rem .3rem;}
.exp-menu a{display:flex;align-items:center;gap:.55rem;padding:.5rem .7rem;border-radius:8px;font-size:.8rem;font-weight:600;color:var(--t1,#1C1008);cursor:pointer;text-decoration:none;}
.exp-menu a:hover{background:var(--m5,rgba(123,29,29,.08));color:var(--m2,#7B1D1D);}
.exp-menu a i{width:16px;text-align:center;color:var(--m3,#7B1D1D);}
.exp-menu-sep{height:1px;background:var(--bdr,#E8DDD0);margin:.35rem .3rem;}

/* -- CONTENT ------------------------------------ */
.content{padding:1.625rem 1.875rem;flex:1;}

/* -- WELCOME HERO ------------------------------- */
.hero{
  background:linear-gradient(125deg,var(--m1) 0%,var(--m2) 40%,var(--m3) 70%,#5C1212 100%);
  border-radius:var(--r4);padding:1.75rem 2rem;
  margin-bottom:1.625rem;
  display:flex;align-items:center;justify-content:space-between;gap:1.5rem;
  position:relative;overflow:hidden;
  box-shadow:var(--sh3d);
  transition:transform .28s,box-shadow .28s;
}
.hero:hover{transform:none;box-shadow:var(--sh3dh);}
/* Decorative circles */
.hero::before{content:'';position:absolute;right:-40px;top:-60px;width:220px;height:220px;border-radius:50%;background:rgba(212,160,23,.06);}
.hero::after{content:'';position:absolute;right:80px;bottom:-70px;width:160px;height:160px;border-radius:50%;background:rgba(212,160,23,.04);}
.hero-left{}
.hero-eyebrow{font-size:.63rem;text-transform:uppercase;letter-spacing:3px;color:rgba(255,255,255,.38);margin-bottom:.35rem;display:flex;align-items:center;gap:.35rem;}
.hero-eyebrow i{color:var(--g2);}
.hero-title{font-family:'Outfit',sans-serif;font-size:1.75rem;font-weight:800;color:#fff;line-height:1.2;margin-bottom:.3rem;text-shadow:0 2px 10px rgba(0,0,0,.25);}
.hero-title .hl{color:var(--g3);}
.hero-title .unit-badge{display:inline-flex;align-items:center;gap:.35rem;vertical-align:middle;margin-left:.6rem;padding:.24rem .66rem;border-radius:999px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);color:#fff;font-family:'DM Sans',sans-serif;font-size:.6rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;text-shadow:none;}
.hero-title .unit-badge i{font-size:.58rem;}
.hero-sub{color:rgba(255,255,255,.48);font-size:.84rem;}
.hero-meta{
  display:flex;align-items:center;gap:1.25rem;flex-shrink:0;
  position:relative;z-index:1;
}
.hero-seal{
  width:80px;height:80px;border-radius:50%;
  border:2px solid rgba(212,160,23,.4);
  box-shadow:0 0 0 5px rgba(212,160,23,.08),0 6px 20px rgba(0,0,0,.4);
  object-fit:cover;
  animation:hsFl 5s ease-in-out infinite;
}
@keyframes hsFl{0%,100%{transform:translateY(0);}50%{transform:translateY(-5px);}}
.hero-timestamp{
  display:flex;flex-direction:column;gap:.25rem;text-align:right;
}
.hero-ts-date{font-family:'Outfit',sans-serif;font-size:.88rem;color:rgba(255,255,255,.7);font-weight:600;}
.hero-ts-time{font-size:.72rem;color:rgba(255,255,255,.35);}

/* -- ALERTS PANEL ------------------------------- */
.alert-panel{
  background:linear-gradient(135deg,#FFF7F7,#FFFBF7);
  border:1.5px solid rgba(220,38,38,.18);
  border-radius:var(--r3);padding:1.25rem 1.5rem;
  margin-bottom:1.625rem;
  box-shadow:var(--sh1);
  animation:alertIn .3s ease;
}
@keyframes alertIn{from{opacity:0;transform:translateY(-6px);}to{opacity:1;transform:translateY(0);}}
.ap-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.875rem;}
.ap-head h3{font-family:'Outfit',sans-serif;font-size:.95rem;font-weight:700;color:#991B1B;display:flex;align-items:center;gap:.4rem;}
.ap-cnt{background:#DC2626;color:#fff;font-size:.58rem;font-weight:900;padding:2px 7px;border-radius:20px;animation:bp 2s ease-in-out infinite;}
.alert-row{display:flex;align-items:flex-start;gap:.875rem;padding:.65rem .875rem;border-radius:var(--r2);margin-bottom:.4rem;cursor:default;transition:all .16s;}
.alert-row:hover{transform:none;}
.alert-row.crit{background:rgba(220,38,38,.06);border-left:3px solid #DC2626;}
.alert-row.high{background:rgba(234,179,8,.06);border-left:3px solid #CA8A04;}
.ai{width:32px;height:32px;border-radius:var(--r1);display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0;}
.alert-row.crit .ai{background:#FEE2E2;color:#DC2626;}
.alert-row.high .ai{background:#FEF9C3;color:#CA8A04;}
.ac strong{display:block;font-size:.82rem;font-weight:700;color:var(--t1);}
.ac p{font-size:.73rem;color:var(--t2);margin-top:.1rem;line-height:1.4;}
.ac time{font-size:.65rem;color:var(--t3);}

/* -- QUICK ACTIONS ------------------------------ */
.qa-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.875rem;margin-bottom:1.625rem;}
.qa-card{
  background:var(--s1);
  border:1.5px solid var(--bdr);
  border-radius:var(--r3);padding:1.1rem 1.25rem;
  display:flex;align-items:center;gap:.875rem;
  text-decoration:none;color:var(--t1);
  transition:all .22s cubic-bezier(.4,0,.2,1);
  box-shadow:var(--sh1);
  position:relative;overflow:hidden;
}
.qa-card::after{content:'';position:absolute;bottom:0;left:0;width:100%;height:2px;background:var(--qc,var(--m3));transform:scaleX(0);transform-origin:left;transition:transform .28s;}
.qa-card:hover{transform:none;box-shadow:var(--sh3d);border-color:transparent;}
.qa-card:hover::after{transform:scaleX(1);}
.qa-ic{width:40px;height:40px;border-radius:var(--r2);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;transition:transform .25s;}
.qa-card:hover .qa-ic{transform:none;}
.qa-text strong{display:block;font-size:.82rem;font-weight:700;color:var(--t1);}
.qa-text span{font-size:.68rem;color:var(--t3);}

.qa-card.c1{--qc:var(--m3);}  .qa-card.c1 .qa-ic{background:#FDECEA;color:var(--m3);}
.qa-card.c2{--qc:#1D4ED8;} .qa-card.c2 .qa-ic{background:#EFF6FF;color:#1D4ED8;}
.qa-card.c3{--qc:#15803D;} .qa-card.c3 .qa-ic{background:#F0FDF4;color:#15803D;}
.qa-card.c4{--qc:#7C3AED;} .qa-card.c4 .qa-ic{background:#F5F3FF;color:#7C3AED;}

/* -- STATS GRID --------------------------------- */
.stats-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:.75rem;margin-bottom:1.625rem;}
.sc{
  background:var(--s1);border-radius:var(--r3);padding:1.1rem 1.125rem;
  border:1px solid var(--bdr);
  position:relative;overflow:hidden;
  transition:all .26s cubic-bezier(.4,0,.2,1);
  box-shadow:var(--sh1);cursor:pointer;
}
.sc::before{content:'';position:absolute;top:-20px;right:-20px;width:80px;height:80px;border-radius:50%;background:var(--sk);opacity:.04;transition:all .28s;}
.sc::after{content:'';position:absolute;bottom:0;left:0;width:100%;height:2px;background:var(--sk);border-radius:0 0 var(--r3) var(--r3);transform:scaleX(0);transform-origin:left;transition:transform .32s;}
.sc:hover{transform:none;box-shadow:var(--sh3);border-color:transparent;}
.sc:hover::before{transform:none;opacity:.08;}
.sc:hover::after{transform:scaleX(1);}

.sc.s0{--sk:var(--m3);   --sk-s:rgba(123,29,29,.12);}
.sc.s1{--sk:#D97706;     --sk-s:rgba(217,119,6,.12);}
.sc.s2{--sk:#2563EB;     --sk-s:rgba(37,99,235,.12);}
.sc.s3{--sk:#16A34A;     --sk-s:rgba(22,163,74,.12);}
.sc.s4{--sk:#7C3AED;     --sk-s:rgba(124,58,237,.12);}
.sc.s5{--sk:#DC2626;     --sk-s:rgba(220,38,38,.12);}
.sc.s6{--sk:#C2410C;     --sk-s:rgba(194,65,12,.12);}

.sc-ic{
  width:38px;height:38px;border-radius:var(--r1);
  display:flex;align-items:center;justify-content:center;
  font-size:.85rem;margin-bottom:.625rem;
  background:var(--ic-bg);color:var(--ic-fg);
  box-shadow:none;
  transition:all .26s;position:relative;z-index:1;
}
.sc:hover .sc-ic{transform:none;}
.sc.s0 .sc-ic{--ic-bg:#FDECEA;--ic-fg:var(--m3);}
.sc.s1 .sc-ic{--ic-bg:#FFFBEB;--ic-fg:#D97706;}
.sc.s2 .sc-ic{--ic-bg:#EFF6FF;--ic-fg:#2563EB;}
.sc.s3 .sc-ic{--ic-bg:#F0FDF4;--ic-fg:#16A34A;}
.sc.s4 .sc-ic{--ic-bg:#F5F3FF;--ic-fg:#7C3AED;}
.sc.s5 .sc-ic{--ic-bg:#FFF1F2;--ic-fg:#DC2626;}
.sc.s6 .sc-ic{--ic-bg:#FFF7ED;--ic-fg:#C2410C;animation:critPulse 2s ease-in-out infinite;}
@keyframes critPulse{0%,100%{box-shadow:0 0 0 0 rgba(194,65,12,.3);}50%{box-shadow:0 0 0 5px rgba(194,65,12,0);}}

.sc-num{font-family:'Outfit',sans-serif;font-size:2rem;font-weight:800;color:var(--t1);line-height:1;margin-bottom:.18rem;position:relative;z-index:1;transition:color .26s;}
.sc:hover .sc-num{color:var(--sk);}
.sc-lbl{font-size:.6rem;text-transform:uppercase;letter-spacing:.8px;color:var(--t3);font-weight:700;position:relative;z-index:1;line-height:1.3;}

/* -- CHARTS + TABLE GRID ------------------------ */
.lower-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;}
.full-grid{margin-bottom:1.25rem;}

/* -- PANEL BASE --------------------------------- */
.panel{background:var(--s1);border-radius:var(--r3);border:1px solid var(--bdr);box-shadow:var(--sh1);overflow:hidden;transition:box-shadow .22s;}
.panel:hover{box-shadow:var(--sh2);}
.panel-h{
  padding:.875rem 1.25rem;border-bottom:1px solid var(--bdr);
  display:flex;align-items:center;justify-content:space-between;
  background:linear-gradient(to right,var(--s2),var(--s1));
}
.panel-h h3{font-family:'Outfit',sans-serif;font-size:.9rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:.35rem;margin:0;}
.panel-h h3 i{color:var(--m3);font-size:.85rem;}

/* -- TABLE -------------------------------------- */
.tbl{width:100%;border-collapse:collapse;}
.tbl thead th{padding:.56rem 1.1rem;font-size:.62rem;text-transform:uppercase;letter-spacing:.9px;color:var(--t3);font-weight:800;text-align:left;background:var(--s2);border-bottom:1.5px solid var(--bdr);white-space:nowrap;}
.tbl tbody td{padding:.72rem 1.1rem;font-size:.8rem;color:var(--t1);border-bottom:1px solid var(--bdr);vertical-align:middle;}
.tbl tbody tr:last-child td{border-bottom:none;}
.tbl tbody tr{transition:background .1s,transform .1s;}
.tbl tbody tr:hover td{background:var(--s2);}
.tbl tbody tr:hover{transform:none;}
.en{font-weight:700;font-size:.82rem;}
.es{font-size:.66rem;color:var(--t3);}
.rid{font-family:'Outfit',sans-serif;font-weight:800;color:var(--m3);font-size:.78rem;white-space:nowrap;}

/* -- BADGES ------------------------------------- */
.badge{display:inline-flex;align-items:center;gap:.25rem;padding:.22rem .6rem;border-radius:20px;font-size:.63rem;font-weight:800;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;}
.badge::before{content:'';width:4px;height:4px;border-radius:50%;background:currentColor;animation:dot 2s ease-in-out infinite;}
@keyframes dot{0%,100%{opacity:1;}50%{opacity:.4;}}
.b-pend{background:var(--pend-bg);color:var(--pend-c);}
.b-prog{background:var(--prog-bg);color:var(--prog-c);}
.b-done{background:var(--done-bg);color:var(--done-c);}
.b-rej {background:var(--rej-bg); color:var(--rej-c);}
.b-crit{background:var(--crit-bg);color:var(--crit-c);}
.b-hi{background:var(--high-bg);color:var(--high-c);}
.b-high{background:var(--high-bg);color:var(--high-c);}
.b-med {background:var(--med-bg); color:var(--med-c);}
.b-lo{background:var(--low-bg);color:var(--low-c);}
.b-low {background:var(--low-bg); color:var(--low-c);}

/* -- ACTION BUTTONS ----------------------------- */
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.4rem .875rem;border-radius:var(--r1);font-family:'DM Sans',sans-serif;font-size:.78rem;font-weight:700;cursor:pointer;border:none;transition:all .18s;text-decoration:none;white-space:nowrap;}
.btn:hover{transform:none;}
.btn:active{transform:translateY(0);}
.btn-maroon{background:linear-gradient(135deg,var(--m3),var(--m4));color:#fff;box-shadow:none;}
.btn-maroon:hover{box-shadow:none;}
.btn-gold{background:linear-gradient(135deg,var(--g2),var(--g3));color:var(--m1);box-shadow:none;}
.btn-gold:hover{box-shadow:none;}
.btn-ghost{background:var(--s2);color:var(--t2);border:1px solid var(--bdr);}
.btn-ghost:hover{background:var(--s3);}
.btn-icon{width:28px;height:28px;display:flex;align-items:center;justify-content:center;border-radius:var(--r1);padding:0;font-size:.75rem;}
.btn-view{background:#EFF6FF;color:#1D4ED8;}
.btn-view:hover{background:#DBEAFE;}
.btn-edit{background:#F0FDF4;color:#15803D;}
.btn-edit:hover{background:#DCFCE7;}
.btn-del{background:#FFF1F2;color:#BE123C;}
.btn-del:hover{background:#FFE4E6;}

/* -- LEGEND ------------------------------------- */
.legend{display:flex;flex-direction:column;gap:.5rem;margin-top:.75rem;}
.li{display:flex;align-items:center;gap:.6rem;}
.lc{width:12px;height:12px;border-radius:3px;flex-shrink:0;}
.ll{flex:1;font-size:.72rem;color:var(--t2);}
.lv{font-weight:700;font-size:.75rem;color:var(--t1);}

/* -- TOAST -------------------------------------- */
.ttray{position:fixed;top:1.25rem;left:50%;transform:translateX(-50%);align-items:center;display:flex;flex-direction:column;gap:.4rem;z-index:9999;}
.toast{background:var(--s1);border:1px solid var(--bdr);border-radius:var(--r2);padding:.72rem .9rem;display:flex;align-items:flex-start;gap:.55rem;box-shadow:var(--sh3);min-width:250px;animation:tIn .22s ease;border-left:3px solid var(--m3);}
.toast.ok{border-left-color:#15803D;}
.toast.err{border-left-color:#DC2626;}
@keyframes tIn{from{transform:translateX(60px);opacity:0}to{transform:translateX(0);opacity:1}}
.tt{font-size:.78rem;font-weight:700;color:var(--t1);}
.tm{font-size:.7rem;color:var(--t2);margin-top:1px;}

/* -- RESPONSIVE --------------------------------- */
@media(max-width:1280px){.stats-grid{grid-template-columns:repeat(4,1fr);}
  .qa-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:1024px){.lower-grid{grid-template-columns:1fr;}
  .stats-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:768px){
  .sb{transform:translateX(-100%);} .sb.open{transform:translateX(0);}
  .main{margin-left:0;} .content{padding:1rem;}
  .topbar{padding:0 1rem;} .mob-btn{display:flex;}
  .stats-grid{grid-template-columns:1fr 1fr;} .qa-grid{grid-template-columns:1fr;}
  .date-chip{display:none;} .hero-meta{display:none;}
}

/* stagger entrance */
.sc{animation:scIn .35s ease both;}
.sc:nth-child(1){animation-delay:.05s;} .sc:nth-child(2){animation-delay:.1s;}
.sc:nth-child(3){animation-delay:.15s;} .sc:nth-child(4){animation-delay:.2s;}
.sc:nth-child(5){animation-delay:.25s;} .sc:nth-child(6){animation-delay:.3s;}
.sc:nth-child(7){animation-delay:.35s;}
@keyframes scIn{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}
@keyframes mUp{from{transform:translateY(16px);opacity:0}to{opacity:1;transform:translateY(0)}}
/* Keyboard accessibility: visible focus ring on interactive elements (WCAG 2.4.7). */
a:focus-visible, button:focus-visible, .btn:focus-visible, .nav-item:focus-visible,
.qa-card:focus-visible, .tb-btn:focus-visible, .exp-btn:focus-visible, .logout-btn:focus-visible,
.sb-user:focus-visible, .sc:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
  outline: 2px solid var(--g1, #D4A017);
  outline-offset: 2px;
  border-radius: 8px;
}
</style>
</head>
<body>

<!-- == SIDEBAR =================================== -->
<?php $activeNav = "dashboard"; $navUnread = $unread; require __DIR__ . "/includes/admin_sidebar.php"; ?>

<!-- == MAIN ======================================= -->
<div class="main">

  <!-- TOPBAR -->
  <header class="topbar">
    <div class="tb-left">
      <button class="mob-btn" onclick="document.getElementById('sb').classList.toggle('open')"><i class="fas fa-bars"></i></button>
      <div>
        <div class="pg-title">Administrator Dashboard</div>
        <div class="bc">
          <a href="admin_dashboard.php"><i class="fas fa-home"></i></a>
          <i class="fas fa-chevron-right"></i><span>Dashboard</span>
        </div>
      </div>
    </div>
    <div class="tb-right">
      <div class="date-chip"><i class="fas fa-calendar-alt"></i><span id="dateChip">-</span></div>
      <a href="admin_notifications.php" class="tb-btn" title="Notifications">
        <i class="fas fa-bell"></i>
        <?php if($unread>0): ?><span class="npip"></span><?php endif; ?>
      </a>
      <div class="exp-wrap">
        <button class="exp-btn" onclick="toggleExportMenu(event)">
          <i class="fas fa-download"></i> Export <i class="fas fa-chevron-down" style="font-size:.58rem;"></i>
        </button>
        <div class="exp-menu" id="expMenu">
          <div class="exp-menu-label">Official Branded Reports</div>
          <a onclick="openExport('defects','pdf')"><i class="fas fa-file-pdf"></i> Defect Reports — PDF</a>
          <a onclick="openExport('defects','xlsx')"><i class="fas fa-file-excel"></i> Defect Reports — Excel</a>
          <a onclick="openExport('equipment','pdf')"><i class="fas fa-file-pdf"></i> Equipment Usage — PDF</a>
          <a onclick="openExport('equipment','xlsx')"><i class="fas fa-file-excel"></i> Equipment Usage — Excel</a>
          <a onclick="openExport('reservations','pdf')"><i class="fas fa-file-pdf"></i> Reservations — PDF</a>
          <a onclick="openExport('reservations','xlsx')"><i class="fas fa-file-excel"></i> Reservations — Excel</a>
          <div class="exp-menu-sep"></div>
          <a onclick="exportReport()"><i class="fas fa-table"></i> Dashboard Summary — CSV</a>
        </div>
      </div>
    </div>
  </header>

  <div class="content">

    <!-- HERO -->
    <div class="hero">
      <div class="hero-left">
        <div class="hero-eyebrow"><i class="fas fa-tools"></i> BEC Equipment Reporting & Maintenance System</div>
        <?php /* Time-neutral on purpose. The English version keyed off the hour
                 and so greeted anyone working at 1am with "Good Morning"; a
                 greeting that holds all day cannot be wrong. */ ?>
        <h1 class="hero-title">Maligayang araw, <span class="hl"><?php echo htmlspecialchars($admin_first);?>!</span>
          <?php if($adminUnit!==''): ?><span class="unit-badge"><i class="fas fa-<?php echo $adminUnit==='ITSO'?'laptop-code':'building-shield'; ?>"></i> <?php echo htmlspecialchars($adminUnit);?> Admin</span><?php endif; ?>
        </h1>
        <p class="hero-sub"><?php if($adminUnit!==''): ?>Your <strong><?php echo htmlspecialchars($adminUnit);?></strong> overview for today. <?php else: ?>Here's your operational overview for today. <?php endif; ?><?php if($criticalRep>0): ?><strong style="color:#FCA5A5;"><?php echo $criticalRep;?> critical <?php echo $criticalRep==1?'case needs':'cases need';?> immediate attention.</strong><?php endif; ?></p>
      </div>
      <div class="hero-meta">
        <div class="hero-timestamp">
          <div class="hero-ts-date" id="heroDate">-</div>
          <div class="hero-ts-time" id="heroTime">-</div>
        </div>
        <img src="assets/bec-seal.jpg" class="hero-seal" alt="BEC Seal">
      </div>
    </div>

    <!-- ALERTS -->
    <?php if(count($priorityAlerts)>0): ?>
    <div class="alert-panel">
      <div class="ap-head">
        <h3><i class="fas fa-exclamation-circle"></i> Priority Alerts <span class="ap-cnt"><?php echo count($priorityAlerts);?></span></h3>
        <a href="admin_defect_reports.php?priority=critical" class="btn btn-ghost" style="font-size:.72rem;padding:.3rem .7rem;">View All</a>
      </div>
      <?php foreach(array_slice($priorityAlerts,0,3) as $a): ?>
      <div class="alert-row <?php echo $a['priority']==='critical'?'crit':'high';?>">
        <div class="ai"><i class="fas fa-<?php echo $a['priority']==='critical'?'radiation':'exclamation-triangle';?>"></i></div>
        <div class="ac">
          <strong><?php echo htmlspecialchars($a['equipment_name']??'Equipment'); ?> - <?php echo prLbl($a['priority']);?> Priority</strong>
          <p><?php echo htmlspecialchars(dashboardSummarizeText((string)($a['issue_description'] ?? ''), 70)); ?></p>
          <time><?php echo date('M j, Y g:i A',strtotime($a['report_date']));?></time>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- SMART KPIs (decision-support) -->
    <style>
      .kpi-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:.875rem;margin-bottom:1.625rem;}
      @media(max-width:1024px){.kpi-strip{grid-template-columns:repeat(2,1fr);}}
      @media(max-width:560px){.kpi-strip{grid-template-columns:1fr;}}
    </style>
    <div class="kpi-strip">
      <div style="background:var(--s1);border:1px solid var(--bdr);border-left:4px solid var(--m3);border-radius:14px;padding:.95rem 1.05rem;box-shadow:none;">
        <div style="display:flex;align-items:center;gap:.45rem;color:var(--t3);font-size:.66rem;text-transform:uppercase;letter-spacing:.6px;font-weight:700;"><i class="fas fa-stopwatch" style="color:var(--m3);"></i> Avg Resolution</div>
        <div style="font-size:1.7rem;font-weight:800;color:var(--t1);margin-top:.3rem;line-height:1;"><?php echo $avgResolution!==null?$avgResolution:'—'; ?><span style="font-size:.8rem;font-weight:600;color:var(--t3);"><?php echo $avgResolution!==null?' days':''; ?></span></div>
        <div style="font-size:.66rem;color:var(--t3);margin-top:.3rem;">Report → completion (resolved)</div>
      </div>
      <div style="background:var(--s1);border:1px solid var(--bdr);border-left:4px solid #16A34A;border-radius:14px;padding:.95rem 1.05rem;box-shadow:none;">
        <div style="display:flex;align-items:center;gap:.45rem;color:var(--t3);font-size:.66rem;text-transform:uppercase;letter-spacing:.6px;font-weight:700;"><i class="fas fa-circle-check" style="color:#16A34A;"></i> Completion Rate</div>
        <div style="font-size:1.7rem;font-weight:800;color:var(--t1);margin-top:.3rem;line-height:1;"><?php echo $completionRate; ?><span style="font-size:.85rem;font-weight:600;color:var(--t3);">%</span></div>
        <div style="height:5px;background:var(--s3);border-radius:4px;margin-top:.5rem;overflow:hidden;"><div style="height:100%;width:<?php echo (int)$completionRate; ?>%;background:linear-gradient(90deg,#16A34A,#4ADE80);"></div></div>
      </div>
      <div style="background:var(--s1);border:1px solid var(--bdr);border-left:4px solid var(--g2);border-radius:14px;padding:.95rem 1.05rem;box-shadow:none;">
        <div style="display:flex;align-items:center;gap:.45rem;color:var(--t3);font-size:.66rem;text-transform:uppercase;letter-spacing:.6px;font-weight:700;"><i class="fas fa-screwdriver-wrench" style="color:var(--g1);"></i> Active Repairs</div>
        <div style="font-size:1.7rem;font-weight:800;color:var(--t1);margin-top:.3rem;line-height:1;"><?php echo $activeRepairs; ?></div>
        <div style="font-size:.66rem;color:var(--t3);margin-top:.3rem;">Assigned + in progress now</div>
      </div>
      <a href="admin_defect_reports.php?status=reported" style="text-decoration:none;background:var(--s1);border:1px solid <?php echo $overdueReports>0?'#FCA5A5':'var(--bdr)'; ?>;border-left:4px solid <?php echo $overdueReports>0?'#DC2626':'#9CA3AF'; ?>;border-radius:14px;padding:.95rem 1.05rem;box-shadow:none;display:block;">
        <div style="display:flex;align-items:center;gap:.45rem;color:var(--t3);font-size:.66rem;text-transform:uppercase;letter-spacing:.6px;font-weight:700;"><i class="fas fa-triangle-exclamation" style="color:<?php echo $overdueReports>0?'#DC2626':'#9CA3AF'; ?>;"></i> SLA Overdue</div>
        <div style="font-size:1.7rem;font-weight:800;color:<?php echo $overdueReports>0?'#DC2626':'var(--t1)'; ?>;margin-top:.3rem;line-height:1;"><?php echo $overdueReports; ?></div>
        <div style="font-size:.66rem;color:var(--t3);margin-top:.3rem;">Open past priority target</div>
      </a>
    </div>

    <!-- QUICK ACTIONS -->
    <div class="qa-grid">
      <a href="admin_defect_reports.php" class="qa-card c1">
        <div class="qa-ic"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="qa-text">
          <strong>Manage Reports</strong>
          <span><?php echo $pendingReports;?> pending review</span>
        </div>
      </a>
      <a href="admin_assign_technicians.php" class="qa-card c3">
        <div class="qa-ic"><i class="fas fa-user-cog"></i></div>
        <div class="qa-text">
          <strong>Assign Technicians</strong>
          <span>Assign maintenance tasks</span>
        </div>
      </a>
      <a href="admin_users.php" class="qa-card c4">
        <div class="qa-ic"><i class="fas fa-users"></i></div>
        <div class="qa-text">
          <strong>User Management</strong>
          <span><?php echo $userCount;?> user<?php echo $userCount==1?'':'s';?> in the system</span>
        </div>
      </a>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
      <div class="sc s0" onclick="location.href='admin_defect_reports.php'">
        <div class="sc-ic"><i class="fas fa-clipboard-list"></i></div>
        <div class="sc-num" id="n0"><?php echo $totalReports;?></div>
        <div class="sc-lbl">Total Reports</div>
      </div>
      <div class="sc s1" onclick="location.href='admin_defect_reports.php?status=reported'">
        <div class="sc-ic"><i class="fas fa-hourglass-half"></i></div>
        <div class="sc-num" id="n1"><?php echo $pendingReports;?></div>
        <div class="sc-lbl">Pending</div>
      </div>
      <div class="sc s2" onclick="location.href='admin_defect_reports.php?status=assigned'">
        <div class="sc-ic"><i class="fas fa-check-double"></i></div>
        <div class="sc-num" id="n2"><?php echo $approvedReports;?></div>
        <div class="sc-lbl">Received</div>
      </div>
      <div class="sc s3" onclick="location.href='admin_defect_reports.php?status=in_progress'">
        <div class="sc-ic"><i class="fas fa-wrench"></i></div>
        <div class="sc-num" id="n3"><?php echo $inProgReports;?></div>
        <div class="sc-lbl">In Progress</div>
      </div>
      <div class="sc s4" onclick="location.href='admin_defect_reports.php?status=completed'">
        <div class="sc-ic"><i class="fas fa-check-circle"></i></div>
        <div class="sc-num" id="n4"><?php echo $completedRep;?></div>
        <div class="sc-lbl">Completed</div>
      </div>
      <div class="sc s5" onclick="location.href='admin_defect_reports.php?status=rejected'">
        <div class="sc-ic"><i class="fas fa-times-circle"></i></div>
        <div class="sc-num" id="n5"><?php echo $rejectedRep;?></div>
        <div class="sc-lbl">Rejected</div>
      </div>
      <div class="sc s6" onclick="location.href='admin_defect_reports.php?priority=critical'">
        <div class="sc-ic"><i class="fas fa-radiation-alt"></i></div>
        <div class="sc-num" id="n6"><?php echo $criticalRep;?></div>
        <div class="sc-lbl">Critical Cases</div>
      </div>
    </div>

    <!-- CHARTS ROW -->
    <div class="lower-grid" style="margin-bottom:1.25rem;">
      <div class="panel">
        <div class="panel-h">
          <h3><i class="fas fa-chart-pie"></i> Equipment Status</h3>
        </div>
        <div style="padding:1.1rem;display:flex;gap:1.25rem;align-items:center;">
          <div style="flex:1;min-width:0;max-width:200px;margin:auto;">
            <canvas id="donutChart" height="180"></canvas>
          </div>
          <div class="legend" style="flex:1;">
            <?php foreach($equipDist as $d): ?>
            <div class="li">
              <div class="lc" style="background:<?php echo $d['color'];?>"></div>
              <span class="ll"><?php echo $d['label'];?></span>
              <span class="lv"><?php echo $d['value'];?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-h">
          <h3><i class="fas fa-chart-line"></i> 7-Day Activity</h3>
        </div>
        <div style="padding:1.1rem;">
          <canvas id="lineChart" height="160"></canvas>
        </div>
      </div>
    </div>

    <!-- TECHNICIAN PERFORMANCE -->
    <div class="panel" style="margin-bottom:1.25rem;">
      <div class="panel-h">
        <h3><i class="fas fa-user-gear"></i> Technician Performance</h3>
        <a href="admin_assign_technicians.php" class="btn btn-ghost" style="font-size:.72rem;padding:.3rem .7rem;">Assign Tasks</a>
      </div>
      <div style="padding:.6rem 1.1rem 1rem;">
        <?php if (empty($technicianStats)): ?>
          <div style="text-align:center;color:var(--t3);padding:1.6rem 1rem;font-size:.82rem;">
            <i class="fas fa-user-slash" style="font-size:1.5rem;display:block;margin-bottom:.55rem;color:var(--t4);"></i>
            No active technicians yet. Create technician accounts in <a href="admin_users.php" style="color:var(--m3);font-weight:600;">User Management</a>.
          </div>
        <?php else: $rank = 1; foreach ($technicianStats as $ts): ?>
          <div style="display:grid;grid-template-columns:1.7fr .7fr .7fr .7fr .7fr;gap:.5rem;align-items:center;padding:.62rem 0;border-bottom:1px solid var(--bdr);">
            <div style="display:flex;align-items:center;gap:.6rem;min-width:0;">
              <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--m3),var(--m4));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.82rem;flex-shrink:0;"><?php echo htmlspecialchars(strtoupper(substr($ts['name'], 0, 1))); ?></div>
              <div style="min-width:0;flex:1;">
                <div style="font-weight:600;color:var(--t1);font-size:.84rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($ts['name']); ?><?php if ($rank === 1 && $ts['completed'] > 0): ?> <i class="fas fa-crown" style="color:var(--g2);font-size:.7rem;" title="Top performer"></i><?php endif; ?></div>
                <div style="height:5px;background:var(--s3);border-radius:4px;margin-top:.34rem;overflow:hidden;"><div style="height:100%;width:<?php echo (int)round($ts['total'] / $maxTechLoad * 100); ?>%;background:linear-gradient(90deg,var(--m3),var(--g2));"></div></div>
              </div>
            </div>
            <div style="text-align:center;"><div style="font-weight:800;font-size:1.02rem;color:var(--t1);line-height:1;"><?php echo $ts['total']; ?></div><div style="font-size:.58rem;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;margin-top:.2rem;">Load</div></div>
            <div style="text-align:center;"><div style="font-weight:700;color:#D97706;line-height:1;"><?php echo $ts['open']; ?></div><div style="font-size:.58rem;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;margin-top:.2rem;">Open</div></div>
            <div style="text-align:center;"><div style="font-weight:700;color:#16A34A;line-height:1;"><?php echo $ts['completed']; ?></div><div style="font-size:.58rem;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;margin-top:.2rem;">Done</div></div>
            <div style="text-align:center;"><div style="font-weight:700;color:var(--t1);line-height:1;"><?php echo $ts['avg'] !== null ? $ts['avg'] . 'd' : '—'; ?></div><div style="font-size:.58rem;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;margin-top:.2rem;">Avg</div></div>
          </div>
        <?php $rank++; endforeach; endif; ?>
      </div>
    </div>

    <!-- ASSET HEALTH -->
    <div class="panel" style="margin-bottom:1.25rem;">
      <div class="panel-h">
        <h3><i class="fas fa-heart-pulse"></i> Asset Health</h3>
        <a href="admin_inventory.php" class="btn btn-ghost" style="font-size:.72rem;padding:.3rem .7rem;">View Inventory</a>
      </div>
      <div style="padding:1rem 1.1rem;">
        <!-- fleet counts -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.7rem;margin-bottom:1.1rem;">
          <?php
            $ahCards = [
              ['Total Assets', $assetCounts['total'], 'var(--t1)', 'fa-boxes-stacked', 'var(--m3)'],
              ['Operational',  $assetCounts['operational'], '#16A34A', 'fa-circle-check', '#16A34A'],
              ['Under Repair', $assetCounts['repair'], '#D97706', 'fa-screwdriver-wrench', '#D97706'],
              ['Defective',    $assetCounts['defective'], '#DC2626', 'fa-triangle-exclamation', '#DC2626'],
            ];
            foreach ($ahCards as [$lbl, $val, $color, $icon, $icColor]):
          ?>
          <div style="background:var(--s2);border:1px solid var(--bdr);border-radius:12px;padding:.7rem .8rem;text-align:center;">
            <div style="font-size:1.5rem;font-weight:800;color:<?php echo $color; ?>;line-height:1;"><i class="fas <?php echo $icon; ?>" style="font-size:.8rem;color:<?php echo $icColor; ?>;margin-right:.2rem;"></i><?php echo (int)$val; ?></div>
            <div style="font-size:.62rem;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;font-weight:700;margin-top:.35rem;"><?php echo $lbl; ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- most defective assets -->
        <div style="font-size:.7rem;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;font-weight:700;margin-bottom:.5rem;">Most Defective Assets</div>
        <?php if (empty($topDefectiveAssets)): ?>
          <div style="text-align:center;color:var(--t3);padding:1.2rem 1rem;font-size:.82rem;">
            <i class="fas fa-shield-heart" style="font-size:1.4rem;display:block;margin-bottom:.5rem;color:#16A34A;"></i>
            No equipment defect history yet — the fleet looks healthy.
          </div>
        <?php else: $rk = 1; foreach ($topDefectiveAssets as $a):
            $rep = (int)$a['reports']; $score = assetHealthScore($rep, (string)($a['status'] ?? ''));
            $sc = $score >= 70 ? '#16A34A' : ($score >= 40 ? '#D97706' : '#DC2626');
            $dot = $score >= 70 ? '🟢' : ($score >= 40 ? '🟠' : '🔴');
        ?>
          <div style="display:grid;grid-template-columns:24px 1.8fr 1fr 86px;gap:.6rem;align-items:center;padding:.55rem 0;border-bottom:1px solid var(--bdr);">
            <div style="font-weight:800;color:var(--t3);font-size:.85rem;text-align:center;"><?php echo $rk; ?></div>
            <div style="min-width:0;">
              <div style="font-weight:600;color:var(--t1);font-size:.84rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($a['name']); ?></div>
              <div style="font-size:.68rem;color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><i class="fas fa-location-dot" style="font-size:.6rem;"></i> <?php echo htmlspecialchars($a['location']); ?></div>
            </div>
            <div>
              <div style="height:7px;background:var(--s3);border-radius:4px;overflow:hidden;"><div style="height:100%;width:<?php echo (int)round($rep / $maxAssetReports * 100); ?>%;background:linear-gradient(90deg,#DC2626,#F87171);"></div></div>
              <div style="font-size:.62rem;color:var(--t3);margin-top:.25rem;"><?php echo $rep; ?> report<?php echo $rep === 1 ? '' : 's'; ?></div>
            </div>
            <div style="text-align:right;font-weight:700;font-size:.8rem;color:<?php echo $sc; ?>;"><?php echo $dot; ?> <?php echo $score; ?></div>
          </div>
        <?php $rk++; endforeach; endif; ?>
      </div>
    </div>

    <!-- RECENT DEFECT REPORTS TABLE -->
    <div class="full-grid">
      <div class="panel">
        <div class="panel-h">
          <h3><i class="fas fa-exclamation-triangle"></i> Active Defect Reports</h3>
          <a href="admin_defect_reports.php" class="btn btn-ghost" style="font-size:.73rem;padding:.3rem .75rem;">View All</a>
        </div>
        <table class="tbl">
          <thead>
            <tr>
              <th>Report ID</th><th>Equipment</th><th>Issue</th>
              <th>Priority</th><th>Status</th><th>Date</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($recentReports)): ?>
            <tr><td colspan="7" style="text-align:center;padding:2.5rem;color:var(--t3);">
              <i class="fas fa-check-circle" style="font-size:2.5rem;color:#22C55E;display:block;margin-bottom:.75rem;opacity:.6;"></i>
              All clear - no active defect reports.
            </td></tr>
            <?php else: foreach($recentReports as $r): ?>
            <tr class="report-row" data-report-id="<?php echo htmlspecialchars((string)$r['report_id']); ?>" tabindex="0" role="button" aria-label="View full report details">
              <td><span class="rid"><?php echo htmlspecialchars($r['report_id']); ?></span></td>
              <td>
                <div class="en"><?php echo htmlspecialchars($r['equipment_name']??'N/A'); ?></div>
                <?php if(!empty($r['asset_tag'])): ?>
                <div class="es"><?php echo htmlspecialchars($r['asset_tag']); ?></div>
                <?php endif; ?>
              </td>
              <td style="max-width:180px;"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.78rem;"><?php echo htmlspecialchars(dashboardSummarizeText((string)($r['issue_description'] ?? ''), 55)); ?></div></td>
              <td><span class="badge b-<?php echo prCls($r['priority']); ?>"><?php echo prLbl($r['priority']); ?></span></td>
              <td><span class="badge b-<?php echo stCls($r['status']); ?>"><?php echo stLbl($r['status']); ?></span></td>
              <td style="font-size:.75rem;color:var(--t3);"><?php echo date('M j, Y',strtotime($r['report_date'])); ?></td>
              <td>
                <div style="display:flex;gap:.3rem;">
                  <a href="admin_defect_reports.php?view=<?php echo $r['report_id']; ?>" class="btn btn-icon btn-view" title="View"><i class="fas fa-eye"></i></a>
                  <a href="admin_assign_technicians.php?report=<?php echo $r['report_id']; ?>" class="btn btn-icon btn-edit" title="Assign"><i class="fas fa-user-plus"></i></a>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->

<style>
.report-row{cursor:pointer;}
.report-row:focus{outline:2px solid #D4A017;outline-offset:-2px;}
.report-detail-modal{position:fixed;inset:0;background:rgba(45,5,5,.62);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:650;padding:1rem;}
.report-detail-modal.active{display:flex;}
.report-detail-card{width:min(1020px,96vw);max-height:92vh;overflow:auto;background:#fff;border-radius:18px;border:1px solid var(--bdr);box-shadow:var(--sh3);padding:1rem 1.1rem 1.15rem;}
.report-detail-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;border-bottom:1px solid var(--bdr);padding:.2rem 0 .85rem;margin-bottom:.9rem;}
.report-detail-title{font-family:'Outfit',sans-serif;font-size:1rem;font-weight:800;color:var(--m2);}
.report-detail-close{border:1px solid var(--bdr2);background:var(--s2);width:34px;height:34px;border-radius:10px;cursor:pointer;color:var(--m2);font-size:1rem;}
.report-detail-grid{display:grid;grid-template-columns:1.05fr 1fr;gap:1rem;}
.report-photo-panel{background:var(--s2);border:1px solid var(--bdr);border-radius:12px;padding:.7rem;}
.report-photo-main{width:100%;height:min(62vh,460px);object-fit:contain;background:#fff;border:1px solid var(--bdr);border-radius:10px;display:block;}
.report-photo-empty{width:100%;height:min(62vh,460px);display:flex;align-items:center;justify-content:center;color:var(--t3);font-size:.85rem;background:#fff;border:1px dashed var(--bdr2);border-radius:10px;}
.report-photo-thumbs{margin-top:.6rem;display:flex;flex-wrap:wrap;gap:.4rem;}
.report-photo-thumbs img{width:72px;height:56px;object-fit:cover;border-radius:8px;border:2px solid transparent;cursor:pointer;background:#fff;}
.report-photo-thumbs img.active{border-color:var(--m3);}
.report-photo-link{display:inline-block;margin-top:.55rem;font-size:.78rem;color:var(--m3);text-decoration:none;font-weight:700;}
.report-detail-list{background:var(--s1);border:1px solid var(--bdr);border-radius:12px;padding:.65rem;display:grid;grid-template-columns:1fr;gap:.48rem;}
.report-detail-item{display:grid;grid-template-columns:140px 1fr;gap:.45rem;align-items:start;border-bottom:1px dashed var(--bdr);padding:.35rem 0;}
.report-detail-item:last-child{border-bottom:none;}
.report-detail-key{font-size:.73rem;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.4px;}
.report-detail-val{font-size:.8rem;color:var(--t1);line-height:1.45;word-break:break-word;}
@media (max-width: 900px){.report-detail-grid{grid-template-columns:1fr;}.report-detail-item{grid-template-columns:1fr;}}
</style>

<div class="report-detail-modal" id="reportDetailModal" aria-hidden="true">
  <div class="report-detail-card" role="dialog" aria-modal="true" aria-labelledby="reportDetailTitle">
    <div class="report-detail-head">
      <div class="report-detail-title" id="reportDetailTitle">Defect Report Details</div>
      <button class="report-detail-close" id="reportDetailClose" type="button" aria-label="Close"><i class="fas fa-times"></i></button>
    </div>
    <div class="report-detail-grid">
      <div class="report-photo-panel">
        <img id="reportDetailMainPhoto" class="report-photo-main" alt="Uploaded report photo" style="display:none;">
        <div id="reportDetailNoPhoto" class="report-photo-empty">No uploaded photo available</div>
        <div id="reportDetailThumbs" class="report-photo-thumbs"></div>
        <a id="reportDetailPhotoLink" class="report-photo-link" target="_blank" rel="noopener" style="display:none;">Open full image</a>
      </div>
      <div class="report-detail-list" id="reportDetailList"></div>
    </div>
  </div>
</div>
<!-- Logout Modal -->
<!-- logout confirmation now comes from includes/admin_ui.php (all admin pages) -->

<div class="ttray" id="ttray"></div>

<script>
// -- DATE / TIME ---------------------------------
function tick(){
  const n=new Date();
  const opts={weekday:'short',month:'short',day:'numeric',year:'numeric'};
  document.getElementById('dateChip').textContent=n.toLocaleDateString('en-US',opts);
  document.getElementById('heroDate').textContent=n.toLocaleDateString('en-US',opts);
  document.getElementById('heroTime').textContent=n.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
}
tick(); setInterval(tick,1000);

// -- ANIMATED COUNTERS ----------------------------
function animN(id,target){
  const el=document.getElementById(id);if(!el)return;
  const start=parseInt(el.textContent)||0,dur=800,t0=performance.now();
  const go=now=>{const p=Math.min((now-t0)/dur,1),e=1-Math.pow(1-p,3);el.textContent=Math.round(start+(target-start)*e);if(p<1)requestAnimationFrame(go);};
  requestAnimationFrame(go);
}
document.addEventListener('DOMContentLoaded',()=>{
  animN('n0',<?php echo $totalReports;?>);
  animN('n1',<?php echo $pendingReports;?>);
  animN('n2',<?php echo $approvedReports;?>);
  animN('n3',<?php echo $inProgReports;?>);
  animN('n4',<?php echo $completedRep;?>);
  animN('n5',<?php echo $rejectedRep;?>);
  animN('n6',<?php echo $criticalRep;?>);
});

// -- DONUT CHART ----------------------------------
new Chart(document.getElementById('donutChart'),{
  type:'doughnut',
  data:{
    labels:<?php echo json_encode(array_column($equipDist,'label')); ?>,
    datasets:[{
      data:<?php echo json_encode(array_column($equipDist,'value')); ?>,
      backgroundColor:<?php echo json_encode(array_column($equipDist,'color')); ?>,
      borderWidth:3,borderColor:'#fff',hoverBorderWidth:4,
      hoverOffset:6
    }]
  },
  options:{
    responsive:true,maintainAspectRatio:false,cutout:'68%',
    plugins:{
      legend:{display:false},
      tooltip:{callbacks:{label:ctx=>{
        const t=ctx.dataset.data.reduce((a,b)=>a+b,0);
        return ctx.label+': '+ctx.parsed+' ('+(t>0?((ctx.parsed/t)*100).toFixed(1):0)+'%)';
      }}}
    },
    animation:{animateRotate:true,duration:900}
  }
});

// -- LINE CHART -----------------------------------
const rT=<?php echo json_encode($reservationTrends); ?>;
const dT=<?php echo json_encode($defectTrends); ?>;
new Chart(document.getElementById('lineChart'),{
  type:'line',
  data:{
    labels:rT.map(i=>{const d=new Date(i.date);return d.toLocaleDateString('en-US',{month:'short',day:'numeric'});}),
    datasets:[
      {label:'Defect Reports',data:dT.map(i=>i.count),borderColor:'#DC2626',backgroundColor:'rgba(220,38,38,.08)',tension:.4,fill:true,borderWidth:2,pointBackgroundColor:'#DC2626',pointBorderColor:'#fff',pointBorderWidth:2,pointRadius:3},
      {label:'Reservations',data:rT.map(i=>i.count),borderColor:'#2563EB',backgroundColor:'rgba(37,99,235,.06)',tension:.4,fill:true,borderWidth:2,pointBackgroundColor:'#2563EB',pointBorderColor:'#fff',pointBorderWidth:2,pointRadius:3},
    ]
  },
  options:{
    responsive:true,maintainAspectRatio:false,
    plugins:{legend:{position:'bottom',labels:{padding:12,font:{size:11},usePointStyle:true}}},
    scales:{
      y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.04)'},ticks:{font:{size:10},precision:0}},
      x:{grid:{display:false},ticks:{font:{size:10}}}
    },
    interaction:{mode:'index',intersect:false}
  }
});

// -- EXPORT ---------------------------------------
function toggleExportMenu(e){ e.stopPropagation(); document.getElementById('expMenu').classList.toggle('open'); }
document.addEventListener('click', function(e){ const m=document.getElementById('expMenu'); if(m && !e.target.closest('.exp-wrap')) m.classList.remove('open'); });
function openExport(type,format){ window.open('api/export_reports.php?type='+encodeURIComponent(type)+'&format='+encodeURIComponent(format),'_blank'); const m=document.getElementById('expMenu'); if(m) m.classList.remove('open'); }
function exportReport(){
  // CRLF and a byte-order mark, to match the server-side exports: without the
  // BOM Excel opens the file as ANSI and any non-ASCII text arrives mangled.
  const rows=[
    ['BATANGAS EASTERN COLLEGES'],
    ['Property Management Office'],
    ['ADMIN DASHBOARD REPORT'],
    ['Generated',new Date().toLocaleString()],
    [],
    ['Metric','Value'],
    ['Total Reports','<?php echo (int)$totalReports;?>'],
    ['Pending Verification','<?php echo (int)$pendingReports;?>'],
    ['Approved','<?php echo (int)$approvedReports;?>'],
    ['In Progress','<?php echo (int)$inProgReports;?>'],
    ['Completed','<?php echo (int)$completedRep;?>'],
    ['Rejected','<?php echo (int)$rejectedRep;?>'],
    ['Critical Cases','<?php echo (int)$criticalRep;?>'],
    ['Total Users','<?php echo (int)$userCount;?>'],
    [],
    ['Batangas Eastern Colleges — Property Management Office'],
    ['Confidential — for authorized administrative use only']
  ];
  const csv=rows.map(r=>r.map(v=>'"'+String(v??'').replace(/"/g,'""')+'"').join(',')).join('\r\n')+'\r\n';
  const b=new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8;'});
  const a=document.createElement('a');a.href=URL.createObjectURL(b);
  a.download='bec_dashboard_'+new Date().toISOString().split('T')[0]+'.csv';
  a.click();
  toast('ok','Dashboard report exported.','Export');
}

function toast(type,msg,title){
  const el=document.createElement('div');el.className='toast '+type;
  el.innerHTML=`<div><div class="tt">${title}</div><div class="tm">${msg}</div></div>`;
  document.getElementById('ttray').appendChild(el);
  setTimeout(()=>el.remove(),4000);
}

const recentReportDetails = <?php echo json_encode($recentReportDetails, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const reportModal = document.getElementById('reportDetailModal');
const reportModalClose = document.getElementById('reportDetailClose');
const reportDetailList = document.getElementById('reportDetailList');
const reportMainPhoto = document.getElementById('reportDetailMainPhoto');
const reportNoPhoto = document.getElementById('reportDetailNoPhoto');
const reportThumbs = document.getElementById('reportDetailThumbs');
const reportPhotoLink = document.getElementById('reportDetailPhotoLink');

function formatDetailKey(key){return key.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());}
function normalizePhotoPath(path){const clean=String(path||'').trim().replace(/\\/g,'/');if(!clean)return '';if(/^https?:\/\//i.test(clean))return clean;if(/^[A-Za-z]:\//.test(clean))return '';return encodeURI(clean.replace(/^\/+/, ''));}
function setMainPhoto(src){if(!src){reportMainPhoto.style.display='none';reportNoPhoto.style.display='flex';reportPhotoLink.style.display='none';reportPhotoLink.removeAttribute('href');return;}reportMainPhoto.src=src;reportMainPhoto.style.display='block';reportNoPhoto.style.display='none';reportPhotoLink.href=src;reportPhotoLink.style.display='inline-block';}
function openReportModal(reportId){
  const report=recentReportDetails[String(reportId)];
  if(!report||!reportModal||!reportDetailList)return;
  const ordered=['report_id','equipment_name','asset_tag','category_name','issue_description','location','priority','status','technician_name','assigned_to','reported_by','report_date','updated_at'];
  const skip=new Set(['defect_photos','photo_path','photos']);
  reportDetailList.innerHTML='';
  const rendered=new Set();
  const addItem=(k,v)=>{if(v===null||v===undefined||String(v).trim()==='')return;const row=document.createElement('div');row.className='report-detail-item';const kk=document.createElement('div');kk.className='report-detail-key';kk.textContent=formatDetailKey(k);const vv=document.createElement('div');vv.className='report-detail-val';vv.textContent=String(v);row.appendChild(kk);row.appendChild(vv);reportDetailList.appendChild(row);};
  ordered.forEach(k=>{if(Object.prototype.hasOwnProperty.call(report,k)&&!skip.has(k)){addItem(k,report[k]);rendered.add(k);}});
  Object.keys(report).forEach(k=>{if(rendered.has(k)||skip.has(k))return;addItem(k,report[k]);});
  const photos=Array.isArray(report.photos)?report.photos.map(normalizePhotoPath).filter(Boolean):[];
  reportThumbs.innerHTML='';setMainPhoto(photos[0]||'');
  photos.forEach((src,idx)=>{const thumb=document.createElement('img');thumb.src=src;thumb.alt='Report photo '+(idx+1);if(idx===0)thumb.classList.add('active');thumb.addEventListener('click',()=>{setMainPhoto(src);reportThumbs.querySelectorAll('img').forEach(i=>i.classList.remove('active'));thumb.classList.add('active');});reportThumbs.appendChild(thumb);});
  reportModal.classList.add('active');reportModal.setAttribute('aria-hidden','false');
}
function closeReportModal(){if(!reportModal)return;reportModal.classList.remove('active');reportModal.setAttribute('aria-hidden','true');}

document.querySelectorAll('.report-row').forEach(row=>{row.addEventListener('click',(e)=>{if(e.target.closest('a,button'))return;openReportModal(row.dataset.reportId);});row.addEventListener('keydown',(e)=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();openReportModal(row.dataset.reportId);}});});
if(reportModalClose){reportModalClose.addEventListener('click',closeReportModal);}if(reportModal){reportModal.addEventListener('click',(e)=>{if(e.target===reportModal)closeReportModal();});}
document.addEventListener('keydown',(e)=>{if(e.key==='Escape'&&reportModal&&reportModal.classList.contains('active'))closeReportModal();});
</script>
<script src="assets/sidebar_autohide.js" defer></script>
<script src="assets/dashboard_live.js" defer></script>
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<?php require __DIR__ . '/includes/admin_ui.php'; ?>
</body>
</html>
