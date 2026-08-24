<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireRole('admin');
require_once __DIR__ . '/includes/csrf.php';

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['fullname'] ?? 'Administrator';
$conn = getDBConnection();

/* ─── POST ACTIONS ─────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Broadcasting to every user and clearing the whole notification list are
    // state changes like any other — this page was the one admin screen that
    // accepted them without a token. AJAX callers send it as a header.
    requireCsrf(isset($_POST['ajax']));
    $act = $_POST['action'] ?? '';

    /* MARK ONE READ */
    if ($act === 'mark_read') {
        $nid = $_POST['notif_id'] ?? '';
        $stmt = $conn->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE notification_id=? AND user_id=?");
        $stmt->bind_param('ss', $nid, $admin_id);
        $stmt->execute();
        if (isset($_POST['ajax'])) { echo json_encode(['ok'=>true]); exit(); }
    }

    /* MARK ALL READ */
    if ($act === 'mark_all_read') {
        $stmt = $conn->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE user_id=? AND is_read=0");
        $stmt->bind_param('s', $admin_id);
        $stmt->execute();
        $_SESSION['flash'] = ['ok', 'All notifications marked as read.'];
    }

    /* DELETE ONE */
    if ($act === 'delete') {
        $nid = $_POST['notif_id'] ?? '';
        $stmt = $conn->prepare("DELETE FROM notifications WHERE notification_id=? AND user_id=?");
        $stmt->bind_param('ss', $nid, $admin_id);
        $stmt->execute();
        if (isset($_POST['ajax'])) { echo json_encode(['ok'=>true]); exit(); }
        $_SESSION['flash'] = ['ok', 'Notification deleted.'];
    }

    /* DELETE ALL */
    if ($act === 'delete_all') {
        $tf = $_POST['type_filter'] ?? 'all';
        if ($tf !== 'all') {
            $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id=? AND type=?");
            $stmt->bind_param('ss', $admin_id, $tf);
        } else {
            $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id=?");
            $stmt->bind_param('s', $admin_id);
        }
        $stmt->execute();
        $_SESSION['flash'] = ['ok', 'Notifications cleared.'];
    }

    /* BROADCAST (admin sends to all or role) */
    if ($act === 'broadcast') {
        $msg    = trim($_POST['message']   ?? '');
        $target = $_POST['target_role']    ?? 'all';
        $type   = $_POST['notif_type']     ?? 'announcement';
        $link   = trim($_POST['link']      ?? '');

        // The form offers fixed choices, but a direct POST can send anything —
        // an unknown role would simply reach nobody, and an off-site link in a
        // message that carries the institution's name is a phishing vector.
        $allowedTargets = ['all', 'reporter', 'technician', 'pmo', 'admin'];
        $allowedTypes   = ['announcement', 'alert', 'reminder', 'system'];
        if (!in_array($target, $allowedTargets, true)) { $target = 'all'; }
        if (!in_array($type, $allowedTypes, true))     { $type = 'announcement'; }
        if (mb_strlen($msg) > 500) { $msg = mb_substr($msg, 0, 500); }
        // Same-site paths only: no scheme, no host, no protocol-relative "//".
        if ($link !== '' && (preg_match('~^[a-z][a-z0-9+.-]*:~i', $link) || str_starts_with($link, '//'))) {
            $link = '';
        }
        if (mb_strlen($link) > 255) { $link = ''; }

        if ($msg) {
            if ($target === 'all') {
                $res = $conn->query("SELECT user_id FROM users WHERE status='active'");
            } else {
                $stmt2 = $conn->prepare("SELECT user_id FROM users WHERE role=? AND status='active'");
                $stmt2->bind_param('s', $target); $stmt2->execute();
                $res = $stmt2->get_result();
            }
            $users = $res->fetch_all(MYSQLI_ASSOC);
            $sent = 0;
            foreach ($users as $u) {
                $uid = $u['user_id'];
                $stmt3 = $conn->prepare("INSERT INTO notifications (user_id,message,type,link,created_date) VALUES (?,?,?,?,NOW())");
                $stmt3->bind_param('ssss', $uid, $msg, $type, $link);
                $stmt3->execute();
                $sent++;
            }
            logActivity($admin_id, 'notification.broadcast',
                "Broadcast ($type) to $target — $sent recipient(s): " . mb_substr($msg, 0, 120));
            $_SESSION['flash'] = ['ok', "Broadcast sent to $sent user(s)."];
        } else {
            $_SESSION['flash'] = ['err', 'Message cannot be empty.'];
        }
    }

    if (!isset($_POST['ajax'])) {
        header('Location: admin_notifications.php'); exit();
    }
}

/* ─── FILTERS ─────────────────────────────────────── */
$tf  = $_GET['type']   ?? 'all';
$rf  = $_GET['read']   ?? 'all';   // all | unread | read
$sq  = $_GET['search'] ?? '';
$pg  = max(1, (int)($_GET['page'] ?? 1));
$per = 20;

/* ─── DATA ────────────────────────────────────────── */
$where  = "WHERE n.user_id = ?";
$params = [$admin_id];
$types  = 's';

if ($tf !== 'all') {
    $where .= " AND n.type = ?";
    $params[] = $tf; $types .= 's';
}
if ($rf === 'unread') { $where .= " AND n.is_read = 0"; }
if ($rf === 'read')   { $where .= " AND n.is_read = 1"; }
if ($sq !== '') {
    $ql = '%' . $sq . '%';
    $where .= " AND (n.message LIKE ? OR n.type LIKE ?)";
    $params[] = $ql; $params[] = $ql; $types .= 'ss';
}

// Total count
$cnt_stmt = $conn->prepare("SELECT COUNT(*) FROM notifications n $where");
$cnt_stmt->bind_param($types, ...$params);
$cnt_stmt->execute();
$total_count = (int)$cnt_stmt->get_result()->fetch_row()[0];
$total_pages = max(1, ceil($total_count / $per));
$offset = ($pg - 1) * $per;

// Notifications with pagination
$params_pg = array_merge($params, [$per, $offset]);
$types_pg  = $types . 'ii';

$has_sender_id = false;
$sender_cols = $conn->query("SHOW COLUMNS FROM notifications LIKE 'sender_id'");
if ($sender_cols && $sender_cols->num_rows > 0) {
    $has_sender_id = true;
}

if ($has_sender_id) {
    $n_sql = "
        SELECT n.*, u.fullname AS sender_name
        FROM notifications n
        LEFT JOIN users u ON n.sender_id = u.user_id
        $where
        ORDER BY n.created_date DESC
        LIMIT ? OFFSET ?
    ";
} else {
    $n_sql = "
        SELECT n.*, '' AS sender_name
        FROM notifications n
        $where
        ORDER BY n.created_date DESC
        LIMIT ? OFFSET ?
    ";
}

$n_stmt = $conn->prepare($n_sql);
$n_stmt->bind_param($types_pg, ...$params_pg);
$n_stmt->execute();
$notifs = $n_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Unread count (always)
$unread_count = (int)$conn->query("SELECT COUNT(*) FROM notifications WHERE user_id='$admin_id' AND is_read=0")->fetch_row()[0];

// Summary counts by type
$type_counts_res = $conn->query("
    SELECT type, COUNT(*) AS n, SUM(CASE WHEN is_read=0 THEN 1 ELSE 0 END) AS unread
    FROM notifications WHERE user_id='$admin_id'
    GROUP BY type ORDER BY n DESC
");
$type_counts = [];
$total_notifs = 0;
while ($row = $type_counts_res->fetch_assoc()) {
    $type_counts[$row['type']] = $row;
    $total_notifs += (int)$row['n'];
}

// Users for broadcast
$users_cols = [];
$users_cols_res = $conn->query("SHOW COLUMNS FROM users");
while ($users_cols_res && ($uc = $users_cols_res->fetch_assoc())) {
    $users_cols[$uc['Field']] = true;
}
$dept_expr = isset($users_cols['department']) ? 'department' : "'' AS department";
$users_res = $conn->query("SELECT user_id, fullname, role, {$dept_expr} FROM users WHERE status='active' ORDER BY role, fullname");
$all_users = $users_res ? $users_res->fetch_all(MYSQLI_ASSOC) : [];

/* ─── HELPERS ─────────────────────────────────────── */
/* Keyed on the type strings notifications are actually written with. Anything
   unmapped still renders — a neutral bell and a Title Cased label — so a new
   notification type never disappears from the filter. */
function typeIcon($t){
    $m=['new_defect_report'=>'fa-exclamation-triangle','sla_escalation'=>'fa-triangle-exclamation',
        'report_status'=>'fa-sync-alt','follow_up'=>'fa-comment-dots',
        'task_assigned'=>'fa-user-cog','task_completed'=>'fa-circle-check',
        'registration'=>'fa-user-plus','budget_request'=>'fa-peso-sign',
        'announcement'=>'fa-bullhorn','system'=>'fa-cog'];
    return 'fas '.($m[$t]??'fa-bell');
}
function typeColor($t){
    $m=['new_defect_report'=>'#DC2626','sla_escalation'=>'#C2410C',
        'report_status'=>'#0891B2','follow_up'=>'#7C3AED',
        'task_assigned'=>'#2563EB','task_completed'=>'#16A34A',
        'registration'=>'#0891B2','budget_request'=>'#D97706',
        'announcement'=>'#D4A017','system'=>'#6B7280'];
    return $m[$t]??'#7B1D1D';
}
function typeBg($t){
    $m=['new_defect_report'=>'#FFF1F2','sla_escalation'=>'#FFF7ED',
        'report_status'=>'#ECFEFF','follow_up'=>'#F5F3FF',
        'task_assigned'=>'#EFF6FF','task_completed'=>'#F0FDF4',
        'registration'=>'#ECFEFF','budget_request'=>'#FFFBEB',
        'announcement'=>'#FEF9E7','system'=>'#F9FAFB'];
    return $m[$t]??'#FDECEA';
}
function typeLbl($t){
    $m=['new_defect_report'=>'New Defect Report','sla_escalation'=>'SLA Escalation',
        'report_status'=>'Status Update','follow_up'=>'Follow Up',
        'task_assigned'=>'Task Assigned','task_completed'=>'Task Completed',
        'registration'=>'Registration','budget_request'=>'Budget Request',
        'announcement'=>'Broadcast','system'=>'System'];
    return $m[$t]??ucwords(str_replace('_',' ',$t));
}
function timeAgo($ts) {
    if (!$ts) return '—';
    $diff = time() - strtotime($ts);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff/60).'m ago';
    if ($diff < 86400)  return floor($diff/3600).'h ago';
    if ($diff < 604800) return floor($diff/86400).'d ago';
    return date('M j, Y', strtotime($ts));
}
function esc($s){return htmlspecialchars((string)($s??''),ENT_QUOTES,'UTF-8');}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Notifications — BEC Admin</title>
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="css/typography.css">
<link rel="stylesheet" href="assets/css/admin-shell.css">
<style>

/* ═══════════════════════════════════════════════════════
   BEC Admin — Notifications  |  Maroon × Gold × Warm
   Outfit · DM Sans
═══════════════════════════════════════════════════════ */
:root{
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
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--t1);min-height:100vh;overflow-x:hidden;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='400'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.022'/%3E%3C/svg%3E");}

/* ── SIDEBAR ──────────────────────────────────────── */
/* sidebar styling lives in assets/css/admin-shell.css */
.lout i{transition:transform .3s;}.lout:hover i{transform:rotate(180deg);}

/* ── LAYOUT ───────────────────────────────────────── */
.wrap{margin-left:var(--sb);min-height:100vh;display:flex;flex-direction:column;}
.topbar{background:rgba(255,252,245,.93);backdrop-filter:blur(14px);border-bottom:1px solid var(--bdr);height:58px;padding:0 1.75rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:200;box-shadow:var(--sh0);}
.tb-l{display:flex;align-items:center;gap:.55rem;}
.mob-tog{display:none;background:none;border:none;font-size:1.1rem;cursor:pointer;color:var(--t2);}
.pg-title{font-family:'Outfit',sans-serif;font-weight:700;font-size:1rem;color:var(--t1);}
.bc{font-size:.68rem;color:var(--t3);display:flex;align-items:center;gap:.25rem;}
.bc a{color:var(--t3);text-decoration:none;}.bc a:hover{color:var(--m3);}
.bc i{font-size:.55rem;}
.tb-r{display:flex;align-items:center;gap:.55rem;}
.unread-badge{background:linear-gradient(135deg,var(--m3),var(--m4));color:#fff;font-family:'Outfit',sans-serif;font-weight:800;font-size:.65rem;padding:.22rem .65rem;border-radius:20px;box-shadow:none;animation:nbp 2.2s ease-in-out infinite;}
.pg{padding:1.5rem 1.75rem;flex:1;}

/* ── FLASH ────────────────────────────────────────── */
/* .flash lives in assets/css/admin-shell.css — one definition for every admin page. */
@keyframes fIn{from{opacity:0;transform:translateY(-5px);}to{opacity:1;transform:translateY(0);}}

/* ── BUTTONS ──────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:.32rem;padding:.4rem .875rem;border-radius:var(--r1);font-family:'DM Sans',sans-serif;font-size:.77rem;font-weight:700;cursor:pointer;border:none;transition:all .17s;text-decoration:none;white-space:nowrap;}
.btn:hover{transform:none;}.btn:active{transform:translateY(0);}
.btn-maroon{background:linear-gradient(135deg,var(--m3),var(--m4));color:#fff;box-shadow:none;}
.btn-maroon:hover{box-shadow:none;}
.btn-gold{background:linear-gradient(135deg,var(--g2),var(--g3));color:var(--m1);box-shadow:none;}
.btn-gold:hover{box-shadow:none;}
.btn-green{background:linear-gradient(135deg,var(--ok-tx),var(--ok));color:#fff;box-shadow:none;}
.btn-green:hover{box-shadow:none;}
.btn-red{background:linear-gradient(135deg,#B91C1C,var(--bad));color:#fff;box-shadow:none;}
.btn-red:hover{box-shadow:none;}
.btn-ghost{background:var(--s2);color:var(--t2);border:1px solid var(--bdr);}
.btn-ghost:hover{background:var(--s3);}
.btn-sm{padding:.3rem .65rem;font-size:.71rem;}

/* ── TWO-COL LAYOUT ───────────────────────────────── */
.main-grid{display:grid;grid-template-columns:240px 1fr;gap:1.125rem;align-items:start;}

/* ── LEFT SIDEBAR PANEL ───────────────────────────── */
.side-panel{background:var(--s1);border-radius:var(--r3);border:1px solid var(--bdr);box-shadow:var(--sh1);overflow:hidden;position:sticky;top:72px;}
.sp-head{padding:.875rem 1.1rem;border-bottom:1px solid var(--bdr);background:linear-gradient(to right,var(--s2),var(--s1));}
.sp-head h3{font-family:'Outfit',sans-serif;font-size:.85rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:.32rem;margin:0;}
.sp-head h3 i{color:var(--m3);}
.type-item{display:flex;align-items:center;gap:.55rem;padding:.55rem 1.1rem;cursor:pointer;text-decoration:none;transition:all .16s;border-left:3px solid transparent;}
.type-item:hover{background:var(--s2);}
.type-item.on{background:var(--s2);border-left-color:var(--m3);}
.type-item.on .ti-lbl{color:var(--m3);font-weight:700;}
.ti-ico{width:30px;height:30px;border-radius:var(--r1);display:flex;align-items:center;justify-content:center;font-size:.72rem;flex-shrink:0;}
.ti-lbl{font-size:.8rem;color:var(--t2);flex:1;}
.ti-cnt{font-family:'Outfit',sans-serif;font-weight:800;font-size:.75rem;color:var(--t3);}
.ti-unread{background:var(--m3);color:#fff;font-family:'Outfit',sans-serif;font-weight:800;font-size:.57rem;padding:.12rem .42rem;border-radius:20px;margin-left:.2rem;animation:nbp 2.2s ease-in-out infinite;}

/* ── MAIN NOTIF PANEL ─────────────────────────────── */
.notif-panel{background:var(--s1);border-radius:var(--r3);border:1px solid var(--bdr);box-shadow:var(--sh1);overflow:hidden;}
.np-head{padding:.875rem 1.25rem;border-bottom:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(to right,var(--s2),var(--s1));flex-wrap:wrap;gap:.5rem;}
.np-head h3{font-family:'Outfit',sans-serif;font-size:.9rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:.35rem;margin:0;}
.np-head h3 i{color:var(--m3);}
.np-acts{display:flex;gap:.38rem;flex-wrap:wrap;}

/* Filter bar */
.fbar{padding:.75rem 1.1rem;border-bottom:1px solid var(--bdr);display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;background:var(--s2);}
.fsw{position:relative;flex:1;min-width:150px;}
.fsw i{position:absolute;left:.62rem;top:50%;transform:translateY(-50%);color:var(--t3);font-size:.7rem;pointer-events:none;}
.fsi{width:100%;padding:.4rem .65rem .4rem 1.75rem;background:var(--s1);border:1.5px solid var(--bdr);border-radius:var(--r1);font-size:.78rem;color:var(--t1);font-family:'DM Sans',sans-serif;outline:none;transition:border-color .18s;}
.fsi:focus{border-color:var(--m3);box-shadow:0 0 0 3px rgba(123,29,29,.07);}
.fsel{padding:.4rem .62rem;background:var(--s1);border:1.5px solid var(--bdr);border-radius:var(--r1);font-size:.78rem;color:var(--t2);font-family:'DM Sans',sans-serif;outline:none;cursor:pointer;}
.fsel:focus{border-color:var(--m3);}

/* ── NOTIFICATION ITEMS ───────────────────────────── */
.notif-list{}
.ni-group{padding:.55rem 1.25rem .4rem;font-family:'Outfit',sans-serif;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--t3);background:var(--s2);border-bottom:1px solid var(--bdr);}
.ni-item{display:flex;align-items:flex-start;gap:.875rem;padding:.925rem 1.25rem;
  border-bottom:1px solid var(--bdr);position:relative;transition:background .15s;
  cursor:pointer;}
.ni-item:last-child{border-bottom:none;}
.ni-item:hover{background:var(--s2);}
.ni-item.unread{background:linear-gradient(to right,rgba(123,29,29,.035),transparent);}
.ni-item.unread::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(to bottom,var(--m3),var(--m4));}
.ni-item.unread .ni-msg{color:var(--t1);font-weight:600;}

/* Notification icon bubble */
.ni-ico{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.88rem;flex-shrink:0;box-shadow:none;transition:transform .22s;position:relative;}
.ni-item:hover .ni-ico{transform:none;}
.ni-ico .unread-dot{position:absolute;top:0;right:0;width:10px;height:10px;background:var(--m3);border-radius:50%;border:2px solid var(--s1);animation:nbp 2.2s ease-in-out infinite;}

/* Content */
.ni-body{flex:1;min-width:0;}
.ni-type-chip{display:inline-flex;align-items:center;gap:.2rem;padding:.13rem .45rem;border-radius:20px;font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.4px;margin-bottom:.25rem;}
.ni-msg{font-size:.81rem;color:var(--t2);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.ni-meta{display:flex;align-items:center;gap:.55rem;margin-top:.3rem;flex-wrap:wrap;}
.ni-time{font-size:.66rem;color:var(--t4);display:flex;align-items:center;gap:.22rem;}
.ni-sender{font-size:.66rem;color:var(--t3);display:flex;align-items:center;gap:.22rem;}
.ni-link-chip{font-size:.64rem;color:var(--m3);background:var(--gp);border:1px solid rgba(212,160,23,.3);padding:.1rem .42rem;border-radius:20px;text-decoration:none;font-weight:700;}
.ni-link-chip:hover{background:var(--g3);color:var(--m1);}

/* Actions on hover */
.ni-actions{display:flex;flex-direction:column;gap:.3rem;flex-shrink:0;opacity:0;transition:opacity .18s;}
.ni-item:hover .ni-actions{opacity:1;}
.na-btn{width:26px;height:26px;border-radius:var(--r1);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.68rem;transition:all .16s;}
.na-read{background:#EFF6FF;color:#2563EB;}.na-read:hover{background:#DBEAFE;}
.na-del{background:#FFF1F2;color:var(--bad);}.na-del:hover{background:#FFE4E6;}

/* ── SUMMARY CARDS ────────────────────────────────── */
.sum-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:1.25rem;}
.scard{background:var(--s1);border-radius:var(--r3);padding:.9rem 1rem;border:1px solid var(--bdr);
  position:relative;overflow:hidden;transition:all .24s cubic-bezier(.4,0,.2,1);box-shadow:var(--sh0);}
.scard::after{content:'';position:absolute;bottom:0;left:0;width:100%;height:3px;background:var(--sk,var(--m3));
  border-radius:0 0 var(--r3) var(--r3);transform:scaleX(0);transform-origin:left;transition:transform .3s;}
.scard:hover{transform:none;box-shadow:var(--sh2);border-color:transparent;}
.scard:hover::after{transform:scaleX(1);}
.sico{width:34px;height:34px;border-radius:var(--r2);display:flex;align-items:center;justify-content:center;font-size:.8rem;margin-bottom:.45rem;background:var(--sib);color:var(--sic);box-shadow:none;transition:transform .24s;}
.scard:hover .sico{transform:none;}
.snum{font-family:'Outfit',sans-serif;font-size:1.7rem;font-weight:800;color:var(--t1);line-height:1;transition:color .24s;}
.scard:hover .snum{color:var(--sk,var(--m3));}
.slbl{font-size:.57rem;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);font-weight:700;margin-top:.08rem;}
.scard{animation:scIn .3s ease both;}
.scard:nth-child(1){animation-delay:.05s;}.scard:nth-child(2){animation-delay:.10s;}
.scard:nth-child(3){animation-delay:.15s;}.scard:nth-child(4){animation-delay:.20s;}
@keyframes scIn{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}

/* ── PAGINATION ───────────────────────────────────── */
.pgn{display:flex;align-items:center;justify-content:space-between;padding:.875rem 1.25rem;border-top:1px solid var(--bdr);background:var(--s2);}
.pgn-info{font-size:.73rem;color:var(--t3);}
.pgn-btns{display:flex;gap:.3rem;}
.pgn-btn{width:30px;height:30px;border-radius:var(--r1);border:1.5px solid var(--bdr);background:var(--s1);color:var(--t2);display:flex;align-items:center;justify-content:center;font-size:.78rem;cursor:pointer;font-family:'DM Sans',sans-serif;font-weight:700;text-decoration:none;transition:all .16s;}
.pgn-btn:hover{background:var(--m3);color:#fff;border-color:var(--m3);}
.pgn-btn.on{background:var(--m3);color:#fff;border-color:var(--m2);box-shadow:none;}
.pgn-btn.dis{opacity:.35;cursor:not-allowed;pointer-events:none;}

/* ── EMPTY STATE ──────────────────────────────────── */
.empty{text-align:center;padding:3.5rem 2rem;}
.empty-ico{font-size:3rem;margin-bottom:.875rem;display:block;opacity:.15;}
.empty-t{font-family:'Outfit',sans-serif;font-size:1rem;font-weight:700;color:var(--t2);margin-bottom:.38rem;}
.empty-s{font-size:.8rem;color:var(--t3);line-height:1.6;}

/* ── BROADCAST / MODAL ────────────────────────────── */
.mo{position:fixed;inset:0;background:rgba(26,8,8,.6);backdrop-filter:blur(7px);z-index:500;display:none;align-items:flex-start;justify-content:center;padding:1.5rem 1rem;overflow-y:auto;}
.mo.open{display:flex;animation:moFade .18s ease;}
@keyframes moFade{from{opacity:0}to{opacity:1}}
.mw{background:var(--s1);border-radius:var(--r4);width:100%;max-width:520px;box-shadow:var(--sh3);animation:mUp .28s cubic-bezier(.4,0,.2,1);border:1px solid var(--bdr);margin:auto;}
.mw-det{max-width:460px;}
@keyframes mUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.mhd{padding:1.25rem 1.5rem 1rem;background:linear-gradient(120deg,var(--m1) 0%,#3D0A0A 45%,var(--m3) 100%);border-radius:var(--r4) var(--r4) 0 0;display:flex;justify-content:space-between;align-items:flex-start;position:relative;overflow:hidden;}
.mhd::after{content:'';position:absolute;right:-10px;top:-10px;width:100px;height:100px;border-radius:50%;background:rgba(212,160,23,.08);pointer-events:none;animation:sealSpin 18s linear infinite;}
.mhd-t{position:relative;z-index:1;}
.mhd-t h2{font-family:'Outfit',sans-serif;font-size:1.05rem;font-weight:800;color:#fff;}
.mhd-t p{font-size:.7rem;color:rgba(255,255,255,.42);margin-top:.1rem;}
.mx{width:27px;height:27px;background:rgba(255,255,255,.1);border:none;border-radius:50%;color:rgba(255,255,255,.6);font-size:.82rem;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .18s;position:relative;z-index:1;}
.mx:hover{background:rgba(255,255,255,.22);color:#fff;transform:rotate(90deg);}
.mb{padding:1.25rem 1.5rem;}
.mf{padding:.8rem 1.5rem 1.25rem;border-top:1px solid var(--bdr);display:flex;justify-content:flex-end;gap:.45rem;flex-wrap:wrap;background:var(--s2);border-radius:0 0 var(--r4) var(--r4);}
.fg{display:flex;flex-direction:column;gap:.28rem;margin-bottom:.7rem;}
.fl{font-size:.63rem;font-weight:800;text-transform:uppercase;letter-spacing:.65px;color:var(--t2);}
.fl span{color:var(--m3);}
.fc{padding:.5rem .82rem;background:var(--s2);border:1.5px solid var(--bdr);border-radius:var(--r1);font-size:.82rem;color:var(--t1);font-family:'DM Sans',sans-serif;outline:none;transition:all .18s;}
.fc:focus{border-color:var(--m3);background:var(--s1);box-shadow:0 0 0 3px rgba(123,29,29,.07);}
textarea.fc{resize:vertical;min-height:88px;}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:.625rem;}

/* char counter */
.char-count{font-size:.62rem;color:var(--t3);text-align:right;margin-top:.15rem;}
.char-count.warn{color:#D97706;}.char-count.over{color:var(--bad);}

/* Detail modal specific */
.det-notif-ico{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;box-shadow:none;}
.dr{display:flex;gap:.65rem;padding:.42rem 0;border-bottom:1px solid var(--bdr);align-items:flex-start;}
.dr:last-child{border:none;}
.dk{width:105px;flex-shrink:0;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--t3);padding-top:.1rem;}
.dv{font-size:.81rem;color:var(--t1);flex:1;line-height:1.55;}
.msg-box{background:var(--s2);border:1.5px solid var(--bdr);border-radius:var(--r1);padding:.65rem .8rem;font-size:.81rem;line-height:1.65;color:var(--t1);}

/* Target selector pills */
.target-pills{display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.3rem;}
.tpill{padding:.28rem .75rem;border-radius:20px;font-size:.72rem;font-weight:700;cursor:pointer;border:1.5px solid var(--bdr);background:var(--s2);color:var(--t2);transition:all .16s;}
.tpill:hover{transform:none;}
.tpill.sel{background:var(--m3);color:#fff;border-color:var(--m2);}

/* ── TOAST ────────────────────────────────────────── */
/* .ttray / .tst live in assets/css/admin-shell.css — one toast for every admin page. */

/* ── RESPONSIVE ───────────────────────────────────── */
@media(max-width:1100px){.main-grid{grid-template-columns:1fr;}.side-panel{position:static;}}
@media(max-width:768px){.sb{transform:translateX(-100%);}.sb.open{transform:translateX(0);}.wrap{margin-left:0;}.pg{padding:1rem;}.mob-tog{display:flex;}.sum-strip{grid-template-columns:1fr 1fr;}.fg2{grid-template-columns:1fr;}}
/* Mobile: keep the top bar from overflowing + comfortable 44px tap targets */
@media(max-width:560px){
  .topbar{padding:0 .85rem;}
  .tb-r{gap:.4rem;}
  /* top-bar action buttons become icon-only so they fit (still fully functional) */
  .tb-r .btn-sm{font-size:0;padding:0;width:44px;height:44px;justify-content:center;}
  .tb-r .btn-sm i{font-size:.95rem;margin:0;}
  .unread-badge{white-space:nowrap;}
  /* filter + action controls to 44px */
  .fsi,.fsel{min-height:44px;}
  .btn-sm{min-height:44px;}
  .na-btn{min-height:44px;min-width:44px;}   /* per-notification mark-read / delete */
  .pgn-btn{min-height:44px;min-width:44px;display:inline-flex;align-items:center;justify-content:center;}
}
</style>
</head>
<body>

<!-- ════ SIDEBAR ══════════════════════════════════════ -->
<?php $activeNav = 'notifications'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

<!-- ════ MAIN ══════════════════════════════════════════ -->
<div class="wrap">
  <header class="topbar">
    <div class="tb-l">
      <button class="mob-tog" onclick="document.getElementById('sb').classList.toggle('open')"><i class="fas fa-bars"></i></button>
      <div>
        <div class="pg-title">Notifications</div>
        <div class="bc"><a href="admin_dashboard.php"><i class="fas fa-home"></i></a><i class="fas fa-chevron-right"></i><span>Notifications</span></div>
      </div>
    </div>
    <div class="tb-r">
      <?php if($unread_count>0):?>
      <span class="unread-badge"><i class="fas fa-bell"></i> <?php echo $unread_count;?> unread</span>
      <?php endif;?>
      <button class="btn btn-gold btn-sm" onclick="document.getElementById('broadcastMo').classList.add('open')">
        <i class="fas fa-broadcast-tower"></i> Broadcast
      </button>
      <?php if($unread_count>0):?>
      <form method="POST" action="admin_notifications.php" style="display:inline;">
        <input type="hidden" name="action" value="mark_all_read">
        <button class="btn btn-ghost btn-sm" type="submit"><i class="fas fa-check-double"></i> Mark all read</button>
      </form>
      <?php endif;?>
    </div>
  </header>

  <div class="pg">

    <?php if(isset($_SESSION['flash'])): [$ft,$fm]=$_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="flash <?php echo $ft;?>"><i class="fas fa-<?php echo $ft==='ok'?'check-circle':'exclamation-circle';?>"></i><?php echo esc($fm);?></div>
    <?php endif;?>

    <!-- Page Header -->
    <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:1.1rem;gap:1rem;flex-wrap:wrap;">
      <div>
        <h1 style="font-family:'Outfit',sans-serif;font-size:1.45rem;font-weight:800;display:flex;align-items:center;gap:.45rem;">
          <i class="fas fa-bell" style="color:var(--m3);"></i> Notifications
        </h1>
        <p style="font-size:.78rem;color:var(--t3);margin-top:.18rem;">Manage your notifications, send broadcasts, and stay on top of system alerts.</p>
      </div>
    </div>

    <!-- Summary strip -->
    <div class="sum-strip">
      <div class="scard" style="--sk:var(--m3);--sib:#FDECEA;--sic:var(--m3);">
        <div class="sico"><i class="fas fa-bell"></i></div>
        <div class="snum" id="sn0"><?php echo $total_notifs;?></div>
        <div class="slbl">Total</div>
      </div>
      <div class="scard" style="--sk:var(--bad);--sib:#FFF1F2;--sic:var(--bad);">
        <div class="sico" style="<?php echo $unread_count>0?'animation:critGlow 2s ease-in-out infinite;':''?>"><i class="fas fa-envelope"></i></div>
        <div class="snum" id="sn1"><?php echo $unread_count;?></div>
        <div class="slbl">Unread</div>
      </div>
      <div class="scard" style="--sk:var(--ok);--sib:#F0FDF4;--sic:var(--ok);">
        <div class="sico"><i class="fas fa-envelope-open"></i></div>
        <div class="snum" id="sn2"><?php echo $total_notifs - $unread_count;?></div>
        <div class="slbl">Read</div>
      </div>
      <div class="scard" style="--sk:var(--g2);--sib:var(--gp);--sic:var(--g1);">
        <div class="sico"><i class="fas fa-broadcast-tower"></i></div>
        <div class="snum" id="sn3"><?php echo isset($type_counts['announcement'])?(int)$type_counts['announcement']['n']:0;?></div>
        <div class="slbl">Broadcasts</div>
      </div>
    </div>
    <style>@keyframes critGlow{0%,100%{box-shadow:none;}50%{box-shadow:0 0 14px rgba(220,38,38,.4);}}</style>

    <!-- Two-column layout -->
    <div class="main-grid">

      <!-- LEFT: Type filter sidebar -->
      <div class="side-panel">
        <div class="sp-head"><h3><i class="fas fa-filter"></i> Filter by Type</h3></div>

        <!-- All -->
        <a href="?type=all&read=<?php echo esc($rf);?>&search=<?php echo urlencode($sq);?>" class="type-item <?php echo $tf==='all'?'on':'';?>">
          <div class="ti-ico" style="background:#FDECEA;color:var(--m3);"><i class="fas fa-bell"></i></div>
          <span class="ti-lbl">All</span>
          <span class="ti-cnt"><?php echo $total_notifs;?></span>
          <?php if($unread_count>0):?><span class="ti-unread"><?php echo $unread_count;?></span><?php endif;?>
        </a>

        <?php
        /* The types come from the notifications themselves, not a hand-written
           list. The old list offered work_order, announcement, approval,
           rejection, reminder, alert and system -- none of which are ever
           written -- while the seven types that ARE written (new_defect_report,
           sla_escalation, report_status, follow_up, task_completed,
           registration, budget_request) had no filter at all. The panel
           advertised filtering and filtered nothing.
           $type_counts is already GROUP BY type ORDER BY n DESC. */
        $all_types = array_keys($type_counts);
        if ($tf !== 'all' && !in_array($tf, $all_types, true)) { $all_types[] = $tf; }
        foreach($all_types as $t):
          if ($t === '' || $t === null) continue;
          $tc=$type_counts[$t]??null;
          $cnt=(int)($tc['n']??0);
          $unr=(int)($tc['unread']??0);
        ?>
        <a href="?type=<?php echo $t;?>&read=<?php echo esc($rf);?>&search=<?php echo urlencode($sq);?>" class="type-item <?php echo $tf===$t?'on':'';?>">
          <div class="ti-ico" style="background:<?php echo typeBg($t);?>;color:<?php echo typeColor($t);?>;">
            <i class="<?php echo typeIcon($t);?>"></i>
          </div>
          <span class="ti-lbl"><?php echo typeLbl($t);?></span>
          <span class="ti-cnt"><?php echo $cnt;?></span>
          <?php if($unr>0):?><span class="ti-unread"><?php echo $unr;?></span><?php endif;?>
        </a>
        <?php endforeach;?>

        <!-- Divider -->
        <div style="border-top:1px solid var(--bdr);margin:.3rem 0;"></div>
        <div style="padding:.55rem 1.1rem;">
          <div style="font-size:.6rem;text-transform:uppercase;letter-spacing:1.5px;color:var(--t4);font-weight:700;margin-bottom:.4rem;">Read Status</div>
          <?php foreach([['all','All'],['unread','Unread'],['read','Read']] as [$rv,$rl]):?>
          <a href="?type=<?php echo esc($tf);?>&read=<?php echo $rv;?>&search=<?php echo urlencode($sq);?>" class="type-item <?php echo $rf===$rv?'on':'';?>" style="padding:.42rem .75rem;">
            <span class="ti-lbl" style="font-size:.78rem;"><?php echo $rl;?></span>
          </a>
          <?php endforeach;?>
        </div>

        <!-- Danger zone -->
        <?php if($total_notifs>0):?>
        <div style="border-top:1px solid var(--bdr);padding:.75rem 1.1rem;">
          <form method="POST" action="admin_notifications.php" onsubmit="return confirm('Clear <?php echo $tf==='all'?'ALL':'these';?> notifications?')">
            <input type="hidden" name="action" value="delete_all">
            <input type="hidden" name="type_filter" value="<?php echo esc($tf);?>">
            <button type="submit" class="btn btn-red btn-sm" style="width:100%;justify-content:center;">
              <i class="fas fa-trash"></i> Clear <?php echo $tf==='all'?'All':'This Type';?>
            </button>
          </form>
        </div>
        <?php endif;?>
      </div>

      <!-- RIGHT: Notification list -->
      <div class="notif-panel">
        <div class="np-head">
          <h3><i class="fas fa-list"></i>
            <?php echo $tf==='all'?'All Notifications':typeLbl($tf).' Notifications';?>
            <span style="font-family:'Outfit',sans-serif;font-size:.78rem;font-weight:800;color:var(--m3);margin-left:.35rem;">(<?php echo $total_count;?>)</span>
          </h3>
          <div class="np-acts">
            <!-- Mark all read lives once, in the page header action bar. -->
            <button class="btn btn-ghost btn-sm" onclick="location.reload()"><i class="fas fa-sync-alt"></i></button>
          </div>
        </div>

        <!-- Search + filter bar -->
        <div class="fbar">
          <div class="fsw">
            <i class="fas fa-search"></i>
            <input type="text" class="fsi" id="fsq" placeholder="Search notifications…"
              value="<?php echo esc($sq);?>" onkeydown="if(event.key==='Enter'){event.preventDefault();go();}">
          </div>
          <select class="fsel" id="fread" onchange="go()">
            <option value="all"    <?php echo $rf==='all'?'selected':'';?>>All</option>
            <option value="unread" <?php echo $rf==='unread'?'selected':'';?>>Unread only</option>
            <option value="read"   <?php echo $rf==='read'?'selected':'';?>>Read only</option>
          </select>
        </div>

        <!-- Notification list -->
        <div class="notif-list">
          <?php if(empty($notifs)):?>
          <div class="empty">
            <i class="fas fa-bell-slash empty-ico"></i>
            <div class="empty-t">No notifications found</div>
            <div class="empty-s">
              <?php if($sq||$tf!=='all'||$rf!=='all'):?>
              Try adjusting your filters or search terms.
              <?php else:?>
              You're all caught up! New notifications will appear here.
              <?php endif;?>
            </div>
          </div>
          <?php else:
            $bucketOf = function($d){ $t=strtotime((string)$d); if(!$t) return 'Older'; $today=strtotime('today'); if($t>=$today) return 'Today'; if($t>=$today-86400) return 'Yesterday'; if($t>=strtotime('-6 days')) return 'Earlier this week'; return 'Older'; };
            $curBucket=null;
            foreach($notifs as $n):
            $unread = !$n['is_read'];
            $tcolor = typeColor($n['type']??'');
            $tbg    = typeBg($n['type']??'');
            $tico   = typeIcon($n['type']??'');
            $tlbl   = typeLbl($n['type']??'');
            $ago    = timeAgo($n['created_date']??'');
          ?>
          <?php $bkt=$bucketOf($n['created_date']??''); if($bkt!==$curBucket): $curBucket=$bkt; ?>
          <div class="ni-group"><?php echo $bkt;?></div>
          <?php endif;?>
          <div class="ni-item <?php echo $unread?'unread':'';?>" id="ni_<?php echo esc($n['notification_id']);?>">
            <!-- Icon bubble -->
            <div class="ni-ico" style="background:<?php echo $tbg;?>;color:<?php echo $tcolor;?>;">
              <i class="<?php echo $tico;?>"></i>
              <?php if($unread):?><div class="unread-dot"></div><?php endif;?>
            </div>

            <!-- Body -->
            <div class="ni-body" onclick="openDetail(<?php echo htmlspecialchars(json_encode($n),ENT_QUOTES);?>)">
              <div class="ni-type-chip" style="background:<?php echo $tbg;?>;color:<?php echo $tcolor;?>;">
                <i class="<?php echo $tico;?>" style="font-size:.54rem;"></i>
                <?php echo $tlbl;?>
              </div>
              <div class="ni-msg"><?php echo esc($n['message']??'');?></div>
              <div class="ni-meta">
                <span class="ni-time"><i class="fas fa-clock" style="font-size:.58rem;"></i><?php echo $ago;?></span>
                <?php if(!empty($n['sender_name'])):?>
                <span class="ni-sender"><i class="fas fa-user" style="font-size:.58rem;"></i><?php echo esc($n['sender_name']);?></span>
                <?php endif;?>
                <?php if(!empty($n['link'])):?>
                <a href="<?php echo esc($n['link']);?>" class="ni-link-chip" onclick="event.stopPropagation()">
                  <i class="fas fa-arrow-right" style="font-size:.56rem;"></i> View
                </a>
                <?php endif;?>
                <?php if(!$unread && !empty($n['read_at'])):?>
                <span style="font-size:.62rem;color:var(--t4);"><i class="fas fa-check-double" style="color:var(--ok);font-size:.56rem;"></i> <?php echo date('M j g:i A',strtotime($n['read_at']));?></span>
                <?php endif;?>
              </div>
            </div>

            <!-- Hover actions -->
            <div class="ni-actions">
              <?php if($unread):?>
              <button class="na-btn na-read" title="Mark as read"
                onclick="markRead('<?php echo esc($n['notification_id']);?>',this)">
                <i class="fas fa-check"></i>
              </button>
              <?php endif;?>
              <button class="na-btn na-del" title="Delete"
                onclick="deleteNotif('<?php echo esc($n['notification_id']);?>',this)">
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </div>
          <?php endforeach; endif;?>
        </div>

        <!-- Pagination -->
        <?php if($total_pages > 1 || $total_count > 0):?>
        <div class="pgn">
          <div class="pgn-info">
            Showing <?php echo min($offset+1,$total_count);?>–<?php echo min($offset+$per,$total_count);?> of <?php echo $total_count;?> notification<?php echo $total_count!=1?'s':'';?>
          </div>
          <div class="pgn-btns">
            <!-- Prev -->
            <?php $prevUrl = '?type='.urlencode($tf).'&read='.urlencode($rf).'&search='.urlencode($sq).'&page='.($pg-1);?>
            <a href="<?php echo $pg<=1?'#':$prevUrl;?>" class="pgn-btn <?php echo $pg<=1?'dis':'';?>">
              <i class="fas fa-chevron-left"></i>
            </a>
            <!-- Page numbers -->
            <?php
            $start = max(1,$pg-2); $end = min($total_pages,$pg+2);
            if($start>1): ?><a href="?type=<?php echo urlencode($tf);?>&read=<?php echo urlencode($rf);?>&page=1" class="pgn-btn">1</a><?php if($start>2) echo '<span style="color:var(--t4);padding:.2rem .4rem;">…</span>'; endif;
            for($i=$start;$i<=$end;$i++):?>
            <a href="?type=<?php echo urlencode($tf);?>&read=<?php echo urlencode($rf);?>&search=<?php echo urlencode($sq);?>&page=<?php echo $i;?>" class="pgn-btn <?php echo $i===$pg?'on':'';?>"><?php echo $i;?></a>
            <?php endfor;
            if($end<$total_pages): if($end<$total_pages-1) echo '<span style="color:var(--t4);padding:.2rem .4rem;">…</span>'; ?><a href="?type=<?php echo urlencode($tf);?>&read=<?php echo urlencode($rf);?>&page=<?php echo $total_pages;?>" class="pgn-btn"><?php echo $total_pages;?></a><?php endif;?>
            <!-- Next -->
            <?php $nextUrl='?type='.urlencode($tf).'&read='.urlencode($rf).'&search='.urlencode($sq).'&page='.($pg+1);?>
            <a href="<?php echo $pg>=$total_pages?'#':$nextUrl;?>" class="pgn-btn <?php echo $pg>=$total_pages?'dis':'';?>">
              <i class="fas fa-chevron-right"></i>
            </a>
          </div>
        </div>
        <?php endif;?>
      </div>

    </div>
  </div><!-- /pg -->
</div><!-- /wrap -->

<!-- ════ DETAIL MODAL ══════════════════════════════════ -->
<div class="mo" id="detMo" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="mw mw-det">
    <div class="mhd">
      <div class="mhd-t">
        <h2><i class="fas fa-bell" style="margin-right:.3rem;opacity:.8;"></i> Notification Detail</h2>
        <p id="detSubtitle">Full message content</p>
      </div>
      <button class="mx" onclick="document.getElementById('detMo').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="mb">
      <div style="display:flex;align-items:center;gap:.875rem;margin-bottom:1.1rem;padding-bottom:1rem;border-bottom:1.5px solid var(--bdr);">
        <div class="det-notif-ico" id="detIco"><i class="fas fa-bell"></i></div>
        <div>
          <div id="detTypeChip" style="margin-bottom:.3rem;"></div>
          <div id="detTime" style="font-size:.7rem;color:var(--t3);"></div>
        </div>
      </div>
      <div class="dr"><div class="dk">Message</div>
        <div class="dv"><div class="msg-box" id="detMsg">—</div></div>
      </div>
      <div class="dr" id="detSenderRow" style="display:none;">
        <div class="dk">From</div><div class="dv" id="detSender">—</div>
      </div>
      <div class="dr" id="detLinkRow" style="display:none;">
        <div class="dk">Link</div>
        <div class="dv"><a id="detLink" href="#" class="ni-link-chip" style="font-size:.75rem;">Open link</a></div>
      </div>
      <div class="dr">
        <div class="dk">Status</div>
        <div class="dv" id="detStatus">—</div>
      </div>
    </div>
    <div class="mf">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('detMo').classList.remove('open')">Close</button>
      <a id="detOpenLink" href="#" class="btn btn-gold btn-sm" style="display:none;"><i class="fas fa-arrow-right"></i> Open</a>
    </div>
  </div>
</div>

<!-- ════ BROADCAST MODAL ═══════════════════════════════ -->
<div class="mo" id="broadcastMo" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="mw">
    <div class="mhd">
      <div class="mhd-t">
        <h2><i class="fas fa-broadcast-tower" style="margin-right:.3rem;opacity:.8;"></i> Send Broadcast</h2>
        <p>Send a notification to all users or a specific role.</p>
      </div>
      <button class="mx" onclick="document.getElementById('broadcastMo').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="mb">
      <form method="POST" action="admin_notifications.php" id="bcastForm">
        <input type="hidden" name="action" value="broadcast">
        <input type="hidden" name="target_role" id="bcastTarget" value="all">

        <div class="fg">
          <label class="fl">Send To <span>*</span></label>
          <div class="target-pills">
            <?php foreach([['all','Everyone'],['admin','Admins'],['technician','Technicians'],['reporter','Reporters'],['student','Students']] as [$rv,$rl]):?>
            <div class="tpill <?php echo $rv==='all'?'sel':'';?>"
              onclick="selectTarget('<?php echo $rv;?>',this)"><?php echo $rl;?></div>
            <?php endforeach;?>
          </div>
          <div style="font-size:.67rem;color:var(--t3);margin-top:.3rem;" id="bcastTargetHint">
            Will send to all active users (<?php echo count($all_users);?> total)
          </div>
        </div>

        <div class="fg2">
          <div class="fg">
            <label class="fl">Notification Type</label>
            <select name="notif_type" class="fc">
              <option value="announcement">📢 Announcement</option>
              <option value="reminder">⏰ Reminder</option>
              <option value="alert">🚨 Alert</option>
              <option value="system">⚙️ System</option>
            </select>
          </div>
          <div class="fg">
            <label class="fl">Link (optional)</label>
            <input type="text" name="link" class="fc" placeholder="e.g. admin_defect_reports.php">
          </div>
        </div>

        <div class="fg">
          <label class="fl">Message <span>*</span></label>
          <textarea name="message" id="bcastMsg" class="fc" placeholder="Type your announcement or reminder here…" maxlength="500" oninput="countChars()" required></textarea>
          <div class="char-count" id="charCount">0 / 500</div>
        </div>
      </form>
    </div>
    <div class="mf">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('broadcastMo').classList.remove('open')">Cancel</button>
      <button type="submit" form="bcastForm" class="btn btn-maroon btn-sm"><i class="fas fa-paper-plane"></i> Send Broadcast</button>
    </div>
  </div>
</div>

<!-- ════ LOGOUT MODAL ══════════════════════════════════ -->
<div class="mo" id="lgmo" onclick="if(event.target===this)this.classList.remove('open')">
  <div style="background:var(--s1);border-radius:var(--r4);padding:2rem;max-width:330px;width:90%;text-align:center;box-shadow:var(--sh3);animation:mUp .25s ease;margin:auto;">
    <i class="fas fa-sign-out-alt" style="font-size:2.2rem;color:var(--m3);margin-bottom:.7rem;display:block;"></i>
    <h3 style="font-family:'Outfit',sans-serif;font-size:1.05rem;font-weight:800;margin-bottom:.38rem;">Log Out?</h3>
    <p style="font-size:.8rem;color:var(--t2);margin-bottom:1.25rem;line-height:1.6;">You will be returned to the BEC admin login page.</p>
    <div style="display:flex;gap:.55rem;justify-content:center;">
      <button onclick="document.getElementById('lgmo').classList.remove('open')" class="btn btn-ghost btn-sm">Cancel</button>
      <a href="logout.php" class="btn btn-maroon btn-sm"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>
  </div>
</div>

<div class="ttray" id="ttray"></div>

<script>
/* ─── FILTER / SEARCH ────────────────────────────────── */
function go(){
  const u=new URL(location.href);
  u.searchParams.set('type',   '<?php echo esc($tf);?>');
  u.searchParams.set('read',   document.getElementById('fread').value);
  u.searchParams.set('search', document.getElementById('fsq').value);
  u.searchParams.set('page',   '1');
  // becListNav(): shared in includes/admin_ui.php. Guards against re-running
  // the same URL and against a second click on an in-flight navigation.
  becListNav(u.toString());
}

/* ─── ANIMATED COUNTERS ──────────────────────────────── */
function animN(id,to){
  const el=document.getElementById(id);if(!el)return;
  const from=parseInt(el.textContent)||0,dur=700,t0=performance.now();
  const tick=now=>{const p=Math.min((now-t0)/dur,1),e=1-Math.pow(1-p,3);
    el.textContent=Math.round(from+(to-from)*e);if(p<1)requestAnimationFrame(tick);};
  requestAnimationFrame(tick);
}
document.addEventListener('DOMContentLoaded',()=>{
  animN('sn0',<?php echo $total_notifs;?>);
  animN('sn1',<?php echo $unread_count;?>);
  animN('sn2',<?php echo $total_notifs-$unread_count;?>);
  animN('sn3',<?php echo isset($type_counts['announcement'])?(int)$type_counts['announcement']['n']:0;?>);
});

/* ─── MARK READ (AJAX) ───────────────────────────────── */
function markRead(nid, btn) {
  const item = document.getElementById('ni_'+nid);
  fetch('admin_notifications.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=mark_read&notif_id='+encodeURIComponent(nid)+'&ajax=1'
  }).then(r=>r.json()).then(d=>{
    if(d.ok) {
      if(item) {
        item.classList.remove('unread');
        const dot = item.querySelector('.unread-dot');
        if(dot) dot.remove();
        if(btn) btn.remove();
        item.querySelector('.ni-msg')?.style.setProperty('font-weight','400');
      }
      // update badge counts
      const badge = document.querySelector('.unread-badge');
      const sbBadge = document.querySelector('.ni.on .nbadge');
      const cur = badge ? parseInt(badge.textContent)||1 : 1;
      const nw = Math.max(0,cur-1);
      if(badge) { if(nw===0) badge.remove(); else badge.innerHTML='<i class="fas fa-bell"></i> '+nw+' unread'; }
      if(sbBadge) { if(nw===0) sbBadge.remove(); else sbBadge.textContent=nw; }
      toast('ok','Marked as read.','Done');
    }
  }).catch(()=>toast('err','Failed to mark as read.','Error'));
}

/* ─── DELETE (AJAX) ──────────────────────────────────── */
function deleteNotif(nid, btn) {
  if(!confirm('Delete this notification?')) return;
  const item = document.getElementById('ni_'+nid);
  fetch('admin_notifications.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=delete&notif_id='+encodeURIComponent(nid)+'&ajax=1'
  }).then(r=>r.json()).then(d=>{
    if(d.ok && item) {
      item.style.transition='all .3s ease';
      item.style.opacity='0';
      item.style.transform='translateX(30px)';
      item.style.maxHeight=item.offsetHeight+'px';
      setTimeout(()=>{
        item.style.maxHeight='0';
        item.style.padding='0';
        item.style.margin='0';
        item.style.overflow='hidden';
        setTimeout(()=>item.remove(),250);
      },280);
      toast('ok','Notification deleted.','Done');
    }
  }).catch(()=>toast('err','Failed to delete.','Error'));
}

/* ─── DETAIL MODAL ───────────────────────────────────── */
function openDetail(n) {
  /* These must stay in step with typeIcon/typeColor/typeBg/typeLbl in the PHP
     above — same keys, same values, same Title Case fallback. They had drifted
     onto the old hand-written vocabulary (work_order, defect_report,
     status_update, approval, ...), none of which is ever written, so every real
     notification missed every map: the modal showed a maroon bell and a raw
     "new_defect_report" where the list beside it showed "New Defect Report". */
  const typeColors={new_defect_report:'#DC2626',sla_escalation:'#C2410C',
    report_status:'#0891B2',follow_up:'#7C3AED',
    task_assigned:'#2563EB',task_completed:'#16A34A',
    registration:'#0891B2',budget_request:'#D97706',
    announcement:'#D4A017',system:'#6B7280'};
  const typeBgs={new_defect_report:'#FFF1F2',sla_escalation:'#FFF7ED',
    report_status:'#ECFEFF',follow_up:'#F5F3FF',
    task_assigned:'#EFF6FF',task_completed:'#F0FDF4',
    registration:'#ECFEFF',budget_request:'#FFFBEB',
    announcement:'#FEF9E7',system:'#F9FAFB'};
  const typeIcons={new_defect_report:'fa-exclamation-triangle',sla_escalation:'fa-triangle-exclamation',
    report_status:'fa-sync-alt',follow_up:'fa-comment-dots',
    task_assigned:'fa-user-cog',task_completed:'fa-circle-check',
    registration:'fa-user-plus',budget_request:'fa-peso-sign',
    announcement:'fa-bullhorn',system:'fa-cog'};
  const typeLabels={new_defect_report:'New Defect Report',sla_escalation:'SLA Escalation',
    report_status:'Status Update',follow_up:'Follow Up',
    task_assigned:'Task Assigned',task_completed:'Task Completed',
    registration:'Registration',budget_request:'Budget Request',
    announcement:'Broadcast',system:'System'};
  const titleCase = s => String(s||'').replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase());

  const t = n.type||'system';
  const col = typeColors[t]||'#7B1D1D';
  const bg  = typeBgs[t]||'#FDECEA';
  const ico = typeIcons[t]||'fa-bell';
  const lbl = typeLabels[t]||titleCase(t);

  document.getElementById('detIco').style.background=bg;
  document.getElementById('detIco').style.color=col;
  document.getElementById('detIco').innerHTML=`<i class="fas ${ico}"></i>`;
  document.getElementById('detTypeChip').innerHTML=`<span style="background:${bg};color:${col};padding:.15rem .55rem;border-radius:20px;font-size:.62rem;font-weight:800;text-transform:uppercase;">${lbl}</span>`;
  document.getElementById('detSubtitle').textContent=lbl+' · '+timeAgoJs(n.created_date);
  document.getElementById('detTime').textContent='Received: '+(n.created_date||'—');
  document.getElementById('detMsg').textContent=n.message||'—';

  const sr=document.getElementById('detSenderRow');
  if(n.sender_name){sr.style.display='flex';document.getElementById('detSender').textContent=n.sender_name;}
  else sr.style.display='none';

  const lr=document.getElementById('detLinkRow');
  const dl=document.getElementById('detOpenLink');
  if(n.link){lr.style.display='flex';document.getElementById('detLink').href=n.link;dl.style.display='inline-flex';dl.href=n.link;}
  else{lr.style.display='none';dl.style.display='none';}

  document.getElementById('detStatus').innerHTML=n.is_read=='1'||n.is_read===true?
    '<span style="color:var(--ok);font-weight:700;"><i class="fas fa-check-double"></i> Read</span>':
    '<span style="color:var(--m3);font-weight:700;"><i class="fas fa-circle" style="font-size:.55rem;"></i> Unread</span>';

  document.getElementById('detMo').classList.add('open');

  // auto mark read
  if(!n.is_read||n.is_read=='0') markRead(n.notification_id, null);
}

function timeAgoJs(ts) {
  if(!ts) return '—';
  const diff=(Date.now()-new Date(ts).getTime())/1000;
  if(diff<60) return 'Just now';
  if(diff<3600) return Math.floor(diff/60)+'m ago';
  if(diff<86400) return Math.floor(diff/3600)+'h ago';
  if(diff<604800) return Math.floor(diff/86400)+'d ago';
  return new Date(ts).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
}

/* ─── BROADCAST ──────────────────────────────────────── */
const roleCounts = {
  all: <?php echo count($all_users);?>,
  admin: <?php echo count(array_filter($all_users,fn($u)=>$u['role']==='admin'));?>,
  technician: <?php echo count(array_filter($all_users,fn($u)=>$u['role']==='technician'));?>,
  reporter: <?php echo count(array_filter($all_users,fn($u)=>$u['role']==='reporter'));?>,
  student: <?php echo count(array_filter($all_users,fn($u)=>$u['role']==='student'));?>,
};
const roleLabels={all:'all active users',admin:'administrators',technician:'technicians',reporter:'reporters',student:'students'};
function selectTarget(val, el) {
  document.querySelectorAll('.tpill').forEach(p=>p.classList.remove('sel'));
  el.classList.add('sel');
  document.getElementById('bcastTarget').value=val;
  const cnt=roleCounts[val]||0;
  document.getElementById('bcastTargetHint').textContent=`Will send to ${cnt} ${roleLabels[val]||val}`;
}
function countChars(){
  const v=document.getElementById('bcastMsg').value.length;
  const el=document.getElementById('charCount');
  el.textContent=v+' / 500';
  el.className='char-count'+(v>450?' warn':'')+(v>=500?' over':'');
}

/* ─── TOAST ──────────────────────────────────────────── */
function toast(type,msg,title){
  const el=document.createElement('div');el.className='tst '+type;
  el.innerHTML=`<div><div class="tst-t">${title}</div><div class="tst-m">${msg}</div></div>`;
  document.getElementById('ttray').appendChild(el);
  setTimeout(()=>{el.style.transition='opacity .3s';el.style.opacity='0';setTimeout(()=>el.remove(),300);},4000);
}
</script>
<script src="assets/sidebar_autohide.js" defer></script>
<script src="assets/search_premium.js"></script>
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<?php require __DIR__ . '/includes/csrf_inject.php'; ?>
<?php require __DIR__ . '/includes/admin_ui.php'; ?>
</body>
</html>





