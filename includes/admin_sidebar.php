<?php
$current_page = basename($_SERVER['PHP_SELF']);
$admin_name = $_SESSION['fullname'] ?? 'Administrator';
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <i class="fas fa-crown"></i>
        </div>
        <h3>PMO Administrator</h3>
        <p class="subtitle"><?php echo htmlspecialchars($admin_name); ?></p>
    </div>

    <div class="nav-section">
        <div class="nav-section-title">Dashboard</div>
        <a class="nav-item <?php echo $current_page === 'admin_dashboard.php' ? 'active' : ''; ?>" href="admin_dashboard.php">
            <i class="fas fa-th-large"></i>
            <span>Overview</span>
        </a>
    </div>

    <div class="nav-section">
        <div class="nav-section-title">Management</div>
        <a class="nav-item <?php echo $current_page === 'admin_defect_reports.php' ? 'active' : ''; ?>" href="admin_defect_reports.php">
            <i class="fas fa-exclamation-triangle"></i>
            <span>Defect Reports</span>
        </a>
        <a class="nav-item <?php echo $current_page === 'admin_reservations.php' ? 'active' : ''; ?>" href="admin_reservations.php">
            <i class="fas fa-calendar-check"></i>
            <span>Reservations</span>
        </a>
        <a class="nav-item <?php echo $current_page === 'admin_users.php' ? 'active' : ''; ?>" href="admin_users.php">
            <i class="fas fa-users"></i>
            <span>User Management</span>
        </a>
        <a class="nav-item <?php echo $current_page
     === 'admin_settings.php' ? 'active' : ''; ?>" href="admin_settings.php">
            <i class="fas fa-cog"></i>
            <span>System Settings</span>
        </a>
    </div>

    <div class="nav-section">
        <div class="nav-section-title">Handler Functions</div>
        <a class="nav-item <?php echo $current_page === 'admin_assign_technicians.php' ? 'active' : ''; ?>" href="admin_assign_technicians.php">
            <i class="fas fa-user-cog"></i>
            <span>Assign Technicians</span>
        </a>
        <a class="nav-item <?php echo $current_page === 'admin_verify_work.php' ? 'active' : ''; ?>" href="admin_verify_work.php">
            <i class="fas fa-check-circle"></i>
            <span>Verify Work</span>
        </a>
        <a class="nav-item <?php echo $current_page === 'admin_maintenance_schedule.php' ? 'active' : ''; ?>" href="admin_maintenance_schedule.php">
            <i class="fas fa-calendar-alt"></i>
            <span>Maintenance Schedule</span>
        </a>

    </div>

    <div class="nav-section">
        <div class="nav-section-title">Reports</div>
        <a class="nav-item <?php echo $current_page === 'admin_inventory.php' ? 'active' : ''; ?>" href="admin_inventory.php">
            <i class="fas fa-boxes"></i>
            <span>Inventory</span>
        </a>
        <a class="nav-item <?php echo $current_page === 'admin_analytics.php' ? 'active' : ''; ?>" href="admin_analytics.php">
            <i class="fas fa-chart-line"></i>
            <span>Analytics</span>
        </a>
    </div>

    <div class="nav-section">
        <a class="nav-item" href="admin/logout.php">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</div>
