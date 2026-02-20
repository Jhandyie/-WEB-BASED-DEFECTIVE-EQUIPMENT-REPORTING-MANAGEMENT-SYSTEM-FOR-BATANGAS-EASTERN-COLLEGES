<?php
// api/get_admin_notifications.php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

requireLogin();
requireRole('admin');

header('Content-Type: application/json');

try {
    $conn = getDBConnection();
    $admin_id = $_SESSION['user_id'];

    // Get recent notifications (last 10)
    $notifications_query = "
        SELECT
            n.notification_id,
            n.message,
            n.type,
            n.related_id,
            n.created_date,
            n.is_read,
            CASE
                WHEN n.type = 'new_defect_report' THEN 'exclamation-triangle'
                WHEN n.type = 'new_reservation' THEN 'calendar-check'
                WHEN n.type = 'task_completed' THEN 'check-circle'
                ELSE 'bell'
            END as icon,
            CASE
                WHEN n.type = 'new_defect_report' THEN 'danger'
                WHEN n.type = 'new_reservation' THEN 'warning'
                WHEN n.type = 'task_completed' THEN 'success'
                ELSE 'info'
            END as badge_class
        FROM notifications n
        WHERE n.user_id IS NULL OR n.user_id = ?
        ORDER BY n.created_date DESC
        LIMIT 10
    ";

    $stmt = $conn->prepare($notifications_query);
    $stmt->bind_param("s", $admin_id);
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Count unread notifications
    $unread_query = "SELECT COUNT(*) as count FROM notifications WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0";
    $stmt = $conn->prepare($unread_query);
    $stmt->bind_param("s", $admin_id);
    $stmt->execute();
    $unread_count = $stmt->get_result()->fetch_assoc()['count'];

    $stmt->close();
    $conn->close();

    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => $unread_count
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
