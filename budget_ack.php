<?php
/**
 * budget_ack.php — Finance acknowledgment page (public, token-based, no login).
 * Finance opens the link from the notification email to mark a budget request
 * as Received or Accepted. Notifies the technician and the PMO on action.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/budget_finance.php';
require_once __DIR__ . '/includes/audit.php';

$conn  = getDBConnection();
$token = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));
$req   = null;
$items = [];
$done  = '';
$error = '';

if ($token !== '') {
    $stmt = $conn->prepare("SELECT b.request_id, b.report_id, b.technician_id, b.justification, b.total_cost,
            b.status, b.finance_status, b.finance_notified_at, b.finance_ack_at,
            COALESCE(u.fullname, b.technician_id) AS technician_name
        FROM budget_requests b LEFT JOIN users u ON u.user_id = b.technician_id
        WHERE b.finance_token = ? LIMIT 1");
    if ($stmt) { $stmt->bind_param('s', $token); $stmt->execute(); $req = $stmt->get_result()->fetch_assoc(); $stmt->close(); }
    if ($req) { $items = bfLoadItems($conn, (string)$req['request_id']); }
}
if ($token === '' || !$req) {
    $error = 'This acknowledgment link is invalid or has expired. Please ask the PMO to resend it.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $req) {
    $act = (string)($_POST['act'] ?? '');
    if (in_array($act, ['received', 'accepted'], true)
        && !((string)$req['finance_status'] === 'accepted' && $act === 'received')) {
        $upd = $conn->prepare("UPDATE budget_requests SET finance_status = ?, finance_ack_at = NOW() WHERE finance_token = ?");
        if ($upd) { $upd->bind_param('ss', $act, $token); $upd->execute(); $upd->close(); }

        // Notify the technician + PMO/admins.
        $recipients = [];
        if (!empty($req['technician_id'])) { $recipients[] = (string)$req['technician_id']; }
        $ares = $conn->query("SELECT user_id FROM users WHERE role IN ('admin','pmo') AND status = 'active'");
        if ($ares) { while ($a = $ares->fetch_assoc()) { $recipients[] = (string)$a['user_id']; } }
        $verb = $act === 'accepted' ? 'ACCEPTED' : 'marked as RECEIVED';
        $msg  = 'Finance ' . $verb . ' budget request ' . $req['request_id'] . '.';
        $ns = $conn->prepare("INSERT INTO notifications (notification_id, user_id, message, type, related_id, created_date) VALUES (?, ?, ?, 'budget_finance', ?, NOW())");
        if ($ns) {
            foreach (array_unique(array_filter($recipients)) as $uid) {
                $nid = 'NTF-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
                $ns->bind_param('ssss', $nid, $uid, $msg, $req['request_id']);
                $ns->execute();
            }
            $ns->close();
        }
        if (function_exists('logActivity')) { try { logActivity('finance', 'budget.finance_' . $act, 'Finance ' . $verb . ' ' . $req['request_id']); } catch (\Throwable $e) {} }

        $req['finance_status'] = $act;
        $req['finance_ack_at'] = date('Y-m-d H:i:s');
        $done = $verb;
    }
}

function ae($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function adt($v){ $t = strtotime((string)$v); return $t ? date('M d, Y h:i A', $t) : '—'; }
$fstat = (string)($req['finance_status'] ?? '');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="theme-color" content="#4A0E0E">
<title>Budget Request Acknowledgment — BEC PMO</title>
<link rel="icon" type="image/png" href="assets/logs.png">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  :root{--m:#7B1D1D;--md:#4A0E0E;--mdd:#2D0505;--g:#C9960C;--gold-soft:#F0C040;--ink:#1C1008;--ink2:#5C3838;--ink3:#9E8070;--paper:#F8F3EA;--surface:#fff;--border:#E8DDD0;--ok:#1A7A33;--info:#1F6F8B;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:'DM Sans',system-ui,Arial,sans-serif;color:var(--ink);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.4rem;
    background:radial-gradient(120% 90% at 100% 0%,rgba(201,150,12,.08),transparent 50%),var(--paper);}
  .card{width:100%;max-width:600px;background:var(--surface);border:1px solid var(--border);border-radius:20px;overflow:hidden;box-shadow:0 24px 70px rgba(44,10,10,.16);}
  .head{position:relative;background:linear-gradient(135deg,var(--mdd),var(--m));color:#fff;padding:1.6rem 1.8rem;}
  .head .k{font-size:.62rem;letter-spacing:1.6px;text-transform:uppercase;color:var(--gold-soft);font-weight:800;}
  .head h1{font-family:'Fraunces',serif;font-weight:700;font-size:1.4rem;margin-top:.3rem;}
  .body{padding:1.7rem 1.8rem;}
  .rid{font-weight:700;color:var(--m);font-size:1rem;}
  .metaline{font-size:.82rem;color:var(--ink3);margin:.25rem 0 1.1rem;}
  .st{display:inline-flex;align-items:center;gap:.4rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:.3rem .7rem;border-radius:20px;}
  .st.awaiting{background:#FFF7E6;color:#9A6A00;border:1px solid #F0D79A;}
  .st.received{background:#EAF4F7;color:var(--info);border:1px solid #CBE4EC;}
  .st.accepted{background:#EEF7F0;color:var(--ok);border:1px solid #CFE9D6;}
  table{width:100%;border-collapse:collapse;margin:.5rem 0 1rem;font-size:.85rem;}
  th{text-align:left;background:var(--paper);color:var(--ink2);padding:.5rem .6rem;font-size:.68rem;text-transform:uppercase;letter-spacing:.4px;}
  td{padding:.5rem .6rem;border-bottom:1px solid var(--border);}
  tfoot td{font-weight:700;border-top:2px solid var(--border);}
  .just{font-size:.84rem;color:var(--ink2);background:var(--paper);border-left:3px solid var(--g);border-radius:8px;padding:.7rem .85rem;margin:.6rem 0 1rem;line-height:1.5;}
  .actions{display:flex;gap:.7rem;flex-wrap:wrap;margin-top:1.2rem;}
  .btn{flex:1;min-width:150px;border:none;border-radius:12px;padding:.85rem 1rem;font-family:inherit;font-weight:700;font-size:.9rem;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:.5rem;}
  .btn.recv{background:#EAF4F7;color:var(--info);border:1.5px solid #CBE4EC;}
  .btn.recv:hover{background:#dcedf2;}
  .btn.acc{background:linear-gradient(135deg,var(--md),var(--m));color:#fff;box-shadow:0 4px 0 var(--mdd);}
  .btn.acc:hover{filter:brightness(1.06);}
  .done{display:flex;gap:.7rem;align-items:flex-start;padding:.9rem 1.1rem;border-radius:12px;font-size:.9rem;line-height:1.5;margin-bottom:1.1rem;}
  .done.ok{background:#EEF7F0;border:1px solid #CFE9D6;color:var(--ok);}
  .state{text-align:center;padding:1.6rem .5rem;}
  .state .ic{width:72px;height:72px;border-radius:50%;background:#FEF6EC;color:#B77400;display:flex;align-items:center;justify-content:center;font-size:1.9rem;margin:0 auto 1rem;}
  .state h2{font-family:'Fraunces',serif;color:var(--m);margin-bottom:.4rem;}
  .state p{color:var(--ink2);font-size:.9rem;line-height:1.6;max-width:38ch;margin:0 auto;}
  .foot{background:var(--paper);border-top:1px solid var(--border);padding:.9rem 1.8rem;font-size:.7rem;color:var(--ink3);text-align:center;}
</style>
</head>
<body>
  <div class="card">
    <div class="head">
      <div class="k">Batangas Eastern Colleges · Property Management Office</div>
      <h1>Budget Request — Finance Acknowledgment</h1>
    </div>
    <div class="body">
      <?php if (!$req): ?>
        <div class="state">
          <div class="ic"><i class="fas fa-triangle-exclamation"></i></div>
          <h2>Link unavailable</h2>
          <p><?php echo ae($error); ?></p>
        </div>
      <?php else: ?>
        <?php if ($done): ?>
          <div class="done ok"><i class="fas fa-circle-check" style="margin-top:.1rem;"></i>
            <span>Thank you. This request has been <strong><?php echo ae($done); ?></strong>. The PMO and the technician have been notified.</span></div>
        <?php endif; ?>

        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
          <div>
            <div class="rid"><i class="fas fa-coins"></i> <?php echo ae($req['request_id']); ?></div>
            <div class="metaline">Requested by <strong><?php echo ae($req['technician_name']); ?></strong><?php if (!empty($req['report_id'])): ?> · Report <?php echo ae($req['report_id']); ?><?php endif; ?></div>
          </div>
          <span class="st <?php echo ae($fstat ?: 'awaiting'); ?>"><i class="fas fa-building-columns"></i> <?php echo ae(bfFinanceStatusLabel($fstat)); ?></span>
        </div>

        <table>
          <thead><tr><th>Part / Material</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Est. Cost</th><th>Supplier</th><th style="text-align:right;">Subtotal</th></tr></thead>
          <tbody>
            <?php if (!$items): ?><tr><td colspan="5" style="color:var(--ink3);">No line items.</td></tr><?php endif; ?>
            <?php foreach ($items as $it): $sub = (float)$it['quantity'] * (float)$it['estimated_cost']; ?>
              <tr>
                <td><?php echo ae($it['part_needed']); ?></td>
                <td style="text-align:center;"><?php echo (int)$it['quantity']; ?></td>
                <td style="text-align:right;">&#8369;<?php echo number_format((float)$it['estimated_cost'], 2); ?></td>
                <td><?php echo ae($it['supplier'] ?: '—'); ?></td>
                <td style="text-align:right;">&#8369;<?php echo number_format($sub, 2); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td colspan="4" style="text-align:right;">Total</td><td style="text-align:right;color:var(--m);">&#8369;<?php echo number_format((float)$req['total_cost'], 2); ?></td></tr></tfoot>
        </table>

        <?php if (trim((string)$req['justification']) !== ''): ?>
          <div class="just"><strong>Justification:</strong> <?php echo ae($req['justification']); ?></div>
        <?php endif; ?>

        <?php if ($fstat === 'accepted'): ?>
          <p style="font-size:.84rem;color:var(--ink3);"><i class="fas fa-circle-check" style="color:var(--ok);"></i> This request was accepted by Finance on <?php echo adt($req['finance_ack_at']); ?>.</p>
        <?php else: ?>
          <form method="post" action="budget_ack.php?token=<?php echo ae($token); ?>">
            <input type="hidden" name="token" value="<?php echo ae($token); ?>">
            <div class="actions">
              <?php if ($fstat !== 'received'): ?>
                <button class="btn recv" type="submit" name="act" value="received"><i class="fas fa-inbox"></i> Mark as Received</button>
              <?php endif; ?>
              <button class="btn acc" type="submit" name="act" value="accepted"><i class="fas fa-circle-check"></i> Accept Request</button>
            </div>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="foot">Batangas Eastern Colleges · Property Management Office · Secure acknowledgment link</div>
  </div>
<?php require __DIR__ . '/includes/site_transitions.php'; ?>
</body>
</html>
