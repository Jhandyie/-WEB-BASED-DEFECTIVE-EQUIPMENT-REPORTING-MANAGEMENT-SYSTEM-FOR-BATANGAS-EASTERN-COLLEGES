<?php
// student/student_dashboard.php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/mail_helper.php';

if (isset($_GET['logout'])) {
    unset($_SESSION['guest_name'], $_SESSION['guest_email'], $_SESSION['guest_since']);
    header('Location: student_index.php');
    exit();
}

// Guard — must have a guest session
if (empty($_SESSION['guest_email']) || empty($_SESSION['guest_name'])) {
    header('Location: student_index.php');
    exit();
}

$student_name  = $_SESSION['guest_name'];
$student_email = $_SESSION['guest_email'];

$error   = '';
$success = '';
$ticket  = '';
$email_notice = '';
$conn = getDBConnection();
$equipment_rows = getAllEquipment();
$equipment_list = array_values(array_map(static function ($row) {
    return [
        'id' => (string)($row['equipment_id'] ?? $row['id'] ?? ''),
        'name' => (string)($row['equipment_name'] ?? ''),
        'category' => (string)($row['category_name'] ?? $row['equipment_category'] ?? $row['category'] ?? 'Uncategorized'),
        'asset_tag' => (string)($row['asset_tag'] ?? ''),
        'location' => trim((string)($row['location'] ?? '')),
    ];
}, $equipment_rows));

$location_options = array_values(array_unique(array_filter(array_map(
    static fn($item) => trim((string)($item['location'] ?? '')),
    $equipment_list
))));
sort($location_options, SORT_NATURAL | SORT_FLAG_CASE);

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

function notifyAdminsOfStudentReport(mysqli $conn, string $reportId, string $equipmentName, string $location, string $studentName): void {
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

function buildStudentTicketEmail(string $student_name, string $ticket, array $report): string {
    $equipment = htmlspecialchars((string)($report['equipment_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $category = htmlspecialchars((string)($report['category'] ?? ''), ENT_QUOTES, 'UTF-8');
    $location = htmlspecialchars((string)($report['location'] ?? ''), ENT_QUOTES, 'UTF-8');
    $issueDate = htmlspecialchars((string)($report['issue_date'] ?? ''), ENT_QUOTES, 'UTF-8');
    $description = nl2br(htmlspecialchars((string)($report['defect_description'] ?? ''), ENT_QUOTES, 'UTF-8'));
    $studentName = htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8');
    $ticketEsc = htmlspecialchars($ticket, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div style="font-family:Arial,sans-serif;color:#2b1a12;line-height:1.6;max-width:640px;margin:0 auto;">
  <div style="background:#7B1D1D;color:#fff;padding:20px 24px;border-radius:14px 14px 0 0;">
    <div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;opacity:.85;">BEC Equipment Reporting</div>
    <h1 style="margin:8px 0 0;font-size:24px;">Report Received</h1>
  </div>
  <div style="border:1px solid #E8DDD0;border-top:none;border-radius:0 0 14px 14px;padding:24px;background:#fff;">
    <p style="margin-top:0;">Hello {$studentName},</p>
    <p>Your equipment report has been submitted successfully. Please keep your ticket number for tracking updates.</p>
    <div style="background:#FFF8E8;border:1px solid #F0D58A;border-radius:12px;padding:16px 18px;margin:20px 0;">
      <div style="font-size:12px;text-transform:uppercase;letter-spacing:1.2px;color:#7B1D1D;font-weight:700;">Ticket Number</div>
      <div style="font-size:28px;font-weight:700;color:#7B1D1D;margin-top:6px;">{$ticketEsc}</div>
    </div>
    <table style="width:100%;border-collapse:collapse;margin:16px 0 20px;">
      <tr><td style="padding:8px 0;font-weight:700;width:160px;">Equipment</td><td style="padding:8px 0;">{$equipment}</td></tr>
      <tr><td style="padding:8px 0;font-weight:700;">Category</td><td style="padding:8px 0;">{$category}</td></tr>
      <tr><td style="padding:8px 0;font-weight:700;">Location</td><td style="padding:8px 0;">{$location}</td></tr>
      <tr><td style="padding:8px 0;font-weight:700;">Issue Noticed</td><td style="padding:8px 0;">{$issueDate}</td></tr>
      <tr><td style="padding:8px 0;font-weight:700;vertical-align:top;">Description</td><td style="padding:8px 0;">{$description}</td></tr>
    </table>
    <p style="margin-bottom:0;">You can use this ticket number when checking your report status.</p>
  </div>
</div>
HTML;
}

// ── POST handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_equipment_id = trim($_POST['equipment_id'] ?? '');
    $selected_equipment = null;
    foreach ($equipment_list as $item) {
        if ($item['id'] === $selected_equipment_id) {
            $selected_equipment = $item;
            break;
        }
    }

    if (!$selected_equipment) {
        $error = 'Please select equipment from the list.';
    } else {
        $_POST['equipment_name'] = $selected_equipment['name'];
        $_POST['category'] = $selected_equipment['category'];
        $_POST['asset_tag'] = $selected_equipment['asset_tag'];
        if (empty(trim($_POST['location'] ?? '')) && $selected_equipment['location'] !== '') {
            $_POST['location'] = $selected_equipment['location'];
        }
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

    if (!$error) {
        // Generate ticket number
        $ticket = 'BEC-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        $reportPriority = inferReportPriority((string)($_POST['defect_description'] ?? ''));

        // Handle photo upload
        $photo_path = null;
        if (!empty($_FILES['photo']['name'])) {
            $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
            $max_size = 15 * 1024 * 1024; // 15MB
            if (!in_array($_FILES['photo']['type'], $allowed)) {
                $error = 'Photo must be JPG, PNG, GIF, or WEBP.';
            } elseif ($_FILES['photo']['size'] > $max_size) {
                $error = 'Photo must be under 15MB.';
            } else {
                $ext        = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $photo_path = 'uploads/reports/' . $ticket . '.' . $ext;
                $photoDest = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $photo_path);
                $photoDir = dirname($photoDest);
                if (!is_dir($photoDir)) {
                    @mkdir($photoDir, 0755, true);
                }
                if (!move_uploaded_file($_FILES['photo']['tmp_name'], $photoDest)) {
                    $error = 'Photo upload failed. Please try again.';
                }
            }
        }

        if (!$error) {
            $reportPayload = [
                'report_id' => $ticket,
                'equipment_id' => trim($_POST['equipment_id']),
                'reported_by' => getGuestReporterId(),
                'issue_description' => trim($_POST['defect_description']),
                'priority' => $reportPriority,
                'status' => 'reported',
            ];

            if ($photo_path !== null) {
                $reportPayload['photo_path'] = $photo_path;
                $reportPayload['defect_photos'] = [$photo_path];
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
<title>Submit Equipment Report — BEC</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --maroon: #7B1D1D;
  --maroon-d: #4A0E0E;
  --maroon-dd: #2D0505;
  --maroon-soft: rgba(123,29,29,.07);
  --gold: #C9960C;
  --gold-l: #F0C040;
  --gold-bg: #FFFBEF;
  --ink: #1C1008;
  --ink2: #5C3838;
  --ink3: #9E8070;
  --paper: #F8F3EA;
  --surface: #FFFFFF;
  --border: #E8DDD0;
  --green: #166534;
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
  border-radius:40px;padding:.35rem .75rem .35rem .45rem;
  box-shadow:var(--shadow-sm);
  font-size:.76rem;color:var(--ink2);
  max-width:100%;
  min-width:0;
  overflow-wrap:anywhere;
}
.user-avatar {
  width:26px;height:26px;border-radius:50%;
  background:var(--maroon-soft);border:1.5px solid rgba(123,29,29,.2);
  display:flex;align-items:center;justify-content:center;
  font-size:.65rem;color:var(--maroon);font-weight:700;
  flex-shrink:0;
}
.logout-link {
  margin-left:.35rem;color:var(--ink3);font-size:.7rem;
  text-decoration:none;transition:color .15s;
}
.logout-link:hover{color:var(--maroon);}

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
.page-sub { font-size:.84rem;color:var(--ink3);line-height:1.6; }

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
  overflow:hidden;
}
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
.section-sub   { font-size:.72rem;color:var(--ink3);margin-top:.1rem; }

/* ── REPORTER INFO (read-only) ── */
.reporter-grid {
  display:grid;grid-template-columns:1fr 1fr;gap:.75rem;
}
.ro-field {
  background:var(--paper);border:1px solid var(--border);
  border-radius:10px;padding:.65rem .9rem;
}
.ro-label { font-size:.65rem;font-weight:600;color:var(--ink3);text-transform:uppercase;letter-spacing:.8px;margin-bottom:.2rem; }
.ro-value { font-size:.85rem;color:var(--ink);font-weight:500; }

/* ── FORM GRID ── */
.form-grid { display:grid;gap:1rem; }
.form-grid.cols-2 { grid-template-columns:1fr 1fr; }
.form-grid.cols-3 { grid-template-columns:1fr 1fr 1fr; }

.fg { }
.fl {
  display:block;font-size:.7rem;font-weight:600;
  color:var(--ink2);margin-bottom:.4rem;
  text-transform:uppercase;letter-spacing:.8px;
}
.fl .req { color:var(--maroon);margin-left:.12rem; }

.fi-wrap { position:relative; }
.fi-icon {
  position:absolute;left:.85rem;top:50%;transform:translateY(-50%);
  color:var(--ink3);font-size:.75rem;pointer-events:none;transition:color .18s;
}
.fi-wrap:focus-within .fi-icon { color:var(--maroon); }

.fi, .fsel, .fta {
  width:100%;
  border:1.5px solid var(--border);border-radius:10px;
  font-family:'DM Sans',sans-serif;font-size:.86rem;color:var(--ink);
  background:#fff;outline:none;
  transition:border-color .18s,box-shadow .18s;
  -webkit-appearance:none;
}
.fi   { padding:.72rem 1rem .72rem 2.4rem; }
.fsel { padding:.72rem 2.4rem .72rem 2.4rem; }
.fta  { padding:.72rem .9rem;resize:vertical;min-height:100px; }
.fi:focus,.fsel:focus,.fta:focus {
  border-color:var(--maroon);
  box-shadow:0 0 0 3px rgba(123,29,29,.09);
}
.fi::placeholder,.fta::placeholder { color:#C4AFA8;font-size:.82rem; }
.fsel { background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%239E8070' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat:no-repeat;background-position:right .85rem center; }
.fi-wrap-sel { position:relative; }
.fi-wrap-sel .fi-icon { pointer-events:none; }

.fi-hint { font-size:.67rem;color:var(--ink3);margin-top:.28rem;display:flex;align-items:center;gap:.3rem; }
.fi-hint i { font-size:.6rem; }

/* ── EQUIPMENT SEARCH AUTOCOMPLETE ── */
.equip-wrap { position:relative; }
.equip-dropdown {
  position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:100;
  background:var(--surface);border:1.5px solid var(--maroon);
  border-radius:10px;box-shadow:0 8px 30px rgba(44,10,10,.15);
  max-height:220px;overflow-y:auto;display:none;
}
.equip-dropdown.open { display:block; }
.search-dd {
  position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:100;
  background:var(--surface);border:1.5px solid var(--maroon);
  border-radius:10px;box-shadow:0 8px 30px rgba(44,10,10,.15);
  max-height:220px;overflow-y:auto;display:none;
}
.search-dd.open { display:block; }
.eq-group-label {
  font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;
  color:var(--ink3);padding:.55rem .85rem .25rem;
  border-top:1px solid var(--border);
}
.eq-group-label:first-child { border-top:none; }
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
.eq-empty { padding:.75rem .85rem;font-size:.82rem;color:var(--ink3);text-align:center; }
.loc-item {
  display:flex;align-items:center;gap:.55rem;
  padding:.6rem .85rem;cursor:pointer;
  transition:background .12s;font-size:.84rem;color:var(--ink);
}
.loc-item:hover,.loc-item.focused { background:var(--maroon-soft); }
.loc-pin {
  width:22px;height:22px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  background:rgba(123,29,29,.08);color:var(--maroon);font-size:.65rem;flex-shrink:0;
}
.loc-meta {
  display:flex;flex-direction:column;gap:.08rem;min-width:0;
}
.loc-name {
  font-weight:600;color:var(--ink);overflow-wrap:anywhere;
}
.loc-sub {
  font-size:.68rem;color:var(--ink3);overflow-wrap:anywhere;
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
.usable-opt:checked + .usable-label.yes  { color:#166534;background:#F0FDF4;border-color:#4ADE80; }
.usable-opt:checked + .usable-label.part { color:#92400E;background:#FFFBEB;border-color:#FCD34D; }
.usable-opt:checked + .usable-label.no   { color:#991B1B;background:#FEF2F2;border-color:#F87171; }

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

/* ── ALERT ── */
.alert {
  padding:.75rem 1rem;border-radius:10px;
  font-size:.8rem;line-height:1.5;margin-bottom:1.25rem;
  display:flex;align-items:flex-start;gap:.55rem;
  animation:riseIn .3s ease;
}
.alert-err { background:#FEF2F2;border:1px solid #FECACA;color:#991B1B; }
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
  box-shadow:0 4px 0 var(--maroon-dd),0 8px 20px rgba(74,14,14,.25);
  letter-spacing:-.01em;-webkit-appearance:none;
}
.btn-submit:hover { background:var(--maroon);transform:translateY(-2px);box-shadow:0 6px 0 var(--maroon-dd),0 14px 28px rgba(74,14,14,.3); }
.btn-submit:active { transform:translateY(1px);box-shadow:0 2px 0 var(--maroon-dd); }
.btn-arrow { width:20px;height:20px;background:rgba(255,255,255,.18);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.65rem;transition:transform .2s; }
.btn-submit:hover .btn-arrow { transform:translateX(3px); }
.btn-cancel { padding:.9rem 1.25rem;border:1.5px solid var(--border);border-radius:11px;color:var(--ink3);font-size:.85rem;font-weight:500;background:none;cursor:pointer;transition:all .18s; text-decoration:none;display:inline-flex;align-items:center; }
.btn-cancel:hover { border-color:var(--maroon);color:var(--maroon); }

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
  color: #166534;
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
  .steps { gap:0;padding:.5rem .75rem; }
  .step span { display:none; }
  .step-connector { width:16px; }
  .submit-row { flex-direction:column; }
  .btn-submit,.btn-cancel { width:100%;justify-content:center; }
  .topbar {
    flex-direction: column;
    align-items: stretch;
  }
  .user-chip {
    justify-content: flex-start;
  }
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
  .ro-value,
  .fi,
  .fsel,
  .fta {
    font-size: .82rem;
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
        <strong>BEC Equipment Reporting</strong>
        <span>User Portal</span>
      </div>
    </div>
    <div class="user-chip">
      <div class="user-avatar"><?php echo strtoupper(substr($student_name,0,1)); ?></div>
      <?php echo htmlspecialchars($student_name); ?>
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

  <form method="POST" enctype="multipart/form-data">

    <!-- ── SECTION 1: REPORTER INFO ── -->
    <div class="section-card">
      <div class="section-head">
        <div class="section-icon"><i class="fas fa-user"></i></div>
        <div>
          <div class="section-title">Reporter Information</div>
          <div class="section-sub">Pre-filled from your session</div>
        </div>
      </div>
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
      <div style="margin-top:.85rem;">
        <label class="fl">Contact Number <span style="color:var(--ink3);font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></label>
        <div class="fi-wrap">
          <i class="fas fa-phone fi-icon"></i>
          <input type="tel" name="student_phone" class="fi" placeholder="e.g. 09xx-xxx-xxxx"
            value="<?php echo htmlspecialchars($_POST['student_phone'] ?? ''); ?>">
        </div>
      </div>
    </div>

    <!-- ── SECTION 2: EQUIPMENT INFO ── -->
    <div class="section-card">
      <div class="section-head">
        <div class="section-icon"><i class="fas fa-desktop"></i></div>
        <div>
          <div class="section-title">Equipment Information</div>
          <div class="section-sub">Search and identify the defective equipment</div>
        </div>
      </div>

      <div class="form-grid">
        <!-- Equipment search -->
        <div class="fg" style="grid-column:1/-1">
          <label class="fl">Equipment Name <span class="req">*</span></label>
          <div class="equip-wrap">
            <div class="fi-wrap">
              <i class="fas fa-search fi-icon"></i>
              <input type="text" id="equip-search" class="fi" placeholder="Type to search equipment…"
                autocomplete="off"
                value="<?php echo htmlspecialchars($_POST['equipment_name'] ?? ''); ?>">
            </div>
            <input type="hidden" name="equipment_id" id="equip-id-hidden" value="<?php echo htmlspecialchars($_POST['equipment_id'] ?? ''); ?>">
            <input type="hidden" name="equipment_name" id="equip-hidden" value="<?php echo htmlspecialchars($_POST['equipment_name'] ?? ''); ?>">
            <input type="hidden" name="category"       id="cat-hidden"   value="<?php echo htmlspecialchars($_POST['category'] ?? ''); ?>">
            <div class="equip-dropdown" id="equip-dropdown"></div>
          </div>
          <div class="fi-hint"><i class="fas fa-lightbulb"></i> Start typing the equipment name and pick a real record from inventory.</div>
        </div>

        <!-- Category (auto-filled) -->
        <div class="fg">
          <label class="fl">Category</label>
          <div class="fi-wrap fi-wrap-sel">
            <i class="fas fa-tag fi-icon"></i>
            <select name="category_display" id="cat-display" class="fsel" disabled>
              <option value="">Auto-filled from equipment</option>
              <?php
              $cats = array_unique(array_column($equipment_list,'category'));
              foreach($cats as $c): ?>
              <option value="<?php echo $c; ?>" <?php echo (($_POST['category']??'')===$c)?'selected':''; ?>><?php echo $c; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Asset Tag -->
        <div class="fg">
          <label class="fl">Asset Tag / Equipment ID <span style="color:var(--ink3);font-weight:400;text-transform:none;letter-spacing:0">(if visible)</span></label>
          <div class="fi-wrap">
            <i class="fas fa-barcode fi-icon"></i>
            <input type="text" name="asset_tag" class="fi" placeholder="e.g. BEC-LAB2-PC05"
              value="<?php echo htmlspecialchars($_POST['asset_tag'] ?? ''); ?>" readonly>
          </div>
          <div class="fi-hint"><i class="fas fa-info-circle"></i> Auto-filled from the selected equipment record.</div>
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
          <label class="fl">Location <span class="req">*</span></label>
          <div class="equip-wrap">
            <div class="fi-wrap">
              <i class="fas fa-map-marker-alt fi-icon"></i>
              <input type="text" id="location-search" class="fi" placeholder="Type to search location…"
                autocomplete="off"
                value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>">
            </div>
            <input type="hidden" name="location" id="location-hidden" value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>">
            <div class="search-dd" id="location-dropdown"></div>
          </div>
          <div class="fi-hint"><i class="fas fa-info-circle"></i> Locations come from the existing equipment records in inventory.</div>
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

      <div class="form-grid">
        <!-- Defect description -->
        <div class="fg" style="grid-column:1/-1">
          <label class="fl">Description of Defect <span class="req">*</span></label>
          <textarea name="defect_description" class="fta" rows="4"
            placeholder="Describe what's wrong with the equipment. Include any error messages, sounds, or behaviors you observed…" required><?php echo htmlspecialchars($_POST['defect_description'] ?? ''); ?></textarea>
          <div class="fi-hint"><i class="fas fa-pen"></i> Be as specific as possible — this helps technicians diagnose faster.</div>
        </div>

        <!-- Date/Time issue noticed -->
        <div class="fg">
          <label class="fl">Date & Time Issue Noticed <span class="req">*</span></label>
          <div class="fi-wrap">
            <i class="fas fa-calendar fi-icon"></i>
            <input type="datetime-local" name="issue_date" class="fi" required
              value="<?php echo htmlspecialchars($_POST['issue_date'] ?? date('Y-m-d\TH:i')); ?>">
          </div>
        </div>

        <!-- Still usable -->
        <div class="fg" style="grid-column:1/-1">
          <label class="fl">Is the equipment still usable? <span class="req">*</span></label>
          <div class="usable-group">
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
          <div class="section-title">Photo Evidence</div>
          <div class="section-sub">Optional but strongly recommended</div>
        </div>
      </div>

      <div class="photo-zone" id="photo-zone">
        <input type="file" name="photo" id="photo-input" accept="image/*" capture="environment">
        <div class="photo-icon"><i class="fas fa-cloud-upload-alt"></i></div>
        <div class="photo-title">Tap to take a photo or upload from gallery</div>
        <div class="photo-sub">JPG, PNG, WEBP — max 15MB</div>
      </div>
      <div class="photo-preview" id="photo-preview">
        <img id="photo-img" src="" alt="Preview">
        <button type="button" class="remove-photo" id="remove-photo"><i class="fas fa-times"></i></button>
      </div>
    </div>

    <!-- ── SUBMIT ── -->
    <div class="submit-row">
      <a href="student_index.php" class="btn-cancel"><i class="fas fa-arrow-left" style="margin-right:.4rem;font-size:.8rem"></i>Back</a>
      <button type="submit" class="btn-submit">
        Submit Report
        <span class="btn-arrow"><i class="fas fa-paper-plane"></i></span>
      </button>
    </div>

  </form>
</div><!-- /page -->

<script>
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
const locationData = <?php echo json_encode(array_values($location_options)); ?>;
const dropdown    = document.getElementById('equip-dropdown');
const locationDropdown = document.getElementById('location-dropdown');

let focusIdx = -1;
let locationFocusIdx = -1;

function groupBy(arr, key) {
  return arr.reduce((acc, item) => {
    (acc[item[key]] = acc[item[key]] || []).push(item);
    return acc;
  }, {});
}

function renderDropdown(query) {
  const q = query.trim().toLowerCase();
  if (!q) { dropdown.classList.remove('open'); return; }

  const matches = equipData.filter(e =>
    (e.name || '').toLowerCase().includes(q) ||
    (e.id || '').toLowerCase().includes(q) ||
    (e.category || '').toLowerCase().includes(q) ||
    (e.asset_tag || '').toLowerCase().includes(q) ||
    (e.location || '').toLowerCase().includes(q)
  );

  if (!matches.length) {
    dropdown.innerHTML = '<div class="eq-empty"><i class="fas fa-search" style="margin-right:.3rem;opacity:.5"></i>No equipment found</div>';
    dropdown.classList.add('open');
    return;
  }

  const grouped = groupBy(matches, 'category');
  let html = '';
  for (const [cat, items] of Object.entries(grouped)) {
    html += `<div class="eq-group-label">${cat}</div>`;
    items.forEach((item) => {
      const safeLocation = item.location ? ` <span style="color:#9E8070;">• ${item.location}</span>` : '';
      html += `<div class="eq-item" data-name="${item.name}" data-cat="${item.category}" data-id="${item.id}" data-asset-tag="${item.asset_tag || ''}" data-location="${item.location || ''}">
        <span class="eq-id">${item.id}</span>${item.name}${safeLocation}
      </div>`;
    });
  }
  dropdown.innerHTML = html;
  dropdown.classList.add('open');
  focusIdx = -1;

  dropdown.querySelectorAll('.eq-item').forEach(el => {
    el.addEventListener('mousedown', e => {
      e.preventDefault();
      selectEquip(el.dataset);
    });
  });
}

function selectEquip(data) {
  searchEl.value   = data.name || '';
  equipIdEl.value  = data.id || '';
  hiddenEl.value   = data.name || '';
  catHidden.value  = data.cat || '';
  assetTagEl.value = data.assetTag || '';
  if (data.location) {
    locationSearchEl.value = data.location;
    locationHiddenEl.value = data.location;
  }
  for (const opt of catDisplay.options) {
    opt.selected = opt.value === (data.cat || '');
  }
  dropdown.classList.remove('open');
  focusIdx = -1;
}

searchEl.addEventListener('input', () => {
  if (!searchEl.value.trim()) {
    equipIdEl.value = '';
    hiddenEl.value = '';
    catHidden.value = '';
    assetTagEl.value = '';
    locationSearchEl.value = '';
    locationHiddenEl.value = '';
    for (const opt of catDisplay.options) {
      opt.selected = opt.value === '';
    }
  }
  renderDropdown(searchEl.value);
});
searchEl.addEventListener('focus', () => { if(searchEl.value) renderDropdown(searchEl.value); });
searchEl.addEventListener('blur',  () => setTimeout(() => dropdown.classList.remove('open'), 150));

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
    selectEquip(el.dataset);
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
}

function renderLocationDropdown(query) {
  const q = (query || '').trim().toLowerCase();
  const matches = q
    ? locationData.filter(location => location.toLowerCase().includes(q))
    : locationData.slice(0, 8);

  if (!matches.length) {
    locationDropdown.innerHTML = '<div class="eq-empty"><i class="fas fa-map-marker-alt" style="margin-right:.3rem;opacity:.5"></i>No location found</div>';
    locationDropdown.classList.add('open');
    return;
  }

  locationDropdown.innerHTML = matches.map(location => `
    <div class="loc-item" data-location="${location}">
      <span class="loc-pin"><i class="fas fa-map-marker-alt"></i></span>
      <span class="loc-meta">
        <span class="loc-name">${location}</span>
        <span class="loc-sub">Inventory location</span>
      </span>
    </div>
  `).join('');
  locationDropdown.classList.add('open');
  locationFocusIdx = -1;

  locationDropdown.querySelectorAll('.loc-item').forEach(el => {
    el.addEventListener('mousedown', e => {
      e.preventDefault();
      selectLocation(el.dataset.location || '');
    });
  });
}

function selectLocation(location) {
  locationSearchEl.value = location;
  locationHiddenEl.value = location;
  locationDropdown.classList.remove('open');
  locationFocusIdx = -1;
}

locationSearchEl.addEventListener('input', () => {
  locationHiddenEl.value = locationSearchEl.value.trim();
  renderLocationDropdown(locationSearchEl.value);
});
locationSearchEl.addEventListener('focus', () => renderLocationDropdown(locationSearchEl.value));
locationSearchEl.addEventListener('blur', () => setTimeout(() => locationDropdown.classList.remove('open'), 150));
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
    selectLocation(items[locationFocusIdx].dataset.location || '');
  } else if (e.key === 'Escape') {
    locationDropdown.classList.remove('open');
  }
});

// ── Photo upload preview ──────────────────────────────────────────────────
const photoInput   = document.getElementById('photo-input');
const photoZone    = document.getElementById('photo-zone');
const photoPreview = document.getElementById('photo-preview');
const photoImg     = document.getElementById('photo-img');
const removePhoto  = document.getElementById('remove-photo');

photoInput.addEventListener('change', () => {
  const file = photoInput.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    photoImg.src = e.target.result;
    photoPreview.style.display = 'block';
    photoZone.style.display    = 'none';
  };
  reader.readAsDataURL(file);
});

removePhoto.addEventListener('click', () => {
  photoInput.value   = '';
  photoPreview.style.display = 'none';
  photoZone.style.display    = '';
});

// Drag & drop highlight
['dragenter','dragover'].forEach(ev => photoZone.addEventListener(ev, e => { e.preventDefault(); photoZone.classList.add('drag'); }));
['dragleave','drop'].forEach(ev => photoZone.addEventListener(ev, e => { e.preventDefault(); photoZone.classList.remove('drag'); }));
</script>
</body>
</html>
