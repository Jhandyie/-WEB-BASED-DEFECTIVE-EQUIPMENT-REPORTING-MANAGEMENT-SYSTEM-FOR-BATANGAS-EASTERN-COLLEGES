<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireRole('admin');

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['fullname'] ?? 'Administrator';

function ae2($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function adt2($v): string { $t = strtotime((string)$v); return $t ? date('M d, Y h:i A', $t) : '—'; }

/* ── Filters ── */
$q    = trim((string)($_GET['q'] ?? ''));
$cat  = trim((string)($_GET['cat'] ?? 'all'));
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 50;

/* The action code lands in different columns depending on which logger wrote
   the row — sometimes user_role, sometimes action_type, occasionally action
   (there are two logActivity() definitions with different column orders). Match
   and search across all of them so the filters/search work regardless. */
$HAY      = "(COALESCE(a.action,'') || ' ' || COALESCE(a.user_role,'') || ' ' || COALESCE(a.action_type,''))";
$HAY_FULL = "($HAY || ' ' || COALESCE(a.action_description,'') || ' ' || COALESCE(a.details,'') || ' ' || COALESCE(a.user_id,'') || ' ' || COALESCE(u.fullname,''))";
$ilike = static function (string $hay, array $pats): string {
    return '(' . implode(' OR ', array_map(static fn($p) => $hay . " ILIKE '" . str_replace("'", "''", $p) . "'", $pats)) . ')';
};
$catPat = [
    'auth'    => ['%login%', '%otp%', '%auth.%', '%logout%'],
    'report'  => ['%report%', '%defect%', '%assign%', '%task.%'],
    'budget'  => ['%budget%'],
    'account' => ['%account%', '%user.%', '%register%', '%invite%', '%directory%'],
];
$cats = [
    'all'     => ['All',      'fa-layer-group',      ''],
    'auth'    => ['Logins',   'fa-right-to-bracket', $ilike($HAY, $catPat['auth'])],
    'report'  => ['Reports',  'fa-clipboard-list',   $ilike($HAY, $catPat['report'])],
    'budget'  => ['Budget',   'fa-coins',            $ilike($HAY, $catPat['budget'])],
    'account' => ['Accounts', 'fa-users',            $ilike($HAY, $catPat['account'])],
    'other'   => ['Other',    'fa-ellipsis',         'NOT (' . implode(' OR ', array_map(static fn($p) => $ilike($HAY, $p), $catPat)) . ')'],
];
if (!isset($cats[$cat])) { $cat = 'all'; }

$rows = [];
$total = 0;
try {
    $pdo = getPgsqlPdoConnection();
    $where = [];
    $args = [];
    if ($q !== '') {
        $where[] = "$HAY_FULL ILIKE :q";
        $args['q'] = '%' . $q . '%';
    }
    if ($cats[$cat][2] !== '') { $where[] = $cats[$cat][2]; }
    $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $st = $pdo->prepare("SELECT COUNT(*) FROM public.activity_log a LEFT JOIN public.users u ON u.user_id = a.user_id $w");
    $st->execute($args);
    $total = (int)$st->fetchColumn();

    $off = ($page - 1) * $per;
    $st = $pdo->prepare("SELECT a.*, COALESCE(NULLIF(u.fullname,''), a.user_id, 'System') AS actor_name, u.role AS actor_role
                         FROM public.activity_log a
                         LEFT JOIN public.users u ON u.user_id = a.user_id
                         $w
                         ORDER BY a.created_at DESC NULLS LAST
                         LIMIT $per OFFSET $off");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $err = $e->getMessage();
}
$pages = max(1, (int)ceil($total / $per));

function actTone(string $a): array {
    $a = strtolower($a);
    if (str_contains($a, 'delete') || str_contains($a, 'reject') || str_contains($a, 'fail')) return ['#B42318', '#FDECEC'];
    if (str_contains($a, 'login') || str_contains($a, 'otp') || str_contains($a, 'auth'))     return ['#1D4ED8', '#E8EFFF'];
    if (str_contains($a, 'budget') || str_contains($a, 'finance'))                            return ['#9A6A00', '#FFF7E6'];
    if (str_contains($a, 'complete') || str_contains($a, 'verif') || str_contains($a, 'approve')) return ['#1A7A33', '#EEF7F0'];
    return ['#7B1D1D', 'rgba(123,29,29,.08)'];
}
function auInitials(string $n): string {
    $n = trim($n); if ($n === '' || $n === 'System') return 'SY';
    $p = preg_split('/\s+/', $n);
    if (count($p) >= 2) return strtoupper(mb_substr($p[0], 0, 1) . mb_substr($p[count($p) - 1], 0, 1));
    return strtoupper(mb_substr($n, 0, 2));
}
/* Pull the action code, human message, and actor role out of a row regardless
   of which logging convention wrote it. */
function auResolve(array $r): array {
    $roleWords = ['admin','reporter','technician','system','pmo','student','dean','finance','handler','faculty','staff'];
    $ac = trim((string)($r['action'] ?? ''));
    $ur = trim((string)($r['user_role'] ?? ''));
    $at = trim((string)($r['action_type'] ?? ''));
    $ad = trim((string)($r['action_description'] ?? ''));
    $de = trim((string)($r['details'] ?? ''));
    // Action code: explicit action, else user_role when it's a code (not a plain role word), else action_type.
    $code = $ac;
    if ($code === '') {
        if ($ur !== '' && !in_array(strtolower($ur), $roleWords, true)) $code = $ur;
        elseif ($at !== '') $code = $at;
        elseif ($ur !== '') $code = $ur;
    }
    // Message: explicit details/description, else action_type when it isn't the code itself.
    $msg = $de !== '' ? $de : $ad;
    if ($msg === '' && $at !== '' && $at !== $code) $msg = $at;
    // Actor role: prefer the joined users.role, else user_role when it's actually a role word.
    $role = trim((string)($r['actor_role'] ?? ''));
    if ($role === '' && $ur !== '' && in_array(strtolower($ur), $roleWords, true)) $role = $ur;
    return [$code !== '' ? $code : '—', $msg, $role];
}
function auAvatar(string $role): string {
    $map = [
        'admin' => 'linear-gradient(135deg,#7B1D1D,#C53030)', 'technician' => 'linear-gradient(135deg,#1D4ED8,#60A5FA)',
        'pmo' => 'linear-gradient(135deg,#92400E,#F59E0B)', 'reporter' => 'linear-gradient(135deg,#7C3AED,#A78BFA)',
        'student' => 'linear-gradient(135deg,#0891B2,#22D3EE)',
    ];
    return $map[strtolower(trim($role))] ?? 'linear-gradient(135deg,#6B7280,#9CA3AF)';
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Log — BEC Admin</title>
<link rel="icon" type="image/png" href="assets/logs.png">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  :root{ --maroon:#7B1D1D; --maroon-d:#4A0E0E; --gold:#C9960C; --ink:#1C1008; --ink2:#5C3838; --ink3:#755B4E; --paper:#F4F1EC; --surface:#fff; --border:#E2D9CC; --field:#FBF9F6; --danger:#B42318; --success:#1A7A33; }
  *{margin:0;padding:0;box-sizing:border-box;font-family:'DM Sans',system-ui,sans-serif;}
  body{background:var(--paper);color:var(--ink);min-height:100vh;}
  .top{background:var(--maroon-d);color:#fff;padding:16px 28px;display:flex;align-items:center;gap:14px;}
  .top .seal{width:40px;height:40px;border-radius:50%;background:#fff;overflow:hidden;display:flex;align-items:center;justify-content:center;}
  .top .seal img{width:100%;height:100%;object-fit:cover;}
  .top h1{font-family:'Fraunces',serif;font-size:1.05rem;font-weight:700;}
  .top .sub{font-size:.72rem;color:rgba(255,255,255,.7);}
  .top a.back{margin-left:auto;color:#fff;text-decoration:none;font-size:.82rem;border:1px solid rgba(255,255,255,.3);padding:7px 14px;border-radius:8px;}
  .top a.back:hover{background:rgba(255,255,255,.1);}
  .wrap{max-width:none;margin:0;padding:24px 28px 60px;} /* full-width desktop view */
  .head h2{font-family:'Fraunces',serif;font-size:1.5rem;display:flex;align-items:center;gap:.6rem;}
  .head h2 .h2-ic{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,rgba(123,29,29,.1),rgba(201,150,12,.14));color:var(--maroon);display:inline-flex;align-items:center;justify-content:center;font-size:.9rem;}
  .head p{font-size:.86rem;color:var(--ink3);margin-top:5px;}
  .bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:16px 0;}
  .chips{display:flex;gap:8px;flex-wrap:wrap;}
  .chip{padding:7px 14px;border-radius:20px;border:1.5px solid var(--border);background:#fff;color:var(--ink2);font-size:.8rem;font-weight:600;text-decoration:none;display:inline-flex;gap:7px;align-items:center;transition:transform .16s,border-color .16s,color .16s,box-shadow .16s;}
  .chip i{font-size:.72rem;color:var(--ink3);transition:color .16s;}
  .chip:hover{transform:translateY(-1px);border-color:var(--maroon);color:var(--maroon);box-shadow:0 3px 10px rgba(74,14,14,.08);}
  .chip:hover i{color:var(--maroon);}
  .chip.on{background:linear-gradient(135deg,var(--maroon-d),var(--maroon));color:#fff;border-color:transparent;box-shadow:0 4px 12px rgba(123,29,29,.28);}
  .chip.on i{color:var(--gold);}
  form.search{position:relative;margin-left:auto;flex:0 1 300px;min-width:200px;}
  form.search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--ink3);font-size:.78rem;transition:color .16s;}
  form.search:focus-within i{color:var(--maroon);}
  form.search input{width:100%;padding:.55rem .9rem .55rem 2.1rem;border:1.5px solid var(--border);border-radius:999px;background:#fff;font-size:.84rem;transition:border-color .16s,box-shadow .16s;}
  form.search input:focus{outline:none;border-color:var(--maroon);box-shadow:0 0 0 4px rgba(123,29,29,.1);}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:0 1px 2px rgba(28,16,8,.04);}
  table{width:100%;border-collapse:collapse;font-size:.82rem;}
  th{text-align:left;background:var(--field);color:var(--ink2);padding:10px 14px;font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);}
  td{padding:10px 14px;border-bottom:1px solid var(--border);vertical-align:top;}
  tbody tr{transition:background .12s;}
  tr:hover td{background:#FDF7EE;}
  .act{display:inline-flex;align-items:center;gap:.38rem;padding:.26rem .62rem;border-radius:14px;font-size:.68rem;font-weight:700;white-space:nowrap;}
  .act::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;flex-shrink:0;}
  .who{display:flex;gap:.55rem;align-items:center;}
  .wav{width:32px;height:32px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-family:'Outfit',sans-serif;font-weight:800;font-size:.68rem;color:#fff;box-shadow:0 2px 5px rgba(28,16,8,.14);}
  .wt b{display:block;font-size:.8rem;}
  .wt span{font-size:.62rem;color:var(--ink3);text-transform:uppercase;letter-spacing:.4px;}
  .det{color:var(--ink2);line-height:1.5;max-width:420px;}
  .ip{font-size:.7rem;color:var(--ink3);white-space:nowrap;}
  .when{white-space:nowrap;font-size:.76rem;color:var(--ink2);}
  .empty{text-align:center;padding:48px 20px;color:var(--ink3);}
  .empty i{font-size:2rem;color:var(--border);display:block;margin-bottom:10px;}
  .pager{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;background:var(--field);border-top:1px solid var(--border);font-size:.78rem;color:var(--ink3);}
  .pager b{color:var(--ink);font-weight:800;}
  .pager .pg{display:inline-flex;gap:4px;padding:4px;background:#fff;border:1px solid var(--border);border-radius:999px;}
  .pager a,.pager span.cur{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;padding:0 9px;border-radius:999px;border:none;background:transparent;color:var(--ink2);text-decoration:none;font-weight:700;transition:background .16s,color .16s,box-shadow .16s,transform .16s;}
  .pager a:hover{background:var(--field);color:var(--maroon);box-shadow:0 1px 5px rgba(0,0,0,.08);transform:translateY(-1px);}
  .pager span.cur{background:linear-gradient(135deg,var(--maroon-d),var(--maroon));color:#fff;box-shadow:0 3px 9px rgba(123,29,29,.3);}
  /* Persistent admin sidebar (canonical) */
  :root{ --sb:262px; --m1:#2D0505; --g2:#D4A017; --g3:#F0C040; --r1:8px; --r2:12px; }
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
  .wrap{margin:0 0 0 var(--sb);max-width:none;}
  .top,.wrap{transition:margin-left .26s ease;}
  body.becSbHide .top, body.becSbHide .wrap{margin-left:0 !important;}
  @media(max-width:860px){ .sb{transform:translateX(-100%);} .top,.wrap{margin-left:0;} }
  @media(max-width:720px){ .det{max-width:220px;} th:nth-child(5),td:nth-child(5){display:none;} }
</style>
</head>
<body>
  <?php $activeNav = 'audit'; require __DIR__ . '/includes/admin_sidebar.php'; ?>
  <div class="top">
    <div class="seal"><img src="assets/logs.png" alt="BEC" onerror="this.parentElement.innerHTML='<i class=\'fas fa-shield\' style=\'color:#7B1D1D\'></i>'"></div>
    <div>
      <h1>Property Management Office</h1>
      <div class="sub">System Audit Trail</div>
    </div>
    <a class="back" href="admin_dashboard.php"><i class="fas fa-arrow-left"></i> Dashboard</a>
  </div>

  <div class="wrap">
    <div class="head">
      <h2><span class="h2-ic"><i class="fas fa-clipboard-list"></i></span> Audit Log</h2>
      <p>Every recorded action in the system — sign-ins, report workflow, budget decisions, and account changes. Read-only.</p>
    </div>

    <div class="bar">
      <div class="chips">
        <?php foreach ($cats as $k => [$lbl, $ic]): ?>
        <a class="chip <?php echo $cat === $k ? 'on' : ''; ?>" href="?cat=<?php echo $k; ?><?php echo $q !== '' ? '&q=' . urlencode($q) : ''; ?>"><i class="fas <?php echo $ic; ?>"></i> <?php echo $lbl; ?></a>
        <?php endforeach; ?>
      </div>
      <form class="search" method="get">
        <input type="hidden" name="cat" value="<?php echo ae2($cat); ?>">
        <i class="fas fa-magnifying-glass"></i>
        <input type="text" name="q" value="<?php echo ae2($q); ?>" placeholder="Search action, details, user…">
      </form>
    </div>

    <div class="card">
      <?php if (!empty($err)): ?>
        <div class="empty"><i class="fas fa-triangle-exclamation"></i><div>Could not read the audit log: <?php echo ae2($err); ?></div></div>
      <?php elseif (!$rows): ?>
        <div class="empty"><i class="fas fa-shield-halved"></i><strong>No matching entries.</strong><div><?php echo ($q !== '' || $cat !== 'all') ? 'No entries match this filter — try “All” or a different search.' : 'Actions will appear here as the system is used.'; ?></div></div>
      <?php else: ?>
      <div style="overflow-x:auto;">
      <table>
        <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r):
            [$action, $details, $roleLbl] = auResolve($r);
            [$fg,$bg] = actTone($action);
          ?>
          <tr>
            <td class="when"><?php echo adt2((string)($r['created_at'] ?? '')); ?></td>
            <td><div class="who"><span class="wav" style="background:<?php echo auAvatar($roleLbl); ?>;"><?php echo ae2(auInitials((string)$r['actor_name'])); ?></span><div class="wt"><b><?php echo ae2((string)$r['actor_name']); ?></b><span><?php echo ae2($roleLbl ?: 'system'); ?></span></div></div></td>
            <td><span class="act" style="color:<?php echo $fg; ?>;background:<?php echo $bg; ?>;"><?php echo ae2($action ?: '—'); ?></span></td>
            <td class="det"><?php echo ae2($details ?: '—'); ?></td>
            <td class="ip"><?php echo ae2((string)($r['ip_address'] ?? '—')); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div class="pager">
        <span><b><?php echo number_format($total); ?></b> entr<?php echo $total === 1 ? 'y' : 'ies'; ?> · page <b><?php echo $page; ?></b> of <b><?php echo $pages; ?></b></span>
        <div class="pg">
          <?php
            $mk = fn(int $p) => '?cat=' . urlencode($cat) . ($q !== '' ? '&q=' . urlencode($q) : '') . '&page=' . $p;
            if ($page > 1) echo '<a href="' . $mk($page - 1) . '"><i class="fas fa-chevron-left"></i></a>';
            for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++) {
                echo $p === $page ? '<span class="cur">' . $p . '</span>' : '<a href="' . $mk($p) . '">' . $p . '</a>';
            }
            if ($page < $pages) echo '<a href="' . $mk($page + 1) . '"><i class="fas fa-chevron-right"></i></a>';
          ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
<script src="assets/sidebar_autohide.js" defer></script>
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<?php require __DIR__ . '/includes/site_transitions.php'; ?>
</body>
</html>
