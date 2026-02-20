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

// Get technician statistics
$stats = getTechnicianStatistics($technician_id);
$assigned_tasks = getAssignedTasks($technician_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tasks - Maintenance Technician</title>
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
            <a class="nav-item active" href="technician_tasks.php">
                <i class="fas fa-tasks"></i>
                <span>My Tasks</span>
                <span class="nav-badge"><?php echo $stats['pending_tasks']; ?></span>
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
            <h1 class="page-title">My Maintenance Tasks</h1>
        </div>

        <div class="content-area">
            <div class="stats-grid">
                <div class="stat-card warning">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['pending_tasks']; ?></div>
                            <div class="stat-label">Pending Tasks</div>
                        </div>
                        <div class="stat-icon warning">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card primary">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['in_progress']; ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                        <div class="stat-icon primary">
                            <i class="fas fa-wrench"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['completed_today']; ?></div>
                            <div class="stat-label">Completed Today</div>
                        </div>
                        <div class="stat-icon success">
                            <i class="fas fa-check"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="data-table-card">
                <div class="table-header">
                    <div class="table-title">
                        <i class="fas fa-tasks"></i>
                        Assigned Maintenance Tasks
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Task ID</th>
                            <th>Equipment</th>
                            <th>Issue</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Assigned Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assigned_tasks as $task): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($task['report_id']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($task['equipment_name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($task['location']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars(substr($task['issue_description'], 0, 50)) . '...'; ?></td>
                            <td>
                                <span class="badge badge-<?php echo getPriorityClass($task['priority']); ?>">
                                    <?php echo htmlspecialchars(ucfirst($task['priority'])); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo getStatusClass($task['status']); ?>">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($task['status']))); ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($task['assigned_date'])); ?></td>
                            <td>
                                <div class="action-btns">
                                    <button class="action-btn view" onclick="viewTaskDetails('<?php echo $task['report_id']; ?>')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="action-btn edit" onclick="updateTaskStatus('<?php echo $task['report_id']; ?>')">
                                        <i class="fas fa-edit"></i> Update
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (empty($assigned_tasks)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <h3>No Tasks Assigned</h3>
                    <p>You don't have any maintenance tasks assigned at the moment.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="js/loading_utils.js"></script>
    <script src="js/technician_dashboard.js"></script>
</body>
</html>
