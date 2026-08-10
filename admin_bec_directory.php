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
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bec_directory_template.csv"');
    header('Cache-Control: max-age=0, must-revalidate');
    // Without the BOM, Excel opens this as ANSI and the sample names come back
    // mangled — and a file saved from that state re-imports mangled too.
    echo "\xEF\xBB\xBF";
    // Department may be left blank for students: it is worked out from the year
    // level and programme, exactly as the registrar's enrolment export supplies
    // them. The header names below are only one accepted spelling — the
    // importer also reads the export's own "Year Level" and
    // "Program/Qualifications" headings.
    echo "Full Name,Email,Employee Number,Student Number,Department,Program,Year Level,Position,User Type\r\n";
    echo "Juan Dela Cruz,juan.delacruz@bec.edu.ph,,2023-00123,,Bachelor of Science in Information Systems,2nd Year,,Student\r\n";
    echo "Ana Ramos,ana.ramos@bec.edu.ph,,2026-00456,,\"Science, Technology, Engineering, and Mathematics\",Grade 11 - STEM,,Student\r\n";
    echo "Pedro Reyes,pedro.reyes@bec.edu.ph,EMP-0123,,College of Computer Studies,,,Instructor I,Faculty\r\n";
    echo "Elena Cruz,elena.cruz@bec.edu.ph,EMP-0150,,Senior High School,,,Teacher II,Faculty\r\n";
    echo "Maria Santos,maria.santos@bec.edu.ph,EMP-0099,,Registrar,,,Records Officer,Staff\r\n";
    echo "Rosa Lim,rosa.lim@bec.edu.ph,EMP-0210,,Maintenance Department,,,Utility Worker,Staff\r\n";
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

/* The roster runs to thousands. This list was capped at 200 rows with no way to
   reach row 201, so most of the directory could only be found by guessing a
   search term that happened to match it. Paged in the database, same as
   admin_users.php. */
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

/* Every filter has to survive a page change, or paging silently resets the view. */
$pageQuery = static function (int $p) use ($search, $tf, $df, $yf): string {
    $args = ['page' => $p];
    if ($search !== '') { $args['q']    = $search; }
    if ($tf !== 'all')  { $args['type'] = $tf; }
    if ($df !== 'all')  { $args['dept'] = $df; }
    if ($yf !== 'all')  { $args['year'] = $yf; }
    return '?' . http_build_query($args);
};

$rows = [];
$typeOptions = $deptOptions = $yearOptions = [];
$matchCount  = 0;
$totalPages  = 1;
$pageOverrun = false;   // set inside the try; must exist if the query throws

try {
    $pdo = getPgsqlPdoConnection();

    // The filters are dropdowns rather than free text because every one of these
    // columns has a closed vocabulary that the importer already normalises —
    // typing "Grade 11" or "grade 11 - STEM" should not be able to miss.
    //
    // Each condition is kept separately so a dropdown can be built with every
    // filter EXCEPT itself applied. Without that the filters don't know about
    // each other: picking "College of Business" still offered Grade 1-12 in the
    // year dropdown, and 161 of the 190 department+year pairs on this roster
    // (85%) can only ever return nothing. A dropdown should not be able to
    // choose an empty result.
    $cond = [];
    if ($search !== '') {
        $cond['search'] = ["(full_name ILIKE :q OR email ILIKE :q OR student_number ILIKE :q OR employee_number ILIKE :q OR program ILIKE :q)",
                           ['q' => '%' . $search . '%']];
    }
    if ($tf !== 'all') { $cond['type'] = ["LOWER(COALESCE(NULLIF(user_type,''),'student')) = :ut", ['ut' => strtolower($tf)]]; }
    if ($df !== 'all') { $cond['dept'] = ["COALESCE(department,'') = :dep", ['dep' => $df]]; }
    if ($yf !== 'all') { $cond['year'] = ["COALESCE(year_level,'') = :yr",  ['yr'  => $yf]]; }

    /** Compose a WHERE from every condition except the named facet. */
    $compose = static function (?string $except = null) use ($cond): array {
        $sql = []; $bind = [];
        foreach ($cond as $key => [$frag, $params]) {
            if ($key === $except) { continue; }
            $sql[] = $frag;
            $bind += $params;
        }
        return [$sql ? ' WHERE ' . implode(' AND ', $sql) : '', $bind];
    };

    [$sqlWhere, $bind] = $compose();
    $where = $cond;   // kept for the ORDER BY choice below

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM public.bec_directory{$sqlWhere}");
    $cnt->execute($bind);
    $matchCount = (int)$cnt->fetchColumn();

    $totalPages = max(1, (int)ceil($matchCount / $perPage));
    // A page number past the end must not silently render an empty table.
    $pageOverrun = $page > $totalPages && $matchCount > 0;

    $order = ($search !== '' || $where) ? 'full_name' : 'imported_at DESC';
    // Interpolated, not bound: both are ints from (int) casts, and PDO will not
    // bind LIMIT/OFFSET placeholders without emulation quirks.
    $st = $pdo->prepare("SELECT * FROM public.bec_directory{$sqlWhere}
                         ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}");
    $st->execute($bind);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $byType = [];
    foreach ($pdo->query("SELECT user_type, COUNT(*) c FROM public.bec_directory GROUP BY user_type") as $r) {
        $byType[(string)$r['user_type']] = (int)$r['c'];
    }

    // Offer only values somebody is actually filed under GIVEN THE OTHER
    // FILTERS, so a dropdown can never select an empty result. Pick a college
    // and the year dropdown drops to 1st-4th Year; pick Grade School and it
    // shows Grade 1-6.
    $facet = static function (string $expr, ?string $except) use ($pdo, $compose): array {
        [$w, $b] = $compose($except);
        $clause = $w === '' ? " WHERE COALESCE({$expr},'') <> ''"
                            : $w . " AND COALESCE({$expr},'') <> ''";
        $st = $pdo->prepare("SELECT DISTINCT {$expr} v FROM public.bec_directory{$clause} ORDER BY 1");
        $st->execute($b);
        $out = [];
        foreach ($st as $r) { if ((string)$r['v'] !== '') { $out[] = (string)$r['v']; } }
        return $out;
    };

    $typeOptions = $facet("LOWER(COALESCE(NULLIF(user_type,''),'student'))", 'type');
    $deptOptions = $facet('department', 'dept');
    $yearOptions = $facet('year_level', 'year');

    // A value that is currently selected must stay in its own list even if the
    // other filters have made it impossible, or the <select> would silently
    // render as if nothing were chosen while the filter is still being applied.
    if ($tf !== 'all' && !in_array(strtolower($tf), $typeOptions, true)) { $typeOptions[] = strtolower($tf); }
    if ($df !== 'all' && !in_array($df, $deptOptions, true))             { $deptOptions[] = $df; }
    if ($yf !== 'all' && !in_array($yf, $yearOptions, true))             { $yearOptions[] = $yf; }

    // Nursery → Kindergarten → Grade 1-12 → 1st-4th Year, not alphabetical
    // (which would put "Grade 10" before "Grade 2").
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

  /* One scale for type and one for space, shared with the defect record so
     the two admin pages agree instead of each being consistent in its own
     dialect. Steps only — nothing lives between them. */
  :root{--fs-xs:.6rem;--fs-sm:.68rem;--fs-base:.76rem;--fs-md:.82rem;--fs-lg:.88rem;
    --sp-0:.125rem;--sp-1:.25rem;--sp-2:.5rem;--sp-3:.75rem;--sp-4:1rem;--sp-5:1.5rem;}
  :root{--m:#7B1D1D;--md:#4A0E0E;--g:#C9960C;--ink:#1A0808;--ink2:#5C3838;--ink3:#9C7A7A;--paper:#F4EFE6;--surface:#fff;--border:#E5D9C6;--sb:262px;--danger:#B42318;--success:#1A7A33;--m1:#2D0505;--g2:#D4A017;--g3:#F0C040;--r1:8px;--r2:12px;}
  *{box-sizing:border-box}
  body{margin:0;font-family:'DM Sans',sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;}
  /* Sidebar — exact match to canonical admin sidebar */
  /* sidebar styling lives in assets/css/admin-shell.css */
  .main{margin-left:var(--sb);transition:margin-left .26s ease;}
  body.becSbHide .main{margin-left:0 !important;}
  .wrap{max-width:none;margin:0;padding:22px 28px 60px;} /* full-width desktop view */
  .flash{padding:var(--sp-3) var(--sp-4);border-radius:10px;margin-bottom:var(--sp-4);font-size:var(--fs-lg);}
  .flash.ok{background:#E9F9EF;border:1px solid #b6e6c6;color:var(--success);}
  .flash.err{background:#FEF2F2;border:1px solid #FECACA;color:var(--danger);}
  /* post-import report */
  .imp{padding:var(--sp-3) var(--sp-4);border-radius:10px;margin-bottom:var(--sp-4);font-size:var(--fs-md);line-height:1.6;border:1px solid var(--border);background:var(--surface);}
  .imp b{display:block;font-size:var(--fs-lg);margin-bottom:var(--sp-1);}
  .imp p{margin:var(--sp-0) 0 var(--sp-2);color:var(--ink2);}
  .imp ul{margin:0;padding-left:var(--sp-4);max-height:15rem;overflow-y:auto;}
  .imp li{margin-bottom:var(--sp-1);color:var(--ink2);}
  .imp code{background:rgba(0,0,0,.05);padding:var(--sp-0) var(--sp-1);border-radius:4px;font-size:.95em;word-break:break-all;}
  .imp .more{margin:var(--sp-2) 0 0;font-style:italic;}
  .imp-warn{background:#FFFBEF;border-color:#F0D79A;border-left:3px solid var(--g);}
  .imp-warn b{color:#8A5A00;}
  .imp-err{background:#FEF2F2;border-color:#FECACA;border-left:3px solid var(--danger);}
  .imp-err b{color:var(--danger);}
  .imp-ok{background:#F3F8F4;border-color:#CFE6D6;border-left:3px solid var(--success);}
  .imp-ok b{color:var(--success);}
  .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-3);margin-bottom:var(--sp-3);}
  /* Sits directly under the cards it explains, quiet enough to read as a
     footnote to them rather than as an error the page is reporting. */
  .cards-note{display:flex;align-items:flex-start;gap:var(--sp-2);
    margin:0 0 var(--sp-4);padding:var(--sp-3) var(--sp-4);
    background:#FBF8F1;border:1px solid var(--border);border-left:3px solid var(--g);
    border-radius:10px;font-size:var(--fs-base);line-height:1.55;color:var(--ink2);}
  .cards-note i{color:var(--g);margin-top:.15em;flex-shrink:0;}
  .cards-note em{font-style:normal;font-weight:700;color:var(--ink);}
  .stat{background:var(--surface);border:1px solid var(--border);border-left:4px solid var(--m);border-radius:12px;padding:var(--sp-4) var(--sp-4);}
  .stat .n{font-size:1.6rem;font-weight:800;color:var(--m);}.stat .l{font-size:var(--fs-sm);text-transform:uppercase;letter-spacing:.5px;color:var(--ink3);font-weight:700;}
  .panel{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:var(--sp-4);margin-bottom:var(--sp-4);}
  .panel h2{margin:0 0 var(--sp-1);font-size:1rem;color:var(--m);}
  .panel p.note{font-size:var(--fs-md);color:var(--ink2);line-height:1.55;margin:var(--sp-1) 0 var(--sp-4);}
  /* The import guidance is reference material, not something read every visit. */
  details.note-guide{padding:0;}
  details.note-guide > summary{cursor:pointer;list-style:none;display:flex;align-items:center;
    gap:var(--sp-2);padding:var(--sp-2) var(--sp-3);font-size:var(--fs-md);}
  details.note-guide > summary::-webkit-details-marker{display:none;}
  details.note-guide > summary::after{content:'\f078';font-family:'Font Awesome 6 Free';font-weight:900;
    margin-left:auto;font-size:var(--fs-xs);color:var(--ink3);transition:transform .18s;}
  details.note-guide[open] > summary::after{transform:rotate(180deg);}
  details.note-guide > summary:focus-visible{outline:2px solid var(--m);outline-offset:2px;}
  details.note-guide .guide-hint{font-size:var(--fs-sm);font-weight:500;color:var(--ink3);}
  details.note-guide ul{margin:0;padding:var(--sp-0) var(--sp-4) var(--sp-3) 2rem;}
  @media(prefers-reduced-motion:reduce){details.note-guide > summary::after{transition:none;}}
  .note-guide{font-size:var(--fs-md);color:var(--ink2);line-height:1.55;margin:var(--sp-1) 0 var(--sp-4);
    background:#FBF8F1;border:1px solid var(--border);border-left:3px solid var(--g);
    border-radius:10px;padding:var(--sp-3) var(--sp-4);}
  .note-guide > b{display:flex;align-items:center;gap:var(--sp-2);margin-bottom:var(--sp-2);color:var(--ink);}
  .note-guide > b i{color:var(--g);}
  .note-guide ul{margin:0;padding-left:var(--sp-4);display:flex;flex-direction:column;gap:var(--sp-1);}
  .note-guide li{padding-left:var(--sp-0);}
  .note-guide em{font-style:normal;font-weight:600;color:var(--ink);}
  .uprow{display:flex;gap:var(--sp-3);flex-wrap:wrap;align-items:center;}
  input[type=file]{font:inherit;}
  .btn{display:inline-flex;align-items:center;gap:var(--sp-2);padding:var(--sp-2) var(--sp-4);border-radius:10px;border:none;font:inherit;font-weight:700;font-size:var(--fs-md);cursor:pointer;text-decoration:none;}
  .btn.m{background:var(--m);color:#fff;}.btn.g{background:var(--g);color:#fff;}.btn.ghost{background:#f1eadf;color:var(--ink2);}.btn.red{background:var(--danger);color:#fff;}
  /* Table type and spacing come from .adm-table in assets/css/admin-shell.css,
     so this page matches User Management instead of drawing its own maroon
     header bar at its own font size. Zebra striping is gone with it — the row
     separators already do that job, and the stripe fought the hover state. */
  .tt{display:inline-block;font-size:var(--fs-xs);font-weight:700;padding:var(--sp-0) var(--sp-2);border-radius:999px;text-transform:uppercase;}
  .tt.student{background:#E8EFFF;color:#1D4ED8;}.tt.faculty{background:#FBF3DF;color:#92600A;}.tt.staff{background:#E9F9EF;color:#166534;}.tt.x{background:#eee;color:#666;}
  .search{display:flex;gap:var(--sp-2);margin-bottom:var(--sp-3);}
  .search{flex-wrap:wrap;}
  .search input{flex:1 1 220px;padding:var(--sp-2) var(--sp-3);border:1.5px solid var(--border);border-radius:9px;font:inherit;}
  .fsel{padding:var(--sp-2) var(--sp-3);border:1.5px solid var(--border);border-radius:9px;font:inherit;
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
        $rUntyped  = $report['untyped']  ?? [];
      ?>
        <?php if ($rUntyped): ?>
        <div class="imp imp-warn">
          <b><i class="fas fa-user-tag"></i> <?php echo count($rUntyped); ?> row<?php echo count($rUntyped)===1?'':'s'; ?> did not say whether the person is a student, faculty, or staff.</b>
          <p>They were imported, but with no affiliation they are counted as students — which is why the Faculty and Staff totals can read low. Add a <b>User Type</b> column (Student / Faculty / Staff), or a <b>Position</b> column so the job title can be read, and import the file again.</p>
          <ul>
            <?php foreach (array_slice($rUntyped, 0, 10) as $s): ?>
            <li>Line <?php echo (int)$s['line']; ?> — <?php echo bd_e($s['name'] !== '' ? $s['name'] : '(no name)'); ?> · <code><?php echo bd_e($s['email']); ?></code></li>
            <?php endforeach; ?>
          </ul>
          <?php if (count($rUntyped) > 10): ?><p class="more">and <?php echo count($rUntyped) - 10; ?> more.</p><?php endif; ?>
        </div>
        <?php endif; ?>
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

      <?php
        $nStudent = (int)($byType['student'] ?? 0);
        $nFaculty = (int)($byType['faculty'] ?? 0);
        $nStaff   = (int)($byType['staff']   ?? 0);
        /* Faculty and Staff read zero because the registrar's export types every
           row as a student, not because nobody works here. A card that will show
           0 until someone else changes a file elsewhere is not a statistic, it
           is an unexplained defect sitting on the page — and this page is shown
           to a panel. State the reason next to the number instead. */
        $typedOnly = ($nStudent > 0 && $nFaculty === 0 && $nStaff === 0);
      ?>
      <div class="cards">
        <div class="stat"><div class="n"><?php echo (int)$total; ?></div><div class="l">Total Records</div></div>
        <div class="stat"><div class="n"><?php echo $nStudent; ?></div><div class="l">Students</div></div>
        <div class="stat"><div class="n"><?php echo $nFaculty; ?></div><div class="l">Faculty</div></div>
        <div class="stat"><div class="n"><?php echo $nStaff; ?></div><div class="l">Staff</div></div>
      </div>
      <?php if ($typedOnly): ?>
      <p class="cards-note">
        <i class="fas fa-circle-info" aria-hidden="true"></i>
        Faculty and Staff show zero because every row in the registrar's export
        is typed <em>student</em>. The people are in the directory and can sign
        in — only the type column is wrong. Re-import with the
        <em>User Type</em> column filled, or upload a separate faculty and staff
        file below, and these counts fill in.
      </p>
      <?php endif; ?>

      <div class="panel">
        <h2><i class="fas fa-file-import"></i> Import Directory</h2>
        <p class="note">Upload a <strong>CSV</strong> file with the official BEC users. Only <em>Full Name</em> and <em>Email</em> are required; <em>Employee Number, Student Number, Department, Program, Year Level, Position, User Type</em> are used when present. Existing emails are updated, new ones are added, and anything that could not be imported is listed above.</p>

        <?php /* Faculty and staff need their own upload: the registrar's file is
                 students only, and a reporter who is in no list cannot sign in.

                 Collapsed by default. This page is opened far more often to look
                 somebody up than to import, and four bullets of import rules —
                 about 250px — stood between the header and the 3,587 records the
                 visit was actually for. It opens itself right after an import,
                 which is exactly when the skipped-row rules matter. */ ?>
        <details class="note note-guide" <?php echo $report ? 'open' : ''; ?>>
          <summary><b><i class="fas fa-users"></i> How importing works</b><span class="guide-hint">students, faculty &amp; staff</span></summary>
          <ul>
            <li><b>Students</b> — the registrar's enrolment export uploads as-is. Its <em>Year Level</em> and <em>Program/Qualifications</em> columns are read, and the department is worked out from them.</li>
            <li><b>Faculty and staff</b> — these are <u>not</u> in the enrolment export, so they need a separate file. Give it <em>Full Name</em>, <em>Email</em>, <em>Department</em> and either a <em>User Type</em> column (Student / Faculty / Staff) or a <em>Position</em> column (&ldquo;Instructor I&rdquo;, &ldquo;Records Officer&rdquo;).</li>
            <li><b>Why it matters</b> — a row with no year level, no <em>User Type</em> and no <em>Position</em> has nothing to identify it, so it is counted as a student. That is what makes the Faculty total read zero.</li>
            <li><b>Position beats department</b> — a utility worker assigned to Senior High School is staff, not faculty. Without a <em>Position</em>, anyone in an academic unit is assumed to teach.</li>
          </ul>
        </details>
        <p class="note">
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
        <?php // "(showing latest 200)" was left over from the old LIMIT 200. The
              // list is paged in SQL now — 50 a page, every record reachable —
              // and the pager underneath reports the range, so this said a number
              // that had stopped being true. ?>
        <h2><i class="fas fa-address-book"></i> Directory Records</h2>
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
        <?php if ($hasFilter || $matchCount > $perPage): ?>
          <p class="note" style="margin:-.3rem 0 .8rem;">
            <?php echo number_format($matchCount); ?> match<?php echo $matchCount === 1 ? '' : 'es'; ?><?php
              if ($matchCount > $perPage && count($rows) > 0) {
                  echo ' — showing ' . number_format($offset + 1) . '–' . number_format($offset + count($rows));
              }
            ?>
          </p>
        <?php endif; ?>
        <?php if ($pageOverrun): ?>
          <p class="note" style="margin:-.3rem 0 .8rem;color:var(--danger);">
            Page <?php echo number_format($page); ?> is past the end —
            there <?php echo $totalPages === 1 ? 'is' : 'are'; ?> <?php echo number_format($totalPages); ?>
            page<?php echo $totalPages !== 1 ? 's' : ''; ?>.
            <a href="<?php echo bd_e($pageQuery(1)); ?>">Back to the first page</a>.
          </p>
        <?php endif; ?>
        <?php if (!$rows): ?>
          <div class="empty"><i class="fas fa-inbox" style="font-size:1.6rem;display:block;margin-bottom:.5rem;"></i><?php echo $total===0 ? 'No directory imported yet. Upload a CSV to get started.' : 'No records match your search.'; ?></div>
        <?php else: ?>
          <div style="overflow-x:auto;">
          <!-- No data-paginate: this list is paged in SQL. The client paginator
               would slice the 50 rows the server already chose, leaving two
               pagers disagreeing about what "page 1" means. -->
          <table class="adm-table">
            <?php /* Widths declared once so the fixed layout has something to
                     honour; without them every column would be an equal seventh
                     and the email would truncate while Year Level sat half empty. */ ?>
            <colgroup>
              <col style="width:17%"><col style="width:21%"><col style="width:9%">
              <col style="width:15%"><col style="width:9%"><col style="width:18%">
              <col style="width:11%">
            </colgroup>
            <thead><tr><th>Name</th><th>Email</th><th>Affiliation</th><th>Department</th><th>Year Level</th><th>Program</th><th>Emp/Student No.</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): $ut = $r['user_type'] ?: 'x'; ?>
              <tr>
                <td title="<?php echo bd_e($r['full_name'] ?: ''); ?>"><?php echo bd_e($r['full_name'] ?: '—'); ?></td>
                <td title="<?php echo bd_e($r['email']); ?>"><?php echo bd_e($r['email']); ?></td>
                <td><span class="tt <?php echo bd_e(in_array($ut,['student','faculty','staff'],true)?$ut:'x'); ?>"><?php echo bd_e($r['user_type'] ?: 'N/A'); ?></span></td>
                <td title="<?php echo bd_e($r['department'] ?: ''); ?>"><?php echo bd_e($r['department'] ?: '—'); ?></td>
                <td><?php echo bd_e($r['year_level'] ?? '' ?: '—'); ?></td>
                <td title="<?php echo bd_e($r['program'] ?: ''); ?>"><?php echo bd_e($r['program'] ?: '—'); ?></td>
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
