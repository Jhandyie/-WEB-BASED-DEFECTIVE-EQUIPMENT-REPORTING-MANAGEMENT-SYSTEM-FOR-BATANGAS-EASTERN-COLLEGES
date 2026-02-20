<?php
/**
 * Inventory Management Functions
 * Handles inventory tracking, alerts, and reorder suggestions
 */

require_once 'config/database.php';

// ============================================
// INVENTORY STATISTICS FUNCTIONS
// ============================================

/**
 * Get comprehensive inventory statistics
 */
function getInventoryStats() {
    $conn = getDBConnection();

    $sql = "SELECT
        COUNT(*) as total_items,
        SUM(quantity) as total_quantity,
        SUM(CASE WHEN quantity <= min_stock_level AND quantity > 0 THEN 1 ELSE 0 END) as low_stock_items,
        SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) as out_of_stock_items,
        SUM(CASE WHEN quantity <= reorder_point THEN 1 ELSE 0 END) as reorder_items,
        SUM(quantity * COALESCE(purchase_cost, 0)) as total_value
        FROM equipment
        WHERE status != 'deleted'";

    $result = $conn->query($sql);
    $stats = $result->fetch_assoc();

    return [
        'total_items' => (int)$stats['total_items'],
        'total_quantity' => (int)$stats['total_quantity'],
        'low_stock_items' => (int)$stats['low_stock_items'],
        'out_of_stock_items' => (int)$stats['out_of_stock_items'],
        'reorder_items' => (int)$stats['reorder_items'],
        'total_value' => (float)$stats['total_value']
    ];
}

/**
 * Get low stock alerts
 */
function getLowStockAlerts() {
    $conn = getDBConnection();

    $sql = "SELECT e.*, c.category_name,
            CASE
                WHEN e.quantity = 0 THEN 'out_of_stock'
                WHEN e.quantity <= e.min_stock_level THEN 'low_stock'
                ELSE 'normal'
            END as alert_type
            FROM equipment e
            LEFT JOIN categories c ON e.category_id = c.category_id
            WHERE e.status != 'deleted'
            AND e.quantity <= e.min_stock_level
            ORDER BY e.quantity ASC, e.equipment_name ASC";

    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Get reorder suggestions
 */
function getReorderSuggestions() {
    $conn = getDBConnection();

    $sql = "SELECT e.*, c.category_name
            FROM equipment e
            LEFT JOIN categories c ON e.category_id = c.category_id
            WHERE e.status != 'deleted'
            AND e.quantity <= e.reorder_point
            ORDER BY e.quantity ASC, e.equipment_name ASC";

    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Get inventory breakdown by category
 */
function getInventoryByCategory() {
    $conn = getDBConnection();

    $sql = "SELECT c.category_name,
            COUNT(e.equipment_id) as total_items,
            SUM(e.quantity) as total_quantity,
            SUM(CASE WHEN e.quantity <= e.min_stock_level THEN 1 ELSE 0 END) as low_stock_count,
            SUM(CASE WHEN e.quantity <= e.reorder_point THEN 1 ELSE 0 END) as reorder_count
            FROM categories c
            LEFT JOIN equipment e ON c.category_id = e.category_id AND e.status != 'deleted'
            GROUP BY c.category_id, c.category_name
            ORDER BY c.category_name ASC";

    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Get equipment with inventory status
 */
function getEquipmentWithInventory() {
    $conn = getDBConnection();

    $sql = "SELECT e.*, c.category_name,
            CASE
                WHEN e.quantity = 0 THEN 'out_of_stock'
                WHEN e.quantity <= e.reorder_point THEN 'reorder'
                WHEN e.quantity <= e.min_stock_level THEN 'low_stock'
                ELSE 'normal'
            END as inventory_status
            FROM equipment e
            LEFT JOIN categories c ON e.category_id = c.category_id
            WHERE e.status != 'deleted'
            ORDER BY e.equipment_name ASC";

    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// ============================================
// INVENTORY UPDATE FUNCTIONS
// ============================================

/**
 * Update inventory quantity
 */
function updateInventory($equipment_id, $quantity_change, $reason = '', $updated_by = null) {
    $conn = getDBConnection();

    // Start transaction
    $conn->begin_transaction();

    try {
        // Get current quantity
        $stmt = $conn->prepare("SELECT quantity, equipment_name FROM equipment WHERE equipment_id = ?");
        $stmt->bind_param("s", $equipment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $current = $result->fetch_assoc();
        $stmt->close();

        if (!$current) {
            throw new Exception("Equipment not found");
        }

        $new_quantity = $current['quantity'] + $quantity_change;

        // Prevent negative quantities
        if ($new_quantity < 0) {
            throw new Exception("Cannot reduce quantity below zero");
        }

        // Update quantity and last inventory check
        $stmt = $conn->prepare("UPDATE equipment SET quantity = ?, last_inventory_check = NOW() WHERE equipment_id = ?");
        $stmt->bind_param("is", $new_quantity, $equipment_id);
        $stmt->execute();
        $stmt->close();

        // Log the inventory change
        logInventoryChange($equipment_id, $quantity_change, $new_quantity, $reason, $updated_by);

        // Check for alerts
        checkInventoryAlertsForItem($equipment_id);

        $conn->commit();
        return true;

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Inventory update error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update inventory settings (min_stock_level, reorder_point, supplier_info)
 */
function updateInventorySettings($equipment_id, $settings) {
    $conn = getDBConnection();

    $updates = [];
    $types = "";
    $values = [];

    if (isset($settings['min_stock_level'])) {
        $updates[] = "min_stock_level = ?";
        $types .= "i";
        $values[] = $settings['min_stock_level'];
    }

    if (isset($settings['reorder_point'])) {
        $updates[] = "reorder_point = ?";
        $types .= "i";
        $values[] = $settings['reorder_point'];
    }

    if (isset($settings['supplier_info'])) {
        $updates[] = "supplier_info = ?";
        $types .= "s";
        $values[] = $settings['supplier_info'];
    }

    if (empty($updates)) {
        return false;
    }

    $values[] = $equipment_id;
    $types .= "s";

    $sql = "UPDATE equipment SET " . implode(", ", $updates) . " WHERE equipment_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $result = $stmt->execute();
    $stmt->close();

    // Check for alerts after settings change
    if ($result) {
        checkInventoryAlertsForItem($equipment_id);
    }

    return $result;
}

// ============================================
// INVENTORY ALERT FUNCTIONS
// ============================================

/**
 * Check and create inventory alerts
 */
function checkInventoryAlerts() {
    $conn = getDBConnection();
    $alerts_created = 0;

    // Get items that need alerts
    $alert_items = getLowStockAlerts();

    foreach ($alert_items as $item) {
        $alert_type = $item['alert_type'];
        $equipment_id = $item['equipment_id'];

        // Check if alert already exists and is unread
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications
                                WHERE type IN ('low_stock_alert', 'out_of_stock_alert')
                                AND related_id = ?
                                AND is_read = 0
                                AND created_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->bind_param("s", $equipment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing = $result->fetch_assoc()['count'];
        $stmt->close();

        if ($existing == 0) {
            // Create new alert
            $message = "";
            $type = "";

            if ($alert_type == 'out_of_stock') {
                $message = "Out of stock: {$item['equipment_name']} (ID: {$item['equipment_id']})";
                $type = "out_of_stock_alert";
            } else {
                $message = "Low stock alert: {$item['equipment_name']} has {$item['quantity']} remaining (min: {$item['min_stock_level']})";
                $type = "low_stock_alert";
            }

            addNotification(null, $message, $type, $equipment_id);
            $alerts_created++;
        }
    }

    return $alerts_created;
}

/**
 * Check alerts for a specific item
 */
function checkInventoryAlertsForItem($equipment_id) {
    $conn = getDBConnection();

    $stmt = $conn->prepare("SELECT e.*, c.category_name FROM equipment e
                            LEFT JOIN categories c ON e.category_id = c.category_id
                            WHERE e.equipment_id = ?");
    $stmt->bind_param("s", $equipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    $stmt->close();

    if (!$item) return false;

    $alert_type = null;
    $message = "";

    if ($item['quantity'] == 0) {
        $alert_type = "out_of_stock_alert";
        $message = "Out of stock: {$item['equipment_name']} (ID: {$item['equipment_id']})";
    } elseif ($item['quantity'] <= $item['min_stock_level']) {
        $alert_type = "low_stock_alert";
        $message = "Low stock alert: {$item['equipment_name']} has {$item['quantity']} remaining (min: {$item['min_stock_level']})";
    }

    if ($alert_type) {
        // Check if recent alert exists
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications
                                WHERE type = ?
                                AND related_id = ?
                                AND is_read = 0
                                AND created_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $stmt->bind_param("ss", $alert_type, $equipment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing = $result->fetch_assoc()['count'];
        $stmt->close();

        if ($existing == 0) {
            addNotification(null, $message, $alert_type, $equipment_id);
            return true;
        }
    }

    return false;
}

/**
 * Get inventory notifications
 */
function getInventoryNotifications($limit = 10) {
    $conn = getDBConnection();

    $sql = "SELECT * FROM notifications
            WHERE type IN ('low_stock_alert', 'out_of_stock_alert', 'reorder_alert')
            ORDER BY created_date DESC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $notifications = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $notifications;
}

// ============================================
// INVENTORY LOGGING FUNCTIONS
// ============================================

/**
 * Log inventory changes
 */
function logInventoryChange($equipment_id, $change, $new_quantity, $reason = '', $updated_by = null) {
    $conn = getDBConnection();

    $sql = "INSERT INTO activity_log (user_id, user_role, action_type, action_description, timestamp)
            VALUES (?, 'system', 'inventory_update',
            CONCAT('Inventory update for ', ?, ': ', ?, ' → ', ?, '. Reason: ', ?), NOW())";

    $stmt = $conn->prepare($sql);
    $change_str = ($change > 0 ? '+' : '') . $change;
    $stmt->bind_param("sssss", $updated_by, $equipment_id, $change_str, $new_quantity, $reason);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

/**
 * Get inventory change history
 */
function getInventoryHistory($equipment_id, $limit = 20) {
    $conn = getDBConnection();

    $sql = "SELECT al.*, e.equipment_name
            FROM activity_log al
            JOIN equipment e ON al.action_description LIKE CONCAT('%', e.equipment_id, '%')
            WHERE al.action_type = 'inventory_update'
            AND al.action_description LIKE CONCAT('%', ?, '%')
            ORDER BY al.timestamp DESC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $equipment_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $history;
}

// ============================================
// INVENTORY REPORTING FUNCTIONS
// ============================================

/**
 * Get inventory report data
 */
function getInventoryReport($filters = []) {
    $conn = getDBConnection();

    $sql = "SELECT e.*, c.category_name,
            CASE
                WHEN e.quantity = 0 THEN 'Out of Stock'
                WHEN e.quantity <= e.reorder_point THEN 'Reorder'
                WHEN e.quantity <= e.min_stock_level THEN 'Low Stock'
                ELSE 'Normal'
            END as status_text
            FROM equipment e
            LEFT JOIN categories c ON e.category_id = c.category_id
            WHERE e.status != 'deleted'";

    $params = [];
    $types = "";

    if (!empty($filters['category_id'])) {
        $sql .= " AND e.category_id = ?";
        $params[] = $filters['category_id'];
        $types .= "i";
    }

    if (!empty($filters['status'])) {
        switch ($filters['status']) {
            case 'low_stock':
                $sql .= " AND e.quantity <= e.min_stock_level AND e.quantity > 0";
                break;
            case 'out_of_stock':
                $sql .= " AND e.quantity = 0";
                break;
            case 'reorder':
                $sql .= " AND e.quantity <= e.reorder_point";
                break;
            case 'normal':
                $sql .= " AND e.quantity > e.min_stock_level";
                break;
        }
    }

    $sql .= " ORDER BY c.category_name ASC, e.equipment_name ASC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $report = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $report;
}

/**
 * Export detailed inventory data to CSV with academic formatting
 */
function exportInventoryToCSV($filename = null) {
    if (!$filename) {
        $filename = 'inventory_export_' . date('Y-m-d_H-i-s') . '.csv';
    }

    $conn = getDBConnection();

    // Get comprehensive inventory data
    $sql = "SELECT e.*, c.category_name,
            CASE
                WHEN e.quantity = 0 THEN 'Out of Stock'
                WHEN e.quantity <= e.reorder_point THEN 'Reorder Required'
                WHEN e.quantity <= e.min_stock_level THEN 'Low Stock'
                ELSE 'Normal'
            END as inventory_status_text
            FROM equipment e
            LEFT JOIN categories c ON e.category_id = c.category_id
            WHERE e.status != 'deleted'
            ORDER BY c.category_name ASC, e.equipment_name ASC";

    $result = $conn->query($sql);
    $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    // Get summary statistics
    $stats = getInventoryStats();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // Academic Report Header
    fputcsv($output, ['BEC Equipment Management System - Detailed Inventory Report']);
    fputcsv($output, ['Generated on: ' . date('F j, Y \a\t g:i A')]);
    fputcsv($output, ['Report Type: Comprehensive Inventory Analysis']);
    fputcsv($output, ['']); // Empty row for spacing

    // Executive Summary Section
    fputcsv($output, ['EXECUTIVE SUMMARY']);
    fputcsv($output, ['Total Equipment Items:', $stats['total_items']]);
    fputcsv($output, ['Total Quantity Across All Items:', $stats['total_quantity']]);
    fputcsv($output, ['Items with Low Stock:', $stats['low_stock_items']]);
    fputcsv($output, ['Items Out of Stock:', $stats['out_of_stock_items']]);
    fputcsv($output, ['Items Requiring Reorder:', $stats['reorder_items']]);
    fputcsv($output, ['Total Inventory Value (Purchase Cost):', '$' . number_format($stats['total_value'], 2)]);
    fputcsv($output, ['']); // Empty row for spacing

    // Detailed Inventory Data Section
    fputcsv($output, ['DETAILED INVENTORY DATA']);
    fputcsv($output, ['']); // Empty row

    // Enhanced CSV headers with academic terminology
    fputcsv($output, [
        'Equipment ID',
        'Asset Tag',
        'Equipment Name',
        'Category',
        'Description',
        'Current Quantity',
        'Minimum Stock Level',
        'Reorder Point',
        'Inventory Status',
        'Condition Status',
        'Location',
        'Purchase Date',
        'Purchase Cost ($)',
        'Warranty Expiry',
        'Supplier Information',
        'Last Inventory Check',
        'Record Created',
        'Last Updated'
    ]);

    // CSV data with enhanced fields
    foreach ($data as $item) {
        fputcsv($output, [
            $item['equipment_id'],
            $item['asset_tag'],
            $item['equipment_name'],
            $item['category_name'] ?: 'Uncategorized',
            $item['description'] ?: 'No description available',
            $item['quantity'],
            $item['min_stock_level'],
            $item['reorder_point'],
            $item['inventory_status_text'],
            ucfirst($item['condition_status']),
            $item['location'] ?: 'Not specified',
            $item['purchase_date'] ?: 'Not recorded',
            $item['purchase_cost'] ? '$' . number_format($item['purchase_cost'], 2) : 'Not recorded',
            $item['warranty_expiry'] ?: 'Not applicable',
            $item['supplier_info'] ?: 'Not specified',
            $item['last_inventory_check'] ?: 'Never checked',
            $item['created_at'],
            $item['updated_at']
        ]);
    }

    // Category Summary Section
    fputcsv($output, ['']); // Empty row
    fputcsv($output, ['CATEGORY SUMMARY']);
    fputcsv($output, ['']); // Empty row

    fputcsv($output, [
        'Category Name',
        'Total Items',
        'Total Quantity',
        'Low Stock Items',
        'Reorder Items'
    ]);

    $categoryData = getInventoryByCategory();
    foreach ($categoryData as $category) {
        fputcsv($output, [
            $category['category_name'],
            $category['total_items'],
            $category['total_quantity'],
            $category['low_stock_count'],
            $category['reorder_count']
        ]);
    }

    // Report Footer
    fputcsv($output, ['']); // Empty row
    fputcsv($output, ['Report generated by BEC Equipment Management System']);
    fputcsv($output, ['Confidential - For Administrative Use Only']);
    fputcsv($output, ['End of Report']);

    fclose($output);
    exit;
}

/**
 * Get all maintenance schedules
 */
function getMaintenanceSchedules() {
    $conn = getDBConnection();

    $sql = "SELECT ms.*, e.equipment_name, mt.fullname as assigned_technician_name
            FROM maintenance_schedules ms
            LEFT JOIN equipment e ON ms.equipment_id = e.equipment_id
            LEFT JOIN maintenance_technicians mt ON ms.assigned_to = mt.technician_id
            ORDER BY ms.scheduled_date ASC, ms.created_date DESC";

    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// ============================================
// PMO/ITSO ANALYTICS FUNCTIONS
// ============================================

/**
 * Get technician performance metrics
 */
function getTechnicianPerformance() {
    $conn = getDBConnection();

    $sql = "SELECT
            mt.technician_id,
            mt.fullname,
            COUNT(dr.report_id) as total_tasks,
            COUNT(CASE WHEN dr.status = 'verified' THEN 1 END) as completed_tasks,
            AVG(CASE WHEN dr.status = 'verified' THEN TIMESTAMPDIFF(HOUR, dr.assigned_date, dr.verification_date) END) as avg_completion_hours,
            COUNT(CASE WHEN dr.status = 'verified' AND TIMESTAMPDIFF(DAY, dr.assigned_date, dr.verification_date) <= 7 THEN 1 END) as on_time_completions
            FROM maintenance_technicians mt
            LEFT JOIN defect_reports dr ON mt.technician_id = dr.assigned_to
            WHERE dr.assigned_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY mt.technician_id, mt.fullname
            ORDER BY completed_tasks DESC";

    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Get repair turnaround time analytics
 */
function getRepairTurnaroundTime() {
    $conn = getDBConnection();

    $sql = "SELECT
            AVG(TIMESTAMPDIFF(DAY, dr.report_date, dr.verification_date)) as avg_days,
            MIN(TIMESTAMPDIFF(DAY, dr.report_date, dr.verification_date)) as min_days,
            MAX(TIMESTAMPDIFF(DAY, dr.report_date, dr.verification_date)) as max_days,
            COUNT(*) as total_completed_repairs
            FROM defect_reports dr
            WHERE dr.status = 'verified'
            AND dr.verification_date IS NOT NULL
            AND dr.report_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)";

    $result = $conn->query($sql);
    $data = $result ? $result->fetch_assoc() : [];

    return [
        'avg_days' => round($data['avg_days'] ?? 0, 1),
        'min_days' => $data['min_days'] ?? 0,
        'max_days' => $data['max_days'] ?? 0,
        'total_completed_repairs' => $data['total_completed_repairs'] ?? 0
    ];
}

/**
 * Get frequently defective equipment
 */
function getFrequentlyDefectiveEquipment() {
    $conn = getDBConnection();

    $sql = "SELECT
            e.equipment_name,
            e.equipment_id,
            COUNT(dr.report_id) as defect_count,
            MAX(dr.report_date) as last_defect
            FROM equipment e
            JOIN defect_reports dr ON e.equipment_id = dr.equipment_id
            WHERE dr.report_date >= DATE_SUB(NOW(), INTERVAL 180 DAY)
            GROUP BY e.equipment_id, e.equipment_name
            HAVING defect_count >= 2
            ORDER BY defect_count DESC
            LIMIT 10";

    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Get PMO vs ITSO reports comparison
 */
function getPmoVsItsoReports() {
    $conn = getDBConnection();

    $sql = "SELECT
            c.type,
            COUNT(dr.report_id) as report_count
            FROM defect_reports dr
            JOIN equipment e ON dr.equipment_id = e.equipment_id
            JOIN categories c ON e.category_id = c.category_id
            WHERE dr.report_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND c.type IN ('PMO', 'ITSO')
            GROUP BY c.type";

    $result = $conn->query($sql);
    $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    $pmo_count = 0;
    $itso_count = 0;

    foreach ($data as $row) {
        if ($row['type'] === 'PMO') {
            $pmo_count = $row['report_count'];
        } elseif ($row['type'] === 'ITSO') {
            $itso_count = $row['report_count'];
        }
    }

    return [
        'PMO' => $pmo_count,
        'ITSO' => $itso_count
    ];
}

// Handle direct access for export
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    session_start();
    require_once 'includes/auth.php';
    requireRole('admin');
    exportInventoryToCSV();
}
?>
