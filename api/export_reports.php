<?php
// api/export_reports.php
require_once __DIR__ . '/../includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole('admin');

/**
 * Render a clean, branded, print-ready BEC report (opens browser print → Save as PDF).
 */
function renderReportPdf(string $title, array $headers, array $rows, array $summary = [], array $metaExtra = [], ?int $groupBy = null): void {
    require_once __DIR__ . '/../includes/export_branding.php';
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $metaExtra = array_merge($metaExtra, ['Total Records' => number_format(count($rows))]);

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1.0">'
       . '<title>' . $h($title) . ' — BEC PMO</title>'
       . '<link rel="icon" type="image/png" href="../assets/logs.png">'
       . '<style>' . becExportCss() . '</style></head><body>';
    echo becExportToolbar();
    echo becExportHeader($title, $metaExtra);
    if ($summary) { echo becExportSummaryCards($summary); }
    echo '<div class="sec-label">Detailed Records</div>';
    echo '<table class="data-table"><thead><tr>';
    foreach ($headers as $hd) { echo '<th>' . $h($hd) . '</th>'; }
    echo '</tr></thead><tbody>';
    $ncol = count($headers);
    if (!$rows) {
        echo '<tr><td colspan="' . $ncol . '" class="empty">No records found for the selected filters.</td></tr>';
    } elseif ($groupBy !== null) {
        $groups = [];
        foreach ($rows as $r) {
            $key = trim((string)($r[$groupBy] ?? ''));
            if ($key === '' || $key === '—') { $key = 'Unspecified'; }
            $groups[$key][] = $r;
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($groups as $gLabel => $gRows) {
            echo '<tr class="grp"><td colspan="' . $ncol . '">' . $h($gLabel) . ' (' . count($gRows) . ')</td></tr>';
            foreach ($gRows as $r) {
                echo '<tr>';
                foreach ($r as $cell) { echo '<td>' . $h($cell) . '</td>'; }
                echo '</tr>';
            }
        }
    } else {
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($r as $cell) { echo '<td>' . $h($cell) . '</td>'; }
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo becExportSignatures();
    echo becExportFooter();
    echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},400);});</script>';
    echo '</body></html>';
}

$type = $_GET['type'] ?? 'equipment';
$format = $_GET['format'] ?? 'csv';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Advanced export filters (#7)
$f_status     = trim((string)($_GET['status'] ?? ''));
$f_priority   = trim((string)($_GET['priority'] ?? ''));
$f_department = trim((string)($_GET['department'] ?? ''));
$f_technician = trim((string)($_GET['technician'] ?? ''));
$f_category   = trim((string)($_GET['category'] ?? ''));
$f_location   = trim((string)($_GET['location'] ?? ''));

$conn = getDBConnection();

// Export Equipment Usage Report
if ($type === 'equipment') {
    // Get comprehensive equipment data with usage statistics
    $sql = "SELECT e.*, e.equipment_category as category_name,
            (SELECT COUNT(*) FROM defect_reports dr WHERE dr.equipment_id = e.equipment_id) as total_defects,
            (SELECT COUNT(*) FROM defect_reports dr WHERE dr.equipment_id = e.equipment_id AND dr.status = 'completed') as resolved_defects,
            (SELECT COUNT(*) FROM defect_reports dr WHERE dr.equipment_id = e.equipment_id AND dr.status IN ('reported', 'assigned', 'in_progress')) as pending_defects,
            (SELECT COUNT(*) FROM reservations r WHERE r.equipment_id = e.equipment_id) as total_reservations,
            (SELECT COUNT(*) FROM reservations r WHERE r.equipment_id = e.equipment_id AND r.status = 'approved') as approved_reservations,
            (SELECT COUNT(*) FROM reservations r WHERE r.equipment_id = e.equipment_id AND r.status = 'active') as active_reservations,
            (SELECT AVG(DATEDIFF(r.end_date, r.start_date)) FROM reservations r WHERE r.equipment_id = e.equipment_id AND r.status = 'completed') as avg_reservation_duration,
            (SELECT MAX(r.request_date) FROM reservations r WHERE r.equipment_id = e.equipment_id) as last_reservation_date,
            (SELECT MAX(dr.report_date) FROM defect_reports dr WHERE dr.equipment_id = e.equipment_id) as last_defect_date
            FROM equipment e
            WHERE e.status != 'deleted'";

    if ($date_from) {
        $sql .= " AND e.created_at >= '$date_from'";
    }
    if ($date_to) {
        $sql .= " AND e.created_at <= '$date_to'";
    }

    $sql .= " ORDER BY e.equipment_name ASC";

    $result = $conn->query($sql);
    $data = $result->fetch_all(MYSQLI_ASSOC);

    // Calculate summary statistics
    $total_equipment = count($data);
    $total_defects = array_sum(array_column($data, 'total_defects'));
    $total_reservations = array_sum(array_column($data, 'total_reservations'));
    $active_reservations = array_sum(array_column($data, 'active_reservations'));
    $pending_defects = array_sum(array_column($data, 'pending_defects'));

    // Category breakdown
    $category_stats = [];
    foreach ($data as $item) {
        $cat = $item['category_name'] ?: 'Uncategorized';
        if (!isset($category_stats[$cat])) {
            $category_stats[$cat] = ['count' => 0, 'defects' => 0, 'reservations' => 0];
        }
        $category_stats[$cat]['count']++;
        $category_stats[$cat]['defects'] += $item['total_defects'];
        $category_stats[$cat]['reservations'] += $item['total_reservations'];
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="equipment_usage_report_' . date('Y-m-d_H-i-s') . '.csv"');

        $output = fopen('php://output', 'w');

        // Academic Report Header
        fputcsv($output, ['BEC Equipment Management System - Comprehensive Equipment Usage Report']);
        fputcsv($output, ['Generated on: ' . date('F j, Y \a\t g:i A')]);
        fputcsv($output, ['Report Type: Equipment Usage and Maintenance Analysis']);
        fputcsv($output, ['']); // Empty row for spacing

        // Executive Summary Section
        fputcsv($output, ['EXECUTIVE SUMMARY']);
        fputcsv($output, ['Total Equipment Items:', $total_equipment]);
        fputcsv($output, ['Total Defect Reports:', $total_defects]);
        fputcsv($output, ['Total Reservations:', $total_reservations]);
        fputcsv($output, ['Currently Active Reservations:', $active_reservations]);
        fputcsv($output, ['Pending Defect Reports:', $pending_defects]);
        fputcsv($output, ['']); // Empty row for spacing

        // Category Summary Section
        fputcsv($output, ['EQUIPMENT BY CATEGORY']);
        fputcsv($output, ['']); // Empty row
        fputcsv($output, ['Category Name', 'Equipment Count', 'Total Defects', 'Total Reservations']);

        foreach ($category_stats as $category => $stats) {
            fputcsv($output, [$category, $stats['count'], $stats['defects'], $stats['reservations']]);
        }
        fputcsv($output, ['']); // Empty row for spacing

        // Detailed Equipment Data Section
        fputcsv($output, ['DETAILED EQUIPMENT USAGE DATA']);
        fputcsv($output, ['']); // Empty row

        // Enhanced CSV headers
        fputcsv($output, [
            'Equipment ID',
            'Equipment Name',
            'Asset Tag',
            'Category',
            'Location',
            'Current Status',
            'Condition Status',
            'Total Defects',
            'Resolved Defects',
            'Pending Defects',
            'Total Reservations',
            'Approved Reservations',
            'Active Reservations',
            'Avg Reservation Duration (Days)',
            'Last Reservation Date',
            'Last Defect Report Date',
            'Date Added',
            'Last Updated'
        ]);

        // CSV data with enhanced fields
        foreach ($data as $item) {
            fputcsv($output, [
                ($item['equipment_id'] ?? ''),
                $item['equipment_name'],
                ($item['equipment_id'] ?? ''),
                $item['category_name'] ?: 'Uncategorized',
                $item['location'] ?: 'Not specified',
                ucfirst($item['status']),
                ucfirst($item['status']),
                $item['total_defects'],
                $item['resolved_defects'],
                $item['pending_defects'],
                $item['total_reservations'],
                $item['approved_reservations'],
                $item['active_reservations'],
                $item['avg_reservation_duration'] ? round($item['avg_reservation_duration'], 1) : 'N/A',
                $item['last_reservation_date'] ?: 'Never reserved',
                $item['last_defect_date'] ?: 'No defects reported',
                $item['created_at'],
                $item['updated_at']
            ]);
        }

        // Report Footer
        fputcsv($output, ['']); // Empty row
        fputcsv($output, ['Report generated by BEC Equipment Management System']);
        fputcsv($output, ['Confidential - For Administrative Use Only']);
        fputcsv($output, ['End of Equipment Usage Report']);

        fclose($output);
    } elseif ($format === 'pdf') {
        require_once __DIR__ . '/../includes/export_branding.php';
        $hh = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1.0">'
           . '<title>Equipment Usage Report — BEC PMO</title>'
           . '<link rel="icon" type="image/png" href="../assets/logs.png">'
           . '<style>' . becExportCss() . '</style></head><body>';
        echo becExportToolbar();
        echo becExportHeader('Equipment Usage & Maintenance Report', array_filter([
            'Report Period' => ($date_from || $date_to) ? (($date_from ?: 'Beginning') . ' to ' . ($date_to ?: 'Present')) : 'All dates',
            'Total Records' => number_format($total_equipment),
        ]));
        echo becExportSummaryCards([
            'Total Equipment'     => number_format($total_equipment),
            'Total Defects'       => number_format($total_defects),
            'Pending Defects'     => number_format($pending_defects),
            'Total Reservations'  => number_format($total_reservations),
            'Active Reservations' => number_format($active_reservations),
        ]);

        echo '<div class="sec-label">Equipment Distribution by Category</div>';
        echo '<table class="data-table"><thead><tr><th>Category</th><th>Equipment</th><th>Defects</th><th>Reservations</th><th>Defect Rate</th></tr></thead><tbody>';
        if (!$category_stats) {
            echo '<tr><td colspan="5" class="empty">No category data.</td></tr>';
        } else {
            foreach ($category_stats as $category => $stats) {
                $rate = $stats['count'] > 0 ? round(($stats['defects'] / $stats['count']) * 100, 1) : 0;
                echo '<tr><td>' . $hh($category) . '</td><td>' . (int)$stats['count'] . '</td><td>' . (int)$stats['defects']
                   . '</td><td>' . (int)$stats['reservations'] . '</td><td>' . $hh($rate) . '%</td></tr>';
            }
        }
        echo '</tbody></table>';

        echo '<div class="sec-label">Detailed Equipment Usage</div>';
        echo '<table class="data-table"><thead><tr><th>Equipment</th><th>Category</th><th>Location</th><th>Status</th><th>Defects (R/P)</th><th>Reservations</th><th>Last Defect</th></tr></thead><tbody>';
        if (!$data) {
            echo '<tr><td colspan="7" class="empty">No equipment found for the selected filters.</td></tr>';
        } else {
            $eqGroups = [];
            foreach ($data as $item) {
                $loc = trim((string)($item['location'] ?? ''));
                if ($loc === '') { $loc = 'Not specified'; }
                $eqGroups[$loc][] = $item;
            }
            ksort($eqGroups, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($eqGroups as $loc => $items) {
                echo '<tr class="grp"><td colspan="7">' . $hh($loc) . ' (' . count($items) . ')</td></tr>';
                foreach ($items as $item) {
                    echo '<tr>'
                       . '<td><strong>' . $hh($item['equipment_name']) . '</strong><br><span style="color:#9E8070;">ID: ' . $hh(($item['equipment_id'] ?? '')) . '</span></td>'
                       . '<td>' . $hh($item['category_name'] ?: 'Uncategorized') . '</td>'
                       . '<td>' . $hh($item['location'] ?: 'Not specified') . '</td>'
                       . '<td>' . $hh(ucfirst((string)$item['status'])) . '</td>'
                       . '<td>' . (int)$item['total_defects'] . ' (' . (int)$item['resolved_defects'] . '/' . (int)$item['pending_defects'] . ')</td>'
                       . '<td>' . (int)$item['total_reservations'] . '</td>'
                       . '<td>' . $hh($item['last_defect_date'] ?: '—') . '</td>'
                       . '</tr>';
                }
            }
        }
        echo '</tbody></table>';
        echo '<div class="legend"><b>Legend:</b> &nbsp;Defects (R/P) = Resolved / Pending &nbsp;&middot;&nbsp; Defect Rate = total defects &divide; equipment count per category &nbsp;&middot;&nbsp; figures reflect records up to the generation date.</div>';
        echo becExportSignatures();
        echo becExportFooter();
        echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},400);});</script>';
        echo '</body></html>';
    } elseif ($format === 'xlsx') {
        require_once __DIR__ . '/../includes/xlsx_writer.php';
        becRenderBrandedXlsx(
            'Equipment Usage & Maintenance Report',
            ['Equipment','Category','Location','Status','Total Defects','Resolved','Pending','Total Reservations','Active Reservations','Last Defect'],
            array_map(static function ($item) {
                return [
                    $item['equipment_name'] ?? '',
                    ($item['category_name'] ?: 'Uncategorized'),
                    ($item['location'] ?: 'Not specified'),
                    ucfirst((string)($item['status'] ?? '')),
                    (int)($item['total_defects'] ?? 0),
                    (int)($item['resolved_defects'] ?? 0),
                    (int)($item['pending_defects'] ?? 0),
                    (int)($item['total_reservations'] ?? 0),
                    (int)($item['active_reservations'] ?? 0),
                    ($item['last_defect_date'] ?: '—'),
                ];
            }, $data),
            [
                'Total Equipment'     => number_format($total_equipment),
                'Total Defects'       => number_format($total_defects),
                'Pending Defects'     => number_format($pending_defects),
                'Total Reservations'  => number_format($total_reservations),
                'Active Reservations' => number_format($active_reservations),
            ],
            array_filter([
                'Report Period' => ($date_from || $date_to) ? (($date_from ?: 'Beginning') . ' to ' . ($date_to ?: 'Present')) : 'All dates',
            ]),
            'equipment_usage',
            2
        );
    }
}

// Export Defect Reports
elseif ($type === 'defects') {
    // Get comprehensive defect report data with additional metrics
    $sql = "SELECT dr.*, e.equipment_name, e.asset_tag as asset_tag, e.location, e.equipment_category as category_name,
            u.fullname as reporter_name, u.role as reporter_role,
            mt.fullname as technician_name, mt.specialization,
            CASE WHEN dr.assigned_to IS NOT NULL THEN DATEDIFF(dr.assigned_date, dr.report_date) ELSE NULL END as assignment_delay_days,
            CASE WHEN dr.status = 'completed' THEN DATEDIFF(dr.completion_date, dr.assigned_date) ELSE NULL END as resolution_time_days,
            CASE WHEN dr.status = 'completed' THEN DATEDIFF(dr.completion_date, dr.report_date) ELSE NULL END as total_resolution_days
            FROM defect_reports dr
            JOIN equipment e ON dr.equipment_id = e.equipment_id
            LEFT JOIN users u ON dr.reported_by = u.user_id
            LEFT JOIN maintenance_technicians mt ON dr.assigned_to = mt.technician_id
            WHERE dr.status != 'deleted'";

    if ($date_from) {
        $sql .= " AND dr.report_date >= '$date_from'";
    }
    if ($date_to) {
        $sql .= " AND dr.report_date <= '$date_to'";
    }
    if ($f_status !== '')     { $sql .= " AND dr.status = '" . $conn->real_escape_string($f_status) . "'"; }
    if ($f_priority !== '')   { $sql .= " AND dr.priority = '" . $conn->real_escape_string($f_priority) . "'"; }
    if ($f_department !== '') { $sql .= " AND dr.department_assigned = '" . $conn->real_escape_string($f_department) . "'"; }
    if ($f_technician !== '') { $sql .= " AND dr.assigned_to = '" . $conn->real_escape_string($f_technician) . "'"; }
    if ($f_category !== '')   { $sql .= " AND e.equipment_category = '" . $conn->real_escape_string($f_category) . "'"; }
    if ($f_location !== '')   { $sql .= " AND (e.location ILIKE '%" . $conn->real_escape_string($f_location) . "%' OR dr.location ILIKE '%" . $conn->real_escape_string($f_location) . "%')"; }

    $sql .= " ORDER BY dr.report_date DESC";

    $result = $conn->query($sql);
    $data = $result->fetch_all(MYSQLI_ASSOC);

    // Calculate summary statistics
    $total_reports = count($data);
    $status_counts = ['reported' => 0, 'assigned' => 0, 'in_progress' => 0, 'completed' => 0, 'verified' => 0, 'closed' => 0];
    $priority_counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
    $avg_resolution_time = 0;
    $completed_count = 0;

    foreach ($data as $report) {
        $status = $report['status'] ?? 'reported';
        $priority = $report['priority'] ?? 'medium';
        if (isset($status_counts[$status])) $status_counts[$status]++;
        if (isset($priority_counts[$priority])) $priority_counts[$priority]++;
        if ($report['status'] === 'completed' && $report['total_resolution_days']) {
            $avg_resolution_time += $report['total_resolution_days'];
            $completed_count++;
        }
    }

    $avg_resolution_time = $completed_count > 0 ? round($avg_resolution_time / $completed_count, 1) : 0;

    // Category breakdown
    $category_defects = [];
    foreach ($data as $report) {
        $cat = $report['category_name'] ?: 'Uncategorized';
        if (!isset($category_defects[$cat])) {
            $category_defects[$cat] = 0;
        }
        $category_defects[$cat]++;
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="comprehensive_defect_reports_' . date('Y-m-d_H-i-s') . '.csv"');

        $output = fopen('php://output', 'w');

        // Academic Report Header
        fputcsv($output, ['BEC Equipment Management System - Comprehensive Defect Reports Analysis']);
        fputcsv($output, ['Generated on: ' . date('F j, Y \a\t g:i A')]);
        fputcsv($output, ['Report Type: Maintenance and Defect Tracking Analysis']);
        fputcsv($output, ['']); // Empty row for spacing

        // Executive Summary Section
        fputcsv($output, ['EXECUTIVE SUMMARY']);
        fputcsv($output, ['Total Defect Reports:', $total_reports]);
        fputcsv($output, ['Completed Reports:', $status_counts['completed']]);
        fputcsv($output, ['Pending Reports:', $status_counts['reported'] + $status_counts['assigned'] + $status_counts['in_progress']]);
        fputcsv($output, ['Average Resolution Time (Days):', $avg_resolution_time]);
        fputcsv($output, ['Critical Priority Reports:', $priority_counts['critical']]);
        fputcsv($output, ['']); // Empty row for spacing

        // Status Distribution
        fputcsv($output, ['DEFECT STATUS DISTRIBUTION']);
        fputcsv($output, ['Reported:', $status_counts['reported']]);
        fputcsv($output, ['Assigned:', $status_counts['assigned']]);
        fputcsv($output, ['In Progress:', $status_counts['in_progress']]);
        fputcsv($output, ['Completed:', $status_counts['completed']]);
        fputcsv($output, ['Verified:', $status_counts['verified']]);
        fputcsv($output, ['Closed:', $status_counts['closed']]);
        fputcsv($output, ['']); // Empty row for spacing

        // Priority Distribution
        fputcsv($output, ['PRIORITY DISTRIBUTION']);
        fputcsv($output, ['Critical:', $priority_counts['critical']]);
        fputcsv($output, ['High:', $priority_counts['high']]);
        fputcsv($output, ['Medium:', $priority_counts['medium']]);
        fputcsv($output, ['Low:', $priority_counts['low']]);
        fputcsv($output, ['']); // Empty row for spacing

        // Category Breakdown
        fputcsv($output, ['DEFECTS BY EQUIPMENT CATEGORY']);
        fputcsv($output, ['']); // Empty row
        fputcsv($output, ['Category Name', 'Defect Count']);

        foreach ($category_defects as $category => $count) {
            fputcsv($output, [$category, $count]);
        }
        fputcsv($output, ['']); // Empty row for spacing

        // Detailed Defect Data Section
        fputcsv($output, ['DETAILED DEFECT REPORTS DATA']);
        fputcsv($output, ['']); // Empty row

        // Enhanced CSV headers
        fputcsv($output, [
            'Report ID',
            'Equipment Name',
            'Asset Tag',
            'Equipment Category',
            'Equipment Location',
            'Issue Description',
            'Priority Level',
            'Current Status',
            'Reporter Name',
            'Reporter Role',
            'Assigned Technician',
            'Technician Specialization',
            'Report Date',
            'Assigned Date',
            'Assignment Delay (Days)',
            'Completion Date',
            'Resolution Time (Days)',
            'Total Resolution Days',
            'Work Details',
            'Completion Notes'
        ]);

        // CSV data with enhanced fields
        foreach ($data as $report) {
            fputcsv($output, [
                $report['report_id'],
                $report['equipment_name'],
                $report['asset_tag'],
                $report['category_name'] ?: 'Uncategorized',
                $report['location'] ?: 'Not specified',
                $report['issue_description'],
                ucfirst($report['priority'] ?? 'medium'),
                ucfirst($report['status'] ?? 'reported'),
                $report['reporter_name'] ?? 'Unknown',
                $report['reporter_role'] ?? 'Unknown',
                $report['technician_name'] ?? 'Unassigned',
                $report['specialization'] ?? 'N/A',
                $report['report_date'],
                $report['assigned_date'] ?? 'Not assigned',
                $report['assignment_delay_days'] ?? 'N/A',
                $report['completion_date'] ?? 'Not completed',
                $report['resolution_time_days'] ?? 'N/A',
                $report['total_resolution_days'] ?? 'N/A',
                $report['work_details'] ?? 'No details provided',
                $report['completion_notes'] ?? 'No notes'
            ]);
        }

        // Report Footer
        fputcsv($output, ['']); // Empty row
        fputcsv($output, ['Report generated by BEC Equipment Management System']);
        fputcsv($output, ['Confidential - For Administrative Use Only']);
        fputcsv($output, ['End of Comprehensive Defect Reports']);

        fclose($output);
    } elseif ($format === 'pdf') {
        renderReportPdf(
            'Defect Reports',
            ['Ticket','Equipment','Location','Priority','Status','Reporter','Technician','Reported','Completed'],
            array_map(static function ($r) {
                return [
                    $r['report_id'] ?? '',
                    $r['equipment_name'] ?? '',
                    $r['location'] ?? '',
                    ucfirst((string)($r['priority'] ?? '')),
                    ucwords(str_replace('_', ' ', (string)($r['status'] ?? ''))),
                    $r['reporter_name'] ?? '—',
                    $r['technician_name'] ?? '—',
                    !empty($r['report_date']) ? date('Y-m-d', strtotime((string)$r['report_date'])) : '—',
                    !empty($r['completion_date']) ? date('Y-m-d', strtotime((string)$r['completion_date'])) : '—',
                ];
            }, $data),
            [
                'Total Reports'  => number_format($total_reports),
                'Completed'      => number_format($status_counts['completed']),
                'In Progress'    => number_format($status_counts['in_progress']),
                'Pending'        => number_format($status_counts['reported'] + $status_counts['assigned']),
                'Critical'       => number_format($priority_counts['critical']),
                'Avg Resolution' => $avg_resolution_time . ' day(s)',
            ],
            array_filter([
                'Report Period'   => ($date_from || $date_to) ? (($date_from ?: 'Beginning') . ' to ' . ($date_to ?: 'Present')) : 'All dates',
                'Status Filter'   => $f_status !== '' ? ucwords(str_replace('_', ' ', $f_status)) : '',
                'Priority Filter' => $f_priority !== '' ? ucfirst($f_priority) : '',
                'Category Filter' => $f_category,
                'Location Filter' => $f_location,
            ]),
            2
        );
    } elseif ($format === 'xlsx') {
        require_once __DIR__ . '/../includes/xlsx_writer.php';
        becRenderBrandedXlsx(
            'Defect Reports',
            ['Ticket','Equipment','Location','Priority','Status','Reporter','Technician','Reported','Completed'],
            array_map(static function ($r) {
                return [
                    $r['report_id'] ?? '',
                    $r['equipment_name'] ?? '',
                    $r['location'] ?? '',
                    ucfirst((string)($r['priority'] ?? '')),
                    ucwords(str_replace('_', ' ', (string)($r['status'] ?? ''))),
                    $r['reporter_name'] ?? '—',
                    $r['technician_name'] ?? '—',
                    !empty($r['report_date']) ? date('Y-m-d', strtotime((string)$r['report_date'])) : '—',
                    !empty($r['completion_date']) ? date('Y-m-d', strtotime((string)$r['completion_date'])) : '—',
                ];
            }, $data),
            [
                'Total Reports'  => number_format($total_reports),
                'Completed'      => number_format($status_counts['completed']),
                'In Progress'    => number_format($status_counts['in_progress']),
                'Pending'        => number_format($status_counts['reported'] + $status_counts['assigned']),
                'Critical'       => number_format($priority_counts['critical']),
                'Avg Resolution' => $avg_resolution_time . ' day(s)',
            ],
            array_filter([
                'Report Period'   => ($date_from || $date_to) ? (($date_from ?: 'Beginning') . ' to ' . ($date_to ?: 'Present')) : 'All dates',
                'Status Filter'   => $f_status !== '' ? ucwords(str_replace('_', ' ', $f_status)) : '',
                'Priority Filter' => $f_priority !== '' ? ucfirst($f_priority) : '',
            ]),
            'defect_reports',
            2
        );
    }
}

// Export Reservations
elseif ($type === 'reservations') {
    $sql = "SELECT r.reservation_id, e.equipment_name, e.asset_tag as asset_tag,
            u.fullname as requester, r.purpose, r.start_date, r.end_date,
            r.status, r.request_date
            FROM reservations r
            JOIN equipment e ON r.equipment_id = e.equipment_id
            LEFT JOIN users u ON r.user_id = u.user_id
            WHERE 1=1";
    
    if ($date_from) {
        $sql .= " AND r.start_date >= '$date_from'";
    }
    if ($date_to) {
        $sql .= " AND r.end_date <= '$date_to'";
    }
    
    $sql .= " ORDER BY r.request_date DESC";
    
    $result = $conn->query($sql);
    $data = $result->fetch_all(MYSQLI_ASSOC);
    
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="reservations_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Reservation ID', 'Equipment', 'Asset Tag', 'Requester', 'Purpose', 'Start Date', 'End Date', 'Status', 'Request Date']);
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['reservation_id'],
                $row['equipment_name'],
                $row['asset_tag'],
                $row['requester'] ?? 'Guest',
                $row['purpose'],
                $row['start_date'],
                $row['end_date'],
                $row['status'],
                $row['request_date']
            ]);
        }
        
        fclose($output);
    } elseif ($format === 'pdf') {
        $resv_count = static function (array $d, $statuses) {
            $statuses = (array)$statuses;
            return count(array_filter($d, static fn($r) => in_array((string)($r['status'] ?? ''), $statuses, true)));
        };
        renderReportPdf(
            'Equipment Reservations',
            ['Reservation','Equipment','Asset Tag','Requester','Purpose','Start','End','Status'],
            array_map(static function ($r) {
                return [
                    $r['reservation_id'] ?? '',
                    $r['equipment_name'] ?? '',
                    $r['asset_tag'] ?? '',
                    $r['requester'] ?? 'Guest',
                    $r['purpose'] ?? '',
                    !empty($r['start_date']) ? date('Y-m-d', strtotime((string)$r['start_date'])) : '—',
                    !empty($r['end_date']) ? date('Y-m-d', strtotime((string)$r['end_date'])) : '—',
                    ucwords(str_replace('_', ' ', (string)($r['status'] ?? ''))),
                ];
            }, $data),
            [
                'Total Reservations' => number_format(count($data)),
                'Approved'           => number_format($resv_count($data, 'approved')),
                'Active'             => number_format($resv_count($data, 'active')),
                'Completed'          => number_format($resv_count($data, 'completed')),
                'Pending'            => number_format($resv_count($data, ['pending', 'requested'])),
            ],
            array_filter([
                'Report Period' => ($date_from || $date_to) ? (($date_from ?: 'Beginning') . ' to ' . ($date_to ?: 'Present')) : 'All dates',
            ]),
            7
        );
    } elseif ($format === 'xlsx') {
        require_once __DIR__ . '/../includes/xlsx_writer.php';
        $resv_count = static function (array $d, $statuses) {
            $statuses = (array)$statuses;
            return count(array_filter($d, static fn($r) => in_array((string)($r['status'] ?? ''), $statuses, true)));
        };
        becRenderBrandedXlsx(
            'Equipment Reservations',
            ['Reservation','Equipment','Asset Tag','Requester','Purpose','Start','End','Status'],
            array_map(static function ($r) {
                return [
                    $r['reservation_id'] ?? '',
                    $r['equipment_name'] ?? '',
                    $r['asset_tag'] ?? '',
                    $r['requester'] ?? 'Guest',
                    $r['purpose'] ?? '',
                    !empty($r['start_date']) ? date('Y-m-d', strtotime((string)$r['start_date'])) : '—',
                    !empty($r['end_date']) ? date('Y-m-d', strtotime((string)$r['end_date'])) : '—',
                    ucwords(str_replace('_', ' ', (string)($r['status'] ?? ''))),
                ];
            }, $data),
            [
                'Total Reservations' => number_format(count($data)),
                'Approved'           => number_format($resv_count($data, 'approved')),
                'Active'             => number_format($resv_count($data, 'active')),
                'Completed'          => number_format($resv_count($data, 'completed')),
            ],
            array_filter([
                'Report Period' => ($date_from || $date_to) ? (($date_from ?: 'Beginning') . ' to ' . ($date_to ?: 'Present')) : 'All dates',
            ]),
            'reservations',
            7
        );
    }
}

$conn->close();
?>
