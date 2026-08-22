<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireRole('admin');
require_once __DIR__ . '/includes/csrf.php';
// becdir_sort_year_levels() — year levels must read Nursery → 4th Year here too.
require_once __DIR__ . '/includes/bec_directory_helper.php';

$isPgSql = isPgSqlDriver();
$pdo = $isPgSql ? getPgsqlPdoConnection() : null;
$conn = $isPgSql ? null : getDBConnection();
$usersTable = $isPgSql ? 'public.users' : 'users';
$defectReportsTable = $isPgSql ? 'public.defect_reports' : 'defect_reports';

// Detect optional users table columns to keep SQL compatible across schema versions.
$rawUserColumns = getTableColumns('users');
$userCols = array_fill_keys(array_keys($rawUserColumns), true);
$hasDeptCol = isset($userCols['department']);
$hasPhoneCol = isset($userCols['phone']);
$roleEnumValues = [];
$roleCol = $rawUserColumns['role'] ?? null;
if (is_array($roleCol) && isset($roleCol['Type'])) {
    if (preg_match_all("/'([^']+)'/", (string)($roleCol['Type'] ?? ''), $matches)) {
        $roleEnumValues = $matches[1];
    }
}
// One role covers everyone who files reports. "Student" used to sit here beside
// "Reporter", which forced the PMO to decide whether a teacher reporting a
// broken aircon was a Reporter or a Student — two different questions. The role
// says what the system lets a person do; who they are at the college is their
// type (below), the same field the imported BEC directory already carries.
// PMO is not a role. Nothing gates on it — every portal asks for 'admin' or
// 'technician' — so a PMO account could be created and then could not sign in
// anywhere. The PMO and the ITSO are administrators, told apart by department.
$assignableRoleMeta = [
    'reporter' => 'Reporter',
    'technician' => 'Technician',
    'admin' => 'Administrator',
];
// Limited to the three types the BEC directory recognises, so an imported record
// and an account created here describe a person the same way. Janitors, drivers
// and office personnel are Staff.
$reporterTypeMeta = [
    'student' => 'Student',
    'faculty' => 'Faculty / Teacher',
    'staff'   => 'Staff (office, maintenance, janitorial)',
];
$hasUserTypeCol = isset($userCols['user_type']);
// Arrives with scripts/2026_08_directory_year_level.sql; the page must still work without it.
$hasYearLevelCol = isset($userCols['year_level']);

/**
 * Server-side field checks for the account forms.
 *
 * The forms carry maxlength and pattern attributes, but those are the browser's
 * opinion — a direct POST ignores them entirely. These run on every submission
 * and return the messages to show, so create and edit judge input identically.
 */
function becAccountFieldErrors(string $fname, string $email, string $phone, string $dept): array {
    $errors = [];
    $len = mb_strlen($fname);
    if ($len > 0 && $len < 2)  { $errors[] = 'Full name is too short.'; }
    if ($len > 100)            { $errors[] = 'Full name must be 100 characters or fewer.'; }
    // Letters, spaces, and the punctuation Filipino names actually use.
    if ($fname !== '' && !preg_match("/^[\p{L}\p{M}\s.'\-]+$/u", $fname)) {
        $errors[] = 'Full name may contain only letters, spaces, apostrophes, hyphens, and periods.';
    }
    if (mb_strlen($email) > 150) { $errors[] = 'Email address must be 150 characters or fewer.'; }
    if ($phone !== '' && !preg_match('/^09\d{9}$/', $phone)) {
        $errors[] = 'Phone number must be 11 digits starting with 09 (e.g. 09171234567).';
    }
    if (mb_strlen($dept) > 100) { $errors[] = 'Unit / department must be 100 characters or fewer.'; }
    return $errors;
}
$assignableRoles = $roleEnumValues
    ? array_values(array_filter(array_keys($assignableRoleMeta), static fn($role) => in_array($role, $roleEnumValues, true)))
    : array_keys($assignableRoleMeta);

// Reporters and students never sign in with a password — the reporter portal
// identifies them by full name + official BEC email (student_index.php), so
// asking the administrator to invent a password for them only causes confusion.
$passwordlessRoles = ['reporter', 'student'];
$roleNeedsPassword = static fn($role) => !in_array($role, ['reporter', 'student'], true);

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['fullname'] ?? 'Administrator';

/* ─── POST ACTIONS ─────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['action'] ?? '';

    /* CREATE */
    if ($act === 'create') {
        $fname  = trim($_POST['fullname']    ?? '');
        $email  = trim($_POST['email']       ?? '');
        $role   = $_POST['role']             ?? 'reporter';
        $dept   = trim($_POST['department']  ?? '');
        $phone  = trim($_POST['phone']       ?? '');
        $pass   = $_POST['password']         ?? '';
        $pass2  = $_POST['password_confirm'] ?? '';
        $utype  = strtolower(trim($_POST['user_type'] ?? ''));
        $ylevel = trim($_POST['year_level']  ?? '');

        $needsPassword = $roleNeedsPassword($role);
        $isReporter    = ($role === 'reporter');

        $errors = becAccountFieldErrors($fname, $email, $phone, $dept);
        if (!$fname) $errors[] = 'Full name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if ($needsPassword) {
            if (strlen($pass) < 8) $errors[] = 'Password must be at least 8 characters.';
            if ($pass !== $pass2) $errors[] = 'Passwords do not match.';
        }
        if (!in_array($role, $assignableRoles, true)) $errors[] = 'Selected role is not supported by the current database setup.';
        // A reporter is a person at the college first — the PMO needs to know
        // whether they are a student, a teacher or staff when reading a report.
        if ($hasUserTypeCol && $isReporter && !isset($reporterTypeMeta[$utype])) {
            $errors[] = 'Choose whether this reporter is a Student, Faculty, or Staff.';
        }
        // An Administrator must be scoped to a unit (PMO or ITSO) — it drives their dashboard.
        if ($role === 'admin') {
            $du = strtoupper($dept);
            if (strpos($du, 'PMO') === false && strpos($du, 'ITSO') === false) {
                $errors[] = 'Select the unit this Administrator will oversee — PMO or ITSO.';
            }
        }

        // Check duplicate email
        if (userExistsByEmail($email)) $errors[] = 'Email address is already registered.';

        if ($errors) {
            $_SESSION['flash'] = ['err', implode(' ', $errors)];
        } else {
            // users.password is NOT NULL, so a passwordless role still stores a
            // hash — a random one nobody holds, which no login can ever match.
            $hash = $needsPassword
                ? password_hash($pass, PASSWORD_DEFAULT)
                : password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
            $uid  = 'USR-' . strtoupper(substr(md5(uniqid()), 0, 8));
            // Generate a unique username (users.username is NOT NULL UNIQUE).
            $username = preg_replace('/[^A-Za-z0-9._-]/', '', explode('@', $email)[0]);
            if ($username === '') { $username = strtolower($uid); }
            if (function_exists('userExistsByUsername')) {
                $baseU = $username; $n = 1;
                while (userExistsByUsername($username)) { $username = $baseU . $n; $n++; }
            }
            $insertData = [
                'user_id' => $uid,
                'username' => $username,
                'fullname' => $fname,
                'email' => $email,
                'role' => $role,
                'password' => $hash,
                'status' => 'active',
            ];
            if ($hasDeptCol) {
                $insertData['department'] = $dept;
            }
            if ($hasPhoneCol) {
                $insertData['phone'] = $phone;
            }
            if ($hasUserTypeCol && $isReporter) {
                $insertData['user_type'] = $utype;
            }
            if ($hasYearLevelCol) {
                // Only a student holds a year level; anyone else would carry a
                // standing that means nothing.
                $insertData['year_level'] = ($isReporter && $utype === 'student' && $ylevel !== '') ? $ylevel : null;
            }

            if ($isPgSql) {
                $fields = array_keys($insertData);
                $sql = "INSERT INTO {$usersTable} (" . implode(', ', $fields) . ", created_at) VALUES (:" . implode(', :', $fields) . ", now())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($insertData);
            } else {
                $fields = array_keys($insertData);
                $placeholders = implode(', ', array_fill(0, count($fields), '?'));
                $sql = "INSERT INTO {$usersTable} (" . implode(', ', $fields) . ", created_at) VALUES ({$placeholders}, NOW())";
                $stmt = $conn->prepare($sql);
                $values = array_values($insertData);
                $stmt->bind_param(str_repeat('s', count($values)), ...$values);
                $stmt->execute();
            }
            logActivity($admin_id, 'account.create', "Created $role account $fname <$email> (ID: $uid)"
                . ($isReporter && $utype !== '' ? " — type: $utype" : ''));
            $_SESSION['flash'] = ['ok', $needsPassword
                ? "User \"$fname\" created successfully (ID: $uid)."
                : "\"$fname\" was added (ID: $uid). They can sign in at the reporter portal with their full name and $email — no password needed."];
        }
    }

    /* INVITE TECHNICIAN (#8 — identity verification flow) */
    if ($act === 'invite_technician') {
        $fname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        $spec = trim($_POST['specialization'] ?? '');

        $errors = [];
        if (!$fname) $errors[] = 'Technician name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        } elseif (!str_ends_with(strtolower($email), '@bec.edu.ph')) {
            // The reporter portal already refuses anything outside the institution;
            // staff invitations were the one door that accepted any domain.
            $errors[] = 'Technicians must be invited using an official @bec.edu.ph email address.';
        }
        // Marked required in the form, but nothing enforced it server-side, so a
        // direct POST could create a technician with no unit at all.
        $allowedUnits = ['PMO', 'ITSO', 'Maintenance Department'];
        if (!in_array($dept, $allowedUnits, true)) {
            $errors[] = 'Please choose the technician\'s unit or department.';
        }
        if (userExistsByEmail($email)) $errors[] = 'That email is already registered.';

        if ($errors) {
            $_SESSION['flash'] = ['err', implode(' ', $errors)];
        } else {
            $uid = 'TECH-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
            $username = preg_replace('/[^A-Za-z0-9._-]/', '', explode('@', $email)[0]) ?: strtolower($uid);
            if (function_exists('userExistsByUsername')) { $baseU = $username; $n = 1; while (userExistsByUsername($username)) { $username = $baseU . $n; $n++; } }
            $token = bin2hex(random_bytes(24));
            $placeholder = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            // Expiry on the DB clock (3-day link) so it can't be skewed by a PHP/DB timezone gap.
            $stmt = $pdo->prepare("INSERT INTO {$usersTable}
                (user_id, username, password, fullname, email, role, status, department, specialization, position, invite_token, invite_expires_at, created_at)
                VALUES (:uid,:un,:pw,:fn,:em,'technician','invited',:dept,:spec,:pos,:tok, now() + interval '72 hours', now())");
            $ok = $stmt->execute([
                'uid'=>$uid,'un'=>$username,'pw'=>$placeholder,'fn'=>$fname,'em'=>$email,
                'dept'=>$dept,'spec'=>$spec,'pos'=>$position,'tok'=>$token,
            ]);

            if ($ok) {
                if (!function_exists('sendEmail')) { @require_once __DIR__ . '/includes/mail_helper.php'; }
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
                $link = $baseUrl . '/technician/verify_account.php?token=' . $token;
                $emailSent = false;
                if (function_exists('sendEmail')) {
                    // Role summary rows (only render what was provided)
                    $roleRows = '';
                    $roleRows .= '<tr><td style="padding:6px 0;color:#9E8070;width:130px;">Role</td><td style="padding:6px 0;color:#1C1008;font-weight:600;">Maintenance Technician</td></tr>';
                    if ($position !== '') $roleRows .= '<tr><td style="padding:6px 0;color:#9E8070;">Position</td><td style="padding:6px 0;color:#1C1008;font-weight:600;">' . htmlspecialchars($position, ENT_QUOTES) . '</td></tr>';
                    if ($dept !== '')     $roleRows .= '<tr><td style="padding:6px 0;color:#9E8070;">Unit / Department</td><td style="padding:6px 0;color:#1C1008;font-weight:600;">' . htmlspecialchars($dept, ENT_QUOTES) . '</td></tr>';
                    if ($spec !== '')     $roleRows .= '<tr><td style="padding:6px 0;color:#9E8070;">Specialization</td><td style="padding:6px 0;color:#1C1008;font-weight:600;">' . htmlspecialchars($spec, ENT_QUOTES) . '</td></tr>';

                    $body = '<div style="background:#F4EFE6;padding:28px 16px;font-family:Segoe UI,Helvetica,Arial,sans-serif;">'
                        . '<div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #E8DDD0;border-radius:16px;overflow:hidden;box-shadow:0 2px 6px rgba(44,10,10,.06);">'
                        // Letterhead
                        . '<div style="background:linear-gradient(135deg,#4A0E0E,#7B1D1D);padding:26px 30px;">'
                        .   '<div style="color:#fff;font-size:17px;font-weight:700;letter-spacing:.2px;">Batangas Eastern Colleges</div>'
                        .   '<div style="color:#E9C77B;font-size:11px;letter-spacing:1.2px;text-transform:uppercase;margin-top:3px;">Property Management Office</div>'
                        .   '<div style="color:rgba(255,255,255,.6);font-size:10.5px;font-style:italic;margin-top:8px;">Beacons of Education, Molders of Educators &middot; Est. 1940</div>'
                        . '</div>'
                        . '<div style="height:3px;background:linear-gradient(90deg,#4A0E0E,#7B1D1D 55%,#C9960C);"></div>'
                        // Body
                        . '<div style="padding:30px;">'
                        .   '<div style="font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#C9960C;">Technician Account Invitation</div>'
                        .   '<h2 style="margin:6px 0 16px;color:#1C1008;font-size:21px;font-weight:700;">Welcome to the PMO maintenance team</h2>'
                        .   '<p style="margin:0 0 16px;color:#5C3838;font-size:14px;line-height:1.7;">Dear ' . htmlspecialchars($fname, ENT_QUOTES) . ',</p>'
                        .   '<p style="margin:0 0 18px;color:#5C3838;font-size:14px;line-height:1.7;">On behalf of the <strong>Property Management Office of Batangas Eastern Colleges</strong>, we are pleased to invite you to join our maintenance team on the college\'s Defective Equipment Reporting &amp; Maintenance Management System. In this role you will receive assigned repair tasks, record your work, and help keep our campus facilities safe and fully operational for the entire BEC community.</p>'
                        // Role card
                        .   '<div style="background:#FAF7F0;border:1px solid #EDE3D3;border-radius:12px;padding:16px 20px;margin:0 0 20px;">'
                        .     '<table style="width:100%;border-collapse:collapse;font-size:13px;">' . $roleRows . '</table>'
                        .   '</div>'
                        .   '<p style="margin:0 0 12px;color:#5C3838;font-size:14px;line-height:1.7;">To activate your account, please verify your identity and set your own password using the secure button below:</p>'
                        .   '<div style="text-align:center;margin:22px 0;">'
                        .     '<a href="' . htmlspecialchars($link, ENT_QUOTES) . '" style="display:inline-block;background:linear-gradient(135deg,#4A0E0E,#7B1D1D);color:#fff;text-decoration:none;padding:14px 34px;border-radius:9px;font-weight:700;font-size:14.5px;box-shadow:0 4px 12px rgba(74,14,14,.25);">Verify &amp; Activate My Account</a>'
                        .   '</div>'
                        .   '<p style="margin:0 0 4px;color:#9E8070;font-size:12px;line-height:1.6;">If the button does not work, copy and paste this link into your browser:</p>'
                        .   '<p style="margin:0 0 20px;word-break:break-all;"><a href="' . htmlspecialchars($link, ENT_QUOTES) . '" style="color:#7B1D1D;font-size:12px;">' . htmlspecialchars($link, ENT_QUOTES) . '</a></p>'
                        .   '<div style="background:#FFFBEF;border:1px solid rgba(201,150,12,.35);border-left:4px solid #C9960C;border-radius:8px;padding:12px 16px;color:#5C3838;font-size:12.5px;line-height:1.6;">'
                        .     '<strong style="color:#8A6400;">For your security:</strong> this invitation link is valid for <strong>3 days</strong> and can be used only once. Choose a strong password of at least 8 characters. Never share this link or your password with anyone.'
                        .   '</div>'
                        .   '<p style="margin:22px 0 0;color:#5C3838;font-size:14px;line-height:1.7;">We look forward to working with you.</p>'
                        .   '<p style="margin:14px 0 0;color:#1C1008;font-size:14px;line-height:1.6;">Warm regards,<br><strong>Property Management Office</strong><br><span style="color:#9E8070;font-size:13px;">Batangas Eastern Colleges</span></p>'
                        . '</div>'
                        // Footer
                        . '<div style="background:#4A0E0E;padding:16px 30px;color:rgba(255,255,255,.7);font-size:11px;line-height:1.6;">'
                        .   'This is an official, automated message from the BEC Property Management Office. If you did not expect this invitation, please disregard this email &mdash; no account will be activated without the link above.'
                        . '</div>'
                        . '</div></div>';
                    try { $emailSent = sendEmail($email, 'Your BEC Technician Account Invitation — Property Management Office', $body, null, 'admin'); } catch (\Throwable $e) {}
                }
                logActivity($admin_id, 'technician.invite', "Invited technician $fname <$email> (ID: $uid)");
                $_SESSION['flash'] = ['ok', $emailSent
                    ? "Invitation sent to $email. The technician can now verify and activate their account."
                    : "Technician invited (ID: $uid), but the email could not be sent. Share this activation link manually: $link"];
            } else {
                $_SESSION['flash'] = ['err', 'Failed to create the technician invitation.'];
            }
        }
    }

    /* EDIT */
    if ($act === 'edit') {
        $uid   = $_POST['user_id']    ?? '';
        $fname = trim($_POST['fullname']   ?? '');
        $email = trim($_POST['email']      ?? '');
        $role  = $_POST['role']            ?? '';
        $dept  = trim($_POST['department'] ?? '');
        $phone = trim($_POST['phone']      ?? '');
        $status= $_POST['status']          ?? 'active';
        $newpass = $_POST['new_password']  ?? '';
        $utype = strtolower(trim($_POST['user_type'] ?? ''));
        $ylevel = trim($_POST['year_level'] ?? '');
        $isReporter = ($role === 'reporter');

        $errors = becAccountFieldErrors($fname, $email, $phone, $dept);
        if (!$fname) $errors[] = 'Full name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if ($newpass !== '' && strlen($newpass) < 8) $errors[] = 'The new password must be at least 8 characters.';
        if ($uid === $admin_id && $role !== 'admin') $errors[] = 'You cannot change your own admin role.';
        if (!in_array($role, $assignableRoles, true)) $errors[] = 'Selected role is not supported by the current database setup.';
        if ($hasUserTypeCol && $isReporter && !isset($reporterTypeMeta[$utype])) {
            $errors[] = 'Choose whether this reporter is a Student, Faculty, or Staff.';
        }
        if ($role === 'admin') {
            $du = strtoupper($dept);
            if (strpos($du, 'PMO') === false && strpos($du, 'ITSO') === false) {
                $errors[] = 'Select the unit this Administrator will oversee — PMO or ITSO.';
            }
        }

        // Check email duplicate (excluding self)
        if (userExistsByEmail($email, $uid)) $errors[] = 'Email already used by another user.';

        if ($errors) {
            $_SESSION['flash'] = ['err', implode(' ', $errors)];
        } else {
            $usePass = ($newpass && strlen($newpass) >= 8);
            $updateData = [
                'fullname' => $fname,
                'email' => $email,
                'role' => $role,
                'status' => $status,
            ];
            if ($hasDeptCol) {
                $updateData['department'] = $dept;
            }
            if ($hasPhoneCol) {
                $updateData['phone'] = $phone;
            }
            if ($hasUserTypeCol) {
                // Cleared when the account stops being a reporter, so a former
                // reporter turned technician doesn't keep a stale "Student" tag.
                $updateData['user_type'] = $isReporter ? $utype : null;
            }
            if ($hasYearLevelCol) {
                $updateData['year_level'] = ($isReporter && $utype === 'student' && $ylevel !== '') ? $ylevel : null;
            }
            if ($usePass) {
                $updateData['password'] = password_hash($newpass, PASSWORD_DEFAULT);
            }

            if ($isPgSql) {
                $sets = [];
                foreach (array_keys($updateData) as $field) {
                    $sets[] = "{$field} = :{$field}";
                }
                $updateData['user_id'] = $uid;
                $stmt = $pdo->prepare("UPDATE {$usersTable} SET " . implode(', ', $sets) . " WHERE user_id = :user_id");
                $stmt->execute($updateData);
                $affected = $stmt->rowCount();
            } else {
                $sets = [];
                foreach (array_keys($updateData) as $field) {
                    $sets[] = "{$field} = ?";
                }
                $values = array_values($updateData);
                $values[] = $uid;
                $stmt = $conn->prepare("UPDATE {$usersTable} SET " . implode(', ', $sets) . " WHERE user_id = ?");
                $stmt->bind_param(str_repeat('s', count($values)), ...$values);
                $stmt->execute();
                $affected = $stmt->affected_rows;
            }
            if ($affected < 1) {
                // No account row matched — e.g. a directory-imported reporter (no login account).
                $_SESSION['flash'] = ['err', "No editable account was found for \"$fname\". Directory-imported reporters have no login account to update."];
            } else {
                logActivity($admin_id, 'account.update', "Updated account $fname <$email> (ID: $uid) — role: $role"
                    . ($usePass ? ', password changed' : ''));
                $_SESSION['flash'] = ['ok', "User \"$fname\" updated successfully."];
            }
        }
    }

    /* DELETE */
    if ($act === 'delete') {
        $uid = $_POST['user_id'] ?? '';
        if ($uid === $admin_id) {
            $_SESSION['flash'] = ['err', 'You cannot delete your own account.'];
        } else {
            $deleted = false;
            // Read the account before it goes, so the audit entry names a person
            // rather than an ID nobody can look up afterwards.
            $gone = null;
            try { $gone = findUserById($uid, ['fullname', 'email', 'role']); } catch (Throwable $e) {}

            // Prefer hard delete so counts drop immediately.
            try {
                if ($isPgSql) {
                    $stmt = $pdo->prepare("DELETE FROM {$usersTable} WHERE user_id = :user_id");
                    $deleted = (bool)$stmt->execute(['user_id' => $uid]);
                } else {
                    $stmt = $conn->prepare("DELETE FROM {$usersTable} WHERE user_id = ?");
                    if ($stmt) {
                        $stmt->bind_param('s', $uid);
                        $deleted = (bool)$stmt->execute();
                    }
                }
            } catch (Throwable $e) {
                $deleted = false;
            }

            // If constrained by related records, deactivate instead.
            if (!$deleted) {
                if ($isPgSql) {
                    $stmt = $pdo->prepare("UPDATE {$usersTable} SET status = 'inactive' WHERE user_id = :user_id");
                    $deleted = (bool)$stmt->execute(['user_id' => $uid]);
                } else {
                    $stmt = $conn->prepare("UPDATE {$usersTable} SET status = 'inactive' WHERE user_id = ?");
                    if ($stmt) {
                        $stmt->bind_param('s', $uid);
                        $deleted = (bool)$stmt->execute();
                    }
                }
            }

            if ($deleted) {
                $who = $gone
                    ? (($gone['fullname'] ?? '') . ' <' . ($gone['email'] ?? '') . '>, role ' . ($gone['role'] ?? '?'))
                    : 'unknown account';
                logActivity($admin_id, 'account.delete', "Removed account $who (ID: $uid)");
            }
            $_SESSION['flash'] = $deleted
                ? ['ok', 'User account removed successfully.']
                : ['err', 'Failed to remove user account.'];
        }
    }

    /* RESET PASSWORD */
    if ($act === 'reset_password') {
        $uid    = $_POST['user_id']  ?? '';
        $newpw  = $_POST['new_pass'] ?? '';
        $errors = [];
        if (strlen($newpw) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($errors) {
            $_SESSION['flash'] = ['err', implode(' ', $errors)];
        } else {
            $hash = password_hash($newpw, PASSWORD_DEFAULT);
            if ($isPgSql) {
                $stmt = $pdo->prepare("UPDATE {$usersTable} SET password = :password WHERE user_id = :user_id");
                $stmt->execute(['password' => $hash, 'user_id' => $uid]);
            } else {
                $stmt = $conn->prepare("UPDATE {$usersTable} SET password=? WHERE user_id=?");
                $stmt->bind_param('ss', $hash, $uid);
                $stmt->execute();
            }
            // The password itself is never logged — only that it was reset, by whom, for whom.
            $target = null;
            try { $target = findUserById($uid, ['fullname', 'email']); } catch (Throwable $e) {}
            logActivity($admin_id, 'account.password_reset', 'Reset the password for '
                . ($target ? ($target['fullname'] ?? '') . ' <' . ($target['email'] ?? '') . '>' : 'account') . " (ID: $uid)");
            $_SESSION['flash'] = ['ok', 'Password reset successfully.'];
        }
    }

    header('Location: admin_users.php'); exit();
}

/* ─── DATA ──────────────────────────────────────────── */
$rf  = $_GET['role']   ?? 'all';
// Student is no longer a role of its own — an old link or bookmark lands on
// Reporters, where those people now live.
if ($rf === 'student') { $rf = 'reporter'; }
$sf  = $_GET['status'] ?? 'all';
$sq  = $_GET['search'] ?? '';

// Role answers what the system lets a person do. It does not answer the
// question the PMO actually asks of this page — "show me the Senior High
// students", "show me the non-teaching offices" — because the whole college
// arrives here under the single role of Reporter. Those two filters do.
$tf = strtolower(trim((string)($_GET['type'] ?? 'all')));
if (!in_array($tf, ['all', 'student', 'faculty', 'staff'], true)) { $tf = 'all'; }
$df = trim((string)($_GET['dept'] ?? 'all'));

// PMO and ITSO are not roles — they are the two units the administrators
// belong to, told apart by users.department exactly as adminUnitForUser()
// does it. The page used to offer a "PMO" role tab, which matched the retired
// role of that name: it held nobody, so it always showed an empty list while
// the six real PMO and ITSO administrators sat under "Admins".
$uf = strtolower(trim((string)($_GET['unit'] ?? 'all')));
if (!in_array($uf, ['all', 'pmo', 'itso'], true)) { $uf = 'all'; }

// Year level — the third question the roster is asked ("the Grade 12s", "the
// 1st years"), and the one the BEC Directory page already answers. Stored as
// the printed standing itself, in both tables, so the value travels as text.
$yl = trim((string)($_GET['year'] ?? 'all'));
if ($yl === '') { $yl = 'all'; }
// ITSO is tested first so a department naming both lands where adminUnitForUser() puts it.
$unitSql = static function (string $unit): string {
    return $unit === 'itso'
        ? "POSITION('ITSO' IN UPPER(COALESCE(u.department,''))) > 0"
        : "POSITION('ITSO' IN UPPER(COALESCE(u.department,''))) = 0
           AND POSITION('PMO' IN UPPER(COALESCE(u.department,''))) > 0";
};

// The roster runs to thousands of people, and every row on this page carries a
// full JSON copy of itself for the profile and edit dialogs. Rendering the lot
// would produce a page too large to be usable, so the list is paginated in the
// database — LIMIT/OFFSET, not a client-side slice of everything.
//
// The list is a concatenation of two sources: account holders from `users`
// first, then the imported directory roster. A page can straddle the boundary,
// so the offset is spent against the users table first and whatever is left
// over becomes the directory offset.
// 50 keeps a page scannable in one scroll and the payload small; the pager is
// the only way through the list now, so this is the real page size, not a cap.
$perPage = 50;
$page    = max(1, (int) ($_GET['page'] ?? 1));

// An export is not a page. It used to be built in the browser from whatever the
// page happened to be showing, so "N users exported" meant the current 50 out of
// thousands. Asking for one page of everything lets the same queries — and the
// same filters — produce the whole list, and the export branch below hands it
// straight to the shared CSV/XLSX/print writers.
$exportFmt = strtolower(trim((string) ($_GET['export'] ?? '')));
$isExport  = in_array($exportFmt, ['csv', 'xlsx', 'pdf'], true);
if ($isExport) { $perPage = 1000000; $page = 1; }

$offset  = ($page - 1) * $perPage;

// Every filter must survive a page change, or paging would silently reset the view.
$pageQuery = static function (int $p) use ($rf, $uf, $tf, $df, $yl, $sq): string {
    $args = ['page' => $p];
    if ($rf !== 'all' && $rf !== '') { $args['role']   = $rf; }
    if ($uf !== 'all')               { $args['unit']   = $uf; }
    if ($tf !== 'all')               { $args['type']   = $tf; }
    if ($df !== 'all')               { $args['dept']   = $df; }
    if ($yl !== 'all')               { $args['year']   = $yl; }
    if ($sq !== '')                  { $args['search'] = $sq; }
    return '?' . http_build_query($args);
};

// Only the view the admin is actually looking at gets rendered. Both were
// always emitted and one was hidden with display:none, so every person was
// written into the page twice — the hidden grid was 40% of the bytes. The
// choice lives in a cookie so the server can see it; the old localStorage key
// is kept in step for anyone who already had a preference.
$view = (($_COOKIE['bec_uview'] ?? 'table') === 'grid') ? 'grid' : 'table';

// What the imported roster says a person is.
//
// The registrar's enrolment export types everyone in it as a student — the
// people sitting in the Administrative / Non-teaching Office included, because
// the file has no column that says otherwise. Reading the department back is
// what keeps the affiliation filter, the counts and the badge on the row all
// saying the same thing about the same person, instead of three things.

// The filter dropdowns and the roster counts, both settled before a single row
// is fetched: the list is ordered by year level, so the school order of the
// levels has to be known while the query is still being built.
//
// Defined before its first use: this is a closure in a variable, so unlike a
// function it is not hoisted. It was declared further down and called from
// inside the try below, where the resulting error was swallowed — leaving the
// affiliation counts at zero and the department and year pickers empty.
$dirTypeSql = static function (string $prefix): string {
    return "CASE
        WHEN POSITION('ADMINISTRATIVE' IN UPPER(COALESCE({$prefix}department,''))) > 0
          OR POSITION('NON-TEACHING'   IN UPPER(COALESCE({$prefix}department,''))) > 0 THEN 'staff'
        ELSE LOWER(COALESCE(NULLIF({$prefix}user_type,''),'student'))
    END";
};

// Counted in the database, not by pulling a row per person back to be counted
// here — the directory is the whole college.
$dirByType = ['student'=>0,'faculty'=>0,'staff'=>0];
$dirTotal  = 0;
// Narrowed by the other active filters — for the FILTER bar only.
$deptOptions = [];
$yearOptions = [];
// The complete vocabulary, ignoring every filter — for the Add/Edit USER FORMS.
// A creation form must offer every department and year level whatever the list
// happens to be filtered to; otherwise filtering the list to Grade School would
// quietly stop you being able to add a college student.
$deptAll = [];
$yearAll = [];
$yearRankCase = '';   // '{col}' placeholder, filled in per table below
try {
    $pdoC = getPgsqlPdoConnection();
    // Somebody who is in the roster AND holds an account is ONE person. The list
    // already de-duplicates them (the directory half carries the same NOT EXISTS),
    // so counting the raw roster here made the headline disagree with the list:
    // 3,598 in the stat card and the affiliation dropdown against 1-50 of 3,595
    // underneath it. Three people, counted twice, on the same screen.
    foreach ($pdoC->query("SELECT " . $dirTypeSql('bd.') . " AS ut, COUNT(*) AS c
                           FROM public.bec_directory bd
                          WHERE NOT EXISTS (SELECT 1 FROM {$usersTable} u
                                             WHERE LOWER(u.email) = LOWER(bd.email)
                                               AND COALESCE(u.status,'') <> 'deleted')
                          GROUP BY 1", PDO::FETCH_ASSOC) as $r) {
        $ut = (string)$r['ut'];
        if (!isset($dirByType[$ut])) { $ut = 'student'; }
        $dirByType[$ut] += (int)$r['c'];
        $dirTotal += (int)$r['c'];
    }
    // Only offer values somebody is actually filed under GIVEN THE OTHER
    // FILTERS, so a dropdown can never lead to an empty list.
    //
    // Applying only "does anybody have this value" is not enough once a second
    // filter is in play: with a college selected, the year dropdown still
    // offered Nursery 1 to Grade 12, and with Grade School selected it still
    // offered 1st to 4th Year. On this roster 85% of the department-and-year
    // pairs cannot exist. Each list is therefore built with every other filter
    // applied but not its own — the same faceting the BEC Directory page uses.
    //
    // Both halves of the union are filtered, because the list on screen is a
    // concatenation of account holders and the imported roster.
    $optFacet = static function (string $col, bool $useYear, bool $useDept, bool $useType = true)
        use ($pdoC, $usersTable, $dirTypeSql, $tf, $df, $yl): array {

        $dW = ["COALESCE(TRIM(bd.{$col}),'') <> ''"];
        $uW = ["COALESCE(TRIM(u.{$col}),'') <> ''", "COALESCE(u.status,'') <> 'deleted'"];
        $p  = [];
        if ($useType && $tf !== 'all') {
            $dW[] = '(' . $dirTypeSql('bd.') . ') = :ut';
            $uW[] = "(LOWER(COALESCE(u.user_type,'')) = :ut OR u.role IN ('admin','pmo','technician'))";
            $p['ut'] = $tf;
        }
        if ($useDept && $df !== 'all') {
            $dW[] = "COALESCE(TRIM(bd.department),'') = :dep";
            $uW[] = "COALESCE(TRIM(u.department),'') = :dep";
            $p['dep'] = $df;
        }
        if ($useYear && $yl !== 'all') {
            $dW[] = "COALESCE(TRIM(bd.year_level),'') = :yr";
            $uW[] = "COALESCE(TRIM(u.year_level),'') = :yr";
            $p['yr'] = $yl;
        }
        $sql = "SELECT DISTINCT TRIM(bd.{$col}) AS v FROM public.bec_directory bd WHERE " . implode(' AND ', $dW)
             . " UNION SELECT DISTINCT TRIM(u.{$col}) FROM {$usersTable} u WHERE " . implode(' AND ', $uW)
             . ' ORDER BY 1';
        $st = $pdoC->prepare($sql);
        $st->execute($p);
        $out = [];
        foreach ($st as $r) { if ((string)$r['v'] !== '') { $out[] = (string)$r['v']; } }
        return $out;
    };

    // Department list respects the year filter; year list respects the department.
    $deptOptions = $optFacet('department', true, false);
    $yearOptions = $optFacet('year_level', false, true);

    // A selected value must stay in its own list even when the other filters
    // have made it impossible, or the <select> shows nothing selected while the
    // filter is still being applied.
    if ($df !== 'all' && !in_array($df, $deptOptions, true)) { $deptOptions[] = $df; }
    if ($yl !== 'all' && !in_array($yl, $yearOptions, true)) { $yearOptions[] = $yl; }

    $yearOptions = becdir_sort_year_levels($yearOptions);

    // School order as a SQL expression, built from the levels that are really
    // in use and ranked by the one function that knows the order. Sorting the
    // text instead puts Grade 10 ahead of Grade 7 and 1st Year ahead of Nursery.
    //
    // Built from EVERY level in use, not from $yearOptions — that list is now
    // narrowed by the department filter, and ranking from it would drop every
    // level outside the current filter into the ELSE bucket, so an unfiltered
    // or differently-filtered view would sort into one undifferentiated block.
    $deptAll = $optFacet('department', false, false, false);   // no filters at all
    $yearAll = $optFacet('year_level', false, false, false);
    $yearAll = becdir_sort_year_levels($yearAll);
    if ($yearAll) {
        $whens = '';
        foreach ($yearAll as $yOpt) {
            $whens .= ' WHEN ' . $pdoC->quote($yOpt) . ' THEN ' . becdir_year_level_rank($yOpt);
        }
        $yearRankCase = "CASE TRIM(COALESCE({col},''))" . $whens . ' ELSE 9999 END';
    }
} catch (Throwable $e) { /* directory or department column unavailable — filters degrade to Role only */ }
$yearRankSql = static fn(string $col): string =>
    $yearRankCase === '' ? '' : str_replace('{col}', $col, $yearRankCase);

// Fetch all users
$drCols = array_fill_keys(array_keys(getTableColumns('defect_reports')), true);
$reporterJoinCol = isset($drCols['reporter_id']) ? 'reporter_id' : (isset($drCols['reported_by']) ? 'reported_by' : (isset($drCols['user_id']) ? 'user_id' : null));
$reportCountExpr = $reporterJoinCol ? "(SELECT COUNT(*) FROM {$defectReportsTable} WHERE {$reporterJoinCol} = u.user_id)" : "0";
/* ─── HOW THE LIST IS ORDERED ────────────────────────
 * Role alone cannot order this page. The whole college files reports under the
 * single role of Reporter, so ordering by role dropped a Nursery pupil, a
 * teacher and the non-teaching office into one undifferentiated block of 3,500.
 *
 * These ranks are the standing the PMO actually reads people by, most senior
 * first, and both halves of the list — account holders and the imported roster
 * — are ordered by the same numbers:
 *
 *   10 PMO administrator   30 technician              50 administrative / non-teaching staff
 *   20 ITSO administrator  40 faculty / teacher       60 student   70 anything else
 *
 * Students then sort by year level (Nursery → 4th Year), then by name.
 */
$roleSortExpr = "CASE
    WHEN u.role IN ('admin','pmo') AND POSITION('ITSO' IN UPPER(COALESCE(u.department,''))) > 0 THEN 20
    WHEN u.role IN ('admin','pmo') THEN 10
    WHEN u.role = 'technician' THEN 30
    WHEN LOWER(COALESCE(u.user_type,'')) = 'faculty' THEN 40
    WHEN LOWER(COALESCE(u.user_type,'')) = 'staff'   THEN 50
    WHEN LOWER(COALESCE(u.user_type,'')) = 'student' THEN 60
    ELSE 70
END";

$q = "SELECT u.*,
        {$reportCountExpr} AS report_count,
        (SELECT COUNT(*) FROM {$defectReportsTable} WHERE assigned_to = u.user_id
         AND status NOT IN ('completed','verified','closed','rejected')) AS active_tasks
      FROM {$usersTable} u
      WHERE u.status != 'deleted'";
$params = []; $types = '';
if ($rf !== 'all')   { $q .= " AND u.role = ?";   $params[] = $rf;   $types .= 's'; }
// Staff hold no user_type — an administrator or technician is a member of
// staff by virtue of the job, so the Staff filter has to reach them too or it
// returns nobody at all.
if ($tf === 'staff') {
    $q .= " AND (LOWER(COALESCE(u.user_type,'')) = 'staff' OR u.role IN ('admin','pmo','technician'))";
} elseif ($tf !== 'all' && $hasUserTypeCol) {
    $q .= " AND LOWER(COALESCE(u.user_type,'')) = ?"; $params[] = $tf; $types .= 's';
}
if ($df !== 'all' && $hasDeptCol)     { $q .= " AND COALESCE(u.department,'') = ?";       $params[] = $df; $types .= 's'; }
if ($uf !== 'all' && $hasDeptCol)     { $q .= " AND (" . $unitSql($uf) . ")"; }
// Nobody holding an account carries a year level unless the column is there, so
// asking for one has to exclude them all rather than quietly ignore the filter.
if ($yl !== 'all') {
    if ($hasYearLevelCol) { $q .= " AND TRIM(COALESCE(u.year_level,'')) = ?"; $params[] = $yl; $types .= 's'; }
    else                  { $q .= " AND 1 = 2"; }
}
if ($sq !== '') {
    $ql = '%'.$sq.'%';
    $searchConds = ["u.fullname LIKE ?", "u.email LIKE ?", "u.user_id LIKE ?"];
    $searchParams = [$ql, $ql, $ql];
    if ($hasDeptCol) {
        $searchConds[] = "u.department LIKE ?";
        $searchParams[] = $ql;
    }
    $q .= " AND (" . implode(' OR ', $searchConds) . ")";
    $params = array_merge($params, $searchParams);
    $types .= str_repeat('s', count($searchParams));
}
// How many account holders match, before paging. Snapshot the FROM/WHERE now —
// once ORDER BY is appended the same string can't be reused as a COUNT (Postgres
// rejects the ordering expressions without a GROUP BY).
// Anchored on the real table, not the first " FROM " — the SELECT list carries
// correlated subqueries that each contain their own FROM.
$mainFrom   = strpos($q, "FROM {$usersTable} u");
$countSql   = 'SELECT COUNT(*) ' . substr($q, $mainFrom);
$usersTotal = 0;
if (isPgSqlDriver()) {
    $pdo  = getPgsqlPdoConnection();
    $cst  = $pdo->prepare($countSql);
    $cst->execute($params);
    $usersTotal = (int) $cst->fetchColumn();
} else {
    $cst = $conn->prepare($countSql);
    if ($params) { $cst->bind_param($types, ...$params); }
    $cst->execute();
    $usersTotal = (int) ($cst->get_result()->fetch_row()[0] ?? 0);
}

// Spend the offset against the users table first, then the directory roster.
$userOffset = min($offset, $usersTotal);
$userLimit  = max(0, min($perPage, $usersTotal - $userOffset));
$dirOffset  = max(0, $offset - $usersTotal);
$dirLimit   = $perPage - $userLimit;

// Interpolated, not bound: both are ints derived from (int) casts, and the two
// drivers disagree about binding LIMIT placeholders.
// Only students are ordered by year level. A technician who happens to carry
// one — the account form offers the field to everybody — would otherwise be
// pulled to the head of the technicians by a value that says nothing about them.
$userYearRank = $hasYearLevelCol && $yearRankSql('u.year_level') !== ''
    ? "CASE WHEN ({$roleSortExpr}) = 60 THEN (" . $yearRankSql('u.year_level') . ") ELSE 0 END"
    : '';
$q .= " ORDER BY {$roleSortExpr}, " . ($userYearRank !== '' ? $userYearRank . ', ' : '')
    . "u.fullname ASC LIMIT {$userLimit} OFFSET {$userOffset}";

$users = [];
if ($userLimit > 0) {
    if (isPgSqlDriver()) {
        $stmt = $pdo->prepare($q);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
    } else {
        $stmt = $conn->prepare($q);
        if ($params) { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// Directory roster (imported students / faculty / staff). Reporters don't hold login
// accounts, so the imported directory people are surfaced here as read-only entries.
$directoryEntries = [];
$dirMatchTotal = 0;   // how many directory people match, before the cap
try {
    $dcolset = getTableColumns('bec_directory');
    // The directory holds reporters only — under any other role tab there is
    // nothing here to show, so the query is not worth running. Nor is it under
    // a PMO/ITSO unit filter: directory departments are academic units, and
    // nobody in the roster belongs to either office.
    if ($dcolset && ($rf === 'all' || $rf === 'reporter') && $uf === 'all') {
        $pdoD = getPgsqlPdoConnection();
        $reporterEmailExpr = isset($drCols['reporter_email'])
            ? "(SELECT COUNT(*) FROM {$defectReportsTable} dr WHERE LOWER(dr.reporter_email)=LOWER(bd.email))"
            : "0";

        // Filtering, de-duplicating and counting all happen in the database.
        // Pulling every row back to sort it out in PHP was affordable for a
        // handful of test records and is not for a full college roster.
        $dWhere = ["NOT EXISTS (SELECT 1 FROM {$usersTable} u
                                 WHERE LOWER(u.email) = LOWER(bd.email)
                                   AND COALESCE(u.status,'') <> 'deleted')"];
        $dParams = [];
        if ($tf !== 'all') { $dWhere[] = "(" . $dirTypeSql('bd.') . ") = :ut"; $dParams['ut'] = $tf; }
        if ($df !== 'all') { $dWhere[] = "COALESCE(bd.department,'') = :dep"; $dParams['dep'] = $df; }
        if ($yl !== 'all') {
            if (isset($dcolset['year_level'])) { $dWhere[] = "TRIM(COALESCE(bd.year_level,'')) = :yr"; $dParams['yr'] = $yl; }
            else                               { $dWhere[] = "1 = 2"; }
        }
        if ($sq !== '') {
            $dWhere[] = "(bd.full_name ILIKE :q OR bd.email ILIKE :q
                          OR COALESCE(bd.student_number,'') ILIKE :q
                          OR COALESCE(bd.employee_number,'') ILIKE :q
                          OR COALESCE(bd.department,'') ILIKE :q
                          OR COALESCE(bd.program,'') ILIKE :q)";
            $dParams['q'] = '%' . $sq . '%';
        }
        $dSql = " FROM public.bec_directory bd WHERE " . implode(' AND ', $dWhere);

        $cst = $pdoD->prepare("SELECT COUNT(*)" . $dSql);
        $cst->execute($dParams);
        $dirMatchTotal = (int)$cst->fetchColumn();

        // Whatever the users table did not consume of this page is filled from
        // the roster, starting wherever the previous pages left off.
        $room = $dirLimit;
        $drows = [];
        if ($room > 0 && $dirOffset < $dirMatchTotal) {
            // Ordered by the same standing ranks the accounts use — faculty,
            // then the non-teaching offices, then the students by year level.
            $dirStanding = "CASE (" . $dirTypeSql('bd.') . ") WHEN 'faculty' THEN 40 WHEN 'staff' THEN 50 ELSE 60 END";
            $dirYearRank = isset($dcolset['year_level']) ? $yearRankSql('bd.year_level') : '';
            $dOrder = $dirStanding
                . ($dirYearRank !== '' ? ", CASE WHEN ({$dirStanding}) = 60 THEN ({$dirYearRank}) ELSE 0 END" : '')
                . ", bd.full_name ASC";
            $dst = $pdoD->prepare("SELECT bd.*, {$reporterEmailExpr} AS report_count, ("
                . $dirTypeSql('bd.') . ") AS affil_type"
                . $dSql . " ORDER BY " . $dOrder . " LIMIT " . (int)$room
                . " OFFSET " . (int)$dirOffset);
            $dst->execute($dParams);
            $drows = $dst->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($drows as $bd) {
            // affil_type, not the raw column: the roster types the whole
            // Administrative / Non-teaching Office as students.
            $ut = strtolower(trim((string)($bd['affil_type'] ?? $bd['user_type'] ?? 'student')));
            if (!in_array($ut, ['student','faculty','staff'], true)) { $ut = 'student'; }
            $entry = [
                'user_id'         => (string)($bd['student_number'] ?: ($bd['employee_number'] ?: ('DIR-'.($bd['id'] ?? '')))),
                'fullname'        => (string)($bd['full_name'] ?? ''),
                'email'           => (string)($bd['email'] ?? ''),
                'role'            => 'reporter',    // everyone in the directory may report; $ut says who they are
                'department'      => (string)($bd['department'] ?? ''),
                'status'          => 'active',
                'report_count'    => (int)($bd['report_count'] ?? 0),
                'active_tasks'    => 0,
                'created_at'      => $bd['imported_at'] ?? null,
                'phone'           => (string)($bd['phone'] ?? ''),
                'program'         => (string)($bd['program'] ?? ''),
                'employee_number' => (string)($bd['employee_number'] ?? ''),
                'student_number'  => (string)($bd['student_number'] ?? ''),
                'year_level'      => (string)($bd['year_level'] ?? ''),
                'user_type'       => $ut,
                'is_directory'    => true,          // read-only marker
            ];
            $directoryEntries[] = $entry;
        }
    }
} catch (Throwable $e) { $directoryEntries = []; }
$users = array_merge($users, $directoryEntries);

/* ---- EXPORT ------------------------------------------------------------
   $users now holds everyone matching the current filters (see $isExport
   above). Built from the records rather than the rendered table, so the
   Standing column reads as one thing instead of a badge plus a sub-label. */
if ($isExport) {
    $uHeaders = ['Full Name', 'Email Address', 'Employee ID', 'Student ID',
                 'Department', 'Program', 'Standing', 'Year Level', 'Reports Filed', 'Date Added'];
    $uRows = [];
    foreach ($users as $u2) {
        $roleLbl = ucwords(str_replace('_', ' ', (string)($u2['role'] ?? '')));
        // A reporter reads as "Reporter (Faculty)" on the PMO form — the role
        // plus who they are at the college.
        $typeLbl = ucwords(strtolower(trim((string)($u2['user_type'] ?? ''))));
        if ($typeLbl !== '' && ($u2['role'] ?? '') === 'reporter') { $roleLbl .= ' (' . $typeLbl . ')'; }
        $joined = trim((string)($u2['created_at'] ?? ''));
        $uRows[] = [
            (string)($u2['fullname'] ?? ''),
            (string)($u2['email'] ?? ''),
            (string)($u2['employee_id'] ?? $u2['employee_number'] ?? '') !== '' ? (string)($u2['employee_id'] ?? $u2['employee_number']) : '—',
            (string)($u2['school_id'] ?? $u2['student_number'] ?? '') !== '' ? (string)($u2['school_id'] ?? $u2['student_number']) : '—',
            (string)($u2['department'] ?? '') !== '' ? (string)$u2['department'] : '—',
            (string)($u2['course'] ?? $u2['program'] ?? '') !== '' ? (string)($u2['course'] ?? $u2['program']) : '—',
            $roleLbl,
            (string)($u2['year_level'] ?? '') !== '' ? (string)$u2['year_level'] : '—',
            (int)($u2['report_count'] ?? 0),
            $joined !== '' ? date('Y-m-d', strtotime($joined)) : '—',
        ];
    }

    $uCount = static function (string $role) use ($users): int {
        return count(array_filter($users, static fn($x) => (string)($x['role'] ?? '') === $role));
    };
    $uSummary = [
        'Total People' => number_format(count($uRows)),
        'Reporters'    => number_format($uCount('reporter')),
        'Technicians'  => number_format($uCount('technician')),
        'Admins'       => number_format($uCount('admin')),
    ];
    $uMeta = array_filter([
        'Role Filter'       => ($rf !== 'all' && $rf !== '') ? ucwords(str_replace('_', ' ', $rf)) : '',
        'Unit Filter'       => $uf !== 'all' ? $uf : '',
        'Affiliation'       => $tf !== 'all' ? ucfirst($tf) : '',
        'Department Filter' => $df !== 'all' ? $df : '',
        'Year Level'        => $yl !== 'all' ? $yl : '',
        'Search'            => $sq !== '' ? $sq : '',
    ]);

    if ($exportFmt === 'csv') {
        require_once __DIR__ . '/includes/csv_export.php';
        $out = becCsvOpen('BEC_PMO_User_List');
        becCsvLetterhead($out, 'User List', $uMeta + ['Total Records' => number_format(count($uRows))]);
        becCsvSection($out, 'Executive Summary', ['Metric', 'Value'],
            array_map(static fn($k, $v) => [$k, $v], array_keys($uSummary), array_values($uSummary)));
        becCsvRow($out, ['USER RECORDS']);
        becCsvRow($out, $uHeaders);
        foreach ($uRows as $row) { becCsvRow($out, $row); }
        becCsvBlank($out);
        becCsvFooter($out, 'End of User List');
        fclose($out);
        exit;
    }

    if ($exportFmt === 'xlsx') {
        require_once __DIR__ . '/includes/xlsx_writer.php';
        becRenderBrandedXlsx('User List', $uHeaders, $uRows, $uSummary, $uMeta, 'BEC_PMO_User_List');
        exit;
    }

    require_once __DIR__ . '/includes/export_branding.php';
    $ueh = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1.0">'
       . '<title>User List — BEC PMO</title>'
       . '<link rel="icon" type="image/png" href="assets/logs.png">'
       . '<style>' . becExportCss() . '@page{size:A4 landscape;margin:12mm 10mm;}</style></head><body>';
    echo becExportToolbar();
    echo becExportHeader('User List', $uMeta + ['Total Records' => number_format(count($uRows))]);
    echo becExportSummaryCards($uSummary);
    echo '<div class="sec-label">User Records</div>';
    echo '<table class="data-table"><thead><tr>';
    foreach ($uHeaders as $hd) { echo '<th>' . $ueh($hd) . '</th>'; }
    echo '</tr></thead><tbody>';
    if (!$uRows) {
        echo '<tr><td colspan="' . count($uHeaders) . '" class="empty">Nobody matches the selected filters.</td></tr>';
    } else {
        foreach ($uRows as $row) {
            echo '<tr>';
            foreach ($row as $cell) { echo '<td>' . $ueh($cell) . '</td>'; }
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo becExportSignatures();
    echo becExportFooter();
    echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},400);});</script>';
    echo '</body></html>';
    exit;
}

// All users for counts. user_type comes along so the Students / Faculty / Staff
// chips can count account holders too, not only imported directory people.
$countCols = 'role, status' . ($hasUserTypeCol ? ', user_type' : '') . ($hasDeptCol ? ', department' : '');
if (isPgSqlDriver()) {
    $all_users_stmt = $pdo->query("SELECT {$countCols} FROM {$usersTable} WHERE status IS NULL OR status != 'deleted'");
    $all_users_raw = $all_users_stmt ? $all_users_stmt->fetchAll() : [];
} else {
    $all_users_res = $conn->query("SELECT {$countCols} FROM {$usersTable} WHERE status IS NULL OR status != 'deleted'");
    $all_users_raw = $all_users_res ? $all_users_res->fetch_all(MYSQLI_ASSOC) : [];
}

function cntU($arr,$fn){return count(array_filter($arr,$fn));}

$usrByType = ['student'=>0,'faculty'=>0,'staff'=>0];
foreach ($all_users_raw as $u) {
    $t = strtolower(trim((string)($u['user_type'] ?? '')));
    // An administrator or technician is staff whether or not anyone recorded a
    // user_type for them; counting only the recorded field showed Staff as 0.
    if ($t === '' && in_array(($u['role'] ?? ''), ['admin','pmo','technician'], true)) { $t = 'staff'; }
    if (isset($usrByType[$t])) { $usrByType[$t]++; }
}
$c_student = $dirByType['student'] + $usrByType['student'];
$c_faculty = $dirByType['faculty'] + $usrByType['faculty'];
$c_staff   = $dirByType['staff']   + $usrByType['staff'];

// The two offices, counted the way adminUnitForUser() reads them.
$unitOf = static function (string $dept): string {
    $d = strtoupper($dept);
    return strpos($d, 'ITSO') !== false ? 'itso' : (strpos($d, 'PMO') !== false ? 'pmo' : '');
};
$c_pmoUnit = 0; $c_itsoUnit = 0; $c_noUnit = 0;
foreach ($all_users_raw as $u) {
    if (($u['role'] ?? '') === 'reporter') { continue; }
    $unit = $unitOf((string)($u['department'] ?? ''));
    if ($unit === 'pmo')  { $c_pmoUnit++; }
    elseif ($unit === 'itso') { $c_itsoUnit++; }
    else { $c_noUnit++; }   // staff account tagged to neither office
}

$c_admin  = cntU($all_users_raw, fn($u)=>$u['role']==='admin');
$c_tech   = cntU($all_users_raw, fn($u)=>$u['role']==='technician');
$c_rep    = cntU($all_users_raw, fn($u)=>$u['role']==='reporter') + $dirTotal;
$c_total  = count($all_users_raw) + $dirTotal;

// How many people match the filters in total, against how many are on screen.
// Both halves are counted in the database now, so this no longer depends on how
// many rows happened to be rendered.
$matchTotal = $usersTotal + $dirMatchTotal;
$totalPages = max(1, (int) ceil($matchTotal / $perPage));
// A page number past the end shows nothing; say so rather than render a blank table.
$pageOverrun = $page > $totalPages && $matchTotal > 0;
// Guarded on the row count, not the offset: a page past the end has an offset
// but no rows, and would otherwise print a backwards range like "99,801–99,800".
$rowFrom    = count($users) > 0 ? $offset + 1 : 0;
$rowTo      = count($users) > 0 ? $offset + count($users) : 0;
$isCapped   = $totalPages > 1;
$filtersOn  = ($rf !== 'all' || $tf !== 'all' || $df !== 'all' || $uf !== 'all' || $yl !== 'all' || $sq !== '');
/* ─── HELPERS ───────────────────────────────────────── */
function roleCls($r){return['admin'=>'r-admin','pmo'=>'r-pmo','technician'=>'r-tech','reporter'=>'r-rep','student'=>'r-stud'][$r]??'r-rep';}
// Who the reporter is at the college, shown beside the role badge. Reporters
// come from every corner of BEC, and the PMO reads a report differently when it
// comes from a teacher than from a first-year student.
function typeBadge($u){
    $role = (string)($u['role'] ?? '');
    // An administrator's standing is which office they sit in — that, not the
    // role, is what tells a PMO administrator from an ITSO one, and it is the
    // same reading adminUnitForUser() makes of the department.
    // For staff accounts this badge was derived from the department and printed
    // PMO or ITSO — the very thing the Department column shows in the next cell.
    // Every administrator row therefore read "ADMIN  PMO | PMO". The unit is the
    // department's job; Standing states the role and nothing else.
    if (in_array($role, ['admin','pmo','technician'], true)) {
        return '';
    } else {
    $t = strtolower(trim((string)($u['user_type'] ?? '')));
    if ($t === '' || $role !== 'reporter') { return ''; }
    $meta = [
        'student' => ['Student', 'fas fa-graduation-cap', '#0891B2'],
        'faculty' => ['Faculty', 'fas fa-chalkboard-user', '#7C3AED'],
        'staff'   => ['Staff',   'fas fa-user-tie',        '#B45309'],
    ][$t] ?? [ucfirst($t), 'fas fa-user', '#6B7280'];
    }
    return '<span class="bdg" style="background:' . $meta[2] . '1A;color:' . $meta[2] . ';font-size:.6rem;margin-left:.25rem;">'
         . '<i class="' . $meta[1] . '" style="font-size:.58rem;margin-right:.18rem;"></i>' . htmlspecialchars($meta[0], ENT_QUOTES) . '</span>';
}
function roleIco($r){return['admin'=>'fas fa-crown','pmo'=>'fas fa-building','technician'=>'fas fa-hard-hat','reporter'=>'fas fa-bullhorn','student'=>'fas fa-graduation-cap'][$r]??'fas fa-user';}
function roleLbl($r){return ucfirst($r??'—');}
function initials($n){$p=array_filter(explode(' ',$n??''));return strtoupper(implode('',array_map(fn($x)=>substr($x,0,1),array_slice($p,0,2))));}
function avatarColor($role){return['admin'=>'linear-gradient(135deg,#7B1D1D,#C53030)','pmo'=>'linear-gradient(135deg,#92400E,#F59E0B)','technician'=>'linear-gradient(135deg,#1D4ED8,#60A5FA)','reporter'=>'linear-gradient(135deg,#7C3AED,#A78BFA)','student'=>'linear-gradient(135deg,#0891B2,#22D3EE)'][$role]??'linear-gradient(135deg,#6B7280,#9CA3AF)';}
function deptCls($d){
    $d=strtolower($d??'');
    if(str_contains($d,'itso')||str_contains($d,'computer')||str_contains($d,'it ')|| $d==='it')return'itso';
    if(str_contains($d,'pmo')||str_contains($d,'physical')||str_contains($d,'maintenance')||str_contains($d,'facilities'))return'pmo';
    return'gen';
}
function esc($s){return htmlspecialchars((string)($s??''),ENT_QUOTES,'UTF-8');}

/**
 * The only fields the profile and edit dialogs actually read.
 *
 * Rows come back as SELECT u.*, and the whole record was being JSON-encoded
 * into the markup five times per person — once on the row and twice on each of
 * the table and grid buttons. That is what made the page enormous, but the
 * bigger problem is what was in it: `users` also carries the password hash and
 * the confirmation, recovery, invite and reauthentication tokens, so every one
 * of them was sitting in the page source. Nothing here needs them.
 */
function cardPayload(array $u): string {
    /* Student and employee numbers are deliberately not carried here. The
       profile does not show them, so putting them in the page source would be
       publishing an identifier for every person on the roster for nothing.
       (The roster export still has its own columns — that is a file an admin
       asks for, not markup shipped to every page load.) */
    $keep = ['user_id','fullname','email','role','user_type','department','program','course',
             'phone','contact_number','year_level',
             'specialization','position','status','created_at','report_count','active_tasks','is_directory'];
    $out = [];
    foreach ($keep as $k) {
        if (!array_key_exists($k, $u)) { continue; }
        $v = $u[$k];
        if ($v === null || $v === '') { continue; }   // absent fields are simply omitted
        $out[$k] = $v;
    }
    return htmlspecialchars(json_encode($out, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>User Management — BEC Admin</title>
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="css/typography.css">
<!-- SheetJS removed: the Excel export is built server-side by
     includes/xlsx_writer.php, so this 861 KB bundle no longer loads. -->
<link rel="stylesheet" href="assets/css/admin-shell.css">
<style>

/* ═══════════════════════════════════════════════════════
   BEC Admin — User Management  |  Maroon × Gold × Warm
   Outfit (headings) · DM Sans (body)
═══════════════════════════════════════════════════════ */
/* One type scale and one space scale, shared with the defect record and the
   BEC directory so the admin pages agree rather than each being internally
   consistent in its own dialect. Steps only — nothing between them. */
:root{--fs-xs:.6rem;--fs-sm:.68rem;--fs-base:.76rem;--fs-md:.82rem;--fs-lg:.88rem;
  --sp-0:.125rem;--sp-1:.25rem;--sp-2:.5rem;--sp-3:.75rem;--sp-4:1rem;--sp-5:1.5rem;
  
  --m1:#2D0505;--m2:#4A0E0E;--m3:#7B1D1D;--m4:#9B2C2C;
  --g1:#92600A;--g2:#D4A017;--g3:#F0C040;--gp:#FEF9E7;
  --bg:#F4EFE6;--s1:#FFFFFF;--s2:#FAF7F0;--s3:#F2EAD9;
  --bdr:#E5D9C6;--bdr2:#D0C0A8;
  --t1:#1A0808;--t2:#5C3838;--t3:#9C7A7A;--t4:#C8ABAB;
  --sh0:0 1px 2px rgba(45,5,5,.05);
  --sh1:0 2px 8px rgba(45,5,5,.07),0 1px 3px rgba(45,5,5,.04);
  --sh2:0 6px 20px rgba(45,5,5,.09),0 2px 6px rgba(45,5,5,.05);
  --sh3:0 14px 40px rgba(45,5,5,.13),0 4px 10px rgba(45,5,5,.07);
  --r1:8px;--r2:12px;--r3:18px;--r4:26px;--sb:262px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--t1);
  min-height:100vh;overflow-x:hidden;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='400'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.022'/%3E%3C/svg%3E");}

/* ── SIDEBAR ─────────────────────────────────────── */
/* sidebar styling lives in assets/css/admin-shell.css */
.lout i{transition:transform .3s;}.lout:hover i{transform:rotate(180deg);}

/* ── LAYOUT ──────────────────────────────────────── */
.wrap{margin-left:var(--sb);min-height:100vh;display:flex;flex-direction:column;}
.topbar{background:rgba(255,252,245,.93);backdrop-filter:blur(14px);
  border-bottom:1px solid var(--bdr);height:58px;padding:0 1.75rem;
  display:flex;align-items:center;justify-content:space-between;
  position:sticky;top:0;z-index:200;box-shadow:var(--sh0);}
.tb-l{display:flex;align-items:center;gap:var(--sp-2);}
.mob-tog{display:none;background:none;border:none;font-size:1.1rem;cursor:pointer;color:var(--t2);}
.pg-title{font-family:'Outfit',sans-serif;font-weight:700;font-size:1rem;color:var(--t1);}
.bc{font-size:var(--fs-sm);color:var(--t3);display:flex;align-items:center;gap:var(--sp-1);}
.bc a{color:var(--t3);text-decoration:none;}.bc a:hover{color:var(--m3);}
.bc i{font-size:var(--fs-xs);}
.tb-r{display:flex;align-items:center;gap:var(--sp-2);}
.ic-btn{width:34px;height:34px;background:var(--s2);border:1px solid var(--bdr);border-radius:var(--r1);
  display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--t2);font-size:var(--fs-md);
  transition:all .17s;text-decoration:none;position:relative;box-shadow:none;}
.ic-btn:hover{background:var(--m3);color:#fff;transform:none;box-shadow:none;}
.pip{position:absolute;top:5px;right:5px;width:7px;height:7px;background:var(--g2);border-radius:50%;
  border:2px solid var(--s1);animation:pp 2.2s ease-in-out infinite;}
@keyframes pp{0%,100%{transform:scale(1);}50%{transform:scale(1.4);}}
.pg{padding:var(--sp-5) 1.75rem;flex:1;}

/* ── FLASH ───────────────────────────────────────── */
.flash{display:flex;align-items:center;gap:var(--sp-3);padding:var(--sp-3) var(--sp-4);border-radius:var(--r2);
  margin-bottom:var(--sp-4);font-size:var(--fs-md);font-weight:600;animation:fIn .25s ease;border-left:3px solid;}
@keyframes fIn{from{opacity:0;transform:translateY(-5px);}to{opacity:1;transform:translateY(0);}}
.flash.ok{background:#F0FDF4;color:#15803D;border-color:#22C55E;}
.flash.err{background:#FFF1F2;color:#DC2626;border-color:#EF4444;}

/* ── PAGE HEADER ─────────────────────────────────── */
.ph{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:var(--sp-5);gap:var(--sp-4);flex-wrap:wrap;}
.ph h1{font-family:'Outfit',sans-serif;font-size:1.45rem;font-weight:800;display:flex;align-items:center;gap:var(--sp-2);}
.ph h1 i{color:var(--m3);}
/* .76rem: one page-subtitle size across the admin pages, matching .head p in
   admin-shell.css and .ph-sub everywhere else. */
.ph-sub{font-size:var(--fs-base);color:var(--t3);margin-top:var(--sp-0);}

/* Two patterns that were repeated inline attributes. Both carried sizes the
   scale could not reach — a .72rem hint and a .3rem icon gap — because a scale
   only governs the stylesheet, and an attribute is not in it. As classes they
   join the scale and are stated once instead of five times. */
/* Fixed layout is what makes the colgroup binding rather than advisory: with
   auto layout the browser re-measures from content and the declared widths are
   only a suggestion it is free to ignore. */
.u-fixed{table-layout:fixed;}
.u-fixed tbody td{overflow:hidden;text-overflow:ellipsis;}
/* A header too long for its column should shorten, not print over its
   neighbour — the headers are nowrap and fixed layout will not widen for them. */
.u-fixed thead th{overflow:hidden;text-overflow:ellipsis;}
/* The empty-state row spans every column and must not be ellipsed to nothing. */
.u-fixed tbody td[colspan]{overflow:visible;text-overflow:clip;}

.mh-ic{margin-right:var(--sp-1);opacity:.8;}
.fhint{font-size:var(--fs-sm);color:var(--t3,#9A7A7A);margin:var(--sp-2) 0 0;line-height:1.5;}
.fhint[hidden]{display:none;}

/* ── BUTTONS ─────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:var(--sp-1);padding:var(--sp-2) var(--sp-4);
  border-radius:var(--r1);font-family:'DM Sans',sans-serif;font-size:var(--fs-base);font-weight:700;
  cursor:pointer;border:none;transition:all .17s;text-decoration:none;white-space:nowrap;}
.btn:hover{transform:none;}.btn:active{transform:translateY(0);}
.btn-maroon{background:linear-gradient(135deg,var(--m3),var(--m4));color:#fff;box-shadow:none;}
.btn-maroon:hover{box-shadow:none;}
.btn-gold{background:linear-gradient(135deg,var(--g2),var(--g3));color:var(--m1);box-shadow:none;}
.btn-gold:hover{box-shadow:none;}
.btn-green{background:linear-gradient(135deg,#15803D,#22C55E);color:#fff;box-shadow:none;}
.btn-green:hover{box-shadow:none;}
.btn-red{background:linear-gradient(135deg,#B91C1C,#EF4444);color:#fff;box-shadow:none;}
.btn-red:hover{box-shadow:none;}
.btn-amber{background:linear-gradient(135deg,#D97706,#FBBF24);color:#fff;box-shadow:none;}
.btn-amber:hover{box-shadow:none;}
.btn-ghost{background:var(--s2);color:var(--t2);border:1px solid var(--bdr);}
.btn-ghost:hover{background:var(--s3);}
.btn-sm{padding:var(--sp-1) var(--sp-3);font-size:var(--fs-sm);}
.bico{width:26px;height:26px;padding:0;display:flex;align-items:center;justify-content:center;border-radius:var(--r1);font-size:var(--fs-sm);}
.bi-v{background:#EFF6FF;color:#1D4ED8;}.bi-v:hover{background:#DBEAFE;}
.bi-e{background:#FFFBEB;color:#D97706;}.bi-e:hover{background:#FEF3C7;}
.bi-k{background:#F0FDF4;color:#15803D;}.bi-k:hover{background:#DCFCE7;}
.bi-d{background:#FFF1F2;color:#BE123C;}.bi-d:hover{background:#FFE4E6;}

/* ── SUMMARY CARDS ───────────────────────────────── */
.sums{display:grid;grid-template-columns:repeat(6,1fr);gap:var(--sp-3);margin-bottom:var(--sp-5);}
.scard{background:var(--s1);border-radius:var(--r3);padding:var(--sp-4) var(--sp-4);
  border:1px solid var(--bdr);position:relative;overflow:hidden;
  transition:all .26s cubic-bezier(.4,0,.2,1);box-shadow:var(--sh0);cursor:pointer;text-decoration:none;display:block;}
.scard::before{content:'';position:absolute;top:-16px;right:-16px;width:66px;height:66px;border-radius:50%;
  background:var(--sk);opacity:.04;transition:all .28s;}
.scard::after{content:'';position:absolute;bottom:0;left:0;width:100%;height:3px;background:var(--sk);
  border-radius:0 0 var(--r3) var(--r3);transform:scaleX(0);transform-origin:left;transition:transform .32s;}
.scard:hover{transform:none;box-shadow:var(--sh3);border-color:transparent;}
.scard:hover::before{transform:none;opacity:.08;}
.scard:hover::after{transform:scaleX(1);}
.sc-a{--sk:var(--m3);--skl:rgba(123,29,29,.14);}
.sc-b{--sk:#C2410C;--skl:rgba(194,65,12,.14);}
.sc-c{--sk:#2563EB;--skl:rgba(37,99,235,.14);}
.sc-d{--sk:#7C3AED;--skl:rgba(124,58,237,.14);}
.sc-e{--sk:#16A34A;--skl:rgba(22,163,74,.14);}
.sc-f{--sk:#DC2626;--skl:rgba(220,38,38,.14);}
.sico{width:36px;height:36px;border-radius:var(--r2);display:flex;align-items:center;justify-content:center;
  font-size:var(--fs-md);margin-bottom:var(--sp-2);background:var(--sib);color:var(--sic);
  box-shadow:none;transition:all .26s;position:relative;z-index:1;}
.scard:hover .sico{transform:none;}
.sc-a .sico{--sib:#FDECEA;--sic:var(--m3);}
.sc-b .sico{--sib:#FFF7ED;--sic:#C2410C;}
.sc-c .sico{--sib:#EFF6FF;--sic:#2563EB;}
.sc-d .sico{--sib:#F5F3FF;--sic:#7C3AED;}
.sc-e .sico{--sib:#F0FDF4;--sic:#16A34A;}
.sc-f .sico{--sib:#FFF1F2;--sic:#DC2626;}
.snum{font-family:'Outfit',sans-serif;font-size:1.9rem;font-weight:800;color:var(--t1);line-height:1;
  margin-bottom:var(--sp-0);position:relative;z-index:1;transition:color .26s;}
.scard:hover .snum{color:var(--sk);}
.slbl{font-size:var(--fs-xs);text-transform:uppercase;letter-spacing:.7px;color:var(--t3);font-weight:700;position:relative;z-index:1;}
.scard{animation:scIn .3s ease both;}
.scard:nth-child(1){animation-delay:.05s;}.scard:nth-child(2){animation-delay:.09s;}
.scard:nth-child(3){animation-delay:.13s;}.scard:nth-child(4){animation-delay:.17s;}
.scard:nth-child(5){animation-delay:.21s;}.scard:nth-child(6){animation-delay:.25s;}
@keyframes scIn{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}

/* ── FILTER / ROLE TABS ──────────────────────────── */
.rtabs{display:flex;gap:var(--sp-1);flex-wrap:wrap;margin-bottom:var(--sp-4);}
.rtab-n{margin-left:var(--sp-2);padding:var(--sp-0) var(--sp-1);border-radius:20px;font-size:var(--fs-xs);
  font-weight:800;background:rgba(0,0,0,.07);color:inherit;}
.rtab.on .rtab-n{background:rgba(255,255,255,.24);}
/* the two admin units, set apart from the role tabs beside them */
.tab-div{width:1px;align-self:stretch;background:var(--bdr);margin:var(--sp-0) var(--sp-1);}
.rtab.u-pmo.on{background:#C2410C;border-color:#9A3412;color:#fff;}
.rtab.u-itso.on{background:#2563EB;border-color:#1D4ED8;color:#fff;}
.rtab{min-height:1.95rem;}
/* ── PAGER ───────────────────────────────────────── */
.pager{display:flex;align-items:center;justify-content:space-between;gap:var(--sp-4);
  flex-wrap:wrap;margin-top:var(--sp-4);padding:var(--sp-3) var(--sp-4);background:var(--s1);
  border:1px solid var(--bdr);border-radius:var(--r2);box-shadow:var(--sh0);}
.pager-count{font-size:var(--fs-sm);color:var(--t3);white-space:nowrap;}
.pager-count strong{color:var(--t2);}
.pager-btns{display:inline-flex;align-items:center;gap:var(--sp-1);flex-wrap:wrap;}
.pgb{display:inline-flex;align-items:center;gap:var(--sp-1);min-width:2rem;justify-content:center;
  padding:var(--sp-1) var(--sp-3);border-radius:var(--r1);border:1.5px solid var(--bdr);
  background:var(--s1);color:var(--t2);font-family:'DM Sans',sans-serif;
  font-size:var(--fs-sm);font-weight:700;text-decoration:none;transition:all .17s;}
.pgb:hover{border-color:var(--m3);color:var(--m3);transform:none;}
.pgb.on{background:var(--m3);border-color:var(--m2);color:#fff;cursor:default;}
.pgb.on:hover{transform:none;color:#fff;}
.pgb.off{opacity:.4;cursor:not-allowed;}
.pgb.off:hover{border-color:var(--bdr);color:var(--t2);transform:none;}
.pgb i{font-size:var(--fs-xs);}
.pg-gap{color:var(--t4);font-size:var(--fs-sm);padding:0 var(--sp-0);}
.capnote{display:flex;align-items:flex-start;gap:var(--sp-2);margin-bottom:var(--sp-4);
  padding:var(--sp-2) var(--sp-3);border-radius:var(--r1);font-size:var(--fs-base);line-height:1.55;
  background:#FFFBEF;border:1px solid rgba(201,150,12,.32);border-left:3px solid var(--g1,#C9960C);color:var(--t2);}
.capnote i{color:#C9960C;margin-top:var(--sp-0);flex-shrink:0;}
.rtab{display:inline-flex;align-items:center;gap:var(--sp-1);padding:var(--sp-1) var(--sp-3);border-radius:20px;
  font-size:var(--fs-sm);font-weight:700;cursor:pointer;text-decoration:none;border:1.5px solid transparent;
  transition:all .17s;background:var(--s1);color:var(--t2);border-color:var(--bdr);}
.rtab:hover{transform:none;}
.rtab.on{background:var(--m3);color:#fff;border-color:var(--m2);}
.rtab-admin.on{background:linear-gradient(135deg,var(--m3),var(--m4));border-color:var(--m2);}
.rtab-tech.on{background:linear-gradient(135deg,#1D4ED8,#60A5FA);border-color:#1E40AF;}
.rtab-rep.on{background:linear-gradient(135deg,#7C3AED,#A78BFA);border-color:#6D28D9;}
.rtab-stud.on{background:linear-gradient(135deg,#0891B2,#22D3EE);border-color:#0E7490;}
/* One control strip. Every child is --fh tall so the search field, the three
   dropdowns and the buttons share a single baseline instead of each being
   sized by its own text — that was what made the old bar look ragged. */
.fbar{--fh:2.2rem;background:var(--s1);border:1px solid var(--bdr);border-radius:var(--r3);
  padding:var(--sp-3) var(--sp-3);margin-bottom:var(--sp-4);display:flex;gap:var(--sp-2);align-items:center;
  flex-wrap:wrap;row-gap:var(--sp-2);box-shadow:var(--sh0);}
.fbar>*{height:var(--fh);}
.fsw{position:relative;flex:1 1 15rem;min-width:11rem;}
.fsw i{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);color:var(--t3);font-size:var(--fs-sm);pointer-events:none;}
.fsi{width:100%;height:100%;padding:0 var(--sp-3) 0 1.9rem;background:var(--s2);border:1.5px solid var(--bdr);
  border-radius:var(--r1);font-size:var(--fs-md);color:var(--t1);font-family:'DM Sans',sans-serif;outline:none;transition:border-color .18s;}
.fsi:focus{border-color:var(--m3);box-shadow:0 0 0 3px rgba(123,29,29,.07);}
/* The native arrow is drawn at a size the browser picks and sits hard against
   the edge; appearance:none plus one inline chevron keeps the three dropdowns
   identical to each other and to the field beside them. */
.fsel{max-width:13.5rem;padding:0 1.85rem 0 var(--sp-3);background:var(--s2);border:1.5px solid var(--bdr);
  border-radius:var(--r1);font-size:var(--fs-md);color:var(--t2);font-family:'DM Sans',sans-serif;
  font-weight:600;outline:none;cursor:pointer;transition:border-color .18s;
  -webkit-appearance:none;appearance:none;text-overflow:ellipsis;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%237B1D1D' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right .65rem center;background-size:.6rem;}
.fsel:hover{border-color:var(--m3);}
.fsel:focus{border-color:var(--m3);box-shadow:0 0 0 3px rgba(123,29,29,.07);}
.fgo{display:inline-flex;align-items:center;justify-content:center;min-width:2.4rem;
  background:var(--m3);color:#fff;border:1.5px solid var(--m2);border-radius:var(--r1);
  cursor:pointer;font-size:var(--fs-md);transition:background .17s,transform .17s;}
.fgo:hover{background:var(--m2);transform:none;}
.fclr{display:inline-flex;align-items:center;gap:var(--sp-1);padding:0 var(--sp-3);border:1.5px solid var(--bdr);
  border-radius:var(--r1);background:var(--s1);color:var(--t3);font-size:var(--fs-base);font-weight:700;
  text-decoration:none;transition:all .17s;}
.fclr:hover{color:var(--m3);border-color:var(--m3);}
/* View toggle and the match count keep to the far end of the strip. */
.fbar-r{margin-left:auto;display:inline-flex;align-items:center;gap:var(--sp-3);}
.fbar-r .vt{height:100%;}
.fcount{font-size:var(--fs-sm);color:var(--t3);white-space:nowrap;}
.fcount strong{color:var(--t2);}

/* ── USER TABLE / CARDS ──────────────────────────── */
.panel{background:#FFFFFF;border-radius:var(--r3);border:1px solid #E5D9C6;box-shadow:var(--sh1);overflow:hidden;transition:box-shadow .22s;}
.panel:hover{box-shadow:var(--sh2);}
.ph3{padding:var(--sp-4) var(--sp-5);border-bottom:1px solid #E5D9C6;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(to right,#FAF7F0,#FFFFFF);}
.ph3 h3{font-family:'Outfit',sans-serif;font-size:var(--fs-lg);font-weight:700;color:var(--t1);
  display:flex;align-items:center;gap:var(--sp-1);margin:0;}
.ph3 h3 i{color:var(--m3);}

/* View toggle */
.vt{display:flex;background:var(--s2);border:1.5px solid var(--bdr);border-radius:var(--r1);padding:2px;gap:2px;}
.vt-b{display:flex;align-items:center;gap:var(--sp-1);padding:var(--sp-1) var(--sp-2);border-radius:6px;
  font-size:var(--fs-sm);font-weight:700;cursor:pointer;background:none;border:none;
  color:var(--t3);font-family:'DM Sans',sans-serif;transition:all .18s;}
.vt-b.on{background:var(--s1);color:var(--m3);box-shadow:var(--sh0);}
.vt-b:not(.on):hover{color:var(--t2);}

/* Table */
/* Table type, header treatment, cell padding and row hover all come from
   .tbl in assets/css/admin-shell.css so this table and the BEC Directory are
   the same table. Only column behaviour specific to this page stays below. */
.tblwrap{overflow-x:auto;}
/* Column alignment, set once. Counts and dates centre under their headings and
   never wrap; only the name, email and department columns are free to reflow,
   which is what keeps a 100-row page reading as columns rather than as prose. */
.tbl th.c,.tbl td.c{text-align:center;}
.tbl th.nw,.tbl td.nw{white-space:nowrap;}
/* No break rule here on purpose. Letting the address break mid-word lets the
   auto table layout collapse this column to a few characters wide once the
   columns beside it stop wrapping, and the email prints one letter per line. */
.tbl tbody tr{transition:background .1s,transform .1s;}
.tbl tbody tr.urow{cursor:pointer;}
.tbl tbody tr:hover{transform:none;}

/* User avatar in table */
.tav{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-family:'Outfit',sans-serif;font-weight:800;font-size:var(--fs-base);color:#fff;
  flex-shrink:0;box-shadow:none;transition:transform .22s;}
.tbl tbody tr:hover .tav{transform:none;}
/* Every row the same height. The name wrapped onto a second line for anyone
   called "Danalyn R. Sabile" and the department badge wrapped for "College of
   Business", so rows ran to two and three lines at random down the page. Each
   is held to one line and ellipsised; the full value is on the row's own
   detail view, and the name column is wide enough that truncation is rare. */
.tbl tbody tr{height:3.4rem;}
.tuser{display:flex;align-items:center;gap:var(--sp-3);min-width:0;}
.tuser > div{min-width:0;}
.tname{font-weight:700;font-size:var(--fs-md);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.tuid{font-size:var(--fs-sm);color:var(--t3);font-family:'Outfit',sans-serif;font-weight:700;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.tbl td.temail{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:15rem;}
.tbl td .dept-pmo,.tbl td .dept-itso,.tbl td .dept-gen{white-space:nowrap;max-width:100%;overflow:hidden;}

/* Badges */
.bdg{display:inline-flex;align-items:center;gap:var(--sp-1);padding:var(--sp-1) var(--sp-2);border-radius:20px;
  font-size:var(--fs-xs);font-weight:800;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;}
.bdg::before{content:'';width:4px;height:4px;border-radius:50%;background:currentColor;
  flex-shrink:0;animation:dot 2.2s ease-in-out infinite;}
@keyframes dot{0%,100%{opacity:1;}50%{opacity:.4;}}
.r-admin{background:#FDECEA;color:var(--m3);}
.r-pmo{background:#FFF7ED;color:#C2410C;}
.r-dean{background:#ECFEFF;color:#0F766E;}
.r-fin{background:#F0FDF4;color:#166534;}
.r-tech{background:#EFF6FF;color:#1D4ED8;}
.r-rep{background:#F5F3FF;color:#7C3AED;}
.r-stud{background:#ECFEFF;color:#0891B2;}
.s-act{background:#F0FDF4;color:#15803D;}
.s-inact{background:#FFF1F2;color:#DC2626;}
.dept-itso{display:inline-flex;align-items:center;gap:var(--sp-1);padding:var(--sp-0) var(--sp-2);border-radius:20px;
  font-size:var(--fs-xs);font-weight:800;background:#ECFEFF;color:#0891B2;border:1px solid #A5F3FC;}
.dept-pmo{display:inline-flex;align-items:center;gap:var(--sp-1);padding:var(--sp-0) var(--sp-2);border-radius:20px;
  font-size:var(--fs-xs);font-weight:800;background:#F5F3FF;color:#7C3AED;border:1px solid #DDD6FE;}
.dept-gen{display:inline-flex;align-items:center;gap:var(--sp-1);padding:var(--sp-0) var(--sp-2);border-radius:20px;
  font-size:var(--fs-xs);font-weight:700;background:var(--s2);color:var(--t2);border:1px solid var(--bdr);}

/* ── GRID VIEW ───────────────────────────────────── */
.ugrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:var(--sp-4);padding:var(--sp-4);}
.ucard{background:var(--s1);border-radius:var(--r3);border:1.5px solid var(--bdr);
  padding:var(--sp-5) var(--sp-4) var(--sp-4);position:relative;overflow:hidden;
  transition:all .24s cubic-bezier(.4,0,.2,1);box-shadow:var(--sh0);}
.ucard::before{content:'';position:absolute;top:-20px;right:-20px;width:80px;height:80px;
  border-radius:50%;background:var(--ucol,var(--m3));opacity:.04;transition:all .28s;}
.ucard::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;
  background:var(--ucol,var(--m3));border-radius:0 0 var(--r3) var(--r3);
  transform:scaleX(0);transform-origin:left;transition:transform .3s;}
.ucard:hover{transform:none;box-shadow:var(--sh3);border-color:transparent;}
.ucard:hover::before{transform:none;opacity:.08;}
.ucard:hover::after{transform:scaleX(1);}
.ucard.role-admin{--ucol:var(--m3);--ucol-s:rgba(123,29,29,.12);}
.ucard.role-pmo{--ucol:#C2410C;--ucol-s:rgba(194,65,12,.12);}
.ucard.role-dean{--ucol:#0F766E;--ucol-s:rgba(15,118,110,.12);}
.ucard.role-finance{--ucol:#166534;--ucol-s:rgba(22,101,52,.12);}
.ucard.role-technician{--ucol:#2563EB;--ucol-s:rgba(37,99,235,.12);}
.ucard.role-reporter{--ucol:#7C3AED;--ucol-s:rgba(124,58,237,.12);}
.ucard.role-student{--ucol:#0891B2;--ucol-s:rgba(8,145,178,.12);}
.ucard.inactive{opacity:.6;filter:grayscale(.3);}
.uc-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:var(--sp-4);}
.uc-av{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-family:'Outfit',sans-serif;font-weight:900;font-size:1.1rem;color:#fff;
  box-shadow:none;transition:transform .25s;flex-shrink:0;position:relative;z-index:1;}
.ucard:hover .uc-av{transform:none;}
.uc-status{flex-shrink:0;}
.uc-name{font-family:'Outfit',sans-serif;font-size:.95rem;font-weight:800;color:var(--t1);
  line-height:1.2;margin-bottom:var(--sp-0);position:relative;z-index:1;}
.uc-id{font-size:var(--fs-xs);color:var(--t3);font-family:'Outfit',sans-serif;font-weight:700;margin-bottom:var(--sp-2);}
.uc-email{font-size:var(--fs-sm);color:var(--t2);margin-bottom:var(--sp-2);display:flex;align-items:center;gap:var(--sp-1);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.uc-meta{display:flex;gap:var(--sp-1);flex-wrap:wrap;margin-bottom:var(--sp-3);}
.uc-stats{display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-2);margin-bottom:var(--sp-4);}
.uc-stat{background:var(--s2);border-radius:var(--r1);padding:var(--sp-2) var(--sp-2);text-align:center;}
.uc-stat-n{font-family:'Outfit',sans-serif;font-size:1.1rem;font-weight:800;color:var(--t1);}
.uc-stat-l{font-size:var(--fs-xs);text-transform:uppercase;letter-spacing:.6px;color:var(--t3);font-weight:700;}
.uc-acts{display:flex;gap:var(--sp-1);flex-wrap:wrap;}
.ucard{animation:ucIn .3s ease both;}
@keyframes ucIn{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}

/* ── MODAL ───────────────────────────────────────── */
.mo{position:fixed;inset:0;background:rgba(26,8,8,.6);backdrop-filter:blur(7px);
  z-index:500;display:none;align-items:flex-start;justify-content:center;
  padding:var(--sp-5) var(--sp-4);overflow-y:auto;}
.mo.open{display:flex;animation:moFade .18s ease;}
@keyframes moFade{from{opacity:0}to{opacity:1}}
.mw{background:var(--s1);border-radius:var(--r4);width:100%;max-width:560px;
  box-shadow:var(--sh3);animation:mUp .28s cubic-bezier(.4,0,.2,1);border:1px solid var(--bdr);margin:auto;}
@keyframes mUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.mhd{padding:var(--sp-5) var(--sp-5) var(--sp-4);
  background:linear-gradient(120deg,var(--m1) 0%,#3D0A0A 45%,var(--m3) 100%);
  border-radius:var(--r4) var(--r4) 0 0;display:flex;justify-content:space-between;
  align-items:flex-start;position:relative;overflow:hidden;}
.mhd::after{content:'';position:absolute;right:-10px;top:-10px;width:100px;height:100px;border-radius:50%;
  background:rgba(212,160,23,.08);pointer-events:none;animation:sealSpin 18s linear infinite;}
.mhd-t{position:relative;z-index:1;}
.mhd-t h2{font-family:'Outfit',sans-serif;font-size:1.05rem;font-weight:800;color:#fff;}
.mhd-t p{font-size:var(--fs-sm);color:rgba(255,255,255,.42);margin-top:var(--sp-0);}
.mx{width:27px;height:27px;background:rgba(255,255,255,.1);border:none;border-radius:50%;
  color:rgba(255,255,255,.6);font-size:var(--fs-md);cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;transition:all .18s;position:relative;z-index:1;}
.mx:hover{background:rgba(255,255,255,.22);color:#fff;transform:rotate(90deg);}
.mb{padding:var(--sp-5) var(--sp-5);}
.mf{padding:var(--sp-3) var(--sp-5) var(--sp-5);border-top:1px solid var(--bdr);
  display:flex;justify-content:flex-end;gap:var(--sp-2);flex-wrap:wrap;
  background:var(--s2);border-radius:0 0 var(--r4) var(--r4);}

/* Profile modal header */
.prof-head{display:flex;align-items:center;gap:var(--sp-4);margin-bottom:var(--sp-5);
  padding-bottom:var(--sp-4);border-bottom:1.5px solid var(--bdr);}
.prof-av{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-family:'Outfit',sans-serif;font-weight:900;font-size:1.4rem;color:#fff;
  flex-shrink:0;box-shadow:none;}
.prof-name{font-family:'Outfit',sans-serif;font-size:1.05rem;font-weight:800;}
.prof-id{font-size:var(--fs-sm);color:var(--t3);font-family:'Outfit',sans-serif;font-weight:700;margin-top:var(--sp-0);}

/* Form */
.fg{display:flex;flex-direction:column;gap:var(--sp-1);margin-bottom:var(--sp-3);}
.fl{font-size:var(--fs-xs);font-weight:800;text-transform:uppercase;letter-spacing:.65px;color:var(--t2);}
.fl span{color:var(--m3);}
.fc{padding:var(--sp-2) var(--sp-3);background:var(--s2);border:1.5px solid var(--bdr);border-radius:var(--r1);
  font-size:var(--fs-md);color:var(--t1);font-family:'DM Sans',sans-serif;outline:none;transition:all .18s;}
.fc:focus{border-color:var(--m3);background:var(--s1);box-shadow:0 0 0 3px rgba(123,29,29,.07);}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-3);}
.pass-wrap{position:relative;}
.pass-wrap .fc{padding-right:2.4rem;}
.pass-toggle{position:absolute;right:.7rem;top:50%;transform:translateY(-50%);
  background:none;border:none;color:var(--t3);cursor:pointer;font-size:var(--fs-md);padding:var(--sp-0);}
.pass-toggle:hover{color:var(--t1);}
.strength-bar{height:3px;background:var(--s3);border-radius:3px;margin-top:var(--sp-1);overflow:hidden;}
.strength-fill{height:100%;border-radius:3px;transition:width .3s,background .3s;}

/* Detail rows */
.dr{display:flex;gap:var(--sp-3);padding:var(--sp-2) 0;border-bottom:1px solid var(--bdr);align-items:flex-start;}
.dr:last-child{border:none;}
.dk{width:110px;flex-shrink:0;font-size:var(--fs-xs);font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--t3);padding-top:var(--sp-0);}
.dv{font-size:var(--fs-md);color:var(--t1);flex:1;line-height:1.5;}

/* Danger zone */
.danger-zone{background:#FFF1F2;border:1.5px solid #FECDD3;border-radius:var(--r2);
  padding:var(--sp-4) var(--sp-4);margin-top:var(--sp-4);}
.dz-title{font-size:var(--fs-sm);font-weight:800;text-transform:uppercase;letter-spacing:.7px;
  color:#DC2626;margin-bottom:var(--sp-3);display:flex;align-items:center;gap:var(--sp-1);}
.dz-row{display:flex;align-items:center;justify-content:space-between;gap:var(--sp-3);
  padding:var(--sp-2) 0;border-bottom:1px solid #FECDD3;}
.dz-row:last-child{border:none;padding-bottom:0;}
.dz-desc{font-size:var(--fs-base);color:var(--t2);line-height:1.4;}
.dz-desc strong{display:block;font-size:var(--fs-md);color:var(--t1);margin-bottom:var(--sp-0);}

/* ── EXPORT MENU ─────────────────────────────────── */
.exp-drop{position:relative;}
#expMenu{display:none;position:absolute;right:0;top:calc(100% + 6px);
  background:var(--s1);border:1.5px solid var(--bdr);border-radius:var(--r2);
  box-shadow:var(--sh3);z-index:300;min-width:145px;overflow:hidden;}
.exp-opt{width:100%;padding:var(--sp-2) var(--sp-4);background:none;border:none;text-align:left;
  font-size:var(--fs-base);font-family:'DM Sans',sans-serif;cursor:pointer;
  display:flex;align-items:center;gap:var(--sp-2);color:var(--t1);}
.exp-opt:hover{background:var(--s2);}
.exp-opt+.exp-opt{border-top:1px solid var(--bdr);}

/* ── TOAST / EMPTY ───────────────────────────────── */
.ttray{position:fixed;top:1.25rem;left:50%;transform:translateX(-50%);align-items:center;display:flex;flex-direction:column;gap:var(--sp-2);z-index:9999;}
.tst{background:var(--s1);border:1px solid var(--bdr);border-radius:var(--r2);
  padding:var(--sp-3) var(--sp-4);display:flex;align-items:flex-start;gap:var(--sp-2);
  box-shadow:var(--sh3);min-width:240px;animation:tIn .22s cubic-bezier(.4,0,.2,1);border-left:3px solid var(--m3);}
.tst.ok{border-left-color:#16A34A;}.tst.err{border-left-color:#DC2626;}
@keyframes tIn{from{transform:translateX(60px);opacity:0}to{transform:translateX(0);opacity:1}}
.tst-t{font-size:var(--fs-base);font-weight:700;color:var(--t1);}
.tst-m{font-size:var(--fs-sm);color:var(--t2);margin-top:1px;}
.empty{text-align:center;padding:2.5rem var(--sp-5);color:var(--t3);}
.empty i{font-size:2.2rem;display:block;margin-bottom:var(--sp-3);opacity:.22;}

/* ── RESPONSIVE ──────────────────────────────────── */
@media(max-width:1280px){.sums{grid-template-columns:repeat(3,1fr);}}
@media(max-width:768px){.sb{transform:translateX(-100%);}.sb.open{transform:translateX(0);}
  .wrap{margin-left:0;}.pg{padding:var(--sp-4);}.mob-tog{display:flex;}
  .sums{grid-template-columns:1fr 1fr;}.fg2{grid-template-columns:1fr;}}
</style>
</head>
<body>

<!-- ════ SIDEBAR ══════════════════════════════════════ -->
<?php $activeNav = 'users'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

<!-- ════ MAIN ══════════════════════════════════════════ -->
<div class="wrap">
  <header class="topbar">
    <div class="tb-l">
      <button class="mob-tog" onclick="document.getElementById('sb').classList.toggle('open')"><i class="fas fa-bars"></i></button>
      <div>
        <div class="pg-title">User Management</div>
        <div class="bc"><a href="admin_dashboard.php"><i class="fas fa-home"></i></a><i class="fas fa-chevron-right"></i><span>User Management</span></div>
      </div>
    </div>
    <div class="tb-r">
      <a href="admin_notifications.php" class="ic-btn"><i class="fas fa-bell"></i><span class="pip"></span></a>
      <!-- Export -->
      <div class="exp-drop">
        <button type="button" class="btn btn-gold btn-sm" onclick="toggleExp(event)">
          <i class="fas fa-download"></i> Export <i class="fas fa-chevron-down" style="font-size:.58rem;"></i>
        </button>
        <div id="expMenu">
          <button onclick="exportCSV()" class="exp-opt"><i class="fas fa-file-csv" style="color:#16A34A;"></i> CSV</button>
          <button onclick="exportExcel()" class="exp-opt"><i class="fas fa-file-excel" style="color:#16A34A;"></i> Excel</button>
          <button onclick="exportPDF()" class="exp-opt"><i class="fas fa-file-pdf" style="color:#DC2626;"></i> PDF</button>
        </div>
      </div>
      <button class="btn btn-maroon btn-sm" onclick="openCreate()"><i class="fas fa-user-plus"></i> Add User</button>
      <button class="btn btn-sm" style="background:#C9960C;color:#fff;border:none;" onclick="document.getElementById('inviteMo').classList.add('open')"><i class="fas fa-user-shield"></i> Invite Technician</button>
    </div>
  </header>

  <div class="pg">

    <?php if(isset($_SESSION['flash'])): [$ft,$fm]=$_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="flash <?php echo $ft;?>">
      <i class="fas fa-<?php echo $ft==='ok'?'check-circle':'exclamation-circle';?>"></i>
      <?php echo esc($fm); ?>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="ph">
      <div>
        <h1><i class="fas fa-users"></i> User Management</h1>
        <p class="ph-sub">Create, edit, and manage all system users — administrators, PMO, technicians, and the imported directory of reporters and students.</p>
      </div>
      <div style="display:flex;gap:.45rem;">
        <button class="btn btn-ghost btn-sm" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
      </div>
    </div>

    <!-- The six summary cards that used to sit here linked to ?role=all,
         ?role=admin, ?unit=pmo, ?unit=itso, ?role=technician and ?role=reporter
         — the same six filters as the tab row directly beneath them, with the
         same six numbers. One row does both jobs: the counts now ride on the
         tabs, which is where you click anyway. -->

    <!-- Role Tabs -->
    <div class="rtabs">
      <?php
      // "PMO" was here as a role and matched nobody. The administrators are
      // the PMO and the ITSO, so those two are unit filters instead.
      $tabs=[
        ['all',   'All Users',      'fas fa-users',          'on',        $c_total],
        ['admin', 'Administrators', 'fas fa-crown',          'rtab-admin',$c_admin],
        ['technician','Technicians','fas fa-hard-hat',       'rtab-tech', $c_tech],
        ['reporter','Reporters',    'fas fa-bullhorn',       'rtab-rep',  $c_rep],
      ];
      // Every filter link keeps the other filters, so choosing a role does not
      // silently throw away the department the admin already picked.
      $keep = static function(array $over) use ($rf,$tf,$df,$yl,$sq,$uf) {
        $qs = array_merge(['role'=>$rf,'type'=>$tf,'dept'=>$df,'unit'=>$uf,'year'=>$yl,'search'=>$sq], $over);
        return '?' . http_build_query(array_filter($qs, static fn($v) => $v !== '' && $v !== 'all'));
      };
      foreach($tabs as [$rval,$rlbl,$rico,$rcls,$rnum]):
        $act = $rf===$rval?'on':'';
      ?>
      <a href="<?php echo esc($keep(['role'=>$rval])); ?>"
         class="rtab <?php echo $rcls; ?> <?php echo $act; ?>">
        <i class="<?php echo $rico;?>"></i><?php echo $rlbl;?>
        <span class="rtab-n"><?php echo number_format((int)$rnum); ?></span>
      </a>
      <?php endforeach; ?>

      <span class="tab-div" aria-hidden="true"></span>
      <?php foreach([['pmo','PMO','fas fa-building',$c_pmoUnit],['itso','ITSO','fas fa-laptop-code',$c_itsoUnit]] as [$uval,$ulbl,$uico,$unum]): ?>
      <a href="<?php echo esc($keep(['unit'=>$uf===$uval?'all':$uval])); ?>"
         class="rtab u-<?php echo $uval; ?> <?php echo $uf===$uval?'on':''; ?>"
         data-tip="<?php echo $ulbl; ?> administrators and technicians">
        <i class="<?php echo $uico; ?>"></i><?php echo $ulbl; ?>
        <span class="rtab-n"><?php echo (int)$unum; ?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Filter bar — the same three questions the BEC Directory page asks of
         the roster, on one line: who is this person at the college, which
         department, which year level. Role alone cannot answer any of them —
         the whole college files reports under the one role of Reporter, so
         without these there is no way to look at just the Grade 12s, or just
         the non-teaching offices. Affiliation used to be a row of chips above
         a separate search bar; two stacked control rows for one question is
         what made the top of this page hard to read. -->
    <div class="fbar">
      <div class="fsw">
        <i class="fas fa-search"></i>
        <input type="text" class="fsi" id="fsq" placeholder="Search name, email, ID, department…"
          value="<?php echo esc($sq); ?>" oninput="debounceGo()" onkeydown="if(event.key==='Enter'){event.preventDefault();go();}">
      </div>

      <select class="fsel" aria-label="Filter by affiliation" onchange="go({type:this.value})">
        <?php
        // Counts ride in the labels: the chips they replace carried them, and
        // "Students (3,587)" is the fastest way to see the roster is loaded.
        // An affiliation nobody holds is not a filter, it is a dead end. The
        // registrar's export types every person in it as a student, so Faculty
        // stood at 0 and could only ever return an empty table. Options with no
        // rows are dropped unless one is currently selected, in which case it
        // has to stay so the <select> still reflects the applied filter.
        foreach ([
          ['all',     'All affiliations', $c_total],
          ['student', 'Students',         $c_student],
          ['faculty', 'Faculty',          $c_faculty],
          ['staff',   'Staff',            $c_staff],
        ] as [$tval,$tlbl,$tnum]):
          if ($tval !== 'all' && (int)$tnum === 0 && $tf !== $tval) { continue; } ?>
        <option value="<?php echo esc($tval); ?>"<?php echo $tf===$tval?' selected':''; ?>><?php
          echo esc($tlbl) . ' (' . number_format((int)$tnum) . ')'; ?></option>
        <?php endforeach; ?>
      </select>

      <?php if ($deptOptions): ?>
      <?php // The lists narrow each other, but the narrowing happens on reload and
            // is easy to miss — the box still reads "All departments" even when only
            // one department can match. Say so on the placeholder. ?>
      <select class="fsel" aria-label="Filter by department or academic unit" onchange="go({dept:this.value})">
        <option value="all"<?php echo $df==='all'?' selected':''; ?>>All departments<?php
          echo count($deptOptions) === 1 ? ' — 1 match' : ''; ?></option>
        <?php foreach($deptOptions as $dopt): ?>
        <option value="<?php echo esc($dopt); ?>"<?php echo $df===$dopt?' selected':''; ?>><?php echo esc($dopt); ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>

      <?php if ($yearOptions): ?>
      <select class="fsel" aria-label="Filter by year level" onchange="go({year:this.value})">
        <option value="all"<?php echo $yl==='all'?' selected':''; ?>>All year levels<?php
          echo count($yearOptions) === 1 ? ' — 1 match' : ''; ?></option>
        <?php foreach($yearOptions as $yOpt): ?>
        <option value="<?php echo esc($yOpt); ?>"<?php echo $yl===$yOpt?' selected':''; ?>><?php echo esc($yOpt); ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>

      <button type="button" class="fgo" onclick="go()" title="Search" aria-label="Search"><i class="fas fa-search"></i></button>
      <?php if ($filtersOn): ?>
      <a href="admin_users.php" class="fclr"><i class="fas fa-xmark"></i> Clear</a>
      <?php endif; ?>

      <div class="fbar-r">
        <div class="vt">
          <button class="vt-b<?php echo $view==='table'?' on':''; ?>" id="vt-tbl" onclick="setView('table')"><i class="fas fa-list"></i> Table</button>
          <button class="vt-b<?php echo $view==='grid'?' on':''; ?>" id="vt-grid" onclick="setView('grid')"><i class="fas fa-th-large"></i> Grid</button>
        </div>
        <span class="fcount">
          <?php if ($matchTotal > $perPage): ?>
            <?php if (count($users) > 0): ?>
              <strong><?php echo number_format($rowFrom); ?>&ndash;<?php echo number_format($rowTo); ?></strong>
            <?php else: ?>
              <strong>0</strong>
            <?php endif; ?>
            of <?php echo number_format($matchTotal); ?>
          <?php else: ?>
            <?php echo number_format(count($users)); ?> user<?php echo count($users)!=1?'s':''; ?>
          <?php endif; ?>
        </span>
      </div>
    </div>

    <?php if ($pageOverrun): ?>
    <div class="capnote">
      <i class="fas fa-circle-exclamation"></i>
      <span>Page <strong><?php echo number_format($page); ?></strong> is past the end of this list
      &mdash; there <?php echo $totalPages === 1 ? 'is' : 'are'; ?>
      <strong><?php echo number_format($totalPages); ?></strong>
      page<?php echo $totalPages !== 1 ? 's' : ''; ?>.
      <a href="<?php echo esc($pageQuery(1)); ?>">Back to the first page</a>.</span>
    </div>
    <?php endif; ?>

    <!-- ════ TABLE VIEW ════ -->
    <?php if ($view === 'table'): ?>
    <div class="panel" id="tableView">
      <div class="ph3">
        <h3><i class="fas fa-list-alt"></i> User Records</h3>
        <!-- Export, Add User and Invite Technician were repeated here from the
             page header a few hundred pixels above, so the same three actions
             appeared twice on one screen. They live in the header only. -->
      </div>
      <!-- No data-paginate here: this list is paged in SQL (see $perPage). The
           client-side paginator would slice the 50 rows the server already
           chose, leaving two stacked pagers disagreeing about "page 1". -->
      <!-- Nine columns of real content do not fit every desktop. The panel
           clips (overflow:hidden, for its rounded corners), so without this the
           Joined date and the whole Actions column are simply not reachable. -->
      <div class="tblwrap">
      <table class="tbl u-fixed" id="uTbl">
        <?php /* Nine columns sized by their content needed 1,608px inside a
                 1,102px panel, so Actions — the Edit and Delete buttons — sat
                 506px off the right edge, reachable only by scrolling a table
                 that gives no sign it scrolls. Percentages rather than rem: they
                 are relative to the table, so the row fits whatever width the
                 panel has instead of fitting one screen and clipping on the
                 next. Text that no longer fits ellipses, which the columns
                 already did. */ ?>
        <?php /* Reports and Tasks hold single digits, so their width is set
                 entirely by the header word above them, not by the data. They
                 need more than the numbers suggest; Email needs less than it
                 wants, and the full address is one click away in the row. */ ?>
        <colgroup>
          <col style="width:18%"><col style="width:17%"><col style="width:11%">
          <col style="width:14%"><col style="width:6%"><col style="width:8%">
          <col style="width:7%"><col style="width:9%"><col style="width:10%">
        </colgroup>
        <thead>
          <?php /* "Year" and "Tasks" rather than "Year Level" and "Active
                   Tasks": the headers are nowrap, so at these widths the long
                   pair ran into the column beside it. The short words say the
                   same thing over a column of numbers. */ ?>
          <tr>
            <th>User</th><th>Email</th><th class="nw">Standing</th><th>Department</th>
            <th class="c nw">Year</th>
            <th class="c">Reports</th><th class="c">Tasks</th><th class="c nw">Joined</th>
            <th class="c">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($users)): ?>
          <tr><td colspan="9"><div class="empty"><i class="fas fa-users-slash"></i>No users match the current filters.</div></td></tr>
          <?php else: foreach($users as $u):
            $init = initials($u['fullname']??'??');
            $avcol = avatarColor($u['role']??'');
            $dc = deptCls($u['department']??'');
          ?>
          <tr class="urow" data-user="<?php echo cardPayload($u);?>" tabindex="0" aria-label="Open user details">
            <td>
              <div class="tuser">
                <div class="tav" style="background:<?php echo $avcol;?>;"><?php echo $init;?></div>
                <div>
                  <div class="tname"><?php echo esc($u['fullname']??'—'); ?></div>
                  <div class="tuid"><?php echo esc($u['user_id']); ?></div>
                </div>
              </div>
            </td>
            <td class="temail"><?php echo esc($u['email']??'—'); ?></td>
            <td class="nw">
              <span class="bdg <?php echo roleCls($u['role']); ?>">
                <i class="<?php echo roleIco($u['role']); ?>" style="font-size:.6rem;margin-right:.18rem;"></i>
                <?php echo roleLbl($u['role']); ?>
              </span><?php echo typeBadge($u); ?>
            </td>
            <td>
              <?php if(!empty($u['department'])):?>
              <span class="dept-<?php echo $dc;?>">
                <?php if($dc==='itso') echo '<i class="fas fa-laptop-code"></i>';
                      elseif($dc==='pmo') echo '<i class="fas fa-building"></i>';
                      else echo '<i class="fas fa-building"></i>'; ?>
                <?php echo esc($u['department']); ?>
              </span>
              <?php else: ?><span style="color:var(--t4);font-size:.72rem;">—</span><?php endif; ?>
            </td>
            <td class="c nw" style="font-size:.74rem;color:var(--t2,#5B4636);">
              <?php echo !empty($u['year_level']) ? esc($u['year_level']) : '<span style="color:var(--t4);font-size:.72rem;">—</span>'; ?>
            </td>
            <td class="c num" style="color:var(--m3);">
              <?php echo (int)($u['report_count']??0); ?>
            </td>
            <td class="c num">
              <?php $at=(int)($u['active_tasks']??0); ?>
              <span style="color:<?php echo $at>3?'#DC2626':($at>1?'#D97706':'#16A34A');?>;"><?php echo $at; ?></span>
            </td>
            <td class="c nw" style="font-size:.72rem;color:var(--t3);">
              <?php echo !empty($u['created_at'])?date('M j, Y',strtotime($u['created_at'])):'—'; ?>
            </td>
            <td class="c">
              <div class="no-row-open" style="display:flex;gap:.25rem;justify-content:center;">
                <button type="button" class="btn bico bi-v" title="View Profile"
                  onclick="openProfile(rowData(this))">
                  <i class="fas fa-eye"></i>
                </button>
                <?php if(empty($u['is_directory'])): ?>
                <button type="button" class="btn bico bi-e" title="Edit User"
                  onclick="openEdit(rowData(this))">
                  <i class="fas fa-pen"></i>
                </button>
                <?php if($roleNeedsPassword($u['role'] ?? '')): ?>
                <button type="button" class="btn bico bi-k" title="Reset Password"
                  onclick="openReset('<?php echo esc($u['user_id']);?>','<?php echo esc($u['fullname']??'');?>')">
                  <i class="fas fa-key"></i>
                </button>
                <?php endif; ?>
                <?php if($u['user_id']!==$admin_id): ?>
                <button type="button" class="btn bico bi-d" title="Delete"
                  onclick="delUser('<?php echo esc($u['user_id']);?>','<?php echo esc($u['fullname']??'');?>')">
                  <i class="fas fa-trash"></i>
                </button>
                <?php endif; ?>
                <?php else: ?>
                <span class="bdg" style="background:rgba(8,145,178,.12);color:#0891B2;font-size:.6rem;" title="Imported from the BEC directory — reporter, no login account"><i class="fas fa-address-book" style="font-size:.6rem;margin-right:.18rem;"></i>Directory</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      </div>
    </div>

    <?php endif; ?>

    <!-- ════ GRID VIEW ════ -->
    <?php if ($view === 'grid'): ?>
    <div id="gridView">
      <div class="ugrid"><!-- paged in SQL, same as the table view -->
        <?php if(empty($users)): ?>
        <div style="grid-column:1/-1;"><div class="empty"><i class="fas fa-users-slash"></i>No users match the current filters.</div></div>
        <?php else: foreach($users as $i=>$u):
          $init = initials($u['fullname']??'??');
          $avcol = avatarColor($u['role']??'');
          $dc = deptCls($u['department']??'');
          $isDir = !empty($u['is_directory']);
        ?>
        <div class="ucard role-<?php echo esc($u['role']??'reporter');?>"
          data-user="<?php echo cardPayload($u);?>"
          style="animation-delay:<?php echo min($i,25)*.04;?>s;">
          <div class="uc-top">
            <div class="uc-av" style="background:<?php echo $avcol;?>;"><?php echo $init;?></div>
            <?php if($isDir): ?><span class="uc-status"><span class="bdg" style="background:rgba(8,145,178,.12);color:#0891B2;font-size:.6rem;"><i class="fas fa-address-book" style="font-size:.55rem;margin-right:.15rem;"></i>Directory</span></span><?php endif; ?>
          </div>
          <div class="uc-name"><?php echo esc($u['fullname']??'—');?></div>
          <div class="uc-id"><?php echo esc($u['user_id']);?></div>
          <div class="uc-email"><i class="fas fa-envelope" style="font-size:.62rem;color:var(--t3);flex-shrink:0;"></i><?php echo esc($u['email']??'—');?></div>
          <div class="uc-meta">
            <span class="bdg <?php echo roleCls($u['role']);?>">
              <i class="<?php echo roleIco($u['role']);?>" style="font-size:.6rem;margin-right:.15rem;"></i>
              <?php echo roleLbl($u['role']);?>
            </span><?php echo typeBadge($u); ?>
            <?php if(!empty($u['department'])):?>
            <span class="dept-<?php echo $dc;?>" style="font-size:.6rem;padding:.15rem .48rem;">
              <?php echo esc($u['department']);?>
            </span>
            <?php endif;?>
          </div>
          <div class="uc-stats">
            <div class="uc-stat">
              <div class="uc-stat-n" style="color:var(--m3);"><?php echo (int)($u['report_count']??0);?></div>
              <div class="uc-stat-l">Reports</div>
            </div>
            <div class="uc-stat">
              <?php $at=(int)($u['active_tasks']??0);?>
              <div class="uc-stat-n" style="color:<?php echo $at>3?'#DC2626':($at>1?'#D97706':'#16A34A');?>;"><?php echo $at;?></div>
              <div class="uc-stat-l">Active Tasks</div>
            </div>
          </div>
          <div class="uc-acts">
            <button type="button" class="btn btn-ghost btn-sm" style="flex:1;justify-content:center;"
              onclick="openProfile(rowData(this))">
              <i class="fas fa-eye"></i> View
            </button>
            <?php if(empty($u['is_directory'])): ?>
            <button type="button" class="btn btn-gold btn-sm" title="Edit User"
              onclick="openEdit(rowData(this))">
              <i class="fas fa-pen"></i>
            </button>
            <?php if($roleNeedsPassword($u['role'] ?? '')): ?>
            <button type="button" class="btn btn-ghost btn-sm" title="Reset Password"
              onclick="openReset('<?php echo esc($u['user_id']);?>','<?php echo esc($u['fullname']??'');?>')">
              <i class="fas fa-key"></i>
            </button>
            <?php endif; ?>
            <?php if($u['user_id']!==$admin_id):?>
            <button type="button" class="btn btn-red btn-sm" title="Delete"
              onclick="delUser('<?php echo esc($u['user_id']);?>','<?php echo esc($u['fullname']??'');?>')">
              <i class="fas fa-trash"></i>
            </button>
            <?php endif;?>
            <?php else: ?>
            <span class="bdg" style="flex:1;justify-content:center;background:rgba(8,145,178,.1);color:#0891B2;font-size:.62rem;" title="Imported from the BEC directory — reporter, no login account to edit"><i class="fas fa-address-book" style="font-size:.6rem;margin-right:.2rem;"></i>Directory record</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; endif;?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <!-- Paging is done in SQL: each page is its own LIMIT/OFFSET query, so the
         browser never receives the rows it isn't showing. -->
    <nav class="pager" id="userPager" aria-label="User list pages">
      <span class="pager-count">
        <?php if (count($users) > 0): ?>
          <?php echo number_format($rowFrom); ?>&ndash;<?php echo number_format($rowTo); ?>
        <?php else: ?>
          No rows on this page &mdash;
        <?php endif; ?>
        of <strong><?php echo number_format($matchTotal); ?></strong>
      </span>
      <span class="pager-btns">
        <?php if ($page > 1): ?>
          <a class="pgb" href="<?php echo esc($pageQuery($page - 1)); ?>" rel="prev"><i class="fas fa-chevron-left"></i> Previous</a>
        <?php else: ?>
          <span class="pgb off"><i class="fas fa-chevron-left"></i> Previous</span>
        <?php endif; ?>

        <?php
        // A window around the current page, so 36 pages don't render 36 links.
        $from = max(1, $page - 2);
        $to   = min($totalPages, $page + 2);
        if ($from > 1): ?>
          <a class="pgb" href="<?php echo esc($pageQuery(1)); ?>">1</a>
          <?php if ($from > 2): ?><span class="pg-gap">&hellip;</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $from; $i <= $to; $i++): ?>
          <?php if ($i === $page): ?>
            <span class="pgb on" aria-current="page"><?php echo $i; ?></span>
          <?php else: ?>
            <a class="pgb" href="<?php echo esc($pageQuery($i)); ?>"><?php echo $i; ?></a>
          <?php endif; ?>
        <?php endfor; ?>

        <?php if ($to < $totalPages): ?>
          <?php if ($to < $totalPages - 1): ?><span class="pg-gap">&hellip;</span><?php endif; ?>
          <a class="pgb" href="<?php echo esc($pageQuery($totalPages)); ?>"><?php echo number_format($totalPages); ?></a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
          <a class="pgb" href="<?php echo esc($pageQuery($page + 1)); ?>" rel="next">Next <i class="fas fa-chevron-right"></i></a>
        <?php else: ?>
          <span class="pgb off">Next <i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
      </span>
    </nav>
    <?php endif; ?>

  </div><!-- /pg -->
</div><!-- /wrap -->

<!-- ════ CREATE USER MODAL ════════════════════════════ -->
<div class="mo" id="createMo" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="mw">
    <div class="mhd">
      <div class="mhd-t">
        <h2><i class="fas fa-user-plus mh-ic"></i> Add New User</h2>
        <p>Register a reporter, or create a login for PMO staff, a technician, or an administrator.</p>
      </div>
      <button class="mx" onclick="document.getElementById('createMo').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="mb">
      <form method="POST" action="admin_users.php" id="createForm" onsubmit="return deptGuard('cuRole','cuDeptTxt');">
        <input type="hidden" name="action" value="create">
        <p id="cuRoleNote" style="display:none;font-size:.76rem;line-height:1.6;color:var(--t2);background:rgba(8,145,178,.08);
          border:1px solid rgba(8,145,178,.25);border-left:3px solid #0891B2;border-radius:.5rem;padding:.6rem .75rem;margin:0 0 1rem;"></p>
        <div class="fg2">
          <div class="fg">
            <label class="fl">Full Name <span>*</span></label>
            <input type="text" name="fullname" class="fc" placeholder="Juan dela Cruz" maxlength="100" data-guard="alpha" required>
          </div>
          <div class="fg">
            <label class="fl">Email Address <span>*</span></label>
            <input type="email" name="email" class="fc" placeholder="juan@bec.edu.ph" maxlength="150" required>
          </div>
        </div>
        <div class="fg2">
          <div class="fg">
            <label class="fl">Role <span>*</span></label>
            <select name="role" id="cuRole" class="fc" required onchange="deptRoleSync('cuRole','cuDeptSel','cuDeptStar','cuDeptHint');createAuthSync();">
              <?php foreach($assignableRoleMeta as $roleValue => $roleLabel): ?>
                <option value="<?php echo esc($roleValue); ?>" <?php echo in_array($roleValue, $assignableRoles, true) ? '' : 'disabled'; ?>>
                  <?php echo esc($roleLabel . (in_array($roleValue, $assignableRoles, true) ? '' : ' (DB setup needed)')); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label class="fl">Unit / Department <span id="cuDeptStar" style="display:none;">*</span></label>
            <select id="cuDeptSel" class="fc" onchange="deptPick('cuDeptSel','cuDeptTxt',this.value)">
              <option value="">Select…</option>
              <optgroup label="Admin / Technician unit">
                <option value="PMO">PMO — Property Management Office</option>
                <option value="ITSO">ITSO — Information Technology Services Office</option>
              </optgroup>
              <?php /* The academic units come from the directory itself, so this list
                        stays right as the registrar's roster changes. */ ?>
              <optgroup label="Academic / Office">
                <?php foreach ($deptAll as $dOpt): if (in_array(strtoupper($dOpt), ['PMO','ITSO'], true)) continue; ?>
                  <option value="<?php echo esc($dOpt); ?>"><?php echo esc($dOpt); ?></option>
                <?php endforeach; ?>
              </optgroup>
              <option value="__other">Other…</option>
            </select>
            <input type="text" name="department" id="cuDeptTxt" class="fc" placeholder="Type the department…" maxlength="100" style="margin-top:.4rem;display:none;">
            <p id="cuDeptHint" class="fhint" hidden><i class="fas fa-circle-info"></i> For an <strong>Administrator</strong> this sets which dashboard they oversee — pick <strong>PMO</strong> or <strong>ITSO</strong>.</p>
          </div>
        </div>
        <?php if($hasUserTypeCol): /* hidden until scripts/2026_08_reporter_user_type.sql has run */ ?>
        <div class="fg" id="cuTypeBlock">
          <label class="fl">Reporter Type <span>*</span></label>
          <select name="user_type" id="cuType" class="fc" onchange="yearSync('cuType','cuYearBlock','cuYear',true)">
            <option value="">Select…</option>
            <?php foreach($reporterTypeMeta as $tVal => $tLbl): ?>
              <option value="<?php echo esc($tVal); ?>"><?php echo esc($tLbl); ?></option>
            <?php endforeach; ?>
          </select>
          <p class="fhint"><i class="fas fa-circle-info"></i> Everyone who reports is a <strong>Reporter</strong>. This says who they are at BEC, so the PMO knows whether a report came from a student, a teacher, or staff.</p>
        </div>
        <?php if($hasYearLevelCol): ?>
        <div class="fg" id="cuYearBlock">
          <label class="fl">Year Level</label>
          <select name="year_level" id="cuYear" class="fc">
            <option value="">Not applicable</option>
            <?php foreach($yearAll as $yOpt): ?>
              <option value="<?php echo esc($yOpt); ?>"><?php echo esc($yOpt); ?></option>
            <?php endforeach; ?>
          </select>
          <p class="fhint"><i class="fas fa-circle-info"></i> Students only. Chosen from the registrar's enrolment list, so it matches the directory exactly.</p>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <div class="fg">
          <label class="fl">Phone Number</label>
          <input type="tel" name="phone" class="fc" placeholder="09171234567" maxlength="11" inputmode="numeric">
        </div>
        <div class="fg2" id="cuPassBlock">
          <div class="fg">
            <label class="fl">Password <span>*</span></label>
            <div class="pass-wrap">
              <input type="password" name="password" id="cpw" class="fc" placeholder="Min. 8 characters" required oninput="checkStrength(this,'cstr')">
              <button type="button" class="pass-toggle" onclick="togglePw('cpw',this)"><i class="fas fa-eye"></i></button>
            </div>
            <div class="strength-bar"><div id="cstr" class="strength-fill" style="width:0;"></div></div>
          </div>
          <div class="fg">
            <label class="fl">Confirm Password <span>*</span></label>
            <div class="pass-wrap">
              <input type="password" name="password_confirm" id="cpw2" class="fc" placeholder="Repeat password" required>
              <button type="button" class="pass-toggle" onclick="togglePw('cpw2',this)"><i class="fas fa-eye"></i></button>
            </div>
          </div>
        </div>
      </form>
    </div>
    <div class="mf">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('createMo').classList.remove('open')">Cancel</button>
      <button type="submit" form="createForm" class="btn btn-green btn-sm"><i class="fas fa-user-plus"></i> Create User</button>
    </div>
  </div>
</div>

<!-- ════ INVITE TECHNICIAN MODAL (#8) ════════════════ -->
<div class="mo" id="inviteMo" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="mw">
    <div class="mhd">
      <div class="mhd-t">
        <h2><i class="fas fa-user-shield mh-ic"></i> Invite Technician</h2>
        <p>Send a secure email invitation. The technician verifies their identity and sets their own password to activate the account.</p>
      </div>
      <button class="mx" onclick="document.getElementById('inviteMo').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="mb">
      <form method="POST" action="admin_users.php" id="inviteForm">
        <input type="hidden" name="action" value="invite_technician">
        <div class="fg2">
          <div class="fg">
            <label class="fl">Full Name <span>*</span></label>
            <input type="text" name="fullname" class="fc" placeholder="Juan dela Cruz" maxlength="100" data-guard="alpha" required>
          </div>
          <div class="fg">
            <label class="fl">Email Address <span>*</span></label>
            <input type="email" name="email" class="fc" placeholder="technician@bec.edu.ph" maxlength="150" required>
          </div>
        </div>
        <div class="fg2">
          <div class="fg">
            <label class="fl">Position</label>
            <input type="text" name="position" class="fc" placeholder="e.g. Maintenance Technician">
          </div>
          <div class="fg">
            <label class="fl">Unit / Department <span>*</span></label>
            <select name="department" class="fc" required>
              <option value="">Select unit…</option>
              <option value="PMO">PMO — Property Management Office</option>
              <option value="ITSO">ITSO — Information Technology Services Office</option>
              <option value="Maintenance Department">Maintenance Department</option>
            </select>
          </div>
        </div>
        <div class="fg">
          <label class="fl">Specialization</label>
          <input type="text" name="specialization" class="fc" placeholder="e.g. Electrical, Computer, Aircon">
        </div>
        <p style="font-size:.74rem;color:var(--t3,#9A7A7A);margin:.6rem 0 0;line-height:1.5;"><i class="fas fa-circle-info"></i> A verification link (valid for 3 days) will be emailed. The account stays inactive until the technician completes verification.</p>
      </form>
    </div>
    <div class="mf">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('inviteMo').classList.remove('open')">Cancel</button>
      <button type="submit" form="inviteForm" class="btn btn-sm" style="background:#C9960C;color:#fff;border:none;"><i class="fas fa-paper-plane"></i> Send Invitation</button>
    </div>
  </div>
</div>

<!-- ════ EDIT USER MODAL ══════════════════════════════ -->
<div class="mo" id="editMo" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="mw" style="max-width:720px;">
    <div class="mhd">
      <div class="mhd-t">
        <h2><i class="fas fa-user-edit mh-ic"></i> Edit User</h2>
        <p id="editSubtitle">Update user account details.</p>
      </div>
      <button class="mx" onclick="document.getElementById('editMo').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="mb">
      <form method="POST" action="admin_users.php" id="editForm" onsubmit="return deptGuard('eRole','eDept');">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="user_id" id="eUid">
        <div class="fg2">
          <div class="fg">
            <label class="fl">Full Name <span>*</span></label>
            <input type="text" name="fullname" id="eFname" class="fc" maxlength="100" data-guard="alpha" required>
          </div>
          <div class="fg">
            <label class="fl">Email Address <span>*</span></label>
            <input type="email" name="email" id="eEmail" class="fc" maxlength="150" required>
          </div>
        </div>
        <div class="fg2">
          <div class="fg">
            <label class="fl">Role <span>*</span></label>
            <select name="role" id="eRole" class="fc" required onchange="deptRoleSync('eRole','eDeptSel','eDeptStar','eDeptHint');editAuthSync();">
              <?php foreach($assignableRoleMeta as $roleValue => $roleLabel): ?>
                <option value="<?php echo esc($roleValue); ?>" <?php echo in_array($roleValue, $assignableRoles, true) ? '' : 'disabled'; ?>>
                  <?php echo esc($roleLabel . (in_array($roleValue, $assignableRoles, true) ? '' : ' (DB setup needed)')); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="fg2">
          <div class="fg">
            <label class="fl">Unit / Department <span id="eDeptStar" style="display:none;">*</span></label>
            <select id="eDeptSel" class="fc" onchange="deptPick('eDeptSel','eDept',this.value)">
              <option value="">Select…</option>
              <optgroup label="Admin / Technician unit">
                <option value="PMO">PMO — Property Management Office</option>
                <option value="ITSO">ITSO — Information Technology Services Office</option>
              </optgroup>
              <optgroup label="Academic / Office">
                <?php foreach ($deptAll as $dOpt): if (in_array(strtoupper($dOpt), ['PMO','ITSO'], true)) continue; ?>
                  <option value="<?php echo esc($dOpt); ?>"><?php echo esc($dOpt); ?></option>
                <?php endforeach; ?>
              </optgroup>
              <option value="__other">Other…</option>
            </select>
            <input type="text" name="department" id="eDept" class="fc" placeholder="Type the department…" maxlength="100" style="margin-top:.4rem;display:none;">
            <p id="eDeptHint" class="fhint" hidden><i class="fas fa-circle-info"></i> For an <strong>Administrator</strong> this sets which dashboard they oversee — pick <strong>PMO</strong> or <strong>ITSO</strong>.</p>
          </div>
          <div class="fg">
            <label class="fl">Phone</label>
            <input type="tel" name="phone" id="ePhone" class="fc" placeholder="09171234567" maxlength="11" inputmode="numeric">
          </div>
        </div>
        <?php if($hasUserTypeCol): ?>
        <div class="fg" id="eTypeBlock">
          <label class="fl">Reporter Type <span>*</span></label>
          <select name="user_type" id="eType" class="fc" onchange="yearSync('eType','eYearBlock','eYear',true)">
            <option value="">Select…</option>
            <?php foreach($reporterTypeMeta as $tVal => $tLbl): ?>
              <option value="<?php echo esc($tVal); ?>"><?php echo esc($tLbl); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php if($hasYearLevelCol): ?>
        <div class="fg" id="eYearBlock">
          <label class="fl">Year Level</label>
          <select name="year_level" id="eYear" class="fc">
            <option value="">Not applicable</option>
            <?php foreach($yearAll as $yOpt): ?>
              <option value="<?php echo esc($yOpt); ?>"><?php echo esc($yOpt); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="fg" id="ePassBlock">
          <label class="fl">New Password <span style="color:var(--t3);font-weight:400;text-transform:none;letter-spacing:0;">(leave blank to keep current)</span></label>
          <div class="pass-wrap">
            <input type="password" name="new_password" id="epw" class="fc" placeholder="Enter new password to change…" oninput="checkStrength(this,'estr')">
            <button type="button" class="pass-toggle" onclick="togglePw('epw',this)"><i class="fas fa-eye"></i></button>
          </div>
          <div class="strength-bar"><div id="estr" class="strength-fill" style="width:0;"></div></div>
        </div>
        <p id="eRoleNote" style="display:none;font-size:.76rem;line-height:1.6;color:var(--t2);background:rgba(8,145,178,.08);
          border:1px solid rgba(8,145,178,.25);border-left:3px solid #0891B2;border-radius:.5rem;padding:.6rem .75rem;margin:.2rem 0 0;"></p>
      </form>
    </div>
    <div class="mf">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('editMo').classList.remove('open')">Cancel</button>
      <button type="submit" form="editForm" class="btn btn-maroon btn-sm"><i class="fas fa-save"></i> Save Changes</button>
    </div>
  </div>
</div>

<!-- ════ PROFILE MODAL ════════════════════════════════ -->
<div class="mo" id="profileMo" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="mw">
    <div class="mhd">
      <div class="mhd-t">
        <h2><i class="fas fa-id-card mh-ic"></i> User Profile</h2>
        <p id="profSubtitle">Account details</p>
      </div>
      <button class="mx" onclick="document.getElementById('profileMo').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="mb">
      <div class="prof-head">
        <div class="prof-av" id="profAv" style="background:linear-gradient(135deg,var(--m3),var(--m4));">??</div>
        <div>
          <div class="prof-name" id="profName">—</div>
          <div class="prof-id" id="profId">—</div>
          <div style="display:flex;gap:.3rem;margin-top:.35rem;flex-wrap:wrap;" id="profBadges"></div>
        </div>
      </div>
      <div id="profRows"><!-- filled by JS --></div>
    </div>
    <div class="mf">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('profileMo').classList.remove('open')">Close</button>
      <button id="profEditBtn" class="btn btn-maroon btn-sm" onclick="switchToEdit()"><i class="fas fa-pen"></i> Edit User</button>
    </div>
  </div>
</div>

<!-- ════ RESET PASSWORD MODAL ════════════════════════ -->
<div class="mo" id="resetMo" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="mw" style="max-width:420px;">
    <div class="mhd">
      <div class="mhd-t">
        <h2><i class="fas fa-key mh-ic"></i> Reset Password</h2>
        <p id="resetSubtitle">Set a new password for this user.</p>
      </div>
      <button class="mx" onclick="document.getElementById('resetMo').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="mb">
      <form method="POST" action="admin_users.php" id="resetForm">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" id="resetUid">
        <div class="fg">
          <label class="fl">New Password <span>*</span></label>
          <div class="pass-wrap">
            <input type="password" name="new_pass" id="rpw" class="fc" placeholder="Min. 8 characters" required oninput="checkStrength(this,'rstr')">
            <button type="button" class="pass-toggle" onclick="togglePw('rpw',this)"><i class="fas fa-eye"></i></button>
          </div>
          <div class="strength-bar"><div id="rstr" class="strength-fill" style="width:0;"></div></div>
        </div>
        <div class="fg">
          <label class="fl">Confirm New Password <span>*</span></label>
          <div class="pass-wrap">
            <input type="password" id="rpw2" class="fc" placeholder="Repeat new password" required>
            <button type="button" class="pass-toggle" onclick="togglePw('rpw2',this)"><i class="fas fa-eye"></i></button>
          </div>
        </div>
      </form>
    </div>
    <div class="mf">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('resetMo').classList.remove('open')">Cancel</button>
      <button type="button" class="btn btn-amber btn-sm" onclick="submitReset()"><i class="fas fa-key"></i> Reset Password</button>
    </div>
  </div>
</div>

<!-- ════ LOGOUT MODAL ═════════════════════════════════ -->
<div class="mo" id="lgmo" onclick="if(event.target===this)this.classList.remove('open')">
  <div style="background:var(--s1);border-radius:var(--r4);padding:2rem;max-width:330px;
    width:90%;text-align:center;box-shadow:var(--sh3);animation:mUp .25s ease;margin:auto;">
    <i class="fas fa-sign-out-alt" style="font-size:2.2rem;color:var(--m3);margin-bottom:.7rem;display:block;"></i>
    <h3 style="font-family:'Outfit',sans-serif;font-size:1.05rem;font-weight:800;margin-bottom:.38rem;">Log Out?</h3>
    <p style="font-size:.8rem;color:var(--t2);margin-bottom:1.25rem;line-height:1.6;">You will be returned to the BEC admin login page.</p>
    <div style="display:flex;gap:.55rem;justify-content:center;">
      <button onclick="document.getElementById('lgmo').classList.remove('open')" class="btn btn-ghost btn-sm">Cancel</button>
      <a href="logout.php" class="btn btn-maroon btn-sm"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>
  </div>
</div>

<!-- Hidden forms -->
<form id="delFrm" method="POST" action="admin_users.php" style="display:none;">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="user_id" id="delUid">
</form>

<div class="ttray" id="ttray"></div>

<script>
/* ─── FILTER ─────────────────────────────────────────── */
function go(over){
  const u=new URL(location.href);
  const set=(k,v)=>{ (v && v!=='all') ? u.searchParams.set(k,v) : u.searchParams.delete(k); };
  set('role',  '<?php echo esc($rf); ?>');
  set('type',  '<?php echo esc($tf); ?>');
  set('dept',  '<?php echo esc($df); ?>');
  set('unit',  '<?php echo esc($uf); ?>');
  set('year',  '<?php echo esc($yl); ?>');
  set('search',document.getElementById('fsq').value);
  // Changing a filter starts a new search, so the old page position goes with it.
  // Without this, searching from page 5 lands on page 5 of the new result set —
  // usually past the end, and reading as "no matches".
  u.searchParams.delete('page');
  if (over) { Object.entries(over).forEach(([k,v])=>set(k,v)); }
  location.href=u.toString();
}
let dbt; function debounceGo(){clearTimeout(dbt);dbt=setTimeout(()=>go(),500);}

/* ─── VIEW TOGGLE ────────────────────────────────────── */
const _view = '<?php echo $view; ?>';
function setView(v){
  if (v === _view) return;
  localStorage.setItem('bec_uview', v);
  document.cookie = 'bec_uview=' + v + ';path=/;max-age=31536000;samesite=Lax';
  location.reload();   // the other view is not in the page — the server sends it
}
/* Someone who chose Grid before this became a cookie still has it only in
   localStorage. Carry it over once, then reload into the right view. */
(function syncStoredView(){
  const stored = localStorage.getItem('bec_uview');
  if (stored && stored !== _view && !sessionStorage.getItem('bec_uview_synced')) {
    sessionStorage.setItem('bec_uview_synced','1');
    document.cookie = 'bec_uview=' + stored + ';path=/;max-age=31536000;samesite=Lax';
    location.reload();
  }
})();
function initUserRowClicks(){
  document.querySelectorAll('#uTbl tbody tr.urow').forEach((tr)=>{
    const openRow = () => {
      const raw = tr.dataset.user || '';
      if(!raw) return;
      try { openProfile(JSON.parse(raw)); }
      catch(e){ console.error('Invalid user payload on row:', e); }
    };
    tr.addEventListener('click', (e)=>{
      if(e.target.closest('button,a,input,select,textarea,.no-row-open')) return;
      openRow();
    });
    tr.addEventListener('keydown', (e)=>{
      if(e.key!=='Enter' && e.key!==' ') return;
      e.preventDefault();
      openRow();
    });
  });
}
document.addEventListener('DOMContentLoaded',()=>{
  initUserRowClicks();
});

/* ─── EXPORT ─────────────────────────────────────────── */
function toggleExp(e){
  e.stopPropagation();
  const m=document.getElementById('expMenu');
  m.style.display=m.style.display==='none'?'block':'none';
}
document.addEventListener('click',()=>{const m=document.getElementById('expMenu');if(m)m.style.display='none';});
/* The export is built server-side from the records themselves (see the export
   branch next to the roster query). It used to be handed the one page of 50 the
   browser had rendered, so "N users exported" was never the whole list. All the
   browser does now is carry the current filters over. */
function exportUrl(format){
  const u=new URL(location.href);
  u.searchParams.delete('page');
  u.searchParams.set('export',format);
  return u.toString();
}
function exportCSV(){
  window.location.href=exportUrl('csv');
  toast('ok','Everyone matching the current filters is being exported.','CSV Export');
}
function exportExcel(){
  window.location.href=exportUrl('xlsx');
  toast('ok','Everyone matching the current filters is being exported.','Excel Export');
}
function exportPDF(){
  window.open(exportUrl('pdf'),'_blank');
  toast('ok','Print view opened in a new tab.','PDF Export');
}

/* ─── PASSWORD VISIBILITY BY ROLE ────────────────────── */
// Reporters and students sign in at the reporter portal with their full name
// and BEC email — there is no password to set, so the fields are hidden (and
// un-required, or the browser would block submit on an invisible input).
const PASSWORDLESS_ROLES = <?php echo json_encode($passwordlessRoles); ?>;
function isPasswordless(role){ return PASSWORDLESS_ROLES.indexOf(role) !== -1; }

// The reporter type only means something for a reporter — a technician has a
// specialization instead, an administrator a unit.
function typeSync(roleId, blockId, selId, yearBlockId, yearSelId){
  const block = document.getElementById(blockId), sel = document.getElementById(selId);
  if(!block || !sel) return;              // field absent until the migration runs
  const role = (document.getElementById(roleId)||{}).value||'';
  const on   = (role === 'reporter');
  block.style.display = on ? '' : 'none';
  sel.required = on;
  if(!on) sel.value = '';
  yearSync(selId, yearBlockId, yearSelId, on);
}

// A year level only means something for a student, so the field appears with
// that choice and is cleared for anyone else.
function yearSync(typeSelId, yearBlockId, yearSelId, roleIsReporter){
  const yBlock = document.getElementById(yearBlockId), ySel = document.getElementById(yearSelId);
  if(!yBlock || !ySel) return;            // field absent until the migration runs
  const type = (document.getElementById(typeSelId)||{}).value||'';
  const show = roleIsReporter && type === 'student';
  yBlock.style.display = show ? '' : 'none';
  if(!show) ySel.value = '';
}

function createAuthSync(){
  typeSync('cuRole','cuTypeBlock','cuType','cuYearBlock','cuYear');
  const role  = (document.getElementById('cuRole')||{}).value||'';
  const block = document.getElementById('cuPassBlock');
  const note  = document.getElementById('cuRoleNote');
  const pw    = document.getElementById('cpw'), pw2 = document.getElementById('cpw2');
  const off   = isPasswordless(role);
  block.style.display = off ? 'none' : '';
  pw.required = pw2.required = !off;
  if(off){
    pw.value=''; pw2.value=''; document.getElementById('cstr').style.width='0';
    note.innerHTML = '<i class="fas fa-circle-info"></i> <strong>No password needed.</strong> '
      + (role==='student'?'Students':'Reporters')
      + ' sign in at the reporting portal with their full name and official <strong>@bec.edu.ph</strong> email — '
      + 'adding them here is what lets that email pass the BEC identity check.';
    note.style.display='';
  } else {
    note.style.display='none';
  }
}

function editAuthSync(){
  typeSync('eRole','eTypeBlock','eType','eYearBlock','eYear');
  const role  = (document.getElementById('eRole')||{}).value||'';
  const block = document.getElementById('ePassBlock');
  const note  = document.getElementById('eRoleNote');
  const off   = isPasswordless(role);
  block.style.display = off ? 'none' : '';
  if(off){
    document.getElementById('epw').value='';
    document.getElementById('estr').style.width='0';
    note.innerHTML = '<i class="fas fa-circle-info"></i> This account signs in with full name + BEC email at the reporting portal, so it has no password to change.';
    note.style.display='';
  } else {
    note.style.display='none';
  }
}

/* ─── CREATE MODAL ───────────────────────────────────── */
function openCreate(){
  createAuthSync();
  document.getElementById('createMo').classList.add('open');
}

/* ─── EDIT MODAL ─────────────────────────────────────── */
let _curUser = null;
/* The row or card already carries this person's details, so the buttons read
   them from it rather than each embedding another copy of the same record. */
function rowData(el){
  const host = el.closest('[data-user]');
  try { return host ? JSON.parse(host.dataset.user) : {}; }
  catch(e){ return {}; }
}

function openEdit(u){
  _curUser = u;
  document.getElementById('eUid').value   = u.user_id||'';
  document.getElementById('eFname').value = u.fullname||'';
  document.getElementById('eEmail').value = u.email||'';
  document.getElementById('eRole').value  = u.role||'reporter';
  deptLoad('eDeptSel','eDept', u.department||'');
  deptRoleSync('eRole','eDeptSel','eDeptStar','eDeptHint');
  const eType = document.getElementById('eType');
  if(eType) eType.value = (u.user_type||'').toLowerCase();
  const eYear = document.getElementById('eYear');
  if(eYear) eYear.value = u.year_level||'';
  editAuthSync();
  document.getElementById('ePhone').value = u.phone||'';
  document.getElementById('epw').value    = '';
  document.getElementById('estr').style.width='0';
  document.getElementById('editSubtitle').textContent = 'Editing: ' + (u.fullname||'User');
  document.getElementById('editMo').classList.add('open');
}

/* ─── UNIT / DEPARTMENT PICKER ──────────────────────── */
// Select drives the hidden text field that actually submits (name="department").
function deptPick(selId, txtId, val){
  var txt=document.getElementById(txtId);
  if(val==='__other'){ txt.style.display=''; txt.value=''; txt.focus(); }
  else { txt.style.display='none'; txt.value=val; }
}
// Reverse-sync: put an existing value into the select, or fall to "Other…".
function deptLoad(selId, txtId, val){
  var sel=document.getElementById(selId), txt=document.getElementById(txtId);
  val=(val||'').trim();
  var match=false;
  for(var i=0;i<sel.options.length;i++){ if(sel.options[i].value===val){ match=true; break; } }
  if(val===''){ sel.value=''; txt.style.display='none'; txt.value=''; }
  else if(match){ sel.value=val; txt.style.display='none'; txt.value=val; }
  else { sel.value='__other'; txt.style.display=''; txt.value=val; }
}
// When the role is Administrator, the unit is required and must be PMO/ITSO.
function deptRoleSync(roleId, selId, starId, hintId){
  var role=(document.getElementById(roleId)||{}).value||'';
  var isAdmin=(role==='admin');
  var star=document.getElementById(starId), hint=document.getElementById(hintId);
  if(star) star.style.display=isAdmin?'':'none';
  /* The hint starts hidden via the attribute, not an inline style, so it has to
     be toggled by the same thing. Clearing style.display would leave [hidden]
     still applying and the hint would never appear. */
  if(hint) hint.hidden=!isAdmin;
}
// Block submit if an Administrator has no PMO/ITSO unit set.
function deptGuard(roleId, txtId){
  var role=(document.getElementById(roleId)||{}).value||'';
  if(role!=='admin') return true;
  var dept=((document.getElementById(txtId)||{}).value||'').toUpperCase();
  if(dept.indexOf('PMO')!==-1 || dept.indexOf('ITSO')!==-1) return true;
  alert('Please choose the unit this Administrator will oversee — PMO or ITSO.');
  return false;
}

/* ─── PROFILE MODAL ─────────────────────────────────── */
function openProfile(u){
  _curUser = u;
  const avcol = avColor(u.role||'');
  document.getElementById('profAv').style.background = avcol;
  document.getElementById('profAv').textContent = initials(u.fullname||'??');
  document.getElementById('profName').textContent = u.fullname||'—';
  document.getElementById('profId').textContent   = u.user_id||'—';
  // badges
  const pb=document.getElementById('profBadges');
  pb.innerHTML = roleBadge(u.role) + (u.is_directory?'<span class="bdg" style="background:rgba(8,145,178,.12);color:#0891B2;">Directory</span>':'');
  // A directory-imported person has no row in users, so there is nothing for the
  // edit form to update. The table and grid already hide their pencil; this is the
  // third way in, and without it the form only failed once it was filled and sent.
  const peb = document.getElementById('profEditBtn');
  if (peb) { peb.style.display = u.is_directory ? 'none' : ''; }
  document.getElementById('profSubtitle').textContent = (u.email||'') + ' · Joined ' + fmtDate(u.created_at);
  // rows
  const rows=[];
  const has = (v) => v!==undefined && v!==null && String(v).trim()!=='' ;
  const add = (k,v) => { if (has(v)) rows.push([k, escH(String(v))]); };
  const addHtml = (k,v) => { if (v) rows.push([k, v]); };
  add('Full Name', u.fullname);
  add('Email', u.email);
  add('Phone', u.phone);
  add('Contact No.', u.contact_number);
  if (has(u.department)) addHtml('Department', deptBadge(u.department));
  addHtml('Role', roleBadge(u.role||''));
  add('User Type', u.user_type);
  /* Course and Year Level describe a student. Staff accounts were being shown
     them too, because the registration form writes whatever was typed: one
     admin's profile read "Course: Bachelor of Science in Information Systems,
     Year Level: 4th Year", and another had the ITSO office name sitting in the
     course field. Ask the role first — for staff, Position and Specialization
     are the equivalent facts, and they are already below. */
  const isStaff = ['admin','technician','pmo','staff'].indexOf(String(u.role||'').toLowerCase()) !== -1;
  if (!isStaff) {
    add('Course / Program', u.program || u.course);
    add('Year Level', u.year_level);
  }
  add('Specialization', u.specialization);
  add('Position', u.position);
  /* A directory record's user_id IS its student/employee number, so showing it
     as "Account ID" would put the number back on the profile under a different
     label. Registered accounts have a real account id worth showing. */
  if (!u.is_directory) add('Account ID', u.user_id);
  add('Status', u.status);
  rows.push(['Reports Filed', `<span style="font-family:'Outfit',sans-serif;font-weight:800;font-size:.9rem;color:var(--m3);">${u.report_count||0}</span>`]);
  if (!u.is_directory) rows.push(['Active Tasks', `<span style="font-family:'Outfit',sans-serif;font-weight:800;font-size:.9rem;">${u.active_tasks||0}</span>`]);
  addHtml('Created', fmtDate(u.created_at));
  document.getElementById('profRows').innerHTML = rows.map(([k,v])=>
    `<div class="dr"><div class="dk">${k}</div><div class="dv">${v}</div></div>`
  ).join('');
  document.getElementById('profileMo').classList.add('open');
}
function switchToEdit(){
  if(!_curUser)return;
  if(_curUser.is_directory){
    toast('err','This person comes from the BEC directory import and has no login account to edit.','No account');
    return;
  }
  document.getElementById('profileMo').classList.remove('open');
  openEdit(_curUser);
}

/* ─── RESET PASSWORD MODAL ───────────────────────────── */
function openReset(uid,name){
  document.getElementById('resetUid').value=uid;
  document.getElementById('resetSubtitle').textContent='Set a new password for: '+name;
  document.getElementById('rpw').value='';
  document.getElementById('rpw2').value='';
  document.getElementById('rstr').style.width='0';
  document.getElementById('resetMo').classList.add('open');
}
function submitReset(){
  const p1=document.getElementById('rpw').value;
  const p2=document.getElementById('rpw2').value;
  if(p1.length<8){toast('err','Password must be at least 8 characters.','Validation');return;}
  if(p1!==p2){toast('err','Passwords do not match.','Validation');return;}
  document.getElementById('resetForm').submit();
}

/* ─── DELETE ──────────────────────────────────────────── */
function delUser(uid,name){
  if(!confirm('Delete user "'+name+'"?\n\nThis will deactivate their account and cannot be easily undone.'))return;
  document.getElementById('delUid').value=uid;
  document.getElementById('delFrm').submit();
}

/* ─── PASSWORD STRENGTH ──────────────────────────────── */
function checkStrength(inp,barId){
  const v=inp.value;let s=0;
  if(v.length>=8)s++;if(v.length>=12)s++;
  if(/[A-Z]/.test(v))s++;if(/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;
  const bar=document.getElementById(barId);
  const pct=[0,20,40,60,80,100][s];
  const col=s<=1?'#DC2626':s<=2?'#D97706':s<=3?'#2563EB':'#16A34A';
  bar.style.width=pct+'%';bar.style.background=col;
}
function togglePw(id,btn){
  const inp=document.getElementById(id);
  const show=inp.type==='password';
  inp.type=show?'text':'password';
  btn.innerHTML=show?'<i class="fas fa-eye-slash"></i>':'<i class="fas fa-eye"></i>';
}

/* ─── ANIMATED COUNTERS ──────────────────────────────── */
/* The count-up animation went with the summary cards it animated; the tab
   counts are rendered server-side and do not need to be animated into place. */

/* ─── HELPERS ─────────────────────────────────────────── */
function avColor(role){return{admin:'linear-gradient(135deg,#7B1D1D,#C53030)',pmo:'linear-gradient(135deg,#92400E,#F59E0B)',dean:'linear-gradient(135deg,#0F766E,#2DD4BF)',finance:'linear-gradient(135deg,#166534,#4ADE80)',technician:'linear-gradient(135deg,#1D4ED8,#60A5FA)',reporter:'linear-gradient(135deg,#7C3AED,#A78BFA)',student:'linear-gradient(135deg,#0891B2,#22D3EE)'}[role]||'linear-gradient(135deg,#6B7280,#9CA3AF)';}
function initials(n){const p=n.trim().split(/\s+/);return(p[0][0]+(p[1]?p[1][0]:'')).toUpperCase();}
function roleBadge(r){const m={admin:'r-admin',pmo:'r-pmo',dean:'r-dean',finance:'r-fin',technician:'r-tech',reporter:'r-rep',student:'r-stud'};return`<span class="bdg ${m[r]||'r-rep'}">${r||'—'}</span>`;}
function deptBadge(d){if(!d)return'<span style="color:var(--t4)">—</span>';
  const c=d.toLowerCase();
  if(c.includes('itso')||c.includes('computer')||c==='it')return`<span class="dept-itso"><i class="fas fa-laptop-code"></i>${d}</span>`;
  if(c.includes('pmo')||c.includes('physical')||c.includes('maintenance'))return`<span class="dept-pmo"><i class="fas fa-building"></i>${d}</span>`;
  return`<span class="dept-gen"><i class="fas fa-building"></i>${d}</span>`;}
function fmtDate(d){return d?new Date(d).toLocaleDateString('en-US',{month:'long',day:'numeric',year:'numeric'}):'—';}
function escH(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}

/* ─── TOAST ─────────────────────────────────────────── */
function toast(type,msg,title){
  const el=document.createElement('div');el.className='tst '+type;
  el.innerHTML=`<div><div class="tst-t">${title}</div><div class="tst-m">${msg}</div></div>`;
  document.getElementById('ttray').appendChild(el);
  setTimeout(()=>{el.style.transition='opacity .3s';el.style.opacity='0';setTimeout(()=>el.remove(),300);},4000);
}
</script>
<?php require_once __DIR__ . '/includes/csrf_inject.php'; ?>
<script src="assets/sidebar_autohide.js" defer></script>
<script src="assets/search_premium.js"></script>
<script src="assets/table_paginate.js" defer></script>
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<?php require __DIR__ . '/includes/admin_ui.php'; ?>
</body>
</html>





