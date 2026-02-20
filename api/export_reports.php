<?php
// api/export_reports.php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';

requireRole('admin');

$type = $_GET['type'] ?? 'equipment';
$format = $_GET['format'] ?? 'csv';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$conn = getDBConnection();

// Export Equipment Usage Report
if ($type === 'equipment') {
    // Get comprehensive equipment data with usage statistics
    $sql = "SELECT e.*, c.category_name,
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
            LEFT JOIN categories c ON e.category_id = c.category_id
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
                $item['equipment_id'],
                $item['equipment_name'],
                $item['asset_tag'],
                $item['category_name'] ?: 'Uncategorized',
                $item['location'] ?: 'Not specified',
                ucfirst($item['status']),
                ucfirst($item['condition_status']),
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
        // Enhanced PDF/HTML version
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Comprehensive Equipment Usage Report</title>
            <style>
                body { font-family: 'Times New Roman', serif; margin: 30px; line-height: 1.6; }
                h1 { color: #800000; text-align: center; border-bottom: 3px solid #800000; padding-bottom: 10px; }
                h2 { color: #800000; margin-top: 30px; border-bottom: 2px solid #800000; padding-bottom: 5px; }
                .header { text-align: center; margin-bottom: 40px; }
                .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0; }
                .summary-item { background: #f8f9fa; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #dee2e6; }
                .summary-value { font-size: 24px; font-weight: bold; color: #800000; }
                .summary-label { font-size: 14px; color: #666; margin-top: 5px; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 12px; }
                th { background: #800000; color: white; padding: 12px; text-align: left; font-weight: bold; }
                td { border: 1px solid #ddd; padding: 10px; }
                tr:nth-child(even) { background: #f9f9f9; }
                .category-table { margin-top: 20px; }
                .footer { margin-top: 50px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #ddd; padding-top: 20px; }
                @media print { .no-print { display: none; } body { margin: 15px; } }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Comprehensive Equipment Usage Report</h1>
                <p><strong>Batangas Eastern Colleges</strong></p>
                <p>Equipment Management System Analysis</p>
                <p><em>Generated: <?php echo date('F j, Y \a\t g:i A'); ?></em></p>
                <?php if ($date_from || $date_to): ?>
                <p><strong>Report Period:</strong> <?php echo $date_from ? date('M j, Y', strtotime($date_from)) : 'Beginning'; ?> to <?php echo $date_to ? date('M j, Y', strtotime($date_to)) : 'Present'; ?></p>
                <?php endif; ?>
            </div>

            <h2>Executive Summary</h2>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-value"><?php echo number_format($total_equipment); ?></div>
                    <div class="summary-label">Total Equipment Items</div>
                </div>
                <div class="summary-item">
                    <div class="summary-value"><?php echo number_format($total_defects); ?></div>
                    <div class="summary-label">Total Defect Reports</div>
                </div>
                <div class="summary-item">
                    <div class="summary-value"><?php echo number_format($total_reservations); ?></div>
                    <div class="summary-label">Total Reservations</div>
                </div>
                <div class="summary-item">
                    <div class="summary-value"><?php echo number_format($active_reservations); ?></div>
                    <div class="summary-label">Active Reservations</div>
                </div>
                <div class="summary-item">
                    <div class="summary-value"><?php echo number_format($pending_defects); ?></div>
                    <div class="summary-label">Pending Defects</div>
                </div>
            </div>

            <h2>Equipment Distribution by Category</h2>
            <table class="category-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Equipment Count</th>
                        <th>Total Defects</th>
                        <th>Total Reservations</th>
                        <th>Defect Rate (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($category_stats as $category => $stats): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($category); ?></td>
                        <td><?php echo $stats['count']; ?></td>
                        <td><?php echo $stats['defects']; ?></td>
                        <td><?php echo $stats['reservations']; ?></td>
                        <td><?php echo $stats['count'] > 0 ? round(($stats['defects'] / $stats['count']) * 100, 1) : 0; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Detailed Equipment Usage Analysis</h2>
            <table>
                <thead>
                    <tr>
                        <th>Equipment Details</th>
                        <th>Status & Condition</th>
                        <th>Defect Reports</th>
                        <th>Reservation Activity</th>
                        <th>Usage Metrics</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $item): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($item['equipment_name']); ?></strong><br>
                            <small>ID: <?php echo $item['equipment_id']; ?><br>
                            Tag: <?php echo htmlspecialchars($item['asset_tag']); ?><br>
                            Category: <?php echo htmlspecialchars($item['category_name'] ?: 'Uncategorized'); ?><br>
                            Location: <?php echo htmlspecialchars($item['location'] ?: 'Not specified'); ?></small>
                        </td>
                        <td>
                            Status: <strong><?php echo ucfirst($item['status']); ?></strong><br>
                            Condition: <strong><?php echo ucfirst($item['condition_status']); ?></strong>
                        </td>
                        <td>
                            Total: <strong><?php echo $item['total_defects']; ?></strong><br>
                            Resolved: <span style="color: green;"><?php echo $item['resolved_defects']; ?></span><br>
                            Pending: <span style="color: orange;"><?php echo $item['pending_defects']; ?></span><br>
                            Last Report: <?php echo $item['last_defect_date'] ?: 'None'; ?>
                        </td>
                        <td>
                            Total: <strong><?php echo $item['total_reservations']; ?></strong><br>
                            Approved: <span style="color: blue;"><?php echo $item['approved_reservations']; ?></span><br>
                            Active: <span style="color: green;"><?php echo $item['active_reservations']; ?></span><br>
                            Avg Duration: <?php echo $item['avg_reservation_duration'] ? round($item['avg_reservation_duration'], 1) . ' days' : 'N/A'; ?>
                        </td>
                        <td>
                            Last Reservation: <?php echo $item['last_reservation_date'] ?: 'Never'; ?><br>
                            Added: <?php echo date('M j, Y', strtotime($item['created_at'])); ?><br>
                            Updated: <?php echo date('M j, Y', strtotime($item['updated_at'])); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="footer">
                <p><strong>Report generated by BEC Equipment Management System</strong></p>
                <p>Confidential - For Administrative Use Only | <?php echo date('F j, Y \a\t g:i A'); ?></p>
            </div>

            <div class="no-print" style="margin-top: 30px; text-align: center;">
                <button onclick="window.print()" style="padding: 12px 40px; background: #800000; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;">Print Report</button>
                <button onclick="window.close()" style="padding: 12px 40px; background: #666; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 15px; font-size: 16px;">Close</button>
            </div>
        </body>
        </html>
        <?php
    }
}

// Export Defect Reports
elseif ($type === 'defects') {
    // Get comprehensive defect report data with additional metrics
    $sql = "SELECT dr.*, e.equipment_name, e.asset_tag, e.location, c.category_name,
            u.fullname as reporter_name, u.role as reporter_role,
            mt.fullname as technician_name, mt.specialization,
            CASE WHEN dr.assigned_to IS NOT NULL THEN DATEDIFF(dr.assigned_date, dr.report_date) ELSE NULL END as assignment_delay_days,
            CASE WHEN dr.status = 'completed' THEN DATEDIFF(dr.completion_date, dr.assigned_date) ELSE NULL END as resolution_time_days,
            CASE WHEN dr.status = 'completed' THEN DATEDIFF(dr.completion_date, dr.report_date) ELSE NULL END as total_resolution_days
            FROM defect_reports dr
            JOIN equipment e ON dr.equipment_id = e.equipment_id
            LEFT JOIN categories c ON e.category_id = c.category_id
            LEFT JOIN users u ON dr.reported_by = u.user_id
            LEFT JOIN maintenance_technicians mt ON dr.assigned_to = mt.technician_id
            WHERE dr.status != 'deleted'";

    if ($date_from) {
        $sql .= " AND dr.report_date >= '$date_from'";
    }
    if ($date_to) {
        $sql .= " AND dr.report_date <= '$date_to'";
    }

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
        $status_counts[$report['status']]++;
        $priority_counts[$report['priority']]++;
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
                ucfirst($report['priority']),
                ucfirst($report['status']),
                $report['reporter_name'] ?: 'Guest User',
                $report['reporter_role'] ?: 'Guest',
                $report['technician_name'] ?: 'Unassigned',
                $report['specialization'] ?: 'N/A',
                $report['report_date'],
                $report['assigned_date'] ?: 'Not assigned',
                $report['assignment_delay_days'] ?: 'N/A',
                $report['completion_date'] ?: 'Not completed',
                $report['resolution_time_days'] ?: 'N/A',
                $report['total_resolution_days'] ?: 'N/A',
                $report['work_details'] ?: 'No details provided',
                $report['completion_notes'] ?: 'No notes'
            ]);
        }

        // Report Footer
        fputcsv($output, ['']); // Empty row
        fputcsv($output, ['Report generated by BEC Equipment Management System']);
        fputcsv($output, ['Confidential - For Administrative Use Only']);
        fputcsv($output, ['End of Comprehensive Defect Reports']);

        fclose($output);
    }
}

// Export Reservations
elseif ($type === 'reservations') {
    $sql = "SELECT r.reservation_id, e.equipment_name, e.asset_tag,
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
    }
}

$conn->close();
?>