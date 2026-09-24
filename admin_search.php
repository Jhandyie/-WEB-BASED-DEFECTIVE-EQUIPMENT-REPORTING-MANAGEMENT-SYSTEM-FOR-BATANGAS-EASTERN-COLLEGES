<?php
/**
 * admin_search.php — one box that finds anything.
 *
 * Before this, every admin screen had its own search field and each one only
 * looked at its own table: eight boxes, none of which could answer a question
 * asked in the corridor. Someone hands you "BEC-2026-000107", or an asset tag
 * off a sticker, or just a name — and you had to already know which page to
 * open before you could start looking.
 *
 * Two things make it feel like a search box rather than a form:
 *
 *   - **It jumps when the answer is certain.** Typing a whole report id or a
 *     whole asset tag goes straight to that record instead of showing a
 *     results page with one row on it that you then have to click.
 *   - **It asks once.** Everything comes back in a single UNION, because a
 *     Supabase round trip costs ~108 ms here and four separate lookups would
 *     make the search visibly slower than the pages it searches (see the note
 *     in CLAUDE.md about this being network-bound).
 *
 * Named admin_search.php because includes/session_bootstrap.php derives the
 * session cookie from the filename — anything not starting with admin_ would
 * silently get an empty session and bounce to the login screen.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
requireRole('admin');

$q = trim((string) ($_GET['q'] ?? ''));
if (function_exists('esc') === false) {
    function esc($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}

$rows = [];
$err  = '';

/* Two characters matches most of the database and is never what anyone meant. */
if (mb_strlen($q) >= 3) {
    try {
        $pdo  = getPgsqlPdoConnection();
        $like = '%' . mb_strtolower($q) . '%';

        $sql = "
        (SELECT 'report'::text AS kind, dr.report_id::text AS id,
                COALESCE(NULLIF(e.equipment_name, ''), 'Unknown equipment')::text AS title,
                COALESCE(dr.issue_description, '')::text AS sub,
                COALESCE(dr.status, '')::text AS extra,
                COALESCE(dr.reporter_name, '')::text AS extra2
           FROM defect_reports dr
           LEFT JOIN equipment e ON e.equipment_id = dr.equipment_id
          WHERE COALESCE(dr.status, '') <> 'deleted'
            AND (LOWER(dr.report_id) LIKE :q
              OR LOWER(COALESCE(e.equipment_name, '')) LIKE :q
              OR LOWER(COALESCE(dr.issue_description, '')) LIKE :q
              OR LOWER(COALESCE(dr.reporter_name, '')) LIKE :q
              OR LOWER(COALESCE(dr.reporter_email, '')) LIKE :q)
          ORDER BY dr.report_date DESC
          LIMIT 8)
        UNION ALL
        (SELECT 'equipment'::text, e.equipment_id::text,
                COALESCE(NULLIF(e.equipment_name, ''), 'Unnamed')::text,
                COALESCE(e.location, '')::text,
                COALESCE(e.asset_tag, '')::text,
                COALESCE(e.status, '')::text
           FROM equipment e
          WHERE LOWER(COALESCE(e.status, '')) <> 'deleted'
            AND (LOWER(COALESCE(e.equipment_name, '')) LIKE :q
              OR LOWER(COALESCE(e.asset_tag, '')) LIKE :q
              OR LOWER(COALESCE(e.location, '')) LIKE :q)
          ORDER BY e.equipment_name
          LIMIT 8)
        UNION ALL
        (SELECT 'account'::text, u.user_id::text,
                COALESCE(NULLIF(u.fullname, ''), u.user_id)::text,
                COALESCE(u.email, '')::text,
                COALESCE(u.role, '')::text,
                COALESCE(u.department, '')::text
           FROM users u
          WHERE COALESCE(u.status, '') <> 'deleted'
            AND (LOWER(COALESCE(u.fullname, '')) LIKE :q
              OR LOWER(COALESCE(u.email, '')) LIKE :q)
          ORDER BY u.fullname
          LIMIT 6)
        UNION ALL
        (SELECT 'person'::text, COALESCE(bd.email, '')::text,
                COALESCE(NULLIF(bd.full_name, ''), 'Unnamed')::text,
                COALESCE(bd.email, '')::text,
                COALESCE(bd.user_type, '')::text,
                COALESCE(bd.department, '')::text
           FROM bec_directory bd
          WHERE (LOWER(COALESCE(bd.full_name, '')) LIKE :q
              OR LOWER(COALESCE(bd.email, '')) LIKE :q)
          ORDER BY bd.full_name
          LIMIT 6)";

        $st = $pdo->prepare($sql);
        $st->execute(['q' => $like]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        error_log('admin_search: ' . $e->getMessage());
        $err = 'The search could not be completed. Please try again.';
    }
}

/*
 * Jump straight there when the query IS the identifier.
 *
 * Deliberately only on an exact, case-insensitive match of the whole value: a
 * search that guesses and redirects on a partial match takes the decision away
 * from the person and is much harder to recover from than one extra click.
 */
$needle = mb_strtolower($q);
foreach ($rows as $r) {
    if ($r['kind'] === 'report' && mb_strtolower((string) $r['id']) === $needle) {
        header('Location: admin_defect_reports.php?view_id=' . urlencode((string) $r['id']));
        exit();
    }
    if ($r['kind'] === 'equipment' && mb_strtolower(trim((string) $r['extra'])) === $needle && $needle !== '') {
        header('Location: admin_defect_reports.php?equipment=' . urlencode((string) $r['id']));
        exit();
    }
}

$groups = ['report' => [], 'equipment' => [], 'account' => [], 'person' => []];
foreach ($rows as $r) { $groups[$r['kind']][] = $r; }

/* The directory and the users table overlap: someone with a login who is also
   in the imported roll would otherwise be listed twice, once per section. */
$seenEmail = [];
foreach ($groups['account'] as $a) {
    $e = mb_strtolower(trim((string) $a['sub']));
    if ($e !== '') { $seenEmail[$e] = true; }
}
$groups['person'] = array_values(array_filter(
    $groups['person'],
    static fn($p) => !isset($seenEmail[mb_strtolower(trim((string) $p['sub']))])
));

$total = count($groups['report']) + count($groups['equipment'])
       + count($groups['account']) + count($groups['person']);

$secLabels = [
    'report'    => ['Reports',        'fa-exclamation-triangle'],
    'equipment' => ['Equipment',      'fa-boxes'],
    'account'   => ['Staff accounts', 'fa-user-shield'],
    'person'    => ['BEC directory',  'fa-id-card'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Search — BEC Admin</title>
<link rel="icon" type="image/png" href="assets/logs.png">
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="assets/css/admin-shell.css">
<style>
:root{ --maroon:#7B1D1D; --maroon-d:#4A0E0E; --gold:#C9960C; --ink:#1A0808;
       --ink2:#5C3838; --ink3:#9C7A7A; --paper:#F4EFE6; --surface:#fff;
       --border:#E5D9C6; --field:#FAF7F0; }
*{margin:0;padding:0;box-sizing:border-box;font-family:'DM Sans',system-ui,sans-serif;}
body{background:var(--paper);color:var(--ink);min-height:100vh;}
.wrap{max-width:none;margin:0;padding:1.5rem 1.75rem 4rem;}

.sbig{display:flex;gap:.6rem;margin:0 0 1.4rem;max-width:660px;}
.sbig input{flex:1;padding:.7rem .95rem;border-radius:10px;border:1px solid var(--border);
  background:var(--surface);font-size:.92rem;color:var(--ink);outline:none;}
.sbig input:focus{border-color:var(--maroon);box-shadow:0 0 0 3px rgba(123,29,29,.1);}
.sbig button{padding:.7rem 1.3rem;border:none;border-radius:10px;background:var(--maroon);
  color:#fff;font-weight:700;font-size:.85rem;cursor:pointer;}
.sbig button:hover{background:var(--maroon-d);}

.smeta{font-size:.8rem;color:var(--ink3);margin:-.9rem 0 1.3rem;}
.ssec{margin-bottom:1.5rem;}
.ssec h3{font-size:.68rem;text-transform:uppercase;letter-spacing:1.8px;color:var(--ink3);
  font-weight:700;margin-bottom:.55rem;display:flex;align-items:center;gap:.45rem;}
.ssec h3 i{color:var(--gold);font-size:.8rem;}

.sres{display:flex;flex-direction:column;gap:.4rem;}
.sr{display:flex;align-items:center;gap:.85rem;padding:.7rem .9rem;border-radius:11px;
  background:var(--surface);border:1px solid var(--border);text-decoration:none;color:inherit;
  transition:border-color .15s,transform .12s;}
.sr:hover{border-color:var(--maroon);transform:translateX(2px);}
.sr-main{min-width:0;flex:1;}
.sr-t{font-weight:600;font-size:.9rem;color:var(--ink);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sr-s{font-size:.78rem;color:var(--ink3);margin-top:.1rem;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sr-id{font-size:.72rem;color:var(--ink2);font-weight:700;flex-shrink:0;
  background:var(--field);border:1px solid var(--border);border-radius:20px;padding:.2rem .6rem;}
.sr-go{color:var(--ink3);flex-shrink:0;font-size:.78rem;}

.snone{background:var(--surface);border:1px dashed var(--border);border-radius:14px;
  padding:2.1rem 1.5rem;text-align:center;color:var(--ink3);max-width:660px;}
.snone i{font-size:1.5rem;color:var(--border);display:block;margin-bottom:.6rem;}
.snone b{color:var(--ink2);display:block;margin-bottom:.35rem;font-size:.95rem;}
.snone span{font-size:.82rem;line-height:1.6;}
.stips{margin-top:.9rem;font-size:.78rem;color:var(--ink3);}
.stips code{background:var(--field);border:1px solid var(--border);border-radius:5px;
  padding:.1rem .35rem;font-family:ui-monospace,Consolas,monospace;font-size:.92em;}

.topbar,.wrap{transition:margin-left .26s ease;}
body.becSbHide .topbar, body.becSbHide .wrap{margin-left:0 !important;}
@media(max-width:860px){ .sb{transform:translateX(-100%);} .topbar,.wrap{margin-left:0;} }
</style>
</head>
<body>
  <?php $activeNav = ''; require __DIR__ . '/includes/admin_sidebar.php'; ?>
  <div class="topbar">
    <div>
      <div class="pg-title">Search</div>
      <div class="bc">
        <a href="admin_dashboard.php"><i class="fas fa-home"></i></a>
        <i class="fas fa-chevron-right"></i><span>Search</span>
      </div>
    </div>
  </div>

  <div class="wrap">
    <form class="sbig" method="GET" action="admin_search.php" role="search">
      <input type="search" name="q" value="<?php echo esc($q); ?>" autofocus
             placeholder="Report ID, asset tag, equipment, a name or an email…"
             aria-label="Search everything">
      <button type="submit"><i class="fas fa-magnifying-glass"></i> Search</button>
    </form>

    <?php if ($err !== ''): ?>
      <div class="snone"><b><?php echo esc($err); ?></b></div>

    <?php elseif ($q === ''): ?>
      <div class="snone">
        <i class="fas fa-magnifying-glass"></i>
        <b>Search across the whole system</b>
        <span>Reports, equipment, staff accounts and the BEC directory — all at once.</span>
        <div class="stips">
          A full report ID like <code>BEC-<?php echo date('Y'); ?>-000107</code> or a whole asset
          tag like <code>A-0825-0001</code> opens that record directly.
        </div>
      </div>

    <?php elseif (mb_strlen($q) < 3): ?>
      <div class="snone">
        <i class="fas fa-keyboard"></i>
        <b>Keep typing</b>
        <span>Three letters or more — one or two would match most of the database.</span>
      </div>

    <?php elseif ($total === 0): ?>
      <div class="snone">
        <i class="fas fa-circle-question"></i>
        <b>Nothing matched &ldquo;<?php echo esc($q); ?>&rdquo;</b>
        <span>Check the spelling, or try part of it — a room name, a surname, or the
              first few characters of an asset tag.</span>
      </div>

    <?php else: ?>
      <div class="smeta"><?php echo $total; ?> match<?php echo $total === 1 ? '' : 'es'; ?>
        for &ldquo;<?php echo esc($q); ?>&rdquo;</div>

      <?php foreach ($secLabels as $kind => [$label, $icon]): ?>
        <?php if (!$groups[$kind]) { continue; } ?>
        <div class="ssec">
          <h3><i class="fas <?php echo $icon; ?>"></i> <?php echo $label; ?>
              <span style="font-weight:600;letter-spacing:0;text-transform:none;">
                (<?php echo count($groups[$kind]); ?>)</span></h3>
          <div class="sres">
            <?php foreach ($groups[$kind] as $r): ?>
              <?php
              /* Every result goes somewhere that can actually act on it, not to a
                 page that merely mentions it. */
              switch ($kind) {
                  case 'report':
                      $href = 'admin_defect_reports.php?view_id=' . urlencode((string) $r['id']);
                      $badge = (string) $r['id'];
                      break;
                  case 'equipment':
                      $href = 'admin_defect_reports.php?equipment=' . urlencode((string) $r['id']);
                      $badge = trim((string) $r['extra']) !== '' ? (string) $r['extra'] : (string) $r['id'];
                      break;
                  case 'account':
                      $href = 'admin_users.php?search=' . urlencode((string) $r['sub']);
                      $badge = (string) $r['extra'];
                      break;
                  default:
                      /* A directory entry is only ever interesting through what they
                         have reported, which is the one thing the directory page
                         cannot show. */
                      $href = 'admin_defect_reports.php?reporter=' . urlencode((string) $r['sub']);
                      $badge = trim((string) $r['extra']) !== '' ? (string) $r['extra'] : 'directory';
                      break;
              }
              $subLine = trim((string) $r['sub']);
              if ($kind === 'report') {
                  $subLine = ($r['extra'] !== '' ? ucwords(str_replace('_', ' ', (string) $r['extra'])) . ' · ' : '')
                           . ($r['extra2'] !== '' ? 'Reported by ' . $r['extra2'] : '')
                           . ($subLine !== '' ? ' — ' . mb_substr($subLine, 0, 90) : '');
              } elseif ($kind === 'person' || $kind === 'account') {
                  $dept = trim((string) $r['extra2']);
                  if ($dept !== '') { $subLine .= ' · ' . $dept; }
              }
              ?>
              <a class="sr" href="<?php echo esc($href); ?>">
                <div class="sr-main">
                  <div class="sr-t"><?php echo esc($r['title']); ?></div>
                  <?php if (trim($subLine) !== ''): ?>
                    <div class="sr-s"><?php echo esc(trim($subLine, ' -·')); ?></div>
                  <?php endif; ?>
                </div>
                <?php if (trim($badge) !== ''): ?>
                  <span class="sr-id"><?php echo esc($badge); ?></span>
                <?php endif; ?>
                <i class="fas fa-chevron-right sr-go" aria-hidden="true"></i>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

<?php require __DIR__ . '/includes/admin_ui.php'; ?>
</body>
</html>
