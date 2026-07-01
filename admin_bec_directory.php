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
    echo "Full Name,Email,Employee Number,Student Number,Department,Program,User Type\r\n";
    echo "Juan Dela Cruz,juan.delacruz@bec.edu.ph,,2023-00123,CCS,BSIT,Student\r\n";
    echo "Maria Santos,maria.santos@bec.edu.ph,EMP-0099,,Registrar,,Staff\r\n";
    echo "Pedro Reyes,pedro.reyes@bec.edu.ph,EMP-0123,,CCS,,Faculty\r\n";
    exit;
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'import') {
        if (empty($_FILES['directory_file']['tmp_name']) || ($_FILES['directory_file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $flash = ['err', 'Please choose a file to import.'];
        } else {
            $parsed = becdir_parse_file($_FILES['directory_file']['tmp_name'], (string)$_FILES['directory_file']['name']);
            if ($parsed['error'] !== '') {
                $flash = ['err', $parsed['error']];
            } else {
                $res = becdir_upsert($parsed['rows']);
                if (function_exists('logActivity')) { try { logActivity($admin_id, 'admin', 'directory.import', "Imported BEC directory: {$res['inserted']} new, {$res['updated']} updated"); } catch (\Throwable $e) {} }
                $flash = ['ok', "Import complete — {$res['inserted']} added, {$res['updated']} updated ({$res['total']} processed)."];
            }
        }
    } elseif ($act === 'clear_all') {
        try { getPgsqlPdoConnection()->exec("TRUNCATE public.bec_directory RESTART IDENTITY"); $flash = ['ok', 'Directory cleared.']; }
        catch (\Throwable $e) { $flash = ['err', 'Could not clear the directory.']; }
        if (function_exists('logActivity')) { try { logActivity($admin_id, 'admin', 'directory.clear', 'Cleared BEC directory'); } catch (\Throwable $e) {} }
    }
}

$total = becdir_count();
$search = trim((string)($_GET['q'] ?? ''));
$rows = [];
try {
    $pdo = getPgsqlPdoConnection();
    if ($search !== '') {
        $st = $pdo->prepare("SELECT * FROM public.bec_directory WHERE full_name ILIKE :q OR email ILIKE :q OR department ILIKE :q ORDER BY full_name LIMIT 200");
        $st->execute(['q' => '%' . $search . '%']); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $pdo->query("SELECT * FROM public.bec_directory ORDER BY imported_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    }
    $byType = [];
    foreach ($pdo->query("SELECT user_type, COUNT(*) c FROM public.bec_directory GROUP BY user_type") as $r) { $byType[(string)$r['user_type']] = (int)$r['c']; }
} catch (\Throwable $e) { $byType = []; }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>BEC Directory Import — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  :root{--m:#7B1D1D;--md:#4A0E0E;--g:#C9960C;--ink:#1C1008;--ink2:#5C3838;--ink3:#8A7466;--paper:#F4F1EC;--surface:#fff;--border:#E2D9CC;--sb:262px;--danger:#B42318;--success:#1A7A33;--m1:#2D0505;--g2:#D4A017;--g3:#F0C040;--r1:8px;--r2:12px;}
  *{box-sizing:border-box}
  body{margin:0;font-family:'DM Sans',sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;}
  /* Sidebar — exact match to canonical admin sidebar */
  .sb{position:fixed;left:0;top:0;width:var(--sb);height:100vh;background:linear-gradient(168deg,#1E0202 0%,#350808 38%,#4A0E0E 68%,#3A0808 100%);display:flex;flex-direction:column;z-index:400;overflow:hidden;box-shadow:5px 0 30px rgba(45,5,5,.38);transition:transform .32s cubic-bezier(.4,0,.2,1);}
  .sb::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 110% 45% at 50% -5%,rgba(212,160,23,.12),transparent);pointer-events:none;}
  .sb-top{padding:1.35rem 1.2rem .9rem;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:.75rem;position:relative;z-index:1;}
  .seal-ring{position:relative;width:46px;height:46px;flex-shrink:0;}
  .seal-spin{position:absolute;inset:-3px;border-radius:50%;background:conic-gradient(var(--g2) 0%,var(--g3) 30%,var(--g2) 60%,var(--g3) 80%,var(--g2) 100%);animation:sealSpin 7s linear infinite;opacity:.72;}
  @keyframes sealSpin{to{transform:rotate(360deg)}}
  .seal-core{position:absolute;inset:2px;border-radius:50%;overflow:hidden;background:var(--m1);}
  .seal-core img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
  .sb-brand strong{display:block;font-family:'Outfit',sans-serif;font-weight:800;font-size:.8rem;color:#fff;line-height:1.25;}
  .sb-brand em{font-size:.57rem;font-style:normal;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:1.8px;}
  .sb-user{margin:.45rem 1rem .2rem;padding:.65rem .875rem;background:rgba(255,255,255,.055);border:1px solid rgba(255,255,255,.07);border-radius:var(--r2);display:flex;align-items:center;gap:.65rem;position:relative;z-index:1;}
  .uav{width:32px;height:32px;flex-shrink:0;border-radius:50%;background:linear-gradient(135deg,var(--g2),#B45309);display:flex;align-items:center;justify-content:center;font-family:'Outfit',sans-serif;font-weight:900;font-size:.77rem;color:#fff;box-shadow:0 3px 0 rgba(0,0,0,.28);}
  .uname{font-size:.8rem;color:#fff;font-weight:600;display:block;}
  .urole{font-size:.58rem;color:rgba(255,255,255,.32);text-transform:uppercase;letter-spacing:1px;}
  .sb-nav{flex:1;padding:.25rem 0;overflow-y:auto;position:relative;z-index:1;scrollbar-width:thin;scrollbar-color:rgba(212,160,23,.45) transparent;}
.sb-nav::-webkit-scrollbar{width:6px;}
.sb-nav::-webkit-scrollbar-thumb{background:rgba(212,160,23,.4);border-radius:3px;}
.sb-nav::-webkit-scrollbar-thumb:hover{background:rgba(212,160,23,.65);}
  .nav-sec{font-size:.54rem;text-transform:uppercase;letter-spacing:2.5px;color:rgba(255,255,255,.18);padding:.5rem 1.25rem .2rem;font-weight:700;}
  .ni{display:flex;align-items:center;gap:.65rem;padding:.56rem 1.25rem;color:rgba(255,255,255,.42);background:none;border:none;width:100%;text-align:left;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;cursor:pointer;transition:all .16s;text-decoration:none;position:relative;}
  .ni-ic{width:30px;height:30px;border-radius:var(--r1);display:flex;align-items:center;justify-content:center;font-size:.78rem;background:rgba(255,255,255,.05);flex-shrink:0;transition:all .22s;}
  .ni:hover{color:rgba(255,255,255,.82);}
  .ni:hover .ni-ic{background:rgba(255,255,255,.1);transform:scale(1.08);}
  .ni.on{color:#fff;font-weight:600;}
  .ni.on .ni-ic{background:linear-gradient(135deg,var(--g2),var(--g3));color:var(--m1);box-shadow:0 3px 0 rgba(0,0,0,.18),0 4px 12px rgba(212,160,23,.25);}
  .ni.on::after{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(to bottom,var(--g2),var(--g3));border-radius:0 3px 3px 0;}
  .sb-foot{padding:.55rem 1rem .95rem;border-top:1px solid rgba(255,255,255,.06);}
  .lout{width:100%;display:flex;align-items:center;justify-content:center;gap:.65rem;padding:.52rem .78rem;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);color:rgba(255,255,255,.42);border-radius:var(--r1);cursor:pointer;font-size:.8rem;font-family:'DM Sans',sans-serif;font-weight:500;text-decoration:none;transition:all .18s;}
  .lout:hover{background:rgba(220,38,38,.14);color:#fca5a5;border-color:rgba(220,38,38,.22);}
  .main{margin-left:var(--sb);transition:margin-left .26s ease;}
  body.becSbHide .main{margin-left:0 !important;}
  .top{background:var(--md);color:#fff;padding:16px 28px;display:flex;align-items:center;gap:14px;}
  .top h1{font-size:1.05rem;margin:0;}.top .sub{font-size:.72rem;color:rgba(255,255,255,.7);}
  .wrap{max-width:1100px;margin:0 auto;padding:22px 24px 60px;}
  .flash{padding:.8rem 1rem;border-radius:10px;margin-bottom:1rem;font-size:.86rem;}
  .flash.ok{background:#E9F9EF;border:1px solid #b6e6c6;color:var(--success);}
  .flash.err{background:#FEF2F2;border:1px solid #FECACA;color:var(--danger);}
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
  .search input{flex:1;padding:.55rem .8rem;border:1.5px solid var(--border);border-radius:9px;font:inherit;}
  .empty{text-align:center;color:var(--ink3);padding:2rem;}
  @media(max-width:860px){.sb{transform:translateX(-100%);}.main{margin-left:0;}.cards{grid-template-columns:1fr 1fr;}}
  @media(max-width:640px){ input,select,textarea,.fi,.fc,.input{ font-size:16px; } } /* prevent iOS zoom */
</style>
</head>
<body>
  <?php $activeNav = 'directory'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

  <div class="main">
    <div class="top">
      <div><h1>Official BEC User Directory</h1><div class="sub">Import &amp; manage the authorized list used to verify reporters</div></div>
    </div>
    <div class="wrap">
      <?php if ($flash): ?><div class="flash <?php echo $flash[0]==='ok'?'ok':'err'; ?>"><?php echo bd_e($flash[1]); ?></div><?php endif; ?>

      <div class="cards">
        <div class="stat"><div class="n"><?php echo (int)$total; ?></div><div class="l">Total Records</div></div>
        <div class="stat"><div class="n"><?php echo (int)($byType['student'] ?? 0); ?></div><div class="l">Students</div></div>
        <div class="stat"><div class="n"><?php echo (int)($byType['faculty'] ?? 0); ?></div><div class="l">Faculty</div></div>
        <div class="stat"><div class="n"><?php echo (int)($byType['staff'] ?? 0); ?></div><div class="l">Staff</div></div>
      </div>

      <div class="panel">
        <h2><i class="fas fa-file-import"></i> Import Directory</h2>
        <p class="note">Upload a <strong>CSV</strong> file with the official BEC users. Required columns: <em>Full Name, Email, Employee Number, Student Number, Department, Program, User Type</em> (Student / Faculty / Staff). Existing emails are updated; new ones are added.
        <?php if (!extension_loaded('zip')): ?><br><i class="fas fa-circle-info"></i> Tip: In Excel choose <strong>File → Save As → CSV</strong>, then upload that file.<?php endif; ?></p>
        <form method="POST" enctype="multipart/form-data" class="uprow">
          <input type="hidden" name="action" value="import">
          <input type="file" name="directory_file" accept=".csv,.txt,.xlsx" required>
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
          <input type="text" name="q" value="<?php echo bd_e($search); ?>" placeholder="Search name, email, or department…">
          <button class="btn m" type="submit"><i class="fas fa-search"></i></button>
          <?php if($search!==''): ?><a class="btn ghost" href="admin_bec_directory.php">Clear</a><?php endif; ?>
        </form>
        <?php if (!$rows): ?>
          <div class="empty"><i class="fas fa-inbox" style="font-size:1.6rem;display:block;margin-bottom:.5rem;"></i><?php echo $total===0 ? 'No directory imported yet. Upload a CSV to get started.' : 'No records match your search.'; ?></div>
        <?php else: ?>
          <div style="overflow-x:auto;">
          <table>
            <thead><tr><th>Name</th><th>Email</th><th>Type</th><th>Dept</th><th>Program</th><th>Emp/Student No.</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): $ut = $r['user_type'] ?: 'x'; ?>
              <tr>
                <td><?php echo bd_e($r['full_name'] ?: '—'); ?></td>
                <td><?php echo bd_e($r['email']); ?></td>
                <td><span class="tt <?php echo bd_e(in_array($ut,['student','faculty','staff'],true)?$ut:'x'); ?>"><?php echo bd_e($r['user_type'] ?: 'N/A'); ?></span></td>
                <td><?php echo bd_e($r['department'] ?: '—'); ?></td>
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
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
</body>
</html>
