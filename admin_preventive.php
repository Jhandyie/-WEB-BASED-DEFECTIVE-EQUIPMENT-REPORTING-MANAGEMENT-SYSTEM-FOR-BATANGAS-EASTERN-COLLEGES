<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/preventive_helper.php';
requireRole('admin');
@runPreventiveMaintenanceSweep(); // generate any due tasks on load

$admin_id   = $_SESSION['user_id'] ?? '';
$admin_name = $_SESSION['fullname'] ?? 'Administrator';
$pdo = getPgsqlPdoConnection();
function pm_e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ─── Unit scope ─────────────────────────────────────────
   Every other admin surface scopes what it shows to the admin's own office
   (admin_dashboard.php, admin_defect_reports.php, inventory_functions.php all
   call adminUnitForUser()). This page did not, so an ITSO admin was shown the
   PMO's aircon schedules and had to pick a target out of all 1,320 equipment
   rows. Same contract as Defect Reports: default to your own unit, switchable
   to All or the other one. equipment.unit is set on every row, so the scope is
   a plain join — a schedule whose equipment was since deleted has no unit and
   stays visible to everyone rather than disappearing from both offices. */
$adminUnit    = adminUnitForUser($admin_id);      // 'PMO' | 'ITSO' | '' (no unit → sees all)
$unitExplicit = array_key_exists('unit', $_GET);
$uf = strtoupper(trim((string)($_GET['unit'] ?? ($adminUnit !== '' ? $adminUnit : 'all'))));
if (!in_array($uf, ['ALL', 'PMO', 'ITSO'], true)) { $uf = 'ALL'; }

/* ─── List controls ──────────────────────────────────── */
$q  = trim((string)($_GET['q'] ?? ''));
$sf = strtolower(trim((string)($_GET['status'] ?? 'all')));   // all | active | paused
$df = strtolower(trim((string)($_GET['due'] ?? 'all')));      // all | overdue | week | month
if (!in_array($sf, ['all', 'active', 'paused'], true))            { $sf = 'all'; }
if (!in_array($df, ['all', 'overdue', 'week', 'month'], true))    { $df = 'all'; }
$sortExplicit = array_key_exists('sort', $_GET);
$sort = strtolower(trim((string)($_GET['sort'] ?? 'due')));
$dir  = strtolower(trim((string)($_GET['dir']  ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
if (!in_array($sort, ['due', 'title', 'equip', 'freq', 'prio', 'status', 'gen'], true)) { $sort = 'due'; }
$filtersOn = ($q !== '' || $sf !== 'all' || $df !== 'all' || $sortExplicit);

/** Current URL with some params replaced — keeps the filter/sort state across links. */
$keep = function (array $over = []) use ($q, $sf, $df, $uf, $sort, $dir, $sortExplicit) {
    $p = ['unit' => $uf, 'q' => $q, 'status' => $sf, 'due' => $df];
    if ($sortExplicit) { $p['sort'] = $sort; $p['dir'] = $dir; }
    $p = array_merge($p, $over);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null && $v !== 'all' && $v !== 'ALL');
    return 'admin_preventive.php' . ($p ? '?' . http_build_query($p) : '');
};

/* Ready-made schedules for the empty state and the "start from a template"
   row. These are the four the PMO actually runs on paper; the point is that a
   first schedule takes one click plus an equipment pick, not a blank form. */
$templates = [
    ['t' => 'Aircon cleaning & filter check',      'f' => 90,  'p' => 'medium',   'i' => "Wash filters, check refrigerant level, clear drain line, wipe fins.",           'ic' => 'fa-wind'],
    ['t' => 'Projector lamp & lens check',         'f' => 180, 'p' => 'medium',   'i' => "Check lamp hours, clean lens and air filter, confirm sharp focus.",             'ic' => 'fa-video'],
    ['t' => 'Fire extinguisher inspection',        'f' => 30,  'p' => 'critical', 'i' => "Check gauge pressure, pin and seal, hose condition, and inspection tag date.", 'ic' => 'fa-fire-extinguisher'],
    ['t' => 'Electrical outlet & wiring check',    'f' => 180, 'p' => 'high',     'i' => "Test outlets, look for scorching or loose plates, check breaker labels.",       'ic' => 'fa-plug'],
];

/** Read the fields shared by create and edit out of a POST body. */
function pmReadForm(array $src): array {
    $freqSel = trim((string)($src['frequency_days'] ?? '60'));
    $freq    = $freqSel === 'custom' ? (int)($src['frequency_custom'] ?? 0) : (int)$freqSel;
    $due     = trim((string)($src['next_due'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || !strtotime($due)) { $due = date('Y-m-d'); }
    $prio = strtolower(trim((string)($src['priority'] ?? 'medium')));
    if (!in_array($prio, ['low', 'medium', 'high', 'critical'], true)) { $prio = 'medium'; }
    return [
        'title'          => trim((string)($src['title'] ?? '')),
        'equipment_id'   => trim((string)($src['equipment_id'] ?? '')),
        'frequency_days' => max(1, min(3650, $freq)),   // 1 day … 10 years
        'next_due'       => $due,
        'priority'       => $prio,
        'assigned_to'    => trim((string)($src['assigned_to'] ?? '')),
        'instructions'   => trim((string)($src['instructions'] ?? '')),
    ];
}

/* ─── POST ───────────────────────────────────────────────
   Successful writes redirect back (POST/redirect/GET) so a refresh cannot
   create the same schedule twice; the flash rides in the session. Validation
   errors do NOT redirect — the form re-renders open, with what was typed. */
$flash = null;
$fv    = ['title' => '', 'equipment_id' => '', 'frequency_days' => 90, 'next_due' => date('Y-m-d'),
          'priority' => 'medium', 'assigned_to' => '', 'instructions' => ''];
$openForm = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act  = $_POST['action'] ?? '';
    $done = null;   // set to [type,msg] when the write succeeded and we should redirect

    if ($act === 'create') {
        $in = pmReadForm($_POST);
        if ($in['title'] === '' || $in['equipment_id'] === '') {
            $flash = ['err', 'A task title and a target equipment are both required.'];
            $fv = $in; $openForm = true;
        } else {
            $eq = function_exists('getEquipmentById') ? getEquipmentById($in['equipment_id']) : null;
            $st = $pdo->prepare("INSERT INTO preventive_schedules
                (title,equipment_id,equipment_name,location,category,frequency_days,next_due,priority,assigned_to,instructions,status,created_by,created_at)
                VALUES (:t,:eid,:en,:loc,:cat,:f,:nd,:pr,:asg,:ins,'active',:cb, now())");
            $st->execute([
                't'   => $in['title'],
                'eid' => $in['equipment_id'],
                'en'  => $eq['equipment_name'] ?? '',
                'loc' => $eq['location'] ?? '',
                'cat' => $eq['category'] ?? ($eq['equipment_category'] ?? ''),
                'f'   => $in['frequency_days'],
                'nd'  => $in['next_due'],
                'pr'  => $in['priority'],
                'asg' => ($in['assigned_to'] !== '' ? $in['assigned_to'] : null),
                'ins' => $in['instructions'],
                'cb'  => $admin_id,
            ]);
            if (function_exists('logActivity')) { try { logActivity($admin_id, 'admin', 'pm.create', 'Created PM schedule: ' . $in['title']); } catch (\Throwable $e) {} }
            // If the first due date is today/past, generate immediately —
            // forced past the throttle, because the admin just asked for it.
            @runPreventiveMaintenanceSweep(true);
            $done = ['ok', 'Schedule created: ' . $in['title'] . '.'];
        }
    } elseif ($act === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $in = pmReadForm($_POST);
        if ($id <= 0) {
            $flash = ['err', 'That schedule could not be identified — reload the page and try again.'];
        } elseif ($in['title'] === '') {
            $flash = ['err', 'A task title is required.'];
        } else {
            // equipment_id is deliberately not editable: the generated tickets
            // and their history hang off it, so a different target is a
            // different schedule.
            $st = $pdo->prepare("UPDATE preventive_schedules
                SET title=:t, frequency_days=:f, next_due=:nd, priority=:pr, assigned_to=:asg, instructions=:ins
                WHERE id=:id");
            $st->execute([
                't'   => $in['title'],
                'f'   => $in['frequency_days'],
                'nd'  => $in['next_due'],
                'pr'  => $in['priority'],
                'asg' => ($in['assigned_to'] !== '' ? $in['assigned_to'] : null),
                'ins' => $in['instructions'],
                'id'  => $id,
            ]);
            if (function_exists('logActivity')) { try { logActivity($admin_id, 'admin', 'pm.edit', 'Edited PM schedule #' . $id . ': ' . $in['title']); } catch (\Throwable $e) {} }
            $done = ['ok', 'Schedule updated: ' . $in['title'] . '.'];
        }
    } elseif ($act === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE preventive_schedules SET status = CASE WHEN status='active' THEN 'paused' ELSE 'active' END WHERE id = ?")->execute([$id]);
        $now = (string)$pdo->query("SELECT status FROM preventive_schedules WHERE id = " . $id)->fetchColumn();
        if (function_exists('logActivity')) { try { logActivity($admin_id, 'admin', 'pm.toggle', 'Set PM schedule #' . $id . ' to ' . $now); } catch (\Throwable $e) {} }
        $done = ['ok', $now === 'paused' ? 'Schedule paused — it will stop generating tasks.' : 'Schedule resumed.'];
    } elseif ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $ttl = (string)$pdo->query("SELECT title FROM preventive_schedules WHERE id = " . $id)->fetchColumn();
        $pdo->prepare("DELETE FROM preventive_schedules WHERE id = ?")->execute([$id]);
        if (function_exists('logActivity')) { try { logActivity($admin_id, 'admin', 'pm.delete', 'Deleted PM schedule #' . $id . ': ' . $ttl); } catch (\Throwable $e) {} }
        $done = ['ok', 'Schedule deleted. Tickets it already generated are kept.'];
    } elseif ($act === 'run_now') {
        $n = runPreventiveMaintenanceSweep(true);
        $done = ['ok', $n > 0 ? "$n preventive ticket" . ($n === 1 ? '' : 's') . " generated." : 'Nothing is due right now, so no tickets were generated.'];
    }

    if ($done) {
        $_SESSION['pm_flash'] = $done;
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}
if (!$flash && !empty($_SESSION['pm_flash'])) { $flash = $_SESSION['pm_flash']; unset($_SESSION['pm_flash']); }
if (isset($_GET['new'])) { $openForm = true; }

/* ─── Data ───────────────────────────────────────────────
   Three queries, all O(1) in the size of the backlog: the schedules (tens of
   rows), one grouped roll-up of the tickets they have generated, and the
   equipment list for the picker. Nothing here runs per report. */
$schedules = $pdo->query("SELECT s.*, UPPER(COALESCE(NULLIF(TRIM(e.unit),''),'')) AS unit, e.asset_tag
                          FROM preventive_schedules s
                          LEFT JOIN equipment e ON e.equipment_id = s.equipment_id")->fetchAll(PDO::FETCH_ASSOC);

$genMap = [];   // schedule id → ['c' => tickets generated, 'last_at' => most recent]
try {
    foreach ($pdo->query("SELECT pm_schedule_id, COUNT(*) AS c, MAX(report_date) AS last_at
                          FROM defect_reports
                          WHERE is_preventive = true AND pm_schedule_id IS NOT NULL
                          GROUP BY pm_schedule_id")->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $genMap[(int)$g['pm_schedule_id']] = ['c' => (int)$g['c'], 'last_at' => $g['last_at']];
    }
} catch (\Throwable $e) {}

$generatedTotal = (int)$pdo->query("SELECT COUNT(*) FROM defect_reports WHERE is_preventive = true")->fetchColumn();

$equipAll = $pdo->query("SELECT equipment_id, equipment_name, asset_tag, location,
                                UPPER(COALESCE(NULLIF(TRIM(unit),''),'')) AS unit
                         FROM equipment
                         WHERE LOWER(COALESCE(status,'')) <> 'deleted'
                         ORDER BY equipment_name")->fetchAll(PDO::FETCH_ASSOC);

$techList = function_exists('getAvailableTechnicians') ? (getAvailableTechnicians() ?: []) : [];
$techName = [];
foreach ($techList as $t) {
    $tid = (string)($t['technician_id'] ?? ($t['user_id'] ?? ''));
    if ($tid !== '') { $techName[$tid] = (string)($t['fullname'] ?? $tid); }
}
$presets = pmFrequencyPresets();

/* ─── Scope, filter, sort (in PHP — the row count here is tens, not thousands) ─ */
$inUnit = function (array $s) use ($uf) {
    if ($uf === 'ALL') return true;
    $u = (string)($s['unit'] ?? '');
    return $u === '' || $u === $uf;   // equipment gone → belongs to nobody, show to all
};
$scoped = array_values(array_filter($schedules, $inUnit));
$equipList = array_values(array_filter($equipAll, function ($e) use ($uf) {
    return $uf === 'ALL' || (string)$e['unit'] === '' || (string)$e['unit'] === $uf;
}));

$today = strtotime('today');
$activeCount = 0; $dueSoon = 0; $overdue = 0;
foreach ($scoped as $s) {
    if (($s['status'] ?? '') !== 'active') continue;
    $activeCount++;
    $d = (int)floor((strtotime((string)$s['next_due']) - $today) / 86400);
    if ($d < 0)       { $overdue++; }
    elseif ($d <= 7)  { $dueSoon++; }
}

$rows = $scoped;
if ($sf !== 'all') {
    $rows = array_values(array_filter($rows, fn($s) => (string)($s['status'] ?? '') === $sf));
}
if ($df !== 'all') {
    $lim = ['overdue' => -1, 'week' => 7, 'month' => 30][$df];
    $rows = array_values(array_filter($rows, function ($s) use ($lim, $df, $today) {
        $d = (int)floor((strtotime((string)$s['next_due']) - $today) / 86400);
        return $df === 'overdue' ? $d < 0 : $d <= $lim;
    }));
}
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_values(array_filter($rows, function ($s) use ($needle) {
        $hay = mb_strtolower(implode(' ', [
            (string)$s['title'], (string)$s['equipment_name'], (string)$s['equipment_id'],
            (string)$s['location'], (string)($s['asset_tag'] ?? ''), (string)$s['instructions'],
        ]));
        return mb_strpos($hay, $needle) !== false;
    }));
}

$prioRank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
usort($rows, function ($a, $b) use ($sort, $dir, $sortExplicit, $prioRank, $genMap) {
    // Default view keeps today's behaviour: running schedules first, soonest due
    // at the top. An explicitly clicked column sorts on that column alone.
    if (!$sortExplicit) {
        $sa = ($a['status'] ?? '') === 'active' ? 0 : 1;
        $sb = ($b['status'] ?? '') === 'active' ? 0 : 1;
        if ($sa !== $sb) return $sa <=> $sb;
    }
    switch ($sort) {
        case 'title':  $c = strcasecmp((string)$a['title'], (string)$b['title']); break;
        case 'equip':  $c = strcasecmp((string)($a['equipment_name'] ?: $a['equipment_id']), (string)($b['equipment_name'] ?: $b['equipment_id'])); break;
        case 'freq':   $c = (int)$a['frequency_days'] <=> (int)$b['frequency_days']; break;
        case 'prio':   $c = ($prioRank[strtolower((string)$a['priority'])] ?? 9) <=> ($prioRank[strtolower((string)$b['priority'])] ?? 9); break;
        case 'status': $c = strcmp((string)$a['status'], (string)$b['status']); break;
        case 'gen':    $c = ($genMap[(int)$a['id']]['c'] ?? 0) <=> ($genMap[(int)$b['id']]['c'] ?? 0); break;
        default:       $c = strtotime((string)$a['next_due']) <=> strtotime((string)$b['next_due']);
    }
    if ($c === 0) { $c = strtotime((string)$a['next_due']) <=> strtotime((string)$b['next_due']); }
    return $dir === 'desc' ? -$c : $c;
});

/** A sortable column header. Clicking the active column flips its direction. */
$sortTh = function (string $key, string $label, string $extra = '') use ($sort, $dir, $keep) {
    $on   = ($sort === $key);
    $next = ($on && $dir === 'asc') ? 'desc' : 'asc';
    $ico  = $on ? ($dir === 'asc' ? 'fa-arrow-up-short-wide' : 'fa-arrow-down-wide-short') : 'fa-sort';
    return '<th' . ($extra ? ' ' . $extra : '') . '><a class="sth' . ($on ? ' on' : '') . '" href="'
         . pm_e($keep(['sort' => $key, 'dir' => $next])) . '">' . pm_e($label)
         . ' <i class="fas ' . $ico . '"></i></a></th>';
};

// The picker payload: compact arrays, not objects — this replaces 1,320 <option>
// elements, so the page gets lighter, not heavier.
$eqPayload = [];
foreach ($equipList as $e) {
    $eqPayload[] = [(string)$e['equipment_id'], (string)$e['equipment_name'],
                    (string)($e['asset_tag'] ?? ''), (string)($e['location'] ?? '')];
}
$eqJson = json_encode($eqPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Preventive Maintenance — Admin</title>
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="assets/css/admin-shell.css">
<style>

  :root{--m:#7B1D1D;--md:#4A0E0E;--g:#C9960C;--ink:#1A0808;--ink2:#5C3838;--ink3:#9C7A7A;--paper:#F4EFE6;--surface:#fff;--border:#E5D9C6;--sb:262px;--danger:#B42318;--success:#1A7A33;--m1:#2D0505;--g2:#D4A017;--g3:#F0C040;--r1:8px;--r2:12px;}
  *{box-sizing:border-box}
  body{margin:0;font-family:'DM Sans',sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;}
  /* sidebar styling lives in assets/css/admin-shell.css */
  .main{margin-left:var(--sb);transition:margin-left .26s ease;}
  body.becSbHide .main{margin-left:0 !important;}
  /* Header */
  .wrap{max-width:none;margin:0;padding:24px 28px 64px;} /* full-width desktop view */
  .head-row{display:flex;align-items:flex-start;justify-content:space-between;gap:1.5rem;margin-bottom:18px;}
  .head{margin-bottom:0;}
  .unit-badge{display:inline-flex;align-items:center;gap:.35rem;vertical-align:middle;margin-left:.55rem;padding:.22rem .6rem;border-radius:999px;background:linear-gradient(135deg,var(--m),#a01a2b);color:#fff;font-family:'DM Sans',sans-serif;font-size:.62rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;box-shadow:0 2px 8px rgba(122,18,32,.25);}
  .unit-badge i{font-size:.6rem;}
  .head-acts{display:flex;gap:.5rem;flex-shrink:0;}
  .flash{display:flex;align-items:center;gap:.55rem;padding:.85rem 1rem;border-radius:11px;margin-bottom:1.1rem;font-size:.86rem;font-weight:600;}
  .flash.ok{background:#E9F9EF;border:1px solid #b6e6c6;color:var(--success);}
  .flash.err{background:#FEF2F2;border:1px solid #FECACA;color:var(--danger);}
  /* Stat cards — each one is the filter it describes */
  .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;}
  .stat{position:relative;overflow:hidden;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:1.15rem 1.3rem;display:flex;align-items:center;gap:1rem;box-shadow:0 1px 2px rgba(44,10,10,.05);transition:transform .26s cubic-bezier(.4,0,.2,1),box-shadow .26s;text-decoration:none;color:inherit;}
  .stat::before{content:'';position:absolute;top:-24px;right:-24px;width:90px;height:90px;border-radius:50%;background:var(--sk,var(--m));opacity:.05;transition:transform .3s,opacity .3s;}
  .stat::after{content:'';position:absolute;left:0;bottom:0;width:100%;height:3px;background:var(--sk,var(--m));transform:scaleX(0);transform-origin:left;transition:transform .32s;}
  .stat:hover{box-shadow:0 12px 30px rgba(44,10,10,.12);}
  .stat:hover::before{opacity:.09;}
  .stat:hover::after,.stat.on::after{transform:scaleX(1);}
  .stat.on{border-color:var(--sk,var(--m));}
  .stat .ic{position:relative;z-index:1;width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;}
  .stat .n{position:relative;z-index:1;font-family:'Outfit',sans-serif;font-size:2rem;font-weight:800;line-height:1;color:var(--ink);}
  .stat .l{position:relative;z-index:1;font-size:.62rem;text-transform:uppercase;letter-spacing:.6px;color:var(--ink3);font-weight:700;margin-top:.35rem;}
  .stat.s-m{--sk:var(--m);} .stat.s-m .ic{background:rgba(123,29,29,.1);color:var(--m);} .stat.s-m .n{color:var(--m);}
  .stat.s-a{--sk:#D97706;} .stat.s-a .ic{background:rgba(201,150,12,.16);color:#B45309;}
  .stat.s-a.warn .n{color:#B45309;}
  .stat.s-d{--sk:var(--danger);} .stat.s-d .ic{background:#FEF2F2;color:var(--danger);}
  .stat.s-d.warn .n{color:var(--danger);}
  .stat.s-g{--sk:var(--success);} .stat.s-g .ic{background:#E9F9EF;color:var(--success);} .stat.s-g .n{color:var(--success);}
  .stat .go{position:absolute;right:.9rem;bottom:.7rem;z-index:1;font-size:.6rem;color:var(--ink3);opacity:0;transition:opacity .2s;}
  .stat:hover .go{opacity:1;}
  /* Panels */
  .panel{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.3rem;margin-bottom:1.4rem;box-shadow:0 1px 2px rgba(44,10,10,.05);}
  .panel h2{margin:0 0 1rem;font-size:1rem;color:var(--ink);display:flex;align-items:center;gap:.6rem;padding-bottom:.8rem;border-bottom:1px solid var(--border);}
  .panel h2 > i{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,rgba(123,29,29,.1),rgba(201,150,12,.12));color:var(--m);display:inline-flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;}
  .panel h2 .count{margin-left:auto;font-size:.66rem;font-weight:700;color:var(--ink3);background:#f4ede1;border:1px solid var(--border);border-radius:999px;padding:.18rem .65rem;text-transform:none;letter-spacing:0;}
  .panel h2 .hact{margin-left:auto;display:flex;gap:.4rem;align-items:center;}
  .panel h2 .hact .count{margin-left:0;}
  #newPanel[hidden]{display:none;}
  /* Form */
  .fgrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;}
  .fg{display:flex;flex-direction:column;gap:.4rem;min-width:0;}.fg.full{grid-column:1/-1;}
  label{font-size:.7rem;font-weight:700;color:var(--ink2);text-transform:uppercase;letter-spacing:.5px;}
  input,select,textarea{width:100%;max-width:100%;padding:.7rem .85rem;border:1.5px solid var(--border);border-radius:10px;font:inherit;font-size:.92rem;background:#fff;color:var(--ink);transition:border-color .15s,box-shadow .15s;}
  select{text-overflow:ellipsis;}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--m);box-shadow:0 0 0 3px rgba(123,29,29,.1);}
  textarea{resize:vertical;min-height:62px;}
  .freq-row{display:flex;gap:.5rem;}
  .freq-row select{flex:1;min-width:0;}
  .freq-row input{width:7.5rem;flex:none;}
  .fhint{font-size:.72rem;color:var(--ink3);line-height:1.45;}
  .fhint b{color:var(--ink2);font-weight:700;}
  .form-actions{margin-top:1.25rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;}
  .form-actions .hint{font-size:.72rem;color:var(--ink3);margin-left:auto;}
  .btn{display:inline-flex;align-items:center;gap:.5rem;padding:.68rem 1.2rem;border-radius:10px;border:none;font:inherit;font-weight:700;font-size:.84rem;cursor:pointer;text-decoration:none;transition:background .15s,color .15s;}
  .btn.m{background:var(--m);color:#fff;} .btn.m:hover{background:var(--md);}
  .btn.ghost{background:#f1eadf;color:var(--ink2);} .btn.ghost:hover{background:#e7dac6;}
  .btn.sm{padding:.42rem .8rem;font-size:.75rem;border-radius:9px;}
  .btn.danger{background:var(--danger);color:#fff;} .btn.danger:hover{background:#8f1d13;}
  /* Template chips */
  .tpl-row{display:flex;flex-wrap:wrap;gap:.45rem;align-items:center;margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px dashed var(--border);}
  .tpl-row .tl{font-size:.7rem;font-weight:700;color:var(--ink2);text-transform:uppercase;letter-spacing:.5px;margin-right:.2rem;}
  .tpl{display:inline-flex;align-items:center;gap:.4rem;background:#faf6ee;border:1px solid var(--border);border-radius:999px;padding:.35rem .8rem;font:inherit;font-size:.76rem;font-weight:600;color:var(--ink2);cursor:pointer;transition:border-color .15s,background .15s,color .15s;}
  .tpl:hover{background:#fff;border-color:var(--m);color:var(--m);}
  .tpl i{color:var(--g);font-size:.75rem;}
  .tpl:hover i{color:var(--m);}
  /* Equipment combobox */
  .cbx{position:relative;}
  .cbx-in{position:relative;display:flex;align-items:center;}
  .cbx-in > i.fa-magnifying-glass{position:absolute;left:.8rem;color:var(--ink3);font-size:.8rem;pointer-events:none;}
  .cbx-in input{padding-left:2.2rem;padding-right:2.2rem;}
  .cbx-clr{position:absolute;right:.4rem;width:1.7rem;height:1.7rem;border:none;background:transparent;color:var(--ink3);border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;}
  .cbx-clr:hover{background:#f1eadf;color:var(--danger);}
  .cbx-list{position:absolute;z-index:60;top:calc(100% + 4px);left:0;right:0;max-height:17rem;overflow-y:auto;background:#fff;border:1.5px solid var(--border);border-radius:10px;box-shadow:0 14px 34px rgba(44,10,10,.16);padding:.3rem;}
  .cbx-list[hidden]{display:none;}
  .cbx-opt{display:block;width:100%;text-align:left;padding:.5rem .6rem;border:none;background:transparent;border-radius:8px;cursor:pointer;font:inherit;font-size:.84rem;color:var(--ink);}
  .cbx-opt:hover,.cbx-opt.on{background:#faf3e8;}
  .cbx-opt .en{font-weight:700;display:block;}
  .cbx-opt .es{display:block;font-size:.72rem;color:var(--ink3);margin-top:.1rem;}
  .cbx-opt mark{background:rgba(201,150,12,.28);color:inherit;border-radius:3px;padding:0 .1em;}
  .cbx-none{padding:.75rem .6rem;font-size:.8rem;color:var(--ink3);}
  .cbx-more{padding:.45rem .6rem;font-size:.72rem;color:var(--ink3);border-top:1px solid var(--border);margin-top:.2rem;}
  .cbx-sel{display:flex;align-items:center;gap:.5rem;margin-top:.45rem;padding:.5rem .7rem;background:#f6fbf7;border:1px solid #cfe9d7;border-radius:9px;font-size:.8rem;color:#1c5c2e;}
  .cbx-sel[hidden]{display:none;}
  .cbx-sel i{color:var(--success);}
  .cbx-sel .sn{font-weight:700;}
  .cbx-sel .ss{color:#4d7a5b;font-size:.74rem;}
  .cbx.bad input[type=text]{border-color:var(--danger);}
  /* Filter bar */
  .fbar{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:.8rem 1.1rem;margin-bottom:1.1rem;display:flex;gap:.55rem;align-items:center;flex-wrap:wrap;box-shadow:0 1px 2px rgba(44,10,10,.05);}
  .fsw{position:relative;flex:1;min-width:190px;}
  .fsw i{position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:var(--ink3);font-size:.72rem;pointer-events:none;}
  .fsi{width:100%;padding:.42rem .65rem .42rem 1.8rem;background:#faf7f0;border:1.5px solid var(--border);border-radius:8px;font-size:.79rem;}
  .fsel{padding:.42rem .65rem;background:#faf7f0;border:1.5px solid var(--border);border-radius:8px;font-size:.79rem;color:var(--ink2);cursor:pointer;width:auto;}
  .fcount{font-size:.7rem;color:var(--ink3);white-space:nowrap;margin-left:auto;}
  .fclr{font-size:.72rem;color:var(--m);text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:.3rem;}
  .fclr:hover{text-decoration:underline;}
  /* Table */
  .tbl-wrap{border:1px solid var(--border);border-radius:12px;overflow:hidden;overflow-x:auto;}
  table{width:100%;border-collapse:collapse;font-size:.84rem;}
  th{text-align:left;padding:.7rem .75rem;background:var(--md);color:#fff;font-size:.63rem;text-transform:uppercase;letter-spacing:.5px;font-weight:800;white-space:nowrap;}
  th .sth{color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;}
  th .sth i{opacity:.45;font-size:.62rem;}
  th .sth:hover i,th .sth.on i{opacity:1;color:var(--g3);}
  td{padding:.7rem .75rem;border-bottom:1px solid var(--border);vertical-align:middle;}
  tbody tr:last-child td{border-bottom:none;}
  tbody tr:nth-child(even) td{background:#faf7f0;}
  tbody tr:hover td{background:#fbf3e6;}
  tbody tr.paused td{opacity:.72;}
  .t-sub{color:#9E8070;font-size:.72rem;}
  .pill{display:inline-flex;align-items:center;gap:.3rem;font-size:.6rem;font-weight:800;padding:.22rem .6rem;border-radius:999px;text-transform:uppercase;letter-spacing:.3px;}
  .pill.active{background:#E9F9EF;color:#166534;}.pill.active::before{content:'';width:6px;height:6px;border-radius:50%;background:#16A34A;}
  .pill.paused{background:#F1F1F1;color:#777;}.pill.paused::before{content:'';width:6px;height:6px;border-radius:50%;background:#9ca3af;}
  .prio{display:inline-block;font-size:.6rem;font-weight:800;padding:.2rem .5rem;border-radius:6px;text-transform:uppercase;letter-spacing:.3px;}
  .prio.critical{background:#FEF2F2;color:#991B1B;}.prio.high{background:#FFF7ED;color:#C2410C;}.prio.medium{background:#FFFBEB;color:#92600A;}.prio.low{background:#F0FDF4;color:#166534;}
  .due-soon{color:var(--danger);font-weight:800;}
  .due-badge{display:inline-block;margin-top:.28rem;font-size:.58rem;font-weight:800;padding:.15rem .48rem;border-radius:6px;letter-spacing:.2px;text-transform:uppercase;}
  .due-badge.over{background:#FEF2F2;color:#991B1B;}
  .due-badge.soon{background:#FFFBEB;color:#92600A;}
  .due-badge.far{background:#F0FDF4;color:#166534;}
  .due-badge.paused{background:#F1F1F1;color:#8A8A8A;}
  .gen{display:inline-flex;flex-direction:column;gap:.15rem;text-decoration:none;color:var(--m);font-weight:700;font-size:.8rem;}
  .gen:hover .gn{text-decoration:underline;}
  .gen .gs{font-size:.7rem;color:var(--ink3);font-weight:600;}
  .gen-none{color:var(--ink3);font-size:.78rem;}
  .unit-tag{display:inline-block;font-size:.58rem;font-weight:800;padding:.12rem .42rem;border-radius:5px;background:#f1eadf;color:var(--ink2);letter-spacing:.3px;margin-left:.35rem;}
  /* Row actions */
  .iact{background:#fff;border:1px solid var(--border);border-radius:9px;width:34px;height:34px;cursor:pointer;color:var(--ink2);transition:background .15s,border-color .15s,color .15s;padding:0;}
  .iact + .iact,form + .iact,.iact + form{margin-left:.15rem;}
  .iact:hover{background:#f1eadf;border-color:var(--m);color:var(--m);}
  .iact.del:hover{background:#FEF2F2;border-color:var(--danger);color:var(--danger);}
  /* Empty state */
  .empty{text-align:center;color:var(--ink3);padding:2.2rem 1rem 1rem;}
  .empty i.big{color:var(--g);opacity:.75;font-size:1.8rem;display:block;margin-bottom:.6rem;}
  .empty h3{margin:.2rem 0 .35rem;font-size:1rem;color:var(--ink);font-family:'Outfit',sans-serif;}
  .empty p{margin:0 auto 1.4rem;max-width:44rem;font-size:.82rem;line-height:1.6;}
  .qs{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:.7rem;max-width:60rem;margin:0 auto;text-align:left;}
  .qs-card{display:flex;gap:.7rem;align-items:flex-start;padding:.85rem .9rem;background:#faf7f0;border:1px solid var(--border);border-radius:12px;cursor:pointer;font:inherit;text-align:left;transition:border-color .15s,background .15s,transform .15s;}
  .qs-card:hover{background:#fff;border-color:var(--m);transform:translateY(-2px);}
  .qs-card .qi{width:34px;height:34px;border-radius:10px;background:rgba(201,150,12,.16);color:#B45309;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.9rem;}
  .qs-card .qt{font-weight:700;font-size:.82rem;color:var(--ink);line-height:1.3;}
  .qs-card .qm{font-size:.72rem;color:var(--ink3);margin-top:.2rem;}
  /* Modals */
  .ovl{position:fixed;inset:0;background:rgba(26,8,8,.5);backdrop-filter:blur(3px);z-index:900;display:flex;align-items:flex-start;justify-content:center;padding:4vh 1rem;overflow-y:auto;}
  .ovl[hidden]{display:none;}
  .dlg{background:var(--surface);border-radius:16px;width:100%;max-width:640px;box-shadow:0 26px 70px rgba(26,8,8,.34);overflow:hidden;}
  .dlg.narrow{max-width:440px;}
  .dlg-h{display:flex;align-items:center;gap:.6rem;padding:1.05rem 1.3rem;border-bottom:1px solid var(--border);font-family:'Outfit',sans-serif;font-weight:700;font-size:.98rem;}
  .dlg-h i{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,rgba(123,29,29,.1),rgba(201,150,12,.12));color:var(--m);display:inline-flex;align-items:center;justify-content:center;font-size:.85rem;}
  .dlg-h .x{margin-left:auto;background:transparent;border:none;color:var(--ink3);font-size:1rem;cursor:pointer;width:2rem;height:2rem;border-radius:8px;padding:0;}
  .dlg-h .x:hover{background:#f1eadf;color:var(--ink);}
  .dlg-b{padding:1.3rem;}
  .dlg-f{display:flex;gap:.6rem;justify-content:flex-end;padding:1rem 1.3rem;border-top:1px solid var(--border);background:#faf7f0;}
  .dlg-eq{display:flex;align-items:center;gap:.55rem;padding:.65rem .8rem;background:#faf7f0;border:1px solid var(--border);border-radius:10px;font-size:.82rem;margin-bottom:1rem;}
  .dlg-eq i{color:var(--ink3);}
  .dlg-eq .dl{font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;color:var(--ink3);font-weight:700;}
  .dlg-warn{font-size:.82rem;line-height:1.6;color:var(--ink2);}
  .dlg-warn strong{color:var(--ink);}
  @media(max-width:1180px){.cards{grid-template-columns:repeat(2,1fr)}}
  @media(max-width:860px){.main{margin-left:0;}.cards{grid-template-columns:1fr}.fgrid{grid-template-columns:1fr}.head-row{flex-direction:column;}}
  @media(max-width:640px){ input,select,textarea,.fi,.fc,.input{ font-size:16px; } } /* prevent iOS zoom */
</style>
</head>
<body>
<?php $activeNav = 'preventive'; require __DIR__ . '/includes/admin_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <div>
        <div class="pg-title">Preventive Maintenance</div>
        <div class="bc">
          <a href="admin_dashboard.php"><i class="fas fa-home"></i></a>
          <i class="fas fa-chevron-right"></i><span>Preventive Maint.</span>
        </div>
      </div>
    </div>
    <div class="wrap">
      <div class="head-row">
        <div class="head">
          <h2>Preventive Maintenance
            <?php if ($adminUnit !== ''): ?><span class="unit-badge"><i class="fas fa-<?php echo $adminUnit === 'ITSO' ? 'laptop-code' : 'building-shield'; ?>"></i> <?php echo pm_e($adminUnit); ?> Admin</span><?php endif; ?>
          </h2>
          <p><?php if ($adminUnit !== '' && !$unitExplicit): ?>Showing <strong><?php echo pm_e($adminUnit); ?></strong> equipment by default — use the <em>Unit</em> filter to view All or the other office. <?php endif; ?>Recurring maintenance schedules that raise a ticket on their own when they come due.</p>
        </div>
        <div class="head-acts">
          <button class="btn ghost" type="submit" form="runForm"><i class="fas fa-bolt"></i> Generate Due Tickets</button>
          <button class="btn m" type="button" id="newBtn"><i class="fas fa-plus"></i> New Schedule</button>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>">
          <i class="fas fa-<?php echo $flash[0] === 'ok' ? 'circle-check' : 'circle-exclamation'; ?>"></i>
          <?php echo pm_e($flash[1]); ?>
        </div>
      <?php endif; ?>

      <div class="cards">
        <a class="stat s-m<?php echo ($sf === 'active' && $df === 'all') ? ' on' : ''; ?>" href="<?php echo pm_e($keep(['status' => 'active', 'due' => 'all'])); ?>">
          <div class="ic"><i class="fas fa-calendar-check"></i></div>
          <div><div class="n"><?php echo (int)$activeCount; ?></div><div class="l">Active Schedules</div></div>
          <span class="go"><i class="fas fa-arrow-right"></i></span>
        </a>
        <a class="stat s-d<?php echo $overdue > 0 ? ' warn' : ''; ?><?php echo $df === 'overdue' ? ' on' : ''; ?>" href="<?php echo pm_e($keep(['due' => 'overdue', 'status' => 'all'])); ?>">
          <div class="ic"><i class="fas fa-triangle-exclamation"></i></div>
          <div><div class="n"><?php echo (int)$overdue; ?></div><div class="l">Overdue</div></div>
          <span class="go"><i class="fas fa-arrow-right"></i></span>
        </a>
        <a class="stat s-a<?php echo $dueSoon > 0 ? ' warn' : ''; ?><?php echo $df === 'week' ? ' on' : ''; ?>" href="<?php echo pm_e($keep(['due' => 'week', 'status' => 'all'])); ?>">
          <div class="ic"><i class="fas fa-clock"></i></div>
          <div><div class="n"><?php echo (int)$dueSoon; ?></div><div class="l">Due Within 7 Days</div></div>
          <span class="go"><i class="fas fa-arrow-right"></i></span>
        </a>
        <a class="stat s-g" href="admin_defect_reports.php?kind=preventive&amp;dept=all&amp;status=all">
          <div class="ic"><i class="fas fa-circle-check"></i></div>
          <div><div class="n"><?php echo (int)$generatedTotal; ?></div><div class="l">Tickets Generated</div></div>
          <span class="go"><i class="fas fa-arrow-right"></i></span>
        </a>
      </div>

      <!-- ═══ New schedule — collapsed by default; the list is what this page is for ═══ -->
      <div class="panel" id="newPanel" <?php echo ($openForm || !$scoped) ? '' : 'hidden'; ?>>
        <h2><i class="fas fa-calendar-plus"></i> New Preventive Schedule
          <span class="hact"><button class="btn ghost sm" type="button" id="newClose"><i class="fas fa-xmark"></i> Close</button></span>
        </h2>
        <div class="tpl-row">
          <span class="tl">Start from</span>
          <?php foreach ($templates as $i => $tp): ?>
            <button class="tpl" type="button" data-tpl="<?php echo (int)$i; ?>"><i class="fas <?php echo pm_e($tp['ic']); ?>"></i> <?php echo pm_e($tp['t']); ?></button>
          <?php endforeach; ?>
        </div>
        <form method="POST" id="createForm">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="create">
          <div class="fgrid">
            <div class="fg full">
              <label for="cTitle">Task Title *</label>
              <input type="text" id="cTitle" name="title" placeholder="e.g. Aircon cleaning &amp; filter check" value="<?php echo pm_e($fv['title']); ?>" required>
            </div>

            <div class="fg full">
              <label for="eqQ">Target Equipment *</label>
              <div class="cbx" id="eqBox">
                <input type="hidden" name="equipment_id" id="eqId" value="<?php echo pm_e($fv['equipment_id']); ?>">
                <div class="cbx-in">
                  <i class="fas fa-magnifying-glass"></i>
                  <input type="text" id="eqQ" role="combobox" aria-expanded="false" aria-controls="eqList"
                         aria-autocomplete="list" autocomplete="off" spellcheck="false"
                         placeholder="Search <?php echo number_format(count($equipList)); ?> items by name, asset tag or room…">
                  <button type="button" class="cbx-clr" id="eqClr" aria-label="Clear selected equipment" hidden><i class="fas fa-xmark"></i></button>
                </div>
                <div class="cbx-list" id="eqList" role="listbox" aria-label="Matching equipment" hidden></div>
                <div class="cbx-sel" id="eqSel" hidden>
                  <i class="fas fa-circle-check"></i>
                  <span><span class="sn" id="eqSelName"></span> <span class="ss" id="eqSelSub"></span></span>
                </div>
              </div>
            </div>

            <div class="fg">
              <label for="cFreq">Frequency *</label>
              <div class="freq-row">
                <select id="cFreq" name="frequency_days" required>
                  <?php foreach ($presets as $days => $lbl): ?>
                    <option value="<?php echo (int)$days; ?>" <?php echo (int)$fv['frequency_days'] === (int)$days ? 'selected' : ''; ?>><?php echo pm_e($lbl); ?> (<?php echo (int)$days; ?> days)</option>
                  <?php endforeach; ?>
                  <option value="custom" <?php echo !isset($presets[(int)$fv['frequency_days']]) ? 'selected' : ''; ?>>Custom…</option>
                </select>
                <input type="number" id="cFreqN" name="frequency_custom" min="1" max="3650" placeholder="days"
                       value="<?php echo !isset($presets[(int)$fv['frequency_days']]) ? (int)$fv['frequency_days'] : ''; ?>"
                       aria-label="Custom frequency in days" <?php echo isset($presets[(int)$fv['frequency_days']]) ? 'hidden' : ''; ?>>
              </div>
            </div>

            <div class="fg">
              <label for="cDue">First Due Date *</label>
              <input type="date" id="cDue" name="next_due" value="<?php echo pm_e($fv['next_due']); ?>" required>
            </div>

            <div class="fg">
              <label for="cPrio">Priority</label>
              <select id="cPrio" name="priority">
                <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $pv => $pl): ?>
                  <option value="<?php echo $pv; ?>" <?php echo $fv['priority'] === $pv ? 'selected' : ''; ?>><?php echo $pl; ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="fg">
              <label for="cTech">Auto-assign Technician (optional)</label>
              <select id="cTech" name="assigned_to">
                <option value="">Leave unassigned</option>
                <?php foreach ($techName as $tid => $tn): ?>
                  <option value="<?php echo pm_e($tid); ?>" <?php echo $fv['assigned_to'] === $tid ? 'selected' : ''; ?>><?php echo pm_e($tn); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="fg full">
              <label for="cIns">Instructions (optional)</label>
              <textarea id="cIns" name="instructions" rows="2" placeholder="Checklist or notes for the technician…"><?php echo pm_e($fv['instructions']); ?></textarea>
            </div>

            <div class="fg full">
              <p class="fhint" id="cPreview"></p>
            </div>
          </div>
          <div class="form-actions">
            <button class="btn m" type="submit"><i class="fas fa-plus"></i> Create Schedule</button>
            <button class="btn ghost" type="button" id="newCancel">Cancel</button>
            <span class="hint">Due schedules also run automatically whenever you open this page or the dashboard.</span>
          </div>
        </form>
      </div>

      <!-- ═══ Schedules ═══ -->
      <div class="fbar">
        <div class="fsw">
          <i class="fas fa-search"></i>
          <input type="text" class="fsi" id="fq" placeholder="Search task, equipment, room…" value="<?php echo pm_e($q); ?>"
                 oninput="pmDebounce()" onkeydown="if(event.key==='Enter'){event.preventDefault();pmGo();}">
        </div>
        <select class="fsel" id="fs" aria-label="Filter by status" onchange="pmGo()">
          <option value="all"    <?php echo $sf === 'all'    ? 'selected' : ''; ?>>All statuses</option>
          <option value="active" <?php echo $sf === 'active' ? 'selected' : ''; ?>>Active only</option>
          <option value="paused" <?php echo $sf === 'paused' ? 'selected' : ''; ?>>Paused only</option>
        </select>
        <select class="fsel" id="fd" aria-label="Filter by due date" onchange="pmGo()">
          <option value="all"     <?php echo $df === 'all'     ? 'selected' : ''; ?>>Any due date</option>
          <option value="overdue" <?php echo $df === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
          <option value="week"    <?php echo $df === 'week'    ? 'selected' : ''; ?>>Due within 7 days</option>
          <option value="month"   <?php echo $df === 'month'   ? 'selected' : ''; ?>>Due within 30 days</option>
        </select>
        <select class="fsel" id="fu" aria-label="Filter by unit" onchange="pmGo()">
          <option value="all"  <?php echo $uf === 'ALL'  ? 'selected' : ''; ?>>All units</option>
          <option value="PMO"  <?php echo $uf === 'PMO'  ? 'selected' : ''; ?>>PMO</option>
          <option value="ITSO" <?php echo $uf === 'ITSO' ? 'selected' : ''; ?>>ITSO</option>
        </select>
        <?php if ($filtersOn): ?>
          <a class="fclr" href="<?php echo pm_e($keep(['q' => '', 'status' => 'all', 'due' => 'all', 'sort' => null, 'dir' => null])); ?>"><i class="fas fa-xmark"></i> Clear</a>
        <?php endif; ?>
        <span class="fcount"><?php echo count($rows); ?> of <?php echo count($scoped); ?> schedule<?php echo count($scoped) !== 1 ? 's' : ''; ?></span>
      </div>

      <div class="panel">
        <h2><i class="fas fa-calendar-check"></i> Schedules
          <span class="count"><?php echo count($rows); ?> shown</span>
        </h2>

        <?php if (!$scoped): ?>
          <!-- Nothing exists yet: offer the four schedules the PMO actually runs,
               one click away from a filled-in form. -->
          <div class="empty">
            <i class="fas fa-calendar-plus big"></i>
            <h3>No preventive schedules yet</h3>
            <p>A schedule raises its own ticket when it comes due, so recurring work stops depending on
               someone remembering it. Pick a starting point below — you only need to choose the equipment.</p>
            <div class="qs">
              <?php foreach ($templates as $i => $tp): ?>
                <button class="qs-card" type="button" data-tpl="<?php echo (int)$i; ?>">
                  <span class="qi"><i class="fas <?php echo pm_e($tp['ic']); ?>"></i></span>
                  <span>
                    <span class="qt"><?php echo pm_e($tp['t']); ?></span>
                    <span class="qm">Every <?php echo (int)$tp['f']; ?> days · <?php echo pm_e(ucfirst($tp['p'])); ?> priority</span>
                  </span>
                </button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php elseif (!$rows): ?>
          <div class="empty">
            <i class="fas fa-filter big"></i>
            <h3>No schedules match these filters</h3>
            <p>Nothing here fits the current search or filters.
               <a href="<?php echo pm_e($keep(['q' => '', 'status' => 'all', 'due' => 'all', 'sort' => null, 'dir' => null])); ?>">Clear them</a> to see all <?php echo count($scoped); ?>.</p>
          </div>
        <?php else: ?>
          <div class="tbl-wrap">
          <table data-paginate="15" data-paginate-noun="schedules">
            <thead><tr>
              <?php
                echo $sortTh('title',  'Task');
                echo $sortTh('equip',  'Equipment');
                echo $sortTh('freq',   'Every');
                echo $sortTh('due',    'Next Due');
                echo $sortTh('gen',    'Generated');
                echo $sortTh('prio',   'Priority');
                echo $sortTh('status', 'Status');
              ?>
              <th>Actions</th>
            </tr></thead>
            <tbody>
              <?php foreach ($rows as $s):
                $sid    = (int)$s['id'];
                $due    = strtotime((string)$s['next_due']);
                $dd     = (int)floor(($due - $today) / 86400);
                $paused = ($s['status'] ?? '') !== 'active';
                $soon   = (!$paused && $dd <= 7);
                if ($paused)       { $bc = 'paused'; $bt = 'Paused'; }
                elseif ($dd < 0)   { $bc = 'over';   $bt = 'Overdue ' . abs($dd) . 'd'; }
                elseif ($dd === 0) { $bc = 'over';   $bt = 'Due today'; }
                else               { $bc = $dd <= 7 ? 'soon' : 'far'; $bt = 'In ' . $dd . 'd'; }
                $gen    = $genMap[$sid] ?? null;
                $editJs = pm_e(json_encode([
                    'id'    => $sid,
                    'title' => (string)$s['title'],
                    'freq'  => (int)$s['frequency_days'],
                    'due'   => (string)$s['next_due'],
                    'prio'  => strtolower((string)$s['priority']),
                    'tech'  => (string)($s['assigned_to'] ?? ''),
                    'ins'   => (string)($s['instructions'] ?? ''),
                    'eq'    => trim(((string)($s['equipment_name'] ?: $s['equipment_id'])) . (($s['location'] ?? '') !== '' ? ' — ' . $s['location'] : '')),
                    'gen'   => $gen['c'] ?? 0,
                ], JSON_UNESCAPED_UNICODE));
              ?>
              <tr class="<?php echo $paused ? 'paused' : ''; ?>">
                <td>
                  <strong><?php echo pm_e($s['title']); ?></strong>
                  <?php if (!empty($techName[(string)($s['assigned_to'] ?? '')])): ?>
                    <div class="t-sub"><i class="fas fa-user-gear"></i> <?php echo pm_e($techName[(string)$s['assigned_to']]); ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php echo pm_e($s['equipment_name'] ?: $s['equipment_id']); ?>
                  <?php if (!empty($s['unit'])): ?><span class="unit-tag"><?php echo pm_e($s['unit']); ?></span><?php endif; ?>
                  <?php if (!empty($s['location'])): ?><div class="t-sub"><?php echo pm_e($s['location']); ?></div><?php endif; ?>
                </td>
                <td><?php echo (int)$s['frequency_days']; ?> days</td>
                <td class="<?php echo ($soon && !$paused) ? 'due-soon' : ''; ?>">
                  <?php echo pm_e(date('M j, Y', $due)); ?>
                  <br><span class="due-badge <?php echo $bc; ?>"><?php echo $bt; ?></span>
                </td>
                <td>
                  <?php if ($gen && $gen['c'] > 0): ?>
                    <a class="gen" href="admin_defect_reports.php?kind=preventive&amp;dept=all&amp;status=all&amp;search=<?php echo urlencode((string)$s['title']); ?>"
                       title="Open the tickets this schedule has generated">
                      <span class="gn"><?php echo (int)$gen['c']; ?> ticket<?php echo $gen['c'] !== 1 ? 's' : ''; ?></span>
                      <?php if (!empty($gen['last_at'])): ?><span class="gs">last <?php echo pm_e(date('M j, Y', strtotime((string)$gen['last_at']))); ?></span><?php endif; ?>
                    </a>
                  <?php else: ?>
                    <span class="gen-none">None yet</span>
                  <?php endif; ?>
                </td>
                <td><span class="prio <?php echo pm_e(strtolower((string)$s['priority'])); ?>"><?php echo pm_e(ucfirst((string)$s['priority'])); ?></span></td>
                <td><span class="pill <?php echo $paused ? 'paused' : 'active'; ?>"><?php echo pm_e($s['status']); ?></span></td>
                <td style="white-space:nowrap;">
                  <button class="iact" type="button" data-edit="<?php echo $editJs; ?>"
                          title="Edit schedule" aria-label="Edit schedule <?php echo pm_e($s['title']); ?>"><i class="fas fa-pen"></i></button>
                  <form method="POST" style="display:inline;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo $sid; ?>">
                    <button class="iact" type="submit"
                            title="<?php echo $paused ? 'Resume' : 'Pause'; ?>"
                            aria-label="<?php echo $paused ? 'Resume' : 'Pause'; ?> schedule <?php echo pm_e($s['title']); ?>"><i class="fas fa-<?php echo $paused ? 'play' : 'pause'; ?>"></i></button>
                  </form>
                  <button class="iact del" type="button" data-del="<?php echo $editJs; ?>"
                          title="Delete schedule" aria-label="Delete schedule <?php echo pm_e($s['title']); ?>"><i class="fas fa-trash"></i></button>
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

  <form method="POST" id="runForm"><?php echo csrf_field(); ?><input type="hidden" name="action" value="run_now"></form>

  <!-- ═══ Edit dialog ═══ -->
  <div class="ovl" id="editOvl" hidden>
    <div class="dlg" role="dialog" aria-modal="true" aria-labelledby="editTitle">
      <div class="dlg-h"><i class="fas fa-pen"></i> <span id="editTitle">Edit Schedule</span>
        <button class="x" type="button" data-close="editOvl" aria-label="Close"><i class="fas fa-xmark"></i></button>
      </div>
      <form method="POST" id="editForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="eId">
        <div class="dlg-b">
          <div class="dlg-eq">
            <i class="fas fa-screwdriver-wrench"></i>
            <span><span class="dl">Equipment</span><br><span id="eEq"></span></span>
          </div>
          <div class="fgrid">
            <div class="fg full"><label for="eTitle">Task Title *</label><input type="text" id="eTitle" name="title" required></div>
            <div class="fg">
              <label for="eFreq">Frequency *</label>
              <div class="freq-row">
                <select id="eFreq" name="frequency_days" required>
                  <?php foreach ($presets as $days => $lbl): ?>
                    <option value="<?php echo (int)$days; ?>"><?php echo pm_e($lbl); ?> (<?php echo (int)$days; ?> days)</option>
                  <?php endforeach; ?>
                  <option value="custom">Custom…</option>
                </select>
                <input type="number" id="eFreqN" name="frequency_custom" min="1" max="3650" placeholder="days" aria-label="Custom frequency in days" hidden>
              </div>
            </div>
            <div class="fg"><label for="eDue">Next Due Date *</label><input type="date" id="eDue" name="next_due" required></div>
            <div class="fg">
              <label for="ePrio">Priority</label>
              <select id="ePrio" name="priority">
                <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="critical">Critical</option>
              </select>
            </div>
            <div class="fg">
              <label for="eTech">Auto-assign Technician</label>
              <select id="eTech" name="assigned_to">
                <option value="">Leave unassigned</option>
                <?php foreach ($techName as $tid => $tn): ?><option value="<?php echo pm_e($tid); ?>"><?php echo pm_e($tn); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="fg full"><label for="eIns">Instructions</label><textarea id="eIns" name="instructions" rows="2"></textarea></div>
            <div class="fg full"><p class="fhint" id="ePreview"></p></div>
          </div>
        </div>
        <div class="dlg-f">
          <button class="btn ghost" type="button" data-close="editOvl">Cancel</button>
          <button class="btn m" type="submit"><i class="fas fa-check"></i> Save Changes</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ═══ Delete confirmation ═══ -->
  <div class="ovl" id="delOvl" hidden>
    <div class="dlg narrow" role="dialog" aria-modal="true" aria-labelledby="delTitle">
      <div class="dlg-h"><i class="fas fa-trash"></i> <span id="delTitle">Delete Schedule</span>
        <button class="x" type="button" data-close="delOvl" aria-label="Close"><i class="fas fa-xmark"></i></button>
      </div>
      <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="dId">
        <div class="dlg-b"><p class="dlg-warn" id="delBody"></p></div>
        <div class="dlg-f">
          <button class="btn ghost" type="button" data-close="delOvl">Cancel</button>
          <button class="btn danger" type="submit"><i class="fas fa-trash"></i> Delete Schedule</button>
        </div>
      </form>
    </div>
  </div>

<?php require_once __DIR__ . '/includes/csrf_inject.php'; ?>
<script src="assets/sidebar_autohide.js" defer></script>
<script src="assets/table_paginate.js" defer></script>
<script src="assets/date_picker.js"></script>
<script>
/* ─── Filter bar ─── */
function pmGo() {
  var u = new URL(location.href);
  var set = function (k, v, dflt) { if (!v || v === dflt) { u.searchParams.delete(k); } else { u.searchParams.set(k, v); } };
  set('q',      document.getElementById('fq').value.trim(), '');
  set('status', document.getElementById('fs').value, 'all');
  set('due',    document.getElementById('fd').value, 'all');
  set('unit',   document.getElementById('fu').value, '');   // "all" is a real choice here: it overrides the admin's own unit
  u.searchParams.delete('new');
  location.href = u.toString();
}
var pmT; function pmDebounce() { clearTimeout(pmT); pmT = setTimeout(pmGo, 450); }

/* ─── New-schedule panel ─── */
var newPanel = document.getElementById('newPanel');
function pmOpenForm(focusEq) {
  newPanel.hidden = false;
  newPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  var t = document.getElementById(focusEq ? 'eqQ' : 'cTitle');
  setTimeout(function () { t.focus(); }, 320);
}
document.getElementById('newBtn').addEventListener('click', function () {
  if (newPanel.hidden) { pmOpenForm(false); } else { newPanel.hidden = true; }
});
['newClose', 'newCancel'].forEach(function (id) {
  document.getElementById(id).addEventListener('click', function () { newPanel.hidden = true; });
});

/* ─── Frequency: presets + custom, with a plain-language recurrence preview ─── */
function pmFreqValue(sel, num) {
  return sel.value === 'custom' ? (parseInt(num.value, 10) || 0) : (parseInt(sel.value, 10) || 0);
}
function pmWirefreq(selId, numId, dueId, outId, firstWord) {
  var sel = document.getElementById(selId), num = document.getElementById(numId),
      due = document.getElementById(dueId), out = document.getElementById(outId);
  function sync() {
    var custom = sel.value === 'custom';
    num.hidden = !custom;
    if (custom && !num.value) { num.value = 60; }
    var days = pmFreqValue(sel, num), d = due.value ? new Date(due.value + 'T00:00:00') : null;
    if (!days || !d || isNaN(d)) { out.textContent = ''; return; }
    var fmt = function (x) { return x.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); };
    var nxt = new Date(d.getTime()); nxt.setDate(nxt.getDate() + days);
    var after = new Date(nxt.getTime()); after.setDate(after.getDate() + days);
    out.innerHTML = '<i class="fas fa-repeat"></i> ' + firstWord + ' <b>' + fmt(d) + '</b>, then every <b>' + days +
                    ' days</b> — next two on ' + fmt(nxt) + ' and ' + fmt(after) + '.';
  }
  sel.addEventListener('change', sync);
  num.addEventListener('input', sync);
  due.addEventListener('change', sync);
  due.addEventListener('input', sync);
  sync();
  return sync;
}
var cSync = pmWirefreq('cFreq', 'cFreqN', 'cDue', 'cPreview', 'First ticket on');
var eSync = pmWirefreq('eFreq', 'eFreqN', 'eDue', 'ePreview', 'Next ticket on');

/* ─── Templates ─── */
var PM_TPL = <?php echo json_encode(array_map(fn($t) => ['t' => $t['t'], 'f' => $t['f'], 'p' => $t['p'], 'i' => $t['i']], $templates), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
document.querySelectorAll('[data-tpl]').forEach(function (b) {
  b.addEventListener('click', function () {
    var t = PM_TPL[parseInt(b.getAttribute('data-tpl'), 10)];
    if (!t) return;
    document.getElementById('cTitle').value = t.t;
    document.getElementById('cIns').value = t.i;
    document.getElementById('cPrio').value = t.p;
    var sel = document.getElementById('cFreq'), num = document.getElementById('cFreqN');
    var hasPreset = Array.prototype.some.call(sel.options, function (o) { return o.value === String(t.f); });
    if (hasPreset) { sel.value = String(t.f); } else { sel.value = 'custom'; num.value = t.f; }
    cSync();
    pmOpenForm(true);   // everything else is filled — the equipment is all that's left
  });
});

/* ─── Equipment combobox ───
   Replaces a <select> that carried every equipment row as an <option>: with
   1,320 of them, finding one meant scrolling a native dropdown. Same data,
   fewer bytes — the old option markup measured 214 KB against 159 KB of
   compact arrays for a unit-scoped admin — and it searches name, asset tag,
   room and ID at once. */
var PM_EQ = <?php echo $eqJson ?: '[]'; ?>;
(function () {
  var box = document.getElementById('eqBox'), q = document.getElementById('eqQ'),
      hid = document.getElementById('eqId'), list = document.getElementById('eqList'),
      clr = document.getElementById('eqClr'), sel = document.getElementById('eqSel'),
      selN = document.getElementById('eqSelName'), selS = document.getElementById('eqSelSub');
  var MAX = 50, cur = -1, shown = [];

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }
  function mark(s, terms) {
    var out = esc(s);
    terms.forEach(function (t) {
      if (!t) return;
      out = out.replace(new RegExp('(' + t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig'), '<mark>$1</mark>');
    });
    return out;
  }
  function sub(e) { return [e[2], e[3]].filter(Boolean).join(' · '); }

  function close() { list.hidden = true; q.setAttribute('aria-expanded', 'false'); cur = -1; }

  function render() {
    var terms = q.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    if (!terms.length) { shown = PM_EQ.slice(0, MAX); }
    else {
      shown = [];
      for (var i = 0; i < PM_EQ.length && shown.length <= MAX; i++) {
        var e = PM_EQ[i], hay = (e[1] + ' ' + e[2] + ' ' + e[3] + ' ' + e[0]).toLowerCase();
        if (terms.every(function (t) { return hay.indexOf(t) !== -1; })) { shown.push(e); }
      }
    }
    var more = shown.length > MAX;
    if (more) { shown = shown.slice(0, MAX); }
    if (!shown.length) {
      list.innerHTML = '<div class="cbx-none">No equipment matches “' + esc(q.value.trim()) + '”.</div>';
    } else {
      var html = '';
      shown.forEach(function (e, i) {
        html += '<button class="cbx-opt" type="button" role="option" data-i="' + i + '">' +
                '<span class="en">' + mark(e[1], terms) + '</span>' +
                '<span class="es">' + mark(sub(e), terms) + '</span></button>';
      });
      if (more) { html += '<div class="cbx-more">Showing the first ' + MAX + ' matches — keep typing to narrow.</div>'; }
      list.innerHTML = html;
    }
    list.hidden = false;
    q.setAttribute('aria-expanded', 'true');
    cur = -1;
    list.scrollTop = 0;
  }

  function pick(e) {
    hid.value = e[0];
    q.value = '';
    q.placeholder = 'Change equipment…';
    selN.textContent = e[1];
    selS.textContent = sub(e);
    sel.hidden = false;
    clr.hidden = false;
    box.classList.remove('bad');
    close();
  }
  function clear() {
    hid.value = ''; sel.hidden = true; clr.hidden = true; q.value = '';
    q.placeholder = 'Search <?php echo number_format(count($equipList)); ?> items by name, asset tag or room…';
    q.focus();
  }

  function highlight(n) {
    var opts = list.querySelectorAll('.cbx-opt');
    if (!opts.length) return;
    if (cur >= 0 && opts[cur]) { opts[cur].classList.remove('on'); }
    cur = (n + opts.length) % opts.length;
    opts[cur].classList.add('on');
    opts[cur].scrollIntoView({ block: 'nearest' });
  }

  q.addEventListener('focus', render);
  q.addEventListener('input', render);
  q.addEventListener('keydown', function (ev) {
    if (ev.key === 'ArrowDown') { ev.preventDefault(); if (list.hidden) { render(); } highlight(cur + 1); }
    else if (ev.key === 'ArrowUp') { ev.preventDefault(); highlight(cur - 1); }
    else if (ev.key === 'Enter') {
      if (!list.hidden && cur >= 0 && shown[cur]) { ev.preventDefault(); pick(shown[cur]); }
      else if (!list.hidden && shown.length === 1) { ev.preventDefault(); pick(shown[0]); }
    } else if (ev.key === 'Escape') { close(); }
  });
  list.addEventListener('mousedown', function (ev) {
    var b = ev.target.closest('.cbx-opt'); if (!b) return;
    ev.preventDefault();
    pick(shown[parseInt(b.getAttribute('data-i'), 10)]);
  });
  clr.addEventListener('click', clear);
  document.addEventListener('click', function (ev) { if (!box.contains(ev.target)) { close(); } });

  // A hidden input cannot be validated by the browser, so the guard is here.
  document.getElementById('createForm').addEventListener('submit', function (ev) {
    if (hid.value.trim() !== '') return;
    ev.preventDefault();
    box.classList.add('bad');
    q.focus();
    q.placeholder = 'Pick the equipment this schedule covers';
  });

  // Repopulate after a failed submit, so nothing typed is lost.
  if (hid.value) {
    for (var i = 0; i < PM_EQ.length; i++) { if (PM_EQ[i][0] === hid.value) { pick(PM_EQ[i]); break; } }
  }
})();

/* ─── Dialogs ─── */
function pmClose(id) { document.getElementById(id).hidden = true; }
document.querySelectorAll('[data-close]').forEach(function (b) {
  b.addEventListener('click', function () { pmClose(b.getAttribute('data-close')); });
});
['editOvl', 'delOvl'].forEach(function (id) {
  var o = document.getElementById(id);
  o.addEventListener('mousedown', function (ev) { if (ev.target === o) { o.hidden = true; } });
});
document.addEventListener('keydown', function (ev) {
  if (ev.key !== 'Escape') return;
  ['editOvl', 'delOvl'].forEach(function (id) { document.getElementById(id).hidden = true; });
});

document.querySelectorAll('[data-edit]').forEach(function (b) {
  b.addEventListener('click', function () {
    var s = JSON.parse(b.getAttribute('data-edit'));
    document.getElementById('eId').value    = s.id;
    document.getElementById('eTitle').value = s.title;
    document.getElementById('eDue').value   = s.due;
    document.getElementById('ePrio').value  = s.prio || 'medium';
    document.getElementById('eTech').value  = s.tech || '';
    document.getElementById('eIns').value   = s.ins || '';
    var sel = document.getElementById('eFreq'), num = document.getElementById('eFreqN');
    var hasPreset = Array.prototype.some.call(sel.options, function (o) { return o.value === String(s.freq); });
    if (hasPreset) { sel.value = String(s.freq); num.value = ''; } else { sel.value = 'custom'; num.value = s.freq; }
    document.getElementById('eEq').textContent = s.eq;
    eSync();
    document.getElementById('editOvl').hidden = false;
    setTimeout(function () { document.getElementById('eTitle').focus(); }, 60);
  });
});

document.querySelectorAll('[data-del]').forEach(function (b) {
  b.addEventListener('click', function () {
    var s = JSON.parse(b.getAttribute('data-del'));
    document.getElementById('dId').value = s.id;
    document.getElementById('delBody').innerHTML =
      'Delete <strong>' + s.title.replace(/[&<>]/g, '') + '</strong>? It will stop generating tickets.' +
      (s.gen > 0 ? ' The <strong>' + s.gen + ' ticket' + (s.gen !== 1 ? 's' : '') + '</strong> it already created stay in Defect Reports.' : '');
    document.getElementById('delOvl').hidden = false;
  });
});
</script>
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<?php require __DIR__ . '/includes/admin_ui.php'; ?>
</body>
</html>
