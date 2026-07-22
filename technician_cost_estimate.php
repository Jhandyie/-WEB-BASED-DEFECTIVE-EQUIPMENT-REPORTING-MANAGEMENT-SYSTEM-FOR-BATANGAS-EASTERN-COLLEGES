<?php
/**
 * technician_cost_estimate.php — Estimated Total Service Cost for a Repair Project.
 *
 * A fill-and-print worksheet following the DepEd TLE–Industrial Arts method:
 *   Total Service Cost = Materials + Labor (workers × daily wage × days) + Miscellaneous.
 * Client-side calculator; no DB writes — it produces a formal printable estimate.
 * Optional ?report=<ID> pre-fills the project reference from a defect report.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('technician');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/technician_guard.php';
require_once __DIR__ . '/config/database.php';
requireRole('technician');

function ce_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$techName = $_SESSION['fullname'] ?? 'Technician';
$reportId = trim((string)($_GET['report'] ?? ''));
$eqName = '';
$loc = '';
if ($reportId !== '') {
    try {
        $pdo = getPgsqlPdoConnection();
        $st = $pdo->prepare("SELECT equipment_name, location FROM defect_reports WHERE report_id = :r LIMIT 1");
        $st->execute(['r' => $reportId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) { $eqName = (string)($row['equipment_name'] ?? ''); $loc = (string)($row['location'] ?? ''); }
    } catch (\Throwable $e) { /* standalone use is fine */ }
}
$today = date('F j, Y');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Estimated Service Cost — BEC PMO</title>
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
  /* Document sheet */
  .doc{max-width:820px;margin:0 auto;background:var(--surface);border:1px solid var(--border);border-radius:6px;box-shadow:0 12px 40px rgba(44,10,10,.12);padding:2.4rem 2.6rem;}
  .doc-head{display:flex;align-items:center;gap:1rem;border-bottom:2.5px solid var(--maroon);padding-bottom:1rem;margin-bottom:.4rem;}
  .doc-seal{width:66px;height:66px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1.5px solid var(--gold);}
  .doc-org{flex:1;text-align:center;}
  .doc-org .o1{font-family:'Fraunces',serif;font-weight:700;font-size:1.2rem;color:var(--maroon-d);letter-spacing:.01em;}
  .doc-org .o2{font-size:.74rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--ink3);margin-top:.1rem;}
  .doc-org .o3{font-size:.64rem;color:var(--ink3);margin-top:.15rem;}
  .doc-title{text-align:center;font-family:'Fraunces',serif;font-weight:700;font-size:1.1rem;color:var(--ink);margin:1rem 0 .3rem;text-transform:uppercase;letter-spacing:.03em;}
  .doc-title-sub{text-align:center;font-size:.72rem;color:var(--ink3);margin-bottom:1.3rem;}
  /* meta grid */
  .meta{display:grid;grid-template-columns:1fr 1fr;gap:.5rem 1.6rem;margin-bottom:1.4rem;}
  .meta .m{display:flex;align-items:baseline;gap:.5rem;font-size:.84rem;}
  .meta .m label{font-weight:700;color:var(--ink2);white-space:nowrap;font-size:.76rem;text-transform:uppercase;letter-spacing:.5px;}
  .meta .m input{flex:1;min-width:0;border:none;border-bottom:1.5px dotted var(--line);background:transparent;font-size:.9rem;color:var(--ink);padding:.2rem .1rem;font-family:inherit;}
  .meta .m input:focus{outline:none;border-bottom-color:var(--maroon);}
  /* section */
  .sec{margin-bottom:1.3rem;}
  .sec-h{display:flex;align-items:center;gap:.55rem;font-family:'Fraunces',serif;font-weight:700;font-size:.98rem;color:var(--maroon-d);margin-bottom:.5rem;}
  .sec-h .ltr{width:24px;height:24px;border-radius:6px;background:linear-gradient(135deg,var(--maroon),var(--gold));color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;flex-shrink:0;}
  table{width:100%;border-collapse:collapse;font-size:.84rem;}
  th{text-align:left;background:#FBF7F0;color:var(--ink2);font-size:.66rem;text-transform:uppercase;letter-spacing:.5px;font-weight:800;padding:.5rem .6rem;border:1px solid var(--line);}
  th.num,td.num{text-align:right;}
  td{padding:.28rem .5rem;border:1px solid var(--line);vertical-align:middle;}
  td input{width:100%;border:none;background:transparent;font-size:.86rem;color:var(--ink);font-family:inherit;padding:.32rem .15rem;}
  td input:focus{outline:2px solid rgba(123,29,29,.18);border-radius:4px;background:#FFFDF9;}
  td input.num{text-align:right;}
  .amt{font-weight:700;color:var(--ink);white-space:nowrap;}
  .rm{border:none;background:none;color:#C4453B;cursor:pointer;font-size:.9rem;padding:.2rem .35rem;border-radius:5px;}
  .rm:hover{background:#FEECEA;}
  .add{margin-top:.5rem;display:inline-flex;align-items:center;gap:.4rem;padding:.42rem .8rem;border:1.5px dashed var(--line);border-radius:8px;background:#fff;color:var(--maroon);font-size:.78rem;font-weight:700;cursor:pointer;}
  .add:hover{border-color:var(--maroon);background:#FBF3EC;}
  .subtotal{text-align:right;font-size:.82rem;color:var(--ink2);margin-top:.45rem;}
  .subtotal b{color:var(--maroon-d);font-size:.92rem;margin-left:.4rem;}
  /* total */
  .total-box{margin-top:1.5rem;border:2px solid var(--maroon);border-radius:12px;background:linear-gradient(135deg,#FBF3EC,#fff);padding:1rem 1.3rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;}
  .total-box .tl{font-family:'Fraunces',serif;font-weight:700;font-size:1.05rem;color:var(--maroon-d);}
  .total-box .tv{font-family:'Fraunces',serif;font-weight:700;font-size:1.7rem;color:var(--maroon);}
  /* signatures */
  .signs{display:grid;grid-template-columns:1fr 1fr;gap:2.5rem;margin-top:2.6rem;}
  .sign{text-align:center;}
  .sign .ln{border-top:1.5px solid var(--ink2);margin:0 auto .35rem;padding-top:.35rem;}
  .sign .nm{font-weight:700;font-size:.86rem;color:var(--ink);text-transform:uppercase;letter-spacing:.4px;min-height:1.1em;}
  .sign .rl{font-size:.72rem;color:var(--ink3);margin-top:.1rem;}
  .foot-note{margin-top:1.6rem;font-size:.68rem;color:var(--ink3);line-height:1.5;border-top:1px dashed var(--line);padding-top:.7rem;}
  @media(max-width:640px){.doc{padding:1.5rem 1.1rem;}.meta{grid-template-columns:1fr;}.signs{grid-template-columns:1fr;gap:2rem;}.doc-org .o1{font-size:1rem;}}
  /* PRINT — clean formal document */
  @media print{
    body{background:#fff;padding:0;}
    .no-print{display:none !important;}
    .doc{box-shadow:none;border:none;border-radius:0;max-width:100%;padding:0;margin:0;}
    .add,.rm{display:none !important;}
    td input,.meta .m input{border:none;}
    th{background:#f2f2f2 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .total-box{background:#fff !important;}
    .sec-h .ltr,.doc-seal{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    a[href]:after{content:"";}
  }
</style>
</head>
<body>
  <div class="toolbar no-print">
    <a class="btn ghost" href="technician_dashboard.php"><i class="fas fa-arrow-left"></i> Back to workspace</a>
    <button class="btn gold" type="button" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button>
    <button class="btn ghost" type="button" onclick="if(confirm('Clear all entries?'))location.href='technician_cost_estimate.php<?php echo $reportId!==''?'?report='.urlencode($reportId):''; ?>'"><i class="fas fa-rotate-left"></i> Reset</button>
  </div>

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
    <div class="doc-title">Estimated Total Service Cost</div>
    <div class="doc-title-sub">for a Repair Project — Materials, Labor &amp; Miscellaneous</div>

    <div class="meta">
      <div class="m"><label>Project / Report</label><input type="text" value="<?php echo ce_e($reportId); ?>" placeholder="e.g. BEC-2026-000001"></div>
      <div class="m"><label>Date</label><input type="text" value="<?php echo ce_e($today); ?>"></div>
      <div class="m"><label>Equipment</label><input type="text" value="<?php echo ce_e($eqName); ?>" placeholder="Equipment / unit"></div>
      <div class="m"><label>Location</label><input type="text" value="<?php echo ce_e($loc); ?>" placeholder="Building / room"></div>
    </div>

    <!-- A. Materials -->
    <div class="sec">
      <div class="sec-h"><span class="ltr">A</span> Materials Cost</div>
      <table>
        <thead><tr><th style="width:44%">Material / Item</th><th class="num" style="width:14%">Qty</th><th class="num" style="width:20%">Unit Cost (₱)</th><th class="num" style="width:18%">Amount (₱)</th><th class="no-print" style="width:4%"></th></tr></thead>
        <tbody id="matRows"></tbody>
      </table>
      <button type="button" class="add no-print" data-add="mat"><i class="fas fa-plus"></i> Add material</button>
      <div class="subtotal">Materials subtotal: <b id="matSub">₱0.00</b></div>
    </div>

    <!-- B. Labor -->
    <div class="sec">
      <div class="sec-h"><span class="ltr">B</span> Labor Cost <span style="font-family:'DM Sans';font-weight:600;font-size:.7rem;color:var(--ink3);">(workers × daily wage × days)</span></div>
      <table>
        <thead><tr><th style="width:34%">Role / Worker</th><th class="num" style="width:15%">Workers</th><th class="num" style="width:19%">Daily Wage (₱)</th><th class="num" style="width:12%">Days</th><th class="num" style="width:16%">Amount (₱)</th><th class="no-print" style="width:4%"></th></tr></thead>
        <tbody id="labRows"></tbody>
      </table>
      <button type="button" class="add no-print" data-add="lab"><i class="fas fa-plus"></i> Add labor</button>
      <div class="subtotal">Labor subtotal: <b id="labSub">₱0.00</b></div>
    </div>

    <!-- C. Miscellaneous -->
    <div class="sec">
      <div class="sec-h"><span class="ltr">C</span> Miscellaneous Expenses <span style="font-family:'DM Sans';font-weight:600;font-size:.7rem;color:var(--ink3);">(tools, PPE, transport, etc.)</span></div>
      <table>
        <thead><tr><th style="width:78%">Description</th><th class="num" style="width:18%">Amount (₱)</th><th class="no-print" style="width:4%"></th></tr></thead>
        <tbody id="miscRows"></tbody>
      </table>
      <button type="button" class="add no-print" data-add="misc"><i class="fas fa-plus"></i> Add expense</button>
      <div class="subtotal">Miscellaneous subtotal: <b id="miscSub">₱0.00</b></div>
    </div>

    <div class="total-box">
      <span class="tl">Total Service Cost <span style="font-family:'DM Sans';font-weight:600;font-size:.72rem;color:var(--ink3);">(A + B + C)</span></span>
      <span class="tv" id="grand">₱0.00</span>
    </div>

    <div class="signs">
      <div class="sign"><div class="ln"></div><div class="nm"><?php echo ce_e($techName); ?></div><div class="rl">Prepared by — Maintenance Technician</div></div>
      <div class="sign"><div class="ln"></div><div class="nm">&nbsp;</div><div class="rl">Noted by — Property Management Office</div></div>
    </div>

    <div class="foot-note"><i class="fas fa-circle-info"></i> This is an <strong>estimate</strong> for planning and budgeting purposes. Total Service Cost = Materials + Labor + Miscellaneous, following the standard repair cost-estimation method. Final billing may vary with actual materials and labor rendered.</div>
  </div>

<script>
(function () {
  var peso = function (n) { return '₱' + (isFinite(n) ? n : 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var num = function (v) { v = parseFloat(String(v).replace(/[^0-9.]/g, '')); return isFinite(v) ? v : 0; };
  var money = 'type="text" inputmode="decimal" class="num" oninput="this.value=this.value.replace(/[^0-9.]/g,\'\').replace(/(\\d*\\.\\d{0,2}).*/,\'$1\');recompute();"';
  var count = 'type="text" inputmode="numeric" class="num" oninput="this.value=this.value.replace(/[^0-9]/g,\'\');recompute();"';

  var tpl = {
    mat: '<tr><td><input type="text" placeholder="e.g. Thermal paste"></td><td><input ' + count + ' placeholder="0"></td><td><input ' + money + ' placeholder="0.00"></td><td class="num amt" data-amt>₱0.00</td><td class="no-print"><button type="button" class="rm" data-rm><i class="fas fa-xmark"></i></button></td></tr>',
    lab: '<tr><td><input type="text" placeholder="e.g. Technician"></td><td><input ' + count + ' placeholder="1"></td><td><input ' + money + ' placeholder="0.00"></td><td><input ' + count + ' placeholder="1"></td><td class="num amt" data-amt>₱0.00</td><td class="no-print"><button type="button" class="rm" data-rm><i class="fas fa-xmark"></i></button></td></tr>',
    misc: '<tr><td><input type="text" placeholder="e.g. Transportation"></td><td><input ' + money + ' placeholder="0.00"></td><td class="num amt" data-amt>₱0.00</td><td class="no-print"><button type="button" class="rm" data-rm><i class="fas fa-xmark"></i></button></td></tr>'
  };
  var bodies = { mat: document.getElementById('matRows'), lab: document.getElementById('labRows'), misc: document.getElementById('miscRows') };

  function addRow(type) { bodies[type].insertAdjacentHTML('beforeend', tpl[type]); }

  function recompute() {
    var matSub = 0, labSub = 0, miscSub = 0;
    bodies.mat.querySelectorAll('tr').forEach(function (r) {
      var i = r.querySelectorAll('input'); var a = num(i[1].value) * num(i[2].value);
      r.querySelector('[data-amt]').textContent = peso(a); matSub += a;
    });
    bodies.lab.querySelectorAll('tr').forEach(function (r) {
      var i = r.querySelectorAll('input'); var a = num(i[1].value) * num(i[2].value) * num(i[3].value);
      r.querySelector('[data-amt]').textContent = peso(a); labSub += a;
    });
    bodies.misc.querySelectorAll('tr').forEach(function (r) {
      var i = r.querySelectorAll('input'); var a = num(i[1].value);
      r.querySelector('[data-amt]').textContent = peso(a); miscSub += a;
    });
    document.getElementById('matSub').textContent = peso(matSub);
    document.getElementById('labSub').textContent = peso(labSub);
    document.getElementById('miscSub').textContent = peso(miscSub);
    document.getElementById('grand').textContent = peso(matSub + labSub + miscSub);
  }
  window.recompute = recompute;

  document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-add]'); if (add) { addRow(add.getAttribute('data-add')); return; }
    var rm = e.target.closest('[data-rm]');
    if (rm) { var tb = rm.closest('tbody'); rm.closest('tr').remove(); if (!tb.querySelector('tr')) addRow({ matRows: 'mat', labRows: 'lab', miscRows: 'misc' }[tb.id]); recompute(); }
  });

  // start each section with one blank row
  addRow('mat'); addRow('lab'); addRow('misc'); recompute();
})();
</script>
</body>
</html>
