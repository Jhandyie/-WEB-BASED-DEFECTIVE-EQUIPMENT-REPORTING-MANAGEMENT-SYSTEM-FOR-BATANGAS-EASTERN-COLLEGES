<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bec_directory_helper.php';
require_once __DIR__ . '/includes/csrf.php';
requireRole('admin');

$admin_id   = $_SESSION['user_id'] ?? '';
$admin_name = $_SESSION['fullname'] ?? 'Administrator';
function bd_e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// CSV template download
if (isset($_GET['template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="bec_directory_template.csv"');
    // Department may be left blank for students: it is worked out from the year
    // level and programme, exactly as the registrar's enrolment export supplies
    // them. The header names below are only one accepted spelling — the
    // importer also reads the export's own "Year Level" and
    // "Program/Qualifications" headings.
    echo "Full Name,Email,Employee Number,Student Number,Department,Program,Year Level,User Type\r\n";
    echo "Juan Dela Cruz,juan.delacruz@bec.edu.ph,,2023-00123,,Bachelor of Science in Information Systems,2nd Year,Student\r\n";
    echo "Ana Ramos,ana.ramos@bec.edu.ph,,2026-00456,,\"Science, Technology, Engineering, and Mathematics\",Grade 11 - STEM,Student\r\n";
    echo "Maria Santos,maria.santos@bec.edu.ph,EMP-0099,,Registrar,,,Staff\r\n";
    echo "Pedro Reyes,pedro.reyes@bec.edu.ph,EMP-0123,,College of Computer Studies,,,Faculty\r\n";
    exit;
}

$flash = null;
$report = null;   // per-import detail: what was cleaned, skipped, or shared
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'import') {
        if (empty($_FILES['directory_file']['tmp_name']) || ($_FILES['directory_file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $flash = ['err', 'Please choose a file to import.'];
        } else {
            // A full roster is thousands of rows against a remote database. The
            // default limit is short enough to cut a real import off partway.
            @set_time_limit(300);
            $parsed = becdir_parse_file($_FILES['directory_file']['tmp_name'], (string)$_FILES['directory_file']['name']);
            if ($parsed['error'] !== '') {
                $flash = ['err', $parsed['error']];
                $report = $parsed;
            } else {
                try {
                    $res = becdir_upsert($parsed['rows']);
                    if (function_exists('logActivity')) { try { logActivity($admin_id, 'admin', 'directory.import', "Imported BEC directory: {$res['inserted']} new, {$res['updated']} updated, " . count($parsed['skipped'] ?? []) . " skipped"); } catch (\Throwable $e) {} }
                    $flash = ['ok', "Import complete — {$res['inserted']} added, {$res['updated']} updated ({$res['total']} processed)."];
                    $report = $parsed;
                } catch (\Throwable $e) {
                    error_log('BEC directory import failed: ' . $e->getMessage());
                    $flash = ['err', 'The import stopped partway and the unfinished batch was rolled back. Please try again.'];
                }
            }
        }
    } elseif ($act === 'clear_all') {
        try { getPgsqlPdoConnection()->exec("TRUNCATE public.bec_directory RESTART IDENTITY"); $flash = ['ok', 'Directory cleared.']; }
        catch (\Throwable $e) { $flash = ['err', 'Could not clear the directory.']; }
        if (function_exists('logActivity')) { try { logActivity($admin_id, 'admin', 'directory.clear', 'Cleared BEC directory'); } catch (\Throwable $e) {} }
    }
}

$total  = becdir_count();
$search = trim((string)($_GET['q'] ?? ''));
$tf     = trim((string)($_GET['type'] ?? 'all'));   // student / faculty / staff
$df     = trim((string)($_GET['dept'] ?? 'all'));   // academic unit
$yf     = trim((string)($_GET['year'] ?? 'all'));   // year level

$rows = [];
$typeOptions = $deptOptions = $yearOptions = [];
$matchCount = 0;

try {
    $pdo = getPgsqlPdoConnection();

    // The filters are dropdowns rather than free text because every one of these
    // columns has a closed vocabulary that the importer already normalises —
    // typing "Grade 11" or "grade 11 - STEM" should not be able to miss.
    $where = [];
    $bind  = [];
    if ($search !== '') {
        $where[] = "(full_name ILIKE :q OR email ILIKE :q OR student_number ILIKE :q OR employee_number ILIKE :q OR program ILIKE :q)";
        $bind['q'] = '%' . $search . '%';
    }
    if ($tf !== 'all') { $where[] = "LOWER(COALESCE(NULLIF(user_type,''),'student')) = :ut"; $bind['ut'] = strtolower($tf); }
    if ($df !== 'all') { $where[] = "COALESCE(department,'') = :dep"; $bind['dep'] = $df; }
    if ($yf !== 'all') { $where[] = "COALESCE(year_level,'') = :yr";  $bind['yr']  = $yf; }
    $sqlWhere = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM public.bec_directory{$sqlWhere}");
    $cnt->execute($bind);
    $matchCount = (int)$cnt->fetchColumn();

    $order = ($search !== '' || $where) ? 'full_name' : 'imported_at DESC';
    $st = $pdo->prepare("SELECT * FROM public.bec_directory{$sqlWhere} ORDER BY {$order} LIMIT 200");
    $st->execute($bind);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $byType = [];
    foreach ($pdo->query("SELECT user_type, COUNT(*) c FROM public.bec_directory GROUP BY user_type") as $r) {
        $byType[(string)$r['user_type']] = (int)$r['c'];
    }

    // Offer only values somebody is actually filed under, so a dropdown can
    // never select an empty result.
    foreach ($pdo->query("SELECT DISTINCT LOWER(COALESCE(NULLIF(user_type,''),'student')) v FROM public.bec_directory ORDER BY 1") as $r) {
        if ($r['v'] !== '') $typeOptions[] = (string)$r['v'];
    }
    foreach ($pdo->query("SELECT DISTINCT department v FROM public.bec_directory WHERE COALESCE(department,'') <> '' ORDER BY 1") as $r) {
        $deptOptions[] = (string)$r['v'];
    }
    foreach ($pdo->query("SELECT DISTINCT year_level v FROM public.bec_directory WHERE COALESCE(year_level,'') <> ''") as $r) {
        $yearOptions[] = (string)$r['v'];
    }
    $yearOptions = becdir_sort_year_levels($yearOptions);
} catch (\Throwable $e) { $byType = []; }

$hasFilter = ($search !== '' || $tf !== 'all' || $df !== 'all' || $yf !== 'all');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>BEC Directory Import — Admin</title>
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="assets/css/admin-shell.css">
<style>

  :root{--m:#7B1D1D;--md:#4A0E0E;--g:#C9960C;--ink:#1A0808;--ink2:#5C3838;--ink3:#9C7A7A;--paper:#F4EFE6;--surface:#fff;--border:#E5D9C6;--sb:262px;--danger:#B42318;--success:#1A7A33;--m1:#2D0505;--g2:#D4A017;--g3:#F0C040;--r1:8px;--r2:12px;}
  *{box-sizing:border-box}
  body{margin:0;font-family:'DM Sans',sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;}
  /* Sidebar — exact match to canonical admin sidebar */
  /* sidebar styling lives in assets/css/admin-shell.css */
  .main{margin-left:var(--sb);transition:margin-left .26s ease;}
  body.becSbHide .main{margin-left:0 !important;}
  .wrap{max-width:none;margin:0;padding:22px 28px 60px;} /* full-width desktop view */
  .flash{padding:.8rem 1rem;border-radius:10px;margin-bottom:1rem;font-size:.86rem;}
  .flash.ok{background:#E9F9EF;border:1px solid #b6e6c6;color:var(--success);}
  .flash.err{background:#FEF2F2;border:1px solid #FECACA;color:var(--danger);}
  /* post-import report */
  .imp{padding:.85rem 1rem;border-radius:10px;margin-bottom:1rem;font-size:.82rem;line-height:1.6;border:1px solid var(--border);background:var(--surface);}
  .imp b{display:block;font-size:.88rem;margin-bottom:.25rem;}
  .imp p{margin:.1rem 0 .55rem;color:var(--ink2);}
  .imp ul{margin:0;padding-left:1.1rem;max-height:15rem;overflow-y:auto;}
  .imp li{margin-bottom:.22rem;color:var(--ink2);}
  .imp code{background:rgba(0,0,0,.05);padding:.05rem .3rem;border-radius:4px;font-size:.95em;word-break:break-all;}
  .imp .more{margin:.45rem 0 0;font-style:italic;}
  .imp-warn{background:#FFFBEF;border-color:#F0D79A;border-left:3px solid var(--g);}
  .imp-warn b{color:#8A5A00;}
  .imp-err{background:#FEF2F2;border-color:#FECACA;border-left:3px solid var(--danger);}
  .imp-err b{color:var(--danger);}
  .imp-ok{background:#F3F8F4;border-color:#CFE6D6;border-left:3px solid var(--success);}
  .imp-ok b{color:var(--success);}
  .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:.8rem;margin-bottom:1.2rem;}
  .stat{background:var(--surface);border:1px solid var(--border);border-left:4px solid var(--m);border-radius:12px;padding:.9rem 1rem;}
  .stat .n{font-size:1.6rem;font-weight:800;color:var(--m);}.stat .l{font-size:.66rem;text-transform:uppercase;letter-spacing:.5px;color:var(--ink3);font-weight:700;}
  .panel{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.2rem;margin-bottom:1.2rem;}
  .panel h2{margin:0 0 .3rem;font-size:1rem;color:var(--m);}
  .panel p.note{font-size:.8rem;color:var(--ink2);line-height:1.55;margin:.2rem 0 1rem;}
  .uprow{display:flex;gap:.7rem;flex-wrap:wrap;align-items:center;}
  input[type=file]{font:inherit;}
  .btn{display:inline-flex;align-items:center;gap:.5rem;padding:.6rem 1.1rem;border-radius:10px;border:none;font:inherit;font-weight:700;font-size:.82rem;cursor:pointer;text-decoration:none;}
  .btn.m{background:var(--m);color:#fff;}.btn.g{background:var(--g);color:#fff;}.btn.ghost{background:#f1eadf;color:var(--ink2);}.btn.red{background:var(--danger);color:#fff;}
  table{width:100%;border-collapse:collapse;font-size:.82rem;}
  th{text-align:left;padding:.55rem .6rem;background:var(--m);color:#fff;font-size:.68rem;text-transform:uppercase;letter-spacing:.4px;}
  td{padding:.5rem .6rem;border-bottom:1px solid var(--border);}
  tr:nth-child(even) td{background:#faf7f0;}
  .tt{display:inline-block;font-size:.62rem;font-weight:700;padding:.1rem .5rem;border-radius:999px;text-transform:uppercase;}
  .tt.student{background:#E8EFFF;color:#1D4ED8;}.tt.faculty{background:#FBF3DF;color:#92600A;}.tt.staff{background:#E9F9EF;color:#166534;}.tt.x{background:#eee;color:#666;}
  .search{display:flex;gap:.5rem;margin-bottom:.8rem;}
  .search{flex-wrap:wrap;}
  .search input{flex:1 1 220px;padding:.55rem .8rem;border:1.5px solid var(--border);border-radius:9px;font:inherit;}
  .fsel{padding:.55rem .7rem;border:1.5px solid var(--border);border-radius:9px;font:inherit;
        background:#fff;color:var(--ink);cursor:pointer;max-width:210px;}
  .fsel:focus{outline:2px solid var(--brand,#7B1E22);outline-offset:1px;}
  .empty{text-align:center;color:var(--ink3);padding:2rem;}
  @media(max-width:860px){.sb{transform:translateX(-100%);}.main{margin-left:0;}.cards{grid-template-columns:1fr 1fr;}}
  @media(max-width:640px){ input,select,textarea,.fi,.fc,.input{ font-size:16px; } } /* prevent iOS zoom */
</style>
</head>
<body>
  <?php $activeNav = 'directory'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div>
        <div class="pg-title">BEC Directory</div>
        <div class="bc">
          <a href="admin_dashboard.php"><i class="fas fa-home"></i></a>
          <i class="fas fa-chevron-right"></i><span>BEC Directory</span>
        </div>
      </div>
    </div>
    <div class="wrap">
      <div class="head">
        <h2>Official BEC User Directory</h2>
        <p>Import &amp; manage the authorized list used to verify reporters.</p>
      </div>
      <?php if ($flash): ?><div class="flash <?php echo $flash[0]==='ok'?'ok':'err'; ?>"><?php echo bd_e($flash[1]); ?></div><?php endif; ?>
      <?php
      // Anything the import could not take, said plainly. A row dropped in
      // silence becomes a person who is told weeks later, at the sign-in page,
      // that they are not in the official directory.
      if ($report):
        $rShared   = $report['shared']   ?? [];
        $rSkipped  = $report['skipped']  ?? [];
        $rRepaired = $report['repaired'] ?? [];
      ?>
        <?php if ($rShared): ?>
        <div class="imp imp-warn">
          <b><i class="fas fa-triangle-exclamation"></i> <?php echo count($rShared); ?> email address<?php echo count($rShared)===1?'':'es'; ?> belong to more than one person.</b>
          <p>Only the last person on each address was kept — the others are not in the directory and cannot file a report. Anyone signing in with one of these becomes whoever was imported last. Send this list back to the registrar.</p>
          <ul>
            <?php foreach (array_slice($rShared, 0, 10) as $s): ?>
            <li><code><?php echo bd_e($s['email']); ?></code> — <b><?php echo (int)$s['count']; ?> people</b>: <?php echo bd_e(implode('; ', array_slice($s['names'], 0, 4))); ?><?php echo count($s['names']) > 4 ? ' …' : ''; ?></li>
            <?php endforeach; ?>
          </ul>
          <?php if (count($rShared) > 10): ?><p class="more">and <?php echo count($rShared) - 10; ?> more.</p><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($rSkipped): ?>
        <div class="imp imp-err">
          <b><i class="fas fa-user-slash"></i> <?php echo count($rSkipped); ?> row<?php echo count($rSkipped)===1?'':'s'; ?> had no usable email and were not imported.</b>
          <p>These people cannot sign in to the reporter portal until the registrar supplies a valid address.</p>
          <ul>
            <?php foreach (array_slice($rSkipped, 0, 15) as $s): ?>
            <li>Line <?php echo (int)$s['line']; ?> — <?php echo bd_e($s['name'] !== '' ? $s['name'] : '(no name)'); ?><?php if (trim($s['email']) !== ''): ?> · <code><?php echo bd_e($s['email']); ?></code><?php endif; ?> — <?php echo bd_e($s['reason']); ?></li>
            <?php endforeach; ?>
          </ul>
          <?php if (count($rSkipped) > 15): ?><p class="more">and <?php echo count($rSkipped) - 15; ?> more.</p><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($rRepaired): ?>
        <div class="imp imp-ok">
          <b><i class="fas fa-wand-magic-sparkles"></i> <?php echo count($rRepaired); ?> address<?php echo count($rRepaired)===1?'':'es'; ?> were corrected on import.</b>
          <p>Doubled dots and accented letters were cleaned up. Check that these match the real mailboxes.</p>
          <ul>
            <?php foreach (array_slice($rRepaired, 0, 10) as $s): ?>
            <li>Line <?php echo (int)$s['line']; ?> — <code><?php echo bd_e($s['from']); ?></code> → <code><?php echo bd_e($s['to']); ?></code></li>
            <?php endforeach; ?>
          </ul>
          <?php if (count($rRepaired) > 10): ?><p class="more">and <?php echo count($rRepaired) - 10; ?> more.</p><?php endif; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="cards">
        <div class="stat"><div class="n"><?php echo (int)$total; ?></div><div class="l">Total Records</div></div>
        <div class="stat"><div class="n"><?php echo (int)($byType['student'] ?? 0); ?></div><div class="l">Students</div></div>
        <div class="stat"><div class="n"><?php echo (int)($byType['faculty'] ?? 0); ?></div><div class="l">Faculty</div></div>
        <div class="stat"><div class="n"><?php echo (int)($byType['staff'] ?? 0); ?></div><div class="l">Staff</div></div>
      </div>

      <div class="panel">
        <h2><i class="fas fa-file-import"></i> Import Directory</h2>
        <p class="note">Upload a <strong>CSV</strong> file with the official BEC users. Only <em>Full Name</em> and <em>Email</em> are required; <em>Employee Number, Student Number, Department, Program, Year Level, User Type</em> are used when present. The registrar's enrolment export can be uploaded as-is — its <em>Year Level</em> and <em>Program/Qualifications</em> columns are read, and a student's department is worked out from them. Existing emails are updated; new ones are added, and anything that could not be imported is listed above.
        <?php if (!extension_loaded('zip')): ?><br><i class="fas fa-circle-info"></i> Tip: In Excel choose <strong>File → Save As → CSV</strong>, then upload that file.<?php endif; ?></p>
        <form method="POST" enctype="multipart/form-data" class="uprow">
          <input type="hidden" name="action" value="import">
          <input type="file" name="directory_file" accept=".csv,.txt,.xlsx" required data-premium-upload data-hint="CSV, TXT or XLSX">
          <button class="btn m" type="submit"><i class="fas fa-upload"></i> Import</button>
          <a class="btn ghost" href="?template=1"><i class="fas fa-download"></i> Download Template</a>
          <?php if ($total > 0): ?>
          <button class="btn red" type="submit" form="clearForm" onclick="return confirm('Remove all <?php echo (int)$total; ?> directory records? This cannot be undone.');"><i class="fas fa-trash"></i> Clear All</button>
          <?php endif; ?>
        </form>
        <form method="POST" id="clearForm"><input type="hidden" name="action" value="clear_all"></form>
      </div>

      <div class="panel">
        <h2><i class="fas fa-address-book"></i> Directory Records <?php if($total>200): ?><span style="font-size:.7rem;color:var(--ink3);font-weight:400;">(showing latest 200)</span><?php endif; ?></h2>
        <form class="search" method="get">
          <input type="text" name="q" value="<?php echo bd_e($search); ?>" placeholder="Search name, email, student no., or program…">
          <select name="type" class="fsel" onchange="this.form.submit()" aria-label="Filter by affiliation">
            <option value="all">All affiliations</option>
            <?php foreach ($typeOptions as $opt): ?>
              <option value="<?php echo bd_e($opt); ?>" <?php echo $tf === $opt ? 'selected' : ''; ?>><?php echo bd_e(ucfirst($opt)); ?></option>
            <?php endforeach; ?>
          </select>
          <select name="dept" class="fsel" onchange="this.form.submit()" aria-label="Filter by department">
            <option value="all">All departments</option>
            <?php foreach ($deptOptions as $opt): ?>
              <option value="<?php echo bd_e($opt); ?>" <?php echo $df === $opt ? 'selected' : ''; ?>><?php echo bd_e($opt); ?></option>
            <?php endforeach; ?>
          </select>
          <select name="year" class="fsel" onchange="this.form.submit()" aria-label="Filter by year level">
            <option value="all">All year levels</option>
            <?php foreach ($yearOptions as $opt): ?>
              <option value="<?php echo bd_e($opt); ?>" <?php echo $yf === $opt ? 'selected' : ''; ?>><?php echo bd_e($opt); ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn m" type="submit"><i class="fas fa-search"></i></button>
          <?php if($hasFilter): ?><a class="btn ghost" href="admin_bec_directory.php">Clear</a><?php endif; ?>
        </form>
        <?php if ($hasFilter): ?>
          <p class="note" style="margin:-.3rem 0 .8rem;">
            <?php echo number_format($matchCount); ?> match<?php echo $matchCount === 1 ? '' : 'es'; ?><?php echo $matchCount > 200 ? ' — showing the first 200' : ''; ?>
          </p>
        <?php endif; ?>
        <?php if (!$rows): ?>
          <div class="empty"><i class="fas fa-inbox" style="font-size:1.6rem;display:block;margin-bottom:.5rem;"></i><?php echo $total===0 ? 'No directory imported yet. Upload a CSV to get started.' : 'No records match your search.'; ?></div>
        <?php else: ?>
          <div style="overflow-x:auto;">
          <table data-paginate="15" data-paginate-noun="people">
            <thead><tr><th>Name</th><th>Email</th><th>Affiliation</th><th>Department</th><th>Year Level</th><th>Program</th><th>Emp/Student No.</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): $ut = $r['user_type'] ?: 'x'; ?>
              <tr>
                <td><?php echo bd_e($r['full_name'] ?: '—'); ?></td>
                <td><?php echo bd_e($r['email']); ?></td>
                <td><span class="tt <?php echo bd_e(in_array($ut,['student','faculty','staff'],true)?$ut:'x'); ?>"><?php echo bd_e($r['user_type'] ?: 'N/A'); ?></span></td>
                <td><?php echo bd_e($r['department'] ?: '—'); ?></td>
                <td><?php echo bd_e($r['year_level'] ?? '' ?: '—'); ?></td>
                <td><?php echo bd_e($r['program'] ?: '—'); ?></td>
                <td><?php echo bd_e($r['employee_number'] ?: ($r['student_number'] ?: '—')); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php require_once __DIR__ . '/includes/csrf_inject.php'; ?>
<script src="assets/sidebar_autohide.js" defer></script>
<script src="assets/table_paginate.js" defer></script>
<script src="assets/file_upload_premium.js"></script>
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<?php require __DIR__ . '/includes/admin_ui.php'; ?>
</body>
</html>
