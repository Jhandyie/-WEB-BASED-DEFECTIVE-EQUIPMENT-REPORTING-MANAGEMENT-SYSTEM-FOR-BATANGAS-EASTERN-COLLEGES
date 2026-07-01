<?php
/**
 * audit.php — lightweight activity / audit logging into activity_log.
 *
 * Call logActivity() from any meaningful state change (login, register,
 * approve/reject, assign, complete, etc.). Failures are swallowed so a
 * logging problem never breaks the primary flow.
 */
require_once __DIR__ . '/../config/database.php';

if (!function_exists('logActivity')) {
    /**
     * @param string|null $userId  Actor user id (null for anonymous/system).
     * @param string      $action  Short action code, e.g. 'user.register'.
     * @param string      $details Human-readable detail string.
     */
    function logActivity(?string $userId, string $action, string $details = ''): bool {
        try {
            if (!tableExists('activity_log')) {
                return false;
            }
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            if ($ip === '') { $ip = null; }

            if (isPgSqlDriver()) {
                $pdo = getPgsqlPdoConnection();
                $stmt = $pdo->prepare(
                    'INSERT INTO public.activity_log (user_id, action, details, ip_address)
                     VALUES (:user_id, :action, :details, :ip)'
                );
                return $stmt->execute([
                    'user_id' => ($userId !== null && $userId !== '') ? $userId : null,
                    'action'  => $action,
                    'details' => $details,
                    'ip'      => $ip,
                ]);
            }

            $conn = getDBConnection();
            $stmt = $conn->prepare(
                'INSERT INTO activity_log (user_id, action, details, ip_address, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            );
            if (!$stmt) { return false; }
            $uid = ($userId !== null && $userId !== '') ? $userId : null;
            $stmt->bind_param('ssss', $uid, $action, $details, $ip);
            $ok = $stmt->execute();
            $stmt->close();
            return (bool)$ok;
        } catch (\Throwable $e) {
            error_log('logActivity failed: ' . $e->getMessage());
            return false;
        }
    }
}
