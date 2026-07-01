<?php
/**
 * Shared admin sidebar — single source of truth for admin navigation.
 *
 * Usage (before output, set the active key), then include:
 *     <?php $activeNav = 'users'; require __DIR__ . '/includes/admin_sidebar.php'; ?>
 *
 * The host page must define the canonical `.sb`/`.ni`/`.sb-nav` CSS (every
 * admin page already does). This keeps the *markup + links* in one place so
 * adding/removing a nav item is a one-file change.
 */
$__active     = $activeNav ?? '';
$__adminName  = $_SESSION['fullname'] ?? 'Administrator';
$__navUnread  = isset($navUnread) ? (int) $navUnread : 0;

$__sections = [
    'Overview' => [
        ['dashboard',     'admin_dashboard.php',          'fa-th-large',            'Dashboard'],
        ['analytics',     'admin_analytics.php',          'fa-chart-bar',           'Analytics'],
    ],
    'Reports' => [
        ['defects',       'admin_defect_reports.php',     'fa-exclamation-triangle','Defect Reports'],
        ['workorders',    'admin_work_orders.php',        'fa-clipboard-check',     'Work Orders'],
        ['assign',        'admin_assign_technicians.php', 'fa-user-cog',            'Assign Technicians'],
        ['budget',        'admin_budget_requests.php',    'fa-coins',               'Budget Requests'],
        ['preventive',    'admin_preventive.php',         'fa-calendar-check',      'Preventive Maint.'],
    ],
    'Management' => [
        ['inventory',     'admin_inventory.php',          'fa-boxes',               'Inventory'],
        ['users',         'admin_users.php',              'fa-users',               'User Management'],
        ['directory',     'admin_bec_directory.php',      'fa-id-card',             'BEC Directory'],
        ['notifications', 'admin_notifications.php',       'fa-bell',                'Notifications'],
    ],
];
?>
<aside class="sb" id="sb">
  <div class="sb-top">
    <div class="seal-ring"><div class="seal-spin"></div><div class="seal-core"><img src="assets/logs.png" alt="BEC" onerror="this.style.display='none'"></div></div>
    <div class="sb-brand"><strong>Batangas Eastern Colleges</strong><em>Equipment Management</em></div>
  </div>
  <div class="sb-user">
    <div class="uav"><?php echo htmlspecialchars(strtoupper(substr((string) $__adminName, 0, 2)), ENT_QUOTES); ?></div>
    <div><span class="uname"><?php echo htmlspecialchars((string) $__adminName, ENT_QUOTES); ?></span><span class="urole">Administrator</span></div>
  </div>
  <nav class="sb-nav">
    <?php foreach ($__sections as $__sec => $__items): ?>
      <div class="nav-sec"><?php echo $__sec; ?></div>
      <?php foreach ($__items as [$__key, $__href, $__icon, $__label]): ?>
        <a href="<?php echo $__href; ?>" class="ni<?php echo $__active === $__key ? ' on' : ''; ?>">
          <span class="ni-ic"><i class="fas <?php echo $__icon; ?>"></i></span><?php echo $__label; ?>
          <?php if ($__key === 'notifications' && $__navUnread > 0): ?><span class="nbadge"><?php echo $__navUnread; ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sb-foot">
    <a class="lout" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </div>
</aside>
