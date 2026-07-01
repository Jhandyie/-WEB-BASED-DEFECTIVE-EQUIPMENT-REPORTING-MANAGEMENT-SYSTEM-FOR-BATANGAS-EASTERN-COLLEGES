<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';

requireRole('admin');

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['fullname'] ?? 'Administrator';
$conn = getDBConnection();

function be($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function bdt($v): string { $t = strtotime((string)$v); return $t ? date('M d, Y h:i A', $t) : '—'; }
function peso($v): string { return '₱' . number_format((float)$v, 2); }

/* ── POST: approve / reject ─────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['action'] ?? '';
    $reqId = trim((string)($_POST['request_id'] ?? ''));
    $notes = trim((string)($_POST['admin_notes'] ?? ''));

    if ($reqId !== '' && in_array($act, ['approve', 'reject'], true)) {
        $newStatus = $act === 'approve' ? 'approved' : 'rejected';

        // Fetch the request (for technician notification).
        $stmt = $conn->prepare("SELECT technician_id, report_id, total_cost FROM budget_requests WHERE request_id = ?");
        $stmt->bind_param('s', $reqId);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $upd = $conn->prepare("UPDATE budget_requests SET status = ?, admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE request_id = ?");
        $upd->bind_param('ssss', $newStatus, $notes, $admin_id, $reqId);
        $ok = $upd->execute();
        $upd->close();

        if ($ok && $req) {
            $techId = trim((string)($req['technician_id'] ?? ''));
            if ($techId !== '') {
                $msg = $newStatus === 'approved'
                    ? "Budget request {$reqId} (" . peso($req['total_cost'] ?? 0) . ") was APPROVED."
                    : "Budget request {$reqId} was REJECTED." . ($notes !== '' ? " Reason: {$notes}" : '');
                addNotification($techId, $msg, 'budget_' . $newStatus, $reqId);
            }
            logActivity($admin_id, 'budget.' . $newStatus, "Budget request {$reqId} {$newStatus} by {$admin_name}");
            $_SESSION['flash'] = ['ok', "Budget request {$reqId} {$newStatus}."];
        } else {
            $_SESSION['flash'] = ['err', 'Unable to update the budget request.'];
        }
    }
    header('Location: admin_budget_requests.php' . (isset($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : ''));
    exit();
}

/* ── Data ───────────────────────────────────────────── */
$filter = trim((string)($_GET['filter'] ?? 'pending'));
if (!in_array($filter, ['pending', 'approved', 'rejected', 'all'], true)) $filter = 'pending';

$where = $filter === 'all' ? '' : "WHERE b.status = '" . $filter . "'";
$sql = "SELECT b.request_id, b.report_id, b.technician_id, b.justification, b.total_cost,
               b.status, b.admin_notes, b.created_at, b.reviewed_at,
               COALESCE(u.fullname, b.technician_id) AS technician_name
        FROM budget_requests b
        LEFT JOIN users u ON u.user_id = b.technician_id
        $where
        ORDER BY CASE b.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END, b.created_at DESC";
$requests = [];
$res = $conn->query($sql);
if ($res) { $requests = $res->fetch_all(MYSQLI_ASSOC); }

// Items per request
$itemsByReq = [];
if ($requests) {
    $ids = array_map(fn($r) => "'" . str_replace("'", "", $r['request_id']) . "'", $requests);
    $inList = implode(',', $ids);
    $ir = $conn->query("SELECT request_id, part_needed, quantity, estimated_cost, supplier FROM budget_request_items WHERE request_id IN ($inList) ORDER BY item_id");
    if ($ir) { while ($row = $ir->fetch_assoc()) { $itemsByReq[$row['request_id']][] = $row; } }
}

// Counts for filter chips
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
$cres = $conn->query("SELECT status, COUNT(*) AS n FROM budget_requests GROUP BY status");
if ($cres) { while ($r = $cres->fetch_assoc()) { $counts[$r['status']] = (int)$r['n']; $counts['all'] += (int)$r['n']; } }

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Budget Requests — BEC Admin</title>
<link rel="icon" type="image/png" href="assets/logs.png">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  :root{ --maroon:#7B1D1D; --maroon-d:#4A0E0E; --gold:#C9960C; --ink:#1C1008; --ink2:#5C3838; --ink3:#8A7466; --paper:#F4F1EC; --surface:#fff; --border:#E2D9CC; --field:#FBF9F6; --danger:#B42318; --success:#1A7A33; }
  *{margin:0;padding:0;box-sizing:border-box;font-family:'DM Sans',system-ui,sans-serif;}
  body{background:var(--paper);color:var(--ink);min-height:100vh;}
  .top{background:var(--maroon-d);color:#fff;padding:16px 28px;display:flex;align-items:center;gap:14px;}
  .top .seal{width:40px;height:40px;border-radius:50%;background:#fff;overflow:hidden;display:flex;align-items:center;justify-content:center;}
  .top .seal img{width:100%;height:100%;object-fit:cover;}
  .top h1{font-family:'Fraunces',serif;font-size:1.05rem;font-weight:700;}
  .top .sub{font-size:.72rem;color:rgba(255,255,255,.7);}
  .top a.back{margin-left:auto;color:#fff;text-decoration:none;font-size:.82rem;border:1px solid rgba(255,255,255,.3);padding:7px 14px;border-radius:8px;}
  .top a.back:hover{background:rgba(255,255,255,.1);}
  .wrap{max-width:1000px;margin:0 auto;padding:24px 20px 60px;}
  .head{margin-bottom:18px;}
  .head h2{font-family:'Fraunces',serif;font-size:1.5rem;color:var(--ink);}
  .head p{font-size:.86rem;color:var(--ink3);margin-top:3px;}
  .chips{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0;}
  .chip{padding:7px 14px;border-radius:20px;border:1px solid var(--border);background:#fff;color:var(--ink2);font-size:.8rem;font-weight:600;text-decoration:none;display:inline-flex;gap:6px;align-items:center;}
  .chip.on{background:var(--maroon);color:#fff;border-color:var(--maroon);}
  .chip .n{background:rgba(0,0,0,.08);border-radius:10px;padding:0 7px;font-size:.72rem;}
  .chip.on .n{background:rgba(255,255,255,.22);}
  .flash{padding:11px 14px;border-radius:9px;margin-bottom:16px;font-size:.85rem;font-weight:500;}
  .flash.ok{background:#eef7f0;color:var(--success);border:1px solid #cfe9d6;}
  .flash.err{background:#fdf3f2;color:var(--danger);border:1px solid #f3d2cf;}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin-bottom:14px;box-shadow:0 1px 2px rgba(28,16,8,.04);}
  .card-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;}
  .rid{font-weight:700;color:var(--maroon);font-size:.95rem;}
  .meta{font-size:.78rem;color:var(--ink3);margin-top:3px;}
  .st{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;padding:4px 10px;border-radius:20px;}
  .st.pending{background:#fff7e6;color:#9a6a00;border:1px solid #f0d79a;}
  .st.approved{background:#eef7f0;color:var(--success);border:1px solid #cfe9d6;}
  .st.rejected{background:#fdf3f2;color:var(--danger);border:1px solid #f3d2cf;}
  table.items{width:100%;border-collapse:collapse;margin:12px 0;font-size:.82rem;}
  table.items th{text-align:left;background:var(--field);color:var(--ink2);padding:7px 10px;font-size:.72rem;text-transform:uppercase;letter-spacing:.4px;}
  table.items td{padding:7px 10px;border-bottom:1px solid var(--border);}
  table.items tfoot td{font-weight:700;border-top:2px solid var(--border);}
  .just{font-size:.84rem;color:var(--ink2);background:var(--field);border-left:3px solid var(--gold);border-radius:8px;padding:10px 12px;margin:10px 0;}
  .anotes{font-size:.8rem;color:var(--ink3);margin-top:8px;}
  .review{display:flex;gap:10px;align-items:flex-start;margin-top:14px;flex-wrap:wrap;}
  .review textarea{flex:1;min-width:220px;min-height:42px;padding:9px 11px;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:.84rem;resize:vertical;}
  .btn{border:none;border-radius:8px;padding:10px 16px;font-weight:600;font-size:.84rem;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:7px;}
  .btn.ok{background:var(--success);color:#fff;}
  .btn.no{background:var(--danger);color:#fff;}
  .empty{text-align:center;padding:48px 20px;color:var(--ink3);}
  .empty i{font-size:2rem;color:var(--border);margin-bottom:10px;}
  /* Persistent admin sidebar — exact match to canonical admin sidebar (fix #12) */
  :root{ --sb:262px; --m1:#2D0505; --m2:#4A0E0E; --g2:#D4A017; --g3:#F0C040; --r1:8px; --r2:12px; }
  .sb{position:fixed;left:0;top:0;width:var(--sb);height:100vh;background:linear-gradient(168deg,#1E0202 0%,#350808 38%,#4A0E0E 68%,#3A0808 100%);display:flex;flex-direction:column;z-index:400;overflow:hidden;box-shadow:5px 0 30px rgba(45,5,5,.38);transition:transform .32s cubic-bezier(.4,0,.2,1);}
  .sb::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 110% 45% at 50% -5%,rgba(212,160,23,.12),transparent);pointer-events:none;}
  .sb-top{padding:1.35rem 1.2rem .9rem;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:.75rem;position:relative;z-index:1;}
  .seal-ring{position:relative;width:46px;height:46px;flex-shrink:0;}
  .seal-spin{position:absolute;inset:-3px;border-radius:50%;background:conic-gradient(var(--g2) 0%,var(--g3) 30%,var(--g2) 60%,var(--g3) 80%,var(--g2) 100%);animation:sealSpin 7s linear infinite;opacity:.72;}
  @keyframes sealSpin{to{transform:rotate(360deg)}}
  .seal-core{position:absolute;inset:2px;border-radius:50%;overflow:hidden;background:var(--m1);}
  .seal-core img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
  .sb-brand strong{display:block;font-family:'Outfit',sans-serif;font-weight:800;font-size:.8rem;color:#fff;line-height:1.25;}
  .sb-brand em{font-size:.57rem;font-style:normal;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:1.8px;}
  .sb-user{margin:.45rem 1rem .2rem;padding:.65rem .875rem;background:rgba(255,255,255,.055);border:1px solid rgba(255,255,255,.07);border-radius:var(--r2);display:flex;align-items:center;gap:.65rem;position:relative;z-index:1;}
  .uav{width:32px;height:32px;flex-shrink:0;border-radius:50%;background:linear-gradient(135deg,var(--g2),#B45309);display:flex;align-items:center;justify-content:center;font-family:'Outfit',sans-serif;font-weight:900;font-size:.77rem;color:#fff;box-shadow:0 3px 0 rgba(0,0,0,.28);}
  .uname{font-size:.8rem;color:#fff;font-weight:600;display:block;}
  .urole{font-size:.58rem;color:rgba(255,255,255,.32);text-transform:uppercase;letter-spacing:1px;}
  .sb-nav{flex:1;padding:.25rem 0;overflow-y:auto;position:relative;z-index:1;scrollbar-width:thin;scrollbar-color:rgba(212,160,23,.45) transparent;}
.sb-nav::-webkit-scrollbar{width:6px;}
.sb-nav::-webkit-scrollbar-thumb{background:rgba(212,160,23,.4);border-radius:3px;}
.sb-nav::-webkit-scrollbar-thumb:hover{background:rgba(212,160,23,.65);}
  .nav-sec{font-size:.54rem;text-transform:uppercase;letter-spacing:2.5px;color:rgba(255,255,255,.18);padding:.5rem 1.25rem .2rem;font-weight:700;}
  .ni{display:flex;align-items:center;gap:.65rem;padding:.56rem 1.25rem;color:rgba(255,255,255,.42);background:none;border:none;width:100%;text-align:left;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;cursor:pointer;transition:all .16s;text-decoration:none;position:relative;}
  .ni-ic{width:30px;height:30px;border-radius:var(--r1);display:flex;align-items:center;justify-content:center;font-size:.78rem;background:rgba(255,255,255,.05);flex-shrink:0;transition:all .22s;}
  .ni:hover{color:rgba(255,255,255,.82);}
  .ni:hover .ni-ic{background:rgba(255,255,255,.1);transform:scale(1.08);}
  .ni.on{color:#fff;font-weight:600;}
  .ni.on .ni-ic{background:linear-gradient(135deg,var(--g2),var(--g3));color:var(--m1);box-shadow:0 3px 0 rgba(0,0,0,.18),0 4px 12px rgba(212,160,23,.25);}
  .ni.on::after{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(to bottom,var(--g2),var(--g3));border-radius:0 3px 3px 0;}
  .sb-foot{padding:.55rem 1rem .95rem;border-top:1px solid rgba(255,255,255,.06);}
  .lout{width:100%;display:flex;align-items:center;justify-content:center;gap:.65rem;padding:.52rem .78rem;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);color:rgba(255,255,255,.42);border-radius:var(--r1);cursor:pointer;font-size:.8rem;font-family:'DM Sans',sans-serif;font-weight:500;text-decoration:none;transition:all .18s;}
  .lout:hover{background:rgba(220,38,38,.14);color:#fca5a5;border-color:rgba(220,38,38,.22);}
  .top{margin-left:var(--sb);}
  .wrap{margin:0 0 0 var(--sb);max-width:1180px;}
  .top,.wrap{transition:margin-left .26s ease;}
  body.becSbHide .top, body.becSbHide .wrap{margin-left:0 !important;}
  @media(max-width:860px){ .sb{transform:translateX(-100%);} .top,.wrap{margin-left:0;} }
  @media(max-width:640px){ input,select,textarea,.fi,.fc,.input{ font-size:16px; } } /* prevent iOS zoom */
</style>
</head>
<body>
  <?php $activeNav = 'budget'; require __DIR__ . '/includes/admin_sidebar.php'; ?>
  <div class="top">
    <div class="seal"><img src="assets/logs.png" alt="BEC" onerror="this.parentElement.innerHTML='<i class=\'fas fa-crown\' style=\'color:#7B1D1D\'></i>'"></div>
    <div>
      <h1>Property Management Office</h1>
      <div class="sub">Technician Budget &amp; Materials Requests</div>
    </div>
    <a class="back" href="admin_dashboard.php"><i class="fas fa-arrow-left"></i> Dashboard</a>
  </div>

  <div class="wrap">
    <div class="head">
      <h2>Budget Requests</h2>
      <p>Review and decide on materials/budget requests submitted by technicians.</p>
    </div>

    <?php if ($flash): ?><div class="flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>"><?php echo be($flash[1]); ?></div><?php endif; ?>

    <div class="chips">
      <?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All'] as $k=>$lbl): ?>
        <a class="chip <?php echo $filter===$k?'on':''; ?>" href="?filter=<?php echo $k; ?>"><?php echo $lbl; ?> <span class="n"><?php echo (int)$counts[$k]; ?></span></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$requests): ?>
      <div class="empty"><i class="fas fa-coins"></i><div>No <?php echo $filter==='all'?'':be($filter).' '; ?>budget requests.</div></div>
    <?php else: foreach ($requests as $r): $items = $itemsByReq[$r['request_id']] ?? []; ?>
      <div class="card">
        <div class="card-top">
          <div>
            <div class="rid"><i class="fas fa-coins"></i> <?php echo be($r['request_id']); ?></div>
            <div class="meta">
              By <strong><?php echo be($r['technician_name']); ?></strong>
              <?php if (!empty($r['report_id'])): ?> · Report <?php echo be($r['report_id']); ?><?php endif; ?>
              · <?php echo bdt($r['created_at']); ?>
            </div>
          </div>
          <span class="st <?php echo be($r['status']); ?>"><?php echo be($r['status']); ?></span>
        </div>

        <table class="items">
          <thead><tr><th>Part / Material</th><th>Qty</th><th>Est. Cost</th><th>Supplier</th><th>Subtotal</th></tr></thead>
          <tbody>
            <?php if (!$items): ?><tr><td colspan="5" style="color:var(--ink3)">No line items.</td></tr><?php endif; ?>
            <?php foreach ($items as $it): $sub = (float)$it['quantity'] * (float)$it['estimated_cost']; ?>
              <tr>
                <td><?php echo be($it['part_needed']); ?></td>
                <td><?php echo (int)$it['quantity']; ?></td>
                <td><?php echo peso($it['estimated_cost']); ?></td>
                <td><?php echo be($it['supplier'] ?: '—'); ?></td>
                <td><?php echo peso($sub); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td colspan="4" style="text-align:right">Total</td><td><?php echo peso($r['total_cost']); ?></td></tr></tfoot>
        </table>

        <?php if (trim((string)$r['justification']) !== ''): ?>
          <div class="just"><strong>Justification:</strong> <?php echo be($r['justification']); ?></div>
        <?php endif; ?>

        <?php if ($r['status'] === 'pending'): ?>
          <form class="review" method="post" action="?filter=<?php echo be($filter); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="request_id" value="<?php echo be($r['request_id']); ?>">
            <textarea name="admin_notes" placeholder="Optional remarks (shown to the technician, esp. on rejection)…"></textarea>
            <button class="btn ok" type="submit" name="action" value="approve"><i class="fas fa-check"></i> Approve</button>
            <button class="btn no" type="submit" name="action" value="reject"><i class="fas fa-xmark"></i> Reject</button>
          </form>
        <?php elseif (trim((string)$r['admin_notes']) !== ''): ?>
          <div class="anotes"><i class="fas fa-comment-dots"></i> Admin remarks: <?php echo be($r['admin_notes']); ?> <span style="color:var(--ink3)">(<?php echo bdt($r['reviewed_at']); ?>)</span></div>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
<script src="assets/sidebar_autohide.js" defer></script>
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
</body>
</html>
