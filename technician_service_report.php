<?php
/**
 * technician_service_report.php — the Repair Completion Form.
 *
 * Written by the system, not by anyone: once a task has been marked fixed,
 * everything the office needs to file is already on the record — who reported
 * what and where, who fixed it, when, how, with which parts, at what cost, and
 * the photos before and after. This page arranges that into one printable
 * document and reads it back as a short narrative. Nobody types anything.
 *
 * Exists only for a fixed task (completed, verified or closed). Earlier than
 * that there is nothing to print, and the page says so.
 *
 * Reachable by the technician who did the job and by admins
 * (requireRole('technician') — admins bypass). Requires ?report=<ID>.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
// The page name would force the 'technician' session context; pick the session
// that is actually present.
startRoleSession(isset($_COOKIE['BECSESSID_ADMIN']) ? 'admin' : 'technician');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/technician_guard.php';
requireRole('technician'); // admins bypass this in requireRole()

function sr_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function sr_has($v) { return trim((string)$v) !== ''; }
function sr_peso($v) { $v = (float)$v; return $v > 0 ? '₱' . number_format($v, 2) : ''; }
/** A JSON list of upload paths (or one bare path) → web paths. */
function sr_paths($raw): array {
    $raw = trim((string)$raw);
    if ($raw === '') return [];
    $d = json_decode($raw, true);
    $list = (json_last_error() === JSON_ERROR_NONE && is_array($d)) ? $d : [$raw];
    $out = [];
    foreach ($list as $p) { $p = str_replace('\\', '/', trim((string)$p)); if ($p !== '' && is_file(__DIR__ . '/' . $p)) $out[] = $p; }
    return $out;
}
/** "1 day 2 h", "45 min" — between two timestamps, or '' when either is missing. */
function sr_span($from, $to): string {
    $a = strtotime((string)$from); $b = strtotime((string)$to);
    if (!$a || !$b || $b <= $a) return '';
    $m = intdiv($b - $a, 60);
    if ($m < 60) return $m . ' min';
    $h = intdiv($m, 60); $m %= 60;
    if ($h < 24) return $h . ' h' . ($m ? ' ' . $m . ' min' : '');
    $d = intdiv($h, 24); $h %= 24;
    return $d . ' day' . ($d === 1 ? '' : 's') . ($h ? ' ' . $h . ' h' : '');
}

$reportId = trim((string)($_GET['report'] ?? ''));
$r = $reportId !== '' ? getDefectReportById($reportId) : null;   // technician & reporter names, photos, resolved

// The report carries the reporter's name and the full fault history, and the
// portal promises reporters that only the PMO and *their* technician see it.
// Any signed-in technician could previously read any report by changing the id
// in the address bar, so the assignment is checked here as well as the role.
if ($r && ($_SESSION['role'] ?? '') === 'technician') {
    $assignee = (string)($r['assigned_to'] ?? ($r['assigned_technician'] ?? ''));
    if (!technicianOwnsAssigneeValue($assignee, technicianIdentityKeysFromSession($_SESSION))) {
        http_response_code(403);
        exit('This form belongs to another technician\'s task.');
    }
}

$g = static function (string $k, $default = '') use ($r) { return $r[$k] ?? $default; };
$fmtDate = static function ($v, $withTime = false) {
    $t = is_numeric($v) ? (int)$v : strtotime((string)$v);
    return $t ? date($withTime ? 'F j, Y · g:i A' : 'F j, Y', $t) : '';
};
$statusLabels = ['reported'=>'Pending','pmo_review'=>'Received by PMO','ready_for_assignment'=>'Ready to Assign','assigned'=>'Assigned','accepted'=>'Accepted','in_progress'=>'In Progress','waiting_for_materials'=>'Waiting for Materials','for_replacement'=>'For Replacement','completed'=>'Fixed — awaiting PMO check','verified'=>'Verified','closed'=>'Verified & Closed','rejected'=>'Rejected'];
$status = strtolower((string)$g('status'));
$statusLabel = $statusLabels[$status] ?? ucfirst(str_replace('_',' ',$status));
// The form exists once the repair is done. Before that there is nothing to
// print, and a half-empty "record" would only invite someone to fill it in.
$isFixed = $r && in_array($status, ['completed','verified','closed'], true);

$eqName   = (string)$g('equipment_name');
$assetTag = (string)$g('asset_tag');
$category = (string)$g('category_name');
$location = (string)$g('location');
$unit     = (string)$g('department_assigned');
$unitName = ['PMO' => 'Property Management Office', 'ITSO' => 'IT Services Office'][strtoupper($unit)] ?? $unit;

$techName = (string)$g('technician_name');
if (strcasecmp($techName, 'Unassigned') === 0) $techName = '';
$rpName   = (string)$g('reporter_name');
$rpWho    = reporterTypeLabel($g('reporter_type'));
$rpDept   = (string)$g('reporter_department');
$rpLine   = implode(', ', array_filter([$rpWho, $rpDept]));

$reported  = (string)$g('report_date');
$received  = (string)($g('received_by_pmo_at') ?: $g('pmo_reviewed_at'));
$assigned  = (string)$g('assigned_date');
$started   = (string)($g('started_at') ?: $g('date_started'));
$finished  = (string)$g('completion_date');
$duration  = trim((string)$g('repair_duration')) ?: sr_span($started, $finished);
$openFor   = sr_span($reported, $finished);

// What was done. New reports carry it in work_performed; older ones may have
// written the same thing under diagnosis / actions instead.
$workDone  = trim((string)($g('work_performed') ?: ($g('actions_performed') ?: $g('diagnosis'))));
$parts     = trim((string)$g('parts_replaced'));
$extraRows = array_filter([
    'Diagnosis'         => (string)$g('diagnosis'),
    'Actions performed' => (string)$g('actions_performed'),
    'Repair procedures' => (string)$g('repair_procedures'),
    'Tools & materials' => trim(implode(' · ', array_filter([trim((string)$g('tools_used')), trim((string)$g('materials_used'))]))),
], static fn($v, $k) => sr_has($v) && $v !== $workDone, ARRAY_FILTER_USE_BOTH);
$cost      = sr_peso((float)$g('repair_cost') > 0 ? $g('repair_cost') : $g('estimated_cost'));
$techNote  = trim((string)$g('technician_notes'));
if (strcasecmp($techNote, $workDone) === 0) $techNote = '';   // the same sentence twice helps nobody
$pmoNote   = trim((string)($g('admin_notes') ?: $g('verification_notes')));
$sat       = strtolower(trim((string)$g('satisfaction')));
$satNote   = trim((string)$g('satisfaction_note'));

$beforePhotos = array_values(array_unique(array_merge((array)($r['photos'] ?? []), sr_paths($g('before_photos')))));
$duringPhotos = sr_paths($g('during_photos'));
$afterPhotos  = array_values(array_unique(array_merge(sr_paths($g('after_photos')), sr_paths($g('work_photos')))));

/* ── The narrative: the record read back as plain sentences ─────────────
   This is the part a person used to write. Every clause is conditional on
   the data being there, so an old record with gaps reads as shorter, never
   as wrong. */
$story = [];
if ($isFixed) {
    $s = 'On ' . ($fmtDate($reported) ?: 'an unrecorded date') . ', '
       . ($rpName !== '' ? $rpName . ($rpLine !== '' ? ' (' . $rpLine . ')' : '') : 'a member of the BEC community')
       . ' reported a problem with the ' . ($eqName !== '' ? $eqName : 'equipment')
       . ($assetTag !== '' ? ' (tag ' . $assetTag . ')' : '')
       . ($location !== '' ? ' at ' . $location : '') . '.';
    $story[] = $s;
    $s = ($techName !== '' ? $techName : 'A technician') . ($unitName !== '' ? ' of the ' . $unitName : '');
    if ($assigned !== '' && $started !== '') {
        $s .= ' was assigned on ' . $fmtDate($assigned) . ' and started work on ' . $fmtDate($started, true) . '.';
    } elseif ($started !== '') {
        $s .= ' started work on ' . $fmtDate($started, true) . '.';
    } elseif ($assigned !== '') {
        $s .= ' was assigned on ' . $fmtDate($assigned) . '.';
    } else {
        $s .= ' carried out the repair.';
    }
    $story[] = $s;
    $s = 'The repair was completed on ' . ($fmtDate($finished, true) ?: 'an unrecorded date')
       . ($duration !== '' ? ', taking ' . $duration : '')
       . ($parts !== '' ? ', using ' . $parts : '')
       . ($cost !== '' ? ', at a cost of ' . $cost : '') . '.';
    $story[] = $s;
    if ($status === 'closed' || $status === 'verified') {
        $story[] = 'The Property Management Office checked the work and ' . ($status === 'closed' ? 'closed the report' : 'verified it')
                 . ($pmoNote !== '' ? ', noting: "' . $pmoNote . '"' : '') . '.';
    } else {
        $story[] = 'The report is now with the Property Management Office for verification.';
    }
    if ($sat === 'satisfied' || $sat === 'yes') {
        $story[] = 'The reporter confirmed the equipment is working again' . ($satNote !== '' ? ': "' . $satNote . '"' : '.');
    } elseif ($sat !== '' && $sat !== 'satisfied') {
        $story[] = 'The reporter said the problem was not resolved' . ($satNote !== '' ? ': "' . $satNote . '"' : '.');
    }
    if ($openFor !== '') $story[] = 'From report to repair: ' . $openFor . '.';
}

$timeline = array_values(array_filter([
    ['Reported',           $reported,  'fa-flag'],
    ['Received by the PMO',$received,  'fa-inbox'],
    ['Assigned',           $assigned,  'fa-user-gear'],
    ['Repair started',     $started,   'fa-play'],
    ['Marked fixed',       $finished,  'fa-circle-check'],
], static fn($row) => sr_has($row[1])));

$today = date('F j, Y');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Repair Form<?php echo $reportId !== '' ? ' — ' . sr_e($reportId) : ''; ?> — BEC PMO</title>
<link rel="icon" type="image/png" href="assets/logs.png">
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<style>
  :root{--maroon:#7B1D1D;--maroon-d:#4A0E0E;--gold:#C9960C;--ink:#1C1008;--ink2:#5C3838;--ink3:#755B4E;--paper:#F1EEE8;--surface:#fff;--border:#E2D9CC;--line:#D8CCBD;}
  *{margin:0;padding:0;box-sizing:border-box;font-family:'DM Sans',system-ui,sans-serif;}
  body{background:var(--paper);color:var(--ink);padding:1.4rem 1rem 3rem;}
  .toolbar{max-width:820px;margin:0 auto 1rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;}
  .btn{display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1.1rem;border-radius:11px;border:1.5px solid var(--maroon);background:var(--maroon);color:#fff;font-size:.86rem;font-weight:700;cursor:pointer;text-decoration:none;transition:filter .15s,transform .12s;min-height:44px;}
  .btn:hover{filter:brightness(1.08);} .btn:active{transform:translateY(1px);}
  .btn.ghost{background:transparent;color:var(--maroon);}
  .btn.gold{background:var(--gold);border-color:var(--gold);color:#3a2600;}
  .doc{max-width:820px;margin:0 auto;background:var(--surface);border:1px solid var(--border);border-radius:6px;box-shadow:0 12px 40px rgba(44,10,10,.12);padding:2.4rem 2.6rem;}
  .doc-head{display:flex;align-items:center;gap:1rem;border-bottom:2.5px solid var(--maroon);padding-bottom:1rem;}
  .doc-seal{width:66px;height:66px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1.5px solid var(--gold);}
  .doc-org{flex:1;text-align:center;}
  .doc-org .o1{font-family:'Fraunces',serif;font-weight:700;font-size:1.2rem;color:var(--maroon-d);}
  .doc-org .o2{font-size:.74rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--ink3);margin-top:.1rem;}
  .doc-org .o3{font-size:.64rem;color:var(--ink3);margin-top:.15rem;}
  .doc-title{text-align:center;font-family:'Fraunces',serif;font-weight:700;font-size:1.15rem;color:var(--ink);margin:1rem 0 .3rem;text-transform:uppercase;letter-spacing:.03em;}
  .doc-title-sub{text-align:center;font-size:.72rem;color:var(--ink3);margin-bottom:1.3rem;}
  .refbar{display:flex;flex-wrap:wrap;gap:.6rem 1.5rem;align-items:center;justify-content:space-between;padding:.7rem 1rem;background:#FBF7F0;border:1px solid var(--line);border-radius:9px;margin-bottom:1.4rem;}
  .refbar .ri{font-size:.84rem;color:var(--ink2);} .refbar .ri b{color:var(--maroon-d);font-family:'Fraunces',serif;}
  .badge{display:inline-flex;align-items:center;gap:.35rem;padding:.24rem .7rem;border-radius:999px;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.4px;background:rgba(123,29,29,.08);color:var(--maroon);}
  .badge.done{background:#EEF7F0;color:#1A7A33;}
  .sec{margin-bottom:1.35rem;}
  .sec-h{font-family:'Fraunces',serif;font-weight:700;font-size:.98rem;color:var(--maroon-d);margin-bottom:.55rem;padding-bottom:.3rem;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:.5rem;}
  .sec-h i{color:var(--gold);font-size:.85rem;}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:.55rem 1.6rem;}
  .kv{font-size:.85rem;line-height:1.5;}
  .kv .k{font-size:.64rem;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink3);display:block;margin-bottom:.05rem;}
  .kv .v{color:var(--ink);font-weight:600;word-break:break-word;}
  .kv .v.muted{color:var(--ink3);font-weight:500;}
  .prose{font-size:.88rem;line-height:1.6;color:var(--ink);white-space:pre-line;}
  .srv-row{display:grid;grid-template-columns:150px 1fr;gap:.5rem 1rem;padding:.5rem 0;border-top:1px dashed var(--line);}
  .srv-row:first-child{border-top:none;}
  .srv-row .k{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink3);padding-top:.1rem;}
  .srv-row .v{font-size:.87rem;line-height:1.55;color:var(--ink);white-space:pre-line;word-break:break-word;}
  .cost-box{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-top:.4rem;padding:.85rem 1.15rem;border:2px solid var(--maroon);border-radius:11px;background:linear-gradient(135deg,#FBF3EC,#fff);}
  .cost-box .cl{font-family:'Fraunces',serif;font-weight:700;font-size:.98rem;color:var(--maroon-d);}
  .cost-box .cv{font-family:'Fraunces',serif;font-weight:700;font-size:1.45rem;color:var(--maroon);}
  .signs{display:grid;grid-template-columns:1fr 1fr;gap:2.5rem;margin-top:2.6rem;}
  .sign{text-align:center;} .sign .ln{border-top:1.5px solid var(--ink2);margin:0 auto .35rem;padding-top:.35rem;}
  .sign .nm{font-weight:700;font-size:.86rem;color:var(--ink);text-transform:uppercase;letter-spacing:.4px;min-height:1.1em;}
  .sign .rl{font-size:.72rem;color:var(--ink3);margin-top:.1rem;}
  .foot-note{margin-top:1.6rem;font-size:.68rem;color:var(--ink3);line-height:1.5;border-top:1px dashed var(--line);padding-top:.7rem;}
  .story{font-size:.92rem;line-height:1.75;color:var(--ink);padding:1rem 1.15rem;border-left:3px solid var(--gold);background:#FBF7F0;border-radius:0 10px 10px 0;}
  .story p+p{margin-top:.45rem;}
  .tl{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.6rem;}
  .tl .st{padding:.6rem .75rem;border:1px solid var(--line);border-radius:9px;background:#fff;}
  .tl .st i{color:var(--gold);font-size:.72rem;margin-right:.35rem;}
  .tl .st b{display:block;font-size:.66rem;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--ink3);}
  .tl .st span{font-size:.8rem;font-weight:600;color:var(--ink);}
  .shots{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
  .shots h4{font-size:.68rem;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--ink3);margin-bottom:.4rem;}
  .shots .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:.45rem;}
  .shots img{width:100%;height:110px;object-fit:cover;border-radius:8px;border:1px solid var(--line);}
  .shots .none{font-size:.8rem;color:var(--ink3);padding:.6rem;border:1px dashed var(--line);border-radius:8px;text-align:center;}
  .auto-note{display:flex;align-items:center;gap:.5rem;font-size:.72rem;color:var(--ink3);margin:-.6rem 0 1.1rem;justify-content:center;}
  .auto-note i{color:var(--gold);}
  @media(max-width:640px){.shots{grid-template-columns:1fr;}}
  @media print{.tl .st,.story,.shots img{break-inside:avoid;}}
  .missing{max-width:560px;margin:3rem auto;text-align:center;background:#fff;border:1px solid var(--border);border-radius:14px;padding:2.5rem 2rem;}
  .missing i{font-size:2rem;color:var(--gold);}
  @media(max-width:640px){.doc{padding:1.5rem 1.1rem;}.grid2{grid-template-columns:1fr;}.signs{grid-template-columns:1fr;gap:2rem;}.srv-row{grid-template-columns:1fr;gap:.15rem;}}
  @media print{
    body{background:#fff;padding:0;}.no-print{display:none !important;}
    .doc{box-shadow:none;border:none;border-radius:0;max-width:100%;padding:0;margin:0;}
    th{background:#f2f2f2 !important;}
    .refbar,.badge.done,.doc-seal,.sec-h i,.cost-box{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  }
</style>
<!-- Last in <head> on purpose: these rules correct the desktop type scale for
     phones, and a stylesheet placed before the page's own <style> would lose
     to it on source order. See the file header for what was measured. -->
<link rel="stylesheet" href="css/mobile.css">
</head>
<body>
  <div class="toolbar no-print">
    <a class="btn ghost" href="javascript:history.back()"><i class="fas fa-arrow-left"></i> Back</a>
    <?php if ($isFixed): ?>
    <button class="btn gold" type="button" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button>
    <?php if (($_SESSION['role'] ?? '') !== 'technician'): ?>
    <a class="btn ghost" href="technician_cost_estimate.php?report=<?php echo urlencode($reportId); ?>" target="_blank" rel="noopener"><i class="fas fa-file-invoice-dollar"></i> Cost estimate</a>
    <?php endif; ?>
    <?php endif; ?>
  </div>

<?php if (!$r): ?>
  <div class="missing">
    <i class="fas fa-file-circle-question"></i>
    <h2 style="font-family:'Fraunces',serif;margin:.6rem 0 .3rem;">Report not found</h2>
    <p style="font-size:.9rem;color:var(--ink3);">No repair record matches <strong><?php echo sr_e($reportId ?: '(none)'); ?></strong>.</p>
  </div>
<?php elseif (!$isFixed): ?>
  <div class="missing">
    <i class="fas fa-hourglass-half"></i>
    <h2 style="font-family:'Fraunces',serif;margin:.6rem 0 .3rem;">Not fixed yet</h2>
    <p style="font-size:.9rem;color:var(--ink3);line-height:1.6;">Report <strong><?php echo sr_e($reportId); ?></strong> is <strong><?php echo sr_e($statusLabel); ?></strong>.
      The repair form is created by the system the moment the task is marked fixed — there is nothing to print before then.</p>
  </div>
<?php else: ?>
  <div class="doc" id="doc">
    <div class="doc-head">
      <img class="doc-seal" src="assets/logs.png" alt="BEC" onerror="this.style.display='none'">
      <div class="doc-org">
        <div class="o1">Batangas Eastern Colleges</div>
        <div class="o2">Property Management Office</div>
        <div class="o3">San Juan, Batangas · Equipment Maintenance</div>
      </div>
      <div style="width:66px;flex-shrink:0;"></div>
    </div>
    <div class="doc-title">Equipment Repair Completion Form</div>
    <div class="doc-title-sub">Record of a completed repair</div>
    <div class="auto-note"><i class="fas fa-wand-magic-sparkles"></i> Filled in automatically by the system from the report record — nothing here was typed by hand.</div>

    <div class="refbar">
      <div class="ri">Report No. <b><?php echo sr_e($reportId); ?></b></div>
      <div class="ri"><span class="badge done"><?php echo sr_e($statusLabel); ?></span></div>
      <div class="ri">Printed <?php echo sr_e($today); ?></div>
    </div>

    <!-- The record, read back -->
    <div class="sec">
      <div class="sec-h"><i class="fas fa-align-left"></i> Summary</div>
      <div class="story"><?php foreach ($story as $p): ?><p><?php echo sr_e($p); ?></p><?php endforeach; ?></div>
    </div>

    <!-- Equipment -->
    <div class="sec">
      <div class="sec-h"><i class="fas fa-wrench"></i> Equipment &amp; Location</div>
      <div class="grid2">
        <div class="kv"><span class="k">Equipment</span><span class="v"><?php echo sr_has($eqName)?sr_e($eqName):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Asset tag</span><span class="v"><?php echo sr_has($assetTag)?sr_e($assetTag):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Category</span><span class="v"><?php echo sr_has($category)?sr_e($category):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Location</span><span class="v"><?php echo sr_has($location)?sr_e($location):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Handled by</span><span class="v"><?php echo sr_has($unitName)?sr_e($unitName):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Priority</span><span class="v"><?php echo sr_has($g('priority'))?sr_e(ucfirst((string)$g('priority'))):'<span class="muted">—</span>'; ?></span></div>
      </div>
    </div>

    <!-- The concern -->
    <div class="sec">
      <div class="sec-h"><i class="fas fa-clipboard-list"></i> Reported Concern</div>
      <div class="grid2" style="margin-bottom:.6rem;">
        <div class="kv"><span class="k">Reported by</span><span class="v"><?php echo sr_has($rpName)?sr_e($rpName):'<span class="muted">—</span>'; ?><?php if ($rpLine !== ''): ?> <span class="muted" style="font-weight:500">(<?php echo sr_e($rpLine); ?>)</span><?php endif; ?></span></div>
        <div class="kv"><span class="k">Date reported</span><span class="v"><?php $d=$fmtDate($reported,true); echo $d?sr_e($d):'<span class="muted">—</span>'; ?></span></div>
      </div>
      <div class="kv"><span class="k">What the reporter said</span><span class="v prose"><?php echo sr_has($g('issue_description'))?nl2br(sr_e($g('issue_description'))):'<span class="muted">—</span>'; ?></span></div>
    </div>

    <!-- The repair -->
    <div class="sec">
      <div class="sec-h"><i class="fas fa-screwdriver-wrench"></i> Repair Done</div>
      <div class="srv-row"><div class="k">Technician</div><div class="v"><?php echo sr_has($techName)?sr_e($techName):'<span class="muted">—</span>'; ?></div></div>
      <div class="srv-row"><div class="k">What was done</div><div class="v"><?php echo sr_has($workDone)?nl2br(sr_e($workDone)):'<span class="muted">Not written down.</span>'; ?></div></div>
      <?php if ($parts !== ''): ?><div class="srv-row"><div class="k">Parts used</div><div class="v"><?php echo sr_e($parts); ?></div></div><?php endif; ?>
      <?php foreach ($extraRows as $k => $v): ?><div class="srv-row"><div class="k"><?php echo sr_e($k); ?></div><div class="v"><?php echo nl2br(sr_e($v)); ?></div></div><?php endforeach; ?>
      <?php if ($techNote !== ''): ?><div class="srv-row"><div class="k">Technician's note</div><div class="v"><?php echo nl2br(sr_e($techNote)); ?></div></div><?php endif; ?>
      <?php if ($duration !== ''): ?><div class="srv-row"><div class="k">Time on the job</div><div class="v"><?php echo sr_e($duration); ?></div></div><?php endif; ?>
      <?php if ($cost !== ''): ?>
      <div class="cost-box"><span class="cl">Cost of the repair</span><span class="cv"><?php echo sr_e($cost); ?></span></div>
      <?php endif; ?>
    </div>

    <!-- Photos -->
    <?php if ($beforePhotos || $afterPhotos || $duringPhotos): ?>
    <div class="sec">
      <div class="sec-h"><i class="fas fa-camera"></i> Before &amp; After</div>
      <div class="shots">
        <div>
          <h4>Before — from the report</h4>
          <?php if ($beforePhotos): ?><div class="grid"><?php foreach (array_slice($beforePhotos, 0, 6) as $p): ?><img src="<?php echo sr_e($p); ?>" alt="Before"><?php endforeach; ?></div>
          <?php else: ?><div class="none">No photo was attached to the report.</div><?php endif; ?>
        </div>
        <div>
          <h4>After — by the technician</h4>
          <?php $afterAll = array_merge($afterPhotos, $duringPhotos); if ($afterAll): ?><div class="grid"><?php foreach (array_slice($afterAll, 0, 6) as $p): ?><img src="<?php echo sr_e($p); ?>" alt="After"><?php endforeach; ?></div>
          <?php else: ?><div class="none">No photo of the finished work.</div><?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Timeline -->
    <div class="sec">
      <div class="sec-h"><i class="fas fa-clock"></i> Timeline</div>
      <div class="tl">
        <?php foreach ($timeline as [$lbl, $when, $ic]): ?>
        <div class="st"><b><i class="fas <?php echo $ic; ?>"></i><?php echo sr_e($lbl); ?></b><span><?php echo sr_e($fmtDate($when, true)); ?></span></div>
        <?php endforeach; ?>
        <?php if ($status === 'closed' || $status === 'verified'): ?>
        <div class="st"><b><i class="fas fa-certificate"></i>Verified by the PMO</b><span><?php echo $status === 'closed' ? 'Report closed' : 'Verified'; ?></span></div>
        <?php endif; ?>
      </div>
      <?php if ($pmoNote !== ''): ?><div class="kv" style="margin-top:.7rem;"><span class="k">PMO verification note</span><span class="v prose"><?php echo nl2br(sr_e($pmoNote)); ?></span></div><?php endif; ?>
    </div>

    <div class="signs">
      <div class="sign"><div class="ln"></div><div class="nm"><?php echo sr_has($techName)?sr_e($techName):'&nbsp;'; ?></div><div class="rl">Repaired by — Maintenance Technician</div></div>
      <div class="sign"><div class="ln"></div><div class="nm">&nbsp;</div><div class="rl">Verified by — Property Management Office</div></div>
    </div>

    <div class="foot-note"><i class="fas fa-circle-info"></i> Generated by the BEC PMO Equipment Reporting &amp; Maintenance Management System from the record of Report No. <?php echo sr_e($reportId); ?>. Printed <?php echo sr_e($today); ?>.</div>
  </div>
<?php endif; ?>
</body>
</html>
