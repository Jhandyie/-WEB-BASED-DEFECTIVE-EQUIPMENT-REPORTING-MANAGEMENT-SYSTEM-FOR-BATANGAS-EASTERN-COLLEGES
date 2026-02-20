<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in and has admin role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin/login.html?error=' . urlencode('Unauthorized access'));
    exit();
}

$admin_id = $_SESSION['user_id'];
$admin_name = $_SESSION['fullname'] ?? 'Administrator';

// Handle task assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign') {
    $report_id = $_POST['report_id'];
    $technician_id = $_POST['technician_id'];
    $priority = $_POST['priority'];
    $instructions = $_POST['instructions'];
    $admin_id = $_SESSION['user_id'];

    try {
        $conn->begin_transaction();

        // Update defect report with assignment
        $stmt = $conn->prepare("
            UPDATE defect_reports
            SET assigned_to = ?,
                status = 'assigned',
                priority = ?,
                handler_instructions = ?,
                assigned_date = NOW(),
                assigned_by = ?
            WHERE report_id = ?
        ");
        $stmt->bind_param("isssi", $technician_id, $priority, $instructions, $admin_id, $report_id);
        $stmt->execute();

        // Create notification for technician
        $notification_message = "New maintenance task assigned to you - Report ID: $report_id";
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, message, type, related_id, created_date)
            VALUES (?, ?, 'task_assigned', ?, NOW())
        ");
        $stmt->bind_param("iss", $technician_id, $notification_message, $report_id);
        $stmt->execute();

        // Get technician email for notification
        $stmt = $conn->prepare("SELECT email, fullname FROM maintenance_technicians WHERE technician_id = ?");
        $stmt->bind_param("s", $technician_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $technician = $result->fetch_assoc();

        // Get report details
        $stmt = $conn->prepare("
            SELECT dr.*, e.equipment_name, e.asset_tag, e.location
            FROM defect_reports dr
            JOIN equipment e ON dr.equipment_id = e.equipment_id
            WHERE dr.report_id = ?
        ");
        $stmt->bind_param("s", $report_id);
        $stmt->execute();
        $report = $stmt->get_result()->fetch_assoc();

        $conn->commit();

        // Send email notification (if function exists)
        if (function_exists('sendTaskAssignmentEmail')) {
            sendTaskAssignmentEmail($technician['email'], $technician['fullname'], $report);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Technician assigned successfully'
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => 'Error assigning technician: ' . $e->getMessage()
        ]);
    }
    exit();
}

// Get available technicians
$technicians = getAvailableTechnicians();
$unassigned_reports = getUnassignedReports();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Technicians - BEC Equipment System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/global_styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin_style.css">
    <style>
        .assign-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .assign-modal.show {
            display: flex;
        }

        .assign-modal-content {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .assign-modal-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e0e0e0;
        }

        .assign-modal-header h3 {
            margin: 0;
            color: var(--bec-navy);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--bec-navy);
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            font-family: inherit;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            transition: background-color 0.2s;
        }

        .btn-cancel:hover {
            background: #5a6268;
        }

        .btn-assign {
            background: var(--bec-gold);
            color: var(--bec-navy);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: background-color 0.2s;
        }

        .btn-assign:hover {
            background: #e6b800;
        }

        .btn-assign:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .assignment-success {
            display: none;
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            border: 1px solid #c3e6cb;
        }

        .assignment-error {
            display: none;
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <!-- Sidebar Navigation -->
    <?php include 'includes/admin_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="page-title-section">
                <h1 class="page-title">Assign Technicians</h1>
                <div class="breadcrumb">
                    <span>Home</span>
                    <i class="fas fa-chevron-right"></i>
                    <span>Handler Functions</span>
                    <i class="fas fa-chevron-right"></i>
                    <span>Assign Technicians</span>
                </div>
            </div>
        </div>

        <div class="content-area">
            <!-- Success/Error Messages -->
            <div id="assignment-success" class="assignment-success">
                <i class="fas fa-check-circle"></i>
                Technician assigned successfully!
            </div>
            <div id="assignment-error" class="assignment-error">
                <i class="fas fa-exclamation-triangle"></i>
                <span id="error-message">Error assigning technician. Please try again.</span>
            </div>

            <!-- Unassigned Reports Table -->
            <div class="data-table-card">
                <div class="table-header">
                    <div class="table-title">
                        <i class="fas fa-clipboard-list"></i>
                        Unassigned Defect Reports
                    </div>
                    <div class="table-actions">
                        <button class="btn btn-secondary" onclick="location.reload()">
                            <i class="fas fa-sync"></i>
                            Refresh
                        </button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Report ID</th>
                            <th>Equipment</th>
                            <th>Issue Description</th>
                            <th>Priority</th>
                            <th>Reported Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($unassigned_reports)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 2rem;">
                                <div class="empty-state">
                                    <i class="fas fa-check-circle" style="font-size: 3rem; color: #28a745; margin-bottom: 1rem;"></i>
                                    <h3>All Caught Up!</h3>
                                    <p>No unassigned defect reports at the moment.</p>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($unassigned_reports as $report): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($report['report_id']); ?></td>
                                <td>
                                    <div>
                                        <strong><?php echo htmlspecialchars($report['equipment_name']); ?></strong>
                                        <?php if (!empty($report['asset_tag'])): ?>
                                        <br><small style="color: #666;">Asset: <?php echo htmlspecialchars($report['asset_tag']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars(substr($report['issue_description'], 0, 80)) . (strlen($report['issue_description']) > 80 ? '...' : ''); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo getPriorityClass($report['priority']); ?>">
                                        <?php echo htmlspecialchars(ucfirst($report['priority'])); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y H:i', strtotime($report['report_date'])); ?></td>
                                <td>
                                    <button class="action-btn edit assign-btn"
                                            onclick="openAssignModal('<?php echo $report['report_id']; ?>', '<?php echo htmlspecialchars(addslashes($report['equipment_name'])); ?>')">
                                        <i class="fas fa-user-plus"></i>
                                        Assign
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Assignment Modal -->
    <div id="assign-modal" class="assign-modal">
        <div class="assign-modal-content">
            <div class="assign-modal-header">
                <h3>Assign Technician</h3>
                <button class="close-modal" onclick="closeAssignModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <form id="assign-form">
                <input type="hidden" id="assign-report-id" name="report_id">

                <div class="form-group">
                    <label for="assign-equipment">Equipment:</label>
                    <input type="text" id="assign-equipment" readonly style="background: #f8f9fa;">
                </div>

                <div class="form-group">
                    <label for="assign-technician">Select Technician:</label>
                    <select id="assign-technician" name="technician_id" required>
                        <option value="">Choose a technician...</option>
                        <?php foreach ($technicians as $tech): ?>
                        <option value="<?php echo $tech['technician_id']; ?>">
                            <?php echo htmlspecialchars($tech['fullname']); ?>
                            <?php if (!empty($tech['specialization'])): ?>
                                (<?php echo htmlspecialchars($tech['specialization']); ?>)
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="assign-priority">Priority Level:</label>
                    <select id="assign-priority" name="priority" required>
                        <option value="">Select priority...</option>
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="assign-instructions">Handler Instructions:</label>
                    <textarea id="assign-instructions" name="instructions" placeholder="Provide specific instructions for the technician..."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeAssignModal()">Cancel</button>
                    <button type="submit" class="btn-assign" id="assign-submit-btn">
                        <i class="fas fa-user-plus"></i>
                        Assign Technician
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="js/loading_utils.js"></script>
    <script>
        let currentReportId = null;

        document.addEventListener('DOMContentLoaded', function() {
            // Initialize loading for assign button
            const assignBtn = document.getElementById('assign-submit-btn');
            if (assignBtn) {
                assignBtn.setAttribute('data-loading-text', 'Assigning technician...');
            }
        });

        function openAssignModal(reportId, equipmentName) {
            currentReportId = reportId;
            document.getElementById('assign-report-id').value = reportId;
            document.getElementById('assign-equipment').value = equipmentName;
            document.getElementById('assign-modal').classList.add('show');

            // Reset form
            document.getElementById('assign-form').reset();
            document.getElementById('assignment-success').style.display = 'none';
            document.getElementById('assignment-error').style.display = 'none';
        }

        function closeAssignModal() {
            document.getElementById('assign-modal').classList.remove('show');
            currentReportId = null;
        }

        // Close modal when clicking outside
        document.getElementById('assign-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAssignModal();
            }
        });

        // Handle form submission
        document.getElementById('assign-form').addEventListener('submit', function(e) {
            e.preventDefault();

            showLoading('Assigning technician...');

            const formData = new FormData(this);
            formData.append('action', 'assign');

            fetch('admin_assign_technicians.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showToast('Technician assigned successfully!', 'success');
                    document.getElementById('assignment-success').style.display = 'block';
                    document.getElementById('assignment-error').style.display = 'none';

                    // Close modal after success
                    setTimeout(() => {
                        closeAssignModal();
                        location.reload(); // Refresh to show updated list
                    }, 1500);
                } else {
                    showToast(data.message || 'Error assigning technician', 'error');
                    document.getElementById('assignment-error').style.display = 'block';
                    document.getElementById('assignment-success').style.display = 'none';
                    document.getElementById('error-message').textContent = data.message || 'Error assigning technician. Please try again.';
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showToast('Network error. Please try again.', 'error');
                document.getElementById('assignment-error').style.display = 'block';
                document.getElementById('assignment-success').style.display = 'none';
                document.getElementById('error-message').textContent = 'Network error. Please try again.';
            });
        });

        // Helper function for priority classes (matching PHP function)
        function getPriorityClass(priority) {
            const classes = {
                'critical': 'danger',
                'high': 'warning',
                'medium': 'info',
                'low': 'secondary'
            };
            return classes[priority] || 'secondary';
        }
    </script>
</body>
</html>
