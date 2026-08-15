<?php
/**
 * admin_reservations.php — Venue Reservation Forms, the PMO's side.
 *
 * The queue the paper folder used to be: what has been requested, what is
 * waiting on a signature, what the office approved, and whether it has been
 * paid. The decision boxes on the form (PMO approval, School Administrator
 * disapproval, Accounting assessment, Cashier's OR number) are the actions here.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/reservation_helper.php';
requireRole('admin');

$admin_id   = $_SESSION['user_id'] ?? '';
$admin_name = $_SESSION['fullname'] ?? 'Administrator';
$pdo = getPgsqlPdoConnection();
function rv_e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ─── Filters ─── */
$q  = trim((string)($_GET['q'] ?? ''));
$sf = strtolower(trim((string)($_GET['status'] ?? 'open')));   // open | all | <status>
$wf = strtolower(trim((string)($_GET['when'] ?? 'all')));      // all | upcoming | past
if (!array_key_exists($sf, vrStatuses()) && !in_array($sf, ['open', 'all'], true)) { $sf = 'open'; }
if (!in_array($wf, ['all', 'upcoming', 'past'], true)) { $wf = 'all'; }
$filtersOn = ($q !== '' || $sf !== 'open' || $wf !== 'all');

$keep = function (array $over = []) use ($q, $sf, $wf) {
    $p = array_merge(['q' => $q, 'status' => $sf, 'when' => $wf], $over);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null && $v !== 'all');
    if (($p['status'] ?? '') === 'open') { unset($p['status']); }
    return 'admin_reservations.php' . ($p ? '?' . http_build_query($p) : '');
};

/* ─── Actions ───
   Every write redirects (POST/redirect/GET) so a refresh cannot approve the
   same request twice or issue a second VRF number for it. */
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = (string)($_POST['action'] ?? '');
    $id  = (int)($_POST['id'] ?? 0);
    $row = null;
    if ($id > 0) {
        $st = $pdo->prepare("SELECT * FROM public.venue_reservations WHERE id = :id");
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $done = null;

    if (!$row) {
        $flash = ['err', 'That reservation could not be found — reload the page and try again.'];
    } elseif ($act === 'endorse') {
        $adviser = trim((string)($_POST['adviser_name'] ?? ''));
        if ($adviser === '') {
            $flash = ['err', 'Record who endorsed it — the form needs the department head or adviser named.'];
        } else {
            $pdo->prepare("UPDATE public.venue_reservations
                              SET adviser_name = :a, adviser_endorsed_at = now(),
                                  status = CASE WHEN status = 'submitted' THEN 'endorsed' ELSE status END,
                                  updated_at = now()
                            WHERE id = :id")->execute(['a' => $adviser, 'id' => $id]);
            $done = ['ok', 'Endorsement recorded for ' . $row['venue'] . '.'];
        }
    } elseif ($act === 'approve') {
        // Re-check the clash at the moment of approval, not only when it was
        // filed: another request for the same room may have been approved since.
        $clash = vrConflicts($pdo, (string)$row['venue'], (string)$row['starts_at'], (string)$row['ends_at'], $id);
        if ($clash) {
            $c = $clash[0];
            $flash = ['err', 'Not approved — ' . $row['venue'] . ' is already held by '
                           . ($c['vrf_no'] ?: '#' . $c['id']) . ' (' . $c['applicant_name'] . ') for '
                           . vrRange($c['starts_at'], $c['ends_at']) . '.'];
        } else {
            $vrf = trim((string)$row['vrf_no']) !== '' ? (string)$row['vrf_no'] : vrNextNumber($pdo);
            try {
                $pdo->prepare("UPDATE public.venue_reservations
                                  SET status = 'approved', vrf_no = :v, approved_by = :by, approved_at = now(),
                                      disapproved_by = NULL, disapproved_at = NULL,
                                      decision_remarks = :rm, updated_at = now()
                                WHERE id = :id")
                    ->execute(['v' => $vrf, 'by' => $admin_name, 'rm' => trim((string)($_POST['remarks'] ?? '')) ?: null, 'id' => $id]);
                if (function_exists('logActivity')) { try { logActivity($admin_id, 'admin', 'vrf.approve', 'Approved reservation ' . $vrf . ' — ' . $row['venue']); } catch (\Throwable $e) {} }
                // The applicant is told the outcome. Mail never blocks the
                // decision — it is already saved above.
                $row['vrf_no'] = $vrf;
                $row['decision_remarks'] = trim((string)($_POST['remarks'] ?? '')) ?: null;
                $mailed = vrNotifyApplicant($row, 'approved');
                $done = ['ok', 'Approved as ' . $vrf . '. The venue is now held for ' . vrRange($row['starts_at'], $row['ends_at']) . '.'
                             . ($mailed ? ' The applicant has been emailed.'
                                        : (trim((string)$row['applicant_email']) === '' ? ' No email on file, so tell them directly.'
                                                                                        : ' The email could not be sent — it is queued for retry.'))];
            } catch (\Throwable $e) {
                // The exclusion constraint is the last word if two approvals race.
                $flash = ['err', 'Not approved — the venue was taken for that window while this was open. Reload to see the current holder.'];
            }
        }
    } elseif ($act === 'disapprove') {
        $rm = trim((string)($_POST['remarks'] ?? ''));
        if ($rm === '') {
            $flash = ['err', 'Give a reason — the applicant is told why, and the form records it.'];
        } else {
            $pdo->prepare("UPDATE public.venue_reservations
                              SET status = 'disapproved', disapproved_by = :by, disapproved_at = now(),
                                  decision_remarks = :rm, updated_at = now()
                            WHERE id = :id")->execute(['by' => $admin_name, 'rm' => $rm, 'id' => $id]);
            if (function_exists('logActivity')) { try { logActivity($admin_id, 'admin', 'vrf.disapprove', 'Disapproved reservation #' . $id . ' — ' . $rm); } catch (\Throwable $e) {} }
            $row['decision_remarks'] = $rm;
            $mailed = vrNotifyApplicant($row, 'disapproved');
            $done = ['ok', 'Disapproved. The slot is released for other requests.'
                         . ($mailed ? ' The applicant has been emailed the reason.'
                                    : (trim((string)$row['applicant_email']) === '' ? ' No email on file, so tell them directly.'
                                                                                    : ' The email could not be sent — it is queued for retry.'))];
        }
    } elseif ($act === 'payment') {
        $amt  = trim((string)($_POST['assessment_amount'] ?? ''));
        $paid = trim((string)($_POST['amount_paid'] ?? ''));
        $ptype = in_array(($_POST['payment_type'] ?? ''), ['down', 'full'], true) ? $_POST['payment_type'] : null;
        $orDate = trim((string)($_POST['or_date'] ?? ''));
        $pdo->prepare("UPDATE public.venue_reservations
                          SET assessment_amount = :am, assessment_by = :aby, payment_type = :pt,
                              amount_paid = :ap, or_no = :orno, or_date = :ord, cashier_name = :csh,
                              updated_at = now()
                        WHERE id = :id")
            ->execute([
                'am'  => $amt !== '' ? (float)$amt : null,
                'aby' => $amt !== '' ? $admin_name : null,
                'pt'  => $ptype,
                'ap'  => $paid !== '' ? (float)$paid : null,
                'orno'=> trim((string)($_POST['or_no'] ?? '')) ?: null,
                'ord' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $orDate) ? $orDate : null,
                'csh' => trim((string)($_POST['cashier_name'] ?? '')) ?: null,
                'id'  => $id,
            ]);
        if (function_exists('logActivity')) { try { logActivity($admin_id, 'admin', 'vrf.payment', 'Recorded assessment/payment on reservation #' . $id); } catch (\Throwable $e) {} }
        $done = ['ok', 'Assessment and payment recorded.'];
    } elseif ($act === 'status') {
        $new = (string)($_POST['new_status'] ?? '');
        if (!array_key_exists($new, vrStatuses())) {
            $flash = ['err', 'Unknown status.'];
        } else {
            $pdo->prepare("UPDATE public.venue_reservations SET status = :s, updated_at = now() WHERE id = :id")
                ->execute(['s' => $new, 'id' => $id]);
            $done = ['ok', 'Marked as ' . vrStatusLabel($new) . '.'];
        }
    }

    if ($done) {
        $_SESSION['vrf_flash'] = $done;
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}
if (!$flash && !empty($_SESSION['vrf_flash'])) { $flash = $_SESSION['vrf_flash']; unset($_SESSION['vrf_flash']); }

/* ─── Data ─── */
$all = $pdo->query("SELECT * FROM public.venue_reservations ORDER BY starts_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$now = time();

$cAwaiting = 0; $cApproved = 0; $cUnpaid = 0; $cToday = 0;
foreach ($all as $r) {
    $s = strtotime((string)$r['starts_at']);
    if (in_array($r['status'], ['submitted', 'endorsed'], true)) { $cAwaiting++; }
    if ($r['status'] === 'approved' && $s >= $now) { $cApproved++; }
    if ($r['status'] === 'approved' && $r['assessment_amount'] !== null && (float)$r['assessment_amount'] > 0
        && ((float)($r['amount_paid'] ?? 0)) < (float)$r['assessment_amount']) { $cUnpaid++; }
    if (date('Y-m-d', $s) === date('Y-m-d')) { $cToday++; }
}

$rows = $all;
if ($sf === 'open')       { $rows = array_values(array_filter($rows, fn($r) => in_array($r['status'], ['submitted', 'endorsed'], true))); }
elseif ($sf !== 'all')    { $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $sf)); }
if ($wf === 'upcoming')   { $rows = array_values(array_filter($rows, fn($r) => strtotime((string)$r['ends_at']) >= $now)); }
elseif ($wf === 'past')   { $rows = array_values(array_filter($rows, fn($r) => strtotime((string)$r['ends_at']) < $now)); }
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_values(array_filter($rows, function ($r) use ($needle) {
        $hay = mb_strtolower(implode(' ', [
            (string)$r['vrf_no'], (string)$r['applicant_name'], (string)$r['department_org'],
            (string)$r['venue'], (string)$r['description'], (string)$r['or_no'],
        ]));
        return mb_strpos($hay, $needle) !== false;
    }));
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Venue Reservations — Admin</title>
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="assets/css/admin-shell.css">
<style>
  :root{--m:#7B1D1D;--md:#4A0E0E;--g:#C9960C;--ink:#1A0808;--ink2:#5C3838;--ink3:#9C7A7A;--paper:#F4EFE6;--surface:#fff;--border:#E5D9C6;--sb:262px;--danger:#B42318;--success:#1A7A33;--g3:#F0C040;--r1:8px;--r2:12px;}
  *{box-sizing:border-box}
  body{margin:0;font-family:'DM Sans',sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;}
  .main{margin-left:var(--sb);transition:margin-left .26s ease;}
  body.becSbHide .main{margin-left:0 !important;}
  .wrap{max-width:none;margin:0;padding:24px 28px 64px;}
  .head-row{display:flex;align-items:flex-start;justify-content:space-between;gap:1.5rem;margin-bottom:18px;}
  .head{margin-bottom:0;}
  .head-acts{display:flex;gap:.5rem;flex-shrink:0;}
  .flash{display:flex;align-items:center;gap:.55rem;padding:.85rem 1rem;border-radius:11px;margin-bottom:1.1rem;font-size:.86rem;font-weight:600;}
  .flash.ok{background:#E9F9EF;border:1px solid #b6e6c6;color:var(--success);}
  .flash.err{background:#FEF2F2;border:1px solid #FECACA;color:var(--danger);}
  .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;}
  .stat{position:relative;overflow:hidden;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:1.15rem 1.3rem;display:flex;align-items:center;gap:1rem;box-shadow:0 1px 2px rgba(44,10,10,.05);text-decoration:none;color:inherit;transition:box-shadow .26s;}
  .stat:hover{box-shadow:0 12px 30px rgba(44,10,10,.12);}
  .stat::after{content:'';position:absolute;left:0;bottom:0;width:100%;height:3px;background:var(--sk,var(--m));transform:scaleX(0);transform-origin:left;transition:transform .32s;}
  .stat:hover::after,.stat.on::after{transform:scaleX(1);}
  .stat.on{border-color:var(--sk,var(--m));}
  .stat .ic{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;}
  .stat .n{font-family:'Outfit',sans-serif;font-size:2rem;font-weight:800;line-height:1;}
  .stat .l{font-size:.62rem;text-transform:uppercase;letter-spacing:.6px;color:var(--ink3);font-weight:700;margin-top:.35rem;}
  .stat.s-a{--sk:#D97706;} .stat.s-a .ic{background:rgba(201,150,12,.16);color:#B45309;} .stat.s-a .n{color:#B45309;}
  .stat.s-g{--sk:var(--success);} .stat.s-g .ic{background:#E9F9EF;color:var(--success);} .stat.s-g .n{color:var(--success);}
  .stat.s-d{--sk:var(--danger);} .stat.s-d .ic{background:#FEF2F2;color:var(--danger);} .stat.s-d .n{color:var(--danger);}
  .stat.s-m{--sk:var(--m);} .stat.s-m .ic{background:rgba(123,29,29,.1);color:var(--m);} .stat.s-m .n{color:var(--m);}
  .panel{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.3rem;margin-bottom:1.4rem;box-shadow:0 1px 2px rgba(44,10,10,.05);}
  .panel h2{margin:0 0 1rem;font-size:1rem;display:flex;align-items:center;gap:.6rem;padding-bottom:.8rem;border-bottom:1px solid var(--border);}
  .panel h2 > i{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,rgba(123,29,29,.1),rgba(201,150,12,.12));color:var(--m);display:inline-flex;align-items:center;justify-content:center;font-size:.85rem;}
  .panel h2 .count{margin-left:auto;font-size:.66rem;font-weight:700;color:var(--ink3);background:#f4ede1;border:1px solid var(--border);border-radius:999px;padding:.18rem .65rem;}
  .fbar{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:.8rem 1.1rem;margin-bottom:1.1rem;display:flex;gap:.55rem;align-items:center;flex-wrap:wrap;box-shadow:0 1px 2px rgba(44,10,10,.05);}
  .fsw{position:relative;flex:1;min-width:200px;}
  .fsw i{position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:var(--ink3);font-size:.72rem;pointer-events:none;}
  .fsi{width:100%;padding:.42rem .65rem .42rem 1.8rem;background:#faf7f0;border:1.5px solid var(--border);border-radius:8px;font:inherit;font-size:.79rem;}
  .fsel{padding:.42rem .65rem;background:#faf7f0;border:1.5px solid var(--border);border-radius:8px;font:inherit;font-size:.79rem;color:var(--ink2);cursor:pointer;}
  .fcount{font-size:.7rem;color:var(--ink3);white-space:nowrap;margin-left:auto;}
  .fclr{font-size:.72rem;color:var(--m);text-decoration:none;font-weight:700;}
  .tbl-wrap{border:1px solid var(--border);border-radius:12px;overflow:hidden;overflow-x:auto;}
  table{width:100%;border-collapse:collapse;font-size:.84rem;}
  th{text-align:left;padding:.7rem .75rem;background:var(--md);color:#fff;font-size:.63rem;text-transform:uppercase;letter-spacing:.5px;font-weight:800;white-space:nowrap;}
  td{padding:.7rem .75rem;border-bottom:1px solid var(--border);vertical-align:middle;}
  tbody tr:last-child td{border-bottom:none;}
  tbody tr:nth-child(even) td{background:#faf7f0;}
  tbody tr.pick:hover td{background:#fbf3e6;}
  tbody tr.pick{cursor:pointer;}
  tbody tr.pick:focus-visible{outline:2px solid var(--m);outline-offset:-2px;}
  .t-sub{color:#9E8070;font-size:.72rem;}
  .vrf{font-family:'Outfit',sans-serif;font-weight:800;color:var(--m);font-size:.8rem;}
  .pill{display:inline-flex;align-items:center;gap:.3rem;font-size:.6rem;font-weight:800;padding:.22rem .6rem;border-radius:999px;text-transform:uppercase;letter-spacing:.3px;}
  .pill.submitted{background:#EFF6FF;color:#1D4ED8;}
  .pill.endorsed{background:#FFFBEB;color:#92600A;}
  .pill.approved{background:#E9F9EF;color:#166534;}
  .pill.disapproved{background:#FEF2F2;color:#991B1B;}
  .pill.cancelled{background:#F1F1F1;color:#777;}
  .pill.completed{background:#F5F3FF;color:#5B21B6;}
  .paid{font-size:.6rem;font-weight:800;padding:.15rem .48rem;border-radius:6px;text-transform:uppercase;}
  .paid.yes{background:#E9F9EF;color:#166534;} .paid.part{background:#FFFBEB;color:#92600A;} .paid.no{background:#FEF2F2;color:#991B1B;}
  .empty{text-align:center;color:var(--ink3);padding:2.4rem 1rem;}
  .empty i.big{color:var(--g);opacity:.75;font-size:1.8rem;display:block;margin-bottom:.6rem;}
  .empty h3{margin:.2rem 0 .35rem;font-size:1rem;color:var(--ink);font-family:'Outfit',sans-serif;}
  .empty p{margin:0 auto 1rem;max-width:42rem;font-size:.82rem;line-height:1.6;}
  .btn{display:inline-flex;align-items:center;gap:.5rem;padding:.68rem 1.2rem;border-radius:10px;border:none;font:inherit;font-weight:700;font-size:.84rem;cursor:pointer;text-decoration:none;transition:background .15s;}
  .btn.m{background:var(--m);color:#fff;} .btn.m:hover{background:var(--md);}
  .btn.ghost{background:#f1eadf;color:var(--ink2);} .btn.ghost:hover{background:#e7dac6;}
  .btn.ok{background:var(--success);color:#fff;} .btn.ok:hover{background:#15602a;}
  .btn.danger{background:var(--danger);color:#fff;} .btn.danger:hover{background:#8f1d13;}
  .btn.sm{padding:.42rem .8rem;font-size:.75rem;border-radius:9px;}
  /* Detail dialog */
  .ovl{position:fixed;inset:0;background:rgba(26,8,8,.5);backdrop-filter:blur(3px);z-index:900;display:flex;align-items:flex-start;justify-content:center;padding:3vh 1rem;overflow-y:auto;}
  .ovl[hidden]{display:none;}
  .dlg{background:var(--surface);border-radius:16px;width:100%;max-width:760px;box-shadow:0 26px 70px rgba(26,8,8,.34);overflow:hidden;}
  .dlg-h{display:flex;align-items:center;gap:.6rem;padding:1.05rem 1.3rem;border-bottom:1px solid var(--border);font-family:'Outfit',sans-serif;font-weight:700;font-size:.98rem;}
  .dlg-h i.lead{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,rgba(123,29,29,.1),rgba(201,150,12,.12));color:var(--m);display:inline-flex;align-items:center;justify-content:center;font-size:.85rem;}
  .dlg-h .x{margin-left:auto;background:transparent;border:none;color:var(--ink3);font-size:1rem;cursor:pointer;width:2rem;height:2rem;border-radius:8px;}
  .dlg-h .x:hover{background:#f1eadf;color:var(--ink);}
  .dlg-b{padding:1.3rem;max-height:64vh;overflow-y:auto;}
  .kv{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.75rem;margin-bottom:1.1rem;}
  .kv > div{padding:.6rem .75rem;background:#faf7f0;border:1px solid var(--border);border-radius:10px;min-width:0;}
  .kv small{display:block;font-size:.58rem;font-weight:800;letter-spacing:.7px;text-transform:uppercase;color:var(--g);margin-bottom:3px;}
  .kv strong{font-size:.84rem;font-weight:700;word-break:break-word;}
  .blk{margin-bottom:1.1rem;}
  .blk h4{margin:0 0 .45rem;font-size:.7rem;text-transform:uppercase;letter-spacing:.6px;color:var(--ink2);}
  .blk .copy{font-size:.85rem;line-height:1.65;color:var(--ink2);white-space:pre-wrap;}
  .mat{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.4rem;}
  .mat li{display:flex;justify-content:space-between;gap:.6rem;padding:.45rem .7rem;background:#faf7f0;border:1px solid var(--border);border-radius:8px;font-size:.8rem;}
  .mat li span{color:var(--ink3);font-weight:700;}
  .warnbox{display:flex;gap:.6rem;padding:.75rem .9rem;border-radius:10px;background:#FEF2F2;border:1px solid #FECACA;color:#8A1C1C;font-size:.8rem;line-height:1.55;margin-bottom:1.1rem;}
  .act-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;}
  .act-card{border:1px solid var(--border);border-radius:12px;padding:.9rem;background:#fff;}
  .act-card h5{margin:0 0 .55rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.6px;color:var(--ink2);}
  label{font-size:.68rem;font-weight:700;color:var(--ink2);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:.25rem;}
  input,select,textarea{width:100%;padding:.55rem .7rem;border:1.5px solid var(--border);border-radius:9px;font:inherit;font-size:.85rem;background:#fff;color:var(--ink);margin-bottom:.6rem;}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--m);box-shadow:0 0 0 3px rgba(123,29,29,.1);}
  textarea{resize:vertical;min-height:56px;}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;}
  @media(max-width:1180px){.cards{grid-template-columns:repeat(2,1fr)}}
  @media(max-width:860px){.main{margin-left:0;}.cards{grid-template-columns:1fr}.head-row{flex-direction:column;}}
</style>
</head>
<body>
<?php $activeNav = 'reservations'; require __DIR__ . '/includes/admin_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <div>
        <div class="pg-title">Venue Reservations</div>
        <div class="bc">
          <a href="admin_dashboard.php"><i class="fas fa-home"></i></a>
          <i class="fas fa-chevron-right"></i><span>Venue Reservations</span>
        </div>
      </div>
    </div>
    <div class="wrap">
      <div class="head-row">
        <div class="head">
          <h2>Venue Reservations</h2>
          <p>The Venue Reservation Form, as a record — requested, endorsed, approved, assessed and paid.
             A venue already held for a time window cannot be booked twice.</p>
        </div>
        <div class="head-acts">
          <a class="btn m" href="reserve_venue.php?walkin=1"><i class="fas fa-plus"></i> File a Request</a>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>">
          <i class="fas fa-<?php echo $flash[0] === 'ok' ? 'circle-check' : 'circle-exclamation'; ?>"></i>
          <?php echo rv_e($flash[1]); ?>
        </div>
      <?php endif; ?>

      <div class="cards">
        <a class="stat s-a<?php echo $sf === 'open' ? ' on' : ''; ?>" href="<?php echo rv_e($keep(['status' => 'open', 'when' => 'all'])); ?>">
          <div class="ic"><i class="fas fa-inbox"></i></div>
          <div><div class="n"><?php echo (int)$cAwaiting; ?></div><div class="l">Awaiting Decision</div></div>
        </a>
        <a class="stat s-g<?php echo ($sf === 'approved' && $wf === 'upcoming') ? ' on' : ''; ?>" href="<?php echo rv_e($keep(['status' => 'approved', 'when' => 'upcoming'])); ?>">
          <div class="ic"><i class="fas fa-calendar-check"></i></div>
          <div><div class="n"><?php echo (int)$cApproved; ?></div><div class="l">Approved Upcoming</div></div>
        </a>
        <a class="stat s-d<?php echo $sf === 'approved' ? ' on' : ''; ?>" href="<?php echo rv_e($keep(['status' => 'approved', 'when' => 'all'])); ?>">
          <div class="ic"><i class="fas fa-peso-sign"></i></div>
          <div><div class="n"><?php echo (int)$cUnpaid; ?></div><div class="l">Awaiting Payment</div></div>
        </a>
        <a class="stat s-m<?php echo $sf === 'all' ? ' on' : ''; ?>" href="<?php echo rv_e($keep(['status' => 'all', 'when' => 'all'])); ?>">
          <div class="ic"><i class="fas fa-calendar-day"></i></div>
          <div><div class="n"><?php echo (int)$cToday; ?></div><div class="l">Happening Today</div></div>
        </a>
      </div>

      <div class="fbar">
        <div class="fsw">
          <i class="fas fa-search"></i>
          <input type="text" class="fsi" id="fq" placeholder="Search VRF no., applicant, organisation, venue, OR no.…"
                 value="<?php echo rv_e($q); ?>" oninput="rvDebounce()" onkeydown="if(event.key==='Enter'){event.preventDefault();rvGo();}">
        </div>
        <select class="fsel" id="fs" aria-label="Filter by status" onchange="rvGo()">
          <option value="open" <?php echo $sf === 'open' ? 'selected' : ''; ?>>Awaiting decision</option>
          <option value="all"  <?php echo $sf === 'all'  ? 'selected' : ''; ?>>All statuses</option>
          <?php foreach (vrStatuses() as $sv => $sl): ?>
            <option value="<?php echo $sv; ?>" <?php echo $sf === $sv ? 'selected' : ''; ?>><?php echo rv_e($sl); ?></option>
          <?php endforeach; ?>
        </select>
        <select class="fsel" id="fw" aria-label="Filter by date" onchange="rvGo()">
          <option value="all"      <?php echo $wf === 'all'      ? 'selected' : ''; ?>>Any date</option>
          <option value="upcoming" <?php echo $wf === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
          <option value="past"     <?php echo $wf === 'past'     ? 'selected' : ''; ?>>Past</option>
        </select>
        <?php if ($filtersOn): ?>
          <a class="fclr" href="admin_reservations.php"><i class="fas fa-xmark"></i> Clear</a>
        <?php endif; ?>
        <span class="fcount"><?php echo count($rows); ?> of <?php echo count($all); ?> request<?php echo count($all) !== 1 ? 's' : ''; ?></span>
      </div>

      <div class="panel">
        <h2><i class="fas fa-file-signature"></i> Reservation Requests <span class="count"><?php echo count($rows); ?> shown</span></h2>

        <?php if (!$all): ?>
          <div class="empty">
            <i class="fas fa-file-signature big"></i>
            <h3>No reservations yet</h3>
            <p>When someone files a Venue Reservation Form it lands here for the PMO to endorse, approve or
               disapprove — and the venue is held against double-booking the moment it is submitted.</p>
            <a class="btn m" href="reserve_venue.php?walkin=1"><i class="fas fa-plus"></i> File the first request</a>
          </div>
        <?php elseif (!$rows): ?>
          <div class="empty">
            <i class="fas fa-filter big"></i>
            <h3>Nothing matches these filters</h3>
            <p><a href="admin_reservations.php">Clear them</a> to see all <?php echo count($all); ?>.</p>
          </div>
        <?php else: ?>
          <div class="tbl-wrap">
          <table data-paginate="15" data-paginate-noun="requests">
            <thead><tr>
              <th>VRF No.</th><th>Applicant</th><th>Venue</th><th>When</th>
              <th>Activity</th><th>Pax</th><th>Payment</th><th>Status</th>
            </tr></thead>
            <tbody>
              <?php foreach ($rows as $r):
                $assessed = $r['assessment_amount'] !== null ? (float)$r['assessment_amount'] : null;
                $paidAmt  = $r['amount_paid'] !== null ? (float)$r['amount_paid'] : 0.0;
                if ($assessed === null || $assessed <= 0) { $payCls = ''; $payTxt = '—'; }
                elseif ($paidAmt >= $assessed)            { $payCls = 'yes';  $payTxt = 'Paid'; }
                elseif ($paidAmt > 0)                     { $payCls = 'part'; $payTxt = 'Partial'; }
                else                                      { $payCls = 'no';   $payTxt = 'Unpaid'; }
                $payload = rv_e(json_encode([
                    'id'      => (int)$r['id'],
                    'vrf'     => (string)$r['vrf_no'],
                    'cf'      => (string)$r['cf_no'],
                    'name'    => (string)$r['applicant_name'],
                    'org'     => (string)$r['department_org'],
                    'email'   => (string)$r['applicant_email'],
                    'phone'   => (string)$r['applicant_phone'],
                    'venue'   => (string)$r['venue'],
                    'nature'  => vrNatureLabel((string)$r['nature'], (string)$r['nature_other']),
                    'when'    => vrRange($r['starts_at'], $r['ends_at']),
                    'dur'     => vrDuration($r['starts_at'], $r['ends_at']),
                    'pax'     => (int)$r['participants'],
                    'desc'    => (string)$r['description'],
                    'mats'    => vrMaterials($r['materials']),
                    'adviser' => (string)$r['adviser_name'],
                    'status'  => (string)$r['status'],
                    'slabel'  => vrStatusLabel((string)$r['status']),
                    'appby'   => (string)$r['approved_by'],
                    'disby'   => (string)$r['disapproved_by'],
                    'remarks' => (string)$r['decision_remarks'],
                    'assess'  => $assessed,
                    'paid'    => $r['amount_paid'] !== null ? (float)$r['amount_paid'] : null,
                    'ptype'   => (string)$r['payment_type'],
                    'orno'    => (string)$r['or_no'],
                    'ordate'  => $r['or_date'] ? date('Y-m-d', strtotime((string)$r['or_date'])) : '',
                    'cashier' => (string)$r['cashier_name'],
                ], JSON_UNESCAPED_UNICODE));
              ?>
              <tr class="pick" role="button" tabindex="0" data-rv="<?php echo $payload; ?>"
                  aria-label="Open reservation <?php echo rv_e($r['vrf_no'] ?: ('#' . $r['id'])); ?>">
                <td><span class="vrf"><?php echo rv_e($r['vrf_no'] ?: '—'); ?></span></td>
                <td><?php echo rv_e($r['applicant_name']); ?><div class="t-sub"><?php echo rv_e($r['department_org']); ?></div></td>
                <td><?php echo rv_e($r['venue']); ?></td>
                <td><?php echo rv_e(vrRange($r['starts_at'], $r['ends_at'])); ?><div class="t-sub"><?php echo rv_e(vrDuration($r['starts_at'], $r['ends_at'])); ?></div></td>
                <td><?php echo rv_e(vrNatureLabel((string)$r['nature'], (string)$r['nature_other'])); ?></td>
                <td><?php echo $r['participants'] !== null ? (int)$r['participants'] : '—'; ?></td>
                <td><?php echo $payCls ? '<span class="paid ' . $payCls . '">' . $payTxt . '</span>' : '<span class="t-sub">—</span>'; ?></td>
                <td><span class="pill <?php echo rv_e($r['status']); ?>"><?php echo rv_e(vrStatusLabel((string)$r['status'])); ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ═══ Reservation detail + decisions ═══ -->
  <div class="ovl" id="rvOvl" hidden>
    <div class="dlg" role="dialog" aria-modal="true" aria-labelledby="rvTitle">
      <div class="dlg-h">
        <i class="fas fa-file-signature lead"></i>
        <span id="rvTitle">Venue Reservation</span>
        <?php /* The office keeps a signed paper copy in the folder; this is that
                 copy, filled in. Opens in its own tab so the queue stays put. */ ?>
        <a class="btn ghost sm" id="rvPrint" href="#" target="_blank" rel="noopener"
           style="margin-left:auto;"><i class="fas fa-print"></i> Print form</a>
        <button class="x" type="button" data-close aria-label="Close" style="margin-left:.4rem;"><i class="fas fa-xmark"></i></button>
      </div>
      <div class="dlg-b">
        <div id="rvWarn"></div>
        <div class="kv" id="rvFacts"></div>
        <div class="blk" id="rvDescBlk"><h4>Description of activity</h4><div class="copy" id="rvDesc"></div></div>
        <div class="blk" id="rvMatBlk"><h4>Materials requested</h4><ul class="mat" id="rvMats"></ul></div>
        <div class="blk" id="rvDecBlk"><h4>Decision</h4><div class="copy" id="rvDec"></div></div>

        <div class="act-grid">
          <div class="act-card">
            <h5><i class="fas fa-user-check"></i> Endorsement</h5>
            <form method="POST">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="endorse">
              <input type="hidden" name="id" class="rvId">
              <label for="advName">Dept. head / org. adviser</label>
              <input type="text" id="advName" name="adviser_name" placeholder="Name over printed signature" required>
              <button class="btn ghost sm" type="submit"><i class="fas fa-signature"></i> Record endorsement</button>
            </form>
          </div>

          <div class="act-card">
            <h5><i class="fas fa-gavel"></i> PMO decision</h5>
            <form method="POST" id="approveForm">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="id" class="rvId">
              <label for="apRemarks">Remarks (optional)</label>
              <textarea id="apRemarks" name="remarks" rows="2" placeholder="Conditions, notes…"></textarea>
              <button class="btn ok sm" type="submit"><i class="fas fa-check"></i> Approve &amp; issue VRF no.</button>
            </form>
            <form method="POST" id="disapproveForm" style="margin-top:.6rem;">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="disapprove">
              <input type="hidden" name="id" class="rvId">
              <label for="disRemarks">Reason for disapproval</label>
              <textarea id="disRemarks" name="remarks" rows="2" placeholder="Told to the applicant"></textarea>
              <button class="btn danger sm" type="submit"><i class="fas fa-xmark"></i> Disapprove</button>
            </form>
          </div>

          <div class="act-card">
            <h5><i class="fas fa-receipt"></i> Assessment &amp; payment</h5>
            <form method="POST">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="payment">
              <input type="hidden" name="id" class="rvId">
              <div class="row2">
                <div><label for="pAssess">Assessment (₱)</label><input type="number" step="0.01" min="0" id="pAssess" name="assessment_amount"></div>
                <div><label for="pPaid">Amount paid (₱)</label><input type="number" step="0.01" min="0" id="pPaid" name="amount_paid"></div>
              </div>
              <label for="pType">Payment</label>
              <select id="pType" name="payment_type">
                <option value="">Not recorded</option>
                <option value="down">Down payment</option>
                <option value="full">Full payment</option>
              </select>
              <div class="row2">
                <div><label for="pOr">OR no.</label><input type="text" id="pOr" name="or_no"></div>
                <div><label for="pOrDate">OR date</label><input type="date" id="pOrDate" name="or_date"></div>
              </div>
              <label for="pCashier">Cashier</label>
              <input type="text" id="pCashier" name="cashier_name">
              <button class="btn m sm" type="submit"><i class="fas fa-save"></i> Save payment</button>
            </form>
          </div>

          <div class="act-card">
            <h5><i class="fas fa-flag-checkered"></i> Close out</h5>
            <form method="POST">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="status">
              <input type="hidden" name="id" class="rvId">
              <label for="newStatus">Mark as</label>
              <select id="newStatus" name="new_status">
                <?php foreach (vrStatuses() as $sv => $sl): ?>
                  <option value="<?php echo $sv; ?>"><?php echo rv_e($sl); ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn ghost sm" type="submit"><i class="fas fa-arrow-right"></i> Update status</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

<?php require_once __DIR__ . '/includes/csrf_inject.php'; ?>
<script src="assets/sidebar_autohide.js" defer></script>
<script src="assets/table_paginate.js" defer></script>
<script src="assets/date_picker.js"></script>
<script>
function rvGo() {
  var u = new URL(location.href);
  var set = function (k, v, d) { if (!v || v === d) { u.searchParams.delete(k); } else { u.searchParams.set(k, v); } };
  set('q',      document.getElementById('fq').value.trim(), '');
  set('status', document.getElementById('fs').value, 'open');
  set('when',   document.getElementById('fw').value, 'all');
  location.href = u.toString();
}
var rvT; function rvDebounce() { clearTimeout(rvT); rvT = setTimeout(rvGo, 450); }

function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
  });
}
var peso = function (n) {
  return (n === null || n === undefined || n === '') ? '—'
    : '₱' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

function rvOpen(tr) {
  var raw = tr.getAttribute('data-rv'); if (!raw) { return; }
  var d;
  try { d = JSON.parse(raw); } catch (e) { return; }

  document.getElementById('rvTitle').textContent = (d.vrf || 'Unnumbered request') + ' · ' + d.venue;
  document.querySelectorAll('.rvId').forEach(function (i) { i.value = d.id; });
  document.getElementById('rvPrint').href = 'admin_reservation_print.php?id=' + encodeURIComponent(d.id) + '&auto=1';

  var facts = [
    ['Applicant', esc(d.name)], ['Department / Organisation', esc(d.org)],
    ['Venue', esc(d.venue)], ['Date &amp; time', esc(d.when) + ' <span style="color:#9C7A7A">(' + esc(d.dur) + ')</span>'],
    ['Nature of activity', esc(d.nature)], ['Expected participants', d.pax ? esc(d.pax) : '—'],
    ['Status', esc(d.slabel)], ['Endorsed by', d.adviser ? esc(d.adviser) : 'Not yet endorsed'],
    ['Contact', esc(d.email || d.phone || '—')], ['CF #', esc(d.cf || '—')],
    ['Assessment', peso(d.assess)], ['Paid', peso(d.paid) + (d.ptype ? ' (' + esc(d.ptype) + ')' : '')],
    ['OR no.', esc(d.orno || '—') + (d.ordate ? ' · ' + esc(d.ordate) : '')], ['Cashier', esc(d.cashier || '—')]
  ];
  document.getElementById('rvFacts').innerHTML = facts.map(function (f) {
    return '<div><small>' + f[0] + '</small><strong>' + f[1] + '</strong></div>';
  }).join('');

  document.getElementById('rvDescBlk').hidden = !d.desc;
  document.getElementById('rvDesc').textContent = d.desc || '';

  var mats = d.mats || [];
  document.getElementById('rvMatBlk').hidden = !mats.length;
  document.getElementById('rvMats').innerHTML = mats.map(function (m) {
    return '<li>' + esc(m.item) + '<span>' + (m.qty ? '× ' + esc(m.qty) : '') + '</span></li>';
  }).join('');

  var dec = '';
  if (d.status === 'approved' && d.appby)      { dec = 'Approved by ' + d.appby + '.'; }
  if (d.status === 'disapproved' && d.disby)   { dec = 'Disapproved by ' + d.disby + '.'; }
  if (d.remarks)                               { dec += (dec ? '\n' : '') + d.remarks; }
  document.getElementById('rvDecBlk').hidden = !dec;
  document.getElementById('rvDec').textContent = dec;

  // Prefill the payment form with what is already recorded, so saving one field
  // does not blank the rest.
  document.getElementById('pAssess').value  = (d.assess === null || d.assess === undefined) ? '' : d.assess;
  document.getElementById('pPaid').value    = (d.paid === null || d.paid === undefined) ? '' : d.paid;
  document.getElementById('pType').value    = d.ptype || '';
  document.getElementById('pOr').value      = d.orno || '';
  document.getElementById('pOrDate').value  = d.ordate || '';
  document.getElementById('pCashier').value = d.cashier || '';
  document.getElementById('advName').value  = d.adviser || '';
  document.getElementById('newStatus').value = d.status || 'submitted';

  var warn = '';
  if (d.status === 'disapproved' || d.status === 'cancelled') {
    warn = 'This request no longer holds the venue — the slot is free for other bookings.';
  } else if (!d.adviser && d.status === 'submitted') {
    warn = 'Not yet endorsed by a department head or organisation adviser. The paper form needs that signature before the PMO signs.';
  }
  document.getElementById('rvWarn').innerHTML = warn
    ? '<div class="warnbox"><i class="fas fa-circle-info"></i><div>' + esc(warn) + '</div></div>' : '';

  document.getElementById('rvOvl').hidden = false;
}

document.addEventListener('click', function (ev) {
  if (ev.target.closest('[data-close]')) { document.getElementById('rvOvl').hidden = true; return; }
  var ovl = document.getElementById('rvOvl');
  if (ev.target === ovl) { ovl.hidden = true; return; }
  var tr = ev.target.closest && ev.target.closest('tr.pick');
  if (tr && !ev.target.closest('a,button')) { rvOpen(tr); }
});
document.addEventListener('keydown', function (ev) {
  if (ev.key === 'Escape') { document.getElementById('rvOvl').hidden = true; return; }
  if (ev.key !== 'Enter' && ev.key !== ' ') { return; }
  var tr = ev.target.closest && ev.target.closest('tr.pick');
  if (tr && ev.target === tr) { ev.preventDefault(); rvOpen(tr); }
});

// A disapproval without a reason is refused server-side; say so before the trip.
document.getElementById('disapproveForm').addEventListener('submit', function (ev) {
  if (document.getElementById('disRemarks').value.trim() === '') {
    ev.preventDefault();
    document.getElementById('disRemarks').focus();
    document.getElementById('disRemarks').placeholder = 'A reason is required — the applicant is told why.';
  }
});
</script>
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<?php require __DIR__ . '/includes/admin_ui.php'; ?>
</body>
</html>
