<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startPublicSession();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';

$query = trim($_GET['ticket'] ?? ($_GET['q'] ?? ''));
$report = null;
$relatedReports = [];
$trackSuggestions = [];
$error = '';
$conn = getDBConnection();

$FOLLOW_UP_MAX = 3;

// Who is asking? The reporter portal signs people in with their name and BEC
// email (student_index.php) and keeps it in the session.
// Follow-up and the one-time satisfaction verdict are owner-only actions, so
// they answer to the same session lifetime as the reporter portal.
if (!empty($_SESSION['guest_email']) && function_exists('becGuestSessionActive') && !becGuestSessionActive()) {
    becEndGuestSession();
}
$viewerEmail = strtolower(trim((string)($_SESSION['guest_email'] ?? '')));
$viewerName  = trim((string)($_SESSION['guest_name'] ?? ''));

/**
 * True when the signed-in reporter is the person who filed this report.
 *
 * Following up and confirming satisfaction used to need nothing at all — no
 * session, no token — so anyone who knew (or guessed, from the suggestion list
 * this page used to publish) a ticket number could bump someone else's report
 * or spend their one-time satisfaction verdict for them.
 */
function trackViewerOwnsReport(string $viewerEmail, ?array $report): bool {
    if ($viewerEmail === '' || !$report) { return false; }
    return strtolower(trim((string)($report['reporter_email'] ?? ''))) === $viewerEmail;
}

// --- Follow-up / Bump handler (#4) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'follow_up') {
    requireCsrf();
    $fid = trim((string)($_POST['report_id'] ?? ''));
    $rep = null;
    if ($fid !== '') {
        $fst = $conn->prepare("SELECT report_id, status, COALESCE(follow_up_count,0) AS follow_up_count, equipment_name, COALESCE(reporter_email,'') AS reporter_email FROM defect_reports WHERE report_id = ? LIMIT 1");
        if ($fst) { $fst->bind_param('s', $fid); $fst->execute(); $rep = $fst->get_result()->fetch_assoc(); $fst->close(); }
    }
    $redirect = 'track_report.php?q=' . urlencode($fid);
    if (!$rep) {
        $redirect .= '&fu=notfound';
    } elseif (!trackViewerOwnsReport($viewerEmail, $rep)) {
        $redirect .= '&fu=notyours';
    } elseif (in_array(strtolower((string)$rep['status']), ['completed','verified','closed','rejected'], true)) {
        $redirect .= '&fu=resolved';
    } elseif ((int)$rep['follow_up_count'] >= $FOLLOW_UP_MAX) {
        $redirect .= '&fu=max';
    } else {
        $newCount = (int)$rep['follow_up_count'] + 1;
        $up = $conn->prepare("UPDATE defect_reports SET follow_up_count = ? WHERE report_id = ?");
        if ($up) { $up->bind_param('is', $newCount, $fid); $up->execute(); $up->close(); }
        // Notify all active admins
        $adminRes = $conn->query("SELECT user_id FROM users WHERE role = 'admin' AND status = 'active' AND user_id IS NOT NULL AND user_id != ''");
        if ($adminRes) {
            $msg = 'Reporter has requested a follow-up (#' . $newCount . ') regarding Ticket ' . $fid . '.';
            while ($a = $adminRes->fetch_assoc()) {
                $aid = trim((string)($a['user_id'] ?? ''));
                if ($aid !== '' && function_exists('addNotification')) { try { addNotification($aid, $msg, 'follow_up', $fid); } catch (\Throwable $e) {} }
            }
        }
        if (function_exists('logActivity')) { try { logActivity($viewerEmail, 'reporter', 'report.follow_up', 'Follow-up #' . $newCount . ' on ' . $fid . ' by ' . ($viewerName !== '' ? $viewerName : $viewerEmail)); } catch (\Throwable $e) {} }
        $redirect .= '&fu=ok&fn=' . $newCount;
    }
    header('Location: ' . $redirect);
    exit;
}

// --- Reporter satisfaction confirmation (closes the loop) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_satisfaction') {
    requireCsrf();
    $sid = trim((string)($_POST['report_id'] ?? ''));
    $verdict = ($_POST['verdict'] ?? '') === 'yes' ? 'satisfied' : 'unsatisfied';
    $note = trim((string)($_POST['satisfaction_note'] ?? ''));
    if (mb_strlen($note) > 500) { $note = mb_substr($note, 0, 500); }
    $rep = null;
    if ($sid !== '') {
        $sst = $conn->prepare("SELECT report_id, status, COALESCE(satisfaction,'') AS satisfaction, COALESCE(reporter_email,'') AS reporter_email FROM defect_reports WHERE report_id = ? LIMIT 1");
        if ($sst) { $sst->bind_param('s', $sid); $sst->execute(); $rep = $sst->get_result()->fetch_assoc(); $sst->close(); }
    }
    $redirect = 'track_report.php?q=' . urlencode($sid);
    if (!$rep) {
        $redirect .= '&sat=notfound';
    } elseif (!trackViewerOwnsReport($viewerEmail, $rep)) {
        // The verdict can only be given once, so letting a stranger spend it
        // would silently take the reporter's say away for good.
        $redirect .= '&sat=notyours';
    } elseif (!in_array(strtolower((string)$rep['status']), ['completed','verified','closed'], true)) {
        $redirect .= '&sat=tooearly';
    } elseif ($rep['satisfaction'] !== '') {
        $redirect .= '&sat=already';
    } else {
        $up = $conn->prepare("UPDATE defect_reports SET satisfaction = ?, satisfaction_at = NOW(), satisfaction_note = ? WHERE report_id = ?");
        if ($up) { $up->bind_param('sss', $verdict, $note, $sid); $up->execute(); $up->close(); }
        // Reporter satisfied on a PMO-verified report closes the loop for good
        // (verified → closed), so the two "resolved" states don't linger apart.
        if ($verdict === 'satisfied' && strtolower((string)$rep['status']) === 'verified') {
            $cl = $conn->prepare("UPDATE defect_reports SET status = 'closed' WHERE report_id = ?");
            if ($cl) { $cl->bind_param('s', $sid); $cl->execute(); $cl->close(); }
            if (function_exists('logActivity')) { try { logActivity($viewerEmail, 'reporter', 'report.closed', 'Auto-closed after ' . ($viewerName !== '' ? $viewerName : $viewerEmail) . ' confirmed resolution — ' . $sid); } catch (\Throwable $e) {} }
        }
        // If not fixed, alert admins so they can re-open / follow through.
        if ($verdict === 'unsatisfied') {
            $adminRes = $conn->query("SELECT user_id FROM users WHERE role = 'admin' AND status = 'active' AND user_id IS NOT NULL AND user_id != ''");
            if ($adminRes) {
                $msg = 'Reporter marked Ticket ' . $sid . ' as NOT resolved' . ($note !== '' ? ': ' . $note : '.');
                while ($a = $adminRes->fetch_assoc()) {
                    $aid = trim((string)($a['user_id'] ?? ''));
                    if ($aid !== '' && function_exists('addNotification')) { try { addNotification($aid, $msg, 'satisfaction', $sid); } catch (\Throwable $e) {} }
                }
            }
        }
        if (function_exists('logActivity')) { try { logActivity($viewerEmail, 'reporter', 'report.satisfaction', $verdict . ' on ' . $sid . ' by ' . ($viewerName !== '' ? $viewerName : $viewerEmail)); } catch (\Throwable $e) {} }
        $redirect .= '&sat=ok&v=' . ($verdict === 'satisfied' ? '1' : '0');
    }
    header('Location: ' . $redirect);
    exit;
}

// The type-ahead used to hand every visitor the newest 120 ticket numbers,
// which is exactly the list someone needs to go poking at other people's
// reports. A signed-in reporter now sees only their own tickets; everyone else
// sees none and must type the reference from their confirmation email.
$suggestionResult = null;
if ($viewerEmail !== '') {
    $sugStmt = $conn->prepare("
        SELECT
            dr.report_id,
            dr.status,
            dr.report_date,
            e.equipment_id,
            COALESCE(e.asset_tag, '') AS asset_tag,
            COALESCE(e.equipment_name, '') AS equipment_name,
            COALESCE(e.location, '') AS location
        FROM defect_reports dr
        JOIN equipment e ON dr.equipment_id = e.equipment_id
        WHERE LOWER(COALESCE(dr.reporter_email, '')) = ?
        ORDER BY dr.report_date DESC
        LIMIT 120
    ");
    if ($sugStmt) {
        $sugStmt->bind_param('s', $viewerEmail);
        $sugStmt->execute();
        $suggestionResult = $sugStmt->get_result();
    }
}

if ($suggestionResult) {
    while ($row = $suggestionResult->fetch_assoc()) {
        $trackSuggestions[] = [
            'report_id' => (string)($row['report_id'] ?? ''),
            'equipment_id' => (string)($row['equipment_id'] ?? ''),
            'asset_tag' => (string)($row['asset_tag'] ?? ''),
            'equipment_name' => (string)($row['equipment_name'] ?? ''),
            'location' => (string)($row['location'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'report_date' => (string)($row['report_date'] ?? ''),
        ];
    }
}

if ($query !== '') {
    $stmt = $conn->prepare("
        SELECT
            dr.report_id,
            dr.priority,
            dr.status,
            dr.issue_description,
            dr.report_date,
            dr.assigned_date,
            dr.completion_date,
            dr.technician_notes,
            dr.verification_notes,
            COALESCE(dr.follow_up_count, 0) AS follow_up_count,
            COALESCE(dr.satisfaction, '') AS satisfaction,
            COALESCE(dr.reporter_email, '') AS reporter_email,
            dr.received_by_pmo_at,
            COALESCE(pu.fullname, '') AS received_by_name,
            e.equipment_name,
            e.equipment_id,
            e.asset_tag,
            COALESCE(e.status, '') AS equipment_status,
            COALESCE(e.condition_status, '') AS equipment_condition,
            COALESCE(c.category_name, CAST(e.category_id AS CHAR), 'Uncategorized') AS category_name,
            COALESCE(e.location, '') AS location
        FROM defect_reports dr
        JOIN equipment e ON dr.equipment_id = e.equipment_id
        LEFT JOIN categories c ON e.category_id = c.category_id
        LEFT JOIN users pu ON pu.user_id = dr.received_by_pmo_id
        WHERE dr.report_id = ?
           OR e.equipment_id = ?
           OR e.asset_tag = ?
        ORDER BY dr.report_date DESC
        LIMIT 1
    ");
    $stmt->bind_param('sss', $query, $query, $query);
    $stmt->execute();
    $report = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$report) {
        $error = 'No report matched that ticket number or equipment reference.';
    } else {
        $historyStmt = $conn->prepare("
            SELECT
                dr.report_id,
                dr.status,
                dr.priority,
                dr.report_date,
                dr.assigned_date,
                dr.completion_date
            FROM defect_reports dr
            WHERE dr.equipment_id = ?
            ORDER BY dr.report_date DESC
            LIMIT 6
        ");
        $historyStmt->bind_param('s', $report['equipment_id']);
        $historyStmt->execute();
        $relatedReports = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $historyStmt->close();
    }
}

function tr_status_label(string $status): string {
    return defectStatusLabel($status);
}

function tr_status_class(string $status): string {
    return match (defectStatusCategory($status)) {
        'pending' => 'st-open',
        'in_progress' => 'st-prog',
        'completed' => 'st-done',
        'rejected' => 'st-open',
        default => '',
    };
}

function tr_priority_class(string $priority): string {
    return match (strtolower((string)$priority)) {
        'low' => 'sev-low',
        'medium' => 'sev-med',
        'high' => 'sev-high',
        'critical' => 'sev-crit',
        default => '',
    };
}

function tr_equipment_status_label(string $status): string {
    return match (strtolower($status)) {
        'available', 'operational' => 'Operational',
        'maintenance', 'under_maintenance' => 'Under Maintenance',
        'reserved', 'in_use', 'in use', 'borrowed' => 'In Use',
        'defective', 'faulty', 'damaged' => 'Needs Attention',
        default => $status !== '' ? ucwords(str_replace('_', ' ', $status)) : 'Unknown',
    };
}

function tr_equipment_status_class(string $status): string {
    return match (strtolower($status)) {
        'available', 'operational' => 'eq-ok',
        'maintenance', 'under_maintenance' => 'eq-maint',
        'reserved', 'in_use', 'in use', 'borrowed' => 'eq-use',
        'defective', 'faulty', 'damaged' => 'eq-bad',
        default => 'eq-unk',
    };
}

function tr_when(?string $datetime): string {
    if (!$datetime) {
        return '—';
    }
    return date('M j, Y g:i A', strtotime($datetime));
}

function tr_step_desc(string $label): string {
    $label = strtolower($label);
    $map = [
        'submit'                => 'Your report reached the system and was queued for the Property Management Office.',
        'pending'               => 'Waiting for the PMO to open and review your report.',
        'received by pmo'       => 'The PMO has acknowledged your report and started the review.',
        'review'                => 'The PMO is checking the details and confirming the priority.',
        'approved'              => 'Your report was approved and scheduled for repair.',
        'assign'                => 'A technician has been assigned to handle this repair.',
        'received by technician'=> 'The technician acknowledged the task and is preparing to start.',
        'in progress'           => 'The technician is actively working on the equipment.',
        'repair'                => 'The technician is actively diagnosing and repairing the equipment.',
        'replacement'           => 'The equipment was endorsed for replacement instead of repair.',
        'complet'               => 'The repair work is finished and documented with findings.',
        'verif'                 => 'The PMO is verifying that the work meets standards.',
        'clos'                  => 'All done — this report is fully resolved and closed.',
        'reject'                => 'After review, this report could not be approved.',
    ];
    foreach ($map as $key => $text) {
        if (str_contains($label, $key)) { return $text; }
    }
    return '';
}

function tr_timeline(array $report): array {
    $items = [];
    foreach (defectTimelineSteps($report) as $step) {
        $state = $step['active'] ? 'active' : ($step['done'] ? 'done' : 'pending');

        // Real-time rule: only completed milestones carry a timestamp.
        // Never show placeholder or future dates on pending/active stages.
        $date = null;
        $extra = '';
        $label = strtolower($step['label']);
        if ($state === 'done' || ($state === 'active' && str_contains($label, 'received by pmo'))) {
            if (str_contains($label, 'received by pmo')) {
                $date = $report['received_by_pmo_at'] ?? $report['report_date'] ?? null;
                $who = trim((string)($report['received_by_name'] ?? ''));
                if ($who !== '') { $extra = 'Acknowledged by ' . $who . ' (Property Management Office).'; }
            } elseif (str_contains($label, 'assign')) {
                $date = $report['assigned_date'] ?? null;
            } elseif (str_contains($label, 'repair') || str_contains($label, 'verification')
                || str_contains($label, 'complet') || str_contains($label, 'clos')
                || str_contains($label, 'replacement')) {
                $date = $report['completion_date'] ?? $report['assigned_date'] ?? null;
            } else {
                $date = $report['report_date'] ?? null;
            }
            if ($state !== 'done') { $date = $date && strtotime((string)$date) ? $date : null; }
        }

        $items[] = [
            'label' => $step['label'],
            'date'  => $date,
            'state' => $state,
            'desc'  => $extra !== '' ? $extra : tr_step_desc((string)$step['label']),
        ];
    }

    // Fully-resolved reports: nothing is still "in progress" — mark every step done
    // (verified is the practical end state; many reports never move to 'closed').
    $statusLc = strtolower(trim((string)($report['status'] ?? '')));
    if (in_array($statusLc, ['verified', 'closed'], true)) {
        foreach ($items as &$it) { if ($it['state'] === 'active') { $it['state'] = 'done'; } }
        unset($it);
    }

    return $items;
}

function tr_progress(array $timeline, string $status = ''): array {
    $status = strtolower(trim($status));
    $total = count($timeline);
    $done = 0; $current = '';
    foreach ($timeline as $t) {
        if ($t['state'] === 'done') { $done++; $current = $t['label']; }
        if ($t['state'] === 'active') { $current = $t['label']; }
    }
    // Terminal states are 100% complete.
    if (in_array($status, ['verified', 'closed'], true)) {
        return ['pct' => 100, 'current' => $current ?: 'Resolved', 'done' => $total, 'total' => $total];
    }
    $hasActive = false;
    foreach ($timeline as $t) { if ($t['state'] === 'active') { $hasActive = true; break; } }
    $pct = $total ? (int)round((($done + ($hasActive ? 0.5 : 0)) / $total) * 100) : 0;
    return ['pct' => min(100, max(4, $pct)), 'current' => $current, 'done' => $done, 'total' => $total];
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Track Report - BEC Equipment</title>
<link rel="icon" type="image/png" href="assets/logs.png">
<link rel="shortcut icon" href="assets/logs.png">
<link rel="apple-touch-icon" href="assets/logs.png">
<!-- Served from this server, not a CDN, so tracking keeps its icons and
     typefaces when the campus connection is unavailable. -->
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="css/typography.css">
<style>
/* Root size bump — MUST stay in <head>. Setting this from an end-of-body
   include repaints, then re-lays-out, the whole page on every load. */
html{font-size:106.25%;scrollbar-gutter:stable;}

:root{
  /* Semantic status ramp — the same values as assets/css/admin-shell.css,
     repeated because this page does not load the admin shell. One meaning,
     one colour, across every surface. */
  --ok:#16A34A;--ok-tx:#166534;--ok-bg:#F0FDF4;--ok-bdr:#BBF7D0;
  --warn:#D97706;--warn-tx:#92600A;--warn-bg:#FFFBEB;--warn-bdr:#FDE68A;
  --bad:#DC2626;--bad-tx:#991B1B;--bad-bg:#FEF2F2;--bad-bdr:#FECACA;
  --info:#2563EB;--info-tx:#1D4ED8;--info-bg:#EFF6FF;--info-bdr:#BFDBFE;

  /* Shared six-step scales — same steps as the admin pages, so a size or a
     gap means the same thing across the system. Nothing between them. */
  --fs-xs:.6rem;--fs-sm:.68rem;--fs-base:.76rem;--fs-md:.82rem;--fs-lg:.88rem;--fs-xl:.95rem;--fs-2xl:1.05rem;
  --sp-0:.125rem;--sp-1:.25rem;--sp-2:.5rem;--sp-3:.75rem;--sp-4:1rem;--sp-5:1.5rem;

  --m:#7B1D1D;--md:#4A0E0E;--g:#C9960C;--k:#1C1008;--k2:#5C3838;--k3:#9E8070;--p:#F8F3EA;--s:#FFFFFF;--b:#E8DDD0;
}
*{box-sizing:border-box}
body{margin:0;font-family:'DM Sans',sans-serif;background:var(--p);color:var(--k);padding:0}
/* One focus ring for the page. This tracker is a form-first page reached from
   the landing page's second CTA, and it had no focus style at all — the browser
   default is nearly invisible on the maroon buttons and inside the status hero.
   :focus-visible keeps it off mouse clicks. */
:focus-visible{outline:3px solid var(--m);outline-offset:3px}
.track-hero :focus-visible,.btn:focus-visible,.fu-btn.active:focus-visible{outline-color:#F0C040}
.page{max-width:760px;margin:0 auto;padding:var(--sp-5)}
/* (page header now provided by the shared includes/site_nav.php) */
.eyebrow{display:inline-flex;align-items:center;gap:var(--sp-2);background:#FFF4DD;color:#92600A;border:1px solid rgba(201,150,12,.3);padding:var(--sp-1) var(--sp-3);border-radius:999px;font-size:var(--fs-sm);font-weight:700;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:var(--sp-2)}
.card{background:var(--s);border:1px solid var(--b);border-radius:18px;padding:var(--sp-4);box-shadow:0 2px 12px rgba(44,10,10,.06)}
h1{font-family:'Fraunces',serif;font-size:1.95rem;margin:var(--sp-1) 0 var(--sp-1);letter-spacing:-.02em}
.sub{color:var(--k3);line-height:1.6;margin-bottom:var(--sp-4)}
form{display:flex;gap:var(--sp-3);flex-wrap:wrap}
.search-wrap{position:relative;flex:1 1 260px}
/* width:100%, not flex — this input's parent is .search-wrap (position:relative,
   a normal block), so flex properties on it were inert and it fell back to the
   intrinsic ~177px input width. It only looked right on a wide screen. */
.input{width:100%;box-sizing:border-box;padding:var(--sp-3) var(--sp-4);border:1.5px solid var(--b);border-radius:12px;font:inherit}
.btn{padding:var(--sp-3) var(--sp-4);border:none;border-radius:12px;background:var(--md);color:#fff;font:inherit;font-weight:600;cursor:pointer}
.search-dd{position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:40;background:var(--s);border:1.5px solid var(--m);border-radius:12px;box-shadow:0 10px 30px rgba(44,10,10,.15);max-height:280px;overflow-y:auto;display:none}
.search-dd.open{display:block}
.search-item{display:flex;gap:var(--sp-3);align-items:flex-start;padding:var(--sp-3) var(--sp-3);cursor:pointer;border-top:1px solid rgba(232,221,208,.7)}
.search-item:first-child{border-top:none}
.search-item:hover,.search-item.focused{background:#fcf6ed}
.search-icon{width:28px;height:28px;border-radius:50%;background:rgba(123,29,29,.08);color:var(--m);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:var(--fs-md)}
.search-copy{display:grid;gap:var(--sp-0);min-width:0}
.search-title{font-weight:700;overflow-wrap:anywhere}
.search-meta{font-size:var(--fs-base);color:var(--k3);line-height:1.45;overflow-wrap:anywhere}
.search-empty{padding:var(--sp-3) var(--sp-4);font-size:var(--fs-md);color:var(--k3);text-align:center}
.alert{margin-top:var(--sp-4);padding:var(--sp-3) var(--sp-4);border-radius:12px;border:1px solid #fecaca;background:#fef2f2;color:var(--bad-tx)}
.result{margin-top:var(--sp-4)}
.ticket{font-family:'Fraunces',serif;font-size:1.4rem;color:var(--m);margin-bottom:var(--sp-3)}
.badges{display:flex;gap:var(--sp-2);flex-wrap:wrap;margin-bottom:var(--sp-4)}
.badge{display:inline-flex;align-items:center;gap:var(--sp-1);padding:var(--sp-1) var(--sp-3);border-radius:999px;font-size:var(--fs-base);font-weight:700}
.sev-low{background:#F0FDF4;color:var(--ok-tx)}.sev-med{background:#FFFBEB;color:#92400E}.sev-high{background:#FFF7ED;color:#C2410C}.sev-crit{background:#FEF2F2;color:var(--bad-tx)}
.st-open{background:#FEF2F2;color:var(--bad-tx)}.st-prog{background:#FFF7ED;color:#C2410C}.st-done{background:#F0FDF4;color:var(--ok-tx)}
.eq-ok{background:#F0FDF4;color:var(--ok-tx)}.eq-maint{background:#FFF7ED;color:#C2410C}.eq-use{background:#EBF5FF;color:#1D4ED8}.eq-bad{background:#FEF2F2;color:var(--bad-tx)}.eq-unk{background:#F3F4F6;color:#4B5563}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-3)}
.item{background:#fffaf4;border:1px solid var(--b);border-radius:12px;padding:var(--sp-3)}
.label{font-size:var(--fs-sm);text-transform:uppercase;letter-spacing:.8px;color:var(--k3);margin-bottom:var(--sp-1)}
.value{font-weight:600;overflow-wrap:anywhere}
.full{grid-column:1/-1}
.hint{font-size:var(--fs-base);color:var(--k3);margin-top:var(--sp-2);line-height:1.5}
.timeline{margin-top:var(--sp-4);display:grid;gap:var(--sp-3)}
.tl-item{display:flex;gap:var(--sp-3);align-items:flex-start}
.tl-dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:var(--fs-sm);font-weight:700;flex-shrink:0}
.tl-item.done .tl-dot{background:#F0FDF4;color:var(--ok-tx)}
.tl-item.active .tl-dot{background:#FFF7ED;color:#C2410C}
.tl-item.pending .tl-dot{background:#F3F4F6;color:#6B7280}
.tl-title{font-weight:700;margin-bottom:var(--sp-0)}
.tl-date{font-size:var(--fs-base);color:var(--k3)}
.mini-table{margin-top:var(--sp-4);border-top:1px solid var(--b);padding-top:var(--sp-4)}
.mini-row{display:grid;grid-template-columns:120px 1fr 110px;gap:var(--sp-2);padding:var(--sp-2) 0;border-bottom:1px solid #f1e6d8}
.mini-row:last-child{border-bottom:none}
.mini-id{font-weight:700;color:var(--m);overflow-wrap:anywhere}
.mini-date{font-size:var(--fs-base);color:var(--k3);text-align:right}
/* ── Status hero (delivery-style) ── */
.track-hero{display:flex;align-items:center;gap:var(--sp-4);margin:var(--sp-4) 0 var(--sp-2);padding:var(--sp-4) var(--sp-4);border-radius:16px;background:linear-gradient(135deg,var(--md),var(--m));color:#fff;position:relative;overflow:hidden}
.track-hero::after{content:'';position:absolute;right:-30px;top:-30px;width:140px;height:140px;border-radius:50%;background:rgba(201,150,12,.18)}
.th-pulse{width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.14);border:1.5px solid rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0;position:relative;z-index:1}
.th-pulse::before{content:'';position:absolute;inset:-6px;border-radius:50%;border:2px solid rgba(255,255,255,.3);animation:thp 2s ease-out infinite}
@keyframes thp{0%{transform:scale(.8);opacity:.7}100%{transform:scale(1.4);opacity:0}}
.th-main{flex:1;min-width:0;position:relative;z-index:1}
.th-main small{font-size:var(--fs-sm);text-transform:uppercase;letter-spacing:1.2px;opacity:.8}
.th-main strong{display:block;font-size:1.12rem;font-weight:700;margin:var(--sp-0) 0 var(--sp-0)}
.th-main span{font-size:var(--fs-base);opacity:.85;line-height:1.45}
.th-prog{text-align:center;flex-shrink:0;position:relative;z-index:1}
.th-ring{--pct:0;width:66px;height:66px;border-radius:50%;background:conic-gradient(var(--g) calc(var(--pct)*1%),rgba(255,255,255,.22) 0);display:flex;align-items:center;justify-content:center;margin:0 auto var(--sp-1)}
.th-ring span{width:50px;height:50px;border-radius:50%;background:var(--m);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:var(--fs-lg)}
.th-prog small{font-size:var(--fs-sm);opacity:.85}

/* ── Real-time tracking timeline ── */
.rt-timeline{margin-top:var(--sp-2);position:relative}
.rt-step{position:relative;padding:0 0 var(--sp-4) 2.4rem;}
.rt-step:last-child{padding-bottom:0}
.rt-step::before{content:'';position:absolute;left:13px;top:26px;bottom:-2px;width:2px;background:var(--b)}
.rt-step:last-child::before{display:none}
.rt-step.done::before{background:#86c79e}
.rt-marker{position:absolute;left:0;top:0;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:var(--fs-sm);font-weight:700;background:#F3F4F6;color:#9aa}
.rt-step.done .rt-marker{background:var(--ok-tx);color:#fff}
.rt-step.active .rt-marker{background:var(--g);color:#3a2a02;box-shadow:0 0 0 0 rgba(201,150,12,.5);animation:rtp 1.8s infinite}
@keyframes rtp{0%{box-shadow:0 0 0 0 rgba(201,150,12,.45)}70%{box-shadow:0 0 0 9px rgba(201,150,12,0)}100%{box-shadow:0 0 0 0 rgba(201,150,12,0)}}
.rt-title{font-weight:700;font-size:var(--fs-xl);display:flex;align-items:center;gap:var(--sp-2);flex-wrap:wrap}
.rt-step.pending .rt-title{color:var(--k3);font-weight:600}
.rt-live{font-size:var(--fs-xs);font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#92400E;background:#FFF3D6;border:1px solid #f0d493;padding:var(--sp-0) var(--sp-2);border-radius:999px}
.rt-desc{font-size:var(--fs-base);color:var(--k2);line-height:1.5;margin-top:var(--sp-1)}
.rt-step.pending .rt-desc{color:var(--k3)}
.rt-date{font-size:var(--fs-sm);color:var(--ok-tx);font-weight:600;margin-top:var(--sp-1);display:flex;align-items:center;gap:var(--sp-1)}
.rt-next{font-size:var(--fs-sm);color:var(--k3);margin-top:var(--sp-1);font-style:italic}

/* ── Follow-up / bump ── */
.followup{background:#fffaf4;border:1px solid var(--b);border-radius:14px;padding:var(--sp-4);margin-top:var(--sp-2)}
.fu-copy{font-size:var(--fs-md);color:var(--k2);line-height:1.55;margin:0 0 var(--sp-3)}
.fu-dots{display:flex;align-items:center;gap:var(--sp-2);margin-bottom:var(--sp-3)}
.fu-dot{width:26px;height:26px;border-radius:50%;border:2px solid var(--b);display:flex;align-items:center;justify-content:center;font-size:var(--fs-sm);font-weight:700;color:var(--k3)}
.fu-dot.used{background:var(--m);border-color:var(--m);color:#fff}
.fu-remaining{font-size:var(--fs-base);color:var(--k3);margin-left:var(--sp-1)}
.fu-btn{display:inline-flex;align-items:center;gap:var(--sp-2);padding:var(--sp-3) var(--sp-4);border-radius:11px;font:inherit;font-weight:700;font-size:var(--fs-lg);border:none;cursor:pointer}
.fu-btn.active{background:linear-gradient(135deg,var(--md),var(--m));color:#fff;box-shadow:0 4px 14px rgba(123,29,29,.25)}
.fu-btn.active:hover{filter:brightness(1.08)}
.fu-btn[disabled]{background:#eee;color:#999;cursor:not-allowed}
.fu-flash{display:flex;align-items:center;gap:var(--sp-2);padding:var(--sp-2) var(--sp-3);border-radius:10px;font-size:var(--fs-md);font-weight:600;margin-bottom:var(--sp-3)}
.fu-flash.ok{background:#F0FDF4;color:var(--ok-tx);border:1px solid #bbf7d0}
.fu-flash.err{background:#FEF7ED;color:#9A3412;border:1px solid #fed7aa}
/* The phone rules tightened `body` but left .page and .card at their desktop
   padding, so three gutters stacked: .9rem + 1.25rem + 1.2rem put the card's
   text 58px from the screen edge — against 19px on index.php and 21px on
   public_reports.php, so the content lurched inward when you moved between
   public pages, and the reading column was down to 259px. Dropping .page's
   horizontal padding (its vertical rhythm is kept) puts the card edge at the
   same ~15px margin the other public pages use and gives the text back 42px. */
@media (max-width:600px){.grid{grid-template-columns:1fr}h1{font-size:1.45rem}body{padding:var(--sp-4)}.page{padding:var(--sp-5) 0}.mini-row{grid-template-columns:1fr}.mini-date{text-align:left}.track-hero{flex-wrap:wrap}.th-prog{order:3;width:100%;display:flex;align-items:center;justify-content:center;gap:var(--sp-2)}.th-ring{margin:0}.input{font-size:16px}
/* Type floor. Tracking a ticket is the one thing people do on this page from a
   phone, standing in the room with the broken equipment — the status date and
   the "what happens next" line were 11.9-12.2px and are the whole answer. */
.eyebrow{font-size:var(--fs-sm)}.label,.rt-date{font-size:var(--fs-base)}.rt-next{font-size:var(--fs-base)}
/* "Current status", "N of M stages" and the In-progress pill on the active
   timeline step ran 10.5-11.2px — these are the answer the page exists to give. */
.th-main small,.th-prog small{font-size:var(--fs-base)}.rt-live{font-size:var(--fs-sm)}}
html{scroll-behavior:smooth}
.item{transition:box-shadow .18s,transform .18s,border-color .18s}
.item:hover{box-shadow:0 8px 22px rgba(123,29,29,.08);transform:none;border-color:rgba(201,150,12,.4)}
@media (prefers-reduced-motion:reduce){html{scroll-behavior:auto}}
</style>
</head>
<body>
<?php $nav_active = 'track'; require __DIR__ . '/includes/site_nav.php'; ?>
<?php $hero_title = 'Track Your Report'; $hero_sub = 'Enter your report ticket, equipment ID, or asset tag to see the latest status and progress.'; require __DIR__ . '/includes/site_hero.php'; ?>
  <main id="main" class="page">

    <div class="card">
      <div class="eyebrow"><i aria-hidden="true" class="fas fa-magnifying-glass"></i> Report Tracker</div>
      <h1>Track your ticket</h1>
      <div class="sub">Enter the report ticket, equipment ID, or asset tag to check both the report progress and the current equipment status.</div>
      <form method="GET" action="">
        <div class="search-wrap">
          <input class="input" id="track-search" type="text" name="q" placeholder="e.g. BEC-A1B2C3D4, EQ-001, or COMP-001" value="<?php echo htmlspecialchars($query); ?>" autocomplete="off" required>
          <div class="search-dd" id="track-dropdown"></div>
        </div>
        <button class="btn" type="submit">Track Report</button>
      </form>
      <div class="hint">This tracker supports report tickets, equipment IDs, and asset tags. Start typing to see possible matches from recent reports.</div>

      <?php if ($error): ?>
      <div class="alert"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <?php if ($report): ?>
      <div class="result">
        <div class="ticket"><?php echo htmlspecialchars($report['report_id']); ?></div>
        <?php
          $tl = tr_timeline($report);
          $prog = tr_progress($tl, (string)$report['status']);
          $heroIcon = match (defectStatusCategory((string)$report['status'])) {
            'pending'     => 'fa-hourglass-half',
            'in_progress' => 'fa-screwdriver-wrench',
            'completed'   => 'fa-circle-check',
            'rejected'    => 'fa-circle-xmark',
            default       => 'fa-clipboard-list',
          };
        ?>
        <div class="track-hero">
          <div class="th-pulse"><i aria-hidden="true" class="fas <?php echo $heroIcon; ?>"></i></div>
          <div class="th-main">
            <small>Current status</small>
            <strong><?php echo htmlspecialchars($prog['current'] !== '' ? $prog['current'] : tr_status_label((string)$report['status'])); ?></strong>
            <span><?php echo htmlspecialchars(tr_step_desc($prog['current']) ?: 'Your report is being processed by the Property Management Office.'); ?></span>
          </div>
          <div class="th-prog">
            <div class="th-ring" style="--pct:<?php echo (int)$prog['pct']; ?>"><span><?php echo (int)$prog['pct']; ?>%</span></div>
            <small><?php echo (int)$prog['done']; ?> of <?php echo (int)$prog['total']; ?> stages</small>
          </div>
        </div>
        <div class="badges">
          <span class="badge <?php echo htmlspecialchars(tr_priority_class((string)$report['priority'])); ?>"><?php echo htmlspecialchars(ucfirst((string)$report['priority'])); ?></span>
          <span class="badge <?php echo htmlspecialchars(tr_status_class((string)$report['status'])); ?>"><?php echo htmlspecialchars(tr_status_label((string)$report['status'])); ?></span>
          <span class="badge <?php echo htmlspecialchars(tr_equipment_status_class((string)$report['equipment_status'])); ?>"><?php echo htmlspecialchars(tr_equipment_status_label((string)$report['equipment_status'])); ?></span>
        </div>
        <div class="grid">
          <div class="item">
            <div class="label">Equipment</div>
            <div class="value"><?php echo htmlspecialchars((string)$report['equipment_name']); ?></div>
          </div>
          <div class="item">
            <div class="label">Category</div>
            <div class="value"><?php echo htmlspecialchars((string)$report['category_name']); ?></div>
          </div>
          <div class="item">
            <div class="label">Equipment Reference</div>
            <div class="value"><?php echo htmlspecialchars((string)$report['equipment_id']); ?><?php echo !empty($report['asset_tag']) ? ' / ' . htmlspecialchars((string)$report['asset_tag']) : ''; ?></div>
          </div>
          <div class="item">
            <div class="label">Location</div>
            <div class="value"><?php echo htmlspecialchars((string)($report['location'] ?: 'Unspecified')); ?></div>
          </div>
          <div class="item">
            <div class="label">Equipment Status</div>
            <div class="value"><?php echo htmlspecialchars(tr_equipment_status_label((string)$report['equipment_status'])); ?></div>
          </div>
          <div class="item">
            <div class="label">Condition</div>
            <div class="value"><?php echo htmlspecialchars($report['equipment_condition'] !== '' ? ucwords(str_replace('_', ' ', (string)$report['equipment_condition'])) : 'Unknown'); ?></div>
          </div>
          <div class="item full">
            <div class="label">Description</div>
            <div class="value"><?php echo nl2br(htmlspecialchars((string)$report['issue_description'])); ?></div>
          </div>
          <div class="item full">
            <div class="label">Submitted</div>
            <div class="value"><?php echo htmlspecialchars(tr_when($report['report_date'] ?? null)); ?></div>
          </div>
          <?php if (!empty($report['technician_notes'])): ?>
          <div class="item full">
            <div class="label">Technician Notes</div>
            <div class="value"><?php echo nl2br(htmlspecialchars((string)$report['technician_notes'])); ?></div>
          </div>
          <?php endif; ?>
          <?php if (!empty($report['verification_notes'])): ?>
          <div class="item full">
            <div class="label">Verification Notes</div>
            <div class="value"><?php echo nl2br(htmlspecialchars((string)$report['verification_notes'])); ?></div>
          </div>
          <?php endif; ?>
          <div class="item full">
            <div class="label">Tracking Timeline</div>
            <div class="rt-timeline">
              <?php $shownNext = false; foreach ($tl as $step): ?>
              <div class="rt-step <?php echo htmlspecialchars($step['state']); ?>">
                <div class="rt-marker">
                  <?php if ($step['state'] === 'done'): ?><i aria-hidden="true" class="fas fa-check"></i>
                  <?php elseif ($step['state'] === 'active'): ?><i aria-hidden="true" class="fas fa-screwdriver-wrench"></i>
                  <?php else: ?><i aria-hidden="true" class="fas fa-circle"></i><?php endif; ?>
                </div>
                <div class="rt-body">
                  <div class="rt-title"><?php echo htmlspecialchars($step['label']); ?><?php if ($step['state'] === 'active'): ?> <span class="rt-live">In progress</span><?php endif; ?></div>
                  <?php if ($step['desc'] !== ''): ?><div class="rt-desc"><?php echo htmlspecialchars($step['desc']); ?></div><?php endif; ?>
                  <?php if (!empty($step['date'])): ?>
                  <div class="rt-date"><i aria-hidden="true" class="fas fa-clock"></i> <?php echo htmlspecialchars(tr_when($step['date'])); ?></div>
                  <?php elseif ($step['state'] === 'pending' && !$shownNext): $shownNext = true; ?>
                  <div class="rt-next">Up next — no action yet</div>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php
            $satStatus = strtolower((string)$report['status']);
            $satEligible = in_array($satStatus, ['completed','verified','closed'], true);
            $satGiven = (string)($report['satisfaction'] ?? '');
            $sat = $_GET['sat'] ?? '';
            // Only the person who filed the report may act on it. Anyone else
            // still sees the status — that is what tracking is for — but the
            // buttons that change something are theirs alone.
            $viewerOwnsReport = trackViewerOwnsReport($viewerEmail, $report);
            $signInPrompt = '<div class="fu-flash err"><i aria-hidden="true" class="fas fa-circle-info"></i> '
                . 'Sign in with the BEC email you filed this report from to follow up or confirm the repair. '
                . '<a href="student_index.php" style="color:inherit;text-decoration:underline;">Sign in</a></div>';
          ?>
          <?php if ($satEligible): ?>
          <div class="item full">
            <div class="label">Was your issue resolved?</div>
            <div class="followup">
              <?php if ($sat === 'ok'): ?><div class="fu-flash ok"><i aria-hidden="true" class="fas fa-check-circle"></i> Thank you for your feedback!</div><?php endif; ?>
              <?php if ($satGiven !== ''): ?>
                <div style="display:flex;align-items:center;gap:.55rem;font-size:.9rem;font-weight:600;color:<?php echo $satGiven==='satisfied'?'#166534':'#9A3412'; ?>;">
                  <i aria-hidden="true" class="fas fa-<?php echo $satGiven==='satisfied'?'circle-check':'circle-exclamation'; ?>"></i>
                  You marked this report as <strong><?php echo $satGiven==='satisfied'?'Resolved':'Not resolved'; ?></strong>.
                </div>
              <?php elseif (!$viewerOwnsReport): ?>
                <?php if ($sat === 'notyours'): ?><div class="fu-flash err"><i aria-hidden="true" class="fas fa-circle-info"></i> Only the reporter who filed this ticket can confirm the repair.</div><?php endif; ?>
                <p class="fu-copy">The repair has been marked done. The reporter who filed this ticket can confirm whether the issue was actually resolved.</p>
                <?php echo $signInPrompt; ?>
              <?php else: ?>
                <p class="fu-copy">The repair has been marked done. Please confirm whether your equipment issue was actually resolved — your feedback helps the PMO ensure quality.</p>
                <form method="POST" action="track_report.php" style="display:flex;gap:.55rem;flex-wrap:wrap;align-items:center;">
                  <input type="hidden" name="action" value="confirm_satisfaction">
                  <input type="hidden" name="report_id" value="<?php echo htmlspecialchars((string)$report['report_id']); ?>">
                  <input type="text" name="satisfaction_note" placeholder="Optional comment…" style="flex:1;min-width:150px;padding:.6rem .8rem;border:1.5px solid var(--b);border-radius:10px;font:inherit;">
                  <button class="fu-btn active" type="submit" name="verdict" value="yes" style="background:linear-gradient(135deg,var(--ok-tx),var(--ok));"><i aria-hidden="true" class="fas fa-thumbs-up"></i> Yes, resolved</button>
                  <button class="fu-btn active" type="submit" name="verdict" value="no" style="background:linear-gradient(135deg,#b91c1c,var(--bad));"><i aria-hidden="true" class="fas fa-thumbs-down"></i> Not fixed</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
          <?php
            $fuCount = (int)($report['follow_up_count'] ?? 0);
            $fuResolved = in_array(strtolower((string)$report['status']), ['completed','verified','closed','rejected'], true);
            $fuRemaining = max(0, $FOLLOW_UP_MAX - $fuCount);
            $fu = $_GET['fu'] ?? '';
          ?>
          <div class="item full">
            <div class="label">Need an update?</div>
            <div class="followup">
              <?php if ($fu === 'ok'): ?>
                <div class="fu-flash ok"><i aria-hidden="true" class="fas fa-check-circle"></i> Follow-up sent to the PMO — they have been notified.</div>
              <?php elseif ($fu === 'max'): ?>
                <div class="fu-flash err"><i aria-hidden="true" class="fas fa-circle-info"></i> You have reached the maximum of <?php echo $FOLLOW_UP_MAX; ?> follow-ups for this report.</div>
              <?php elseif ($fu === 'resolved'): ?>
                <div class="fu-flash err"><i aria-hidden="true" class="fas fa-circle-info"></i> This report is already resolved — no follow-up needed.</div>
              <?php elseif ($fu === 'notyours'): ?>
                <div class="fu-flash err"><i aria-hidden="true" class="fas fa-circle-info"></i> Only the reporter who filed this ticket can send a follow-up.</div>
              <?php endif; ?>
              <p class="fu-copy">If your report hasn't moved in a while, you can gently nudge the Property Management Office. You may send up to <strong><?php echo $FOLLOW_UP_MAX; ?></strong> follow-ups per report.</p>
              <div class="fu-dots">
                <?php for($i=1;$i<=$FOLLOW_UP_MAX;$i++): ?><span class="fu-dot <?php echo $i<=$fuCount?'used':''; ?>"><?php echo $i; ?></span><?php endfor; ?>
                <span class="fu-remaining"><?php echo $fuRemaining; ?> remaining</span>
              </div>
              <?php if ($fuResolved): ?>
                <button class="fu-btn" disabled><i aria-hidden="true" class="fas fa-check"></i> Report already resolved</button>
              <?php elseif ($fuCount >= $FOLLOW_UP_MAX): ?>
                <button class="fu-btn" disabled><i aria-hidden="true" class="fas fa-ban"></i> Follow-up limit reached</button>
              <?php elseif (!$viewerOwnsReport): ?>
                <?php echo $signInPrompt; ?>
              <?php else: ?>
                <form method="POST" action="track_report.php" style="margin:0;">
                  <input type="hidden" name="action" value="follow_up">
                  <input type="hidden" name="report_id" value="<?php echo htmlspecialchars((string)$report['report_id']); ?>">
                  <button class="fu-btn active" type="submit"><i aria-hidden="true" class="fas fa-bell"></i> Send Follow-Up #<?php echo $fuCount+1; ?></button>
                </form>
              <?php endif; ?>
            </div>
          </div>
          <?php if (!empty($relatedReports)): ?>
          <div class="item full">
            <div class="label">Recent Logs For This Equipment</div>
            <div class="mini-table">
              <?php foreach ($relatedReports as $entry): ?>
              <div class="mini-row">
                <div class="mini-id"><?php echo htmlspecialchars((string)$entry['report_id']); ?></div>
                <div><?php echo htmlspecialchars(tr_status_label((string)$entry['status'])); ?><?php echo !empty($entry['priority']) ? ' · ' . htmlspecialchars(ucfirst((string)$entry['priority'])) : ''; ?></div>
                <div class="mini-date"><?php echo htmlspecialchars(tr_when($entry['report_date'] ?? null)); ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </main>
<?php require __DIR__ . '/includes/site_footer.php'; ?>
<?php require __DIR__ . '/includes/site_ui.php'; ?>
<script>
const trackInput = document.getElementById('track-search');
const trackDropdown = document.getElementById('track-dropdown');
const trackData = <?php echo json_encode($trackSuggestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let trackFocusIdx = -1;
let trackRenderTimer = null;

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  })[char]);
}

function badgeLabel(status) {
  const normalized = String(status || '').toLowerCase();
  if (['reported','pmo_review','ready_for_assignment'].includes(normalized)) return 'Pending';
  if (['assigned','in_progress','for_replacement'].includes(normalized)) return 'In Progress';
  if (['completed','verified','closed'].includes(normalized)) return 'Resolved';
  if (normalized === 'rejected') return 'Rejected';
  return normalized ? normalized.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : 'Report';
}

function renderTrackDropdown(query) {
  const q = String(query || '').trim().toLowerCase();
  const matches = q
    ? trackData.filter(item =>
        (item.report_id || '').toLowerCase().includes(q) ||
        (item.equipment_id || '').toLowerCase().includes(q) ||
        (item.asset_tag || '').toLowerCase().includes(q) ||
        (item.equipment_name || '').toLowerCase().includes(q) ||
        (item.location || '').toLowerCase().includes(q)
      )
    : trackData.slice(0, 8);

  if (!matches.length) {
    trackDropdown.innerHTML = '<div class="search-empty">No matching tickets or equipment references found yet.</div>';
    trackDropdown.classList.add('open');
    trackFocusIdx = -1;
    return;
  }

  trackDropdown.innerHTML = matches.slice(0, 8).map(item => {
    const title = item.report_id || item.equipment_id || item.asset_tag || 'Reference';
    const refs = [item.equipment_id, item.asset_tag].filter(Boolean).join(' · ');
    const detailParts = [item.equipment_name, refs, item.location].filter(Boolean);
    return `
      <div class="search-item" data-value="${escapeHtml(item.report_id || item.equipment_id || item.asset_tag || '')}">
        <span class="search-icon"><i aria-hidden="true" class="fas fa-search"></i></span>
        <span class="search-copy">
          <span class="search-title">${escapeHtml(title)}</span>
          <span class="search-meta">${escapeHtml(detailParts.join(' • '))}</span>
          <span class="search-meta">${escapeHtml(badgeLabel(item.status || ''))}</span>
        </span>
      </div>
    `;
  }).join('');

  trackDropdown.classList.add('open');
  trackFocusIdx = -1;

  trackDropdown.querySelectorAll('.search-item').forEach(el => {
    el.addEventListener('mousedown', event => {
      event.preventDefault();
      trackInput.value = el.dataset.value || '';
      trackDropdown.classList.remove('open');
      trackInput.form?.requestSubmit();
    });
  });
}

trackInput.addEventListener('input', () => {
  clearTimeout(trackRenderTimer);
  trackRenderTimer = setTimeout(() => {
    renderTrackDropdown(trackInput.value);
  }, 120);
});

trackInput.addEventListener('focus', () => {
  renderTrackDropdown(trackInput.value);
});

trackInput.addEventListener('blur', () => {
  setTimeout(() => trackDropdown.classList.remove('open'), 150);
});

trackInput.addEventListener('keydown', event => {
  const items = trackDropdown.querySelectorAll('.search-item');
  if (!items.length) {
    if (event.key === 'Escape') {
      trackDropdown.classList.remove('open');
    }
    return;
  }

  if (event.key === 'ArrowDown') {
    event.preventDefault();
    trackFocusIdx = Math.min(trackFocusIdx + 1, items.length - 1);
  } else if (event.key === 'ArrowUp') {
    event.preventDefault();
    trackFocusIdx = Math.max(trackFocusIdx - 1, 0);
  } else if (event.key === 'Enter' && trackFocusIdx >= 0) {
    event.preventDefault();
    items[trackFocusIdx].dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
    return;
  } else if (event.key === 'Escape') {
    trackDropdown.classList.remove('open');
    return;
  } else {
    return;
  }

  items.forEach((el, index) => el.classList.toggle('focused', index === trackFocusIdx));
  items[trackFocusIdx]?.scrollIntoView({ block: 'nearest' });
});
</script>
<?php require __DIR__ . '/includes/csrf_inject.php'; ?>
<?php require __DIR__ . '/includes/becca_tokens.php'; ?>
<?php require __DIR__ . '/includes/becca_widget.php'; ?>
</body>
</html>
