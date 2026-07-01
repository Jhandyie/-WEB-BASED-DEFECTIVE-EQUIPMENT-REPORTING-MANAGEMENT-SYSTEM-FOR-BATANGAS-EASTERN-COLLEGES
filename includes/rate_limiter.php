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

    /** Server-side store (lives under data/, which is blocked from HTTP by .htaccess). */
    private static function storeDir(): string {
        $dir = __DIR__ . '/../data/rate_limits';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        return $dir;
    }

    /**
     * Persistent, identifier-based limiter (use an IP and/or username).
     * Counts this attempt; throws Exception when the limit is exceeded.
     * Returns attempts remaining.
     */
    public static function enforce(string $identifier, int $maxAttempts = 5, int $timeWindow = 900): int {
        $file = self::storeDir() . '/' . md5($identifier) . '.json';
        $now  = time();
        $data = ['count' => 0, 'start' => $now];
        if (is_file($file)) {
            $raw = json_decode((string) @file_get_contents($file), true);
            if (is_array($raw) && isset($raw['count'], $raw['start'])) { $data = $raw; }
        }
        if ($now - (int) $data['start'] > $timeWindow) {
            $data = ['count' => 0, 'start' => $now]; // window expired → reset
        }
        if ((int) $data['count'] >= $maxAttempts) {
            $retry = $timeWindow - ($now - (int) $data['start']);
            throw new Exception('Too many attempts. Please try again in about ' . max(1, (int) ceil($retry / 60)) . ' minute(s).');
        }
        $data['count'] = (int) $data['count'] + 1;
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return max(0, $maxAttempts - (int) $data['count']);
    }

    /** Reset the counter (call after a successful login). */
    public static function clear(string $identifier): void {
        $file = self::storeDir() . '/' . md5($identifier) . '.json';
        if (is_file($file)) { @unlink($file); }
    }

    /** Best-effort client IP. */
    public static function clientIp(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) { return trim(explode(',', (string) $_SERVER[$k])[0]); }
        }
        return 'unknown';
    }
}