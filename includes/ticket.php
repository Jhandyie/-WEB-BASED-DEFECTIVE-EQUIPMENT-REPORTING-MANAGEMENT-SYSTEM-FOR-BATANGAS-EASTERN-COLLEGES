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

if (!function_exists('becTicketCounterResync')) {
    /**
     * Drag the per-year counter up past the highest ticket that actually exists.
     *
     * The counter only advances when generateTicketNumber() runs, so anything
     * that writes defect_reports with an explicit report_id — a seeding script,
     * a restore, a bulk import — leaves it behind. Every ticket it then hands
     * out collides with a row that is already there, and report submission
     * fails outright until the counter catches up. It has, in production.
     */
    function becTicketCounterResync(PDO $pdo, int $year): void {
        $pdo->prepare(
            "UPDATE public.ticket_counters
                SET last_seq = GREATEST(last_seq, COALESCE((
                        SELECT MAX(CAST(SUBSTRING(report_id FROM 'BEC-[0-9]{4}-([0-9]+)\$') AS INTEGER))
                          FROM public.defect_reports
                         WHERE report_id ~ ('^BEC-' || :y1 || '-[0-9]+\$')
                    ), 0))
              WHERE year = :y2"
        )->execute(['y1' => $year, 'y2' => $year]);
    }
}

if (!function_exists('generateTicketNumber')) {
    function generateTicketNumber(): string {
        $year = (int)date('Y');

        try {
            if (isPgSqlDriver()) {
                $pdo = getPgsqlPdoConnection();
                $bump = $pdo->prepare(
                    'INSERT INTO public.ticket_counters (year, last_seq) VALUES (:y, 1)
                     ON CONFLICT (year) DO UPDATE SET last_seq = public.ticket_counters.last_seq + 1
                     RETURNING last_seq'
                );
                $taken = $pdo->prepare('SELECT 1 FROM public.defect_reports WHERE report_id = :id LIMIT 1');

                // Normal path is one round trip. Only a counter that has drifted
                // behind pays for the resync, and then only once.
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    $bump->execute(['y' => $year]);
                    $seq = (int)$bump->fetchColumn();
                    if ($seq < 1) { break; }
                    $id = sprintf('BEC-%d-%06d', $year, $seq);

                    $taken->execute(['id' => $id]);
                    if ($taken->fetchColumn() === false) {
                        return $id;                    // free — hand it out
                    }
                    error_log("generateTicketNumber: {$id} already exists, resyncing counter");
                    becTicketCounterResync($pdo, $year);
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
