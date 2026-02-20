<?php
// includes/notification_helper.php
// Notification helper functions for the BEC Equipment System

require_once __DIR__ . '/../FileStorage.php';

/**
 * Get unread notification count for a user
 */
function getUnreadNotificationCount($user_id = null) {
    if (!$user_id) return 0;

    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $stmt->close();
    $conn->close();

    return $count;
}

/**
 * Create a new notification
 */
function createNotification($user_id, $message, $type, $related_id = null) {
    global $fileStorage;

    $notification = [
        'notification_id' => 'NOT-' . uniqid(),
        'user_id' => $user_id,
        'message' => $message,
        'type' => $type,
        'related_id' => $related_id,
        'created_date' => date('Y-m-d H:i:s'),
        'is_read' => false
    ];

    return $fileStorage->addNotification($notification) ? $notification['notification_id'] : false;
}

/**
 * Mark notification as read
 */
function markNotificationAsRead($notification_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ?");
    $stmt->bind_param("s", $notification_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    return $affected > 0;
}

/**
 * Mark all notifications as read for a user
 */
function markAllNotificationsAsRead($user_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    return $affected;
}

/**
 * Get recent notifications for a user
 */
function getRecentNotifications($user_id, $limit = 10) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("
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
                WHEN n.type = 'support_response' THEN 'reply'
                ELSE 'bell'
            END as icon,
            CASE
                WHEN n.type = 'new_defect_report' THEN 'danger'
                WHEN n.type = 'new_reservation' THEN 'warning'
                WHEN n.type = 'task_completed' THEN 'success'
                WHEN n.type = 'support_response' THEN 'info'
                ELSE 'info'
            END as badge_class
        FROM notifications n
        WHERE n.user_id IS NULL OR n.user_id = ?
        ORDER BY n.created_date DESC
        LIMIT ?
    ");
    $stmt->bind_param("si", $user_id, $limit);
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    return $notifications;
}
?>
