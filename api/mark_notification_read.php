<?php
// api/mark_notification_read.php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // Check if marking all notifications as read
    if (isset($data['mark_all']) && $data['mark_all'] === true) {
        try {
            $conn = getDBConnection();
            $user_id = $_SESSION['user_id'];
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0");
            $stmt->bind_param("s", $user_id);
            $stmt->execute();
            $stmt->close();
            $conn->close();

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    } else {
        // Mark single notification as read
        $notification_id = $data['notification_id'] ?? null;

        if (!$notification_id) {
            echo json_encode(['success' => false, 'message' => 'Notification ID required']);
            exit();
        }

        try {
            $conn = getDBConnection();
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ?");
            $stmt->bind_param("s", $notification_id);
            $stmt->execute();
            $stmt->close();
            $conn->close();

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}

// api/get_notification_count.php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

requireLogin();
header('Content-Type: application/json');

try {
    $conn = getDBConnection();
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'count' => $count]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// Helper function to create notifications
function createNotification($user_id, $message, $type, $related_id = null) {
    $conn = getDBConnection();
    $notification_id = 'NOT-' . uniqid();
    
    $stmt = $conn->prepare("INSERT INTO notifications (notification_id, user_id, message, type, related_id, created_date, is_read) VALUES (?, ?, ?, ?, ?, NOW(), 0)");
    $stmt->bind_param("sssss", $notification_id, $user_id, $message, $type, $related_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    
    return $notification_id;
}
?>