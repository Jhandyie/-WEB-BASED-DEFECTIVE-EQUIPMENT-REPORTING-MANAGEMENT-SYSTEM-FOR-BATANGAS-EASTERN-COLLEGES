<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if (!isLoggedIn() || $_SESSION['role'] !== 'technician') {
    header('Location: login.html');
    exit();
}

$technician_id = $_SESSION['user_id'];
$technician_name = $_SESSION['fullname'];

$report_id = $_GET['report_id'] ?? '';
if (empty($report_id)) {
    header('Location: technician_dashboard.php');
    exit();
}

// Get task details
$task = getDefectReportById($report_id);
if (!$task) {
    header('Location: technician_dashboard.php');
    exit();
}
// Allow viewing if unassigned; only allow updates if assigned to this technician
$can_update = ($task['assigned_to'] === $technician_id);
$is_unassigned = (empty($task['assigned_to']) && $task['status'] === 'reported');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Details - Maintenance Technician</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/global_styles.css">
    <link rel="stylesheet" href="css/technician_styles.css">
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <i class="fas fa-wrench"></i>
            </div>
            <h3>Maintenance Technician</h3>
            <p class="subtitle"><?php echo htmlspecialchars($technician_name); ?></p>
        </div>

        <div class="nav-section">
            <a class="nav-item" href="technician_dashboard.php">
                <i class="fas fa-th-large"></i>
                <span>Dashboard</span>
            </a>
            <a class="nav-item" href="technician_tasks.php">
                <i class="fas fa-tasks"></i>
                <span>My Tasks</span>
            </a>
            <a class="nav-item" href="technician_history.php">
                <i class="fas fa-history"></i>
                <span>Work History</span>
            </a>
        </div>

        <div class="nav-section">
            <a class="nav-item" href="logout.php">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <div class="main-content">
        <div class="top-header">
            <h1 class="page-title">Task Details</h1>
            <a href="technician_tasks.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Tasks
            </a>
        </div>

        <div class="content-area">
            <div class="task-details-card">
                <div class="task-header">
                    <div class="task-title">
                        <h2><?php echo htmlspecialchars($task['equipment_name']); ?></h2>
                        <span class="task-id">Task ID: <?php echo htmlspecialchars($task['report_id']); ?></span>
                    </div>
                    <div class="task-status">
                        <span class="badge badge-<?php echo getStatusClass($task['status']); ?>">
                            <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($task['status']))); ?>
                        </span>
                    </div>
                </div>

                <div class="task-content">
                    <div class="task-info-grid">
                        <div class="info-item">
                            <label>Asset Tag:</label>
                            <span><?php echo htmlspecialchars($task['asset_tag']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Location:</label>
                            <span><?php echo htmlspecialchars($task['location']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Priority:</label>
                            <span class="badge badge-<?php echo getPriorityClass($task['priority']); ?>">
                                <?php echo htmlspecialchars(ucfirst($task['priority'])); ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <label>Reported Date:</label>
                            <span><?php echo date('M d, Y H:i', strtotime($task['report_date'])); ?></span>
                        </div>
                        <?php if ($task['assigned_date']): ?>
                        <div class="info-item">
                            <label>Assigned Date:</label>
                            <span><?php echo date('M d, Y H:i', strtotime($task['assigned_date'])); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($task['completion_date']): ?>
                        <div class="info-item">
                            <label>Completed Date:</label>
                            <span><?php echo date('M d, Y H:i', strtotime($task['completion_date'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="task-description">
                        <h3>Issue Description</h3>
                        <p><?php echo nl2br(htmlspecialchars($task['issue_description'])); ?></p>
                    </div>

                    <?php if ($task['technician_notes']): ?>
                    <div class="task-notes">
                        <h3>Technician Notes</h3>
                        <p><?php echo nl2br(htmlspecialchars($task['technician_notes'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ($task['handler_instructions']): ?>
                    <div class="task-instructions">
                        <h3>Handler Instructions</h3>
                        <p><?php echo nl2br(htmlspecialchars($task['handler_instructions'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="task-actions">
                    <?php if ($can_update): ?>
                        <?php if ($task['status'] === 'assigned'): ?>
                        <button class="btn btn-primary" onclick="quickStatusUpdate('<?php echo $task['report_id']; ?>', 'in_progress')">
                            <i class="fas fa-play"></i> Start Working
                        </button>
                        <?php elseif ($task['status'] === 'in_progress'): ?>
                        <button class="btn btn-success" onclick="quickStatusUpdate('<?php echo $task['report_id']; ?>', 'completed')">
                            <i class="fas fa-check"></i> Mark Complete
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-secondary" onclick="window.location.href='technician_update_status.php?report_id=<?php echo urlencode($task['report_id']); ?>'">
                            <i class="fas fa-edit"></i> Detailed Update
                        </button>
                    <?php elseif ($is_unassigned): ?>
                        <button class="btn btn-success" onclick="claimTask('<?php echo $task['report_id']; ?>')">
                            <i class="fas fa-hand-paper"></i> Claim Task
                        </button>
                    <?php else: ?>
                        <div class="alert alert-info" style="margin-top:10px;">
                            <i class="fas fa-info-circle"></i>
                            This task is assigned to another technician. You can view details but cannot update it.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="js/loading_utils.js"></script>
    <script src="js/technician_dashboard.js"></script>
</body>
</html>
