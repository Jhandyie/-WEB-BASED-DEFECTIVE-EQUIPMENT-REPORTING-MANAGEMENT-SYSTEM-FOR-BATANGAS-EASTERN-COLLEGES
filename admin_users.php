<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in and has admin role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin/login.html?error=' . urlencode('Unauthorized access'));
    exit();
}

$admin_name = $_SESSION['fullname'] ?? 'Administrator';

// Handle POST requests for CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'add_user':
            $result = createUser($_POST['role'], $_POST);
            if ($result) {
                $_SESSION['success_message'] = 'User created successfully!';
            } else {
                $_SESSION['error_message'] = 'Failed to create user.';
            }
            break;

        case 'update_user':
            // Extract only the fields that should be updated
            $updateData = [
                'username' => $_POST['username'],
                'fullname' => $_POST['fullname'],
                'email' => $_POST['email'],
                'status' => $_POST['status']
            ];

            // Add role-specific fields
            $role = $_POST['role'];
            if (($role === 'faculty' || $role === 'student') && isset($_POST['department'])) $updateData['department'] = $_POST['department'];
            if ($role === 'technician' && isset($_POST['specialization'])) $updateData['specialization'] = $_POST['specialization'];

            $result = updateUser($_POST['user_id'], $_POST['role'], $updateData);
            if ($result) {
                $_SESSION['success_message'] = 'User updated successfully!';
            } else {
                $_SESSION['error_message'] = 'Failed to update user.';
            }
            break;

        case 'delete_user':
            // Soft delete by setting status to inactive
            $result = updateUser($_POST['user_id'], $_POST['role'], ['status' => 'inactive']);
            if ($result) {
                $_SESSION['success_message'] = 'User deactivated successfully!';
            } else {
                $_SESSION['error_message'] = 'Failed to deactivate user.';
            }
            break;

        case 'activate_user':
            $result = updateUser($_POST['user_id'], $_POST['role'], ['status' => 'active']);
            if ($result) {
                $_SESSION['success_message'] = 'User activated successfully!';
            } else {
                $_SESSION['error_message'] = 'Failed to activate user.';
            }
            break;
    }

    // Redirect to avoid form resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Get filter parameters
$searchTerm = $_GET['search'] ?? '';
$selectedRole = $_GET['role'] ?? 'all';
$currentPage = (int)($_GET['page'] ?? 1);
$itemsPerPage = 25;

// Get filtered users
$filteredUsers = getFilteredUsers($searchTerm, $selectedRole);

// Pagination
$totalItems = count($filteredUsers);
$totalPages = ceil($totalItems / $itemsPerPage);
$currentPage = max(1, min($currentPage, $totalPages));
$startIndex = ($currentPage - 1) * $itemsPerPage;
$paginatedUsers = array_slice($filteredUsers, $startIndex, $itemsPerPage);

// Get user statistics
$userStats = [
    'total_users' => getTotalUserCount(),
    'active_admins' => count(array_filter(getAllUsersByRole('admin'), function($u) { return $u['status'] === 'active'; })),
    'active_handlers' => count(array_filter(getAllUsersByRole('handler'), function($u) { return $u['status'] === 'active'; })),
    'active_technicians' => count(array_filter(getAllUsersByRole('technician'), function($u) { return $u['status'] === 'active'; })),
    'active_faculty' => count(array_filter(getAllUsersByRole('faculty'), function($u) { return $u['status'] === 'active'; })),
    'active_students' => count(array_filter(getAllUsersByRole('student'), function($u) { return $u['status'] === 'active'; }))
];

// Get user for modal (if editing)
$selectedUser = null;
if (isset($_GET['edit_id']) && isset($_GET['edit_role'])) {
    $selectedUser = getUserById($_GET['edit_id'], $_GET['edit_role']);
    if ($selectedUser) {
        $selectedUser['role'] = $_GET['edit_role'];
    }
}

// Helper functions
function getFilteredUsers($search, $role) {
    $allUsers = [];

    $roles = ['admin', 'handler', 'technician', 'faculty', 'student'];
    if ($role !== 'all') {
        $roles = [$role];
    }

    foreach ($roles as $r) {
        $users = getAllUsersByRole($r);
        foreach ($users as &$user) {
            $user['role'] = $r;
            $user['role_display'] = ucfirst($r);
        }
        $allUsers = array_merge($allUsers, $users);
    }

    // Apply search filter
    if (!empty($search)) {
        $term = strtolower($search);
        $allUsers = array_filter($allUsers, function($user) use ($term) {
            return strpos(strtolower($user['fullname'] ?? ''), $term) !== false ||
                   strpos(strtolower($user['username'] ?? ''), $term) !== false ||
                   strpos(strtolower($user['email'] ?? ''), $term) !== false ||
                   strpos(strtolower($user[$user['role'] . '_id'] ?? ''), $term) !== false;
        });
    }

    // Sort by fullname
    usort($allUsers, function($a, $b) {
        return strcmp($a['fullname'] ?? '', $b['fullname'] ?? '');
    });

    return $allUsers;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - BEC Equipment System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/global_styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin_style.css">
    <style>
        .users-header {
            background: linear-gradient(135deg, var(--bec-maroon) 0%, #a30000 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(128, 0, 0, 0.2);
        }

        .users-header h2 {
            font-size: 24px;
            margin-bottom: 8px;
            font-weight: 700;
        }

        .users-header p {
            opacity: 0.95;
            font-size: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            border: 2px solid #e9ecef;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }

        .stat-card.primary { border-color: var(--bec-maroon); }
        .stat-card.success { border-color: #28a745; }
        .stat-card.info { border-color: #17a2b8; }
        .stat-card.warning { border-color: #ffc107; }
        .stat-card.secondary { border-color: #6c757d; }

        .stat-card h3 {
            font-size: 28px;
            font-weight: bold;
            margin: 0 0 5px 0;
            color: #333;
        }

        .stat-card p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
            font-size: 14px;
        }

        .filter-group input,
        .filter-group select {
            padding: 10px 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--bec-maroon);
            box-shadow: 0 0 0 3px rgba(127, 29, 29, 0.1);
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .btn-primary {
            background: var(--bec-maroon);
            color: white;
        }

        .btn-primary:hover {
            background: #a30000;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(127, 29, 29, 0.4);
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
        }

        .users-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }

        .table-header {
            background: linear-gradient(135deg, var(--bec-maroon) 0%, #a30000 100%);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            margin: 0;
            font-size: 18px;
        }

        .users-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th,
        .users-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .users-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .users-table tbody tr:hover {
            background: #f8f9fa;
        }

        .role-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-admin { background: #dc3545; color: white; }
        .role-handler { background: #17a2b8; color: white; }
        .role-technician { background: #ffc107; color: #333; }
        .role-faculty { background: #28a745; color: white; }
        .role-student { background: #6f42c1; color: white; }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .action-btn {
            padding: 6px 10px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }

        .action-btn.edit {
            background: #17a2b8;
            color: white;
        }

        .action-btn.edit:hover {
            background: #138496;
        }

        .action-btn.delete {
            background: #dc3545;
            color: white;
        }

        .action-btn.delete:hover {
            background: #c82333;
        }

        .action-btn.activate {
            background: #28a745;
            color: white;
        }

        .action-btn.activate:hover {
            background: #218838;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin-top: 25px;
        }

        .pagination button {
            padding: 8px 12px;
            border: 2px solid var(--bec-maroon);
            background: white;
            color: var(--bec-maroon);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .pagination button:hover:not(:disabled) {
            background: var(--bec-maroon);
            color: white;
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination span {
            font-weight: 600;
            color: #333;
        }

        /* Modal Styles */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }

        .modal-header h2 {
            margin: 0;
            color: var(--bec-maroon);
            font-size: 24px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            padding: 5px;
            border-radius: 50%;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: #f8f9fa;
            color: #333;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
            font-size: 14px;
        }

        .form-group input,
        .form-group select {
            padding: 10px 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--bec-maroon);
            box-shadow: 0 0 0 3px rgba(127, 29, 29, 0.1);
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 25px;
            padding-top: 15px;
            border-top: 2px solid #e9ecef;
        }

        /* Alert Messages */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert i {
            font-size: 18px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .users-table {
                overflow-x: auto;
            }

            .users-table table {
                min-width: 800px;
            }
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
                <h1 class="page-title">User Management</h1>
                <div class="breadcrumb">
                    <span>Home</span>
                    <i class="fas fa-chevron-right"></i>
                    <span>Users</span>
                    <i class="fas fa-chevron-right"></i>
                    <span>Manage</span>
                </div>
            </div>
            <div class="header-actions">
                <button class="btn btn-success" onclick="openAddModal()">
                    <i class="fas fa-plus"></i>
                    Add User
                </button>
            </div>
        </div>

        <div class="content-area">
            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="users-header">
                <h2>User Account Management</h2>
                <p>Manage user accounts, roles, and access permissions across the system.</p>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <h3><?php echo $userStats['total_users']; ?></h3>
                    <p>Total Users</p>
                </div>
                <div class="stat-card danger">
                    <h3><?php echo $userStats['active_admins']; ?></h3>
                    <p>Administrators</p>
                </div>
                <div class="stat-card info">
                    <h3><?php echo $userStats['active_handlers']; ?></h3>
                    <p>Equipment Handlers</p>
                </div>
                <div class="stat-card warning">
                    <h3><?php echo $userStats['active_technicians']; ?></h3>
                    <p>Technicians</p>
                </div>
                <div class="stat-card success">
                    <h3><?php echo $userStats['active_faculty']; ?></h3>
                    <p>Faculty Members</p>
                </div>
                <div class="stat-card secondary">
                    <h3><?php echo $userStats['active_students']; ?></h3>
                    <p>Students</p>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="filters-section">
                <form method="GET" id="filterForm">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label for="search">Search Users</label>
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Name, username, or email...">
                        </div>
                        <div class="filter-group">
                            <label for="role">Role</label>
                            <select id="role" name="role">
                                <option value="all">All Roles</option>
                                <option value="admin" <?php echo $selectedRole === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                                <option value="handler" <?php echo $selectedRole === 'handler' ? 'selected' : ''; ?>>Equipment Handler</option>
                                <option value="technician" <?php echo $selectedRole === 'technician' ? 'selected' : ''; ?>>Technician</option>
                                <option value="faculty" <?php echo $selectedRole === 'faculty' ? 'selected' : ''; ?>>Faculty</option>
                                <option value="student" <?php echo $selectedRole === 'student' ? 'selected' : ''; ?>>Student</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Users Table -->
            <div class="users-table">
                <div class="table-header">
                    <h3><i class="fas fa-users"></i> System Users (<?php echo $totalItems; ?>)</h3>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($paginatedUsers)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px; color: #666;">
                                    <i class="fas fa-user-slash" style="font-size: 48px; color: #ddd; margin-bottom: 10px; display: block;"></i>
                                    No users found matching your criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($paginatedUsers as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user[$user['role'] . '_id']); ?></td>
                                    <td><?php echo htmlspecialchars($user['fullname'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($user['username'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="role-badge role-<?php echo $user['role']; ?>">
                                            <?php echo htmlspecialchars($user['role_display']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $user['status']; ?>">
                                            <?php echo htmlspecialchars($user['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $user['last_login'] ? date('M d, Y H:i', strtotime($user['last_login'])) : 'Never'; ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn edit" onclick="openEditModal('<?php echo $user[$user['role'] . '_id']; ?>', '<?php echo $user['role']; ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($user['status'] === 'active'): ?>
                                                <button class="action-btn delete" onclick="deactivateUser('<?php echo $user[$user['role'] . '_id']; ?>', '<?php echo $user['role']; ?>', '<?php echo htmlspecialchars($user['fullname']); ?>')">
                                                    <i class="fas fa-user-times"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="action-btn activate" onclick="activateUser('<?php echo $user[$user['role'] . '_id']; ?>', '<?php echo $user['role']; ?>', '<?php echo htmlspecialchars($user['fullname']); ?>')">
                                                    <i class="fas fa-user-check"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <button onclick="changePage(<?php echo $currentPage - 1; ?>)" <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>>
                        <i class="fas fa-chevron-left"></i> Previous
                    </button>
                    <span>Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
                    <button onclick="changePage(<?php echo $currentPage + 1; ?>)" <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>>
                        Next <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit User Modal -->
    <div id="userModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Add New User</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form id="userForm" method="POST">
                <input type="hidden" name="action" id="formAction" value="add_user">
                <input type="hidden" name="user_id" id="userId">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="role">Role *</label>
                        <select id="user_role" name="role" required onchange="updateRoleSpecificFields()">
                            <option value="">Select Role</option>
                            <option value="admin">Administrator</option>
                            <option value="handler">Equipment Handler</option>
                            <option value="technician">Technician</option>
                            <option value="faculty">Faculty Member</option>
                            <option value="student">Student</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="username">Username *</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="fullname">Full Name *</label>
                        <input type="text" id="fullname" name="fullname" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <div class="form-group" id="passwordGroup">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <!-- Role-specific fields -->
                    <div class="form-group" id="departmentGroup" style="display: none;">
                        <label for="department">Department</label>
                        <input type="text" id="department" name="department" placeholder="e.g., Computer Science">
                    </div>
                    <div class="form-group" id="specializationGroup" style="display: none;">
                        <label for="specialization">Specialization</label>
                        <input type="text" id="specialization" name="specialization" placeholder="e.g., Electrical, Mechanical">
                    </div>
                    <div class="form-group" id="studentIdGroup" style="display: none;">
                        <label for="student_id_display">Student ID</label>
                        <input type="text" id="student_id_display" name="student_id_display" placeholder="Will be auto-generated">
                    </div>
                    <div class="form-group" id="facultyIdGroup" style="display: none;">
                        <label for="faculty_id_display">Faculty ID</label>
                        <input type="text" id="faculty_id_display" name="faculty_id_display" placeholder="Will be auto-generated">
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save User</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New User';
            document.getElementById('formAction').value = 'add_user';
            document.getElementById('userForm').reset();
            document.getElementById('passwordGroup').style.display = 'block';
            document.getElementById('password').required = true;
            document.getElementById('userModal').style.display = 'flex';
            updateRoleSpecificFields();
        }

        function openEditModal(userId, role) {
            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('formAction').value = 'update_user';
            document.getElementById('userId').value = userId;
            document.getElementById('passwordGroup').style.display = 'none';
            document.getElementById('password').required = false;

            // Fetch user data (you would need to implement AJAX for this)
            // For now, we'll use a simple redirect to populate the form
            window.location.href = `?edit_id=${userId}&edit_role=${role}`;
        }

        function closeModal() {
            document.getElementById('userModal').style.display = 'none';
        }

        function updateRoleSpecificFields() {
            const role = document.getElementById('user_role').value;
            const departmentGroup = document.getElementById('departmentGroup');
            const specializationGroup = document.getElementById('specializationGroup');
            const studentIdGroup = document.getElementById('studentIdGroup');
            const facultyIdGroup = document.getElementById('facultyIdGroup');

            // Hide all role-specific fields first
            departmentGroup.style.display = 'none';
            specializationGroup.style.display = 'none';
            studentIdGroup.style.display = 'none';
            facultyIdGroup.style.display = 'none';

            // Show relevant fields based on role
            if (role === 'faculty' || role === 'student') {
                departmentGroup.style.display = 'block';
            }
            if (role === 'technician') {
                specializationGroup.style.display = 'block';
            }
            if (role === 'student') {
                studentIdGroup.style.display = 'block';
            }
            if (role === 'faculty') {
                facultyIdGroup.style.display = 'block';
            }
        }

        function deactivateUser(userId, role, name) {
            if (confirm(`Are you sure you want to deactivate ${name}?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="${userId}">
                    <input type="hidden" name="role" value="${role}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function activateUser(userId, role, name) {
            if (confirm(`Are you sure you want to activate ${name}?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="activate_user">
                    <input type="hidden" name="user_id" value="${userId}">
                    <input type="hidden" name="role" value="${role}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function changePage(page) {
            const url = new URL(window.location);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }

        // Populate edit form if editing
        <?php if ($selectedUser): ?>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('formAction').value = 'update_user';
            document.getElementById('userId').value = '<?php echo $selectedUser[$selectedUser['role'] . '_id']; ?>';
            document.getElementById('user_role').value = '<?php echo $selectedUser['role']; ?>';
            document.getElementById('username').value = '<?php echo htmlspecialchars($selectedUser['username'] ?? ''); ?>';
            document.getElementById('fullname').value = '<?php echo htmlspecialchars($selectedUser['fullname'] ?? ''); ?>';
            document.getElementById('email').value = '<?php echo htmlspecialchars($selectedUser['email'] ?? ''); ?>';
            document.getElementById('status').value = '<?php echo $selectedUser['status']; ?>';

            // Populate role-specific fields
            <?php if (isset($selectedUser['department'])): ?>
            document.getElementById('department').value = '<?php echo htmlspecialchars($selectedUser['department']); ?>';
            <?php endif; ?>
            <?php if (isset($selectedUser['specialization'])): ?>
            document.getElementById('specialization').value = '<?php echo htmlspecialchars($selectedUser['specialization']); ?>';
            <?php endif; ?>

            updateRoleSpecificFields();
            document.getElementById('userModal').style.display = 'flex';
        });
        <?php endif; ?>

        // Close modal when clicking outside
        document.getElementById('userModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>
</html>
