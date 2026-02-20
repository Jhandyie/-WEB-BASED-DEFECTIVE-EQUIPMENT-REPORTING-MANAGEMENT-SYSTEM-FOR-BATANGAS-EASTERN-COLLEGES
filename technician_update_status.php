<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if (!isLoggedIn() || $_SESSION['role'] !== 'technician') {
    header('Location: login.html');
    exit();
}

$technician_id = $_SESSION['user_id'];
$technician_name = $_SESSION['fullname'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $report_id = $_POST['report_id'] ?? '';
    $new_status = $_POST['status'] ?? ($_POST['quick_status'] ?? '');
    $work_details = $_POST['work_details'] ?? '';
    $parts_replaced = $_POST['parts_replaced'] ?? '';
    $repair_cost = $_POST['repair_cost'] ?? 0;

    try {
        if (empty($report_id) || empty($new_status)) {
            throw new Exception('Missing required parameters.');
        }

        // Get current report to check assignment
        $report = getDefectReportById($report_id);
        if (!$report || $report['assigned_to'] != $technician_id) {
            throw new Exception('Unable to update status. Task not found or not assigned to you.');
        }

use only 1        // Update report status (only existing columns)
        $updateData = [
            'status' => $new_status
        ];

        if ($new_status === 'completed') {
            $updateData['completion_date'] = date('Y-m-d H:i:s');
            // Notify handler
            addNotification($report['assigned_by'], "Technician completed work on report $report_id", 'work_completed', $report_id);
        }

        if (!updateDefectReport($report_id, $updateData)) {
            throw new Exception('Failed to update the report.');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Status updated successfully'
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit();
}

$report_id = $_GET['report_id'] ?? '';

// Get report details
$report = getDefectReportById($report_id);

if (!$report || $report['assigned_to'] != $technician_id) {
    header('Location: technician_dashboard.php');
    exit();
}

// Handle quick status updates from task details page
$quick_status = $_GET['status'] ?? ($_GET['quick_status'] ?? '');
if (!empty($quick_status) && in_array($quick_status, ['in_progress', 'completed'])) {
    try {
        $updateData = ['status' => $quick_status];

        if ($quick_status === 'completed') {
            $updateData['completion_date'] = date('Y-m-d H:i:s');
            addNotification($report['assigned_by'], "Technician completed work on report $report_id", 'work_completed', $report_id);
        }

        updateDefectReport($report_id, $updateData);

        header('Location: technician_task_details.php?report_id=' . urlencode($report_id) . '&updated=1');
        exit();
    } catch (Exception $e) {
        $error_message = 'Error updating status: ' . $e->getMessage();
    }
}


// Display the update form
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Task Status - Maintenance Technician</title>
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
            <h1 class="page-title">Update Task Status</h1>
            <a href="technician_task_details.php?report_id=<?php echo urlencode($report_id); ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Task Details
            </a>
        </div>

        <div class="content-area">
            <div class="form-card">
                <h2><i class="fas fa-edit"></i> Update Task Status</h2>

                <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <div class="task-summary">
                    <div class="task-info">
                        <h3><?php echo htmlspecialchars($report['equipment_name']); ?></h3>
                        <p><strong>Task ID:</strong> <?php echo htmlspecialchars($report['report_id']); ?></p>
                        <p><strong>Current Status:</strong>
                            <span class="badge badge-<?php echo getStatusClass($report['status']); ?>">
                                <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($report['status']))); ?>
                            </span>
                        </p>
                        <p><strong>Issue:</strong> <?php echo htmlspecialchars(substr($report['issue_description'], 0, 100)) . '...'; ?></p>
                    </div>
                </div>

                <form id="updateStatusForm" method="POST">
                    <input type="hidden" name="report_id" value="<?php echo htmlspecialchars($report_id); ?>">

                    <div class="form-group">
                        <label for="status">New Status <span class="required">*</span></label>
                        <select name="status" id="status" class="form-control" required>
                            <option value="">Select new status...</option>
                            <?php if ($report['status'] === 'assigned'): ?>
                            <option value="in_progress">In Progress - Start working on this task</option>
                            <?php endif; ?>
                            <?php if ($report['status'] === 'in_progress'): ?>
                            <option value="completed">Completed - Task is finished</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="work_details">Work Details</label>
                        <textarea name="work_details" id="work_details" class="form-control" rows="4"
                                  placeholder="Describe the work performed..."><?php echo htmlspecialchars($report['work_details'] ?? ''); ?></textarea>
                        <small class="form-text">Provide details about the work done, repairs made, or any observations.</small>
                    </div>

                    <div class="form-group">
                        <label for="parts_replaced">Parts Replaced</label>
                        <textarea name="parts_replaced" id="parts_replaced" class="form-control" rows="3"
                                  placeholder="List any parts that were replaced..."><?php echo htmlspecialchars($report['parts_replaced'] ?? ''); ?></textarea>
                        <small class="form-text">List any parts, components, or materials used in the repair.</small>
                    </div>

                    <div class="form-group">
                        <label for="repair_cost">Repair Cost ($)</label>
                        <input type="number" name="repair_cost" id="repair_cost" class="form-control"
                               step="0.01" min="0" value="<?php echo htmlspecialchars($report['repair_cost'] ?? 0); ?>"
                               placeholder="0.00">
                        <small class="form-text">Enter the total cost of parts and materials used.</small>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="history.back()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Status
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="js/loading_utils.js"></script>
    <script>
    document.getElementById('updateStatusForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        const status = formData.get('status');

        if (!status) {
            alert('Please select a new status.');
            return;
        }

        if (!confirm('Are you sure you want to update this task status?')) {
            return;
        }

        showPageLoading('Updating status...');

        fetch('technician_update_status.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Status updated successfully!');
                window.location.href = 'technician_task_details.php?report_id=<?php echo urlencode($report_id); ?>&updated=1';
            } else {
                alert('Error updating status: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while updating the status.');
        })
        .finally(() => {
            hidePageLoading();
        });
    });
    </script>
</body>
</html>
?>