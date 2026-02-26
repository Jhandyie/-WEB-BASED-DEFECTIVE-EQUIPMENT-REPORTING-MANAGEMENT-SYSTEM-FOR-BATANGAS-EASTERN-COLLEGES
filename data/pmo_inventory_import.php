<?php
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Run from CLI only.\n"); exit(1); }
require_once __DIR__ . '/../config/database.php';

$input = $argv[1] ?? '';
$dryRun = in_array('--dry-run', $argv, true);
if ($input === '' || !is_file($input)) { fwrite(STDERR, "Usage: php data/pmo_inventory_import.php <file> [--dry-run]\n"); exit(1); }

$conn = getDBConnection();
$eqCols = [];
$res = $conn->query("SHOW COLUMNS FROM equipment");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $eqCols[strtolower((string)$r['Field'])] = true;
    }
}
foreach (['acquired', 'issued', 'counted', 'remarks'] as $c) {
    if (!isset($eqCols[$c])) {
        $conn->query("ALTER TABLE equipment ADD COLUMN `{$c}` VARCHAR(255) NULL");
    }
}

$catMap = [
    'AIRCON' => 'Air Conditioner',
    'BOARDS' => 'Boards',
    'CABINETS' => 'Cabinets',
    'CHAIRS' => 'Chairs',
    'COPY PRINTER' => 'Copy Printer',
    'FANS' => 'Fans',
    'FOOD WARMER' => 'Food Warmer',
    'LOCKER' => 'Locker',
    'PIANO' => 'Piano',
    'TV' => 'Television',
    'TABLES' => 'Tables',
    'COMPUTERS' => 'Computers',
];

$prefixCatMap = [
    'A' => 'Air Conditioner',
    'WB' => 'Boards',
    'CA' => 'Cabinets',
    'OC' => 'Chairs',
    'CP' => 'Copy Printer',
    'F' => 'Fans',
    'FW' => 'Food Warmer',
    'L' => 'Locker',
    'P' => 'Piano',
    'T' => 'Television',
    'OT' => 'Tables',
];

function isDateish(string $v): bool {
    $v = trim($v);
    if ($v === '') return false;
    if (preg_match('/^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*\s+\d{4}$/i', $v)) return true;
    if (preg_match('/^\d{4}-\d{2}-\d{2}(?:\s+\d{2}:\d{1,2})?$/', $v)) return true;
    return false;
}

$lines = file($input, FILE_IGNORE_NEW_LINES);
$current = '';
$rows = [];
$skipped = 0;

foreach ($lines as $line) {
    $line = rtrim((string)$line);
    if (trim($line) === '') continue;

    if (preg_match('/^([A-Z][A-Z\s]+)\s*-\s*\d+\s+ITEMS\s*$/', trim($line), $m)) {
        $h = trim($m[1]);
        if (isset($catMap[$h])) $current = $catMap[$h];
        continue;
    }

    if ($current === '') continue;

    if (!preg_match('/^\s*(\d+)\s+(.*?)\s+([A-Z]{1,3}-\d{4}-\d{4})\s*(.*)$/', $line, $m)) {
        $skipped++;
        continue;
    }

    $location = trim((string)$m[2]);
    $location = ltrim($location, '/ ');
    $asset = trim((string)$m[3]);
    $tail = trim((string)$m[4]);

    $tokens = [];
    if ($tail !== '') {
        $tokens = preg_split('/\s{2,}/', $tail);
        $tokens = array_values(array_filter(array_map('trim', $tokens), fn($x) => $x !== ''));
    }

    $type = '';
    $color = '';
    $counted = '';
    $remarks = '';

    if (!empty($tokens)) {
        $type = $tokens[0] ?? '';
        $color = $tokens[1] ?? '';
        $d = -1;
        foreach ($tokens as $i => $t) {
            if (isDateish($t)) { $d = $i; break; }
        }
        if ($d >= 0) {
            $counted = $tokens[$d] ?? '';
            if ($d + 1 < count($tokens)) $remarks = implode(' | ', array_slice($tokens, $d + 1));
        } else {
            if (count($tokens) >= 3) $remarks = implode(' | ', array_slice($tokens, 2));
        }
    }

    if (strcasecmp($type, 'NONE') === 0) $type = '';

    $prefix = '';
    if (preg_match('/^([A-Z]{1,3})-\d{4}-\d{4}$/', $asset, $pm)) {
        $prefix = $pm[1];
    }
    $category = ($prefix !== '' && isset($prefixCatMap[$prefix])) ? $prefixCatMap[$prefix] : $current;

    $rows[] = [
        'category' => $category,
        'asset_tag' => $asset,
        'location' => $location,
        'item_type' => $type,
        'color' => $color,
        'counted' => $counted,
        'remarks' => $remarks,
    ];
}

if (!$rows) { fwrite(STDERR, "No detail rows parsed.\n"); exit(1); }

$sel = $conn->prepare("SELECT equipment_id FROM equipment WHERE asset_tag=? LIMIT 1");
$upd = $conn->prepare("UPDATE equipment SET equipment_name=?, category=?, location=?, department=?, status=?, `condition`=?, brand=?, model=?, counted=?, remarks=?, notes=?, acquired=?, issued=? WHERE equipment_id=?");
$ins = $conn->prepare("INSERT INTO equipment (equipment_id,equipment_name,asset_tag,category,location,department,status,`condition`,brand,model,counted,remarks,notes,acquired,issued,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");

$created = 0;
$updated = 0;
$errors = 0;
if (!$dryRun) $conn->begin_transaction();

foreach ($rows as $r) {
    $asset = $r['asset_tag'];
    $cat = $r['category'];
    $loc = $r['location'] !== '' ? $r['location'] : 'Unassigned';
    $brand = $r['item_type'];
    $model = $r['color'];
    $counted = $r['counted'];
    $remarks = $r['remarks'];
    $notes = $remarks;
    $name = trim($cat . ($brand !== '' ? ' - ' . $brand : ''));

    $dept = 'PMO';
    $status = 'operational';
    $condition = 'good';
    $acquired = '';
    $issued = '';

    $sel->bind_param('s', $asset);
    if (!$sel->execute()) { $errors++; continue; }
    $resSel = $sel->get_result();
    $ex = $resSel ? $resSel->fetch_assoc() : null;

    if ($ex && !empty($ex['equipment_id'])) {
        $eid = (string)$ex['equipment_id'];
        $upd->bind_param('ssssssssssssss', $name, $cat, $loc, $dept, $status, $condition, $brand, $model, $counted, $remarks, $notes, $acquired, $issued, $eid);
        if ($dryRun || $upd->execute()) $updated++; else $errors++;
    } else {
        $eid = 'EQ-' . strtoupper(substr(md5($asset), 0, 7));
        $ins->bind_param('sssssssssssssss', $eid, $name, $asset, $cat, $loc, $dept, $status, $condition, $brand, $model, $counted, $remarks, $notes, $acquired, $issued);
        if ($dryRun || $ins->execute()) $created++; else $errors++;
    }
}

if (!$dryRun) {
    if ($errors > 0) {
        $conn->rollback();
        fwrite(STDERR, "Rolled back due to errors: $errors\n");
        exit(1);
    }
    $conn->commit();
}

echo "Rows parsed: " . count($rows) . "\n";
echo "Skipped detail lines: $skipped\n";
echo "Created: $created\n";
echo "Updated: $updated\n";
echo "Errors: $errors\n";
?>
