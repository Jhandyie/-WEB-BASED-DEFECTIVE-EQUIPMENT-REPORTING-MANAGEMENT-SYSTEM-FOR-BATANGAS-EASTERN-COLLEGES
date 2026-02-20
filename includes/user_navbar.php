<?php
// User Navbar Component
// Include this file in all user-facing pages

$is_guest = isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true;
$user_name = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'User';
$current_page = basename($_SERVER['PHP_SELF']);
?>

<nav class="top-navbar">
    <div class="navbar-brand">
        <i class="fas fa-tools"></i>
        BEC Equipment System
    </div>
    <div class="navbar-menu">
        <a href="<?php echo $is_guest ? ($_SESSION['guest_type'] ?? 'student') . '_dashboard.php' : 'user_dashboard.php'; ?>" 
           class="nav-link <?php echo strpos($current_page, 'dashboard') !== false ? 'active' : ''; ?>">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="user_report_defect.php" 
           class="nav-link <?php echo $current_page === 'user_report_defect.php' ? 'active' : ''; ?>">
            <i class="fas fa-exclamation-circle"></i> Report Defect
        </a>
        
        <a href="user_reserve_equipment.php"
           class="nav-link <?php echo $current_page === 'user_reserve_equipment.php' ? 'active' : ''; ?>">
            <i class="fas fa-calendar-check"></i> Reserve Equipment
        </a>

        <?php if (!$is_guest): ?>
        <a href="user_my_reports.php"
           class="nav-link <?php echo $current_page === 'user_my_reports.php' ? 'active' : ''; ?>">
            <i class="fas fa-list"></i> My Reports
        </a>
        <a href="user_my_reservations.php"
           class="nav-link <?php echo $current_page === 'user_my_reservations.php' ? 'active' : ''; ?>">
            <i class="fas fa-bookmark"></i> My Reservations
        </a>
        <?php endif; ?>
        
        <a href="user_browse_equipment.php" 
           class="nav-link <?php echo $current_page === 'user_browse_equipment.php' ? 'active' : ''; ?>">
            <i class="fas fa-search"></i> Browse
        </a>
        
        <div class="user-menu">
            <i class="fas fa-user-circle"></i>
            <span><?php echo htmlspecialchars($user_name); ?></span>
            <?php if ($is_guest): ?>
                <span class="guest-badge">Guest</span>
            <?php endif; ?>
            
            <?php if (!$is_guest): ?>
            <a href="guest/logout.php" class="logout-btn" title="Logout">
                <i class="fas fa-sign-out-alt"></i>
            </a>
            <?php else: ?>
            <a href="login.html" class="login-btn" title="Login">
                <i class="fas fa-sign-in-alt"></i> Login
            </a>
            <?php endif; ?>
        </div>
    </div>
</nav>