<?php
/**
 * run_sweeps.php — generate due preventive-maintenance tasks and escalate
 * overdue reports on a clock, not on an admin's page load.
 *
 * Both sweeps live in the admin dashboard's page load (throttled to once per
 * five minutes). That is fine on a weekday morning; a task due on Saturday,
 * and the technician's notification and email for it, waited until the first
 * admin opened the dashboard on Monday. On the VM this runs from cron:
 *
 *   /etc/cron.d/bec-pmo-sweeps
 *   *\/15 * * * * www-data /usr/bin/php /var/www/bec-pmo/scripts/run_sweeps.php >>/var/www/bec-pmo/logs/sweeps.log 2>&1
 *
 * Locally: c:\xampp\php\php.exe scripts\run_sweeps.php
 *
 * The email and notification links need the site address, which a CLI run
 * has no request to read it from: set APP_BASE_URL in .env
 * (https://becpmo.com on the VM). Without it the links point at localhost.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
require_once __DIR__ . '/../config/database.php';

// What notifyTechnicianAssignment() and the OTP/mail helpers read to build links.
$base = rtrim((string)dbEnv('APP_BASE_URL', 'http://localhost/bec-pmo'), '/');
$u = parse_url($base);
$_SERVER['HTTPS']       = (($u['scheme'] ?? 'http') === 'https') ? 'on' : 'off';
$_SERVER['HTTP_HOST']   = ($u['host'] ?? 'localhost') . (isset($u['port']) ? ':' . $u['port'] : '');
$_SERVER['SCRIPT_NAME'] = rtrim($u['path'] ?? '', '/') . '/index.php';
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];

require_once __DIR__ . '/../includes/preventive_helper.php';
require_once __DIR__ . '/../includes/sla_helper.php';
require_once __DIR__ . '/../includes/mail_helper.php';

$t0  = microtime(true);
$pm  = 0; $sla = 0; $mail = 0; $err = [];
try { $pm  = runPreventiveMaintenanceSweep(true); } catch (Throwable $e) { $err[] = 'pm: '  . $e->getMessage(); }
try { $sla = runSlaEscalationSweep(true);         } catch (Throwable $e) { $err[] = 'sla: ' . $e->getMessage(); }

/*
 * Drain the mail outbox.
 *
 * The workflow actions an admin takes now queue their reporter email instead
 * of holding the page open on an SMTP handshake. Nothing drained that queue on
 * a clock: sendEmail() flushes two on its way past and the nightly backup
 * fifty, so a busy morning could outrun its own drain and a reporter's "your
 * report was approved" could sit for a day. Every fifteen minutes is well
 * inside what a status email should take.
 */
try { $mail = flushMailOutbox(50); } catch (Throwable $e) { $err[] = 'mail: ' . $e->getMessage(); }

// One line per run, only when something happened or failed - a quiet quarter
// hour writes nothing, so the log stays readable.
if ($pm || $sla || $mail || $err) {
    printf("%s pm_tasks=%d sla_escalated=%d mail_sent=%d %.2fs%s\n", date('Y-m-d H:i:s'), $pm, $sla, $mail, microtime(true) - $t0, $err ? ' ERR ' . implode(' | ', $err) : '');
}
exit($err ? 1 : 0);
