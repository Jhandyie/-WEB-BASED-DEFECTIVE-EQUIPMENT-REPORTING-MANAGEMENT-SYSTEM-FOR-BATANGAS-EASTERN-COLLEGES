<?php
/**
 * backfill_category_ids.php — give every equipment row the category the
 * pages actually read.
 *
 * The inventory import wrote the category name into equipment.category (text)
 * — 1,298 of 1,329 rows have one — but every page joins categories on
 * equipment.category_id, which the import left NULL. So the tracker, the
 * public board, the report exports and the reports-by-category chart all
 * showed "Uncategorized" for nearly everything while the data sat one column
 * over.
 *
 * Two passes. First, the exact match: category text = categories.category_name
 * (case-insensitive). Second, for rows with no category text at all, a guess
 * from the equipment name using the keyword table below; anything the table
 * does not recognise is listed and left NULL rather than mislabelled.
 *
 *   c:\xampp\php\php.exe scripts\backfill_category_ids.php           # dry run
 *   c:\xampp\php\php.exe scripts\backfill_category_ids.php --apply
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
require_once __DIR__ . '/../config/database.php';

$apply = in_array('--apply', $argv, true);
$pdo   = getPgsqlPdoConnection();

// keyword (matched case-insensitively against the equipment name) => category name
$guess = [
    'aircon' => 'Air Conditioner', 'air con' => 'Air Conditioner', 'air condition' => 'Air Conditioner', ' ac ' => 'Air Conditioner',
    'television' => 'Television', ' tv' => 'Television', 'tv ' => 'Television',
    'electric fan' => 'Electric Fan', 'ceiling fan' => 'Electric Fan', 'stand fan' => 'Electric Fan', 'wall fan' => 'Electric Fan', 'fan' => 'Electric Fan',
    'whiteboard' => 'Whiteboard / Glassboard', 'white board' => 'Whiteboard / Glassboard', 'glassboard' => 'Whiteboard / Glassboard', 'board' => 'Whiteboard / Glassboard',
    'locker' => 'Locker', 'cabinet' => 'Cabinet', 'shelf' => 'Cabinet',
    'table' => 'Office Table', 'desk' => 'Office Table',
    'chair' => 'Office Chair', 'armchair' => 'Office Chair', 'stool' => 'Office Chair',
    'copier' => 'Copier / Duplicator', 'xerox' => 'Copier / Duplicator', 'duplicator' => 'Copier / Duplicator',
    'piano' => 'Piano', 'food warmer' => 'Food Warmer',
    'computer' => 'Computer', 'desktop' => 'Computer', 'laptop' => 'Computer', 'monitor' => 'Computer', ' pc' => 'Computer', 'keyboard' => 'Computer', 'mouse' => 'Computer', 'cpu' => 'Computer',
    'printer' => 'Printer', 'projector' => 'Projector',
    'router' => 'Network Equipment', 'switch' => 'Network Equipment', 'wifi' => 'Network Equipment', 'network' => 'Network Equipment', 'net' => 'Network Equipment',
    'light' => 'Lighting / Electrical', 'lamp' => 'Lighting / Electrical', 'bulb' => 'Lighting / Electrical', 'outlet' => 'Lighting / Electrical', 'socket' => 'Lighting / Electrical', 'electrical' => 'Lighting / Electrical',
    'toilet' => 'Plumbing / Sanitary', 'comfort room' => 'Plumbing / Sanitary', 'cr ' => 'Plumbing / Sanitary', 'faucet' => 'Plumbing / Sanitary', 'sink' => 'Plumbing / Sanitary', 'flush' => 'Plumbing / Sanitary', 'bidet' => 'Plumbing / Sanitary', 'urinal' => 'Plumbing / Sanitary', 'lavatory' => 'Plumbing / Sanitary',
    'kisame' => 'Other / Not sure', 'ceiling' => 'Other / Not sure', 'door' => 'Other / Not sure', 'window' => 'Other / Not sure', 'karpet' => 'Other / Not sure', 'carpet' => 'Other / Not sure',
];

$cats = [];
foreach ($pdo->query("SELECT category_id, category_name FROM public.categories") as $c) { $cats[strtolower(trim($c['category_name']))] = (int)$c['category_id']; }

$exact = $pdo->query("SELECT COUNT(*) FROM public.equipment e JOIN public.categories c
                        ON lower(trim(c.category_name)) = lower(trim(COALESCE(NULLIF(e.category,''), e.equipment_category, '')))
                       WHERE e.category_id IS NULL")->fetchColumn();
echo ($apply ? "== APPLYING ==\n" : "== DRY RUN (add --apply to write) ==\n");
echo "pass 1 - category text matches a category name: {$exact} row(s)\n";

$rows = $pdo->query("SELECT equipment_id, equipment_name FROM public.equipment
                      WHERE category_id IS NULL
                        AND NOT EXISTS (SELECT 1 FROM public.categories c
                                         WHERE lower(trim(c.category_name)) = lower(trim(COALESCE(NULLIF(equipment.category,''), equipment.equipment_category, ''))))
                      ORDER BY equipment_id")->fetchAll(PDO::FETCH_ASSOC);
$guessed = []; $unknown = [];
foreach ($rows as $r) {
    $name = ' ' . strtolower(trim((string)$r['equipment_name'])) . ' ';
    $hit = null;
    foreach ($guess as $kw => $cat) { if (str_contains($name, $kw)) { $hit = $cat; break; } }
    if ($hit !== null && isset($cats[strtolower($hit)])) { $guessed[] = [$r['equipment_id'], $r['equipment_name'], $hit]; }
    else { $unknown[] = $r; }
}
echo "pass 2 - no category text, guessed from the name: " . count($guessed) . " row(s)\n";
foreach ($guessed as [$id, $nm, $cat]) { printf("  %-14s %-32s -> %s\n", $id, mb_strimwidth($nm, 0, 32, '…'), $cat); }
echo "left NULL (name not recognised): " . count($unknown) . " row(s)\n";
foreach ($unknown as $r) { printf("  %-14s %s\n", $r['equipment_id'], $r['equipment_name']); }
if (!$apply) { exit(0); }

$pdo->beginTransaction();
$n1 = $pdo->exec("UPDATE public.equipment e SET category_id = c.category_id, updated_at = now()
                   FROM public.categories c
                  WHERE e.category_id IS NULL
                    AND lower(trim(c.category_name)) = lower(trim(COALESCE(NULLIF(e.category,''), e.equipment_category, '')))");
$st = $pdo->prepare("UPDATE public.equipment SET category_id = :cid, category = :cat, updated_at = now() WHERE equipment_id = :id AND category_id IS NULL");
$n2 = 0;
foreach ($guessed as [$id, $nm, $cat]) { $st->execute(['cid' => $cats[strtolower($cat)], 'cat' => $cat, 'id' => $id]); $n2 += $st->rowCount(); }
$pdo->commit();
echo "written: {$n1} exact + {$n2} guessed\n";
echo "still NULL: " . $pdo->query("SELECT COUNT(*) FROM public.equipment WHERE category_id IS NULL")->fetchColumn() . "\n";
