<?php
/**
 * technician_service_report.php — formal, printable Equipment Repair / Service Report.
 *
 * Read-only: pulls a defect report's full record and renders a professional
 * printable document (same formal header as the cost estimate). Reachable by
 * the technician who did the job and by admins (requireRole('technician') —
 * admins bypass). Requires ?report=<ID>.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
// Reachable by the technician who did the job and by admins. The page name would
// force the 'technician' session context, so pick the session that's actually present.
startRoleSession(isset($_COOKIE['BECSESSID_ADMIN']) ? 'admin' : 'technician');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
requireRole('technician'); // admins bypass this in requireRole()

function sr_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function sr_has($v) { return trim((string)$v) !== ''; }
function sr_peso($v) { $v = (float)$v; return $v > 0 ? '₱' . number_format($v, 2) : ''; }

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
        // fall back to a simpler query if the join fails
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
$statusLabels = ['reported'=>'Pending','pmo_review'=>'Received by PMO','ready_for_assignment'=>'Ready to Assign','assigned'=>'Assigned','accepted'=>'Accepted','in_progress'=>'In Progress','completed'=>'Completed','verified'=>'Verified','closed'=>'Closed','rejected'=>'Rejected'];
$status = strtolower((string)$g('status'));
$statusLabel = $statusLabels[$status] ?? ucfirst(str_replace('_',' ',$status));

$eqName   = (string)($r['eq_name'] ?? $g('equipment_name'));
$assetTag = (string)($r['eq_asset_tag'] ?? $g('asset_tag'));
$category = (string)($r['eq_category'] ?? $g('category'));
$estCost  = sr_peso($g('estimated_cost'));
$toolsMaterials = trim(implode(' · ', array_filter([trim((string)$g('tools_used')), trim((string)$g('materials_used'))])));

// Service detail rows (label => value) — only rendered when present.
$serviceRows = array_filter([
    'Diagnosis'           => (string)$g('diagnosis'),
    'Work performed'      => (string)$g('work_performed'),
    'Actions performed'   => (string)$g('actions_performed'),
    'Repair procedures'   => (string)$g('repair_procedures'),
    'Parts replaced'      => (string)$g('parts_replaced'),
    'Tools & materials'   => $toolsMaterials,
], 'sr_has');

$today = date('F j, Y');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Service Report<?php echo $reportId !== '' ? ' — ' . sr_e($reportId) : ''; ?> — BEC PMO</title>
<link rel="icon" type="image/png" href="assets/logs.png">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
</head>
<body>
  <div class="toolbar no-print">
    <a class="btn ghost" href="javascript:history.back()"><i class="fas fa-arrow-left"></i> Back</a>
    <?php if ($r): ?>
    <button class="btn gold" type="button" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button>
    <a class="btn ghost" href="technician_cost_estimate.php?report=<?php echo urlencode($reportId); ?>" target="_blank" rel="noopener"><i class="fas fa-file-invoice-dollar"></i> Cost estimate</a>
    <?php endif; ?>
  </div>

<?php if (!$r): ?>
  <div class="missing">
    <i class="fas fa-file-circle-question"></i>
    <h2 style="font-family:'Fraunces',serif;margin:.6rem 0 .3rem;">Report not found</h2>
    <p style="font-size:.9rem;color:var(--ink3);">No repair record matches <strong><?php echo sr_e($reportId ?: '(none)'); ?></strong>. Open this page from a case with <code>?report=&lt;ID&gt;</code>.</p>
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
    <div class="doc-title">Equipment Repair / Service Report</div>
    <div class="doc-title-sub">Record of completed maintenance work</div>

    <div class="refbar">
      <div class="ri">Report No. <b><?php echo sr_e($reportId); ?></b></div>
      <div class="ri"><span class="badge <?php echo in_array($status,['completed','verified','closed'],true)?'done':''; ?>"><?php echo sr_e($statusLabel); ?></span></div>
      <div class="ri">Printed <?php echo sr_e($today); ?></div>
    </div>

    <!-- Equipment -->
    <div class="sec">
      <div class="sec-h"><i class="fas fa-wrench"></i> Equipment &amp; Location</div>
      <div class="grid2">
        <div class="kv"><span class="k">Equipment</span><span class="v"><?php echo sr_has($eqName)?sr_e($eqName):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Asset tag</span><span class="v"><?php echo sr_has($assetTag)?sr_e($assetTag):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Category</span><span class="v"><?php echo sr_has($category)?sr_e($category):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Location</span><span class="v"><?php echo sr_has($g('location'))?sr_e($g('location')):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Responsible unit</span><span class="v"><?php echo sr_has($g('department_assigned'))?sr_e($g('department_assigned')):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Priority</span><span class="v"><?php echo sr_has($g('priority'))?sr_e(ucfirst((string)$g('priority'))):'<span class="muted">—</span>'; ?></span></div>
      </div>
    </div>

    <!-- Request -->
    <div class="sec">
      <div class="sec-h"><i class="fas fa-clipboard-list"></i> Reported Concern</div>
      <div class="grid2" style="margin-bottom:.6rem;">
        <div class="kv"><span class="k">Reported by</span><span class="v"><?php echo sr_has($g('reporter_name'))?sr_e($g('reporter_name')):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Date reported</span><span class="v"><?php $d=$fmtDate($g('report_date'),true); echo $d?sr_e($d):'<span class="muted">—</span>'; ?></span></div>
      </div>
      <div class="kv"><span class="k">Issue described</span><span class="v prose"><?php echo sr_has($g('issue_description'))?nl2br(sr_e($g('issue_description'))):'<span class="muted">—</span>'; ?></span></div>
    </div>

    <!-- Service performed -->
    <div class="sec">
      <div class="sec-h"><i class="fas fa-screwdriver-wrench"></i> Service Performed</div>
      <?php if ($serviceRows): foreach ($serviceRows as $k => $v): ?>
      <div class="srv-row"><div class="k"><?php echo sr_e($k); ?></div><div class="v"><?php echo nl2br(sr_e($v)); ?></div></div>
      <?php endforeach; else: ?>
      <div class="prose" style="color:var(--ink3);">No service details have been recorded for this case yet.</div>
      <?php endif; ?>
    </div>

    <!-- Timeline & cost -->
    <div class="sec">
      <div class="sec-h"><i class="fas fa-clock"></i> Timeline<?php echo sr_has($estCost)?' &amp; Cost':''; ?></div>
      <div class="grid2">
        <div class="kv"><span class="k">Date started</span><span class="v"><?php $d=$fmtDate($g('date_started'),true); echo $d?sr_e($d):'<span class="muted">—</span>'; ?></span></div>
        <div class="kv"><span class="k">Date completed</span><span class="v"><?php $d=$fmtDate($g('completion_date'),true); echo $d?sr_e($d):'<span class="muted">—</span>'; ?></span></div>
        <?php if (sr_has($g('repair_duration'))): ?>
        <div class="kv"><span class="k">Duration</span><span class="v"><?php echo sr_e($g('repair_duration')); ?></span></div>
        <?php endif; ?>
      </div>
      <?php if (sr_has($estCost)): ?>
      <div class="cost-box"><span class="cl">Estimated service cost</span><span class="cv"><?php echo sr_e($estCost); ?></span></div>
      <?php endif; ?>
    </div>

    <div class="signs">
      <div class="sign"><div class="ln"></div><div class="nm"><?php echo sr_has($g('technician_name'))?sr_e($g('technician_name')):'&nbsp;'; ?></div><div class="rl">Serviced by — Maintenance Technician</div></div>
      <div class="sign"><div class="ln"></div><div class="nm">&nbsp;</div><div class="rl">Verified by — Property Management Office</div></div>
    </div>

    <div class="foot-note"><i class="fas fa-circle-info"></i> Official record generated from the BEC PMO Equipment Reporting &amp; Maintenance Management System. Report No. <?php echo sr_e($reportId); ?>.</div>
  </div>
<?php endif; ?>
</body>
</html>
