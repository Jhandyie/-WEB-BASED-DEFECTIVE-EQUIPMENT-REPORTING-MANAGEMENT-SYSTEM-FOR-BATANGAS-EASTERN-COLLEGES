<?php
// file_storage_helpers.php - File storage helper functions
// This file provides backward compatibility for code that expects this file
// and wraps the FileStorage class functionality

// Load the FileStorage class if not already loaded
require_once __DIR__ . '/FileStorage.php';

// Create a global instance for convenience
$fileStorage = new FileStorage();

// Export all static methods as global helper functions for easier access
if (!function_exists('save_file')) {
    function save_file($filename, $data) {
        return FileStorage::save($filename, $data);
    }
}

if (!function_exists('get_file')) {
    function get_file($filename) {
        return FileStorage::get($filename);
    }
}

if (!function_exists('delete_file')) {
    function delete_file($filename) {
        return FileStorage::delete($filename);
    }
}
?>
