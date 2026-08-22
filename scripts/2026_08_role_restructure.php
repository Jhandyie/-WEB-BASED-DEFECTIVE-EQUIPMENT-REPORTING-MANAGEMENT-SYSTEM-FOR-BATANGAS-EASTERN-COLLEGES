<?php
/**
 * Role restructure, August 2026.
 *
 * The scenario the system is demoed under changed: each unit's technician is also
 * that unit's administrator, so the standalone technician accounts are no longer
 * technicians. This script makes the database match.
 *
 *   1. Two in-flight reports are moved off the accounts about to lose the portal.
 *      They are PMO equipment (a fan and an air conditioner), so they go to the
 *      Maintenance technician rather than to ITSO. A report sitting at 'accepted'
 *      drops back to 'assigned': the previous technician had taken it on, the new
 *      one has not.
 *   2. Jaymiel Colego, Shane Sumage and Michelle Dino become reporters. They keep
 *      full reporter access either way, since the reporter portal identifies people
 *      by BEC email and never reads users.role.
 *   3. Juan P. Bejuna is created as PMO's admin-and-technician, the same shape the
 *      ITSO three already have: role 'admin' in users, plus a maintenance_technicians
 *      row that makes him assignable and lets him sign in to the technician portal.
 *      See findTechnicianPortalUser() in config/database.php.
 *   4. Mark Matibag is created as an administrator for external testing. His
 *      department is deliberately neither PMO nor ITSO so adminUnitForUser() returns
 *      '' and he sees every report. config/demo_access.php lets him skip the OTP.
 *
 * Passwords are generated here and printed once. Nothing else prints them.
 *
 * Safe to re-run: the account step checks for an existing address first.
 */

require_once __DIR__ . '/../config/database.php';

$pdo = getPgsqlPdoConnection();

$REASSIGN_TO = 'TECH-001';   // Juan Dela Cruz, Maintenance Department
$REPORTS     = ['BEC-2026-000105', 'BEC-2026-000148'];
$DEMOTE      = [
    'TECH-6E904014' => 'Jaymiel Colego',
    'TECH-8902AF22' => 'Shane Sumage',
    'TECH-CDC94F6D' => 'Michelle A. Dino',
];

$reportList = "'" . implode("','", $REPORTS) . "'";
$demoteList = "'" . implode("','", array_keys($DEMOTE)) . "'";

/** A password a person can still read aloud, but not guess. */
function makePassword(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $symbols  = '!@#$%&*?';
    $out = '';
    for ($i = 0; $i < 12; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out . $symbols[random_int(0, strlen($symbols) - 1)];
}

echo "=== BEFORE ===\n";
foreach ($pdo->query("SELECT report_id, status, assigned_to FROM public.defect_reports
                      WHERE report_id IN ({$reportList}) ORDER BY report_id") as $r) {
    echo "  {$r['report_id']}  status={$r['status']}  assigned_to={$r['assigned_to']}\n";
}
foreach ($pdo->query("SELECT user_id, fullname, role FROM public.users
                      WHERE user_id IN ({$demoteList}) ORDER BY fullname") as $r) {
    echo "  {$r['user_id']}  {$r['fullname']}  role={$r['role']}\n";
}

$created = [];
$pdo->beginTransaction();
try {
    // ---- 1. Move the in-flight work off the outgoing technicians -------------
    $up = $pdo->prepare(
        "UPDATE public.defect_reports
            SET assigned_to = :t, assigned_technician = :t2, status = 'assigned', assigned_date = now()
          WHERE report_id = :r");
    foreach ($REPORTS as $rid) {
        $up->execute([':t' => $REASSIGN_TO, ':t2' => $REASSIGN_TO, ':r' => $rid]);
    }

    // ---- 2. Technician -> reporter -------------------------------------------
    $dm = $pdo->prepare("UPDATE public.users SET role = 'reporter' WHERE user_id = :u AND role <> 'reporter'");
    foreach (array_keys($DEMOTE) as $uid) {
        $dm->execute([':u' => $uid]);
    }

    // ---- 3 & 4. The two new accounts -----------------------------------------
    $insUser = $pdo->prepare(
        "INSERT INTO public.users (user_id, username, password, fullname, email, department, role, status, created_at)
         VALUES (:id, :un, :pw, :fn, :em, :dept, 'admin', 'active', now())");
    $insTech = $pdo->prepare(
        "INSERT INTO public.maintenance_technicians
                (technician_id, username, password, fullname, email, specialization, department, status, created_at)
         VALUES (:id, :un, :pw, :fn, :em, :spec, :dept, 'active', now())");
    $exists = $pdo->prepare("SELECT 1 FROM public.users WHERE lower(email) = lower(:em) LIMIT 1");

    $newAccounts = [
        [
            'id'   => 'ADMIN-007',
            'un'   => 'juan_bejuna',
            'fn'   => 'Juan P. Bejuna',
            'em'   => 'juan_bejuna@bec.edu.ph',
            'dept' => 'PMO',
            'technician' => true,
            'spec' => 'General',
        ],
        [
            'id'   => 'ADMIN-008',
            'un'   => 'mark.matibag',
            'fn'   => 'Mark Matibag',
            'em'   => 'mark.matibag@bec.edu.ph',
            'dept' => 'External IT Tester',
            'technician' => false,
            'spec' => null,
        ],
    ];

    foreach ($newAccounts as $a) {
        $exists->execute([':em' => $a['em']]);
        if ($exists->fetchColumn()) {
            echo "\n  (skip) {$a['em']} already exists\n";
            continue;
        }

        $plain  = makePassword();
        $hashed = password_hash($plain, PASSWORD_DEFAULT);

        $insUser->execute([
            ':id'   => $a['id'],
            ':un'   => $a['un'],
            ':pw'   => $hashed,
            ':fn'   => $a['fn'],
            ':em'   => $a['em'],
            ':dept' => $a['dept'],
        ]);

        if ($a['technician']) {
            $insTech->execute([
                ':id'   => $a['id'],
                ':un'   => $a['un'],
                ':pw'   => $hashed,
                ':fn'   => $a['fn'],
                ':em'   => $a['em'],
                ':spec' => $a['spec'],
                ':dept' => $a['dept'],
            ]);
        }

        $created[] = [
            'email'    => $a['em'],
            'password' => $plain,
            'id'       => $a['id'],
            'name'     => $a['fn'],
        ];
    }

    $pdo->commit();
    echo "\ncommitted\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\nROLLED BACK: " . $e->getMessage() . "\n");
    exit(1);
}

foreach ($REPORTS as $rid) {
    logActivity($REASSIGN_TO, 'report.reassigned',
        "Report {$rid} reassigned to {$REASSIGN_TO}; previous technician moved to the reporter role");
}
foreach ($DEMOTE as $uid => $name) {
    logActivity($uid, 'user.role_changed', "{$name} changed from technician to reporter");
}
foreach ($created as $c) {
    logActivity($c['id'], 'user.created', "{$c['name']} created as an administrator ({$c['email']})");
}

echo "\n=== AFTER ===\n";
foreach ($pdo->query("SELECT report_id, status, assigned_to FROM public.defect_reports
                      WHERE report_id IN ({$reportList}) ORDER BY report_id") as $r) {
    echo "  {$r['report_id']}  status={$r['status']}  assigned_to={$r['assigned_to']}\n";
}
echo "\n";
foreach ($pdo->query("SELECT user_id, fullname, role, department FROM public.users
                      ORDER BY role, fullname") as $r) {
    printf("  %-11s %-24s %-11s %s\n", $r['user_id'], $r['fullname'], $r['role'], $r['department']);
}

echo "\n=== assignable technicians now ===\n";
foreach (getAvailableTechnicians() as $t) {
    printf("  %-11s %s\n", $t['technician_id'], $t['fullname']);
}

if ($created) {
    echo "\n=== NEW PASSWORDS (shown once) ===\n";
    foreach ($created as $c) {
        printf("  %-26s %s\n", $c['email'], $c['password']);
    }
}
