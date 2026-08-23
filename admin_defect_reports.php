<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mail_helper.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/file_storage_helpers.php';
require_once __DIR__ . '/includes/sla_helper.php';
require_once __DIR__ . '/includes/report_media.php';   // photoListFromRow / videoListFromRow

requireRole('admin');
@runSlaEscalationSweep(); // auto-escalate overdue reports (idempotent)

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['fullname'] ?? 'Administrator';

function adminWorkflowNotifyRole($conn, string $role, string $message, string $reportId): void {
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE role = ? AND status = 'active'");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('s', $role);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        $userId = trim((string)($row['user_id'] ?? ''));
        if ($userId !== '') {
            addNotification($userId, $message, 'workflow_update', $reportId);
        }
    }
}


/* ─── POST ACTIONS ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['action'] ?? '';
    $reportId = trim((string)($_POST['report_id'] ?? ''));
    $conn = getDBConnection();
    $report = $reportId !== '' ? getDefectReportById($reportId) : null;

    if ($reportId === '' || !$report) {
        $_SESSION['flash'] = ['err', 'Report not found.'];
        header('Location: admin_defect_reports.php');
        exit();
    }

    if ($act !== '') {
        logActivity($admin_id, 'report.' . $act, 'Report ' . $reportId . ' — action ' . $act . ' by ' . $admin_name);
    }

    if ($act === 'mark_received') {
        updateDefectReport($reportId, [
            'status'             => 'pmo_review',
            'pmo_review_status'  => 'received',
            'received_by_pmo_at' => date('Y-m-d H:i:s'),
            'received_by_pmo_id' => $admin_id,
        ]);
        $fresh = getDefectReportById($reportId) ?: array_merge($report, ['status' => 'pmo_review']);
        notifyReporter(
            $fresh,
            'Your report ' . $reportId . ' has been officially received by the PMO and is now under evaluation.',
            'Your Report Has Been Received',
            "We would like to inform you that the Property Management Office has officially received your defective equipment report.\n\n"
            . "Your report has entered the evaluation stage and will now be reviewed by our maintenance personnel.\n\n"
            . "Current Status: Received by PMO\n\n"
            . "No further action is required from you at this time. You may monitor the progress of your maintenance request anytime on the Track Report page.\n\n"
            . "Thank you for helping us maintain the facilities of Batangas Eastern Colleges."
        );
        $_SESSION['flash'] = ['ok', 'Report marked as received. The reporter has been notified.'];
    }
    elseif ($act === 'approve') {
        $dept = trim((string)($_POST['department_assigned'] ?? ''));
        if (!in_array($dept, ['ITSO','PMO'], true)) {
            $src = $report;
            $dept = classifyDepartmentByEquipment(
                $src['equipment_id'] ?? null,
                $src['equipment_name'] ?? '',
                $src['category_name'] ?? '',
                $src['location'] ?? '',
                $src['issue_description'] ?? ''
            );
        }

        $priority = $_POST['priority_level'] ?? ($_POST['priority'] ?? 'medium');
        $adminNotes = $_POST['admin_notes'] ?? '';

        updateDefectReport($reportId, [
            'admin_approval_status' => 'approved',
            'status'                => 'assigned',
            'department_assigned'   => $dept,
            'priority'              => $priority,
            'admin_notes'           => $adminNotes,
            'categorized_by'        => $admin_id,
            'categorized_date'      => date('Y-m-d H:i:s'),
        ]);

        $fresh = getDefectReportById($reportId) ?: array_merge($report, ['status' => 'assigned']);
        notifyReporter(
            $fresh,
            'Your report ' . $reportId . ' has been approved. A technician will be assigned shortly.',
            'Report Approved',
            'Your report has been approved by the Property Management Office. A technician will be assigned to handle the repair shortly.'
        );

        $_SESSION['flash'] = ['ok', 'Report approved and categorised.'];
    }
    elseif ($act === 'reject') {
        $rejReason = trim((string)($_POST['rejection_reason'] ?? ''));
        updateDefectReport($reportId, [
            'admin_approval_status' => 'rejected',
            'status'                => 'rejected',
            'admin_notes'           => $rejReason,
        ]);
        $fresh = getDefectReportById($reportId) ?: array_merge($report, ['status' => 'rejected']);
        notifyReporter(
            $fresh,
            'Your report ' . $reportId . ' was reviewed and could not be approved.',
            'Report Not Approved',
            'After review, your report could not be approved at this time.' . ($rejReason !== '' ? "\n\nReason: " . $rejReason : '')
        );
        $_SESSION['flash'] = ['err', 'Report rejected.'];
    }
    elseif ($act === 'verify_completion') {
        // "Verify & Close" — the PMO confirms the repair and closes the report in one step
        // (the button and the reporter notice both say the report is resolved/closed).
        updateDefectReport($reportId, [
            'status'                        => 'closed',
            'completion_verified_by_admin'  => $admin_id,
            'admin_notes'                   => $_POST['verification_notes'] ?? '',
        ]);

        $cur = getDefectReportById($reportId);
        $vDept = $cur['department_assigned'] ?? 'PMO';
        $vPrio = $cur['priority'] ?? 'medium';
        $vNotes = $_POST['verification_notes'] ?? '';
        notifyReporter(
            $cur ?: $report,
            'Your report ' . $reportId . ' has been verified and resolved. Thank you!',
            'Repair Verified & Resolved',
            'The repair on your reported equipment has been verified by the Property Management Office and your report is now resolved. Thank you for helping keep BEC facilities in good condition.'
        );
        $_SESSION['flash'] = ['ok', 'Completion verified and report closed.'];
    }
    elseif ($act === 'return_to_progress') {
        updateDefectReport($reportId, ['status' => 'in_progress']);
        $_SESSION['flash'] = ['ok', 'Report returned to In Progress.'];
    }
    elseif ($act === 'delete') {
        deleteDefectReport($reportId);
        $_SESSION['flash'] = ['ok', 'Report deleted.'];
    }

    header('Location: admin_defect_reports.php' .
           (isset($_POST['view_after']) ? '?view='.$_POST['view_after'] : ''));
    exit();
}

/* ─── FILTERS ──────────────────────────────────────────── */
$sf = $_GET['status']   ?? 'all';
$pf = $_GET['priority'] ?? 'all';
$adminUnit  = adminUnitForUser($admin_id);   // 'PMO' | 'ITSO' | '' (no unit → sees all)
$dfExplicit = array_key_exists('dept', $_GET); // did the admin pick a unit, or is this their default?
// Default the department filter to the admin's own unit; they can still switch to All/other.
$df = $_GET['dept'] ?? ($adminUnit !== '' ? $adminUnit : 'all');
// Unit scope: a chosen unit is strict; the admin's DEFAULT unit view also surfaces reports not
// yet triaged (no department_assigned) so pending items are never hidden from both admins.
$unitFilter = function ($r) use ($df, $dfExplicit) {
    if ($df === 'all') return true;
    $d = (string)($r['department_assigned'] ?? '');
    if ($d === $df) return true;
    if (!$dfExplicit && $d === '') return true;
    return false;
};
$sq = $_GET['search']   ?? '';
$vw = $_GET['view']     ?? 'table'; // 'table' | 'kanban'
// Where the ticket came from. Preventive ones are raised by a schedule on
// admin_preventive.php rather than reported by a person, and until this filter
// existed there was no way to see them as a group — is_preventive was written
// on every generated row and read by nothing.
$kf = strtolower(trim((string)($_GET['kind'] ?? 'all')));
if (!in_array($kf, ['all', 'preventive', 'reported'], true)) { $kf = 'all'; }
$kindFilter = function ($r) use ($kf) {
    if ($kf === 'all') return true;
    $isPm = !empty($r['is_preventive']) && $r['is_preventive'] !== 'f';
    return $kf === 'preventive' ? $isPm : !$isPm;
};

// Workflow stages — each stat card / status filter covers a group of raw statuses, so the
// counts and the filtered list stay consistent and always add up to the total.
$stages = [
    'pending'     => ['reported','pmo_review'],
    'received'    => ['ready_for_assignment','assigned'],
    'in_progress' => ['accepted','in_progress','waiting_for_materials','for_replacement'],
    'completed'   => ['completed','verified','closed'],
    'rejected'    => ['rejected'],
];

/* The same four filters the closures above describe, handed to the database.
   They used to run as array_filter() passes over every report ever filed, which
   is the reason this page could not use a SQL LIMIT: the limit would have applied
   before them and returned short pages. As SQL they compose with the limit, so the
   list asks for one page and the cards ask for counts. See becDefectFilterClauses()
   in config/database.php. $unitFilter/$kindFilter stay defined above — the kanban
   view still uses them. */
$listOpts = ['exclude_statuses' => ['deleted']];
if ($sf !== 'all') { $listOpts['statuses'] = $stages[$sf] ?? [$sf]; }
if ($df !== 'all') { $listOpts['dept'] = $df; $listOpts['dept_untriaged'] = !$dfExplicit; }
if ($kf !== 'all') { $listOpts['kind'] = $kf; }

/* The cards count the same unit and kind scope but ignore the status stage,
   priority and search — so every stage keeps showing its own total while one of
   them is selected, which is what $all_raw did by fetching with no filters. */
$cardOpts = ['exclude_statuses' => ['deleted']];
if ($df !== 'all') { $cardOpts['dept'] = $df; $cardOpts['dept_untriaged'] = !$dfExplicit; }
if ($kf !== 'all') { $cardOpts['kind'] = $kf; }

// The pager's total, and the empty-state test, without fetching a single row.
$totalReports = countDefectReportsWithFilters('all', $pf, $sq, $listOpts);

/* ─── EXPORT ────────────────────────────────────────────
   Built here, from the records, while $reports still holds everything the
   active filters select. The browser used to assemble this by reading the
   rendered table, which meant the status arrived as badge text and the CSV
   had no byte-order mark, so every em dash and "ñ" opened mangled in Excel.
   The status stages and the PMO/ITSO unit scope are non-trivial and defined
   just above, which is why the export lives here and not in
   api/export_reports.php — that endpoint still serves the Advanced Export
   dialog, where the filters are chosen independently of this page. */
$exportFmt = strtolower(trim((string)($_GET['export'] ?? '')));
if (in_array($exportFmt, ['csv', 'xlsx', 'pdf'], true)) {
    // An export is the one request that legitimately wants the whole filtered set,
    // so it is the one request that pays for it. The page render below never does.
    $reports = getDefectReportsWithFilters('all', $pf, $sq, $listOpts);
    $dHeaders = ['Ticket', 'Equipment', 'Asset Tag', 'Location', 'Issue', 'Priority', 'Status',
                 'Unit', 'Reporter', 'Technician', 'Reported', 'Completed'];
    $flat = static fn($v) => trim(preg_replace('/\s+/u', ' ', (string)$v));
    $dash = static fn($v) => trim((string)$v) !== '' ? trim((string)$v) : '—';
    $day  = static fn($v) => !empty($v) ? date('Y-m-d', strtotime((string)$v)) : '—';
    $dRows = [];
    foreach ($reports as $r1) {
        $dRows[] = [
            (string)($r1['report_id'] ?? ''),
            $dash($r1['equipment_name'] ?? ''),
            $dash($r1['asset_tag'] ?? ''),
            $dash($r1['location'] ?? ''),
            $flat($r1['issue_description'] ?? ''),
            ucfirst((string)($r1['priority'] ?? '')),
            ucwords(str_replace('_', ' ', (string)($r1['status'] ?? ''))),
            $dash($r1['department_assigned'] ?? ''),
            $dash($r1['reporter_name'] ?? ($r1['reported_by'] ?? '')),
            $dash($r1['technician_name'] ?? ''),
            $day($r1['report_date'] ?? ''),
            $day($r1['completion_date'] ?? ''),
        ];
    }

    $stageCount = static function (array $statuses) use ($reports): int {
        return count(array_filter($reports, static fn($x) => in_array((string)($x['status'] ?? ''), $statuses, true)));
    };
    $dSummary = [
        'Total Reports' => number_format(count($dRows)),
        'Pending'       => number_format($stageCount($stages['pending'])),
        'Received'      => number_format($stageCount($stages['received'])),
        'In Progress'   => number_format($stageCount($stages['in_progress'])),
        'Completed'     => number_format($stageCount($stages['completed'])),
        'Rejected'      => number_format($stageCount($stages['rejected'])),
    ];
    $dMeta = array_filter([
        'Status Filter'   => $sf !== 'all' ? ucwords(str_replace('_', ' ', $sf)) : '',
        'Priority Filter' => $pf !== 'all' ? ucfirst($pf) : '',
        'Unit Filter'     => $df !== 'all' ? $df : '',
        'Origin Filter'   => $kf !== 'all' ? ($kf === 'preventive' ? 'Preventive (scheduled)' : 'Reported by a person') : '',
        'Search'          => $sq !== '' ? $sq : '',
    ]);

    if ($exportFmt === 'csv') {
        require_once __DIR__ . '/includes/csv_export.php';
        $out = becCsvOpen('defect_reports');
        becCsvLetterhead($out, 'Defect Reports', $dMeta + ['Total Records' => number_format(count($dRows))]);
        becCsvSection($out, 'Executive Summary', ['Metric', 'Value'],
            array_map(static fn($k, $v) => [$k, $v], array_keys($dSummary), array_values($dSummary)));
        becCsvRow($out, ['DEFECT REPORT RECORDS']);
        becCsvRow($out, $dHeaders);
        foreach ($dRows as $row) { becCsvRow($out, $row); }
        becCsvBlank($out);
        becCsvFooter($out, 'End of Defect Reports');
        fclose($out);
        exit;
    }

    if ($exportFmt === 'xlsx') {
        require_once __DIR__ . '/includes/xlsx_writer.php';
        becRenderBrandedXlsx('Defect Reports', $dHeaders, $dRows, $dSummary, $dMeta, 'defect_reports');
        exit;
    }

    require_once __DIR__ . '/includes/export_branding.php';
    $deh = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1.0">'
       . '<title>Defect Reports — BEC PMO</title>'
       . '<link rel="icon" type="image/png" href="assets/logs.png">'
       . '<style>' . becExportCss() . '@page{size:A4 landscape;margin:12mm 10mm;}</style></head><body>';
    echo becExportToolbar();
    echo becExportHeader('Defect Reports', $dMeta + ['Total Records' => number_format(count($dRows))]);
    echo becExportSummaryCards($dSummary);
    echo '<div class="sec-label">Defect Report Records</div>';
    echo '<table class="data-table"><thead><tr>';
    foreach ($dHeaders as $hd) { echo '<th>' . $deh($hd) . '</th>'; }
    echo '</tr></thead><tbody>';
    if (!$dRows) {
        echo '<tr><td colspan="' . count($dHeaders) . '" class="empty">No reports match the selected filters.</td></tr>';
    } else {
        foreach ($dRows as $row) {
            echo '<tr>';
            foreach ($row as $cell) { echo '<td>' . $deh($cell) . '</td>'; }
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo becExportSignatures();
    echo becExportFooter();
    echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},400);});</script>';
    echo '</body></html>';
    exit;
}

/* ─── PAGINATION ───────────────────────────────────────────
   This page used to render every matching row and then let JavaScript hide all
   but ten of them. The rows a person never sees still crossed the wire: about
   11 MB of markup at 5,000 reports, which is the page-weight ceiling CLAUDE.md
   calls the one architectural change still owed. The slice happens here, so the
   markup is O(page) instead of O(backlog).

   Placed deliberately AFTER the export block above: export is the one request
   that genuinely wants every filtered row, so it fetches them itself and this
   never runs for it.

   The fetch is now a page, not a slice. The four filters this page used to apply
   in PHP — the status stage, the PMO/ITSO unit scope, preventive-vs-reported and
   dropping deleted rows — moved into SQL (becDefectFilterClauses), which is what
   a LIMIT needed in order to be correct: applied before them it would have
   returned short pages. So what this page reads no longer grows with the backlog
   behind it, and the pager's total comes from a COUNT rather than from measuring
   a fetched array. */
const BEC_REPORTS_PER_PAGE = 25;

$totalPages   = max(1, (int)ceil($totalReports / BEC_REPORTS_PER_PAGE));
$pageNum      = (int)($_GET['page'] ?? 1);
if ($pageNum < 1)           { $pageNum = 1; }
if ($pageNum > $totalPages) { $pageNum = $totalPages; }   // deep-link past the end lands on the last page
$pageOffset   = ($pageNum - 1) * BEC_REPORTS_PER_PAGE;

// One page of rows, selected by the database. This is the whole point of the
// change: what the page fetches no longer grows with the backlog behind it.
$reportsPage  = getDefectReportsWithFilters('all', $pf, $sq,
    $listOpts + ['limit' => BEC_REPORTS_PER_PAGE, 'offset' => $pageOffset]);

/**
 * The pager's links have to carry the filters, or paging resets them to defaults.
 * Everything except `page` is preserved as-is.
 */
function repPageUrl(int $p): string {
    $q = $_GET;
    $q['page'] = $p;
    unset($q['view_id'], $q['export']);   // never page INTO a modal or re-trigger a download
    return '?' . http_build_query($q);
}

/* Photo resolution is per-report work, so it runs on the page being rendered
   rather than on the whole backlog — it was previously paid for all 5,000. */
foreach ($reportsPage as &$r0) {
    $pl = photoListFromRow($r0);
    $r0['photo_urls'] = $pl;
    $r0['photo_url']  = $pl[0] ?? '';
}
unset($r0);

/* ─── VIEW SINGLE ──────────────────────────────────────── */
$vr = null;
if (isset($_GET['view_id'])) {
    $vr = getDefectReportById($_GET['view_id']);
    if ($vr) {
        $eq = getEquipmentById($vr['equipment_id'] ?? '');
        $vr['equipment_name']  = $eq['equipment_name']  ?? '—';
        $vr['asset_tag']       = $eq['asset_tag']       ?? '—';
        $vr['location']        = $eq['location']        ?? '—';
        $vr['reporter_name']   = $vr['reporter_name']   ?? 'N/A';
        $vr['technician_name'] = !empty($vr['technician_name']) ? $vr['technician_name'] : 'Unassigned';
        // Both, not just video. Every row in the LIST gets photo_urls from
        // photoListFromRow() in the loop below, but the single record never did,
        // so the one screen built to show the evidence was the one screen that
        // could not: the template fell back to the raw photo_url column, which
        // holds an unresolved path, and rendered nothing.
        $vr['photo_urls']      = photoListFromRow($vr);
        $vr['video_urls']      = videoListFromRow($vr);
    }
}


/* ─── COUNTS ───────────────────────────────────────────── */
/* One grouped count instead of fetching every report and bucketing the array six
   times over. Same numbers; the query returns one row per status/priority pair
   whether there are eighty reports behind it or eighty thousand. */
$__byStatus = []; $c_crit = 0; $c_all = 0;
foreach (defectReportStatusCounts('all', '', $cardOpts) as $__row) {
    $__n = (int)($__row['n'] ?? 0);
    $__s = (string)($__row['status'] ?? '');
    $__byStatus[$__s] = ($__byStatus[$__s] ?? 0) + $__n;
    if ((string)($__row['priority'] ?? '') === 'critical') { $c_crit += $__n; }
    $c_all += $__n;
}
$stageTotal = static function (array $statuses) use ($__byStatus): int {
    $t = 0;
    foreach ($statuses as $st) { $t += ($__byStatus[$st] ?? 0); }
    return $t;
};
$c_pend = $stageTotal($stages['pending']);
$c_app  = $stageTotal($stages['received']);
$c_prog = $stageTotal($stages['in_progress']);
$c_done = $stageTotal($stages['completed']);
$c_rej  = $stageTotal($stages['rejected']);

/* ─── KANBAN COLUMNS ───────────────────────────────────── */
$cols = [
    'reported'    => ['label'=>'Pending Verification', 'icon'=>'hourglass-half',  'color'=>'#D97706', 'bg'=>'#FFFBEB', 'bdr'=>'#FDE68A'],
    'ready_for_assignment' => ['label'=>'Ready to Assign', 'icon'=>'user-plus', 'color'=>'#2563EB', 'bg'=>'#EFF6FF', 'bdr'=>'#BFDBFE'],
    'assigned'    => ['label'=>'Approved',             'icon'=>'check-double',    'color'=>'#2563EB', 'bg'=>'#EFF6FF', 'bdr'=>'#BFDBFE'],
    'in_progress' => ['label'=>'In Progress',          'icon'=>'wrench',          'color'=>'#7C3AED', 'bg'=>'#F5F3FF', 'bdr'=>'#DDD6FE'],
    'completed'   => ['label'=>'Completed',            'icon'=>'check-circle',    'color'=>'#16A34A', 'bg'=>'#F0FDF4', 'bdr'=>'#BBF7D0'],
    'rejected'    => ['label'=>'Rejected',             'icon'=>'times-circle',    'color'=>'#DC2626', 'bg'=>'#FFF1F2', 'bdr'=>'#FECDD3'],
];
// Only the active view is rendered (switchView() reloads the page), so in table view none of
// this is ever read. Bucketing every report six times over is not free at a real backlog.
$kanban = [];
if ($vw === 'kanban') {
    // The board shows every card at once, so this view is the one that still wants
    // the whole scope. It asks for it here rather than the table view paying for it.
    $kanbanRows = getDefectReportsWithFilters('all', 'all', '', $cardOpts);
    foreach ($cols as $status => $_) { $kanban[$status] = []; }
    // completed bucket also includes verified/closed
    foreach ($kanbanRows as $r) {
        $s = $r['status'] ?? '';
        if (in_array($s, ['verified','closed'], true)) { $s = 'completed'; }
        if (isset($kanban[$s])) { $kanban[$s][] = $r; }
    }
}

/* ─── HELPERS ──────────────────────────────────────────── */
function stCls($s){return['reported'=>'pend','pmo_review'=>'pend','ready_for_assignment'=>'prog','assigned'=>'prog','in_progress'=>'prog2','completed'=>'done','verified'=>'done','closed'=>'done','rejected'=>'rej'][$s]??'pend';}
function stLbl($s){return['reported'=>'Pending','pmo_review'=>'Received by PMO','ready_for_assignment'=>'Ready to Assign','assigned'=>'Assigned','in_progress'=>'In Progress','completed'=>'Completed','verified'=>'Verified','closed'=>'Closed','rejected'=>'Rejected'][$s]??ucfirst(str_replace('_',' ',$s));}
function prCls($p){return['critical'=>'crit','high'=>'hi','medium'=>'med','low'=>'lo'][$p]??'lo';}
function prLbl($p){return ucfirst($p??'—');}
function esc($s){return htmlspecialchars((string)($s ?? '—'), ENT_QUOTES, 'UTF-8');}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Defect Reports — BEC Admin</title>
<link rel="icon" type="image/png" href="assets/logs.png" />
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="css/typography.css">
<!-- SheetJS for Excel -->
<!-- SheetJS removed: the Excel export is built server-side by
     includes/xlsx_writer.php, so this 861 KB bundle no longer loads. -->
<link rel="stylesheet" href="assets/css/admin-shell.css">
<style>

/* ═══════════════════════════════════════════════════════
   BEC ADMIN — Defect Reports  |  Maroon × Gold × Warm
   Fonts: Outfit (headings) · DM Sans (body)
═══════════════════════════════════════════════════════ */
:root{
  --m1:#2D0505;--m2:#4A0E0E;--m3:#7B1D1D;--m4:#9B2C2C;--m5:#C53030;
  --g1:#92600A;--g2:#D4A017;--g3:#F0C040;--gp:#FEF9E7;
  --bg:#F4EFE6;--s1:#FFFFFF;--s2:#FAF7F0;--s3:#F2EAD9;
  --bdr:#E5D9C6;--bdr2:#D0C0A8;
  --t1:#1A0808;--t2:#5C3838;--t3:#9C7A7A;--t4:#C8ABAB;
  --sh0:0 1px 2px rgba(45,5,5,.05);
  --sh1:0 2px 8px rgba(45,5,5,.07),0 1px 3px rgba(45,5,5,.04);
  --sh2:0 6px 20px rgba(45,5,5,.09),0 2px 6px rgba(45,5,5,.05);
  --sh3:0 14px 40px rgba(45,5,5,.13),0 4px 10px rgba(45,5,5,.07);
  --sh3d:0 6px 0 rgba(45,5,5,.16),0 12px 32px rgba(45,5,5,.11);
  --sh3dh:0 11px 0 rgba(45,5,5,.2),0 18px 48px rgba(45,5,5,.15);
  --r1:8px;--r2:12px;--r3:18px;--r4:26px;--sb:262px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{
  font-family:'DM Sans',sans-serif;background:var(--bg);
  color:var(--t1);min-height:100vh;overflow-x:hidden;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='400'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.022'/%3E%3C/svg%3E");
}
/* ── SIDEBAR ────────────────────────────────────────── */
/* sidebar styling lives in assets/css/admin-shell.css */
.lout i{transition:transform .3s;}.lout:hover i{transform:rotate(180deg);}

/* ── MAIN + TOPBAR ──────────────────────────────────── */
.wrap{margin-left:var(--sb);min-height:100vh;display:flex;flex-direction:column;}
.topbar{background:rgba(255,252,245,.93);backdrop-filter:blur(14px);
  border-bottom:1px solid var(--bdr);height:58px;padding:0 1.75rem;
  display:flex;align-items:center;justify-content:space-between;
  position:sticky;top:0;z-index:200;box-shadow:var(--sh0);}
.tb-l{display:flex;align-items:center;gap:.55rem;}
.mob-tog{display:none;background:none;border:none;font-size:1.1rem;cursor:pointer;color:var(--t2);}
.pg-title{font-family:'Outfit',sans-serif;font-weight:700;font-size:1rem;color:var(--t1);}
.bc{font-size:.68rem;color:var(--t3);display:flex;align-items:center;gap:.25rem;}
.bc a{color:var(--t3);text-decoration:none;}.bc a:hover{color:var(--m3);}
.bc i{font-size:.55rem;}
.tb-r{display:flex;align-items:center;gap:.55rem;}
.unit-badge{display:inline-flex;align-items:center;gap:.35rem;vertical-align:middle;margin-left:.55rem;padding:.22rem .6rem;border-radius:999px;background:linear-gradient(135deg,var(--m3,#7a1220),#a01a2b);color:#fff;font-family:'DM Sans',sans-serif;font-size:.62rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;box-shadow:0 2px 8px rgba(122,18,32,.25);}
.unit-badge i{font-size:.6rem;}
.ic-btn{width:34px;height:34px;background:var(--s2);border:1px solid var(--bdr);
  border-radius:var(--r1);display:flex;align-items:center;justify-content:center;
  cursor:pointer;color:var(--t2);font-size:.85rem;transition:all .17s;
  text-decoration:none;position:relative;box-shadow:none;}
.ic-btn:hover{background:var(--m3);color:#fff;transform:none;box-shadow:none;}
.pip{position:absolute;top:5px;right:5px;width:7px;height:7px;
  background:var(--g2);border-radius:50%;border:2px solid var(--s1);
  animation:pp 2.2s ease-in-out infinite;}
@keyframes pp{0%,100%{transform:scale(1);}50%{transform:scale(1.4);}}

/* ── PAGE CONTENT ───────────────────────────────────── */
.pg{padding:1.5rem 1.75rem;flex:1;}

/* ── FLASH ──────────────────────────────────────────── */
.flash{display:flex;align-items:center;gap:.65rem;padding:.7rem 1.1rem;
  border-radius:var(--r2);margin-bottom:1.125rem;font-size:.81rem;font-weight:600;
  animation:fIn .25s ease;border-left:3px solid;}
@keyframes fIn{from{opacity:0;transform:translateY(-5px);}to{opacity:1;transform:translateY(0);}}
.flash.ok{background:#F0FDF4;color:#15803D;border-color:#22C55E;}
.flash.err{background:#FFF1F2;color:#DC2626;border-color:#EF4444;}

/* ── PAGE HEADER ────────────────────────────────────── */
.ph{display:flex;align-items:flex-end;justify-content:space-between;
  margin-bottom:1.25rem;gap:1rem;flex-wrap:wrap;}
.ph h1{font-family:'Outfit',sans-serif;font-size:1.45rem;font-weight:800;
  color:var(--t1);display:flex;align-items:center;gap:.45rem;}
.ph h1 i{color:var(--m3);font-size:1.15rem;}
/* .76rem: one page-subtitle size across the admin pages, matching .head p in
   admin-shell.css and .ph-sub everywhere else. */
.ph-sub{font-size:.76rem;color:var(--t3);margin-top:.18rem;}
.ph-acts{display:flex;gap:.45rem;flex-wrap:wrap;}

/* ── BTN SYSTEM ─────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:.32rem;
  padding:.4rem .875rem;border-radius:var(--r1);
  font-family:'DM Sans',sans-serif;font-size:.77rem;font-weight:700;
  cursor:pointer;border:none;transition:all .17s;text-decoration:none;white-space:nowrap;}
.btn:hover{transform:none;}.btn:active{transform:translateY(0);}
.btn-maroon{background:linear-gradient(135deg,var(--m3),var(--m4));color:#fff;box-shadow:none;}
.btn-maroon:hover{box-shadow:none;}
.btn-gold{background:linear-gradient(135deg,var(--g2),var(--g3));color:var(--m1);box-shadow:none;}
.btn-gold:hover{box-shadow:none;}
.btn-green{background:linear-gradient(135deg,#15803D,#22C55E);color:#fff;box-shadow:none;}
.btn-green:hover{box-shadow:none;}
.btn-red{background:linear-gradient(135deg,#B91C1C,#EF4444);color:#fff;box-shadow:none;}
.btn-red:hover{box-shadow:none;}
.btn-ghost{background:var(--s2);color:var(--t2);border:1px solid var(--bdr);}
.btn-ghost:hover{background:var(--s3);}
.btn-outline{background:var(--s1);color:var(--m3);border:1.5px solid var(--m3);}
.btn-outline:hover{background:var(--m3);color:#fff;}
.btn-sm{padding:.3rem .62rem;font-size:.71rem;}
.btn-xs{padding:.22rem .5rem;font-size:.66rem;}
.bico{width:26px;height:26px;padding:0;display:flex;align-items:center;justify-content:center;border-radius:var(--r1);font-size:.7rem;}
.bi-v{background:#EFF6FF;color:#1D4ED8;}.bi-v:hover{background:#DBEAFE;}
.bi-a{background:#F0FDF4;color:#15803D;}.bi-a:hover{background:#DCFCE7;}
.bi-d{background:#FFF1F2;color:#BE123C;}.bi-d:hover{background:#FFE4E6;}

/* ── SUMMARY CARDS ──────────────────────────────────── */
.sums{display:grid;grid-template-columns:repeat(7,1fr);gap:.625rem;margin-bottom:1.2rem;}
.scard{background:var(--s1);border-radius:var(--r3);padding:.875rem 1rem;
  border:1px solid var(--bdr);position:relative;overflow:hidden;
  transition:all .24s cubic-bezier(.4,0,.2,1);
  box-shadow:var(--sh0);cursor:pointer;text-decoration:none;display:block;}
.scard::before{content:'';position:absolute;top:-16px;right:-16px;
  width:65px;height:65px;border-radius:50%;background:var(--sk);
  opacity:.04;transition:all .26s;}
.scard::after{content:'';position:absolute;bottom:0;left:0;width:100%;
  height:2.5px;background:var(--sk);border-radius:0 0 var(--r3) var(--r3);
  transform:scaleX(0);transform-origin:left;transition:transform .3s;}
.scard:hover{transform:none;
  box-shadow:var(--sh3);border-color:transparent;}
.scard:hover::before{transform:none;opacity:.08;}
.scard:hover::after{transform:scaleX(1);}
.sc-a{--sk:var(--m3);--skl:rgba(123,29,29,.14);}
.sc-p{--sk:#D97706;--skl:rgba(217,119,6,.14);}
.sc-q{--sk:#2563EB;--skl:rgba(37,99,235,.14);}
.sc-r{--sk:#7C3AED;--skl:rgba(124,58,237,.14);}
.sc-s{--sk:#16A34A;--skl:rgba(22,163,74,.14);}
.sc-t{--sk:#DC2626;--skl:rgba(220,38,38,.14);}
.sc-u{--sk:#C2410C;--skl:rgba(194,65,12,.14);}
.sico{width:34px;height:34px;border-radius:var(--r1);display:flex;align-items:center;
  justify-content:center;font-size:.8rem;margin-bottom:.5rem;
  background:var(--sb-ic);color:var(--sc-ic);
  box-shadow:none;transition:all .24s;position:relative;z-index:1;}
.scard:hover .sico{transform:none;}
.sc-a .sico{--sb-ic:#FDECEA;--sc-ic:var(--m3);}
.sc-p .sico{--sb-ic:#FFFBEB;--sc-ic:#D97706;}
.sc-q .sico{--sb-ic:#EFF6FF;--sc-ic:#2563EB;}
.sc-r .sico{--sb-ic:#F5F3FF;--sc-ic:#7C3AED;}
.sc-s .sico{--sb-ic:#F0FDF4;--sc-ic:#16A34A;}
.sc-t .sico{--sb-ic:#FFF1F2;--sc-ic:#DC2626;}
.sc-u .sico{--sb-ic:#FFF7ED;--sc-ic:#C2410C;
  animation:critGlow 2.2s ease-in-out infinite;}
@keyframes critGlow{0%,100%{box-shadow:none;}
  50%{box-shadow:0 0 14px rgba(194,65,12,.4);}}
.snum{font-family:'Outfit',sans-serif;font-size:1.8rem;font-weight:800;
  color:var(--t1);line-height:1;margin-bottom:.12rem;
  position:relative;z-index:1;transition:color .24s;}
.scard:hover .snum{color:var(--sk);}
.slbl{font-size:.57rem;text-transform:uppercase;letter-spacing:.7px;
  color:var(--t3);font-weight:700;position:relative;z-index:1;line-height:1.3;}

/* ── VIEW TOGGLE ────────────────────────────────────── */
.view-toggle{display:flex;background:var(--s2);border:1.5px solid var(--bdr);
  border-radius:var(--r1);padding:2px;gap:2px;}
.vt-btn{display:flex;align-items:center;gap:.3rem;padding:.3rem .7rem;
  border-radius:6px;font-size:.73rem;font-weight:700;cursor:pointer;
  background:none;border:none;color:var(--t3);font-family:'DM Sans',sans-serif;
  transition:all .18s;}
.vt-btn.on{background:var(--s1);color:var(--m3);box-shadow:var(--sh0);}
.vt-btn:not(.on):hover{color:var(--t2);}

/* ── FILTER BAR ─────────────────────────────────────── */
.fbar{background:var(--s1);border:1px solid var(--bdr);border-radius:var(--r3);
  padding:.8rem 1.1rem;margin-bottom:1.1rem;
  display:flex;gap:.55rem;align-items:center;flex-wrap:wrap;box-shadow:var(--sh0);}
.fsw{position:relative;flex:1;min-width:165px;}
.fsw i{position:absolute;left:.65rem;top:50%;transform:translateY(-50%);
  color:var(--t3);font-size:.72rem;pointer-events:none;}
.fsi{width:100%;padding:.42rem .65rem .42rem 1.8rem;background:var(--s2);
  border:1.5px solid var(--bdr);border-radius:var(--r1);font-size:.79rem;
  color:var(--t1);font-family:'DM Sans',sans-serif;outline:none;transition:border-color .18s;}
.fsi:focus{border-color:var(--m3);box-shadow:0 0 0 3px rgba(123,29,29,.07);}
.fsel{padding:.42rem .65rem;background:var(--s2);border:1.5px solid var(--bdr);
  border-radius:var(--r1);font-size:.79rem;color:var(--t2);
  font-family:'DM Sans',sans-serif;outline:none;cursor:pointer;transition:border-color .18s;}
.fsel:focus{border-color:var(--m3);}
.fcount{font-size:.7rem;color:var(--t3);white-space:nowrap;margin-left:.2rem;}
/* Tickets a preventive schedule raised on its own, not a person */
.pm-tag{display:inline-flex;align-items:center;gap:.25rem;margin-left:.35rem;padding:.1rem .4rem;border-radius:5px;
  background:#E9F9EF;color:#166534;font-size:.55rem;font-weight:800;letter-spacing:.4px;vertical-align:middle;}
.pm-tag i{font-size:.55rem;}

/* ── PANEL / TABLE ──────────────────────────────────── */
.panel{background:#FFFFFF;border-radius:var(--r3);border:1px solid #E5D9C6;box-shadow:var(--sh1);overflow:hidden;transition:box-shadow .22s;}
.panel:hover{box-shadow:var(--sh2);}
.ph3{padding:.875rem 1.25rem;border-bottom:1px solid #E5D9C6;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(to right,#FAF7F0,#FFFFFF);}
/* Face, size and weight come from admin-shell.css; only the layout of this
   particular header (icon beside the text) is this page's business. */
.ph3 h3{color:var(--t1);display:flex;align-items:center;gap:.32rem;margin:0;}
.ph3 h3 i{color:var(--m3);}
.tbl{width:100%;border-collapse:collapse;}
.tbl thead th{padding:.52rem 1rem;font-size:.6rem;text-transform:uppercase;
  letter-spacing:.85px;color:var(--t3);font-weight:800;text-align:left;
  background:var(--s2);border-bottom:1.5px solid var(--bdr);white-space:nowrap;}
.tbl tbody td{padding:.68rem 1rem;font-size:.78rem;color:var(--t1);
  border-bottom:1px solid var(--bdr);vertical-align:middle;}
.tbl tbody tr:last-child td{border-bottom:none;}
.tbl tbody tr{transition:background .1s,transform .1s;}
.tbl tbody tr.rep-row{cursor:pointer;}
.tbl tbody tr:hover td{background:var(--s2);}
.tbl tbody tr:hover{transform:none;}
/* nowrap: the id is hyphenated, so on a 1366 or 1440 laptop - which is what
   the office actually uses - it breaks into "BEC-" / "2026-" / "000153" and
   takes three lines per row. It reads as one token or not at all. */
.rid{font-family:'Outfit',sans-serif;font-weight:800;color:var(--m3);font-size:.78rem;white-space:nowrap;}
.en{font-weight:700;}.esl{font-size:.64rem;color:var(--t3);}

/* ── BADGES ─────────────────────────────────────────── */
.bdg{display:inline-flex;align-items:center;gap:.22rem;padding:.2rem .58rem;
  border-radius:20px;font-size:.6rem;font-weight:800;
  text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;}
.bdg::before{content:'';width:4px;height:4px;border-radius:50%;
  background:currentColor;flex-shrink:0;animation:dot 2.2s ease-in-out infinite;}
@keyframes dot{0%,100%{opacity:1;}50%{opacity:.4;}}
.b-pend{background:#FEF9E7;color:#92600A;}
.b-prog{background:#EFF6FF;color:#1D4ED8;}
.b-prog2{background:#F5F3FF;color:#7C3AED;}
.b-done{background:#F0FDF4;color:#15803D;}
.b-rej{background:#FFF1F2;color:#BE123C;}
.b-crit{background:#FFF7ED;color:#C2410C;}
.b-hi{background:#FFFBEB;color:#B45309;}
.b-med{background:#EFF6FF;color:#1D4ED8;}
.b-lo{background:#F0FDF4;color:#15803D;}
.dept-itso{display:inline-flex;align-items:center;gap:.25rem;
  padding:.2rem .6rem;border-radius:20px;font-size:.62rem;font-weight:800;
  background:#ECFEFF;color:#0891B2;border:1px solid #A5F3FC;}
.dept-pmo{display:inline-flex;align-items:center;gap:.25rem;
  padding:.2rem .6rem;border-radius:20px;font-size:.62rem;font-weight:800;
  background:#F5F3FF;color:#7C3AED;border:1px solid #DDD6FE;}
.dept-none{display:inline-flex;align-items:center;gap:.22rem;
  padding:.2rem .58rem;border-radius:20px;font-size:.62rem;font-weight:700;
  background:var(--s2);color:var(--t3);border:1px solid var(--bdr);}

/* ─────────────────────────────────────────────────────
   KANBAN BOARD
───────────────────────────────────────────────────── */
.kanban{display:grid;grid-template-columns:repeat(5,1fr);gap:.875rem;align-items:start;}
.kol{background:var(--s2);border-radius:var(--r3);border:1px solid var(--bdr);
  overflow:hidden;transition:box-shadow .22s;}
.kol:hover{box-shadow:var(--sh2);}
.kol-h{padding:.75rem 1rem;display:flex;align-items:center;justify-content:space-between;
  border-bottom:2px solid var(--kbdr,var(--bdr));}
.kol-h-l{display:flex;align-items:center;gap:.5rem;}
.kol-ic{width:30px;height:30px;border-radius:var(--r1);
  display:flex;align-items:center;justify-content:center;font-size:.72rem;
  background:var(--kbg,var(--s3));color:var(--kc,var(--t2));flex-shrink:0;}
.kol-title{font-family:'Outfit',sans-serif;font-size:.8rem;font-weight:700;color:var(--t1);}
.kol-cnt{font-size:.62rem;font-weight:800;padding:1px 7px;border-radius:20px;
  background:var(--kbg,var(--s3));color:var(--kc,var(--t2));}
.kol-body{padding:.6rem .65rem;display:flex;flex-direction:column;gap:.5rem;
  min-height:120px;max-height:calc(100vh - 320px);overflow-y:auto;}
.kol-body::-webkit-scrollbar{width:3px;}
.kol-body::-webkit-scrollbar-thumb{background:var(--kbdr,var(--bdr));border-radius:3px;}

/* Kanban card */
.kcard{background:var(--s1);border-radius:var(--r2);
  border:1px solid var(--bdr);padding:.72rem .875rem;
  cursor:pointer;transition:all .22s cubic-bezier(.4,0,.2,1);
  box-shadow:var(--sh0);position:relative;overflow:hidden;}
.kcard::before{content:'';position:absolute;left:0;top:0;bottom:0;
  width:3px;background:var(--kbdr,var(--bdr));border-radius:3px 0 0 3px;}
.kcard:hover{transform:none;box-shadow:var(--sh2);border-color:var(--kbdr,var(--bdr));}
.kcard-id{font-family:'Outfit',sans-serif;font-size:.68rem;font-weight:800;
  color:var(--kc,var(--t3));margin-bottom:.28rem;}
.kcard-eq{font-size:.8rem;font-weight:700;color:var(--t1);line-height:1.3;
  margin-bottom:.3rem;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.kcard-desc{font-size:.7rem;color:var(--t2);line-height:1.45;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
  margin-bottom:.42rem;}
.kcard-meta{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.25rem;}
.kcard-date{font-size:.62rem;color:var(--t3);}
.kcard-photo{position:absolute;top:.55rem;right:.55rem;
  width:20px;height:20px;border-radius:4px;background:#EFF6FF;
  display:flex;align-items:center;justify-content:center;font-size:.6rem;color:#2563EB;}
.k-empty{text-align:center;padding:1.75rem .875rem;color:var(--t4);font-size:.73rem;}
.k-empty i{font-size:1.6rem;display:block;margin-bottom:.4rem;opacity:.3;}

/* ─────────────────────────────────────────────────────
   DETAIL MODAL
───────────────────────────────────────────────────── */
.mo{position:fixed;inset:0;background:rgba(26,8,8,.6);backdrop-filter:blur(7px);
  z-index:500;display:none;align-items:flex-start;justify-content:center;
  padding:1.5rem 1rem;overflow-y:auto;overscroll-behavior:contain;}
/* while any modal is open the page behind must not scroll */
body:has(.mo.open){overflow:hidden;}
.mo.open{display:flex;animation:moFade .18s ease;}
@keyframes moFade{from{opacity:0}to{opacity:1}}
.mw{background:var(--s1);border-radius:var(--r4);width:100%;max-width:840px;
  box-shadow:var(--sh3);animation:mUp .28s cubic-bezier(.4,0,.2,1);
  border:1px solid var(--bdr);margin:auto;}
@keyframes mUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.mw::-webkit-scrollbar{width:4px;}
.mw::-webkit-scrollbar-thumb{background:var(--bdr);border-radius:4px;}

.mhd{padding:1.35rem 1.55rem 1.15rem;
  background:linear-gradient(120deg,var(--m1) 0%,#3D0A0A 45%,var(--m3) 100%);
  border-radius:var(--r4) var(--r4) 0 0;
  display:flex;justify-content:space-between;align-items:flex-start;
  position:relative;overflow:hidden;}
.mhd::after{content:'';position:absolute;right:-15px;top:-15px;
  width:110px;height:110px;border-radius:50%;
  background:rgba(212,160,23,.08);pointer-events:none;
  animation:sealSpin 18s linear infinite;}
.mhd-t{position:relative;z-index:1;min-width:0;}
.mhd-eyebrow{font-family:'Outfit',sans-serif;font-size:.72rem;font-weight:700;
  color:var(--g3);display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;letter-spacing:.02em;}
.mhd-eyebrow .dot{opacity:.5;color:rgba(255,255,255,.4);}
.mhd-eyebrow .when{color:rgba(255,255,255,.55);font-weight:600;}
.mhd-t h2{font-family:'Outfit',sans-serif;font-size:1.32rem;font-weight:800;color:#fff;
  margin-top:.3rem;line-height:1.2;overflow-wrap:anywhere;}
.mhd-pills{display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.6rem;}
.mhd-pills .bdg{background:rgba(255,255,255,.14);color:#fff;backdrop-filter:blur(2px);}
.mhd-pills .bdg::before{background:currentColor;}
.mhd-pills .b-crit{color:#FED7AA;}
.mhd-pills .b-hi{color:#FDE68A;}
.mhd-pills .b-med{color:#BFDBFE;}
.mhd-pills .b-lo{color:#BBF7D0;}
.mhd-pills .b-pend{color:#FDE68A;}
.mhd-pills .b-prog{color:#BFDBFE;}
.mhd-pills .b-prog2{color:#DDD6FE;}
.mhd-pills .b-done{color:#BBF7D0;}
.mhd-pills .b-rej{color:#FECDD3;}
.mhd-t p{font-size:.7rem;color:rgba(255,255,255,.42);margin-top:.1rem;}
.mx{width:27px;height:27px;background:rgba(255,255,255,.1);border:none;border-radius:50%;
  color:rgba(255,255,255,.6);font-size:.82rem;cursor:pointer;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
  transition:all .18s;position:relative;z-index:1;}
.mx:hover{background:rgba(255,255,255,.22);color:#fff;transform:rotate(90deg);}

/* Two pane layout */
.m2col{display:grid;grid-template-columns:1fr 1fr;gap:0;max-height:70vh;overflow:hidden;}
.mleft{padding:1.35rem 1.55rem;overflow-y:auto;border-right:1px solid var(--bdr);overscroll-behavior:contain;}
.mright{padding:1.35rem 1.55rem;overflow-y:auto;background:var(--s2);overscroll-behavior:contain;}
.mleft::-webkit-scrollbar,.mright::-webkit-scrollbar{width:3px;}
.mleft::-webkit-scrollbar-thumb,.mright::-webkit-scrollbar-thumb{background:var(--bdr);border-radius:3px;}

.det-row{display:flex;gap:.75rem;padding:.44rem 0;border-bottom:1px solid var(--bdr);align-items:flex-start;}
.det-row:last-child{border:none;}
.det-k{width:110px;flex-shrink:0;font-size:.63rem;font-weight:800;
  text-transform:uppercase;letter-spacing:.6px;color:var(--t3);padding-top:.08rem;}
.det-v{font-size:.81rem;color:var(--t1);flex:1;line-height:1.5;}
.desc-box{background:var(--s2);border:1.5px solid var(--bdr);border-left:3px solid var(--m3);
  border-radius:var(--r1);padding:.7rem .85rem;font-size:.81rem;line-height:1.65;color:var(--t1);}
.notes-box{background:var(--gp);border:1px solid rgba(212,160,23,.25);
  border-radius:var(--r1);padding:.58rem .72rem;font-size:.78rem;line-height:1.55;color:var(--t2);}

/* Section labels (icon + uppercase caption) reused across the details modal */
.sec-lbl{font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.8px;
  color:var(--t3);margin-bottom:.5rem;display:flex;align-items:center;gap:.4rem;}
.sec-lbl i{color:var(--m3);font-size:.7rem;}

/* Scannable icon-tile fact grid (replaces the plain key/value list) */
/* auto-fit rather than a rigid 1fr 1fr. With two fixed columns a tile whose
   neighbour had already been claimed by a .wide row sat alone with an empty
   half-cell beside it, and "Bachelor of Science in Information Systems" wrapped
   to four lines while the space next to it stayed blank. Tiles now flow into
   the width that is actually there. */
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));
  gap:.6rem;margin-bottom:1.15rem;align-items:stretch;}
.info-tile{display:flex;gap:.6rem;align-items:flex-start;background:var(--s2);
  border:1px solid var(--bdr);border-radius:var(--r1);padding:.62rem .75rem;min-width:0;
  transition:border-color .16s;}
.info-tile:hover{border-color:#D8CCBD;}
.info-tile.wide{grid-column:1/-1;}
.info-ic{flex-shrink:0;width:30px;height:30px;border-radius:9px;
  background:linear-gradient(135deg,var(--m3),var(--m4));color:#fff;
  display:flex;align-items:center;justify-content:center;font-size:.74rem;}
.info-ic.muted{background:var(--s1);color:var(--t3);border:1.5px solid var(--bdr);}
.info-b{min-width:0;flex:1;}
.info-l{font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.55px;
  color:var(--t3);margin-bottom:.16rem;}
/* overflow-wrap:anywhere, not word-break:break-word — the latter split
   shane_sumague@bec.edu.ph across lines even when it would have fitted, so a
   contact address read as three fragments. This only breaks when it must. */
.info-v{font-size:.79rem;color:var(--t1);font-weight:600;line-height:1.42;
  overflow-wrap:anywhere;word-break:normal;}
.info-v a{color:var(--m3);text-decoration:none;}
.info-v a:hover{text-decoration:underline;}
.info-v.muted{color:var(--t3);font-weight:500;}

/* Workflow progress card wrapper */
.wf-card{background:var(--s2);border:1px solid var(--bdr);border-radius:var(--r2);
  padding:.9rem 1rem 1rem;margin-bottom:1.15rem;}

/* Photo preview */
.photo-wrap{border-radius:var(--r2);overflow:hidden;border:2px solid var(--bdr);
  cursor:zoom-in;position:relative;transition:all .22s;}
.photo-wrap:hover{border-color:var(--m3);box-shadow:0 0 0 3px rgba(123,29,29,.1);}
.photo-wrap img{width:100%;max-height:340px;object-fit:contain;display:block;transition:transform .3s;background:#fff;}
.photo-wrap:hover img{transform:none;}
.photo-hint{position:absolute;inset:0;background:rgba(45,5,5,.45);
  display:flex;align-items:center;justify-content:center;
  opacity:0;transition:opacity .2s;}
.photo-wrap:hover .photo-hint{opacity:1;}
.photo-hint i{font-size:1.6rem;color:#fff;}
.photo-thumbs{display:flex;gap:.45rem;flex-wrap:wrap;margin-top:.55rem;}
.photo-thumbs img{width:68px;height:56px;object-fit:cover;border-radius:8px;border:2px solid transparent;cursor:pointer;background:#fff;}
.photo-thumbs img:hover,.photo-thumbs img.act{border-color:var(--m3);}

/* Timeline */
.tl{padding:.2rem 0;}
.tls{display:flex;gap:.68rem;align-items:flex-start;position:relative;padding-bottom:.8rem;}
.tls:last-child{padding-bottom:0;}
.tls:not(:last-child)::before{content:'';position:absolute;left:14px;top:30px;bottom:0;
  width:2px;background:var(--bdr);}
.tlb{width:30px;height:30px;border-radius:50%;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;
  box-shadow:none;}
.tlb.done{background:linear-gradient(135deg,#14532D,#22C55E);color:#fff;}
.tlb.act{background:linear-gradient(135deg,var(--m3),var(--m4));color:#fff;
  box-shadow:0 0 12px rgba(123,29,29,.28);
  animation:actPulse 2s ease-in-out infinite;}
@keyframes actPulse{0%,100%{box-shadow:0 0 8px rgba(123,29,29,.2);}
  50%{box-shadow:0 0 18px rgba(123,29,29,.45);}}
.tlb.idle{background:var(--s2);color:var(--t3);border:1.5px solid var(--bdr);}
.tlt strong{display:block;font-size:.78rem;font-weight:700;color:var(--t1);}
.tlt span{font-size:.68rem;color:var(--t3);}

/* Action forms */
.af{background:var(--s1);border:1.5px solid var(--bdr);border-radius:var(--r2);
  padding:1rem 1.1rem;margin-top:.875rem;}
.af-title{font-family:'Outfit',sans-serif;font-size:.85rem;font-weight:700;
  margin-bottom:.75rem;display:flex;align-items:center;gap:.35rem;}
.af-approve .af-title{color:#15803D;}
.af-reject  .af-title{color:#DC2626;}
.af-verify  .af-title{color:#1D4ED8;}
.fg{display:flex;flex-direction:column;gap:.28rem;margin-bottom:.68rem;}
.fl{font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.65px;color:var(--t2);}
.fl span{color:var(--m3);}
.fc{padding:.48rem .78rem;background:var(--s2);border:1.5px solid var(--bdr);
  border-radius:var(--r1);font-size:.8rem;color:var(--t1);
  width:100%;max-width:100%;min-width:0;box-sizing:border-box;
  font-family:'DM Sans',sans-serif;outline:none;transition:border-color .18s;}
.fc:focus{border-color:var(--m3);box-shadow:0 0 0 3px rgba(123,29,29,.07);}
textarea.fc{resize:vertical;min-height:70px;}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:.55rem;}
.fg2 > .fg{min-width:0;}
.af-actions{display:flex;gap:.45rem;flex-wrap:wrap;align-items:center;}
.af-actions .btn{min-width:0;max-width:100%;white-space:normal;}
/* ── Review / Route panel ─────────────────────────── */
.af-review{background:var(--s1);border:1.5px solid var(--bdr);border-radius:var(--r2);
  padding:1.15rem 1.2rem;margin-top:.875rem;}
.rv-head{display:flex;gap:.75rem;align-items:flex-start;margin-bottom:1rem;}
.rv-ic{flex-shrink:0;width:40px;height:40px;border-radius:12px;display:grid;place-items:center;
  background:linear-gradient(135deg,#166534,#22C55E);color:#fff;font-size:1.02rem;
  box-shadow:0 5px 12px rgba(22,101,52,.24);}
.rv-hx{min-width:0;}
.rv-title{font-family:'Outfit',sans-serif;font-size:.98rem;font-weight:800;color:var(--t1);line-height:1.2;}
.rv-desc{font-size:.73rem;color:var(--t3);line-height:1.55;margin-top:.28rem;}
.fhint{font-size:.66rem;color:var(--t3);margin-top:.05rem;line-height:1.4;}
.fl .opt{color:var(--t3);font-weight:600;text-transform:none;letter-spacing:0;font-size:.6rem;}
/* ── responsible unit cards ───────────────────────────────────────────────
   Same control as the Assign Technicians page. Two screens, one way of asking. */
.unit-seg{display:grid;grid-template-columns:1fr 1fr;gap:.4rem;}
.unit-opt{position:relative;display:block;cursor:pointer;}
.unit-opt input{position:absolute;opacity:0;width:0;height:0;}
.unit-box{display:block;position:relative;height:100%;padding:.55rem .6rem;
  border-radius:var(--r1);border:1.5px solid var(--bdr);background:var(--s1);
  transition:border-color .15s,background .15s;}
.unit-opt:hover .unit-box{border-color:var(--bdr2);}
.unit-opt input:focus-visible + .unit-box{outline:2px solid var(--m3);outline-offset:2px;}
.unit-ic{display:block;font-size:.8rem;color:var(--t3);margin-bottom:.2rem;}
.unit-t{display:block;font-family:'Outfit',sans-serif;font-size:.78rem;font-weight:800;color:var(--t1);}
.unit-d{display:block;font-size:.6rem;color:var(--t3);line-height:1.3;margin-top:.06rem;}
.unit-check{position:absolute;top:.4rem;right:.4rem;width:16px;height:16px;border-radius:50%;
  background:var(--m3);color:#fff;font-size:.5rem;display:none;align-items:center;justify-content:center;}
.unit-opt input:checked + .unit-box{border-color:var(--m3);background:#FDF6F6;}
.unit-opt input:checked + .unit-box .unit-check{display:flex;}
.unit-opt input:checked + .unit-box .unit-ic{color:var(--m3);}
.unit-itso input:checked + .unit-box{border-color:#2563EB;background:#EFF6FF;}
.unit-itso input:checked + .unit-box .unit-check{background:#2563EB;}
.unit-itso input:checked + .unit-box .unit-ic{color:#2563EB;}

/* ── what to do next ──────────────────────────────────────────────────────
   The panel showed the record and left the reader to work out the next move
   from the status badge. This states it. */
.dr-next{display:flex;align-items:flex-start;gap:.6rem;padding:.7rem .85rem;
  border-radius:12px;margin-bottom:1rem;
  background:#FFFBEF;border:1px solid #EBD9A8;}
.dr-next i{color:#C9960C;font-size:.9rem;margin-top:.1rem;flex-shrink:0;}
.dr-next b{display:block;font-family:'Outfit',sans-serif;font-size:.82rem;
  font-weight:800;color:#1C1008;margin-bottom:.1rem;}
.dr-next span{font-size:.74rem;color:#6B5344;line-height:1.5;}
.dr-next.is-done{background:#F0FDF4;border-color:#BBE8CB;}
.dr-next.is-done i{color:#16A34A;}

.prio-seg{display:grid;grid-template-columns:repeat(4,1fr);gap:.32rem;}
.prio-opt{padding:.46rem .2rem;border:1.5px solid var(--bdr);background:var(--s2);border-radius:var(--r1);
  font-size:.7rem;font-weight:700;color:var(--t2);cursor:pointer;font-family:'DM Sans',sans-serif;
  transition:transform .12s,border-color .16s,background .16s,color .16s;text-align:center;line-height:1;}
.prio-opt:hover{border-color:var(--t3);}
.prio-opt.on{color:#fff;border-color:transparent;transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.14);}
.prio-opt[data-v=low].on{background:#16A34A;}
.prio-opt[data-v=medium].on{background:#D97706;}
.prio-opt[data-v=high].on{background:#EA580C;}
.prio-opt[data-v=critical].on{background:#DC2626;}
.rv-actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-top:.35rem;}
.rv-actions .btn{white-space:nowrap;}
.rv-amber{background:#C9960C;color:#fff;border:none;}
.rv-amber:hover{background:#b3860a;}
.rv-rejbtn{margin-left:auto;color:#DC2626;}
.rv-rejbtn:hover{background:#FEF2F2;border-color:#FCA5A5;}
@media(max-width:520px){.rv-rejbtn{margin-left:0;}}
.mfoot{padding:.8rem 1.55rem 1.25rem;border-top:1px solid var(--bdr);
  display:flex;justify-content:flex-end;gap:.45rem;flex-wrap:wrap;background:var(--s2);
  border-radius:0 0 var(--r4) var(--r4);}


/* ══════════════════════════════════════════════════════════════════════════
   DEFECT REPORT WORKSPACE  (.dr-*)

   A service-desk record, not a form in a box. Replaces the old .mw/.m2col
   modal, whose every fact sat in its own bordered tile with a gradient icon —
   eight boxes of chrome for eight single-line values.
   Structure: header (sticky) · summary strip · two columns · footer (sticky).
   ══════════════════════════════════════════════════════════════════════════ */
.dr-shell{background:#fff;width:min(1200px,calc(100vw - 48px));max-height:calc(100vh - 48px);
  display:flex;flex-direction:column;border-radius:18px;overflow:hidden;
  box-shadow:0 24px 70px rgba(28,16,8,.28),0 2px 8px rgba(28,16,8,.10);
  /* Six steps and five spaces, and nothing between them. Declared on the
     component so the scale is a fact of it, not something rediscovered per
     rule. Keep these in the same block as the layout: a second .dr-shell rule
     is one more thing to keep in sync for no benefit. */
  --fs-xs:.6rem;--fs-sm:.68rem;--fs-base:.76rem;--fs-md:.82rem;--fs-lg:.88rem;
  /* --sp-0 is the hairline nudge — the quarter-step that separates a label from
     the value under it. Without it those gaps snap to .25rem and quadruple. */
  --sp-0:.125rem;--sp-1:.25rem;--sp-2:.5rem;--sp-3:.75rem;--sp-4:1rem;--sp-5:1.5rem;}

/* ── header ─────────────────────────────────────────────────────────────── */
.dr-head{position:sticky;top:0;z-index:3;flex-shrink:0;
  display:flex;align-items:flex-start;gap:var(--sp-5);
  padding:var(--sp-4) var(--sp-5) var(--sp-4);background:#fff;
  border-bottom:1px solid #EDE4D6;}
.dr-head-main{min-width:0;flex:1;}
.dr-eyebrow{font-size:var(--fs-xs);font-weight:800;letter-spacing:1.6px;text-transform:uppercase;
  color:#C9960C;margin-bottom:var(--sp-1);}
.dr-title{font-family:'Outfit',sans-serif;font-size:1.5rem;font-weight:800;
  color:#1C1008;line-height:1.15;letter-spacing:-.015em;margin:0 0 var(--sp-1);}
.dr-sub{display:flex;align-items:center;gap:var(--sp-2);flex-wrap:wrap;
  font-size:var(--fs-base);color:#8A7060;}
.dr-sub .tk{font-family:'Outfit',sans-serif;font-weight:800;color:#7B1D1D;letter-spacing:.02em;}
.dr-sub .sep{color:#D8CCBD;}

/* Status and priority as blocks, not pills — and as a grid of equal columns
   rather than two flex items sized by their text. Side by side, a card that is
   wider because its word is longer reads as the more important of the two; the
   pair carries no ranking, so they are the same size and their labels line up. */
.dr-keys{display:grid;grid-template-columns:repeat(2,8.5rem);gap:var(--sp-2);flex-shrink:0;}
.dr-key{min-width:0;padding:var(--sp-2) var(--sp-3);border-radius:12px;
  border:1px solid #EDE4D6;background:#FCFAF6;
  display:flex;flex-direction:column;justify-content:center;}
.dr-key-l{font-size:var(--fs-xs);font-weight:800;letter-spacing:1.2px;text-transform:uppercase;
  color:#A08C7C;margin-bottom:var(--sp-1);}
.dr-key-v{display:flex;align-items:center;gap:var(--sp-2);
  font-size:var(--fs-lg);font-weight:800;color:#1C1008;line-height:1.2;}
.dr-key-v .dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.dr-key.is-status{background:#FFFBEF;border-color:#EBD9A8;}
.dr-key-when{font-size:var(--fs-xs);color:#A08C7C;margin-top:var(--sp-0);}
.dr-head-btns{display:flex;gap:var(--sp-1);flex-shrink:0;}
.dr-ic{width:34px;height:34px;border-radius:10px;border:1px solid #EDE4D6;background:#fff;
  color:#6B5344;font-size:var(--fs-md);cursor:pointer;display:flex;align-items:center;
  justify-content:center;transition:background .15s,color .15s,border-color .15s;}
.dr-ic:hover{background:#7B1D1D;border-color:#7B1D1D;color:#fff;}
.dr-ic:focus-visible{outline:2px solid #7B1D1D;outline-offset:2px;}

/* ── summary strip ──────────────────────────────────────────────────────── */
.dr-summary{flex-shrink:0;display:grid;grid-template-columns:repeat(4,1fr);gap:1px;
  background:#EDE4D6;border-bottom:1px solid #EDE4D6;}
.dr-sum{background:#F8F3EA;padding:var(--sp-3) var(--sp-4);min-width:0;}
.dr-sum-l{display:flex;align-items:center;gap:var(--sp-1);
  font-size:var(--fs-xs);font-weight:800;letter-spacing:1.1px;text-transform:uppercase;
  color:#A08C7C;margin-bottom:var(--sp-1);}
.dr-sum-l i{font-size:var(--fs-xs);color:#C9960C;}
.dr-sum-v{font-size:var(--fs-md);font-weight:700;color:#1C1008;line-height:1.3;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.dr-sum-s{font-size:var(--fs-sm);color:#8A7060;margin-top:var(--sp-0);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

/* ── body ───────────────────────────────────────────────────────────────── */
.dr-body{flex:1;min-height:0;overflow-y:auto;display:grid;
  grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:0;background:#fff;}
/* The extra bottom padding is the sticky footer's height. Without it the last
   card in a column scrolls to its end flush against the footer and the final
   line sits under it — reachable only by knowing it is there. */
.dr-col{padding:var(--sp-5) var(--sp-5) 2.25rem;min-width:0;}
.dr-col + .dr-col{border-left:1px solid #EDE4D6;background:#FDFBF7;}

.dr-card{border:1px solid #EDE4D6;border-radius:14px;background:#fff;
  padding:var(--sp-4) var(--sp-4);margin-bottom:var(--sp-4);}
.dr-card:last-child{margin-bottom:0;}
.dr-card-h{display:flex;align-items:center;gap:var(--sp-2);margin-bottom:var(--sp-3);}
.dr-card-h i{color:#7B1D1D;font-size:var(--fs-md);}
.dr-card-h h3{font-family:'Outfit',sans-serif;font-size:var(--fs-lg);font-weight:800;
  color:#1C1008;margin:0;letter-spacing:-.01em;}

/* compact label/value rows — the replacement for eight bordered tiles */
.dr-rows{display:grid;gap:var(--sp-0);}
.dr-row{display:grid;grid-template-columns:8.5rem minmax(0,1fr);gap:var(--sp-3);
  padding:var(--sp-2) 0;border-bottom:1px solid #F4EDE2;align-items:baseline;}
.dr-row:last-child{border-bottom:none;}
.dr-row dt{font-size:var(--fs-sm);font-weight:700;color:#8A7060;}
.dr-row dd{margin:0;font-size:var(--fs-md);color:#1C1008;font-weight:600;line-height:1.45;
  overflow-wrap:anywhere;}
.dr-row dd a{color:#7B1D1D;text-decoration:none;}
.dr-row dd a:hover{text-decoration:underline;}
.dr-row dd.muted{color:#A08C7C;font-weight:500;}

/* equipment condition — severity, stated once and clearly */
.dr-cond{display:flex;align-items:center;gap:var(--sp-2);padding:var(--sp-2) var(--sp-3);border-radius:11px;
  border:1px solid;font-size:var(--fs-md);font-weight:800;margin-bottom:var(--sp-4);}
.dr-cond .dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.dr-cond.sev-broken{background:#FEF2F2;border-color:#F4C7C7;color:#B91C1C;}
.dr-cond.sev-partial{background:#FFF7ED;border-color:#F6D9B8;color:#C2410C;}
.dr-cond.sev-usable{background:#F0FDF4;border-color:#BBE8CB;color:#15803D;}
.dr-cond.sev-unknown{background:#F8F3EA;border-color:#EDE4D6;color:#6B5344;}

.dr-desc{background:#F8F3EA;border-left:3px solid #C9960C;border-radius:0 10px 10px 0;
  padding:var(--sp-3) var(--sp-4);font-size:var(--fs-md);line-height:1.6;color:#1C1008;}
.dr-desc.empty{color:#A08C7C;font-style:italic;border-left-color:#DCD0BE;}

/* ── evidence ───────────────────────────────────────────────────────────── */
.dr-ev{display:grid;grid-template-columns:repeat(auto-fill,minmax(84px,1fr));gap:var(--sp-2);}
.dr-ev-item{position:relative;aspect-ratio:1;border-radius:10px;overflow:hidden;
  border:1px solid #EDE4D6;background:#F8F3EA;cursor:pointer;padding:0;
  display:flex;align-items:center;justify-content:center;}
.dr-ev-item img{width:100%;height:100%;object-fit:cover;display:block;
  transition:transform .22s ease;}
/* A thumbnail that opens a lightbox should say so before it is clicked. The
   image grows inside a fixed frame rather than the frame growing, so the grid
   does not reflow under the pointer. */
.dr-ev-item:hover{border-color:#C9960C;}
.dr-ev-item:hover img{transform:scale(1.06);}
.dr-ev-item:focus-visible{outline:2px solid #7B1D1D;outline-offset:2px;}
.dr-ev-item .vid{color:#7B1D1D;font-size:1.3rem;}
.dr-ev-item .tag{position:absolute;left:0;right:0;bottom:0;background:rgba(28,16,8,.72);
  color:#fff;font-size:var(--fs-xs);font-weight:700;letter-spacing:.5px;padding:var(--sp-0) 0;
  text-align:center;text-transform:uppercase;}
.dr-none{display:flex;align-items:center;gap:var(--sp-2);font-size:var(--fs-base);color:#A08C7C;
  padding:var(--sp-2) var(--sp-3);background:#F8F3EA;border-radius:10px;}

/* ── timeline ───────────────────────────────────────────────────────────── */
.dr-tl{display:grid;gap:0;}
.dr-tl-step{display:grid;grid-template-columns:26px minmax(0,1fr);gap:var(--sp-3);
  position:relative;padding-bottom:var(--sp-3);}
.dr-tl-step:last-child{padding-bottom:0;}
.dr-tl-step::before{content:'';position:absolute;left:12px;top:26px;bottom:0;width:2px;
  background:#EDE4D6;}
.dr-tl-step:last-child::before{display:none;}
.dr-tl-step.done::before{background:#16A34A;}
.dr-tl-dot{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;
  justify-content:center;font-size:var(--fs-sm);flex-shrink:0;position:relative;z-index:1;
  border:2px solid #EDE4D6;background:#fff;color:#C0AC9C;}
.dr-tl-step.done .dr-tl-dot{background:#16A34A;border-color:#16A34A;color:#fff;}
.dr-tl-step.now .dr-tl-dot{background:#7B1D1D;border-color:#7B1D1D;color:#fff;
  box-shadow:0 0 0 4px rgba(123,29,29,.14);}
.dr-tl-b{padding-top:var(--sp-0);min-width:0;}
.dr-tl-n{font-size:var(--fs-md);font-weight:700;color:#A08C7C;line-height:1.25;}
.dr-tl-step.done .dr-tl-n,.dr-tl-step.now .dr-tl-n{color:#1C1008;}
.dr-tl-step.now .dr-tl-n{font-weight:800;}
.dr-tl-when{font-size:var(--fs-sm);color:#7B1D1D;font-weight:700;margin-top:var(--sp-0);}
.dr-tl-d{font-size:var(--fs-sm);color:#A08C7C;margin-top:var(--sp-0);line-height:1.4;}
.dr-tl-now-tag{display:inline-block;margin-left:var(--sp-1);font-size:var(--fs-xs);font-weight:800;
  letter-spacing:.8px;text-transform:uppercase;color:#7B1D1D;
  background:#FFFBEF;border:1px solid #EBD9A8;border-radius:20px;padding:var(--sp-0) var(--sp-2);
  vertical-align:middle;}

/* ── activity log (collapsible) ─────────────────────────────────────────── */
.dr-log summary{cursor:pointer;list-style:none;display:flex;align-items:center;gap:var(--sp-2);
  font-family:'Outfit',sans-serif;font-size:var(--fs-lg);font-weight:800;color:#1C1008;}
.dr-log summary::-webkit-details-marker{display:none;}
/* It is a control, so it reacts like one — and it is reachable by keyboard, so
   the focus ring is on the summary itself, not left to the browser default that
   list-style:none tends to flatten. */
.dr-log summary{border-radius:8px;padding:var(--sp-1);margin:calc(var(--sp-1) * -1);
  transition:background .15s,color .15s;}
.dr-log summary:hover{background:#F8F3EA;color:#7B1D1D;}
.dr-log summary:hover i.chev{color:#7B1D1D;}
.dr-log summary:focus-visible{outline:2px solid #7B1D1D;outline-offset:2px;}
.dr-log summary i.chev{margin-left:auto;color:#A08C7C;font-size:var(--fs-sm);transition:transform .18s;}
.dr-log[open] summary i.chev{transform:rotate(180deg);}
.dr-log-list{margin-top:var(--sp-3);display:grid;gap:var(--sp-2);max-height:15rem;overflow-y:auto;}
.dr-log-item{display:grid;grid-template-columns:auto minmax(0,1fr);gap:var(--sp-2);
  font-size:var(--fs-base);padding-bottom:var(--sp-2);border-bottom:1px solid #F4EDE2;}
.dr-log-item:last-child{border-bottom:none;padding-bottom:0;}
.dr-log-when{color:#A08C7C;white-space:nowrap;font-size:var(--fs-sm);}
.dr-log-what{color:#1C1008;line-height:1.45;}
.dr-log-who{color:#8A7060;}

/* ── footer ─────────────────────────────────────────────────────────────── */
.dr-foot{position:sticky;bottom:0;z-index:3;flex-shrink:0;
  display:flex;align-items:center;gap:var(--sp-2);flex-wrap:wrap;
  padding:var(--sp-3) var(--sp-5);background:#fff;border-top:1px solid #EDE4D6;}
/* Utilities left, decisions right. Print and Service Report are things you might
   take away with you; Close and the primary action are what you came to do, and
   they sit where the eye finishes. */
.dr-foot-l{display:flex;align-items:center;gap:var(--sp-2);flex-wrap:wrap;}
.dr-foot-r{margin-left:auto;display:flex;align-items:center;gap:var(--sp-2);flex-wrap:wrap;}
/* One clear primary. The utilities are quieter so the thing to do next is the
   thing that looks like it. */
.dr-foot-l .btn{font-weight:600;}
.dr-foot-r .btn-green,.dr-foot-r .btn-maroon{font-weight:800;padding-left:var(--sp-4);padding-right:var(--sp-4);}

/* ── responsive ─────────────────────────────────────────────────────────── */
@media(max-width:1024px){
  .dr-body{grid-template-columns:1fr;}
  .dr-col + .dr-col{border-left:none;border-top:1px solid #EDE4D6;}
  .dr-summary{grid-template-columns:1fr 1fr;}
}
@media(max-width:720px){
  /* .mo carries 1rem of side padding, so a shell of 100vw sat 2rem wider than the
     screen and the Priority card hung off the right edge. Full-bleed means the
     overlay gives up its padding too. */
  #detmo{padding:0;align-items:stretch;}
  .dr-shell{width:100%;max-width:100%;max-height:100vh;border-radius:0;}
  .dr-head{flex-wrap:wrap;gap:var(--sp-3);padding:var(--sp-4) var(--sp-4);}
  /* Two equal halves that are allowed to shrink. flex:1 alone still respected
     the 8.5rem min-width from the base rule. */
  .dr-keys{width:100%;display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-2);}
  .dr-key{min-width:0;}
  .dr-key-v{font-size:var(--fs-md);}
  .dr-summary{grid-template-columns:1fr;}
  .dr-col{padding:var(--sp-4) var(--sp-4) var(--sp-5);}
  .dr-row{grid-template-columns:1fr;gap:var(--sp-0);}
  .dr-foot{padding:var(--sp-3) var(--sp-4);}
  .dr-foot-r{width:100%;margin-left:0;}
  .dr-foot-r .btn{flex:1;justify-content:center;}
}
@media(prefers-reduced-motion:reduce){
  .dr-log summary i.chev,.dr-log summary,.dr-ev-item img,.dr-ic{transition:none;}
  .dr-ev-item:hover img{transform:none;}
}

/* ── LIGHTBOX ────────────────────────────────────────── */
#lbVid video{max-width:90vw;max-height:88vh;border-radius:var(--r2);display:block;}

.lb{position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:999;
  display:none;align-items:center;justify-content:center;cursor:zoom-out;}
.lb.open{display:flex;animation:moFade .2s ease;}
.lb img{max-width:90vw;max-height:88vh;border-radius:var(--r2);
  box-shadow:0 20px 60px rgba(0,0,0,.5);}
.lb-close{position:absolute;top:1.5rem;right:1.5rem;
  width:36px;height:36px;background:rgba(255,255,255,.15);border-radius:50%;border:none;
  color:#fff;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;
  transition:background .18s;}
.lb-close:hover{background:rgba(255,255,255,.3);}

/* ── TOAST ───────────────────────────────────────────── */
.ttray{position:fixed;top:1.25rem;left:50%;transform:translateX(-50%);align-items:center;display:flex;
  flex-direction:column;gap:.38rem;z-index:9999;}
.tst{background:var(--s1);border:1px solid var(--bdr);border-radius:var(--r2);
  padding:.68rem .88rem;display:flex;align-items:flex-start;gap:.5rem;
  box-shadow:var(--sh3);min-width:240px;
  animation:tIn .22s cubic-bezier(.4,0,.2,1);border-left:3px solid var(--m3);}
.tst.ok{border-left-color:#16A34A;}.tst.err{border-left-color:#DC2626;}
@keyframes tIn{from{transform:translateX(60px);opacity:0}to{transform:translateX(0);opacity:1}}
.tst-t{font-size:.77rem;font-weight:700;color:var(--t1);}
.tst-m{font-size:.69rem;color:var(--t2);margin-top:1px;}

/* ── EMPTY ───────────────────────────────────────────── */
.empty{text-align:center;padding:3rem 1.5rem;color:var(--t3);}
.empty i{font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.22;}
.rpager{display:flex;align-items:center;justify-content:space-between;gap:.85rem;flex-wrap:wrap;padding:.9rem 1rem;border-top:1px solid var(--bdr);}
.rpager[hidden]{display:none;}
.rpager .rp-info{font-size:.78rem;color:var(--t3);font-weight:600;}
.rpager .rp-info b{color:var(--t1);font-weight:800;}
.rpager .rp-btns{display:inline-flex;align-items:center;gap:.25rem;flex-wrap:wrap;padding:.28rem;background:var(--s2);border:1px solid var(--bdr);border-radius:999px;}
.rpager .rp-gap{padding:0 .1rem;color:var(--t3);font-size:.85rem;font-weight:800;user-select:none;align-self:center;opacity:.6;}
/* Anchors as well as buttons: the pager is server-rendered links now, and the
   current page stays a disabled-looking button because it goes nowhere. */
.rpager button,.rpager a{display:inline-flex;align-items:center;justify-content:center;min-width:2rem;height:2rem;padding:0 .6rem;border:none;background:transparent;color:var(--t2);border-radius:999px;font-size:.8rem;font-weight:700;cursor:pointer;line-height:1;text-decoration:none;transition:color .16s,background .16s,box-shadow .16s,transform .16s;}
.rpager button:hover:not(:disabled):not(.on),.rpager a:hover{background:var(--s1);color:var(--m3);box-shadow:0 1px 5px rgba(0,0,0,.09);transform:none;}
.rpager button.on{background:linear-gradient(135deg,#4A0E0E,#7B1D1D);color:#fff;box-shadow:0 3px 9px rgba(123,29,29,.34);transform:translateY(-1px);cursor:default;}
.rpager button.rp-nav,.rpager a.rp-nav{width:2rem;min-width:2rem;padding:0;font-size:1rem;color:var(--t3);}
.rpager button.rp-nav:hover:not(:disabled),.rpager a.rp-nav:hover{color:var(--m3);}
.rpager button:disabled{opacity:.35;cursor:default;box-shadow:none;transform:none;}

/* ── RESPONSIVE ──────────────────────────────────────── */
@media(max-width:1400px){.sums{grid-template-columns:repeat(4,1fr);}
  .kanban{grid-template-columns:repeat(3,1fr);}}
@media(max-width:1100px){.kanban{grid-template-columns:repeat(2,1fr);}
  .sums{grid-template-columns:repeat(3,1fr);}.m2col{grid-template-columns:1fr;max-height:none;}}
@media(max-width:768px){.sb{transform:translateX(-100%);}.sb.open{transform:translateX(0);}
  .wrap{margin-left:0;}.pg{padding:1rem;}.mob-tog{display:flex;}
  .kanban{grid-template-columns:1fr;}.sums{grid-template-columns:1fr 1fr;}}
@media(max-width:480px){.info-grid{grid-template-columns:1fr;}.prio-seg{grid-template-columns:1fr 1fr;}}

/* stagger entrance */
.scard{animation:scIn .32s ease both;}
.scard:nth-child(1){animation-delay:.04s;}.scard:nth-child(2){animation-delay:.09s;}
.scard:nth-child(3){animation-delay:.14s;}.scard:nth-child(4){animation-delay:.19s;}
.scard:nth-child(5){animation-delay:.24s;}.scard:nth-child(6){animation-delay:.29s;}
.scard:nth-child(7){animation-delay:.34s;}
@keyframes scIn{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}
.kcard{animation:kcIn .28s ease both;}
@keyframes kcIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
</style>
</head>
<body>

<!-- ══════════ SIDEBAR ════════════════════════════════ -->
<?php $activeNav = 'defects'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

<!-- ══════════ MAIN ═══════════════════════════════════ -->
<div class="wrap">

  <!-- TOPBAR -->
  <header class="topbar">
    <div class="tb-l">
      <button class="mob-tog" onclick="document.getElementById('sb').classList.toggle('open')">
        <i class="fas fa-bars"></i>
      </button>
      <div>
        <div class="pg-title">Defect Reports Management</div>
        <div class="bc">
          <a href="admin_dashboard.php"><i class="fas fa-home"></i></a>
          <i class="fas fa-chevron-right"></i><span>Defect Reports</span>
        </div>
      </div>
    </div>
    <div class="tb-r">
      <a href="admin_notifications.php" class="ic-btn" title="Notifications">
        <i class="fas fa-bell"></i><span class="pip"></span>
      </a>
      <!-- Export dropdown trigger -->
      <div style="position:relative;">
        <button class="btn btn-gold btn-sm" onclick="toggleExportMenu(event)">
          <i class="fas fa-download"></i> Export <i class="fas fa-chevron-down" style="font-size:.6rem;"></i>
        </button>
        <div id="exportMenu" style="display:none;position:absolute;right:0;top:calc(100% + 6px);background:var(--s1);border:1.5px solid var(--bdr);border-radius:var(--r2);box-shadow:var(--sh3);z-index:300;min-width:150px;overflow:hidden;">
          <button onclick="exportCSV()" style="width:100%;padding:.6rem 1rem;background:none;border:none;text-align:left;font-size:.78rem;font-family:'DM Sans',sans-serif;cursor:pointer;display:flex;align-items:center;gap:.5rem;color:var(--t1);" onmouseover="this.style.background='var(--s2)'" onmouseout="this.style.background='none'">
            <i class="fas fa-file-csv" style="color:#16A34A;"></i> Export CSV
          </button>
          <button onclick="exportExcel()" style="width:100%;padding:.6rem 1rem;background:none;border:none;text-align:left;font-size:.78rem;font-family:'DM Sans',sans-serif;cursor:pointer;display:flex;align-items:center;gap:.5rem;color:var(--t1);border-top:1px solid var(--bdr);" onmouseover="this.style.background='var(--s2)'" onmouseout="this.style.background='none'">
            <i class="fas fa-file-excel" style="color:#16A34A;"></i> Export Excel
          </button>
          <button onclick="exportPDF()" style="width:100%;padding:.6rem 1rem;background:none;border:none;text-align:left;font-size:.78rem;font-family:'DM Sans',sans-serif;cursor:pointer;display:flex;align-items:center;gap:.5rem;color:var(--t1);border-top:1px solid var(--bdr);" onmouseover="this.style.background='var(--s2)'" onmouseout="this.style.background='none'">
            <i class="fas fa-file-pdf" style="color:#DC2626;"></i> Export PDF
          </button>
          <button onclick="openExportModal()" style="width:100%;padding:.6rem 1rem;background:none;border:none;text-align:left;font-size:.78rem;font-family:'DM Sans',sans-serif;cursor:pointer;display:flex;align-items:center;gap:.5rem;color:var(--t1);border-top:1px solid var(--bdr);font-weight:600;" onmouseover="this.style.background='var(--s2)'" onmouseout="this.style.background='none'">
            <i class="fas fa-filter" style="color:#92600A;"></i> Advanced Export…
          </button>
        </div>
      </div>
    </div>
  </header>

  <!-- PAGE CONTENT -->
  <div class="pg">

    <!-- Flash -->
    <?php if(isset($_SESSION['flash'])): [$ft,$fm]=$_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="flash <?php echo $ft; ?>">
      <i class="fas fa-<?php echo $ft==='ok'?'check-circle':'exclamation-circle'; ?>"></i>
      <?php echo esc($fm); ?>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="ph">
      <div>
        <h1><i class="fas fa-exclamation-triangle"></i> Defect Reports
          <?php if ($adminUnit !== ''): ?><span class="unit-badge"><i class="fas fa-<?php echo $adminUnit==='ITSO'?'laptop-code':'building-shield'; ?>"></i> <?php echo esc($adminUnit); ?> Admin</span><?php endif; ?>
        </h1>
        <p class="ph-sub"><?php if ($adminUnit !== '' && !$dfExplicit): ?>Showing <strong><?php echo esc($adminUnit); ?></strong> reports by default (plus any not yet triaged) — use the <em>Department</em> filter to view All or the other unit. <?php endif; ?>Review, approve, categorise and monitor equipment defect reports. Click any card to open details.</p>
      </div>
      <div class="ph-acts">
        <!-- View toggle -->
        <div class="view-toggle">
          <button class="vt-btn <?php echo $vw==='table'?'on':''; ?>" onclick="switchView('table')">
            <i class="fas fa-list"></i> Table
          </button>
          <button class="vt-btn <?php echo $vw==='kanban'?'on':''; ?>" onclick="switchView('kanban')">
            <i class="fas fa-columns"></i> Kanban
          </button>
        </div>
        <button class="btn btn-maroon btn-sm" onclick="location.reload()">
          <i class="fas fa-sync-alt"></i> Refresh
        </button>
      </div>
    </div>

    <!-- Summary Strip -->
    <div class="sums">
      <a href="?status=all&view=<?php echo $vw;?>" class="scard sc-a">
        <div class="sico"><i class="fas fa-clipboard-list"></i></div>
        <div class="snum" id="sn0"><?php echo $c_all; ?></div>
        <div class="slbl">All Reports</div>
      </a>
      <a href="?status=pending&view=<?php echo $vw;?>" class="scard sc-p">
        <div class="sico"><i class="fas fa-hourglass-half"></i></div>
        <div class="snum" id="sn1"><?php echo $c_pend; ?></div>
        <div class="slbl">Pending</div>
      </a>
      <a href="?status=received&view=<?php echo $vw;?>" class="scard sc-q">
        <div class="sico"><i class="fas fa-check-double"></i></div>
        <div class="snum" id="sn2"><?php echo $c_app; ?></div>
        <div class="slbl">Received</div>
      </a>
      <a href="?status=in_progress&view=<?php echo $vw;?>" class="scard sc-r">
        <div class="sico"><i class="fas fa-wrench"></i></div>
        <div class="snum" id="sn3"><?php echo $c_prog; ?></div>
        <div class="slbl">In Progress</div>
      </a>
      <a href="?status=completed&view=<?php echo $vw;?>" class="scard sc-s">
        <div class="sico"><i class="fas fa-check-circle"></i></div>
        <div class="snum" id="sn4"><?php echo $c_done; ?></div>
        <div class="slbl">Completed</div>
      </a>
      <a href="?status=rejected&view=<?php echo $vw;?>" class="scard sc-t">
        <div class="sico"><i class="fas fa-times-circle"></i></div>
        <div class="snum" id="sn5"><?php echo $c_rej; ?></div>
        <div class="slbl">Rejected</div>
      </a>
      <a href="?priority=critical&view=<?php echo $vw;?>" class="scard sc-u">
        <div class="sico"><i class="fas fa-radiation-alt"></i></div>
        <div class="snum" id="sn6"><?php echo $c_crit; ?></div>
        <div class="slbl">Critical Cases</div>
      </a>
    </div>

    <!-- Filter Bar -->
    <div class="fbar">
      <div class="fsw">
        <i class="fas fa-search"></i>
        <input type="text" class="fsi" id="fsq" placeholder="Search ID, equipment, description…"
          value="<?php echo esc($sq); ?>" oninput="debounceGo()">
      </div>
      <select class="fsel" id="fss" onchange="go()">
        <option value="all" <?php echo $sf==='all'?'selected':''; ?>>All Status</option>
        <option value="pending"     <?php echo $sf==='pending'?'selected':''; ?>>Pending (awaiting PMO)</option>
        <option value="received"    <?php echo $sf==='received'?'selected':''; ?>>Received / Assigned</option>
        <option value="in_progress" <?php echo $sf==='in_progress'?'selected':''; ?>>In Progress</option>
        <option value="completed"   <?php echo $sf==='completed'?'selected':''; ?>>Completed</option>
        <option value="rejected"    <?php echo $sf==='rejected'?'selected':''; ?>>Rejected</option>
      </select>
      <select class="fsel" id="fsp" onchange="go()">
        <option value="all"      <?php echo $pf==='all'?'selected':''; ?>>All Priority</option>
        <option value="critical" <?php echo $pf==='critical'?'selected':''; ?>>Critical</option>
        <option value="high"     <?php echo $pf==='high'?'selected':''; ?>>High</option>
        <option value="medium"   <?php echo $pf==='medium'?'selected':''; ?>>Medium</option>
        <option value="low"      <?php echo $pf==='low'?'selected':''; ?>>Low</option>
      </select>
      <select class="fsel" id="fsd" onchange="go()">
        <option value="all"  <?php echo $df==='all'?'selected':''; ?>>All Departments</option>
        <option value="ITSO" <?php echo $df==='ITSO'?'selected':''; ?>>ITSO</option>
        <option value="PMO"  <?php echo $df==='PMO'?'selected':''; ?>>PMO</option>
      </select>
      <select class="fsel" id="fsk" aria-label="Filter by ticket origin" onchange="go()">
        <option value="all"        <?php echo $kf==='all'?'selected':''; ?>>All Origins</option>
        <option value="reported"   <?php echo $kf==='reported'?'selected':''; ?>>Reported by a person</option>
        <option value="preventive" <?php echo $kf==='preventive'?'selected':''; ?>>Preventive (scheduled)</option>
      </select>
      <span class="fcount"><?php echo $totalReports; ?> result<?php echo $totalReports != 1 ? 's' : ''; ?></span>
    </div>

    <!-- ════ TABLE VIEW ════════════════════════════════ -->
    <?php if ($vw === 'table'): ?>
    <div id="tableView">
      <div class="panel">
        <div class="ph3">
          <h3><i class="fas fa-list-alt"></i> Report Records</h3>
          <div style="display:flex;gap:.4rem;">
            <button class="btn btn-ghost btn-sm" onclick="exportCSV()"><i class="fas fa-file-csv"></i> CSV</button>
            <button class="btn btn-ghost btn-sm" onclick="exportExcel()"><i class="fas fa-file-excel"></i> XLS</button>
          </div>
        </div>
        <table class="tbl" id="mainTbl">
          <thead>
            <tr>
              <th>Report ID</th><th>Equipment</th><th>Reporter</th>
              <th>Priority</th><th>Status</th><th>Department</th>
              <th>Date</th><th>Assigned To</th><th style="text-align:center;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if($totalReports === 0): ?>
            <tr><td colspan="9"><div class="empty">
              <i class="fas fa-folder-open"></i>No reports match your current filters.
            </div></td></tr>
            <?php else: foreach($reportsPage as $r): ?>
            <tr class="rep-row" tabindex="0" role="button" aria-label="Open report details" data-rid="<?php echo esc($r['report_id']); ?>" data-view-url="?view_id=<?php echo $r['report_id']; ?>&status=<?php echo $sf; ?>&priority=<?php echo $pf; ?>&dept=<?php echo $df; ?>&kind=<?php echo $kf; ?>&search=<?php echo urlencode($sq); ?>&view=<?php echo $vw; ?>">
              <td><span class="rid"><?php echo esc($r['report_id']); ?></span>
                <?php if(!empty($r['is_preventive']) && $r['is_preventive'] !== 'f'): ?>
                <span class="pm-tag" title="Raised automatically by a preventive maintenance schedule"><i class="fas fa-calendar-check"></i> PM</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="en"><?php echo esc($r['equipment_name']??'N/A'); ?></div>
                <?php if(!empty($r['asset_tag'])): ?>
                <div class="esl"><?php echo esc($r['asset_tag']); ?></div>
                <?php endif; ?>
              </td>
              <td style="font-size:.77rem;"><?php echo esc($r['reporter_name']??'—'); ?></td>
              <td><span class="bdg b-<?php echo prCls($r['priority']); ?>"><?php echo prLbl($r['priority']); ?></span></td>
              <td><span class="bdg b-<?php echo stCls($r['status']); ?>"><?php echo stLbl($r['status']); ?></span></td>
              <td>
                <?php $d=$r['department_assigned']??'';
                if($d==='ITSO') echo '<span class="dept-itso"><i class="fas fa-laptop-code"></i>ITSO</span>';
                elseif($d==='PMO') echo '<span class="dept-pmo"><i class="fas fa-building"></i>PMO</span>';
                else echo '<span class="dept-none">—</span>'; ?>
              </td>
              <td style="font-size:.73rem;color:var(--t3);white-space:nowrap;"><?php echo date('M j, Y',strtotime($r['report_date'])); ?></td>
              <td style="font-size:.73rem;"><?php echo esc($r['technician_name']??'Unassigned'); ?></td>
              <td style="text-align:center;">
                <div style="display:flex;gap:.25rem;justify-content:center;">
                  <a href="?view_id=<?php echo $r['report_id']; ?>&status=<?php echo $sf; ?>&priority=<?php echo $pf; ?>&dept=<?php echo $df; ?>&kind=<?php echo $kf; ?>&search=<?php echo urlencode($sq); ?>&view=<?php echo $vw; ?>"
                    class="btn bico bi-v" title="View Details"><i class="fas fa-eye"></i></a>
                  <?php if (($r['status'] ?? '') === 'reported'): ?>
                  <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Mark report <?php echo esc($r['report_id']); ?> as officially Received by the PMO?\n\nThe reporter will be notified by email and in-app, and this will be recorded in the audit log and tracking timeline.');">
                    <input type="hidden" name="action" value="mark_received">
                    <input type="hidden" name="report_id" value="<?php echo esc($r['report_id']); ?>">
                    <button type="submit" class="btn bico" title="Mark as Received" style="background:#C9960C;color:#fff;"><i class="fas fa-inbox"></i></button>
                  </form>
                  <?php endif; ?>
                  <?php /* only statuses assignDefectReportToTechnician() accepts */
                    if (in_array($r['status'] ?? '', ['pmo_review','ready_for_assignment','assigned'], true)): ?>
                  <a href="admin_assign_technicians.php?report=<?php echo $r['report_id']; ?>"
                    class="btn bico bi-a" title="Assign Technician"><i class="fas fa-user-plus"></i></a>
                  <?php endif; ?>
                  <button class="btn bico bi-d" title="Delete"
                    onclick="delRep('<?php echo $r['report_id']; ?>')"><i class="fas fa-trash"></i></button>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        <?php /* Rendered by PHP, not JS: the rows for other pages are no longer in the
                 document for a script to hide, so the pager has to be real links. Real
                 links also mean a page is bookmarkable and the back button works. */ ?>
        <?php if ($totalReports > 0): ?>
        <div class="rpager" id="repPager">
          <span class="rp-info">Showing
            <b><?php echo number_format($pageOffset + 1); ?>–<?php echo number_format($pageOffset + count($reportsPage)); ?></b>
            of <b><?php echo number_format($totalReports); ?></b> report<?php echo $totalReports === 1 ? '' : 's'; ?></span>
          <?php if ($totalPages > 1): ?>
          <div class="rp-btns">
            <?php if ($pageNum > 1): ?>
              <a class="rp-nav" href="<?php echo esc(repPageUrl($pageNum - 1)); ?>" aria-label="Previous page">&lsaquo;</a>
            <?php else: ?>
              <button type="button" class="rp-nav" disabled aria-label="Previous page">&lsaquo;</button>
            <?php endif; ?>
            <?php
              /* Window the numbers with ellipses so 200 pages stay one short row. */
              $lo = max(1, $pageNum - 2);
              $hi = min($totalPages, $pageNum + 2);
              $btn = function (int $p) use ($pageNum) {
                  if ($p === $pageNum) {
                      echo '<button type="button" class="on" aria-current="page">' . $p . '</button>';
                  } else {
                      echo '<a href="' . esc(repPageUrl($p)) . '">' . $p . '</a>';
                  }
              };
              if ($lo > 1) { $btn(1); if ($lo > 2) { echo '<span class="rp-gap">&hellip;</span>'; } }
              for ($p = $lo; $p <= $hi; $p++) { $btn($p); }
              if ($hi < $totalPages) {
                  if ($hi < $totalPages - 1) { echo '<span class="rp-gap">&hellip;</span>'; }
                  $btn($totalPages);
              }
            ?>
            <?php if ($pageNum < $totalPages): ?>
              <a class="rp-nav" href="<?php echo esc(repPageUrl($pageNum + 1)); ?>" aria-label="Next page">&rsaquo;</a>
            <?php else: ?>
              <button type="button" class="rp-nav" disabled aria-label="Next page">&rsaquo;</button>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ════ KANBAN VIEW ═══════════════════════════════ -->
    <?php if ($vw === 'kanban'): ?>
    <div id="kanbanView">
      <div class="kanban">
        <?php foreach($cols as $status => $col):
          $cards = $kanban[$status];
          $kStyle = "background:{$col['bg']};--kc:{$col['color']};--kbdr:{$col['bdr']};--kbg:{$col['bg']};";
        ?>
        <div class="kol" style="--kbdr:<?php echo $col['bdr'];?>;">
          <!-- Column Header -->
          <div class="kol-h" style="border-bottom-color:<?php echo $col['bdr'];?>;">
            <div class="kol-h-l">
              <div class="kol-ic" style="background:<?php echo $col['bg'];?>;color:<?php echo $col['color'];?>;">
                <i class="fas fa-<?php echo $col['icon'];?>"></i>
              </div>
              <span class="kol-title"><?php echo $col['label'];?></span>
            </div>
            <span class="kol-cnt" style="background:<?php echo $col['bg'];?>;color:<?php echo $col['color'];?>;">
              <?php echo count($cards);?>
            </span>
          </div>

          <!-- Cards -->
          <div class="kol-body">
            <?php if(empty($cards)): ?>
            <div class="k-empty">
              <i class="fas fa-inbox"></i>No reports
            </div>
            <?php else: foreach($cards as $i=>$r): ?>
            <div class="kcard" style="--kbdr:<?php echo $col['bdr'];?>;--kc:<?php echo $col['color'];?>;animation-delay:<?php echo min($i,25)*.04;?>s;"
              onclick="location.href='?view_id=<?php echo $r['report_id'];?>&view=kanban'">
              <?php if(!empty($r['photo_url'])): ?>
              <div class="kcard-photo"><i class="fas fa-camera"></i></div>
              <?php endif; ?>
              <div class="kcard-id">#<?php echo esc($r['report_id']); ?></div>
              <div class="kcard-eq"><?php echo esc($r['equipment_name']??'Equipment'); ?></div>
              <div class="kcard-desc"><?php echo esc(substr($r['issue_description']??'',0,80)); ?></div>
              <div class="kcard-meta">
                <span class="bdg b-<?php echo prCls($r['priority']); ?>"><?php echo prLbl($r['priority']); ?></span>
                <span class="kcard-date"><?php echo date('M j',strtotime($r['report_date'])); ?></span>
              </div>
            </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /pg -->
</div><!-- /wrap -->

<!-- ════ DETAIL / ACTION MODAL ════════════════════════ -->
<?php if($vr): ?>
<div class="mo open" id="detmo" onclick="if(event.target===this)closeDet()">
  <div class="dr-shell" role="dialog" aria-modal="true" aria-labelledby="drTitle">
  <?php
    /* ── data the record view reads, resolved once ─────────────────────────
       Nothing here is new: every value already existed on $vr or in
       activity_log. The redesign is presentational — the workflow, the forms
       and the POST actions below are untouched. */
    $drUs  = trim((string)($vr['usable_status'] ?? ''));
    $drCond = [
      'Yes'       => ['sev-usable',  '#16A34A', 'Still usable'],
      'Partially' => ['sev-partial', '#D97706', 'Partially usable'],
      'No'        => ['sev-broken',  '#DC2626', 'Completely broken'],
    ][$drUs] ?? ['sev-unknown', '#8A7466', $drUs !== '' ? $drUs : 'Not stated'];

    $drWhen = static fn($v) => !empty($v) ? date('M j, Y · g:i A', strtotime((string)$v)) : null;

    /* The stepper reads the timestamps the workflow actually writes, so a stage
       shows when it happened rather than a generic label. A null stamp means the
       stage has not been reached — which is also how "future" is decided. */
    $drRejected = strtolower((string)$vr['status']) === 'rejected';
    $drStages = [
      ['Submitted',       'fa-paper-plane',   $vr['report_date']        ?? null, 'Reported and logged.'],
      ['Received by PMO', 'fa-inbox',         $vr['received_by_pmo_at'] ?? null, 'Acknowledged for review.'],
      ['Assigned',        'fa-user-plus',     $vr['assigned_date']      ?? null, 'Routed to a technician.'],
      ['Accepted',        'fa-handshake',     $vr['accepted_at']        ?? null, 'Technician took the job.'],
      ['In Progress',     'fa-screwdriver-wrench', $vr['started_at']    ?? null, 'Repair under way.'],
      ['Completed',       'fa-clipboard-check',    $vr['completion_date'] ?? null, 'Work reported finished.'],
      ['Verified & Closed','fa-circle-check', in_array($vr['status'], ['verified','closed'], true) ? ($vr['completion_date'] ?? null) : null, 'Confirmed by the office.'],
    ];
    // the current stage is the last one with a timestamp
    $drNow = 0;
    foreach ($drStages as $i => $s) { if (!empty($s[2])) { $drNow = $i; } }

    /* Audit trail for this ticket. activity_log.action is dead in every row and
       some legacy rows are column-shifted, so the description is resolved across
       the columns that actually carry it rather than trusting one. */
    $drLog = [];
    try {
        $drSt = getPgsqlPdoConnection()->prepare(
            "SELECT created_at, user_id, user_role, action_type, action_description, details
               FROM activity_log
              WHERE action_description ILIKE :a OR details ILIKE :b
              ORDER BY created_at DESC LIMIT 25");
        $drSt->execute(['a' => '%' . $vr['report_id'] . '%', 'b' => '%' . $vr['report_id'] . '%']);
        $drLog = $drSt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $drLog = []; }
  ?>

    <!-- ── ReportHeader ─────────────────────────────────────────────────── -->
    <header class="dr-head">
      <div class="dr-head-main">
        <div class="dr-eyebrow">Defect Report</div>
        <h2 class="dr-title" id="drTitle"><?php echo esc($vr['equipment_name']); ?></h2>
        <div class="dr-sub">
          <span class="tk">#<?php echo esc($vr['report_id']); ?></span>
          <span class="sep">&bull;</span>
          <span>Submitted <?php echo $drWhen($vr['report_date']) ?? '—'; ?></span>
        </div>
      </div>

      <div class="dr-keys">
        <div class="dr-key is-status">
          <div class="dr-key-l">Status</div>
          <div class="dr-key-v">
            <span class="dot" style="background:<?php echo $drRejected ? '#DC2626' : '#7B1D1D'; ?>;"></span>
            <?php echo esc(stLbl($vr['status'])); ?>
          </div>
          <?php if ($w = $drWhen($vr['received_by_pmo_at'] ?? null)): ?>
          <div class="dr-key-when"><?php echo $w; ?></div>
          <?php endif; ?>
        </div>
        <div class="dr-key">
          <div class="dr-key-l">Priority</div>
          <div class="dr-key-v">
            <span class="dot" style="background:<?php
              echo ['low'=>'#2563EB','medium'=>'#C9960C','high'=>'#EA580C','critical'=>'#DC2626'][strtolower((string)$vr['priority'])] ?? '#8A7466'; ?>;"></span>
            <?php echo esc(prLbl($vr['priority'])); ?>
          </div>
        </div>
      </div>

      <div class="dr-head-btns">
        <a class="dr-ic" href="defect_report_ticket.php?report=<?php echo urlencode($vr['report_id']); ?>"
           target="_blank" rel="noopener" data-tip="Print ticket" aria-label="Print ticket">
          <i class="fas fa-print" aria-hidden="true"></i></a>
        <button type="button" class="dr-ic" onclick="closeDet()" data-tip="Close" aria-label="Close report">
          <i class="fas fa-times" aria-hidden="true"></i></button>
      </div>
    </header>

    <!-- ── ReportSummary ────────────────────────────────────────────────── -->
    <div class="dr-summary">
      <div class="dr-sum">
        <div class="dr-sum-l"><i class="fas fa-user" aria-hidden="true"></i> Reporter</div>
        <div class="dr-sum-v" title="<?php echo esc($vr['reporter_name']); ?>"><?php echo esc($vr['reporter_name']); ?></div>
        <?php if (!empty($vr['reporter_email'])): ?>
        <div class="dr-sum-s" title="<?php echo esc($vr['reporter_email']); ?>"><?php echo esc($vr['reporter_email']); ?></div>
        <?php endif; ?>
      </div>
      <div class="dr-sum">
        <div class="dr-sum-l"><i class="fas fa-location-dot" aria-hidden="true"></i> Location</div>
        <?php $drLoc = (string)($vr['location'] ?? '—'); $drLocHead = trim(explode('•', $drLoc)[0]); ?>
        <div class="dr-sum-v" title="<?php echo esc($drLoc); ?>"><?php echo esc($drLocHead ?: '—'); ?></div>
        <?php if ($drLocHead !== '' && $drLocHead !== $drLoc): ?>
        <div class="dr-sum-s" title="<?php echo esc($drLoc); ?>"><?php echo esc(trim(substr($drLoc, strlen($drLocHead) + 1), " •")); ?></div>
        <?php endif; ?>
      </div>
      <div class="dr-sum">
        <div class="dr-sum-l"><i class="fas fa-building-columns" aria-hidden="true"></i> Department</div>
        <div class="dr-sum-v" title="<?php echo esc($vr['reporter_department'] ?? ''); ?>"><?php echo esc($vr['reporter_department'] ?: '—'); ?></div>
        <?php if (!empty($vr['reporter_course'])): ?>
        <div class="dr-sum-s" title="<?php echo esc($vr['reporter_course']); ?>"><?php echo esc($vr['reporter_course']); ?></div>
        <?php endif; ?>
      </div>
      <div class="dr-sum">
        <div class="dr-sum-l"><i class="fas fa-screwdriver-wrench" aria-hidden="true"></i> Equipment</div>
        <div class="dr-sum-v" title="<?php echo esc($vr['equipment_name']); ?>"><?php echo esc($vr['equipment_name']); ?></div>
        <div class="dr-sum-s"><?php echo esc($vr['asset_tag'] ?: 'No asset tag'); ?></div>
      </div>
    </div>

    <!-- ── body ─────────────────────────────────────────────────────────── -->
    <div class="dr-body">

      <!-- LEFT ─────────────────────────────────────────────────────────── -->
      <div class="dr-col">

        <!-- IssueDetails -->
        <section class="dr-card">
          <div class="dr-card-h"><i class="fas fa-circle-info" aria-hidden="true"></i><h3>Issue Details</h3></div>

          <div class="dr-cond <?php echo $drCond[0]; ?>">
            <span class="dot" style="background:<?php echo $drCond[1]; ?>;"></span>
            <span><?php echo esc(strtoupper($drCond[2])); ?></span>
          </div>

          <dl class="dr-rows">
            <div class="dr-row"><dt>Equipment</dt><dd><?php echo esc($vr['equipment_name']); ?></dd></div>
            <div class="dr-row"><dt>Asset Tag</dt><dd><?php echo esc($vr['asset_tag'] ?: '—'); ?></dd></div>
            <div class="dr-row"><dt>Location</dt><dd><?php echo esc($vr['location'] ?: '—'); ?></dd></div>
            <div class="dr-row"><dt>Reported by</dt><dd><?php echo esc($vr['reporter_name']); ?></dd></div>
            <?php if (!empty($vr['reporter_email'])): ?>
            <div class="dr-row"><dt>Contact</dt><dd><a href="mailto:<?php echo esc($vr['reporter_email']); ?>"><?php echo esc($vr['reporter_email']); ?></a></dd></div>
            <?php endif; ?>
            <?php if (!empty($vr['reporter_course'])): ?>
            <div class="dr-row"><dt>Course / Program</dt><dd><?php echo esc($vr['reporter_course']); ?></dd></div>
            <?php endif; ?>
            <div class="dr-row"><dt>Responsible Unit</dt>
              <dd class="<?php echo empty($vr['department_assigned']) ? 'muted' : ''; ?>">
                <?php echo esc($vr['department_assigned'] ?: 'Not routed yet'); ?></dd></div>
            <div class="dr-row"><dt>Technician</dt>
              <dd class="<?php echo ($vr['technician_name'] === 'Unassigned') ? 'muted' : ''; ?>">
                <?php echo esc($vr['technician_name']); ?></dd></div>
          </dl>

          <div class="dr-card-h" style="margin:1rem 0 .5rem;">
            <i class="fas fa-align-left" aria-hidden="true"></i><h3>Description</h3>
          </div>
          <div class="dr-desc<?php echo trim((string)$vr['issue_description']) === '' ? ' empty' : ''; ?>">
            <?php echo trim((string)$vr['issue_description']) !== ''
              ? nl2br(esc($vr['issue_description']))
              : 'No description was given.'; ?>
          </div>
          <?php if (!empty($vr['admin_notes'])): ?>
          <div class="dr-card-h" style="margin:1rem 0 .5rem;">
            <i class="fas fa-note-sticky" aria-hidden="true"></i><h3>PMO Notes</h3>
          </div>
          <div class="dr-desc"><?php echo nl2br(esc($vr['admin_notes'])); ?></div>
          <?php endif; ?>
        </section>

        <!-- Evidence -->
        <section class="dr-card">
          <div class="dr-card-h"><i class="fas fa-camera" aria-hidden="true"></i><h3>Evidence</h3></div>
          <?php
            $drPhotos = !empty($vr['photo_urls']) ? $vr['photo_urls'] : [];
            $drVideos = !empty($vr['video_urls']) ? $vr['video_urls'] : [];
          ?>
          <?php if ($drPhotos || $drVideos): ?>
          <div class="dr-ev">
            <?php foreach ($drPhotos as $drP): ?>
            <button type="button" class="dr-ev-item" onclick="openLb('<?php echo esc($drP); ?>')"
                    aria-label="View photo evidence">
              <img src="<?php echo esc($drP); ?>" alt="Reported defect" loading="lazy">
            </button>
            <?php endforeach; ?>
            <?php foreach ($drVideos as $drV): ?>
            <button type="button" class="dr-ev-item" onclick="openVidLb('<?php echo esc($drV); ?>')"
                    aria-label="Play video evidence">
              <i class="fas fa-circle-play vid" aria-hidden="true"></i>
              <span class="tag">Video</span>
            </button>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="dr-none"><i class="fas fa-image" aria-hidden="true"></i> No evidence attached</div>
          <?php endif; ?>
        </section>

        <!-- ActivityLog -->
        <details class="dr-card dr-log">
          <summary>
            <i class="fas fa-clock-rotate-left" aria-hidden="true" style="color:#7B1D1D;font-size:.82rem;"></i>
            Activity &amp; Audit Log
            <span style="font-size:.66rem;font-weight:600;color:#A08C7C;">(<?php echo count($drLog); ?>)</span>
            <i class="fas fa-chevron-down chev" aria-hidden="true"></i>
          </summary>
          <?php if ($drLog): ?>
          <div class="dr-log-list">
            <?php foreach ($drLog as $drE): ?>
            <div class="dr-log-item">
              <span class="dr-log-when"><?php echo date('M j · g:i A', strtotime((string)$drE['created_at'])); ?></span>
              <span class="dr-log-what">
                <?php
                  $drTxt = trim((string)($drE['action_description'] ?: $drE['details'] ?: $drE['action_type']));
                  echo esc($drTxt !== '' ? $drTxt : 'Activity recorded');
                ?>
                <?php if (!empty($drE['user_id'])): ?>
                <span class="dr-log-who">— <?php echo esc($drE['user_id']); ?><?php
                  echo !empty($drE['user_role']) ? ' (' . esc($drE['user_role']) . ')' : ''; ?></span>
                <?php endif; ?>
              </span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="dr-none" style="margin-top:.7rem;"><i class="fas fa-inbox" aria-hidden="true"></i> No recorded activity for this ticket yet</div>
          <?php endif; ?>
        </details>
      </div>

      <!-- RIGHT ────────────────────────────────────────────────────────── -->
      <div class="dr-col">

        <?php
          /* What happens next, said plainly. The panel showed a status badge and
             left the reader to work out what that meant they should do — which
             is fine once you know the workflow by heart and useless before then.
             Read from the same status the action forms below key off, so the two
             can never disagree. */
          $drNext = [
            'reported'             => ['fa-inbox',           'Acknowledge receipt',   'Mark this as received so the reporter knows the office has it, then approve or reject it.'],
            'pmo_review'           => ['fa-scale-balanced',  'Review and route',      'Confirm which unit carries out the repair and how urgent it is, then approve it for assignment.'],
            'ready_for_assignment' => ['fa-user-plus',       'Assign a technician',   'This report is approved and waiting for someone to be sent. Use Assign Technician below.'],
            'assigned'             => ['fa-hourglass-half',  'Waiting on the technician', 'Assigned and not yet accepted. Nothing is needed from the office until it is.'],
            'accepted'             => ['fa-screwdriver-wrench','Repair in progress',  'The technician has taken the job. The next update comes from them.'],
            'in_progress'          => ['fa-screwdriver-wrench','Repair in progress',  'The technician is working on it. The next update comes from them.'],
            'waiting_for_materials'=> ['fa-boxes-stacked',   'Held for materials',    'The technician is waiting on parts. Follow up if this has been sitting.'],
            'for_replacement'      => ['fa-rotate',          'Replacement recommended','The technician judged this beyond repair. Decide whether to replace the unit.'],
            'completed'            => ['fa-clipboard-check', 'Verify and close',      'The technician reported the work finished. Check it, then verify and close — or send it back.'],
            'verified'             => ['fa-circle-check',    'Closed',                'Verified and closed. The reporter has been asked whether the fix held.'],
            'closed'               => ['fa-circle-check',    'Closed',                'This report is finished. Nothing further is required.'],
            'rejected'             => ['fa-ban',             'Rejected',              'This report was rejected and the reporter was told why.'],
          ][strtolower((string)$vr['status'])] ?? ['fa-circle-info', 'In progress', 'This report is somewhere in the workflow below.'];
          $drSettled = in_array(strtolower((string)$vr['status']), ['verified','closed','rejected'], true);
        ?>
        <div class="dr-next<?php echo $drSettled ? ' is-done' : ''; ?>" role="status">
          <i class="fas <?php echo $drNext[0]; ?>" aria-hidden="true"></i>
          <div>
            <b><?php echo esc($drNext[1]); ?></b>
            <span><?php echo esc($drNext[2]); ?></span>
          </div>
        </div>

        <!-- WorkflowTimeline -->
        <section class="dr-card">
          <div class="dr-card-h"><i class="fas fa-timeline" aria-hidden="true"></i><h3>Workflow</h3></div>
          <div class="dr-tl">
            <?php foreach ($drStages as $drI => [$drN, $drIco, $drTs, $drD]):
              $drDone = !empty($drTs) && $drI < $drNow;
              $drIsNow = ($drI === $drNow);
              $drCls = $drRejected && $drIsNow ? 'now' : ($drDone ? 'done' : ($drIsNow ? 'now' : ''));
            ?>
            <div class="dr-tl-step <?php echo $drCls; ?>">
              <div class="dr-tl-dot">
                <i class="fas <?php echo $drDone ? 'fa-check' : $drIco; ?>" aria-hidden="true"></i>
              </div>
              <div class="dr-tl-b">
                <div class="dr-tl-n"><?php echo esc($drN); ?><?php
                  if ($drIsNow) echo '<span class="dr-tl-now-tag">Now</span>'; ?></div>
                <?php if ($w = $drWhen($drTs)): ?>
                <div class="dr-tl-when"><?php echo $w; ?></div>
                <?php endif; ?>
                <div class="dr-tl-d"><?php echo esc($drD); ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </section>

        <!-- Workflow actions — carried through unchanged from the previous
             implementation. Same forms, same POST targets, same validation. -->
        <!-- ── ACTION FORMS ────────────────────────── -->
        <?php if(in_array($vr['status'], ['reported','pmo_review'], true)): ?>
        <!-- REVIEW / ROUTE -->
        <?php
          $rvNew     = ($vr['status'] === 'reported');
          $rvDeptDef = strtoupper(trim((string)($vr['department_assigned'] ?? ''))) ?: $adminUnit;
          $rvPrioDef = strtolower(trim((string)($vr['priority'] ?? '')));
        ?>
        <div class="af af-review" id="approveAf">
          <div class="rv-head">
            <span class="rv-ic"><i class="fas fa-clipboard-check"></i></span>
            <div class="rv-hx">
              <div class="rv-title"><?php echo $rvNew ? 'Acknowledge &amp; Review' : 'Review &amp; Route Report'; ?></div>
              <div class="rv-desc">Confirm the responsible unit and how urgent the repair is, then <strong>approve</strong> to send it for technician assignment — or reject it with a reason.</div>
            </div>
          </div>
          <form method="POST" action="?view_id=<?php echo $vr['report_id'];?>&view=<?php echo $vw;?>" onsubmit="return rvValidate(this);">
            <input type="hidden" name="report_id" value="<?php echo esc($vr['report_id']);?>">
            <div class="fg2">
              <div class="fg">
                <label class="fl">Responsible Unit <span>*</span></label>
                <?php /* Cards, matching the Assign Technicians page, so the two
                         screens ask the same question the same way. Two options
                         behind a dropdown you had to open to see what was in it. */ ?>
                <div class="unit-seg" role="radiogroup" aria-label="Responsible unit">
                  <?php foreach ([
                    ["PMO",  "fa-building",    "PMO",  "Physical maintenance"],
                    ["ITSO", "fa-laptop-code", "ITSO", "IT, labs &amp; network"],
                  ] as [$uv, $uic, $ut, $ud]): ?>
                  <label class="unit-opt unit-<?php echo strtolower($uv); ?>">
                    <input type="radio" name="department_assigned" value="<?php echo $uv; ?>"
                           <?php echo $rvDeptDef === $uv ? "checked" : ""; ?> required>
                    <span class="unit-box">
                      <span class="unit-check" aria-hidden="true"><i class="fas fa-check"></i></span>
                      <i class="fas <?php echo $uic; ?> unit-ic" aria-hidden="true"></i>
                      <span class="unit-t"><?php echo $ut; ?></span>
                      <span class="unit-d"><?php echo $ud; ?></span>
                    </span>
                  </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="fg">
                <label class="fl">Priority <span>*</span></label>
                <div class="prio-seg" role="group" aria-label="Priority level">
                  <button type="button" class="prio-opt" data-v="low">Low</button>
                  <button type="button" class="prio-opt" data-v="medium">Medium</button>
                  <button type="button" class="prio-opt" data-v="high">High</button>
                  <button type="button" class="prio-opt" data-v="critical">Critical</button>
                </div>
                <input type="hidden" name="priority_level" id="prioVal" value="<?php echo esc($rvPrioDef); ?>">
                <div class="fhint">How urgent this repair is.</div>
              </div>
            </div>
            <div class="fg">
              <label class="fl">Admin Notes <span class="opt">optional</span></label>
              <textarea name="admin_notes" class="fc" placeholder="Instructions for the technician, observations, or context…"></textarea>
            </div>
            <div class="rv-actions">
              <?php if($rvNew): ?>
              <button type="submit" name="action" value="mark_received" class="btn btn-sm rv-amber" onclick="return confirm('Confirm Report Receipt\n\nYou are about to acknowledge that the Property Management Office has officially received this maintenance report for evaluation.\n\nThis will:\n• Update the report status to Received by PMO\n• Record the acknowledgement timestamp and your name\n• Notify the reporter by email and in-app\n• Update the tracking timeline and audit log\n\nProceed?');"><i class="fas fa-inbox"></i> Mark as Received</button>
              <?php endif; ?>
              <button type="submit" name="action" value="approve" class="btn btn-green btn-sm"><i class="fas fa-check"></i> <?php echo $rvNew ? 'Approve Directly' : 'Approve'; ?></button>
              <button type="button" class="btn btn-ghost btn-sm rv-rejbtn" onclick="toggleReject()"><i class="fas fa-ban"></i> Reject…</button>
            </div>
          </form>
        </div>
        <!-- REJECT -->
        <div class="af af-reject" id="rejectAf" style="display:none;margin-top:.6rem;">
          <div class="af-title"><i class="fas fa-times-circle"></i> Reject Report</div>
          <form method="POST" action="?view_id=<?php echo $vr['report_id'];?>&view=<?php echo $vw;?>">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="report_id" value="<?php echo esc($vr['report_id']);?>">
            <div class="fg">
              <label class="fl">Rejection Reason <span>*</span></label>
              <textarea name="rejection_reason" class="fc" placeholder="Explain why this report is rejected…" required></textarea>
            </div>
            <div class="af-actions">
              <button type="submit" class="btn btn-red btn-sm"><i class="fas fa-times"></i> Confirm Reject</button>
              <button type="button" class="btn btn-ghost btn-sm" onclick="toggleReject()">Cancel</button>
            </div>
          </form>
        </div>

        <?php elseif($vr['status']==='ready_for_assignment'): ?>
        <div class="af af-approve">
          <div class="af-title"><i class="fas fa-user-plus"></i> Ready for Technician Assignment</div>
          <div class="notes-box" style="margin-bottom:.75rem;">This report has completed the approval flow and is now ready for technician assignment.</div>
          <div class="af-actions">
            <a href="admin_assign_technicians.php?report=<?php echo $vr['report_id'];?>" class="btn btn-maroon btn-sm"><i class="fas fa-user-plus"></i> Assign Technician</a>
          </div>
        </div>

        <?php elseif($vr['status']==='completed'): ?>
        <!-- VERIFY -->
        <div class="af af-verify">
          <div class="af-title"><i class="fas fa-shield-alt"></i> Verify Completion</div>
          <form id="verifyForm" method="POST" action="?view_id=<?php echo $vr['report_id'];?>&view=<?php echo $vw;?>">
            <input type="hidden" name="action" value="verify_completion">
            <input type="hidden" name="report_id" value="<?php echo esc($vr['report_id']);?>">
            <div class="fg">
              <label class="fl">Verification Notes</label>
              <textarea name="verification_notes" class="fc" placeholder="Confirm the repair outcome…"></textarea>
            </div>
            <div class="af-actions">
              <button type="submit" class="btn btn-green btn-sm"><i class="fas fa-check-circle"></i> Verify & Close</button>
              <button type="button" class="btn btn-ghost btn-sm" onclick="retProg()"><i class="fas fa-undo"></i> Return to In Progress</button>
            </div>
          </form>
          <form id="retFrm" method="POST" action="?view_id=<?php echo $vr['report_id'];?>&view=<?php echo $vw;?>" style="display:none;">
            <input type="hidden" name="action" value="return_to_progress">
            <input type="hidden" name="report_id" value="<?php echo esc($vr['report_id']);?>">
          </form>
        </div>
        <?php endif; ?>

      </div><!-- /right col -->
    </div><!-- /dr-body -->

    <!-- ── ModalFooter ─────────────────────────────────────────────── -->

    <div class="mfoot dr-foot">
      <div class="dr-foot-l">
      <a href="defect_report_ticket.php?report=<?php echo urlencode($vr['report_id']);?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm"><i class="fas fa-ticket"></i> Print Ticket</a>
      <?php if (in_array($vr['status'], ['completed','verified','closed'], true)): ?>
      <a href="technician_service_report.php?report=<?php echo urlencode($vr['report_id']);?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm"><i class="fas fa-file-lines"></i> Service Report</a>
      <?php endif; ?>
      </div>
      <div class="dr-foot-r">
        <button class="btn btn-ghost btn-sm" onclick="closeDet()">Close</button>
      <?php if ($vr['status'] === 'completed'): ?>
      <!-- always-visible shortcut; submits the Verify form in the right pane -->
      <button type="submit" form="verifyForm" class="btn btn-green btn-sm"
        onclick="return confirm('Verify this completion and close report <?php echo esc($vr['report_id']); ?>?\n\nThe reporter will be notified that the repair is confirmed.');">
        <i class="fas fa-check-circle"></i> Verify &amp; Close
      </button>
      <?php endif; ?>
      <?php
        $vrAssigned = !empty($vr['technician_name']) && strtolower(trim((string)$vr['technician_name'])) !== 'unassigned';
        /* only statuses assignDefectReportToTechnician() accepts — once work starts
           (or the report is done) assignment is locked */
        if (in_array($vr['status'], ['pmo_review','ready_for_assignment','assigned'], true)):
      ?>
      <a href="admin_assign_technicians.php?report=<?php echo $vr['report_id'];?>" class="btn <?php echo $vrAssigned ? 'btn-ghost' : 'btn-maroon'; ?> btn-sm">
        <i class="fas fa-user-<?php echo $vrAssigned ? 'pen' : 'plus'; ?>"></i> <?php echo $vrAssigned ? 'Reassign Technician' : 'Assign Technician'; ?>
      </a>
      <?php endif; ?>
      </div><!-- /dr-foot-r -->
    </div>
  </div><!-- /dr-shell -->
</div><!-- /detmo -->
<?php endif; ?>

<!-- ════ LOGOUT MODAL ══════════════════════════════════ -->
<div class="mo" id="lgmo" onclick="if(event.target===this)this.classList.remove('open')">
  <div style="background:var(--s1);border-radius:var(--r4);padding:2rem;max-width:330px;width:90%;
    text-align:center;box-shadow:var(--sh3);animation:mUp .25s ease;margin:auto;">
    <i class="fas fa-sign-out-alt" style="font-size:2.2rem;color:var(--m3);margin-bottom:.7rem;display:block;"></i>
    <h3 style="font-family:'Outfit',sans-serif;font-size:1.05rem;font-weight:800;margin-bottom:.38rem;">Log Out?</h3>
    <p style="font-size:.8rem;color:var(--t2);margin-bottom:1.25rem;line-height:1.6;">
      You will be returned to the BEC admin login page.
    </p>
    <div style="display:flex;gap:.55rem;justify-content:center;">
      <button onclick="document.getElementById('lgmo').classList.remove('open')" class="btn btn-ghost btn-sm">Cancel</button>
      <a href="logout.php" class="btn btn-maroon btn-sm"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>
  </div>
</div>

<!-- Lightbox -->
<div class="lb" id="lb" onclick="closeLb()">
  <img id="lbImg" src="" alt="Report photo">
  <!-- Videos get their own element rather than replacing the lightbox markup,
       which would destroy #lbImg and break photo viewing afterwards. -->
  <div id="lbVid"></div>
  <button class="lb-close" onclick="closeLb()"><i class="fas fa-times"></i></button>
</div>

<!-- Hidden delete form -->
<form id="delFrm" method="POST" action="admin_defect_reports.php" style="display:none;">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="report_id" id="delRid">
</form>

<div class="ttray" id="ttray"></div>

<script>
/* ── VIEW TOGGLE ──────────────────────────────────── */
function switchView(v) {
  const url = new URL(location.href);
  url.searchParams.set('view', v);
  location.href = url.toString();
}
/* ── FILTER / SEARCH ──────────────────────────────── */
function go() {
  const url = new URL(location.href);
  url.searchParams.set('status',   document.getElementById('fss').value);
  url.searchParams.set('priority', document.getElementById('fsp').value);
  url.searchParams.set('dept',     document.getElementById('fsd').value);
  url.searchParams.set('kind',     document.getElementById('fsk').value);
  url.searchParams.set('search',   document.getElementById('fsq').value);
  url.searchParams.set('view',     '<?php echo $vw; ?>');
  location.href = url.toString();
}
let dbt; function debounceGo() { clearTimeout(dbt); dbt = setTimeout(go, 500); }

/* ── DETAIL MODAL ─────────────────────────────────── */
/* Opening a report and closing it are both real navigations, so the browser put
   the list back at the top each time — that is the jump when a record opens or
   closes, and why it reads as the page refreshing itself. Remember where the
   list was and restore it, so the page you come back to is the page you left.
   pagehide covers both directions, so neither the row handler nor closeDet has
   to know about any of this. */
(function () {
  const KEY = 'becDrScroll';
  if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
  window.addEventListener('pagehide', function () {
    try { sessionStorage.setItem(KEY, String(window.scrollY || 0)); } catch (_) {}
  });
  window.addEventListener('load', function () {
    let y = null;
    try { y = sessionStorage.getItem(KEY); } catch (_) {}
    if (y === null) { return; }
    // on load, not DOMContentLoaded: the table and its images have settled by
    // then, so the offset we stored still points at the same row
    window.scrollTo(0, parseInt(y, 10) || 0);
  });
})();

/* Video evidence opens in the shared lightbox. #lbImg is hidden rather than
   replaced, because replacing the lightbox markup destroys it and photo viewing
   stops working for the rest of the session. */
function openVidLb(src) {
  const img = document.getElementById('lbImg');
  if (img) { img.style.display = 'none'; }
  const box = document.getElementById('lbVid');
  if (box) {
    box.innerHTML = '<video src="' + String(src).replace(/"/g, '&quot;') +
                    '" controls autoplay playsinline></video>';
  }
  document.getElementById('lb').classList.add('open');
}

function closeDet() {
  const mo = document.getElementById('detmo');
  if (!mo) return;
  mo.style.opacity = '0';
  mo.style.transition = 'opacity .16s';
  setTimeout(() => {
    const url = new URL(location.href);
    url.searchParams.delete('view_id');
    location.href = url.toString();
  }, 150);
}
function toggleReject() {
  const af = document.getElementById('rejectAf');
  const ap = document.getElementById('approveAf');
  const show = af.style.display === 'none';
  af.style.display = show ? 'block' : 'none';
  ap.style.opacity = show ? '.45' : '1';
}
function retProg() { document.getElementById('retFrm')?.submit(); }

/* ── REVIEW PANEL: priority segmented control + guard ─ */
function rvInitPrio() {
  const seg = document.querySelector('.prio-seg');
  if (!seg) return;
  const hidden = document.getElementById('prioVal');
  const paint = (v) => seg.querySelectorAll('.prio-opt').forEach(b => b.classList.toggle('on', b.dataset.v === v));
  seg.querySelectorAll('.prio-opt').forEach(b => {
    b.addEventListener('click', () => { hidden.value = b.dataset.v; paint(b.dataset.v); });
  });
  if (hidden.value) paint(hidden.value);
}
function rvValidate(form) {
  const d = form.querySelector('[name=department_assigned]');
  const p = document.getElementById('prioVal');
  if (d && !d.value) { alert('Please select the responsible unit — PMO or ITSO.'); d.focus(); return false; }
  if (p && !p.value) { alert('Please choose a priority level for this repair.'); return false; }
  return true;
}
document.addEventListener('DOMContentLoaded', rvInitPrio);

/* ── LIGHTBOX ─────────────────────────────────────── */
function openLb(src) {
  document.getElementById('lbVid').innerHTML = '';
  const img = document.getElementById('lbImg');
  img.style.display = '';
  img.src = src;
  document.getElementById('lb').classList.add('open');
}
function closeLb() {
  document.getElementById('lb').classList.remove('open');
  document.getElementById('lbVid').innerHTML = '';   // stops a playing video
}
document.addEventListener('keydown', e => { if(e.key==='Escape') { closeLb(); } });

function setMainPhoto(src, el) {
  const m = document.getElementById('mainRptPhoto');
  if (!m) return;
  m.src = src;
  const wrap = m.closest('.photo-wrap');
  if (wrap) wrap.setAttribute('onclick', "openLb('" + src.replace(/'/g, "\\'") + "')");
  document.querySelectorAll('.photo-thumbs img').forEach(i => i.classList.remove('act'));
  if (el) el.classList.add('act');
}

/* ── REPORT DETAIL MODAL ──────────────────────────────
   A row used to navigate away, losing the filters, the scroll position and the
   view. It now fetches the same record as JSON and shows it in place. If the
   fetch fails for any reason the original full-page URL is still used, so the
   worst case is the behaviour we had before. */
function dEsc(s){
  return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
    ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

document.querySelectorAll('#mainTbl tbody tr.rep-row').forEach(tr => {
  /* Straight to the full record. There was a summary modal in front of this that
     showed a handful of facts and then offered "Open full record & actions" —
     so reading a report took two clicks and the first one showed less than the
     second. The record view already carries everything: the timeline, the photos
     and video, Review & Route with Approve/Reject, Print Ticket and Assign
     Technician. data-view-url carries the current filters, so coming back from
     it lands on the same filtered list. */
  const open = () => { location.href = tr.getAttribute('data-view-url'); };
  tr.addEventListener('click', (e) => {
    if (e.target.closest('a,button,input,select,textarea,label')) return;
    open();
  });
  tr.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
  });
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeLb();   // the lightbox is the only overlay left
});
/* ── DELETE ───────────────────────────────────────── */
function delRep(rid) {
  if (!confirm('Delete report ' + rid + '?\nThis cannot be undone.')) return;
  document.getElementById('delRid').value = rid;
  document.getElementById('delFrm').submit();
}

/* ── EXPORT MENU ──────────────────────────────────── */
function toggleExportMenu(e) {
  e.stopPropagation();
  const m = document.getElementById('exportMenu');
  m.style.display = m.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', () => {
  const m = document.getElementById('exportMenu');
  if (m) m.style.display = 'none';
});

/* ── EXPORT ───────────────────────────────────────────
   Built server-side from the records (see the export branch next to the
   filters). The browser only carries the current filters over. */
function exportUrl(format) {
  const u = new URL(location.href);
  u.searchParams.delete('view_id');
  u.searchParams.set('export', format);
  return u.toString();
}
function exportCSV() {
  window.location.href = exportUrl('csv');
  toast('ok', 'All filtered reports are being exported.', 'CSV Export');
}
function exportExcel() {
  window.location.href = exportUrl('xlsx');
  toast('ok', 'All filtered reports are being exported.', 'Excel Export');
}

function exportPDF() {
  window.open(exportUrl('pdf'), '_blank');
  toast('ok', 'Print view opened in a new tab.', 'PDF Export');
}
/* ── ANIMATED COUNTERS ────────────────────────────── */
function animN(id, to) {
  const el = document.getElementById(id);
  if (!el) return;
  const from = parseInt(el.textContent) || 0;
  const dur = 750, t0 = performance.now();
  const go = now => {
    const p = Math.min((now - t0) / dur, 1), e = 1 - Math.pow(1-p, 3);
    el.textContent = Math.round(from + (to - from) * e);
    if (p < 1) requestAnimationFrame(go);
  };
  requestAnimationFrame(go);
}
document.addEventListener('DOMContentLoaded', () => {
  animN('sn0', <?php echo $c_all; ?>);
  animN('sn1', <?php echo $c_pend; ?>);
  animN('sn2', <?php echo $c_app; ?>);
  animN('sn3', <?php echo $c_prog; ?>);
  animN('sn4', <?php echo $c_done; ?>);
  animN('sn5', <?php echo $c_rej; ?>);
  animN('sn6', <?php echo $c_crit; ?>);
});

/* ── TOAST ────────────────────────────────────────── */
function toast(type, msg, title) {
  const el = document.createElement('div');
  el.className = 'tst ' + type;
  el.innerHTML = `<div><div class="tst-t">${title}</div><div class="tst-m">${msg}</div></div>`;
  document.getElementById('ttray').appendChild(el);
  setTimeout(() => { el.style.transition='opacity .3s'; el.style.opacity='0'; setTimeout(()=>el.remove(),300); }, 4000);
}
</script>
<?php require_once __DIR__ . '/includes/csrf_inject.php'; ?>
<?php
  $exportTechs = function_exists('getAvailableTechnicians') ? (getAvailableTechnicians() ?: []) : [];
  $exportCats = [];
  try {
    $ecRes = getDBConnection()->query("SELECT DISTINCT equipment_category FROM equipment WHERE equipment_category IS NOT NULL AND equipment_category <> '' ORDER BY equipment_category");
    if ($ecRes) { while ($cr = $ecRes->fetch_assoc()) { $exportCats[] = (string)$cr['equipment_category']; } }
  } catch (\Throwable $e) {}
?>
<!-- ══ ADVANCED EXPORT MODAL (#7) ══ -->
<div id="advExportOverlay" style="display:none;position:fixed;inset:0;background:rgba(20,8,8,.5);backdrop-filter:blur(3px);z-index:1500;align-items:center;justify-content:center;padding:1rem;">
  <div style="background:var(--s1);border:1px solid var(--bdr);border-radius:16px;max-width:560px;width:100%;max-height:92vh;overflow:auto;box-shadow:var(--sh3);">
    <div style="background:var(--m3,#7B1D1D);color:#fff;padding:1rem 1.2rem;display:flex;align-items:center;justify-content:space-between;">
      <div><div style="font-weight:700;font-size:1rem;"><i class="fas fa-filter"></i> Advanced Export</div><div style="font-size:.72rem;opacity:.8;">Filter the records, then export only the matches.</div></div>
      <button onclick="closeExportModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:30px;height:30px;border-radius:8px;cursor:pointer;"><i class="fas fa-times"></i></button>
    </div>
    <div style="padding:1.2rem;display:grid;grid-template-columns:1fr 1fr;gap:.85rem;">
      <div><label style="font-size:.7rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;">Date From</label><input type="date" id="exf_from" style="width:100%;padding:.55rem;border:1.5px solid var(--bdr);border-radius:9px;margin-top:.25rem;"></div>
      <div><label style="font-size:.7rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;">Date To</label><input type="date" id="exf_to" style="width:100%;padding:.55rem;border:1.5px solid var(--bdr);border-radius:9px;margin-top:.25rem;"></div>
      <div><label style="font-size:.7rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;">Status</label>
        <select id="exf_status" style="width:100%;padding:.55rem;border:1.5px solid var(--bdr);border-radius:9px;margin-top:.25rem;">
          <option value="">All statuses</option>
          <?php foreach (defectWorkflowStatuses() as $k=>$lbl): ?><option value="<?php echo esc($k); ?>"><?php echo esc($lbl); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label style="font-size:.7rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;">Priority</label>
        <select id="exf_priority" style="width:100%;padding:.55rem;border:1.5px solid var(--bdr);border-radius:9px;margin-top:.25rem;">
          <option value="">All priorities</option><option value="critical">Critical</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option>
        </select>
      </div>
      <div><label style="font-size:.7rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;">Department</label>
        <select id="exf_department" style="width:100%;padding:.55rem;border:1.5px solid var(--bdr);border-radius:9px;margin-top:.25rem;">
          <option value="">All departments</option><option value="ITSO">ITSO</option><option value="PMO">PMO</option>
        </select>
      </div>
      <div><label style="font-size:.7rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;">Technician</label>
        <select id="exf_technician" style="width:100%;padding:.55rem;border:1.5px solid var(--bdr);border-radius:9px;margin-top:.25rem;">
          <option value="">All technicians</option>
          <?php foreach ($exportTechs as $t): $tid=$t['technician_id']??($t['user_id']??''); ?><option value="<?php echo esc($tid); ?>"><?php echo esc($t['fullname']??$tid); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label style="font-size:.7rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;">Category</label>
        <select id="exf_category" style="width:100%;padding:.55rem;border:1.5px solid var(--bdr);border-radius:9px;margin-top:.25rem;">
          <option value="">All categories</option>
          <?php foreach ($exportCats as $cat): ?><option value="<?php echo esc($cat); ?>"><?php echo esc($cat); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label style="font-size:.7rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;">Building / Location</label><input type="text" id="exf_location" placeholder="e.g. ICT Building" style="width:100%;padding:.55rem;border:1.5px solid var(--bdr);border-radius:9px;margin-top:.25rem;"></div>
    </div>
    <div style="padding:0 1.2rem 1.2rem;display:flex;gap:.6rem;flex-wrap:wrap;">
      <button onclick="runAdvancedExport('csv')" class="btn btn-green btn-sm"><i class="fas fa-file-csv"></i> Export CSV</button>
      <button onclick="runAdvancedExport('xlsx')" class="btn btn-green btn-sm"><i class="fas fa-file-excel"></i> Export Excel</button>
      <button onclick="runAdvancedExport('pdf')" class="btn btn-maroon btn-sm"><i class="fas fa-file-pdf"></i> Export PDF</button>
      <button onclick="closeExportModal()" class="btn btn-ghost btn-sm">Cancel</button>
    </div>
  </div>
</div>
<script>
function openExportModal(){ document.getElementById('exportMenu').style.display='none'; document.getElementById('advExportOverlay').style.display='flex'; }
function closeExportModal(){ document.getElementById('advExportOverlay').style.display='none'; }
document.getElementById('advExportOverlay').addEventListener('click', e=>{ if(e.target.id==='advExportOverlay') closeExportModal(); });
function runAdvancedExport(format){
  const p = new URLSearchParams({ type:'defects', format:format });
  const map = { date_from:'exf_from', date_to:'exf_to', status:'exf_status', priority:'exf_priority', department:'exf_department', technician:'exf_technician', category:'exf_category', location:'exf_location' };
  for (const k in map){ const v = (document.getElementById(map[k]).value||'').trim(); if(v) p.append(k, v); }
  window.open('api/export_reports.php?' + p.toString(), '_blank');
  closeExportModal();
}
</script>
<?php /* The client-side paginator that lived here is gone. It rendered every row and
         then hid all but ten, which is what made this page O(backlog); the slice is
         now done in PHP above and the pager is real links. Removing it also removes
         the bug where it would have re-paginated the 25 server-rendered rows into
         three little pages of ten. */ ?>
<script src="assets/sidebar_autohide.js" defer></script>
<script src="assets/search_premium.js"></script>
<script src="assets/select_premium.js"></script>
<script src="assets/date_picker.js"></script>
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<?php require __DIR__ . '/includes/admin_ui.php'; ?>
</body>
</html>
