<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('technician');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireRole('technician');

$technician_id = $_SESSION['user_id'];
$technician_name = $_SESSION['fullname'];$initials = 'T';
if (!empty($technician_name)) {
    $parts = preg_split('/\s+/', trim((string)$technician_name));
    if (is_array($parts) && count($parts) >= 2) {
        $initials = strtoupper(substr((string)$parts[0], 0, 1) . substr((string)$parts[count($parts)-1], 0, 1));
    } else {
        $initials = strtoupper(substr((string)$technician_name, 0, 2));
    }
}

if (!function_exists('getStatusClass')) {
    function getStatusClass($status): string {
        $s = strtolower((string)$status);
        return match ($s) {
            'reported' => 'reported',
            'assigned' => 'assigned',
            'in_progress' => 'in_progress',
            'completed', 'verified', 'closed' => 'completed',
            default => 'assigned',
        };
    }
}

if (!function_exists('getPriorityClass')) {
    function getPriorityClass($priority): string {
        $p = strtolower((string)$priority);
        return match ($p) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            default => 'low',
        };
    }
}

$report_id = $_GET['report_id'] ?? '';
$error = '';
$task = null;

if (empty($report_id)) {
    $error = 'No task ID specified. Please select a task from your task list.';
} else {
    $task = getDefectReportById($report_id);
    if (!$task) {
        $error = 'Task not found or no longer available. Please open it again from your task list.';
    }
}

$tech_keys = array_values(array_filter(array_unique([
    trim((string)$technician_id),
    trim((string)($_SESSION['user_email'] ?? '')),
    trim((string)($technician_name ?? '')),
]), fn($v) => $v !== ''));
$tech_key_norms = array_values(array_unique(array_map(
    fn($v) => strtolower(trim((string)$v)),
    $tech_keys
)));
$assigned_value = $task ? (string)($task['assigned_to'] ?? ($task['assigned_technician'] ?? '')) : '';
$can_update = $task ? in_array(strtolower(trim($assigned_value)), $tech_key_norms, true) : false;
$is_unassigned = $task ? (trim($assigned_value) === '' && ($task['status'] ?? '') === 'reported') : false;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Details - Maintenance Technician</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&family=Poppins:wght@400;500;600;700;800&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{
            --maroon:#7B1D1D; --maroon-d:#521010; --maroon-l:#9B2C2C;
            --gold:#D4A017;   --gold-l:#F0C040;   --gold-p:#FEF9E7;
            --cream:#FFFDF8;  --surf:#FFFFFF;     --surf2:#FBF7F0;
            --bdr:#EDE0CC;    --t1:#1A0A0A;       --t2:#6B4040; --t3:#B08080;
            --s1:0 2px 8px rgba(90,16,16,.07);
            --s2:0 6px 24px rgba(90,16,16,.12);
            --r1:8px; --r2:14px; --r3:20px;
        }
        *{box-sizing:border-box}
        body{
            margin:0;
            font-family:'Nunito',sans-serif;
            background:var(--cream);
            color:var(--t1);
        }

        .sidebar{
            position:fixed;left:0;top:0;width:260px;height:100vh;
            background:linear-gradient(168deg,#1E0202 0%,#350808 38%,#4A0E0E 68%,#3A0808 100%);
            color:#fff;z-index:200;
            box-shadow:5px 0 30px rgba(45,5,5,.38);
            display:flex;flex-direction:column;
        }
        .sidebar-header{padding:1.4rem 1.25rem 1rem;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:.75rem}
        .sidebar-logo{
            width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;
            background:var(--maroon-d);position:relative;overflow:hidden;flex-shrink:0;
        }
        .sidebar-logo img{width:100%;height:100%;border-radius:50%;object-fit:cover}
        .sidebar-header h3{margin:0;font-family:'Poppins',sans-serif;font-size:.8rem;font-weight:800;line-height:1.25;color:#fff}
        .subtitle{margin:.1rem 0 0;color:rgba(255,255,255,.35);font-size:.57rem;text-transform:uppercase;letter-spacing:1.8px}

        .nav-section{padding:.5rem .85rem}
        .nav-item{
            display:flex;align-items:center;gap:.65rem;padding:.56rem .75rem;border-radius:10px;
            color:rgba(255,255,255,.7);text-decoration:none;font-size:.84rem;font-weight:700;
            transition:.18s ease;
        }
        .nav-item i{width:18px;text-align:center}
        .nav-item:hover{background:rgba(255,255,255,.09);color:#fff}

        .main-content{margin-left:260px;width:calc(100% - 260px);min-height:100vh;display:flex;flex-direction:column}
        .top-header{
            height:62px;background:var(--surf);border-bottom:1px solid var(--bdr);
            display:flex;align-items:center;justify-content:space-between;
            padding:0 2rem;position:sticky;top:0;z-index:100;box-shadow:var(--s1);
        }
        .page-title{margin:0;font-size:1.05rem;font-weight:700;font-family:'Poppins',sans-serif}
        .content-area{padding:1.875rem 2rem}

        .task-details-card{
            background:var(--surf);border:1px solid var(--bdr);border-radius:var(--r3);
            box-shadow:var(--s1);overflow:hidden;transition:box-shadow .25s;
        }
        .task-details-card:hover{box-shadow:var(--s2);}
        .task-header{
            padding:1rem 1.4rem;border-bottom:1px solid var(--bdr);
            display:flex;align-items:center;justify-content:space-between;gap:1rem;
            background:var(--surf);
        }
        .task-title h2{margin:0;font-family:'Poppins',sans-serif;font-size:1.12rem;font-weight:800;color:var(--t1)}
        .task-id{display:inline-block;margin-top:.2rem;font-size:.73rem;font-weight:800;letter-spacing:.4px;color:var(--maroon)}

        .badge{display:inline-flex;align-items:center;padding:.24rem .62rem;border-radius:20px;font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.4px}
        .badge-reported{background:var(--gold-p);color:#92600A}
        .badge-assigned{background:var(--gold-p);color:#92600A}
        .badge-in_progress{background:#EBF5FB;color:#154360}
        .badge-completed,.badge-verified,.badge-closed{background:#EAFAF1;color:#145A32}
        .badge-critical{background:#FDEDEC;color:#7B241C}
        .badge-high{background:#FEF0E7;color:#873600}
        .badge-medium{background:#EBF5FB;color:#154360}
        .badge-low{background:var(--surf2);color:var(--t3);border:1px solid var(--bdr)}

        .task-content{padding:1rem;display:grid;gap:.85rem;background:var(--surf)}
        .task-info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem}
        .info-item{background:var(--surf);border:1px solid var(--bdr);border-radius:10px;padding:.68rem .78rem;box-shadow:var(--s1)}
        .info-item label{display:block;font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);margin-bottom:.2rem}
        .info-item span{font-size:.85rem;color:var(--t1);font-weight:700}

        .task-description,.task-notes,.task-instructions{
            background:var(--surf);border:1px solid var(--bdr);border-radius:12px;padding:.9rem .95rem;box-shadow:var(--s1);
        }
        .task-description h3,.task-notes h3,.task-instructions h3{
            margin:0 0 .45rem;
            font-size:.88rem;font-family:'Poppins',sans-serif;font-weight:700;color:var(--t1);
            display:flex;align-items:center;gap:.4rem;
        }
        .task-description h3::before,.task-notes h3::before,.task-instructions h3::before{
            content:'';width:8px;height:8px;border-radius:50%;background:var(--maroon);
        }
        .task-description p,.task-notes p,.task-instructions p{margin:0;font-size:.84rem;line-height:1.6;color:var(--t2)}
        .task-title h2,.task-id,.info-item span,.task-description p,.task-notes p,.task-instructions p,.du-row div,.du-text{overflow-wrap:anywhere;word-break:break-word;}

        .task-photos{background:var(--surf2);border:1px solid var(--bdr);border-radius:12px;padding:.9rem .95rem;box-shadow:var(--s1)}
        .task-photos h3{margin:0 0 .6rem;font-size:.88rem;font-family:'Poppins',sans-serif;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:.4rem}
        .task-photos h3::before{content:'';width:8px;height:8px;border-radius:50%;background:var(--maroon)}
        .photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.55rem}
        .photo-item{display:block;border:1px solid var(--bdr);border-radius:10px;overflow:hidden;background:#fff}
        .photo-item img{display:block;width:100%;height:120px;object-fit:cover}

        .task-actions{
            border-top:1px solid var(--bdr);padding:.95rem 1rem;
            display:flex;gap:.55rem;flex-wrap:wrap;background:var(--surf);
        }
        .alert{padding:.7rem .8rem;border-radius:10px;font-size:.82rem}
        .alert-info{background:#EBF5FB;border:1px solid #cfe2f3;color:#154360}

        .btn{display:inline-flex;align-items:center;gap:.4rem;padding:.48rem .9rem;border:none;border-radius:8px;font-size:.82rem;font-weight:800;text-decoration:none;cursor:pointer}
        .btn-primary{background:linear-gradient(135deg,var(--maroon),var(--maroon-l));color:#fff;box-shadow:0 3px 0 var(--maroon-d)}
        .btn-primary:hover{transform:translateY(-1px)}
        .btn-success{background:linear-gradient(135deg,#1D8348,#28a85a);color:#fff;box-shadow:0 3px 0 #145A32}
        .btn-secondary{background:var(--surf2);border:1px solid var(--bdr);color:var(--t2);box-shadow:0 2px 0 var(--bdr)}
        .du-mo{position:fixed;inset:0;background:rgba(26,10,10,.62);backdrop-filter:blur(6px);z-index:550;display:none;align-items:center;justify-content:center;padding:1rem}
        .du-mo.open{display:flex}
        .du-modal{background:#fff;border:1px solid var(--bdr);border-radius:16px;box-shadow:0 16px 48px rgba(90,16,16,.2);width:min(680px,95vw);max-height:92vh;overflow:auto}
        .du-head{padding:1.1rem 1.25rem .95rem;border-bottom:1px solid var(--bdr);display:flex;align-items:flex-start;justify-content:space-between;gap:.8rem}
        .du-head h3{margin:0;font-family:'Poppins',sans-serif;font-size:1.02rem;font-weight:800;color:var(--t1);line-height:1.2}
        .du-head p{margin:.2rem 0 0;font-size:.72rem;color:var(--t3)}
        .du-x{background:var(--surf2);border:1px solid var(--bdr);width:30px;height:30px;border-radius:8px;cursor:pointer}

        .du-body{padding:1.05rem 1.25rem 1.15rem;display:grid;gap:.62rem}
        .du-row{display:grid;grid-template-columns:130px minmax(0,1fr);gap:.72rem;align-items:start;padding:.22rem 0}
        .du-row label{font-size:.62rem;font-weight:900;text-transform:uppercase;letter-spacing:.75px;color:var(--t3);line-height:1.2;padding-top:.2rem}
        .du-row > div{min-width:0;font-size:.82rem;line-height:1.42;color:var(--t1)}

        .du-select,.du-text{width:100%;border:1px solid var(--bdr);border-radius:10px;padding:.5rem .68rem;font-family:'Nunito',sans-serif;font-size:.82rem;background:var(--surf2);color:var(--t1)}
        .du-select{height:35px}
        .du-select:focus,.du-text:focus{outline:none;border-color:var(--maroon);box-shadow:0 0 0 3px rgba(123,29,29,.08)}
        .du-text{min-height:96px;resize:vertical;line-height:1.42}

        .du-foot{padding:.9rem 1.25rem 1.15rem;border-top:1px solid var(--bdr);display:flex;justify-content:flex-end;gap:.5rem;flex-wrap:wrap}

        @media (max-width: 768px){
            .du-head{padding:.95rem 1rem .85rem}
            .du-body{padding:.9rem 1rem 1rem;gap:.48rem}
            .du-foot{padding:.8rem 1rem 1rem}
            .du-row{grid-template-columns:1fr;gap:.28rem;padding:.14rem 0}
            .du-row label{padding-top:0}
            .du-foot .btn{width:100%;justify-content:center}
        }

        .empty-task-state{
            max-width:860px;margin:0 auto;background:linear-gradient(145deg,#ffffff,#fff9ef 55%,#fff2db);
            border:1px solid #eadfcf;border-left:5px solid #d4a017;border-radius:16px;padding:1.25rem;box-shadow:0 12px 32px rgba(90,16,16,.08);
        }
        .ets-top{display:flex;gap:.9rem;align-items:flex-start;margin-bottom:.95rem}
        .ets-ico{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#7B1D1D,#9B2C2C);color:#fff;font-size:1.25rem;flex-shrink:0}
        .ets-title{margin:0;font-size:1.22rem;font-weight:800;color:#1a0a0a}
        .ets-desc{margin-top:.35rem;color:#6b4040;font-size:.92rem;line-height:1.5}
        .ets-actions{display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.8rem}
        .ets-tips{margin-top:.9rem;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.6rem}
        .ets-tip{background:#fff;border:1px solid #eadfcf;border-radius:10px;padding:.6rem .7rem;font-size:.82rem;color:#6b4040}

        @media (max-width: 960px){
            .task-info-grid{grid-template-columns:1fr}
        }
        @media (max-width: 768px) {
            .sidebar{transform:translateX(-100%)}
            .main-content{margin-left:0;width:100%}
            .content-area{padding:1.25rem 1rem}
            .ets-top{flex-direction:column}
            .ets-tips{grid-template-columns:1fr}
        }        /* My Tasks strict parity */
        .sidebar .sidebar-logo{
            box-shadow:0 4px 0 rgba(0,0,0,.3),0 6px 14px rgba(0,0,0,.2);
            border:2px solid rgba(212,160,23,.45);
            transition:transform .25s,box-shadow .25s;
        }
        .sidebar .sidebar-logo:hover{
            transform:scale(1.08) rotate(-6deg);
            box-shadow:0 4px 0 rgba(0,0,0,.3),0 10px 20px rgba(212,160,23,.35);
        }
        .nav-section{padding:.25rem 0}
        .nav-sep{font-size:.54rem;text-transform:uppercase;letter-spacing:2.5px;color:rgba(255,255,255,.18);padding:.5rem 1.25rem .2rem;font-weight:700;}
        .nav-item{
            display:flex;align-items:center;gap:.65rem;padding:.56rem 1.25rem;border-radius:0;
            color:rgba(255,255,255,.42);text-decoration:none;font-family:'DM Sans',sans-serif;
            font-size:.82rem;font-weight:500;transition:all .16s;position:relative;
        }
        .nav-item .ni{
            width:30px;height:30px;border-radius:var(--r1);display:flex;align-items:center;justify-content:center;
            font-size:.78rem;background:rgba(255,255,255,.05);flex-shrink:0;transition:all .22s;
        }
        .nav-item i{width:auto;text-align:center}
        .nav-item:hover{color:rgba(255,255,255,.82);background:none}
        .nav-item:hover .ni{background:rgba(255,255,255,.12);transform:scale(1.08)}

        .top-header{
            height:62px;background:var(--surf);border-bottom:1px solid var(--bdr);
            display:flex;align-items:center;justify-content:space-between;
            padding:0 2rem;position:sticky;top:0;z-index:100;box-shadow:var(--s1);
        }
        .th-left{display:flex;align-items:center;gap:.75rem}
        .tb-seal{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--maroon-d),var(--maroon));border:2px solid var(--bdr);display:flex;align-items:center;justify-content:center;box-shadow:var(--s1);cursor:pointer;transition:transform .3s,box-shadow .3s;flex-shrink:0}
        .tb-seal:hover{transform:scale(1.1) rotate(10deg);box-shadow:0 0 14px rgba(212,160,23,.4);border-color:var(--gold)}
        .tb-seal i{font-size:.8rem;color:var(--gold-l)}
        .page-title{margin:0;font-size:1.05rem;font-weight:700;font-family:'Poppins',sans-serif}
        .tb-bread{font-size:.72rem;color:var(--t3);display:flex;align-items:center;gap:.3rem}
        .tb-bread i{font-size:.6rem}
        .tb-bread a{color:inherit;text-decoration:none}

        .task-details-card{
            background:var(--surf) !important;
            border:1px solid var(--bdr) !important;
            border-radius:var(--r3) !important;
            box-shadow:var(--s1) !important;
            overflow:hidden;
            transition:box-shadow .25s ease;
        }
        .task-details-card:hover{box-shadow:var(--s2) !important;}
        .task-header{
            padding:1rem 1.4rem !important;
            border-bottom:1px solid var(--bdr) !important;
            background:var(--surf) !important;
            display:flex;align-items:center;justify-content:space-between;gap:1rem;
        }
        .task-title h2{margin:0;font-family:'Poppins',sans-serif;font-size:1.12rem;font-weight:800;color:var(--t1)}
        .task-id{color:var(--maroon);font-size:.73rem;font-weight:800;letter-spacing:.4px}

        .task-content{background:var(--surf) !important;border-color:var(--bdr) !important;padding:1rem 1.1rem;display:grid;gap:.85rem}
        .info-item,.task-description,.task-notes,.task-instructions{background:#fff !important;border:1px solid var(--bdr) !important;box-shadow:none !important;border-radius:var(--r2)}
        .task-actions{background:var(--surf) !important;border-top:1px solid var(--bdr) !important;padding:1rem 1.1rem}
        .task-description h3,.task-notes h3,.task-instructions h3{font-family:'Poppins',sans-serif;color:var(--t1)}        /* Shared Sidebar Parity */
        .sidebar{position:fixed;left:0;top:0;width:260px;height:100vh;background:linear-gradient(168deg,#1E0202 0%,#350808 38%,#4A0E0E 68%,#3A0808 100%) !important;display:flex;flex-direction:column;z-index:300;box-shadow:5px 0 30px rgba(45,5,5,.38) !important;overflow:hidden}
        .sidebar::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 80% 40% at 50% 0%,rgba(212,160,23,.13),transparent);pointer-events:none}
        .sb-seal{padding:1.4rem 1.25rem 1rem;border-bottom:1px solid rgba(255,255,255,.06);position:relative;z-index:1;display:flex;align-items:center;gap:.75rem}
        .seal-wrap{position:relative;flex-shrink:0;width:46px;height:46px}
        .seal-glow{position:absolute;inset:-3px;border-radius:50%;background:conic-gradient(var(--gold),var(--gold-l),var(--gold));animation:sglow 6s linear infinite;opacity:.7}
        @keyframes sglow{from{transform:rotate(0)}to{transform:rotate(360deg)}}
        .seal-inner{position:absolute;inset:2px;border-radius:50%;background:var(--maroon-d);display:flex;align-items:center;justify-content:center;overflow:hidden}
        .seal-inner img{width:100%;height:100%;border-radius:50%;object-fit:cover}
        .sb-brand strong{display:block;font-family:'Outfit',sans-serif;font-size:.8rem;font-weight:800;color:#fff;line-height:1.25}
        .sb-brand span{font-size:.57rem;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:1.8px}
        .sb-user{margin:.45rem 1rem .2rem;background:rgba(255,255,255,.055);border:1px solid rgba(255,255,255,.07);border-radius:var(--r2);padding:.65rem .875rem;display:flex;align-items:center;gap:.65rem;position:relative;z-index:1}
        .u-av{width:32px;height:32px;flex-shrink:0;background:linear-gradient(135deg,var(--gold),var(--maroon-l));border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-weight:800;font-size:.77rem;color:#fff;box-shadow:0 4px 0 rgba(0,0,0,.3),0 6px 14px rgba(0,0,0,.2)}
        .u-name{display:block;font-size:.8rem;color:#fff;font-weight:600}.u-meta{display:flex;align-items:center;gap:.3rem;margin-top:.1rem}
        .u-dot{width:6px;height:6px;border-radius:50%;background:#4ade80;box-shadow:0 0 6px #4ade80}.u-role{font-size:.58rem;color:rgba(255,255,255,.32);text-transform:uppercase;letter-spacing:1px}
        .sb-nav{flex:1;padding:.25rem 0;overflow-y:auto;position:relative;z-index:1}
        .nav-sep{font-size:.54rem;text-transform:uppercase;letter-spacing:2.5px;color:rgba(255,255,255,.18);padding:.5rem 1.25rem .2rem;font-weight:700}
        .nav-item{display:flex;align-items:center;gap:.65rem;padding:.56rem 1.25rem;color:rgba(255,255,255,.42);background:none;border:none;width:100%;text-align:left;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;text-decoration:none;position:relative}
        .nav-item .ni{width:30px;height:30px;border-radius:var(--r1);display:flex;align-items:center;justify-content:center;font-size:.78rem;background:rgba(255,255,255,.05);flex-shrink:0}
        .nav-item.active{color:#fff}.nav-item.active .ni{background:linear-gradient(135deg,var(--gold),var(--gold-l));color:var(--maroon-d)}
        .nav-item.active::after{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(to bottom,var(--gold),var(--gold-l));border-radius:0 3px 3px 0}
        .sb-foot{padding:.55rem 1rem .95rem;border-top:1px solid rgba(255,255,255,.07);position:relative;z-index:1}
        .logout-btn{width:100%;display:flex;align-items:center;gap:.65rem;padding:.52rem .78rem;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);color:rgba(255,255,255,.42);border-radius:var(--r1);font-size:.8rem;font-family:'DM Sans',sans-serif;font-weight:500;text-decoration:none}
</style>
</head>
<body>
        <aside class="sidebar" id="sidebar">
      <div class="sb-seal">
        <div class="seal-wrap">
          <div class="seal-glow"></div>
          <div class="seal-inner">
            <img src="assets/logs.png" alt="BEC Seal">
          </div>
        </div>
        <div class="sb-brand">
          <strong>Batangas Eastern Colleges</strong>
          <span>Equipment Management</span>
        </div>
      </div>

      <div class="sb-user">
        <div class="u-av"><?php echo htmlspecialchars($initials); ?></div>
        <div>
          <span class="u-name"><?php echo htmlspecialchars($technician_name); ?></span>
          <div class="u-meta">
            <div class="u-dot"></div>
            <span class="u-role">Technician</span>
          </div>
        </div>
      </div>

      <nav class="sb-nav">
        <div class="nav-sep">Main Menu</div>
        <a href="technician_dashboard.php" class="nav-item">
          <div class="ni"><i class="fas fa-th-large"></i></div>
          Dashboard
        </a>
        <a href="technician_tasks.php" class="nav-item">
          <div class="ni"><i class="fas fa-clipboard-list"></i></div>
          My Tasks
        </a>

        <div class="nav-sep">Work</div>
        <a href="technician_task_details.php" class="nav-item active">
          <div class="ni"><i class="fas fa-wrench"></i></div>
          Task Details
        </a>
        <a href="technician_history.php" class="nav-item">
          <div class="ni"><i class="fas fa-history"></i></div>
          Work History
        </a>

        <div class="nav-sep">Account</div>
        <a href="technician_profile.php" class="nav-item">
          <div class="ni"><i class="fas fa-user-cog"></i></div>
          Profile
        </a>
      </nav>

      <div class="sb-foot">
        <a href="logout.php" class="logout-btn">
          <i class="fas fa-sign-out-alt"></i>
          Logout
        </a>
      </div>
    </aside>

    <div class="main-content">
        <div class="top-header">
            <div class="th-left">
                <div class="tb-seal" onclick="window.location='technician_dashboard.php'">
                    <i class="fas fa-tools"></i>
                </div>
                <div>
                    <h1 class="page-title">Task Details</h1>
                    <div class="tb-bread">
                        <a href="technician_dashboard.php">Dashboard</a>
                        <i class="fas fa-chevron-right"></i>
                        <span>Task Details</span>
                    </div>
                </div>
            </div>
            <a href="technician_tasks.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Tasks
            </a>
        </div>

        <div class="content-area">
            <?php if ($error): ?>
            <div class="empty-task-state">
                <div class="ets-top">
                    <div class="ets-ico"><i class="fas fa-clipboard-question"></i></div>
                    <div>
                        <h2 class="ets-title">Open a task to view full details</h2>
                        <div class="ets-desc"><?php echo htmlspecialchars($error); ?></div>
                        <div class="ets-actions">
                            <a href="technician_tasks.php" class="btn btn-primary"><i class="fas fa-tasks"></i> Open My Tasks</a>
                            <a href="technician_history.php" class="btn btn-secondary"><i class="fas fa-history"></i> Work History</a>
                            <a href="technician_dashboard.php" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
                        </div>
                    </div>
                </div>
                <div class="ets-tips">
                    <div class="ets-tip"><strong>Best path:</strong> Open details from the My Tasks list.</div>
                    <div class="ets-tip"><strong>Common cause:</strong> This page was opened directly without a task ID.</div>
                    <div class="ets-tip"><strong>Access:</strong> You can only update tasks assigned to you.</div>
                </div>
            </div>
            <?php else: ?>
            <div class="task-details-card">
                <div class="task-header">
                    <div class="task-title">
                        <h2><?php echo htmlspecialchars($task['equipment_name']); ?></h2>
                        <span class="task-id">Task ID: <?php echo htmlspecialchars($task['report_id']); ?></span>
                    </div>
                    <div class="task-status">
                        <span class="badge badge-<?php echo getStatusClass($task['status']); ?>">
                            <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($task['status']))); ?>
                        </span>
                    </div>
                </div>

                <div class="task-content">
                    <div class="task-info-grid">
                        <div class="info-item">
                            <label>Asset Tag:</label>
                            <span><?php echo htmlspecialchars($task['asset_tag']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Location:</label>
                            <span><?php echo htmlspecialchars($task['location'] ?? 'Unspecified'); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Priority:</label>
                            <span class="badge badge-<?php echo getPriorityClass($task['priority']); ?>">
                                <?php echo htmlspecialchars(ucfirst($task['priority'])); ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <label>Reported Date:</label>
                            <span><?php echo date('M d, Y H:i', strtotime($task['report_date'])); ?></span>
                        </div>
                        <?php if (!empty($task['assigned_date'])): ?>
                        <div class="info-item">
                            <label>Assigned Date:</label>
                            <span><?php echo date('M d, Y H:i', strtotime($task['assigned_date'])); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($task['completion_date'])): ?>
                        <div class="info-item">
                            <label>Completed Date:</label>
                            <span><?php echo date('M d, Y H:i', strtotime($task['completion_date'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="task-description">
                        <h3>Issue Description</h3>
                        <p><?php echo nl2br(htmlspecialchars($task['issue_description'] ?? 'No description provided.')); ?></p>
                    </div>

                    <?php
                    $taskPhotos = [];
                    if (!empty($task['photos']) && is_array($task['photos'])) {
                        $taskPhotos = $task['photos'];
                    } elseif (!empty($task['defect_photos'])) {
                        $decodedPhotos = json_decode((string)$task['defect_photos'], true);
                        if (is_array($decodedPhotos)) {
                            $taskPhotos = $decodedPhotos;
                        }
                    } elseif (!empty($task['photo_paths'])) {
                        $decodedPhotos = json_decode((string)$task['photo_paths'], true);
                        if (is_array($decodedPhotos)) {
                            $taskPhotos = $decodedPhotos;
                        } elseif (is_string($task['photo_paths'])) {
                            $taskPhotos = [(string)$task['photo_paths']];
                        }
                    } elseif (!empty($task['photo'])) {
                        $taskPhotos = [(string)$task['photo']];
                    } elseif (!empty($task['image_path'])) {
                        $taskPhotos = [(string)$task['image_path']];
                    }

                    $taskPhotos = array_values(array_filter($taskPhotos, function ($photoPath) {
                        return is_string($photoPath) && trim($photoPath) !== '';
                    }));
                    ?>

                    <?php if (!empty($taskPhotos)): ?>
                    <div class="task-photos">
                        <h3>Uploaded Photos</h3>
                        <div class="photo-grid">
                            <?php foreach ($taskPhotos as $photo):
                                $photoSrc = str_replace('\\', '/', trim($photo));
                                $isAbsoluteUrl = preg_match('/^https?:\/\//i', $photoSrc) === 1;
                                $isDataUri = strpos($photoSrc, 'data:') === 0;
                                if (!$isAbsoluteUrl && !$isDataUri) {
                                    $photoSrc = ltrim($photoSrc, '/');
                                }
                            ?>
                            <a class="photo-item" href="<?php echo htmlspecialchars($photoSrc); ?>" target="_blank" rel="noopener">
                                <img src="<?php echo htmlspecialchars($photoSrc); ?>" alt="Defect photo">
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($task['technician_notes'] ?? '')): ?>
                    <div class="task-notes">
                        <h3>Technician Notes</h3>
                        <p><?php echo nl2br(htmlspecialchars($task['technician_notes'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($task['handler_instructions'] ?? '')): ?>
                    <div class="task-instructions">
                        <h3>Handler Instructions</h3>
                        <p><?php echo nl2br(htmlspecialchars($task['handler_instructions'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="task-actions">
                    <?php if ($can_update): ?>
                        <?php if ($task['status'] === 'assigned'): ?>
                        <button class="btn btn-primary" onclick="quickStatusUpdate('<?php echo $task['report_id']; ?>', 'in_progress')">
                            <i class="fas fa-play"></i> Start Working
                        </button>
                        <?php elseif ($task['status'] === 'in_progress'): ?>
                        <button class="btn btn-success" onclick="quickStatusUpdate('<?php echo $task['report_id']; ?>', 'completed')">
                            <i class="fas fa-check"></i> Mark Complete
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-secondary" type="button" onclick="openDetailedUpdateModal()">
                            <i class="fas fa-edit"></i> Detailed Update
                        </button>
                    <?php elseif ($is_unassigned): ?>
                        <button class="btn btn-success" onclick="claimTask('<?php echo $task['report_id']; ?>')">
                            <i class="fas fa-hand-paper"></i> Claim Task
                        </button>
                    <?php else: ?>
                        <div class="alert alert-info" style="margin-top:10px;">
                            <i class="fas fa-info-circle"></i>
                            This task is assigned to another technician. You can view details but cannot update it.
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($can_update): ?>
                <div class="du-mo" id="detailUpdateModal" aria-hidden="true">
                    <div class="du-modal" role="dialog" aria-modal="true" aria-labelledby="duTitle">
                        <div class="du-head">
                            <div>
                                <h3 id="duTitle">Detailed Update</h3>
                                <p>Update status and add technician notes for this task.</p>
                            </div>
                            <button class="du-x" type="button" onclick="closeDetailedUpdateModal()"><i class="fas fa-times"></i></button>
                        </div>
                        <form method="POST" action="technician_update_status.php" onsubmit="document.getElementById('duLogNote').value=document.getElementById('duNotes').value;">
                            <input type="hidden" name="report_id" value="<?php echo htmlspecialchars((string)$task['report_id']); ?>">
                            <input type="hidden" name="redirect" value="technician_task_details.php?report_id=<?php echo urlencode((string)$task['report_id']); ?>">
                            <input type="hidden" name="log_note" id="duLogNote" value="">
                            <div class="du-body">
                                <div class="du-row">
                                    <label>Task</label>
                                    <div><strong><?php echo htmlspecialchars((string)($task['equipment_name'] ?? 'Equipment')); ?></strong> <span style="color:var(--t3);font-size:.78rem;">(<?php echo htmlspecialchars((string)$task['report_id']); ?>)</span></div>
                                </div>
                                <div class="du-row">
                                    <label>Current Status</label>
                                    <div><span class="badge badge-<?php echo getStatusClass((string)($task['status'] ?? 'assigned')); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_',' ',(string)($task['status'] ?? 'assigned')))); ?></span></div>
                                </div>
                                <div class="du-row">
                                    <label for="duStatus">New Status</label>
                                    <select class="du-select" id="duStatus" name="new_status" required>
                                        <option value="assigned" <?php echo (($task['status'] ?? '')==='assigned') ? 'selected' : ''; ?>>Assigned</option>
                                        <option value="in_progress" <?php echo (($task['status'] ?? '')==='in_progress') ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="completed" <?php echo in_array(($task['status'] ?? ''), ['completed','verified','closed'], true) ? 'selected' : ''; ?>>Completed</option>
                                    </select>
                                </div>
                                <div class="du-row">
                                    <label for="duNotes">Technician Notes</label>
                                    <textarea class="du-text" id="duNotes" name="notes" placeholder="Add work details, parts replaced, and findings..."></textarea>
                                </div>
                            </div>
                            <div class="du-foot">
                                <button class="btn btn-secondary" type="button" onclick="closeDetailedUpdateModal()"><i class="fas fa-times"></i> Cancel</button>
                                <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save Update</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
        function quickStatusUpdate(reportId, newStatus) {
            const label = newStatus.replace('_', ' ');
            const ok = confirm('Update this task to "' + label.charAt(0).toUpperCase() + label.slice(1) + '"?');
            if (!ok) return;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'technician_update_status.php';

            const fields = {
                report_id: reportId,
                new_status: newStatus,
                redirect: window.location.pathname + '?report_id=' + encodeURIComponent(reportId)
            };

            Object.keys(fields).forEach((k) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = k;
                input.value = fields[k];
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();
        }

        function openDetailedUpdateModal() {
            const modal = document.getElementById('detailUpdateModal');
            if (modal) modal.classList.add('open');
        }

        function closeDetailedUpdateModal() {
            const modal = document.getElementById('detailUpdateModal');
            if (modal) modal.classList.remove('open');
        }

        document.addEventListener('click', function(e){
            const modal = document.getElementById('detailUpdateModal');
            if (modal && e.target === modal) closeDetailedUpdateModal();
        });
        function claimTask(reportId) {
            const ok = confirm('Claim this task now?');
            if (!ok) return;

            fetch('technician_claim_task.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: 'report_id=' + encodeURIComponent(reportId)
            })
            .then((res) => res.json())
            .then((data) => {
                if (data && data.success) {
                    window.location.reload();
                } else {
                    alert((data && data.message) ? data.message : 'Unable to claim task.');
                }
            })
            .catch(() => {
                alert('Unable to claim task right now.');
            });
        }
    </script>
    <script src="js/loading_utils.js"></script>
    <script src="js/technician_dashboard.js"></script>
</body>
</html>















