<?php
/**
 * approval_ack.php — Dean / Finance decision page (public, token-based, no login).
 * Opened from the decision-request email. Shows the report and records the
 * office's own Approve / Reject (Dean) or Approve / Hold (Finance) decision.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/workflow_approvals.php';
require_once __DIR__ . '/includes/audit.php';

$conn  = getDBConnection();
$token = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));
$report = null;
$stage = '';
$done = '';
$error = '';

if ($token !== '') {
    $st = $conn->prepare("SELECT dr.report_id, dr.status, dr.priority, dr.issue_description, dr.report_date,
                                 dr.approval_stage, dr.approval_token, dr.admin_notes,
                                 e.equipment_name, e.asset_tag, COALESCE(NULLIF(e.location,''),'Unspecified') AS location
                          FROM defect_reports dr
                          JOIN equipment e ON e.equipment_id = dr.equipment_id
                          WHERE dr.approval_token = ? LIMIT 1");
    if ($st) { $st->bind_param('s', $token); $st->execute(); $report = $st->get_result()->fetch_assoc(); $st->close(); }
    if ($report) { $stage = strtolower((string)($report['approval_stage'] ?? '')); }
}
if (!$report || !in_array($stage, ['dean', 'finance'], true)) {
    $error = 'This decision link is invalid, already used, or has been superseded. Please contact the Property Management Office.';
    $report = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $report) {
    $act   = (string)($_POST['act'] ?? '');
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($stage === 'dean' && in_array($act, ['approve', 'reject'], true)) {
        [$ok, $msg] = wfaDeanDecide($conn, $report, $act === 'approve', $notes);
        if ($ok) { $done = $msg; } else { $error = $msg; $report = null; }
    } elseif ($stage === 'finance' && in_array($act, ['approve', 'hold'], true)) {
        [$ok, $msg] = wfaFinanceDecide($conn, $report, $act === 'approve', $notes);
        if ($ok) { $done = $msg; } else { $error = $msg; $report = null; }
    }
}

function we($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$officeLabel = $stage === 'dean' ? BEC_DEAN_LABEL : BEC_FINANCE_LABEL;
$pageTitle   = $stage === 'dean' ? 'Dean Approval' : 'Finance Budget Clearance';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="theme-color" content="#4A0E0E">
<title>Report Decision — BEC PMO</title>
<link rel="icon" type="image/png" href="assets/logs.png">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  :root{--m:#7B1D1D;--md:#4A0E0E;--mdd:#2D0505;--g:#C9960C;--gold-soft:#F0C040;--ink:#1C1008;--ink2:#5C3838;--ink3:#9E8070;--paper:#F8F3EA;--surface:#fff;--border:#E8DDD0;--ok:#1A7A33;--warn:#9A6A00;--bad:#B42318;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:'DM Sans',system-ui,Arial,sans-serif;color:var(--ink);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.4rem;
    background:radial-gradient(120% 90% at 100% 0%,rgba(201,150,12,.08),transparent 50%),var(--paper);}
  .card{width:100%;max-width:600px;background:var(--surface);border:1px solid var(--border);border-radius:20px;overflow:hidden;box-shadow:0 24px 70px rgba(44,10,10,.16);}
  .head{background:linear-gradient(135deg,var(--mdd),var(--m));color:#fff;padding:1.6rem 1.8rem;}
  .head .k{font-size:.62rem;letter-spacing:1.6px;text-transform:uppercase;color:var(--gold-soft);font-weight:800;}
  .head h1{font-family:'Fraunces',serif;font-weight:700;font-size:1.35rem;margin-top:.3rem;}
  .body{padding:1.7rem 1.8rem;}
  .rid{font-weight:800;color:var(--m);font-size:1rem;}
  .metaline{font-size:.82rem;color:var(--ink3);margin:.25rem 0 1rem;}
  .facts{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:1rem;}
  .facts>div{padding:10px 12px;border-radius:11px;border:1px solid var(--border);background:#FBF9F6;}
  .facts small{display:block;font-size:.58rem;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--ink3);margin-bottom:3px;}
  .facts strong{font-size:.84rem;color:var(--ink);font-weight:600;}
  .issue{font-size:.87rem;color:var(--ink2);background:#FBF9F6;border-left:3px solid var(--g);border-radius:8px;padding:.8rem .95rem;line-height:1.6;margin-bottom:1.1rem;}
  label{display:block;font-size:.7rem;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--ink2);margin:0 0 .35rem;}
  textarea{width:100%;min-height:84px;padding:.7rem .85rem;border:1.5px solid var(--border);border-radius:11px;font:inherit;font-size:16px;resize:vertical;}
  textarea:focus{outline:none;border-color:var(--m);box-shadow:0 0 0 3px rgba(123,29,29,.08);}
  .actions{display:flex;gap:.7rem;flex-wrap:wrap;margin-top:1.1rem;}
  .btn{flex:1;min-width:160px;border:none;border-radius:12px;padding:.9rem 1rem;font-family:inherit;font-weight:800;font-size:.92rem;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:.5rem;color:#fff;}
  .btn.ok{background:linear-gradient(135deg,#0C6B2E,var(--ok));box-shadow:0 4px 0 #07461D;}
  .btn.no{background:linear-gradient(135deg,#8F1A12,var(--bad));box-shadow:0 4px 0 #5E0F0A;}
  .btn.hold{background:linear-gradient(135deg,#7C5205,var(--warn));box-shadow:0 4px 0 #57390A;}
  .btn:hover{filter:brightness(1.07);}
  .state{text-align:center;padding:1.6rem .5rem;}
  .state .ic{width:74px;height:74px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2rem;margin:0 auto 1rem;}
  .state.ok .ic{background:#EEF7F0;color:var(--ok);}
  .state.warn .ic{background:#FEF6EC;color:#B77400;}
  .state h2{font-family:'Fraunces',serif;color:var(--m);margin-bottom:.45rem;}
  .state p{color:var(--ink2);font-size:.92rem;line-height:1.65;max-width:42ch;margin:0 auto;}
  .foot{background:var(--paper);border-top:1px solid var(--border);padding:.9rem 1.8rem;font-size:.7rem;color:var(--ink3);text-align:center;}
  .who{display:inline-flex;align-items:center;gap:.45rem;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.3);color:#fff;font-size:.7rem;font-weight:700;padding:.35rem .8rem;border-radius:18px;margin-top:.7rem;}
  .who i{color:var(--gold-soft);}
  @media(max-width:480px){.facts{grid-template-columns:1fr;}.btn{min-width:100%;}}
</style>
</head>
<body>
  <div class="card">
    <div class="head">
      <div class="k">Batangas Eastern Colleges · Property Management Office</div>
      <h1><?php echo $report || $done ? we($pageTitle) : 'Report Decision'; ?></h1>
      <?php if ($report || $done): ?><span class="who"><i class="fas fa-building-columns"></i> Acting as: <?php echo we($officeLabel); ?></span><?php endif; ?>
    </div>
    <div class="body">
      <?php if ($done !== ''): ?>
        <div class="state ok">
          <div class="ic"><i class="fas fa-circle-check"></i></div>
          <h2>Decision recorded</h2>
          <p><?php echo we($done); ?></p>
          <p style="margin-top:.8rem;font-size:.8rem;color:var(--ink3);">You may close this page. The PMO and the reporter are notified automatically.</p>
        </div>

      <?php elseif (!$report): ?>
        <div class="state warn">
          <div class="ic"><i class="fas fa-triangle-exclamation"></i></div>
          <h2>Link unavailable</h2>
          <p><?php echo we($error); ?></p>
        </div>

      <?php else: ?>
        <div class="rid"><i class="fas fa-clipboard-list"></i> Report <?php echo we($report['report_id']); ?></div>
        <div class="metaline">Filed <?php echo we(date('M d, Y h:i A', strtotime((string)$report['report_date']))); ?> · currently at the <strong><?php echo $stage === 'dean' ? 'Dean approval' : 'Finance clearance'; ?></strong> stage</div>
        <div class="facts">
          <div><small>Equipment</small><strong><?php echo we($report['equipment_name'] ?: '—'); ?></strong></div>
          <div><small>Asset Tag</small><strong><?php echo we($report['asset_tag'] ?: '—'); ?></strong></div>
          <div><small>Location</small><strong><?php echo we($report['location']); ?></strong></div>
          <div><small>Priority</small><strong><?php echo we(ucfirst((string)($report['priority'] ?: 'medium'))); ?></strong></div>
        </div>
        <div class="issue"><strong>Reported issue:</strong> <?php echo nl2br(we($report['issue_description'] ?: 'No description recorded.')); ?></div>
        <?php if (trim((string)$report['admin_notes']) !== ''): ?>
        <div class="issue" style="border-left-color:#7B1D1D;"><strong>PMO endorsement notes:</strong> <?php echo nl2br(we($report['admin_notes'])); ?></div>
        <?php endif; ?>

        <form method="post" action="approval_ack.php?token=<?php echo we($token); ?>">
          <input type="hidden" name="token" value="<?php echo we($token); ?>">
          <label for="notes">Remarks (optional — recorded with your decision)</label>
          <textarea id="notes" name="notes" placeholder="<?php echo $stage === 'dean' ? 'e.g. Approved; prioritize before enrollment week.' : 'e.g. Charge to Q3 maintenance fund.'; ?>"></textarea>
          <div class="actions">
            <?php if ($stage === 'dean'): ?>
              <button class="btn ok" type="submit" name="act" value="approve"><i class="fas fa-check"></i> Approve Report</button>
              <button class="btn no" type="submit" name="act" value="reject"><i class="fas fa-xmark"></i> Reject</button>
            <?php else: ?>
              <button class="btn ok" type="submit" name="act" value="approve"><i class="fas fa-check"></i> Approve Budget</button>
              <button class="btn hold" type="submit" name="act" value="hold"><i class="fas fa-pause"></i> Hold — No Funds Yet</button>
            <?php endif; ?>
          </div>
        </form>
      <?php endif; ?>
    </div>
    <div class="foot">Secure one-time decision link · Batangas Eastern Colleges · Property Management Office</div>
  </div>
</body>
</html>
