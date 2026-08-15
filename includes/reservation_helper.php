<?php
/**
 * reservation_helper.php — Venue Reservation Form (VRF) vocabulary and helpers.
 *
 * Shared by reserve_venue.php (the applicant's form) and admin_reservations.php
 * (the PMO's queue). Everything here mirrors the paper form the office already
 * uses; scripts/2026_08_venue_reservations.sql describes the record itself.
 */
require_once __DIR__ . '/../config/database.php';

/** Workflow states, in the order the paper form travels. */
function vrStatuses(): array {
    return [
        'submitted'   => 'Submitted',
        'endorsed'    => 'Endorsed by adviser',
        'approved'    => 'Approved',
        'disapproved' => 'Disapproved',
        'cancelled'   => 'Cancelled',
        'completed'   => 'Completed',
    ];
}

/** The states that still hold the room — must match the SQL exclusion constraint. */
function vrHoldingStatuses(): array {
    return ['submitted', 'endorsed', 'approved'];
}

/** The form's "NATURE OF ACTIVITY" tick boxes. */
function vrNatures(): array {
    return [
        'seminar'          => 'Seminar',
        'film_showing'     => 'Film Showing',
        'social_gathering' => 'Social Gathering',
        'lecture'          => 'Lecture',
        'meeting'          => 'Meeting',
        'others'           => 'Others',
    ];
}

function vrStatusLabel(string $s): string { return vrStatuses()[$s] ?? ucfirst($s); }
function vrNatureLabel(string $n, string $other = ''): string {
    if ($n === 'others') { return trim($other) !== '' ? trim($other) : 'Others'; }
    return vrNatures()[$n] ?? ucfirst(str_replace('_', ' ', $n));
}

/**
 * Reservations already holding this venue over this window.
 *
 * The database refuses an overlap outright (an EXCLUDE constraint), but a
 * constraint violation is a 500-shaped error, not an answer. This runs first so
 * the form can name the clash — who booked it and until when — while the
 * constraint stays as the thing that is actually true under concurrency.
 *
 * @param int|null $excludeId Ignore this reservation (editing its own booking).
 */
function vrConflicts(PDO $pdo, string $venue, string $startsAt, string $endsAt, ?int $excludeId = null): array {
    $sql = "SELECT id, vrf_no, applicant_name, department_org, starts_at, ends_at, status
              FROM public.venue_reservations
             WHERE lower(venue) = lower(:v)
               AND status = ANY(:st)
               AND tstzrange(starts_at, ends_at, '[)') && tstzrange(:s, :e, '[)')";
    $params = ['v' => $venue, 'st' => '{' . implode(',', vrHoldingStatuses()) . '}', 's' => $startsAt, 'e' => $endsAt];
    if ($excludeId !== null) { $sql .= " AND id <> :id"; $params['id'] = $excludeId; }
    $sql .= " ORDER BY starts_at";
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { return []; }
}

/**
 * The next VRF number, matching the paper pad's numbering: VRF-<year>-<seq>.
 * Assigned when the PMO approves, because that is when the office releases one.
 */
function vrNextNumber(PDO $pdo): string {
    $year = date('Y');
    try {
        $n = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SPLIT_PART(vrf_no,'-',3) AS INTEGER)),0)
                                 FROM public.venue_reservations
                                WHERE vrf_no LIKE 'VRF-{$year}-%'")->fetchColumn();
    } catch (\Throwable $e) { $n = 0; }
    return sprintf('VRF-%s-%04d', $year, $n + 1);
}

/**
 * Venue names to offer in the picker.
 *
 * Venues already reserved come first — they are the real, curated list, and it
 * grows as the office uses the system. Equipment locations follow as a starting
 * point on day one, when nothing has been reserved yet.
 */
function vrVenueSuggestions(PDO $pdo, int $limit = 120): array {
    $out = [];
    try {
        foreach ($pdo->query("SELECT DISTINCT venue FROM public.venue_reservations ORDER BY venue")->fetchAll(PDO::FETCH_COLUMN) as $v) {
            if (trim((string)$v) !== '') { $out[] = (string)$v; }
        }
    } catch (\Throwable $e) {}
    try {
        $rows = $pdo->query("SELECT DISTINCT location FROM public.equipment
                              WHERE COALESCE(location,'') <> ''
                              ORDER BY location LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $loc) {
            $loc = trim((string)$loc);
            if ($loc !== '' && !in_array($loc, $out, true)) { $out[] = $loc; }
        }
    } catch (\Throwable $e) {}
    return $out;
}

/**
 * Read the repeating materials rows off a POST into [{item, qty}, …].
 * Blank lines are dropped; a quantity is optional (some items are just "sound system").
 */
function vrMaterialsFromPost(array $src): array {
    $items = (array)($src['material_item'] ?? []);
    $qtys  = (array)($src['material_qty'] ?? []);
    $out = [];
    foreach ($items as $i => $item) {
        $item = trim((string)$item);
        if ($item === '') { continue; }
        $qty = (int)($qtys[$i] ?? 0);
        $out[] = ['item' => mb_substr($item, 0, 120), 'qty' => $qty > 0 ? $qty : null];
    }
    return array_slice($out, 0, 30);
}

/** Decode the stored jsonb into a plain array, whatever the driver handed back. */
function vrMaterials($raw): array {
    if (is_array($raw)) { return $raw; }
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : [];
}

/** "Aug 20, 2026 · 8:00 AM – 12:00 NN" (one date when both ends share a day). */
function vrRange($startsAt, $endsAt): string {
    $s = strtotime((string)$startsAt); $e = strtotime((string)$endsAt);
    if (!$s || !$e) { return '—'; }
    $sameDay = date('Y-m-d', $s) === date('Y-m-d', $e);
    return $sameDay
        ? date('M j, Y', $s) . ' · ' . date('g:i A', $s) . ' – ' . date('g:i A', $e)
        : date('M j, Y g:i A', $s) . ' – ' . date('M j, Y g:i A', $e);
}

/**
 * Tell the applicant what happened to their request.
 *
 * sendDefectWorkflowEmail() could not be reused: it is built around a defect
 * report (report id, asset tag, priority) and addresses whole *roles* from the
 * user table, whereas a reservation is addressed to one applicant who may have
 * no account at all. Same letterhead and structure, different facts.
 *
 * Never fatal and never blocking: a reservation decision is recorded whether or
 * not the mail server answers, and sendEmail() already keeps a retry outbox in
 * data/mail_outbox/.
 *
 * @param string $event 'submitted' | 'approved' | 'disapproved'
 * @param array  $opts  Passed through to sendEmail(); ['defer' => true] writes
 *                      the message straight to the outbox instead of sending,
 *                      which is how the template can be inspected without
 *                      delivering anything.
 * @return bool True when a message was handed to the mailer.
 */
function vrNotifyApplicant(array $r, string $event, array $opts = []): bool {
    $to = trim((string)($r['applicant_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { return false; }

    $mailer = __DIR__ . '/mail_helper.php';
    if (!is_file($mailer)) { return false; }
    require_once $mailer;
    if (!function_exists('sendEmail')) { return false; }

    $h    = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $vrf  = trim((string)($r['vrf_no'] ?? ''));
    $venue = (string)($r['venue'] ?? '');
    $when  = vrRange($r['starts_at'] ?? '', $r['ends_at'] ?? '');

    if ($event === 'approved') {
        $subject  = 'Venue reservation approved' . ($vrf !== '' ? ' — ' . $vrf : '') . ' · ' . $venue;
        $headline = 'Your reservation is approved';
        $summary  = $venue . ' is reserved for you on ' . $when . '.';
        $accent   = '#1A7A33';
        $action   = 'Please bring this confirmation, or your VRF number, when you collect the keys from the '
                  . 'Property Management Office. Any assessed fee must be settled with the Cashier before the activity.';
    } elseif ($event === 'disapproved') {
        $subject  = 'Venue reservation not approved · ' . $venue;
        $headline = 'Your reservation was not approved';
        $summary  = 'The Property Management Office could not approve ' . $venue . ' for ' . $when . '.';
        $accent   = '#B42318';
        $action   = 'You are welcome to file another request for a different venue or time. '
                  . 'If you need to discuss it, contact the Property Management Office.';
    } else {
        $subject  = 'Venue reservation request received · ' . $venue;
        $headline = 'We received your request';
        $summary  = $venue . ' is held for ' . $when . ' while the PMO reviews your request.';
        $accent   = '#C9960C';
        $action   = 'Your department head or organisation adviser still needs to endorse the request. '
                  . 'The Property Management Office will confirm once a decision is made — you do not need to do anything else for now.';
    }

    $facts = [
        'Reference'   => $vrf !== '' ? $vrf : 'Issued once approved',
        'Venue'       => $venue,
        'Date & time' => $when,
        'Activity'    => vrNatureLabel((string)($r['nature'] ?? ''), (string)($r['nature_other'] ?? '')),
        'Applicant'   => (string)($r['applicant_name'] ?? ''),
        'Department / Organisation' => (string)($r['department_org'] ?? ''),
    ];
    if (!empty($r['participants'])) { $facts['Expected participants'] = (int)$r['participants']; }

    $rows = '';
    foreach ($facts as $k => $v) {
        if (trim((string)$v) === '') { continue; }
        $rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid #efe3da;font-weight:700;width:38%;">' . $h($k)
               . '</td><td style="padding:10px 0;border-bottom:1px solid #efe3da;">' . $h($v) . '</td></tr>';
    }

    $remarks = trim((string)($r['decision_remarks'] ?? ''));
    $remarksBlock = $remarks === '' ? '' :
        '<div style="margin-top:18px;padding:14px 16px;background:#f8f1eb;border:1px solid #ead9cd;border-radius:10px;">'
      . '<div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#7a5a49;margin-bottom:6px;">'
      . ($event === 'disapproved' ? 'Reason' : 'Remarks from the PMO') . '</div>'
      . '<div style="font-size:14px;line-height:1.65;color:#2f221d;">' . nl2br($h($remarks)) . '</div></div>';

    $fee = '';
    if ($event === 'approved' && ($r['assessment_amount'] ?? null) !== null && (float)$r['assessment_amount'] > 0) {
        $paid = (float)($r['amount_paid'] ?? 0);
        $due  = (float)$r['assessment_amount'] - $paid;
        $fee  = '<div style="margin-top:18px;padding:14px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;">'
              . '<div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#92600a;margin-bottom:6px;">Assessment</div>'
              . '<div style="font-size:14px;line-height:1.65;color:#2f221d;">Assessed: PHP '
              . number_format((float)$r['assessment_amount'], 2)
              . ($paid > 0 ? ' &middot; Paid: PHP ' . number_format($paid, 2) : '')
              . ($due > 0 ? ' &middot; <strong>Balance: PHP ' . number_format($due, 2) . '</strong>' : ' &middot; <strong>Settled</strong>')
              . '</div></div>';
    }

    $message = '
    <html><body style="margin:0;padding:24px;background:#f4efe9;font-family:Arial,sans-serif;color:#241713;">
      <div style="max-width:760px;margin:0 auto;background:#ffffff;border:1px solid #e7d8cc;border-radius:18px;overflow:hidden;">
        <div style="padding:24px 28px;background:linear-gradient(135deg,#6f1d1b,#9f3b2e);color:#ffffff;">
          <div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.82;">Batangas Eastern Colleges &middot; Property Management Office</div>
          <h1 style="margin:10px 0 8px;font-size:26px;line-height:1.25;">' . $h($headline) . '</h1>
          <p style="margin:0;font-size:15px;line-height:1.65;opacity:.94;">' . $h($summary) . '</p>
        </div>
        <div style="padding:24px 28px;">
          <div style="display:inline-block;padding:6px 14px;border-radius:999px;background:' . $accent . ';color:#ffffff;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;margin-bottom:16px;">Venue Reservation</div>
          <table style="width:100%;border-collapse:collapse;">' . $rows . '</table>'
          . $remarksBlock . $fee .
          '<div style="margin-top:18px;padding:16px;border-radius:10px;background:#eef6ff;border:1px solid #cfe0ff;">
            <div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#284b8f;margin-bottom:8px;">What happens next</div>
            <div style="font-size:14px;line-height:1.7;color:#1f2d3d;">' . $h($action) . '</div>
          </div>
          <p style="margin:20px 0 0;font-size:14px;line-height:1.7;">Sincerely,<br><strong>Property Management Office</strong><br>Batangas Eastern Colleges</p>
        </div>
      </div>
    </body></html>';

    try {
        return (bool) sendEmail($to, $subject, $message, null, 'admin', $opts);
    } catch (\Throwable $e) {
        error_log('vrNotifyApplicant failed: ' . $e->getMessage());
        return false;
    }
}

/** How long the booking runs, in plain words. */
function vrDuration($startsAt, $endsAt): string {
    $s = strtotime((string)$startsAt); $e = strtotime((string)$endsAt);
    if (!$s || !$e || $e <= $s) { return '—'; }
    $mins = (int)round(($e - $s) / 60);
    $h = intdiv($mins, 60); $m = $mins % 60;
    if ($h === 0) { return $m . ' min'; }
    return $h . 'h' . ($m ? ' ' . $m . 'm' : '');
}
