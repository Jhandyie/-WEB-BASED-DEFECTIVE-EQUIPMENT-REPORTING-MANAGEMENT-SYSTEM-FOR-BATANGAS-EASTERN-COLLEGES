<?php
/**
 * scripts/import_inventory_2026.php
 *
 * Replaces the equipment table with the June 2026 PMO inventory and regenerates
 * the location/category reference the report forms read from.
 *
 *   php scripts/parse_inventory_workbook.php "<workbook.xlsx>"   # produces the JSON
 *   php scripts/import_inventory_2026.php --dry-run              # show what would change
 *   php scripts/import_inventory_2026.php --commit               # apply it
 *
 * Existing equipment rows are *soft*-deleted (status='deleted', the app's own
 * convention — see the delete handler in inventory_functions.php), because every
 * one of them is referenced by a defect report and defect_reports.equipment_id is
 * NOT NULL. They disappear from every inventory view; the reports keep working.
 */

require_once __DIR__ . '/../config/database.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$commit  = in_array('--commit', $argv, true);
$dataPath = __DIR__ . '/../data/bec_inventory_2026.json';

$payload = json_decode((string)@file_get_contents($dataPath), true);
if (!$payload || empty($payload['items'])) {
    fwrite(STDERR, "No parsed inventory at {$dataPath}. Run scripts/parse_inventory_workbook.php first.\n");
    exit(1);
}
$items = $payload['items'];
$tree  = $payload['tree'];

/* ---------- helpers ---------------------------------------------------- */

/** "Aug 2025" / "Jun 2026" -> "2025-08-01"; anything unparseable -> null. */
function invMonthToDate(string $v): ?string {
    $v = trim($v);
    if ($v === '') return null;
    $ts = strtotime('01 ' . $v);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** The workbook flags broken assets in its Remarks/Status columns. */
function invIsFaulty(string ...$notes): bool {
    $blob = strtolower(implode(' ', $notes));
    return (bool)preg_match('/not working|disposition|missing parts|defective|broken|damaged|busted/i', $blob);
}

/* ---------- build the rows -------------------------------------------- */

$rows      = [];
$seenIds   = [];
$tooLong   = [];
foreach ($items as $it) {
    $assetId = $it['asset_id'];
    if (isset($seenIds[$assetId])) { fwrite(STDERR, "! duplicate asset id {$assetId}\n"); continue; }
    $seenIds[$assetId] = true;

    $article = trim((string)$it['article']);
    if (strcasecmp($article, 'NONE') === 0) $article = '';
    $isBrand = (bool)preg_match('/brand|article/i', (string)$it['article_label']);

    $brand = ($isBrand && $article !== '') ? $article : null;
    $name  = $it['category'];
    if (!$isBrand && $article !== '') {
        // The "Type" column is a full name on some sheets ("Ceiling Fan") and only
        // a qualifier on others ("Wooden", "Executive"). Keep it as the name when it
        // already says what the thing is; otherwise put it in front of the category.
        $catWords = preg_split('/\W+/', strtolower($it['category']), -1, PREG_SPLIT_NO_EMPTY);
        $artWords = preg_split('/\W+/', strtolower($article), -1, PREG_SPLIT_NO_EMPTY);
        $name = array_intersect($catWords, $artWords) ? $article : $article . ' ' . $it['category'];
    }

    // Everything the sheet said about this asset that has no column of its own.
    $bits = [];
    foreach ([
        'Size'           => $it['size'],
        'Colour'         => $it['color'],
        'Classification' => $it['classification'],
    ] as $label => $v) {
        if (trim((string)$v) !== '') $bits[] = $label . ': ' . trim((string)$v);
    }
    foreach ([$it['remarks'], $it['status_note']] as $v) {
        $v = trim((string)$v);
        if ($v !== '' && !in_array($v, $bits, true)) $bits[] = $v;
    }
    if (!empty($it['floor']))        $bits[] = 'Floor: ' . $it['floor'];
    if (trim((string)$it['counted']) !== '') $bits[] = 'Counted ' . trim((string)$it['counted']);
    if ($it['property_no'] === '')   $bits[] = 'Bulk-counted — no property tag in the 2026 workbook';
    if (!empty($it['tag_conflict'])) $bits[] = 'Workbook reuses property no. ' . $it['property_no'] . ' at another location';
    $remarks = implode(' • ', $bits);

    $faulty = invIsFaulty((string)$it['remarks'], (string)$it['status_note']);

    $location = $it['location'];
    if (mb_strlen($location) > 200) { $tooLong[] = $assetId; $location = mb_substr($location, 0, 200); }

    $rows[] = [
        'equipment_id'   => $assetId,
        'asset_tag'      => $assetId,
        'equipment_name' => mb_substr($name, 0, 200),
        'category'       => $it['category'],
        'location'       => $location,
        'status'         => $faulty ? 'faulty' : 'operational',
        'condition'      => $faulty ? 'poor' : 'good',
        'quantity'       => max(1, (int)$it['qty']),
        'brand'          => $brand === null ? null : mb_substr($brand, 0, 120),
        'serial'         => trim((string)$it['serial']) !== '' ? mb_substr(trim((string)$it['serial']), 0, 120) : null,
        'acquired'       => invMonthToDate((string)$it['acquired']),
        'issued'         => invMonthToDate((string)$it['issued']),
        'counted_at'     => invMonthToDate((string)$it['counted']),
        'remarks'        => $remarks,
        'unit'           => function_exists('classifyDepartmentByEquipment')
                            ? classifyDepartmentByEquipment('', $name, $it['category'], $location)
                            : 'PMO',
    ];
}

/* ---------- report ----------------------------------------------------- */

$conn = getDBConnection();
$existing = (int)$conn->query("SELECT COUNT(*) c FROM equipment WHERE status != 'deleted'")->fetch_assoc()['c'];

$byCat = $byUnit = $byStatus = [];
foreach ($rows as $r) {
    $byCat[$r['category']] = ($byCat[$r['category']] ?? 0) + 1;
    $byUnit[$r['unit']]    = ($byUnit[$r['unit']] ?? 0) + 1;
    $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
}
ksort($byCat);

echo ($commit ? "== IMPORT ==" : "== DRY RUN (pass --commit to apply) ==") . "\n\n";
echo "Currently in equipment (not deleted): {$existing}\n";
echo "Rows to import: " . count($rows) . " ("
   . array_sum(array_map(static fn($r) => $r['quantity'], $rows)) . " units)\n\n";
foreach ($byCat as $c => $n) printf("  %-26s %5d\n", $c, $n);
echo "\n  by unit:   "; foreach ($byUnit as $u => $n) echo "{$u}={$n} ";
echo "\n  by status: "; foreach ($byStatus as $s => $n) echo "{$s}={$n} ";
echo "\n";
if ($tooLong) echo "\n! " . count($tooLong) . " location(s) truncated to 200 chars\n";

if (!$commit) { echo "\nNothing written.\n"; exit(0); }

/* ---------- apply ------------------------------------------------------ */

// 1. Retire everything currently in the table. Rows with no defect report, no
//    maintenance history and no reservation can go for real; the rest are soft
//    deleted so their reports keep resolving.
$conn->query("
    DELETE FROM equipment
     WHERE equipment_id NOT IN (SELECT equipment_id FROM defect_reports WHERE equipment_id IS NOT NULL)
       AND equipment_id NOT IN (SELECT equipment_id FROM maintenance_history WHERE equipment_id IS NOT NULL)
       AND equipment_id NOT IN (SELECT equipment_id FROM reservations WHERE equipment_id IS NOT NULL)
");
$hardDeleted = $conn->affected_rows;

$conn->query("
    UPDATE equipment
       SET status  = 'deleted',
           remarks = 'Pre-2026 record. Kept only because a defect report references it; not part of the June 2026 PMO inventory.',
           updated_at = NOW()
     WHERE status != 'deleted'
");
$softDeleted = $conn->affected_rows;

// 2. Insert the 2026 inventory.
$sql = "INSERT INTO equipment
        (equipment_id, asset_tag, equipment_name, category, equipment_category, location,
         status, condition_status, \"condition\", quantity, brand, model, unit,
         acquired, issued, last_inventory_check, remarks, notes, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";
$stmt = $conn->prepare($sql);
if (!$stmt) { fwrite(STDERR, "Prepare failed\n"); exit(1); }

$inserted = 0;
$failed   = [];
foreach ($rows as $r) {
    $ok = $stmt->bind_param(
        'ssssssssssssssssss',
        $r['equipment_id'], $r['asset_tag'], $r['equipment_name'], $r['category'], $r['category'],
        $r['location'], $r['status'], $r['condition'], $r['condition'], $r['quantity'],
        $r['brand'], $r['serial'], $r['unit'],
        $r['acquired'], $r['issued'], $r['counted_at'], $r['remarks'], $r['remarks']
    ) && $stmt->execute();
    if ($ok) { $inserted++; } else { $failed[] = $r['equipment_id'] . ': ' . $conn->error; }
}

echo "\nHard-deleted (unreferenced): {$hardDeleted}\n";
echo "Soft-deleted (referenced by a report): {$softDeleted}\n";
echo "Inserted: {$inserted}\n";
if ($failed) {
    echo "! " . count($failed) . " insert(s) failed:\n";
    foreach (array_slice($failed, 0, 10) as $f) echo "   {$f}\n";
}

$after = (int)$conn->query("SELECT COUNT(*) c FROM equipment WHERE status != 'deleted'")->fetch_assoc()['c'];
echo "Live equipment rows now: {$after}\n";

/* ---------- 3. regenerate the pickers the report forms read ------------ */

$categories = array_values(array_unique(array_column($rows, 'category')));
sort($categories);
// Faults get reported against things the PMO does not tag individually, so the
// picker keeps the general trades and an escape hatch alongside the asset classes.
$categories = array_merge($categories, [
    'Computer', 'Printer', 'Projector', 'Network Equipment',
    'Lighting / Electrical', 'Plumbing / Sanitary', 'Other / Not sure',
]);

$php  = "<?php\n";
$php .= "/**\n";
$php .= " * bec_inventory_reference.php\n";
$php .= " * Canonical BEC equipment categories and campus/building/room locations.\n";
$php .= " *\n";
$php .= " * GENERATED — do not edit by hand.\n";
$php .= " * Source: " . ($payload['_meta']['source'] ?? 'PMO inventory workbook') . "\n";
$php .= " * Regenerate: php scripts/parse_inventory_workbook.php \"<workbook.xlsx>\"\n";
$php .= " *             php scripts/import_inventory_2026.php --commit\n";
$php .= " *\n";
$php .= " * Feeds the defect-report form's Category and Location pickers.\n";
$php .= " */\n\n";

$php .= "if (!function_exists('becCategories')) {\n";
$php .= "    /** Equipment categories present in the BEC PMO inventory (+ common report types). */\n";
$php .= "    function becCategories(): array {\n        return [\n";
foreach ($categories as $c) $php .= "            " . var_export($c, true) . ",\n";
$php .= "        ];\n    }\n}\n\n";

$php .= "if (!function_exists('becLocationTree')) {\n";
$php .= "    /** Campus => Building label => [rooms/areas]. Mirrors the PMO inventory. */\n";
$php .= "    function becLocationTree(): array {\n        return [\n";
foreach ($tree as $campus => $buildings) {
    $php .= "            " . var_export($campus, true) . " => [\n";
    foreach ($buildings as $b => $roomList) {
        $php .= "                " . var_export($b, true) . " => [\n";
        foreach ($roomList as $r) $php .= "                    " . var_export($r, true) . ",\n";
        $php .= "                ],\n";
    }
    $php .= "            ],\n";
}
$php .= "        ];\n    }\n}\n\n";

$php .= <<<'TAIL'
if (!function_exists('becLocations')) {
    /** Flat, human-readable list of every room/area: "Campus • Building • Room". */
    function becLocations(): array {
        $out = [];
        foreach (becLocationTree() as $campus => $buildings) {
            foreach ($buildings as $building => $rooms) {
                foreach ($rooms as $room) {
                    $out[] = $campus . ' • ' . $building . ' • ' . $room;
                }
            }
        }
        return $out;
    }
}

if (!function_exists('becCampuses')) {
    function becCampuses(): array {
        return array_keys(becLocationTree());
    }
}

TAIL;

file_put_contents(__DIR__ . '/../data/bec_inventory_reference.php', $php);
echo "Wrote data/bec_inventory_reference.php ("
   . count($tree) . " campuses, "
   . array_sum(array_map('count', $tree)) . " buildings, "
   . array_sum(array_map(static fn($b) => array_sum(array_map('count', $b)), $tree)) . " rooms)\n";

/* ---------- 4. the dashboard's category summary ------------------------ */

$keyFor = [
    'Air Conditioner'         => 'airConditioners',
    'Television'              => 'televisions',
    'Electric Fan'            => 'fans',
    'Whiteboard / Glassboard' => 'whiteboards',
    'Locker'                  => 'lockers',
    'Cabinet'                 => 'filingCabinets',
    'Office Table'            => 'tables',
    'Office Chair'            => 'officeChairs',
    'Copier / Duplicator'     => 'copyPrinters',
    'Food Warmer'             => 'foodWarmers',
    'Shredder'                => 'shredders',
    'Piano'                   => 'pianos',
];
$summary = [];
foreach ($rows as $r) {
    $key = $keyFor[$r['category']] ?? 'other';
    if (!isset($summary[$key])) {
        $summary[$key] = [[
            'id' => 1, 'propertyNo' => strtoupper(substr($key, 0, 3)) . '-2026-0001',
            'campus' => 'All Campuses', 'building' => 'Multiple', 'buildingName' => 'PMO Inventory 2026',
            'room' => 'Multiple Rooms', 'type' => $r['category'], 'article' => $r['category'],
            'qty' => 0, 'status' => 'Active', 'department' => $r['unit'],
            'remarks' => 'From the PMO inventory dated June 14, 2026',
        ]];
    }
    $summary[$key][0]['qty'] += $r['quantity'];
}
$summary['_meta'] = [
    'uploaded_at' => date('c'),
    'filename'    => $payload['_meta']['source'] ?? 'NEW Inventory 2026.xlsx',
    'total'       => array_sum(array_map(static fn($r) => $r['quantity'], $rows)),
];
file_put_contents(__DIR__ . '/../api/data/inventory.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Wrote api/data/inventory.json\n";
