<?php
require_once 'FileStorage.php';

function getSystemStatistics() {
    global $fileStorage;
    return $fileStorage->getStatistics();
}

function getAllDefectReports() {
    global $fileStorage;
    return $fileStorage->getAllDefectReports();
}

function getAllReservations() {
    global $fileStorage;
    return $fileStorage->getAllReservations();
}







function getReservationById($reservation_id) {
    global $fileStorage;
    return $fileStorage->getReservationById($reservation_id);
}

function updateReservation($reservation_id, $data) {
    global $fileStorage;
    return $fileStorage->updateReservation($reservation_id, $data);
}

// ============================================
// ANALYTICS FUNCTIONS
// ============================================

function getReservationsOverTime($days = 30) {
    global $fileStorage;
    $reservations = $fileStorage->getAllReservations();
    $data = [];

    // Initialize last 30 days
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $data[$date] = 0;
    }

    // Count reservations per day
    foreach ($reservations as $reservation) {
        $date = date('Y-m-d', strtotime($reservation['request_date']));
        if (isset($data[$date])) {
            $data[$date]++;
        }
    }

    return array_map(function($date, $count) {
        return ['date' => $date, 'count' => $count];
    }, array_keys($data), $data);
}

function getDefectsOverTime($days = 30) {
    global $fileStorage;
    $defects = $fileStorage->getAllDefectReports();
    $data = [];

    // Initialize last 30 days
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $data[$date] = 0;
    }

    // Count defects per day
    foreach ($defects as $defect) {
        $date = date('Y-m-d', strtotime($defect['report_date']));
        if (isset($data[$date])) {
            $data[$date]++;
        }
    }

    return array_map(function($date, $count) {
        return ['date' => $date, 'count' => $count];
    }, array_keys($data), $data);
}

function getEquipmentUsageOverTime($days = 30) {
    global $fileStorage;
    $reservations = $fileStorage->getAllReservations();
    $data = [];

    // Initialize last 30 days
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $data[$date] = 0;
    }

    // Count active reservations per day
    foreach ($reservations as $reservation) {
        if ($reservation['status'] === 'approved' || $reservation['status'] === 'active') {
            $start = strtotime($reservation['start_date']);
            $end = strtotime($reservation['end_date']);
            $current = time();

            // Only count if reservation is/was active in our timeframe
            if ($end >= strtotime("-$days days")) {
                for ($i = 0; $i < $days; $i++) {
                    $checkDate = strtotime("-$i days");
                    if ($checkDate >= $start && $checkDate <= $end) {
                        $dateKey = date('Y-m-d', $checkDate);
                        if (isset($data[$dateKey])) {
                            $data[$dateKey]++;
                        }
                    }
                }
            }
        }
    }

    return array_map(function($date, $count) {
        return ['date' => $date, 'count' => $count];
    }, array_keys($data), $data);
}

function getEquipmentStatusDistribution() {
    global $fileStorage;
    $equipment = $fileStorage->getAllEquipment();
    $distribution = [
        'available' => 0,
        'in-use' => 0,
        'maintenance' => 0,
        'defective' => 0
    ];

    foreach ($equipment as $item) {
        $status = $item['status'] ?? 'available';
        if (isset($distribution[$status])) {
            $distribution[$status]++;
        }
    }

    return $distribution;
}

function getCategoryUsageStats() {
    global $fileStorage;
    $equipment = $fileStorage->getAllEquipment();
    $reservations = $fileStorage->getAllReservations();
    $categories = $fileStorage->getAllCategories();

    $stats = [];
    foreach ($categories as $category) {
        $stats[$category['category_id']] = [
            'name' => $category['name'],
            'total_equipment' => 0,
            'reservations' => 0
        ];
    }

    // Add inventory categories if not already present
    $inventoryCategories = [
        'CAT-AC' => 'Air Conditioners',
        'CAT-TV' => 'Televisions',
        'CAT-FAN' => 'Fans',
        'CAT-WB' => 'Whiteboards',
        'CAT-LOCK' => 'Lockers',
        'CAT-CHAIR' => 'Office Chairs',
        'CAT-PROJ' => 'Projectors',
        'CAT-LAP' => 'Laptops',
        'CAT-TAB' => 'Tablets',
        'CAT-MIC' => 'Microphones',
        'CAT-SPK' => 'Speakers',
        'CAT-CAM' => 'Cameras'
    ];

    foreach ($inventoryCategories as $catId => $catName) {
        if (!isset($stats[$catId])) {
            $stats[$catId] = [
                'name' => $catName,
                'total_equipment' => 0,
                'reservations' => 0
            ];
        }
    }

    // Count equipment per category
    foreach ($equipment as $item) {
        $catId = $item['category_id'] ?? 'CAT-001';
        if (isset($stats[$catId])) {
            $stats[$catId]['total_equipment']++;
        }
    }

    // Count reservations per category
    foreach ($reservations as $reservation) {
        $catId = $reservation['category_id'] ?? 'CAT-001';
        if (isset($stats[$catId])) {
            $stats[$catId]['reservations']++;
        }
    }

    return array_values($stats);
}

function getMonthlyTrends($months = 12) {
    global $fileStorage;
    $reservations = $fileStorage->getAllReservations();
    $defects = $fileStorage->getAllDefectReports();

    $data = [];

    // Initialize last 12 months
    for ($i = $months - 1; $i >= 0; $i--) {
        $month = date('Y-m', strtotime("-$i months"));
        $data[$month] = [
            'month' => date('M Y', strtotime("-$i months")),
            'reservations' => 0,
            'defects' => 0
        ];
    }

    // Count reservations per month
    foreach ($reservations as $reservation) {
        $month = date('Y-m', strtotime($reservation['request_date']));
        if (isset($data[$month])) {
            $data[$month]['reservations']++;
        }
    }

    // Count defects per month
    foreach ($defects as $defect) {
        $month = date('Y-m', strtotime($defect['report_date']));
        if (isset($data[$month])) {
            $data[$month]['defects']++;
        }
    }

    return array_values($data);
}

// ============================================
// INVENTORY ANALYTICS FUNCTIONS
// ============================================

function getInventoryStatistics() {
    global $fileStorage;

    // Get all equipment from FileStorage
    $equipment = $fileStorage->getAllEquipment();

    $stats = [
        'totalItems' => count($equipment),
        'totalAirConditioners' => 0, // Will be calculated from categories if needed
        'totalTelevisions' => 0,
        'totalFans' => 0,
        'totalWhiteboards' => 0,
        'totalLockers' => 0,
        'totalOfficeChairs' => 0,
        'mainCampusItems' => 0,
        'annex1Items' => 0,
        'annex2Items' => 0,
        'activeItems' => 0,
        'notWorkingItems' => 0,
        'newItems' => 0,
        'oldItems' => 0,
        'availableItems' => 0,
        'inUseItems' => 0,
        'maintenanceItems' => 0,
        'defectiveItems' => 0
    ];

    // Calculate statistics based on actual equipment data
    foreach ($equipment as $item) {
        $status = $item['status'] ?? 'available';
        $campus = $item['campus'] ?? '';
        $category = $item['category_name'] ?? '';

        // Count by status
        switch (strtolower($status)) {
            case 'available':
                $stats['availableItems']++;
                break;
            case 'in-use':
            case 'in use':
                $stats['inUseItems']++;
                break;
            case 'maintenance':
                $stats['maintenanceItems']++;
                break;
            case 'defective':
                $stats['defectiveItems']++;
                break;
            case 'active':
                $stats['activeItems']++;
                break;
            case 'not working':
            case 'broken':
            case 'damaged':
                $stats['notWorkingItems']++;
                break;
            case 'new':
                $stats['newItems']++;
                break;
            case 'old':
                $stats['oldItems']++;
                break;
        }

        // Count by campus (if campus info exists in equipment data)
        switch ($campus) {
            case 'Main Campus':
                $stats['mainCampusItems']++;
                break;
            case 'Annex 1 Campus':
                $stats['annex1Items']++;
                break;
            case 'Annex 2 Campus':
                $stats['annex2Items']++;
                break;
        }

        // Count by category (basic mapping)
        switch (strtolower($category)) {
            case 'air conditioners':
            case 'air conditioner':
                $stats['totalAirConditioners']++;
                break;
            case 'televisions':
            case 'television':
            case 'tv':
                $stats['totalTelevisions']++;
                break;
            case 'fans':
            case 'fan':
                $stats['totalFans']++;
                break;
            case 'whiteboards':
            case 'whiteboard':
            case 'furniture':
                $stats['totalWhiteboards']++;
                break;
            case 'lockers':
            case 'locker':
                $stats['totalLockers']++;
                break;
            case 'office chairs':
            case 'office chair':
            case 'chairs':
                $stats['totalOfficeChairs']++;
                break;
        }
    }

    // Add additional stats for analytics compatibility
    $stats['total_equipment'] = $stats['totalItems'];
    $stats['available_equipment'] = $stats['availableItems'] + $stats['activeItems'] + $stats['newItems'] + $stats['oldItems'];
    $stats['total_reports'] = count(getAllDefectReports());
    $stats['total_reservations'] = count(getAllReservations());

    return $stats;
}

function getLegacyInventoryByCategory() {
    require_once 'admin_inventory.php';

    global $ACData, $TVData, $FanData, $WhiteboardData, $LockerData, $OfficeChairData;

    // Ensure variables are arrays to prevent count() errors
    $ACData = is_array($ACData) ? $ACData : [];
    $TVData = is_array($TVData) ? $TVData : [];
    $FanData = is_array($FanData) ? $FanData : [];
    $WhiteboardData = is_array($WhiteboardData) ? $WhiteboardData : [];
    $LockerData = is_array($LockerData) ? $LockerData : [];
    $OfficeChairData = is_array($OfficeChairData) ? $OfficeChairData : [];

    return [
        ['name' => 'Air Conditioners', 'count' => count($ACData), 'color' => '#FF6384'],
        ['name' => 'Televisions', 'count' => count($TVData), 'color' => '#36A2EB'],
        ['name' => 'Fans', 'count' => count($FanData), 'color' => '#FFCE56'],
        ['name' => 'Whiteboards', 'count' => count($WhiteboardData), 'color' => '#4BC0C0'],
        ['name' => 'Lockers', 'count' => count($LockerData), 'color' => '#9966FF'],
        ['name' => 'Office Chairs', 'count' => count($OfficeChairData), 'color' => '#FF9F40']
    ];
}

function getInventoryByCampus() {
    require_once 'admin_inventory.php';

    global $ACData, $TVData, $FanData, $WhiteboardData, $LockerData, $OfficeChairData;

    // Ensure variables are arrays to prevent count() errors
    $ACData = is_array($ACData) ? $ACData : [];
    $TVData = is_array($TVData) ? $TVData : [];
    $FanData = is_array($FanData) ? $FanData : [];
    $WhiteboardData = is_array($WhiteboardData) ? $WhiteboardData : [];
    $LockerData = is_array($LockerData) ? $LockerData : [];
    $OfficeChairData = is_array($OfficeChairData) ? $OfficeChairData : [];

    $campusStats = [
        'Main Campus' => 0,
        'Annex 1 Campus' => 0,
        'Annex 2 Campus' => 0
    ];

    $allItems = array_merge($ACData, $TVData, $FanData, $WhiteboardData, $LockerData, $OfficeChairData);
    foreach ($allItems as $item) {
        if (isset($campusStats[$item['campus']])) {
            $campusStats[$item['campus']]++;
        }
    }

    return array_map(function($campus, $count) {
        return ['campus' => $campus, 'count' => $count];
    }, array_keys($campusStats), $campusStats);
}

function getInventoryStatusDistribution() {
    require_once 'admin_inventory.php';

    global $ACData, $TVData, $FanData, $WhiteboardData, $LockerData, $OfficeChairData;

    // Ensure variables are arrays to prevent count() errors
    $ACData = is_array($ACData) ? $ACData : [];
    $TVData = is_array($TVData) ? $TVData : [];
    $FanData = is_array($FanData) ? $FanData : [];
    $WhiteboardData = is_array($WhiteboardData) ? $WhiteboardData : [];
    $LockerData = is_array($LockerData) ? $LockerData : [];
    $OfficeChairData = is_array($OfficeChairData) ? $OfficeChairData : [];

    $statusStats = [
        'Active' => 0,
        'New' => 0,
        'Old' => 0,
        'Not Working' => 0,
        'Broken' => 0,
        'Damaged' => 0
    ];

    $allItems = array_merge($ACData, $TVData, $FanData, $WhiteboardData, $LockerData, $OfficeChairData);
    foreach ($allItems as $item) {
        if (isset($statusStats[$item['status']])) {
            $statusStats[$item['status']]++;
        }
    }

    return array_map(function($status, $count) {
        return ['status' => $status, 'count' => $count];
    }, array_keys($statusStats), $statusStats);
}

function getInventoryByBuilding() {
    require_once 'admin_inventory.php';

    global $ACData, $TVData, $FanData, $WhiteboardData, $LockerData, $OfficeChairData;

    // Ensure variables are arrays to prevent count() errors
    $ACData = is_array($ACData) ? $ACData : [];
    $TVData = is_array($TVData) ? $TVData : [];
    $FanData = is_array($FanData) ? $FanData : [];
    $WhiteboardData = is_array($WhiteboardData) ? $WhiteboardData : [];
    $LockerData = is_array($LockerData) ? $LockerData : [];
    $OfficeChairData = is_array($OfficeChairData) ? $OfficeChairData : [];

    $buildingStats = [];

    $allItems = array_merge($ACData, $TVData, $FanData, $WhiteboardData, $LockerData, $OfficeChairData);
    foreach ($allItems as $item) {
        $building = $item['building'];
        if (!isset($buildingStats[$building])) {
            $buildingStats[$building] = 0;
        }
        $buildingStats[$building]++;
    }

    arsort($buildingStats); // Sort by count descending

    return array_map(function($building, $count) {
        return ['building' => $building, 'count' => $count];
    }, array_keys($buildingStats), $buildingStats);
}
