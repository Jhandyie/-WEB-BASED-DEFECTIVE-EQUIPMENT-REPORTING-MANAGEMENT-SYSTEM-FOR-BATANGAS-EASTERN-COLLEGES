<?php
// FileStorage.php - File storage functionality for the BEC Equipment System
// This file is required by notification_helper.php

require_once __DIR__ . '/config/database.php';

class FileStorage {
    public static function save($filename, $data) {
        $path = __DIR__ . '/uploads/' . $filename;
        file_put_contents($path, $data);
        return $path;
    }
    
    public static function get($filename) {
        $path = __DIR__ . '/uploads/' . $filename;
        if (file_exists($path)) {
            return file_get_contents($path);
        }
        return null;
    }
    
    public static function delete($filename) {
        $path = __DIR__ . '/uploads/' . $filename;
        if (file_exists($path)) {
            return unlink($path);
        }
        return false;
    }
    
    public function addNotification($notification) {
        $conn = getDBConnection();
        if (!$conn) return false;
        
        $stmt = $conn->prepare("INSERT INTO notifications (notification_id, user_id, message, type, related_id, is_read) VALUES (?, ?, ?, ?, ?, ?)");
        $is_read = $notification['is_read'] ? 1 : 0;
        $stmt->bind_param("sssssi", 
            $notification['notification_id'],
            $notification['user_id'],
            $notification['message'],
            $notification['type'],
            $notification['related_id'],
            $is_read
        );
        $result = $stmt->execute();
        $stmt->close();
        $conn->close();
        return $result;
    }
}
?>
