<?php
/**
 * scripts/weekly_summary.php — automated weekly PMO operations digest.
 *
 * Emails every active admin/PMO a branded summary of the past 7 days:
 * new vs resolved reports, current backlog, overdue cases, priority mix, and a
 * technician leaderboard. Reuses the mail pipeline; safe to run repeatedly.
 *
 * Run manually:      c:\xampp\php\php.exe scripts\weekly_summary.php [recipient@email]
 * Scheduled (weekly): Windows Task Scheduler task "BEC PMO Weekly Summary" (Mondays 07:00).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/sla.php';        // becSlaSqlCase()
require_once __DIR__ . '/../includes/mail_helper.php';

$pdo = getPgsqlPdoConnection();
$scalar = function (string $sql) use ($pdo): int {
    try { return (int) $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
};
$rows = function (string $sql) use ($pdo): array {
    try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) { return []; }
};

$resolved = "status IN ('completed','verified','closed')";
$open     = "status NOT IN ('completed','verified','closed','rejected')";

$stats = [
    'new_week'       => $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE report_date >= NOW() - INTERVAL '7 days'"),
    'resolved_week'  => $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE $resolved AND COALESCE(completion_date, report_date) >= NOW() - INTERVAL '7 days'"),
    'open_total'     => $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE $open"),
    'pending_review' => $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE status IN ('reported','pmo_review')"),
    'in_progress'    => $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE status IN ('assigned','accepted','in_progress','waiting_for_materials','for_replacement')"),
    'overdue'        => $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE $open AND report_date < NOW() - " . becSlaSqlCase('priority')),
    'critical_open'  => $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE $open AND LOWER(COALESCE(priority,''))='critical'"),
    'high_open'      => $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE $open AND LOWER(COALESCE(priority,''))='high'"),
];

// Technician leaderboard for the week (completed count, current open load).
$leaders = $rows(
    "SELECT COALESCE(NULLIF(u.fullname,''), dr.assigned_to) AS name,
            COUNT(*) FILTER (WHERE dr.status IN ('completed','verified','closed')
                             AND COALESCE(dr.completion_date, dr.report_date) >= NOW() - INTERVAL '7 days') AS done_week,
            COUNT(*) FILTER (WHERE dr.status IN ('assigned','accepted','in_progress','waiting_for_materials','for_replacement')) AS open_now
     FROM public.defect_reports dr
     LEFT JOIN public.users u ON u.user_id = dr.assigned_to
     WHERE dr.assigned_to IS NOT NULL AND dr.assigned_to <> ''
     GROUP BY 1 ORDER BY done_week DESC, open_now DESC LIMIT 6"
);

$topEquip = $rows("SELECT COALESCE(NULLIF(equipment_name,''),'Unknown') AS label, COUNT(*) AS n
                   FROM public.defect_reports WHERE report_date >= NOW() - INTERVAL '7 days' GROUP BY 1 ORDER BY n DESC LIMIT 3");

// ── Build the branded HTML ────────────────────────────────────────────────
$M = '#7B1D1D'; $MD = '#4A0E0E'; $G = '#C9960C';
$he = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$periodEnd = date('M j, Y'); $periodStart = date('M j, Y', strtotime('-7 days'));

$stat = function (string $label, $value, string $tone = '') use ($M, $he) {
    $color = $tone === 'bad' ? '#B42318' : ($tone === 'good' ? '#1A7A33' : $M);
    return '<td style="padding:6px;"><div style="border:1px solid #E8DDD0;border-radius:12px;padding:14px 12px;text-align:center;background:#fff;">'
         . '<div style="font-family:Georgia,serif;font-size:26px;font-weight:700;color:' . $color . ';">' . (int)$value . '</div>'
         . '<div style="font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#8A7466;margin-top:2px;">' . $he($label) . '</div></div></td>';
};

$leaderRows = '';
foreach ($leaders as $i => $l) {
    $crown = $i === 0 && (int)$l['done_week'] > 0 ? ' 👑' : '';
    $leaderRows .= '<tr>'
        . '<td style="padding:8px 10px;border-bottom:1px solid #F0E9DF;font-size:13px;color:#1C1008;">' . $he($l['name']) . $crown . '</td>'
        . '<td style="padding:8px 10px;border-bottom:1px solid #F0E9DF;text-align:center;font-weight:700;color:#1A7A33;">' . (int)$l['done_week'] . '</td>'
        . '<td style="padding:8px 10px;border-bottom:1px solid #F0E9DF;text-align:center;color:#5C3838;">' . (int)$l['open_now'] . '</td>'
        . '</tr>';
}
if ($leaderRows === '') { $leaderRows = '<tr><td colspan="3" style="padding:12px;text-align:center;color:#8A7466;font-size:13px;">No technician activity yet.</td></tr>'; }

$equipList = '';
foreach ($topEquip as $e) { $equipList .= '<li style="margin:2px 0;">' . $he($e['label']) . ' <span style="color:#8A7466;">(' . (int)$e['n'] . ')</span></li>'; }
if ($equipList === '') { $equipList = '<li style="color:#8A7466;">No reports this week.</li>'; }

$html = '<div style="font-family:Segoe UI,Arial,sans-serif;background:#F4F1EC;padding:24px;">'
  . '<div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #E8DDD0;border-radius:14px;overflow:hidden;">'
    . '<div style="background:linear-gradient(135deg,' . $MD . ',' . $M . ');color:#fff;padding:22px 24px;">'
      . '<div style="font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:' . $G . ';">Batangas Eastern Colleges · Property Management Office</div>'
      . '<div style="font-size:20px;font-weight:700;margin-top:4px;">Weekly Operations Summary</div>'
      . '<div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;">' . $he($periodStart) . ' – ' . $he($periodEnd) . '</div>'
    . '</div>'
    . '<div style="padding:20px 22px;">'
      . '<table width="100%" cellspacing="0" cellpadding="0"><tr>'
        . $stat('New this week', $stats['new_week'])
        . $stat('Resolved this week', $stats['resolved_week'], 'good')
        . $stat('Open backlog', $stats['open_total'])
      . '</tr><tr>'
        . $stat('Pending review', $stats['pending_review'])
        . $stat('In progress', $stats['in_progress'])
        . $stat('Overdue', $stats['overdue'], $stats['overdue'] > 0 ? 'bad' : '')
      . '</tr></table>'
      . ($stats['critical_open'] + $stats['high_open'] > 0
          ? '<div style="margin-top:14px;padding:12px 14px;border-radius:10px;background:#FDECEC;border:1px solid #F3C0C0;color:#8A1C1C;font-size:13px;">'
            . '<strong>Needs attention:</strong> ' . (int)$stats['critical_open'] . ' critical and ' . (int)$stats['high_open'] . ' high-priority case(s) still open.</div>'
          : '')
      . '<h3 style="font-size:14px;color:' . $M . ';margin:22px 0 8px;">Technician leaderboard (this week)</h3>'
      . '<table width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #E8DDD0;border-radius:10px;overflow:hidden;">'
        . '<tr style="background:#FBF9F6;"><th align="left" style="padding:8px 10px;font-size:11px;text-transform:uppercase;color:#8A7466;">Technician</th>'
        . '<th style="padding:8px 10px;font-size:11px;text-transform:uppercase;color:#8A7466;">Done</th>'
        . '<th style="padding:8px 10px;font-size:11px;text-transform:uppercase;color:#8A7466;">Open now</th></tr>'
        . $leaderRows
      . '</table>'
      . '<h3 style="font-size:14px;color:' . $M . ';margin:22px 0 6px;">Most-reported equipment this week</h3>'
      . '<ul style="margin:0;padding-left:18px;font-size:13px;color:#1C1008;">' . $equipList . '</ul>'
      . '<div style="text-align:center;margin-top:22px;">'
        . '<a href="' . $he(rtrim((string)dbEnv('APP_BASE_URL', 'http://localhost/bec-pmo'), '/')) . '/admin_dashboard.php" '
        . 'style="display:inline-block;background:linear-gradient(135deg,' . $MD . ',' . $M . ');color:#fff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 26px;border-radius:10px;">Open the PMO Dashboard</a>'
      . '</div>'
    . '</div>'
    . '<div style="background:#F8F3EA;padding:14px 24px;font-size:11px;color:#9E8070;text-align:center;border-top:1px solid #E8DDD0;">'
      . 'Automated weekly summary · Batangas Eastern Colleges · Property Management Office</div>'
  . '</div></div>';

// ── Recipients: CLI arg overrides; otherwise all active admin/pmo accounts ──
$subject = 'Weekly PMO Summary — ' . $periodStart . ' to ' . $periodEnd;
$recipients = [];
if (PHP_SAPI === 'cli' && !empty($argv[1])) {
    $recipients[] = $argv[1];
} else {
    foreach ($rows("SELECT DISTINCT email FROM public.users WHERE role IN ('admin','pmo') AND COALESCE(status,'active')='active' AND email <> ''") as $r) {
        if (filter_var($r['email'], FILTER_VALIDATE_EMAIL)) { $recipients[] = $r['email']; }
    }
}
if (!$recipients) { fwrite(STDERR, "weekly summary: no recipients found\n"); exit(1); }

$sent = 0;
foreach (array_unique($recipients) as $to) {
    try { if (sendEmail($to, $subject, $html, null, 'admin')) { $sent++; } }
    catch (Throwable $e) { error_log('weekly summary send failed for ' . $to . ': ' . $e->getMessage()); }
}
echo "weekly summary: emailed {$sent}/" . count(array_unique($recipients)) . " recipient(s) — "
   . "{$stats['new_week']} new, {$stats['resolved_week']} resolved, {$stats['open_total']} open, {$stats['overdue']} overdue.\n";
