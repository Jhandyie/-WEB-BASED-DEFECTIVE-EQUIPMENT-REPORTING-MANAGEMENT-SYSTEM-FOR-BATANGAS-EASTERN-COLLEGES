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

// Get work history
$work_history = getTechnicianWorkHistory($technician_id);

// Calculate statistics
$total_completed = count($work_history);
$this_month = count(array_filter($work_history, function($task) {
    return date('Y-m', strtotime($task['completion_date'])) === date('Y-m');
}));
$this_week = count(array_filter($work_history, function($task) {
    return date('Y-W', strtotime($task['completion_date'])) === date('Y-W');
}));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Work History - Maintenance Technician</title>
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
            <a class="nav-item active" href="technician_history.php">
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
            <h1 class="page-title">Work History</h1>
        </div>

        <div class="content-area">
            <div class="stats-grid">
                <div class="stat-card success">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $total_completed; ?></div>
                            <div class="stat-label">Total Completed</div>
                        </div>
                        <div class="stat-icon success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card info">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $this_month; ?></div>
                            <div class="stat-label">This Month</div>
                        </div>
                        <div class="stat-icon info">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card primary">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $this_week; ?></div>
                            <div class="stat-label">This Week</div>
                        </div>
                        <div class="stat-icon primary">
                            <i class="fas fa-calendar-week"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="data-table-card">
                <div class="table-header">
                    <div class="table-title">
                        <i class="fas fa-history"></i>
                        Completed Maintenance Tasks
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Task ID</th>
                            <th>Equipment</th>
                            <th>Issue</th>
                            <th>Priority</th>
                            <th>Completed Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($work_history as $task): ?>
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
                            <td><?php echo date('M d, Y H:i', strtotime($task['completion_date'])); ?></td>
                            <td>
                                <div class="action-btns">
                                    <button class="action-btn view" onclick="viewTaskDetails('<?php echo $task['report_id']; ?>')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (empty($work_history)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Work History</h3>
                    <p>You haven't completed any maintenance tasks yet.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="loading-overlay">
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading...</p>
        </div>
    </div>

    <script src="js/loading_utils.js"></script>

    <!-- Loading Overlay - Add to every page -->
    <div id="global-loading-overlay" class="loading-overlay">
        <div class="loading-spinner-container">
            <div class="loading-spinner"></div>
            <p class="loading-text">Loading...</p>
        </div>
    </div>

    <script>
    function viewTaskDetails(reportId) {
        window.location.href = `technician_task_details.php?report_id=${reportId}`;
    }
    </script>
</body>
</html>
