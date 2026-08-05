<?php
/**
 * seed_demo_reports.php - replace the report table with a realistic set for a demo.
 *
 * The reports accumulated during development are thin: most carry no reporter
 * name, none carry a department or course, and the descriptions are test
 * strings. On screen and in the printed Equipment Defect Report they show rows
 * of dashes, which is the wrong thing to put in front of a panel.
 *
 * This clears them and writes a set that covers the whole workflow - newly
 * reported, under PMO review, assigned, accepted, in progress, completed,
 * verified and closed - with real BEC departments, courses carrying their year
 * level, sensible equipment and locations, and full technician write-ups on the
 * finished ones so the Service Report prints complete.
 *
 *   php scripts/seed_demo_reports.php           # show what it would do
 *   php scripts/seed_demo_reports.php --apply   # replace them
 *
 * Every existing report is written to backups/ as JSON first. Nothing here
 * touches equipment, users or the directory.
 */
require_once __DIR__ . '/../config/database.php';

$apply = in_array('--apply', $argv, true);
$pdo = getPgsqlPdoConnection();

$existing = $pdo->query("SELECT * FROM public.defect_reports ORDER BY report_date")->fetchAll(PDO::FETCH_ASSOC);
printf("  reports currently in the table: %d\n", count($existing));

$equipment = $pdo->query("SELECT equipment_id, equipment_name, COALESCE(location,'') AS location
                          FROM public.equipment WHERE COALESCE(status,'') <> 'deleted'
                          ORDER BY equipment_name")->fetchAll(PDO::FETCH_ASSOC);
$byName = [];
foreach ($equipment as $e) { $byName[strtolower($e['equipment_name'])] = $e; }

$techs = $pdo->query("SELECT user_id, fullname FROM public.users
                      WHERE role = 'technician' AND status = 'active' ORDER BY user_id")->fetchAll(PDO::FETCH_ASSOC);
if (!$equipment || !$techs) {
    echo "  Need at least one equipment row and one active technician. Nothing done.\n";
    exit(1);
}

/** Pick equipment by name, falling back to the first row so this still runs on a fresh inventory. */
$eq = function (string $name) use ($byName, $equipment) {
    return $byName[strtolower($name)] ?? $equipment[0];
};
$tech = function (int $i) use ($techs) { return $techs[$i % count($techs)]; };
$daysAgo = function (int $d, string $time = '09:15') {
    return date('Y-m-d H:i:s', strtotime("-{$d} days {$time}"));
};

// Reporters drawn from across the college, each with the course and year level
// the form now asks for.
$people = [
    ['Maria Clara Santos',   'maria.santos@bec.edu.ph',    'College of Computer Studies',  'Bachelor of Science in Information Systems - 3rd Year'],
    ['Andres Bonifacio Jr.', 'andres.bonifacio@bec.edu.ph','Senior High School',           'STEM - Science, Technology, Engineering and Mathematics - Grade 12'],
    ['Josefa Reyes',         'josefa.reyes@bec.edu.ph',    'College of Teacher Education', 'Bachelor of Elementary Education - 2nd Year'],
    ['Ramon Delos Reyes',    'ramon.delosreyes@bec.edu.ph','Administrative / Non-teaching Office', "Registrar's Office"],
    ['Liwayway Mercado',     'liwayway.mercado@bec.edu.ph','Junior High School',           'Grade 9'],
    ['Teodoro Alvarez',      'teodoro.alvarez@bec.edu.ph', 'College of Business',          'Bachelor of Science in Accountancy - 4th Year'],
    ['Corazon Villanueva',   'corazon.villanueva@bec.edu.ph','Technical-Vocational Center','Computer Systems Servicing NC II'],
    ['Emilio Aguinaldo',     'emilio.aguinaldo@bec.edu.ph','Grade School',                 'Grade 5'],
];

$reports = [
    // --- freshly reported, waiting for the PMO to pick them up ---
    [
        'equipment' => 'Air Conditioner', 'person' => 0, 'status' => 'reported',
        'priority' => 'high', 'usable' => 'No', 'days' => 1,
        'category' => 'Air Conditioning Unit',
        'issue' => 'The aircon in the computer laboratory stopped cooling yesterday afternoon and now blows only warm air. There is also a rattling sound from the outdoor unit. The room becomes very uncomfortable during the 1:00 PM class.',
    ],
    [
        'equipment' => 'Chair', 'person' => 4, 'status' => 'reported',
        'priority' => 'medium', 'usable' => 'Partially', 'days' => 2,
        'category' => 'Furniture',
        'issue' => 'One of the armchairs in Grade 9-Sampaguita has a cracked backrest and the left armrest is loose. A student almost fell backwards when leaning on it.',
    ],
    // --- received, PMO deciding ---
    [
        'equipment' => 'Keyboard', 'person' => 6, 'status' => 'pmo_review',
        'priority' => 'low', 'usable' => 'Partially', 'days' => 4,
        'category' => 'Computer Peripheral',
        'issue' => 'Several keys on the keyboard at workstation 12 do not register unless pressed very hard - specifically the E, R and spacebar. It slows down the hands-on assessment.',
        'pmo_notes' => 'Received. Checking whether a spare keyboard is available from stock before raising a work order.',
    ],
    // --- assigned to a technician, not yet accepted ---
    [
        'equipment' => 'television', 'person' => 2, 'status' => 'assigned',
        'priority' => 'medium', 'usable' => 'No', 'days' => 5,
        'category' => 'Audio Visual Equipment',
        'issue' => 'The smart TV used for demonstration teaching will not turn on. The standby light blinks red three times and stops. It was working last Friday.',
        'tech' => 0, 'instructions' => 'Check the power board and standby capacitor first. Bring the spare power cable.',
    ],
    // --- accepted, technician on the way ---
    [
        'equipment' => 'Fan', 'person' => 7, 'status' => 'accepted',
        'priority' => 'medium', 'usable' => 'Partially', 'days' => 6,
        'category' => 'Electric Fan',
        'issue' => 'The ceiling fan in Grade 5-Mabini wobbles noticeably at high speed and makes a clicking noise. The pupils are seated directly underneath it.',
        'tech' => 1, 'instructions' => 'Safety first - check the mounting bracket and blade balance before anything else.',
    ],
    // --- work under way ---
    [
        'equipment' => 'Laptop', 'person' => 5, 'status' => 'in_progress',
        'priority' => 'high', 'usable' => 'No', 'days' => 7,
        'category' => 'Computer',
        'issue' => 'The laptop assigned to the Accountancy review room shuts down on its own after about ten minutes of use, and the fan is very loud before it does. It cannot get through a full session.',
        'tech' => 2, 'instructions' => 'Suspect thermal - clean the heatsink and repaste, then run a stress test.',
        'notes' => 'Opened the chassis, heatsink was heavily clogged with dust. Cleaning and re-pasting now.',
    ],
    // --- completed, waiting for PMO verification ---
    [
        'equipment' => 'Board', 'person' => 1, 'status' => 'completed',
        'priority' => 'critical', 'usable' => 'No', 'days' => 10,
        'category' => 'Whiteboard / Glassboard',
        'issue' => 'The mounted whiteboard came loose from its wall bracket and shifted downward on the left side. It is still hanging but clearly unstable, and the room is used by a full class, so there is a risk of it falling on someone.',
        'tech' => 3, 'instructions' => 'Treat as urgent - a falling board is a safety incident. Re-anchor properly.',
        'diagnosis' => 'Two of the four wall anchors had pulled out of the hollow block. The remaining anchors were carrying the whole load, which is why the left side dropped.',
        'actions' => 'Removed the board, filled and re-drilled the failed anchor points, fitted four new expansion anchors rated well above the board weight, remounted and checked level.',
        'work' => 'Whiteboard re-anchored to the wall with new expansion bolts and confirmed secure.',
        'parts' => '4 x 10mm expansion anchors, wall filler',
        'tools' => 'Hammer drill, spirit level, socket set',
        'duration' => '1h 40m', 'cost' => 350.00,
    ],
    // --- verified by the PMO ---
    [
        'equipment' => 'Air Conditioner', 'person' => 3, 'status' => 'verified',
        'priority' => 'high', 'usable' => 'Partially', 'days' => 14,
        'category' => 'Air Conditioning Unit',
        'issue' => "The aircon in the Registrar's Office drips water onto the filing cabinet below it. We have put a basin underneath but the records are at risk.",
        'tech' => 0, 'instructions' => 'Check the drain line and pan before assuming a refrigerant problem.',
        'diagnosis' => 'The condensate drain line was blocked with algae and sludge, so the pan overflowed at the front edge instead of draining.',
        'actions' => 'Flushed the drain line, cleaned the drain pan, treated the line to slow regrowth and confirmed free flow for fifteen minutes of operation.',
        'work' => 'Condensate drain cleared and tested - no further dripping.',
        'parts' => 'Drain line cleaner',
        'tools' => 'Wet vacuum, flexible brush',
        'duration' => '55m', 'cost' => 180.00,
        'verification' => 'Checked on site the following morning. No water in the pan or on the cabinet. Records unaffected.',
    ],
    // --- closed after the reporter confirmed ---
    [
        'equipment' => 'Keyboard', 'person' => 0, 'status' => 'closed',
        'priority' => 'low', 'usable' => 'No', 'days' => 21,
        'category' => 'Computer Peripheral',
        'issue' => 'Coffee was spilled on the keyboard at the front desk of the computer laboratory. It now types repeated characters on its own and cannot be used.',
        'tech' => 1, 'instructions' => 'Liquid damage - replace rather than repair if the membrane is affected.',
        'diagnosis' => 'Liquid had reached the membrane layers and corroded several traces. Cleaning would not restore reliable contact.',
        'actions' => 'Replaced the unit with a spare from stock, tested every key, and disposed of the damaged keyboard following property procedure.',
        'work' => 'Keyboard replaced from stock and tested.',
        'parts' => '1 x USB keyboard (from stock)',
        'tools' => 'Multimeter',
        'duration' => '25m', 'cost' => 0.00,
        'verification' => 'Replacement confirmed working. Property record updated.',
        'satisfaction' => 'satisfied',
        'satisfaction_note' => 'Working perfectly now, thank you for the quick replacement.',
    ],
];

printf("  reports that would be written : %d\n", count($reports));
$statuses = array_count_values(array_column($reports, 'status'));
ksort($statuses);
echo "  covering: ";
foreach ($statuses as $s => $n) { echo "$s($n) "; }
echo "\n";

if (!$apply) {
    echo "\n  dry run - re-run with --apply to replace the reports\n";
    exit(0);
}

// ---- back up first -------------------------------------------------------
$dir = __DIR__ . '/../backups';
if (!is_dir($dir)) { mkdir($dir, 0775, true); }
$snapshot = $dir . '/defect_reports_before_seed_' . date('Ymd_His') . '.json';
file_put_contents($snapshot, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
printf("\n  snapshot: %s (%d row(s))\n", basename($snapshot), count($existing));

$pdo->beginTransaction();
try {
    $ids = array_column($existing, 'report_id');
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (['notifications' => 'related_id', 'maintenance_history' => 'report_id', 'work_orders' => 'report_id'] as $tbl => $col) {
            try { $pdo->prepare("DELETE FROM public.$tbl WHERE $col IN ($in)")->execute($ids); }
            catch (\Throwable $e) { /* table may not exist on every deployment */ }
        }
        $pdo->prepare("DELETE FROM public.defect_reports WHERE report_id IN ($in)")->execute($ids);
    }

    $seq = 1;
    $written = 0;
    foreach ($reports as $r) {
        $e = $eq($r['equipment']);
        $p = $people[$r['person']];
        $reported = $daysAgo($r['days']);
        $id = sprintf('BEC-%s-%06d', date('Y'), 100 + $seq++);

        $row = [
            'report_id' => $id,
            'equipment_id' => $e['equipment_id'],
            'equipment_name' => $e['equipment_name'],
            'category' => $r['category'],
            'location' => $e['location'],
            'issue_description' => $r['issue'],
            'defect_description' => $r['issue'],
            'priority' => $r['priority'],
            'status' => $r['status'],
            'usable_status' => $r['usable'],
            'report_date' => $reported,
            'reporter_name' => $p[0],
            'reporter_email' => $p[1],
            'reporter_department' => $p[2],
            'reporter_course' => $p[3],
            'department_assigned' => 'PMO',
        ];

        // Everything past "reported" has been seen by the PMO.
        if ($r['status'] !== 'reported') {
            $row['received_by_pmo_at'] = $daysAgo($r['days'] - 1, '08:40');
            $row['pmo_review_status'] = 'approved';
            if (!empty($r['pmo_notes'])) { $row['pmo_notes'] = $r['pmo_notes']; }
        }
        if (isset($r['tech'])) {
            $t = $tech($r['tech']);
            $row['assigned_to'] = $t['user_id'];
            $row['assigned_technician'] = $t['fullname'];
            $row['assigned_date'] = $daysAgo($r['days'] - 1, '10:20');
            $row['handler_instructions'] = $r['instructions'] ?? null;
        }
        if (in_array($r['status'], ['accepted', 'in_progress', 'completed', 'verified', 'closed'], true)) {
            $row['accepted_at'] = $daysAgo($r['days'] - 1, '13:05');
        }
        if (in_array($r['status'], ['in_progress', 'completed', 'verified', 'closed'], true)) {
            $row['started_at'] = $daysAgo($r['days'] - 2, '08:30');
            $row['date_started'] = $row['started_at'];
        }
        if (!empty($r['notes'])) { $row['technician_notes'] = $r['notes']; }
        if (in_array($r['status'], ['completed', 'verified', 'closed'], true)) {
            $row['completion_date'] = $daysAgo($r['days'] - 3, '15:45');
            $row['diagnosis'] = $r['diagnosis'];
            $row['actions_performed'] = $r['actions'];
            $row['work_performed'] = $r['work'];
            $row['parts_replaced'] = $r['parts'];
            $row['tools_used'] = $r['tools'];
            $row['repair_duration'] = $r['duration'];
            $row['repair_cost'] = $r['cost'];
            $row['technician_notes'] = $r['work'];
        }
        if (in_array($r['status'], ['verified', 'closed'], true)) {
            $row['verification_notes'] = $r['verification'];
        }
        if (!empty($r['satisfaction'])) {
            $row['satisfaction'] = $r['satisfaction'];
            $row['satisfaction_at'] = $daysAgo($r['days'] - 4, '09:10');
            $row['satisfaction_note'] = $r['satisfaction_note'];
        }

        $cols = array_keys($row);
        $sql = 'INSERT INTO public.defect_reports (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')';
        $pdo->prepare($sql)->execute($row);
        $written++;
        printf("    %-20s %-12s %-22s %s\n", $id, $r['status'], substr($p[0], 0, 20), substr($e['equipment_name'], 0, 18));
    }

    $pdo->commit();
    printf("\n  replaced %d old report(s) with %d new one(s)\n", count($existing), $written);
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "\n  FAILED, rolled back: " . $e->getMessage() . "\n";
    echo "  Nothing was changed. The snapshot is still at " . basename($snapshot) . "\n";
    exit(1);
}
