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

// A failed submit must win over the saved profile, or it would overwrite what
// the reporter had just corrected by hand.
$preDept   = (string)($_POST['reporter_department'] ?? $prefill['department']);
$preCourse = (string)($_POST['reporter_course']     ?? $prefill['course']);
$preLevel  = (string)($_POST['reporter_level']      ?? $prefill['level']);
$prePhone  = (string)($_POST['student_phone']       ?? $prefill['phone']);

// Enough to fill the section on its own — the reporter only has to confirm it.
$profileComplete = $preDept !== ''
    && ($preCourse !== '' || empty($becPrograms[$preDept]))
    && ($preLevel  !== '' || !isset($becLevels[$preDept]));

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
$equipment_rows = getAllEquipment();
$category_rows = getAllCategories();
$equipment_list = array_values(array_map(static function ($row) {
    $id = (string)($row['equipment_id'] ?? $row['id'] ?? '');
    return [
        'id' => $id,
        'name' => (string)($row['equipment_name'] ?? ''),
        'category' => (string)($row['category_name'] ?? $row['equipment_category'] ?? $row['category'] ?? 'Uncategorized'),
        'asset_tag' => (string)($row['asset_tag'] ?? ''),
        'location' => trim((string)($row['location'] ?? '')),
        // Where this entry came from. The PMO's inventory creates EQ- ids (and
        // imported ones keep their property number); a reporter who types a
        // name that is not on the list gets a MAN- row created for them. Both
        // sit in the same table, so without this the suggestions a reporter
        // scrolls through are mostly other people's past reports wearing the
        // same clothes as real inventory.
        'source' => str_starts_with($id, 'MAN-') ? 'reported' : 'inventory',
    ];
}, $equipment_rows));

// Inventory first, so it is what a reporter sees and picks.
usort($equipment_list, static function ($a, $b) {
    if ($a['source'] !== $b['source']) { return $a['source'] === 'inventory' ? -1 : 1; }
    return strnatcasecmp($a['name'], $b['name']);
});
$equipment_counts = [
    'inventory' => count(array_filter($equipment_list, static fn($e) => $e['source'] === 'inventory')),
    'reported'  => count(array_filter($equipment_list, static fn($e) => $e['source'] === 'reported')),
];

// Canonical BEC categories + campus/building/room locations from the PMO inventory.
require_once __DIR__ . '/data/bec_inventory_reference.php';

/*
 * Locations keep the PMO inventory's own order — Main Campus, then Annex 1,
 * then Annex 2, and inside each the buildings as the workbook lists them.
 * Sorting this alphabetically buried Main Campus behind every Annex 1 room, so
 * a reporter opening the list saw a screen of buildings on the wrong campus and
 * concluded the list was out of date.
 *
 * Anything an old report left behind that is not a catalogued location follows
 * at the end rather than being interleaved with the real ones.
 */
$inventoryLocations = becLocations();
$knownLocations     = array_flip($inventoryLocations);
$extraLocations     = [];
foreach ($equipment_list as $item) {
    $loc = trim((string)($item['location'] ?? ''));
    if ($loc === '' || isset($knownLocations[$loc])) { continue; }
    $knownLocations[$loc] = true;
    $extraLocations[] = $loc;
}
sort($extraLocations, SORT_NATURAL | SORT_FLAG_CASE);
$location_options = array_merge($inventoryLocations, $extraLocations);

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

$category_options = array_values(array_unique(array_filter(array_merge(
    becCategories(),
    array_map(static fn($row) => trim((string)($row['category_name'] ?? '')), $category_rows),
    array_map(static fn($item) => trim((string)($item['category'] ?? '')), $equipment_list)
))));
sort($category_options, SORT_NATURAL | SORT_FLAG_CASE);

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

function createStudentManualEquipment($conn, string $name, string $category, string $location, string $assetTag = ''): ?array {
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
            $unit = classifyDepartmentByEquipment($equipmentId, $name, $category, $location);
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
    $issueDate = htmlspecialchars((string)($report['issue_date'] ?? ''), ENT_QUOTES, 'UTF-8');
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
      <tr><td style="padding:9px 0;font-weight:700;color:#5C3838;border-bottom:1px solid #efe7da;">Issue Noticed</td><td style="padding:9px 0;border-bottom:1px solid #efe7da;">{$issueDate}</td></tr>
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
    $postedEquipmentName = trim((string)($_POST['equipment_name'] ?? ''));
    if ($postedEquipmentName === '') {
        $postedEquipmentName = trim((string)($_POST['equipment_name_display'] ?? ''));
    }
    $postedAssetTag = trim((string)($_POST['asset_tag'] ?? ''));
    $_POST['equipment_name'] = $postedEquipmentName;

    $selected_equipment = null;
    foreach ($equipment_list as $item) {
        if ($selected_equipment_id !== '' && $item['id'] === $selected_equipment_id) {
            $selected_equipment = $item;
            break;
        }
    }

    if (!$selected_equipment && $postedAssetTag !== '') {
        foreach ($equipment_list as $item) {
            if (strcasecmp((string)($item['asset_tag'] ?? ''), $postedAssetTag) === 0) {
                $selected_equipment = $item;
                break;
            }
        }
    }

    if (!$selected_equipment && $postedEquipmentName !== '') {
        foreach ($equipment_list as $item) {
            if (strcasecmp((string)($item['name'] ?? ''), $postedEquipmentName) === 0) {
                $selected_equipment = $item;
                break;
            }
        }
    }

    if (!$selected_equipment) {
        $manualName = $postedEquipmentName;
        $manualCategory = trim((string)($_POST['category'] ?? ''));
        $manualLocation = trim((string)($_POST['location'] ?? ''));
        $manualAssetTag = $postedAssetTag;

        if ($manualName === '') {
            $error = 'Please enter the equipment name.';
        } else {
            $selected_equipment = createStudentManualEquipment($conn, $manualName, $manualCategory, $manualLocation, $manualAssetTag);
            if (!$selected_equipment) {
                $error = 'We could not save that equipment entry. Please check the details and try again.';
            }
        }
    }

    if ($selected_equipment) {
        $_POST['equipment_id'] = $selected_equipment['id'];
        $_POST['equipment_name'] = $selected_equipment['name'];
        $_POST['category'] = $selected_equipment['category'] !== '' ? $selected_equipment['category'] : trim((string)($_POST['category'] ?? 'Other / Not sure'));
        $_POST['asset_tag'] = $selected_equipment['asset_tag'];
        if (empty(trim($_POST['location'] ?? '')) && $selected_equipment['location'] !== '') {
            $_POST['location'] = $selected_equipment['location'];
        }
    } else {
        $_POST['category'] = trim((string)($_POST['category'] ?? 'Other / Not sure'));
    }

    // Validate required fields
    $required = ['equipment_id','equipment_name','category','location','defect_description','issue_date'];
    $missing  = [];
    foreach ($required as $f) {
        if (empty(trim($_POST[$f] ?? ''))) {
            $missing[] = $f;
        }
    }

    if (!$error && $missing) {
        $error = 'Please fill in all required fields.';
    }

    // Reporter's BEC department / course (academic categorization)
    $reporterDepartment = trim($_POST['reporter_department'] ?? '');
    $reporterCourse     = trim($_POST['reporter_course'] ?? '');
    $reporterLevel      = trim($_POST['reporter_level'] ?? '');
    if (!$error) {
        if ($reporterDepartment === '' || !array_key_exists($reporterDepartment, $becPrograms)) {
            $error = 'Please select your department or academic unit.';
        } elseif (!empty($becPrograms[$reporterDepartment]) && ($reporterCourse === '' || !in_array($reporterCourse, $becPrograms[$reporterDepartment], true))) {
            $error = 'Please select your course or program.';
        } elseif (empty($becPrograms[$reporterDepartment])) {
            $reporterCourse = ''; // department has no specific course
        }
    }
    // Year / grade level, for the departments where the programme does not
    // already say it. Kept as its own field: appending it to the course left a
    // value that matched no entry in the official programme list, so it could
    // never be read back to pre-fill this form.
    if (!$error && isset($becLevels[$reporterDepartment])) {
        if ($reporterLevel === '' || !in_array($reporterLevel, $becLevels[$reporterDepartment], true)) {
            $error = 'Please select your year or grade level.';
        }
    } else {
        $reporterLevel = '';   // this department names its level in the course
    }

    // The textarea carries maxlength="1500", but that is only a browser hint —
    // a 200,000-character description was accepted and stored verbatim, which
    // is how pasted junk ended up bloating report pages. Enforce it server-side.
    if (!$error && mb_strlen(trim((string)($_POST['defect_description'] ?? ''))) > 1500) {
        $error = 'Please keep the problem description under 1,500 characters.';
    }

    // Contact number (optional): if given, must be exactly 11 digits (numbers only).
    $reporterPhone = trim($_POST['student_phone'] ?? '');
    if (!$error && $reporterPhone !== '' && !preg_match('/^\d{11}$/', $reporterPhone)) {
        $error = 'Please enter a valid 11-digit mobile number (numbers only), e.g. 09171234567.';
    }

    // Operational impact — a required triage signal the PMO uses to prioritise.
    $stillUsable = trim((string)($_POST['still_usable'] ?? ''));
    if (!$error && !in_array($stillUsable, ['Yes', 'Partially', 'No'], true)) {
        $error = 'Please indicate whether the equipment is still usable.';
    }

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
                'issue_description' => trim($_POST['defect_description']),
                'priority' => $reportPriority,
                'usable_status' => $stillUsable,
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
                // Save the reporter's self-provided details onto their profile (visible to admin).
                becSyncReporterProfile($student_email, $reporterDepartment, $reporterCourse, $reporterPhone, $reporterLevel);

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
.logo-text span   { font-size:.62rem;color:var(--ink3);text-transform:uppercase;letter-spacing:1.5px; }

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
.logout-link {
  margin-left:auto;flex-shrink:0;color:var(--ink3);font-size:.9rem;
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
.ro-value,
.fi-hint,
.photo-sub,
.modal-sub,
.ticket-num,
.ticket-copy {
  overflow-wrap:anywhere;
}

/* ── PROGRESS STEPS ── */
.steps {
  display:flex;align-items:center;gap:0;
  background:var(--surface);border:1px solid var(--border);
  border-radius:14px;padding:.6rem 1rem;
  margin-bottom:1.75rem;
  box-shadow:var(--shadow-sm);
  animation:riseIn .55s cubic-bezier(.22,1,.36,1) .1s both;
  overflow-x:auto;
}
.step {
  display:flex;align-items:center;gap:.45rem;
  flex:1;min-width:80px;
  font-size:.7rem;color:var(--ink3);font-weight:500;
  white-space:nowrap;
}
.step.active { color:var(--maroon);font-weight:600; }
.step.done   { color:var(--green); }
.step-dot {
  width:22px;height:22px;flex-shrink:0;border-radius:50%;
  background:var(--border);display:flex;align-items:center;justify-content:center;
  font-size:.6rem;color:var(--ink3);font-weight:700;
}
.step.active .step-dot { background:var(--maroon);color:#fff; }
.step.done   .step-dot { background:var(--green-bg);color:var(--green);border:1.5px solid var(--green-border); }
.step-connector { width:24px;height:1px;background:var(--border);flex-shrink:0;margin:0 .25rem; }

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

/* ── REPORTER INFO (read-only) ── */
.reporter-grid {
  display:grid;grid-template-columns:1fr 1fr;gap:.75rem;
}
.ro-field {
  background:var(--paper);border:1px solid var(--border);
  border-radius:10px;padding:.65rem .9rem;
}
/* Matches .fl — these sit directly above the editable pickers in the same card,
   and two label sizes one under the other read as a mistake. */
.ro-label { font-size:.78rem;font-weight:600;color:var(--ink3);text-transform:uppercase;letter-spacing:.8px;margin-bottom:.2rem; }
.ro-value { font-size:.85rem;color:var(--ink);font-weight:500; }

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
.fl .req { color:var(--maroon);margin-left:.12rem; }
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
.known{display:flex;align-items:center;gap:.75rem;margin-top:.85rem;padding:.8rem .95rem;
  background:#F4FAF5;border:1px solid #CFE6D4;border-left:3px solid var(--ok-tx);border-radius:12px;}
.known-ic{flex-shrink:0;width:30px;height:30px;border-radius:50%;background:rgba(26,122,51,.12);
  display:flex;align-items:center;justify-content:center;color:var(--ok-tx);font-size:.85rem;}
.known-txt{flex:1;min-width:0;line-height:1.5;}
.known-txt b{display:block;font-size:.86rem;color:#1C1008;font-weight:600;}
.known-txt span{display:block;font-size:.76rem;color:#5C3838;overflow-wrap:anywhere;}
.known-edit{flex-shrink:0;background:none;border:1.5px solid #CFE6D4;border-radius:20px;
  padding:.4rem .85rem;min-height:38px;font-family:'DM Sans',sans-serif;font-size:.75rem;
  font-weight:700;color:var(--ok-tx);cursor:pointer;transition:background .15s,border-color .15s;}
.known-edit:hover{background:rgba(26,122,51,.08);border-color:var(--ok-tx);}
@media (max-width:520px){
  .known{flex-wrap:wrap;}
  .known-edit{width:100%;min-height:44px;}
}
.fi-hint { font-size:.74rem;color:var(--ink3);margin-top:.28rem;display:flex;align-items:center;gap:.3rem; }
.fi-hint i { font-size:.6rem; }

/* ── EQUIPMENT SEARCH AUTOCOMPLETE ── */
.equip-wrap { position:relative; }
.equip-dropdown {
  position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:100;
  background:var(--surface);border:1.5px solid var(--maroon);
  border-radius:10px;box-shadow:0 8px 30px rgba(44,10,10,.15);
  max-height:340px;overflow-y:auto;display:none;
  -webkit-overflow-scrolling:touch;overscroll-behavior:contain;
}
.equip-dropdown.open { display:block; }
.search-dd {
  position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:100;
  background:var(--surface);border:1.5px solid var(--maroon);
  border-radius:10px;box-shadow:0 8px 30px rgba(44,10,10,.15);
  max-height:340px;overflow-y:auto;display:none;
  -webkit-overflow-scrolling:touch;overscroll-behavior:contain;
}
.search-dd.open { display:block; }
.eq-group-label {
  font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;
  color:var(--ink3);padding:.55rem .85rem .25rem;
  border-top:1px solid var(--border);
}
.eq-group-label:first-child { border-top:none; }
/* Which store an entry came from. The inventory heading carries the brand
   colour; anything a reporter typed in a past report is set apart and muted so
   it cannot be mistaken for a cataloged asset. */
.eq-source-label {
  font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:1.1px;
  color:var(--maroon);background:rgba(123,29,29,.05);
  padding:.5rem .85rem;border-top:1px solid var(--border);
}
.eq-source-label:first-child { border-top:none; }
.eq-source-label ~ .eq-source-label,
.eq-source-label + .eq-group-label { border-top:none; }
.eq-item-reported .eq-id { opacity:.55; }
.eq-item-reported .eq-name { color:var(--ink2); }
.eq-item {
  display:flex;align-items:center;gap:.6rem;
  padding:.55rem .85rem;cursor:pointer;
  transition:background .12s;font-size:.84rem;color:var(--ink);
}
.eq-item:hover,.eq-item.focused { background:var(--maroon-soft); }
.eq-item .eq-id {
  font-size:.65rem;font-weight:600;color:var(--maroon);
  background:rgba(123,29,29,.08);border-radius:4px;padding:.1rem .35rem;
  flex-shrink:0;
}
/* clean 2-line row: name + single-line truncated location (no runaway wrapping) */
.eq-body { display:flex;flex-direction:column;min-width:0;flex:1;gap:.05rem; }
.eq-name { font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
/* #9E8070 on white is 3.6:1 — under the 4.5:1 needed for text this small, and it
   is the line that says which room the asset is in. #8A6E5E measures 4.7:1. */
.eq-loc  { font-size:.72rem;color:#8A6E5E;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.eq-empty { padding:.75rem .85rem;font-size:.82rem;color:var(--ink3);text-align:center; }
.eq-manual {
  padding:.7rem .85rem;border-top:1px solid var(--border);
  background:#FFFBEF;color:var(--ink2);font-size:.78rem;line-height:1.45;
}
.eq-manual strong { color:var(--maroon); }
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
.usable-group { display:flex;gap:.5rem;flex-wrap:wrap; }
.usable-opt   { display:none; }
.usable-label {
  flex:1 1 160px;display:flex;align-items:center;justify-content:center;gap:.4rem;
  padding:.55rem;border:1.5px solid var(--border);
  border-radius:8px;cursor:pointer;font-size:.78rem;font-weight:500;color:var(--ink2);
  transition:all .16s;text-align:center;
}
.usable-opt:checked + .usable-label.yes  { color:var(--ok-tx);background:#F0FDF4;border-color:#4ADE80; }
.usable-opt:checked + .usable-label.part { color:#92400E;background:#FFFBEB;border-color:#FCD34D; }
.usable-opt:checked + .usable-label.no   { color:var(--bad-tx);background:#FEF2F2;border-color:#F87171; }

/* ── PHOTO UPLOAD ── */
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

/* ── One section at a time ──────────────────────────────────────────────────
   Reporters said the form was too much scrolling and asked to finish one part
   before the next appears. Only .wz — added by JavaScript — hides anything, so
   with scripting off or broken the form stays the single scrollable page it has
   always been, and every field is still submitted by the same one form. */
.wz .section-card { display:none; }
.wz .section-card.wz-on { display:block; }
.wz-nav { display:flex;align-items:center;gap:.8rem;margin-top:1.4rem;flex-wrap:wrap; }
.wz-nav .wz-count { font-size:.78rem;color:var(--ink3);margin-right:auto; }
.wz-next {
  flex:1;min-width:200px;padding:.9rem 1.5rem;
  background:var(--maroon-d);color:#fff;border:none;border-radius:11px;
  font-family:'DM Sans',sans-serif;font-size:.95rem;font-weight:600;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:.55rem;
  transition:all .22s cubic-bezier(.22,1,.36,1);
  box-shadow:0 8px 20px rgba(74,14,14,.25);letter-spacing:-.01em;-webkit-appearance:none;
}
.wz-next:hover { background:var(--maroon);box-shadow:0 14px 28px rgba(74,14,14,.3); }
.wz-back {
  padding:.9rem 1.25rem;border:1.5px solid var(--border);border-radius:11px;
  color:var(--ink3);font-size:.85rem;font-weight:500;background:none;cursor:pointer;
  /* Named properties, not "all": "all" includes visibility, which is how this
     button is shown and hidden between steps, and animating it left the control
     still reading as hidden for the length of the transition. */
  transition:border-color .18s, color .18s;
  display:inline-flex;align-items:center;gap:.4rem;min-height:44px;
}
.wz-back:hover { border-color:var(--maroon);color:var(--maroon); }
@media(max-width:640px){
  .wz-nav .wz-count { width:100%;margin:0 0 .2rem;text-align:center; }
  .wz-next { min-width:0; }
}

/* ── Form progress stepper (sticky, scrollspy) ── */
.fsteps{position:sticky;top:.6rem;z-index:60;display:flex;gap:6px;margin:0 0 1.1rem;padding:8px;border-radius:14px;
  background:rgba(255,255,255,.88);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);
  border:1px solid var(--border,#E8DDD0);box-shadow:0 6px 18px rgba(44,10,10,.08);overflow-x:auto;scrollbar-width:none;}
.fsteps::-webkit-scrollbar{display:none;}
.fstep{flex:1;min-width:max-content;display:flex;align-items:center;gap:7px;padding:.5rem .8rem;border-radius:10px;
  border:none;background:none;font:inherit;font-size:.74rem;font-weight:700;color:#9E8070;cursor:pointer;white-space:nowrap;transition:all .18s;}
.fstep .fs-n{width:22px;height:22px;flex-shrink:0;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:.66rem;font-weight:800;background:#F1E9DC;color:#9E8070;transition:all .18s;}
.fstep:hover{color:#7B1D1D;background:rgba(123,29,29,.06);}
.fstep.on{color:#7B1D1D;background:rgba(123,29,29,.08);}
.fstep.on .fs-n{background:linear-gradient(135deg,#4A0E0E,#7B1D1D);color:#fff;box-shadow:0 3px 8px rgba(74,14,14,.3);}
.fstep.done .fs-n{background:linear-gradient(135deg,var(--ok-tx),#2FA455);color:#fff;}
.fstep.done{color:var(--ok-tx);}
@media(max-width:640px){.fsteps{top:.4rem;}.fstep{font-size:.68rem;padding:.45rem .7rem;min-height:44px;justify-content:center;}.fstep .fs-lbl{display:none;}.fstep.on .fs-lbl{display:inline;}}

/* inline required-field warnings */
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
  /* Hide the 3-stage journey strip on phones. Its labels are dropped at this
     width, which left three unlabelled circles sitting directly above the
     labelled 5-section form stepper — two numbered rows that read as a
     duplicate. The section stepper below carries the same progress meaning. */
  .steps { display:none; }
  .step-connector { width:16px; }
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
    font-size:.68rem;
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

  .steps {
    padding: .45rem .55rem;
  }

  .step {
    min-width: 0;
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
/* Sticky progress stepper (top offset set by JS to sit under the topbar) */
.steps { position: sticky; z-index: 55; }
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
        <span>User Portal</span>
      </div>
    </div>
    <div class="user-chip">
      <div class="user-avatar"><?php echo strtoupper(substr($student_name,0,1)); ?></div>
      <span class="user-name"><?php echo htmlspecialchars($student_name); ?></span>
      <a href="student_dashboard.php?logout=1" class="logout-link" title="Sign out"><i class="fas fa-sign-out-alt"></i></a>
    </div>
  </div>

  <!-- PAGE HEADER -->
  <div class="page-header">
    <div class="page-eyebrow">New Report</div>
    <h1 class="page-title">Submit an <em>equipment defect</em> report.</h1>
    <p class="page-sub">Fill in all required fields accurately. A ticket number will be emailed to you upon submission.</p>
  </div>

  <!-- PROGRESS STEPS -->
  <div class="steps">
    <div class="step done">
      <div class="step-dot"><i class="fas fa-check" style="font-size:.55rem"></i></div>
      <span>Your Info</span>
    </div>
    <div class="step-connector"></div>
    <div class="step active">
      <div class="step-dot">2</div>
      <span>Report Details</span>
    </div>
    <div class="step-connector"></div>
    <div class="step">
      <div class="step-dot">3</div>
      <span>Confirmation</span>
    </div>
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

  <nav class="fsteps" id="fsteps" aria-label="Report form progress"></nav>

  <form method="POST" enctype="multipart/form-data" id="report-form" novalidate>
    <?php echo csrf_field(); ?>

    <!-- ── SECTION 1: REPORTER INFO ── -->
    <div class="section-card">
      <div class="section-head">
        <div class="section-icon"><i class="fas fa-user"></i></div>
        <div>
          <div class="section-title">Reporter Information</div>
          <div class="section-sub"><?php echo $profileComplete ? 'Saved from your last report — check it is still correct' : 'Pre-filled from your session'; ?></div>
        </div>
      </div>

      <?php if ($profileComplete): ?>
      <!-- A returning reporter has already answered all of this. Showing it as
           one line to confirm — instead of three empty pickers to work through
           again — is the difference between a report that takes a moment and
           one that feels like paperwork. -->
      <div class="known" id="knownStrip">
        <div class="known-ic"><i class="fas fa-circle-check"></i></div>
        <div class="known-txt">
          <b>Reporting as <?php echo htmlspecialchars($student_name); ?></b>
          <span>
            <?php echo htmlspecialchars($preDept); ?><?php
              if ($preCourse !== '') echo ' · ' . htmlspecialchars($preCourse);
              if ($preLevel  !== '') echo ' · ' . htmlspecialchars($preLevel);
              if ($prePhone  !== '') echo ' · ' . htmlspecialchars($prePhone);
            ?>
          </span>
        </div>
        <button type="button" class="known-edit" id="knownEdit"
          aria-expanded="false" aria-controls="reporterFields">Change</button>
      </div>
      <?php endif; ?>
      <div class="reporter-grid">
        <div class="ro-field">
          <div class="ro-label">Full Name</div>
          <div class="ro-value"><?php echo htmlspecialchars($student_name); ?></div>
        </div>
        <div class="ro-field">
          <div class="ro-label">Email Address</div>
          <div class="ro-value"><?php echo htmlspecialchars($student_email); ?></div>
        </div>
      </div>
      <div id="reporterFields"<?php echo $profileComplete ? ' hidden' : ''; ?>>
      <div class="fg" style="margin-top:.85rem;">
        <label class="fl" for="rDept">Department / Academic Unit <span class="req">*</span></label>
        <div class="fi-wrap">
          <i class="fas fa-building-columns fi-icon"></i>
          <select name="reporter_department" id="rDept" class="fsel" required>
            <option value="" disabled <?php echo $preDept === '' ? 'selected' : ''; ?>>Select your department…</option>
            <?php
              // Labelled optgroups, so the native mobile picker is easy to scan.
              // Membership is stated outright rather than guessed from words in
              // the name: the old version tested for "school", "college",
              // "office" and so on, which quietly put anything renamed - or any
              // department added later - under "Other".
              $__deptGroups = ['Basic Education'=>[], 'Higher Education'=>[], 'Technical-Vocational'=>[], 'Administrative'=>[], 'Other'=>[]];
              $__groupOf = [
                  'Pre-School'                          => 'Basic Education',
                  'Grade School'                        => 'Basic Education',
                  'Junior High School'                  => 'Basic Education',
                  'Senior High School'                  => 'Basic Education',
                  'College of Teacher Education'        => 'Higher Education',
                  'College of Business'                 => 'Higher Education',
                  'College of Computer Studies'         => 'Higher Education',
                  'Technical-Vocational Center'         => 'Technical-Vocational',
                  'Administrative / Non-teaching Office' => 'Administrative',
              ];
              foreach (array_keys($becPrograms) as $__d) {
                  $__deptGroups[$__groupOf[$__d] ?? 'Other'][] = $__d;
              }
              $__depSel = $preDept;
              foreach ($__deptGroups as $__grp => $__depts):
                  if (!$__depts) continue; ?>
              <optgroup label="<?php echo htmlspecialchars($__grp); ?>">
                <?php foreach ($__depts as $__dept): ?>
                <option value="<?php echo htmlspecialchars($__dept, ENT_QUOTES); ?>"<?php echo ($__depSel === $__dept) ? ' selected' : ''; ?>><?php echo htmlspecialchars($__dept); ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="fg" style="margin-top:.85rem;">
        <label class="fl" for="rCourse">Course / Program <span class="opt" id="rCourseOpt">(select a department first)</span></label>
        <div class="fi-wrap">
          <i class="fas fa-graduation-cap fi-icon"></i>
          <select name="reporter_course" id="rCourse" class="fsel" disabled>
            <option value="">—</option>
          </select>
        </div>
      </div>
      <!-- Shown only for Senior High and the colleges, where the programme
           alone does not say which year group the reporter is in. -->
      <div class="fg" id="rLevelWrap" style="margin-top:.85rem;display:none;">
        <!-- "your level" assumed a student. Teachers are not in the directory
             and identify by the department and grade they teach, so the wording
             has to fit both without asking which they are. -->
        <label class="fl" for="rLevel">Year / Grade Level <span class="req">*</span></label>
        <div class="fi-wrap">
          <i class="fas fa-layer-group fi-icon"></i>
          <select name="reporter_level" id="rLevel" class="fsel">
            <option value="">Select the level you study or teach…</option>
          </select>
        </div>
      </div>
      <div style="margin-top:.85rem;">
        <label class="fl" for="rPhone">Contact Number <span class="opt">(optional)</span></label>
        <div class="fi-wrap">
          <i class="fas fa-phone fi-icon"></i>
          <input type="tel" name="student_phone" id="rPhone" class="fi" placeholder="e.g. 09171234567"
            value="<?php echo htmlspecialchars($prePhone); ?>"
            inputmode="numeric" autocomplete="tel" maxlength="11" pattern="\d{11}"
            title="Enter your 11-digit mobile number (numbers only), e.g. 09171234567">
        </div>
        <div class="fi-hint"><i class="fas fa-circle-info"></i> 11-digit mobile number, numbers only (e.g. 09171234567).</div>
      </div>
      </div><!-- /#reporterFields -->
    </div>

    <!-- ── SECTION 2: EQUIPMENT INFO ── -->
    <div class="section-card" id="equipSection">
      <?php if ($prefillEq): ?>
      <div class="qr-banner" style="display:flex;align-items:flex-start;gap:.7rem;margin-bottom:1rem;padding:.85rem 1rem;border-radius:12px;background:#FFFBEF;border:1px solid rgba(201,150,12,.35);border-left:4px solid #C9960C;">
        <i class="fas fa-qrcode" style="color:#C9960C;font-size:1.1rem;margin-top:.15rem;"></i>
        <div style="font-size:.86rem;line-height:1.55;color:#5C3838;">
          <strong style="color:#1C1008;">Scanned from an equipment QR code</strong><br>
          Reporting: <strong style="color:#7B1D1D;"><?php echo htmlspecialchars($prefillEq['name'] ?: $prefillEq['id']); ?></strong>
          <?php if ($prefillEq['asset_tag'] !== ''): ?> · Tag <?php echo htmlspecialchars($prefillEq['asset_tag']); ?><?php endif; ?>
          <?php if ($prefillEq['location'] !== ''): ?> · <?php echo htmlspecialchars($prefillEq['location']); ?><?php endif; ?>
          — just describe the issue below and submit.
        </div>
      </div>
      <?php endif; ?>
      <div class="section-head">
        <div class="section-icon"><i class="fas fa-desktop"></i></div>
        <div>
          <div class="section-title">Equipment Information</div>
          <div class="section-sub">Search and identify the defective equipment</div>
        </div>
      </div>

      <?php /* cols-2 is what the grid-column spans below were written for — without
               it .form-grid is a single column and those spans do nothing. Category
               and Asset Tag are the one genuine pair on the form; it collapses back
               to one column at 768px. */ ?>
      <div class="form-grid cols-2">
        <!-- Equipment search -->
        <div class="fg" style="grid-column:1/-1">
          <label class="fl" for="equip-search">Equipment Name <span class="req">*</span></label>
          <div class="equip-wrap">
            <div class="fi-wrap">
              <i class="fas fa-search fi-icon"></i>
              <input type="text" id="equip-search" name="equipment_name_display" class="fi" placeholder="Type to search equipment…"
                autocomplete="off" required
                value="<?php echo htmlspecialchars($_POST['equipment_name'] ?? ($prefillEq['name'] ?? '')); ?>">
            </div>
            <input type="hidden" name="equipment_id" id="equip-id-hidden" value="<?php echo htmlspecialchars($_POST['equipment_id'] ?? ($prefillEq['id'] ?? '')); ?>">
            <input type="hidden" name="equipment_name" id="equip-hidden" value="<?php echo htmlspecialchars($_POST['equipment_name'] ?? ($prefillEq['name'] ?? '')); ?>">
            <input type="hidden" name="category"       id="cat-hidden"   value="<?php echo htmlspecialchars($_POST['category'] ?? ($prefillEq['category'] ?? '')); ?>">
            <div class="equip-dropdown" id="equip-dropdown"></div>
          </div>
          <div class="fi-hint"><i class="fas fa-lightbulb"></i> Start typing to search, click the field to browse all, or enter a new equipment name manually.</div>
        </div>

        <!-- Category -->
        <div class="fg">
          <label class="fl" for="cat-display">Category <span class="req">*</span></label>
          <div class="fi-wrap">
            <i class="fas fa-tag fi-icon"></i>
            <select id="cat-display" class="fsel" required>
              <option value="">Select category</option>
              <?php
              foreach($category_options as $c): ?>
              <option value="<?php echo $c; ?>" <?php echo (($_POST['category']??'')===$c)?'selected':''; ?>><?php echo $c; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fi-hint"><i class="fas fa-tag"></i> If the item is not listed, choose the closest category.</div>
        </div>

        <!-- Asset Tag -->
        <div class="fg">
          <label class="fl" for="assetTag">Asset Tag / Equipment ID <span class="opt">(if visible)</span></label>
          <div class="fi-wrap">
            <i class="fas fa-barcode fi-icon"></i>
            <?php /* Carries the scanned unit's tag like every other field here.
                     It was the one that did not: the tag was read from the
                     record and printed in the banner above, then dropped before
                     the field, so someone who had just scanned the sticker on
                     the equipment was asked to type its number in by hand. */ ?>
            <input type="text" name="asset_tag" id="assetTag" class="fi" placeholder="e.g. BEC-LAB2-PC05" maxlength="40"
              value="<?php echo htmlspecialchars($_POST['asset_tag'] ?? ($prefillEq['asset_tag'] ?? '')); ?>">
          </div>
          <div class="fi-hint"><i class="fas fa-info-circle"></i> Auto-filled when you select an inventory item, or type it manually if visible.</div>
        </div>
      </div>
    </div>

    <!-- ── SECTION 3: LOCATION ── -->
    <div class="section-card">
      <div class="section-head">
        <div class="section-icon"><i class="fas fa-map-marker-alt"></i></div>
        <div>
          <div class="section-title">Location Details</div>
          <div class="section-sub">Where is the defective equipment located?</div>
        </div>
      </div>
      <div class="form-grid">
        <div class="fg">
          <label class="fl" for="location-search">Location <span class="req">*</span></label>
          <div class="equip-wrap">
            <div class="fi-wrap">
              <i class="fas fa-map-marker-alt fi-icon"></i>
              <input type="text" id="location-search" class="fi" placeholder="Type to search location…"
                autocomplete="off" required
                value="<?php echo htmlspecialchars($_POST['location'] ?? ($prefillEq['location'] ?? '')); ?>">
            </div>
            <input type="hidden" name="location" id="location-hidden" value="<?php echo htmlspecialchars($_POST['location'] ?? ($prefillEq['location'] ?? '')); ?>">
            <div class="search-dd" id="location-dropdown"></div>
          </div>
          <div class="fi-hint"><i class="fas fa-info-circle"></i> Choose an existing location or type the room/location manually.</div>
        </div>
      </div>
    </div>

    <!-- ── SECTION 4: PROBLEM INFO ── -->
    <div class="section-card">
      <div class="section-head">
        <div class="section-icon"><i class="fas fa-exclamation-triangle"></i></div>
        <div>
          <div class="section-title">Problem Details</div>
          <div class="section-sub">Describe the defect as clearly as possible</div>
        </div>
      </div>

      <?php /* Left as one column: pairing the date picker with anything here
               would leave a half-width field beside empty space. */ ?>
      <div class="form-grid">
        <!-- Defect description -->
        <div class="fg">
          <label class="fl" for="defectDesc">Description of Defect <span class="req">*</span></label>
          <div class="fi-wrap fi-wrap-ta">
            <i class="fas fa-pen fi-icon"></i>
            <textarea name="defect_description" id="defectDesc" class="fta" rows="4" maxlength="1500"
              placeholder="Describe what's wrong with the equipment. Include any error messages, sounds, or behaviors you observed…" required><?php echo htmlspecialchars($_POST['defect_description'] ?? ''); ?></textarea>
          </div>
          <div class="fi-hint"><i class="fas fa-pen"></i> Be as specific as possible — this helps technicians diagnose faster.</div>
        </div>

        <!-- Date/Time issue noticed -->
        <div class="fg">
          <label class="fl" for="issueDate">Date &amp; Time Issue Noticed <span class="req">*</span></label>
          <div class="fi-wrap">
            <i class="fas fa-calendar fi-icon"></i>
            <input type="datetime-local" name="issue_date" id="issueDate" class="fi" required
              value="<?php echo htmlspecialchars($_POST['issue_date'] ?? date('Y-m-d\TH:i')); ?>">
          </div>
        </div>

        <!-- Still usable -->
        <div class="fg">
          <?php /* A plain <label> here labelled nothing — the three radios were
                   announced without the question they answer. */ ?>
          <span class="fl" id="usableLbl">Is the equipment still usable? <span class="req">*</span></span>
          <div class="usable-group" role="radiogroup" aria-labelledby="usableLbl">
            <input type="radio" name="still_usable" id="use-yes" class="usable-opt" value="Yes" <?php echo (($_POST['still_usable']??'')==='Yes')?'checked':''; ?>>
            <label for="use-yes" class="usable-label yes"><i class="fas fa-check-circle"></i> Yes, still usable</label>

            <input type="radio" name="still_usable" id="use-part" class="usable-opt" value="Partially" <?php echo (($_POST['still_usable']??'')==='Partially')?'checked':''; ?>>
            <label for="use-part" class="usable-label part"><i class="fas fa-exclamation-circle"></i> Partially</label>

            <input type="radio" name="still_usable" id="use-no" class="usable-opt" value="No" <?php echo (($_POST['still_usable']??'')==='No')?'checked':''; ?>>
            <label for="use-no" class="usable-label no"><i class="fas fa-times-circle"></i> No, completely broken</label>
          </div>
        </div>
      </div>
    </div>

    <!-- ── SECTION 5: EVIDENCE ── -->
    <div class="section-card">
      <div class="section-head">
        <div class="section-icon"><i class="fas fa-camera"></i></div>
        <div>
          <div class="section-title">Photo &amp; Video Evidence</div>
          <div class="section-sub">Optional but strongly recommended</div>
        </div>
      </div>

      <?php /* One picker for both. Its accept list names images and videos, so
               the phone's own sheet offers the gallery, "Take Photo" and
               "Take Video" together - which is what a reporter wanted when they
               tapped "evidence" - instead of making them decide which of two
               boxes they needed before they had chosen anything.
               photos[] and videos[] are still submitted separately, filled from
               here, so nothing downstream changed. */ ?>
      <div class="photo-zone" id="media-zone">
        <input type="file" id="media-input" aria-label="Add photos or a video of the problem"
               accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime" multiple>
        <div class="photo-icon"><i class="fas fa-camera-retro"></i></div>
        <div class="photo-title">Add photos or a video — tap to choose, or drag them here</div>
        <div class="photo-sub">Photos: up to <strong>10</strong>, max 10MB each &middot; Video: up to <strong>2</strong>, max 20MB each</div>
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
// ── Reporter Information: dependent Department → Course dropdown (accurate BEC program list) ──
(function () {
  var PROGRAMS = <?php echo json_encode($becPrograms, JSON_UNESCAPED_UNICODE); ?>;
/* The saved profile is shown as one line to confirm. "Change" opens the real
   fields — which are always present and always submitted, so nothing here
   depends on JavaScript having run. */
(function(){
  var strip = document.getElementById('knownStrip'),
      btn   = document.getElementById('knownEdit'),
      wrap  = document.getElementById('reporterFields');
  if (!btn || !wrap) return;
  btn.addEventListener('click', function(){
    wrap.hidden = false;
    btn.setAttribute('aria-expanded','true');
    if (strip) strip.style.display = 'none';
    var d = document.getElementById('rDept');
    if (d) { try { d.focus({preventScroll:true}); } catch(e) { d.focus(); } }
  });
})();

  var dept = document.getElementById('rDept'),
      course = document.getElementById('rCourse'),
      optHint = document.getElementById('rCourseOpt');
  if (!dept || !course) return;
  var preset = <?php echo json_encode($preCourse, JSON_UNESCAPED_UNICODE); ?>;
  function fill() {
    var list = PROGRAMS[dept.value] || [];
    course.innerHTML = '';
    if (list.length) {
      var ph = document.createElement('option');
      ph.value = ''; ph.textContent = 'Select your course or program…'; ph.disabled = true; ph.selected = true;
      course.appendChild(ph);
      list.forEach(function (c) {
        var o = document.createElement('option');
        o.value = c; o.textContent = c;
        if (c === preset) { o.selected = true; ph.selected = false; }
        course.appendChild(o);
      });
      course.disabled = false; course.required = true;
      // Swap the class as well as the text: this asterisk means the same thing
      // as every other one on the form, so it has to be the same maroon rather
      // than the muted grey the "(optional)" wording uses.
      if (optHint) { optHint.className = 'req'; optHint.textContent = '*'; }
    } else {
      var o = document.createElement('option');
      o.value = ''; o.textContent = dept.value ? 'Not applicable for this department' : '—';
      course.appendChild(o);
      course.disabled = true; course.required = false;
      if (optHint) { optHint.className = 'opt'; optHint.textContent = dept.value ? '(not applicable)' : '(select a department first)'; }
    }
  }
  // Year / grade level, for the departments where the programme alone does not
  // say it. Same source of truth as the server check ($becLevels).
  var LEVELS = <?php echo json_encode($becLevels, JSON_UNESCAPED_UNICODE); ?>;
  var level = document.getElementById('rLevel'),
      levelWrap = document.getElementById('rLevelWrap');
  var levelPreset = <?php echo json_encode($preLevel, JSON_UNESCAPED_UNICODE); ?>;

  function fillLevels() {
    if (!level || !levelWrap) return;
    var list = LEVELS[dept.value] || [];
    level.innerHTML = '';
    if (!list.length) {
      // Hidden and un-required, or the browser would block submit on a field
      // nobody can see.
      levelWrap.style.display = 'none';
      level.required = false;
      return;
    }
    var ph = document.createElement('option');
    // Must match the server-rendered placeholder: this rebuild replaces it the
    // moment a department is chosen, so wording fixed only in the HTML above
    // would show for a fraction of a second and then revert.
    ph.value = ''; ph.textContent = 'Select the level you study or teach…'; ph.disabled = true; ph.selected = true;
    level.appendChild(ph);
    list.forEach(function (v) {
      var o = document.createElement('option');
      o.value = v; o.textContent = v;
      if (v === levelPreset) { o.selected = true; ph.selected = false; }
      level.appendChild(o);
    });
    levelWrap.style.display = '';
    level.required = true;
  }

  dept.addEventListener('change', function () { preset = ''; levelPreset = ''; fill(); fillLevels(); });
  fill(); fillLevels(); // initial render (restores selection after a validation error too)
})();

// ── Contact number: allow digits only, cap at 11 (PH mobile format) ──
(function () {
  var ph = document.getElementById('rPhone');
  if (!ph) return;
  ph.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 11); });
})();

// ── Equipment search / autocomplete ──────────────────────────────────────
const equipData = <?php echo json_encode($equipment_list); ?>;

const searchEl    = document.getElementById('equip-search');
const equipIdEl   = document.getElementById('equip-id-hidden');
const hiddenEl    = document.getElementById('equip-hidden');
const catHidden   = document.getElementById('cat-hidden');
const catDisplay  = document.getElementById('cat-display');
const assetTagEl  = document.querySelector('input[name="asset_tag"]');
const locationSearchEl = document.getElementById('location-search');
const locationHiddenEl = document.getElementById('location-hidden');
const locationData  = <?php echo json_encode(array_values($location_options)); ?>;
// Same list, pre-split into campus / building / room so the picker can show the
// room prominently instead of one long truncated string.
const locationParts = <?php echo json_encode(array_values($locationParts)); ?>;
const dropdown    = document.getElementById('equip-dropdown');
const locationDropdown = document.getElementById('location-dropdown');
const reportForm = document.getElementById('report-form');
const loadingOverlay = document.getElementById('loading-overlay');
const submitBtn = reportForm?.querySelector('.btn-submit');

let focusIdx = -1;
let locationFocusIdx = -1;

// Stable position of every row in its source array, stamped once. Looking the
// index up per rendered row (indexOf) is O(n^2), which with 1,300 inventory
// items meant ~1.7M string comparisons on every keystroke.
equipData.forEach((item, i) => { item._i = i; });

// How many suggestions to put in the DOM at once. The full inventory is 1,300
// rows; building all of them on focus janks the field on a phone, and nobody
// scrolls that far — they type instead.
const COMBO_LIMIT = 50;

// Locations are short, grouped rows and there are only ~300 of them, so the
// whole campus fits comfortably; capping these at 50 like the equipment list cut
// the list off inside the first campus.
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

function groupBy(arr, key) {
  return arr.reduce((acc, item) => {
    (acc[item[key]] = acc[item[key]] || []).push(item);
    return acc;
  }, {});
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

// One lowercased haystack per item, built once rather than on every keystroke:
// there are over a thousand records and this runs while someone is typing.
const equipHaystack = equipData.map(e =>
  [e.name, e.id, e.category, e.asset_tag, e.location]
    .filter(Boolean).join(' ').toLowerCase()
);

function renderDropdown(query) {
  const q = query.trim().toLowerCase();
  // Each word must appear somewhere across all the fields, not all of them in
  // one field. Matching per-field meant "aircon computer lab" found nothing -
  // "aircon" is the name and "computer lab" is the location, and no single
  // field held both - even though naming a thing and where it is, is exactly
  // how someone describes the equipment in front of them.
  const terms = q ? q.split(/\s+/).filter(Boolean) : [];
  const matches = terms.length
    ? equipData.filter((e, i) => terms.every(t => equipHaystack[i].includes(t)))
    : equipData.slice();

  if (!matches.length) {
    dropdown.innerHTML = `
      <div class="eq-empty"><i class="fas fa-search" style="margin-right:.3rem;opacity:.5"></i>No equipment found</div>
      <div class="eq-manual"><strong>Manual entry:</strong> keep the name you typed, choose a category and location, then submit.</div>
    `;
    dropdown.classList.add('open');
    return;
  }

  // The PMO's inventory comes first and on its own. What follows it are entries
  // that exist only because someone typed them into a past report - useful to
  // reuse, but they are not inventory and should not look like it.
  let html = '';
  let hidden = 0;
  const sections = [
    { key: 'inventory', label: 'From the PMO inventory' },
    { key: 'reported',  label: 'Previously reported (not in the inventory yet)' },
  ];
  for (const section of sections) {
    const all = matches.filter(e => (e.source || 'inventory') === section.key);
    // Capped per section, not across the whole list: the inventory alone is
    // 1,300 rows and would otherwise bury the "previously reported" group.
    const rows = all.slice(0, COMBO_LIMIT);
    hidden += all.length - rows.length;
    if (!rows.length) continue;
    html += `<div class="eq-source-label">${escapeHtml(section.label)}</div>`;
    const grouped = groupBy(rows, 'category');
    for (const [cat, items] of Object.entries(grouped)) {
      html += `<div class="eq-group-label">${escapeHtml(cat)}</div>`;
      items.forEach((item) => {
        const locLine = item.location ? `<span class="eq-loc">${escapeHtml(item.location)}</span>` : '';
        html += `<div class="eq-item${section.key === 'reported' ? ' eq-item-reported' : ''}" data-index="${item._i}">
          <span class="eq-id">${escapeHtml(item.id)}</span>
          <span class="eq-body"><span class="eq-name">${escapeHtml(item.name)}</span>${locLine}</span>
        </div>`;
      });
    }
  }
  if (hidden > 0) {
    html += `<div class="eq-manual"><strong>${hidden.toLocaleString()} more match${hidden === 1 ? '' : 'es'}.</strong> Type the property number or room to narrow it down.</div>`;
  }
  if (q) {
    html += '<div class="eq-manual"><strong>Not in the list?</strong> Keep typing the new equipment name and submit it manually.</div>';
  }
  dropdown.innerHTML = html;
  dropdown.classList.add('open');
  focusIdx = -1;
}

/* byTap: chosen with a finger or mouse rather than the keyboard. The field is
   then blurred, which shuts the on-screen keyboard and — the point of it —
   stops the focus handler below re-opening the list the instant it closes.
   Keyboard selection keeps focus, so arrow-key users are not thrown out. */
function selectEquip(data, byTap) {
  const category = data.cat || data.category || '';
  const assetTag = data.assetTag || data.asset_tag || '';
  searchEl.value   = data.name || '';
  equipIdEl.value  = data.id || '';
  hiddenEl.value   = data.name || '';
  catHidden.value  = category;
  assetTagEl.value = assetTag;
  if (data.location) {
    locationSearchEl.value = data.location;
    locationHiddenEl.value = data.location;
  }
  for (const opt of catDisplay.options) {
    opt.selected = opt.value === category;
  }
  dropdown.classList.remove('open');
  focusIdx = -1;
  if (byTap) searchEl.blur();
}

catDisplay.addEventListener('change', () => {
  catHidden.value = catDisplay.value;
});

searchEl.addEventListener('input', () => {
  equipIdEl.value = '';
  hiddenEl.value = searchEl.value.trim();
  if (!searchEl.value.trim()) {
    assetTagEl.value = '';
  }
  renderDropdown(searchEl.value);
});
searchEl.addEventListener('focus', () => renderDropdown(searchEl.value));
wireComboDismiss(searchEl, dropdown);

// One delegated listener instead of one per row — the rows are rebuilt on every
// keystroke, so per-row listeners were being created and thrown away in bulk.
/* Selection happens on click, never on pointerdown. preventDefault() during
   pointerdown cancels the browser's own scroll gesture, so the list could not
   be scrolled with a finger at all - it only closed on whatever was under the
   first touch. A click arrives after a genuine tap and never during a drag,
   which is exactly the distinction needed here.
   The mousedown below is desktop-only housekeeping: it stops the press moving
   focus out of the input, and does not affect touch scrolling. */
dropdown.addEventListener('mousedown', e => { if (e.target.closest('.eq-item')) e.preventDefault(); });
dropdown.addEventListener('click', e => {
  const el = e.target.closest('.eq-item');
  if (!el) return;
  selectEquip(equipData[Number(el.dataset.index)] || {}, true);
});

searchEl.addEventListener('keydown', e => {
  const items = dropdown.querySelectorAll('.eq-item');
  if (!items.length) return;
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    focusIdx = Math.min(focusIdx + 1, items.length - 1);
    items.forEach((el,i) => el.classList.toggle('focused', i === focusIdx));
    items[focusIdx]?.scrollIntoView({block:'nearest'});
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    focusIdx = Math.max(focusIdx - 1, 0);
    items.forEach((el,i) => el.classList.toggle('focused', i === focusIdx));
    items[focusIdx]?.scrollIntoView({block:'nearest'});
  } else if (e.key === 'Enter' && focusIdx >= 0) {
    e.preventDefault();
    const el = items[focusIdx];
    selectEquip(equipData[Number(el.dataset.index)] || {});
  } else if (e.key === 'Escape') {
    dropdown.classList.remove('open');
  }
});

if (equipIdEl.value) {
  const currentEquipment = equipData.find(item => item.id === equipIdEl.value);
  if (currentEquipment) {
    selectEquip({
      id: currentEquipment.id,
      name: currentEquipment.name,
      cat: currentEquipment.category,
      assetTag: currentEquipment.asset_tag,
      location: currentEquipment.location
    });
  }
} else if (searchEl.value.trim()) {
  hiddenEl.value = searchEl.value.trim();
}

if (catHidden.value) {
  for (const opt of catDisplay.options) {
    opt.selected = opt.value === catHidden.value;
  }
}

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
}

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

/* ── Form progress stepper: built from the section cards, scrollspy-highlighted ── */
(function () {
  const bar = document.getElementById('fsteps');
  const form = document.getElementById('report-form');
  if (!bar || !form) return;
  const sections = Array.from(form.querySelectorAll('.section-card')).filter(s => s.querySelector('.section-title'));
  if (sections.length < 2) { bar.remove(); return; }
  const shortNames = { 'Reporter Information': 'Reporter', 'Equipment Information': 'Equipment', 'Location Details': 'Location', 'Problem Details': 'Problem', 'Photo Evidence': 'Photos' };
  const chips = sections.map((sec, i) => {
    sec.id = sec.id || ('fsec-' + (i + 1));
    const title = sec.querySelector('.section-title').textContent.trim();
    const chip = document.createElement('button');
    chip.type = 'button';
    chip.className = 'fstep';
    chip.innerHTML = '<span class="fs-n">' + (i + 1) + '</span><span class="fs-lbl">' + (shortNames[title] || title) + '</span>';
    chip.addEventListener('click', () => goTo(i, true));
    bar.appendChild(chip);
    return chip;
  });

  function markActive(idx) {
    chips.forEach((c, i) => {
      c.classList.toggle('on', i === idx);
      c.classList.toggle('done', i < idx);
      if (i === idx) c.querySelector('.fs-n').textContent = i + 1;
      else c.querySelector('.fs-n').innerHTML = i < idx ? '<i class="fas fa-check" style="font-size:.56rem"></i>' : (i + 1);
    });
  }

  /* Everything above is the old scrollspy stepper. From here it drives one
     section at a time instead, because reporters asked to finish a part before
     the next appears rather than scroll past all five. The form, its single
     submit and the server-side validation are untouched: this only decides
     which section is on screen. */
  const submitRow = form.querySelector('.submit-row');
  const dupBox    = form.querySelector('.dup-override');
  // A duplicate notice sends the form back with everything still filled in and
  // a tickbox at the end that has to be agreed to. Starting at step 1 would
  // make the reporter walk all five steps again to reach it, so open on the
  // step that holds the notice and the submit button.
  let current = dupBox ? Math.max(0, sections.length - 1) : 0;
  // Everything up to here has already been filled in and accepted once, so the
  // earlier steps are reachable from the chips rather than locked behind Next.
  let furthest = current;

  const nav = document.createElement('div');
  nav.className = 'wz-nav';
  nav.innerHTML =
    '<button type="button" class="wz-back"><i class="fas fa-arrow-left" style="font-size:.75rem"></i> Back</button>' +
    '<span class="wz-count"></span>' +
    '<button type="button" class="wz-next">Next <span class="btn-arrow"><i class="fas fa-arrow-right"></i></span></button>';
  submitRow.parentNode.insertBefore(nav, submitRow);
  const backBtn  = nav.querySelector('.wz-back');
  const nextBtn  = nav.querySelector('.wz-next');
  const countEl  = nav.querySelector('.wz-count');

  function render() {
    sections.forEach((s, i) => s.classList.toggle('wz-on', i === current));
    const last = current === sections.length - 1;
    nav.style.display        = last ? 'none' : 'flex';
    submitRow.style.display  = last ? '' : 'none';
    // 'flex', not '': the duplicate notice carries display:flex in an inline
    // style attribute and has no stylesheet rule to fall back on, so clearing
    // the property dropped it to display:inline and the checkbox and its text
    // ran together. It is required, so a notice nobody could read was also a
    // submit nobody could complete.
    if (dupBox) dupBox.style.display = last ? 'flex' : 'none';
    backBtn.style.visibility = current === 0 ? 'hidden' : 'visible';
    countEl.textContent      = 'Step ' + (current + 1) + ' of ' + sections.length;
    markActive(current);
  }

  function goTo(i, viaChip) {
    if (i < 0 || i > sections.length - 1) return;
    // Forward only once the section you are leaving is complete; back is always
    // free, and a chip may only reach a step already visited.
    if (i > current) {
      if (viaChip && i > furthest) return;
      for (let s = current; s < i; s++) {
        if (!rfValidate(sections[s], true)) { current = s; render(); scrollToForm(); return; }
      }
    }
    current = i;
    furthest = Math.max(furthest, current);
    render();
    scrollToForm();
  }

  function scrollToForm() {
    const top = bar.getBoundingClientRect().top;
    // Nothing to do if the top of the form is already on screen. Scrolling on
    // every Next and Back - including when the page had not moved at all -
    // made the whole thing lurch up and down between steps.
    if (top >= -4 && top <= window.innerHeight * 0.5) return;
    const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({
      top: Math.max(0, top + window.scrollY - 12),
      behavior: reduce ? 'auto' : 'smooth'
    });
  }

  nextBtn.addEventListener('click', () => goTo(current + 1));
  backBtn.addEventListener('click', () => goTo(current - 1));

  /* Submit validates the whole form, so a missing field may sit on a step that
     is off screen. This lets the validator bring it into view before it
     complains about it. */
  window.wzRevealField = function (el) {
    const sec = el && el.closest ? el.closest('.section-card') : null;
    if (!sec) return;
    const i = sections.indexOf(sec);
    if (i >= 0 && i !== current) { current = i; furthest = Math.max(furthest, i); render(); }
  };

  // render() first, then hide: if anything in here threw, the form would be
  // left with .wz applied and no section shown at all. This way a failure
  // leaves every section visible - the page as it was before any of this.
  render();
  form.classList.add('wz');
})();

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
    // Whatever is wrong may be on a step that is not on screen, so move there
    // first - otherwise the popup names a field the reporter cannot see.
    if (window.wzRevealField) window.wzRevealField(bad[0]);
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

reportForm?.addEventListener('submit', (e) => {
  hiddenEl.value = searchEl.value.trim();
  locationHiddenEl.value = locationSearchEl.value.trim();
  catHidden.value = catDisplay.value;

  if (!rfValidate()) {
    e.preventDefault();
    loadingOverlay?.classList.remove('show');
    return;
  }

  loadingOverlay?.classList.add('show');
  loadingOverlay?.setAttribute('aria-hidden', 'false');
  if (submitBtn) {
    submitBtn.classList.add('is-loading');
    submitBtn.innerHTML = 'Submitting <span class="btn-arrow"><i class="fas fa-spinner"></i></span>';
  }
});
</script>
<script>
// ── Sticky stepper offset + section scroll-spy ───────────────────────────
(function () {
  const topbar = document.querySelector('.topbar');
  const steps  = document.querySelector('.steps');
  // Keep the sticky stepper pinned directly under the sticky topbar.
  function syncOffsets() {
    if (topbar && steps) { steps.style.top = topbar.offsetHeight + 'px'; }
  }
  syncOffsets();
  window.addEventListener('resize', syncOffsets);
  window.addEventListener('load', syncOffsets);

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
/* Arrived via equipment QR scan: bring the pre-filled report form into view
   and drop the cursor straight into the issue description. */
window.addEventListener('load', function () {
  var sec = document.getElementById('equipSection');
  if (sec) setTimeout(function () { sec.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 350);
  var desc = document.querySelector('textarea[name="defect_description"]');
  if (desc) setTimeout(function () { desc.focus({ preventScroll: true }); }, 900);
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
