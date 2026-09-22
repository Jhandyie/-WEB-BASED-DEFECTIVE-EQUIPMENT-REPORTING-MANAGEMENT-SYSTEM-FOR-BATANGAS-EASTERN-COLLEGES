<?php
// student/student_dashboard.php
require_once __DIR__ . '/includes/session_bootstrap.php';
startPublicSession();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/mail_helper.php';
require_once __DIR__ . '/includes/csrf.php';

if (isset($_GET['logout'])) {
    becEndGuestSession();
    header('Location: student_index.php');
    exit();
}

// Guard — must have a guest session that has not gone stale. becGuestSessionActive()
// also refreshes the idle clock, so this is the page that keeps a working
// session alive.
if (empty($_SESSION['guest_email']) || empty($_SESSION['guest_name']) || !becGuestSessionActive()) {
    becEndGuestSession();
    header('Location: student_index.php?expired=1');
    exit();
}

$student_name  = $_SESSION['guest_name'];
$student_email = $_SESSION['guest_email'];

// The form asks this the moment a unit is chosen on the Equipment step. The
// same lookup used to run only on submit, so a reporter filled all five steps
// and attached their photos before being told the fault was already filed.
if (isset($_GET['check_open'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $open = function_exists('findOpenReportForEquipment')
        ? findOpenReportForEquipment((string)$_GET['check_open']) : null;
    // Whose report it is changes what the notice should say. "This equipment
    // already has an open report" read, to a reporter who had just signed in,
    // as an accusation that they had filed something - when the unit had been
    // reported by somebody else, or by them last week.
    $mine = false; $eqName = ''; $when = '';
    if ($open && function_exists('getDefectReportById')) {
        $full = getDefectReportById((string)$open['report_id']);
        if ($full) {
            $mine   = strcasecmp(trim((string)($full['reporter_email'] ?? '')), trim($student_email)) === 0;
            $eqName = (string)($full['equipment_name'] ?? '');
            $when   = !empty($full['report_date']) ? date('M j', strtotime((string)$full['report_date'])) : '';
        }
    }
    echo json_encode(['open' => $open ? [
        'report_id' => (string)($open['report_id'] ?? ''),
        'status'    => ucwords(str_replace('_', ' ', (string)($open['status'] ?? ''))),
        'mine'      => $mine,
        'equipment' => $eqName,
        'when'      => $when,
    ] : null]);
    exit();
}

// What the system will file the typed equipment under, shown as a quiet line
// beneath the name as the reporter types. The category select this replaces
// asked the reporter to answer a question the words they had just typed
// already answered. No database access: a keyword table on both counts.
if (isset($_GET['guess_category'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $text = mb_substr(trim((string)$_GET['guess_category']), 0, 200);
    $cat  = $text === '' ? '' : inferEquipmentCategory($text);
    $unit = $text === '' ? '' : classifyDepartmentByEquipment('', $text, $cat);
    echo json_encode(['category' => $cat, 'unit' => $unit]);
    exit();
}

/*
 * Official BEC academic structure (department => specific course/program offerings).
 * Source: bec.edu.ph (College, Senior High School Tracks, Technical-Vocational Center).
 * Departments with an empty list have no specific course — the course field stays disabled.
 */
$becPrograms = [
    'Pre-School'                          => [
        'Nursery',
        'Kindergarten',
    ],
    'Grade School'                        => [
        'Grade 1',
        'Grade 2',
        'Grade 3',
        'Grade 4',
        'Grade 5',
        'Grade 6',
    ],
    'Junior High School'                  => [
        'Grade 7',
        'Grade 8',
        'Grade 9',
        'Grade 10',
    ],
    // Every strand below has students enrolled in it in the official
    // (SY2026-2027) roster. The first five were the only ones offered here, so
    // a Hospitality and Tourism or Business and Entrepreneurship student had
    // nothing correct to choose.
    'Senior High School'                  => [
        'STEM — Science, Technology, Engineering and Mathematics',
        'ABM — Accountancy, Business and Management',
        'HUMSS — Humanities and Social Sciences',
        'ASSH — Arts, Social Sciences and Humanities',
        'BE — Business and Entrepreneurship',
        'HT — Hospitality and Tourism',
        'TVL — Home Economics (HE)',
        'TVL — Information and Communications Technology (ICT)',
        'ICT — Computer Programming',
        'ICT — Computer Hardware Servicing',
        'ICT — Support and Computer Programming Technologies (ISCPT)',
    ],
    'College of Teacher Education'        => [
        'Bachelor of Elementary Education',
        'Bachelor of Secondary Education major in English',
        'Bachelor of Secondary Education major in Filipino',
        'Bachelor of Secondary Education major in Mathematics',
        'Bachelor of Secondary Education major in Science',
        'Bachelor of Secondary Education major in Social Studies',
        'Bachelor of Secondary Education major in Values Education',
        'Teacher Certificate Program',
    ],
    'College of Business'                 => [
        'Bachelor of Science in Accountancy',
        'Bachelor of Science in Accounting Information Systems',
        'Bachelor of Science in Business Administration major in Financial Management',
        'Bachelor of Science in Business Administration major in Human Resource Management',
        'Bachelor of Science in Business Administration major in Marketing Management',
    ],
    'College of Computer Studies'         => [
        'Bachelor of Science in Information Systems',
    ],
    'Technical-Vocational Center'         => [
        'Computer Systems Servicing NC II',
        'Contact Center Services NC II',
        'Electronic Products Assembly and Servicing NC II',
        'Visual Graphic Design NC III',
        'Cookery NC II',
        'Food and Beverage Services NC II',
        'Front Office Services NC II',
        'Housekeeping NC II',
        'Bartending NC II',
        'Diploma in Hospitality Management',
    ],
    'Administrative / Non-teaching Office' => [
        "Registrar's Office",
        'Admissions Office',
        'Cashier / Finance Office',
        'Accounting Office',
        'Human Resources Office',
        'Property Management Office (PMO)',
        'Information Technology Office (ITSO)',
        'Library',
        'Guidance and Counseling Office',
        'Clinic / Medical and Dental',
        'Security Office',
        'General Services / Maintenance',
        "Administrator's / Principal's Office",
        'Other Office',
    ],
];

/**
 * Departments where the programme alone does not say which year group the
 * reporter is in, and the levels each one offers.
 *
 * Pre-School, Grade School and Junior High already name the level in the
 * programme itself (Kindergarten, Grade 4, Grade 9). Senior High named only the
 * strand, and the colleges only the degree, so a Grade 11 and a Grade 12
 * student — or a first-year and a graduating student — filed reports that read
 * identically.
 *
 * Kept as a separate field rather than folded into the programme names: a
 * degree crossed with four years gives options like "Bachelor of Science in
 * Business Administration major in Human Resource Management - 4th Year", 92
 * characters that a phone's native picker truncates, in a list of 28. Two short
 * lists beat one long one on the screen most reporters use.
 */
$becLevels = [
    'Senior High School'           => ['Grade 11', 'Grade 12'],
    'College of Teacher Education' => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
    'College of Business'          => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
    'College of Computer Studies'  => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
];

/*
 * What this reporter told us last time.
 *
 * Their department, course, year level and contact number are already on their
 * BEC directory record — becSyncReporterProfile() has been writing them there
 * on every submit — but nothing ever read them back, so the form asked for all
 * four again on every single report. The registrar's own wording is not the
 * form's wording, so the values are matched onto the options this page offers
 * rather than trusted verbatim; anything that cannot be matched is simply left
 * for the reporter to choose.
 */
require_once __DIR__ . '/includes/bec_directory_helper.php';
$reporterProfile = function_exists('becdir_lookup') ? becdir_lookup($student_email) : null;
$prefill = becdir_form_prefill($reporterProfile, $becPrograms, $becLevels);

// The form no longer asks any of this. What the directory holds is written
// onto the report as it is; what it does not hold is left blank, and the PMO
// sees "—" rather than the reporter seeing four pickers. Who they are —
// student, teacher or staff — was one tap at sign-in and rides in the session.
$preDept   = $prefill['department'];
$preCourse = $prefill['course'];
$preLevel  = $prefill['level'];
$reporterType = reporterCanonType((string)($_SESSION['guest_role'] ?? ''));
if ($reporterType === '') {
    // A session that predates the sign-in question. The directory knows every
    // student; anyone else simply goes unlabelled rather than being blocked.
    $reporterType = reporterCanonType((string)($reporterProfile['user_type'] ?? ''));
}

// Pre-fill equipment from a scanned QR code (?eq=EQUIPMENT_ID)
$prefillEq = null;
$eqParam = trim((string)($_GET['eq'] ?? ''));
if ($eqParam !== '' && function_exists('getEquipmentById')) {
    $eqRow = getEquipmentById($eqParam);
    if ($eqRow) {
        $prefillEq = [
            'id'        => (string)($eqRow['equipment_id'] ?? $eqParam),
            'name'      => (string)($eqRow['equipment_name'] ?? ''),
            'category'  => (string)($eqRow['category'] ?? ($eqRow['equipment_category'] ?? '')),
            'asset_tag' => (string)($eqRow['asset_tag'] ?? ''),
            'location'  => (string)($eqRow['location'] ?? ''),
        ];
    }
}

$error   = '';
$success = '';
$ticket  = '';
$email_notice = '';
$conn = getDBConnection();

// The whole inventory — 1,300 rows — used to be fetched here on every open and
// inlined into the page as the search dropdown's data. The dropdown is gone
// (the panel found it in the way of simply naming the thing), so the page no
// longer needs the list at all; a typed name is matched on submit with one
// targeted query instead. Two fewer Supabase round trips per open.

// Canonical BEC categories + campus/building/room locations from the PMO inventory.
require_once __DIR__ . '/data/bec_inventory_reference.php';

/*
 * Locations keep the PMO inventory's own order — Main Campus, then Annex 1,
 * then Annex 2, and inside each the buildings as the workbook lists them.
 * Sorting this alphabetically buried Main Campus behind every Annex 1 room, so
 * a reporter opening the list saw a screen of buildings on the wrong campus and
 * concluded the list was out of date.
 */
$location_options = becLocations();

/** "Campus • Building • Room" split into the parts the picker shows separately. */
$locationParts = array_map(static function (string $full): array {
    $bits = array_map('trim', explode('•', $full));
    return [
        'full'   => $full,
        'campus' => $bits[0] ?? '',
        'bldg'   => $bits[1] ?? '',
        'room'   => $bits[2] ?? ($bits[1] ?? $full),
    ];
}, $location_options);

function getGuestReporterId(): string {
    if (!empty($_SESSION['guest_reporter_id'])) {
        return (string)$_SESSION['guest_reporter_id'];
    }

    $seed = strtolower((string)($_SESSION['guest_email'] ?? 'guest')) . '|' . (string)($_SESSION['guest_since'] ?? time());
    $guestId = 'GST-' . strtoupper(substr(md5($seed), 0, 12));
    $_SESSION['guest_reporter_id'] = $guestId;
    return $guestId;
}

function inferReportPriority(string $description): string {
    $text = strtolower($description);

    $criticalKeywords = ['urgent', 'fire', 'smoke', 'sparking', 'spark', 'shock', 'exploded', 'cannot use', 'won\'t turn on', 'will not turn on', 'offline', 'no power'];
    foreach ($criticalKeywords as $keyword) {
        if (strpos($text, $keyword) !== false) {
            return 'critical';
        }
    }

    $highKeywords = ['broken', 'not working', 'failed', 'failure', 'error', 'damaged', 'flicker', 'black screen', 'restart', 'cannot connect'];
    foreach ($highKeywords as $keyword) {
        if (strpos($text, $keyword) !== false) {
            return 'high';
        }
    }

    $lowKeywords = ['minor', 'loose', 'slow', 'faded', 'dim', 'small'];
    foreach ($lowKeywords as $keyword) {
        if (strpos($text, $keyword) !== false) {
            return 'low';
        }
    }

    return 'medium';
}

function notifyAdminsOfStudentReport($conn, string $reportId, string $equipmentName, string $location, string $studentName): void {
    $adminResult = $conn->query("SELECT user_id FROM users WHERE role = 'admin' AND status = 'active' AND user_id IS NOT NULL AND user_id != ''");
    if (!$adminResult) {
        return;
    }

    $message = sprintf(
        'New student report %s submitted by %s for %s%s.',
        $reportId,
        $studentName,
        $equipmentName,
        $location !== '' ? ' at ' . $location : ''
    );

    while ($admin = $adminResult->fetch_assoc()) {
        $adminId = trim((string)($admin['user_id'] ?? ''));
        if ($adminId === '') {
            continue;
        }
        addNotification($adminId, $message, 'new_defect_report', $reportId);
    }
}

function ensureStudentManualCategoryId($conn, string $category): ?int {
    $category = trim($category);
    if ($category === '') {
        $category = 'Other / Not sure';
    }

    $stmt = $conn->prepare("SELECT category_id FROM categories WHERE category_name = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("s", $category);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($existing && isset($existing['category_id'])) {
        return (int)$existing['category_id'];
    }

    $description = 'Created from a manual student report entry.';
    $stmt = $conn->prepare("INSERT INTO categories (category_name, description) VALUES (?, ?)");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("ss", $category, $description);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $categoryId = (int)$conn->insert_id;
    $stmt->close();

    return $categoryId > 0 ? $categoryId : null;
}

/**
 * The catalogued unit a report is about, or null when the reporter named
 * something the inventory does not hold (a MAN- row is created for those).
 *
 * Tried in order of how sure each one is: the id carried by a scanned QR code,
 * then the asset tag, then the name the reporter typed. A name is matched as a
 * whole, case-insensitively, and where several units share it the one in the
 * room being reported wins — "Aircon" exists in a hundred rooms.
 *
 * Returns the same shape as createStudentManualEquipment(), so the caller
 * does not care which of the two answered.
 */
function findReporterEquipment($conn, string $id, string $assetTag, string $name, string $location): ?array {
    $select = "SELECT e.equipment_id, e.equipment_name, e.asset_tag, e.location,
                      COALESCE(c.category_name, '') AS category_name
                 FROM equipment e LEFT JOIN categories c ON c.category_id = e.category_id
                WHERE e.status != 'deleted' AND ";
    $tries = [];
    if ($id !== '')       { $tries[] = ["e.equipment_id = ? LIMIT 1", 's', [$id]]; }
    if ($assetTag !== '') { $tries[] = ["upper(e.asset_tag) = upper(?) LIMIT 1", 's', [$assetTag]]; }
    if ($name !== '')     { $tries[] = ["lower(e.equipment_name) = lower(?) ORDER BY (e.location = ?) DESC, e.equipment_id LIMIT 1", 'ss', [$name, $location]]; }
    foreach ($tries as [$where, $types, $args]) {
        $stmt = $conn->prepare($select . $where);
        if (!$stmt) { continue; }
        $stmt->bind_param($types, ...$args);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            return [
                'id'        => (string)$row['equipment_id'],
                'name'      => (string)$row['equipment_name'],
                'category'  => (string)$row['category_name'],
                'asset_tag' => (string)$row['asset_tag'],
                'location'  => (string)$row['location'],
            ];
        }
    }
    return null;
}

function createStudentManualEquipment($conn, string $name, string $category, string $location, string $assetTag = '', string $description = ''): ?array {
    $name = trim($name);
    $category = trim($category) !== '' ? trim($category) : 'Other / Not sure';
    $location = trim($location);
    $assetTag = strtoupper(trim($assetTag));

    if ($name === '') {
        return null;
    }

    if ($assetTag !== '') {
        $stmt = $conn->prepare("SELECT e.equipment_id, e.equipment_name, e.asset_tag, e.location, COALESCE(c.category_name, '') AS category_name FROM equipment e LEFT JOIN categories c ON c.category_id = e.category_id WHERE e.asset_tag = ? AND e.status != 'deleted' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $assetTag);
            $stmt->execute();
            $result = $stmt->get_result();
            $existing = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if ($existing) {
                return [
                    'id' => (string)$existing['equipment_id'],
                    'name' => (string)$existing['equipment_name'],
                    'category' => (string)($existing['category_name'] ?: $category),
                    'asset_tag' => (string)$existing['asset_tag'],
                    'location' => (string)$existing['location'],
                ];
            }
        }
    }

    $seed = strtoupper(substr(md5($name . '|' . $location . '|' . microtime(true)), 0, 10));
    $equipmentId = 'MAN-' . $seed;
    $finalAssetTag = $assetTag !== '' ? $assetTag : 'MAN-' . $seed;
    $categoryId = ensureStudentManualCategoryId($conn, $category);
    $description = 'Manual student report entry. Review and merge with inventory if needed.';

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $stmt = $conn->prepare("INSERT INTO equipment (equipment_id, asset_tag, equipment_name, category_id, description, location, status, condition_status, quantity, min_stock_level, reorder_point) VALUES (?, ?, ?, ?, ?, ?, 'available', 'fair', 1, 1, 0)");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("sssiss", $equipmentId, $finalAssetTag, $name, $categoryId, $description, $location);
        if ($stmt->execute()) {
            $stmt->close();
            // Categorize the new equipment by unit (ITSO for computers/network, PMO otherwise).
            $unit = classifyDepartmentByEquipment($equipmentId, $name, $category, $location, $description);
            if ($us = $conn->prepare("UPDATE equipment SET unit = ? WHERE equipment_id = ?")) {
                $us->bind_param("ss", $unit, $equipmentId); $us->execute(); $us->close();
            }
            return [
                'id' => $equipmentId,
                'name' => $name,
                'category' => $category,
                'asset_tag' => $finalAssetTag,
                'location' => $location,
            ];
        }
        $stmt->close();

        $seed = strtoupper(substr(md5($seed . '|' . $attempt . '|' . microtime(true)), 0, 10));
        $equipmentId = 'MAN-' . $seed;
        if ($assetTag === '') {
            $finalAssetTag = 'MAN-' . $seed;
        }
    }

    return null;
}

function buildStudentTicketEmail(string $student_name, string $ticket, array $report): string {
    $equipment = htmlspecialchars((string)($report['equipment_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $category = htmlspecialchars((string)($report['category'] ?? ''), ENT_QUOTES, 'UTF-8');
    $location = htmlspecialchars((string)($report['location'] ?? ''), ENT_QUOTES, 'UTF-8');
    $description = nl2br(htmlspecialchars((string)($report['defect_description'] ?? ''), ENT_QUOTES, 'UTF-8'));
    $studentName = htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8');
    $ticketEsc = htmlspecialchars($ticket, ENT_QUOTES, 'UTF-8');

    $year = date('Y');
    return <<<HTML
<body style="margin:0;padding:0;background:#eef0f3;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#eef0f3;">
<tr><td align="center" style="padding:28px 14px 40px;">
<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e2d9cc;border-radius:14px;overflow:hidden;">

  <tr><td style="height:4px;background:#C9960C;font-size:0;line-height:0;">&nbsp;</td></tr>
  <tr><td style="background:#4A0E0E;padding:26px 32px;text-align:center;">
    <div style="font-family:Georgia,'Times New Roman',serif;font-size:17px;font-weight:700;color:#ffffff;letter-spacing:.5px;">Batangas Eastern Colleges</div>
    <div style="font-size:12px;color:rgba(255,255,255,.72);margin-top:4px;">Property Management Office</div>
    <div style="font-size:11px;color:rgba(201,150,12,.95);margin-top:10px;text-transform:uppercase;letter-spacing:1.4px;font-weight:700;">Defective Equipment Reporting Management System</div>
  </td></tr>

  <tr><td style="padding:30px 32px 8px;color:#1C1008;">
    <p style="margin:0 0 6px;font-size:17px;font-weight:700;color:var(--ok-tx);">&#10003; Report Received</p>
    <p style="margin:0 0 4px;font-size:14px;color:#5C3838;">Hello {$studentName},</p>
    <p style="margin:0 0 20px;font-size:14px;line-height:1.65;color:#5C3838;">Thank you for helping keep our campus equipment in good condition. Your defect report has been logged. Please keep the ticket number below to track its progress.</p>

    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#FFFBEF;border:1px solid #F0D58A;border-radius:10px;">
    <tr><td align="center" style="padding:18px 16px;">
      <div style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#8A7466;">Ticket Number</div>
      <div style="font-size:26px;font-weight:800;color:#7B1D1D;margin-top:6px;letter-spacing:1px;">{$ticketEsc}</div>
    </td></tr></table>
  </td></tr>

  <tr><td style="padding:8px 32px 4px;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="font-size:13.5px;color:#1C1008;">
      <tr><td style="padding:9px 0;font-weight:700;width:150px;color:#5C3838;border-bottom:1px solid #efe7da;">Equipment</td><td style="padding:9px 0;border-bottom:1px solid #efe7da;">{$equipment}</td></tr>
      <tr><td style="padding:9px 0;font-weight:700;color:#5C3838;border-bottom:1px solid #efe7da;">Category</td><td style="padding:9px 0;border-bottom:1px solid #efe7da;">{$category}</td></tr>
      <tr><td style="padding:9px 0;font-weight:700;color:#5C3838;border-bottom:1px solid #efe7da;">Location</td><td style="padding:9px 0;border-bottom:1px solid #efe7da;">{$location}</td></tr>
      <tr><td style="padding:9px 0;font-weight:700;color:#5C3838;vertical-align:top;">Description</td><td style="padding:9px 0;">{$description}</td></tr>
    </table>
  </td></tr>

  <tr><td style="padding:18px 32px 6px;">
    <div style="font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#8A7466;margin-bottom:10px;">What happens next</div>
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="font-size:12.5px;color:#5C3838;line-height:1.5;">
      <tr><td style="padding:4px 0;"><strong style="color:#7B1D1D;">1. Pending Review</strong> &mdash; the PMO reviews your report.</td></tr>
      <tr><td style="padding:4px 0;"><strong style="color:#7B1D1D;">2. Approved &amp; Assigned</strong> &mdash; a technician is assigned to the task.</td></tr>
      <tr><td style="padding:4px 0;"><strong style="color:#7B1D1D;">3. In Progress</strong> &mdash; the technician inspects and repairs the equipment.</td></tr>
      <tr><td style="padding:4px 0;"><strong style="color:#7B1D1D;">4. Completed</strong> &mdash; the repair is verified and you are notified.</td></tr>
    </table>
  </td></tr>

  <tr><td style="padding:16px 32px 26px;">
    <p style="margin:0;font-size:12.5px;line-height:1.6;color:#5C3838;background:#FBF9F6;border-left:3px solid #C9960C;border-radius:8px;padding:12px 14px;">
      <strong>Track your report:</strong> visit the reporter portal and enter your ticket number <strong>{$ticketEsc}</strong> to see live status updates.</p>
  </td></tr>

  <tr><td style="background:#FBF9F6;border-top:1px solid #e2d9cc;padding:16px 32px;text-align:center;">
    <div style="font-size:11px;color:#9E8070;line-height:1.7;">
      This is an automated message — please do not reply.<br>
      &copy; {$year} Batangas Eastern Colleges · Property Management Office
    </div>
  </td></tr>

</table></td></tr></table></body>
HTML;
}

// ── POST handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Every admin page enforces this; the public submit form was the one that
    // did not, so another site could silently file reports as a signed-in
    // reporter. Reported as a normal form error rather than a bare 403 page.
    if (!csrf_check()) {
        $error = 'Your session expired for security reasons. Please review your details and submit again.';
    }
    // A signed-in reporter is identified but not vetted — the sign-in gate only
    // proves the email is in the BEC directory. This keeps one person (or one
    // script running with their session) from burying the PMO in reports.
    // Deliberately generous: a real reporter never files 15 in an hour.
    if (!$error) {
        require_once __DIR__ . '/includes/rate_limiter.php';
        try {
            RateLimiter::enforce('report_submit:' . strtolower((string)($_SESSION['guest_email'] ?? RateLimiter::clientIp())), 15, 3600);
        } catch (\Throwable $rl) {
            $error = 'You have submitted several reports in a short time. Please wait a few minutes before filing another — if this is urgent, contact the PMO directly.';
        }
    }
    $selected_equipment_id = trim((string)($_POST['equipment_id'] ?? ''));
    $postedEquipmentName   = trim((string)($_POST['equipment_name'] ?? ''));
    $postedAssetTag        = trim((string)($_POST['asset_tag'] ?? ''));
    $postedLocation        = trim((string)($_POST['location'] ?? ''));
    $postedDescription     = trim((string)($_POST['defect_description'] ?? ''));
    $_POST['equipment_name'] = $postedEquipmentName;

    // The four things the form asks. Everything else the report carries is
    // worked out from these, the session and the directory.
    if (!$error && $postedEquipmentName === '') {
        $error = 'Please tell us what is broken.';
    } elseif (!$error && $postedLocation === '') {
        $error = 'Please tell us where the equipment is.';
    } elseif (!$error && $postedDescription === '') {
        $error = 'Please describe what is wrong.';
    } elseif (!$error && mb_strlen($postedDescription) > 1500) {
        // The textarea carries maxlength="1500", but that is only a browser
        // hint — a 200,000-character description was accepted and stored
        // verbatim, which is how pasted junk ended up bloating report pages.
        $error = 'Please keep the problem description under 1,500 characters.';
    }

    // A catalogued unit, or null when the reporter named something the
    // inventory does not hold. The MAN- row for those is created further down,
    // only once the whole submission has been accepted, so a refused submit
    // (no photo, say) leaves nothing behind in the equipment table.
    $selected_equipment = $error ? null
        : findReporterEquipment($conn, $selected_equipment_id, $postedAssetTag, $postedEquipmentName, $postedLocation);
    $inferredCategory = inferEquipmentCategory($postedEquipmentName, $postedDescription);

    if ($selected_equipment) {
        $_POST['equipment_id']   = $selected_equipment['id'];
        $_POST['equipment_name'] = $selected_equipment['name'];
        $_POST['asset_tag']      = $selected_equipment['asset_tag'];
    } else {
        $_POST['equipment_id']   = '';
    }
    // Shown in the confirmation email. An inventory unit already carries a
    // category; anything else gets the one the words imply.
    $_POST['category'] = ($selected_equipment && $selected_equipment['category'] !== '') ? $selected_equipment['category'] : $inferredCategory;

    // From the BEC directory, silently — see $prefill above.
    $reporterDepartment = $preDept;
    $reporterCourse     = $preCourse;
    $reporterLevel      = $preLevel;

    // Duplicate guard: this equipment may already have an open report.
    $duplicateFound = null;
    if (!$error && empty($_POST['duplicate_override']) && function_exists('findOpenReportForEquipment')) {
        $duplicateFound = findOpenReportForEquipment((string)($_POST['equipment_id'] ?? ''));
    }

    if (!$error && !$duplicateFound) {
        // Generate ticket number
        require_once __DIR__ . '/includes/ticket.php';
        $ticket = generateTicketNumber();
        $reportPriority = inferReportPriority((string)($_POST['defect_description'] ?? ''));

        // Handle photo uploads (multiple — up to 10 images, 10MB each).
        // Validate by ACTUAL image content (getimagesize) and save with a derived
        // safe extension — never trust the client MIME type or original filename
        // (prevents uploading an executable file disguised as an image).
        $photo_path = null;
        $photo_paths = [];
        if (!empty($_FILES['photos']) && is_array($_FILES['photos']['tmp_name'] ?? null)) {
            $allowedImg = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            $max_size  = 10 * 1024 * 1024; // 10MB per image
            $max_count = 10;
            $count = 0;
            foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
                if (($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                if ($count >= $max_count) { $error = 'You can upload up to ' . $max_count . ' photos per report.'; break; }
                if (!is_uploaded_file($tmp)) { continue; }
                $size = (int)($_FILES['photos']['size'][$i] ?? 0);
                if ($size <= 0 || $size > $max_size) { $error = 'Each photo must be a valid image under 10MB.'; break; }
                $info = @getimagesize($tmp);
                if ($info === false || !isset($allowedImg[$info[2]])) { $error = 'Photos must be JPG, PNG or WEBP. An iPhone HEIC photo will not upload — screenshot it and attach that, or set Settings › Camera › Formats › Most Compatible.'; break; }
                $safeExt = $allowedImg[$info[2]];
                $rel  = 'uploads/reports/' . $ticket . '_' . ($count + 1) . '.' . $safeExt;
                $dest = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                $dir  = dirname($dest);
                if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
                if (move_uploaded_file($tmp, $dest)) {
                    @chmod($dest, 0644);
                    $photo_paths[] = $rel;
                    $count++;
                } else { $error = 'Photo upload failed. Please try again.'; break; }
            }
            if (!$error && $photo_paths) { $photo_path = $photo_paths[0]; }
        }

        // Handle short video evidence (up to 2 clips, 20MB each). Validate by REAL MIME
        // (finfo) — never trust the client type — and save with a derived safe extension.
        $video_paths = [];
        if (!$error && !empty($_FILES['videos']) && is_array($_FILES['videos']['tmp_name'] ?? null)) {
            $allowedVid = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
            $vmax_size  = 20 * 1024 * 1024; // 20MB per video
            $vmax_count = 2;
            $vcount = 0;
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
            foreach ($_FILES['videos']['tmp_name'] as $i => $tmp) {
                if (($_FILES['videos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                if ($vcount >= $vmax_count) { $error = 'You can upload up to ' . $vmax_count . ' videos per report.'; break; }
                if (!is_uploaded_file($tmp)) { continue; }
                $size = (int)($_FILES['videos']['size'][$i] ?? 0);
                if ($size <= 0 || $size > $vmax_size) { $error = 'Each video must be a valid clip under 20MB.'; break; }
                $mime = $finfo ? finfo_file($finfo, $tmp) : (string)($_FILES['videos']['type'][$i] ?? '');
                if (!isset($allowedVid[$mime])) { $error = 'Videos must be MP4, WEBM, or MOV files.'; break; }
                $safeExt = $allowedVid[$mime];
                $rel  = 'uploads/reports/' . $ticket . '_v' . ($vcount + 1) . '.' . $safeExt;
                $dest = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                $dir  = dirname($dest);
                if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
                if (move_uploaded_file($tmp, $dest)) {
                    @chmod($dest, 0644);
                    $video_paths[] = $rel;
                    $vcount++;
                } else { $error = 'Video upload failed. Please try again.'; break; }
            }
            if ($finfo) { finfo_close($finfo); }
        }

        // Evidence is part of the report, not an extra. A technician sent to a
        // "broken aircon" with nothing to look at first has to go and look; a
        // photo or a short clip is the difference between one trip and two.
        // The page blocks this earlier; this is for a submit that got past it.
        if (!$error && !$photo_paths && !$video_paths) {
            $error = 'Please add at least one photo or video of the problem.';
        }

        // Not in the inventory: file it under the inferred category and let
        // the PMO merge it later. Everything about the report has been
        // accepted by this point, so the row will have a report to belong to.
        if (!$error && !$selected_equipment) {
            $selected_equipment = createStudentManualEquipment($conn, $postedEquipmentName, $inferredCategory, $postedLocation, $postedAssetTag, $postedDescription);
            if ($selected_equipment) {
                $_POST['equipment_id']   = $selected_equipment['id'];
                $_POST['equipment_name'] = $selected_equipment['name'];
                $_POST['asset_tag']      = $selected_equipment['asset_tag'];
            } else {
                $error = 'We could not save that equipment entry. Please check the details and try again.';
            }
        }

        if (!$error) {
            $reportPayload = [
                'report_id' => $ticket,
                'equipment_id' => trim($_POST['equipment_id']),
                'equipment_name' => trim((string)($_POST['equipment_name'] ?? '')),
                'location' => trim((string)($_POST['location'] ?? '')),
                'department_assigned' => equipmentUnit(trim((string)($_POST['equipment_id'] ?? ''))),
                'reported_by' => getGuestReporterId(),
                'reporter_name' => $student_name,
                'reporter_email' => $student_email,
                'reporter_department' => $reporterDepartment,
                'reporter_course' => $reporterCourse,
                'reporter_level' => $reporterLevel,
                'reporter_type' => $reporterType,
                'issue_description' => trim($_POST['defect_description']),
                'priority' => $reportPriority,
                'status' => 'reported',
            ];

            if ($photo_path !== null) {
                $reportPayload['photo_path'] = $photo_path;
                $reportPayload['defect_photos'] = $photo_paths;
            }
            if ($video_paths) {
                $reportPayload['defect_videos'] = $video_paths;
            }

            $saved = addDefectReport($reportPayload);

            if (!$saved) {
                $error = 'We could not save your report right now. Please try again.';
            } else {
                notifyAdminsOfStudentReport(
                    $conn,
                    $ticket,
                    trim((string)($_POST['equipment_name'] ?? 'Equipment')),
                    trim((string)($_POST['location'] ?? '')),
                    $student_name
                );

                $subject = "BEC Equipment Report Received - Ticket $ticket";
                $emailBody = buildStudentTicketEmail($student_name, $ticket, $_POST);
                $emailSent = sendEmail($student_email, $subject, $emailBody, null, 'student');
                if (!$emailSent) {
                    $email_notice = 'Your report was submitted, but we could not send the ticket email right now. Please save your ticket number.';
                }

                $success = true;
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Submit Equipment Report — BEC PMO</title>
<meta name="theme-color" content="#4A0E0E">
<link rel="icon" type="image/png" href="assets/logs.png">
<link rel="apple-touch-icon" href="assets/logs.png">
<!-- Served from this server, not a CDN, so the reporter keeps icons and
     typefaces when the campus connection is unavailable. -->
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="css/typography.css">
<style>
/* Root size bump — MUST stay in <head>. Setting this from an end-of-body
   include repaints, then re-lays-out, the whole page on every load. */
html{font-size:106.25%;scrollbar-gutter:stable;}

:root {
  /* Semantic status ramp — the same values as assets/css/admin-shell.css,
     repeated because this page does not load the admin shell. One meaning,
     one colour, across every surface. */
  --ok:#16A34A;--ok-tx:#166534;--ok-bg:#F0FDF4;--ok-bdr:#BBF7D0;
  --warn:#D97706;--warn-tx:#92600A;--warn-bg:#FFFBEB;--warn-bdr:#FDE68A;
  --bad:#DC2626;--bad-tx:#991B1B;--bad-bg:#FEF2F2;--bad-bdr:#FECACA;
  --info:#2563EB;--info-tx:#1D4ED8;--info-bg:#EFF6FF;--info-bdr:#BFDBFE;

  --maroon: #7B1D1D;
  --maroon-d: #4A0E0E;
  --maroon-dd: #2D0505;
  --maroon-soft: rgba(123,29,29,.07);
  --gold: #C9960C;
  --gold-l: #F0C040;
  --gold-bg: #FFFBEF;
  --ink: #1C1008;
  --ink2: #5C3838;
  --ink3: #755B4E;
  --paper: #F8F3EA;
  --surface: #FFFFFF;
  --border: #E8DDD0;
  --green: var(--ok-tx);
  --green-bg: #F0FDF4;
  --green-border: #BBF7D0;
  --shadow-sm: 0 1px 4px rgba(44,10,10,.05), 0 4px 16px rgba(44,10,10,.07);
  --shadow-md: 0 2px 8px rgba(44,10,10,.06), 0 12px 40px rgba(44,10,10,.10);
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}

body {
  font-family: 'DM Sans', sans-serif;
  background: var(--paper);
  min-height: 100vh;
  padding: 1.5rem 1rem 4rem;
  position: relative;
}

html {
  -webkit-text-size-adjust: 100%;
}
body::before {
  content:'';position:fixed;top:-200px;right:-200px;
  width:550px;height:550px;border-radius:50%;
  background:radial-gradient(circle,rgba(201,150,12,.1) 0%,transparent 65%);
  pointer-events:none;z-index:0;
}
body::after {
  content:'';position:fixed;bottom:-160px;left:-160px;
  width:450px;height:450px;border-radius:50%;
  background:radial-gradient(circle,rgba(123,29,29,.08) 0%,transparent 65%);
  pointer-events:none;z-index:0;
}
.bg-grid {
  position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:radial-gradient(circle,rgba(123,29,29,.1) 1px,transparent 1px);
  background-size:32px 32px;
  mask-image:radial-gradient(ellipse 80% 80% at 50% 50%,black 0%,transparent 100%);
}

/* ── LAYOUT ── */
.page { max-width: 760px; margin: 0 auto; position: relative; z-index: 1; width: 100%; }

/* ── TOP BAR ── */
.topbar {
  display: flex; align-items: center; justify-content: space-between;
  gap: .9rem;
  flex-wrap: wrap;
  margin-bottom: 2rem;
  animation: fadeDown .5s ease both;
}
@keyframes fadeDown { from{opacity:0;transform:translateY(-12px)} to{opacity:1;transform:none} }

.logo-row { display:flex;align-items:center;gap:.65rem; }
.logo-seal {
  width:38px;height:38px;border-radius:50%;
  background:#fff;
  border:1px solid rgba(123,29,29,.14);
  display:flex;align-items:center;justify-content:center;
  box-shadow:0 0 0 3px rgba(123,29,29,.15);
  overflow:hidden;
}
.logo-seal img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
}
.logo-text strong { display:block;font-size:.78rem;font-weight:600;color:var(--ink); }
.logo-text span   { font-size:.66rem;color:var(--ink3);text-transform:uppercase;letter-spacing:1.5px; }

.user-chip {
  display:flex;align-items:center;gap:.5rem;
  background:var(--surface);border:1px solid var(--border);
  border-radius:40px;padding:.35rem .5rem .35rem .45rem;
  box-shadow:var(--shadow-sm);
  font-size:.8rem;color:var(--ink2);
  max-width:100%;
  min-width:0;
}
.user-chip .user-name {
  flex:1;min-width:0;font-weight:600;color:var(--ink);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.user-avatar {
  width:26px;height:26px;border-radius:50%;
  background:var(--maroon-soft);border:1.5px solid rgba(123,29,29,.2);
  display:flex;align-items:center;justify-content:center;
  font-size:.65rem;color:var(--maroon);font-weight:700;
  flex-shrink:0;
}
.chip-link {
  margin-left:auto;flex-shrink:0;display:inline-flex;align-items:center;gap:.4rem;
  min-height:40px;padding:0 .75rem;border-radius:40px;
  color:var(--maroon);font-size:.76rem;font-weight:700;text-decoration:none;
  background:var(--maroon-soft);border:1px solid rgba(123,29,29,.14);
  transition:background .15s,color .15s;white-space:nowrap;
}
.chip-link:hover { background:var(--maroon);color:#fff; }
.chip-link i { font-size:.8rem; }
@media (max-width:480px) { .chip-link span { display:none; } .chip-link { min-width:40px;padding:0;justify-content:center;border-radius:50%; } }
.logout-link {
  flex-shrink:0;color:var(--ink3);font-size:.9rem;
  text-decoration:none;transition:color .15s,background .15s;
  display:inline-flex;align-items:center;justify-content:center;
  min-width:40px;min-height:40px;border-radius:50%;
}
.logout-link:hover{color:var(--maroon);background:var(--maroon-soft);}

/* ── PAGE HEADER ── */
.page-header {
  margin-bottom: 1.75rem;
  animation: riseIn .55s cubic-bezier(.22,1,.36,1) .05s both;
}
@keyframes riseIn { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:none} }

.page-eyebrow {
  font-size:.67rem;font-weight:600;color:var(--maroon);
  text-transform:uppercase;letter-spacing:2px;
  margin-bottom:.4rem;display:flex;align-items:center;gap:.4rem;
}
.page-eyebrow::before { content:'';width:20px;height:2px;background:var(--maroon); }
.page-title {
  font-family:'Fraunces',serif;font-size:1.9rem;font-weight:700;
  color:var(--ink);line-height:1.1;letter-spacing:-.02em;margin-bottom:.35rem;
}
.page-title em { font-style:italic;color:var(--maroon); }
.page-sub { font-size:.9rem;color:var(--ink3);line-height:1.6; }

.page-sub,
.section-sub,
.fi-hint,
.photo-sub,
.modal-sub,
.ticket-num,
.ticket-copy {
  overflow-wrap:anywhere;
}

/* ── SECTION CARD ── */
.section-card {
  background:var(--surface);border:1px solid var(--border);
  border-radius:16px;padding:1.75rem;margin-bottom:1.1rem;
  box-shadow:var(--shadow-sm);
  animation:riseIn .55s cubic-bezier(.22,1,.36,1) both;
  overflow:visible;position:relative;
}
/* Lift the card that has an open dropdown above the sections below it */
.section-card:has(.search-dd.open),
.section-card:has(.equip-dropdown.open){ z-index:60; }
.section-card:nth-child(1){animation-delay:.12s}
.section-card:nth-child(2){animation-delay:.18s}
.section-card:nth-child(3){animation-delay:.24s}
.section-card:nth-child(4){animation-delay:.30s}
.section-card:nth-child(5){animation-delay:.36s}

.section-head {
  display:flex;align-items:center;gap:.65rem;
  margin-bottom:1.25rem;padding-bottom:1rem;
  border-bottom:1px solid var(--border);
}
.section-icon {
  width:34px;height:34px;border-radius:10px;
  background:var(--maroon-soft);
  display:flex;align-items:center;justify-content:center;
  font-size:.8rem;color:var(--maroon);flex-shrink:0;
}
.section-title { font-family:'Fraunces',serif;font-size:1rem;font-weight:600;color:var(--ink); }
.section-sub   { font-size:.78rem;color:var(--ink3);margin-top:.1rem; }

/* ── FORM GRID ── */
.form-grid { display:grid;gap:1rem; }
.form-grid.cols-2 { grid-template-columns:1fr 1fr; }
.form-grid.cols-3 { grid-template-columns:1fr 1fr 1fr; }

.fg { }
.fl {
  display:block;font-size:.78rem;font-weight:600;
  color:var(--ink2);margin-bottom:.4rem;
  text-transform:uppercase;letter-spacing:.8px;
}
.fl .req, .section-title .req { color:var(--maroon);margin-left:.12rem; }
/* "(optional)", "(if visible)" — was the same four inline properties repeated on
   every label that needed it. */
.fl .opt { color:var(--ink3);font-weight:400;text-transform:none;letter-spacing:0;margin-left:.2rem; }

.fi-wrap { position:relative; }
.fi-icon {
  position:absolute;left:.85rem;top:50%;transform:translateY(-50%);
  color:var(--ink3);font-size:.75rem;pointer-events:none;transition:color .18s;
}
.fi-wrap:focus-within .fi-icon { color:var(--maroon); }

.fi, .fsel, .fta {
  width:100%;
  border:1.5px solid var(--border);border-radius:10px;
  font-family:'DM Sans',sans-serif;font-size:1rem;color:var(--ink);
  background:#fff;outline:none;
  transition:border-color .18s,box-shadow .18s;
  -webkit-appearance:none;
}
.fi   { padding:.72rem 1rem .72rem 2.4rem; }
.fsel { padding:.72rem 2.4rem .72rem 2.4rem; }
/* Left inset matches .fi/.fsel so the description's first character sits on the
   same line as every other field in the column. */
.fta  { padding:.72rem .9rem .72rem 2.4rem;resize:vertical;min-height:100px; }
/* The wrap is as tall as the textarea, so the icon is pinned near the top rather
   than vertically centred the way it is beside a single-line input. */
.fi-wrap-ta .fi-icon { top:1.15rem;transform:none; }
.fi:focus,.fsel:focus,.fta:focus {
  border-color:var(--maroon);
  box-shadow:0 0 0 3px rgba(123,29,29,.09);
}
.fi::placeholder,.fta::placeholder { color:#C4AFA8;font-size:.82rem; }
.fsel { background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%239E8070' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat:no-repeat;background-position:right .85rem center;cursor:pointer; }
.fsel:disabled { background-color:#F5EFE6;color:var(--ink3);cursor:not-allowed;opacity:1; }


/* ── "Reporting as …" — the saved profile, offered for confirmation ── */
/* ── EQUIPMENT SEARCH AUTOCOMPLETE ── */
.equip-wrap { position:relative; }
.search-dd {
  position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:100;
  background:var(--surface);border:1.5px solid var(--maroon);
  border-radius:10px;box-shadow:0 8px 30px rgba(44,10,10,.15);
  max-height:340px;overflow-y:auto;display:none;
  -webkit-overflow-scrolling:touch;overscroll-behavior:contain;
}
.search-dd.open { display:block; }
/* Opens upward when there is no room below. On a phone the keyboard takes the
   bottom half of the screen the moment the field is tapped, and a list that
   drops down from a field in the lower half went straight under it - the
   reporter saw the field, typed, and nothing appeared to happen. The script
   measures the visible viewport (keyboard included) and flips the list to
   whichever side has more room. */
.search-dd.up { top:auto; bottom:calc(100% + 4px); box-shadow:0 -8px 30px rgba(44,10,10,.15); }
/* Sticky campus/building heading, so you always know which building the rooms
   you are scrolling past belong to. */
.loc-group {
  position:sticky;top:0;z-index:1;
  display:flex;flex-direction:column;gap:.1rem;
  padding:.5rem .85rem .4rem;
  background:#FBF6F0;border-top:1px solid var(--border);border-bottom:1px solid var(--border);
  font-size:.74rem;font-weight:700;color:var(--ink2);line-height:1.3;
}
.loc-group:first-child { border-top:none; }
.loc-group-campus {
  font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:1.1px;
  color:var(--maroon);
}
.loc-item {
  display:flex;align-items:center;gap:.6rem;
  padding:.5rem .85rem .5rem 1.15rem;cursor:pointer;
  transition:background .12s;font-size:.86rem;color:var(--ink);
}
.loc-item:hover,.loc-item.focused { background:var(--maroon-soft); }
.loc-pin {
  width:22px;height:22px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  background:rgba(123,29,29,.08);color:var(--maroon);font-size:.65rem;flex-shrink:0;
}
/* The room wraps rather than truncating — it is the whole point of the row, and
   the campus and building it belongs to are on the heading directly above, so
   the row itself carries nothing else. */
.loc-name {
  font-weight:600;color:var(--ink);line-height:1.35;overflow-wrap:anywhere;flex:1;min-width:0;
}

/* ── USABLE TOGGLE ── */
.photo-zone {
  border:2px dashed var(--border);border-radius:12px;
  padding:1.5rem;text-align:center;cursor:pointer;
  transition:all .2s;position:relative;background:#fff;
}
.photo-zone:hover,.photo-zone.drag { border-color:var(--maroon);background:var(--maroon-soft); }
.photo-zone input[type=file] { position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%; }
.photo-icon { font-size:1.5rem;color:var(--ink3);margin-bottom:.5rem; }
.photo-title { font-size:.84rem;font-weight:600;color:var(--ink2); }
.photo-sub   { font-size:.72rem;color:var(--ink3);margin-top:.2rem; }
.photo-preview {
  display:none;margin-top:.75rem;
  position:relative;display:none;
}
.photo-preview img { width:100%;max-height:160px;object-fit:cover;border-radius:8px; }
.photo-preview .remove-photo {
  position:absolute;top:.4rem;right:.4rem;
  background:rgba(0,0,0,.55);color:#fff;border:none;
  border-radius:50%;width:24px;height:24px;font-size:.65rem;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
}
.photo-meta{font-size:.72rem;color:var(--maroon);font-weight:600;margin-top:.5rem;}
.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:.55rem;margin-top:.75rem;}
.pg-cell{position:relative;border-radius:10px;overflow:hidden;border:1px solid var(--border);aspect-ratio:1/1;background:#faf7f0;}
.pg-cell img{width:100%;height:100%;object-fit:cover;display:block;}
.pg-x{position:absolute;top:.25rem;right:.25rem;background:rgba(0,0,0,.6);color:#fff;border:none;border-radius:50%;width:22px;height:22px;font-size:.62rem;cursor:pointer;display:flex;align-items:center;justify-content:center;}
.pg-x:hover{background:var(--bad-tx);}
.pg-size{position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,.55);color:#fff;font-size:.58rem;text-align:center;padding:1px 0;}

/* ── ALERT ── */
.alert {
  padding:.75rem 1rem;border-radius:10px;
  font-size:.8rem;line-height:1.5;margin-bottom:1.25rem;
  display:flex;align-items:flex-start;gap:.55rem;
  animation:riseIn .3s ease;
}
.alert-err { background:#FEF2F2;border:1px solid #FECACA;color:var(--bad-tx); }
.alert i   { font-size:.82rem;margin-top:.1rem;flex-shrink:0; }

/* ── SUBMIT ROW ── */
.submit-row {
  display:flex;align-items:center;gap:1rem;
  margin-top:1.5rem;flex-wrap:wrap;
}
.btn-submit {
  flex:1;min-width:200px;
  padding:.9rem 1.5rem;
  background:var(--maroon-d);color:#fff;
  border:none;border-radius:11px;
  font-family:'DM Sans',sans-serif;font-size:.95rem;font-weight:600;
  cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.55rem;
  transition:all .22s cubic-bezier(.22,1,.36,1);
  box-shadow:0 8px 20px rgba(74,14,14,.25);
  letter-spacing:-.01em;-webkit-appearance:none;
}
.btn-submit:hover { background:var(--maroon);transform:none;box-shadow:0 14px 28px rgba(74,14,14,.3); }
.btn-submit:active { transform:none;box-shadow:none; }
.btn-arrow { width:20px;height:20px;background:rgba(255,255,255,.18);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.65rem;transition:transform .2s; }
.btn-submit:hover .btn-arrow { transform:none; }
.btn-submit.is-loading {
  pointer-events:none;opacity:.82;transform:none;
}
.btn-submit.is-loading .btn-arrow {
  animation:spin .9s linear infinite;
}
.btn-cancel { padding:.9rem 1.25rem;border:1.5px solid var(--border);border-radius:11px;color:var(--ink3);font-size:.85rem;font-weight:500;background:none;cursor:pointer;transition:all .18s; text-decoration:none;display:inline-flex;align-items:center; }
.btn-cancel:hover { border-color:var(--maroon);color:var(--maroon); }

/* Open-report notice, filled in on the Equipment step */
.dup-early { display:flex;gap:.6rem;align-items:flex-start;margin:0 0 1rem;padding:.85rem 1rem;border-radius:12px;background:#FFF7E6;border:1px solid #F0D79A;border-left:4px solid #C9960C;color:#5C3838;font-size:.86rem;line-height:1.6; }
.dup-early > i { color:#9A6A00;margin-top:.2rem;flex-shrink:0; }
.dup-early strong { color:#1C1008; }
.dup-early .dup-early-id { color:var(--maroon); }
.dup-early a { display:inline-block;padding:.35rem 0;color:var(--maroon);font-weight:700;text-decoration:underline; }
.dup-early-ok { display:flex;gap:.55rem;align-items:flex-start;margin-top:.55rem;cursor:pointer; }
.dup-early-acts { display:flex;align-items:center;flex-wrap:wrap;gap:.5rem .7rem;margin-top:.6rem; }
.dup-btn { display:inline-flex;align-items:center;gap:.45rem;min-height:40px;padding:.45rem .9rem;border-radius:10px;border:1.5px solid var(--maroon);background:var(--maroon);color:#fff;font:inherit;font-size:.86rem;font-weight:700;cursor:pointer; }
.dup-btn:disabled { opacity:.6;cursor:default; }
.dup-early a.dup-btn { color:#fff;text-decoration:none;padding:.45rem .9rem; }
.dup-btn[hidden] { display:none; }
/* Any element on this page that says hidden is hidden - a class that sets
   display (the notice's flex, a button's inline-flex) used to win over the
   attribute, which is how an empty "already reported" box showed on every
   plain load of the form. */
[hidden] { display:none !important; }
.dup-early-ok input { width:17px;height:17px;flex-shrink:0;margin-top:.2rem;accent-color:var(--maroon); }
.qr-change { display:inline-block;margin-top:.45rem;padding:0;border:0;background:none;color:var(--maroon);font:inherit;font-size:.82rem;font-weight:700;text-decoration:underline;cursor:pointer; }

/* inline required-field warnings */
/* The evidence card has no input to outline, so the card itself says so. */
.section-card.f-err-card{border-color:var(--bad);box-shadow:0 0 0 3px rgba(220,38,38,.12);}
/* The two camera buttons above the drop zone. camera_capture.js styles the
   buttons; this only sets them side by side, and stacks them on a narrow phone
   where two 44px targets in one row would be too small to hit. */
.section-card .cam-row-2{gap:.5rem;margin:0 0 .75rem;}
@media(max-width:420px){.section-card .cam-row-2{flex-direction:column;}}
/* "Filed under …" beneath the equipment name. */
.cat-hint{min-height:1.2em;color:var(--maroon);}
.cat-hint strong{color:var(--maroon);}
.cat-hint i{margin-right:.25rem;color:var(--gold);}
.f-err{border-color:var(--bad) !important;background:#FFF8F8 !important;box-shadow:0 0 0 3px rgba(220,38,38,.12) !important;animation:fShake .3s ease;}
@keyframes fShake{0%,100%{transform:translateX(0);}25%{transform:translateX(-4px);}75%{transform:translateX(4px);}}
.f-flash{animation:fFlash .65s ease 2;}
@keyframes fFlash{0%,100%{outline:3px solid rgba(220,38,38,0);outline-offset:2px;}50%{outline:6px solid rgba(220,38,38,.45);outline-offset:3px;}}
.f-msg{display:flex;align-items:center;gap:.4rem;margin-top:.35rem;font-size:.76rem;font-weight:700;color:var(--bad);}
.f-msg i{font-size:.72rem;}

.loading-overlay {
  position:fixed;inset:0;z-index:800;
  background:rgba(248,243,234,.88);
  backdrop-filter:blur(4px);
  display:none;align-items:center;justify-content:center;
  padding:1.5rem;text-align:center;
}
.loading-overlay.show { display:flex; }
.loading-box {
  width:min(360px,100%);
  background:#fff;border:1.5px solid var(--border);
  border-radius:16px;padding:1.4rem;
  box-shadow:var(--shadow-md);
}
.loading-spinner {
  width:44px;height:44px;border-radius:50%;
  border:4px solid #F0E3D7;border-top-color:var(--maroon);
  animation:spin .85s linear infinite;
  margin:0 auto .9rem;
}
.loading-title {
  font-family:'Fraunces',serif;font-size:1.2rem;font-weight:700;color:var(--ink);
}
.loading-sub {
  margin-top:.35rem;font-size:.82rem;line-height:1.5;color:var(--ink3);
}
@keyframes spin { to { transform:rotate(360deg); } }

/* ── SUCCESS MODAL ── */
.modal-overlay {
  position:fixed;inset:0;background:rgba(20,5,5,.55);
  display:flex;align-items:center;justify-content:center;
  z-index:500;padding:1.5rem;
  animation:fadeIn .25s ease;
}
@keyframes fadeIn { from{opacity:0} to{opacity:1} }
.modal {
  background:var(--surface);border-radius:20px;
  padding:2.25rem;max-width:440px;width:100%;
  max-height:calc(100vh - 3rem);overflow:auto;
  box-shadow:0 20px 60px rgba(20,5,5,.3);
  animation:riseIn .4s cubic-bezier(.22,1,.36,1);
  text-align:center;
}
.modal-check {
  width:64px;height:64px;border-radius:50%;
  background:var(--green-bg);border:2px solid var(--green-border);
  display:flex;align-items:center;justify-content:center;
  font-size:1.5rem;color:var(--green);margin:0 auto 1.25rem;
}
.modal-title { font-family:'Fraunces',serif;font-size:1.5rem;font-weight:700;color:var(--ink);margin-bottom:.4rem; }
.modal-sub   { font-size:.84rem;color:var(--ink3);line-height:1.6;margin-bottom:1.25rem; }
.ticket-box {
  background:var(--paper);border:1.5px dashed var(--border);
  border-radius:10px;padding:.85rem 1rem;margin-bottom:1.5rem;
}
.ticket-label { font-size:.65rem;font-weight:600;text-transform:uppercase;letter-spacing:1.5px;color:var(--ink3);margin-bottom:.3rem; }
.ticket-num   { font-family:'Fraunces',serif;font-size:1.4rem;font-weight:700;color:var(--maroon);letter-spacing:.05em; }
.ticket-copy  { font-size:.72rem;color:var(--ink3);margin-top:.2rem; }
.email-note {
  margin: 0 0 1rem;
  padding: .8rem .95rem;
  border-radius: 10px;
  font-size: .78rem;
  line-height: 1.5;
}
.email-note.ok {
  background: #F0FDF4;
  border: 1px solid #BBF7D0;
  color: var(--ok-tx);
}
.email-note.warn {
  background: #FFF7ED;
  border: 1px solid #FED7AA;
  color: #9A3412;
}
.modal-actions { display:flex;flex-direction:column;gap:.6rem; }
.btn-track {
  padding:.8rem;background:var(--maroon-d);color:#fff;border:none;border-radius:10px;
  font-family:'DM Sans',sans-serif;font-size:.9rem;font-weight:600;cursor:pointer;
  text-decoration:none;display:block;transition:background .18s;
}
.btn-track:hover { background:var(--maroon); }
.btn-new {
  padding:.8rem;border:1.5px solid var(--border);border-radius:10px;
  color:var(--ink2);font-size:.88rem;font-weight:500;
  background:none;cursor:pointer;text-decoration:none;display:block;transition:all .18s;
}
.btn-new:hover { border-color:var(--maroon);color:var(--maroon); }

/* ── RESPONSIVE ── */
@media(max-width:900px){
  .page {
    max-width: 100%;
  }

  .form-grid.cols-3 {
    grid-template-columns: 1fr 1fr;
  }
}

@media(max-width:700px){
  body {
    padding: 1rem .85rem 3rem;
  }

  .topbar {
    margin-bottom: 1.35rem;
  }

  .section-card {
    padding: 1.25rem;
  }

  .page-title {
    font-size: 1.65rem;
  }
}

@media(max-width:600px){
  .form-grid.cols-2,.form-grid.cols-3 { grid-template-columns:1fr; }
  .reporter-grid { grid-template-columns:1fr; }
  .page-title { font-size:1.55rem; }
  /* Thumb-zone sticky submit bar — always reachable on the long form */
  .submit-row {
    position:fixed;left:0;right:0;bottom:0;z-index:80;margin:0;
    flex-direction:row;flex-wrap:nowrap;align-items:stretch;gap:.5rem;
    padding:.6rem .8rem calc(.6rem + env(safe-area-inset-bottom,0px));
    background:rgba(255,255,255,.92);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);
    border-top:1px solid var(--border);box-shadow:0 -6px 22px rgba(74,14,14,.1);
  }
  .btn-submit { flex:1;min-width:0;width:auto;padding:.9rem 1rem;box-shadow:none; }
  .btn-cancel { flex:0 0 auto;width:auto;padding:.9rem 1.05rem;justify-content:center; }
  .btn-cancel .bc-txt { display:none; }        /* icon-only Back to keep the bar tidy */
  .page { padding-bottom:5.5rem; }             /* clear the fixed bar */
  .topbar {
    flex-direction: column;
    align-items: stretch;
  }
  .user-chip {
    justify-content: flex-start;
  }
  /* Comfortable 44px+ tap targets on phones */
  .eq-item,.loc-item { min-height:46px; padding-top:.72rem; padding-bottom:.72rem; }
  .eq-item:active,.loc-item:active { background:var(--maroon-soft); }
  .usable-label { min-height:50px; padding:.6rem; font-size:.82rem; }
  .fi,.fsel { min-height:48px; }
  .cam-trigger { min-height:48px; }
}

@media(max-width:480px){
  .section-head {
    gap:.55rem;
    margin-bottom:1rem;
    padding-bottom:.85rem;
  }
  .section-title {
    font-size:.95rem;
  }
  .section-sub,
  .fi-hint {
    font-size:.74rem;   /* 11.6px was under the 12px phone floor for help copy */
  }
  .usable-label {
    flex-basis:100%;
  }
  .btn-submit {
    min-width:0;
    padding:.85rem 1rem;
    font-size:.9rem;
  }
  .btn-cancel {
    padding:.8rem 1rem;
  }
  .ro-field {
    padding:.6rem .75rem;
  }
}

@media(max-width:390px){
  body {
    padding: .8rem .65rem 2rem;
  }

  .section-card {
    padding: 1rem;
    border-radius: 14px;
  }

  .page-title {
    font-size: 1.35rem;
  }

  .page-sub,
  .ro-value {
    font-size: .82rem;
  }
  /* Keep form controls at 16px on phones so iOS Safari doesn't zoom in
     when a field is focused. Never drop inputs below 16px. */
  .fi, .fsel, .fta {
    font-size: 16px;
  }

  .section-head {
    align-items: flex-start;
  }

  .modal {
    padding: 1.15rem 1rem;
    border-radius: 16px;
  }

  .ticket-num {
    font-size: 1.1rem;
  }
}

@media (max-height: 720px) {
  body {
    padding-top: .85rem;
  }

  .modal-overlay {
    align-items: flex-start;
    padding-top: 1rem;
    padding-bottom: 1rem;
  }
}

/* ── DESKTOP / PC: fill the whole screen with a two-column form ──
   min-width only — never touches the mobile/tablet layout (<=900px). */
@media (min-width: 901px) {
  body { padding: 1.75rem 2.25rem 4rem; }
  /* Wide but readable: a comfortable measure instead of an over-stretched form. */
  .page { max-width: 1180px; margin: 0 auto; width: 100%; }
  /* Centre the page header + section headings + submit. */
  .page-header { text-align: center; }
  .page-sub { max-width: 64ch; margin-left: auto; margin-right: auto; }
  .section-head { justify-content: center; text-align: center; }
  .section-title { font-size: 1.15rem; }
  .submit-row { justify-content: center; }
  /* Card hover elevation. */
  .section-card { transition: box-shadow .2s ease, transform .2s ease, border-color .2s ease; }
  .section-card:hover { box-shadow: 0 12px 32px rgba(123,29,29,.10); transform:none; }
  /* a card with an open search dropdown floats above its neighbour */
  .section-card:has(.search-dd.open),
  .section-card:has(.equip-dropdown.open) { z-index: 60; }
}

/* ── HEADER, BRAND & INTERACTION POLISH (all sizes) ── */
html { scroll-behavior: smooth; }
/* "New Report" eyebrow as a gold pill */
.page-eyebrow {
  display: inline-flex; background: #FFF4DD; color: #92600A;
  border: 1px solid rgba(201,150,12,.3); padding: .34rem .8rem;
  border-radius: 999px; letter-spacing: 1.5px;
}
.page-eyebrow::before { display: none; }
/* Logo seal: soft gold ring + lift */
.logo-seal { box-shadow: 0 0 0 3px rgba(201,150,12,.22), 0 4px 14px rgba(123,29,29,.12); }
/* Sticky translucent topbar for the long scrolling form */
.topbar {
  position: sticky; top: 0; z-index: 60;
  background: rgba(248,243,234,.85);
  -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
  border-bottom: 1px solid rgba(226,217,204,.6);
  padding-top: .7rem; padding-bottom: .7rem;
}
/* Scroll-spy: the section currently in view gets a gold accent */
.section-card.is-active { border-color: rgba(201,150,12,.55); box-shadow: 0 0 0 1px rgba(201,150,12,.35), 0 12px 32px rgba(123,29,29,.10); }
.section-card.is-active .section-icon { background: var(--maroon); color: #fff; }
@media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
/* Minimalist error / incomplete-fields popup */
.err-modal { position: fixed; inset: 0; z-index: 11000; display: none; align-items: center; justify-content: center; padding: 1.2rem; background: rgba(28,16,8,.5); backdrop-filter: blur(3px); }
.err-modal.show { display: flex; }
.err-box { background: #fff; border-radius: 16px; max-width: 400px; width: 100%; padding: 1.7rem 1.5rem 1.4rem; text-align: center; box-shadow: 0 24px 64px rgba(44,10,10,.30); animation: errPop .18s ease; }
@keyframes errPop { from { transform: scale(.94); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.err-ic { width: 54px; height: 54px; border-radius: 50%; background: #FDECEC; color: var(--bad-tx); display: flex; align-items: center; justify-content: center; font-size: 1.45rem; margin: 0 auto 1rem; }
.err-box h3 { font-family: 'Fraunces', serif; font-size: 1.18rem; color: var(--ink); margin-bottom: .4rem; }
.err-box p { font-size: .85rem; color: var(--ink2); line-height: 1.55; margin-bottom: 1rem; }
.err-list { list-style: none; text-align: left; margin: 0 0 1.15rem; padding: 0; display: flex; flex-direction: column; gap: .4rem; max-height: 190px; overflow: auto; }
.err-list li { font-size: .8rem; color: var(--ink); display: flex; align-items: center; gap: .55rem; padding: .55rem .75rem; background: #FBF3F3; border-radius: 9px; border-left: 3px solid var(--bad-tx); }
.err-list li i { color: var(--bad-tx); font-size: .72rem; flex-shrink: 0; }
.err-btn { width: 100%; padding: .82rem; border: none; border-radius: 11px; background: linear-gradient(135deg, var(--maroon-d), var(--maroon)); color: #fff; font-family: 'DM Sans', sans-serif; font-weight: 700; font-size: .9rem; cursor: pointer; transition: filter .15s; }
.err-btn:hover { filter: brightness(1.08); }
.exit-actions { display: flex; gap: .6rem; }
.exit-actions .err-btn, .exit-actions .err-btn2 { flex: 1; }
.err-btn2 { padding: .82rem; border: 1.5px solid var(--border); border-radius: 11px; background: #fff; color: var(--ink2); font-family: 'DM Sans', sans-serif; font-weight: 700; font-size: .9rem; cursor: pointer; transition: border-color .15s, color .15s; }
.err-btn2:hover { border-color: var(--maroon); color: var(--maroon); }
</style>
</head>
<body>
<div class="bg-grid"></div>

<?php if ($success): ?>
<!-- ── SUCCESS MODAL ── -->
<div class="modal-overlay">
  <div class="modal">
    <div class="modal-check"><i class="fas fa-check"></i></div>
    <h2 class="modal-title">Report Submitted!</h2>
    <p class="modal-sub">Your equipment defect report has been received. An email confirmation has been sent to <strong><?php echo htmlspecialchars($student_email); ?></strong>.</p>
    <div class="ticket-box">
      <div class="ticket-label">Your Ticket Number</div>
      <div class="ticket-num"><?php echo $ticket; ?></div>
      <div class="ticket-copy">Save this — you'll need it to track your report.</div>
    </div>
    <?php if ($email_notice): ?>
    <div class="email-note warn"><?php echo htmlspecialchars($email_notice); ?></div>
    <?php else: ?>
    <div class="email-note ok">A ticket confirmation has been sent to <strong><?php echo htmlspecialchars($student_email); ?></strong>.</div>
    <?php endif; ?>
    <div class="modal-actions">
      <a href="track_report.php?ticket=<?php echo $ticket; ?>" class="btn-track">
        <i class="fas fa-search" style="margin-right:.4rem"></i>Track My Report
      </a>
      <a href="student_dashboard.php" class="btn-new">Submit Another Report</a>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="page">

  <!-- TOP BAR -->
  <div class="topbar">
    <div class="logo-row">
      <div class="logo-seal">
        <img src="assets/logs.png" alt="BEC logo">
      </div>
      <div class="logo-text">
        <?php /* Says who is signed in — Student, Teacher or Staff Portal — from
                 the one tap made at sign-in. "User Portal" for the rare session
                 with no role on record. */ ?>
        <span><?php echo htmlspecialchars(($reporterTypeLabel = reporterTypeLabel($reporterType)) !== '' ? $reporterTypeLabel . ' Portal' : 'User Portal'); ?></span>
      </div>
    </div>
    <div class="user-chip">
      <div class="user-avatar"><?php echo strtoupper(substr($student_name,0,1)); ?></div>
      <span class="user-name"><?php echo htmlspecialchars($student_name); ?></span>
      <?php /* Track a Report lists a signed-in reporter's own tickets. Nothing on
               this page led there: the only way to see a report filed last week
               was back out through the landing page. */ ?>
      <a href="track_report.php" class="chip-link" title="My reports"><i class="fas fa-list-check"></i><span>My reports</span></a>
      <a href="student_dashboard.php?logout=1" class="logout-link" title="Sign out"><i class="fas fa-sign-out-alt"></i></a>
    </div>
  </div>

  <!-- PAGE HEADER -->
  <div class="page-header">
    <div class="page-eyebrow">New Report</div>
    <h1 class="page-title">Submit an <em>equipment defect</em> report.</h1>
    <p class="page-sub">Say what is broken, where it is, and add a photo. Your ticket number is emailed to you the moment you submit.</p>
  </div>


  <?php if ($error): ?>
  <div class="alert alert-err">
    <i class="fas fa-exclamation-circle"></i>
    <?php echo htmlspecialchars($error); ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($duplicateFound)): ?>
  <div class="alert" id="dupAlert" style="background:#FFF7E6;border:1px solid #F0D79A;border-left:4px solid #C9960C;color:#5C3838;display:flex;gap:.6rem;align-items:flex-start;">
    <i class="fas fa-clone" style="color:#9A6A00;margin-top:.15rem;"></i>
    <div style="line-height:1.6;">
      <strong style="color:#1C1008;">This equipment already has an open report:</strong>
      <strong style="color:#7B1D1D;"><?php echo htmlspecialchars((string)$duplicateFound['report_id']); ?></strong>
      (<?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$duplicateFound['status']))); ?>)
      — <a href="track_report.php?q=<?php echo urlencode((string)$duplicateFound['report_id']); ?>" target="_blank" style="color:#7B1D1D;font-weight:700;text-decoration:underline;">track it here</a> instead of filing again.<br>
      If yours is a <em>different problem on the same unit</em>, tick the confirmation at the bottom of the form and submit again — your details are still filled in below.
    </div>
  </div>
  <?php endif; ?>

  <?php if ($prefillEq): ?>
  <?php /* Above the stepper, not inside the Equipment card: the wizard opens a
           QR scan on the Problem step, and a banner inside step 2 would never
           be seen by the person it is addressed to. */ ?>
  <div class="qr-banner" style="display:flex;align-items:flex-start;gap:.7rem;margin-bottom:1rem;padding:.85rem 1rem;border-radius:12px;background:#FFFBEF;border:1px solid rgba(201,150,12,.35);border-left:4px solid #C9960C;">
    <i class="fas fa-qrcode" style="color:#C9960C;font-size:1.1rem;margin-top:.15rem;"></i>
    <div style="font-size:.86rem;line-height:1.55;color:#5C3838;">
      <strong style="color:#1C1008;">Scanned from an equipment QR code</strong><br>
      Reporting: <strong style="color:#7B1D1D;"><?php echo htmlspecialchars($prefillEq['name'] ?: $prefillEq['id']); ?></strong>
      <?php if ($prefillEq['asset_tag'] !== ''): ?> · Tag <?php echo htmlspecialchars($prefillEq['asset_tag']); ?><?php endif; ?>
      <?php if ($prefillEq['location'] !== ''): ?> · <?php echo htmlspecialchars($prefillEq['location']); ?><?php endif; ?>
      — just describe the issue and submit.
      <button type="button" class="qr-change" onclick="if(window.rfChangeEquipment)rfChangeEquipment()">Not this unit? Change equipment</button>
    </div>
  </div>
  <?php endif; ?>
  <?php /* Filled by the Equipment step the moment a unit is chosen (see
           check_open at the top of this file). Lives up here with the QR
           banner so a scanned unit that is already reported says so at once. */ ?>
  <div class="dup-early" id="dupEarly" hidden>
    <i class="fas fa-clone" aria-hidden="true"></i>
    <div>
      <span id="dupEarlyText"><strong>This unit already has an open report</strong></span>
      — <strong class="dup-early-id" id="dupEarlyId"></strong> <span id="dupEarlyStatus"></span>
      <div class="dup-early-acts">
        <a class="dup-btn" id="dupEarlyLink" href="track_report.php" target="_blank" rel="noopener"><i class="fas fa-clock-rotate-left"></i> Check its history — track it instead</a>
      </div>
      <label class="dup-early-ok"><input type="checkbox" id="dupEarlyOk" form="report-form" name="duplicate_override" value="1"> <span id="dupEarlyOkText">Mine is a <em>different problem</em> on the same unit — file a new report anyway.</span></label>
    </div>
  </div>
  <form method="POST" enctype="multipart/form-data" id="report-form" novalidate>
    <?php echo csrf_field(); ?>

    <?php /* Four questions on one screen. This was a five-step wizard that
             opened with the reporter's department, course, year level and
             phone, then asked for a category, an asset tag, the date they
             noticed the fault and whether it still worked. The panel called it
             too much, and it was: none of that is needed to send someone to
             look at a broken aircon. Who is asking was one tap at sign-in;
             the category and the responsible unit are worked out from the
             words typed here; the rest was never used. */ ?>

    <!-- ── WHAT & WHERE ── -->
    <div class="section-card" id="equipSection">
      <div class="section-head">
        <div class="section-icon"><i class="fas fa-desktop"></i></div>
        <div>
          <div class="section-title">What is broken?</div>
          <div class="section-sub">Name the equipment and say where it is</div>
        </div>
      </div>
      <div class="form-grid">
        <div class="fg">
          <label class="fl" for="equip-name">Equipment <span class="req">*</span></label>
          <div class="fi-wrap">
            <i class="fas fa-desktop fi-icon"></i>
            <input type="text" id="equip-name" name="equipment_name" class="fi"
              placeholder="e.g. Aircon, projector, chair, faucet…" maxlength="120" autocomplete="off" required
              value="<?php echo htmlspecialchars($_POST['equipment_name'] ?? ($prefillEq['name'] ?? '')); ?>">
          </div>
          <?php /* Carried only by a scanned QR code; nothing on the page sets
                   them by hand any more. The id is what the duplicate guard
                   and the inventory link key on, so it must survive the round
                   trip when the reporter arrived through a sticker. */ ?>
          <input type="hidden" name="equipment_id" id="equip-id-hidden" value="<?php echo htmlspecialchars($_POST['equipment_id'] ?? ($prefillEq['id'] ?? '')); ?>">
          <input type="hidden" name="asset_tag" id="assetTag" value="<?php echo htmlspecialchars($_POST['asset_tag'] ?? ($prefillEq['asset_tag'] ?? '')); ?>">
          <div class="fi-hint cat-hint" id="catHint" aria-live="polite"></div>
        </div>

        <div class="fg">
          <label class="fl" for="location-search">Location <span class="req">*</span></label>
          <div class="equip-wrap">
            <div class="fi-wrap">
              <i class="fas fa-map-marker-alt fi-icon"></i>
              <input type="text" id="location-search" class="fi" placeholder="Type the room or building…"
                autocomplete="off" required
                value="<?php echo htmlspecialchars($_POST['location'] ?? ($prefillEq['location'] ?? '')); ?>">
            </div>
            <input type="hidden" name="location" id="location-hidden" value="<?php echo htmlspecialchars($_POST['location'] ?? ($prefillEq['location'] ?? '')); ?>">
            <div class="search-dd" id="location-dropdown"></div>
          </div>
          <div class="fi-hint"><i class="fas fa-info-circle"></i> Pick the room from the list, or type it if it is not there.</div>
        </div>
      </div>
    </div>

    <!-- ── WHAT'S WRONG ── -->
    <div class="section-card">
      <div class="section-head">
        <div class="section-icon"><i class="fas fa-exclamation-triangle"></i></div>
        <div>
          <div class="section-title">What's wrong?</div>
          <div class="section-sub">A sentence or two is enough</div>
        </div>
      </div>
      <div class="form-grid">
        <div class="fg">
          <label class="fl" for="defectDesc">Description <span class="req">*</span></label>
          <div class="fi-wrap fi-wrap-ta">
            <i class="fas fa-pen fi-icon"></i>
            <textarea name="defect_description" id="defectDesc" class="fta" rows="4" maxlength="1500"
              placeholder="e.g. It turns on but does not cool, and water drips from it." required><?php echo htmlspecialchars($_POST['defect_description'] ?? ''); ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- ── EVIDENCE ── -->
    <div class="section-card" id="evidenceCard">
      <div class="section-head">
        <div class="section-icon"><i class="fas fa-camera"></i></div>
        <div>
          <div class="section-title">Photo or video <span class="req">*</span></div>
          <div class="section-sub">Show the technician what you see — at least one</div>
        </div>
      </div>

      <?php /* Two buttons that open the camera itself. A file input on its own
               is allowed to show a picker, and on a phone that is what it did:
               the reporter landed in Files instead of the camera. The buttons
               are handled by assets/camera_capture.js, which creates a fresh
               capture="environment" input inside the tap - the one thing that
               makes iOS Safari and Android Chrome go straight to the camera -
               and hands whatever comes back to #media-input below, so the
               previews, limits and the two hidden form fields are untouched.
               On a laptop the same buttons open a file chooser, which is all a
               laptop can do. */ ?>
      <div class="cam-row cam-row-2">
        <button type="button" class="cam-trigger" data-camera="photo" data-camera-target="#media-input"><i class="fas fa-camera"></i> Take a photo</button>
        <button type="button" class="cam-trigger" data-camera="video" data-camera-target="#media-input"><i class="fas fa-video"></i> Record a video</button>
      </div>

      <?php /* One picker for both. Its accept list names images and videos, so
               the phone's own sheet offers the gallery and the camera together.
               photos[] and videos[] are still submitted separately, filled from
               here, so nothing downstream changed. */ ?>
      <div class="photo-zone" id="media-zone">
        <input type="file" id="media-input" aria-label="Choose photos or a video from your gallery"
               accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime" multiple>
        <div class="photo-icon"><i class="fas fa-images"></i></div>
        <div class="photo-title">or choose from your gallery / files</div>
        <div class="photo-sub">Photos: up to <strong>10</strong>, 10MB each &middot; Video: up to <strong>2</strong>, 20MB each</div>
        <div class="photo-meta" id="media-meta"></div>
      </div>

      <?php /* Kept, hidden: these carry the files to the server under the names
               it already expects, and photo_shrink.js still finds photos[] by
               its data-shrink hook at submit time. */ ?>
      <input type="file" name="photos[]" id="photo-input" data-shrink accept="image/jpeg,image/png,image/webp" multiple hidden tabindex="-1" aria-hidden="true">
      <input type="file" name="videos[]" id="video-input" accept="video/mp4,video/webm,video/quicktime" multiple hidden tabindex="-1" aria-hidden="true">

      <div class="photo-meta" id="photo-meta"></div>
      <div class="photo-grid" id="photo-grid"></div>
      <div class="photo-meta" id="video-meta"></div>
      <div class="photo-grid" id="video-grid"></div>
      <div class="f-msg" id="evidenceMsg" hidden><i class="fas fa-circle-exclamation"></i> Please add at least one photo or video.</div>
    </div>

    <!-- ── SUBMIT ── -->
    <?php if (!empty($duplicateFound)): ?>
    <label class="dup-override" style="display:flex;align-items:flex-start;gap:.6rem;margin:0 0 1rem;padding:.8rem 1rem;border-radius:12px;background:#FFF7E6;border:1.5px solid #F0D79A;font-size:.86rem;color:#5C3838;cursor:pointer;line-height:1.5;">
      <input type="checkbox" name="duplicate_override" value="1" required style="width:17px;height:17px;flex-shrink:0;margin-top:.15rem;accent-color:#7B1D1D;">
      <span><strong style="color:#1C1008;">This is a separate issue</strong> on the same equipment — not the one already reported in
      <strong><?php echo htmlspecialchars((string)$duplicateFound['report_id']); ?></strong>. Submit as a new report.</span>
    </label>
    <?php endif; ?>
    <div class="submit-row">
      <a href="student_index.php" class="btn-cancel"><i class="fas fa-arrow-left" style="font-size:.8rem"></i><span class="bc-txt">Back</span></a>
      <button type="submit" class="btn-submit">
        Submit Report
        <span class="btn-arrow"><i class="fas fa-paper-plane"></i></span>
      </button>
    </div>

  </form>
</div><!-- /page -->

<div class="loading-overlay" id="loading-overlay" aria-live="polite" aria-hidden="true">
  <div class="loading-box">
    <div class="loading-spinner"></div>
    <div class="loading-title">Submitting report</div>
    <div class="loading-sub">Please wait while we save the report and generate the ticket number.</div>
  </div>
</div>

<script>
// ── Elements ─────────────────────────────────────────────────────────────
const equipNameEl = document.getElementById('equip-name');
const equipIdEl   = document.getElementById('equip-id-hidden');
const assetTagEl  = document.getElementById('assetTag');
const catHintEl   = document.getElementById('catHint');
const locationSearchEl = document.getElementById('location-search');
const locationHiddenEl = document.getElementById('location-hidden');
const locationData  = <?php echo json_encode(array_values($location_options)); ?>;
// Same list, pre-split into campus / building / room so the picker can show the
// room prominently instead of one long truncated string.
const locationParts = <?php echo json_encode(array_values($locationParts)); ?>;
const locationDropdown = document.getElementById('location-dropdown');
const reportForm = document.getElementById('report-form');
const loadingOverlay = document.getElementById('loading-overlay');
const submitBtn = reportForm?.querySelector('.btn-submit');

let locationFocusIdx = -1;

// Locations are short, grouped rows and there are only ~300 of them, so the
// whole campus fits comfortably.
const LOCATION_LIMIT = 120;

/**
 * Keeps a suggestion list open while the pointer is inside it.
 *
 * The list used to close on the input's blur after a 150ms timer. Only the rows
 * themselves suppressed that blur, so pressing the scrollbar, a group heading,
 * or the gap between two rows blurred the input and the list vanished mid-scroll
 * — which is exactly what a long list invites you to do.
 */
function wireComboDismiss(input, dd) {
  let holdingInside = false;

  // Desktop: never let a press inside the list move focus out of the input.
  dd.addEventListener('mousedown', e => { if (e.target !== input) e.preventDefault(); });

  // Touch: the drag has to be allowed to scroll, so remember it instead and let
  // the blur pass by until the finger is lifted.
  dd.addEventListener('pointerdown', () => { holdingInside = true; });
  document.addEventListener('pointerup', () => {
    if (!holdingInside) return;
    holdingInside = false;
    // Only worth restoring focus if the list is still open — that is the drag
    // case this exists for. When a choice has just closed it, refocusing put
    // the cursor back in the field and the focus handler opened the list
    // straight back up, so on a phone picking an item appeared to do nothing.
    if (!dd.classList.contains('open')) return;
    if (document.activeElement !== input && !dd.contains(document.activeElement)) {
      input.focus({ preventScroll: true });
    }
  });

  input.addEventListener('blur', () => {
    if (holdingInside) return;
    setTimeout(() => { if (!holdingInside) dd.classList.remove('open'); }, 150);
  });

  // A press anywhere else on the page is a genuine dismissal.
  document.addEventListener('pointerdown', e => {
    if (e.target !== input && !dd.contains(e.target)) dd.classList.remove('open');
  });
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[char]));
}

/* A scanned unit arrives with its id. Ask at once whether it already has an
   open report and say so above the form - before the description is typed
   and the photos attached, which is when the server used to say it. The
   server still checks on submit; this only moves the news earlier. A typed
   name has no id to look up, so it is the server's check alone. */
const dupEarly = document.getElementById('dupEarly');
let dupEarlySeq = 0;
function checkOpenReport(id) {
  if (!dupEarly) return;
  const seq = ++dupEarlySeq;
  id = (id || '').trim();
  if (!id) { dupEarly.hidden = true; return; }
  fetch('student_dashboard.php?check_open=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
    .then(r => r.ok ? r.json() : null)
    .then(j => {
      if (seq !== dupEarlySeq) return;              // a later pick already answered
      const open = j && j.open;
      if (!open || !open.report_id) { dupEarly.hidden = true; return; }
      // Say whose it is and when, so a reporter who has just signed in is not
      // left wondering what they are supposed to have filed.
      var when = open.when ? ' on ' + open.when : '';
      document.getElementById('dupEarlyText').innerHTML = open.mine
        ? '<strong>You already reported this unit' + when + '</strong>'
        : '<strong>This unit was already reported' + when + ' by someone else</strong>';
      document.getElementById('dupEarlyOkText').innerHTML = open.mine
        ? 'This is a <em>new, different problem</em> on the same unit — file another report.'
        : 'Mine is a <em>different problem</em> on the same unit — file a new report anyway.';
      document.getElementById('dupEarlyId').textContent = open.report_id;
      document.getElementById('dupEarlyStatus').textContent = open.status ? '(' + open.status + ')' : '';
      document.getElementById('dupEarlyLink').href = 'track_report.php?q=' + encodeURIComponent(open.report_id);
      document.getElementById('dupEarlyOk').checked = false;
      dupEarly.dataset.report = open.report_id;
      dupEarly.hidden = false;
    })
    .catch(() => {});
}
// Arrived with the unit pre-filled (a QR scan); the server's own notice covers a form sent back.
if (equipIdEl.value && !document.getElementById('dupAlert')) checkOpenReport(equipIdEl.value);

/* ── Equipment name ──────────────────────────────────────────────────────
   Plain text. The 1,300-row search dropdown that used to hang off this field
   is gone: a reporter names the thing in front of them and the server matches
   it to the inventory, or files it as new. What remains here is (a) letting go
   of a scanned unit's id the moment the name is changed, so an edited name is
   not filed against the wrong sticker, and (b) the category hint. */
const scannedName = equipNameEl.value.trim();
equipNameEl.addEventListener('input', () => {
  if (equipIdEl.value && equipNameEl.value.trim() !== scannedName) {
    equipIdEl.value = '';
    assetTagEl.value = '';
    checkOpenReport('');
  }
  scheduleCatHint();
});

/* "Not this unit?" on the QR banner: forget the scanned unit and start typing. */
window.rfChangeEquipment = function () {
  equipIdEl.value = '';
  assetTagEl.value = '';
  checkOpenReport('');
  equipNameEl.value = '';
  locationSearchEl.value = '';
  locationHiddenEl.value = '';
  renderCatHint(null);
  const banner = document.querySelector('.qr-banner');
  if (banner) banner.hidden = true;
  try { equipNameEl.focus({ preventScroll: false }); } catch (e) { equipNameEl.focus(); }
};

/* ── Category hint ───────────────────────────────────────────────────────
   Read-only. The server works out the category and the responsible unit from
   the words typed (student_dashboard.php?guess_category=…); this shows the
   answer as one quiet line so the reporter can see it was understood, and so
   a panel watching over a shoulder can see the system doing the sorting. */
const UNIT_NAMES = { PMO: 'Property Management Office', ITSO: 'IT Services Office' };
let catHintTimer = 0, catHintSeq = 0;
function renderCatHint(j) {
  if (!catHintEl) return;
  if (!j || !j.category || /not sure/i.test(j.category)) { catHintEl.innerHTML = ''; return; }
  catHintEl.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Filed under <strong>' + escapeHtml(j.category) + '</strong>'
    + (j.unit && UNIT_NAMES[j.unit] ? ' · ' + escapeHtml(UNIT_NAMES[j.unit]) : '');
}
function scheduleCatHint() {
  clearTimeout(catHintTimer);
  const text = equipNameEl.value.trim();
  if (text.length < 3) { renderCatHint(null); return; }
  catHintTimer = setTimeout(() => {
    const seq = ++catHintSeq;
    fetch('student_dashboard.php?guess_category=' + encodeURIComponent(text), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(r => r.ok ? r.json() : null)
      .then(j => { if (seq === catHintSeq) renderCatHint(j); })
      .catch(() => {});
  }, 400);
}
if (equipNameEl.value.trim()) scheduleCatHint();   // a scanned or re-shown name


// Lower-cased once, with each entry carrying its own index — the filter used to
// re-lower-case all 304 locations and then indexOf() each rendered row.
const locationIndexed = locationParts.map((p, i) => ({
  ...p, i, lc: p.full.toLowerCase()
}));

function renderLocationDropdown(query) {
  const q = (query || '').trim().toLowerCase();
  // Every word has to appear somewhere, but they need not be adjacent. A
  // location reads "Annex 1 Campus - Building 12 ... - Bookstore", so matching
  // the query as one contiguous run meant "annex bookstore" - the natural way
  // to describe that room - returned "No location found" while "bookstore"
  // alone worked. People type what they remember, in their own order.
  const terms = q ? q.split(/\s+/).filter(Boolean) : [];
  const matches = terms.length
    ? locationIndexed.filter(entry => terms.every(t => entry.lc.includes(t)))
    : locationIndexed;

  if (!matches.length) {
    locationDropdown.innerHTML = '<div class="eq-empty"><i class="fas fa-map-marker-alt" style="margin-right:.3rem;opacity:.5"></i>No location found</div>';
    locationDropdown.classList.add('open');
    placeLocationDropdown();
    return;
  }

  const shown  = matches.slice(0, LOCATION_LIMIT);
  const hidden = matches.length - shown.length;

  // The room is what a reporter is looking for, so it leads; the campus and
  // building it sits in run underneath it. Putting the whole
  // "Campus • Building • Room" string on one line meant the room — the only part
  // that identifies the place — was always the part cut off by the ellipsis.
  let html = '';
  let lastGroup = null;
  for (const entry of shown) {
    const group = entry.campus + ' • ' + entry.bldg;
    if (group !== lastGroup) {
      html += `<div class="loc-group"><span class="loc-group-campus">${escapeHtml(entry.campus)}</span>${escapeHtml(entry.bldg)}</div>`;
      lastGroup = group;
    }
    html += `
      <div class="loc-item" data-index="${entry.i}">
        <span class="loc-pin"><i class="fas fa-map-marker-alt"></i></span>
        <span class="loc-name">${escapeHtml(entry.room)}</span>
      </div>`;
  }
  if (hidden > 0) {
    html += `<div class="eq-manual"><strong>${hidden.toLocaleString()} more location${hidden === 1 ? '' : 's'}.</strong> Type a building or room name to narrow it down.</div>`;
  }
  locationDropdown.innerHTML = html;
  locationDropdown.classList.add('open');
  locationFocusIdx = -1;
  placeLocationDropdown();
}

/* Drop-down or drop-up: whichever side of the field has more visible room.
   visualViewport is the part of the page not covered by the on-screen
   keyboard; without it (older browsers) the whole window counts. Re-run on
   viewport changes, because the keyboard sliding in is exactly the moment
   the room below disappears. */
function placeLocationDropdown() {
  if (!locationDropdown.classList.contains('open')) return;
  const vv = window.visualViewport;
  const viewTop = vv ? vv.offsetTop : 0;
  const viewH   = vv ? vv.height : window.innerHeight;
  const rect  = locationSearchEl.getBoundingClientRect();
  const below = viewTop + viewH - rect.bottom;
  const above = rect.top - viewTop;
  // Downward unless there is not even a short list's worth of room below
  // and the space above is the better of the two.
  const up    = below < 200 && above > below;
  locationDropdown.classList.toggle('up', up);
  locationDropdown.style.maxHeight = Math.max(150, Math.min(340, (up ? above : below) - 12)) + 'px';
}
if (window.visualViewport) {
  window.visualViewport.addEventListener('resize', placeLocationDropdown);
  window.visualViewport.addEventListener('scroll', placeLocationDropdown);
}
window.addEventListener('resize', placeLocationDropdown);

function selectLocation(location, byTap) {
  locationSearchEl.value = location;
  locationHiddenEl.value = location;
  locationDropdown.classList.remove('open');
  locationFocusIdx = -1;
  if (byTap) locationSearchEl.blur();   // see selectEquip: keeps it shut on touch
}

locationSearchEl.addEventListener('input', () => {
  locationHiddenEl.value = locationSearchEl.value.trim();
  renderLocationDropdown(locationSearchEl.value);
});
locationSearchEl.addEventListener('focus', () => renderLocationDropdown(locationSearchEl.value));
wireComboDismiss(locationSearchEl, locationDropdown);

// click, not pointerdown - see the equipment list above: preventing the default
// on pointerdown stops the finger scrolling the list.
locationDropdown.addEventListener('mousedown', e => { if (e.target.closest('.loc-item')) e.preventDefault(); });
locationDropdown.addEventListener('click', e => {
  const el = e.target.closest('.loc-item');
  if (!el) return;
  selectLocation(locationData[Number(el.dataset.index)] || '', true);
});
locationSearchEl.addEventListener('keydown', e => {
  const items = locationDropdown.querySelectorAll('.loc-item');
  if (!items.length) return;
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    locationFocusIdx = Math.min(locationFocusIdx + 1, items.length - 1);
    items.forEach((el, i) => el.classList.toggle('focused', i === locationFocusIdx));
    items[locationFocusIdx]?.scrollIntoView({block:'nearest'});
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    locationFocusIdx = Math.max(locationFocusIdx - 1, 0);
    items.forEach((el, i) => el.classList.toggle('focused', i === locationFocusIdx));
    items[locationFocusIdx]?.scrollIntoView({block:'nearest'});
  } else if (e.key === 'Enter' && locationFocusIdx >= 0) {
    e.preventDefault();
    selectLocation(locationData[Number(items[locationFocusIdx].dataset.index)] || '');
  } else if (e.key === 'Escape') {
    locationDropdown.classList.remove('open');
  }
});

// ── Multi-photo uploader (drag-drop, preview, size, remove, limits) ────────
const photoInput = document.getElementById('photo-input');
// (the separate photo drop-zone is gone; #media-zone handles both now)
const photoGrid  = document.getElementById('photo-grid');
const photoMeta  = document.getElementById('photo-meta');
const MAX_PHOTOS = 10;
const MAX_BYTES  = 10 * 1024 * 1024;
const OK_TYPES   = ['image/jpeg','image/png','image/webp'];
let photoStore   = []; // {file, url}

function fmtSize(b){ return b < 1048576 ? (b/1024).toFixed(0)+' KB' : (b/1048576).toFixed(1)+' MB'; }

function syncPhotoInput(){
  const dt = new DataTransfer();
  photoStore.forEach(p => dt.items.add(p.file));
  photoInput.files = dt.files;
}

function renderPhotos(){
  photoGrid.innerHTML = '';
  let total = 0;
  photoStore.forEach((p, idx) => {
    total += p.file.size;
    const cell = document.createElement('div');
    cell.className = 'pg-cell';
    cell.innerHTML =
      '<img src="'+p.url+'" alt="">' +
      '<button type="button" class="pg-x" data-i="'+idx+'" aria-label="Remove"><i class="fas fa-times"></i></button>' +
      '<span class="pg-size">'+fmtSize(p.file.size)+'</span>';
    photoGrid.appendChild(cell);
  });
  if (photoStore.length){
    photoMeta.innerHTML = '<i class="fas fa-images"></i> '+photoStore.length+' / '+MAX_PHOTOS+' photos · '+fmtSize(total)+' · '+(MAX_PHOTOS-photoStore.length)+' slot(s) left';
  } else {
    photoMeta.innerHTML = '';
  }
}

function addFiles(fileList){
  const errs = [];
  Array.from(fileList).forEach(file => {
    if (photoStore.length >= MAX_PHOTOS){ errs.push('Maximum '+MAX_PHOTOS+' photos.'); return; }
    if (!OK_TYPES.includes(file.type)){
      // HEIC is what an iPhone stores by default. iOS converts it to JPEG on
      // upload as long as the accept list does not mention HEIC - which is why
      // it must not be added there - but a file picked out of Files, or from
      // some Android cameras, still arrives in the original format. Say what to
      // do about it rather than "unsupported type".
      const heic = /hei[cf]/i.test(file.type) || /\.hei[cf]$/i.test(file.name);
      errs.push(heic
        ? file.name + ' is an iPhone HEIC photo. Take a screenshot of it and attach that, or set Settings › Camera › Formats › Most Compatible.'
        : file.name + ' is not a JPG, PNG or WEBP photo.');
      return;
    }
    if (file.size > MAX_BYTES){ errs.push(file.name+': over 10MB'); return; }
    if (photoStore.some(p => p.file.name===file.name && p.file.size===file.size)) return; // dedupe
    photoStore.push({ file, url: URL.createObjectURL(file) });
  });
  syncPhotoInput();
  renderPhotos();
  if (errs.length && window.toast) { /* optional */ }
  if (errs.length) { photoMeta.innerHTML = '<span style="color:#b42318">'+errs[0]+'</span>'; }
}

photoGrid.addEventListener('click', e => {
  const btn = e.target.closest('.pg-x'); if (!btn) return;
  const i = parseInt(btn.dataset.i, 10);
  if (photoStore[i]) { URL.revokeObjectURL(photoStore[i].url); photoStore.splice(i,1); }
  syncPhotoInput(); renderPhotos();
});

// ── Video evidence picker (mirrors photos; up to 2 short clips) ──
const videoInput = document.getElementById('video-input');
// (the separate video drop-zone is gone; #media-zone handles both now)
const videoGrid  = document.getElementById('video-grid');
const videoMeta  = document.getElementById('video-meta');
const MAX_VIDEOS = 2;
const MAX_VBYTES = 20 * 1024 * 1024;
const OK_VTYPES  = ['video/mp4','video/webm','video/quicktime'];
let videoStore   = [];
function syncVideoInput(){ const dt = new DataTransfer(); videoStore.forEach(v => dt.items.add(v.file)); videoInput.files = dt.files; }
function renderVideos(){
  videoGrid.innerHTML = ''; let total = 0;
  videoStore.forEach((v, idx) => {
    total += v.file.size;
    const cell = document.createElement('div');
    cell.className = 'pg-cell';
    cell.innerHTML =
      '<video src="'+v.url+'" muted playsinline preload="metadata" style="width:100%;height:100%;object-fit:cover;border-radius:8px;background:#000;"></video>' +
      '<button type="button" class="pg-x" data-i="'+idx+'" aria-label="Remove"><i class="fas fa-times"></i></button>' +
      '<span class="pg-size">'+fmtSize(v.file.size)+'</span>';
    videoGrid.appendChild(cell);
  });
  videoMeta.innerHTML = videoStore.length ? '<i class="fas fa-video"></i> '+videoStore.length+' / '+MAX_VIDEOS+' videos · '+fmtSize(total) : '';
}
function addVideoFiles(fileList){
  const errs = [];
  Array.from(fileList).forEach(file => {
    if (videoStore.length >= MAX_VIDEOS){ errs.push('Maximum '+MAX_VIDEOS+' videos.'); return; }
    if (!OK_VTYPES.includes(file.type)){ errs.push(file.name+' is not an MP4, WEBM or MOV video.'); return; }
    if (file.size > MAX_VBYTES){ errs.push(file.name+': over 20MB'); return; }
    if (videoStore.some(v => v.file.name===file.name && v.file.size===file.size)) return;
    videoStore.push({ file, url: URL.createObjectURL(file) });
  });
  syncVideoInput(); renderVideos();
  if (errs.length) { videoMeta.innerHTML = '<span style="color:#b42318">'+errs[0]+'</span>'; }
}
if (videoInput) {
  videoGrid.addEventListener('click', e => {
    const btn = e.target.closest('.pg-x'); if (!btn) return;
    const i = parseInt(btn.dataset.i, 10);
    if (videoStore[i]) { URL.revokeObjectURL(videoStore[i].url); videoStore.splice(i,1); }
    syncVideoInput(); renderVideos();
  });
}

/* ── The single evidence picker ───────────────────────────────────────────────
   Sorts what arrives by type and hands each kind to the picker that already
   knew how to deal with it, so the counts, the size limits, the previews and
   the two form fields all behave exactly as they did when there were two boxes.
   The reporter simply no longer has to choose which box before choosing a file. */
const mediaZone  = document.getElementById('media-zone');
const mediaInput = document.getElementById('media-input');
const mediaMeta  = document.getElementById('media-meta');

function addMedia(fileList) {
  const files  = Array.from(fileList || []);
  if (!files.length) return;
  if (typeof rfMarkEvidence === 'function') rfMarkEvidence(true);
  // Some pickers hand over a file with no type at all - a .heic out of Files on
  // an iPhone is the common one - so fall back to the extension. Getting this
  // wrong would send it to the "not a photo or video" branch instead of the
  // advice about HEIC.
  const isImg = f => /^image\//i.test(f.type) || (!f.type && /\.(jpe?g|png|webp|hei[cf])$/i.test(f.name));
  const isVid = f => /^video\//i.test(f.type) || (!f.type && /\.(mp4|webm|mov)$/i.test(f.name));
  const images = files.filter(isImg);
  const videos = files.filter(f => !isImg(f) && isVid(f));
  // Anything that is neither - a PDF dragged in by mistake - is named rather
  // than dropped in silence.
  const other  = files.filter(f => !isImg(f) && !isVid(f));
  if (images.length) addFiles(images);
  if (videos.length) addVideoFiles(videos);
  mediaMeta.innerHTML = other.length
    ? '<span style="color:#b42318">' + other[0].name + ': not a photo or video</span>'
    : '';
}

if (mediaZone && mediaInput) {
  mediaInput.addEventListener('change', () => {
    addMedia(mediaInput.files);
    // Cleared so picking the same file twice in a row still raises a change.
    mediaInput.value = '';
  });
  ['dragenter','dragover'].forEach(ev => mediaZone.addEventListener(ev, e => { e.preventDefault(); mediaZone.classList.add('drag'); }));
  ['dragleave','dragend'].forEach(ev => mediaZone.addEventListener(ev, e => { e.preventDefault(); mediaZone.classList.remove('drag'); }));
  mediaZone.addEventListener('drop', e => {
    e.preventDefault(); mediaZone.classList.remove('drag');
    if (e.dataTransfer && e.dataTransfer.files) addMedia(e.dataTransfer.files);
  });
}

/* ── Inline required-field warnings (red outline + message under the field) ── */
function rfFieldError(el, msg) {
  el.classList.add('f-err');
  const anchor = el.closest('.fi-wrap') || el;          // message goes under the wrapper, not inside it
  let m = anchor.nextElementSibling;
  if (!(m && m.classList && m.classList.contains('f-msg'))) {
    m = document.createElement('div'); m.className = 'f-msg';
    anchor.insertAdjacentElement('afterend', m);
  }
  m.innerHTML = '<i class="fas fa-circle-exclamation"></i> ' + msg;
}
function rfFieldOk(el) {
  el.classList.remove('f-err');
  const anchor = el.closest('.fi-wrap') || el;
  const m = anchor.nextElementSibling;
  if (m && m.classList && m.classList.contains('f-msg')) m.remove();
}
/* scope: what to check — one section while stepping, the whole form on submit.
   quiet: mark the fields inline but skip the popup, which is too heavy a
   response to "you missed one on this step".
   Fields hidden because they were pre-filled already hold their values, so they
   pass on their own and need no special handling here. */
function rfValidate(scope, quiet) {
  const root = scope || reportForm;
  const bad = [];
  root.querySelectorAll('input[required], textarea[required], select[required]').forEach((el) => {
    if (el.type === 'hidden') return;
    const empty = el.type === 'checkbox' ? !el.checked : !String(el.value || '').trim();
    if (empty) {
      const label = el.closest('.fg')?.querySelector('.fl')?.childNodes[0]?.textContent?.trim()
        || el.getAttribute('placeholder') || 'This field';
      rfFieldError(el, (el.type === 'checkbox' ? 'Please tick this confirmation.' : label.replace(/\s*\*$/, '') + ' is required.'));
      bad.push(el);
    } else {
      rfFieldOk(el);
    }
  });
  if (bad.length) {
    // Build a friendly list of the fields that need attention for the popup.
    const seen = {};
    const labels = [];
    bad.forEach((el) => {
      let l = el.closest('.fg')?.querySelector('.fl')?.childNodes[0]?.textContent?.trim();
      if (l) l = l.replace(/\s*\*$/, '');
      else if (el.type === 'checkbox') l = 'Data Privacy agreement';
      else l = el.getAttribute('placeholder') || 'A required field';
      if (!seen[l]) { seen[l] = 1; labels.push(l); }
    });
    if (window.setErrFirstField) window.setErrFirstField(bad[0]);
    if (!quiet && window.showErrModal) {
      window.showErrModal(
        labels.length === 1 ? 'One more detail needed' : 'Some details are missing',
        'Please complete the following before submitting your report:',
        labels
      );
    } else {
      bad[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (typeof bad[0].focus === 'function') { try { bad[0].focus({ preventScroll: true }); } catch (e) { bad[0].focus(); } }
    }
  }
  return bad.length === 0;
}
document.addEventListener('input', (e) => {
  const el = e.target;
  if (!el.classList || !el.classList.contains('f-err')) return;
  const filled = el.type === 'checkbox' ? el.checked : String(el.value || '').trim();
  if (filled) rfFieldOk(el);
});

// One submission per form. pointer-events:none on the button stops a second
// tap, but not an Enter key in a text field, and on slow campus Wi-Fi a
// reporter waiting on a spinner does press Enter. The flag stops the second
// submit event itself; the overlay and the disabled button are the feedback.
let rfSubmitting = false;
const rfSubmitLabel = submitBtn ? submitBtn.innerHTML : '';
/* Evidence is not an <input required>, so rfValidate() cannot see it: the
   files live in photoStore / videoStore and reach the form through two hidden
   inputs. Checked here, in the same breath as the text fields, and the server
   refuses a report without it as well. */
const evidenceCard = document.getElementById('evidenceCard');
const evidenceMsg  = document.getElementById('evidenceMsg');
function rfHasEvidence() { return photoStore.length > 0 || videoStore.length > 0; }
function rfMarkEvidence(ok) {
  if (evidenceCard) evidenceCard.classList.toggle('f-err-card', !ok);
  if (evidenceMsg)  evidenceMsg.hidden = ok;
}
reportForm?.addEventListener('submit', (e) => {
  if (rfSubmitting) { e.preventDefault(); return; }
  locationHiddenEl.value = locationSearchEl.value.trim();

  const mediaOk = rfHasEvidence();
  rfMarkEvidence(mediaOk);
  if (mediaOk) {
    // The text fields alone: rfValidate() names what is missing itself.
    if (!rfValidate()) { e.preventDefault(); loadingOverlay?.classList.remove('show'); return; }
  } else {
    // Mark the fields quietly and put everything, evidence included, in one
    // popup rather than two in a row.
    const fieldsOk = rfValidate(null, true);
    e.preventDefault();
    loadingOverlay?.classList.remove('show');
    const labels = fieldsOk ? [] : Array.from(reportForm.querySelectorAll('.f-err'))
      .map(el => (el.closest('.fg')?.querySelector('.fl')?.childNodes[0]?.textContent || '').trim().replace(/\s*\*$/, ''))
      .filter((l, i, a) => l && a.indexOf(l) === i);
    labels.push('Photo or video');
    if (fieldsOk && window.setErrFirstField) window.setErrFirstField(document.querySelector('[data-camera="photo"]'));
    if (window.showErrModal) {
      window.showErrModal(
        labels.length === 1 ? 'Add a photo or video' : 'Some details are missing',
        labels.length === 1
          ? 'The technician needs to see the problem. Take a photo or record a short video, then submit.'
          : 'Please complete the following before submitting your report:',
        labels.length === 1 ? [] : labels);
    } else {
      evidenceCard?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    return;
  }

  rfSubmitting = true;
  loadingOverlay?.classList.add('show');
  loadingOverlay?.setAttribute('aria-hidden', 'false');
  if (submitBtn) {
    submitBtn.classList.add('is-loading');
    submitBtn.setAttribute('aria-busy', 'true');
    submitBtn.innerHTML = 'Submitting <span class="btn-arrow"><i class="fas fa-spinner"></i></span>';
    // Disabled on the next tick, not now: a disabled submit button is excluded
    // from the form data the browser is about to serialise.
    setTimeout(() => { submitBtn.disabled = true; }, 0);
  }
});
// Back button after a submit restores the page from the bfcache with the
// spinner still spinning and the button still disabled. Put it back.
window.addEventListener('pageshow', (ev) => {
  if (!ev.persisted) return;
  rfSubmitting = false;
  loadingOverlay?.classList.remove('show');
  loadingOverlay?.setAttribute('aria-hidden', 'true');
  if (submitBtn) {
    submitBtn.disabled = false;
    submitBtn.classList.remove('is-loading');
    submitBtn.removeAttribute('aria-busy');
    if (rfSubmitLabel) submitBtn.innerHTML = rfSubmitLabel;
  }
});
</script>
<script>
// ── Section scroll-spy ─────────────────────────────────────────────────────
(function () {
  // Highlight the section card you're currently viewing.
  const cards = document.querySelectorAll('.section-card');
  if ('IntersectionObserver' in window && cards.length) {
    const obs = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (e.isIntersecting) {
          cards.forEach((c) => c.classList.remove('is-active'));
          e.target.classList.add('is-active');
        }
      });
    }, { rootMargin: '-40% 0px -50% 0px', threshold: 0 });
    cards.forEach((c) => obs.observe(c));
  }
})();
</script>
<?php if ($prefillEq): ?>
<script>
/* Arrived via equipment QR scan: the unit and the room are already filled in,
   so drop the cursor straight into the description. */
window.addEventListener('load', function () {
  var desc = document.querySelector('textarea[name="defect_description"]');
  if (desc) setTimeout(function () { desc.focus({ preventScroll: true }); }, 500);
});
</script>
<?php endif; ?>
<!-- Minimalist error / incomplete-fields popup -->
<div class="err-modal" id="errModal" aria-hidden="true">
  <div class="err-box" role="dialog" aria-modal="true" aria-labelledby="errTitle">
    <div class="err-ic"><i class="fas fa-triangle-exclamation"></i></div>
    <h3 id="errTitle">Some details are missing</h3>
    <p id="errMsg">Please complete the following before submitting your report.</p>
    <ul class="err-list" id="errList"></ul>
    <button type="button" class="err-btn" id="errClose">Review the form</button>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('errModal');
  if (!modal) return;
  var first = null;
  window.showErrModal = function (title, msg, items) {
    document.getElementById('errTitle').textContent = title;
    document.getElementById('errMsg').textContent = msg;
    var list = document.getElementById('errList');
    list.innerHTML = '';
    (items || []).forEach(function (t) {
      var li = document.createElement('li');
      var ic = document.createElement('i'); ic.className = 'fas fa-circle-exclamation';
      var sp = document.createElement('span'); sp.textContent = t;
      li.appendChild(ic); li.appendChild(sp); list.appendChild(li);
    });
    list.style.display = (items && items.length) ? '' : 'none';
    modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false');
  };
  window.setErrFirstField = function (el) { first = el || null; };
  function close() {
    modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true');
    if (first) {
      var b = first; first = null;
      b.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(function () { try { b.focus({ preventScroll: true }); } catch (e) {} b.classList.remove('f-flash'); void b.offsetWidth; b.classList.add('f-flash'); }, 300);
    }
  }
  document.getElementById('errClose').addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('show')) close(); });
})();
<?php if (!empty($error)): ?>
window.addEventListener('DOMContentLoaded', function () {
  if (window.showErrModal) window.showErrModal('Please check your report', <?php echo json_encode($error, JSON_UNESCAPED_UNICODE); ?>, []);
});
<?php endif; ?>
</script>

<!-- Exit confirmation: asks before leaving the report and returning to sign-in -->
<div class="err-modal" id="exitModal" aria-hidden="true">
  <div class="err-box" role="dialog" aria-modal="true" aria-labelledby="exitTitle">
    <div class="err-ic" style="background:#FFF4E5;color:#C9960C;"><i class="fas fa-right-from-bracket"></i></div>
    <h3 id="exitTitle">Do you really want to exit?</h3>
    <p>You haven't submitted this report yet. If you leave now and go back to sign-in, the details you've entered will be lost.</p>
    <div class="exit-actions">
      <button type="button" class="err-btn2" id="exitNo">No</button>
      <button type="button" class="err-btn" id="exitYes">Yes</button>
    </div>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('exitModal');
  if (!modal) return;
  if (<?php echo !empty($success) ? 'true' : 'false'; ?>) return; // report already submitted — nothing to lose, no guard
  var pendingUrl = null;
  function open(url) { pendingUrl = url; modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false'); }
  function close() { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); pendingUrl = null; }
  // Intercept the Back button and the Sign-out link.
  document.querySelectorAll('.btn-cancel, .logout-link').forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      // Stop the page-transition handler (delegated on document) from navigating anyway.
      e.stopPropagation();
      if (e.stopImmediatePropagation) e.stopImmediatePropagation();
      open(link.getAttribute('href'));
    }, true); // capture phase — run before the document-level transition listener
  });
  // Intercept the browser / phone Back gesture (swipe-back or hardware/soft back button).
  try {
    history.pushState(null, document.title, location.href);
    window.addEventListener('popstate', function () {
      history.pushState(null, document.title, location.href); // re-trap so the page stays put
      if (!modal.classList.contains('show')) open('student_index.php');
    });
  } catch (e) { /* History API unavailable — the on-page links are still guarded */ }
  document.getElementById('exitYes').addEventListener('click', function () {
    var url = pendingUrl; close();
    if (url) window.location.href = url;
  });
  document.getElementById('exitNo').addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('show')) close(); });
})();
</script>
<?php require __DIR__ . '/includes/site_ui.php'; ?>
<script src="assets/input_guard.js" defer></script>
<script src="assets/camera_capture.js"></script>
<!-- Downscales camera photos on the device before they upload. Not deferred:
     it has to be listening before the form can be submitted. -->
<script src="assets/photo_shrink.js"></script>
<?php require __DIR__ . '/includes/becca_widget.php'; ?>
</body>
</html>
