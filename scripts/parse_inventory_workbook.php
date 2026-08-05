<?php
/**
 * scripts/parse_inventory_workbook.php
 *
 * Parses the PMO "NEW Inventory 2026" workbook into a flat, normalised item list
 * plus the campus/building/room tree the rest of the app needs.
 *
 * Every sheet is one equipment category. The layout is positional and repeats
 * across sheets: column B = Building, C = Area, D = Room, and a header row
 * (found by locating "Property Number") names the remaining columns. Location
 * cells are only filled on the row where they change, so they carry downward.
 *
 *   php scripts/parse_inventory_workbook.php <workbook.xlsx> [out.json]
 *
 * Writes data/bec_inventory_2026.json by default. Pure parsing — touches no DB.
 */

require_once __DIR__ . '/../includes/xlsx_reader.php';

/** Sheet name => [canonical category, property-number prefixes]. */
function invSheetCategories(): array {
    return [
        'Aircon'       => ['Air Conditioner',        ['A']],
        'Fans'         => ['Electric Fan',           ['CF', 'SF', 'IF', 'WF', 'EF', 'RF']],
        'TV'           => ['Television',             ['T']],
        'Boards'       => ['Whiteboard / Glassboard', ['WB']],
        'Locker'       => ['Locker',                 ['L']],
        'Cabinets'     => ['Cabinet',                ['CA']],
        'Chairs'       => ['Office Chair',           ['OC']],
        'Tables'       => ['Office Table',           ['OT']],
        'Shreder'      => ['Shredder',               ['SHR']],
        'Copy Printer' => ['Copier / Duplicator',    ['CP']],
        'Piano'        => ['Piano',                  ['P']],
        'Food Warmer'  => ['Food Warmer',            ['FW']],
    ];
}

/**
 * Property-number prefix => category. The sheet a row sits on is not always its
 * real category: the Piano sheet was copied from the Copy Printer sheet and still
 * carries its CP- rows, so the tag decides and the sheet is only a fallback.
 */
function invPrefixCategories(): array {
    $out = [];
    foreach (invSheetCategories() as [$category, $prefixes]) {
        foreach ($prefixes as $p) $out[$p] = $category;
    }
    return $out;
}

/** Which BEC unit services this category — PMO owns everything except IT gear. */
function invCategoryUnit(string $category): string {
    return in_array($category, ['Copier / Duplicator'], true) ? 'ITSO' : 'PMO';
}

/**
 * Build "Campus • Building • Room" the way the rest of the app already stores it
 * (see equipment.location and defect_reports.location).
 */
function invBuildingLabel(string $building, string $area): string {
    $building = trim($building);
    $area     = trim($area);
    if ($area === '')                                   return $building;
    if (preg_match('/^Building\s+\d+/i', $building))     return $building . ' - ' . $area;
    return $building . ' (' . $area . ')';
}

/** Locate the header row (the one naming "Property Number") and map label => column. */
function invHeaderMap(array $grid): array {
    foreach ($grid as $rowNo => $cells) {
        foreach ($cells as $col => $val) {
            if (!preg_match('/^property\s*(number|no\.?)$/i', $val)) continue;
            $map = [];
            foreach ($cells as $c => $v) $map[$c] = $v;
            // The date sub-header (Acquired / Issued / Counted) sits on the next row.
            foreach (($grid[$rowNo + 1] ?? []) as $c => $v) {
                if (preg_match('/^(acquired|issued|counted)$/i', $v)) $map[$c] = $v;
            }
            return ['row' => $rowNo, 'map' => $map];
        }
    }
    return ['row' => 0, 'map' => []];
}

/** Match a header label to the field we store it under. */
function invFieldForLabel(string $label): ?string {
    $l = strtolower(trim($label));
    if (preg_match('/^property\s*(number|no\.?)$/', $l))        return 'property_no';
    if (preg_match('/^qty/', $l))                               return 'qty';
    if (preg_match('/^serial/', $l))                            return 'serial';
    if ($l === 'acquired' || $l === 'issued' || $l === 'counted') return $l;
    if ($l === 'size' || $l === 'no. of slots')                 return 'size';
    if ($l === 'color' || $l === 'type - color')                return 'color';
    if ($l === 'remarks')                                       return 'remarks';
    if ($l === 'status')                                        return 'status_note';
    if ($l === 'classification')                                return 'classification';
    if (preg_match('/^(article|brand|type)/', $l))              return 'article';
    return null;
}

/** Collapse the inconsistent spacing the workbook uses around slashes and commas. */
function invTidyRoom(string $room): string {
    $room = preg_replace('/\s*\/\s*/', '/ ', $room);
    $room = preg_replace('/\s*,\s*/', ', ', $room);
    return trim(preg_replace('/\s+/', ' ', $room));
}

/**
 * Parse one sheet into ['items' => [...], 'tree' => [campus => [building => [rooms]]]].
 */
function invParseSheet(string $sheetName, array $grid, string $category): array {
    $header = invHeaderMap($grid);
    $fields = [];
    foreach ($header['map'] as $col => $label) {
        $f = invFieldForLabel($label);
        if ($f !== null && !isset($fields[$f])) $fields[$f] = $col;
    }

    $items  = [];
    $tree   = [];
    $campus = $building = $area = $room = $floor = '';
    $site   = ''; // last non-gate building, used to re-anchor rooms after a gate block
    $warnings = [];
    $runningQty = 0;
    $inSummary  = false;

    foreach ($grid as $rowNo => $cells) {
        if ($rowNo <= $header['row'] + 1) continue;

        $b = trim($cells[1] ?? '');   // B - Building / Bldg #
        $c = trim($cells[2] ?? '');   // C - Area / Building Name
        $d = trim($cells[3] ?? '');   // D - Room

        // Signature block ends the list.
        if (preg_match('/^(prepared|noted|approved|received)\s+by/i', $b)) break;

        // A per-campus breakdown follows the subtotal on some sheets, and after it
        // the Aircon/Fans/TV sheets resume with the June 2026 "New Building
        // (Diamond)" assets. Skip the breakdown rows — they name fan types and
        // campuses, not rooms — and pick the list back up at the next building.
        if ($inSummary) {
            if ($b === '') continue;
            $inSummary = false;
        }

        // Campus banner: "MAIN CAMPUS", "ANNEX 1 CAMPUS", ...
        if ($b !== '' && $c === '' && $d === '' && preg_match('/CAMPUS$/', $b) && $b === strtoupper($b)) {
            $campus = ucwords(strtolower($b));
            $campus = preg_replace('/\bAnnex (\d)\b/i', 'Annex $1', $campus);
            $building = $area = $room = $floor = $site = '';
            continue;
        }

        // "SHS FLOOR (Level 2)", "G11 SHS FLOOR (Level 3)" … are storeys of the
        // building above them, not buildings. They appear both on their own row and
        // sharing a row with the first room of the floor.
        $isFloorLabel = $b !== '' && preg_match('/\b(floor|level)\b/i', $b);

        if ($isFloorLabel) {
            $floor = $b;
            if ($d !== '') $room = $d;
        } elseif ($b !== '') {
            // New building header. On the Fans/TV sheets column B is the building
            // *number* and C the building name; elsewhere C is the area. Either way
            // invBuildingLabel() joins them the same way.
            $building = $b;
            $area     = $c;
            $room     = $d;
            $floor    = '';
        } elseif ($c !== '') {
            // Only the building-name column is filled: an un-numbered building
            // ("SPC Bldg. TESDA", "Rental Bldg. - GA Bldg. - SHS"), not an area.
            $building = $c;
            $area     = '';
            $room     = $d;
            $floor    = '';
        } elseif ($d !== '') {
            $room = $d;
            // Sites like "SPC Bldg. TESDA" open with their own "Main Gate / Entrance"
            // block and then list their rooms with no further building header. Once
            // the gate's guard house is past, those rooms belong to the site.
            if ($site !== '' && preg_match('/gate/i', $building) && !preg_match('/guard\s*house/i', $room)) {
                $building = $site;
                $area     = '';
            }
        }

        if ($campus === '' || $building === '') continue;

        if (!preg_match('/gate/i', $building)) $site = invBuildingLabel($building, $area);

        $buildingLabel = invBuildingLabel($building, $area);
        $roomLabel     = invTidyRoom($room !== '' ? $room : 'General Area');

        $tree[$campus][$buildingLabel][$roomLabel] = true;

        $get = static function (string $f) use ($fields, $cells): string {
            return isset($fields[$f]) ? trim($cells[$fields[$f]] ?? '') : '';
        };

        $propertyNo = strtoupper($get('property_no'));
        if (!preg_match('/^[A-Z]{1,4}-\d{3,4}-\d{3,4}$/', $propertyNo)) $propertyNo = '';

        // QTY is what makes a row an asset. Several sheets have a property-number
        // column that was fill-dragged down the whole location list (the Copy
        // Printer and Piano sheets each carry 246 such CP- numbers against rooms
        // that hold nothing), so a property number alone does not mean an item.
        $qty = (int)preg_replace('/\D/', '', $get('qty'));
        if ($qty < 1) {
            if ($propertyNo !== '') {
                $warnings[] = "row {$rowNo}: {$propertyNo} has no QTY — treated as a blank location row";
            }
            continue;
        }

        // Grand-total row: untagged, equal to everything counted so far, and
        // carrying nothing but the number (Fans/TV additionally label it "Total").
        if ($propertyNo === '' && $runningQty > 0 && $qty === $runningQty) {
            $bare = true;
            foreach ($cells as $col => $v) {
                if (isset($fields['qty']) && $col === $fields['qty']) continue;
                if (trim($v) !== '' && strcasecmp(trim($v), 'Total') !== 0) { $bare = false; break; }
            }
            if ($bare) { $inSummary = true; continue; }
        }
        $runningQty += $qty;

        $serial = $get('serial');
        if (strcasecmp($serial, 'NONE') === 0) $serial = '';

        $prefix      = $propertyNo === '' ? '' : strtoupper(explode('-', $propertyNo)[0]);
        $rowCategory = invPrefixCategories()[$prefix] ?? $category;

        $items[] = [
            'sheet'          => $sheetName,
            'category'       => $rowCategory,
            'property_no'    => $propertyNo,
            'campus'         => $campus,
            'building'       => $buildingLabel,
            'area'           => $area,
            'floor'          => $floor,
            'room'           => $roomLabel,
            'location'       => $campus . ' • ' . $buildingLabel . ' • ' . $roomLabel,
            'qty'            => $qty,
            'serial'         => $serial,
            'article'        => $get('article'),
            // Some sheets call this column "Brand", others "Type" — which it is
            // decides whether the value is the asset's name or its manufacturer.
            'article_label'  => isset($fields['article']) ? ($header['map'][$fields['article']] ?? '') : '',
            'size'           => $get('size'),
            'color'          => $get('color'),
            'classification' => $get('classification'),
            'remarks'        => $get('remarks'),
            'status_note'    => $get('status_note'),
            'acquired'       => $get('acquired'),
            'issued'         => $get('issued'),
            'counted'        => $get('counted'),
        ];
    }

    return ['items' => $items, 'tree' => $tree, 'fields' => $fields, 'warnings' => $warnings];
}

/* ------------------------------------------------------------------ */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$workbook = $argv[1] ?? '';
$outPath  = $argv[2] ?? (__DIR__ . '/../data/bec_inventory_2026.json');
if ($workbook === '' || !is_file($workbook)) {
    fwrite(STDERR, "Usage: php scripts/parse_inventory_workbook.php <workbook.xlsx> [out.json]\n");
    exit(1);
}

$sheets = becXlsxSheets($workbook);
if (!$sheets) { fwrite(STDERR, "Could not read any sheets from {$workbook}\n"); exit(1); }

$catalog   = invSheetCategories();
$allItems  = [];
$tree      = [];
$perSheet  = [];

foreach ($sheets as $name => $grid) {
    if (!isset($catalog[$name])) {
        fwrite(STDERR, "! Skipping unmapped sheet: {$name}\n");
        continue;
    }
    [$category, $prefixes] = $catalog[$name];
    $res = invParseSheet($name, $grid, $category);

    $untagged = 0;
    $units    = 0;
    foreach ($res['items'] as $item) {
        if ($item['property_no'] === '') {
            $untagged++;
        } else {
            $prefix = strtoupper(explode('-', $item['property_no'])[0]);
            if (!in_array($prefix, $prefixes, true) && !isset(invPrefixCategories()[$prefix])) {
                fwrite(STDERR, "! {$name}: unrecognised property prefix {$item['property_no']}\n");
            }
        }
        $units += $item['qty'];
        $allItems[] = $item;
    }
    foreach ($res['tree'] as $campus => $buildings) {
        foreach ($buildings as $b => $rooms) {
            foreach ($rooms as $r => $_) $tree[$campus][$b][$r] = true;
        }
    }
    $perSheet[$name] = [
        'category'  => $category,
        'rows'      => count($res['items']),
        'units'     => $units,
        'untagged'  => $untagged,
        'columns'   => $res['fields'],
        'warnings'  => $res['warnings'],
    ];
}

// The same asset listed twice (the Piano sheet is a copy of the Copy Printer
// sheet and repeats its CP- rows) collapses to one record.
$firstSheet = [];
$deduped    = [];
$repeats    = 0;
foreach ($allItems as $item) {
    $key = $item['property_no'] === '' ? null
         : $item['property_no'] . '|' . $item['category'] . '|' . $item['location'] . '|' . $item['qty'];
    // Only a *cross-sheet* repeat is a copy. Two identical rows on the same sheet
    // are two physical assets sharing a mistyped tag — the PMO's own sheet totals
    // count both, so dropping one would undercount.
    if ($key !== null && isset($firstSheet[$key]) && $firstSheet[$key] !== $item['sheet']) {
        $repeats++;
        continue;
    }
    if ($key !== null && !isset($firstSheet[$key])) $firstSheet[$key] = $item['sheet'];
    $deduped[] = $item;
}
$allItems = $deduped;

// A property number reused at two *different* locations is a real numbering
// conflict in the workbook: two physical assets, one tag. Keep both and give the
// later ones a suffixed asset id so they stay addressable, but report every one.
$byProperty = [];
foreach ($allItems as $i => $item) {
    if ($item['property_no'] !== '') $byProperty[$item['property_no']][] = $i;
}
$duplicates = [];
foreach ($byProperty as $pno => $idx) {
    if (count($idx) < 2) continue;
    $duplicates[$pno] = [];
    foreach ($idx as $n => $i) {
        $allItems[$i]['asset_id']       = $n === 0 ? $pno : $pno . '-' . chr(65 + $n);
        $allItems[$i]['tag_conflict']   = true;
        $duplicates[$pno][] = $allItems[$i]['asset_id'] . ' @ ' . $allItems[$i]['location'];
    }
}

// Everything else keeps its property number as its id; untagged bulk rows get a
// generated one so they can still be referenced by a defect report.
$untaggedSeq = [];
foreach ($allItems as $i => $item) {
    if (isset($allItems[$i]['asset_id'])) continue;
    if ($item['property_no'] !== '') {
        $allItems[$i]['asset_id'] = $item['property_no'];
        continue;
    }
    // Reuse the category's own PMO prefix; the 2026 segment keeps these clear of
    // the real tags (CF-2026-U001 can never collide with CF-0825-0001).
    $prefix = 'XX';
    foreach (invSheetCategories() as [$cat, $prefixes]) {
        if ($cat === $item['category']) { $prefix = $prefixes[0]; break; }
    }
    $n = ($untaggedSeq[$prefix] = ($untaggedSeq[$prefix] ?? 0) + 1);
    $allItems[$i]['asset_id'] = sprintf('%s-2026-U%03d', $prefix, $n);
}

// Normalise the tree to plain arrays. Rooms the workbook marks "Demolished" are
// not places anyone can report a fault in; a building left with nothing else goes too.
$treeOut  = [];
$demolished = [];
foreach ($tree as $campus => $buildings) {
    foreach ($buildings as $b => $rooms) {
        $keep = [];
        foreach (array_keys($rooms) as $r) {
            if (preg_match('/^demolished$/i', $r)) { $demolished[] = "{$campus} • {$b}"; continue; }
            $keep[] = $r;
        }
        if ($keep) $treeOut[$campus][$b] = $keep;
    }
}

// Final per-category tallies, taken after de-duplication and re-categorisation.
$byCategory = [];
foreach ($allItems as $item) {
    $k = $item['category'];
    $byCategory[$k]['rows']     = ($byCategory[$k]['rows'] ?? 0) + 1;
    $byCategory[$k]['units']    = ($byCategory[$k]['units'] ?? 0) + $item['qty'];
    $byCategory[$k]['untagged'] = ($byCategory[$k]['untagged'] ?? 0) + ($item['property_no'] === '' ? 1 : 0);
}
ksort($byCategory);

$payload = [
    '_meta' => [
        'source'           => basename($workbook),
        'parsed_at'        => date('c'),
        'total_rows'       => count($allItems),
        'total_units'      => array_sum(array_map(static fn($i) => $i['qty'], $allItems)),
        'repeated_rows'    => $repeats,
        'categories'       => $byCategory,
        'sheets'           => $perSheet,
        'duplicates'       => $duplicates,
    ],
    'items' => $allItems,
    'tree'  => $treeOut,
];

@mkdir(dirname($outPath), 0777, true);
file_put_contents($outPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$totalUnits = array_sum(array_map(static fn($i) => $i['qty'], $allItems));
echo "Parsed " . count($allItems) . " inventory rows (" . $totalUnits . " units); "
   . "dropped {$repeats} row(s) the workbook lists twice\n";
printf("  %-24s %6s %7s %9s\n", 'CATEGORY', 'ROWS', 'UNITS', 'UNTAGGED');
foreach ($byCategory as $cat => $s) {
    printf("  %-24s %6d %7d %9d\n", $cat, $s['rows'], $s['units'], $s['untagged']);
}
printf("  %-24s %6d %7d %9d\n", 'TOTAL', count($allItems), $totalUnits,
    array_sum(array_column($byCategory, 'untagged')));
echo "Campuses: " . count($treeOut) . ", buildings: " . array_sum(array_map('count', $treeOut))
   . ", rooms: " . array_sum(array_map(static fn($b) => array_sum(array_map('count', $b)), $treeOut)) . "\n";
if ($duplicates) {
    echo "\n! " . count($duplicates) . " duplicate property number(s) in the workbook:\n";
    foreach ($duplicates as $pno => $locs) {
        echo "   {$pno}\n";
        foreach ($locs as $l) echo "      - {$l}\n";
    }
}
echo "\nWrote " . realpath($outPath) . "\n";
