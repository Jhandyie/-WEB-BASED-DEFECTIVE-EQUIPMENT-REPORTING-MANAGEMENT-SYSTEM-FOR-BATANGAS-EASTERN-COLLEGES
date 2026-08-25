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

$__sections = [
    'Overview' => [
        ['dashboard',     'admin_dashboard.php',          'fa-th-large',            'Dashboard'],
        ['analytics',     'admin_analytics.php',          'fa-chart-bar',           'Analytics'],
        ['workorders',    'admin_work_orders.php',        'fa-clipboard-check',     'Work Orders'],
    ],
    'Reports' => [
        ['defects',       'admin_defect_reports.php',     'fa-exclamation-triangle','Defect Reports'],
        ['assign',        'admin_assign_technicians.php', 'fa-user-cog',            'Assign Technicians'],
        ['preventive',    'admin_preventive.php',         'fa-calendar-check',      'Preventive Maint.'],
    ],
    'Management' => [
        ['inventory',     'admin_inventory.php',          'fa-boxes',               'Inventory'],
        ['users',         'admin_users.php',              'fa-users',               'User Management'],
        ['directory',     'admin_bec_directory.php',      'fa-id-card',             'BEC Directory'],
        // Notifications is deliberately NOT a nav item. It is a bell with an
        // unread count in the header of every admin page (the page's own
        // .ic-btn, or the .aia-bell that includes/admin_assistant.php relocates
        // into the header on the pages without one). A destination you glance at
        // does not need to sit in the list of places you go.
        ['backup',        'admin_backup.php',              'fa-database',            'Backup & Recovery'],
    ],
];

/* Venue Reservations follows config/features.php rather than being listed or
   commented out by hand. It was removed from the array when the module was
   hidden, so switching the flag back on restored the pages and the public nav
   but left the admin without a way in. Gated here so one switch covers all. */
if (!function_exists('becVenueEnabled')) {
    $__feat = __DIR__ . '/../config/features.php';
    if (is_file($__feat)) { require_once $__feat; }
}
if (function_exists('becVenueEnabled') && becVenueEnabled()) {
    $__sections['Reports'][] = ['reservations', 'admin_reservations.php', 'fa-file-signature', 'Venue Reservations'];
}
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
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sb-foot">
    <a class="lout" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </div>
</aside>
