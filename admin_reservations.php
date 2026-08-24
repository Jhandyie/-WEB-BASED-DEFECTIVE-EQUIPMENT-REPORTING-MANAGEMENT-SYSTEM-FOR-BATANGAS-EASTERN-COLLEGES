<?php
/**
 * admin_reservations.php — Venue Reservation Forms, the PMO's side.
 *
 * The queue the paper folder used to be: what has been requested, what is
 * waiting on a signature, what the office approved, and whether it has been
 * paid. The decision boxes on the form (PMO approval, School Administrator
 * disapproval, Accounting assessment, Cashier's OR number) are the actions here.
 *
 * WHY THERE IS AN ASSESSMENT & PAYMENT PANEL IN A CAMPUS BOOKING SYSTEM
 * ---------------------------------------------------------------------
 * Reviewers keep asking, reasonably, why reserving a room the college already
 * owns involves an amount due and an OR number. It is not invented, and it is
 * not e-commerce: BEC's paper "VENUE RESERVATION FORM" (Rev. 00) is assessed by
 * Accounting and closed out by the Cashier, and BEC does charge for some venue
 * use. Every field here is a box on that form, so an approved reservation can
 * be reprinted as the form (admin_reservation_print.php).
 *
 * Note the scoping, which is the part worth preserving if this is ever
 * revisited: the panel is ADMIN-ONLY. reserve_venue.php never asks an applicant
 * for a peso figure or an OR number — those are the office's boxes, filled by
 * the office, exactly as on paper. No money moves through this system; it
 * records what Accounting and the Cashier already did.
 */
require_once __DIR__ . '/config/features.php';
if (!becVenueEnabled()) { header('Location: admin_dashboard.php'); exit; }
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
            /* Setting a status by hand can re-acquire a hold on the room. A
               request that was cancelled or disapproved has released its slot,
               someone else may have taken it since, and moving it back to
               submitted / endorsed / approved puts two holders on one window.
               The EXCLUDE constraint refuses that — correctly — but this branch
               used to have neither a pre-check nor a try/catch, so the refusal
               arrived as an uncaught PDOException: a 500 page, on a click that
               should have produced a sentence. Approve already does both; this
               now matches it. */
            $reacquires = in_array($new, vrHoldingStatuses(), true)
                       && !in_array((string)$row['status'], vrHoldingStatuses(), true);
            $clash = $reacquires
                ? vrConflicts($pdo, (string)$row['venue'], (string)$row['starts_at'], (string)$row['ends_at'], $id)
                : [];
            if ($clash) {
                $c = $clash[0];
                $flash = ['err', 'Not changed — ' . $row['venue'] . ' is now held by '
                               . ($c['vrf_no'] ?: '#' . $c['id']) . ' (' . $c['applicant_name'] . ') for '
                               . vrRange($c['starts_at'], $c['ends_at']) . '. Release that one first.'];
            } else {
                try {
                    $pdo->prepare("UPDATE public.venue_reservations SET status = :s, updated_at = now() WHERE id = :id")
                        ->execute(['s' => $new, 'id' => $id]);
                    if (function_exists('logActivity')) {
                        try { logActivity($admin_id, 'admin', 'vrf.status', 'Set reservation #' . $id . ' to ' . $new); } catch (\Throwable $e) {}
                    }
                    $done = ['ok', 'Marked as ' . vrStatusLabel($new) . '.'];
                } catch (\Throwable $e) {
                    // Last word if another admin took the slot mid-request.
                    error_log('vrf.status failed: ' . $e->getMessage());
                    $flash = ['err', 'Not changed — the venue was taken for that window while this page was open. Reload to see the current holder.'];
                }
            }
        }
    }

    if ($done) {
        $_SESSION['vrf_flash'] = $done;
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}
if (!$flash && !empty($_SESSION['vrf_flash'])) { $flash = $_SESSION['vrf_flash']; unset($_SESSION['vrf_flash']); }

/* ─── Data ───
   Every row used to be fetched here, counted in a PHP loop, then narrowed by
   three array_filter() passes and handed to a client-side paginator — so the
   browser received the whole table and showed 15 of it. That is the shape
   CLAUDE.md warns about: work proportional to the backlog on every page load,
   invisible while there is one reservation and a wall at a few thousand.
   Filtering, counting and paging now all happen in the database, the same way
   Defect Reports and User Management do. */
$now = time();

// Shared by the count and the page query, so the number under the table and
// the rows in it can never disagree.
$where = []; $bind = [];
if ($sf === 'open')     { $where[] = "status IN ('submitted','endorsed')"; }
elseif ($sf !== 'all')  { $where[] = 'status = :st'; $bind['st'] = $sf; }
if ($wf === 'upcoming') { $where[] = 'ends_at >= now()'; }
elseif ($wf === 'past') { $where[] = 'ends_at <  now()'; }
if ($q !== '') {
    // COALESCE on every column: in Postgres a NULL anywhere in a concatenation
    // makes the whole haystack NULL, so one empty OR number would hide the row.
    $where[] = "(COALESCE(vrf_no,'') || ' ' || COALESCE(applicant_name,'') || ' '
              || COALESCE(department_org,'') || ' ' || COALESCE(venue,'') || ' '
              || COALESCE(description,'') || ' ' || COALESCE(or_no,'')) ILIKE :q";
    $bind['q'] = '%' . $q . '%';
}
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// The four headline figures, counted in the database rather than by walking
// every row. Deliberately NOT narrowed by the filters — they describe the whole
// queue, which is what makes them worth glancing at while a filter is applied.
$counts = $pdo->query(
    "SELECT
       COUNT(*) FILTER (WHERE status IN ('submitted','endorsed'))                          AS awaiting,
       COUNT(*) FILTER (WHERE status = 'approved' AND starts_at >= now())                  AS approved,
       COUNT(*) FILTER (WHERE status = 'approved' AND assessment_amount > 0
                          AND COALESCE(amount_paid,0) < assessment_amount)                  AS unpaid,
       COUNT(*) FILTER (WHERE starts_at::date = CURRENT_DATE)                              AS today,
       COUNT(*)                                                                            AS total
     FROM public.venue_reservations"
)->fetch(PDO::FETCH_ASSOC) ?: [];
$cAwaiting = (int)($counts['awaiting'] ?? 0);
$cApproved = (int)($counts['approved'] ?? 0);
$cUnpaid   = (int)($counts['unpaid']   ?? 0);
$cToday    = (int)($counts['today']    ?? 0);
$allTotal  = (int)($counts['total']    ?? 0);

$perPage = 15;                                  // what the old client paginator showed
$page    = max(1, (int)($_GET['page'] ?? 1));

$cst = $pdo->prepare("SELECT COUNT(*) FROM public.venue_reservations{$sqlWhere}");
$cst->execute($bind);
$matchTotal = (int)$cst->fetchColumn();
$totalPages = max(1, (int)ceil($matchTotal / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

// Interpolated, not bound: both are ints from (int) casts, and PDO will not
// bind LIMIT placeholders on every driver.
$rst = $pdo->prepare("SELECT * FROM public.venue_reservations{$sqlWhere}
                       ORDER BY starts_at DESC LIMIT {$perPage} OFFSET {$offset}");
$rst->execute($bind);
$rows = $rst->fetchAll(PDO::FETCH_ASSOC);

$rowFrom = $matchTotal === 0 ? 0 : $offset + 1;
$rowTo   = $offset + count($rows);

// Every filter has to survive a page change, or paging would silently reset the view.
$pageQuery = static function (int $p) use ($q, $sf, $wf): string {
    $args = array_filter(
        ['q' => $q, 'status' => $sf === 'open' ? '' : $sf, 'when' => $wf, 'page' => $p > 1 ? $p : ''],
        static fn($v) => $v !== '' && $v !== 'all'
    );
    return 'admin_reservations.php' . ($args ? '?' . http_build_query($args) : '');
};
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
  :root{--m:#7B1D1D;--md:#4A0E0E;--g:#C9960C;--ink:#1A0808;--ink2:#5C3838;--ink3:#9C7A7A;--paper:#F4EFE6;--surface:#fff;--border:#E5D9C6;--sb:262px;--danger:var(--bad-tx);--success:var(--ok-tx);--g3:#F0C040;--r1:8px;--r2:12px;}
  *{box-sizing:border-box}
  body{margin:0;font-family:'DM Sans',sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;}
  .main{margin-left:var(--sb);transition:margin-left .26s ease;}
  body.becSbHide .main{margin-left:0 !important;}
  .wrap{max-width:none;margin:0;padding:1.5rem 1.75rem 4rem;}
  .head-row{display:flex;align-items:flex-start;justify-content:space-between;gap:1.5rem;margin-bottom:18px;}
  .head{margin-bottom:0;}
  .head-acts{display:flex;gap:.5rem;flex-shrink:0;}
/* .flash lives in assets/css/admin-shell.css — one definition for every admin page. */
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
  .pill.approved{background:#E9F9EF;color:var(--ok-tx);}
  .pill.disapproved{background:#FEF2F2;color:var(--bad-tx);}
  .pill.cancelled{background:#F1F1F1;color:#777;}
  .pill.completed{background:#F5F3FF;color:#5B21B6;}
  .paid{font-size:.6rem;font-weight:800;padding:.15rem .48rem;border-radius:6px;text-transform:uppercase;}
  .paid.yes{background:#E9F9EF;color:var(--ok-tx);} .paid.part{background:#FFFBEB;color:#92600A;} .paid.no{background:#FEF2F2;color:var(--bad-tx);}
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
  .dlg{background:var(--surface);border-radius:16px;width:100%;max-width:880px;box-shadow:0 26px 70px rgba(26,8,8,.34);overflow:hidden;}
  .dlg-h{display:flex;align-items:center;gap:.6rem;padding:1.05rem 1.3rem;border-bottom:1px solid var(--border);font-family:'Outfit',sans-serif;font-weight:700;font-size:.98rem;}
  .dlg-h i.lead{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,rgba(123,29,29,.1),rgba(201,150,12,.12));color:var(--m);display:inline-flex;align-items:center;justify-content:center;font-size:.85rem;}
  .dlg-h .x{margin-left:auto;background:transparent;border:none;color:var(--ink3);font-size:1rem;cursor:pointer;width:2rem;height:2rem;border-radius:8px;}
  .dlg-h .x:hover{background:#f1eadf;color:var(--ink);}
  .dlg-b{padding:1.3rem;max-height:64vh;overflow-y:auto;}
  /* The detail view is the Venue Reservation Form on screen: the same sections in
     the same order, each value on a ruled line under a right-aligned label. The
     tile grid this replaced sized every fact equally, so a one-word status took
     as much room as a full venue name and nothing lined up down the page. */
  .rvform{border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:1.15rem;}
  .rvsec + .rvsec{border-top:1px solid var(--border);}
  .rvsec > h5{margin:0;padding:.5rem .95rem;font-family:'Outfit',sans-serif;font-size:.62rem;
    font-weight:800;letter-spacing:1.1px;text-transform:uppercase;color:var(--m);
    background:#faf7f0;border-bottom:1px solid var(--border);}
  .rvflds{padding:.85rem .95rem .3rem;}
  .rvf{display:flex;align-items:baseline;gap:.65rem;margin-bottom:.62rem;}
  .rvf .l{flex:0 0 11rem;font-size:.61rem;font-weight:800;letter-spacing:.6px;
    text-transform:uppercase;color:var(--g);text-align:right;line-height:1.5;}
  .rvf .v{flex:1;min-width:0;font-size:.86rem;font-weight:650;color:var(--ink);
    border-bottom:1px dotted var(--border);padding:0 .25rem 4px;word-break:break-word;line-height:1.5;}
  .rvf .v.empty{color:var(--ink3);font-weight:500;}
  @media(max-width:640px){.rvf{display:block;}.rvf .l{text-align:left;margin-bottom:2px;}}
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
          <input type="text" class="fsi" id="fq" placeholder="Search VRF no., applicant, organization, venue, OR no.…"
                 value="<?php echo rv_e($q); ?>" onkeydown="if(event.key==='Enter'){event.preventDefault();rvGo();}">
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
        <span class="fcount"><?php
          echo $matchTotal > $perPage
            ? number_format($rowFrom) . '&ndash;' . number_format($rowTo) . ' of ' . number_format($matchTotal)
            : number_format($matchTotal) . ' request' . ($matchTotal !== 1 ? 's' : ''); ?></span>
      </div>

      <div class="panel">
        <h2><i class="fas fa-file-signature"></i> Reservation Requests <span class="count"><?php echo number_format($matchTotal); ?> matching</span></h2>

        <?php if ($allTotal === 0): ?>
          <div class="empty">
            <i class="fas fa-file-signature big"></i>
            <h3>No reservations yet</h3>
            <p>When someone files a Venue Reservation Form it lands here for the PMO to endorse, approve or
               disapprove — and the venue is held against double-booking the moment it is submitted.</p>
            <a class="btn m" href="reserve_venue.php?walkin=1"><i class="fas fa-plus"></i> File the First Request</a>
          </div>
        <?php elseif (!$rows): ?>
          <div class="empty">
            <i class="fas fa-filter big"></i>
            <h3>Nothing matches these filters</h3>
            <p><a href="admin_reservations.php">Clear them</a> to see all <?php echo number_format($allTotal); ?>.</p>
          </div>
        <?php else: ?>
          <div class="tbl-wrap">
          <?php /* No data-paginate: the client paginator only hid rows the server
                   had already sent. The page is now 15 rows because the query
                   asked for 15. */ ?>
          <table>
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

          <?php if ($totalPages > 1): ?>
          <?php /* The shared pager from assets/css/admin-shell.css — the same
                   control, in the same place, as Defect Reports and User
                   Management. */ ?>
          <nav class="pager" aria-label="Reservation list pages">
            <span class="pager-count">
              <?php echo number_format($rowFrom); ?>&ndash;<?php echo number_format($rowTo); ?>
              of <strong><?php echo number_format($matchTotal); ?></strong>
            </span>
            <span class="pager-btns">
              <?php if ($page > 1): ?>
                <a class="pgb" href="<?php echo rv_e($pageQuery($page - 1)); ?>" rel="prev"><i class="fas fa-chevron-left"></i> Previous</a>
              <?php else: ?>
                <span class="pgb off"><i class="fas fa-chevron-left"></i> Previous</span>
              <?php endif; ?>

              <?php
              // A window around the current page, so 40 pages don't render 40 links.
              $from = max(1, $page - 2);
              $to   = min($totalPages, $page + 2);
              if ($from > 1): ?>
                <a class="pgb" href="<?php echo rv_e($pageQuery(1)); ?>">1</a>
                <?php if ($from > 2): ?><span class="pg-gap">&hellip;</span><?php endif; ?>
              <?php endif; ?>

              <?php for ($i = $from; $i <= $to; $i++): ?>
                <?php if ($i === $page): ?>
                  <span class="pgb on" aria-current="page"><?php echo $i; ?></span>
                <?php else: ?>
                  <a class="pgb" href="<?php echo rv_e($pageQuery($i)); ?>"><?php echo $i; ?></a>
                <?php endif; ?>
              <?php endfor; ?>

              <?php if ($to < $totalPages): ?>
                <?php if ($to < $totalPages - 1): ?><span class="pg-gap">&hellip;</span><?php endif; ?>
                <a class="pgb" href="<?php echo rv_e($pageQuery($totalPages)); ?>"><?php echo number_format($totalPages); ?></a>
              <?php endif; ?>

              <?php if ($page < $totalPages): ?>
                <a class="pgb" href="<?php echo rv_e($pageQuery($page + 1)); ?>" rel="next">Next <i class="fas fa-chevron-right"></i></a>
              <?php else: ?>
                <span class="pgb off">Next <i class="fas fa-chevron-right"></i></span>
              <?php endif; ?>
            </span>
          </nav>
          <?php endif; ?>
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
           style="margin-left:auto;"><i class="fas fa-print"></i> Print Form</a>
        <button class="x" type="button" data-close aria-label="Close" style="margin-left:.4rem;"><i class="fas fa-xmark"></i></button>
      </div>
      <div class="dlg-b">
        <div id="rvWarn"></div>
        <div id="rvFacts"></div>
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
              <button class="btn ghost sm" type="submit"><i class="fas fa-signature"></i> Record Endorsement</button>
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
              <button class="btn ok sm" type="submit"><i class="fas fa-check"></i> Approve &amp; Issue VRF No.</button>
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
              <button class="btn m sm" type="submit"><i class="fas fa-save"></i> Save Payment</button>
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
              <button class="btn ghost sm" type="submit"><i class="fas fa-arrow-right"></i> Update Status</button>
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
  // Changing a filter starts a new search, so the old page position goes with it.
  u.searchParams.delete('page');
  var next = u.toString();
  // Same two guards as User Management: this is a full page load against
  // Supabase, so it runs when the admin asks for it and not twice for one ask.
  // The search box used to call this on a 450ms input debounce, which reloaded
  // the page two or three times while a name was being typed.
  if (rvNav || next === location.href) { return; }
  rvNav = true;
  document.body.classList.add('is-nav');
  location.href = next;
}
var rvNav = false;
// A bfcache restore keeps the old JS state, which would otherwise leave the
// page permanently refusing to navigate.
window.addEventListener('pageshow', function () {
  rvNav = false;
  document.body.classList.remove('is-nav');
});

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

  // Same sections, same order as admin_reservation_print.php, so the person
  // checking the screen against the signed paper copy reads down both alike.
  var dash = '\u2014';
  var sections = [
    ['Applicant', [
      ['Name of Applicant', esc(d.name)],
      ['Department / Organization', esc(d.org)],
      ['Contact', esc(d.email || d.phone || dash)]
    ]],
    ['Activity', [
      ['Venue', esc(d.venue)],
      ['Nature of Activity', esc(d.nature)],
      ['Date and Time', esc(d.when) + (d.dur ? ' <span style="color:#9C7A7A;font-weight:600">(' + esc(d.dur) + ')</span>' : '')],
      ['Expected Participants', d.pax ? esc(d.pax) : dash]
    ]],
    ['Endorsement and Status', [
      ['Status', esc(d.slabel)],
      ['Endorsed By', d.adviser ? esc(d.adviser) : 'Not yet endorsed']
    ]],
    ['Cashier and Payment', [
      ['CF No.', esc(d.cf || dash)],
      ['Assessment', peso(d.assess)],
      ['Amount Paid', peso(d.paid) + (d.ptype ? ' (' + esc(d.ptype) + ')' : '')],
      ['OR No.', esc(d.orno || dash) + (d.ordate ? ' \u00b7 ' + esc(d.ordate) : '')],
      ['Cashier', esc(d.cashier || dash)]
    ]]
  ];
  document.getElementById('rvFacts').innerHTML = '<div class="rvform">' + sections.map(function (sec) {
    var body = sec[1].map(function (fl) {
      var v = fl[1];
      var blank = (v === dash || v === '' || v === null || v === undefined);
      return '<div class="rvf"><span class="l">' + fl[0] + '</span>'
           + '<span class="v' + (blank ? ' empty' : '') + '">' + (blank ? dash : v) + '</span></div>';
    }).join('');
    return '<div class="rvsec"><h5>' + sec[0] + '</h5><div class="rvflds">' + body + '</div></div>';
  }).join('') + '</div>';

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
    warn = 'This request no longer holds the venue — the slot is open for other bookings.';
  } else if (!d.adviser && d.status === 'submitted') {
    warn = 'Not yet endorsed by a department head or organization adviser. The paper form needs that signature before the PMO signs.';
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
