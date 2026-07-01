<?php
/**
 * ticket.php — sequential defect-report ticket numbers: BEC-YYYY-XXXXXX
 *
 * Uses an atomic per-year counter (public.ticket_counters) so numbers are
 * gap-free and unique, e.g. BEC-2026-000145. Falls back to a year-prefixed
 * random suffix only if the counter table is unavailable, so report creation
 * never hard-fails on a numbering issue.
 */
require_once __DIR__ . '/../config/database.php';

if (!function_exists('generateTicketNumber')) {
    function generateTicketNumber(): string {
        $year = (int)date('Y');

        try {
            if (isPgSqlDriver()) {
                $pdo = getPgsqlPdoConnection();
                $stmt = $pdo->prepare(
                    'INSERT INTO public.ticket_counters (year, last_seq) VALUES (:y, 1)
                     ON CONFLICT (year) DO UPDATE SET last_seq = public.ticket_counters.last_seq + 1
                     RETURNING last_seq'
                );
                $stmt->execute(['y' => $year]);
                $seq = (int)$stmt->fetchColumn();
                if ($seq > 0) {
                    return sprintf('BEC-%d-%06d', $year, $seq);
                }
            } else {
                $conn = getDBConnection();
                // Atomic increment trick: LAST_INSERT_ID carries the new value on UPDATE.
                $conn->query(
                    "INSERT INTO ticket_counters (year, last_seq) VALUES ({$year}, 1)
                     ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)"
                );
                $seq = (int)$conn->insert_id;
                if ($seq < 1) {
                    // First insert for the year: read it back.
                    $res = $conn->query("SELECT last_seq FROM ticket_counters WHERE year = {$year}");
                    $row = $res ? $res->fetch_assoc() : null;
                    $seq = (int)($row['last_seq'] ?? 1);
                }
                if ($seq > 0) {
                    return sprintf('BEC-%d-%06d', $year, $seq);
                }
            }
        } catch (\Throwable $e) {
            error_log('generateTicketNumber counter failed, using fallback: ' . $e->getMessage());
        }

        // Safety fallback — still year-prefixed and unique enough for retries.
        return sprintf('BEC-%d-%06d', $year, random_int(100000, 999999));
    }
}
