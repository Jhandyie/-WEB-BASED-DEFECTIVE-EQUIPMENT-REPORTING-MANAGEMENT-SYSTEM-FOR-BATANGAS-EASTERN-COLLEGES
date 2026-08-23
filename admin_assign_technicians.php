<?php
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/file_storage_helpers.php';
require_once __DIR__ . '/includes/report_media.php';   // photoListFromRow / videoListFromRow

requireRole('admin');
require_once __DIR__ . '/includes/csrf.php';
$conn = getDBConnection();

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['fullname'] ?? 'Administrator';

/* --- POST ACTIONS ----------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'assign') {
        $rid   = $_POST['report_id']   ?? '';
        $tid   = $_POST['technician_id']?? '';
        $prio  = $_POST['priority']    ?? 'medium';
        $instr = $_POST['instructions']?? '';
        $dept  = $_POST['department']  ?? '';
        $result = assignDefectReportToTechnician($rid, $tid, [
            'actor_id' => $admin_id,
            'priority' => $prio,
            'instructions' => $instr,
            'department' => $dept,
        ]);
        if (!empty($result['ok']) && function_exists('notifyReporter')) {
            $fresh = getDefectReportById($rid);
            if ($fresh) {
                notifyReporter(
                    $fresh,
                    'A technician has been assigned to your report ' . $rid . '.',
                    'Technician Assigned',
                    'A technician has been assigned to handle your reported equipment issue. You will be notified once they begin the repair.'
                );
            }
        }
        // Web Push: instantly alert the assigned technician's installed app (best-effort).
        if (!empty($result['ok']) && $tid !== '') {
            try { require_once __DIR__ . '/includes/webpush.php'; wpNotifyUser((string) $tid); }
            catch (\Throwable $e) { error_log('assign push failed: ' . $e->getMessage()); }
        }
        $_SESSION['flash'] = [$result['ok'] ? 'ok' : 'err', $result['message']];
    }

    if ($act === 'unassign') {
        $rid = $_POST['report_id'] ?? '';
        updateDefectReport($rid, [
            'assigned_to'  => null,
            'status'       => 'ready_for_assignment',
            'assigned_date'=> null,
        ]);
        $_SESSION['flash'] = ['ok', "Report #$rid unassigned."];
    }

    header('Location: admin_assign_technicians.php');
    exit();
}

/* --- DATA -------------------------------------------- */
$allReportsForAssign = getAllDefectReports();

// Reports needing a technician: Received by PMO (pmo_review), ready_for_assignment,
// OR directly-approved (assigned) — all with no technician yet. Assigning a
// pmo_review report auto-approves it, so it can be assigned straight after receipt.
$unassigned = array_values(array_filter(
    $allReportsForAssign,
    fn($r) => in_array($r['status'], ['pmo_review', 'ready_for_assignment', 'assigned'], true) && ($r['assigned_to'] ?? '') === ''
));

// Active reports already handled by a technician (used for the table + workload).
$inprogress = array_values(array_filter(
    $allReportsForAssign,
    fn($r) => in_array($r['status'], ['assigned', 'accepted', 'in_progress', 'waiting_for_materials', 'for_replacement'], true) && ($r['assigned_to'] ?? '') !== ''
));

/* ── Render cap ────────────────────────────────────────────────────────────
   Both tables below render every matching row server-side, so the page is
   O(backlog): the same shape CLAUDE.md warns about, and the reason a 5,000-row
   admin page weighs ~11 MB. A dispatcher works the front of the queue — they
   never scroll to row 800 — so past a few hundred rows the markup is paid for
   and never read.

   The cap applies to the RENDER only. $unassigned and $inprogress stay whole
   above because the header counts and, more importantly, each technician's
   workload (and therefore the overloaded/available badge) are computed from
   them — capping the arrays themselves would silently under-count the workload
   and start recommending technicians who are already full. */
const BEC_ASSIGN_RENDER_CAP = 250;

$unassignedTotal = count($unassigned);
$inprogressTotal = count($inprogress);
$unassignedShown = array_slice($unassigned, 0, BEC_ASSIGN_RENDER_CAP);
$inprogressShown = array_slice($inprogress, 0, BEC_ASSIGN_RENDER_CAP);

// Technicians
$technicians = getAvailableTechnicians() ?: [];

// Enrich technicians with workload, availability and specialization.
foreach ($technicians as &$t) {
    $tid = $t['technician_id'] ?? $t['user_id'] ?? '';
    $t['tid'] = $tid;
    $t['workload'] = count(array_filter($inprogress, fn($r) => ($r['assigned_to'] ?? '') === $tid));
    $t['spec'] = trim((string)($t['specialization'] ?? '')) !== '' ? trim((string)$t['specialization']) : 'General';
    $t['dept'] = $t['department'] ?? $t['specialization'] ?? '';
    $acctActive = strtolower((string)($t['status'] ?? 'active')) === 'active';
    // Availability: Unavailable (inactive) > Overloaded (4+ active tasks) > Available
    $t['avail'] = !$acctActive ? 'unavailable' : ($t['workload'] >= 4 ? 'overloaded' : 'available');
}
unset($t);

/* defect_reports.assigned_to stores a user id (TECH-8902AF22), and the
   monitoring table was printing it raw under a heading that reads Technician.
   One lookup builds the map for the whole page: staff accounts number in the
   dozens, and the alternative — resolving a name per row — is exactly the
   O(rows) page-load work this codebase gets bitten by. A report can also be
   held by someone getAvailableTechnicians() does not return (an admin with a
   TECH- id, for one), so the map is built from users rather than from the
   technician list, and the id still shows if no name is found. */
$staffNames = [];
$staffInfo  = [];
try {
    $nameRes = $conn->query("SELECT user_id, fullname, email, phone, position, department FROM users");
    if ($nameRes) {
        while ($u = $nameRes->fetch_assoc()) {
            $uid = (string)$u['user_id'];
            $staffNames[$uid] = (string)$u['fullname'];
            /* Contact details for the profile card. Read here because the same
               query is already being made — a second trip for four technicians
               would cost more than the rows are worth. */
            $staffInfo[$uid] = [
                'email'    => (string)($u['email']    ?? ''),
                'phone'    => (string)($u['phone']    ?? ''),
                'position' => (string)($u['position'] ?? ''),
                'dept'     => (string)($u['department'] ?? ''),
            ];
        }
    }
} catch (Throwable $e) {
    /* A missing name is cosmetic — the id still identifies the row. */
}

/* Profile payload for the technician cards. Everything here is a stored value —
   name, department, specialization, account status, contact details, and the
   reports the person is actually holding right now. Nothing is scored,
   predicted or inferred: the card already shows a workload bar, and a profile
   that claimed to rate someone's suitability would be inventing a judgement the
   system does not make. Built once for the page rather than per card. */
$techProfiles = [];
foreach ($technicians as $t) {
    $tid = (string)($t['tid'] ?? '');
    if ($tid === '') { continue; }
    $held = [];
    foreach ($inprogress as $r) {
        if ((string)($r['assigned_to'] ?? '') !== $tid) { continue; }
        $held[] = [
            'id'     => (string)($r['report_id'] ?? ''),
            'eq'     => (string)($r['equipment_name'] ?? 'Equipment'),
            'status' => stLbl($r['status'] ?? ''),
            'scls'   => stCls($r['status'] ?? ''),
            'prio'   => prLbl($r['priority'] ?? ''),
            'pcls'   => prCls($r['priority'] ?? ''),
            'when'   => !empty($r['assigned_date']) ? date('M j', strtotime((string)$r['assigned_date'])) : '',
        ];
    }
    $info = $staffInfo[$tid] ?? [];
    /* The availability wording and colours are decided in one place (availMeta)
       and carried into the payload, so the profile cannot drift into saying
       something different from the card it was opened from. */
    [$pLbl, $pColor, $pIcon, $pBg] = availMeta($t['avail'] ?? '');
    $techProfiles[$tid] = [
        'aLbl'   => $pLbl,
        'aColor' => $pColor,
        'aBg'    => $pBg,
        'name'   => (string)($t['fullname'] ?? 'Technician'),
        'tid'    => $tid,
        'spec'   => (string)($t['spec'] ?? ''),
        'dept'   => (string)($info['dept'] ?? $t['dept'] ?? ''),
        'pos'    => (string)($info['position'] ?? ''),
        'email'  => (string)($info['email'] ?? ''),
        'phone'  => (string)($info['phone'] ?? ''),
        'avail'  => (string)($t['avail'] ?? ''),
        'load'   => (int)($t['workload'] ?? 0),
        'held'   => $held,
    ];
}

// Pre-select report from URL (coming from defect reports page)
$preReport = null;
if (isset($_GET['report'])) {
    $preReport = getDefectReportById($_GET['report']);
    if ($preReport) {
        $eq = getEquipmentById($preReport['equipment_id'] ?? '');
        $preReport['equipment_name'] = $eq['equipment_name'] ?? '-';
        $preReport['asset_tag']      = $eq['asset_tag']      ?? '-';
        $preReport['location']       = $eq['location']       ?? '-';
    }
}

/* The "smart recommendation" is gone. It ranked technicians on workload and a
   loose string match against a free-text specialization field, then presented
   the winner as a best match — a confidence the inputs could not support, and
   one that quietly pushed work toward whoever happened to be typed as
   "Electrical". Choosing who goes is the dispatcher's judgement; the page's job
   is to show what they need to make it (who is free, who is carrying what) and
   then get out of the way. */

// Stats
$totalTechs      = count($technicians);
$availTechs      = count(array_filter($technicians, fn($t) => ($t['avail'] ?? '') === 'available'));
$overloadedTechs = count(array_filter($technicians, fn($t) => ($t['avail'] ?? '') === 'overloaded'));
$totalUnassigned = count($unassigned);
$totalInProgress = count($inprogress);

/* --- HELPERS ----------------------------------------- */
function prCls($p){return['critical'=>'crit','high'=>'hi','medium'=>'med','low'=>'lo'][$p]??'lo';}
function prLbl($p){return ucfirst($p??'-');}
function stLbl($s){return['ready_for_assignment'=>'Ready','assigned'=>'Assigned','in_progress'=>'In Progress','for_replacement'=>'For Replacement'][$s]??ucfirst(str_replace('_',' ',$s));}
function stCls($s){return['ready_for_assignment'=>'pend','assigned'=>'prog','in_progress'=>'prog2','for_replacement'=>'rej'][$s]??'pend';}
function esc($s){return htmlspecialchars((string)($s??'-'),ENT_QUOTES,'UTF-8');}
/* wlClass/wlLabel/wlColor lived here and had no callers. Availability comes
   from availMeta() now, which returns the label, colour, icon and background
   together — the three of them classified the same number three ways and could
   disagree. */
function deptClass($d){
    $d=strtolower($d??'');
    if(strpos($d,'itso')!==false||strpos($d,'it')!==false||strpos($d,'computer')!==false||strpos($d,'tech')!==false)return'itso';
    if(strpos($d,'pmo')!==false||strpos($d,'physical')!==false||strpos($d,'maint')!==false||strpos($d,'facilities')!==false)return'pmo';
    return'gen';
}
function availMeta($a){
    return [
        'available'   => ['Available','#16A34A','fa-circle-check','#E9F9EF'],
        'overloaded'  => ['Overloaded','#DC2626','fa-triangle-exclamation','#FDECEC'],
        'unavailable' => ['Unavailable','#6B7280','fa-ban','#F1F1F1'],
    ][$a] ?? ['Available','#16A34A','fa-circle-check','#E9F9EF'];
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Assign Technicians - BEC Admin</title>
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="css/typography.css">
<link rel="stylesheet" href="assets/css/admin-shell.css">
<style>

/* =======================================================
   BEC ADMIN - Assign Technicians
   Maroon x Gold x Warm | Outfit + DM Sans
======================================================= */
:root{
  --m1:#2D0505;--m2:#4A0E0E;--m3:#7B1D1D;--m4:#9B2C2C;
  --g2:#D4A017;--g3:#F0C040;
  --bg:#F4EFE6;--s1:#FFFFFF;--s2:#FAF7F0;--s3:#F2EAD9;
  --bdr:#E5D9C6;--bdr2:#D0C0A8;
  --t1:#1A0808;--t2:#5C3838;--t3:#9C7A7A;--t4:#C8ABAB;
  --sh0:0 1px 2px rgba(45,5,5,.05);
  --sh1:0 2px 8px rgba(45,5,5,.07),0 1px 3px rgba(45,5,5,.04);
  --sh2:0 6px 20px rgba(45,5,5,.09),0 2px 6px rgba(45,5,5,.05);
  --sh3:0 14px 40px rgba(45,5,5,.13),0 4px 10px rgba(45,5,5,.07);
  --r1:8px;--r2:12px;--r3:18px;--r4:26px;--sb:262px;
  --fs-xs:.6rem;--fs-sm:.68rem;--fs-base:.76rem;--fs-md:.82rem;--fs-lg:.88rem;--fs-xl:1.02rem;
  --sp-1:.25rem;--sp-2:.5rem;--sp-3:.75rem;--sp-4:1rem;--sp-5:1.5rem;
}
/* Six type steps and five spaces, the same scale admin_defect_reports.php uses.
   This page had 37 different font sizes — .62 and .63 and .64rem all in play,
   none of the differences meaning anything — so the scale is the whole point:
   every size in this file is var(--fs-*), inline styles included. Two things
   stay literal on purpose: the page h1 and the stat number, which are display
   sizes, and the 2.2rem glyphs in the empty state and the log-out modal, which
   are illustration rather than type.

   The spacing tokens are applied only where a value already sat exactly on a
   step, so nothing moved. The literals still here — .45rem, .625rem, .875rem
   and the rest — are off-scale on purpose for now: collapsing them is a real
   layout change, and this page carries measurements that were arrived at by
   measuring (the drawer's 720px, the queue's column widths). That is its own
   pass, with its own before/after, not a side effect of a typography cleanup. */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--t1);
  min-height:100vh;overflow-x:hidden;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='400'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.022'/%3E%3C/svg%3E");}

/* -- SIDEBAR --------------------------------------- */
/* sidebar styling lives in assets/css/admin-shell.css */
.lout i{transition:transform .3s;}.lout:hover i{transform:rotate(180deg);}

/* -- MAIN LAYOUT ----------------------------------- */
.wrap{margin-left:var(--sb);min-height:100vh;display:flex;flex-direction:column;}
.topbar{background:rgba(255,252,245,.93);backdrop-filter:blur(14px);
  border-bottom:1px solid var(--bdr);height:58px;padding:0 1.75rem;
  display:flex;align-items:center;justify-content:space-between;
  position:sticky;top:0;z-index:200;box-shadow:var(--sh0);}
.tb-l{display:flex;align-items:center;gap:.55rem;}
.mob-tog{display:none;background:none;border:none;font-size:var(--fs-xl);cursor:pointer;color:var(--t2);}
/* .pg-title is set by assets/css/admin-shell.css. Restating it here with this
   page's own scale variable made the topbar title 17.34px where every other
   admin page draws it at 17px. */
.bc{font-size:var(--fs-sm);color:var(--t3);display:flex;align-items:center;gap:var(--sp-1);}
.bc a{color:var(--t3);text-decoration:none;}.bc a:hover{color:var(--m3);}
.bc i{font-size:var(--fs-xs);}
.tb-r{display:flex;align-items:center;gap:.55rem;}
.ic-btn{width:34px;height:34px;background:var(--s2);border:1px solid var(--bdr);
  border-radius:var(--r1);display:flex;align-items:center;justify-content:center;
  cursor:pointer;color:var(--t2);font-size:var(--fs-lg);transition:all .17s;
  text-decoration:none;position:relative;box-shadow:none;}
.ic-btn:hover{background:var(--m3);color:#fff;transform:none;box-shadow:none;}
.pip{position:absolute;top:5px;right:5px;width:7px;height:7px;
  background:var(--g2);border-radius:50%;border:2px solid var(--s1);
  animation:pp 2.2s ease-in-out infinite;}
@keyframes pp{0%,100%{transform:scale(1);}50%{transform:scale(1.4);}}
.pg{padding:var(--sp-5) 1.75rem;flex:1;}

/* -- FLASH ----------------------------------------- */
.flash{display:flex;align-items:center;gap:.6rem;padding:.7rem 1.1rem;
  border-radius:var(--r2);margin-bottom:1.1rem;font-size:var(--fs-md);font-weight:600;
  animation:fIn .25s ease;border-left:3px solid;}
@keyframes fIn{from{opacity:0;transform:translateY(-5px);}to{opacity:1;transform:translateY(0);}}
.flash.ok{background:#F0FDF4;color:#15803D;border-color:#22C55E;}
.flash.err{background:#FFF1F2;color:#DC2626;border-color:#EF4444;}

/* -- PAGE HEADER ----------------------------------- */
.ph{display:flex;align-items:flex-end;justify-content:space-between;
  margin-bottom:1.25rem;gap:var(--sp-4);flex-wrap:wrap;}
.ph h1{font-family:'Outfit',sans-serif;font-size:1.45rem;font-weight:800;
  display:flex;align-items:center;gap:.45rem;}
.ph h1 i{color:var(--m3);}
.ph-sub{font-size:var(--fs-base);color:var(--t3);margin-top:.18rem;}
.ph-acts{display:flex;gap:.45rem;}

/* -- BTN SYSTEM ------------------------------------ */
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.4rem .875rem;
  border-radius:var(--r1);font-family:'DM Sans',sans-serif;font-size:var(--fs-base);
  font-weight:700;cursor:pointer;border:none;transition:all .17s;
  text-decoration:none;white-space:nowrap;}
.btn:hover{transform:none;}.btn:active{transform:translateY(0);}
.btn-maroon{background:linear-gradient(135deg,var(--m3),var(--m4));color:#fff;box-shadow:none;}
.btn-maroon:hover{box-shadow:none;}
.btn-green{background:linear-gradient(135deg,#15803D,#22C55E);color:#fff;box-shadow:none;}
.btn-green:hover{box-shadow:none;}
.btn-red{background:linear-gradient(135deg,#B91C1C,#EF4444);color:#fff;box-shadow:none;}
.btn-red:hover{box-shadow:none;}
.btn-ghost{background:var(--s2);color:var(--t2);border:1px solid var(--bdr);}
.btn-ghost:hover{background:var(--s3);}
.btn-sm{padding:.3rem .6rem;font-size:var(--fs-sm);}
/* .bico is the square icon button. Only the destructive tint is still used —
   the view and accept tints went with the buttons that had them. */
.bico{width:26px;height:26px;padding:0;display:flex;align-items:center;
  justify-content:center;border-radius:var(--r1);font-size:var(--fs-sm);}
.bi-d{background:#FFF1F2;color:#BE123C;}.bi-d:hover{background:#FFE4E6;}

/* -- SUMMARY CARDS --------------------------------- */
.sums{display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-3);margin-bottom:1.375rem;}
.scard{background:var(--s1);border-radius:var(--r3);padding:1.1rem 1.25rem;
  border:1px solid var(--bdr);position:relative;overflow:hidden;
  transition:all .26s cubic-bezier(.4,0,.2,1);box-shadow:var(--sh0);}
.scard::before{content:'';position:absolute;top:-16px;right:-16px;
  width:70px;height:70px;border-radius:50%;background:var(--sk);
  opacity:.04;transition:all .28s;}
.scard::after{content:'';position:absolute;bottom:0;left:0;width:100%;
  height:3px;background:var(--sk);border-radius:0 0 var(--r3) var(--r3);
  transform:scaleX(0);transform-origin:left;transition:transform .32s;}
.scard:hover{transform:none;
  box-shadow:var(--sh3);border-color:transparent;}
.scard:hover::before{transform:none;opacity:.08;}
.scard:hover::after{transform:scaleX(1);}
.sc-a{--sk:var(--m3);--skl:rgba(123,29,29,.14);}
.sc-b{--sk:#16A34A;--skl:rgba(22,163,74,.14);}
.sc-c{--sk:#D97706;--skl:rgba(217,119,6,.14);}
.sc-d{--sk:#7C3AED;--skl:rgba(124,58,237,.14);}
.sico{width:40px;height:40px;border-radius:var(--r2);display:flex;align-items:center;
  justify-content:center;font-size:var(--fs-lg);margin-bottom:.6rem;
  background:var(--sib);color:var(--sic);
  box-shadow:none;transition:all .26s;position:relative;z-index:1;}
.scard:hover .sico{transform:none;}
.sc-a .sico{--sib:#FDECEA;--sic:var(--m3);}
.sc-b .sico{--sib:#F0FDF4;--sic:#16A34A;}
.sc-c .sico{--sib:#FFFBEB;--sic:#D97706;}
.sc-d .sico{--sib:#F5F3FF;--sic:#7C3AED;}
.snum{font-family:'Outfit',sans-serif;font-size:2.2rem;font-weight:800;
  color:var(--t1);line-height:1;margin-bottom:.18rem;
  position:relative;z-index:1;transition:color .26s;}
.scard:hover .snum{color:var(--sk);}
.slbl{font-size:var(--fs-xs);text-transform:uppercase;letter-spacing:.7px;
  color:var(--t3);font-weight:700;position:relative;z-index:1;}
.smicro{font-size:var(--fs-sm);color:var(--t3);margin-top:.22rem;
  position:relative;z-index:1;display:flex;align-items:center;gap:.3rem;}
.smicro i{font-size:var(--fs-xs);}

/* -- MAIN GRID ------------------------------------- */
/* 400px, not 360: the technician cards now live in this column and a name plus
   a workload bar plus an Available pill does not fit comfortably in 360. */
/* One column. The second track held the assignment workspace, which is a drawer
   now — so the queue gets the whole page and its six columns stop fighting for
   room against a form that was idle most of the time. */
.main-grid{display:block;}

/* -- PANEL ----------------------------------------- */
.panel{background:var(--s1);border-radius:var(--r3);border:1px solid var(--bdr);
  box-shadow:var(--sh1);overflow:hidden;transition:box-shadow .22s;}
.panel:hover{box-shadow:var(--sh2);}
.ph3{padding:.875rem 1.25rem;border-bottom:1px solid var(--bdr);
  display:flex;align-items:center;justify-content:space-between;
  background:linear-gradient(to right,var(--s2),var(--s1));}
.ph3 h3{font-family:'Outfit',sans-serif;font-size:var(--fs-lg);font-weight:700;
  color:var(--t1);display:flex;align-items:center;gap:.35rem;margin:0;}
.ph3 h3 i{color:var(--m3);}

/* -- TABLE ----------------------------------------- */
/* Table type comes from .tbl in assets/css/admin-shell.css — this page had
   its own copy, like the other two rosters did. Only the column behaviour
   specific to these tables stays here. A ticket number and a date are
   single tokens: letting them wrap turned BEC-2026-000097 into three lines
   and made every row a different height. */
/* The panel clips (rounded corners), so without a scroll container the Action
   column — the Assign button itself — was cut off at the right edge. */
/* Segmented priority control — four choices shown at once instead of hidden
   behind a dropdown. The radio itself is the state; the label is the target, so
   it stays keyboard-reachable and needs no JS. */
.prio-seg{display:grid;grid-template-columns:repeat(4,1fr);gap:.3rem;}
.ps-opt{position:relative;display:block;cursor:pointer;}
.ps-opt input{position:absolute;opacity:0;width:0;height:0;}
.ps-opt span{display:block;text-align:center;padding:.45rem .22rem;border-radius:var(--r1);
  border:1.5px solid var(--bdr);background:var(--s1);
  font-size:var(--fs-base);font-weight:700;color:var(--t2);transition:all .15s;}
.ps-opt:hover span{border-color:var(--bdr2);}
.ps-opt input:focus-visible + span{outline:2px solid var(--m3);outline-offset:2px;}
.ps-low     input:checked + span{background:#EFF6FF;border-color:#2563EB;color:#1D4ED8;}
.ps-medium  input:checked + span{background:#FFFBEB;border-color:#D4A017;color:#92600A;}
.ps-high    input:checked + span{background:#FFF7ED;border-color:#EA580C;color:#C2410C;}
.ps-critical input:checked + span{background:#FEF2F2;border-color:#DC2626;color:#B91C1C;}

/* The chosen technician is stated on the card you clicked, not only in a select
   the card happens to drive. */
/* ── responsible unit cards ───────────────────────────────────────────────
   Two units, stated with what each one covers, selected by clicking. Same
   shape as the priority control so the form asks its questions one way. */
.unit-seg{display:grid;grid-template-columns:1fr 1fr;gap:.45rem;}
.unit-opt{position:relative;display:block;cursor:pointer;}
.unit-opt input{position:absolute;opacity:0;width:0;height:0;}
.unit-box{display:block;position:relative;height:100%;padding:.6rem .6rem;border-radius:var(--r1);
  border:1.5px solid var(--bdr);background:var(--s1);transition:all .15s;}
.unit-opt:hover .unit-box{border-color:var(--bdr2);}
.unit-opt input:focus-visible + .unit-box{outline:2px solid var(--m3);outline-offset:2px;}
.unit-ic{display:block;font-size:var(--fs-lg);color:var(--t3);margin-bottom:var(--sp-1);}
.unit-t{display:block;font-family:'Outfit',sans-serif;font-size:var(--fs-md);font-weight:800;color:var(--t1);}
.unit-d{display:block;font-size:var(--fs-xs);color:var(--t3);line-height:1.35;margin-top:.1rem;}
.unit-check{position:absolute;top:.45rem;right:.45rem;width:17px;height:17px;border-radius:50%;
  background:var(--m3);color:#fff;font-size:var(--fs-xs);display:none;align-items:center;justify-content:center;}
.unit-opt input:checked + .unit-box{border-color:var(--m3);background:#FDF6F6;}
.unit-opt input:checked + .unit-box .unit-check{display:flex;}
.unit-opt input:checked + .unit-box .unit-ic{color:var(--m3);}
.unit-itso input:checked + .unit-box{border-color:#2563EB;background:#EFF6FF;}
.unit-itso input:checked + .unit-box .unit-check{background:#2563EB;}
.unit-itso input:checked + .unit-box .unit-ic{color:#2563EB;}

/* ── assignment stepper ───────────────────────────────────────────────────
   Four short words telling you where you are in a job that used to be a
   column of unlabelled fields. Driven by what has actually been chosen. */
.asg-steps{display:grid;grid-template-columns:repeat(4,1fr);gap:.3rem;
  padding:.7rem .875rem;border-bottom:1px solid var(--bdr);background:var(--s2);}
.asg-step{text-align:center;font-size:var(--fs-xs);font-weight:700;color:var(--t4);
  letter-spacing:.3px;text-transform:uppercase;}
.asg-step span{display:flex;align-items:center;justify-content:center;
  width:20px;height:20px;margin:0 auto .18rem;border-radius:50%;
  border:1.5px solid var(--bdr);background:var(--s1);
  font-size:var(--fs-xs);font-weight:800;color:var(--t4);}
.asg-step.done{color:var(--t2);}
.asg-step.done span{background:#16A34A;border-color:#16A34A;color:#fff;}
.asg-step.now{color:var(--m3);}
.asg-step.now span{background:var(--m3);border-color:var(--m2);color:#fff;
  box-shadow:0 0 0 3px rgba(123,29,29,.13);}

/* ── technician card ──────────────────────────────────────────────────────
   Name, what they do, where they sit, whether they are free and how loaded —
   the five things needed to choose someone, on the card you click. */
.tcd{position:relative;border:1.5px solid #E8DFD0;border-radius:14px;background:#fff;
  padding:.875rem .875rem;transition:border-color .15s,box-shadow .15s;}
.tech-card{cursor:pointer;}
.tech-card:hover{border-color:#C9960C;box-shadow:0 6px 18px rgba(123,29,29,.10);}
.tech-card:focus-visible{outline:2px solid var(--m3);outline-offset:2px;}
.tcd-off{opacity:.55;background:#FAF7F0;}

/* Selected is a check and a border, not only a colour. */
.tcd-check{position:absolute;top:.7rem;right:.7rem;width:20px;height:20px;border-radius:50%;
  background:var(--m3);color:#fff;font-size:var(--fs-xs);display:none;
  align-items:center;justify-content:center;}
.tech-card.on{border-color:var(--m3);box-shadow:0 0 0 2px rgba(123,29,29,.14);}
.tech-card.on .tcd-check{display:flex;}
.tech-card.on .tcd-cta{color:var(--m3);font-weight:800;}
.tcd-top{display:flex;align-items:center;gap:.6rem;}
.tcd-av{width:38px;height:38px;border-radius:50%;flex-shrink:0;color:#fff;
  background:linear-gradient(135deg,var(--m3),var(--m4));
  display:flex;align-items:center;justify-content:center;
  font-family:'Outfit',sans-serif;font-weight:900;font-size:var(--fs-base);}
.tcd-id{flex:1;min-width:0;}
.tcd-name{font-weight:700;font-size:var(--fs-lg);color:var(--t1);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.tcd-meta{display:flex;align-items:center;gap:.35rem;flex-wrap:wrap;margin-top:.1rem;}
.tcd-spec{font-size:var(--fs-sm);color:#8A6D5A;white-space:nowrap;}
.tcd-spec i{font-size:var(--fs-xs);color:#C9960C;}
.tcd-dept{font-size:var(--fs-xs);color:#8A6D5A;background:#F8F3EA;border:1px solid #EDE4D6;
  border-radius:20px;padding:.05rem .4rem;white-space:nowrap;
  max-width:9rem;overflow:hidden;text-overflow:ellipsis;}
.tcd-avail{display:inline-flex;align-items:center;gap:.25rem;flex-shrink:0;
  font-size:var(--fs-xs);font-weight:800;padding:.18rem var(--sp-2);border-radius:20px;}
.tcd-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0;}
.tcd-load{margin-top:.6rem;}
.tcd-load-l{display:flex;justify-content:space-between;align-items:baseline;
  font-size:var(--fs-xs);color:#8A6D5A;margin-bottom:.22rem;}
.tcd-load-n{font-weight:700;color:var(--t2);}
.tcd-bar{height:5px;background:#F0E7D8;border-radius:4px;overflow:hidden;}
.tcd-bar span{display:block;height:100%;border-radius:4px;}
.tcd-cta{margin-top:var(--sp-2);font-size:var(--fs-xs);color:#B08D4F;text-align:right;}
.tcd-cta-off{color:#9C8A7A;}

.tblwrap{overflow-x:auto;}
/* These two tables carry six and seven columns inside a panel that lost 40px
   when the technician picker moved into the right column, so the Action cell —
   the Assign button — fell off the edge. Tighter gutters and a ceiling on the
   free-text column bring it back on screen; the wrapper above is the safety net
   for narrow displays, not the normal way to reach the button. */
/* Active assignments moved out from under the queue, so the left column is the
   queue's alone and the Action cell fits again without the squeeze. */
.tbl thead th,.tbl tbody td{padding-left:.6rem;padding-right:.6rem;}
/* The monitoring table now runs the full page width and can be read as a table
   rather than as something crammed beside a form, so it gets the wider gutters
   the queue beside a 400px workspace cannot afford. */
.asg-active{margin-top:1.25rem;}
.asg-active .tbl thead th,.asg-active .tbl tbody td{padding-left:var(--sp-4);padding-right:var(--sp-4);}
/* The queue shares its row with the workspace, so the free-text column is what
   gives way — the Assign button must stay reachable without scrolling. */
/* Column widths are declared in the queue table's own colgroup now. */
#unTbl th:last-child,#unTbl td:last-child{width:1%;white-space:nowrap;}
.tbl td .esl{white-space:nowrap;}
.tbl td:last-child,.tbl th:last-child{white-space:nowrap;width:1%;}
.tbl td .rid,.tbl td.nw,.tbl th.nw{white-space:nowrap;}
.tbl tbody td:first-child,.tbl tbody td:nth-last-child(2){white-space:nowrap;}
.tbl tbody tr{transition:background .1s,transform .1s;}
.tbl tbody tr:hover{transform:none;}
.rid{font-family:'Outfit',sans-serif;font-weight:800;color:var(--m3);font-size:var(--fs-base);white-space:nowrap;}
/* The ticket number is the way into the full record. Underlined only on hover
   so a column of them does not read as a wall of links. */
.rid-lnk{text-decoration:none;border-bottom:1px solid transparent;
  transition:color .15s,border-color .15s;}
.rid-lnk:hover{color:var(--m2);border-bottom-color:currentColor;}
.rid-lnk:focus-visible{outline:2px solid var(--m3);outline-offset:2px;border-radius:3px;}

/* Ticket number and the way to the full record, on one baseline. */
.rep-idrow{display:flex;align-items:center;justify-content:space-between;gap:var(--sp-2);}
.rep-full{display:inline-flex;align-items:center;gap:.3rem;flex-shrink:0;
  font-size:var(--fs-sm);font-weight:700;color:var(--m3);text-decoration:none;
  padding:.22rem var(--sp-2);border:1px solid var(--bdr);border-radius:20px;background:var(--s1);
  transition:background .15s,color .15s,border-color .15s;}
.rep-full i{font-size:var(--fs-xs);}
.rep-full:hover{background:var(--m3);border-color:var(--m3);color:#fff;}
.rep-full:focus-visible{outline:2px solid var(--m3);outline-offset:2px;}
.en{font-weight:700;}.esl{font-size:var(--fs-sm);color:var(--t3);}

/* -- QUEUE TOOLBAR --------------------------------- */
/* The toolbar's controls are labelled for screen readers but their purpose is
   already obvious on screen, so the labels are hidden rather than shown.
   Defined here, not in admin-shell.css: no other admin page needs it yet, and
   that file is shared with work happening in parallel. */
.sr-only{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;
  clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;border:0;}
/* A dispatcher works the queue by asking three questions — is this one mine to
   worry about, which is worst, and what has been waiting longest. The table
   answered none of them without reading every row, so the questions get
   controls. All of it filters the rows already on the page: the queue is the
   set of unassigned reports, it is small by definition, and a round trip to
   Supabase costs ~429ms to tell us something the browser already knows. */
.qbar{display:flex;align-items:center;gap:var(--sp-2);flex-wrap:wrap;
  padding:.7rem 1.25rem;border-bottom:1px solid var(--bdr);background:var(--s2);}
.qsearch{position:relative;flex:1 1 15rem;min-width:11rem;display:flex;align-items:center;}
.qsearch > i{position:absolute;left:.6rem;font-size:var(--fs-sm);color:var(--t3);pointer-events:none;}
.qsearch input{width:100%;height:2rem;padding:0 1.9rem 0 1.9rem;border-radius:var(--r1);
  border:1.5px solid var(--bdr);background:var(--s1);font-family:'DM Sans',sans-serif;
  font-size:var(--fs-base);color:var(--t1);transition:border-color .15s,box-shadow .15s;}
.qsearch input::placeholder{color:var(--t3);}
.qsearch input:focus{outline:none;border-color:var(--m3);box-shadow:0 0 0 3px rgba(123,29,29,.10);}
/* The browser's own search-clear only appears in some engines and sits at a
   different inset in each, so the control is ours and always in one place. */
.qsearch input::-webkit-search-cancel-button{display:none;}
.qclear{position:absolute;right:.4rem;width:1.25rem;height:1.25rem;border:0;padding:0;
  border-radius:50%;background:var(--bdr);color:var(--t2);cursor:pointer;font-size:var(--fs-xs);
  display:flex;align-items:center;justify-content:center;transition:background .15s,color .15s;}
.qclear:hover{background:var(--m3);color:#fff;}
.qclear[hidden]{display:none;}
.qsel{height:2rem;padding:0 1.75rem 0 .6rem;border-radius:var(--r1);border:1.5px solid var(--bdr);
  background:var(--s1) url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%239C7A7A' stroke-width='1.6' fill='none' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right .55rem center;
  font-family:'DM Sans',sans-serif;font-size:var(--fs-base);font-weight:600;color:var(--t2);
  cursor:pointer;appearance:none;-webkit-appearance:none;transition:border-color .15s;}
.qsel:hover{border-color:var(--bdr2);}
.qsel:focus-visible{outline:2px solid var(--m3);outline-offset:1px;}
/* Reads as pressed rather than checked: it is a view the dispatcher turns on,
   and the amber matches the warning already on the Waiting column. */
.qchip{position:relative;display:block;cursor:pointer;}
.qchip input{position:absolute;opacity:0;width:0;height:0;}
.qchip span{display:flex;align-items:center;gap:.3rem;height:2rem;padding:0 .7rem;
  border-radius:var(--r1);border:1.5px solid var(--bdr);background:var(--s1);
  font-size:var(--fs-base);font-weight:700;color:var(--t2);white-space:nowrap;transition:all .15s;}
.qchip span i{font-size:var(--fs-sm);color:#C2410C;}
.qchip:hover span{border-color:var(--bdr2);}
.qchip input:focus-visible + span{outline:2px solid var(--m3);outline-offset:2px;}
.qchip input:checked + span{background:#FFF7ED;border-color:#EA580C;color:#C2410C;}
.qchip input:checked + span i{color:#C2410C;}
.qcount{margin-left:auto;font-size:var(--fs-sm);color:var(--t3);white-space:nowrap;font-weight:600;}
.qcount b{color:var(--t1);font-weight:800;}
/* Filtering to nothing is a result, not a blank panel. Both tables use it. */
.tbl tr.qhide{display:none;}
.qnone td{padding:0;}
/* The render-cap footnote is a note about the table, not a row in it — no hover
   lift, and a hairline above it so it reads as a rule rather than as data. */
.tbl tr.qcap td{padding:0;border-top:1px solid var(--b, #E5D9C6);background:#FBF8F1;}
.tbl tr.qcap:hover{background:#FBF8F1;}
.tbl tr.qcap .empty{font-size:.8rem;color:#6F564A;padding:.75rem 1rem;}

/* Panel header, right side: the count, and the one number worth interrupting
   for. Dispatched-and-not-accepted is the state the dispatcher can still act
   on, so it is the only thing here that gets a colour. */
.ph3-r{display:flex;align-items:center;gap:var(--sp-2);}
.ph3-n{font-size:var(--fs-base);color:var(--t3);white-space:nowrap;}
.ph3-warn{display:inline-flex;align-items:center;gap:.3rem;white-space:nowrap;
  padding:.18rem .55rem;border-radius:20px;font-size:var(--fs-sm);font-weight:800;
  background:#FFF7ED;border:1px solid #F6D9B8;color:#C2410C;}
.ph3-warn i{font-size:var(--fs-xs);}

/* The monitoring table has the full page width, so its columns are declared in
   a colgroup and stop being re-decided by whichever equipment name is longest.
   Fixed layout is what makes the colgroup binding rather than advisory. */
.asg-tbl{table-layout:fixed;}
.asg-tbl td .en,.asg-tbl td .esl{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
/* The queue row is the button that opens the dispatch drawer, so it has to look
   like one: a pointer, a hover, and a visible focus ring for keyboard use. */
tr.pick-row{cursor:pointer;}
tr.pick-row:hover>td{background:#FFF7ED;}
tr.pick-row:focus-visible{outline:2px solid var(--m3,#7a1220);outline-offset:-2px;}
tr.pick-row .rid-lnk{cursor:alias;}

/* -- TECHNICIAN PROFILE ---------------------------- */
/* The card's one control that is not the card. Sized to the same 22px box the
   availability pill sits against so the top row keeps its baseline. */
.tcd-info{width:22px;height:22px;flex-shrink:0;margin-left:var(--sp-1);padding:0;
  border:1px solid var(--bdr);border-radius:50%;background:var(--s1);color:var(--t3);
  font-size:var(--fs-xs);cursor:pointer;display:flex;align-items:center;justify-content:center;
  transition:background .15s,color .15s,border-color .15s;}
.tcd-info:hover{background:var(--m3);border-color:var(--m3);color:#fff;}
.tcd-info:focus-visible{outline:2px solid var(--m3);outline-offset:2px;}
.tcd-off .tcd-info{opacity:.75;}

.tprof-hd{display:flex;align-items:center;gap:.7rem;min-width:0;}
.tprof-av{width:42px;height:42px;border-radius:50%;flex-shrink:0;
  background:linear-gradient(135deg,var(--m3),var(--m2));color:#fff;
  font-family:'Outfit',sans-serif;font-weight:800;font-size:var(--fs-lg);
  display:flex;align-items:center;justify-content:center;}
#tprofMo .mhd-t h2{margin:0;}
#tprofMo .mhd-t p{margin:.1rem 0 0;}
.tprof-rows{display:grid;grid-template-columns:auto minmax(0,1fr);gap:.4rem .875rem;margin:0 0 var(--sp-4);}
.tprof-rows dt{font-size:var(--fs-sm);font-weight:700;color:var(--t3);white-space:nowrap;}
.tprof-rows dd{margin:0;font-size:var(--fs-base);color:var(--t1);font-weight:600;overflow-wrap:anywhere;}
.tprof-rows dd a{color:var(--m3);text-decoration:none;}
.tprof-rows dd a:hover{text-decoration:underline;}
.tprof-rows dd.none{color:var(--t3);font-weight:500;font-style:italic;}
.tprof-sec{border-top:1px solid var(--bdr);padding-top:.875rem;}
.tprof-sec h4{font-family:'Outfit',sans-serif;font-size:var(--fs-md);font-weight:800;color:var(--t1);
  margin:0 0 .6rem;display:flex;align-items:center;gap:.35rem;}
.tprof-sec h4 i{color:var(--m3);font-size:var(--fs-base);}
.tprof-n{margin-left:auto;font-size:var(--fs-sm);font-weight:800;color:var(--t2);
  background:var(--s3);border-radius:20px;padding:.1rem var(--sp-2);}
/* One line per report they are holding: the ticket, what it is, and where it
   has got to. Enough to answer "can they take another one" without leaving. */
.tprof-job{display:flex;align-items:center;gap:var(--sp-2);padding:var(--sp-2) .6rem;
  border:1px solid var(--bdr);border-radius:var(--r1);background:var(--s2);margin-bottom:.4rem;}
.tprof-job:last-child{margin-bottom:0;}
.tprof-job-id{font-family:'Outfit',sans-serif;font-weight:800;color:var(--m3);
  font-size:var(--fs-base);white-space:nowrap;}
.tprof-job-eq{flex:1;min-width:0;font-size:var(--fs-base);color:var(--t1);font-weight:600;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.tprof-job-when{font-size:var(--fs-sm);color:var(--t3);white-space:nowrap;}
.tprof-free{display:flex;align-items:center;gap:.45rem;font-size:var(--fs-base);color:#15803D;
  background:#F0FDF4;border:1px solid #BBE8CB;border-radius:var(--r1);padding:.6rem var(--sp-3);}

/* -- BADGES ---------------------------------------- */
.bdg{display:inline-flex;align-items:center;gap:.22rem;padding:.22rem .55rem;
  border-radius:20px;font-size:var(--fs-xs);font-weight:800;
  text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;}
.bdg::before{content:'';width:4px;height:4px;border-radius:50%;
  background:currentColor;flex-shrink:0;animation:dot 2.2s ease-in-out infinite;}
@keyframes dot{0%,100%{opacity:1;}50%{opacity:.4;}}
.b-pend{background:#FEF9E7;color:#92600A;}
.b-prog{background:#EFF6FF;color:#1D4ED8;}
.b-prog2{background:#F5F3FF;color:#7C3AED;}
.b-crit{background:#FFF7ED;color:#C2410C;}
.b-hi{background:#FFFBEB;color:#B45309;}
.b-med{background:#EFF6FF;color:#1D4ED8;}
.b-lo{background:#F0FDF4;color:#15803D;}
/* stCls() maps for_replacement to 'rej', and this rule was never written — so a
   For Replacement row rendered .bdg with no background and no colour, a pill
   shape holding dark body text while every other status was tinted. Rose, the
   same family .bi-d uses for the destructive action. */
.b-rej{background:#FFF1F2;color:#BE123C;}

/* -- TECHNICIAN CARDS ------------------------------ */
/* The chooser is a field of the assignment form, not a panel parked inside one.
   No border of its own — it already sits in the workspace panel — and no inner
   padding, so the cards start on the same left edge as the select above them
   and the unit cards below. Measured: every control in this form now shares one
   left edge and one width. */
.tech-pool{margin-top:.25rem;}
/* No .tech-grid layout rule here on purpose: `.dw .tech-grid` further down
   already owns it and is more specific, so a second one would look like it
   were in charge while doing nothing. Its padding is zeroed there. */
/* The workload bar reads as a caption to the label above it, so it sits tight
   to the label rather than floating between two borders. */
.wbal{padding:0 0 .55rem;}
/* These lived in a <style> block wedged between two form fields. Same rules,
   somewhere a stylesheet would actually be looked for. */
.tech-card{transition:box-shadow .15s,transform .12s,border-color .15s;}
.tech-card:hover{box-shadow:0 8px 20px rgba(123,29,29,.16);transform:none;border-color:#C9960C;}
.tech-card:active{transform:translateY(0);}
.wbal-seg{transition:opacity .15s;}
.wbal-seg:hover{opacity:.8;}
/* The card that does the choosing is .tcd / .tech-card, further up. An earlier
   .tcard — with its own avatar, name, dept line and workload bar — was replaced
   by it and left behind whole: ~40 rules that matched no element on the page,
   plus a wl-* workload bar fed by wlClass()/wlLabel()/wlColor(), none of which
   were called any more. Removed rather than kept "in case", because two card
   vocabularies in one file is how the next edit lands on the dead one. */
.tech-grid{display:flex;flex-direction:column;gap:.6rem;padding:.875rem;}

/* Dept badges */
.dept-itso{display:inline-flex;align-items:center;gap:.22rem;padding:.18rem .55rem;
  border-radius:20px;font-size:var(--fs-xs);font-weight:800;
  background:#ECFEFF;color:#0891B2;border:1px solid #A5F3FC;}
.dept-pmo{display:inline-flex;align-items:center;gap:.22rem;padding:.18rem .55rem;
  border-radius:20px;font-size:var(--fs-xs);font-weight:800;
  background:#F5F3FF;color:#7C3AED;border:1px solid #DDD6FE;}
.dept-gen{display:inline-flex;align-items:center;gap:.22rem;padding:.18rem .55rem;
  border-radius:20px;font-size:var(--fs-xs);font-weight:800;
  background:var(--s2);color:var(--t2);border:1px solid var(--bdr);}

/* -- ASSIGNMENT PANEL ------------------------------ */
/* -- DISPATCH DRAWER ------------------------------- */
/* The decision, on demand: the report you picked, the technicians who could
   take it, and the confirm — in the order you actually work. It used to be a
   permanent column beside the queue, which meant a 400px-wide form that was
   idle most of the time and a queue too narrow for its own columns. */
.dw{position:fixed;inset:0;z-index:900;display:flex;justify-content:flex-end;
  background:rgba(28,16,8,.42);opacity:0;visibility:hidden;
  transition:opacity .22s ease,visibility .22s;}
.dw.open{opacity:1;visibility:visible;}
/* 720, arrived at by measurement rather than taste. A card's top row spends 38px
   on the avatar, 73px on the availability pill, 22px on the info button and 29px
   on gaps before the name gets anything. Two cards to a row plus the drawer and
   panel padding means the name is left 25px at 580 and 75px at 680 — both less
   than "Michelle A. Dino" needs. At 720 it gets about 140px and reads. */
.dw-panel{width:min(720px,100%);height:100%;background:var(--bg);
  display:flex;flex-direction:column;box-shadow:-18px 0 50px rgba(28,16,8,.22);
  transform:translateX(100%);transition:transform .26s cubic-bezier(.4,0,.2,1);}
.dw.open .dw-panel{transform:translateX(0);}
.dw-hd{flex-shrink:0;display:flex;align-items:flex-start;gap:var(--sp-3);
  padding:1.1rem 1.25rem;background:linear-gradient(125deg,var(--m1),var(--m3));color:#fff;}
.dw-hd-t{flex:1;min-width:0;}
.dw-hd h2{font-family:'Outfit',sans-serif;font-size:var(--fs-xl);font-weight:800;margin:0;
  display:flex;align-items:center;gap:.4rem;}
.dw-hd h2 i{opacity:.85;font-size:var(--fs-lg);}
.dw-hd p{margin:.18rem 0 0;font-size:var(--fs-base);opacity:.82;line-height:1.45;}
.dw-x{width:32px;height:32px;flex-shrink:0;border:0;border-radius:9px;cursor:pointer;
  background:rgba(255,255,255,.14);color:#fff;font-size:var(--fs-md);
  display:flex;align-items:center;justify-content:center;transition:background .15s;}
.dw-x:hover{background:rgba(255,255,255,.26);}
.dw-x:focus-visible{outline:2px solid #fff;outline-offset:2px;}

/* The scroll container is the drawer body, so the header stays put while the
   form and the technician list move together as one column. */
.assign-col{flex:1;min-height:0;overflow-y:auto;overscroll-behavior:contain;
  display:flex;flex-direction:column;gap:var(--sp-4);padding:1.25rem;
  scrollbar-width:thin;scrollbar-color:var(--bdr2,#D0C0A8) transparent;}
.assign-col::-webkit-scrollbar{width:6px;}
.assign-col::-webkit-scrollbar-thumb{background:var(--bdr2,#D0C0A8);border-radius:3px;}
/* The panel header inside the drawer would repeat what the drawer header just
   said, so it goes; the drawer is the workspace now. */
.dw .ap-head{display:none;}
/* 580px is wide enough to compare technicians side by side, which a 400px
   column never was — the whole point of the list is choosing between them.
   minmax(0,1fr), not 1fr: a plain fr floors at the column's min-content, so the
   card holding the longest name came out 4px wider than the one beside it and
   the two columns never lined up. */
/* padding:0 undoes the .875rem the generic .tech-grid rule carries from when this list lived inside its own panel. Inside the form it is a field, and a field does not indent itself 15px from every other control. */ .dw .tech-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.6rem;padding:0;}
/* Cards sit on a shared baseline whatever their content does. */
.dw .tech-grid > *{min-width:0;}
/* A name is the one thing on this card that must not be traded away for layout.
   If a longer one than the current roster ever appears, it wraps to a second
   line rather than being ellipsised into initials — the grid row equalises the
   heights, so a taller card costs nothing but a few pixels. */
.dw .tcd-name{white-space:normal;overflow:visible;text-overflow:clip;line-height:1.25;}

/* Outside the scroll container, so the decision is on screen no matter how far
   down the technician list you have read. */
.dw-foot{flex-shrink:0;display:flex;align-items:center;gap:.6rem;
  padding:.875rem 1.25rem;background:var(--s1);border-top:1px solid var(--bdr);
  box-shadow:0 -4px 14px rgba(28,16,8,.06);}
.dw-foot .btn{padding:.6rem var(--sp-4);}
.dw-go{flex:1;justify-content:center;font-weight:800;}
@media(max-width:640px){
  .dw-panel{width:100%;}
  .dw .tech-grid{grid-template-columns:1fr;}
}
@media(prefers-reduced-motion:reduce){
  .dw,.dw-panel{transition:none;}
}
.assign-panel{background:var(--s1);border-radius:var(--r3);
  border:1.5px solid var(--bdr);box-shadow:var(--sh1);overflow:hidden;flex-shrink:0;}
.ap-head{padding:var(--sp-4) 1.25rem;background:linear-gradient(125deg,var(--m1),var(--m3));
  position:relative;overflow:hidden;}
.ap-head::after{content:'';position:absolute;right:-10px;top:-10px;
  width:90px;height:90px;border-radius:50%;
  background:rgba(212,160,23,.08);pointer-events:none;
  animation:sealSpin 18s linear infinite;}
.ap-head h3{font-family:'Outfit',sans-serif;font-size:var(--fs-xl);font-weight:800;
  color:#fff;position:relative;z-index:1;display:flex;align-items:center;gap:.4rem;}
.ap-head p{font-size:var(--fs-base);color:rgba(255,255,255,.42);
  position:relative;z-index:1;margin-top:.22rem;}
.ap-body{padding:1.1rem 1.25rem;}

/* Selected report preview */
.rep-prev{background:var(--s2);border:1.5px solid var(--bdr);
  border-radius:var(--r2);padding:var(--sp-3) .875rem;margin-bottom:.875rem;
  position:relative;transition:all .22s;}
.rep-prev.filled{border-color:rgba(123,29,29,.25);background:linear-gradient(135deg,#FFF8F5,#FFF5EE);}
.rep-prev-lbl{font-size:var(--fs-xs);font-weight:800;text-transform:uppercase;
  letter-spacing:.7px;color:var(--t3);margin-bottom:.45rem;}
.rep-id{font-family:'Outfit',sans-serif;font-weight:800;color:var(--m3);font-size:var(--fs-lg);}
.rep-eq{font-weight:700;font-size:var(--fs-lg);margin-top:.18rem;}
/* Was clamped to 2 lines against a 90-character stub. The panel now receives the
   whole description, because you cannot choose the right technician from half a
   sentence; it scrolls rather than truncating. */
.rep-desc{font-size:var(--fs-base);color:var(--t2);margin-top:.3rem;line-height:1.55;
  max-height:7.5em;overflow-y:auto;white-space:pre-line;}

/* where / when / who */
.rep-facts{display:flex;flex-wrap:wrap;gap:.3rem .7rem;margin-top:.3rem;}
.rep-fact{display:inline-flex;align-items:center;gap:.3rem;
  font-size:var(--fs-sm);color:var(--t3);}
.rep-fact i{font-size:var(--fs-xs);color:var(--m3);}

/* attached evidence */
.rep-media{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:var(--sp-2);}
.rep-thumb{width:54px;height:54px;padding:0;border-radius:var(--r1);
  border:1.5px solid var(--bdr);background:var(--s1);overflow:hidden;cursor:pointer;
  display:flex;align-items:center;justify-content:center;transition:border-color .16s;}
.rep-thumb:hover{border-color:var(--m3);}
.rep-thumb img{width:100%;height:100%;object-fit:cover;display:block;}
.rep-thumb-vid{background:var(--m1);color:var(--g3);font-size:var(--fs-lg);}
.rep-nomedia{margin-top:var(--sp-2);font-size:var(--fs-sm);color:var(--t4);
  display:flex;align-items:center;gap:.35rem;}

/* lightbox */
.media-lb{position:fixed;inset:0;z-index:900;background:rgba(0,0,0,.9);
  display:none;align-items:center;justify-content:center;padding:2rem;}
.media-lb.open{display:flex;}
.media-lb-body img,.media-lb-body video{max-width:88vw;max-height:86vh;
  border-radius:var(--r2);display:block;}
.media-lb-x{position:absolute;top:1.25rem;right:1.25rem;width:40px;height:40px;
  border-radius:50%;border:none;cursor:pointer;font-size:var(--fs-xl);
  background:rgba(255,255,255,.16);color:#fff;
  display:flex;align-items:center;justify-content:center;}
.media-lb-x:hover{background:rgba(255,255,255,.3);}
.rep-clear{position:absolute;top:.5rem;right:.5rem;
  width:20px;height:20px;background:none;border:none;
  color:var(--t3);cursor:pointer;font-size:var(--fs-base);
  display:flex;align-items:center;justify-content:center;
  border-radius:50%;transition:all .16s;}
.rep-clear:hover{background:var(--s3);color:var(--t1);}
.rep-placeholder{color:var(--t4);font-size:var(--fs-md);display:flex;
  align-items:center;gap:var(--sp-2);justify-content:center;padding:var(--sp-2) 0;}

/* Tech preview in form */
.tech-prev{background:var(--s2);border:1.5px solid var(--bdr);
  border-radius:var(--r2);padding:.7rem .875rem;margin-bottom:var(--sp-3);
  display:none;transition:all .22s;}
.tech-prev.show{display:flex;align-items:center;gap:.6rem;
  animation:fIn .18s ease;}
.tp-av{width:36px;height:36px;border-radius:50%;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-family:'Outfit',sans-serif;font-weight:800;font-size:var(--fs-md);color:#fff;
  background:linear-gradient(135deg,var(--m3),var(--m4));
  box-shadow:none;}
.tp-name{font-weight:700;font-size:var(--fs-md);color:var(--t1);}

/* Form elements */
.fg{display:flex;flex-direction:column;gap:.25rem;margin-bottom:.7rem;}
.fl{font-size:var(--fs-xs);font-weight:800;text-transform:uppercase;letter-spacing:.65px;color:var(--t2);}
.fl span{color:var(--m3);}
.fc{padding:var(--sp-2) .875rem;background:var(--s2);border:1.5px solid var(--bdr);
  border-radius:var(--r1);font-size:var(--fs-md);color:var(--t1);
  font-family:'DM Sans',sans-serif;outline:none;transition:all .18s;}
.fc:focus{border-color:var(--m3);background:var(--s1);
  box-shadow:0 0 0 3px rgba(123,29,29,.07);}
textarea.fc{resize:vertical;min-height:80px;}

/* The Active Assignments panel is a .tbl table (see .asg-active above). It was
   once a list of .act-row flex rows with their own workload bar; that markup is
   gone and its rules went with it. */

/* -- MODAL ----------------------------------------- */
/* Above the dispatch drawer (z-index 900). Both the confirmation and the
   technician profile are opened from inside it, so a modal that sat below the
   drawer would be invisible at the exact moment it is asked for. */
.mo{position:fixed;inset:0;background:rgba(26,8,8,.6);backdrop-filter:blur(7px);
  z-index:1000;display:none;align-items:flex-start;justify-content:center;
  padding:var(--sp-5) var(--sp-4);overflow-y:auto;}
.mo.open{display:flex;animation:moFade .18s ease;}
@keyframes moFade{from{opacity:0}to{opacity:1}}
.mw{background:var(--s1);border-radius:var(--r4);width:100%;max-width:540px;
  box-shadow:var(--sh3);animation:mUp .28s cubic-bezier(.4,0,.2,1);
  border:1px solid var(--bdr);margin:auto;}
@keyframes mUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.mhd{padding:1.25rem var(--sp-5) var(--sp-4);
  background:linear-gradient(120deg,var(--m1) 0%,#3D0A0A 45%,var(--m3) 100%);
  border-radius:var(--r4) var(--r4) 0 0;
  display:flex;justify-content:space-between;align-items:flex-start;
  position:relative;overflow:hidden;}
.mhd::after{content:'';position:absolute;right:-10px;top:-10px;
  width:100px;height:100px;border-radius:50%;
  background:rgba(212,160,23,.08);pointer-events:none;
  animation:sealSpin 18s linear infinite;}
.mhd-t{position:relative;z-index:1;}
.mhd-t h2{font-family:'Outfit',sans-serif;font-size:var(--fs-xl);font-weight:800;color:#fff;}
.mhd-t p{font-size:var(--fs-sm);color:rgba(255,255,255,.42);margin-top:.1rem;}
.mx{width:27px;height:27px;background:rgba(255,255,255,.1);border:none;border-radius:50%;
  color:rgba(255,255,255,.6);font-size:var(--fs-md);cursor:pointer;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
  transition:all .18s;position:relative;z-index:1;}
.mx:hover{background:rgba(255,255,255,.22);color:#fff;transform:rotate(90deg);}
/* Dispatch summary: label/value rows, so what is about to happen reads in one
   pass rather than as a sentence to parse. */
.asg-sum{display:grid;grid-template-columns:7rem minmax(0,1fr);gap:.1rem .875rem;margin:0;}
.asg-sum dt{font-size:var(--fs-sm);font-weight:700;color:var(--t3);padding:.4rem 0;
  border-bottom:1px solid var(--bdr);}
.asg-sum dd{margin:0;font-size:var(--fs-md);font-weight:600;color:var(--t1);padding:.4rem 0;
  border-bottom:1px solid var(--bdr);overflow-wrap:anywhere;}
.asg-sum dt:last-of-type,.asg-sum dd:last-of-type{border-bottom:none;}
.mb{padding:1.375rem var(--sp-5);}
.mf{padding:.8rem var(--sp-5) 1.25rem;border-top:1px solid var(--bdr);
  display:flex;justify-content:flex-end;gap:.45rem;
  background:var(--s2);border-radius:0 0 var(--r4) var(--r4);}

/* -- UNASSIGN CONFIRMATION ------------------------- */
.conf-panel{background:#FFF1F2;border:1.5px solid #FECDD3;border-radius:var(--r2);
  padding:.875rem var(--sp-4);display:flex;gap:var(--sp-3);align-items:flex-start;margin-top:var(--sp-2);}
.conf-icon{width:34px;height:34px;background:#FEE2E2;color:#DC2626;
  border-radius:50%;display:flex;align-items:center;justify-content:center;
  flex-shrink:0;font-size:var(--fs-lg);}

/* -- TOAST ----------------------------------------- */
.ttray{position:fixed;top:1.25rem;left:50%;transform:translateX(-50%);align-items:center;display:flex;
  flex-direction:column;gap:.35rem;z-index:9999;}
.tst{background:var(--s1);border:1px solid var(--bdr);border-radius:var(--r2);
  padding:.7rem .875rem;display:flex;align-items:flex-start;gap:var(--sp-2);
  box-shadow:var(--sh3);min-width:240px;
  animation:tIn .22s cubic-bezier(.4,0,.2,1);border-left:3px solid var(--m3);}
.tst.ok{border-left-color:#16A34A;}.tst.err{border-left-color:#DC2626;}
@keyframes tIn{from{transform:translateX(60px);opacity:0}to{transform:translateX(0);opacity:1}}
.tst-t{font-size:var(--fs-base);font-weight:700;color:var(--t1);}
.tst-m{font-size:var(--fs-sm);color:var(--t2);margin-top:1px;}

/* -- EMPTY ----------------------------------------- */
.empty{text-align:center;padding:2.5rem var(--sp-5);color:var(--t3);}
.empty i{font-size:2.2rem;display:block;margin-bottom:.6rem;opacity:.22;}

/* -- RESPONSIVE ------------------------------------ */
/* The 1366 stacking rule that used to live here is gone with the column it
   stacked. The queue now has the full page at every width, which is what it
   needed: six declared columns want about 570px and used to get 520 on a 1280
   laptop, so the Assign button fell outside the panel. The drawer carries its
   own width rules up beside the rest of its styles. */
@media(max-width:768px){.sb{transform:translateX(-100%);}.sb.open{transform:translateX(0);}
  .wrap{margin-left:0;}.pg{padding:var(--sp-4);}.mob-tog{display:flex;}
  .sums{grid-template-columns:1fr 1fr;}}

/* entrance animations */
.scard{animation:scIn .3s ease both;}
.scard:nth-child(1){animation-delay:.05s;}.scard:nth-child(2){animation-delay:.1s;}
.scard:nth-child(3){animation-delay:.15s;}.scard:nth-child(4){animation-delay:.2s;}
@keyframes scIn{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}

/* Nothing on this page animates in on a timer except the four summary cards.
   The stagger above is four fixed delays, so it cannot outlast them; a card
   list that grew would need a different mechanism, which is part of why the
   technician cards do not have one. */
@media(prefers-reduced-motion:reduce){
  .scard{animation:none;}
  .pip,.bdg::before{animation:none;}
}
</style>
</head>
<body>

<!-- =========== SIDEBAR =============================== -->
<?php $activeNav = 'assign'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

<!-- =========== MAIN ================================== -->
<div class="wrap">
  <header class="topbar">
    <div class="tb-l">
      <button class="mob-tog" onclick="document.getElementById('sb').classList.toggle('open')">
        <i class="fas fa-bars"></i>
      </button>
      <div>
        <div class="pg-title">Assign Technicians</div>
        <div class="bc">
          <a href="admin_dashboard.php"><i class="fas fa-home"></i></a>
          <i class="fas fa-chevron-right"></i>
          <a href="admin_defect_reports.php">Defect Reports</a>
          <i class="fas fa-chevron-right"></i>
          <span>Assign Technicians</span>
        </div>
      </div>
    </div>
    <div class="tb-r">
      <a href="admin_notifications.php" class="ic-btn"><i class="fas fa-bell"></i><span class="pip"></span></a>
      <button class="btn btn-maroon btn-sm" onclick="location.reload()">
        <i class="fas fa-sync-alt"></i> Refresh
      </button>
    </div>
  </header>

  <div class="pg">

    <!-- Flash -->
    <?php if(isset($_SESSION['flash'])): [$ft,$fm]=$_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="flash <?php echo $ft;?>">
      <i class="fas fa-<?php echo $ft==='ok'?'check-circle':'exclamation-circle';?>"></i>
      <?php echo esc($fm); ?>
    </div>
    <?php endif; ?>

    <!-- Page header -->
    <div class="ph">
      <div>
        <h1><i class="fas fa-user-cog"></i> Assign Technicians</h1>
        <p class="ph-sub">Select an unassigned report, pick a technician, set priority and instructions - then confirm.</p>
      </div>
      <div class="ph-acts">
        <a href="admin_defect_reports.php" class="btn btn-ghost btn-sm">
          <i class="fas fa-arrow-left"></i> Back to Reports
        </a>
      </div>
    </div>

    <!-- Summary Strip -->
    <div class="sums">
      <div class="scard sc-a">
        <div class="sico"><i class="fas fa-clipboard-list"></i></div>
        <div class="snum" id="sn0"><?php echo $totalUnassigned; ?></div>
        <div class="slbl">Unassigned Reports</div>
        <div class="smicro"><i class="fas fa-arrow-right"></i> Awaiting assignment</div>
      </div>
      <div class="scard sc-b">
        <div class="sico"><i class="fas fa-user-check"></i></div>
        <div class="snum" id="sn1"><?php echo $availTechs; ?></div>
        <div class="slbl">Available Technicians</div>
        <div class="smicro"><i class="fas fa-circle" style="color:#16A34A;font-size:var(--fs-xs);"></i> Light workload</div>
      </div>
      <div class="scard sc-c">
        <div class="sico"><i class="fas fa-triangle-exclamation"></i></div>
        <div class="snum" id="sn2"><?php echo $overloadedTechs; ?></div>
        <div class="slbl">Overloaded Technicians</div>
        <div class="smicro"><i class="fas fa-circle" style="color:#DC2626;font-size:var(--fs-xs);"></i> 4+ active tasks</div>
      </div>
      <div class="scard sc-d">
        <div class="sico"><i class="fas fa-wrench"></i></div>
        <div class="snum" id="sn3"><?php echo $totalInProgress; ?></div>
        <div class="slbl">In Progress</div>
        <div class="smicro"><i class="fas fa-circle" style="color:#7C3AED;font-size:var(--fs-xs);"></i> Active repairs</div>
      </div>
    </div>

    <!-- Main two-column layout -->
    <div class="main-grid">

      <!-- LEFT: Unassigned reports + Active assignments -->
      <div style="display:flex;flex-direction:column;gap:1.25rem;">

        <!-- Unassigned Reports Panel -->
        <div class="panel">
          <div class="ph3">
            <h3><i class="fas fa-clipboard-list"></i> Unassigned Reports
              <?php if($totalUnassigned>0):?>
              <span style="background:var(--m3);color:#fff;font-size:var(--fs-xs);padding:1px 7px;border-radius:20px;font-weight:900;margin-left:.25rem;"><?php echo $totalUnassigned;?></span>
              <?php endif;?>
            </h3>
            <a href="admin_defect_reports.php?status=ready_for_assignment" class="btn btn-ghost btn-sm">
              <i class="fas fa-external-link-alt"></i> View All
            </a>
          </div>

          <?php /* Only worth showing when there is a queue to work through. One
                   report needs no search, and an empty panel needs no filters. */ ?>
          <?php if (!empty($unassigned)): ?>
          <div class="qbar">
            <div class="qsearch">
              <i class="fas fa-search"></i>
              <label for="qSearch" class="sr-only">Search the queue</label>
              <input type="search" id="qSearch" autocomplete="off"
                     placeholder="Search ticket, equipment, location or reporter">
              <button type="button" class="qclear" id="qClear" hidden aria-label="Clear search">
                <i class="fas fa-times"></i>
              </button>
            </div>
            <label for="qPrio" class="sr-only">Filter by priority</label>
            <select class="qsel" id="qPrio">
              <option value="">All priorities</option>
              <option value="critical">Critical</option>
              <option value="high">High</option>
              <option value="medium">Medium</option>
              <option value="low">Low</option>
            </select>
            <label for="qSort" class="sr-only">Sort the queue</label>
            <select class="qsel" id="qSort">
              <option value="wait">Longest waiting</option>
              <option value="prio">Highest priority</option>
              <option value="new">Newest first</option>
            </select>
            <label class="qchip" title="Only reports unassigned for two days or more">
              <input type="checkbox" id="qStale">
              <span><i class="fas fa-triangle-exclamation"></i> Overdue only</span>
            </label>
            <span class="qcount" id="qCount"></span>
          </div>
          <?php endif; ?>

          <div class="tblwrap"><table class="tbl asg-tbl" id="unTbl">
            <?php /* Declared tracks rather than content-measured ones. The queue
                     shares its row with a 400px workspace, and letting the
                     browser size six columns from the longest location string
                     pushed the Assign button past the panel edge — the
                     horizontal scrollbar was the symptom, not the fix. */ ?>
            <colgroup>
              <col style="width:8.6rem"><col><col style="width:9.5rem">
              <col style="width:5.2rem"><col style="width:4.4rem"><col style="width:5.8rem">
            </colgroup>
            <thead>
              <tr>
                <?php /* Location replaces the Issue stub. The stub was the first 50
                         characters and an ellipsis — never enough to judge a fault by,
                         and the full text is in the panel the moment a report is
                         selected. Where the equipment is decides who can go, so it
                         earns the column more than half a sentence does. */ ?>
                <th>Report</th><th>Equipment</th><th>Location</th>
                <th>Priority</th><th>Waiting</th><th style="text-align:center;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if(empty($unassigned)): ?>
              <tr><td colspan="6"><div class="empty">
                <i class="fas fa-check-circle" style="color:#16A34A;"></i>
                All reports are assigned - great work!
              </div></td></tr>
              <?php else: foreach($unassignedShown as $r):
                /* Everything the toolbar sorts or filters on is written onto the
                   row, so the script never has to parse rendered cells back into
                   values — badge text and a formatted age are for reading, not
                   for comparing. Age is computed once here and reused below. */
                $rowAge  = !empty($r['report_date']) ? (time() - strtotime((string)$r['report_date'])) : null;
                $rowRank = ['critical'=>4,'high'=>3,'medium'=>2,'low'=>1][$r['priority'] ?? ''] ?? 0;
                $rowHay  = strtolower(trim(preg_replace('~\s+~', ' ', implode(' ', [
                    $r['report_id']    ?? '', $r['equipment_name'] ?? '',
                    $r['asset_tag']    ?? '', $r['location']       ?? '',
                    $r['reporter_name']?? '', $r['issue_description'] ?? '',
                ]))));
              ?>
              <?php
                /* The whole row is the target, not just the Assign button in the
                   last cell. Clicking the equipment — the natural thing to click
                   when you have decided this is the one — used to do nothing at
                   all, on a page whose entire job is picking a report. The
                   payload lives on the row so the row and the button dispatch
                   the identical object instead of two copies of it. */
                $rowRep = htmlspecialchars(json_encode([
                    'id'         => $r['report_id'],
                    'equipment'  => $r['equipment_name']??'N/A',
                    'asset'      => $r['asset_tag']??'',
                    // Full text, not the 90-character stub the table shows. Whoever
                    // is dispatching needs to read the fault before choosing who
                    // to send, and the panel has room for it.
                    'issue'      => $r['issue_description']??'',
                    'location'   => $r['location']??'',
                    'reported'   => !empty($r['report_date']) ? date('M j, Y', strtotime($r['report_date'])) : '',
                    'reporter'   => $r['reporter_name']??'',
                    'priority'   => $r['priority']??'medium',
                    'dept'       => $r['department_assigned']??'',
                    'photos'     => photoListFromRow($r),
                    'videos'     => videoListFromRow($r),
                ]), ENT_QUOTES);
              ?>
              <tr id="row-<?php echo esc($r['report_id']); ?>"
                  class="pick-row" role="button" tabindex="0"
                  aria-label="Dispatch report <?php echo esc($r['report_id']); ?> — <?php echo esc($r['equipment_name'] ?? 'equipment'); ?>"
                  data-rep="<?php echo $rowRep; ?>"
                  data-hay="<?php echo esc($rowHay); ?>"
                  data-prio="<?php echo esc(strtolower((string)($r['priority'] ?? ''))); ?>"
                  data-rank="<?php echo $rowRank; ?>"
                  data-age="<?php echo $rowAge === null ? -1 : (int)$rowAge; ?>"
                  data-stale="<?php echo ($rowAge !== null && $rowAge >= 172800) ? '1' : '0'; ?>">
                <td><a class="rid rid-lnk" title="Open the full record in a new tab"
                       target="_blank" rel="noopener"
                       href="admin_defect_reports.php?view_id=<?php echo urlencode($r['report_id']); ?>"><?php echo esc($r['report_id']); ?></a></td>
                <?php /* The reported fault in full, on hover. The column itself
                         stays as it is — a 50-character stub was tried and
                         removed for good reason (see the header note) — but the
                         text should not require a click to reach either. */ ?>
                <td title="<?php echo esc(trim(preg_replace('~\s+~', ' ', (string)($r['issue_description'] ?? ''))) ?: 'No description given.'); ?>">
                  <div class="en"><?php echo esc($r['equipment_name']??'N/A'); ?></div>
                  <?php if(!empty($r['asset_tag'])): ?><div class="esl"><?php echo esc($r['asset_tag']); ?></div><?php endif; ?>
                </td>
                <?php
                  /* The head of the location is the campus/building — the part that
                     decides who can walk there. The rest stays on the title. */
                  $uLoc  = trim((string)($r['location'] ?? ''));
                  $uHead = trim(explode('•', $uLoc)[0]);
                  /* How long this has been sitting unassigned. A queue is judged by
                     its oldest item, and a date alone makes you do the arithmetic. */
                  $uAge  = $rowAge;   /* already computed for the row attributes */
                  $uAgeT = $uAge === null ? '—'
                         : ($uAge < 3600   ? max(1, (int)floor($uAge / 60)) . 'm'
                         : ($uAge < 86400  ? (int)floor($uAge / 3600) . 'h'
                         :                   (int)floor($uAge / 86400) . 'd'));
                  $uStale = $uAge !== null && $uAge >= 172800;   // two days unassigned
                ?>
                <td title="<?php echo esc($uLoc); ?>">
                  <div class="en" style="font-weight:600;font-size:var(--fs-base);"><?php echo esc($uHead ?: '—'); ?></div>
                  <?php if ($uHead !== '' && $uHead !== $uLoc): ?>
                  <div class="esl"><?php echo esc(trim(substr($uLoc, strlen($uHead) + 1), " •")); ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="bdg b-<?php echo prCls($r['priority']); ?>"><?php echo prLbl($r['priority']); ?></span></td>
                <td class="nw" style="font-size:var(--fs-base);<?php echo $uStale ? 'color:#C2410C;font-weight:800;' : 'color:var(--t3);'; ?>"
                    title="Reported <?php echo esc(date('M j, Y · g:i A', strtotime((string)$r['report_date']))); ?>">
                  <?php echo $uAgeT; ?><?php echo $uStale ? ' <i class="fas fa-triangle-exclamation" style="font-size:var(--fs-xs);"></i>' : ''; ?>
                </td>
                <td style="text-align:center;">
                  <?php /* Same payload as the row — read back off it rather than
                           written twice, so the two can never drift apart. */ ?>
                  <button class="btn btn-maroon btn-sm" onclick="pickRow(this.closest('tr'))">
                    <i class="fas fa-user-plus"></i> Assign
                  </button>
                </td>
              </tr>
              <?php endforeach; endif; ?>
              <?php if (!empty($unassigned)): ?>
              <?php /* Filtering down to nothing is a result and should say so.
                       Left in the markup and toggled, so the row is never built
                       from a string at the moment it is needed. */ ?>
              <tr class="qnone" id="qNone" hidden><td colspan="6"><div class="empty">
                <i class="fas fa-magnifying-glass-minus"></i>
                No reports match these filters.<br>
                <button type="button" class="btn btn-ghost btn-sm" style="margin-top:.7rem;"
                        onclick="qReset()"><i class="fas fa-rotate-left"></i> Clear filters</button>
              </div></td></tr>
              <?php if ($unassignedTotal > count($unassignedShown)): ?>
              <?php /* Says the count AND the consequence: the toolbar filters rows that
                       are on the page, so a search here is a search of these 250, not of
                       the whole queue. Without that sentence an empty result reads as
                       "no such report" when it means "not in the first 250". */ ?>
              <tr class="qcap"><td colspan="6"><div class="empty" style="text-align:left;">
                <i class="fas fa-layer-group" style="color:#C9960C;"></i>
                Showing the <?php echo number_format(count($unassignedShown)); ?> oldest of
                <strong><?php echo number_format($unassignedTotal); ?></strong> unassigned reports.
                Search and filters above apply to these <?php echo number_format(count($unassignedShown)); ?>.
                Assign from the front of the queue and the rest move up.
              </div></td></tr>
              <?php endif; ?>
              <?php endif; ?>
            </tbody>
          </table></div>
        </div>



      </div><!-- /left col -->
    </div><!-- /main-grid -->

    <?php /* ── DISPATCH DRAWER ───────────────────────────────────────────────
             The workspace was a permanent 400px column beside the queue: 812px
             tall, idle until a report was picked, holding a 770px form and a
             935px vertical list of technicians you scrolled rather than
             compared. It also cost the queue the width its own columns needed.

             It opens on demand now, so the queue and the monitoring table get
             the full page and the decision gets a surface wide enough to show
             technicians two at a time. Nothing inside moved: the form, its
             fields, its ids and its POST are the same markup, one container
             deeper. The drawer is position:fixed, so where it sits in the
             source does not affect the layout above it. */ ?>
    <div class="dw" id="asgDw" onclick="if(event.target===this)closeDispatch()">
      <aside class="dw-panel" role="dialog" aria-modal="true" aria-labelledby="dwTitle">
        <div class="dw-hd">
          <div class="dw-hd-t">
            <h2 id="dwTitle"><i class="fas fa-user-plus"></i> Dispatch a repair</h2>
            <p>Choose who takes it, set how urgent it is, then send.</p>
          </div>
          <button type="button" class="dw-x" onclick="closeDispatch()" aria-label="Close">
            <i class="fas fa-times"></i>
          </button>
        </div>
      <div class="assign-col">
      <div class="assign-panel">
        <div class="ap-head">
          <h3><i class="fas fa-user-plus"></i> Assignment Workspace</h3>
          <p>Pick a report, choose who takes it, set how urgent it is — then dispatch.</p>
        </div>

        <!-- Where you are, in four words. Driven by what has actually been
             chosen, not by a wizard that makes you click Next. -->
        <div class="asg-steps" id="asgSteps" aria-label="Assignment progress">
          <div class="asg-step now" data-step="1"><span>1</span>Report</div>
          <div class="asg-step"     data-step="2"><span>2</span>Technician</div>
          <div class="asg-step"     data-step="3"><span>3</span>Details</div>
          <div class="asg-step"     data-step="4"><span>4</span>Dispatch</div>
        </div>

        <div class="ap-body">


          <!-- Selected report preview -->
          <div class="rep-prev" id="repPrev">
            <div class="rep-prev-lbl"><i class="fas fa-file-alt"></i> Selected Report</div>
            <div id="repEmpty" class="rep-placeholder">
              <i class="fas fa-mouse-pointer"></i> Click "Assign" on any report
            </div>
            <div id="repFilled" style="display:none;">
              <button class="rep-clear" onclick="clearReport()" title="Clear selection">
                <i class="fas fa-times"></i>
              </button>
              <?php /* The preview carries what the dispatch decision needs. The
                       whole record — timeline, activity log, PMO notes, every
                       photo — lives on the Defect Reports page, so this links
                       there rather than duplicating it. New tab, because losing
                       a half-filled assignment to a navigation is worse than a
                       second tab. */ ?>
              <div class="rep-idrow">
                <div class="rep-id" id="rpId">-</div>
                <a class="rep-full" id="rpFull" href="#" target="_blank" rel="noopener"
                   title="Open the full record in a new tab">
                  <i class="fas fa-arrow-up-right-from-square"></i> Full record
                </a>
              </div>
              <div class="rep-eq" id="rpEq">-</div>
              <div class="rep-facts" id="rpFacts"></div>
              <div class="rep-desc" id="rpDesc">-</div>
              <!-- What the reporter actually photographed. Choosing a technician
                   from a text description alone is guesswork. -->
              <div class="rep-media" id="rpMedia"></div>
              <div style="margin-top:.45rem;display:flex;gap:.35rem;flex-wrap:wrap;" id="rpMeta"></div>
            </div>
          </div>

          <!-- Assignment Form -->
          <form id="assignForm" method="POST" action="admin_assign_technicians.php" onsubmit="return validateAssign()">
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="report_id" id="fRid" value="">

            <!-- Technician select -->
            <div class="fg">
              <label class="fl">Technician <span>*</span></label>
              <select name="technician_id" id="fTech" class="fc" required
                onchange="techChanged(this)" <?php if(empty($technicians))echo 'disabled';?>>
                <option value="">Choose technician...</option>
                <?php foreach($technicians as $t):
                  $tid = $t['tid'];
                  $dept = $t['dept']??'';
                  $wl = (int)$t['workload'];
                  [$aLbl] = availMeta($t['avail']);
                  $disabled = $t['avail'] === 'unavailable';
                  $initials = strtoupper(implode('',array_map(fn($p)=>substr($p,0,1),array_slice(explode(' ',$t['fullname']??'??'),0,2))));
                ?>
                <option value="<?php echo esc($tid); ?>"
                  data-dept="<?php echo esc($dept); ?>"
                  data-wl="<?php echo $wl; ?>"
                  data-initials="<?php echo $initials; ?>"
                  data-name="<?php echo esc($t['fullname']??'Technician'); ?>"
                  data-spec="<?php echo esc($t['spec']); ?>"
                  data-avail="<?php echo esc($aLbl); ?>"
                  data-deptcls="<?php echo deptClass($dept); ?>"
                  <?php echo $disabled ? 'disabled' : ''; ?>>
                  <?php echo esc($t['fullname']??'Technician'); ?>
                  — <?php echo $aLbl; ?> (<?php echo $wl; ?> task<?php echo $wl!=1?'s':'';?>)<?php if($t['spec']!=='General') echo ' · '.esc($t['spec']); ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <?php /* Who is free, and what each of them is already carrying.

                     This was a full .panel with its own gradient header sitting
                     between two form fields — a card inside a form, announcing
                     itself with a heading that competed with the labels around
                     it, and indenting its contents 11px off the edge every other
                     control sits on. It is a field of this form, so it is
                     dressed as one: the same .fl label, the same left edge, no
                     second border around something already inside a panel. */ ?>
            <div class="fg tech-pool">
              <label class="fl" id="techPoolLbl">Who is available</label>
          <?php
            $teamTotal = (int)array_sum(array_column($technicians,'workload'));
            $avgLoad = $totalTechs > 0 ? round($teamTotal / $totalTechs, 1) : 0;
          ?>
          <?php if(!empty($technicians)): ?>
          <div class="wbal">
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:var(--fs-xs);color:#8a6d5a;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.4rem;">
              <span><i class="fas fa-scale-balanced"></i> Team Workload Balance</span>
              <span><?php echo $teamTotal; ?> active · avg <?php echo $avgLoad; ?>/tech</span>
            </div>
            <div style="display:flex;height:16px;border-radius:8px;overflow:hidden;background:#f0e7d8;gap:1px;">
              <?php if($teamTotal > 0): foreach($technicians as $t): $wlb=(int)$t['workload']; if($wlb<=0) continue;
                $share = ($wlb / $teamTotal) * 100;
                $col = ($t['avail']==='overloaded') ? '#DC2626' : (($t['avail']==='unavailable') ? '#9ca3af' : '#16A34A');
              ?>
                <div class="wbal-seg" title="<?php echo esc($t['fullname']).' — '.$wlb.' active task(s)'; ?>" style="width:<?php echo $share; ?>%;background:<?php echo $col; ?>;min-width:3px;"></div>
              <?php endforeach; else: ?>
                <div style="width:100%;display:flex;align-items:center;justify-content:center;font-size:var(--fs-xs);color:#8a6d5a;">No active tasks assigned yet — everyone is free</div>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
          <div class="tech-grid">
            <?php if(empty($technicians)): ?>
            <div class="empty"><i class="fas fa-users-slash"></i>No technicians registered.</div>
            <?php else:
              $maxWl = max(1, max(array_column($technicians,'workload')));
              foreach($technicians as $t):
                $wl = (int)$t['workload']; $tid = $t['tid'];
                $pct = min(100, ($wl / max(1,$maxWl)) * 100);
                $initials = strtoupper(implode('',array_map(fn($p)=>substr($p,0,1),array_slice(explode(' ',$t['fullname']??'??'),0,2))));
                [$aLbl,$aColor,$aIcon,$aBg] = availMeta($t['avail']);
                $disabled = $t['avail'] === 'unavailable';
            ?>
            <div class="<?php echo $disabled ? 'tcd tcd-off' : 'tech-card tcd'; ?>"
                 <?php echo $disabled ? '' : 'onclick="assignFromCard(this)" tabindex="0" role="button" onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();assignFromCard(this);}"'; ?>
                 data-tid="<?php echo esc($tid); ?>" data-name="<?php echo esc($t['fullname'] ?? 'Technician'); ?>"
                 data-load="<?php echo (int)$wl; ?>"
                 aria-label="Select <?php echo esc($t['fullname'] ?? 'technician'); ?><?php echo $disabled ? ' (unavailable)' : ''; ?>"
>

              <span class="tcd-check" aria-hidden="true"><i class="fas fa-check"></i></span>

              <div class="tcd-top">
                <div class="tcd-av"><?php echo esc($initials); ?></div>
                <div class="tcd-id">
                  <div class="tcd-name"><?php echo esc($t['fullname'] ?? 'Technician'); ?></div>
                  <div class="tcd-meta">
                    <span class="tcd-spec"><i class="fas fa-screwdriver-wrench" aria-hidden="true"></i> <?php echo esc($t['spec']); ?></span>
                    <?php /* $t['dept'] falls back to specialization when the account
                             has no department, which printed "General General". Only
                             show it when it says something the line before did not. */
                          if (!empty($t['dept']) && strcasecmp(trim((string)$t['dept']), trim((string)$t['spec'])) !== 0): ?>
                    <span class="tcd-dept"><?php echo esc($t['dept']); ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <span class="tcd-avail" style="color:<?php echo $aColor; ?>;background:<?php echo $aBg; ?>;">
                  <span class="tcd-dot" style="background:<?php echo $aColor; ?>;"></span><?php echo esc($aLbl); ?>
                </span>
                <?php /* The card as a whole assigns; this one control does not.
                         stopPropagation keeps a look at someone's details from
                         dispatching a repair to them, which is the kind of
                         mis-click that sends a real email to a real person. */ ?>
                <button type="button" class="tcd-info" title="View profile"
                        aria-label="View <?php echo esc($t['fullname'] ?? 'technician'); ?>'s profile"
                        onclick="event.stopPropagation();showTechProfile('<?php echo esc($tid); ?>');">
                  <i class="fas fa-circle-info" aria-hidden="true"></i>
                </button>
              </div>

              <?php /* Workload is stated as a real count and drawn relative to the
                       busiest technician on the team. There is no capacity column in
                       the schema, so a "3 / 5" bar would be inventing the 5 — this
                       compares technicians against each other, which is the question
                       actually being asked: who is freest right now. */ ?>
              <div class="tcd-load">
                <div class="tcd-load-l">
                  <span>Workload</span>
                  <span class="tcd-load-n"><?php echo $wl; ?> active <?php echo $wl === 1 ? 'task' : 'tasks'; ?></span>
                </div>
                <div class="tcd-bar" role="img"
                     aria-label="<?php echo $wl; ?> active tasks, busiest on the team has <?php echo (int)$maxWl; ?>">
                  <span style="width:<?php echo $wl > 0 ? max(6, (int)$pct) : 0; ?>%;background:<?php echo $aColor; ?>;"></span>
                </div>
              </div>

              <?php if (!$disabled): ?>
              <div class="tcd-cta"><i class="fas fa-hand-pointer" aria-hidden="true"></i> Select technician</div>
              <?php else: ?>
              <div class="tcd-cta tcd-cta-off"><i class="fas fa-ban" aria-hidden="true"></i> Account inactive</div>
              <?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
            <!-- Selected tech preview -->
            <div class="tech-prev" id="techPrev">
              <div class="tp-av" id="tpAv">??</div>
              <div>
                <div class="tp-name" id="tpName">-</div>
                <div style="display:flex;gap:.3rem;margin-top:.22rem;flex-wrap:wrap;" id="tpMeta"></div>
              </div>
            </div>

            <!-- Responsible unit. Two options behind a dropdown you had to open to
                 discover it held two. As cards they state what each unit covers,
                 which is the part an administrator is actually deciding. -->
            <div class="fg">
              <label class="fl">Responsible Unit <span>*</span></label>
              <div class="unit-seg" role="radiogroup" aria-label="Responsible unit">
                <?php foreach ([
                  ['PMO',  'fa-building',     'PMO',  'Physical maintenance &amp; facilities'],
                  ['ITSO', 'fa-laptop-code',  'ITSO', 'IT equipment &amp; computer labs'],
                ] as [$uv, $uic, $ut, $ud]): ?>
                <label class="unit-opt unit-<?php echo strtolower($uv); ?>">
                  <input type="radio" name="department" value="<?php echo $uv; ?>" required>
                  <span class="unit-box">
                    <span class="unit-check" aria-hidden="true"><i class="fas fa-check"></i></span>
                    <i class="fas <?php echo $uic; ?> unit-ic" aria-hidden="true"></i>
                    <span class="unit-t"><?php echo $ut; ?></span>
                    <span class="unit-d"><?php echo $ud; ?></span>
                  </span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Priority: four fixed choices, so it is four buttons rather than a
                 dropdown you have to open to discover what is in it. Matches the
                 segmented control the report record already uses for the same
                 field, so the two screens ask the question the same way.
                 (The old dropdown also read "Critical Critical".) -->
            <div class="fg">
              <label class="fl">Priority Level <span>*</span></label>
              <div class="prio-seg" role="radiogroup" aria-label="Priority level">
                <?php foreach ([['low','Low'],['medium','Medium'],['high','High'],['critical','Critical']] as [$pv,$pl]): ?>
                <label class="ps-opt ps-<?php echo $pv; ?>">
                  <input type="radio" name="priority" value="<?php echo $pv; ?>" required>
                  <span><?php echo $pl; ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Instructions -->
            <div class="fg">
              <label class="fl">Handler Instructions</label>
              <textarea name="instructions" id="fInstr" class="fc"
                placeholder="Provide specific instructions for the technician (tools needed, safety notes, access info)..."></textarea>
            </div>

            <?php /* Confirm and Clear used to sit here, at the end of the form
                     and therefore above the technician cards that follow it —
                     in a scrolling drawer that meant meeting the primary action
                     before the chooser step 2 asks for. They are in the drawer's
                     sticky footer now, tied back to this form by the form=
                     attribute, so the decision is always in reach and never
                     arrives before the choice. */ ?>
          </form>

        </div><!-- /ap-body -->
      </div><!-- /assign-panel -->
    </div><!-- /assign-col -->
        <div class="dw-foot">
          <button type="button" class="btn btn-ghost" onclick="clearAll()">
            <i class="fas fa-times"></i> Clear Form
          </button>
          <button type="submit" form="assignForm" class="btn btn-green dw-go">
            <i class="fas fa-paper-plane"></i> Confirm Assignment
          </button>
        </div>
      </aside>
    </div><!-- /dw -->

    <!-- Active assignments are monitoring, not dispatch. They sat inside the
         LEFT column between the queue and nothing, so the queue lost the width
         that pushed its Assign button off the edge, and seven columns of
         already-assigned work sat in the middle of the job of assigning. Below
         the dispatch area, full width, where they have room and are out of the
         way of the decision above them. -->
        <!-- Active Assignments Panel -->
        <?php
          /* Who currently holds work, for the filter. Built from the rows on the
             page rather than from the technician list: a report can be held by
             someone no longer offered for assignment, and a filter that cannot
             select a row that is visible is worse than no filter. */
          $actHolders = [];
          foreach ($inprogress as $r) {
              $tid = (string)($r['assigned_to'] ?? '');
              if ($tid !== '') { $actHolders[$tid] = $staffNames[$tid] ?? $tid; }
          }
          asort($actHolders, SORT_NATURAL | SORT_FLAG_CASE);

          /* The statuses actually present, in workflow order — not every status
             the system knows, most of which could never appear in this table. */
          $actStatusOrder = ['assigned','accepted','in_progress','waiting_for_materials','for_replacement'];
          $actStatuses = array_values(array_filter($actStatusOrder, static function ($s) use ($inprogress) {
              foreach ($inprogress as $r) { if (($r['status'] ?? '') === $s) { return true; } }
              return false;
          }));

          /* Not yet accepted is the one state worth singling out: the report has
             been dispatched and the technician has not picked it up, which is
             the failure the dispatcher can still do something about. */
          $actUnaccepted = count(array_filter($inprogress, static fn($r) => ($r['status'] ?? '') === 'assigned'));
        ?>
        <div class="panel asg-active">
          <div class="ph3">
            <h3><i class="fas fa-tasks"></i> Active Assignments</h3>
            <div class="ph3-r">
              <?php if ($actUnaccepted > 0): ?>
              <span class="ph3-warn" title="Dispatched, but the technician has not accepted yet">
                <i class="fas fa-hourglass-half"></i> <?php echo $actUnaccepted; ?> not yet accepted
              </span>
              <?php endif; ?>
              <span class="ph3-n"><?php echo $totalInProgress; ?> in progress</span>
            </div>
          </div>

          <?php if (!empty($inprogress)): ?>
          <div class="qbar">
            <div class="qsearch">
              <i class="fas fa-search"></i>
              <label for="aSearch" class="sr-only">Search active assignments</label>
              <input type="search" id="aSearch" autocomplete="off"
                     placeholder="Search ticket, equipment or technician">
              <button type="button" class="qclear" id="aClear" hidden aria-label="Clear search">
                <i class="fas fa-times"></i>
              </button>
            </div>
            <label for="aTech" class="sr-only">Filter by technician</label>
            <select class="qsel" id="aTech">
              <option value="">All technicians</option>
              <?php foreach ($actHolders as $tid => $nm): ?>
              <option value="<?php echo esc($tid); ?>"><?php echo esc($nm); ?></option>
              <?php endforeach; ?>
            </select>
            <label for="aStatus" class="sr-only">Filter by status</label>
            <select class="qsel" id="aStatus">
              <option value="">All statuses</option>
              <?php foreach ($actStatuses as $s): ?>
              <option value="<?php echo esc($s); ?>"><?php echo esc(stLbl($s)); ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($actUnaccepted > 0): ?>
            <label class="qchip" title="Dispatched but not yet picked up by the technician">
              <input type="checkbox" id="aPending">
              <span><i class="fas fa-hourglass-half"></i> Not yet accepted</span>
            </label>
            <?php endif; ?>
            <span class="qcount" id="aCount"></span>
          </div>
          <?php endif; ?>

          <div class="tblwrap"><table class="tbl asg-tbl" id="actTbl">
            <?php /* Fixed tracks so the columns line up down the page instead of
                     being re-decided by whichever equipment name is longest. */ ?>
            <colgroup>
              <col style="width:9.5rem"><col><col style="width:13rem">
              <col style="width:6rem"><col style="width:8.5rem">
              <col style="width:9rem"><col style="width:5rem">
            </colgroup>
            <thead>
              <tr>
                <th>Report</th><th>Equipment</th><th>Technician</th>
                <th>Priority</th><th>Status</th><th>Assigned</th>
                <th style="text-align:center;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if(empty($inprogress)): ?>
              <tr><td colspan="7"><div class="empty">
                <i class="fas fa-inbox"></i> No active assignments.
              </div></td></tr>
              <?php else: foreach($inprogressShown as $r):
                $aTid   = (string)($r['assigned_to'] ?? '');
                $aName  = $staffNames[$aTid] ?? '';
                $aWhen  = !empty($r['assigned_date']) ? strtotime((string)$r['assigned_date']) : null;
                $aAge   = $aWhen ? (time() - $aWhen) : null;
                $aAgeT  = $aAge === null ? '—'
                        : ($aAge < 3600  ? max(1, (int)floor($aAge / 60)) . 'm'
                        : ($aAge < 86400 ? (int)floor($aAge / 3600) . 'h'
                        :                  (int)floor($aAge / 86400) . 'd'));
                $aHay   = strtolower(trim(preg_replace('~\s+~', ' ', implode(' ', [
                    $r['report_id'] ?? '', $r['equipment_name'] ?? '',
                    $r['asset_tag'] ?? '', $aName, $aTid,
                ]))));
              ?>
              <tr data-hay="<?php echo esc($aHay); ?>"
                  data-tech="<?php echo esc($aTid); ?>"
                  data-status="<?php echo esc((string)($r['status'] ?? '')); ?>">
                <td><a class="rid rid-lnk" title="Open the full record in a new tab"
                       target="_blank" rel="noopener"
                       href="admin_defect_reports.php?view_id=<?php echo urlencode($r['report_id']); ?>"><?php echo esc($r['report_id']); ?></a></td>
                <td>
                  <div class="en"><?php echo esc($r['equipment_name']??'N/A'); ?></div>
                  <?php if(!empty($r['asset_tag'])): ?><div class="esl"><?php echo esc($r['asset_tag']); ?></div><?php endif; ?>
                </td>
                <?php /* The name is who you would go and ask; the id is what the
                         record stores. Both, with the name doing the reading. */ ?>
                <td>
                  <div class="en"><?php echo esc($aName !== '' ? $aName : $aTid); ?></div>
                  <?php if ($aName !== ''): ?><div class="esl"><?php echo esc($aTid); ?></div><?php endif; ?>
                </td>
                <td><span class="bdg b-<?php echo prCls($r['priority']); ?>"><?php echo prLbl($r['priority']); ?></span></td>
                <td><span class="bdg b-<?php echo stCls($r['status']); ?>"><?php echo stLbl($r['status']); ?></span></td>
                <?php /* A date alone makes the reader do the arithmetic that the
                         question "has this been sitting too long" actually needs. */ ?>
                <td class="nw"
                    title="<?php echo $aWhen ? esc(date('M j, Y · g:i A', $aWhen)) : 'Not recorded'; ?>">
                  <div class="en" style="font-weight:600;font-size:var(--fs-base);">
                    <?php echo $aWhen ? esc(date('M j', $aWhen)) : '—'; ?>
                  </div>
                  <div class="esl"><?php echo esc($aAgeT); ?> ago</div>
                </td>
                <td style="text-align:center;">
                  <button class="btn bico bi-d btn-sm" title="Unassign"
                    onclick="openUnassign('<?php echo esc($r['report_id']); ?>','<?php echo esc($r['equipment_name']??'Equipment'); ?>')">
                    <i class="fas fa-user-minus"></i>
                  </button>
                </td>
              </tr>
              <?php endforeach; endif; ?>
              <?php if (!empty($inprogress)): ?>
              <tr class="qnone" id="aNone" hidden><td colspan="7"><div class="empty">
                <i class="fas fa-magnifying-glass-minus"></i>
                No assignments match these filters.<br>
                <button type="button" class="btn btn-ghost btn-sm" style="margin-top:.7rem;"
                        onclick="aReset()"><i class="fas fa-rotate-left"></i> Clear filters</button>
              </div></td></tr>
              <?php if ($inprogressTotal > count($inprogressShown)): ?>
              <tr class="qcap"><td colspan="7"><div class="empty" style="text-align:left;">
                <i class="fas fa-layer-group" style="color:#C9960C;"></i>
                Showing <?php echo number_format(count($inprogressShown)); ?> of
                <strong><?php echo number_format($inprogressTotal); ?></strong> active assignments.
                Search and filters above apply to these <?php echo number_format(count($inprogressShown)); ?>.
                Technician workload counts below still cover all <?php echo number_format($inprogressTotal); ?>.
              </div></td></tr>
              <?php endif; ?>
              <?php endif; ?>
            </tbody>
          </table></div>
        </div>
  </div><!-- /pg -->
</div><!-- /wrap -->

<!-- === UNASSIGN CONFIRM MODAL ======================== -->
<div class="media-lb" id="mediaLb" onclick="if(event.target===this)closeMedia()">
  <button type="button" class="media-lb-x" onclick="closeMedia()" aria-label="Close"><i class="fas fa-times"></i></button>
  <div class="media-lb-body" id="mediaLbBody"></div>
</div>

<!-- Dispatch confirmation. Assigning sends an email and moves the report into a
     technician's queue, so it is worth one look at what is about to happen —
     especially the technician's current load, which is the thing most easily got
     wrong when several people are free. -->
<div class="mo" id="asgMo" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="mw">
    <div class="mhd">
      <div class="mhd-t">
        <h2><i class="fas fa-paper-plane" style="margin-right:.3rem;opacity:.8;"></i> Dispatch this repair?</h2>
        <p>The technician is notified by email and the report moves into their queue.</p>
      </div>
      <button class="mx" onclick="document.getElementById('asgMo').classList.remove('open')" aria-label="Cancel"><i class="fas fa-times"></i></button>
    </div>
    <div class="mb">
      <dl class="asg-sum" id="asgSum"></dl>
      <div id="asgWarn" style="display:none;margin-top:.7rem;" class="conf-panel">
        <div class="conf-icon"><i class="fas fa-triangle-exclamation"></i></div>
        <div style="font-size:var(--fs-base);color:var(--t2);line-height:1.5;" id="asgWarnTxt"></div>
      </div>
    </div>
    <div class="mf">
      <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('asgMo').classList.remove('open')">Back &amp; edit</button>
      <button type="button" class="btn btn-green btn-sm" id="asgGo" onclick="confirmAssign()">
        <i class="fas fa-paper-plane"></i> Confirm assignment
      </button>
    </div>
  </div>
</div>

<?php /* Technician profile. A look, not a decision — it assigns nothing and
         posts nothing, so its only footer control is Close plus a shortcut to
         select the person you are already looking at. Built from the same
         stored values the cards use; see $techProfiles for why nothing here is
         scored or predicted. */ ?>
<div class="mo" id="tprofMo" onclick="if(event.target===this)closeTechProfile()">
  <div class="mw" role="dialog" aria-modal="true" aria-labelledby="tprofName">
    <div class="mhd">
      <div class="mhd-t tprof-hd">
        <div class="tprof-av" id="tprofAv">--</div>
        <div style="min-width:0;">
          <h2 id="tprofName">Technician</h2>
          <p id="tprofRole">-</p>
        </div>
        <span class="tcd-avail" id="tprofAvail"><span class="tcd-dot"></span>-</span>
      </div>
      <button class="mx" onclick="closeTechProfile()" aria-label="Close"><i class="fas fa-times"></i></button>
    </div>
    <div class="mb">
      <dl class="tprof-rows" id="tprofRows"></dl>
      <div class="tprof-sec">
        <h4><i class="fas fa-clipboard-check"></i> Current workload
          <span class="tprof-n" id="tprofLoad">0</span>
        </h4>
        <div id="tprofHeld"></div>
      </div>
    </div>
    <div class="mf">
      <button class="btn btn-ghost btn-sm" onclick="closeTechProfile()">Close</button>
      <button class="btn btn-maroon btn-sm" id="tprofPick" onclick="tpSelect()">
        <i class="fas fa-user-check"></i> Select this technician
      </button>
    </div>
  </div>
</div>

<div class="mo" id="unMo" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="mw">
    <div class="mhd">
      <div class="mhd-t">
        <h2><i class="fas fa-user-minus" style="margin-right:.3rem;opacity:.8;"></i> Unassign Technician</h2>
        <p>This will return the report to &ldquo;Pending Verification&rdquo; status.</p>
      </div>
      <button class="mx" onclick="document.getElementById('unMo').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="mb">
      <div class="conf-panel">
        <div class="conf-icon"><i class="fas fa-exclamation-triangle"></i></div>
        <div>
          <div style="font-weight:700;font-size:var(--fs-lg);margin-bottom:.25rem;">
            Unassign report <span id="unRid" style="color:var(--m3);font-family:'Outfit',sans-serif;">-</span>?
          </div>
          <div style="font-size:var(--fs-base);color:var(--t2);line-height:1.5;">
            <strong id="unEq">-</strong> will be returned to the unassigned queue.
            The assigned technician will lose access to this task.
          </div>
        </div>
      </div>
    </div>
    <div class="mf">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('unMo').classList.remove('open')">Cancel</button>
      <form method="POST" action="admin_assign_technicians.php" style="display:inline;">
        <input type="hidden" name="action" value="unassign">
        <input type="hidden" name="report_id" id="unRidInput" value="">
        <button type="submit" class="btn btn-red btn-sm"><i class="fas fa-user-minus"></i> Confirm Unassign</button>
      </form>
    </div>
  </div>
</div>

<!-- === LOGOUT MODAL ================================== -->
<div class="mo" id="lgmo" onclick="if(event.target===this)this.classList.remove('open')">
  <div style="background:var(--s1);border-radius:var(--r4);padding:2rem;max-width:330px;
    width:90%;text-align:center;box-shadow:var(--sh3);animation:mUp .25s ease;margin:auto;">
    <i class="fas fa-sign-out-alt" style="font-size:2.2rem;color:var(--m3);margin-bottom:.7rem;display:block;"></i>
    <h3 style="font-family:'Outfit',sans-serif;font-size:var(--fs-xl);font-weight:800;margin-bottom:.35rem;">Log Out?</h3>
    <p style="font-size:var(--fs-md);color:var(--t2);margin-bottom:1.25rem;line-height:1.6;">
      You will be returned to the BEC admin login page.
    </p>
    <div style="display:flex;gap:.55rem;justify-content:center;">
      <button onclick="document.getElementById('lgmo').classList.remove('open')" class="btn btn-ghost btn-sm">Cancel</button>
      <a href="logout.php" class="btn btn-maroon btn-sm"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>
  </div>
</div>

<div class="ttray" id="ttray"></div>

<script>
/* --- STATE ---------------------------------------- */
let _selReport = null;

/* --- SELECT REPORT -------------------------------- */
/* The report text is reporter-supplied and goes into innerHTML below, so it is
   escaped here rather than trusted. escAttr also kills quotes that would break
   out of the onclick it is written into. */
function escHtml(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
  });
}
function escAttr(s) { return escHtml(s); }

/* --- FILTER BARS ---------------------------------- */
/* The queue and the monitoring table ask different questions of different
   columns, but they filter the same way: narrow the rows already on the page,
   say how many survived, and offer a way back. One implementation configured
   twice — the alternative is the same forty lines with different ids.

   Nothing here talks to the server. Both sets are bounded (reports with no
   technician; reports a technician currently holds) and a round trip to
   Supabase costs about 429ms to return what the browser is already holding. */
function wireFilterBar(cfg) {
  var tbl = document.getElementById(cfg.table),
      box = document.getElementById(cfg.search);
  if (!tbl || !box) { return; }            /* an empty table renders no toolbar */

  var tbody = tbl.tBodies[0],
      clr   = document.getElementById(cfg.clear),
      count = document.getElementById(cfg.count),
      none  = document.getElementById(cfg.none),
      rows  = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-hay]')),
      total = rows.length,
      sortEl = cfg.sort ? document.getElementById(cfg.sort.id) : null,
      /* Optional controls: the chip is only rendered when the state it filters
         for actually occurs, so a missing element is normal, not an error. */
      flagEl = cfg.flag ? document.getElementById(cfg.flag.id) : null,
      sels   = (cfg.selects || []).map(function (s) {
                 return { el: document.getElementById(s.id), attr: s.attr };
               }).filter(function (s) { return s.el; });

  function num(row, key) { return parseInt(row.getAttribute(key), 10) || 0; }

  function apply() {
    var q = box.value.trim().toLowerCase(), shown = 0;

    rows.forEach(function (row) {
      var ok = (!q || row.getAttribute('data-hay').indexOf(q) !== -1);
      for (var i = 0; ok && i < sels.length; i++) {
        if (sels[i].el.value && row.getAttribute(sels[i].attr) !== sels[i].el.value) { ok = false; }
      }
      if (ok && flagEl && flagEl.checked && row.getAttribute(cfg.flag.attr) !== cfg.flag.value) {
        ok = false;
      }
      row.classList.toggle('qhide', !ok);
      if (ok) { shown++; }
    });

    /* Order the whole set, not only what is visible: a row hidden by the search
       has to already be in its right place for when the search is cleared.
       appendChild moves the node rather than copying it, so each row's Assign
       or Unassign handler travels with it. */
    if (sortEl) {
      var mode = sortEl.value;
      rows.slice().sort(function (a, b) { return cfg.sort.compare(mode, a, b, num); })
          .forEach(function (row) { tbody.appendChild(row); });
    }
    /* The sort above appends every data row, which would leave the render-cap
       notice stranded above them. It is a footnote about the rows, so it has to
       stay under the rows — re-append it for the same reason `none` is. */
    var capRow = tbody.querySelector('tr.qcap');
    if (capRow) { tbody.appendChild(capRow); }
    if (none) { tbody.appendChild(none); none.hidden = (shown !== 0); }

    count.innerHTML = (shown === total)
      ? '<b>' + total + '</b> ' + cfg.noun + (total === 1 ? '' : 's')
      : '<b>' + shown + '</b> of ' + total + ' shown';
    if (clr) { clr.hidden = (box.value === ''); }
  }

  box.addEventListener('input', apply);
  sels.forEach(function (s) { s.el.addEventListener('change', apply); });
  if (sortEl) { sortEl.addEventListener('change', apply); }
  if (flagEl) { flagEl.addEventListener('change', apply); }
  if (clr) { clr.addEventListener('click', function () { box.value = ''; apply(); box.focus(); }); }

  /* Escape empties the search rather than bubbling up to close something: this
     is a search box in a page, not in a dialog. */
  box.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && box.value !== '') { e.stopPropagation(); box.value = ''; apply(); }
  });

  window[cfg.reset] = function () {
    box.value = '';
    sels.forEach(function (s) { s.el.value = ''; });
    if (flagEl) { flagEl.checked = false; }
    apply();
    box.focus();
  };

  apply();
}

/* The queue: which is worst, and what has been waiting longest. */
wireFilterBar({
  table: 'unTbl', search: 'qSearch', clear: 'qClear', count: 'qCount',
  none: 'qNone', reset: 'qReset', noun: 'report',
  selects: [{ id: 'qPrio', attr: 'data-prio' }],
  flag: { id: 'qStale', attr: 'data-stale', value: '1' },
  sort: {
    id: 'qSort',
    compare: function (mode, a, b, num) {
      if (mode === 'prio') {
        return num(b, 'data-rank') - num(a, 'data-rank')
            || num(b, 'data-age')  - num(a, 'data-age');
      }
      if (mode === 'new') { return num(a, 'data-age') - num(b, 'data-age'); }
      return num(b, 'data-age') - num(a, 'data-age');          /* longest waiting */
    }
  }
});

/* The monitoring table: who is carrying what, and what has not been picked up.
   No sort control — the rows arrive newest-assigned first, which is the order
   this table is read in. */
wireFilterBar({
  table: 'actTbl', search: 'aSearch', clear: 'aClear', count: 'aCount',
  none: 'aNone', reset: 'aReset', noun: 'assignment',
  selects: [{ id: 'aTech', attr: 'data-tech' }, { id: 'aStatus', attr: 'data-status' }],
  flag: { id: 'aPending', attr: 'data-status', value: 'assigned' }
});

/* --- TECHNICIAN PROFILE --------------------------- */
/* Built server-side and embedded once. The HEX flags matter: equipment names
   and report ids are being written inside a script element, and a stored value
   containing a closing script tag would end this block early and take every
   function below it with it. JSON_HEX_TAG escapes the angle brackets so no
   stored text can do that.

   Note for whoever edits this comment: the sequence itself cannot be written
   here in full, for exactly the reason described above. */
var TECH_PROFILES = <?php echo json_encode($techProfiles,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG |
    JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var tpCurrent = null;

function tpRow(label, value, isLink) {
  if (!value) {
    return '<dt>' + label + '</dt><dd class="none">Not recorded</dd>';
  }
  var v = isLink
    ? '<a href="' + (isLink === 'mail' ? 'mailto:' : 'tel:') + escAttr(value) + '">' + escHtml(value) + '</a>'
    : escHtml(value);
  return '<dt>' + label + '</dt><dd>' + v + '</dd>';
}

function showTechProfile(tid) {
  var p = TECH_PROFILES[tid];
  if (!p) { return; }
  tpCurrent = tid;

  document.getElementById('tprofAv').textContent = (p.name || '??').split(' ')
    .slice(0, 2).map(function (w) { return w.charAt(0); }).join('').toUpperCase();
  document.getElementById('tprofName').textContent = p.name;
  document.getElementById('tprofRole').textContent = p.pos || p.spec || 'Technician';

  var av = document.getElementById('tprofAvail');
  av.style.color = p.aColor; av.style.background = p.aBg;
  av.innerHTML = '<span class="tcd-dot" style="background:' + escAttr(p.aColor) + ';"></span>'
               + escHtml(p.aLbl);

  document.getElementById('tprofRows').innerHTML =
      tpRow('Technician ID', p.tid)
    + tpRow('Specialization', p.spec)
    + tpRow('Department', p.dept)
    + tpRow('Email', p.email, 'mail')
    + tpRow('Phone', p.phone, 'tel');

  document.getElementById('tprofLoad').textContent =
    p.load + (p.load === 1 ? ' active task' : ' active tasks');

  var held = p.held || [];
  document.getElementById('tprofHeld').innerHTML = held.length
    ? held.map(function (j) {
        return '<div class="tprof-job">'
             +   '<span class="tprof-job-id">' + escHtml(j.id) + '</span>'
             +   '<span class="tprof-job-eq">' + escHtml(j.eq) + '</span>'
             +   '<span class="bdg b-' + escAttr(j.pcls) + '">' + escHtml(j.prio) + '</span>'
             +   '<span class="bdg b-' + escAttr(j.scls) + '">' + escHtml(j.status) + '</span>'
             +   (j.when ? '<span class="tprof-job-when">' + escHtml(j.when) + '</span>' : '')
             + '</div>';
      }).join('')
    : '<div class="tprof-free"><i class="fas fa-circle-check"></i> '
      + 'No active tasks — free to take work.</div>';

  /* Selecting from here only makes sense while there is a report to assign, and
     an inactive account cannot be assigned to at all. */
  var pick = document.getElementById('tprofPick');
  var rid = document.getElementById('fRid');
  var canPick = (p.avail !== 'unavailable') && !!(rid && rid.value);
  pick.style.display = canPick ? '' : 'none';

  document.getElementById('tprofMo').classList.add('open');
}

function closeTechProfile() {
  document.getElementById('tprofMo').classList.remove('open');
  tpCurrent = null;
}

function tpSelect() {
  if (!tpCurrent) { return; }
  var card = document.querySelector('.tcd[data-tid="' + tpCurrent + '"]');
  closeTechProfile();
  if (card) { assignFromCard(card); }
}

/* --- MEDIA LIGHTBOX ------------------------------- */
function openMedia(src, kind) {
  const lb = document.getElementById('mediaLb');
  const body = document.getElementById('mediaLbBody');
  body.innerHTML = (kind === 'video')
    ? '<video src="' + escAttr(src) + '" controls autoplay playsinline></video>'
    : '<img src="' + escAttr(src) + '" alt="Reported defect">';
  lb.classList.add('open');
}
function closeMedia() {
  const lb = document.getElementById('mediaLb');
  lb.classList.remove('open');
  document.getElementById('mediaLbBody').innerHTML = '';   // stops a playing video
}
/* Escape closes whatever is open, innermost first: the lightbox sits on top of
   the dialogs, the dialogs sit on top of the drawer, and the drawer goes last.
   Closing the drawer while a confirmation is still open would leave the
   confirmation floating over a page it no longer belongs to. */
document.addEventListener('keydown', function (e) {
  if (e.key !== 'Escape') { return; }
  var lb = document.getElementById('mediaLb');
  if (lb && lb.classList.contains('open')) { closeMedia(); return; }
  var mo = document.querySelector('.mo.open');
  if (mo) { mo.classList.remove('open'); return; }
  var dw = document.getElementById('asgDw');
  if (dw && dw.classList.contains('open')) { closeDispatch(); }
});

/* Picking a report from the queue.
   The Assign button in the last cell used to be the only live target on the
   row, so clicking the equipment — the obvious thing to click once you have
   decided which fault to dispatch — did nothing at all. The whole row is the
   button now; the report-id link keeps its own job (open the full record in a
   new tab) and stops the click from reaching the row. */
function pickRow(tr) {
  if (!tr) { return; }
  var raw = tr.getAttribute('data-rep');
  if (!raw) { return; }
  try { selectReport(JSON.parse(raw)); } catch (e) { /* malformed payload: leave the row inert */ }
}
document.addEventListener('click', function (ev) {
  var tr = ev.target.closest && ev.target.closest('tr.pick-row');
  if (!tr) { return; }
  if (ev.target.closest('a,button')) { return; }   // links and buttons speak for themselves
  pickRow(tr);
});
document.addEventListener('keydown', function (ev) {
  if (ev.key !== 'Enter' && ev.key !== ' ') { return; }
  var tr = ev.target.closest && ev.target.closest('tr.pick-row');
  if (!tr || ev.target !== tr) { return; }
  ev.preventDefault();
  pickRow(tr);
});

function selectReport(data) {
  _selReport = data;

  // Populate preview
  document.getElementById('repEmpty').style.display = 'none';
  document.getElementById('repFilled').style.display = 'block';
  document.getElementById('repPrev').classList.add('filled');
  document.getElementById('rpId').textContent = '#' + data.id;
  /* encodeURIComponent, not raw: the id is a stored value going into a URL. */
  document.getElementById('rpFull').href =
    'admin_defect_reports.php?view_id=' + encodeURIComponent(data.id);
  document.getElementById('rpEq').textContent = data.equipment + (data.asset ? '  -  ' + data.asset : '');
  document.getElementById('rpDesc').textContent = data.issue || 'No description given.';

  // Where, when and who — the three things asked before "send who?"
  const facts = [];
  if (data.location) facts.push(['fa-location-dot', data.location]);
  if (data.reported) facts.push(['fa-calendar-day', data.reported]);
  if (data.reporter) facts.push(['fa-user', data.reporter]);
  document.getElementById('rpFacts').innerHTML = facts.map(
    f => '<span class="rep-fact"><i class="fas ' + f[0] + '"></i>' + escHtml(f[1]) + '</span>'
  ).join('');

  // Photos and videos the reporter attached.
  const media = document.getElementById('rpMedia');
  const photos = data.photos || [], videos = data.videos || [];
  if (!photos.length && !videos.length) {
    media.innerHTML = '<div class="rep-nomedia"><i class="fas fa-image"></i> No photo or video attached</div>';
  } else {
    let h = '';
    photos.forEach(function (p) {
      h += '<button type="button" class="rep-thumb" onclick="openMedia(\'' + escAttr(p) + '\',\'image\')">'
         + '<img src="' + escAttr(p) + '" alt="Reported defect" loading="lazy"></button>';
    });
    videos.forEach(function (v) {
      h += '<button type="button" class="rep-thumb rep-thumb-vid" onclick="openMedia(\'' + escAttr(v) + '\',\'video\')">'
         + '<i class="fas fa-play"></i></button>';
    });
    media.innerHTML = h;
  }

  // Priority + dept badges
  const meta = document.getElementById('rpMeta');
  meta.innerHTML = prBadge(data.priority);
  if (data.dept) meta.innerHTML += ' ' + deptBadge(data.dept);

  // Fill form
  document.getElementById('fRid').value = data.id;
  asgSyncSteps();
  // Priority is a radio group now, not a select — tick the matching option so
  // picking a report still pre-fills the priority it was reported with.
  if (data.priority) {
    const p = document.querySelector('.prio-seg input[value="' + data.priority + '"]');
    if (p) { p.checked = true; }
  }
  // Responsible unit is a radio group now, so picking a report pre-selects the
  // unit it was already routed to instead of setting a select's value.
  if (data.dept) {
    const u = document.querySelector('.unit-seg input[value="' + data.dept + '"]');
    if (u) { u.checked = true; }
  }

  // Highlight row
  document.querySelectorAll('#unTbl tbody tr').forEach(r => r.style.background = '');
  const row = document.getElementById('row-' + data.id);
  if (row) {
    row.style.background = '#FEF3F2';
  }

  /* The workspace is a drawer now, so picking a report opens it rather than
     scrolling to a column that was always on screen. The old scrollIntoView on
     the row went with it: moving the page under the pointer at the moment the
     drawer covers it only fought the drawer. */
  openDispatch(data.id);

  toast('ok', 'Report #' + data.id + ' selected. Now pick a technician.', 'Report Selected');
}

/* --- DISPATCH DRAWER ------------------------------ */
/* Opening and closing only. Everything the drawer contains — the stepper, the
   form, the technician cards — is the same markup it was in the column, so no
   behaviour moved in here with it. */
var _dwReturn = null;

function openDispatch(rid) {
  var dw = document.getElementById('asgDw');
  if (!dw) { return; }
  /* Remember what to hand focus back to, so closing does not dump the caret at
     the top of the document and make the dispatcher find their row again. */
  _dwReturn = (rid && document.getElementById('row-' + rid)) || document.activeElement;
  dw.classList.add('open');
  document.body.style.overflow = 'hidden';
  var x = dw.querySelector('.dw-x');
  if (x) { x.focus(); }
}

function closeDispatch() {
  var dw = document.getElementById('asgDw');
  if (!dw) { return; }
  dw.classList.remove('open');
  document.body.style.overflow = '';
  if (_dwReturn) {
    var btn = _dwReturn.querySelector ? _dwReturn.querySelector('button') : null;
    (btn || _dwReturn).focus && (btn || _dwReturn).focus();
    _dwReturn = null;
  }
}

function clearReport() {
  _selReport = null;
  document.getElementById('repEmpty').style.display = 'flex';
  document.getElementById('repFilled').style.display = 'none';
  document.getElementById('repPrev').classList.remove('filled');
  document.getElementById('fRid').value = '';
  document.querySelectorAll('#unTbl tbody tr').forEach(r => r.style.background = '');
}

function clearAll() {
  clearReport();
  document.getElementById('assignForm').reset();
  document.getElementById('techPrev').classList.remove('show');
  // form.reset() clears the radios and the select, so the cards that mirror
  // them have to be cleared too or they keep claiming a selection that is gone
  document.querySelectorAll('.tech-card').forEach(c => c.classList.remove('on'));
  asgSyncSteps();
}

/* --- TECHNICIAN CHANGED --------------------------- */
/* Reflect what has been chosen so far. Called from every control that changes
   the answer to one of the four questions, so the stepper never claims progress
   that has not been made. */
function asgSyncSteps() {
  const has = {
    1: !!(document.getElementById('fRid') || {}).value,
    2: !!(document.getElementById('fTech') || {}).value,
    3: !!document.querySelector('.prio-seg input:checked')
       && !!document.querySelector('.unit-seg input:checked'),
  };
  const steps = document.querySelectorAll('#asgSteps .asg-step');
  // the current step is the first unanswered one
  let cur = has[1] ? (has[2] ? (has[3] ? 4 : 3) : 2) : 1;
  steps.forEach(function (el) {
    const n = parseInt(el.getAttribute('data-step'), 10);
    el.classList.toggle('done', n < cur);
    el.classList.toggle('now',  n === cur);
  });
}
document.addEventListener('change', function (e) {
  if (e.target.closest && e.target.closest('.prio-seg,.unit-seg,#fTech')) { asgSyncSteps(); }
});

function techChanged(sel) {
  const opt = sel.options[sel.selectedIndex];
  const prev = document.getElementById('techPrev');

  /* Mark the availability card for whoever is now chosen. The cards already set
     this select when clicked, but nothing pointed back the other way, so after
     picking someone the list gave no sign of who that was. */
  document.querySelectorAll('.tech-card').forEach(c =>
    c.classList.toggle('on', !!sel.value && c.getAttribute('data-tid') === sel.value));

  if (!opt.value) {
    prev.classList.remove('show');
    return;
  }

  // Show preview
  const initials = opt.dataset.initials || opt.text.slice(0,2).toUpperCase();
  const name     = opt.dataset.name || opt.text;
  const dept     = opt.dataset.dept || '';
  const wl       = parseInt(opt.dataset.wl) || 0;
  const deptCls  = opt.dataset.deptcls || 'gen';

  document.getElementById('tprofAv').textContent = initials;
  document.getElementById('tprofName').textContent = name;

  const tpMeta = document.getElementById('tpMeta');
  tpMeta.innerHTML = '';
  if (dept) tpMeta.innerHTML += deptBadgeStr(deptCls, dept);
  tpMeta.innerHTML += wlBadge(wl);

  prev.classList.add('show');
  asgSyncSteps();
  /* The card for this technician is already marked at the top of this function,
     against .tech-card and data-tid. A second block here highlighted .tcard and
     looked the element up as id="tc-<id>" — neither of which the page renders,
     so it had been doing nothing since the cards were rebuilt. */
}

/* --- TECHNICIAN CARD CLICK ------------------------ */
/* pickTech() and selectTechCard() stood here with no callers — the first from
   an older card list, the second from the "Use" button on the smart
   recommendation. assignFromCard() below is the one the cards actually call. */
/* Click a technician card to assign the currently-selected report to them. */
function assignFromCard(card) {
  const id   = card.getAttribute('data-tid');
  const name = card.getAttribute('data-name') || 'this technician';
  const sel  = document.getElementById('fTech');
  const opt  = sel.querySelector('option[value="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]');
  if (opt && opt.disabled) return;
  sel.value = id;
  techChanged(sel);
  const rid = document.getElementById('fRid').value;
  if (!rid) {
    if (typeof toast === 'function') { toast('err', 'Select a report on the left first, then click a technician to assign.', 'No report selected'); }
    document.getElementById('repPrev')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }
  if (confirm('Assign report ' + rid + ' to ' + name + '?')) {
    if (typeof validateAssign === 'function' && !validateAssign()) return;
    document.getElementById('assignForm').submit();
  } else {
    document.getElementById('assignForm')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}
/* A technician can arrive pre-selected from a deep link; reflect it. */
document.addEventListener('DOMContentLoaded', function () {
  const sel = document.getElementById('fTech');
  if (sel && sel.value) { techChanged(sel); }
});

/* --- VALIDATION ----------------------------------- */
/* Set only by the confirmation dialog, so the form cannot post until the summary
   has actually been seen. Reset immediately after, so a second assignment on the
   same page still has to be confirmed. */
let _asgOK = false;

function validateAssign() {
  if (!document.getElementById('fRid').value) {
    toast('err', 'Please select a report first.', 'Missing Report');
    return false;
  }
  const techSel = document.getElementById('fTech');
  if (!techSel.value) {
    toast('err', 'Please select a technician.', 'Missing Technician');
    return false;
  }
  if (!document.querySelector('.unit-seg input:checked')) {
    toast('err', 'Please choose the responsible unit.', 'Missing Unit');
    return false;
  }
  if (!document.querySelector('.prio-seg input:checked')) {
    toast('err', 'Please set a priority level.', 'Missing Priority');
    return false;
  }
  if (_asgOK) { _asgOK = false; return true; }   // came back from the dialog

  /* Build the summary from what is actually selected, not from a copy kept in
     JS that could drift out of step with the form. */
  const rid   = document.getElementById('fRid').value;
  const eqEl  = document.getElementById('rpEq');
  const tOpt  = techSel.options[techSel.selectedIndex];
  const unit  = document.querySelector('.unit-seg input:checked').value;
  const prio  = document.querySelector('.prio-seg input:checked').value;
  const card  = document.querySelector('.tech-card[data-tid="' + (window.CSS && CSS.escape ? CSS.escape(techSel.value) : techSel.value) + '"]');
  const instr = (document.getElementById('fInstr').value || '').trim();

  const row = (k, v) => '<dt>' + k + '</dt><dd>' + dEscA(v) + '</dd>';
  document.getElementById('asgSum').innerHTML =
      row('Report',     rid + (eqEl && eqEl.textContent.trim() ? ' — ' + eqEl.textContent.trim() : ''))
    + row('Technician', (tOpt ? tOpt.textContent : '').replace(/\s+/g, ' ').trim())
    + row('Unit',       unit)
    + row('Priority',   prio.charAt(0).toUpperCase() + prio.slice(1))
    + row('Instructions', instr !== '' ? instr : 'None given');

  /* Overload is the one thing worth interrupting for: everything else on this
     form is visible, but how much that technician is already carrying is not. */
  const warn = document.getElementById('asgWarn');
  const load = card ? parseInt(card.getAttribute('data-load') || '0', 10) : 0;
  if (load >= 4) {
    document.getElementById('asgWarnTxt').innerHTML =
      '<b>Heavy workload.</b> This technician already has ' + load +
      ' open repairs. Assigning anyway is allowed — it is recorded either way.';
    warn.style.display = 'flex';
  } else {
    warn.style.display = 'none';
  }

  document.getElementById('asgMo').classList.add('open');
  setTimeout(function () { document.getElementById('asgGo').focus(); }, 60);
  return false;   // hold the submit until the dialog says go
}

function dEscA(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function confirmAssign() {
  _asgOK = true;
  document.getElementById('asgMo').classList.remove('open');
  const btn = document.getElementById('asgGo');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Dispatching…';
  document.getElementById('assignForm').requestSubmit
    ? document.getElementById('assignForm').requestSubmit()
    : document.getElementById('assignForm').submit();
}

/* --- UNASSIGN MODAL ------------------------------- */
function openUnassign(rid, eq) {
  document.getElementById('unRid').textContent = rid;
  document.getElementById('unEq').textContent  = eq;
  document.getElementById('unRidInput').value  = rid;
  document.getElementById('unMo').classList.add('open');
}

/* --- ANIMATED COUNTERS ---------------------------- */
function animN(id, to) {
  const el = document.getElementById(id);
  if (!el) return;
  const from = parseInt(el.textContent) || 0;
  const dur = 750, t0 = performance.now();
  const go = now => {
    const p = Math.min((now-t0)/dur,1), e = 1 - Math.pow(1-p,3);
    el.textContent = Math.round(from+(to-from)*e);
    if(p<1) requestAnimationFrame(go);
  };
  requestAnimationFrame(go);
}
document.addEventListener('DOMContentLoaded', () => {
  animN('sn0', <?php echo $totalUnassigned; ?>);
  animN('sn1', <?php echo $availTechs; ?>);
  animN('sn2', <?php echo $overloadedTechs; ?>);
  animN('sn3', <?php echo $totalInProgress; ?>);
});

/* --- PRE-SELECT REPORT FROM URL ------------------- */
<?php if($preReport): ?>
document.addEventListener('DOMContentLoaded', () => {
  /* json_encode, not addslashes: the description is free text that can contain
     newlines and quotes, which addslashes does not make safe inside a JS string
     literal. This also keeps the payload identical to the one the table sends. */
  selectReport(<?php echo json_encode([
    'id'        => $preReport['report_id'],
    'equipment' => $preReport['equipment_name'] ?? 'N/A',
    'asset'     => $preReport['asset_tag'] ?? '',
    'issue'     => $preReport['issue_description'] ?? '',
    'location'  => $preReport['location'] ?? '',
    'reported'  => !empty($preReport['report_date']) ? date('M j, Y', strtotime($preReport['report_date'])) : '',
    'reporter'  => $preReport['reporter_name'] ?? '',
    'priority'  => $preReport['priority'] ?? 'medium',
    'dept'      => $preReport['department_assigned'] ?? '',
    'photos'    => photoListFromRow($preReport),
    'videos'    => videoListFromRow($preReport),
  ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>);
});
<?php endif; ?>

/* --- BADGE HELPERS -------------------------------- */
function prBadge(p) {
  const map = { critical:'crit', high:'hi', medium:'med', low:'lo' };
  const cls = map[p] || 'lo';
  return `<span class="bdg b-${cls}">${p||'-'}</span>`;
}
function deptBadge(d) {
  if(d==='ITSO') return '<span class="dept-itso"><i class="fas fa-laptop-code"></i>ITSO</span>';
  if(d==='PMO')  return '<span class="dept-pmo"><i class="fas fa-building"></i>PMO</span>';
  return '';
}
function deptBadgeStr(cls, d) {
  return `<span class="dept-${cls}" style="font-size:var(--fs-xs);padding:.18rem .5rem;">${d}</span>`;
}
function wlBadge(n) {
  const over = n >= 4;
  const lbl = over ? 'Overloaded' : 'Available';
  const bg  = over ? '#FFF1F2' : '#F0FDF4';
  const c   = over ? '#DC2626' : '#16A34A';
  return `<span style="background:${bg};color:${c};padding:.1rem .45rem;border-radius:20px;font-size:var(--fs-xs);font-weight:800;">${lbl} (${n} active)</span>`;
}

/* --- TOAST ---------------------------------------- */
function toast(type, msg, title) {
  const el = document.createElement('div');
  el.className = 'tst ' + type;
  el.innerHTML = `<div><div class="tst-t">${title}</div><div class="tst-m">${msg}</div></div>`;
  document.getElementById('ttray').appendChild(el);
  setTimeout(() => { el.style.transition='opacity .3s'; el.style.opacity='0'; setTimeout(()=>el.remove(),300); }, 4000);
}
</script>

<!-- -- Technician Cards (right-side panel in wider viewports, below left) -->
<!-- These are rendered as JS-clickable cards in the sidebar -->
<script>
// Inject tech cards into the technician section of the panel
// (We pre-render them via PHP below and toggle selection from JS)
</script>

<?php require_once __DIR__ . '/includes/csrf_inject.php'; ?>
<script src="assets/sidebar_autohide.js" defer></script>
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<?php require __DIR__ . '/includes/admin_ui.php'; ?>
</body>
</html>




