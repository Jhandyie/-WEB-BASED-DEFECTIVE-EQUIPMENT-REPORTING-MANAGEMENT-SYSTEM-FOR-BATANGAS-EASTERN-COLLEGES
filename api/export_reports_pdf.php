<?php
/**
 * api/export_reports_pdf.php
 * Branded, print-ready defect-reports document for admins. Opens the browser
 * print dialog so the report can be saved as PDF (no external PDF library
 * required). Supports optional ?status=, ?date_from=, ?date_to= filters and
 * ?auto=0 to suppress auto-print.
 */
require_once __DIR__ . '/../includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole('admin');

$status    = trim((string)($_GET['status'] ?? ''));
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to   = trim((string)($_GET['date_to'] ?? ''));
$auto      = ($_GET['auto'] ?? '1') !== '0';

$conn = getDBConnection();

$where  = ['1=1'];
$params = [];
$types  = '';
if ($status !== '') { $where[] = 'dr.status = ?';      $params[] = $status;    $types .= 's'; }
if ($date_from !== '') { $where[] = 'dr.report_date >= ?'; $params[] = $date_from; $types .= 's'; }
if ($date_to !== '')   { $where[] = 'dr.report_date <= ?'; $params[] = $date_to . ' 23:59:59'; $types .= 's'; }

$sql = "SELECT dr.report_id, dr.status, dr.priority,
               COALESCE(dr.equipment_name, e.equipment_name) AS equipment_name,
               dr.location,
               COALESCE(dr.issue_description, dr.defect_description, dr.notes) AS description,
               ru.fullname AS reporter_name,
               tu.fullname AS technician_name,
               dr.report_date, dr.completion_date
        FROM defect_reports dr
        LEFT JOIN equipment e ON e.equipment_id = dr.equipment_id
        LEFT JOIN users ru ON ru.user_id = dr.reported_by
        LEFT JOIN users tu ON tu.user_id = dr.assigned_to
        WHERE " . implode(' AND ', $where) . "
        ORDER BY dr.report_date DESC";

$rows = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) { $rows = $res->fetch_all(MYSQLI_ASSOC); }
}

// Summary counts.
$total = count($rows);
$by_status = [];
foreach ($rows as $r) {
    $s = (string)($r['status'] ?? 'unknown');
    $by_status[$s] = ($by_status[$s] ?? 0) + 1;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function statusLabel($s) { return ucwords(str_replace('_', ' ', (string)$s)); }

require_once __DIR__ . '/../includes/export_branding.php';

$metaExtra = array_filter([
    'Report Period' => ($date_from !== '' || $date_to !== '') ? (($date_from ?: 'Beginning') . ' to ' . ($date_to ?: 'Present')) : 'All dates',
    'Status Filter' => $status !== '' ? statusLabel($status) : '',
    'Total Records' => number_format($total),
]);

$summary = ['Total Reports' => number_format($total)];
foreach ($by_status as $s => $c) { $summary[statusLabel($s)] = number_format($c); }

header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Defect Reports — BEC PMO</title>
<link rel="icon" type="image/png" href="../assets/logs.png">
<style><?php echo becExportCss(); ?></style>
</head>
<body>
<?php echo becExportToolbar(); ?>
<?php echo becExportHeader('Defect Reports', $metaExtra); ?>
<?php echo becExportSummaryCards($summary); ?>

<div class="sec-label">Detailed Records</div>
<table class="data-table">
  <thead>
    <tr>
      <th>Ticket</th><th>Equipment</th><th>Location</th><th>Priority</th>
      <th>Status</th><th>Reporter</th><th>Technician</th><th>Reported</th><th>Completed</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="9" class="empty">No reports found for the selected filters.</td></tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td><?php echo h($r['report_id']); ?></td>
        <td><?php echo h($r['equipment_name'] ?: '—'); ?></td>
        <td><?php echo h($r['location'] ?: '—'); ?></td>
        <td><?php echo h(ucfirst((string)$r['priority'])); ?></td>
        <td><?php echo h(statusLabel($r['status'])); ?></td>
        <td><?php echo h($r['reporter_name'] ?: '—'); ?></td>
        <td><?php echo h($r['technician_name'] ?: '—'); ?></td>
        <td><?php echo h($r['report_date'] ? date('Y-m-d', strtotime($r['report_date'])) : '—'); ?></td>
        <td><?php echo h($r['completion_date'] ? date('Y-m-d', strtotime($r['completion_date'])) : '—'); ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<div class="legend"><b>Status flow:</b> Reported &rarr; Assigned &rarr; In Progress &rarr; Completed &rarr; Verified / Closed. Dates shown are report and completion dates.</div>

<?php echo becExportSignatures(); ?>
<?php echo becExportFooter(); ?>

<?php if ($auto): ?>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 400); });</script>
<?php endif; ?>
</body>
</html>
