<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireRole('admin');
require_once __DIR__ . '/includes/csrf.php';

// ── Inventory Excel upload (admin) — parses the PMO inventory workbook and
//    repopulates api/data/inventory.json (empty until a file is uploaded). ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['inventory_xlsx'])) {
    $flash = ['ok' => false, 'msg' => ''];
    if (function_exists('csrf_check') && !csrf_check()) {
        $flash['msg'] = 'Security check failed. Please reload and try again.';
    } elseif ((int)($_FILES['inventory_xlsx']['error'] ?? 4) !== 0) {
        $flash['msg'] = 'No file uploaded, or the upload failed.';
    } elseif (!preg_match('/\.xlsx$/i', (string)($_FILES['inventory_xlsx']['name'] ?? ''))) {
        $flash['msg'] = 'Please upload an Excel .xlsx file.';
    } else {
        require_once __DIR__ . '/includes/xlsx_reader.php';
        $texts = becXlsxAllText((string)$_FILES['inventory_xlsx']['tmp_name']);
        // Property-number prefix -> inventory category key (robust to sheet layout).
        $prefixKey = [
            'A' => 'airConditioners', 'T' => 'televisions',
            'CF' => 'fans', 'SF' => 'fans', 'IF' => 'fans', 'WF' => 'fans', 'EF' => 'fans', 'RF' => 'fans',
            'WB' => 'whiteboards', 'L' => 'lockers', 'CA' => 'filingCabinets', 'OT' => 'tables',
            'OC' => 'officeChairs', 'CP' => 'copyPrinters', 'FW' => 'foodWarmers', 'SHR' => 'shredders', 'PC' => 'computers',
        ];
        $seen = []; // propertyNo => prefix (dedup by property number)
        foreach ($texts as $t) {
            $t = trim((string)$t);
            if (preg_match('/^([A-Za-z]{1,4})-\d{3,4}-\d{2,4}$/', $t, $mm)) {
                $seen[strtoupper($t)] = strtoupper($mm[1]);
            }
        }
        $countByKey = [];
        foreach ($seen as $pno => $prefix) {
            $key = $prefixKey[$prefix] ?? null;
            if ($key) $countByKey[$key] = ($countByKey[$key] ?? 0) + 1;
        }
        if (!$countByKey) {
            $flash['msg'] = 'No inventory property numbers (e.g. A-0825-0001) were found in that file. Please check the workbook.';
        } else {
            $labels = [
                'airConditioners' => 'Air Conditioning Unit', 'televisions' => 'Television Unit', 'fans' => 'Fan',
                'whiteboards' => 'Whiteboard', 'lockers' => 'Locker', 'filingCabinets' => 'Filing Cabinet',
                'tables' => 'Table', 'officeChairs' => 'Office Chair', 'copyPrinters' => 'Copy Printer',
                'foodWarmers' => 'Food Warmer', 'shredders' => 'Shredder', 'computers' => 'Computer',
            ];
            $out = [];
            foreach ($countByKey as $key => $cnt) {
                $out[$key] = [[
                    'id' => 1,
                    'propertyNo' => strtoupper(substr($key, 0, 3)) . '-UPLOAD-0001',
                    'campus' => 'All Campuses', 'building' => 'Multiple', 'buildingName' => 'Uploaded Inventory',
                    'room' => 'Multiple Rooms', 'type' => ($labels[$key] ?? $key), 'article' => ($labels[$key] ?? $key),
                    'qty' => $cnt, 'status' => 'Active', 'department' => 'PMO',
                    'remarks' => 'Imported from ' . basename((string)$_FILES['inventory_xlsx']['name']) . ' on ' . date('M j, Y'),
                ]];
            }
            $out['_meta'] = ['uploaded_at' => date('c'), 'filename' => basename((string)$_FILES['inventory_xlsx']['name']), 'total' => array_sum($countByKey)];
            @file_put_contents(__DIR__ . '/api/data/inventory.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $flash['ok'] = true;
            $flash['msg'] = 'Inventory imported — ' . number_format(array_sum($countByKey)) . ' items across ' . count($countByKey) . ' categories from ' . basename((string)$_FILES['inventory_xlsx']['name']) . '.';
        }
    }
    $_SESSION['inv_flash'] = $flash;
    header('Location: inventory_functions.php');
    exit;
}

$conn = getDBConnection();

// Schema compatibility for equipment/defect tables across DB variants.
$eqCols = [];
$eqColsRes = $conn->query("SHOW COLUMNS FROM equipment");
if ($eqColsRes) {
    while ($ec = $eqColsRes->fetch_assoc()) {
        $eqCols[strtolower($ec['Field'])] = true;
    }
}
$hasEqStatus     = isset($eqCols['status']);
$hasEqCategory   = isset($eqCols['category']);
$hasEqDepartment = isset($eqCols['department']);
$hasEqLocation   = isset($eqCols['location']);
$hasEqBrand      = isset($eqCols['brand']);
$hasEqModel      = isset($eqCols['model']);
$hasEqCondition  = isset($eqCols['condition']);
$hasEqWarranty   = isset($eqCols['warranty_expiry']);
$hasEqAcquired   = isset($eqCols['acquired']);
$hasEqIssued     = isset($eqCols['issued']);
$hasEqCounted    = isset($eqCols['counted']);
$hasEqRemarks    = isset($eqCols['remarks']);
$hasEqUpdatedAt  = isset($eqCols['updated_at']);
$hasEqAddedBy    = isset($eqCols['added_by']);
$hasEqCreatedAt  = isset($eqCols['created_at']);

$hasDefectReports = (bool)$conn->query("SHOW TABLES LIKE 'defect_reports'")->num_rows;
$drCols = [];
if ($hasDefectReports) {
    $drColsRes = $conn->query("SHOW COLUMNS FROM defect_reports");
    if ($drColsRes) {
        while ($dc = $drColsRes->fetch_assoc()) {
            $drCols[strtolower($dc['Field'])] = true;
        }
    }
}
$hasDrEquipId = isset($drCols['equipment_id']);
$hasDrStatus  = isset($drCols['status']);

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['fullname'] ?? 'Administrator';

/* --- POST ACTIONS ----------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['action'] ?? '';

    /* ADD EQUIPMENT */
    if ($act === 'add') {
        $name    = trim($_POST['equipment_name'] ?? '');
        $tag     = trim($_POST['asset_tag']      ?? '');
        $cat     = $_POST['category']            ?? '';
        $loc     = trim($_POST['location']       ?? '');
        $dept    = $_POST['department']          ?? '';
        $brand   = trim($_POST['brand']          ?? '');
        $model   = trim($_POST['model']          ?? '');
        $serial  = trim($_POST['serial_number']  ?? '');
        $status  = $_POST['status']              ?? 'operational';
        $cond    = $_POST['condition']           ?? 'good';
        $pdate   = $_POST['purchase_date']       ?? null;
        $price   = $_POST['purchase_price']      ?? null;
        $warranty= $_POST['warranty_expiry']     ?? null;
        $acquired= trim($_POST['acquired']       ?? '');
        $issued  = trim($_POST['issued']         ?? '');
        $counted = trim($_POST['counted']        ?? '');
        $remarks = trim($_POST['remarks']        ?? '');
        $notes   = trim($_POST['notes']          ?? '');
        if (!$hasEqRemarks && $remarks !== '' && $notes === '') { $notes = $remarks; }

        // Empty date/number fields must be NULL (Postgres rejects '' for date/numeric columns).
        $pdate    = ($pdate === '' || $pdate === null)       ? null : $pdate;
        $price    = ($price === '' || $price === null)       ? null : $price;
        $warranty = ($warranty === '' || $warranty === null) ? null : $warranty;
        $acquired = ($acquired === '') ? null : $acquired;
        $issued   = ($issued === '')   ? null : $issued;
        $counted  = ($counted === '')  ? null : $counted;

        $errors = [];
        if (!$name) $errors[] = 'Equipment name is required.';
        if (!$tag)  $errors[] = 'Asset tag is required.';
        if (!$loc)  $errors[] = 'Location is required.';

        // duplicate asset tag check
        $chk = $conn->prepare("SELECT equipment_id FROM equipment WHERE asset_tag = ?");
        $chk->bind_param('s', $tag); $chk->execute();
        if ($chk->get_result()->num_rows > 0) $errors[] = 'Asset tag already exists.';

        if ($errors) {
            $_SESSION['flash'] = ['err', implode(' ', $errors)];
        } else {
            $eid = 'EQ-' . strtoupper(substr(md5(uniqid()), 0, 7));

            $insCols = ['equipment_id', 'equipment_name', 'asset_tag'];
            $insVals = [$eid, $name, $tag];
            if ($hasEqCategory)   { $insCols[] = 'category';        $insVals[] = $cat; }
            if ($hasEqLocation)   { $insCols[] = 'location';        $insVals[] = $loc; }
            if ($hasEqDepartment) { $insCols[] = 'department';      $insVals[] = $dept; }
            if (isset($eqCols['unit']) && function_exists('classifyDepartmentByEquipment')) {
                $insCols[] = 'unit'; $insVals[] = classifyDepartmentByEquipment('', $name, $cat, $loc);
            }
            if ($hasEqBrand)      { $insCols[] = 'brand';           $insVals[] = $brand; }
            if ($hasEqModel)      { $insCols[] = 'model';           $insVals[] = $model; }
            if (isset($eqCols['serial_number']))   { $insCols[] = 'serial_number';   $insVals[] = $serial; }
            if ($hasEqStatus)     { $insCols[] = 'status';          $insVals[] = $status; }
            if ($hasEqCondition)  { $insCols[] = '`condition`';     $insVals[] = $cond; }
            if (isset($eqCols['purchase_date']))   { $insCols[] = 'purchase_date';   $insVals[] = $pdate; }
            if (isset($eqCols['purchase_price']))  { $insCols[] = 'purchase_price';  $insVals[] = $price; }
            if ($hasEqWarranty)   { $insCols[] = 'warranty_expiry'; $insVals[] = $warranty; }
            if ($hasEqAcquired)   { $insCols[] = 'acquired';        $insVals[] = $acquired; }
            if ($hasEqIssued)     { $insCols[] = 'issued';          $insVals[] = $issued; }
            if ($hasEqCounted)    { $insCols[] = 'counted';         $insVals[] = $counted; }
            if ($hasEqRemarks)    { $insCols[] = 'remarks';         $insVals[] = $remarks; }
            if (isset($eqCols['notes']))           { $insCols[] = 'notes';           $insVals[] = $notes; }
            if ($hasEqAddedBy)    { $insCols[] = 'added_by';        $insVals[] = $admin_id; }

            $ph = implode(',', array_fill(0, count($insVals), '?'));
            $sql = "INSERT INTO equipment (" . implode(',', $insCols);
            if ($hasEqCreatedAt) {
                $sql .= ",created_at) VALUES (" . $ph . ",NOW())";
            } else {
                $sql .= ") VALUES (" . $ph . ")";
            }
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(str_repeat('s', count($insVals)), ...$insVals);
            $stmt->execute();
            // Third element carries what the label needs, so the confirmation
            // can offer to print it there and then. Whoever just added the
            // item is the person holding it; sending them back to the list to
            // find it again is the moment a label stops getting made.
            $_SESSION['flash'] = ['ok', "Equipment \"$name\" added (ID: $eid).",
                                  ['id' => $eid, 'name' => $name, 'tag' => $tag, 'loc' => $loc]];
        }
    }

    /* EDIT EQUIPMENT */
    if ($act === 'edit') {
        $eid     = $_POST['equipment_id']    ?? '';
        $name    = trim($_POST['equipment_name'] ?? '');
        $tag     = trim($_POST['asset_tag']  ?? '');
        $cat     = $_POST['category']        ?? '';
        $loc     = trim($_POST['location']   ?? '');
        $dept    = $_POST['department']      ?? '';
        $brand   = trim($_POST['brand']      ?? '');
        $model   = trim($_POST['model']      ?? '');
        $serial  = trim($_POST['serial_number'] ?? '');
        $status  = $_POST['status']          ?? 'operational';
        $cond    = $_POST['condition']       ?? 'good';
        $pdate   = $_POST['purchase_date']   ?? null;
        $price   = $_POST['purchase_price']  ?? null;
        $warranty= $_POST['warranty_expiry'] ?? null;
        $acquired= trim($_POST['acquired']   ?? '');
        $issued  = trim($_POST['issued']     ?? '');
        $counted = trim($_POST['counted']    ?? '');
        $remarks = trim($_POST['remarks']    ?? '');
        $notes   = trim($_POST['notes']      ?? '');
        if (!$hasEqRemarks && $remarks !== '' && $notes === '') { $notes = $remarks; }

        // Empty date/number fields must be NULL (Postgres rejects '' for date/numeric columns).
        $pdate    = ($pdate === '' || $pdate === null)       ? null : $pdate;
        $price    = ($price === '' || $price === null)       ? null : $price;
        $warranty = ($warranty === '' || $warranty === null) ? null : $warranty;
        $acquired = ($acquired === '') ? null : $acquired;
        $issued   = ($issued === '')   ? null : $issued;
        $counted  = ($counted === '')  ? null : $counted;

        $errors = [];
        if (!$name) $errors[] = 'Equipment name is required.';
        if (!$tag)  $errors[] = 'Asset tag is required.';

        $exists = $conn->prepare("SELECT equipment_id FROM equipment WHERE equipment_id=? LIMIT 1");
        $exists->bind_param('s', $eid);
        $exists->execute();
        if ($exists->get_result()->num_rows === 0) {
            $errors[] = 'This item cannot be edited because it is not stored in the equipment table.';
        }

        $chk = $conn->prepare("SELECT equipment_id FROM equipment WHERE asset_tag=? AND equipment_id!=?");
        $chk->bind_param('ss',$tag,$eid); $chk->execute();
        if ($chk->get_result()->num_rows > 0) $errors[] = 'Asset tag in use by another item.';

        if ($errors) {
            $_SESSION['flash'] = ['err', implode(' ', $errors)];
        } else {
            $set = ['equipment_name=?', 'asset_tag=?'];
            $vals = [$name, $tag];
            if ($hasEqCategory)   { $set[] = 'category=?';        $vals[] = $cat; }
            if ($hasEqLocation)   { $set[] = 'location=?';        $vals[] = $loc; }
            if ($hasEqDepartment) { $set[] = 'department=?';      $vals[] = $dept; }
            if ($hasEqBrand)      { $set[] = 'brand=?';           $vals[] = $brand; }
            if ($hasEqModel)      { $set[] = 'model=?';           $vals[] = $model; }
            if (isset($eqCols['serial_number']))  { $set[] = 'serial_number=?';  $vals[] = $serial; }
            if ($hasEqStatus)     { $set[] = 'status=?';          $vals[] = $status; }
            if ($hasEqCondition)  { $set[] = '`condition`=?';     $vals[] = $cond; }
            if (isset($eqCols['purchase_date']))  { $set[] = 'purchase_date=?';  $vals[] = $pdate; }
            if (isset($eqCols['purchase_price'])) { $set[] = 'purchase_price=?'; $vals[] = $price; }
            if ($hasEqWarranty)   { $set[] = 'warranty_expiry=?'; $vals[] = $warranty; }
            if ($hasEqAcquired)   { $set[] = 'acquired=?';        $vals[] = $acquired; }
            if ($hasEqIssued)     { $set[] = 'issued=?';          $vals[] = $issued; }
            if ($hasEqCounted)    { $set[] = 'counted=?';         $vals[] = $counted; }
            if ($hasEqRemarks)    { $set[] = 'remarks=?';         $vals[] = $remarks; }
            if (isset($eqCols['notes']))          { $set[] = 'notes=?';          $vals[] = $notes; }
            if ($hasEqUpdatedAt)  { $set[] = 'updated_at=NOW()'; }

            $vals[] = $eid;
            $stmt = $conn->prepare("UPDATE equipment SET " . implode(',', $set) . " WHERE equipment_id=?");
            $stmt->bind_param(str_repeat('s', count($vals)), ...$vals);
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                $_SESSION['flash'] = ['ok', "Equipment \"$name\" updated."];
            } else {
                $_SESSION['flash'] = ['err', 'No changes were saved. The selected item may not exist in the equipment table.'];
            }
        }
    }

    /* RETIRE / DELETE */
    if ($act === 'retire') {
        $eid = $_POST['equipment_id'] ?? '';
        if ($hasEqStatus) {
            $sql = $hasEqUpdatedAt
                ? "UPDATE equipment SET status='retired',updated_at=NOW() WHERE equipment_id=?"
                : "UPDATE equipment SET status='retired' WHERE equipment_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $eid);
            $stmt->execute();
            $_SESSION['flash'] = ['ok', 'Equipment retired.'];
        } else {
            $_SESSION['flash'] = ['err', 'Retire not available: status column is missing.'];
        }
    }
    if ($act === 'delete') {
        $eid = $_POST['equipment_id'] ?? '';
        if ($hasEqStatus) {
            $sql = $hasEqUpdatedAt
                ? "UPDATE equipment SET status='deleted',updated_at=NOW() WHERE equipment_id=?"
                : "UPDATE equipment SET status='deleted' WHERE equipment_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $eid);
            $stmt->execute();
            $_SESSION['flash'] = ['ok', 'Equipment removed from inventory.'];
        } else {
            $stmt = $conn->prepare("DELETE FROM equipment WHERE equipment_id=?");
            $stmt->bind_param('s', $eid);
            $stmt->execute();
            $_SESSION['flash'] = ['ok', 'Equipment removed from inventory.'];
        }
    }

    header('Location: admin_inventory.php'); exit();
}

/* --- FILTERS ----------------------------------------- */
$cf  = $_GET['category']   ?? 'all';
$sf  = $_GET['status']     ?? 'all';
$df  = $_GET['dept']       ?? 'all';
$sq  = $_GET['search']     ?? '';
// PMO/ITSO responsible-unit scope: defaults to the logged-in admin's unit, still switchable.
$adminUnit  = function_exists('adminUnitForUser') ? adminUnitForUser($admin_id) : '';
$uf         = $_GET['unit'] ?? ($adminUnit !== '' ? $adminUnit : 'all');
$uf         = in_array($uf, ['all','ITSO','PMO'], true) ? $uf : 'all';
$ufExplicit = array_key_exists('unit', $_GET);
$hasEqUnit  = isset($eqCols['unit']);
$vw  = $_GET['view']       ?? 'table'; // table | grid
$jc  = $_GET['jc']         ?? ''; // JSON category quick view
$pg  = max(1, (int)($_GET['page'] ?? 1));
$per = (int)($_GET['per_page'] ?? 10);
if (!in_array($per, [10,20,50,100], true)) { $per = 10; }

/* --- DATA -------------------------------------------- */
$openDefectsExpr = '0';
if ($hasDefectReports && $hasDrEquipId) {
    $openDefectsExpr = $hasDrStatus
        ? "(SELECT COUNT(*) FROM defect_reports WHERE equipment_id = e.equipment_id AND status NOT IN ('completed','verified','closed','rejected','deleted'))"
        : "(SELECT COUNT(*) FROM defect_reports WHERE equipment_id = e.equipment_id)";
}

$selCategory = $hasEqCategory ? 'e.category' : "'' AS category";
$selLocation = $hasEqLocation ? 'e.location' : "'' AS location";
$selDept     = $hasEqDepartment ? 'e.department' : "'' AS department";
$selBrand    = $hasEqBrand ? 'e.brand' : "'' AS brand";
$selModel    = $hasEqModel ? 'e.model' : "'' AS model";
$selStatus   = $hasEqStatus ? 'e.status' : "'operational' AS status";
$selCond     = $hasEqCondition ? 'e.`condition`' : "'good' AS `condition`";
$selWarranty = $hasEqWarranty ? 'e.warranty_expiry' : "NULL AS warranty_expiry";
$selAcquired = $hasEqAcquired ? 'e.acquired' : "NULL AS acquired";
$selIssued   = $hasEqIssued ? 'e.issued' : "NULL AS issued";
$selCounted  = $hasEqCounted ? 'e.counted' : "NULL AS counted";
$selRemarks  = $hasEqRemarks ? 'e.remarks' : (isset($eqCols['notes']) ? 'e.notes AS remarks' : "'' AS remarks");

$q = "SELECT e.*,
        {$selCategory},
        {$selLocation},
        {$selDept},
        {$selBrand},
        {$selModel},
        {$selStatus},
        {$selCond},
        {$selWarranty},
        {$selAcquired},
        {$selIssued},
        {$selCounted},
        {$selRemarks},
        {$openDefectsExpr} AS open_defects
      FROM equipment e
      WHERE 1=1";
$params=[]; $types='';
if ($hasEqStatus) { $q .= " AND e.status != 'deleted'"; }
if ($cf!=='all' && $hasEqCategory) { $q.=" AND e.category=?";    $params[]=$cf; $types.='s'; }
if ($sf!=='all' && $hasEqStatus)   { $q.=" AND e.status=?";      $params[]=$sf; $types.='s'; }
if ($df!=='all' && $hasEqDepartment){$q.=" AND e.department=?";  $params[]=$df; $types.='s'; }
if ($uf!=='all' && $hasEqUnit) {
    if ($ufExplicit) { $q.=" AND e.unit=?"; $params[]=$uf; $types.='s'; }
    else { $q.=" AND (e.unit=? OR e.unit IS NULL OR e.unit='')"; $params[]=$uf; $types.='s'; } // default view keeps un-classified
}
if ($sq!=='') {
    $ql='%'.$sq.'%';
    $searchParts = ["e.equipment_name LIKE ?", "e.asset_tag LIKE ?"]; 
    $searchVals = [$ql, $ql];
    if ($hasEqLocation) { $searchParts[] = "e.location LIKE ?"; $searchVals[] = $ql; }
    if ($hasEqBrand)    { $searchParts[] = "e.brand LIKE ?";    $searchVals[] = $ql; }
    if ($hasEqModel)    { $searchParts[] = "e.model LIKE ?";    $searchVals[] = $ql; }
    $q .= " AND (" . implode(' OR ', $searchParts) . ")";
    $params=array_merge($params,$searchVals); $types.=str_repeat('s', count($searchVals));
}
if ($hasEqStatus) {
    $q.=" ORDER BY FIELD(e.status,'operational','under_maintenance','faulty','retired'), e.equipment_name ASC";
} else {
    $q.=" ORDER BY e.equipment_name ASC";
}

$stmt=$conn->prepare($q);
if($params){$stmt->bind_param($types,...$params);}
$stmt->execute();
$items=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// all items for counts (no filter)
$allSql = "SELECT " .
    ($hasEqStatus ? "status" : "'operational' AS status") . "," .
    ($hasEqCategory ? "category" : "'' AS category") . "," .
    ($hasEqDepartment ? "department" : "'' AS department") . "," .
    ($hasEqCondition ? "`condition`" : "'good' AS `condition`") . "," .
    ($hasEqWarranty ? "warranty_expiry" : "NULL AS warranty_expiry") .
    " FROM equipment" . ($hasEqStatus ? " WHERE status!='deleted'" : "");
if ($uf!=='all' && $hasEqUnit) {
    $uCond = $ufExplicit ? "unit='$uf'" : "(unit='$uf' OR unit IS NULL OR unit='')";
    $allSql .= (strpos($allSql, 'WHERE') !== false ? " AND " : " WHERE ") . $uCond;
}
$all_res=$conn->query($allSql);
$all_raw=$all_res?$all_res->fetch_all(MYSQLI_ASSOC):[];
$db_total = count($all_raw);

function cntE($arr,$fn){return count(array_filter($arr,$fn));}
$c_total  = count($all_raw);
$c_oper   = cntE($all_raw,fn($e)=>$e['status']==='operational');
$c_maint  = cntE($all_raw,fn($e)=>$e['status']==='under_maintenance');
$c_fault  = cntE($all_raw,fn($e)=>$e['status']==='faulty');
$c_retire = cntE($all_raw,fn($e)=>$e['status']==='retired');
$c_warn   = cntE($all_raw,fn($e)=>
    !empty($e['warranty_expiry']) &&
    strtotime($e['warranty_expiry']) <= strtotime('+30 days') &&
    strtotime($e['warranty_expiry']) >= time()
);

// Inventory snapshot sourced from api/data/inventory.json
$inventorySummary = [
    'fans' => 0,
    'tables' => 0,
    'filingCabinets' => 0,
    'chairs' => 0,
    'officeChairs' => 0,
    'airConditioners' => 0,
    'televisions' => 0,
    'shredders' => 0,
    'copyPrinters' => 0,
    'lockers' => 0,
    'foodWarmers' => 0,
    'whiteboards' => 0,
    'computers' => 0,
    'pianos' => 0,
];
$computerByDept = ['ITSO' => 0, 'PMO' => 0];
$inventorySummaryLabels = [
    'fans' => 'Fans',
    'tables' => 'Tables',
    'filingCabinets' => 'Filing Cabinets',
    'chairs' => 'Chairs',
    'officeChairs' => 'Office Chairs',
    'airConditioners' => 'Air Conditioners',
    'televisions' => 'Televisions',
    'shredders' => 'Shredders',
    'copyPrinters' => 'Copy Printers',
    'lockers' => 'Lockers',
    'foodWarmers' => 'Food Warmers',
    'whiteboards' => 'Whiteboards',
    'computers' => 'Computers',
    'pianos' => 'Pianos',
];
$inventoryQuickFilterMap = [
    'fans' => ['search' => 'Fan', 'dept' => 'all'],
    'tables' => ['search' => 'Table', 'dept' => 'all'],
    'filingCabinets' => ['search' => 'Filing Cabinet', 'dept' => 'all'],
    'chairs' => ['search' => 'Chair', 'dept' => 'all'],
    'officeChairs' => ['search' => 'Office Chair', 'dept' => 'all'],
    'airConditioners' => ['search' => 'Air Conditioner', 'dept' => 'all'],
    'televisions' => ['search' => 'Television', 'dept' => 'all'],
    'shredders' => ['search' => 'Shredder', 'dept' => 'all'],
    'copyPrinters' => ['search' => 'Copy Printer', 'dept' => 'all'],
    'lockers' => ['search' => 'Locker', 'dept' => 'all'],
    'foodWarmers' => ['search' => 'Food Warmer', 'dept' => 'all'],
    'whiteboards' => ['search' => 'Whiteboard', 'dept' => 'all'],
    'computers' => ['search' => 'Computer', 'dept' => 'all'],
    'pianos' => ['search' => 'Piano', 'dept' => 'all'],
];

$inventorySources = [
    __DIR__ . '/api/data/inventory.json',
    __DIR__ . '/data/inventory.json',
];
$inv = null;
foreach ($inventorySources as $inventoryFile) {
    if (!is_file($inventoryFile)) continue;
    $invRaw = file_get_contents($inventoryFile);
    if ($invRaw === false) continue;
    $invRaw = preg_replace('/^\xEF\xBB\xBF/', '', (string)$invRaw);
    $decoded = json_decode($invRaw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $inv = $decoded;
        break;
    }
}
if (is_array($inv)) {
    $sumQty = static function ($items): int {
        if (!is_array($items)) return 0;
        $sum = 0;
        foreach ($items as $row) {
            if (is_array($row) && isset($row['qty']) && is_numeric($row['qty'])) $sum += (int)$row['qty'];
            else $sum += 1;
        }
        return $sum;
    };
    foreach ($inventorySummary as $k => $_) $inventorySummary[$k] = $sumQty($inv[$k] ?? []);
    if (isset($inv['computers']) && is_array($inv['computers'])) {
        foreach ($inv['computers'] as $row) {
            if (!is_array($row)) continue;
            $qty = (isset($row['qty']) && is_numeric($row['qty'])) ? (int)$row['qty'] : 1;
            $dept = strtoupper(trim((string)($row['department'] ?? $row['category'] ?? '')));
            if ($dept === 'ITSO') $computerByDept['ITSO'] += $qty;
            if ($dept === 'PMO')  $computerByDept['PMO']  += $qty;
        }
    }
}
$inventoryGrandTotal = array_sum($inventorySummary);
$c_total = $inventoryGrandTotal; // Total Items reflects the uploaded inventory (0 until an Excel is uploaded)

// Default to JSON "fans" quick view when DB inventory is empty on initial page load.
if (
    $db_total === 0 &&
    $jc === '' &&
    $cf === 'all' &&
    $sf === 'all' &&
    $df === 'all' &&
    trim((string)$sq) === '' &&
    is_array($inv) &&
    !empty($inv['fans'])
) {
    $jc = 'fans';
}

// JSON category quick view for table records
if ($jc !== '' && is_array($inv) && isset($inventorySummaryLabels[$jc]) && isset($inv[$jc]) && is_array($inv[$jc])) {
    $jsonRows = $inv[$jc];
    if ($df !== 'all') {
        $jsonRows = array_values(array_filter($jsonRows, function($r) use ($df) {
            $dept = strtoupper((string)($r['department'] ?? $r['category'] ?? ''));
            return $dept === strtoupper($df);
        }));
    }
    $toStatus = static function ($s): string {
        $s = strtolower(trim((string)$s));
        if ($s === 'retired') return 'retired';
        if (str_contains($s, 'under repair') || str_contains($s, 'maintenance')) return 'under_maintenance';
        if (str_contains($s, 'not working') || str_contains($s, 'broken') || str_contains($s, 'damaged') || str_contains($s, 'faded') || str_contains($s, 'fault')) return 'faulty';
        return 'operational';
    };
    $items = [];
    $catLabel = $inventorySummaryLabels[$jc] ?? ucfirst($jc);
    foreach ($jsonRows as $r) {
        if (!is_array($r)) continue;
        $status = $toStatus($r['status'] ?? 'Active');
        $condition = $status === 'faulty' ? 'poor' : ($status === 'under_maintenance' ? 'fair' : 'good');
        $qty = isset($r['qty']) && is_numeric($r['qty']) ? max(1, (int)$r['qty']) : 1;
        $nameParts = [$catLabel];
        if (!empty($r['type'])) $nameParts[] = (string)$r['type'];
        elseif (!empty($r['article'])) $nameParts[] = (string)$r['article'];
        $location = trim(implode(' | ', array_filter([
            (string)($r['campus'] ?? ''),
            (string)($r['buildingName'] ?? $r['building'] ?? ''),
            (string)($r['room'] ?? ''),
        ])));
        $dept = (string)($r['department'] ?? ($jc === 'computers' ? 'ITSO' : 'PMO'));
        $baseId = (string)($r['propertyNo'] ?? strtoupper(substr($jc, 0, 3)) . '-' . str_pad((string)($r['id'] ?? 0), 4, '0', STR_PAD_LEFT));
        $baseTag = (string)($r['propertyNo'] ?? $r['serialNo'] ?? $baseId);
        for ($n = 1; $n <= $qty; $n++) {
            $suffix = $qty > 1 ? '-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT) : '';
            $item = [
                'equipment_id' => $baseId . $suffix,
                'equipment_name' => trim(implode(' ', array_filter($nameParts))),
                'asset_tag' => $baseTag . $suffix,
                'category' => $catLabel,
                'location' => $location,
                'department' => $dept,
                'status' => $status,
                'condition' => $condition,
                'open_defects' => 0,
                'warranty_expiry' => null,
                'brand' => (string)($r['article'] ?? ''),
                'model' => (string)($r['model'] ?? ''),
                'serial_number' => (string)($r['serialNo'] ?? ''),
                'acquired' => $r['acquired'] ?? null,
                'issued' => $r['issued'] ?? null,
                'counted' => $r['counted'] ?? null,
                'remarks' => (string)($r['remarks'] ?? ''),
                'notes' => (string)($r['remarks'] ?? ''),
            ];
            if ($sq !== '') {
                $hay = strtolower(implode(' ', [
                    $item['equipment_name'], $item['asset_tag'], $item['location'],
                    $item['brand'], $item['model'], $item['notes'],
                ]));
                if (!str_contains($hay, strtolower($sq))) continue;
            }
            $items[] = $item;
        }
    }
}

/* ---- EXPORT ------------------------------------------------------------
   Placed here on purpose: $items still holds every row that matches the
   current filters. The old export read the rendered table instead, so it
   only ever contained the page you were looking at (ten rows by default),
   its Status column actually held the unit, and its Warranty column held the
   empty action buttons. Reading the records themselves fixes all three. */
$exportFmt = strtolower(trim((string)($_GET['export'] ?? '')));
if (in_array($exportFmt, ['csv', 'xlsx', 'pdf'], true)) {
    $expHeaders = ['Equipment ID', 'Equipment Name', 'Asset Tag', 'Category', 'Brand / Model',
                   'Location', 'Department', 'Unit', 'Status', 'Condition', 'Open Defects', 'Warranty Expiry'];
    $lbl = static function ($v, string $fallback = '—'): string {
        $v = trim((string)$v);
        return $v === '' ? $fallback : ucwords(str_replace('_', ' ', $v));
    };
    $expRows = [];
    foreach ($items as $e) {
        $brandModel = trim(($e['brand'] ?? '') . ' ' . ($e['model'] ?? ''));
        $warranty   = trim((string)($e['warranty_expiry'] ?? ''));
        $expRows[] = [
            (string)($e['equipment_id'] ?? ''),
            (string)($e['equipment_name'] ?? ''),
            (string)($e['asset_tag'] ?? ''),
            (string)($e['category'] ?? '') !== '' ? (string)$e['category'] : 'Uncategorized',
            $brandModel !== '' ? $brandModel : '—',
            (string)($e['location'] ?? '') !== '' ? (string)$e['location'] : 'Not specified',
            (string)($e['department'] ?? '') !== '' ? (string)$e['department'] : '—',
            strtoupper(trim((string)($e['unit'] ?? ''))) !== '' ? strtoupper(trim((string)$e['unit'])) : '—',
            $lbl($e['status'] ?? '', 'Operational'),
            $lbl($e['condition'] ?? ($e['condition_status'] ?? ''), 'Not recorded'),
            (int)($e['open_defects'] ?? 0),
            $warranty !== '' ? date('Y-m-d', strtotime($warranty)) : '—',
        ];
    }

    $expCount = static fn(string $st) => count(array_filter($items, static fn($e) => (string)($e['status'] ?? '') === $st));
    $expSummary = [
        'Total Items'       => number_format(count($items)),
        'Operational'       => number_format($expCount('operational')),
        'Under Maintenance' => number_format($expCount('under_maintenance')),
        'Faulty'            => number_format($expCount('faulty')),
        'Retired'           => number_format($expCount('retired')),
        'Open Defects'      => number_format(array_sum(array_map(static fn($e) => (int)($e['open_defects'] ?? 0), $items))),
    ];
    $expMeta = array_filter([
        'Category Filter'   => $cf !== 'all' ? $cf : '',
        'Status Filter'     => $sf !== 'all' ? ucwords(str_replace('_', ' ', $sf)) : '',
        'Department Filter' => $df !== 'all' ? $df : '',
        'Unit Filter'       => $uf !== 'all' ? $uf : '',
        'Search'            => $sq !== '' ? $sq : '',
    ]);

    if ($exportFmt === 'csv') {
        require_once __DIR__ . '/includes/csv_export.php';
        $out = becCsvOpen('bec_inventory');
        becCsvLetterhead($out, 'Equipment Inventory',
            $expMeta + ['Total Records' => number_format(count($expRows))]);
        becCsvSection($out, 'Executive Summary', ['Metric', 'Value'],
            array_map(static fn($k, $v) => [$k, $v], array_keys($expSummary), array_values($expSummary)));
        becCsvRow($out, ['EQUIPMENT RECORDS']);
        becCsvRow($out, $expHeaders);
        foreach ($expRows as $row) { becCsvRow($out, $row); }
        becCsvBlank($out);
        becCsvFooter($out, 'End of Equipment Inventory');
        fclose($out);
        exit;
    }

    if ($exportFmt === 'xlsx') {
        require_once __DIR__ . '/includes/xlsx_writer.php';
        becRenderBrandedXlsx('Equipment Inventory', $expHeaders, $expRows, $expSummary, $expMeta, 'bec_inventory', 5);
        exit;
    }

    // pdf: the shared branded letterhead, printed from the browser
    require_once __DIR__ . '/includes/export_branding.php';
    $eh = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1.0">'
       . '<title>Equipment Inventory — BEC PMO</title>'
       . '<link rel="icon" type="image/png" href="assets/logs.png">'
       . '<style>' . becExportCss() . '@page{size:A4 landscape;margin:12mm 10mm;}</style></head><body>';
    echo becExportToolbar();
    echo becExportHeader('Equipment Inventory', $expMeta + ['Total Records' => number_format(count($expRows))]);
    echo becExportSummaryCards($expSummary);
    echo '<div class="sec-label">Equipment Records</div>';
    echo '<table class="data-table"><thead><tr>';
    foreach ($expHeaders as $hd) { echo '<th>' . $eh($hd) . '</th>'; }
    echo '</tr></thead><tbody>';
    if (!$expRows) {
        echo '<tr><td colspan="' . count($expHeaders) . '" class="empty">No equipment matches the selected filters.</td></tr>';
    } else {
        $byLoc = [];
        foreach ($expRows as $row) {
            $key = trim((string)$row[5]);
            if ($key === '' || $key === '—') { $key = 'Unspecified'; }
            $byLoc[$key][] = $row;
        }
        ksort($byLoc, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($byLoc as $loc => $group) {
            echo '<tr class="grp"><td colspan="' . count($expHeaders) . '">' . $eh($loc) . ' (' . count($group) . ')</td></tr>';
            foreach ($group as $row) {
                echo '<tr>';
                foreach ($row as $cell) { echo '<td>' . $eh($cell) . '</td>'; }
                echo '</tr>';
            }
        }
    }
    echo '</tbody></table>';
    echo becExportSignatures();
    echo becExportFooter();
    echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},400);});</script>';
    echo '</body></html>';
    exit;
}

// paginate current result set (applies to DB and JSON quick view)
$total_items = count($items);
$total_pages = max(1, (int)ceil($total_items / $per));
if ($pg > $total_pages) { $pg = $total_pages; }
$offset = ($pg - 1) * $per;
$items = array_slice($items, $offset, $per);
$show_from = $total_items > 0 ? ($offset + 1) : 0;
$show_to = min($offset + count($items), $total_items);

// categories & departments for filters
if ($hasEqCategory) {
    $catSql = "SELECT DISTINCT category FROM equipment WHERE category IS NOT NULL AND category!=''";
    if ($hasEqStatus) $catSql .= " AND status!='deleted'";
    $catSql .= " ORDER BY category";
    $cats_res=$conn->query($catSql);
    $cats=$cats_res?$cats_res->fetch_all(MYSQLI_NUM):[];
} else {
    $cats=[];
}
if ($hasEqDepartment) {
    $deptSql = "SELECT DISTINCT department FROM equipment WHERE department IS NOT NULL AND department!=''";
    if ($hasEqStatus) $deptSql .= " AND status!='deleted'";
    $deptSql .= " ORDER BY department";
    $depts_res=$conn->query($deptSql);
    $depts=$depts_res?$depts_res->fetch_all(MYSQLI_NUM):[];
} else {
    $depts=[];
}

/* --- HELPERS ----------------------------------------- */
function stCls($s){return['operational'=>'s-op','under_maintenance'=>'s-maint','faulty'=>'s-fault','retired'=>'s-ret'][$s]??'s-op';}
function stLbl($s){return['operational'=>'Operational','under_maintenance'=>'Under Maintenance','faulty'=>'Faulty','retired'=>'Retired'][$s]??ucfirst(str_replace('_',' ',$s));}
function stIco($s){return['operational'=>'fas fa-check-circle','under_maintenance'=>'fas fa-tools','faulty'=>'fas fa-exclamation-triangle','retired'=>'fas fa-archive'][$s]??'fas fa-circle';}
function condCls($c){return['excellent'=>'c-ex','good'=>'c-good','fair'=>'c-fair','poor'=>'c-poor'][$c]??'c-good';}
function condLbl($c){return ucfirst($c??'-');}
function catIco($c){$m=['computer'=>'fa-desktop','laptop'=>'fa-laptop','projector'=>'fa-video','printer'=>'fa-print','scanner'=>'fa-barcode','server'=>'fa-server','network'=>'fa-network-wired','phone'=>'fa-phone','camera'=>'fa-camera','audio'=>'fa-volume-up','furniture'=>'fa-chair','vehicle'=>'fa-car','aircon'=>'fa-snowflake','other'=>'fa-box'];foreach($m as $k=>$v)if(str_contains(strtolower($c??''),$k))return 'fas '.$v;return'fas fa-box';}
function deptCls($d){$d=strtolower($d??'');if(str_contains($d,'itso')||str_contains($d,'it')||str_contains($d,'computer'))return'itso';if(str_contains($d,'pmo')||str_contains($d,'physical')||str_contains($d,'maintenance'))return'pmo';return'gen';}
function warrantyStatus($w){if(!$w)return null;$days=floor((strtotime($w)-time())/86400);if($days<0)return['expired','#DC2626',$days];if($days<=30)return['expiring',$days<=7?'#DC2626':'#D97706',$days];return['valid','#16A34A',$days];}
function esc($s){return htmlspecialchars((string)($s??''),ENT_QUOTES,'UTF-8');}
function qurl(array $params): string { return '?' . http_build_query($params); }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Inventory - BEC Admin</title>
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="css/typography.css">
<!-- SheetJS removed: the Excel export is built server-side by
     includes/xlsx_writer.php, so this 861 KB bundle no longer loads. -->
<link rel="stylesheet" href="assets/css/admin-shell.css">
<style>

/* -------------------------------------------------------
   BEC Admin - Inventory  |  Maroon - Gold - Warm
   Outfit (headings) - DM Sans (body)
------------------------------------------------------- */
:root{
  --m1:#2D0505;--m2:#4A0E0E;--m3:#7B1D1D;--m4:#9B2C2C;
  --g1:#92600A;--g2:#D4A017;--g3:#F0C040;--gp:#FEF9E7;
  --bg:#F4EFE6;--s1:#FFFFFF;--s2:#FAF7F0;--s3:#F2EAD9;
  --bdr:#E5D9C6;--bdr2:#D0C0A8;
  --t1:#1A0808;--t2:#5C3838;--t3:#9C7A7A;--t4:#C8ABAB;
  --sh0:0 1px 2px rgba(45,5,5,.05);
  --sh1:0 2px 8px rgba(45,5,5,.07),0 1px 3px rgba(45,5,5,.04);
  --sh2:0 6px 20px rgba(45,5,5,.09),0 2px 6px rgba(45,5,5,.05);
  --sh3:0 14px 40px rgba(45,5,5,.13),0 4px 10px rgba(45,5,5,.07);
  --r1:8px;--r2:12px;--r3:18px;--r4:26px;--sb:262px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--t1);min-height:100vh;overflow-x:hidden;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='400'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.022'/%3E%3C/svg%3E");}

/* -- SIDEBAR ---------------------------------------- */
/* sidebar styling lives in assets/css/admin-shell.css */
.lout i{transition:transform .3s;}.lout:hover i{transform:rotate(180deg);}

/* -- LAYOUT ----------------------------------------- */
.wrap{margin-left:var(--sb);min-height:100vh;display:flex;flex-direction:column;}
.topbar{background:rgba(255,252,245,.93);backdrop-filter:blur(14px);border-bottom:1px solid var(--bdr);
  height:58px;padding:0 1.75rem;display:flex;align-items:center;justify-content:space-between;
  position:sticky;top:0;z-index:200;box-shadow:var(--sh0);}
.tb-l{display:flex;align-items:center;gap:.55rem;}
.mob-tog{display:none;background:none;border:none;font-size:1.1rem;cursor:pointer;color:var(--t2);}
.pg-title{font-family:'Outfit',sans-serif;font-weight:700;font-size:1rem;color:var(--t1);}
.bc{font-size:.68rem;color:var(--t3);display:flex;align-items:center;gap:.25rem;}
.bc a{color:var(--t3);text-decoration:none;}.bc a:hover{color:var(--m3);}
.bc i{font-size:.55rem;}
.tb-r{display:flex;align-items:center;gap:.55rem;}
.ic-btn{width:34px;height:34px;background:var(--s2);border:1px solid var(--bdr);border-radius:var(--r1);
  display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--t2);font-size:.85rem;
  transition:all .17s;text-decoration:none;position:relative;box-shadow:none;}
.ic-btn:hover{background:var(--m3);color:#fff;transform:none;box-shadow:none;}
.pip{position:absolute;top:5px;right:5px;width:7px;height:7px;background:var(--g2);border-radius:50%;
  border:2px solid var(--s1);animation:pp 2.2s ease-in-out infinite;}
@keyframes pp{0%,100%{transform:scale(1);}50%{transform:scale(1.4);}}
.pg{padding:1.5rem 1.75rem;flex:1;}

/* Sits at the end of the "added" confirmation, so the label can be printed by
   the person still holding the equipment. */
.flash-qr{margin-left:auto;flex-shrink:0;display:inline-flex;align-items:center;gap:.4rem;
  background:var(--m3,#7B1D1D);color:#fff;border:0;border-radius:8px;padding:.45rem .85rem;
  font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer;}
.flash-qr:hover{background:var(--m2,#4A0E0E);}

/* -- FLASH ------------------------------------------ */
/* .flash lives in assets/css/admin-shell.css — one definition for every admin page. */
@keyframes fIn{from{opacity:0;transform:translateY(-5px);}to{opacity:1;transform:translateY(0);}}

/* -- BTN -------------------------------------------- */
.btn{display:inline-flex;align-items:center;gap:.32rem;padding:.4rem .875rem;border-radius:var(--r1);
  font-family:'DM Sans',sans-serif;font-size:.77rem;font-weight:700;cursor:pointer;border:none;
  transition:all .17s;text-decoration:none;white-space:nowrap;}
.btn:hover{transform:none;}.btn:active{transform:translateY(0);}
.btn-maroon{background:linear-gradient(135deg,var(--m3),var(--m4));color:#fff;box-shadow:none;}
.btn-maroon:hover{box-shadow:none;}
.btn-gold{background:linear-gradient(135deg,var(--g2),var(--g3));color:var(--m1);box-shadow:none;}
.btn-gold:hover{box-shadow:none;}
.btn-green{background:linear-gradient(135deg,var(--ok-tx),var(--ok));color:#fff;box-shadow:none;}
.btn-green:hover{box-shadow:none;}
.btn-red{background:linear-gradient(135deg,#B91C1C,var(--bad));color:#fff;box-shadow:none;}
.btn-red:hover{box-shadow:none;}
.btn-amber{background:linear-gradient(135deg,#D97706,#FBBF24);color:#fff;box-shadow:none;}
.btn-amber:hover{box-shadow:none;}
.btn-ghost{background:var(--s2);color:var(--t2);border:1px solid var(--bdr);}
.btn-ghost:hover{background:var(--s3);}
.btn-sm{padding:.3rem .65rem;font-size:.71rem;}
.bico{width:26px;height:26px;padding:0;display:flex;align-items:center;justify-content:center;border-radius:var(--r1);font-size:.7rem;}
.bi-v{background:#EFF6FF;color:#1D4ED8;}.bi-v:hover{background:#DBEAFE;}
.bi-e{background:#FFFBEB;color:#D97706;}.bi-e:hover{background:#FEF3C7;}
.bi-d{background:#FFF1F2;color:#BE123C;}.bi-d:hover{background:#FFE4E6;}

/* -- SUMMARY CARDS ---------------------------------- */
.sums{display:grid;grid-template-columns:repeat(6,1fr);gap:.7rem;margin-bottom:1.375rem;}
.scard{background:var(--s1);border-radius:var(--r3);padding:1.15rem 1.2rem;
  border:1px solid var(--bdr);position:relative;overflow:hidden;
  transition:all .26s cubic-bezier(.4,0,.2,1);box-shadow:var(--sh0);cursor:pointer;text-decoration:none;display:block;}
.scard::before{content:'';position:absolute;top:-16px;right:-16px;width:66px;height:66px;border-radius:50%;
  background:var(--sk);opacity:.04;transition:all .28s;}
.scard::after{content:'';position:absolute;bottom:0;left:0;width:100%;height:3px;background:var(--sk);
  border-radius:0 0 var(--r3) var(--r3);transform:scaleX(0);transform-origin:left;transition:transform .32s;}
.scard:hover{transform:none;box-shadow:var(--sh3);border-color:transparent;}
.scard:hover::before{transform:none;opacity:.08;}
.scard:hover::after{transform:scaleX(1);}
.sc-a{--sk:var(--m3);--skl:rgba(123,29,29,.14);}
.sc-b{--sk:var(--ok);--skl:rgba(22,163,74,.14);}
.sc-c{--sk:#D97706;--skl:rgba(217,119,6,.14);}
.sc-d{--sk:var(--bad);--skl:rgba(220,38,38,.14);}
.sc-e{--sk:#6B7280;--skl:rgba(107,114,128,.14);}
.sc-f{--sk:#C2410C;--skl:rgba(194,65,12,.14);animation:warnP 2.2s ease-in-out infinite;}
@keyframes warnP{0%,100%{box-shadow:var(--sh0);}50%{box-shadow:0 0 0 3px rgba(194,65,12,.12),var(--sh0);}}
.sico{width:36px;height:36px;border-radius:var(--r2);display:flex;align-items:center;justify-content:center;
  font-size:.84rem;margin-bottom:.5rem;background:var(--sib);color:var(--sic);
  box-shadow:none;transition:all .26s;position:relative;z-index:1;}
.scard:hover .sico{transform:none;}
.sc-a .sico{--sib:#FDECEA;--sic:var(--m3);}
.sc-b .sico{--sib:#F0FDF4;--sic:var(--ok);}
.sc-c .sico{--sib:#FFFBEB;--sic:#D97706;}
.sc-d .sico{--sib:#FFF1F2;--sic:var(--bad);animation:critP 2s ease-in-out infinite;}
@keyframes critP{0%,100%{box-shadow:none;}50%{box-shadow:0 0 14px rgba(220,38,38,.35);}}
.sc-e .sico{--sib:#F9FAFB;--sic:#6B7280;}
.sc-f .sico{--sib:#FFF7ED;--sic:#C2410C;animation:critP 2s ease-in-out infinite;}
.snum{font-family:'Outfit',sans-serif;font-size:2.2rem;font-weight:800;color:var(--t1);line-height:1;
  margin-bottom:.1rem;position:relative;z-index:1;transition:color .26s;}
.scard:hover .snum{color:var(--sk);}
.slbl{font-size:.6rem;text-transform:uppercase;letter-spacing:.8px;color:var(--t3);font-weight:700;position:relative;z-index:1;margin-top:.15rem;}
.scard{animation:scIn .3s ease both;}
.scard:nth-child(1){animation-delay:.04s;}.scard:nth-child(2){animation-delay:.08s;}
.scard:nth-child(3){animation-delay:.12s;}.scard:nth-child(4){animation-delay:.16s;}
.scard:nth-child(5){animation-delay:.20s;}.scard:nth-child(6){animation-delay:.24s;}
@keyframes scIn{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}

/* -- FILTER BAR ------------------------------------- */
.fbar{background:var(--s1);border:1px solid var(--bdr);border-radius:var(--r3);
  padding:.75rem 1.1rem;margin-bottom:1.1rem;display:flex;gap:.55rem;align-items:center;flex-wrap:wrap;box-shadow:var(--sh0);}
.fsw{position:relative;flex:1;min-width:165px;}
.fsw i{position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:var(--t3);font-size:.72rem;pointer-events:none;}
.fsi{width:100%;padding:.42rem .65rem .42rem 1.8rem;background:var(--s2);border:1.5px solid var(--bdr);
  border-radius:var(--r1);font-size:.79rem;color:var(--t1);font-family:'DM Sans',sans-serif;outline:none;transition:border-color .18s;}
.fsi:focus{border-color:var(--m3);box-shadow:0 0 0 3px rgba(123,29,29,.07);}
.fsel{padding:.42rem .65rem;background:var(--s2);border:1.5px solid var(--bdr);border-radius:var(--r1);
  font-size:.79rem;color:var(--t2);font-family:'DM Sans',sans-serif;outline:none;cursor:pointer;}
.fsel:focus{border-color:var(--m3);}
.vt{display:flex;background:var(--s2);border:1.5px solid var(--bdr);border-radius:var(--r1);padding:2px;gap:2px;}
.vt-b{display:flex;align-items:center;gap:.28rem;padding:.28rem .62rem;border-radius:6px;
  font-size:.71rem;font-weight:700;cursor:pointer;background:none;border:none;
  color:var(--t3);font-family:'DM Sans',sans-serif;transition:all .18s;}
.vt-b.on{background:var(--s1);color:var(--m3);box-shadow:var(--sh0);}

/* -- PANEL / TABLE ---------------------------------- */
.panel{background:var(--s1);border-radius:var(--r3);border:1px solid var(--bdr);
  box-shadow:var(--sh1);overflow:hidden;transition:box-shadow .22s;}
.panel:hover{box-shadow:var(--sh2);}
.ph3{padding:.875rem 1.25rem;border-bottom:1px solid var(--bdr);display:flex;align-items:center;
  justify-content:space-between;background:linear-gradient(to right,var(--s2),var(--s1));}
.ph3 h3{font-family:'Outfit',sans-serif;font-size:.9rem;font-weight:700;color:var(--t1);
  display:flex;align-items:center;gap:.35rem;margin:0;}
.ph3 h3 i{color:var(--m3);}
.tbl-scroll{max-height:62vh;overflow-y:auto;}
.tbl{width:100%;border-collapse:separate;border-spacing:0;}
.tbl thead th{padding:.5rem .9rem;font-size:.58rem;text-transform:uppercase;letter-spacing:.8px;
  color:var(--t3);font-weight:800;text-align:left;background:var(--s2);border-bottom:1.5px solid var(--bdr);white-space:nowrap;
  position:sticky;top:0;z-index:5;}
.tbl tbody td{padding:.55rem .9rem;font-size:.78rem;color:var(--t1);border-bottom:1px solid rgba(0,0,0,.045);vertical-align:middle;}
.tbl tbody tr:nth-child(even) td{background:rgba(123,29,29,.022);}
.tbl tbody tr:last-child td{border-bottom:none;}
.tbl tbody tr{transition:background .1s;}
.tbl tbody tr:hover td{background:var(--s2);}
.eid{font-family:'Outfit',sans-serif;font-weight:800;color:var(--m3);font-size:.74rem;}
.en{font-weight:700;}.esl{font-size:.63rem;color:var(--t3);} 
.pgx{display:flex;align-items:center;justify-content:space-between;gap:.7rem;flex-wrap:wrap;} 
.pgx-left{font-size:.72rem;color:var(--t3);} 
.pgx-nav,.pgx-num{min-width:34px;height:34px;padding:0 .55rem;border-radius:8px;border:1px solid var(--bdr);background:var(--s2);color:var(--t2);cursor:pointer;font-size:.78rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;line-height:1;} 
.pgx-num.on{background:linear-gradient(135deg,var(--m3),var(--m4));color:#fff;border-color:transparent;} 
.pgx-nav:disabled,.pgx-num:disabled{opacity:.45;cursor:not-allowed;} 
.pgx-ell{display:inline-flex;align-items:center;justify-content:center;min-width:24px;color:var(--t3);}

/* -- BADGES ----------------------------------------- */
.bdg{display:inline-flex;align-items:center;gap:.22rem;padding:.2rem .58rem;border-radius:20px;
  font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;}
.bdg::before{content:'';width:4px;height:4px;border-radius:50%;background:currentColor;
  flex-shrink:0;animation:dot 2.2s ease-in-out infinite;}
@keyframes dot{0%,100%{opacity:1;}50%{opacity:.4;}}
.s-op{background:#F0FDF4;color:var(--ok-tx);}
.s-maint{background:#FFFBEB;color:#D97706;}
.s-fault{background:#FFF1F2;color:var(--bad);}
.s-ret{background:var(--s2);color:var(--t3);}
.c-ex{background:#F0FDF4;color:var(--ok-tx);}
.c-good{background:#EFF6FF;color:#2563EB;}
.c-fair{background:#FFFBEB;color:#D97706;}
.c-poor{background:#FFF1F2;color:var(--bad);}
.dept-itso{display:inline-flex;align-items:center;gap:.2rem;padding:.17rem .5rem;border-radius:20px;
  font-size:.6rem;font-weight:800;background:#ECFEFF;color:#0891B2;border:1px solid #A5F3FC;}
.dept-pmo{display:inline-flex;align-items:center;gap:.2rem;padding:.17rem .5rem;border-radius:20px;
  font-size:.6rem;font-weight:800;background:#F5F3FF;color:#7C3AED;border:1px solid #DDD6FE;}
.dept-gen{display:inline-flex;align-items:center;gap:.2rem;padding:.17rem .5rem;border-radius:20px;
  font-size:.6rem;font-weight:700;background:var(--s2);color:var(--t2);border:1px solid var(--bdr);}
.defect-chip{display:inline-flex;align-items:center;gap:.22rem;padding:.17rem .5rem;border-radius:20px;
  font-size:.62rem;font-weight:800;background:#FFF7ED;color:#C2410C;}
.defect-chip.none{background:var(--s2);color:var(--t4);}

/* -- GRID VIEW -------------------------------------- */
.egrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem;padding:1rem;}
.ecard{background:var(--s1);border-radius:var(--r3);border:1.5px solid var(--bdr);
  padding:0;position:relative;overflow:hidden;
  transition:all .24s cubic-bezier(.4,0,.2,1);box-shadow:var(--sh0);}
.ecard:hover{transform:none;box-shadow:var(--sh3);border-color:transparent;}
.ec-stripe{height:5px;background:var(--stripe,var(--m3));}
.ecard.st-op .ec-stripe{background:linear-gradient(to right,var(--ok-tx),var(--ok));}
.ecard.st-maint .ec-stripe{background:linear-gradient(to right,#D97706,#FBBF24);}
.ecard.st-fault .ec-stripe{background:linear-gradient(to right,var(--bad),#F87171);}
.ecard.st-ret .ec-stripe{background:linear-gradient(to right,#9CA3AF,#D1D5DB);}
.ec-body{padding:1rem 1.1rem .875rem;}
.ec-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.625rem;}
.ec-ico{width:42px;height:42px;border-radius:var(--r2);display:flex;align-items:center;justify-content:center;
  font-size:1rem;background:var(--s2);color:var(--m3);
  box-shadow:none;flex-shrink:0;transition:transform .25s;}
.ecard:hover .ec-ico{transform:none;}
.ec-id{font-family:'Outfit',sans-serif;font-size:.66rem;font-weight:800;color:var(--m3);}
.ec-name{font-family:'Outfit',sans-serif;font-size:.95rem;font-weight:800;color:var(--t1);
  line-height:1.2;margin-bottom:.2rem;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.ec-tag{font-size:.68rem;color:var(--t3);margin-bottom:.45rem;display:flex;align-items:center;gap:.25rem;}
.ec-meta{display:flex;gap:.3rem;flex-wrap:wrap;margin-bottom:.625rem;}
.ec-loc{font-size:.7rem;color:var(--t2);display:flex;align-items:center;gap:.28rem;margin-bottom:.5rem;}
.ec-loc i{color:var(--t3);font-size:.62rem;}
.ec-acts{display:flex;gap:.3rem;border-top:1px solid var(--bdr);padding:.7rem 1.1rem .875rem;}
.ecard{animation:ecIn .3s ease both;}
@keyframes ecIn{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}

/* -- DETAIL MODAL ----------------------------------- */
.mo{position:fixed;inset:0;background:rgba(26,8,8,.6);backdrop-filter:blur(7px);
  z-index:500;display:none;align-items:flex-start;justify-content:center;
  padding:1.5rem 1rem;overflow-y:auto;}
.mo.open{display:flex;animation:moFade .18s ease;}
@keyframes moFade{from{opacity:0}to{opacity:1}}
.mw{background:var(--s1);border-radius:var(--r4);width:100%;max-width:680px;
  box-shadow:var(--sh3);animation:mUp .28s cubic-bezier(.4,0,.2,1);border:1px solid var(--bdr);margin:auto;}
.mw-sm{max-width:520px;}
@keyframes mUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.mhd{padding:1.25rem 1.5rem 1rem;
  background:linear-gradient(120deg,var(--m1) 0%,#3D0A0A 45%,var(--m3) 100%);
  border-radius:var(--r4) var(--r4) 0 0;display:flex;justify-content:space-between;
  align-items:flex-start;position:relative;overflow:hidden;}
.mhd::after{content:'';position:absolute;right:-10px;top:-10px;width:100px;height:100px;border-radius:50%;
  background:rgba(212,160,23,.08);pointer-events:none;animation:sealSpin 18s linear infinite;}
.mhd-t{position:relative;z-index:1;}
.mhd-t h2{font-family:'Outfit',sans-serif;font-size:1.05rem;font-weight:800;color:#fff;}
.mhd-t .mid{font-family:'Outfit',sans-serif;font-size:.82rem;font-weight:800;color:var(--g3);margin-top:.22rem;}
.mhd-t p{font-size:.7rem;color:rgba(255,255,255,.42);margin-top:.08rem;}
.mx{width:27px;height:27px;background:rgba(255,255,255,.1);border:none;border-radius:50%;
  color:rgba(255,255,255,.6);font-size:.82rem;cursor:pointer;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .18s;position:relative;z-index:1;}
.mx:hover{background:rgba(255,255,255,.22);color:#fff;transform:rotate(90deg);}
.mb{padding:1.375rem 1.5rem;max-height:72vh;overflow-y:auto;}
.mb::-webkit-scrollbar{width:3px;}
.mb::-webkit-scrollbar-thumb{background:var(--bdr);border-radius:3px;}
.mf{padding:.8rem 1.5rem 1.25rem;border-top:1px solid var(--bdr);
  display:flex;justify-content:flex-end;gap:.45rem;flex-wrap:wrap;
  background:var(--s2);border-radius:0 0 var(--r4) var(--r4);}

/* Detail layout */
.det-grid{display:grid;grid-template-columns:1fr 1fr;gap:.1rem;margin-bottom:1rem;}
.dr{display:flex;gap:.65rem;padding:.42rem 0;border-bottom:1px solid var(--bdr);align-items:flex-start;}
.dr:last-child{border:none;}
.dk{width:115px;flex-shrink:0;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--t3);padding-top:.08rem;}
.dv{font-size:.81rem;color:var(--t1);flex:1;line-height:1.5;}
.desc-box{background:var(--s2);border:1.5px solid var(--bdr);border-radius:var(--r1);padding:.6rem .75rem;font-size:.79rem;line-height:1.6;}

/* Warranty status bar */
.warr-bar{margin-top:.4rem;}
.warr-track{height:6px;background:var(--s3);border-radius:6px;overflow:hidden;margin-bottom:.3rem;}
.warr-fill{height:100%;border-radius:6px;transition:width .7s cubic-bezier(.4,0,.2,1);}
.warr-label{font-size:.68rem;font-weight:700;}

/* Recent defects list */
.def-list{display:flex;flex-direction:column;gap:.4rem;margin-top:.5rem;}
.def-item{background:var(--s2);border-radius:var(--r1);padding:.5rem .7rem;
  display:flex;align-items:center;justify-content:space-between;gap:.5rem;
  border-left:3px solid var(--bdr);font-size:.75rem;}
.def-item.crit{border-left-color:var(--bad);}
.def-item.hi{border-left-color:#D97706;}
.def-item.med{border-left-color:#2563EB;}
.def-item.lo{border-left-color:var(--ok);}

/* Form elements */
.fg{display:flex;flex-direction:column;gap:.28rem;margin-bottom:.7rem;}
.fl{font-size:.63rem;font-weight:800;text-transform:uppercase;letter-spacing:.65px;color:var(--t2);}
.fl span{color:var(--m3);}
.fc{padding:.5rem .82rem;background:var(--s2);border:1.5px solid var(--bdr);border-radius:var(--r1);
  font-size:.82rem;color:var(--t1);font-family:'DM Sans',sans-serif;outline:none;transition:all .18s;}
.fc:focus{border-color:var(--m3);background:var(--s1);box-shadow:0 0 0 3px rgba(123,29,29,.07);}
textarea.fc{resize:vertical;min-height:72px;}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:.625rem;}
.fg3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.625rem;}
.sec-title{font-family:'Outfit',sans-serif;font-size:.78rem;font-weight:800;color:var(--m3);
  margin:1rem 0 .5rem;display:flex;align-items:center;gap:.3rem;
  border-bottom:1.5px solid var(--bdr);padding-bottom:.38rem;}

/* -- EXPORT MENU ------------------------------------ */
.exp-drop{position:relative;}
#expMenu{display:none;position:absolute;right:0;top:calc(100% + 6px);
  background:var(--s1);border:1.5px solid var(--bdr);border-radius:var(--r2);
  box-shadow:var(--sh3);z-index:300;min-width:145px;overflow:hidden;}
.exp-opt{width:100%;padding:.6rem 1rem;background:none;border:none;text-align:left;
  font-size:.77rem;font-family:'DM Sans',sans-serif;cursor:pointer;
  display:flex;align-items:center;gap:.5rem;color:var(--t1);}
.exp-opt:hover{background:var(--s2);}
.exp-opt+.exp-opt{border-top:1px solid var(--bdr);}

/* -- TOAST / EMPTY ---------------------------------- */
/* .ttray / .tst live in assets/css/admin-shell.css — one toast for every admin page. */
.empty{text-align:center;padding:2.5rem 1.5rem;color:var(--t3);}
.empty i{font-size:2.2rem;display:block;margin-bottom:.65rem;opacity:.22;}

/* -- RESPONSIVE ------------------------------------- */
@media(max-width:1280px){.sums{grid-template-columns:repeat(3,1fr);}.fg3{grid-template-columns:1fr 1fr;}}
@media(max-width:768px){.sb{transform:translateX(-100%);}.sb.open{transform:translateX(0);}
  .wrap{margin-left:0;}.pg{padding:1rem;}.mob-tog{display:flex;}
  .sums{grid-template-columns:1fr 1fr;}.fg2{grid-template-columns:1fr;}.fg3{grid-template-columns:1fr;}.det-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>

<!-- ---- SIDEBAR -------------------------------------- -->
<?php $activeNav = 'inventory'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

<!-- ---- MAIN ------------------------------------------ -->
<div class="wrap">
  <header class="topbar">
    <div class="tb-l">
      <button class="mob-tog" onclick="document.getElementById('sb').classList.toggle('open')"><i class="fas fa-bars"></i></button>
      <div>
        <div class="pg-title">Inventory Management</div>
        <div class="bc"><a href="admin_dashboard.php"><i class="fas fa-home"></i></a><i class="fas fa-chevron-right"></i><span>Inventory</span></div>
      </div>
    </div>
    <div class="tb-r">
      <a href="admin_notifications.php" class="ic-btn"><i class="fas fa-bell"></i><span class="pip"></span></a>
      <div class="exp-drop">
        <button class="btn btn-gold btn-sm" onclick="toggleExp(event)">
          <i class="fas fa-download"></i> Export <i class="fas fa-chevron-down" style="font-size:.58rem;"></i>
        </button>
        <div id="expMenu">
          <button onclick="exportCSV()" class="exp-opt"><i class="fas fa-file-csv" style="color:var(--ok);"></i> CSV</button>
          <button onclick="exportExcel()" class="exp-opt"><i class="fas fa-file-excel" style="color:var(--ok);"></i> Excel</button>
          <button onclick="exportPDF()" class="exp-opt"><i class="fas fa-file-pdf" style="color:var(--bad);"></i> PDF</button>
        </div>
      </div>
      <button class="btn btn-maroon btn-sm" onclick="openAdd()"><i class="fas fa-plus"></i> Add Equipment</button>
    </div>
  </header>

  <div class="pg">

    <?php if(isset($_SESSION['flash'])):
            $__f = $_SESSION['flash']; unset($_SESSION['flash']);
            $ft = $__f[0] ?? 'ok'; $fm = $__f[1] ?? ''; $fq = $__f[2] ?? null; ?>
    <div class="flash <?php echo $ft;?>">
      <i class="fas fa-<?php echo $ft==='ok'?'check-circle':'exclamation-circle';?>"></i>
      <span><?php echo esc($fm); ?></span>
      <?php if ($fq): ?>
      <button type="button" class="flash-qr"
        onclick="openQR('<?php echo esc($fq['id']);?>','<?php echo esc($fq['name']);?>','<?php echo esc($fq['tag']);?>','<?php echo esc($fq['loc']);?>')">
        <i class="fas fa-qrcode"></i> Print QR label
      </button>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:1.25rem;gap:1rem;flex-wrap:wrap;">
      <div>
        <h1 style="font-family:'Outfit',sans-serif;font-size:1.45rem;font-weight:800;display:flex;align-items:center;gap:.45rem;">
          <i class="fas fa-boxes" style="color:var(--m3);"></i> Inventory
          <?php if($adminUnit!==''): ?><span style="display:inline-flex;align-items:center;gap:.35rem;padding:.2rem .58rem;border-radius:999px;background:linear-gradient(135deg,var(--m3,#7a1220),#a01a2b);color:#fff;font-family:'DM Sans',sans-serif;font-size:.6rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;"><i class="fas fa-<?php echo $adminUnit==='ITSO'?'laptop-code':'building-shield';?>" style="font-size:.58rem;"></i> <?php echo esc($adminUnit);?> Admin</span><?php endif; ?>
        </h1>
        <p style="font-size:.78rem;color:var(--t3);margin-top:.18rem;"><?php if($adminUnit!==''&&!$ufExplicit): ?>Showing <strong><?php echo esc($adminUnit);?></strong> equipment by default — use the unit filter to view All or the other unit. <?php endif; ?>Track, manage, and monitor BEC equipment by responsible unit, status, and defect history.</p>
      </div>
      <button class="btn btn-ghost btn-sm" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>

    <!-- Summary Cards -->
    <div class="sums">
      <a href="?status=all" class="scard sc-a">
        <div class="sico"><i class="fas fa-boxes"></i></div>
        <div class="snum" id="sn0"><?php echo $c_total; ?></div>
        <div class="slbl">Total Items</div>
      </a>
      <a href="?status=operational" class="scard sc-b">
        <div class="sico"><i class="fas fa-check-circle"></i></div>
        <div class="snum" id="sn1"><?php echo $c_oper; ?></div>
        <div class="slbl">Operational</div>
      </a>
      <a href="?status=under_maintenance" class="scard sc-c">
        <div class="sico"><i class="fas fa-tools"></i></div>
        <div class="snum" id="sn2"><?php echo $c_maint; ?></div>
        <div class="slbl">Under Maintenance</div>
      </a>
      <a href="?status=faulty" class="scard sc-d">
        <div class="sico"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="snum" id="sn3"><?php echo $c_fault; ?></div>
        <div class="slbl">Faulty</div>
      </a>
      <a href="?status=retired" class="scard sc-e">
        <div class="sico"><i class="fas fa-archive"></i></div>
        <div class="snum" id="sn4"><?php echo $c_retire; ?></div>
        <div class="slbl">Retired</div>
      </a>
      <a href="?warranty=expiring" class="scard sc-f">
        <div class="sico"><i class="fas fa-shield-alt"></i></div>
        <div class="snum" id="sn5"><?php echo $c_warn; ?></div>
        <div class="slbl">Warranty Expiring</div>
      </a>
    </div>

        <div class="panel" style="margin-bottom:1rem;">
      <div class="ph3" style="flex-wrap:wrap;gap:.6rem;">
        <h3><i class="fas fa-database"></i> Inventory Totals</h3>
        <form method="POST" action="inventory_functions.php" enctype="multipart/form-data" style="display:flex;align-items:center;gap:.5rem;margin:0;">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(function_exists('csrf_token') ? csrf_token() : ''); ?>">
          <input type="file" name="inventory_xlsx" accept=".xlsx" required data-premium-upload data-hint="Excel .xlsx file">
          <button type="submit" class="btn btn-green btn-sm"><i class="fas fa-file-excel"></i> Upload Inventory Excel</button>
        </form>
      </div>
      <?php if (!empty($_SESSION['inv_flash'])): $fl = $_SESSION['inv_flash']; unset($_SESSION['inv_flash']); ?>
      <div style="margin:.7rem 1rem 0;padding:.6rem .9rem;border-radius:9px;font-size:.78rem;<?php echo $fl['ok'] ? 'background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;' : 'background:#FEF2F2;border:1px solid #FECACA;color:var(--bad-tx);'; ?>">
        <i class="fas fa-<?php echo $fl['ok'] ? 'check-circle' : 'exclamation-circle'; ?>"></i> <?php echo esc($fl['msg']); ?>
      </div>
      <?php endif; ?>
      <?php if ($inventoryGrandTotal <= 0): ?>
      <div style="padding:2.2rem 1rem;text-align:center;color:var(--t3);">
        <i class="fas fa-box-open" style="font-size:1.9rem;color:var(--bdr);"></i>
        <div style="margin-top:.55rem;font-size:.92rem;font-weight:700;color:var(--t2);">No inventory uploaded yet</div>
        <div style="font-size:.77rem;margin-top:.25rem;max-width:460px;margin-left:auto;margin-right:auto;line-height:1.6;">Upload the PMO inventory Excel (the workbook with property numbers like <b>A-0825-0001</b>, <b>T-0825-0002</b>, <b>CF-0825-0100</b>) to populate the totals. Categories are counted automatically by property number.</div>
      </div>
      <?php else: ?>
      <div style="padding:.9rem 1rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.65rem;">
        <?php foreach($inventorySummary as $key=>$val):
          $qf = $inventoryQuickFilterMap[$key] ?? ['search'=>($inventorySummaryLabels[$key] ?? $key),'dept'=>'all'];
          $isActive = ($jc === $key) && (($qf['dept'] ?? 'all') === 'all' ? $df === 'all' : strtoupper((string)$df) === strtoupper((string)$qf['dept']));
        ?>
        <button type="button" onclick='applyJsonQuickFilter(<?php echo json_encode($key); ?>, <?php echo json_encode($qf['dept']); ?>)'
          style="background:<?php echo $isActive ? '#EFF6FF' : 'var(--s2)';?>;border:1px solid <?php echo $isActive ? '#BFDBFE' : 'var(--bdr)';?>;border-radius:10px;padding:.55rem .7rem;display:flex;justify-content:space-between;align-items:center;cursor:pointer;text-align:left;transition:all .16s;">
          <span style="font-size:.74rem;color:<?php echo $isActive ? '#1E3A8A' : 'var(--t2)';?>;font-weight:<?php echo $isActive ? '700' : '600';?>;"><?php echo esc($inventorySummaryLabels[$key] ?? $key); ?></span>
          <strong style="font-family:'Outfit',sans-serif;font-size:.95rem;color:<?php echo $isActive ? '#1D4ED8' : 'var(--m3)';?>;"><?php echo (int)$val; ?></strong>
        </button>
        <?php endforeach; ?>
        <?php $itsoActive = ($jc === 'computers' && strtoupper((string)$df) === 'ITSO'); ?>
        <button type="button" onclick='applyJsonQuickFilter("computers","ITSO")'
          style="background:<?php echo $itsoActive ? '#EFF6FF' : 'var(--s2)';?>;border:1px solid <?php echo $itsoActive ? '#BFDBFE' : 'var(--bdr)';?>;border-radius:10px;padding:.55rem .7rem;display:flex;justify-content:space-between;align-items:center;cursor:pointer;text-align:left;transition:all .16s;">
          <span style="font-size:.74rem;color:<?php echo $itsoActive ? '#1E3A8A' : 'var(--t2)';?>;font-weight:<?php echo $itsoActive ? '700' : '600';?>;">Computers (ITSO)</span>
          <strong style="font-family:'Outfit',sans-serif;font-size:.95rem;color:<?php echo $itsoActive ? '#1D4ED8' : 'var(--m3)';?>;"><?php echo (int)$computerByDept['ITSO']; ?></strong>
        </button>
        <?php $pmoActive = ($jc === 'computers' && strtoupper((string)$df) === 'PMO'); ?>
        <button type="button" onclick='applyJsonQuickFilter("computers","PMO")'
          style="background:<?php echo $pmoActive ? '#FFF7ED' : 'var(--s2)';?>;border:1px solid <?php echo $pmoActive ? '#FED7AA' : 'var(--bdr)';?>;border-radius:10px;padding:.55rem .7rem;display:flex;justify-content:space-between;align-items:center;cursor:pointer;text-align:left;transition:all .16s;">
          <span style="font-size:.74rem;color:<?php echo $pmoActive ? '#9A3412' : 'var(--t2)';?>;font-weight:<?php echo $pmoActive ? '700' : '600';?>;">Computers (PMO)</span>
          <strong style="font-family:'Outfit',sans-serif;font-size:.95rem;color:<?php echo $pmoActive ? '#C2410C' : 'var(--m3)';?>;"><?php echo (int)$computerByDept['PMO']; ?></strong>
        </button>
      </div>
      <?php endif; ?>
    </div>
<!-- Filter Bar -->
    <div class="fbar">
      <div class="fsw">
        <i class="fas fa-search"></i>
        <input type="text" class="fsi" id="fsq" placeholder="Search name, tag, brand, location"
          value="<?php echo esc($sq); ?>" onkeydown="if(event.key==='Enter'){event.preventDefault();go();}">
      </div>
      <select class="fsel" id="fss" onchange="go()">
        <option value="all" <?php echo $sf==='all'?'selected':'';?>>All Status</option>
        <option value="operational"       <?php echo $sf==='operational'?'selected':'';?>>Operational</option>
        <option value="under_maintenance" <?php echo $sf==='under_maintenance'?'selected':'';?>>Under Maintenance</option>
        <option value="faulty"            <?php echo $sf==='faulty'?'selected':'';?>>Faulty</option>
        <option value="retired"           <?php echo $sf==='retired'?'selected':'';?>>Retired</option>
      </select>
      <select class="fsel" id="fsc" onchange="go()">
        <option value="all" <?php echo $cf==='all'?'selected':'';?>>All Categories</option>
        <?php foreach($cats as [$cat]):?>
        <option value="<?php echo esc($cat);?>" <?php echo $cf===$cat?'selected':'';?>><?php echo esc($cat);?></option>
        <?php endforeach;?>
      </select>
      <select class="fsel" id="fsd" onchange="go()">
        <option value="all" <?php echo $df==='all'?'selected':'';?>>All Departments</option>
        <?php foreach($depts as [$dept]):?>
        <option value="<?php echo esc($dept);?>" <?php echo $df===$dept?'selected':'';?>><?php echo esc($dept);?></option>
        <?php endforeach;?>
      </select>
      <?php if($hasEqUnit): ?>
      <select class="fsel" id="fsu" onchange="go()" title="Responsible unit (PMO/ITSO)">
        <option value="all"  <?php echo $uf==='all'?'selected':'';?>>All Units</option>
        <option value="ITSO" <?php echo $uf==='ITSO'?'selected':'';?>>ITSO</option>
        <option value="PMO"  <?php echo $uf==='PMO'?'selected':'';?>>PMO</option>
      </select>
      <?php endif; ?>
      <div class="vt">
        <button class="vt-b" id="vt-tbl" onclick="setView('table')"><i class="fas fa-list"></i> Table</button>
        <button class="vt-b" id="vt-grid" onclick="setView('grid')"><i class="fas fa-th-large"></i> Grid</button>
      </div>
      <select class="fsel" id="fper" onchange="go()">
        <option value="10" <?php echo $per===10?'selected':'';?>>10 / page</option>
        <option value="20" <?php echo $per===20?'selected':'';?>>20 / page</option>
        <option value="50" <?php echo $per===50?'selected':'';?>>50 / page</option>
        <option value="100" <?php echo $per===100?'selected':'';?>>100 / page</option>
      </select>
      <span style="font-size:.7rem;color:var(--t3);white-space:nowrap;">Showing <?php echo $show_from;?>-<?php echo $show_to;?> of <?php echo $total_items;?> item<?php echo $total_items!=1?'s':'';?></span>
    </div>

    <!-- ---- TABLE VIEW ---- -->
    <div class="panel" id="tableView">
      <div class="ph3">
        <h3><i class="fas fa-list-alt"></i> Equipment Records</h3>
        <div style="display:flex;gap:.4rem;">
          <button class="btn btn-ghost btn-sm" onclick="exportCSV()"><i class="fas fa-file-csv"></i> CSV</button>
          <button class="btn btn-maroon btn-sm" onclick="openAdd()"><i class="fas fa-plus"></i> Add Equipment</button>
        </div>
      </div>
      <div class="tbl-scroll">
      <table class="tbl" id="invTbl">
        <thead>
          <tr>
            <th>ID</th><th>Equipment</th><th>Asset Tag</th><th>Category</th>
            <th>Location</th><th>Department</th><th>Unit</th><th>Status</th>
            <th>Open Defects</th><th style="text-align:center;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($items)):?>
          <tr><td colspan="10"><div class="empty"><i class="fas fa-box-open"></i>No equipment found. Add some with the button above.</div></td></tr>
          <?php else: foreach($items as $e):
            $ws = warrantyStatus($e['warranty_expiry']??null);
            $od = (int)($e['open_defects']??0);
            $dc = deptCls($e['department']??'');
          ?>
          <tr onclick="openDetailModal(<?php echo htmlspecialchars(json_encode($e),ENT_QUOTES);?>)" style="cursor:pointer;">
            <td><span class="eid"><?php echo esc($e['equipment_id']);?></span></td>
            <td>
              <div class="en"><?php echo esc($e['equipment_name']);?></div>
              <?php if(!empty($e['brand'])||!empty($e['model'])):?>
              <div class="esl"><?php echo esc(trim(($e['brand']??'').' '.($e['model']??'')));?></div>
              <?php endif;?>
            </td>
            <td style="font-family:'Outfit',sans-serif;font-weight:700;font-size:.78rem;"><?php echo esc($e['asset_tag']);?></td>
            <td style="font-size:.75rem;"><?php echo esc($e['category']??'-');?></td>
            <td>
              <div style="font-size:.77rem;display:flex;align-items:center;gap:.25rem;">
                <i class="fas fa-map-marker-alt" style="color:var(--t3);font-size:.62rem;"></i>
                <?php echo esc($e['location']??'-');?>
              </div>
            </td>
            <td>
              <?php $dc=deptCls($e['department']??'');
              if(!empty($e['department'])):
                if($dc==='itso') echo '<span class="dept-itso"><i class="fas fa-laptop-code"></i>'.esc($e['department']).'</span>';
                elseif($dc==='pmo') echo '<span class="dept-pmo"><i class="fas fa-building"></i>'.esc($e['department']).'</span>';
                else echo '<span class="dept-gen">'.esc($e['department']).'</span>';
              else: echo '<span style="color:var(--t4)">-</span>';
              endif;?>
            </td>
            <td>
              <?php $u=strtoupper(trim((string)($e['unit']??'')));
                if($u==='ITSO') echo '<span class="dept-itso"><i class="fas fa-laptop-code"></i>ITSO</span>';
                elseif($u==='PMO') echo '<span class="dept-pmo"><i class="fas fa-building"></i>PMO</span>';
                else echo '<span style="color:var(--t4)">-</span>';?>
            </td>
            <td><span class="bdg <?php echo stCls($e['status']);?>"><?php echo stLbl($e['status']);?></span></td>
            <td style="text-align:center;">
              <?php if($od>0):?>
              <span class="defect-chip"><i class="fas fa-exclamation-triangle"></i><?php echo $od;?></span>
              <?php else:?>
              <span class="defect-chip none">0</span>
              <?php endif;?>
            </td>
            <td style="text-align:center;">
              <div style="display:flex;gap:.25rem;justify-content:center;">
                <button class="btn bico bi-v" title="View Details"
                  onclick="event.stopPropagation();openDetailModal(<?php echo htmlspecialchars(json_encode($e),ENT_QUOTES);?>)">
                  <i class="fas fa-eye"></i>
                </button>
                <button class="btn bico bi-e" title="Edit"
                  onclick="event.stopPropagation();openEdit(<?php echo htmlspecialchars(json_encode($e),ENT_QUOTES);?>)">
                  <i class="fas fa-pen"></i>
                </button>
                <button class="btn bico bi-e" title="Print QR code"
                  onclick="event.stopPropagation();openQR('<?php echo esc($e['equipment_id']);?>','<?php echo esc($e['equipment_name']);?>','<?php echo esc($e['asset_tag']??'');?>','<?php echo esc($e['location']??'');?>')">
                  <i class="fas fa-qrcode"></i>
                </button>
                <button class="btn bico bi-d" title="Retire / Delete"
                  onclick="event.stopPropagation();openRetire('<?php echo esc($e['equipment_id']);?>','<?php echo esc($e['equipment_name']);?>')">
                  <i class="fas fa-archive"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif;?>
        </tbody>
      </table>
      </div>
    </div>

    <!-- ---- GRID VIEW ---- -->
    <div id="gridView" style="display:none;">
      <div class="egrid">
        <?php if(empty($items)):?>
        <div style="grid-column:1/-1;"><div class="empty"><i class="fas fa-box-open"></i>No equipment found.</div></div>
        <?php else: foreach($items as $i=>$e):
          $od=(int)($e['open_defects']??0);
          $ws=warrantyStatus($e['warranty_expiry']??null);
          $stClass=['operational'=>'st-op','under_maintenance'=>'st-maint','faulty'=>'st-fault','retired'=>'st-ret'][$e['status']??'']??'st-op';
        ?>
        <div class="ecard <?php echo $stClass;?>" style="animation-delay:<?php echo min($i,25)*.04;?>s;">
          <div class="ec-stripe"></div>
          <div class="ec-body">
            <div class="ec-top">
              <div class="ec-ico"><i class="<?php echo catIco($e['category']??'');?>"></i></div>
              <span class="bdg <?php echo stCls($e['status']);?>"><?php echo stLbl($e['status']);?></span>
            </div>
            <div class="ec-id"><?php echo esc($e['equipment_id']);?></div>
            <div class="ec-name"><?php echo esc($e['equipment_name']);?></div>
            <div class="ec-tag"><i class="fas fa-tag" style="font-size:.6rem;color:var(--t3);"></i><?php echo esc($e['asset_tag']);?></div>
            <div class="ec-meta">
              <?php $gu=strtoupper(trim((string)($e['unit']??'')));
                if($gu==='ITSO') echo '<span class="dept-itso"><i class="fas fa-laptop-code"></i>ITSO</span>';
                elseif($gu==='PMO') echo '<span class="dept-pmo"><i class="fas fa-building"></i>PMO</span>'; ?>
              <?php if($od>0):?><span class="defect-chip"><i class="fas fa-exclamation-triangle"></i><?php echo $od;?> defect<?php echo $od!=1?'s':'';?></span><?php endif;?>
            </div>
            <div class="ec-loc"><i class="fas fa-map-marker-alt"></i><?php echo esc($e['location']??'Unknown location');?></div>
            <?php if($ws && $ws[0]!=='valid'):[$wst,$wcol,$wdays]=$ws;?>
            <div style="font-size:.68rem;font-weight:700;color:<?php echo $wcol;?>;margin-bottom:.3rem;">
              <i class="fas fa-shield-alt"></i>
              <?php echo $wst==='expired'?'Warranty expired '.abs($wdays).'d ago':'Warranty expires in '.$wdays.'d';?>
            </div>
            <?php endif;?>
          </div>
          <div class="ec-acts">
            <button class="btn btn-ghost btn-sm" style="flex:1;justify-content:center;font-size:.7rem;"
              onclick="openDetailModal(<?php echo htmlspecialchars(json_encode($e),ENT_QUOTES);?>)">
              <i class="fas fa-eye"></i> View
            </button>
            <button class="btn btn-gold btn-sm"
              onclick="openEdit(<?php echo htmlspecialchars(json_encode($e),ENT_QUOTES);?>)">
              <i class="fas fa-pen"></i>
            </button>
            <button class="btn btn-ghost btn-sm" title="Print QR code"
              onclick="openQR('<?php echo esc($e['equipment_id']);?>','<?php echo esc($e['equipment_name']);?>','<?php echo esc($e['asset_tag']??'');?>','<?php echo esc($e['location']??'');?>')">
              <i class="fas fa-qrcode"></i>
            </button>
            <button class="btn btn-ghost btn-sm"
              onclick="openRetire('<?php echo esc($e['equipment_id']);?>','<?php echo esc($e['equipment_name']);?>')">
              <i class="fas fa-archive"></i>
            </button>
          </div>
        </div>
        <?php endforeach; endif;?>
      </div>
    </div>

    <?php if($total_pages > 1): ?>
    <div class="panel" id="invPgWrap" style="margin-top:.85rem;padding:.7rem 1rem;">
      <form method="get" action="admin_inventory.php#invPgWrap" class="pgx">
        <input type="hidden" name="status" value="<?php echo esc($sf);?>">
        <input type="hidden" name="category" value="<?php echo esc($cf);?>">
        <input type="hidden" name="dept" value="<?php echo esc($df);?>">
        <input type="hidden" name="unit" value="<?php echo esc($uf);?>">
        <input type="hidden" name="search" value="<?php echo esc($sq);?>">
        <input type="hidden" name="jc" value="<?php echo esc($jc);?>">
        <input type="hidden" name="per_page" value="<?php echo (int)$per;?>">
        <div class="pgx-left">Page <?php echo $pg;?> of <?php echo $total_pages;?></div>
        <div style="display:flex;gap:.35rem;align-items:center;flex-wrap:wrap;">
          <button type="submit" class="pgx-nav" name="page" value="1" <?php echo $pg<=1?'disabled':'';?> title="First"><i class="fas fa-angles-left"></i></button>
          <button type="submit" class="pgx-nav" name="page" value="<?php echo max(1,$pg-1);?>" <?php echo $pg<=1?'disabled':'';?> title="Previous"><i class="fas fa-chevron-left"></i></button>
          <?php
            $ps = max(1, $pg - 2);
            $pe = min($total_pages, $pg + 2);
            if ($ps > 1):
          ?>
            <button type="submit" class="pgx-num" name="page" value="1">1</button>
            <?php if ($ps > 2): ?><span class="pgx-ell">...</span><?php endif; ?>
          <?php endif; ?>
          <?php for($p=$ps;$p<=$pe;$p++): ?>
            <button type="submit" class="pgx-num <?php echo $p===$pg?'on':'';?>" name="page" value="<?php echo $p;?>" <?php echo $p===$pg?'disabled':'';?>><?php echo $p;?></button>
          <?php endfor; ?>
          <?php if ($pe < $total_pages): ?>
            <?php if ($pe < $total_pages - 1): ?><span class="pgx-ell">...</span><?php endif; ?>
            <button type="submit" class="pgx-num" name="page" value="<?php echo $total_pages;?>"><?php echo $total_pages;?></button>
          <?php endif; ?>
          <button type="submit" class="pgx-nav" name="page" value="<?php echo min($total_pages,$pg+1);?>" <?php echo $pg>=$total_pages?'disabled':'';?> title="Next"><i class="fas fa-chevron-right"></i></button>
          <button type="submit" class="pgx-nav" name="page" value="<?php echo $total_pages;?>" <?php echo $pg>=$total_pages?'disabled':'';?> title="Last"><i class="fas fa-angles-right"></i></button>
        </div>
      </form>
    </div>
    <?php endif;?>

  </div><!-- /pg -->
</div><!-- /wrap -->

<!-- ---- ADD EQUIPMENT MODAL -------------------------- -->
<div class="mo" id="addMo" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="mw">
    <div class="mhd">
      <div class="mhd-t">
        <h2><i class="fas fa-plus-circle" style="margin-right:.3rem;opacity:.8;"></i> Add Equipment</h2>
        <p>Register a new item in the BEC inventory.</p>
      </div>
      <button class="mx" onclick="document.getElementById('addMo').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="mb">
      <form method="POST" action="admin_inventory.php" id="addForm">
        <input type="hidden" name="action" value="add">
        <div class="sec-title"><i class="fas fa-info-circle"></i> Basic Information</div>
        <div class="fg2">
          <div class="fg"><label class="fl">Equipment Name <span>*</span></label>
            <input type="text" name="equipment_name" class="fc" placeholder="e.g. HP LaserJet Pro" maxlength="120" required></div>
          <div class="fg"><label class="fl">Asset Tag <span>*</span></label>
            <input type="text" name="asset_tag" class="fc" placeholder="e.g. BEC-PRN-001" maxlength="40" required></div>
        </div>
        <div class="fg3">
          <div class="fg"><label class="fl">Category</label>
            <select name="category" class="fc">
              <option value="">Select-</option>
              <option>Computer</option><option>Laptop</option><option>Projector</option>
              <option>Printer</option><option>Scanner</option><option>Server</option>
              <option>Network Equipment</option><option>Phone</option><option>Camera</option>
              <option>Audio / AV</option><option>Air Conditioner</option>
              <option>Furniture</option><option>Vehicle</option><option>Other</option>
            </select>
          </div>
          <div class="fg"><label class="fl">Department</label>
            <select name="department" class="fc">
              <option value="">Select-</option>
              <option value="ITSO">ITSO</option><option value="PMO">PMO</option>
              <option value="CCS">CCS</option><option value="Administration">Administration</option>
              <option value="Library">Library</option><option value="Finance">Finance</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="fg"><label class="fl">Location <span>*</span></label>
            <input type="text" name="location" class="fc" placeholder="e.g. Room 201, Lab 3" maxlength="150" required></div>
        </div>
        <div class="sec-title"><i class="fas fa-clipboard-check"></i> Status</div>
        <div class="fg"><label class="fl">Status</label>
          <select name="status" class="fc">
            <option value="operational">Operational</option>
            <option value="under_maintenance">Under Maintenance</option>
            <option value="faulty">Faulty</option>
            <option value="retired">Retired</option>
          </select>
        </div>
        <div class="fg"><label class="fl">Notes</label>
          <textarea name="notes" class="fc" placeholder="Additional notes about this equipment-"></textarea></div>
      </form>
    </div>
    <div class="mf">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('addMo').classList.remove('open')">Cancel</button>
      <button type="submit" form="addForm" class="btn btn-green btn-sm"><i class="fas fa-plus-circle"></i> Add to Inventory</button>
    </div>
  </div>
</div>

<!-- ---- EDIT MODAL ------------------------------------ -->
<div class="mo" id="editMo" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="mw">
    <div class="mhd">
      <div class="mhd-t">
        <h2><i class="fas fa-pen" style="margin-right:.3rem;opacity:.8;"></i> Edit Equipment</h2>
        <p id="editSub">Update item details.</p>
      </div>
      <button class="mx" onclick="document.getElementById('editMo').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="mb">
      <form method="POST" action="admin_inventory.php" id="editForm">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="equipment_id" id="eEid">
        <div class="sec-title"><i class="fas fa-info-circle"></i> Basic Information</div>
        <div class="fg2">
          <div class="fg"><label class="fl">Equipment Name <span>*</span></label>
            <input type="text" name="equipment_name" id="eEname" class="fc" maxlength="120" required></div>
          <div class="fg"><label class="fl">Asset Tag <span>*</span></label>
            <input type="text" name="asset_tag" id="eEtag" class="fc" maxlength="40" required></div>
        </div>
        <div class="fg3">
          <div class="fg"><label class="fl">Category</label>
            <select name="category" id="eEcat" class="fc">
              <option value="">Select-</option>
              <option>Computer</option><option>Laptop</option><option>Projector</option>
              <option>Printer</option><option>Scanner</option><option>Server</option>
              <option>Network Equipment</option><option>Phone</option><option>Camera</option>
              <option>Audio / AV</option><option>Air Conditioner</option>
              <option>Furniture</option><option>Vehicle</option><option>Other</option>
            </select>
          </div>
          <div class="fg"><label class="fl">Department</label>
            <select name="department" id="eEdept" class="fc">
              <option value="">Select-</option>
              <option value="ITSO">ITSO</option><option value="PMO">PMO</option>
              <option value="CCS">CCS</option><option value="Administration">Administration</option>
              <option value="Library">Library</option><option value="Finance">Finance</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="fg"><label class="fl">Location <span>*</span></label>
            <input type="text" name="location" id="eEloc" class="fc" maxlength="150" required></div>
        </div>
        <div class="sec-title"><i class="fas fa-clipboard-check"></i> Status</div>
        <div class="fg"><label class="fl">Status</label>
          <select name="status" id="eEst" class="fc">
            <option value="operational">Operational</option>
            <option value="under_maintenance">Under Maintenance</option>
            <option value="faulty">Faulty</option>
            <option value="retired">Retired</option>
          </select>
        </div>
        <div class="fg"><label class="fl">Notes</label><textarea name="notes" id="eEnotes" class="fc"></textarea></div>
      </form>
    </div>
    <div class="mf">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('editMo').classList.remove('open')">Cancel</button>
      <button type="submit" form="editForm" class="btn btn-maroon btn-sm"><i class="fas fa-save"></i> Save Changes</button>
    </div>
  </div>
</div>

<!-- ---- RETIRE CONFIRM MODAL ------------------------- -->
<div class="mo" id="retireMo" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="mw mw-sm">
    <div class="mhd">
      <div class="mhd-t">
        <h2><i class="fas fa-archive" style="margin-right:.3rem;opacity:.8;"></i> Retire Equipment</h2>
        <p>Choose an action for this item.</p>
      </div>
      <button class="mx" onclick="document.getElementById('retireMo').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="mb">
      <p style="font-size:.83rem;color:var(--t2);margin-bottom:1rem;line-height:1.6;">
        You are about to act on: <strong id="rName" style="color:var(--t1);">-</strong>
      </p>
      <div style="display:flex;flex-direction:column;gap:.65rem;">
        <div style="background:#FFFBEB;border:1.5px solid #FDE68A;border-radius:var(--r2);padding:.875rem 1rem;display:flex;gap:.75rem;align-items:flex-start;">
          <i class="fas fa-archive" style="color:#D97706;font-size:1.1rem;flex-shrink:0;margin-top:.1rem;"></i>
          <div>
            <div style="font-weight:700;font-size:.83rem;margin-bottom:.18rem;">Retire Equipment</div>
            <div style="font-size:.75rem;color:var(--t2);line-height:1.5;">Mark as retired - keeps the record, removes it from active inventory.</div>
            <form method="POST" action="admin_inventory.php" style="margin-top:.5rem;">
              <input type="hidden" name="action" value="retire">
              <input type="hidden" name="equipment_id" id="rEid">
              <button type="submit" class="btn btn-amber btn-sm"><i class="fas fa-archive"></i> Retire</button>
            </form>
          </div>
        </div>
        <div style="background:#FFF1F2;border:1.5px solid #FECDD3;border-radius:var(--r2);padding:.875rem 1rem;display:flex;gap:.75rem;align-items:flex-start;">
          <i class="fas fa-trash" style="color:var(--bad);font-size:1.1rem;flex-shrink:0;margin-top:.1rem;"></i>
          <div>
            <div style="font-weight:700;font-size:.83rem;margin-bottom:.18rem;">Delete Record</div>
            <div style="font-size:.75rem;color:var(--t2);line-height:1.5;">Permanently remove from inventory. Cannot be undone.</div>
            <form method="POST" action="admin_inventory.php" style="margin-top:.5rem;">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="equipment_id" id="dEid">
              <button type="submit" class="btn btn-red btn-sm" onclick="return confirm('Delete this item? This cannot be undone.')"><i class="fas fa-trash"></i> Delete</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <div class="mf">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('retireMo').classList.remove('open')">Cancel</button>
    </div>
  </div>
</div>

<!-- ---- LOGOUT MODAL ---------------------------------- -->
<div class="mo" id="lgmo" onclick="if(event.target===this)this.classList.remove('open')">
  <div style="background:var(--s1);border-radius:var(--r4);padding:2rem;max-width:330px;
    width:90%;text-align:center;box-shadow:var(--sh3);animation:mUp .25s ease;margin:auto;">
    <i class="fas fa-sign-out-alt" style="font-size:2.2rem;color:var(--m3);margin-bottom:.7rem;display:block;"></i>
    <h3 style="font-family:'Outfit',sans-serif;font-size:1.05rem;font-weight:800;margin-bottom:.38rem;">Log Out?</h3>
    <p style="font-size:.8rem;color:var(--t2);margin-bottom:1.25rem;line-height:1.6;">You will be returned to the BEC admin login page.</p>
    <div style="display:flex;gap:.55rem;justify-content:center;">
      <button onclick="document.getElementById('lgmo').classList.remove('open')" class="btn btn-ghost btn-sm">Cancel</button>
      <a href="logout.php" class="btn btn-maroon btn-sm"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>
  </div>
</div>

<div class="mo" id="detMoLive" onclick="if(event.target===this)closeDetLive()">
  <div class="mw">
    <div class="mhd">
      <div class="mhd-t">
        <h2><i class="fas fa-box-open" style="margin-right:.3rem;opacity:.8;"></i>Equipment Details</h2>
        <div class="mid" id="detLiveId">-</div>
        <p id="detLiveName">-</p>
      </div>
      <button class="mx" onclick="closeDetLive()"><i class="fas fa-times"></i></button>
    </div>
    <div class="mb">
      <div class="det-grid">
        <div>
          <div class="dr"><div class="dk">Asset Tag</div><div class="dv" id="detLiveTag">-</div></div>
          <div class="dr"><div class="dk">Category</div><div class="dv" id="detLiveCategory">-</div></div>
          <div class="dr"><div class="dk">Brand / Model</div><div class="dv" id="detLiveBrandModel">-</div></div>
          <div class="dr"><div class="dk">Serial No.</div><div class="dv" id="detLiveSerial">-</div></div>
          <div class="dr"><div class="dk">Location</div><div class="dv" id="detLiveLocation">-</div></div>
          <div class="dr"><div class="dk">Department</div><div class="dv" id="detLiveDept">-</div></div>
        </div>
        <div>
          <div class="dr"><div class="dk">Status</div><div class="dv" id="detLiveStatus">-</div></div>
          <div class="dr"><div class="dk">Condition</div><div class="dv" id="detLiveCondition">-</div></div>
          <div class="dr"><div class="dk">Purchase Date</div><div class="dv" id="detLivePDate">-</div></div>
          <div class="dr"><div class="dk">Purchase Price</div><div class="dv" id="detLivePrice">-</div></div>
          <div class="dr"><div class="dk">Warranty Exp.</div><div class="dv" id="detLiveWarr">-</div></div>
          <div class="dr"><div class="dk">Total Defects</div><div class="dv" id="detLiveDefects">0</div></div>
          <div class="dr"><div class="dk">Acquired</div><div class="dv" id="detLiveAcquired">-</div></div>
          <div class="dr"><div class="dk">Issued</div><div class="dv" id="detLiveIssued">-</div></div>
          <div class="dr"><div class="dk">Counted</div><div class="dv" id="detLiveCounted">-</div></div>
          <div class="dr"><div class="dk">Remarks</div><div class="dv" id="detLiveRemarks">-</div></div>
        </div>
      </div>
      <div style="margin-top:1rem;" id="detLiveNotesWrap" hidden>
        <div class="sec-title"><i class="fas fa-sticky-note"></i> Notes</div>
        <div class="desc-box" id="detLiveNotes"></div>
      </div>
    </div>
    <div class="mf">
      <button class="btn btn-ghost btn-sm" onclick="closeDetLive()">Close</button>
      <button class="btn btn-amber btn-sm" id="detLiveRetireBtn"><i class="fas fa-archive"></i> Retire</button>
      <button class="btn btn-maroon btn-sm" id="detLiveEditBtn"><i class="fas fa-pen"></i> Edit</button>
    </div>
  </div>
</div>

<div class="ttray" id="ttray"></div>

<script>
const isJsonQuickView = <?php echo $jc !== '' ? 'true' : 'false'; ?>;
/* --- FILTER / SEARCH ---------------------------------- */
function go(){
  const u=new URL(location.href);
  u.searchParams.set('status',   document.getElementById('fss').value);
  u.searchParams.set('category', document.getElementById('fsc').value);
  u.searchParams.set('dept',     document.getElementById('fsd').value);
  const fsu=document.getElementById('fsu'); if(fsu) u.searchParams.set('unit', fsu.value);
  u.searchParams.set('search',   document.getElementById('fsq').value);
  u.searchParams.set('per_page', document.getElementById('fper').value);
  u.searchParams.set('page',     '1');
  // becListNav(): shared in includes/admin_ui.php. Guards against re-running
  // the same URL and against a second click on an in-flight navigation.
  becListNav(u.toString());
}
function applyJsonQuickFilter(jsonCategory, dept){
  const u = new URL(location.href);
  u.searchParams.set('jc', jsonCategory || '');
  u.searchParams.set('status', 'all');
  u.searchParams.set('category', 'all');
  u.searchParams.set('search', '');
  u.searchParams.set('dept', (dept && dept !== '') ? dept : 'all');
  u.searchParams.set('per_page', document.getElementById('fper').value);
  u.searchParams.set('page', '1');
  becListNav(u.toString());
}

/* --- VIEW TOGGLE -------------------------------------- */
let _view=localStorage.getItem('bec_invview')||'table';
function setView(v){
  _view=v; localStorage.setItem('bec_invview',v);
  document.getElementById('tableView').style.display=v==='table'?'block':'none';
  document.getElementById('gridView').style.display=v==='grid'?'block':'none';
  document.getElementById('vt-tbl').classList.toggle('on',v==='table');
  document.getElementById('vt-grid').classList.toggle('on',v==='grid');
}
document.addEventListener('DOMContentLoaded',()=>setView(_view));

/* --- DETAIL MODAL ------------------------------------- */

function setSelVal(id,val){
  const s=document.getElementById(id);
  if(!s)return;
  for(const o of s.options){if(o.value===val){s.value=val;return;}}
  // try text match
  for(const o of s.options){if(o.textContent.trim()===val){s.value=o.value;return;}}
}
function closeDet(){ const m=document.getElementById('detMo'); if(m) m.classList.remove('open'); }
function closeDetLive(){ const m=document.getElementById('detMoLive'); if(m) m.classList.remove('open'); }
function openAdd(){ const m=document.getElementById('addMo'); if(m) m.classList.add('open'); }
function fmtDate(v){
  if(!v) return '-';
  const d = new Date(v);
  if(isNaN(d.getTime())) return String(v);
  return d.toLocaleDateString(undefined,{year:'numeric',month:'short',day:'numeric'});
}
function fmtMoney(v){
  const n = parseFloat(v);
  if(!isFinite(n)) return '-';
  return n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
}
function escapeHtml(v){
  return String(v===undefined||v===null?'':v)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/\"/g,'&quot;').replace(/'/g,'&#39;');
}
function stLabel(s){
  if(s==='under_maintenance') return 'Under Maintenance';
  if(s==='faulty') return 'Faulty';
  if(s==='retired') return 'Retired';
  return 'Operational';
}
function stClass(s){
  if(s==='under_maintenance') return 's-maint';
  if(s==='faulty') return 's-fault';
  if(s==='retired') return 's-ret';
  return 's-op';
}
function condLabel(c){
  if(!c) return '-';
  return c.charAt(0).toUpperCase()+c.slice(1);
}
function condClass(c){
  if(c==='excellent') return 'c-ex';
  if(c==='fair') return 'c-fair';
  if(c==='poor') return 'c-poor';
  return 'c-good';
}
function openDetailModal(e){
  document.getElementById('detLiveId').textContent = e.equipment_id || '-';
  document.getElementById('detLiveName').textContent = e.equipment_name || '-';
  document.getElementById('detLiveTag').textContent = e.asset_tag || '-';
  document.getElementById('detLiveCategory').textContent = e.category || '-';
  const bm = ((e.brand||'') + ' ' + (e.model||'')).trim();
  document.getElementById('detLiveBrandModel').textContent = bm || '-';
  document.getElementById('detLiveSerial').textContent = e.serial_number || '-';
  document.getElementById('detLiveLocation').textContent = e.location || '-';
  document.getElementById('detLiveDept').textContent = e.department || '-';
  document.getElementById('detLiveStatus').innerHTML = '<span class="bdg '+stClass(e.status)+'">'+stLabel(e.status)+'</span>';
  document.getElementById('detLiveCondition').innerHTML = '<span class="bdg '+condClass(e.condition)+'">'+condLabel(e.condition||'good')+'</span>';
  document.getElementById('detLivePDate').textContent = fmtDate(e.purchase_date);
  document.getElementById('detLivePrice').textContent = (e.purchase_price!==undefined && e.purchase_price!==null && e.purchase_price!=='') ? ('?'+fmtMoney(e.purchase_price)) : '-';
  document.getElementById('detLiveWarr').textContent = fmtDate(e.warranty_expiry);
  document.getElementById('detLiveDefects').textContent = String(parseInt(e.open_defects || 0, 10));
  document.getElementById('detLiveAcquired').textContent = (e.acquired!==undefined && e.acquired!==null && String(e.acquired).trim()!=='') ? String(e.acquired) : '-';
  document.getElementById('detLiveIssued').textContent = (e.issued!==undefined && e.issued!==null && String(e.issued).trim()!=='') ? String(e.issued) : '-';
  document.getElementById('detLiveCounted').textContent = (e.counted!==undefined && e.counted!==null && String(e.counted).trim()!=='') ? String(e.counted) : '-';
  document.getElementById('detLiveRemarks').textContent = (e.remarks!==undefined && e.remarks!==null && String(e.remarks).trim()!=='') ? String(e.remarks) : ((e.notes!==undefined && e.notes!==null && String(e.notes).trim()!=='') ? String(e.notes) : '-');
  const nw=document.getElementById('detLiveNotesWrap');
  const nv=document.getElementById('detLiveNotes');
  const n=((e.notes||e.remarks)||'').trim();
  if(n){ nw.hidden=false; nv.innerHTML=escapeHtml(n).replace(/\n/g,'<br>'); }
  else { nw.hidden=true; nv.textContent=''; }
  document.getElementById('detLiveEditBtn').onclick=function(){ closeDetLive(); openEdit(e); };
  document.getElementById('detLiveRetireBtn').onclick=function(){ closeDetLive(); openRetire(e.equipment_id||'', e.equipment_name||''); };
  document.getElementById('detMoLive').classList.add('open');
}
function openEdit(e){
  if (isJsonQuickView) {
    toast('err','This item comes from JSON quick view and is not editable in equipment table.','Edit Not Available');
    return;
  }
  document.getElementById('eEid').value=e.equipment_id||'';
  document.getElementById('eEname').value=e.equipment_name||'';
  document.getElementById('eEtag').value=e.asset_tag||'';
  setSelVal('eEcat',e.category||'');
  document.getElementById('eEloc').value=e.location||'';
  setSelVal('eEdept',e.department||'');
  setSelVal('eEst',e.status||'operational');
  document.getElementById('eEnotes').value=e.notes||'';
  document.getElementById('editSub').textContent=(e.equipment_id||'')+' - '+(e.equipment_name||'');
  document.getElementById('editMo').classList.add('open');
}

/* --- RETIRE MODAL ------------------------------------- */
function openRetire(eid,ename){
  document.getElementById('rName').textContent=ename;
  document.getElementById('rEid').value=eid;
  document.getElementById('dEid').value=eid;
  document.getElementById('retireMo').classList.add('open');
}

/* --- EXPORT MENU -------------------------------------- */
function toggleExp(e){
  e.stopPropagation();
  const m=document.getElementById('expMenu');
  m.style.display=m.style.display==='none'?'block':'none';
}
document.addEventListener('click',()=>{const m=document.getElementById('expMenu');if(m)m.style.display='none';});

/* The export is built server-side from the records themselves (see the export
   branch above the pagination slice). Scraping the rendered table only ever
   caught the page on screen, and the badge markup did not line up with the
   header labels. All the browser does now is carry the current filters over. */
function exportUrl(format){
  const u=new URL(location.href);
  u.searchParams.delete('page');
  u.searchParams.delete('per_page');
  u.searchParams.set('export',format);
  return u.toString();
}
function exportCSV(){
  window.location.href=exportUrl('csv');
  toast('ok','All filtered items are being exported.','CSV Export');
}
function exportExcel(){
  window.location.href=exportUrl('xlsx');
  toast('ok','All filtered items are being exported.','Excel Export');
}
function exportPDF(){
  window.open(exportUrl('pdf'),'_blank');
  toast('ok','Print view opened in a new tab.','PDF Export');
}

/* --- ANIMATED COUNTERS -------------------------------- */
function animN(id,to){
  const el=document.getElementById(id);if(!el)return;
  const from=parseInt(el.textContent)||0,dur=750,t0=performance.now();
  const tick=now=>{const p=Math.min((now-t0)/dur,1),e=1-Math.pow(1-p,3);
    el.textContent=Math.round(from+(to-from)*e);if(p<1)requestAnimationFrame(tick);};
  requestAnimationFrame(tick);
}
document.addEventListener('DOMContentLoaded',()=>{
  animN('sn0',<?php echo $c_total;?>);animN('sn1',<?php echo $c_oper;?>);
  animN('sn2',<?php echo $c_maint;?>);animN('sn3',<?php echo $c_fault;?>);
  animN('sn4',<?php echo $c_retire;?>);animN('sn5',<?php echo $c_warn;?>);
});

/* --- TOAST -------------------------------------------- */
function toast(type,msg,title){
  const el=document.createElement('div');el.className='tst '+type;
  el.innerHTML=`<div><div class="tst-t">${title}</div><div class="tst-m">${msg}</div></div>`;
  document.getElementById('ttray').appendChild(el);
  setTimeout(()=>{el.style.transition='opacity .3s';el.style.opacity='0';setTimeout(()=>el.remove(),300);},4000);
}
</script>

<!-- ══ EQUIPMENT QR MODAL ══ -->
<div id="qrModal" style="display:none;position:fixed;inset:0;background:rgba(20,8,8,.55);z-index:1600;align-items:center;justify-content:center;padding:1rem;">
  <div style="background:#fff;border-radius:14px;max-width:340px;width:100%;overflow:hidden;box-shadow:0 16px 44px rgba(44,10,10,.28);">
    <div style="background:#7B1D1D;color:#fff;padding:.9rem 1.1rem;display:flex;justify-content:space-between;align-items:center;">
      <strong style="font-size:.95rem;"><i class="fas fa-qrcode"></i> Equipment QR</strong>
      <button type="button" onclick="document.getElementById('qrModal').style.display='none'" style="background:rgba(255,255,255,.16);border:none;color:#fff;width:28px;height:28px;border-radius:7px;cursor:pointer;font-size:1rem;">&times;</button>
    </div>
    <div style="padding:1.3rem;text-align:center;">
      <div id="qrBox" style="display:inline-block;padding:10px;background:#fff;border:1px solid #E8DDD0;border-radius:10px;"></div>
      <div id="qrName" style="font-weight:700;color:#1C1008;margin-top:.7rem;font-size:.95rem;"></div>
      <div id="qrTag" style="color:#9E8070;font-size:.75rem;margin-top:.15rem;"></div>
      <div style="color:#9E8070;font-size:.66rem;margin-top:.55rem;">Scan to report a defect for this equipment</div>
    </div>
    <div style="padding:0 1.3rem 1.3rem;display:flex;gap:.5rem;">
      <button type="button" onclick="printQR()" class="btn btn-maroon btn-sm" style="flex:1;justify-content:center;"><i class="fas fa-print"></i> Print</button>
      <button type="button" onclick="document.getElementById('qrModal').style.display='none'" class="btn btn-ghost btn-sm">Close</button>
    </div>
  </div>
</div>
<!-- Served from our own assets so equipment QR codes still generate when the
     campus network is down or the demo laptop is offline (was a CDN script). -->
<script src="assets/qrcode.min.js"></script>
<script>
var QR_CTX = {id:'', name:'', tag:'', loc:'', url:''};

function openQR(id, name, tag, loc){
  var base = location.origin + location.pathname.replace(/[^/]*$/, '');
  var url = base + 'student_index.php?eq=' + encodeURIComponent(id);
  QR_CTX = {id:id, name:name||id, tag:tag||'', loc:loc||'', url:url, base:base};
  var box = document.getElementById('qrBox'); box.innerHTML = '';
  // Higher error correction (H) so the label still scans after scuffs and tape.
  if (window.QRCode) { new QRCode(box, { text: url, width: 180, height: 180, colorDark: '#1C1008', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.H }); }
  else { box.innerHTML = '<div style="font-size:.72rem;color:var(--bad-tx);max-width:160px;">QR library could not load. Link:<br>' + url + '</div>'; }
  document.getElementById('qrName').textContent = QR_CTX.name;
  document.getElementById('qrTag').textContent = (tag ? 'Asset Tag: ' + tag + ' · ' : '') + id;
  document.getElementById('qrModal').style.display = 'flex';
}

/* Official BEC asset label: letterhead, seal, QR, and the asset details —
   sized to print two-up on A4 and be taped onto the equipment itself. */
function printQR(){
  var img = document.querySelector('#qrBox img, #qrBox canvas');
  var src = img ? (img.tagName === 'IMG' ? img.src : img.toDataURL('image/png')) : '';
  var esc = function(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
  var seal = QR_CTX.base + 'assets/logs.png';
  var today = new Date().toLocaleDateString('en-PH', {year:'numeric', month:'long', day:'numeric'});
  var row = function(k, v){ return v ? '<tr><th>' + esc(k) + '</th><td>' + esc(v) + '</td></tr>' : ''; };

  var html =
  '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Equipment QR Label — ' + esc(QR_CTX.tag || QR_CTX.id) + '</title><style>'
  + '@page{size:A4;margin:14mm;}'
  + '*{box-sizing:border-box;margin:0;padding:0;}'
  + 'body{font-family:"Segoe UI",Arial,Helvetica,sans-serif;color:#1C1008;background:#fff;padding:8mm;}'
  + '.label{width:104mm;border:1.5px solid #E8DDD0;border-radius:10px;overflow:hidden;page-break-inside:avoid;}'
  + '.lh{display:flex;align-items:center;gap:10px;padding:9px 12px;background:#7B1D1D;color:#fff;}'
  + '.lh img{height:34px;width:34px;object-fit:contain;background:#fff;border-radius:5px;padding:2px;}'
  + '.lh .n{font-family:"Times New Roman",Georgia,serif;font-size:13.5px;font-weight:800;letter-spacing:.3px;line-height:1.15;}'
  + '.lh .o{font-family:"Times New Roman",Georgia,serif;font-style:italic;font-size:10px;color:rgba(255,255,255,.82);}'
  + '.accent{height:3px;background:linear-gradient(90deg,#4A0E0E,#7B1D1D 55%,#C9960C);}'
  + '.title{text-align:center;font-size:10px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:#7B1D1D;padding:9px 10px 2px;}'
  + '.sub{text-align:center;font-size:9px;color:#9E8070;padding-bottom:9px;}'
  + '.qrwrap{text-align:center;padding:0 10px 8px;}'
  // 38.1mm is 1.5in exactly, and content-box keeps it that way: the sheet sets
  // box-sizing:border-box globally, which would otherwise let the border and
  // padding eat into the code and print it undersized.
  + '.qrwrap img{width:38.1mm;height:38.1mm;box-sizing:content-box;border:1px solid #E8DDD0;border-radius:8px;padding:4px;}'
  + '.scan{font-size:10.5px;font-weight:700;color:#4A0E0E;margin-top:6px;}'
  + '.scan span{display:block;font-size:8.5px;font-weight:400;color:#9E8070;margin-top:2px;}'
  + 'table{width:100%;border-collapse:collapse;font-size:10px;margin-top:2px;}'
  + 'th,td{border-top:1px solid #EFE7DA;padding:5px 12px;text-align:left;vertical-align:top;}'
  + 'th{width:34%;color:#9E8070;font-weight:700;text-transform:uppercase;letter-spacing:.4px;font-size:8.5px;}'
  + 'td{color:#1C1008;font-weight:600;}'
  + '.foot{display:flex;justify-content:space-between;gap:8px;font-size:8px;color:#9E8070;padding:7px 12px;background:#F8F3EA;border-top:1px solid #EFE7DA;}'
  + '.bar{display:none;}'
  + '@media screen{body{background:#F1EEE8;padding:22px;}.bar{display:flex;gap:8px;margin-bottom:14px;}'
  + '.bar button{background:#7B1D1D;color:#fff;border:0;padding:9px 18px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;}'
  + '.bar button.sec{background:#6b5b50;}}'
  + '@media print{.bar{display:none!important;}body{padding:0;}'
  + '.lh,.accent,.foot{-webkit-print-color-adjust:exact;print-color-adjust:exact;}}'
  + '</style></head><body>'
  + '<div class="bar"><button onclick="window.print()">Print label</button>'
  + '<button class="sec" onclick="window.close()">Close</button></div>'
  + '<div class="label">'
  +   '<div class="lh"><img src="' + esc(seal) + '" alt="" onerror="this.style.display=\'none\'">'
  +     '<div><div class="n">BATANGAS EASTERN COLLEGES</div>'
  +     '<div class="o">Property Management Office</div></div></div>'
  +   '<div class="accent"></div>'
  +   '<div class="title">Equipment Identification Label</div>'
  +   '<div class="sub">Scan to report a defect for this unit</div>'
  +   '<div class="qrwrap"><img src="' + esc(src) + '" alt="Equipment QR code">'
  +     '<div class="scan">Point your phone camera here<span>Opens the BEC defect report form</span></div></div>'
  +   '<table>'
  +     row('Equipment', QR_CTX.name)
  +     row('Asset Tag', QR_CTX.tag)
  +     row('Equipment ID', QR_CTX.id)
  +     row('Location', QR_CTX.loc)
  +   '</table>'
  +   '<div class="foot"><span>Issued ' + esc(today) + '</span>'
  +     '<span>Do not remove &middot; Property of BEC</span></div>'
  + '</div>'
  + '<script>setTimeout(function(){window.print();},450);<\/script>'
  + '</body></html>';

  var w = window.open('', '_blank');
  if (!w) { alert('Please allow pop-ups to print the QR label.'); return; }
  w.document.write(html);
  w.document.close();
}
</script>
<?php require_once __DIR__ . '/includes/csrf_inject.php'; ?>
<script src="assets/sidebar_autohide.js" defer></script>
<script src="assets/search_premium.js"></script>
<script src="assets/select_premium.js"></script>
<script src="assets/file_upload_premium.js"></script>
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<?php require __DIR__ . '/includes/admin_ui.php'; ?>
</body>
</html>

