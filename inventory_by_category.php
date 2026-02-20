<?php
/**
 * Enhanced Inventory by Category Report
 * Shows inventory status breakdown by category
 */

require_once 'config/database.php';

// Get inventory data by category
$byCategory = getInventoryByCategory();
$equipment = getEquipmentWithInventory();

echo '=== INVENTORY BY CATEGORY ===' . PHP_EOL . PHP_EOL;

// Category Summary with Inventory Status
echo '📂 CATEGORY INVENTORY SUMMARY:' . PHP_EOL;
foreach ($byCategory as $cat) {
    $lowStockPercent = $cat['total_items'] > 0 ? round(($cat['low_stock_count'] / $cat['total_items']) * 100, 1) : 0;
    $reorderPercent = $cat['total_items'] > 0 ? round(($cat['reorder_count'] / $cat['total_items']) * 100, 1) : 0;

    echo "- {$cat['category_name']}:" . PHP_EOL;
    echo "  Total Items: {$cat['total_items']}" . PHP_EOL;
    echo "  Total Quantity: {$cat['total_quantity']}" . PHP_EOL;
    echo "  Low Stock Items: {$cat['low_stock_count']} ({$lowStockPercent}%)" . PHP_EOL;
    echo "  Reorder Items: {$cat['reorder_count']} ({$reorderPercent}%)" . PHP_EOL;
    echo PHP_EOL;
}

// Detailed Equipment List by Category
echo '📋 DETAILED EQUIPMENT LIST BY CATEGORY:' . PHP_EOL . PHP_EOL;

// Group equipment by category
$equipmentByCategory = [];
foreach ($equipment as $item) {
    $category = $item['category_name'] ?: 'Uncategorized';
    if (!isset($equipmentByCategory[$category])) {
        $equipmentByCategory[$category] = [];
    }
    $equipmentByCategory[$category][] = $item;
}

// Sort categories alphabetically
ksort($equipmentByCategory);

foreach ($equipmentByCategory as $category => $items) {
    echo "🔸 {$category} ({count($items)} items):" . PHP_EOL;

    // Sort items by inventory status (critical first)
    usort($items, function($a, $b) {
        $statusOrder = ['out_of_stock' => 0, 'reorder' => 1, 'low_stock' => 2, 'normal' => 3];
        $aOrder = $statusOrder[$a['inventory_status']] ?? 3;
        $bOrder = $statusOrder[$b['inventory_status']] ?? 3;
        if ($aOrder !== $bOrder) return $aOrder - $bOrder;
        return strcmp($a['equipment_name'], $b['equipment_name']);
    });

    foreach ($items as $item) {
        $statusIcon = '';
        switch ($item['inventory_status']) {
            case 'out_of_stock': $statusIcon = '❌'; break;
            case 'reorder': $statusIcon = '🔄'; break;
            case 'low_stock': $statusIcon = '⚠️'; break;
            default: $statusIcon = '✅'; break;
        }

        $statusText = ucwords(str_replace('_', ' ', $item['inventory_status']));
        echo "  {$statusIcon} {$item['equipment_name']} (ID: {$item['equipment_id']})" . PHP_EOL;
        echo "     Quantity: {$item['quantity']} | Min: {$item['min_stock_level']} | Reorder: {$item['reorder_point']} | Status: {$statusText}" . PHP_EOL;
        echo PHP_EOL;
    }
    echo PHP_EOL;
}

// Category Health Summary
echo '🏥 CATEGORY HEALTH SUMMARY:' . PHP_EOL;
$healthyCategories = 0;
$warningCategories = 0;
$criticalCategories = 0;

foreach ($byCategory as $cat) {
    $lowStockPercent = $cat['total_items'] > 0 ? ($cat['low_stock_count'] / $cat['total_items']) * 100 : 0;

    if ($lowStockPercent == 0) {
        $healthyCategories++;
    } elseif ($lowStockPercent <= 25) {
        $warningCategories++;
    } else {
        $criticalCategories++;
    }
}

echo "- Healthy Categories (0% low stock): {$healthyCategories}" . PHP_EOL;
echo "- Warning Categories (1-25% low stock): {$warningCategories}" . PHP_EOL;
echo "- Critical Categories (>25% low stock): {$criticalCategories}" . PHP_EOL . PHP_EOL;

echo 'Report generated on: ' . date('Y-m-d H:i:s') . PHP_EOL;
?>
