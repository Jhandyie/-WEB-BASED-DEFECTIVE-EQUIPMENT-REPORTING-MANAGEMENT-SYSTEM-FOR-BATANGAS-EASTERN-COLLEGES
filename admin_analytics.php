<?php
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireRole('admin');
$conn = getDBConnection();

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['fullname'] ?? 'Administrator';

/* ─── DATE RANGE ─────────────────────────────────────── */
$range  = $_GET['range'] ?? '30';   // 7 | 30 | 90 | 365 | custom
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime("-{$range} days"));
$date_to   = $_GET['to']   ?? date('Y-m-d');
if ($range === 'custom') {
    $date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $date_to   = $_GET['to']   ?? date('Y-m-d');
}

$df = $date_from;
$dt = $date_to;
$df_ts = $df . ' 00:00:00';
$dt_ts = $dt . ' 23:59:59';

/* ─── HELPERS ─────────────────────────────────────────── */
function q($conn, $sql, $types='', ...$params) {
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result();
}
function row1($conn, $sql, $types='', ...$params) {
    $r = q($conn,$sql,$types,...$params)->fetch_assoc();
    return is_array($r) ? $r : [];
}
function esc($s){return htmlspecialchars((string)($s??''),ENT_QUOTES,'UTF-8');}

/* Schema probes go through getTableColumns(), which caches per request. Asking
   the adapter for SHOW COLUMNS directly is an information_schema round trip
   every time — two of them here, ~300 ms of the page's budget, for answers
   config/database.php had already fetched. */
$drCols   = getTableColumns('defect_reports');
$userCols = getTableColumns('users');
$resolutionDateCol = isset($drCols['updated_at']) ? 'updated_at' : (isset($drCols['completion_date']) ? 'completion_date' : null);
$reporterJoinCol = isset($drCols['reporter_id']) ? 'reporter_id' : (isset($drCols['reported_by']) ? 'reported_by' : null);
$userDeptExpr = isset($userCols['department']) ? 'u.department' : "''";

/* ─── KPI METRICS ─────────────────────────────────────────
   Ten separate COUNT(*) queries used to live here. Every one of them is a
   Supabase round trip (~60-100 ms each), and they scan three tables between
   them — so they fold into one aggregate per table with conditional SUMs.
   NULL-safety is unchanged: `status != 'deleted'` and `status NOT IN (...)`
   both evaluate to NULL for a NULL status, which the CASE counts as 0, exactly
   as the old WHERE clauses excluded those rows.                             */
$eq = row1($conn,
    "SELECT SUM(CASE WHEN status!='deleted'    THEN 1 ELSE 0 END) AS total,
            SUM(CASE WHEN status='operational' THEN 1 ELSE 0 END) AS operational
     FROM equipment");
$kpi_total_eq = (int)($eq['total'] ?? 0);
$kpi_op_eq    = (int)($eq['operational'] ?? 0);

$us = row1($conn,
    "SELECT SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN role='technician' AND status='active' THEN 1 ELSE 0 END) AS technicians
     FROM users");
$kpi_total_users = (int)($us['active'] ?? 0);
$kpi_total_tech  = (int)($us['technicians'] ?? 0);

// Work orders were removed; the lifecycle now lives entirely in defect_reports.
$dr = row1($conn,
    "SELECT SUM(CASE WHEN report_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS in_range,
            SUM(CASE WHEN status IN('completed','verified','closed') AND report_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS resolved,
            SUM(CASE WHEN status='reported' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status IN('assigned','accepted','in_progress') THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN priority='critical' AND status NOT IN('completed','verified','closed','rejected','deleted') THEN 1 ELSE 0 END) AS critical,
            SUM(CASE WHEN status IN('pmo_review','ready_for_assignment') THEN 1 ELSE 0 END) AS unassigned
     FROM defect_reports", "ssss", $df_ts, $dt_ts, $df_ts, $dt_ts);
$kpi_reports    = (int)($dr['in_range'] ?? 0);
$kpi_resolved   = (int)($dr['resolved'] ?? 0);
$kpi_pending    = (int)($dr['pending'] ?? 0);
$kpi_inprog     = (int)($dr['in_progress'] ?? 0);
$kpi_crit       = (int)($dr['critical'] ?? 0);
$kpi_unassigned = (int)($dr['unassigned'] ?? 0);

$resolution_rate = $kpi_reports > 0 ? round(($kpi_resolved / $kpi_reports) * 100) : 0;

// Avg resolution time (days) — completed reports
$avg_res = [null];
if ($resolutionDateCol !== null) {
    $avg_sql = "SELECT AVG(DATEDIFF({$resolutionDateCol}, report_date)) FROM defect_reports WHERE status IN('completed','verified','closed') AND report_date BETWEEN ? AND ?";
    $avg_stmt = $conn->prepare($avg_sql);
    $avg_stmt->bind_param('ss', $df_ts, $dt_ts);
    $avg_stmt->execute();
    $avg_res = $avg_stmt->get_result()->fetch_row();
}
$avg_resolution_days = $avg_res && $avg_res[0] ? round((float)$avg_res[0], 1) : 0;

/* ─── CHART 1: Reports over time (daily/weekly) ──────── */
$interval = $range <= 30 ? 'day' : ($range <= 90 ? 'week' : 'month');
// Bucket label expression (same in SELECT and GROUP BY so Postgres is happy).
$bucket = $interval === 'day'
    ? "to_char(report_date, 'Mon DD')"
    : ($interval === 'week'
        ? "to_char(date_trunc('week', report_date), 'Mon DD')"
        : "to_char(report_date, 'Mon YYYY')");

$chart1_res = q($conn, "
    SELECT $bucket AS lbl, COUNT(*) AS total,
           SUM(CASE WHEN status IN('completed','verified','closed') THEN 1 ELSE 0 END) AS resolved
    FROM defect_reports
    WHERE report_date BETWEEN ? AND ?
    GROUP BY $bucket ORDER BY MIN(report_date)
", "ss", $df_ts, $dt_ts)->fetch_all(MYSQLI_ASSOC);
$chart1_labels   = array_column($chart1_res,'lbl');
$chart1_total    = array_column($chart1_res,'total');
$chart1_resolved = array_column($chart1_res,'resolved');

/* ─── CHART 2: Reports by status (donut) ─────────────── */
$status_res = q($conn,"
    SELECT status, COUNT(*) AS n FROM defect_reports
    WHERE report_date BETWEEN ? AND ? AND status!='deleted'
    GROUP BY status ORDER BY n DESC
","ss",$df_ts,$dt_ts)->fetch_all(MYSQLI_ASSOC);
$status_labels = array_column($status_res,'status');
$status_vals   = array_column($status_res,'n');

/* ─── CHART 3: Reports by priority (bar) ─────────────── */
$prio_res = q($conn,"
    SELECT priority, COUNT(*) AS n FROM defect_reports
    WHERE report_date BETWEEN ? AND ? AND status!='deleted'
    GROUP BY priority ORDER BY FIELD(priority,'critical','high','medium','low')
","ss",$df_ts,$dt_ts)->fetch_all(MYSQLI_ASSOC);
$prio_labels = array_column($prio_res,'priority');
$prio_vals   = array_column($prio_res,'n');

/* ─── CHART 4: Reports by department (horizontal bar) ── */
$dept_res = q($conn,"
    SELECT COALESCE(NULLIF({$userDeptExpr},''),'Unassigned') AS dept, COUNT(*) AS n
    FROM defect_reports r
    LEFT JOIN users u ON r.assigned_to = u.user_id
    WHERE r.report_date BETWEEN ? AND ? AND r.status!='deleted'
    GROUP BY dept ORDER BY n DESC LIMIT 8
","ss",$df_ts,$dt_ts)->fetch_all(MYSQLI_ASSOC);
$dept_labels = array_column($dept_res,'dept');
$dept_vals   = array_column($dept_res,'n');

/* ─── CHART 5: Equipment status breakdown (doughnut) ─── */
$eq_status_res = q($conn,"SELECT status, COUNT(*) AS n FROM equipment WHERE status!='deleted' GROUP BY status ORDER BY n DESC")->fetch_all(MYSQLI_ASSOC);
$eq_st_labels = array_column($eq_status_res,'status');
$eq_st_vals   = array_column($eq_status_res,'n');

/* ─── CHART 6: Top faulty equipment ──────────────────── */
$top_eq_res = q($conn,"
    SELECT e.equipment_name, e.asset_tag, e.location,
           COUNT(r.report_id) AS defects,
           SUM(CASE WHEN r.priority='critical' THEN 1 ELSE 0 END) AS crit
    FROM defect_reports r
    JOIN equipment e ON r.equipment_id = e.equipment_id
    WHERE r.report_date BETWEEN ? AND ? AND r.status!='deleted'
    GROUP BY e.equipment_id ORDER BY defects DESC, crit DESC LIMIT 8
","ss",$df_ts,$dt_ts)->fetch_all(MYSQLI_ASSOC);
$top_eq_labels = array_map(fn($e)=>substr($e['equipment_name'],0,22).'..', array_slice($top_eq_res,0,8));
$top_eq_vals   = array_column($top_eq_res,'defects');

/* ─── CHART 7: Technician performance ────────────────── */
$tech_res = q($conn,"
    SELECT u.fullname,
           COUNT(r.report_id) AS total,
           SUM(CASE WHEN r.status IN('completed','verified','closed') THEN 1 ELSE 0 END) AS done
    FROM users u
    LEFT JOIN defect_reports r ON r.assigned_to = u.user_id
        AND r.report_date BETWEEN ? AND ?
    WHERE u.role='technician' AND u.status='active'
    GROUP BY u.user_id ORDER BY done DESC, total DESC LIMIT 8
","ss",$df_ts,$dt_ts)->fetch_all(MYSQLI_ASSOC);

/* Chart 8 (work-order status) removed with the work-order module. */

/* ─── CHART 9: Monthly trend (last 12 months) ───────── */
$trend_res = q($conn,"
    SELECT DATE_FORMAT(report_date,'%b %Y') AS lbl,
           DATE_FORMAT(report_date,'%Y-%m') AS mkey,
           COUNT(*) AS total,
           SUM(CASE WHEN status IN('completed','verified','closed') THEN 1 ELSE 0 END) AS resolved
    FROM defect_reports
    WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND status!='deleted'
    GROUP BY 1, 2 ORDER BY 2 ASC
")->fetch_all(MYSQLI_ASSOC);
$trend_labels   = array_column($trend_res,'lbl');
$trend_total    = array_column($trend_res,'total');
$trend_resolved = array_column($trend_res,'resolved');

/* ─── RECENT ACTIVITY FEED ───────────────────────────── */
$activity = q($conn,"
    SELECT 'defect' AS type, report_id AS id, issue_description AS details,
           status, priority, report_date AS ts
    FROM defect_reports WHERE status!='deleted' ORDER BY report_date DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

/* ─── TOP REPORTERS ──────────────────────────────────── */
$top_reporters = [];
if ($reporterJoinCol !== null) {
    $top_reporters = q($conn,"
    SELECT u.fullname, {$userDeptExpr} AS department, COUNT(r.report_id) AS n
    FROM defect_reports r JOIN users u ON r.{$reporterJoinCol}=u.user_id
    WHERE r.report_date BETWEEN ? AND ? AND r.status!='deleted'
    GROUP BY u.user_id ORDER BY n DESC LIMIT 5
","ss",$df_ts,$dt_ts)->fetch_all(MYSQLI_ASSOC);
}

function jArr($arr){return json_encode(array_values($arr),JSON_UNESCAPED_UNICODE);}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Analytics — BEC Admin</title>
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="css/typography.css">
<script src="assets/vendor/js/chart.umd.min.js"></script>
<link rel="stylesheet" href="assets/css/admin-shell.css">
<style>

/* ═══════════════════════════════════════════════════════
   BEC Admin — Analytics  |  Maroon × Gold × Warm
   Outfit · DM Sans · Chart.js 4
═══════════════════════════════════════════════════════ */
:root{
  --m1:#2D0505;--m2:#4A0E0E;--m3:#7B1D1D;--m4:#9B2C2C;
  --g1:#92600A;--g2:#D4A017;--g3:#F0C040;--gp:#FEF9E7;
  --bg:#F4EFE6;--s1:#FFFFFF;--s2:#FAF7F0;--s3:#F2EAD9;
  --bdr:#E5D9C6;--bdr2:#D0C0A8;
  --t1:#1A0808;--t2:#5C3838;--t3:#9C7A7A;--t4:#C8ABAB;
  --sh0:0 1px 2px rgba(45,5,5,.05);
  --sh1:0 2px 8px rgba(45,5,5,.07),0 1px 3px rgba(45,5,5,.04);
  --sh2:0 6px 20px rgba(45,5,5,.09),0 2px 6px rgba(45,5,5,.05);
  --sh3:0 14px 40px rgba(45,5,5,.13),0 4px 10px rgba(45,5,5,.07);
  --r1:8px;--r2:12px;--r3:18px;--r4:26px;--sb:262px;
  /* Chart palette */
  --ch1:#7B1D1D;--ch2:#D4A017;--ch3:#2563EB;--ch4:var(--ok);
  --ch5:#7C3AED;--ch6:#0891B2;--ch7:var(--bad);--ch8:#D97706;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--t1);min-height:100vh;overflow-x:hidden;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='400'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.022'/%3E%3C/svg%3E");}

/* ── SIDEBAR ──────────────────────────────────────── */
/* sidebar styling lives in assets/css/admin-shell.css */
.lout i{transition:transform .3s;}.lout:hover i{transform:rotate(180deg);}

/* ── LAYOUT ───────────────────────────────────────── */
.wrap{margin-left:var(--sb);min-height:100vh;display:flex;flex-direction:column;}
.topbar{background:rgba(255,252,245,.93);backdrop-filter:blur(14px);border-bottom:1px solid var(--bdr);height:58px;padding:0 1.75rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:200;box-shadow:var(--sh0);}
.tb-l{display:flex;align-items:center;gap:.55rem;}
.mob-tog{display:none;background:none;border:none;font-size:1.1rem;cursor:pointer;color:var(--t2);}
.pg-title{font-family:'Outfit',sans-serif;font-weight:700;font-size:1rem;color:var(--t1);}
.bc{font-size:.68rem;color:var(--t3);display:flex;align-items:center;gap:.25rem;}
.bc a{color:var(--t3);text-decoration:none;}.bc a:hover{color:var(--m3);}
.bc i{font-size:.55rem;}
.tb-r{display:flex;align-items:center;gap:.55rem;}
.ic-btn{width:34px;height:34px;background:var(--s2);border:1px solid var(--bdr);border-radius:var(--r1);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--t2);font-size:.85rem;transition:all .17s;text-decoration:none;position:relative;box-shadow:none;}
.ic-btn:hover{background:var(--m3);color:#fff;transform:none;box-shadow:none;}
.pip{position:absolute;top:5px;right:5px;width:7px;height:7px;background:var(--g2);border-radius:50%;border:2px solid var(--s1);animation:pp 2.2s ease-in-out infinite;}
@keyframes pp{0%,100%{transform:scale(1);}50%{transform:scale(1.4);}}
.pg{padding:1.5rem 1.75rem;flex:1;}

/* ── BUTTONS ──────────────────────────────────────── */
.btn{padding:.4rem .875rem;font-size:.77rem;border:none;}
.btn:hover{transform:none;}.btn:active{transform:translateY(0);}
.btn-maroon{background:linear-gradient(135deg,var(--m3),var(--m4));color:#fff;box-shadow:none;}
.btn-maroon:hover{box-shadow:none;}
.btn-gold{background:linear-gradient(135deg,var(--g2),var(--g3));color:var(--m1);box-shadow:none;}
.btn-gold:hover{box-shadow:none;}
.btn-ghost{background:var(--s2);color:var(--t2);border:1px solid var(--bdr);}
.btn-ghost:hover{background:var(--s3);}
.btn-sm{padding:.3rem .65rem;font-size:.71rem;}

/* ── DATE RANGE TABS ──────────────────────────────── */
.rtabs{display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:1.25rem;align-items:center;}
.rtab{display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .78rem;border-radius:20px;font-size:.73rem;font-weight:700;cursor:pointer;text-decoration:none;border:1.5px solid var(--bdr);background:var(--s1);color:var(--t2);transition:all .17s;}
.rtab:hover{transform:none;background:var(--s2);}
.rtab.on{background:var(--m3);color:#fff;border-color:var(--m2);box-shadow:none;}
.date-range-lbl{font-size:.72rem;color:var(--t3);margin-left:auto;font-style:italic;}

/* ── KPI STRIP ────────────────────────────────────── */
.kpis{display:grid;grid-template-columns:repeat(8,1fr);gap:.65rem;margin-bottom:1.375rem;}
.kcard{background:var(--s1);border-radius:var(--r3);padding:.9rem 1rem;border:1px solid var(--bdr);
  position:relative;overflow:hidden;transition:all .26s cubic-bezier(.4,0,.2,1);box-shadow:var(--sh0);}
.kcard::after{content:'';position:absolute;bottom:0;left:0;width:100%;height:3px;background:var(--kc,var(--m3));
  border-radius:0 0 var(--r3) var(--r3);transform:scaleX(0);transform-origin:left;transition:transform .32s;}
.kcard:hover{transform:none;box-shadow:var(--sh2);border-color:transparent;}
.kcard:hover::after{transform:scaleX(1);}
.kico{width:32px;height:32px;border-radius:var(--r1);display:flex;align-items:center;justify-content:center;font-size:.78rem;margin-bottom:.45rem;background:var(--kb);color:var(--kc,var(--m3));box-shadow:none;transition:transform .26s;position:relative;z-index:1;}
.kcard:hover .kico{transform:none;}
.knum{font-family:'Outfit',sans-serif;font-size:1.65rem;font-weight:800;color:var(--t1);line-height:1;transition:color .26s;position:relative;z-index:1;}
.kcard:hover .knum{color:var(--kc,var(--m3));}
.klbl{font-size:.56rem;text-transform:uppercase;letter-spacing:.65px;color:var(--t3);font-weight:700;position:relative;z-index:1;margin-top:.08rem;}
.kcard{animation:scIn .3s ease both;}
.kcard:nth-child(1){animation-delay:.04s;}.kcard:nth-child(2){animation-delay:.08s;}
.kcard:nth-child(3){animation-delay:.12s;}.kcard:nth-child(4){animation-delay:.16s;}
.kcard:nth-child(5){animation-delay:.20s;}.kcard:nth-child(6){animation-delay:.24s;}
.kcard:nth-child(7){animation-delay:.28s;}.kcard:nth-child(8){animation-delay:.32s;}
@keyframes scIn{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}

/* ── CHART GRID ───────────────────────────────────── */
.cgrid{display:grid;gap:1.125rem;}
.cgrid-2{grid-template-columns:1fr 1fr;}
.cgrid-3{grid-template-columns:2fr 1fr 1fr;}
.cgrid-r{grid-template-columns:1fr 1fr;}

/* ── CHART PANEL ──────────────────────────────────── */
.cpanel{background:var(--s1);border-radius:var(--r3);border:1px solid var(--bdr);
  box-shadow:var(--sh1);overflow:hidden;transition:box-shadow .22s;display:flex;flex-direction:column;}
.cpanel:hover{box-shadow:var(--sh2);}
.cp-head{padding:.8rem 1.1rem .65rem;border-bottom:1px solid var(--bdr);
  display:flex;align-items:center;justify-content:space-between;
  background:linear-gradient(to right,var(--s2),var(--s1));flex-shrink:0;}
.cp-head h3{font-family:'Outfit',sans-serif;font-size:.85rem;font-weight:700;color:var(--t1);
  display:flex;align-items:center;gap:.32rem;margin:0;}
.cp-head h3 i{color:var(--m3);}
.cp-sub{font-size:.62rem;color:var(--t3);margin-top:.05rem;}
.cp-body{padding:1rem;flex:1;display:flex;align-items:center;justify-content:center;min-height:0;}
.cp-body canvas{max-height:100%;}

/* ── INSIGHT CARDS ────────────────────────────────── */
.insight-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.125rem;margin-top:1.125rem;}
.insight-panel{background:var(--s1);border-radius:var(--r3);border:1px solid var(--bdr);box-shadow:var(--sh1);overflow:hidden;}
.insight-panel:hover{box-shadow:var(--sh2);}

/* Technician table */
.tech-tbl{width:100%;border-collapse:collapse;}
.tech-tbl thead th{padding:.45rem 1rem;font-size:.6rem;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);font-weight:800;text-align:left;background:var(--s2);border-bottom:1.5px solid var(--bdr);}
.tech-tbl tbody td{padding:.6rem 1rem;font-size:.78rem;border-bottom:1px solid var(--bdr);vertical-align:middle;}
.tech-tbl tbody tr:last-child td{border-bottom:none;}
.tech-tbl tbody tr:hover td{background:var(--s2);}
.tech-av{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--m3),var(--m4));display:flex;align-items:center;justify-content:center;font-family:'Outfit',sans-serif;font-weight:800;font-size:.65rem;color:#fff;flex-shrink:0;box-shadow:none;}
.prog-bar{height:5px;background:var(--s3);border-radius:5px;overflow:hidden;margin-top:.2rem;}
.prog-fill{height:100%;border-radius:5px;transition:width .7s cubic-bezier(.4,0,.2,1);}

/* Activity feed */
.act-item{display:flex;align-items:flex-start;gap:.625rem;padding:.6rem 1rem;border-bottom:1px solid var(--bdr);}
.act-item:last-child{border:none;}
.act-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:.35rem;}
.act-txt{font-size:.77rem;line-height:1.45;}
.act-id{font-family:'Outfit',sans-serif;font-weight:800;color:var(--m3);font-size:.73rem;}
.act-ts{font-size:.65rem;color:var(--t3);margin-top:.1rem;}

/* Reporter leaderboard */
.rep-item{display:flex;align-items:center;gap:.625rem;padding:.55rem 1rem;border-bottom:1px solid var(--bdr);}
.rep-item:last-child{border:none;}
.rep-rank{font-family:'Outfit',sans-serif;font-weight:900;font-size:.9rem;width:22px;text-align:center;
  color:var(--t4);flex-shrink:0;}
.rep-rank.gold{color:#D4A017;}.rep-rank.silver{color:#9CA3AF;}.rep-rank.bronze{color:#B45309;}
.rep-av{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#7C3AED,#A78BFA);display:flex;align-items:center;justify-content:center;font-family:'Outfit',sans-serif;font-weight:800;font-size:.68rem;color:#fff;flex-shrink:0;}
.rep-name{font-size:.8rem;font-weight:700;flex:1;}
.rep-dept{font-size:.62rem;color:var(--t3);}
.rep-cnt{font-family:'Outfit',sans-serif;font-weight:800;font-size:.95rem;color:var(--m3);}

/* Stat highlights */
.stat-row{display:flex;align-items:center;justify-content:space-between;padding:.55rem 1rem;border-bottom:1px solid var(--bdr);}
.stat-row:last-child{border:none;}
.stat-lbl{font-size:.78rem;color:var(--t2);display:flex;align-items:center;gap:.35rem;}
.stat-lbl i{font-size:.68rem;color:var(--m3);}
.stat-val{font-family:'Outfit',sans-serif;font-weight:800;font-size:.95rem;color:var(--t1);}
.stat-val.good{color:var(--ok);}.stat-val.warn{color:#D97706;}.stat-val.bad{color:var(--bad);}

/* Resolution ring */
.ring-wrap{display:flex;align-items:center;gap:1.25rem;padding:1rem;}
.ring-svg{flex-shrink:0;}
.ring-info .big{font-family:'Outfit',sans-serif;font-size:2.2rem;font-weight:900;color:var(--m3);line-height:1;}
.ring-info .sub{font-size:.7rem;color:var(--t3);text-transform:uppercase;letter-spacing:.7px;font-weight:700;}

/* ── MODAL ────────────────────────────────────────── */
.mo{position:fixed;inset:0;background:rgba(26,8,8,.6);backdrop-filter:blur(7px);z-index:500;display:none;align-items:flex-start;justify-content:center;padding:1.5rem 1rem;overflow-y:auto;}
.mo.open{display:flex;animation:moFade .18s ease;}
@keyframes moFade{from{opacity:0}to{opacity:1}}
.mw{background:var(--s1);border-radius:var(--r4);width:100%;max-width:400px;box-shadow:var(--sh3);animation:mUp .28s cubic-bezier(.4,0,.2,1);border:1px solid var(--bdr);margin:auto;}
@keyframes mUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.mhd{padding:1.25rem 1.5rem 1rem;background:linear-gradient(120deg,var(--m1) 0%,#3D0A0A 45%,var(--m3) 100%);border-radius:var(--r4) var(--r4) 0 0;display:flex;justify-content:space-between;align-items:flex-start;position:relative;overflow:hidden;}
.mhd::after{content:'';position:absolute;right:-10px;top:-10px;width:100px;height:100px;border-radius:50%;background:rgba(212,160,23,.08);pointer-events:none;animation:sealSpin 18s linear infinite;}
.mhd-t{position:relative;z-index:1;}
.mhd-t h2{font-family:'Outfit',sans-serif;font-size:1.05rem;font-weight:800;color:#fff;}
.mhd-t p{font-size:.7rem;color:rgba(255,255,255,.42);margin-top:.1rem;}
.mx{width:27px;height:27px;background:rgba(255,255,255,.1);border:none;border-radius:50%;color:rgba(255,255,255,.6);font-size:.82rem;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .18s;position:relative;z-index:1;}
.mx:hover{background:rgba(255,255,255,.22);color:#fff;transform:rotate(90deg);}
.mb{padding:1.25rem 1.5rem;}
.mf{padding:.8rem 1.5rem 1.25rem;border-top:1px solid var(--bdr);display:flex;justify-content:flex-end;gap:.45rem;background:var(--s2);border-radius:0 0 var(--r4) var(--r4);}
.fg{display:flex;flex-direction:column;gap:.28rem;margin-bottom:.7rem;}
.fl{font-size:.63rem;font-weight:800;text-transform:uppercase;letter-spacing:.65px;color:var(--t2);}
.fc{padding:.5rem .82rem;background:var(--s2);border:1.5px solid var(--bdr);border-radius:var(--r1);font-size:.82rem;color:var(--t1);font-family:'DM Sans',sans-serif;outline:none;transition:all .18s;}
.fc:focus{border-color:var(--m3);background:var(--s1);box-shadow:0 0 0 3px rgba(123,29,29,.07);}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:.625rem;}

/* ── TOAST ────────────────────────────────────────── */
/* .ttray / .tst live in assets/css/admin-shell.css — one toast for every admin page. */
.empty{padding:1.5rem;font-size:.8rem;}

/* ── RESPONSIVE ───────────────────────────────────── */
@media(max-width:1400px){.kpis{grid-template-columns:repeat(4,1fr);}.cgrid-3{grid-template-columns:1fr 1fr;}}
@media(max-width:1100px){.cgrid-2{grid-template-columns:1fr;}.cgrid-3{grid-template-columns:1fr;}.insight-grid{grid-template-columns:1fr;}}
@media(max-width:768px){.sb{transform:translateX(-100%);}.sb.open{transform:translateX(0);}.wrap{margin-left:0;}.pg{padding:1rem;}.mob-tog{display:flex;}.kpis{grid-template-columns:repeat(2,1fr);}}

/* ── PRINT ─────────────────────────────────────────
   The Print Report button had nothing behind it: the printout carried the
   sidebar, the sticky toolbar and the assistant bubble, the charts landed
   half-off the page, and the cards printed without their fills because
   browsers drop backgrounds by default. These rules make it an actual
   document, headed like the CSV/XLSX/PDF exports. */
.print-lh{display:none;}
@media print{
  @page{size:A4 portrait;margin:12mm 10mm;}
  html,body{background:#fff !important;}
  body{font-size:10pt;}
  /* keep the designed fills — a KPI card with no background is just a number */
  *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;
    box-shadow:none !important;text-shadow:none !important;animation:none !important;transition:none !important;}
  /* screen furniture */
  .sb,.topbar,.mob-tog,.ttray,.mo,#chatFab,#chatOverlay,#chatModal,
  .becca-fab,.becca-panel,.exp-wrap,.tb-r{display:none !important;}
  .wrap{margin-left:0 !important;min-height:0 !important;}
  .pg{padding:0 !important;}
  /* the letterhead, only on paper */
  .print-lh{display:block;text-align:center;margin:0 0 12px;}
  .print-lh img{height:58px;width:58px;object-fit:contain;}
  .print-lh .p-school{font-family:'Times New Roman',Georgia,serif;font-size:15pt;font-weight:800;color:#1C1008;letter-spacing:.3px;}
  .print-lh .p-office{font-family:'Times New Roman',Georgia,serif;font-style:italic;font-size:10pt;color:#1C1008;}
  .print-lh .p-doc{font-size:9pt;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#1C1008;margin-top:2px;}
  .print-lh .p-rule{height:3px;border-radius:4px;margin-top:6px;background:linear-gradient(90deg,#4A0E0E,#7B1D1D 55%,#C9960C);}
  .print-lh .p-meta{display:flex;justify-content:space-between;font-size:8pt;color:#755B4E;margin-top:5px;}
  /* nothing may be cut in half across a page break */
  .kcard,.cpanel,.insight-panel{page-break-inside:avoid;break-inside:avoid;}
  .kpis{grid-template-columns:repeat(4,1fr) !important;gap:6px !important;margin-bottom:10px !important;}
  .cgrid{gap:8px !important;}
  .cgrid-2,.cgrid-3,.cgrid-r,.insight-grid{grid-template-columns:1fr 1fr !important;}
  .cp-body{padding:6px !important;}
  .cp-body canvas{max-width:100% !important;height:auto !important;max-height:200px !important;}
  .print-foot{display:block !important;margin-top:10px;border-top:1px solid #E8DDD0;padding-top:6px;
    font-size:8pt;color:#755B4E;display:flex;justify-content:space-between;}
}
.print-foot{display:none;}
</style>
</head>
<body>

<!-- ════ SIDEBAR ══════════════════════════════════════ -->
<?php $activeNav = 'analytics'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

<!-- ════ MAIN ══════════════════════════════════════════ -->
<div class="wrap">
  <header class="topbar">
    <div class="tb-l">
      <button class="mob-tog" onclick="document.getElementById('sb').classList.toggle('open')"><i class="fas fa-bars"></i></button>
      <div>
        <div class="pg-title">Analytics</div>
        <div class="bc"><a href="admin_dashboard.php"><i class="fas fa-home"></i></a><i class="fas fa-chevron-right"></i><span>Analytics</span></div>
      </div>
    </div>
    <div class="tb-r">
      <a href="admin_notifications.php" class="ic-btn"><i class="fas fa-bell"></i><span class="pip"></span></a>
      <button class="btn btn-gold btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('customMo').classList.add('open')"><i class="fas fa-calendar-alt"></i> Custom Range</button>
    </div>
  </header>

  <div class="pg">

    <!-- Printed letterhead — hidden on screen, heads the page on paper so a
         printed analytics report matches the other BEC PMO documents. -->
    <div class="print-lh">
      <?php
        require_once __DIR__ . '/includes/export_branding.php';
        $anLogo = becExportLogoDataUri();
        if ($anLogo !== '') { echo '<img src="' . $anLogo . '" alt="BEC logo">'; }
      ?>
      <div class="p-school">BATANGAS EASTERN COLLEGES</div>
      <div class="p-office">Property Management Office</div>
      <div class="p-doc">Maintenance Analytics Report</div>
      <div class="p-rule"></div>
      <div class="p-meta">
        <span>Date: <?php echo esc(date('F j, Y')); ?></span>
        <span>Prepared by: <?php echo esc(becExportPreparedBy()); ?></span>
        <span>Ref. <?php echo esc(becExportRef()); ?></span>
      </div>
    </div>

    <!-- Page Header -->
    <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:1.1rem;gap:1rem;flex-wrap:wrap;">
      <div>
        <h1 style="font-family:'Outfit',sans-serif;font-size:1.45rem;font-weight:800;display:flex;align-items:center;gap:.45rem;">
          <i class="fas fa-chart-bar" style="color:var(--m3);"></i> Analytics Dashboard
        </h1>
        <p style="font-size:.78rem;color:var(--t3);margin-top:.18rem;">
          Data from <strong><?php echo date('M j, Y', strtotime($df)); ?></strong>
          to <strong><?php echo date('M j, Y', strtotime($dt)); ?></strong>
        </p>
      </div>
    </div>

    <!-- Date range tabs -->
    <div class="rtabs">
      <?php foreach([['7','Last 7 days'],['30','Last 30 days'],['90','Last 90 days'],['365','Last year']] as [$rv,$rl]):?>
      <a href="?range=<?php echo $rv;?>" class="rtab <?php echo $range===$rv?'on':'';?>">
        <?php echo $rl;?>
      </a>
      <?php endforeach;?>
      <?php if($range==='custom'):?>
      <span class="rtab on"><i class="fas fa-calendar-alt"></i> Custom: <?php echo date('M j',$_GET['from']?strtotime($_GET['from']):time());?> – <?php echo date('M j',$_GET['to']?strtotime($_GET['to']):time());?></span>
      <?php else:?>
      <a href="#" class="rtab" onclick="document.getElementById('customMo').classList.add('open');return false;"><i class="fas fa-calendar-alt"></i> Custom</a>
      <?php endif;?>
      <span class="date-range-lbl"><i class="fas fa-sync-alt"></i> Updated <?php echo date('M j, Y g:i A');?></span>
    </div>

    <!-- ── KPI STRIP ───────────────────────────────── -->
    <div class="kpis">
      <div class="kcard" style="--kc:var(--m3);--kb:#FDECEA;">
        <div class="kico"><i class="fas fa-file-alt"></i></div>
        <div class="knum" id="kn0"><?php echo $kpi_reports;?></div>
        <div class="klbl">Reports Filed</div>
      </div>
      <div class="kcard" style="--kc:var(--ok);--kb:#F0FDF4;">
        <div class="kico"><i class="fas fa-check-circle"></i></div>
        <div class="knum" id="kn1"><?php echo $kpi_resolved;?></div>
        <div class="klbl">Resolved</div>
      </div>
      <div class="kcard" style="--kc:#D97706;--kb:#FFFBEB;">
        <div class="kico"><i class="fas fa-hourglass-half"></i></div>
        <div class="knum" id="kn2"><?php echo $kpi_pending;?></div>
        <div class="klbl">Pending Verify</div>
      </div>
      <div class="kcard" style="--kc:#2563EB;--kb:#EFF6FF;">
        <div class="kico"><i class="fas fa-clipboard-check"></i></div>
        <div class="knum" id="kn3"><?php echo $kpi_inprog;?></div>
        <div class="klbl">In Progress</div>
      </div>
      <div class="kcard" style="--kc:var(--m3);--kb:#FEF9E7;">
        <div class="kico"><i class="fas fa-percentage"></i></div>
        <div class="knum" id="kn4"><?php echo $resolution_rate;?>%</div>
        <div class="klbl">Resolution Rate</div>
      </div>
      <div class="kcard" style="--kc:#7C3AED;--kb:#F5F3FF;">
        <div class="kico"><i class="fas fa-clock"></i></div>
        <div class="knum" id="kn5"><?php echo $avg_resolution_days;?>d</div>
        <div class="klbl">Avg Resolution</div>
      </div>
      <div class="kcard" style="--kc:var(--bad);--kb:#FFF1F2;">
        <div class="kico" style="animation:critGlow 2s ease-in-out infinite;"><i class="fas fa-radiation-alt"></i></div>
        <div class="knum" id="kn6"><?php echo $kpi_crit;?></div>
        <div class="klbl">Critical Active</div>
      </div>
      <div class="kcard" style="--kc:#0891B2;--kb:#ECFEFF;">
        <div class="kico"><i class="fas fa-desktop"></i></div>
        <div class="knum" id="kn7"><?php echo $kpi_op_eq;?></div>
        <div class="klbl">Operational Equip.</div>
      </div>
    </div>
    <style>@keyframes critGlow{0%,100%{box-shadow:none;}50%{box-shadow:0 0 14px rgba(220,38,38,.4);}}</style>

    <!-- ── ROW 1: Reports over time (wide) ────────── -->
    <div class="cgrid cgrid-2" style="margin-bottom:1.125rem;grid-template-columns:2fr 1fr;">
      <div class="cpanel" style="min-height:300px;">
        <div class="cp-head">
          <div>
            <h3><i class="fas fa-chart-line"></i> Defect Reports Over Time</h3>
            <div class="cp-sub">Submitted vs Resolved — <?php echo ucfirst($interval);?>ly breakdown</div>
          </div>
        </div>
        <div class="cp-body" style="padding:.75rem 1rem 1rem;">
          <?php if(empty($chart1_labels)):?>
          <div class="empty"><i class="fas fa-chart-line" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem;"></i>No data for this period.</div>
          <?php else:?>
          <canvas id="c1" style="width:100%;height:260px;"></canvas>
          <?php endif;?>
        </div>
      </div>

      <!-- Resolution rate ring -->
      <div class="cpanel" style="min-height:300px;">
        <div class="cp-head"><h3><i class="fas fa-bullseye"></i> Resolution Rate</h3></div>
        <div class="cp-body" style="flex-direction:column;gap:.75rem;">
          <div class="ring-wrap" style="justify-content:center;">
            <svg class="ring-svg" width="110" height="110" viewBox="0 0 110 110">
              <circle cx="55" cy="55" r="48" fill="none" stroke="#F2EAD9" stroke-width="10"/>
              <circle cx="55" cy="55" r="48" fill="none" stroke="url(#rg)" stroke-width="10"
                stroke-linecap="round" stroke-dasharray="<?php echo round(301.6*$resolution_rate/100,1);?> 301.6"
                transform="rotate(-90 55 55)" style="transition:stroke-dasharray .8s ease;"/>
              <defs><linearGradient id="rg" x1="0%" y1="0%" x2="100%" y2="0%">
                <stop offset="0%" stop-color="#7B1D1D"/><stop offset="100%" stop-color="#D4A017"/>
              </linearGradient></defs>
              <text x="55" y="50" text-anchor="middle" font-family="Outfit,sans-serif" font-weight="900" font-size="20" fill="#1A0808"><?php echo $resolution_rate;?>%</text>
              <text x="55" y="66" text-anchor="middle" font-family="DM Sans,sans-serif" font-size="9" fill="#9C7A7A">RESOLUTION</text>
            </svg>
          </div>
          <div style="padding:0 1rem 1rem;display:flex;flex-direction:column;gap:.35rem;width:100%;">
            <div class="stat-row" style="padding:.45rem .75rem;background:var(--s2);border-radius:var(--r1);">
              <span class="stat-lbl"><i class="fas fa-check"></i> Resolved</span>
              <span class="stat-val good"><?php echo $kpi_resolved;?></span>
            </div>
            <div class="stat-row" style="padding:.45rem .75rem;background:var(--s2);border-radius:var(--r1);">
              <span class="stat-lbl"><i class="fas fa-hourglass"></i> Pending</span>
              <span class="stat-val warn"><?php echo $kpi_pending;?></span>
            </div>
            <div class="stat-row" style="padding:.45rem .75rem;background:var(--s2);border-radius:var(--r1);">
              <span class="stat-lbl"><i class="fas fa-clock"></i> Avg Days</span>
              <span class="stat-val <?php echo $avg_resolution_days<=3?'good':($avg_resolution_days<=7?'warn':'bad');?>"><?php echo $avg_resolution_days;?>d</span>
            </div>
            <div class="stat-row" style="padding:.45rem .75rem;background:var(--s2);border-radius:var(--r1);">
              <span class="stat-lbl"><i class="fas fa-exclamation-circle"></i> Awaiting assignment</span>
              <span class="stat-val <?php echo $kpi_unassigned>0?'warn':'good';?>"><?php echo $kpi_unassigned;?></span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── ROW 2: Priority + Status + Equipment status ── -->
    <div class="cgrid cgrid-3" style="margin-bottom:1.125rem;">
      <!-- Priority bar chart -->
      <div class="cpanel" style="min-height:260px;">
        <div class="cp-head">
          <div><h3><i class="fas fa-layer-group"></i> Reports by Priority</h3>
          <div class="cp-sub">Distribution in selected period</div></div>
        </div>
        <div class="cp-body" style="padding:.75rem 1rem 1rem;">
          <?php if(empty($prio_labels)):?><div class="empty">No data.</div>
          <?php else:?><canvas id="c2" style="width:100%;height:200px;"></canvas><?php endif;?>
        </div>
      </div>
      <!-- Status donut -->
      <div class="cpanel" style="min-height:260px;">
        <div class="cp-head"><h3><i class="fas fa-circle-notch"></i> Report Status</h3></div>
        <div class="cp-body" style="padding:.75rem 1rem 1rem;">
          <?php if(empty($status_labels)):?><div class="empty">No data.</div>
          <?php else:?><canvas id="c3" style="width:100%;height:200px;"></canvas><?php endif;?>
        </div>
      </div>
      <!-- Equipment status donut -->
      <div class="cpanel" style="min-height:260px;">
        <div class="cp-head"><h3><i class="fas fa-desktop"></i> Equipment Status</h3></div>
        <div class="cp-body" style="padding:.75rem 1rem 1rem;">
          <?php if(empty($eq_st_labels)):?><div class="empty">No data.</div>
          <?php else:?><canvas id="c4" style="width:100%;height:200px;"></canvas><?php endif;?>
        </div>
      </div>
    </div>

    <!-- ── ROW 3: Top faulty equipment + Department ── -->
    <div class="cgrid cgrid-2" style="margin-bottom:1.125rem;">
      <!-- Top faulty equipment horizontal bar -->
      <div class="cpanel" style="min-height:280px;">
        <div class="cp-head">
          <div><h3><i class="fas fa-exclamation-triangle"></i> Most Reported Equipment</h3>
          <div class="cp-sub">Top items by defect count</div></div>
        </div>
        <div class="cp-body" style="padding:.75rem 1rem 1rem;">
          <?php if(empty($top_eq_res)):?><div class="empty"><i class="fas fa-check-circle" style="color:var(--ok);font-size:1.5rem;display:block;margin-bottom:.4rem;"></i>No defects reported!</div>
          <?php else:?><canvas id="c5" style="width:100%;height:240px;"></canvas><?php endif;?>
        </div>
      </div>
      <!-- Department distribution -->
      <div class="cpanel" style="min-height:280px;">
        <div class="cp-head">
          <div><h3><i class="fas fa-building"></i> Reports by Department</h3>
          <div class="cp-sub">Which departments report the most</div></div>
        </div>
        <div class="cp-body" style="padding:.75rem 1rem 1rem;">
          <?php if(empty($dept_labels)):?><div class="empty">No data.</div>
          <?php else:?><canvas id="c6" style="width:100%;height:240px;"></canvas><?php endif;?>
        </div>
      </div>
    </div>

    <!-- ── ROW 4: 12-month trend (full width) ─────── -->
    <div class="cgrid" style="margin-bottom:1.125rem;">
      <div class="cpanel" style="min-height:260px;">
        <div class="cp-head">
          <div><h3><i class="fas fa-chart-area"></i> 12-Month Trend</h3>
          <div class="cp-sub">Reports submitted vs resolved — all time rolling view</div></div>
        </div>
        <div class="cp-body" style="padding:.75rem 1rem 1rem;">
          <?php if(empty($trend_labels)):?><div class="empty">No trend data available yet.</div>
          <?php else:?><canvas id="c7" style="width:100%;height:220px;"></canvas><?php endif;?>
        </div>
      </div>
    </div>

    <!-- ── ROW 5: Technician performance (full width) ──
         Was a 2-column grid whose second cell held Work Orders. That panel went
         with the feature, leaving the table stranded at half width beside an
         empty column; the table has four columns and wants the room. -->
    <div class="cgrid" style="margin-bottom:1.125rem;grid-template-columns:1fr;">
      <!-- Technician performance -->
      <div class="cpanel">
        <div class="cp-head"><h3><i class="fas fa-hard-hat"></i> Technician Performance</h3></div>
        <div style="overflow-x:auto;">
          <?php if(empty($tech_res)):?>
          <div class="empty">No technician data.</div>
          <?php else:?>
          <table class="tech-tbl">
            <thead><tr><th>Technician</th><th>Assigned</th><th>Completed</th><th>Rate</th></tr></thead>
            <tbody>
              <?php foreach($tech_res as $t):
                $rate = ($t['total']??0)>0 ? round(($t['done']/$t['total'])*100) : 0;
                $initials = strtoupper(implode('',array_map(fn($p)=>substr($p,0,1),array_slice(explode(' ',$t['fullname']),0,2))));
              ?>
              <tr>
                <td>
                  <div style="display:flex;align-items:center;gap:.5rem;">
                    <div class="tech-av"><?php echo $initials;?></div>
                    <div>
                      <div style="font-weight:700;font-size:.79rem;"><?php echo esc($t['fullname']);?></div>
                      <div class="prog-bar" style="width:90px;">
                        <div class="prog-fill" style="width:<?php echo $rate;?>%;background:<?php echo $rate>=80?'#16A34A':($rate>=50?'#D97706':'#DC2626');?>;"></div>
                      </div>
                    </div>
                  </div>
                </td>
                <td style="font-family:'Outfit',sans-serif;font-weight:800;"><?php echo (int)$t['total'];?></td>
                <td style="font-family:'Outfit',sans-serif;font-weight:800;color:var(--ok);"><?php echo (int)$t['done'];?></td>
                <td>
                  <span style="font-family:'Outfit',sans-serif;font-weight:800;font-size:.85rem;color:<?php echo $rate>=80?'#16A34A':($rate>=50?'#D97706':'#DC2626');?>;">
                    <?php echo $rate;?>%
                  </span>
                </td>
              </tr>
              <?php endforeach;?>
            </tbody>
          </table>
          <?php endif;?>
        </div>
      </div>

    </div>

    <!-- ── ROW 6: Top reporters + Recent activity ── -->
    <div class="insight-grid">
      <!-- Top reporters -->
      <div class="insight-panel">
        <div class="cp-head"><h3><i class="fas fa-trophy"></i> Top Reporters</h3></div>
        <?php if(empty($top_reporters)):?>
        <div class="empty">No reporter data.</div>
        <?php else: foreach($top_reporters as $i=>$r):
          $rankCls = $i===0?'gold':($i===1?'silver':($i===2?'bronze':''));
          $initials = strtoupper(implode('',array_map(fn($p)=>substr($p,0,1),array_slice(explode(' ',$r['fullname']),0,2))));
        ?>
        <div class="rep-item">
          <div class="rep-rank <?php echo $rankCls;?>"><?php echo $i+1;?></div>
          <div class="rep-av"><?php echo $initials;?></div>
          <div style="flex:1;min-width:0;">
            <div class="rep-name"><?php echo esc($r['fullname']);?></div>
            <div class="rep-dept"><?php echo esc($r['department']??'—');?></div>
          </div>
          <div class="rep-cnt"><?php echo (int)$r['n'];?></div>
        </div>
        <?php endforeach; endif;?>
      </div>

      <!-- Recent activity -->
      <div class="insight-panel">
        <div class="cp-head"><h3><i class="fas fa-stream"></i> Recent Defect Activity</h3></div>
        <?php if(empty($activity)):?>
        <div class="empty">No recent activity.</div>
        <?php else: foreach($activity as $a):
          $pcol=becPriorityColor($a['priority'] ?? '');   // the one scale — config/database.php
          $stlbl=['reported'=>'Pending','assigned'=>'Assigned','in_progress'=>'In Progress','completed'=>'Completed','verified'=>'Verified','rejected'=>'Rejected'][$a['status']??'']??ucfirst($a['status']??'');
        ?>
        <div class="act-item">
          <div class="act-dot" style="background:<?php echo $pcol;?>;"></div>
          <div style="flex:1;min-width:0;">
            <div class="act-txt">
              <span class="act-id"><?php echo esc($a['id']);?></span>
              <span style="color:var(--t2);"> — <?php echo esc(substr($a['details']??'',0,55));?>…</span>
            </div>
            <div style="display:flex;gap:.4rem;margin-top:.2rem;flex-wrap:wrap;">
              <span style="font-size:.62rem;font-weight:800;background:var(--s2);padding:.12rem .42rem;border-radius:20px;color:var(--t2);"><?php echo $stlbl;?></span>
            </div>
            <div class="act-ts"><?php echo date('M j, Y g:i A',strtotime($a['ts']??'now'));?></div>
          </div>
        </div>
        <?php endforeach; endif;?>
      </div>
    </div>

    <!-- Print-only footer, matching the exported documents. -->
    <div class="print-foot">
      <span><b>Batangas Eastern Colleges</b> &middot; Property Management Office</span>
      <span>Confidential — for authorized administrative use only</span>
      <span>Generated <?php echo esc(date('Y-m-d H:i')); ?></span>
    </div>

  </div><!-- /pg -->
</div><!-- /wrap -->

<!-- ════ CUSTOM DATE RANGE MODAL ══════════════════════ -->
<div class="mo" id="customMo" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="mw">
    <div class="mhd">
      <div class="mhd-t">
        <h2><i class="fas fa-calendar-alt" style="margin-right:.3rem;opacity:.8;"></i> Custom Date Range</h2>
        <p>Select start and end date for analytics.</p>
      </div>
      <button class="mx" onclick="document.getElementById('customMo').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="mb">
      <form method="GET" action="admin_analytics.php" id="customForm">
        <input type="hidden" name="range" value="custom">
        <div class="fg2">
          <div class="fg"><label class="fl">From</label>
            <input type="date" name="from" class="fc" value="<?php echo esc($df);?>" required></div>
          <div class="fg"><label class="fl">To</label>
            <input type="date" name="to" class="fc" value="<?php echo esc($dt);?>" required></div>
        </div>
      </form>
    </div>
    <div class="mf">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('customMo').classList.remove('open')">Cancel</button>
      <button type="submit" form="customForm" class="btn btn-maroon btn-sm"><i class="fas fa-chart-bar"></i> Apply Range</button>
    </div>
  </div>
</div>

<!-- ════ LOGOUT MODAL ══════════════════════════════════ -->
<div class="mo" id="lgmo" onclick="if(event.target===this)this.classList.remove('open')">
  <div style="background:var(--s1);border-radius:var(--r4);padding:2rem;max-width:330px;width:90%;text-align:center;box-shadow:var(--sh3);animation:mUp .25s ease;margin:auto;">
    <i class="fas fa-sign-out-alt" style="font-size:2.2rem;color:var(--m3);margin-bottom:.7rem;display:block;"></i>
    <h3 style="font-family:'Outfit',sans-serif;font-size:1.05rem;font-weight:800;margin-bottom:.38rem;">Log Out?</h3>
    <p style="font-size:.8rem;color:var(--t2);margin-bottom:1.25rem;line-height:1.6;">You will be returned to the BEC admin login page.</p>
    <div style="display:flex;gap:.55rem;justify-content:center;">
      <button onclick="document.getElementById('lgmo').classList.remove('open')" class="btn btn-ghost btn-sm">Cancel</button>
      <a href="logout.php" class="btn btn-maroon btn-sm"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>
  </div>
</div>

<div class="ttray" id="ttray"></div>

<script>
/* ─── CHART.JS GLOBAL DEFAULTS ──────────────────────── */
Chart.defaults.font.family = "'DM Sans', sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#9C7A7A';
Chart.defaults.plugins.legend.display = false;
Chart.defaults.plugins.tooltip.backgroundColor = '#1A0808';
Chart.defaults.plugins.tooltip.titleColor = '#F0C040';
Chart.defaults.plugins.tooltip.bodyColor  = '#E5D9C6';
Chart.defaults.plugins.tooltip.cornerRadius = 8;
Chart.defaults.plugins.tooltip.padding = 10;

const MAROON = '#7B1D1D', GOLD = '#D4A017', GREEN = '#16A34A',
      BLUE = '#2563EB', PURPLE = '#7C3AED', CYAN = '#0891B2',
      RED = '#DC2626', AMBER = '#D97706', LIGHT = '#F2EAD9';

/* ─── CHART 1: Reports over time (line) ─────────────── */
<?php if(!empty($chart1_labels)):?>
new Chart(document.getElementById('c1'), {
  type:'line',
  data:{
    labels: <?php echo jArr($chart1_labels);?>,
    datasets:[
      {label:'Submitted',data:<?php echo jArr($chart1_total);?>,
        borderColor:MAROON,backgroundColor:'rgba(123,29,29,.10)',
        fill:true,tension:.4,pointBackgroundColor:MAROON,pointRadius:3,pointHoverRadius:6,borderWidth:2.5},
      {label:'Resolved',data:<?php echo jArr($chart1_resolved);?>,
        borderColor:GREEN,backgroundColor:'rgba(22,163,74,.08)',
        fill:true,tension:.4,pointBackgroundColor:GREEN,pointRadius:3,pointHoverRadius:6,borderWidth:2.5,borderDash:[4,3]}
    ]
  },
  options:{responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:true,position:'top',labels:{boxWidth:12,padding:16,color:'#5C3838',font:{weight:'700'}}}},
    scales:{
      x:{grid:{color:'rgba(229,217,198,.4)'},ticks:{maxRotation:45}},
      y:{grid:{color:'rgba(229,217,198,.4)'},beginAtZero:true,ticks:{stepSize:1}}
    },
    interaction:{intersect:false,mode:'index'}
  }
});
<?php endif;?>

/* ─── CHART 2: Priority bar ──────────────────────────── */
<?php if(!empty($prio_labels)):
  $prioColors=becPriorityColorMap();   // the one scale — config/database.php
  $pColors = array_map(fn($p)=>$prioColors[$p]??'#7B1D1D', $prio_labels);
?>
new Chart(document.getElementById('c2'), {
  type:'bar',
  data:{
    labels: <?php echo jArr(array_map('ucfirst',$prio_labels));?>,
    datasets:[{
      data:<?php echo jArr($prio_vals);?>,
      backgroundColor:<?php echo json_encode(array_values($pColors));?>,
      borderRadius:8,borderSkipped:false,
    }]
  },
  options:{responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:false}},
    scales:{
      x:{grid:{display:false}},
      y:{grid:{color:'rgba(229,217,198,.5)'},beginAtZero:true,ticks:{stepSize:1}}
    }
  }
});
<?php endif;?>

/* ─── CHART 3: Status donut ──────────────────────────── */
<?php if(!empty($status_labels)):
  $stColors=['reported'=>'#D4A017','assigned'=>'#2563EB','in_progress'=>'#7C3AED','completed'=>'#16A34A','verified'=>'#14532D','closed'=>'#6B7280','rejected'=>'#DC2626'];
  $sPalette = array_map(fn($s)=>$stColors[$s]??'#7B1D1D', $status_labels);
  $sLabels  = array_map(fn($s)=>['reported'=>'Pending','assigned'=>'Approved','in_progress'=>'In Progress','completed'=>'Completed','verified'=>'Verified','closed'=>'Closed','rejected'=>'Rejected'][$s]??ucfirst(str_replace('_',' ',$s)), $status_labels);
?>
new Chart(document.getElementById('c3'), {
  type:'doughnut',
  data:{
    labels:<?php echo jArr($sLabels);?>,
    datasets:[{data:<?php echo jArr($status_vals);?>,backgroundColor:<?php echo json_encode(array_values($sPalette));?>,borderWidth:2,borderColor:'#FFFFFF',hoverOffset:8}]
  },
  options:{responsive:true,maintainAspectRatio:false,cutout:'68%',
    plugins:{legend:{display:true,position:'bottom',labels:{boxWidth:10,padding:10,font:{size:10}}}}
  }
});
<?php endif;?>

/* ─── CHART 4: Equipment status donut ────────────────── */
<?php if(!empty($eq_st_labels)):
  $eqColors=['operational'=>'#16A34A','under_maintenance'=>'#D97706','faulty'=>'#DC2626','retired'=>'#9CA3AF'];
  $ePalette = array_map(fn($s)=>$eqColors[$s]??'#7B1D1D', $eq_st_labels);
  $eLabels  = array_map(fn($s)=>['operational'=>'Operational','under_maintenance'=>'Under Maint.','faulty'=>'Faulty','retired'=>'Retired'][$s]??ucfirst(str_replace('_',' ',$s)), $eq_st_labels);
?>
new Chart(document.getElementById('c4'), {
  type:'doughnut',
  data:{
    labels:<?php echo jArr($eLabels);?>,
    datasets:[{data:<?php echo jArr($eq_st_vals);?>,backgroundColor:<?php echo json_encode(array_values($ePalette));?>,borderWidth:2,borderColor:'#FFFFFF',hoverOffset:8}]
  },
  options:{responsive:true,maintainAspectRatio:false,cutout:'68%',
    plugins:{legend:{display:true,position:'bottom',labels:{boxWidth:10,padding:10,font:{size:10}}}}
  }
});
<?php endif;?>

/* ─── CHART 5: Top faulty equipment (horizontal bar) ─── */
<?php if(!empty($top_eq_res)):
  $topNames = array_map(fn($e)=>substr($e['equipment_name'],0,22), $top_eq_res);
  $topVals  = array_column($top_eq_res,'defects');
  $topCrits = array_column($top_eq_res,'crit');
?>
new Chart(document.getElementById('c5'), {
  type:'bar',
  data:{
    labels:<?php echo jArr($topNames);?>,
    datasets:[
      {label:'Defects',data:<?php echo jArr($topVals);?>,backgroundColor:'rgba(123,29,29,.75)',borderRadius:5,borderSkipped:false},
      {label:'Critical',data:<?php echo jArr($topCrits);?>,backgroundColor:RED,borderRadius:5,borderSkipped:false}
    ]
  },
  options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',
    plugins:{legend:{display:true,position:'top',labels:{boxWidth:10,padding:12,font:{size:10}}}},
    scales:{
      x:{grid:{color:'rgba(229,217,198,.5)'},stacked:false,beginAtZero:true,ticks:{stepSize:1}},
      y:{grid:{display:false}}
    }
  }
});
<?php endif;?>

/* ─── CHART 6: Department horizontal bar ─────────────── */
<?php if(!empty($dept_labels)):?>
new Chart(document.getElementById('c6'), {
  type:'bar',
  data:{
    labels:<?php echo jArr($dept_labels);?>,
    datasets:[{data:<?php echo jArr($dept_vals);?>,
      backgroundColor:[MAROON,GOLD,BLUE,GREEN,PURPLE,CYAN,AMBER,RED],
      borderRadius:5,borderSkipped:false}]
  },
  options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',
    plugins:{legend:{display:false}},
    scales:{
      x:{grid:{color:'rgba(229,217,198,.5)'},beginAtZero:true,ticks:{stepSize:1}},
      y:{grid:{display:false}}
    }
  }
});
<?php endif;?>

/* ─── CHART 7: 12-month trend (area) ────────────────── */
<?php if(!empty($trend_labels)):?>
new Chart(document.getElementById('c7'), {
  type:'line',
  data:{
    labels:<?php echo jArr($trend_labels);?>,
    datasets:[
      {label:'Submitted',data:<?php echo jArr($trend_total);?>,
        borderColor:MAROON,backgroundColor:'rgba(123,29,29,.08)',fill:true,tension:.4,
        pointBackgroundColor:MAROON,pointRadius:4,pointHoverRadius:7,borderWidth:2.5},
      {label:'Resolved',data:<?php echo jArr($trend_resolved);?>,
        borderColor:GREEN,backgroundColor:'rgba(22,163,74,.07)',fill:true,tension:.4,
        pointBackgroundColor:GREEN,pointRadius:4,pointHoverRadius:7,borderWidth:2.5,borderDash:[5,3]}
    ]
  },
  options:{responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:true,position:'top',labels:{boxWidth:12,padding:16,color:'#5C3838',font:{weight:'700'}}}},
    scales:{
      x:{grid:{color:'rgba(229,217,198,.4)'},ticks:{maxRotation:30}},
      y:{grid:{color:'rgba(229,217,198,.4)'},beginAtZero:true,ticks:{stepSize:1}}
    },
    interaction:{intersect:false,mode:'index'}
  }
});
<?php endif;?>

/* ─── ANIMATED KPI COUNTERS ──────────────────────────── */
function animN(id, to, suffix='') {
  const el = document.getElementById(id);
  if (!el) return;
  const raw = parseFloat(el.textContent) || 0;
  const dur = 900, t0 = performance.now();
  const tick = now => {
    const p = Math.min((now-t0)/dur, 1), e = 1 - Math.pow(1-p, 3);
    const cur = raw + (to - raw) * e;
    el.textContent = Number.isInteger(to) ? Math.round(cur) + suffix : cur.toFixed(1) + suffix;
    if (p < 1) requestAnimationFrame(tick);
  };
  requestAnimationFrame(tick);
}
document.addEventListener('DOMContentLoaded', () => {
  animN('kn0', <?php echo $kpi_reports;?>);
  animN('kn1', <?php echo $kpi_resolved;?>);
  animN('kn2', <?php echo $kpi_pending;?>);
  animN('kn3', <?php echo $kpi_inprog;?>);
  animN('kn4', <?php echo $resolution_rate;?>, '%');
  animN('kn5', <?php echo $avg_resolution_days;?>, 'd');
  animN('kn6', <?php echo $kpi_crit;?>);
  animN('kn7', <?php echo $kpi_op_eq;?>);
});

/* ─── TOAST ──────────────────────────────────────────── */
function toast(type, msg, title) {
  const el = document.createElement('div'); el.className = 'tst ' + type;
  el.innerHTML = `<div><div class="tst-t">${title}</div><div class="tst-m">${msg}</div></div>`;
  document.getElementById('ttray').appendChild(el);
  setTimeout(()=>{el.style.transition='opacity .3s';el.style.opacity='0';setTimeout(()=>el.remove(),300);},4000);
}
</script>
<script src="assets/sidebar_autohide.js" defer></script>
<script src="assets/date_picker.js"></script>
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<?php require __DIR__ . '/includes/admin_ui.php'; ?>
</body>
</html>



