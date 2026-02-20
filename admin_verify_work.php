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

// Handle work verification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'verify') {
        $task_id = $_POST['task_id'];
        $verification_notes = $_POST['verification_notes'] ?? '';

        try {
            $conn->begin_transaction();

            // Update task status to verified
            $stmt = $conn->prepare("
                UPDATE defect_reports
                SET status = 'verified',
                    verified_by = ?,
                    verification_date = NOW(),
                    verification_notes = ?
                WHERE report_id = ?
            ");
            $stmt->bind_param("sss", $admin_id, $verification_notes, $task_id);
            $stmt->execute();

            // Get technician who completed the work
            $stmt = $conn->prepare("SELECT assigned_to FROM defect_reports WHERE report_id = ?");
            $stmt->bind_param("s", $task_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $task = $result->fetch_assoc();

            // Create notification for technician
            if ($task && $task['assigned_to']) {
                $notification_message = "Your work on report #$task_id has been verified and approved.";
                $stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, message, type, related_id, created_date)
                    VALUES (?, ?, 'work_verified', ?, NOW())
                ");
                $stmt->bind_param("iss", $task['assigned_to'], $notification_message, $task_id);
                $stmt->execute();
            }

            $conn->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Work verified successfully'
            ]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode([
                'success' => false,
                'message' => 'Error verifying work: ' . $e->getMessage()
            ]);
        }
    } elseif ($action === 'reject') {
        $task_id = $_POST['task_id'];
        $rejection_reason = $_POST['rejection_reason'];

        try {
            $conn->begin_transaction();

            // Update task status back to in_progress
            $stmt = $conn->prepare("
                UPDATE defect_reports
                SET status = 'in_progress',
                    handler_notes = CONCAT(COALESCE(handler_notes, ''), '\nRejected by admin: ', ?),
                    verification_date = NULL,
                    verified_by = NULL
                WHERE report_id = ?
            ");
            $stmt->bind_param("ss", $rejection_reason, $task_id);
            $stmt->execute();

            // Get technician who completed the work
            $stmt = $conn->prepare("SELECT assigned_to FROM defect_reports WHERE report_id = ?");
            $stmt->bind_param("s", $task_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $task = $result->fetch_assoc();

            // Create notification for technician
            if ($task && $task['assigned_to']) {
                $notification_message = "Your work on report #$task_id was rejected. Please review and resubmit.";
                $stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, message, type, related_id, created_date)
                    VALUES (?, ?, 'work_rejected', ?, NOW())
                ");
                $stmt->bind_param("iss", $task['assigned_to'], $notification_message, $task_id);
                $stmt->execute();
            }

            $conn->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Work rejected and sent back for revision'
            ]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode([
                'success' => false,
                'message' => 'Error rejecting work: ' . $e->getMessage()
            ]);
        }
    }
    exit();
}

// Get completed work awaiting verification
$completed_work = getCompletedWorkForVerification();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Work - BEC Equipment System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/global_styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin_style.css">
    <style>
        .work-item {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            border-left: 4px solid var(--bec-gold);
        }

        .work-item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .work-item-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--bec-navy);
        }

        .work-item-status {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            background: #17a2b8;
            color: white;
        }

        .work-item-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #666;
        }

        .work-item-description {
            margin-bottom: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .work-item-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .work-item-actions button {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-verify {
            background: #28a745;
            color: white;
        }

        .btn-verify:hover {
            background: #218838;
        }

        .btn-reject {
            background: #dc3545;
            color: white;
        }

        .btn-reject:hover {
            background: #c82333;
        }

        .btn-view-photos {
            background: var(--bec-gold);
            color: var(--bec-navy);
        }

        .btn-view-photos:hover {
            background: #e6b800;
        }

        .photo-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .photo-modal.show {
            display: flex;
        }

        .photo-modal-content {
            position: relative;
            max-width: 90vw;
            max-height: 90vh;
        }

        .photo-modal img {
            max-width: 100%;
            max-height: 100%;
            border-radius: 8px;
        }

        .photo-modal-close {
            position: absolute;
            top: -40px;
            right: 0;
            background: rgba(0, 0, 0, 0.5);
            color: white;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .verification-modal {
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

        .verification-modal.show {
            display: flex;
        }

        .verification-modal-content {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .verification-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e0e0e0;
        }

        .verification-modal-header h3 {
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

        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            font-family: inherit;
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

        .btn-confirm-verify {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: background-color 0.2s;
        }

        .btn-confirm-verify:hover {
            background: #218838;
        }

        .btn-confirm-reject {
            background: #dc3545;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: background-color 0.2s;
        }

        .btn-confirm-reject:hover {
            background: #c82333;
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
                <h1 class="page-title">Verify Completed Work</h1>
                <div class="breadcrumb">
                    <span>Home</span>
                    <i class="fas fa-chevron-right"></i>
                    <span>Handler Functions</span>
                    <i class="fas fa-chevron-right"></i>
                    <span>Verify Work</span>
                </div>
            </div>
        </div>

        <div class="content-area">
            <!-- Completed Work List -->
            <div class="data-table-card">
                <div class="table-header">
                    <div class="table-title">
                        <i class="fas fa-check-circle"></i>
                        Completed Work Awaiting Verification
                    </div>
                    <div class="table-actions">
                        <button class="btn btn-secondary" onclick="location.reload()">
                            <i class="fas fa-sync"></i>
                            Refresh
                        </button>
                    </div>
                </div>

                <?php if (empty($completed_work)): ?>
                <div style="text-align: center; padding: 3rem;">
                    <i class="fas fa-check-double" style="font-size: 4rem; color: #28a745; margin-bottom: 1rem;"></i>
                    <h3>All Caught Up!</h3>
                    <p>No completed work awaiting verification at the moment.</p>
                </div>
                <?php else: ?>
                    <?php foreach ($completed_work as $work): ?>
                    <div class="work-item">
                        <div class="work-item-header">
                            <div class="work-item-title">
                                Report #<?php echo htmlspecialchars($work['report_id']); ?> - <?php echo htmlspecialchars($work['equipment_name']); ?>
                            </div>
                            <span class="work-item-status">Completed</span>
                        </div>

                        <div class="work-item-meta">
                            <div><strong>Technician:</strong> <?php echo htmlspecialchars($work['technician_name'] ?? 'Unknown'); ?></div>
                            <div><strong>Completed:</strong> <?php echo date('M d, Y H:i', strtotime($work['completion_date'])); ?></div>
                            <div><strong>Priority:</strong> <?php echo ucfirst($work['priority']); ?></div>
                            <div><strong>Issue:</strong> <?php echo htmlspecialchars(substr($work['issue_description'], 0, 50)) . (strlen($work['issue_description']) > 50 ? '...' : ''); ?></div>
                        </div>

                        <?php if (!empty($work['technician_notes'])): ?>
                        <div class="work-item-description">
                            <strong>Technician's Notes:</strong><br>
                            <?php echo nl2br(htmlspecialchars($work['technician_notes'])); ?>
                        </div>
                        <?php endif; ?>

                        <div class="work-item-actions">
                            <?php if (!empty($work['completion_photos'])): ?>
                                <button class="btn-view-photos" onclick="viewPhotos('<?php echo htmlspecialchars($work['completion_photos']); ?>')">
                                    <i class="fas fa-images"></i> View Photos
                                </button>
                            <?php endif; ?>
                            <button class="btn-verify" onclick="verifyWork('<?php echo $work['report_id']; ?>')">
                                <i class="fas fa-check"></i> Verify
                            </button>
                            <button class="btn-reject" onclick="rejectWork('<?php echo $work['report_id']; ?>')">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Photo Modal -->
    <div id="photo-modal" class="photo-modal">
        <div class="photo-modal-content">
            <button class="photo-modal-close" onclick="closePhotoModal()">
                <i class="fas fa-times"></i>
            </button>
            <img id="photo-modal-image" src="" alt="Completion Photo">
        </div>
    </div>

    <!-- Verification Modal -->
    <div id="verification-modal" class="verification-modal">
        <div class="verification-modal-content">
            <div class="verification-modal-header">
                <h3 id="verification-modal-title">Verify Work</h3>
                <button class="close-modal" onclick="closeVerificationModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <form id="verification-form">
                <input type="hidden" id="verification-action" name="action">
                <input type="hidden" id="verification-task-id" name="task_id">

                <div class="form-group">
                    <label for="verification-notes">Verification Notes (Optional):</label>
                    <textarea id="verification-notes" name="verification_notes" placeholder="Add any notes about the verification..."></textarea>
                </div>

                <div class="form-group" id="rejection-reason-group" style="display: none;">
                    <label for="rejection-reason">Rejection Reason:</label>
                    <textarea id="rejection-reason" name="rejection_reason" placeholder="Explain why the work is being rejected..." required></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeVerificationModal()">Cancel</button>
                    <button type="submit" class="btn-confirm-verify" id="verification-submit-btn">
                        <i class="fas fa-check"></i> Verify Work
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="js/loading_utils.js"></script>
    <script>
        function viewPhotos(photoPath) {
            const modal = document.getElementById('photo-modal');
            const img = document.getElementById('photo-modal-image');
            img.src = photoPath;
            modal.classList.add('show');
        }

        function closePhotoModal() {
            document.getElementById('photo-modal').classList.remove('show');
        }

        function verifyWork(taskId) {
            document.getElementById('verification-modal-title').textContent = 'Verify Work';
            document.getElementById('verification-action').value = 'verify';
            document.getElementById('verification-task-id').value = taskId;
            document.getElementById('verification-form').reset();
            document.getElementById('rejection-reason-group').style.display = 'none';
            document.getElementById('verification-submit-btn').className = 'btn-confirm-verify';
            document.getElementById('verification-submit-btn').innerHTML = '<i class="fas fa-check"></i> Verify Work';
            document.getElementById('verification-notes').name = 'verification_notes';
            document.getElementById('verification-modal').classList.add('show');
        }

        function rejectWork(taskId) {
            document.getElementById('verification-modal-title').textContent = 'Reject Work';
            document.getElementById('verification-action').value = 'reject';
            document.getElementById('verification-task-id').value = taskId;
            document.getElementById('verification-form').reset();
            document.getElementById('rejection-reason-group').style.display = 'block';
            document.getElementById('verification-submit-btn').className = 'btn-confirm-reject';
            document.getElementById('verification-submit-btn').innerHTML = '<i class="fas fa-times"></i> Reject Work';
            document.getElementById('verification-notes').name = 'rejection_reason';
            document.getElementById('verification-modal').classList.add('show');
        }

        function closeVerificationModal() {
            document.getElementById('verification-modal').classList.remove('show');
        }

        // Handle verification form submission
        document.getElementById('verification-form').addEventListener('submit', function(e) {
            e.preventDefault();

            const action = document.getElementById('verification-action').value;
            const buttonText = action === 'verify' ? 'Verifying work...' : 'Rejecting work...';

            showLoading(buttonText);

            const formData = new FormData(this);

            fetch('admin_verify_work.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showToast(data.message, 'success');
                    closeVerificationModal();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.message || 'Error processing request', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showToast('Network error. Please try again.', 'error');
            });
        });

        // Close modals when clicking outside
        document.getElementById('photo-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePhotoModal();
            }
        });

        document.getElementById('verification-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeVerificationModal();
            }
        });
    </script>
</body>
</html>
