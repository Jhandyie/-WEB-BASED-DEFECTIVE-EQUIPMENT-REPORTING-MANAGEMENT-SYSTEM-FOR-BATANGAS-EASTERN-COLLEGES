<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Get technician statistics
 */
function getTechnicianStats($technician_id) {
    $conn = getDBConnection();
    
    // Get total assigned tasks (assigned or in_progress)
    $assigned_stmt = $conn->prepare("SELECT COUNT(*) as count FROM defect_reports 
                                      WHERE assigned_to = ? AND status IN ('assigned', 'in_progress')");
    $assigned_stmt->bind_param("s", $technician_id);
    $assigned_stmt->execute();
    $assigned_result = $assigned_stmt->get_result();
    $assigned_row = $assigned_result->fetch_assoc();
    $total_tasks = $assigned_row['count'] ?? 0;
    $assigned_stmt->close();
    
    // Get pending tasks
    $pending_stmt = $conn->prepare("SELECT COUNT(*) as count FROM defect_reports 
                                     WHERE assigned_to = ? AND status = 'assigned'");
    $pending_stmt->bind_param("s", $technician_id);
    $pending_stmt->execute();
    $pending_result = $pending_stmt->get_result();
    $pending_row = $pending_result->fetch_assoc();
    $pending_tasks = $pending_row['count'] ?? 0;
    $pending_stmt->close();
    
    // Get in progress tasks
    $in_progress_stmt = $conn->prepare("SELECT COUNT(*) as count FROM defect_reports 
                                        WHERE assigned_to = ? AND status = 'in_progress'");
    $in_progress_stmt->bind_param("s", $technician_id);
    $in_progress_stmt->execute();
    $in_progress_result = $in_progress_stmt->get_result();
    $in_progress_row = $in_progress_result->fetch_assoc();
    $in_progress_tasks = $in_progress_row['count'] ?? 0;
    $in_progress_stmt->close();
    
    // Get completed today
    $completed_today_stmt = $conn->prepare("SELECT COUNT(*) as count FROM defect_reports 
                                            WHERE assigned_to = ? AND status = 'completed' 
                                            AND DATE(completion_date) = CURDATE()");
    $completed_today_stmt->bind_param("s", $technician_id);
    $completed_today_stmt->execute();
    $completed_today_result = $completed_today_stmt->get_result();
    $completed_today_row = $completed_today_result->fetch_assoc();
    $completed_today = $completed_today_row['count'] ?? 0;
    $completed_today_stmt->close();
    
    // Get total completed
    $total_completed_stmt = $conn->prepare("SELECT COUNT(*) as count FROM defect_reports 
                                            WHERE assigned_to = ? AND status = 'completed'");
    $total_completed_stmt->bind_param("s", $technician_id);
    $total_completed_stmt->execute();
    $total_completed_result = $total_completed_stmt->get_result();
    $total_completed_row = $total_completed_result->fetch_assoc();
    $total_completed = $total_completed_row['count'] ?? 0;
    $total_completed_stmt->close();
    
    // Get unread notifications
    $notif_stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications 
                                  WHERE user_id = ? AND is_read = 0");
    $notif_stmt->bind_param("s", $technician_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result();
    $notif_row = $notif_result->fetch_assoc();
    $unread_notifs = $notif_row['count'] ?? 0;
    $notif_stmt->close();
    
    return [
        'success' => true,
        'stats' => [
            'total_tasks' => $total_tasks,
            'pending_tasks' => $pending_tasks,
            'in_progress_tasks' => $in_progress_tasks,
            'completed_today' => $completed_today,
            'total_completed' => $total_completed,
            'unread_notifications' => $unread_notifs
        ]
    ];
}

/**
 * Get technician tasks
 */
function getTechnicianTasks($technician_id, $status = null) {
    $conn = getDBConnection();
    
if ($status) {
        $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.id as asset_tag, e.location
                                FROM defect_reports dr
                                JOIN equipment e ON dr.equipment_id = e.id
                                WHERE dr.assigned_to = ? AND dr.status = ?
                                ORDER BY dr.priority DESC, dr.assigned_date DESC");
        $stmt->bind_param("ss", $technician_id, $status);
    } else {
        $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.id as asset_tag, e.location
                                FROM defect_reports dr
                                JOIN equipment e ON dr.equipment_id = e.id
                                WHERE dr.assigned_to = ? AND dr.status IN ('assigned', 'in_progress')
                                ORDER BY dr.priority DESC, dr.assigned_date DESC");
        $stmt->bind_param("s", $technician_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $tasks = [];
    while ($row = $result->fetch_assoc()) {
        $tasks[] = $row;
    }
    $stmt->close();
    
    return ['success' => true, 'tasks' => $tasks];
}

/**
 * Get technician notifications
 */
function getTechnicianNotifications($technician_id, $limit = 10) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM notifications 
                            WHERE user_id = ? 
                            ORDER BY created_date DESC 
                            LIMIT ?");
    $stmt->bind_param("si", $technician_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
    
    return ['success' => true, 'notifications' => $notifications];
}

/**
 * Mark notification as read
 */
function markNotificationRead($notification_id, $user_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() 
                            WHERE notification_id = ? AND user_id = ?");
    $stmt->bind_param("is", $notification_id, $user_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return ['success' => $result];
}

/**
 * Get task details
 */
function getTaskDetails($report_id, $technician_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.id as asset_tag, e.location
                            FROM defect_reports dr
                            JOIN equipment e ON dr.equipment_id = e.id
                            WHERE dr.report_id = ? AND dr.assigned_to = ?");
    $stmt->bind_param("is", $report_id, $technician_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $task = $result->fetch_assoc();
    $stmt->close();
    
    if ($task) {
        return ['success' => true, 'task' => $task];
    } else {
        return ['success' => false, 'message' => 'Task not found'];
    }
}

/**
 * Get dashboard data (combined stats and tasks)
 */
function getDashboardData($technician_id) {
    $stats = getTechnicianStats($technician_id);
    $tasks_result = getTechnicianTasks($technician_id);
    $notifications_result = getTechnicianNotifications($technician_id, 5);
    
    return [
        'success' => true,
        'data' => [
            'stats' => $stats['stats'],
            'tasks' => $tasks_result['tasks'],
            'notifications' => $notifications_result['notifications']
        ]
    ];
}

// Check if this is an API request
if (isset($_GET['action'])) {
    // Set content type to JSON
    header('Content-Type: application/json');
    
    // Check if user is logged in for API requests
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['role'] ?? '';
    
    // Only technicians can access this API
    if ($role !== 'technician') {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }
    
    $action = $_GET['action'];
    
    try {
        switch ($action) {
            case 'refresh':
                $result = getDashboardData($user_id);
                echo json_encode($result);
                break;
                
            case 'get_stats':
                $result = getTechnicianStats($user_id);
                echo json_encode($result);
                break;
                
            case 'get_tasks':
                $status = isset($_GET['status']) ? $_GET['status'] : null;
                $result = getTechnicianTasks($user_id, $status);
                echo json_encode($result);
                break;
                
            case 'get_notifications':
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
                $result = getTechnicianNotifications($user_id, $limit);
                echo json_encode($result);
                break;
                
            case 'mark_notification_read':
                $notification_id = isset($_GET['notification_id']) ? (int)$_GET['notification_id'] : null;
                if ($notification_id) {
                    $result = markNotificationRead($notification_id, $user_id);
                    echo json_encode($result);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Notification ID required']);
                }
                break;
                
            case 'get_task_details':
                $report_id = isset($_GET['report_id']) ? (int)$_GET['report_id'] : null;
                if ($report_id) {
                    $result = getTaskDetails($report_id, $user_id);
                    echo json_encode($result);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Report ID required']);
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Continue with normal page load if not an API request
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$role = $_SESSION['role'] ?? '';
if ($role !== 'technician') {
    header('Location: ../login.php');
    exit;
}

$user_name = $_SESSION['fullname'] ?? 'Technician';
$user_id = $_SESSION['user_id'];
$first_name = explode(' ', $user_name)[0];

// Get dashboard data
$dashboardData = getDashboardData($user_id);
$stats = $dashboardData['success'] ? $dashboardData['data']['stats'] : [];
$tasks = $dashboardData['success'] ? $dashboardData['data']['tasks'] : [];
$notifications = $dashboardData['success'] ? $dashboardData['data']['notifications'] : [];

$total_tasks = $stats['total_tasks'] ?? 0;
$pending_tasks = $stats['pending_tasks'] ?? 0;
$in_progress_tasks = $stats['in_progress_tasks'] ?? 0;
$completed_tasks = $stats['completed_tasks'] ?? 0;
$unread_notifs = $stats['unread_notifications'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Technician Dashboard — BEC Equipment Management</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../css/typography.css">
<style>
/* ═══════════════════════════════════════════════════
   BEC EQUIPMENT MANAGEMENT — TECHNICIAN DASHBOARD
   Batangas Eastern Colleges · San Juan, Batangas
   Theme: Maroon #7B1D1D  Gold #D4A017
═══════════════════════════════════════════════════ */
:root {
  --maroon:       #7B1D1D;
  --maroon-d:     #521010;
  --maroon-l:     #9B2C2C;
  --gold:         #D4A017;
  --gold-l:       #F0C040;
  --gold-pale:    #FEF9E7;
  --cream:        #FFFDF7;
  --surface:      #FFFFFF;
  --surface2:     #FBF7F0;
  --border:       #EDE0CC;
  --txt1:         #1A0A0A;
  --txt2:         #6B4040;
  --txt3:         #B08080;
  --pending-bg:   #FEF9E7; --pending-c:  #92600A;
  --prog-bg:      #EBF5FB; --prog-c:     #154360;
  --done-bg:      #EAFAF1; --done-c:     #145A32;
  --reject-bg:    #FDEDEC; --reject-c:   #7B241C;
  --sh1: 0 2px 8px rgba(90,16,16,.07);
  --sh2: 0 6px 24px rgba(90,16,16,.12);
  --sh3: 0 16px 48px rgba(90,16,16,.16);
  --sh3d: 0 8px 0 rgba(82,16,16,.22), 0 14px 32px rgba(90,16,16,.14);
  --sh3dh:0 12px 0 rgba(82,16,16,.26), 0 20px 48px rgba(90,16,16,.18);
  --r1:8px; --r2:14px; --r3:20px; --r4:28px;
}

*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}

html{scroll-behavior:smooth;}

body{
  font-family:'Nunito',sans-serif;
  background:var(--cream);
  color:var(--txt1);
  min-height:100vh;
  overflow-x:hidden;
}

/* ─── SIDEBAR ──────────────────────────────────── */
.sidebar{
  position:fixed;left:0;top:0;
  width:265px;height:100vh;
  background:linear-gradient(180deg, var(--maroon-d) 0%, var(--maroon) 60%, #6B1A1A 100%);
  display:flex;flex-direction:column;
  z-index:300;
  box-shadow:5px 0 30px rgba(82,16,16,.35);
  transition:transform .35s cubic-bezier(.4,0,.2,1);
  overflow:hidden;
}

/* Sidebar texture overlay */
.sidebar::before{
  content:'';position:absolute;inset:0;
  background:
    radial-gradient(circle at 20% 20%, rgba(212,160,23,.1) 0%, transparent 50%),
    radial-gradient(circle at 80% 80%, rgba(212,160,23,.07) 0%, transparent 50%);
  pointer-events:none;
}

/* Animated gear decoration */
.sidebar-gear{
  position:absolute;bottom:-30px;right:-30px;
  width:140px;height:140px;
  opacity:.05;
  animation:gearSpin 20s linear infinite;
}

.sidebar-gear2{
  position:absolute;top:-20px;left:-20px;
  width:90px;height:90px;
  opacity:.04;
  animation:gearSpin 15s linear infinite reverse;
}

@keyframes gearSpin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}

.sidebar-top{
  padding:1.5rem 1.5rem 1rem;
  border-bottom:1px solid rgba(255,255,255,.08);
  position:relative;z-index:1;
}

.logo-area{
  display:flex;align-items:center;gap:.75rem;
  margin-bottom:1rem;
}

.logo-img{
  height:48px;width:auto;
  filter:drop-shadow(0 3px 8px rgba(0,0,0,.4));
  animation:logoFloat 4s ease-in-out infinite;
  flex-shrink:0;
}

@keyframes logoFloat{
  0%,100%{transform:translateY(0) rotate(0deg);}
  50%{transform:translateY(-4px) rotate(1deg);}
}

.logo-texts strong{
  display:block;font-family:'Poppins',sans-serif;
  font-size:.9rem;font-weight:800;color:#fff;
  line-height:1.1;letter-spacing:-.2px;
}
.logo-texts span{
  font-size:.62rem;color:rgba(255,255,255,.4);
  text-transform:uppercase;letter-spacing:2px;
}

/* School seal in sidebar */
.school-seal{
  width:42px;height:42px;border-radius:50%;
  border:2px solid rgba(212,160,23,.4);
  object-fit:cover;
  box-shadow:0 0 12px rgba(212,160,23,.25);
  animation:sealGlow 3s ease-in-out infinite;
  flex-shrink:0;
}

@keyframes sealGlow{
  0%,100%{box-shadow:0 0 8px rgba(212,160,23,.25);}
  50%{box-shadow:0 0 18px rgba(212,160,23,.5);}
}

/* User card in sidebar */
.user-card{
  background:rgba(255,255,255,.06);
  border:1px solid rgba(255,255,255,.1);
  border-radius:var(--r2);
  padding:.875rem 1rem;
  display:flex;align-items:center;gap:.75rem;
  transition:all .2s;
}
.user-card:hover{background:rgba(255,255,255,.1);}

.u-avatar{
  width:40px;height:40px;flex-shrink:0;
  background:linear-gradient(135deg,var(--gold) 0%,#E8B820 50%,var(--maroon-l) 100%);
  border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-family:'Poppins',sans-serif;font-weight:800;
  font-size:.9rem;color:#fff;
  box-shadow:0 4px 0 rgba(0,0,0,.3),0 6px 12px rgba(0,0,0,.2);
  transition:transform .3s;
}
.user-card:hover .u-avatar{transform:scale(1.05) rotate(-5deg);}

.u-name{display:block;font-size:.86rem;color:#fff;font-weight:700;}
.u-role{font-size:.66rem;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:1.2px;}

/* NAV */
.sidebar-nav{flex:1;padding:.5rem 0;overflow-y:auto;position:relative;z-index:1;}
.sidebar-nav::-webkit-scrollbar{width:3px;}
.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:3px;}

.nav-group-label{
  font-size:.58rem;text-transform:uppercase;letter-spacing:2.5px;
  color:rgba(255,255,255,.22);padding:.6rem 1.5rem .25rem;
  font-weight:700;
}

.nav-item{
  display:flex;align-items:center;gap:.8rem;
  padding:.625rem 1.5rem;
  color:rgba(255,255,255,.5);
  background:none;border:none;width:100%;
  text-align:left;font-family:'Nunito',sans-serif;
  font-size:.86rem;font-weight:600;cursor:pointer;
  transition:all .18s;position:relative;
  text-decoration:none;
}
.nav-item i{width:18px;text-align:center;font-size:.88rem;flex-shrink:0;transition:transform .2s;}
.nav-item:hover{color:rgba(255,255,255,.9);background:rgba(255,255,255,.06);}
.nav-item:hover i{transform:scale(1.2);}

.nav-item.active{
  color:#fff;
  background:linear-gradient(90deg,rgba(212,160,23,.2) 0%,rgba(212,160,23,.05) 100%);
}
.nav-item.active::before{
  content:'';position:absolute;left:0;top:0;bottom:0;
  width:3px;background:linear-gradient(to bottom,var(--gold),var(--gold-l));
  border-radius:0 3px 3px 0;
}
.nav-item.active i{color:var(--gold-l);}

.nav-badge{
  margin-left:auto;
  background:linear-gradient(135deg,var(--gold),var(--gold-l));
  color:var(--maroon-d);
  font-size:.62rem;font-weight:900;
  padding:2px 8px;border-radius:20px;
  box-shadow:0 2px 6px rgba(212,160,23,.4);
  animation:badgePulse 2s ease-in-out infinite;
}
@keyframes badgePulse{0%,100%{transform:scale(1);}50%{transform:scale(1.08);}}

.sidebar-footer{
  padding:.75rem 1.5rem 1.25rem;
  border-top:1px solid rgba(255,255,255,.07);
  position:relative;z-index:1;
}

.logout-btn{
  width:100%;display:flex;align-items:center;gap:.7rem;
  padding:.625rem .9rem;
  background:rgba(255,255,255,.05);
  border:1px solid rgba(255,255,255,.08);
  color:rgba(255,255,255,.5);
  border-radius:var(--r1);cursor:pointer;font-size:.84rem;
  font-family:'Nunito',sans-serif;font-weight:600;
  transition:all .2s;
}
.logout-btn:hover{background:rgba(220,38,38,.18);color:#fca5a5;border-color:rgba(220,38,38,.3);}
.logout-btn i{transition:transform .3s;}
.logout-btn:hover i{transform:rotate(180deg);}

/* Mascot in sidebar */
.sidebar-mascot{
  position:absolute;bottom:70px;right:-5px;
  width:80px;height:auto;
  opacity:.6;
  filter:drop-shadow(0 4px 8px rgba(0,0,0,.4));
  animation:mascotBob 3s ease-in-out infinite;
  pointer-events:none;
  z-index:0;
}
@keyframes mascotBob{
  0%,100%{transform:translateY(0) scale(1);}
  50%{transform:translateY(-8px) scale(1.02);}
}

/* ─── MAIN LAYOUT ──────────────────────────────── */
.main{margin-left:265px;min-height:100vh;display:flex;flex-direction:column;}

/* ─── TOPBAR ───────────────────────────────────── */
.topbar{
  background:var(--surface);
  border-bottom:1px solid var(--border);
  height:62px;padding:0 2rem;
  display:flex;align-items:center;justify-content:space-between;
  position:sticky;top:0;z-index:100;
  box-shadow:var(--sh1);
}

.topbar-left{display:flex;align-items:center;gap:.75rem;}
.topbar-title{
  font-family:'Poppins',sans-serif;
  font-weight:700;font-size:1.1rem;color:var(--txt1);
}
.topbar-breadcrumb{
  font-size:.75rem;color:var(--txt3);
  display:flex;align-items:center;gap:.35rem;
}
.topbar-breadcrumb i{font-size:.65rem;}

.topbar-right{display:flex;align-items:center;gap:.875rem;}

.mobile-btn{
  display:none;background:none;border:none;
  font-size:1.2rem;cursor:pointer;
  color:var(--txt2);padding:.25rem;
}

.date-pill{
  background:var(--surface2);border:1px solid var(--border);
  border-radius:30px;padding:.38rem 1rem;
  font-size:.74rem;color:var(--txt2);font-weight:600;
  display:flex;align-items:center;gap:.4rem;
}
.date-pill i{color:var(--gold);font-size:.8rem;}

.topbar-btn{
  width:38px;height:38px;
  background:var(--surface2);border:1px solid var(--border);
  border-radius:var(--r1);
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;color:var(--txt2);font-size:.95rem;
  transition:all .2s;
  box-shadow:0 2px 0 var(--border);
  position:relative;
}
.topbar-btn:hover{
  background:var(--maroon);color:#fff;
  transform:translateY(-2px);
  box-shadow:0 4px 0 var(--maroon-d),var(--sh1);
}

.notif-pip{
  position:absolute;top:6px;right:6px;
  width:8px;height:8px;background:var(--gold);
  border-radius:50%;border:2px solid var(--surface);
  animation:pipPulse 2s ease-in-out infinite;
}
@keyframes pipPulse{0%,100%{transform:scale(1);box-shadow:0 0 0 0 rgba(212,160,23,.4);}50%{transform:scale(1.2);box-shadow:0 0 0 4px rgba(212,160,23,0);}}

/* ─── CONTENT ──────────────────────────────────── */
.content{padding:1.875rem 2rem;flex:1;}

/* ─── HERO BANNER ──────────────────────────────── */
.hero{
  border-radius:var(--r4);
  margin-bottom:1.75rem;
  position:relative;overflow:hidden;
  min-height:185px;
  box-shadow:var(--sh3d);
  transition:transform .3s,box-shadow .3s;
}
.hero:hover{transform:translateY(-4px);box-shadow:var(--sh3dh);}

/* BEC Campus background */
.hero-bg{
  position:absolute;inset:0;
  background-image:url('data:image/png;base64,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
