<?php
/**
 * Enhanced Inventory Summary Report
 * Shows comprehensive inventory statistics using quantity data
 */

require_once 'config/database.php';

// Get inventory statistics
$stats = getInventoryStats();
$lowStock = getLowStockAlerts();
$reorder = getReorderSuggestions();
$byCategory = getInventoryByCategory();

echo '=== EQUIPMENT INVENTORY SUMMARY ===' . PHP_EOL . PHP_EOL;

// Overall Statistics
echo '📊 OVERALL STATISTICS:' . PHP_EOL;
echo 'Total Equipment Items: ' . number_format($stats['total_items']) . PHP_EOL;
echo 'Total Quantity in Stock: ' . number_format($stats['total_quantity']) . PHP_EOL;
echo 'Total Inventory Value: $' . number_format($stats['total_value'], 2) . PHP_EOL . PHP_EOL;

// Stock Status Summary
echo '📦 STOCK STATUS SUMMARY:' . PHP_EOL;
echo 'Low Stock Items (≤ min level): ' . $stats['low_stock_items'] . PHP_EOL;
echo 'Out of Stock Items: ' . $stats['out_of_stock_items'] . PHP_EOL;
echo 'Items Needing Reorder (≤ reorder point): ' . $stats['reorder_items'] . PHP_EOL . PHP_EOL;

// Low Stock Alerts
if (!empty($lowStock)) {
    echo '⚠️  LOW STOCK ALERTS:' . PHP_EOL;
    foreach ($lowStock as $item) {
        $status = $item['quantity'] == 0 ? 'OUT OF STOCK' : 'LOW STOCK';
        echo "- {$item['equipment_name']} ({$item['equipment_id']}): {$item['quantity']} remaining (min: {$item['min_stock_level']}) [{$status}]" . PHP_EOL;
    }
    echo PHP_EOL;
} else {
    echo '✅ No low stock alerts at this time.' . PHP_EOL . PHP_EOL;
}

// Reorder Suggestions
if (!empty($reorder)) {
    echo '📋 REORDER SUGGESTIONS:' . PHP_EOL;
    foreach ($reorder as $item) {
        echo "- {$item['equipment_name']} ({$item['equipment_id']}): {$item['quantity']} remaining (reorder at: {$item['reorder_point']})" . PHP_EOL;
    }
    echo PHP_EOL;
} else {
    echo '✅ No reorder suggestions at this time.' . PHP_EOL . PHP_EOL;
}

// Inventory by Category
echo '📂 INVENTORY BY CATEGORY:' . PHP_EOL;
foreach ($byCategory as $cat) {
    $lowStockPercent = $cat['total_items'] > 0 ? round(($cat['low_stock_count'] / $cat['total_items']) * 100, 1) : 0;
    echo "- {$cat['category_name']}: {$cat['total_quantity']} items total, {$cat['low_stock_count']} low stock ({$lowStockPercent}%)" . PHP_EOL;
}
echo PHP_EOL;

// Equipment Status Distribution
$equipment = getEquipmentWithInventory();
$statusCounts = ['normal' => 0, 'low_stock' => 0, 'reorder' => 0, 'out_of_stock' => 0];
foreach ($equipment as $item) {
    $statusCounts[$item['inventory_status']]++;
}

echo '📈 INVENTORY STATUS DISTRIBUTION:' . PHP_EOL;
echo "- Normal Stock: {$statusCounts['normal']} items" . PHP_EOL;
echo "- Low Stock: {$statusCounts['low_stock']} items" . PHP_EOL;
echo "- Needs Reorder: {$statusCounts['reorder']} items" . PHP_EOL;
echo "- Out of Stock: {$statusCounts['out_of_stock']} items" . PHP_EOL . PHP_EOL;

echo 'Report generated on: ' . date('Y-m-d H:i:s') . PHP_EOL;
?>
