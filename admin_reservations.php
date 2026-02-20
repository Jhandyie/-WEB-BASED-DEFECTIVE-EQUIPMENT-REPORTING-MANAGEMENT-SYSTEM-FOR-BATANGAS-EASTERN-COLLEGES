<?php
// admin_reservations.php
session_start();
require_once 'includes/auth.php';
require_once 'config/database.php';
require_once 'file_storage_helpers.php';

requireRole('admin');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $reservation_id = $_POST['reservation_id'];
        $admin_notes = $_POST['admin_notes'] ?? '';

        // Get current reservation data
        $current_reservation = getReservationById($reservation_id);
        if ($current_reservation) {
            $update_data = [
                'status' => 'approved',
                'approved_by' => $_SESSION['user_id'],
                'approval_date' => date('Y-m-d H:i:s'),
                'admin_notes' => $admin_notes,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            updateReservation($reservation_id, $update_data);

            // Create notification for user
            addNotification($current_reservation['user_id'], "Your reservation $reservation_id has been approved", 'reservation_approved', $reservation_id);

            $_SESSION['success_message'] = 'Reservation approved successfully';
        } else {
            $_SESSION['error_message'] = 'Reservation not found';
        }

        header('Location: admin_reservations.php');
        exit();
    }

    if ($action === 'reject') {
        $reservation_id = $_POST['reservation_id'];
        $rejection_reason = $_POST['rejection_reason'];

        // Get current reservation data
        $current_reservation = getReservationById($reservation_id);
        if ($current_reservation) {
            $update_data = [
                'status' => 'rejected',
                'rejected_by' => $_SESSION['user_id'],
                'rejection_date' => date('Y-m-d H:i:s'),
                'rejection_reason' => $rejection_reason,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            updateReservation($reservation_id, $update_data);

            // Notify user
            addNotification($current_reservation['user_id'], "Your reservation $reservation_id has been rejected", 'reservation_rejected', $reservation_id);

            $_SESSION['success_message'] = 'Reservation rejected';
        } else {
            $_SESSION['error_message'] = 'Reservation not found';
        }

        header('Location: admin_reservations.php');
        exit();
    }

    if ($action === 'update') {
        $reservation_id = $_POST['reservation_id'];
        $status = $_POST['status'];
        $admin_notes = $_POST['admin_notes'];

        $update_data = [
            'status' => $status,
            'admin_notes' => $admin_notes,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        updateReservation($reservation_id, $update_data);

        $_SESSION['success_message'] = 'Reservation updated successfully';
        header('Location: admin_reservations.php');
        exit();
    }

    if ($action === 'delete') {
        $reservation_id = $_POST['reservation_id'];

        // Soft delete by updating status
        updateReservation($reservation_id, [
            'status' => 'cancelled',
            'deleted_at' => date('Y-m-d H:i:s')
        ]);

        $_SESSION['success_message'] = 'Reservation deleted successfully';
        header('Location: admin_reservations.php');
        exit();
    }
}

// Get filters
$status_filter = $_GET['status'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Get reservations using helper functions
$reservations = getAllReservations();

// Add equipment details to each reservation
foreach ($reservations as &$reservation) {
    $equipment = getEquipmentById($reservation['equipment_id']);
    if ($equipment) {
        $reservation['equipment_name'] = $equipment['equipment_name'];
        $reservation['asset_tag'] = $equipment['asset_tag'];
    } else {
        $reservation['equipment_name'] = 'Unknown Equipment';
        $reservation['asset_tag'] = 'N/A';
    }
}

// Apply filters
if ($status_filter !== 'all') {
    $reservations = array_filter($reservations, function($r) use ($status_filter) {
        return $r['status'] === $status_filter;
    });
}

if (!empty($date_from)) {
    $reservations = array_filter($reservations, function($r) use ($date_from) {
        return strtotime($r['start_date']) >= strtotime($date_from);
    });
}

if (!empty($date_to)) {
    $reservations = array_filter($reservations, function($r) use ($date_to) {
        return strtotime($r['end_date']) <= strtotime($date_to);
    });
}

if (!empty($search)) {
    $search_lower = strtolower($search);
    $reservations = array_filter($reservations, function($r) use ($search_lower) {
        return (
            (isset($r['reservation_id']) && strpos(strtolower($r['reservation_id']), $search_lower) !== false) ||
            (isset($r['equipment_name']) && strpos(strtolower($r['equipment_name']), $search_lower) !== false) ||
            (isset($r['requester_name']) && strpos(strtolower($r['requester_name']), $search_lower) !== false)
        );
    });
}

// Sort by request date descending
usort($reservations, function($a, $b) {
    return strtotime($b['request_date']) - strtotime($a['request_date']);
});

// Reset array keys
$reservations = array_values($reservations);

// Get statistics
$stats = [
    'total' => count($reservations),
    'pending' => count(array_filter($reservations, fn($r) => $r['status'] === 'pending')),
    'approved' => count(array_filter($reservations, fn($r) => $r['status'] === 'approved')),
    'active' => count(array_filter($reservations, fn($r) => $r['status'] === 'active')),
    'completed' => count(array_filter($reservations, fn($r) => $r['status'] === 'completed'))
];

// Get view details if requested
$view_reservation = null;
if (isset($_GET['view'])) {
    $view_reservation = getReservationById($_GET['view']);
    if ($view_reservation) {
        // Add equipment details
        $equipment = getEquipmentById($view_reservation['equipment_id']);
        if ($equipment) {
            $view_reservation['equipment_name'] = $equipment['equipment_name'];
            $view_reservation['asset_tag'] = $equipment['asset_tag'];
            $view_reservation['category_id'] = $equipment['category_id'];

            // Add category name
            $categories = getAllCategories();
            foreach ($categories as $cat) {
                if ($cat['category_id'] == $equipment['category_id']) {
                    $view_reservation['category_name'] = $cat['name'];
                    break;
                }
            }
        }

        // Add requester and approver info (placeholders)
        $view_reservation['requester_name'] = $view_reservation['requester_name'] ?? 'Guest';
        $view_reservation['requester_email'] = $view_reservation['requester_email'] ?? '';
        $view_reservation['requester_phone'] = $view_reservation['requester_phone'] ?? '';
        $view_reservation['approver_name'] = $view_reservation['approver_name'] ?? 'Not yet approved';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservations - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/global_styles.css">
    <link rel="stylesheet" href="css/admin_style.css">
    <!-- FullCalendar CSS -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
</head>
<body>
    <?php include 'includes/admin_sidebar.php'; ?>

    <div class="main-content">
        <div class="top-header">
            <div class="page-title-section">
                <h1 class="page-title">Reservations Management</h1>
                <div class="breadcrumb">
                    <span>Home</span>
                    <i class="fas fa-chevron-right"></i>
                    <span>Reservations</span>
                </div>
            </div>
        </div>

        <div class="content-area">
            <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid" style="grid-template-columns: repeat(5, 1fr); margin-bottom: 30px;">
                <div class="stat-card info">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['total']; ?></div>
                            <div class="stat-label">Total</div>
                        </div>
                        <div class="stat-icon info">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['pending']; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                        <div class="stat-icon warning">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['approved']; ?></div>
                            <div class="stat-label">Approved</div>
                        </div>
                        <div class="stat-icon success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['active']; ?></div>
                            <div class="stat-label">Active</div>
                        </div>
                        <div class="stat-icon primary">
                            <i class="fas fa-play-circle"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card secondary">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['completed']; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                        <div class="stat-icon secondary">
                            <i class="fas fa-check-double"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions Bar -->
            <div class="quick-actions-bar">
                <div class="action-buttons">
                    <button class="btn btn-success" onclick="bulkApprove()">
                        <i class="fas fa-check-circle"></i> Bulk Approve
                    </button>
                    <button class="btn btn-danger" onclick="bulkReject()">
                        <i class="fas fa-times-circle"></i> Bulk Reject
                    </button>
                    <button class="btn btn-info" onclick="toggleCalendarView()">
                        <i class="fas fa-calendar-alt"></i> Calendar View
                    </button>
                </div>
                <div class="view-toggle">
                    <button class="btn btn-outline-primary active" onclick="setView('table')">
                        <i class="fas fa-table"></i> Table
                    </button>
                    <button class="btn btn-outline-primary" onclick="setView('cards')">
                        <i class="fas fa-th"></i> Cards
                    </button>
                </div>
            </div>

            <!-- View Container -->
            <div id="tableView" class="view-container active">
                <div class="data-table-card">
                    <div class="table-header">
                        <div class="table-title">
                            <i class="fas fa-calendar-check"></i>
                            All Reservations
                            <span class="record-count">(<?php echo count($reservations); ?> records)</span>
                        </div>
                        <div class="table-actions">
                            <button class="btn btn-primary" onclick="refreshData()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                            <button class="btn btn-secondary" onclick="exportReservations()">
                                <i class="fas fa-download"></i> Export
                            </button>
                        </div>
                    </div>

                    <div class="table-filters">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" placeholder="Search by ID, equipment, or requester..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <select class="filter-select" id="statusFilter" onchange="applyFilters()">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                        <select class="filter-select" id="categoryFilter" onchange="applyFilters()">
                            <option value="all">All Categories</option>
                            <?php
                            $categories = getAllCategories();
                            foreach ($categories as $cat) {
                                echo "<option value='{$cat['category_id']}'>{$cat['name']}</option>";
                            }
                            ?>
                        </select>
                        <input type="date" class="filter-select" id="dateFrom" value="<?php echo $date_from; ?>" onchange="applyFilters()">
                        <input type="date" class="filter-select" id="dateTo" value="<?php echo $date_to; ?>" onchange="applyFilters()">
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Reservation ID</th>
                                <th>Equipment</th>
                                <th>Requester</th>
                                <th>Purpose</th>
                                <th>Date Range</th>
                                <th>Status</th>
                                <th>Requested</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $reservation): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($reservation['reservation_id']); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($reservation['equipment_name']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($reservation['asset_tag']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($reservation['requester_name'] ?? 'Guest'); ?></td>
                                <td><?php echo htmlspecialchars(substr($reservation['purpose'], 0, 50)) . '...'; ?></td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($reservation['start_date'])); ?><br>
                                    <small>to <?php echo date('M d, Y', strtotime($reservation['end_date'])); ?></small>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo getReservationStatusClass($reservation['status']); ?>">
                                        <?php echo ucfirst($reservation['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($reservation['request_date'])); ?></td>
                                <td>
                                    <div class="action-btns">
                                        <button class="action-btn view" onclick="viewReservation('<?php echo $reservation['reservation_id']; ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($reservation['status'] === 'pending'): ?>
                                        <button class="action-btn approve" onclick="approveReservation('<?php echo $reservation['reservation_id']; ?>')">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="action-btn reject" onclick="rejectReservation('<?php echo $reservation['reservation_id']; ?>')">
                                            <i class="fas fa-times"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button class="action-btn edit" onclick="editReservation('<?php echo $reservation['reservation_id']; ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="action-btn delete" onclick="deleteReservation('<?php echo $reservation['reservation_id']; ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Calendar View -->
            <div id="calendarView" class="view-container" style="display: none;">
                <div class="data-table-card">
                    <div class="table-header">
                        <div class="table-title">
                            <i class="fas fa-calendar-alt"></i>
                            Reservation Calendar
                        </div>
                        <div class="table-actions">
                            <button class="btn btn-primary" onclick="refreshData()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                            <button class="btn btn-secondary" onclick="exportReservations()">
                                <i class="fas fa-download"></i> Export
                            </button>
                        </div>
                    </div>
                    <div id="calendar" style="height: 700px;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- View/Edit Modal -->
    <?php if ($view_reservation): ?>
    <div class="modal active" id="viewModal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3 class="modal-title">Reservation Details</h3>
                <button class="close-modal" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label class="form-label">Reservation ID</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($view_reservation['reservation_id']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Request Date</label>
                        <input type="text" class="form-control" value="<?php echo date('M d, Y H:i', strtotime($view_reservation['request_date'])); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Equipment</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($view_reservation['equipment_name']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Asset Tag</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($view_reservation['asset_tag']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($view_reservation['category_name']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Current Status</label>
                        <input type="text" class="form-control" value="<?php echo ucfirst($view_reservation['status']); ?>" readonly>
                    </div>
                </div>

                <h4 style="margin: 20px 0 15px; color: var(--bec-maroon);">Requester Information</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($view_reservation['requester_name'] ?? 'Guest'); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($view_reservation['requester_email'] ?? 'N/A'); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($view_reservation['requester_phone'] ?? 'N/A'); ?>" readonly>
                    </div>
                </div>

                <h4 style="margin: 20px 0 15px; color: var(--bec-maroon);">Reservation Details</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label class="form-label">Start Date & Time</label>
                        <input type="text" class="form-control" value="<?php echo date('M d, Y H:i', strtotime($view_reservation['start_date'])); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">End Date & Time</label>
                        <input type="text" class="form-control" value="<?php echo date('M d, Y H:i', strtotime($view_reservation['end_date'])); ?>" readonly>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Purpose</label>
                    <textarea class="form-control" rows="3" readonly><?php echo htmlspecialchars($view_reservation['purpose']); ?></textarea>
                </div>

                <form method="POST" action="admin_reservations.php">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="reservation_id" value="<?php echo $view_reservation['reservation_id']; ?>">
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label class="form-label">Update Status</label>
                            <select class="form-control" name="status" required>
                                <option value="pending" <?php echo $view_reservation['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $view_reservation['status'] === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="active" <?php echo $view_reservation['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="completed" <?php echo $view_reservation['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="rejected" <?php echo $view_reservation['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="cancelled" <?php echo $view_reservation['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Approved By</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($view_reservation['approver_name'] ?? 'Not yet approved'); ?>" readonly>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Admin Notes</label>
                        <textarea class="form-control" name="admin_notes" rows="3" placeholder="Add notes or comments..."><?php echo htmlspecialchars($view_reservation['admin_notes'] ?? ''); ?></textarea>
                    </div>

                    <?php if ($view_reservation['rejection_reason']): ?>
                    <div class="form-group">
                        <label class="form-label">Rejection Reason</label>
                        <textarea class="form-control" rows="2" readonly><?php echo htmlspecialchars($view_reservation['rejection_reason']); ?></textarea>
                    </div>
                    <?php endif; ?>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-cancel" onclick="closeViewModal()">Close</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Reservation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="js/loading_utils.js"></script>

    <!-- Loading Overlay - Add to every page -->
    <div id="global-loading-overlay" class="loading-overlay">
        <div class="loading-spinner-container">
            <div class="loading-spinner"></div>
            <p class="loading-text">Loading...</p>
        </div>
    </div>

    <script>
        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;

            const url = new URL(window.location.href);
            url.searchParams.set('search', search);
            url.searchParams.set('status', status);
            url.searchParams.set('date_from', dateFrom);
            url.searchParams.set('date_to', dateTo);

            window.location.href = url.toString();
        }

        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(applyFilters, 500);
        });

        function viewReservation(reservationId) {
            window.location.href = `admin_reservations.php?view=${reservationId}`;
        }

        function editReservation(reservationId) {
            window.location.href = `admin_reservations.php?view=${reservationId}`;
        }

        function approveReservation(reservationId) {
            const notes = prompt('Add approval notes (optional):');

            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="reservation_id" value="${reservationId}">
                <input type="hidden" name="admin_notes" value="${notes || ''}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function rejectReservation(reservationId) {
            const reason = prompt('Enter rejection reason:');
            if (!reason) return;

            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="reservation_id" value="${reservationId}">
                <input type="hidden" name="rejection_reason" value="${reason}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function deleteReservation(reservationId) {
            if (!confirm('Are you sure you want to delete this reservation?')) return;

            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="reservation_id" value="${reservationId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function closeViewModal() {
            window.location.href = 'admin_reservations.php';
        }

        function exportReservations() {
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;
            window.location.href = `api/export_reports.php?type=reservations&date_from=${dateFrom}&date_to=${dateTo}`;
        }

        function bulkApprove() {
            const selected = getSelectedReservations();
            if (selected.length === 0) {
                alert('Please select reservations to approve');
                return;
            }

            if (confirm(`Approve ${selected.length} reservation(s)?`)) {
                selected.forEach(id => {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="reservation_id" value="${id}">
                        <input type="hidden" name="admin_notes" value="Bulk approved">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                });
            }
        }

        function bulkReject() {
            const selected = getSelectedReservations();
            if (selected.length === 0) {
                alert('Please select reservations to reject');
                return;
            }

            const reason = prompt('Enter rejection reason for all selected reservations:');
            if (!reason) return;

            selected.forEach(id => {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="reservation_id" value="${id}">
                    <input type="hidden" name="rejection_reason" value="${reason}">
                `;
                document.body.appendChild(form);
                form.submit();
            });
        }

        function getSelectedReservations() {
            // This would need checkboxes added to the table rows
            // For now, return empty array
            return [];
        }

        function toggleCalendarView() {
            const tableView = document.getElementById('tableView');
            const calendarView = document.getElementById('calendarView');

            if (calendarView.style.display === 'none') {
                // Switch to calendar view
                tableView.style.display = 'none';
                calendarView.style.display = 'block';
                event.target.innerHTML = '<i class="fas fa-table"></i> Table View';

                // Initialize calendar if not already done
                if (!window.calendar) {
                    initializeCalendar();
                }
            } else {
                // Switch to table view
                calendarView.style.display = 'none';
                tableView.style.display = 'block';
                event.target.innerHTML = '<i class="fas fa-calendar-alt"></i> Calendar View';
            }
        }

        function initializeCalendar() {
            const calendarEl = document.getElementById('calendar');

            if (!calendarEl) {
                alert('Calendar container not found');
                return;
            }

            // Check if FullCalendar is loaded
            if (typeof FullCalendar === 'undefined') {
                alert('FullCalendar library not loaded. Please check your internet connection.');
                return;
            }

            try {
                // Prepare events from PHP data
                const events = <?php echo json_encode(array_map(function($reservation) {
                    $statusColors = [
                        'pending' => '#ffc107',
                        'approved' => '#198754',
                        'active' => '#0d6efd',
                        'completed' => '#6c757d',
                        'rejected' => '#dc3545',
                        'cancelled' => '#dc3545'
                    ];

                    return [
                        'id' => $reservation['reservation_id'],
                        'title' => $reservation['equipment_name'] . ' - ' . ($reservation['requester_name'] ?? 'Guest'),
                        'start' => $reservation['start_date'],
                        'end' => $reservation['end_date'],
                        'backgroundColor' => $statusColors[$reservation['status']] ?? '#6c757d',
                        'borderColor' => $statusColors[$reservation['status']] ?? '#6c757d',
                        'extendedProps' => [
                            'status' => $reservation['status'],
                            'purpose' => $reservation['purpose'],
                            'asset_tag' => $reservation['asset_tag']
                        ]
                    ];
                }, $reservations)); ?>;

                console.log('Calendar events:', events); // Debug log

                window.calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay'
                    },
                    events: events,
                    eventClick: function(info) {
                        // Show reservation details on click
                        const props = info.event.extendedProps;
                        alert(`Reservation: ${info.event.title}\nStatus: ${props.status}\nPurpose: ${props.purpose}\nAsset Tag: ${props.asset_tag}`);
                    },
                    eventMouseEnter: function(info) {
                        // Show tooltip on hover
                        info.el.style.cursor = 'pointer';
                    },
                    height: 700,
                    dayMaxEvents: 3,
                    moreLinkClick: 'popover',
                    noEventsContent: 'No reservations found for this period.'
                });

                window.calendar.render();
                console.log('Calendar rendered successfully'); // Debug log

            } catch (error) {
                console.error('Error initializing calendar:', error);
                alert('Error initializing calendar: ' + error.message);
            }
        }

        function setView(viewType) {
            // Toggle between table and card view
            const buttons = document.querySelectorAll('.view-toggle .btn');
            buttons.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');

            if (viewType === 'cards') {
                // Switch to card view
                alert('Card view feature coming soon!');
            }
        }

        function refreshData() {
            window.location.reload();
        }

        // Add category filter to applyFilters
        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            const category = document.getElementById('categoryFilter').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;

            const url = new URL(window.location.href);
            url.searchParams.set('search', search);
            url.searchParams.set('status', status);
            url.searchParams.set('category', category);
            url.searchParams.set('date_from', dateFrom);
            url.searchParams.set('date_to', dateTo);

            window.location.href = url.toString();
        }
    </script>
</body>
</html>


