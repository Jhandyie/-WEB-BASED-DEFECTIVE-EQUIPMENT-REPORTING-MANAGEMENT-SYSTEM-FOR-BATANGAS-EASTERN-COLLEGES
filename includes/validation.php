<?php
class Validator {
    public static function validateEmail($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address');
        }
        return true;
    }
    
    public static function validateRequired($value, $fieldName) {
        if (empty(trim($value))) {
            throw new Exception("$fieldName is required");
        }
        return true;
    }
    
    public static function validateLength($value, $min, $max, $fieldName) {
        $length = strlen($value);
        if ($length < $min || $length > $max) {
            throw new Exception("$fieldName must be between $min and $max characters");
        }
        return true;
    }
    
    public static function sanitizeInput($data) {
        return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
    }
    
    public static function validateDate($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    public static function validateFileUpload($file, $maxSize = 5242880, $allowedTypes = ['image/jpeg', 'image/png', 'image/gif']) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error');
        }
        
        if ($file['size'] > $maxSize) {
            throw new Exception('File size exceeds limit');
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            throw new Exception('Invalid file type');
        }
        
        return true;
    }
}