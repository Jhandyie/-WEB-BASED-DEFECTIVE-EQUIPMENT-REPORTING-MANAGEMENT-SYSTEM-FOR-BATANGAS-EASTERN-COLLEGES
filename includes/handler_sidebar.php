<?php
$current_page = basename($_SERVER['PHP_SELF']);
$handler_name = $_SESSION['fullname'] ?? 'Handler';
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <i class="fas fa-tools"></i>
        </div>
        <h3>Equipment Handler</h3>
        <p class="subtitle"><?php echo htmlspecialchars($handler_name); ?></p>
    </div>

    <div class="nav-section">
        <div class="nav-section-title">Main</div>
        <a class="nav-item <?php echo $current_page === 'handler_dashboard.php' ? 'active' : ''; ?>" href="handler_dashboard.php">
            <i class="fas fa-th-large"></i>
            <span>Dashboard</span>
        </a>
        <a class="nav-item <?php echo $current_page === 'handler_defect_reports.php' ? 'active' : ''; ?>" href="handler_defect_reports.php">
            <i class="fas fa-exclamation-triangle"></i>
            <span>Defect Reports</span>
        </a>
        <a class="nav-item <?php echo $current_page === 'handler_assign_tasks.php' ? 'active' : ''; ?>" href="handler_assign_tasks.php">
            <i class="fas fa-user-cog"></i>
            <span>Assign Technicians</span>
        </a>
        <a class="nav-item <?php echo $current_page === 'handler_verify_work.php' ? 'active' : ''; ?>" href="handler_verify_work.php">
            <i class="fas fa-check-circle"></i>
            <span>Verify Completed Work</span>
        </a>
        <a class="nav-item <?php echo $current_page === 'handler_reservations.php' ? 'active' : ''; ?>" href="handler_reservations.php">
            <i class="fas fa-calendar-check"></i>
            <span>Equipment Reservations</span>
        </a>
    </div>

    <div class="nav-section">
        <a class="nav-item" href="logout.php" style="color: var(--bec-gold);">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</div>