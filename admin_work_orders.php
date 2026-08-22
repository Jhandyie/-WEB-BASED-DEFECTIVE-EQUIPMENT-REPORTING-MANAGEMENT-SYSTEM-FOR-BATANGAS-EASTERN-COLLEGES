<?php
/**
 * Work Orders — the record of work that is finished.
 *
 * Defect Reports is a queue: it answers "what still needs doing". Once a job is
 * completed nobody wants it in that queue any more, and yet the completed jobs are
 * exactly what the office is asked for afterwards — what was repaired, by whom, on
 * what date, at what cost. This page is that ledger, and nothing else: a report only
 * appears here once a technician has finished it.
 *
 * 'completed' means the technician is done and an admin has not verified it yet;
 * 'verified' and 'closed' are past that. All three are finished work, so all three
 * are here, with the un-verified ones called out because they are the only rows that
 * still want an action.
 */

require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
requireRole('admin');

$admin_id   = $_SESSION['user_id'] ?? '';
$admin_name = $_SESSION['fullname'] ?? 'Administrator';
$pdo = getPgsqlPdoConnection();

function wo_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ─── Unit scope ─────────────────────────────────────────
   Same contract as Defect Reports and Preventive Maintenance: default to your own
   office, switchable to All or the other one. A report whose equipment row was
   since deleted has no unit; it stays visible to everyone rather than vanishing
   from both offices. */
$adminUnit    = adminUnitForUser($admin_id);   // 'PMO' | 'ITSO' | '' (no unit → sees all)
$unitExplicit = array_key_exists('unit', $_GET);
$uf = strtoupper(trim((string)($_GET['unit'] ?? ($adminUnit !== '' ? $adminUnit : 'all'))));
if (!in_array($uf, ['ALL', 'PMO', 'ITSO'], true)) { $uf = 'ALL'; }

/* ─── List controls ──────────────────────────────────── */
$q  = trim((string)($_GET['q'] ?? ''));
$sf = strtolower(trim((string)($_GET['status'] ?? 'all')));   // all | completed | verified | closed
$df = strtolower(trim((string)($_GET['when'] ?? 'all')));     // all | week | month | year
if (!in_array($sf, ['all', 'completed', 'verified', 'closed'], true)) { $sf = 'all'; }
if (!in_array($df, ['all', 'week', 'month', 'year'], true))           { $df = 'all'; }
$sort = strtolower(trim((string)($_GET['sort'] ?? 'done')));
$dir  = strtolower(trim((string)($_GET['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
if (!in_array($sort, ['done', 'wo', 'equip', 'tech', 'cost'], true)) { $sort = 'done'; }
$filtersOn = ($q !== '' || $sf !== 'all' || $df !== 'all');

/** Current URL with some params replaced — keeps filter/sort state across links. */
$keep = function (array $over = []) use ($q, $sf, $df, $uf, $sort, $dir) {
    $p = ['unit' => $uf, 'q' => $q, 'status' => $sf, 'when' => $df, 'sort' => $sort, 'dir' => $dir];
    $p = array_merge($p, $over);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null && $v !== 'all' && $v !== 'ALL');
    return 'admin_work_orders.php' . ($p ? '?' . http_build_query($p) : '');
};

/* ─── The ledger ─────────────────────────────────────────
   One query, no per-row work: this list grows for the life of the system, so
   anything O(rows) in the page load would be O(every job ever finished). The
   technician name is joined rather than looked up per row for the same reason. */
$DONE = "'completed','verified','closed'";

$where  = ["r.status IN ({$DONE})"];
$params = [];

if ($uf !== 'ALL') {
    $where[] = "(upper(COALESCE(e.unit,'')) = :unit OR e.equipment_id IS NULL)";
    $params[':unit'] = $uf;
}
if ($sf !== 'all') {
    $where[] = "r.status = :sf";
    $params[':sf'] = $sf;
}
if ($df !== 'all') {
    $interval = ['week' => '7 days', 'month' => '1 month', 'year' => '1 year'][$df];
    $where[] = "COALESCE(r.completion_date, r.report_date) >= now() - interval '{$interval}'";
}
if ($q !== '') {
    $where[] = "(r.report_id ILIKE :q OR COALESCE(r.work_order_id,'') ILIKE :q
                 OR COALESCE(r.equipment_name, e.equipment_name, '') ILIKE :q
                 OR COALESCE(r.location, e.location, '') ILIKE :q
                 OR COALESCE(u.fullname, mt.fullname, '') ILIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

$orderBy = [
    'done'  => 'COALESCE(r.completion_date, r.report_date)',
    'wo'    => 'COALESCE(NULLIF(r.work_order_id, \'\'), r.report_id)',
    'equip' => 'COALESCE(r.equipment_name, e.equipment_name)',
    'tech'  => 'COALESCE(u.fullname, mt.fullname)',
    'cost'  => 'COALESCE(r.repair_cost, 0)',
][$sort];

$sql = "SELECT r.report_id, r.work_order_id, r.status, r.priority, r.category,
               COALESCE(r.equipment_name, e.equipment_name) AS equipment_name,
               COALESCE(r.location, e.location)             AS location,
               e.unit, e.asset_tag,
               COALESCE(u.fullname, mt.fullname)            AS technician,
               r.assigned_to,
               r.report_date, r.completion_date, r.started_at,
               r.repair_cost, r.repair_duration,
               r.issue_description, r.defect_description,
               r.work_performed, r.actions_performed, r.findings, r.parts_replaced, r.materials_used,
               r.recommendations, r.completion_notes, r.resolution_notes,
               r.technician_notes, r.verification_notes,
               r.satisfaction, r.satisfaction_note,
               r.reporter_name, r.reporter_department
          FROM public.defect_reports r
          LEFT JOIN public.equipment e  ON e.equipment_id = r.equipment_id
          LEFT JOIN public.users u      ON u.user_id      = r.assigned_to
          LEFT JOIN public.maintenance_technicians mt ON mt.technician_id = r.assigned_to
         WHERE " . implode(' AND ', $where) . "
         ORDER BY {$orderBy} " . strtoupper($dir) . " NULLS LAST";

$rows = [];
$loadError = '';
try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $ex) {
    $loadError = $ex->getMessage();
    error_log('admin_work_orders load failed: ' . $ex->getMessage());
}

/* Totals for the cards. Counted over the same unit scope as the list, but without
   the status/date/search filters, so the cards stay a stable summary to filter by
   rather than a restatement of whatever is on screen. */
$tot = ['completed' => 0, 'verified' => 0, 'closed' => 0, 'cost' => 0.0];
try {
    $csql = "SELECT r.status, COUNT(*) AS n, COALESCE(SUM(r.repair_cost),0) AS c
               FROM public.defect_reports r
               LEFT JOIN public.equipment e ON e.equipment_id = r.equipment_id
              WHERE r.status IN ({$DONE})"
          . ($uf !== 'ALL' ? " AND (upper(COALESCE(e.unit,'')) = :unit OR e.equipment_id IS NULL)" : '')
          . " GROUP BY r.status";
    $cst = $pdo->prepare($csql);
    $cst->execute($uf !== 'ALL' ? [':unit' => $uf] : []);
    foreach ($cst as $r) {
        if (isset($tot[$r['status']])) { $tot[$r['status']] = (int)$r['n']; }
        $tot['cost'] += (float)$r['c'];
    }
} catch (\Throwable $ex) { error_log('admin_work_orders totals failed: ' . $ex->getMessage()); }
$totalDone = $tot['completed'] + $tot['verified'] + $tot['closed'];

/** A date the way the office says it, or an em dash. */
function wo_date($v, $withTime = true) {
    if (!$v) { return '—'; }
    $ts = strtotime((string)$v);
    if (!$ts) { return '—'; }
    return date($withTime ? 'M j, Y · g:i A' : 'M j, Y', $ts);
}
function wo_peso($v) {
    if ($v === null || $v === '' || (float)$v == 0.0) { return '—'; }
    return '₱' . number_format((float)$v, 2);
}
/** How long the job took, from the two stamps we actually keep. */
function wo_span($start, $end) {
    if (!$start || !$end) { return ''; }
    $a = strtotime((string)$start); $b = strtotime((string)$end);
    if (!$a || !$b || $b <= $a) { return ''; }
    $mins = (int)round(($b - $a) / 60);
    if ($mins < 60)   { return $mins . 'm'; }
    if ($mins < 1440) { return intdiv($mins, 60) . 'h ' . ($mins % 60) . 'm'; }
    return intdiv($mins, 1440) . 'd ' . intdiv($mins % 1440, 60) . 'h';
}

$statusMeta = [
    'completed' => ['Awaiting verification', '#C9960C', 'fa-hourglass-half'],
    'verified'  => ['Verified',              '#1A7A33', 'fa-clipboard-check'],
    'closed'    => ['Closed',                '#5C3838', 'fa-lock'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Work Orders — Admin</title>
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="assets/css/admin-shell.css">
<style>
  :root{--m:#7B1D1D;--md:#4A0E0E;--g:#C9960C;--ink:#1A0808;--ink2:#5C3838;--ink3:#9C7A7A;--paper:#F4EFE6;--surface:#fff;--border:#E5D9C6;--sb:262px;--danger:#B42318;--success:#1A7A33;--m1:#2D0505;--r1:8px;--r2:12px;}
  *{box-sizing:border-box}
  body{margin:0;font-family:'DM Sans',sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;}
  .main{margin-left:var(--sb);transition:margin-left .26s ease;}
  body.becSbHide .main{margin-left:0 !important;}
  .wrap{max-width:none;margin:0;padding:24px 28px 64px;}
  .head-row{display:flex;align-items:flex-start;justify-content:space-between;gap:1.5rem;margin-bottom:18px;}
  .unit-badge{display:inline-flex;align-items:center;gap:.35rem;vertical-align:middle;margin-left:.55rem;padding:.22rem .6rem;border-radius:999px;background:linear-gradient(135deg,var(--m),#a01a2b);color:#fff;font-size:.62rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;box-shadow:0 2px 8px rgba(122,18,32,.25);}
  .unit-badge i{font-size:.6rem;}
  .head-acts{display:flex;gap:.5rem;flex-shrink:0;}

  .btn{display:inline-flex;align-items:center;gap:.45rem;padding:.6rem 1rem;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--ink2);font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;text-decoration:none;transition:.2s;}
  .btn:hover{background:#faf7f0;color:var(--ink);}
  .btn.m{background:linear-gradient(135deg,var(--m),var(--md));border-color:transparent;color:#fff;}
  .btn.m:hover{filter:brightness(1.08);color:#fff;}
  .btn.sm{padding:.4rem .7rem;font-size:.75rem;}

  .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;}
  .stat{position:relative;overflow:hidden;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:1.15rem 1.3rem;display:flex;align-items:center;gap:1rem;box-shadow:0 1px 2px rgba(44,10,10,.05);transition:transform .26s cubic-bezier(.4,0,.2,1),box-shadow .26s;text-decoration:none;color:inherit;}
  .stat::before{content:'';position:absolute;top:-24px;right:-24px;width:90px;height:90px;border-radius:50%;background:var(--sk,var(--m));opacity:.05;transition:transform .3s,opacity .3s;}
  .stat::after{content:'';position:absolute;left:0;bottom:0;width:100%;height:3px;background:var(--sk,var(--m));transform:scaleX(0);transform-origin:left;transition:transform .32s;}
  .stat:hover{box-shadow:0 12px 30px rgba(44,10,10,.12);}
  .stat:hover::after,.stat.on::after{transform:scaleX(1);}
  .stat.on{border-color:var(--sk,var(--m));}
  .stat .ic{position:relative;z-index:1;width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;}
  .stat .n{position:relative;z-index:1;font-family:'Outfit',sans-serif;font-size:2rem;font-weight:800;line-height:1;}
  .stat .n.money{font-size:1.35rem;}
  .stat .l{position:relative;z-index:1;font-size:.62rem;text-transform:uppercase;letter-spacing:.6px;color:var(--ink3);font-weight:700;margin-top:.35rem;}

  .panel{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:0 1px 2px rgba(44,10,10,.05);overflow:hidden;}
  .tools{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;padding:1rem 1.1rem;border-bottom:1px solid var(--border);}
  .srch{position:relative;flex:1;min-width:230px;}
  .srch i{position:absolute;left:.8rem;top:50%;transform:translateY(-50%);color:var(--ink3);font-size:.8rem;}
  .srch input{width:100%;padding:.62rem .8rem .62rem 2.2rem;border:1.5px solid var(--border);border-radius:10px;background:#faf7f0;font-family:'DM Sans',sans-serif;font-size:.84rem;color:var(--ink);}
  .srch input:focus{outline:none;border-color:var(--m);background:#fff;}
  select.fsel{padding:.62rem 1.8rem .62rem .75rem;border:1.5px solid var(--border);border-radius:10px;background:#faf7f0;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:600;color:var(--ink2);cursor:pointer;}
  select.fsel:focus{outline:none;border-color:var(--m);}
  .cnt{margin-left:auto;font-size:.76rem;color:var(--ink3);font-weight:700;white-space:nowrap;}

  /* .head, .panel-title and .tbl are owned by assets/css/admin-shell.css —
     that file exists to keep headings and tables to one treatment across the
     admin. Only what this page genuinely adds is declared here. */
  .tbl tbody tr{cursor:pointer;}
  .tbl thead th a{color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem;}
  .tbl thead th a i{opacity:.55;font-size:.62rem;}
  .wo-id{font-family:'Outfit',sans-serif;font-weight:800;color:var(--m);white-space:nowrap;}
  .wo-sub{display:block;font-size:.66rem;color:var(--ink3);font-weight:600;margin-top:2px;}
  .eq{font-weight:700;}
  .loc{display:block;font-size:.7rem;color:var(--ink3);margin-top:2px;max-width:26rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .bdg{display:inline-flex;align-items:center;gap:.32rem;padding:.24rem .6rem;border-radius:999px;font-size:.66rem;font-weight:800;white-space:nowrap;}
  .money{font-family:'Outfit',sans-serif;font-weight:800;}
  .empty{padding:3.5rem 1.5rem;text-align:center;color:var(--ink3);}
  .empty i{font-size:2.4rem;color:var(--border);margin-bottom:.8rem;display:block;}
  .empty h3{margin:0 0 .35rem;font-family:'Outfit',sans-serif;font-size:1.05rem;color:var(--ink2);}
  .empty p{margin:0;font-size:.85rem;}

  /* ── Detail dialog: the finished job as a service record ─────────────── */
  .ovl{position:fixed;inset:0;background:rgba(26,8,8,.55);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:1.5rem;z-index:120;}
  .ovl[hidden]{display:none;}
  .dlg{background:var(--surface);border-radius:16px;width:100%;max-width:880px;box-shadow:0 26px 70px rgba(26,8,8,.34);overflow:hidden;}
  .dlg-h{display:flex;align-items:center;gap:.6rem;padding:1.05rem 1.3rem;border-bottom:1px solid var(--border);font-family:'Outfit',sans-serif;font-weight:700;font-size:.98rem;}
  .dlg-h i.lead{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,rgba(123,29,29,.1),rgba(201,150,12,.12));color:var(--m);display:inline-flex;align-items:center;justify-content:center;font-size:.85rem;}
  .dlg-h .x{margin-left:auto;background:transparent;border:none;color:var(--ink3);font-size:1rem;cursor:pointer;width:2rem;height:2rem;border-radius:8px;}
  .dlg-h .x:hover{background:#f1eadf;color:var(--ink);}
  .dlg-b{padding:1.3rem;max-height:66vh;overflow-y:auto;}
  /* Same ruled-field treatment the reservation form uses, so the two record
     views in this admin read alike. */
  .wform{border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:1.15rem;}
  .wsec + .wsec{border-top:1px solid var(--border);}
  .wsec > h5{margin:0;padding:.5rem .95rem;font-family:'Outfit',sans-serif;font-size:.62rem;font-weight:800;letter-spacing:1.1px;text-transform:uppercase;color:var(--m);background:#faf7f0;border-bottom:1px solid var(--border);}
  .wflds{padding:.85rem .95rem .3rem;}
  .wf{display:flex;align-items:baseline;gap:.65rem;margin-bottom:.62rem;}
  .wf .l{flex:0 0 11rem;font-size:.61rem;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:var(--g);text-align:right;line-height:1.5;}
  .wf .v{flex:1;min-width:0;font-size:.86rem;font-weight:650;color:var(--ink);border-bottom:1px dotted var(--border);padding:0 .25rem 4px;word-break:break-word;line-height:1.5;}
  .wf .v.empty2{color:var(--ink3);font-weight:500;}
  .wf .v.para{white-space:pre-wrap;border-bottom:none;padding-bottom:0;}
  @media(max-width:640px){.wf{display:block;}.wf .l{text-align:left;margin-bottom:2px;}}
</style>
</head>
<body>
<?php $activeNav = 'workorders'; require __DIR__ . '/includes/admin_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <div>
        <div class="pg-title">Work Orders</div>
        <div class="bc">
          <a href="admin_dashboard.php"><i class="fas fa-home"></i></a>
          <i class="fas fa-chevron-right"></i><span>Work Orders</span>
        </div>
      </div>
    </div>
    <div class="wrap">
      <div class="head-row">
        <div class="head">
          <h2>Work Orders
            <?php if ($adminUnit !== ''): ?><span class="unit-badge"><i class="fas fa-<?php echo $adminUnit === 'ITSO' ? 'laptop-code' : 'building-shield'; ?>"></i> <?php echo wo_e($adminUnit); ?> Admin</span><?php endif; ?>
          </h2>
          <p><?php if ($adminUnit !== '' && !$unitExplicit): ?>Showing <strong><?php echo wo_e($adminUnit); ?></strong> equipment by default — use the <em>Unit</em> filter to view All or the other office. <?php endif; ?>Every job a technician has finished, with what was done and what it cost. Rows marked <strong>Awaiting verification</strong> are finished but still need an admin to sign them off in Defect Reports.</p>
        </div>
        <div class="head-acts">
          <a class="btn" href="admin_defect_reports.php"><i class="fas fa-list-check"></i> Open Queue</a>
        </div>
      </div>

      <?php if ($loadError !== ''): ?>
        <div class="panel" style="padding:1rem 1.1rem;margin-bottom:1.2rem;border-color:#FECACA;background:#FEF2F2;color:var(--danger);font-size:.85rem;font-weight:600;">
          <i class="fas fa-circle-exclamation"></i> The ledger could not be loaded. The error is in <code>logs/</code>.
        </div>
      <?php endif; ?>

      <div class="cards">
        <a class="stat <?php echo $sf === 'completed' ? 'on' : ''; ?>" style="--sk:#C9960C;" href="<?php echo wo_e($keep(['status' => $sf === 'completed' ? 'all' : 'completed'])); ?>">
          <div class="ic" style="background:rgba(201,150,12,.12);color:#C9960C;"><i class="fas fa-hourglass-half"></i></div>
          <div><div class="n"><?php echo (int)$tot['completed']; ?></div><div class="l">Awaiting verification</div></div>
        </a>
        <a class="stat <?php echo $sf === 'verified' ? 'on' : ''; ?>" style="--sk:#1A7A33;" href="<?php echo wo_e($keep(['status' => $sf === 'verified' ? 'all' : 'verified'])); ?>">
          <div class="ic" style="background:rgba(26,122,51,.12);color:#1A7A33;"><i class="fas fa-clipboard-check"></i></div>
          <div><div class="n"><?php echo (int)$tot['verified']; ?></div><div class="l">Verified</div></div>
        </a>
        <a class="stat <?php echo $sf === 'closed' ? 'on' : ''; ?>" style="--sk:#5C3838;" href="<?php echo wo_e($keep(['status' => $sf === 'closed' ? 'all' : 'closed'])); ?>">
          <div class="ic" style="background:rgba(92,56,56,.12);color:#5C3838;"><i class="fas fa-lock"></i></div>
          <div><div class="n"><?php echo (int)$tot['closed']; ?></div><div class="l">Closed</div></div>
        </a>
        <div class="stat" style="--sk:#7B1D1D;cursor:default;">
          <div class="ic" style="background:rgba(123,29,29,.1);color:var(--m);"><i class="fas fa-peso-sign"></i></div>
          <div><div class="n money"><?php echo $tot['cost'] > 0 ? '₱' . number_format($tot['cost'], 2) : '—'; ?></div><div class="l">Repair cost recorded</div></div>
        </div>
      </div>

      <div class="panel">
        <form class="tools" method="GET" action="admin_work_orders.php" id="filterForm">
          <input type="hidden" name="sort" value="<?php echo wo_e($sort); ?>">
          <input type="hidden" name="dir"  value="<?php echo wo_e($dir); ?>">
          <div class="srch">
            <i class="fas fa-search"></i>
            <input type="search" name="q" value="<?php echo wo_e($q); ?>" placeholder="Search work order, equipment, location, technician…">
          </div>
          <select class="fsel" name="unit" onchange="this.form.submit()">
            <option value="all"  <?php echo $uf === 'ALL'  ? 'selected' : ''; ?>>All units</option>
            <option value="PMO"  <?php echo $uf === 'PMO'  ? 'selected' : ''; ?>>PMO only</option>
            <option value="ITSO" <?php echo $uf === 'ITSO' ? 'selected' : ''; ?>>ITSO only</option>
          </select>
          <select class="fsel" name="status" onchange="this.form.submit()">
            <option value="all"       <?php echo $sf === 'all'       ? 'selected' : ''; ?>>Any state</option>
            <option value="completed" <?php echo $sf === 'completed' ? 'selected' : ''; ?>>Awaiting verification</option>
            <option value="verified"  <?php echo $sf === 'verified'  ? 'selected' : ''; ?>>Verified</option>
            <option value="closed"    <?php echo $sf === 'closed'    ? 'selected' : ''; ?>>Closed</option>
          </select>
          <select class="fsel" name="when" onchange="this.form.submit()">
            <option value="all"   <?php echo $df === 'all'   ? 'selected' : ''; ?>>Any date</option>
            <option value="week"  <?php echo $df === 'week'  ? 'selected' : ''; ?>>Last 7 days</option>
            <option value="month" <?php echo $df === 'month' ? 'selected' : ''; ?>>Last month</option>
            <option value="year"  <?php echo $df === 'year'  ? 'selected' : ''; ?>>Last year</option>
          </select>
          <?php if ($filtersOn): ?>
            <a class="btn sm" href="admin_work_orders.php"><i class="fas fa-xmark"></i> Clear</a>
          <?php endif; ?>
          <span class="cnt"><?php echo count($rows); ?> of <?php echo (int)$totalDone; ?> finished</span>
        </form>

        <?php if (!$rows): ?>
          <div class="empty">
            <i class="fas fa-clipboard-check"></i>
            <h3><?php echo $filtersOn ? 'Nothing matches those filters' : 'No finished work yet'; ?></h3>
            <p><?php echo $filtersOn
                ? 'Clear the filters to see the whole ledger.'
                : 'A job lands here the moment a technician marks it complete.'; ?></p>
          </div>
        <?php else: ?>
          <div style="overflow-x:auto;">
          <table class="tbl">
            <thead>
              <tr>
                <?php
                $th = function ($key, $label) use ($sort, $dir, $keep) {
                    $nd  = ($sort === $key && $dir === 'desc') ? 'asc' : 'desc';
                    $ic  = $sort !== $key ? 'fa-sort' : ($dir === 'desc' ? 'fa-sort-down' : 'fa-sort-up');
                    echo '<th><a href="' . wo_e($keep(['sort' => $key, 'dir' => $nd])) . '">'
                       . wo_e($label) . ' <i class="fas ' . $ic . '"></i></a></th>';
                };
                $th('wo', 'Work Order');
                $th('equip', 'Equipment');
                $th('tech', 'Technician');
                $th('done', 'Completed');
                $th('cost', 'Cost');
                ?>
                <th>State</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $meta = $statusMeta[$r['status']] ?? ['—', '#9C7A7A', 'fa-circle'];
                $span = wo_span($r['started_at'], $r['completion_date']);
                $payload = [
                    'wo'    => $r['work_order_id'] ?: $r['report_id'],
                    'rid'   => $r['report_id'],
                    'eq'    => $r['equipment_name'] ?: '—',
                    'tag'   => $r['asset_tag'] ?: '',
                    'loc'   => $r['location'] ?: '—',
                    'unit'  => $r['unit'] ?: '',
                    'tech'  => $r['technician'] ?: '—',
                    'stat'  => $meta[0],
                    'prio'  => $r['priority'] ?: '',
                    'cat'   => $r['category'] ?: '',
                    'rep'   => wo_date($r['report_date']),
                    'start' => wo_date($r['started_at']),
                    'done'  => wo_date($r['completion_date']),
                    'span'  => $span ?: ($r['repair_duration'] ?: ''),
                    'cost'  => wo_peso($r['repair_cost']),
                    'issue' => $r['issue_description'] ?: $r['defect_description'] ?: '',
                    'work'  => $r['work_performed'] ?: ($r['actions_performed'] ?: ''),
                    'find'  => $r['findings'] ?: '',
                    'parts' => $r['parts_replaced'] ?: '',
                    'mats'  => $r['materials_used'] ?: '',
                    'recs'  => $r['recommendations'] ?: '',
                    'cnote' => $r['completion_notes'] ?: $r['resolution_notes'] ?: $r['technician_notes'] ?: '',
                    'vnote' => $r['verification_notes'] ?: '',
                    'sat'   => $r['satisfaction'] ?: '',
                    'satn'  => $r['satisfaction_note'] ?: '',
                    'rby'   => $r['reporter_name'] ?: '',
                    'rdept' => $r['reporter_department'] ?: '',
                ];
            ?>
              <tr tabindex="0" data-wo='<?php echo wo_e(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>'>
                <td>
                  <span class="wo-id"><?php echo wo_e($r['work_order_id'] ?: $r['report_id']); ?></span>
                  <?php if ($r['work_order_id'] && $r['work_order_id'] !== $r['report_id']): ?>
                    <span class="wo-sub"><?php echo wo_e($r['report_id']); ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="eq"><?php echo wo_e($r['equipment_name'] ?: '—'); ?></span>
                  <span class="loc"><i class="fas fa-location-dot" style="opacity:.5;"></i> <?php echo wo_e($r['location'] ?: 'No location on file'); ?></span>
                </td>
                <td><?php echo wo_e($r['technician'] ?: '—'); ?></td>
                <td style="white-space:nowrap;">
                  <?php echo wo_e(wo_date($r['completion_date'], false)); ?>
                  <?php if ($span): ?><span class="wo-sub"><i class="fas fa-stopwatch" style="opacity:.5;"></i> <?php echo wo_e($span); ?></span><?php endif; ?>
                </td>
                <td class="money"><?php echo wo_e(wo_peso($r['repair_cost'])); ?></td>
                <td>
                  <span class="bdg" style="background:<?php echo $meta[1]; ?>1a;color:<?php echo $meta[1]; ?>;">
                    <i class="fas <?php echo $meta[2]; ?>"></i> <?php echo wo_e($meta[0]); ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ═══ Service record ═══ -->
  <div class="ovl" id="woOvl" hidden>
    <div class="dlg" role="dialog" aria-modal="true" aria-labelledby="woTitle">
      <div class="dlg-h">
        <i class="fas fa-clipboard-check lead"></i>
        <span id="woTitle">Work Order</span>
        <button class="x" type="button" data-close aria-label="Close"><i class="fas fa-xmark"></i></button>
      </div>
      <div class="dlg-b"><div id="woBody"></div></div>
    </div>
  </div>

<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<script>
(function () {
  var DASH = '—';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
    });
  }

  function field(label, value, isPara) {
    var blank = (value === null || value === undefined || String(value).trim() === '' || value === DASH);
    var cls = 'v' + (blank ? ' empty2' : '') + (isPara && !blank ? ' para' : '');
    return '<div class="wf"><span class="l">' + esc(label) + '</span>'
         + '<span class="' + cls + '">' + (blank ? DASH : esc(value)) + '</span></div>';
  }

  function section(title, fields) {
    var body = fields.join('');
    return body ? '<div class="wsec"><h5>' + esc(title) + '</h5><div class="wflds">' + body + '</div></div>' : '';
  }

  function open(tr) {
    var raw = tr.getAttribute('data-wo');
    if (!raw) { return; }
    var d;
    try { d = JSON.parse(raw); } catch (e) { return; }

    document.getElementById('woTitle').textContent = d.wo + ' · ' + d.eq;

    var html = '<div class="wform">'
      + section('Equipment', [
          field('Equipment', d.eq),
          field('Asset Tag', d.tag),
          field('Location', d.loc),
          field('Responsible Unit', d.unit)
        ])
      + section('The Request', [
          field('Report No.', d.rid),
          field('Reported By', d.rby + (d.rdept ? ' · ' + d.rdept : '')),
          field('Date Reported', d.rep),
          field('Category', d.cat),
          field('Priority', d.prio),
          field('Reported Problem', d.issue, true)
        ])
      + section('The Work', [
          field('Technician', d.tech),
          field('Started', d.start),
          field('Completed', d.done),
          field('Time On Job', d.span),
          field('Work Performed', d.work, true),
          field('Findings', d.find, true),
          field('Parts Replaced', d.parts, true),
          field('Materials Used', d.mats, true),
          field('Recommendations', d.recs, true),
          field('Technician Notes', d.cnote, true)
        ])
      + section('Closing', [
          field('State', d.stat),
          field('Repair Cost', d.cost),
          field('Verification Notes', d.vnote, true),
          field('Reporter Satisfaction', d.sat),
          field('Reporter Comment', d.satn, true)
        ])
      + '</div>';

    document.getElementById('woBody').innerHTML = html;
    document.getElementById('woOvl').hidden = false;
  }

  function close() { document.getElementById('woOvl').hidden = true; }

  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-close]')) { close(); return; }
    if (ev.target.id === 'woOvl') { close(); return; }
    var tr = ev.target.closest('tr[data-wo]');
    if (tr) { open(tr); }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { close(); return; }
    if (ev.key === 'Enter') {
      var tr = ev.target.closest && ev.target.closest('tr[data-wo]');
      if (tr) { ev.preventDefault(); open(tr); }
    }
  });
}());
</script>
</body>
</html>
