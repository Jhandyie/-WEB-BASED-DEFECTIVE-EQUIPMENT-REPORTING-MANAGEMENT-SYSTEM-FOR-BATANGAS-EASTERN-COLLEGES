<?php
class RateLimiter {
    public static function checkLimit($identifier, $maxAttempts = 5, $timeWindow = 300) {
        $key = "rate_limit_$identifier";
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 0, 'start_time' => time()];
        }
        
        $data = $_SESSION[$key];
        
        if (time() - $data['start_time'] > $timeWindow) {
            $_SESSION[$key] = ['count' => 1, 'start_time' => time()];
            return true;
        }
        
        if ($data['count'] >= $maxAttempts) {
            throw new Exception("Too many attempts. Please try again later.");
        }
        
        $_SESSION[$key]['count']++;
        return true;
    }
}