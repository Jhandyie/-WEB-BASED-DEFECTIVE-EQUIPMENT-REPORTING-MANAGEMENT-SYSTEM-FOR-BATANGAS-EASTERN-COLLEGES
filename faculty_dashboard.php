<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php'; require_once __DIR__ . '/inventory_functions.php';

// Guest users can access this page
$is_guest = isGuest();
$user_name = $_SESSION['fullname'] ?? 'Guest User';

// Get available equipment
$available_equipment = getAvailableEquipment();

// Get inventory statistics
$inventoryStats = getInventoryStats();

// Get recent public defect reports (last 5)
$recent_reports = getAllDefectReports();
$public_reports = array_slice(array_filter($recent_reports, function($report) {
    return in_array($report['status'], ['reported', 'assigned', 'in_progress']);
}), 0, 5);

// Get recent reservations (last 5)
$all_reservations = getAllReservations();
$recent_reservations = array_slice(array_filter($all_reservations, function($reservation) {
    return $reservation['status'] === 'approved';
}), 0, 5);

// Get user's defect reports if logged in
$my_reports = [];
if (!$is_guest) {
    $my_reports = getUserDefectReports($_SESSION['user_id']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Dashboard - BEC Equipment System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/global_styles.css">
    <link rel="stylesheet" href="css/user_styles.css">
    <style>
        .navbar-toggle { display: none; }
    </style>
</head>
<body>
    <nav class="top-navbar">
        <div class="navbar-brand">
            <i class="fas fa-tools"></i>
            BEC EQUIPMENT SYSTEM
        </div>
        <div class="navbar-menu">
            <a href="faculty_dashboard.php" class="nav-link active">
                Dashboard
            </a>
            <a href="user_report_defect.php" class="nav-link">
                Report Defect
            </a>
            <a href="user_reserve_equipment.php" class="nav-link">
                Reserve Equipment
            </a>
            <?php if (!$is_guest): ?>
            <a href="user_my_reports.php" class="nav-link">
                My Reports
            </a>
            <a href="user_my_reservations.php" class="nav-link">
                My Reservations
            </a>
            <?php endif; ?>
            <div class="user-menu">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($user_name); ?></span>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </nav>

    <div class="main-container">
        <div class="welcome-section">
            <h1>Welcome, <?php echo htmlspecialchars($user_name); ?>!</h1>
            <p>Report equipment defects, reserve equipment, and track your requests.</p>
        </div>

        <div class="quick-actions">
            <div class="action-card" onclick="location.href='user_report_defect.php'">
                <div class="action-icon" style="background: rgba(220, 53, 69, 0.1); color: #dc3545;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3>Report Defect</h3>
                <p>Submit equipment malfunction reports with photos</p>
            </div>

            <div class="action-card" onclick="location.href='user_reserve_equipment.php'">
                <div class="action-icon" style="background: rgba(40, 167, 69, 0.1); color: #28a745;">
                    <i class="fas fa-calendar-plus"></i>
                </div>
                <h3>Reserve Equipment</h3>
                <p>Schedule equipment for your classes or events</p>
            </div>

            <div class="action-card" onclick="location.href='user_browse_equipment.php'">
                <div class="action-icon" style="background: rgba(23, 162, 184, 0.1); color: #17a2b8;">
                    <i class="fas fa-search"></i>
                </div>
                <h3>Browse Equipment</h3>
                <p>View available equipment and specifications</p>
            </div>
        </div>

        <?php if (!$is_guest): ?>
        <!-- Statistics Section -->
        <div class="stats-grid">
            <div class="stat-card success">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $inventoryStats['total_items'] ?? 0; ?></div>
                        <div class="stat-label">Total Equipment</div>
                    </div>
                    <div class="stat-icon success">
                        <i class="fas fa-boxes"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card primary">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $inventoryStats['total_quantity'] ?? 0; ?></div>
                        <div class="stat-label">Available Items</div>
                    </div>
                    <div class="stat-icon primary">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card info">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo count($public_reports); ?></div>
                        <div class="stat-label">Active Reports</div>
                    </div>
                    <div class="stat-icon info">
                        <i class="fas fa-tools"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card secondary">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo count($recent_reservations); ?></div>
                        <div class="stat-label">Recent Reservations</div>
                    </div>
                    <div class="stat-icon secondary">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$is_guest): ?>
        <!-- Recent Activities Section -->
        <div class="dashboard-grid">
            <!-- Recent Defect Reports -->
            <div class="data-table-card">
                <div class="table-header">
                    <div class="table-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        Recent Defect Reports
                    </div>
                    <div class="table-actions">
                        <button class="btn btn-primary" onclick="location.href='user_report_defect.php'">
                            <i class="fas fa-plus"></i>
                            Report Issue
                        </button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Equipment</th>
                            <th>Issue</th>
                            <th>Status</th>
                            <th>Reported</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($public_reports as $report): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($report['equipment_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars(substr($report['issue_description'], 0, 30)) . '...'; ?></td>
                            <td>
                                <span class="badge badge-<?php echo getStatusClass($report['status']); ?>">
                                    <?php echo htmlspecialchars($report['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($report['report_date'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($public_reports)): ?>
                        <tr>
                            <td colspan="4" class="text-center">No recent reports</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Reservations -->
            <div class="data-table-card">
                <div class="table-header">
                    <div class="table-title">
                        <i class="fas fa-calendar-check"></i>
                        Recent Reservations
                    </div>
                    <div class="table-actions">
                        <button class="btn btn-primary" onclick="location.href='user_reserve_equipment.php'">
                            <i class="fas fa-plus"></i>
                            Make Reservation
                        </button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Equipment</th>
                            <th>Status</th>
                            <th>Requested</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_reservations as $reservation): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($reservation['user_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($reservation['equipment_name'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="badge badge-<?php echo getReservationStatusClass($reservation['status']); ?>">
                                    <?php echo htmlspecialchars($reservation['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($reservation['request_date'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_reservations)): ?>
                        <tr>
                            <td colspan="4" class="text-center">No recent reservations</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (count($my_reports) > 0): ?>
        <div class="section-card">
            <div class="section-header">
                <h2>My Recent Reports</h2>
                <a href="user_my_reports.php" class="view-all-link">View All</a>
            </div>
            <div class="reports-list">
                <?php foreach (array_slice($my_reports, 0, 5) as $report): ?>
                <div class="report-item">
                    <div class="report-info">
                        <strong><?php echo htmlspecialchars($report['equipment_name']); ?></strong>
                        <p><?php echo htmlspecialchars(substr($report['issue_description'], 0, 80)) . '...'; ?></p>
                        <small>Reported: <?php echo date('M d, Y', strtotime($report['report_date'])); ?></small>
                    </div>
                    <div class="report-status">
                        <span class="status-badge status-<?php echo $report['status']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $report['status'])); ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$is_guest): ?>
        <div class="section-card">
            <div class="section-header">
                <h2>Available Equipment</h2>
                <a href="admin_inventory.php" class="view-all-link">View Full Inventory</a>
            </div>
            <div class="equipment-grid">
                <?php
                // Sample equipment from different categories
                $sample_equipment = [
                    [
                        'name' => 'Carrier Air Conditioner',
                        'category' => 'Air Conditioner',
                        'location' => 'Building 1, Gymnasium',
                        'status' => 'Active'
                    ],
                    [
                        'name' => 'TCL Television',
                        'category' => 'Television',
                        'location' => 'Building 4, Diamond Room 101',
                        'status' => 'Active'
                    ],
                    [
                        'name' => 'Ceiling Fan',
                        'category' => 'Fan',
                        'location' => 'Building 3, Room 125',
                        'status' => 'Active'
                    ],
                    [
                        'name' => 'Glass Whiteboard',
                        'category' => 'Whiteboard',
                        'location' => 'Building 13, BEC Skills Training Center 101',
                        'status' => 'Active'
                    ],
                    [
                        'name' => 'Steel Locker (15 slots)',
                        'category' => 'Locker',
                        'location' => 'Building 2, Faculty Office',
                        'status' => 'Active'
                    ],
                    [
                        'name' => 'Executive Office Chair',
                        'category' => 'Office Chair',
                        'location' => 'Building 4, Deans Office',
                        'status' => 'Active'
                    ]
                ];

                foreach ($sample_equipment as $equipment): ?>
                <div class="equipment-card">
                    <div class="equipment-image">
                        <div class="no-image">
                            <?php
                            $icon = 'fas fa-box';
                            switch($equipment['category']) {
                                case 'Air Conditioner': $icon = 'fas fa-snowflake'; break;
                                case 'Television': $icon = 'fas fa-tv'; break;
                                case 'Fan': $icon = 'fas fa-fan'; break;
                                case 'Whiteboard': $icon = 'fas fa-chalkboard'; break;
                                case 'Locker': $icon = 'fas fa-lock'; break;
                                case 'Office Chair': $icon = 'fas fa-chair'; break;
                            }
                            ?>
                            <i class="<?php echo $icon; ?>"></i>
                        </div>
                    </div>
                    <div class="equipment-details">
                        <h4><?php echo htmlspecialchars($equipment['name']); ?></h4>
                        <p class="equipment-category"><?php echo htmlspecialchars($equipment['category']); ?></p>
                        <p class="equipment-location">
                            <i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($equipment['location']); ?>
                        </p>
                        <button class="btn-reserve" onclick="reserveEquipment('sample')">
                            <i class="fas fa-calendar-check"></i> Reserve
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
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

    <script src="js/user_dashboard.js"></script>
    <script>
        // Mobile Navigation Handler
        // Add this script to all pages that use the navbar

        document.addEventListener('DOMContentLoaded', function() {
            // Create mobile menu toggle if not exists
            const navbar = document.querySelector('.top-navbar');
            const navbarBrand = navbar.querySelector('.navbar-brand');
            const navbarMenu = navbar.querySelector('.navbar-menu');
            
            if (!navbar.querySelector('.navbar-toggle')) {
                // Create toggle button
                const toggleBtn = document.createElement('button');
                toggleBtn.className = 'navbar-toggle';
                toggleBtn.innerHTML = '☰';
                toggleBtn.setAttribute('aria-label', 'Toggle navigation');
                
                // Insert after brand
                navbarBrand.insertAdjacentElement('afterend', toggleBtn);
                
                // Create close button in menu
                const closeDiv = document.createElement('div');
                closeDiv.className = 'navbar-close';
                closeDiv.innerHTML = '<button aria-label="Close menu"><i class="fas fa-times"></i></button>';
                navbarMenu.insertBefore(closeDiv, navbarMenu.firstChild);
                
                // Create overlay
                const overlay = document.createElement('div');
                overlay.className = 'navbar-overlay';
                document.body.appendChild(overlay);
                
                // Toggle functionality
                toggleBtn.addEventListener('click', function() {
                    navbarMenu.classList.add('active');
                    overlay.classList.add('active');
                    document.body.style.overflow = 'hidden';
                });
                
                // Close functionality
                const closeBtn = closeDiv.querySelector('button');
                closeBtn.addEventListener('click', closeMenu);
                overlay.addEventListener('click', closeMenu);
                
                // Close on link click
                navbarMenu.querySelectorAll('.nav-link').forEach(link => {
                    link.addEventListener('click', closeMenu);
                });
                
                function closeMenu() {
                    navbarMenu.classList.remove('active');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
                
                // Close on ESC key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && navbarMenu.classList.contains('active')) {
                        closeMenu();
                    }
                });
            }
        });

        // Prevent menu from being open on resize to desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth > 968) {
                const navbarMenu = document.querySelector('.navbar-menu');
                const overlay = document.querySelector('.navbar-overlay');
                if (navbarMenu && overlay) {
                    navbarMenu.classList.remove('active');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
        });
    </script>
</body>
</html>
