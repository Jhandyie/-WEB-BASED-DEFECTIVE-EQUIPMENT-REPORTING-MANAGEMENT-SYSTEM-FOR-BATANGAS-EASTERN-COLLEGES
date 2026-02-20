<?php
session_start();
require_once 'config/database.php';
require_once 'includes/notification_helper.php';

// Check if user is logged in and has admin role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin/login.html?error=' . urlencode('Unauthorized access'));
    exit();
}

$admin_name = $_SESSION['fullname'] ?? 'Administrator';

// Get inventory data from hardcoded admin inventory
$adminData = getAdminInventoryData();
$equipment = [];

// Category name mapping
$categoryNames = [
    'airConditioners' => 'Air Conditioners',
    'televisions' => 'Televisions',
    'fans' => 'Fans',
    'whiteboards' => 'Whiteboards',
    'lockers' => 'Lockers',
    'officeChairs' => 'Office Chairs',
    'computers' => 'Computers'
];

foreach ($adminData as $category => $items) {
    $categoryName = $categoryNames[$category] ?? ucfirst($category);
    foreach ($items as $item) {
        $item['category_name'] = $categoryName;
        $item['category'] = $category;
        $equipment[] = $item;
    }
}

// Get filter parameters
$searchTerm = $_GET['search'] ?? '';
$selectedCategory = $_GET['category'] ?? 'all';
$selectedCampus = $_GET['campus'] ?? 'all';
$selectedBuilding = $_GET['building'] ?? 'all';
$selectedStatus = $_GET['status'] ?? 'all';
$currentPage = (int)($_GET['page'] ?? 1);
$itemsPerPage = 50;

// Get filtered items
$filteredItems = $equipment;

// Apply category filter
if ($selectedCategory !== 'all') {
    $categoryMap = [
        'airConditioners' => 'Air Conditioners',
        'televisions' => 'Televisions',
        'fans' => 'Fans',
        'whiteboards' => 'Whiteboards',
        'lockers' => 'Lockers',
        'officeChairs' => 'Office Chairs',
        'computers' => 'Computers'
    ];

    $targetCategory = $categoryMap[$selectedCategory] ?? $selectedCategory;
    $filteredItems = array_filter($filteredItems, function($item) use ($targetCategory) {
        return ($item['category_name'] ?? '') === $targetCategory;
    });
}

// Apply filters
if ($selectedCampus !== 'all') {
    $filteredItems = array_filter($filteredItems, function($item) use ($selectedCampus) {
        return $item['campus'] === $selectedCampus;
    });
}

if ($selectedBuilding !== 'all') {
    $filteredItems = array_filter($filteredItems, function($item) use ($selectedBuilding) {
        return isset($item['building']) ? $item['building'] === $selectedBuilding : false;
    });
}

if ($selectedStatus !== 'all') {
    $filteredItems = array_filter($filteredItems, function($item) use ($selectedStatus) {
        return $item['status'] === $selectedStatus;
    });
}

if (!empty($searchTerm)) {
    $term = strtolower($searchTerm);
    $filteredItems = array_filter($filteredItems, function($item) use ($term) {
        return strpos(strtolower($item['propertyNo'] ?? ''), $term) !== false ||
               strpos(strtolower($item['room'] ?? ''), $term) !== false ||
               strpos(strtolower($item['buildingName'] ?? ''), $term) !== false ||
               strpos(strtolower($item['article'] ?? ''), $term) !== false ||
               strpos(strtolower($item['type'] ?? ''), $term) !== false ||
               strpos(strtolower($item['remarks'] ?? ''), $term) !== false;
    });
}

// Pagination
$totalItems = count($filteredItems);
$totalPages = ceil($totalItems / $itemsPerPage);
$currentPage = max(1, min($currentPage, $totalPages));
$startIndex = ($currentPage - 1) * $itemsPerPage;
$paginatedItems = array_slice($filteredItems, $startIndex, $itemsPerPage);

// Get unique buildings for filter
$buildings = ['all'];
foreach ($equipment as $item) {
    if (isset($item['building']) && !in_array($item['building'], $buildings)) {
        $buildings[] = $item['building'];
    }
}

// Calculate statistics
$stats = [
    'totalItems' => count($equipment),
    'totalAirConditioners' => count(array_filter($equipment, function($item) { return isset($item['category_name']) && $item['category_name'] === 'Air Conditioners'; })),
    'totalTelevisions' => count(array_filter($equipment, function($item) { return isset($item['category_name']) && $item['category_name'] === 'Televisions'; })),
    'totalFans' => count(array_filter($equipment, function($item) { return isset($item['category_name']) && $item['category_name'] === 'Fans'; })),
    'totalWhiteboards' => count(array_filter($equipment, function($item) { return isset($item['category_name']) && $item['category_name'] === 'Whiteboards'; })),
    'totalLockers' => count(array_filter($equipment, function($item) { return isset($item['category_name']) && $item['category_name'] === 'Lockers'; })),
    'totalOfficeChairs' => count(array_filter($equipment, function($item) { return isset($item['category_name']) && $item['category_name'] === 'Office Chairs'; })),
    'totalComputers' => count(array_filter($equipment, function($item) { return isset($item['category_name']) && $item['category_name'] === 'Computers'; })),
    'mainCampusItems' => count(array_filter($equipment, function($item) { return isset($item['campus']) && $item['campus'] === "Main Campus"; })),
    'annex1Items' => count(array_filter($equipment, function($item) { return isset($item['campus']) && $item['campus'] === "Annex 1 Campus"; })),
    'annex2Items' => count(array_filter($equipment, function($item) { return isset($item['campus']) && $item['campus'] === "Annex 2 Campus"; })),
    'activeItems' => count(array_filter($equipment, function($item) { return isset($item['status']) && $item['status'] === "Active"; })),
    'notWorkingItems' => count(array_filter($equipment, function($item) {
        return isset($item['status']) && in_array($item['status'], ["Not Working", "Broken", "Damaged", "Under Repair"]);
    })),
    'newItems' => count(array_filter($equipment, function($item) { return isset($item['status']) && $item['status'] === "New"; })),
    'oldItems' => count(array_filter($equipment, function($item) { return isset($item['status']) && $item['status'] === "Old"; }))
];

// Get selected item for modal
$selectedItem = null;
if (isset($_GET['item_id'])) {
    $itemId = (int)$_GET['item_id'];
    foreach ($equipment as $item) {
        if ($item['id'] === $itemId) {
            $selectedItem = $item;
            break;
        }
    }
}

// Handle export functionality
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=inventory_report_' . date('Y-m-d_H-i-s') . '.csv');

    // Create output stream
    $output = fopen('php://output', 'w');

    // Write CSV headers
    fputcsv($output, [
        'Property No.',
        'Campus',
        'Building',
        'Room',
        'Article/Type',
        'Quantity',
        'Status',
        'Remarks'
    ]);

    // Write data rows
    foreach ($filteredItems as $item) {
        fputcsv($output, [
            $item['propertyNo'],
            $item['campus'],
            $item['buildingName'],
            $item['room'],
            $item['article'] ?? $item['type'] ?? $item['classification'] ?? 'N/A',
            $item['qty'],
            $item['status'],
            $item['remarks'] ?? ''
        ]);
    }

    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - BEC Equipment System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/global_styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin_style.css">
    <style>
        .inventory-header {
            background: linear-gradient(to right, #7f1d1d, #991b1b, #b45309);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 2px solid #fef3c7;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        .stat-card.primary { border-color: #7f1d1d; }
        .stat-card.success { border-color: #10b981; }
        .stat-card.warning { border-color: #f59e0b; }
        .stat-card.danger { border-color: #ef4444; }

        .stat-card h3 {
            font-size: 2rem;
            font-weight: bold;
            margin: 0;
            color: #1f2937;
        }

        .stat-card p {
            margin: 0.5rem 0 0 0;
            color: #6b7280;
        }

        .category-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .category-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 2px solid #fef3c7;
        }

        .category-card.active {
            background: linear-gradient(to bottom right, #7f1d1d, #b45309);
            color: white;
            border-color: #b45309;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .category-card:hover {
            transform: scale(1.05);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        .category-card i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 2px solid #fef3c7;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: #374151;
        }

        .filter-group input,
        .filter-group select {
            padding: 0.75rem;
            border: 2px solid #fef3c7;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.2s;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #7f1d1d;
            box-shadow: 0 0 0 3px rgba(127, 29, 29, 0.1);
        }

        .inventory-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 2px solid #fef3c7;
        }

        .table-header {
            background: linear-gradient(to right, #7f1d1d, #991b1b, #b45309);
            color: white;
            padding: 1rem 1.5rem;
        }

        .table-header h3 {
            margin: 0;
            font-size: 1.25rem;
        }

        .inventory-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .inventory-table th,
        .inventory-table td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        .inventory-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }

        .inventory-table tbody tr:hover {
            background: #fef3c7;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-active { background: #d1fae5; color: #065f46; }
        .status-new { background: #dbeafe; color: #1e40af; }
        .status-old { background: #fef3c7; color: #92400e; }
        .status-not-working { background: #fee2e2; color: #991b1b; }
        .status-under-repair { background: #fed7aa; color: #9a3412; }
        .status-broken { background: #fecaca; color: #b91c1c; }
        .status-damaged { background: #fecaca; color: #b91c1c; }
        .status-faded { background: #fef3c7; color: #92400e; }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            margin-top: 2rem;
        }

        .pagination button {
            padding: 0.5rem 1rem;
            border: 2px solid #7f1d1d;
            background: white;
            color: #7f1d1d;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .pagination button:hover:not(:disabled) {
            background: #7f1d1d;
            color: white;
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

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
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            border: 4px solid #fbbf24;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6b7280;
        }

        .modal-body {
            display: grid;
            gap: 1rem;
        }

        .modal-field {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .modal-field strong {
            color: #374151;
        }

        .export-btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .export-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .add-btn {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white;
            border: none;
            padding: 0.875rem 1.75rem;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 1rem;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
            font-size: 0.95rem;
            letter-spacing: 0.5px;
        }

        .add-btn:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 20px rgba(5, 150, 105, 0.4);
            background: linear-gradient(135deg, #047857 0%, #065f46 100%);
        }

        .add-btn:active {
            transform: translateY(0) scale(0.98);
            transition: all 0.1s ease;
        }

        .action-btn {
            padding: 0.3rem 0.6rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.7rem;
            font-weight: 700;
            margin-right: 0.3rem;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.2rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1), 0 1px 2px rgba(0, 0, 0, 0.06);
            min-width: 60px;
            height: 30px;
            position: relative;
            overflow: hidden;
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .action-btn:hover::before {
            left: 100%;
        }

        .action-btn:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15), 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .action-btn:active {
            transform: translateY(0) scale(0.98);
            transition: all 0.1s;
        }

        .edit-btn {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            border: 1px solid rgba(37, 99, 235, 0.3);
        }

        .edit-btn:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            border-color: rgba(29, 78, 216, 0.5);
        }

        .delete-btn {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
            border: 1px solid rgba(220, 38, 38, 0.3);
        }

        .delete-btn:hover {
            background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
            border-color: rgba(185, 28, 28, 0.5);
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
                <h1 class="page-title">Inventory Management</h1>
                <div class="breadcrumb">
                    <span>Home</span>
                    <i class="fas fa-chevron-right"></i>
                    <span>Reports</span>
                    <i class="fas fa-chevron-right"></i>
                    <span>Inventory</span>
                </div>
            </div>
            <div class="header-actions">
                <button class="add-btn" onclick="showAddModal()">
                    <i class="fas fa-plus-circle"></i>
                    Add New Asset
                </button>
                <button class="export-btn" onclick="window.location.href='?export=csv'">
                    <i class="fas fa-download"></i>
                    Export Report
                </button>
            </div>
        </div>

        <div class="content-area">
            <!-- Header -->
            <div class="inventory-header">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h1 style="margin: 0; font-size: 2rem;">Campus Inventory Management System</h1>
                        <p style="margin: 0.5rem 0 0 0; opacity: 0.9;">Date: <?php echo date('F d, Y'); ?> | Total Items: <?php echo $stats['totalItems']; ?></p>
                    </div>
                    <i class="fas fa-boxes" style="font-size: 3rem; opacity: 0.8;"></i>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <h3><?php echo $stats['mainCampusItems']; ?></h3>
                    <p>Main Campus</p>
                </div>
                <div class="stat-card success">
                    <h3><?php echo $stats['annex1Items']; ?></h3>
                    <p>Annex 1 Campus</p>
                </div>
                <div class="stat-card warning">
                    <h3><?php echo $stats['annex2Items']; ?></h3>
                    <p>Annex 2 Campus</p>
                </div>
                <div class="stat-card danger">
                    <h3><?php echo $stats['notWorkingItems']; ?></h3>
                    <p>Need Attention</p>
                </div>
            </div>

            <!-- Category Cards -->
            <div class="category-grid">
                <div class="category-card <?php echo $selectedCategory === 'all' ? 'active' : ''; ?>" onclick="filterByCategory('all')">
                    <i class="fas fa-th-large"></i>
                    <h4>All Items</h4>
                    <p><?php echo $stats['totalItems']; ?></p>
                </div>
                <div class="category-card <?php echo $selectedCategory === 'airConditioners' ? 'active' : ''; ?>" onclick="filterByCategory('airConditioners')">
                    <i class="fas fa-snowflake"></i>
                    <h4>Air Conditioners</h4>
                    <p><?php echo $stats['totalAirConditioners']; ?></p>
                </div>
                <div class="category-card <?php echo $selectedCategory === 'televisions' ? 'active' : ''; ?>" onclick="filterByCategory('televisions')">
                    <i class="fas fa-tv"></i>
                    <h4>Televisions</h4>
                    <p><?php echo $stats['totalTelevisions']; ?></p>
                </div>
                <div class="category-card <?php echo $selectedCategory === 'fans' ? 'active' : ''; ?>" onclick="filterByCategory('fans')">
                    <i class="fas fa-fan"></i>
                    <h4>Fans</h4>
                    <p><?php echo $stats['totalFans']; ?></p>
                </div>
                <div class="category-card <?php echo $selectedCategory === 'whiteboards' ? 'active' : ''; ?>" onclick="filterByCategory('whiteboards')">
                    <i class="fas fa-chalkboard"></i>
                    <h4>Whiteboards</h4>
                    <p><?php echo $stats['totalWhiteboards']; ?></p>
                </div>
                <div class="category-card <?php echo $selectedCategory === 'lockers' ? 'active' : ''; ?>" onclick="filterByCategory('lockers')">
                    <i class="fas fa-lock"></i>
                    <h4>Lockers</h4>
                    <p><?php echo $stats['totalLockers']; ?></p>
                </div>
                <div class="category-card <?php echo $selectedCategory === 'officeChairs' ? 'active' : ''; ?>" onclick="filterByCategory('officeChairs')">
                    <i class="fas fa-chair"></i>
                    <h4>Office Chairs</h4>
                    <p><?php echo $stats['totalOfficeChairs']; ?></p>
                </div>
                <div class="category-card <?php echo $selectedCategory === 'computers' ? 'active' : ''; ?>" onclick="filterByCategory('computers')">
                    <i class="fas fa-desktop"></i>
                    <h4>Computers</h4>
                    <p><?php echo $stats['totalComputers']; ?></p>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <h3 style="margin-bottom: 1rem; color: #374151;"><i class="fas fa-filter"></i> Filters</h3>
                <form method="GET" id="filterForm">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label for="search">Search</label>
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Search inventory...">
                        </div>
                        <div class="filter-group">
                            <label for="campus">Campus</label>
                            <select id="campus" name="campus">
                                <option value="all" <?php echo $selectedCampus === 'all' ? 'selected' : ''; ?>>All Campuses</option>
                                <option value="Main Campus" <?php echo $selectedCampus === 'Main Campus' ? 'selected' : ''; ?>>Main Campus</option>
                                <option value="Annex 1 Campus" <?php echo $selectedCampus === 'Annex 1 Campus' ? 'selected' : ''; ?>>Annex 1 Campus</option>
                                <option value="Annex 2 Campus" <?php echo $selectedCampus === 'Annex 2 Campus' ? 'selected' : ''; ?>>Annex 2 Campus</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="building">Building</label>
                            <select id="building" name="building">
                                <?php foreach ($buildings as $building): ?>
                                <option value="<?php echo $building; ?>" <?php echo $selectedBuilding === $building ? 'selected' : ''; ?>>
                                    <?php echo $building === 'all' ? 'All Buildings' : $building; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="all" <?php echo $selectedStatus === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="Active" <?php echo $selectedStatus === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="New" <?php echo $selectedStatus === 'New' ? 'selected' : ''; ?>>New</option>
                                <option value="Old" <?php echo $selectedStatus === 'Old' ? 'selected' : ''; ?>>Old</option>
                                <option value="Not Working" <?php echo $selectedStatus === 'Not Working' ? 'selected' : ''; ?>>Not Working</option>
                                <option value="Under Repair" <?php echo $selectedStatus === 'Under Repair' ? 'selected' : ''; ?>>Under Repair</option>
                                <option value="Broken" <?php echo $selectedStatus === 'Broken' ? 'selected' : ''; ?>>Broken</option>
                                <option value="Damaged" <?php echo $selectedStatus === 'Damaged' ? 'selected' : ''; ?>>Damaged</option>
                                <option value="Faded" <?php echo $selectedStatus === 'Faded' ? 'selected' : ''; ?>>Faded</option>
                            </select>
                        </div>
                    </div>
                </form>
                <p style="margin-top: 1rem; color: #6b7280;">
                    Showing <?php echo count($filteredItems); ?> items
                    <?php if (count($filteredItems) > $itemsPerPage): ?>
                    (Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?>)
                    <?php endif; ?>
                </p>
            </div>

            <!-- Inventory Table -->
            <div class="inventory-table">
                <div class="table-header">
                    <h3><i class="fas fa-list"></i> Inventory Items</h3>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Property No.</th>
                            <th>Campus</th>
                            <th>Building</th>
                            <th>Room</th>
                            <th>Article/Type</th>
                            <th>Qty</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paginatedItems as $item): ?>
                        <tr>
                            <td onclick="showItemDetails(<?php echo $item['id']; ?>)"><?php echo htmlspecialchars($item['propertyNo']); ?></td>
                            <td onclick="showItemDetails(<?php echo $item['id']; ?>)"><?php echo htmlspecialchars($item['campus']); ?></td>
                            <td onclick="showItemDetails(<?php echo $item['id']; ?>)"><?php echo htmlspecialchars($item['buildingName']); ?></td>
                            <td onclick="showItemDetails(<?php echo $item['id']; ?>)"><?php echo htmlspecialchars($item['room']); ?></td>
                            <td onclick="showItemDetails(<?php echo $item['id']; ?>)"><?php echo htmlspecialchars($item['article'] ?? $item['type'] ?? $item['classification'] ?? 'N/A'); ?></td>
                            <td onclick="showItemDetails(<?php echo $item['id']; ?>)"><?php echo htmlspecialchars($item['qty']); ?></td>
                            <td onclick="showItemDetails(<?php echo $item['id']; ?>)">
                                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $item['status'])); ?>">
                                    <?php echo htmlspecialchars($item['status']); ?>
                                </span>
                            </td>
                            <td>
                                <button class="action-btn edit-btn" onclick="showEditModal(<?php echo $item['id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="action-btn delete-btn" onclick="deleteItem(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['propertyNo']); ?>')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <button onclick="changePage(<?php echo $currentPage - 1; ?>)" <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>>
                    Previous
                </button>
                <span>Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
                <button onclick="changePage(<?php echo $currentPage + 1; ?>)" <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>>
                    Next
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Item Details Modal -->
    <?php if ($selectedItem): ?>
    <div class="modal" onclick="closeModal()">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2>Item Details</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-field">
                    <strong>Property Number:</strong>
                    <span><?php echo htmlspecialchars($selectedItem['propertyNo']); ?></span>
                </div>
                <div class="modal-field">
                    <strong>Status:</strong>
                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $selectedItem['status'])); ?>">
                        <?php echo htmlspecialchars($selectedItem['status']); ?>
                    </span>
                </div>
                <div class="modal-field">
                    <strong>Campus:</strong>
                    <span><?php echo htmlspecialchars($selectedItem['campus']); ?></span>
                </div>
                <div class="modal-field">
                    <strong>Building:</strong>
                    <span><?php echo htmlspecialchars($selectedItem['buildingName']); ?></span>
                </div>
                <div class="modal-field">
                    <strong>Room:</strong>
                    <span><?php echo htmlspecialchars($selectedItem['room']); ?></span>
                </div>
                <div class="modal-field">
                    <strong>Article/Type:</strong>
                    <span><?php echo htmlspecialchars($selectedItem['article'] ?? $selectedItem['type'] ?? $selectedItem['classification'] ?? 'N/A'); ?></span>
                </div>
                <div class="modal-field">
                    <strong>Quantity:</strong>
                    <span><?php echo htmlspecialchars($selectedItem['qty']); ?></span>
                </div>
                <?php if (isset($selectedItem['model'])): ?>
                <div class="modal-field">
                    <strong>Model:</strong>
                    <span><?php echo htmlspecialchars($selectedItem['model']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (isset($selectedItem['serialNo'])): ?>
                <div class="modal-field">
                    <strong>Serial Number:</strong>
                    <span><?php echo htmlspecialchars($selectedItem['serialNo']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (isset($selectedItem['size'])): ?>
                <div class="modal-field">
                    <strong>Size:</strong>
                    <span><?php echo htmlspecialchars($selectedItem['size']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (isset($selectedItem['slots'])): ?>
                <div class="modal-field">
                    <strong>Slots:</strong>
                    <span><?php echo htmlspecialchars($selectedItem['slots']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (isset($selectedItem['color'])): ?>
                <div class="modal-field">
                    <strong>Color:</strong>
                    <span><?php echo htmlspecialchars($selectedItem['color']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (isset($selectedItem['remarks']) && !empty($selectedItem['remarks'])): ?>
                <div class="modal-field" style="border-bottom: none; flex-direction: column; align-items: flex-start;">
                    <strong>Remarks:</strong>
                    <span style="margin-top: 0.5rem; padding: 0.5rem; background: #fef3c7; border-radius: 4px; width: 100%;">
                        <?php echo htmlspecialchars($selectedItem['remarks']); ?>
                    </span>
                </div>
                <?php endif; ?>
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
        function filterByCategory(category) {
            const url = new URL(window.location);
            url.searchParams.set('category', category);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        function changePage(page) {
            const url = new URL(window.location);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }

        function showItemDetails(itemId) {
            const url = new URL(window.location);
            url.searchParams.set('item_id', itemId);
            window.location.href = url.toString();
        }

        function closeModal() {
            const url = new URL(window.location);
            url.searchParams.delete('item_id');
            window.location.href = url.toString();
        }

        // Auto-submit form on filter change
        document.querySelectorAll('#filterForm select').forEach(select => {
            select.addEventListener('change', () => {
                const url = new URL(window.location);
                url.searchParams.set('page', '1');
                document.getElementById('filterForm').action = url.toString();
                document.getElementById('filterForm').submit();
            });
        });

        document.getElementById('search').addEventListener('input', debounce(function() {
            const url = new URL(window.location);
            url.searchParams.set('page', '1');
            document.getElementById('filterForm').action = url.toString();
            document.getElementById('filterForm').submit();
        }, 500));

        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Inventory Management Functions
        function showAddModal() {
            // Create modal for adding new equipment
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.onclick = () => modal.remove();

            modal.innerHTML = `
                <div class="modal-content" onclick="event.stopPropagation()">
                    <div class="modal-header">
                        <h2>Add New Equipment</h2>
                        <button class="modal-close" onclick="this.closest('.modal').remove()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form id="addEquipmentForm">
                            <div class="filter-group">
                                <label for="add_propertyNo">Property Number</label>
                                <input type="text" id="add_propertyNo" name="propertyNo" required>
                            </div>
                            <div class="filter-group">
                                <label for="add_campus">Campus</label>
                                <select id="add_campus" name="campus" required>
                                    <option value="Main Campus">Main Campus</option>
                                    <option value="Annex 1 Campus">Annex 1 Campus</option>
                                    <option value="Annex 2 Campus">Annex 2 Campus</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="add_buildingName">Building Name</label>
                                <input type="text" id="add_buildingName" name="buildingName" required>
                            </div>
                            <div class="filter-group">
                                <label for="add_room">Room</label>
                                <input type="text" id="add_room" name="room" required>
                            </div>
                            <div class="filter-group">
                                <label for="add_category">Category</label>
                                <select id="add_category" name="category" required>
                                    <option value="airConditioners">Air Conditioners</option>
                                    <option value="televisions">Televisions</option>
                                    <option value="fans">Fans</option>
                                    <option value="whiteboards">Whiteboards</option>
                                    <option value="lockers">Lockers</option>
                                    <option value="officeChairs">Office Chairs</option>
                                    <option value="computers">Computers</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="add_article">Article/Type</label>
                                <input type="text" id="add_article" name="article" required>
                            </div>
                            <div class="filter-group">
                                <label for="add_qty">Quantity</label>
                                <input type="number" id="add_qty" name="qty" min="1" value="1" required>
                            </div>
                            <div class="filter-group">
                                <label for="add_status">Status</label>
                                <select id="add_status" name="status" required>
                                    <option value="Active">Active</option>
                                    <option value="New">New</option>
                                    <option value="Old">Old</option>
                                    <option value="Not Working">Not Working</option>
                                    <option value="Under Repair">Under Repair</option>
                                    <option value="Broken">Broken</option>
                                    <option value="Damaged">Damaged</option>
                                    <option value="Faded">Faded</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="add_remarks">Remarks (Optional)</label>
                                <textarea id="add_remarks" name="remarks" rows="3"></textarea>
                            </div>
                            <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                                <button type="submit" class="update-btn">Add Equipment</button>
                                <button type="button" class="cancel-btn" onclick="this.closest('.modal').remove()">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);

            // Handle form submission
            document.getElementById('addEquipmentForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const data = Object.fromEntries(formData.entries());

                // Send add request to server
                fetch('api/update_inventory.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'add',
                        category: data.category,
                        propertyNo: data.propertyNo,
                        campus: data.campus,
                        buildingName: data.buildingName,
                        room: data.room,
                        article: data.article,
                        qty: parseInt(data.qty),
                        status: data.status,
                        remarks: data.remarks || ''
                    })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert('Equipment added successfully!');
                        modal.remove();
                        window.location.href = window.location.href;
                    } else {
                        alert('Error adding equipment: ' + (result.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error adding equipment. Please try again.');
                });
            });
        }

        function showEditModal(itemId) {
            // Find the item data
            const items = <?php echo json_encode($equipment); ?>;
            const item = items.find(i => i.id === itemId);

            if (!item) {
                alert('Item not found');
                return;
            }

            // Create modal for editing equipment
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.onclick = () => modal.remove();

            modal.innerHTML = `
                <div class="modal-content" onclick="event.stopPropagation()">
                    <div class="modal-header">
                        <h2>Edit Equipment</h2>
                        <button class="modal-close" onclick="this.closest('.modal').remove()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form id="editEquipmentForm">
                            <input type="hidden" name="itemId" value="${item.id}">
                            <div class="filter-group">
                                <label for="edit_propertyNo">Property Number</label>
                                <input type="text" id="edit_propertyNo" name="propertyNo" value="${item.propertyNo}" required>
                            </div>
                            <div class="filter-group">
                                <label for="edit_campus">Campus</label>
                                <select id="edit_campus" name="campus" required>
                                    <option value="Main Campus" ${item.campus === 'Main Campus' ? 'selected' : ''}>Main Campus</option>
                                    <option value="Annex 1 Campus" ${item.campus === 'Annex 1 Campus' ? 'selected' : ''}>Annex 1 Campus</option>
                                    <option value="Annex 2 Campus" ${item.campus === 'Annex 2 Campus' ? 'selected' : ''}>Annex 2 Campus</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="edit_buildingName">Building Name</label>
                                <input type="text" id="edit_buildingName" name="buildingName" value="${item.buildingName}" required>
                            </div>
                            <div class="filter-group">
                                <label for="edit_room">Room</label>
                                <input type="text" id="edit_room" name="room" value="${item.room}" required>
                            </div>
                            <div class="filter-group">
                                <label for="edit_article">Article/Type</label>
                                <input type="text" id="edit_article" name="article" value="${item.article || item.type || item.classification || ''}" required>
                            </div>
                            <div class="filter-group">
                                <label for="edit_qty">Quantity</label>
                                <input type="number" id="edit_qty" name="qty" min="1" value="${item.qty}" required>
                            </div>
                            <div class="filter-group">
                                <label for="edit_status">Status</label>
                                <select id="edit_status" name="status" required>
                                    <option value="Active" ${item.status === 'Active' ? 'selected' : ''}>Active</option>
                                    <option value="New" ${item.status === 'New' ? 'selected' : ''}>New</option>
                                    <option value="Old" ${item.status === 'Old' ? 'selected' : ''}>Old</option>
                                    <option value="Not Working" ${item.status === 'Not Working' ? 'selected' : ''}>Not Working</option>
                                    <option value="Under Repair" ${item.status === 'Under Repair' ? 'selected' : ''}>Under Repair</option>
                                    <option value="Broken" ${item.status === 'Broken' ? 'selected' : ''}>Broken</option>
                                    <option value="Damaged" ${item.status === 'Damaged' ? 'selected' : ''}>Damaged</option>
                                    <option value="Faded" ${item.status === 'Faded' ? 'selected' : ''}>Faded</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="edit_remarks">Remarks (Optional)</label>
                                <textarea id="edit_remarks" name="remarks" rows="3">${item.remarks || ''}</textarea>
                            </div>
                            <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                                <button type="submit" class="update-btn">Update Equipment</button>
                                <button type="button" class="cancel-btn" onclick="this.closest('.modal').remove()">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);

            // Handle form submission
            document.getElementById('editEquipmentForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const data = Object.fromEntries(formData.entries());

                // Send update request to server
                fetch('api/update_inventory.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'update',
                        itemId: data.itemId,
                        category: item.category,
                        propertyNo: data.propertyNo,
                        campus: data.campus,
                        buildingName: data.buildingName,
                        room: data.room,
                        article: data.article,
                        qty: parseInt(data.qty),
                        status: data.status,
                        remarks: data.remarks || ''
                    })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert('Equipment updated successfully!');
                        modal.remove();
                        window.location.href = window.location.href;
                    } else {
                        alert('Error updating equipment: ' + (result.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error updating equipment. Please try again.');
                });
            });
        }

        function deleteItem(itemId, propertyNo) {
            if (confirm(`Are you sure you want to delete equipment "${propertyNo}"? This action cannot be undone.`)) {
                // Find the item to get its category
                const items = <?php echo json_encode($equipment); ?>;
                const item = items.find(i => i.id === itemId);

                if (!item) {
                    alert('Item not found');
                    return;
                }

                // Send delete request to server
                fetch('api/update_inventory.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'delete',
                        itemId: itemId,
                        category: item.category
                    })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert('Equipment deleted successfully!');
                        window.location.href = window.location.href;
                    } else {
                        alert('Error deleting equipment: ' + (result.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error deleting equipment. Please try again.');
                });
            }
        }
    </script>
</body>
</html>
