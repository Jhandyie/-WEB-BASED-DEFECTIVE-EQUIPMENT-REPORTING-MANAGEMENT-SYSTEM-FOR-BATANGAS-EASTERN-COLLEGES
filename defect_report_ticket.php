<?php
/**
 * defect_report_ticket.php — formal, printable Equipment Defect Report ticket.
 *
 * The "before" companion to the Service Report: a professional printout of the
 * reporter's initial defect report (reporter, equipment, issue, priority,
 * evidence). Read-only. Reachable by admins and technicians (session picked from
 * whichever BEC cookie is present; admins bypass requireRole). Needs ?report=<ID>.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession(isset($_COOKIE['BECSESSID_ADMIN']) ? 'admin' : 'technician');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
requireRole('technician'); // admins bypass this in requireRole()

function dt_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function dt_has($v) { return trim((string)$v) !== ''; }
function dt_paths($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return [];
    $d = json_decode($raw, true);
    $arr = (json_last_error() === JSON_ERROR_NONE && is_array($d)) ? $d : [$raw];
    $out = [];
    foreach ($arr as $v) {
        $v = str_replace('\\', '/', trim((string)$v));
        if ($v === '') continue;
        $pos = strpos($v, 'uploads/');
        $out[] = $pos !== false ? substr($v, $pos) : $v;
    }
    return array_values(array_unique($out));
}

$reportId = trim((string)($_GET['report'] ?? ''));
$r = null;
if ($reportId !== '') {
    try {
        $pdo = getPgsqlPdoConnection();
        $st = $pdo->prepare("
            SELECT dr.*, e.asset_tag AS eq_asset_tag,
                   COALESCE(NULLIF(dr.equipment_name,''), e.equipment_name) AS eq_name,
                   COALESCE(c.category_name, CAST(e.category_id AS TEXT)) AS eq_category
            FROM public.defect_reports dr
            LEFT JOIN public.equipment e ON e.equipment_id = dr.equipment_id
            LEFT JOIN public.categories c ON c.category_id = e.category_id
            WHERE dr.report_id = :r LIMIT 1");
        $st->execute(['r' => $reportId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        try {
            $st = getPgsqlPdoConnection()->prepare("SELECT * FROM public.defect_reports WHERE report_id = :r LIMIT 1");
            $st->execute(['r' => $reportId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e2) { $r = null; }
    }
}

$g = static function (string $k, $default = '') use ($r) { return $r[$k] ?? $default; };
$fmtDate = static function ($v, $withTime = false) {
    $t = is_numeric($v) ? (int)$v : strtotime((string)$v);
    return $t ? date($withTime ? 'F j, Y · g:i A' : 'F j, Y', $t) : '';
};
$statusLabels = ['reported'=>'Pending Review','pmo_review'=>'Received by PMO','ready_for_assignment'=>'Ready to Assign','assigned'=>'Assigned','accepted'=>'Accepted','in_progress'=>'In Progress','waiting_for_materials'=>'Waiting for Materials','for_replacement'=>'For Replacement','completed'=>'Completed','verified'=>'Verified','closed'=>'Closed','rejected'=>'Rejected'];
$status = strtolower((string)$g('status'));
$statusLabel = $statusLabels[$status] ?? ucfirst(str_replace('_',' ',$status));

$eqName   = (string)($r['eq_name'] ?? $g('equipment_name'));
$assetTag = (string)($r['eq_asset_tag'] ?? $g('asset_tag'));
$category = (string)($r['eq_category'] ?? $g('category'));

$photos = $r ? dt_paths($g('defect_photos')) : [];
if (!$photos && dt_has($g('photo_path'))) { $photos = dt_paths($g('photo_path')); }
$videos = $r ? dt_paths($g('defect_videos')) : [];

$us = trim((string)$g('usable_status'));
$usMap = ['Yes'=>['#16A34A','Yes — still usable'],'Partially'=>['#D97706','Partially usable'],'No'=>['#DC2626','No — completely broken']];
$usInfo = $usMap[$us] ?? null;
$today = date('F j, Y');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Defect Report<?php echo $reportId !== '' ? ' — ' . dt_e($reportId) : ''; ?> — BEC PMO</title>
<link rel="icon" type="image/png" href="assets/logs.png">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  :root{--maroon:#7B1D1D;--maroon-d:#4A0E0E;--gold:#C9960C;--ink:#1C1008;--ink2:#5C3838;--ink3:#8A7466;--paper:#F1EEE8;--surface:#fff;--border:#E2D9CC;--line:#D8CCBD;}
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
  .sec{margin-bottom:1.35rem;}
  .sec-h{font-family:'Fraunces',serif;font-weight:700;font-size:.98rem;color:var(--maroon-d);margin-bottom:.55rem;padding-bottom:.3rem;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:.5rem;}
  .sec-h i{color:var(--gold);font-size:.85rem;}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:.55rem 1.6rem;}
  .kv{font-size:.85rem;line-height:1.5;}
  .kv .k{font-size:.64rem;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink3);display:block;margin-bottom:.05rem;}
  .kv .v{color:var(--ink);font-weight:600;word-break:break-word;}
  .kv .v.muted{color:var(--ink3);font-weight:500;}
  .prio{display:inline-block;padding:.12rem .55rem;border-radius:6px;font-size:.74rem;font-weight:800;text-transform:uppercase;letter-spacing:.3px;}
  .prio.critical{background:#FEF2F2;color:#991B1B;} .prio.high{background:#FFF7ED;color:#C2410C;} .prio.medium{background:#FFFBEB;color:#92600A;} .prio.low{background:#F0FDF4;color:#166534;}
  .issue-box{font-size:.9rem;line-height:1.65;color:var(--ink);white-space:pre-line;background:#FBF9F6;border:1px solid var(--line);border-left:3px solid var(--maroon);border-radius:8px;padding:.75rem .9rem;}
  .thumbs{display:flex;flex-wrap:wrap;gap:8px;}
  .thumbs img{width:120px;height:96px;object-fit:cover;border-radius:8px;border:1px solid var(--line);}
  .ev-note{font-size:.82rem;color:var(--ink3);}
  .signs{display:grid;grid-template-columns:1fr 1fr;gap:2.5rem;margin-top:2.6rem;}
  .sign{text-align:center;} .sign .ln{border-top:1.5px solid var(--ink2);margin:0 auto .35rem;padding-top:.35rem;}
  .sign .nm{font-weight:700;font-size:.86rem;color:var(--ink);text-transform:uppercase;letter-spacing:.4px;min-height:1.1em;}
  .sign .rl{font-size:.72rem;color:var(--ink3);margin-top:.1rem;}
  .foot-note{margin-top:1.6rem;font-size:.68rem;color:var(--ink3);line-height:1.5;border-top:1px dashed var(--line);padding-top:.7rem;}
  .missing{max-width:560px;margin:3rem auto;text-align:center;background:#fff;border:1px solid var(--border);border-radius:14px;padding:2.5rem 2rem;}
  .missing i{font-size:2rem;color:var(--gold);}
  @media(max-width:640px){.doc{padding:1.5rem 1.1rem;}.grid2{grid-template-columns:1fr;}.signs{grid-template-columns:1fr;gap:2rem;}}
  @media print{
    @page{size:A4 portrait;margin:14mm 14mm;}
    html,body{background:#fff !important;padding:0;margin:0;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .no-print{display:none !important;}
    .doc{box-shadow:none;border:none;border-radius:0;max-width:100%;width:100%;padding:0;margin:0;}
    /* Keep the formal two-column layout even when printing from a phone */
    .grid2{grid-template-columns:1fr 1fr !important;}
    .signs{grid-template-columns:1fr 1fr !important;gap:2.5rem !important;margin-top:2.2rem;}
    .sec,.signs,.issue-box,.thumbs{break-inside:avoid;page-break-inside:avoid;}
    .thumbs img{width:110px;height:88px;}
  }
</style>
</head>
<body>
  <div class="toolbar no-print">
    <a class="btn ghost" href="javascript:history.back()"><i class="fas fa-arrow-left"></i> Back</a>
    <?php if ($r): ?>
    <button class="btn gold" type="button" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button>
    <?php if (in_array($status, ['completed','verified','closed'], true)): ?>
    <a class="btn ghost" href="technician_service_report.php?report=<?php echo urlencode($reportId); ?>" target="_blank" rel="noopener"><i class="fas fa-file-lines"></i> Service report</a>
    <?php endif; ?>
    <?php endif; ?>
  </div>

<?php if (!$r): ?>
  <div class="missing">
    <i class="fas fa-file-circle-question"></i>
    <h2 style="font-family:'Fraunces',serif;margin:.6rem 0 .3rem;">Report not found</h2>
    <p style="font-size:.9rem;color:var(--ink3);">No report matches <strong><?php echo dt_e($reportId ?: '(none)'); ?></strong>. Open this page from a case with <code>?report=&lt;ID&gt;</code>.</p>
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
    <div class="doc-title">Equipment Defect Report</div>
    <div class="doc-title-sub">Reported concern — official ticket</div>

    <div class="refbar">
      <div class="ri">Report No. <b><?php echo dt_e($reportId); ?></b></div>
      <div class="ri"><span class="badge"><?php echo dt_e($statusLabel); ?></span></div>
      <div class="ri">Printed <?php echo dt_e($today); ?></div>
    </div>

    <!-- Reporter -->
    <div class="sec">
      <div class="sec-h"><i class="fas fa-user"></i> Reporter Information</div>
      <div class="grid2">
        <div class="kv"><span class="k">Name</span><span class="v"><?php echo dt_has($g('reporter_name'))?dt_e($g('reporter_name')):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Email</span><span class="v"><?php echo dt_has($g('reporter_email'))?dt_e($g('reporter_email')):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Department / Unit</span><span class="v"><?php echo dt_has($g('reporter_department'))?dt_e($g('reporter_department')):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Course / Program</span><span class="v"><?php echo dt_has($g('reporter_course'))?dt_e($g('reporter_course')):'<span class="muted">—</span>'; ?></span></div>
      </div>
    </div>

    <!-- Equipment -->
    <div class="sec">
      <div class="sec-h"><i class="fas fa-display"></i> Equipment &amp; Location</div>
      <div class="grid2">
        <div class="kv"><span class="k">Equipment</span><span class="v"><?php echo dt_has($eqName)?dt_e($eqName):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Asset tag</span><span class="v"><?php echo dt_has($assetTag)?dt_e($assetTag):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Category</span><span class="v"><?php echo dt_has($category)?dt_e($category):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Location</span><span class="v"><?php echo dt_has($g('location'))?dt_e($g('location')):'<span class="muted">—</span>'; ?></span></div>
      </div>
    </div>

    <!-- Defect -->
    <div class="sec">
      <div class="sec-h"><i class="fas fa-triangle-exclamation"></i> Defect Details</div>
      <div class="grid2" style="margin-bottom:.7rem;">
        <div class="kv"><span class="k">Priority</span><span class="v"><?php echo dt_has($g('priority'))?'<span class="prio '.dt_e(strtolower((string)$g('priority'))).'">'.dt_e(ucfirst((string)$g('priority'))).'</span>':'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Still usable?</span><span class="v"><?php echo $usInfo?'<span style="color:'.$usInfo[0].';font-weight:700;">'.dt_e($usInfo[1]).'</span>':'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Date reported</span><span class="v"><?php $d=$fmtDate($g('report_date'),true); echo $d?dt_e($d):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Responsible unit</span><span class="v"><?php echo dt_has($g('department_assigned'))?dt_e($g('department_assigned')):'<span class="muted">Not yet triaged</span>'; ?></span></div>
      </div>
      <div class="kv"><span class="k">Issue described</span></div>
      <div class="issue-box"><?php echo dt_has($g('issue_description'))?nl2br(dt_e($g('issue_description'))):'—'; ?></div>
    </div>

    <!-- Evidence -->
    <div class="sec">
      <div class="sec-h"><i class="fas fa-camera"></i> Photo &amp; Video Evidence</div>
      <?php if ($photos): ?>
      <div class="thumbs"><?php foreach (array_slice($photos, 0, 6) as $p): ?><img src="<?php echo dt_e($p); ?>" alt="evidence" onerror="this.style.display='none'"><?php endforeach; ?></div>
      <div class="ev-note" style="margin-top:.5rem;"><?php echo count($photos); ?> photo<?php echo count($photos)===1?'':'s'; ?><?php echo $videos?' · '.count($videos).' video'.(count($videos)===1?'':'s').' attached':''; ?>.</div>
      <?php elseif ($videos): ?>
      <div class="ev-note"><?php echo count($videos); ?> video<?php echo count($videos)===1?'':'s'; ?> attached (view online).</div>
      <?php else: ?>
      <div class="ev-note">No photo or video evidence was attached to this report.</div>
      <?php endif; ?>
    </div>

    <div class="signs">
      <div class="sign"><div class="ln"></div><div class="nm"><?php echo dt_has($g('reporter_name'))?dt_e($g('reporter_name')):'&nbsp;'; ?></div><div class="rl">Reported by</div></div>
      <div class="sign"><div class="ln"></div><div class="nm">&nbsp;</div><div class="rl">Received by — Property Management Office</div></div>
    </div>

    <div class="foot-note"><i class="fas fa-circle-info"></i> Official ticket generated from the BEC PMO Equipment Reporting &amp; Maintenance Management System. Report No. <?php echo dt_e($reportId); ?>.</div>
  </div>
<?php endif; ?>
</body>
</html>
