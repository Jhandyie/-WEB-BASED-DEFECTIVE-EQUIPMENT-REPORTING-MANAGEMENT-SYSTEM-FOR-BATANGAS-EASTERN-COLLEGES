<?php
/**
 * issue_report.php — receives a problem report about the system itself.
 *
 * Not the same thing as a defect report: that is about equipment on campus and
 * goes through the workflow. This is someone telling us the software misbehaved,
 * and it goes straight to the PMO mailbox.
 *
 * Public and unauthenticated by design — the person who most needs to report a
 * broken sign-in is the one who cannot sign in. That makes it an upload endpoint
 * anybody can reach, so everything below is written on the assumption that the
 * sender is hostile: files are checked by content rather than by name, stored
 * under names of our own choosing, and the whole thing is rate limited per IP.
 */

require_once __DIR__ . '/includes/session_bootstrap.php';
startPublicSession();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/mail_helper.php';
require_once __DIR__ . '/includes/rate_limiter.php';

header('Content-Type: application/json; charset=utf-8');

function issue_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { issue_fail('Method not allowed.', 405); }

/* A field hidden from people and irresistible to scripts. */
if (trim((string)($_POST['website'] ?? '')) !== '') { echo json_encode(['ok' => true]); exit; }

/* Three an hour from one address. Enough for a real person hitting several
   problems in a session, not enough to be worth abusing. */
$ip = class_exists('RateLimiter') ? RateLimiter::clientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
if (class_exists('RateLimiter')) {
    $left = RateLimiter::checkLimit('issue_report:' . $ip, 3, 3600);
    if ($left === false || $left < 0) {
        issue_fail('You have sent several reports already. Please wait a while before sending another.', 429);
    }
}

$category = trim((string)($_POST['category'] ?? 'Bug'));
$email    = trim((string)($_POST['email'] ?? ''));
$subject  = trim((string)($_POST['subject'] ?? ''));
$body     = trim((string)($_POST['description'] ?? ''));
$page     = trim((string)($_POST['page'] ?? ''));
$agent    = substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300);

$allowedCats = ['Bug', 'Suggestion', 'Question', 'Other'];
if (!in_array($category, $allowedCats, true)) { $category = 'Other'; }

if ($subject === '' || mb_strlen($subject) > 150) { issue_fail('Please give the problem a short subject.'); }
if ($body === '')                                 { issue_fail('Please describe what happened.'); }
if (mb_strlen($body) > 5000)                      { $body = mb_substr($body, 0, 5000) . "\n\n[truncated]"; }
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    issue_fail('That email address does not look right.');
}

/* ---- attachments -------------------------------------------------------- */
$dir = __DIR__ . '/uploads/issues';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

$MAX_FILES = 3;
$MAX_BYTES = 15 * 1024 * 1024;
$okImage   = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
$okVideo   = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];

$saved = [];
if (!empty($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
    $count = min(count($_FILES['files']['name']), $MAX_FILES);
    for ($i = 0; $i < $count; $i++) {
        if ((int)($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { continue; }
        $tmp  = $_FILES['files']['tmp_name'][$i];
        $size = (int)($_FILES['files']['size'][$i] ?? 0);
        if (!is_uploaded_file($tmp) || $size <= 0 || $size > $MAX_BYTES) { continue; }

        // Decided by what the file contains, never by what it was called.
        $ext  = null;
        $info = @getimagesize($tmp);
        if ($info !== false && isset($okImage[$info[2]])) {
            $ext = $okImage[$info[2]];
        } else {
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
            $mime  = $finfo ? finfo_file($finfo, $tmp) : '';
            if ($finfo) { finfo_close($finfo); }
            if (isset($okVideo[$mime])) { $ext = $okVideo[$mime]; }
        }
        if ($ext === null) { continue; }

        $name = 'issue_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
        if (@move_uploaded_file($tmp, $dir . '/' . $name)) { $saved[] = $name; }
    }
}

/* ---- send it ------------------------------------------------------------ */
$base = rtrim((string)dbEnv('APP_BASE_URL', (isset($_SERVER['HTTP_HOST'])
        ? ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'])
        : 'http://localhost')), '/');

$he   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$rows = '';
$row  = function (string $k, string $v) use (&$rows, $he) {
    if ($v === '') { return; }
    $rows .= '<tr><th align="left" style="padding:6px 12px;color:#7A6255;font-size:12px;text-transform:uppercase;'
           . 'letter-spacing:.5px;white-space:nowrap;vertical-align:top;">' . $he($k) . '</th>'
           . '<td style="padding:6px 12px;color:#1C1008;font-size:14px;">' . $he($v) . '</td></tr>';
};
$row('Category',  $category);
$row('From',      $email !== '' ? $email : 'not given');
$row('Page',      $page);
$row('Browser',   $agent);
$row('Received',  date('F j, Y g:i A'));

$links = '';
foreach ($saved as $f) {
    $u = $base . '/uploads/issues/' . rawurlencode($f);
    $links .= '<div style="margin:4px 0;"><a href="' . $he($u) . '" style="color:#7B1D1D;">' . $he($f) . '</a></div>';
}
if ($links === '') { $links = '<div style="color:#7A6255;">none</div>'; }

$html = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:640px;">'
      . '<h2 style="color:#4A0E0E;margin:0 0 4px;">' . $he($category) . ': ' . $he($subject) . '</h2>'
      . '<p style="color:#7A6255;margin:0 0 14px;font-size:13px;">Reported through the system\'s own issue form.</p>'
      . '<div style="white-space:pre-wrap;background:#FBF7F0;border:1px solid #E6DACB;border-radius:8px;'
      . 'padding:12px 14px;color:#1C1008;font-size:14px;line-height:1.6;">' . $he($body) . '</div>'
      . '<table style="border-collapse:collapse;margin-top:14px;width:100%;">' . $rows . '</table>'
      . '<h3 style="color:#4A0E0E;font-size:14px;margin:16px 0 4px;">Attachments</h3>' . $links
      . '</div>';

$to = 'jhanmark_decastro@bec.edu.ph';
try {
    $settings = function_exists('getEmailSettings') ? getEmailSettings() : null;
    $sent = sendEmail($to, '[System Issue] ' . $category . ': ' . $subject, $html, $settings, 'admin');
} catch (\Throwable $e) {
    $sent = false;
}

// The report is kept regardless, so nothing is lost when mail is the thing that
// is broken - which is exactly when someone is most likely to be using this.
@file_put_contents(__DIR__ . '/logs/issue_reports.log',
    json_encode([
        'time' => date('c'), 'category' => $category, 'subject' => $subject,
        'email' => $email, 'page' => $page, 'files' => $saved, 'mailed' => (bool)$sent,
        'body' => mb_substr($body, 0, 1000),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

echo json_encode([
    'ok'      => true,
    'mailed'  => (bool)$sent,
    'files'   => count($saved),
    'message' => $sent
        ? 'Thank you — your report has been sent to the Property Management Office.'
        : 'Thank you — your report has been recorded. Email delivery is unavailable right now, but nothing was lost.',
]);
