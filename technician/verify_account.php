<?php
require_once __DIR__ . '/../config/database.php';

$conn = getDBConnection();
$token = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));
$error = '';
$done = false;
$invite = null;

/** Load a valid, unexpired invitation. */
if ($token !== '') {
    $stmt = $conn->prepare("SELECT user_id, fullname, email, department, specialization, position, invite_expires_at
                            FROM users WHERE invite_token = ? AND status = 'invited' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $invite = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if ($invite && !empty($invite['invite_expires_at']) && strtotime((string)$invite['invite_expires_at']) < time()) {
        $invite = null;
        $error = 'This invitation link has expired. Please ask the PMO to send a new invitation.';
    }
}
if ($token === '' || (!$invite && $error === '')) {
    $error = 'This invitation link is invalid or has already been used.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invite) {
    $fullname  = trim((string)($_POST['fullname'] ?? ''));
    $empId     = trim((string)($_POST['employee_id'] ?? ''));
    $contact   = trim((string)($_POST['contact_number'] ?? ''));
    $dept      = trim((string)($_POST['department'] ?? ''));
    $spec      = trim((string)($_POST['specialization'] ?? ''));
    $pass      = (string)($_POST['password'] ?? '');
    $pass2     = (string)($_POST['password_confirm'] ?? '');

    if ($fullname === '' || $empId === '' || $contact === '' || $dept === '' || $spec === '') {
        $error = 'Please complete all profile fields.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pass !== $pass2) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $uid  = (string)$invite['user_id'];
        $upd = $conn->prepare("UPDATE users
            SET fullname = ?, employee_id = ?, phone = ?, department = ?, specialization = ?,
                password = ?, status = 'active', invite_token = NULL, invite_expires_at = NULL
            WHERE user_id = ? AND status = 'invited'");
        if ($upd) {
            $upd->bind_param('sssssss', $fullname, $empId, $contact, $dept, $spec, $hash, $uid);
            $upd->execute();
            $ok = $upd->affected_rows > 0;
            $upd->close();
            if ($ok) {
                if (function_exists('logActivity')) { try { logActivity($uid, 'technician', 'account.verified', 'Technician completed identity verification'); } catch (\Throwable $e) {} }
                $done = true;
            } else {
                $error = 'We could not activate the account. The link may have already been used.';
            }
        } else {
            $error = 'Something went wrong. Please try again.';
        }
    }
    // Refresh invite snapshot for re-render on validation error
    if (!$done) {
        $invite['fullname'] = $fullname ?: $invite['fullname'];
        $invite['department'] = $dept ?: $invite['department'];
        $invite['specialization'] = $spec ?: $invite['specialization'];
    }
}

function vh($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="theme-color" content="#4A0E0E">
<title>Activate Technician Account — Batangas Eastern Colleges · PMO</title>
<link rel="icon" type="image/png" href="../assets/logs.png">
<link rel="apple-touch-icon" href="../assets/logs.png">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  :root{--m:#7B1D1D;--md:#4A0E0E;--mdd:#2D0505;--g:#C9960C;--gold-soft:#F0C040;--ink:#1C1008;--ink2:#5C3838;--ink3:#9E8070;--paper:#F8F3EA;--surface:#fff;--border:#E8DDD0;}
  *{box-sizing:border-box}
  body{margin:0;font-family:'DM Sans',system-ui,Arial,sans-serif;color:var(--ink);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.4rem;
    background:radial-gradient(120% 90% at 100% 0%,rgba(201,150,12,.08),transparent 50%),var(--paper);}
  .shell{width:100%;max-width:960px;}
  .card{display:grid;grid-template-columns:1.02fr 1fr;background:var(--surface);border:1px solid var(--border);
    border-radius:22px;overflow:hidden;box-shadow:0 24px 70px rgba(44,10,10,.16),0 4px 14px rgba(44,10,10,.08);}

  /* ── Brand / invitation panel ── */
  .brand{position:relative;overflow:hidden;color:#fff;padding:2.4rem 2.1rem;display:flex;flex-direction:column;isolation:isolate;}
  .brand::before{content:'';position:absolute;inset:0;z-index:-2;
    background:linear-gradient(155deg,rgba(45,5,5,.92) 0%,rgba(74,14,14,.86) 55%,rgba(123,29,29,.8) 100%),
      url('../assets/Landing Page Background.jpg') center/cover no-repeat;}
  .brand::after{content:'';position:absolute;inset:0;z-index:-1;pointer-events:none;opacity:.5;
    background-image:radial-gradient(circle,rgba(255,255,255,.07) 1px,transparent 1px);background-size:20px 20px;
    -webkit-mask-image:radial-gradient(ellipse 80% 60% at 80% 10%,#000,transparent 75%);mask-image:radial-gradient(ellipse 80% 60% at 80% 10%,#000,transparent 75%);}
  .seal{width:58px;height:58px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;
    box-shadow:0 0 0 3px rgba(201,150,12,.5),0 8px 22px rgba(0,0,0,.35);}
  .seal img{width:100%;height:100%;object-fit:cover}
  .b-eyebrow{margin-top:1.5rem;font-size:.62rem;font-weight:800;letter-spacing:1.8px;text-transform:uppercase;color:var(--gold-soft);}
  .brand h1{font-family:'Fraunces',serif;font-weight:700;font-size:1.72rem;line-height:1.16;letter-spacing:-.02em;margin:.55rem 0 .7rem;text-shadow:0 2px 18px rgba(0,0,0,.35);}
  .brand p.lead{font-size:.9rem;line-height:1.7;color:rgba(255,255,255,.82);margin:0;}
  .steps{list-style:none;margin:1.6rem 0 0;padding:0;display:flex;flex-direction:column;gap:.9rem;}
  .steps li{display:flex;gap:.75rem;align-items:flex-start;font-size:.84rem;line-height:1.45;color:rgba(255,255,255,.9);}
  .steps .n{flex-shrink:0;width:26px;height:26px;border-radius:50%;background:rgba(201,150,12,.22);border:1px solid rgba(201,150,12,.5);
    color:var(--gold-soft);font-weight:700;font-size:.78rem;display:flex;align-items:center;justify-content:center;}
  .b-foot{margin-top:auto;padding-top:1.7rem;}
  .b-trust{display:inline-flex;align-items:center;gap:.5rem;font-size:.72rem;font-weight:600;color:rgba(255,255,255,.82);
    background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);padding:.4rem .8rem;border-radius:20px;-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);}
  .b-trust i{color:var(--gold-soft)}
  .b-motto{margin-top:1rem;font-size:.66rem;letter-spacing:.4px;color:rgba(255,255,255,.55);line-height:1.5;font-style:italic;}

  /* ── Form panel ── */
  .side{padding:2.4rem 2.2rem;display:flex;flex-direction:column;}
  .s-title{font-family:'Fraunces',serif;font-weight:700;font-size:1.4rem;letter-spacing:-.01em;color:var(--ink);margin:0;}
  .s-sub{font-size:.84rem;color:var(--ink2);line-height:1.6;margin:.45rem 0 1.3rem;}
  .email-badge{display:inline-flex;align-items:center;gap:.4rem;background:var(--paper);border:1px solid var(--border);
    border-radius:20px;padding:.28rem .7rem;font-size:.78rem;font-weight:600;color:var(--m);margin-top:.15rem;}
  .email-badge i{color:var(--g);font-size:.72rem;}
  .divider{display:flex;align-items:center;gap:.6rem;margin:.4rem 0 1rem;font-size:.66rem;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;color:var(--ink3);}
  .divider::after{content:'';flex:1;height:1px;background:var(--border);}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:.8rem;}
  .fg{display:flex;flex-direction:column;gap:.32rem;}
  .fg.full{grid-column:1/-1;}
  label{font-size:.7rem;font-weight:700;color:var(--ink2);text-transform:uppercase;letter-spacing:.4px;}
  .ctrl{position:relative;display:flex;align-items:center;}
  .ctrl > i{position:absolute;left:.8rem;color:var(--ink3);font-size:.82rem;pointer-events:none;}
  input{width:100%;padding:.72rem .85rem .72rem 2.3rem;border:1.5px solid var(--border);border-radius:11px;font:inherit;font-size:.9rem;color:var(--ink);background:#fff;transition:border-color .15s,box-shadow .15s;}
  input::placeholder{color:#C4AFA8}
  input:focus{outline:none;border-color:var(--m);box-shadow:0 0 0 3px rgba(123,29,29,.09);}
  .toggle{position:absolute;right:.5rem;background:none;border:none;color:var(--ink3);cursor:pointer;padding:.4rem;font-size:.85rem;}
  .toggle:hover{color:var(--m)}
  .hint{font-size:.72rem;color:var(--ink3);margin-top:.5rem;display:flex;align-items:center;gap:.4rem;}
  .hint.ok{color:#16A34A}.hint.bad{color:#B4232A}
  .btn{margin-top:1.3rem;width:100%;padding:.9rem;border:none;border-radius:12px;background:linear-gradient(135deg,var(--md),var(--m));color:#fff;font-family:inherit;font-weight:700;font-size:.95rem;cursor:pointer;
    box-shadow:0 4px 0 var(--mdd),0 10px 24px rgba(74,14,14,.24);transition:transform .18s,box-shadow .18s,filter .18s;display:inline-flex;align-items:center;justify-content:center;gap:.5rem;}
  .btn:hover{filter:brightness(1.06);transform:translateY(-2px);box-shadow:0 6px 0 var(--mdd),0 14px 30px rgba(74,14,14,.3);}
  .btn:active{transform:translateY(1px);box-shadow:0 2px 0 var(--mdd);}
  .privacy{margin-top:.9rem;font-size:.72rem;color:var(--ink3);line-height:1.5;display:flex;gap:.45rem;align-items:flex-start;}
  .privacy i{color:var(--g);margin-top:.1rem;}
  .alert{padding:.8rem 1rem;border-radius:11px;font-size:.84rem;margin-bottom:1.1rem;display:flex;gap:.55rem;align-items:flex-start;line-height:1.5;}
  .alert.err{background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;}
  .alert i{margin-top:.1rem;}

  /* ── Result states ── */
  .state{text-align:center;margin:auto 0;padding:1rem 0;}
  .state .ic{width:74px;height:74px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2rem;margin:0 auto 1.1rem;}
  .state.ok .ic{background:#F0FDF4;color:#16A34A;box-shadow:0 0 0 6px rgba(22,163,74,.08);}
  .state.warn .ic{background:#FEF6EC;color:#B77400;box-shadow:0 0 0 6px rgba(201,150,12,.1);}
  .state h2{font-family:'Fraunces',serif;color:var(--m);margin:.2rem 0 .5rem;font-size:1.5rem;}
  .state p{color:var(--ink2);font-size:.9rem;line-height:1.65;margin:0 auto;max-width:34ch;}
  .state a.cta{display:inline-flex;align-items:center;gap:.5rem;margin-top:1.4rem;background:linear-gradient(135deg,var(--md),var(--m));color:#fff;text-decoration:none;padding:.8rem 1.5rem;border-radius:11px;font-weight:700;box-shadow:0 4px 0 var(--mdd);}
  .state a.cta:hover{filter:brightness(1.06)}

  @media (max-width:820px){
    .card{grid-template-columns:1fr;}
    .brand{padding:2rem 1.6rem;}
    .brand h1{font-size:1.5rem;}
    .steps{display:none;}
    .b-foot{padding-top:1.2rem;}
    .side{padding:1.8rem 1.5rem;}
    .grid{grid-template-columns:1fr;}
  }
</style>
</head>
<body>
  <div class="shell">
    <div class="card">

      <!-- Brand / official invitation -->
      <aside class="brand">
        <span class="seal"><img src="../assets/logs.png" alt="BEC seal" onerror="this.style.display='none';this.parentElement.innerHTML='<i class=&quot;fas fa-graduation-cap&quot; style=&quot;color:#7B1D1D;font-size:1.5rem&quot;></i>'"></span>
        <div class="b-eyebrow">Property Management Office · Technician Onboarding</div>
        <h1>You've been invited to join the BEC technical team</h1>
        <p class="lead">Batangas Eastern Colleges welcomes you to the Property Management Office. Complete a brief, secure verification to activate your technician account and begin receiving equipment work orders.</p>
        <ol class="steps">
          <li><span class="n">1</span><span>Confirm your official profile details.</span></li>
          <li><span class="n">2</span><span>Set a private, secure password.</span></li>
          <li><span class="n">3</span><span>Sign in and start managing assigned repairs.</span></li>
        </ol>
        <div class="b-foot">
          <span class="b-trust"><i class="fas fa-shield-halved"></i> Secure &amp; confidential onboarding</span>
          <div class="b-motto">Beacons of Education, Molders of Educators<br>Batangas Eastern Colleges · Established 1940</div>
        </div>
      </aside>

      <!-- Action panel -->
      <section class="side">
        <?php if ($done): ?>
          <div class="state ok">
            <div class="ic"><i class="fas fa-check"></i></div>
            <h2>Account activated</h2>
            <p>Your technician account has been verified and is now active. You may sign in using your registered email and the password you just created.</p>
            <a class="cta" href="login.html"><i class="fas fa-right-to-bracket"></i> Proceed to Login</a>
          </div>

        <?php elseif (!$invite): ?>
          <div class="state warn">
            <div class="ic"><i class="fas fa-triangle-exclamation"></i></div>
            <h2>Invitation unavailable</h2>
            <p><?php echo vh($error); ?></p>
            <p style="margin-top:.8rem;font-size:.82rem;color:var(--ink3);">Please contact the <strong>Property Management Office</strong> to request a new invitation link.</p>
          </div>

        <?php else: ?>
          <h2 class="s-title">Activate your account</h2>
          <p class="s-sub">Confirm the details below and set a password to complete your onboarding.<br>
            <span class="email-badge"><i class="fas fa-envelope"></i> <?php echo vh($invite['email']); ?></span></p>

          <?php if ($error): ?><div class="alert err"><i class="fas fa-circle-exclamation"></i> <span><?php echo vh($error); ?></span></div><?php endif; ?>

          <form method="POST" action="verify_account.php?token=<?php echo vh($token); ?>" autocomplete="off">
            <input type="hidden" name="token" value="<?php echo vh($token); ?>">

            <div class="divider">Your details</div>
            <div class="grid">
              <div class="fg full"><label>Full Name</label>
                <div class="ctrl"><i class="fas fa-user"></i><input type="text" name="fullname" value="<?php echo vh($invite['fullname'] ?? ''); ?>" placeholder="Juan D. Dela Cruz" required></div></div>
              <div class="fg"><label>Employee ID</label>
                <div class="ctrl"><i class="fas fa-id-badge"></i><input type="text" name="employee_id" value="<?php echo vh($_POST['employee_id'] ?? ''); ?>" placeholder="EMP-0000" required></div></div>
              <div class="fg"><label>Contact Number</label>
                <div class="ctrl"><i class="fas fa-phone"></i><input type="text" name="contact_number" value="<?php echo vh($_POST['contact_number'] ?? ''); ?>" placeholder="09XX XXX XXXX" required></div></div>
              <div class="fg"><label>Department</label>
                <div class="ctrl"><i class="fas fa-building"></i><input type="text" name="department" value="<?php echo vh($invite['department'] ?? ''); ?>" placeholder="Maintenance" required></div></div>
              <div class="fg"><label>Specialization</label>
                <div class="ctrl"><i class="fas fa-screwdriver-wrench"></i><input type="text" name="specialization" value="<?php echo vh($invite['specialization'] ?? ''); ?>" placeholder="Electrical / HVAC" required></div></div>
            </div>

            <div class="divider" style="margin-top:1.3rem;">Set your password</div>
            <div class="grid">
              <div class="fg"><label>Password</label>
                <div class="ctrl"><i class="fas fa-lock"></i><input type="password" id="pw" name="password" minlength="8" placeholder="At least 8 characters" required>
                  <button type="button" class="toggle" data-t="pw" aria-label="Show password"><i class="fas fa-eye"></i></button></div></div>
              <div class="fg"><label>Confirm Password</label>
                <div class="ctrl"><i class="fas fa-lock"></i><input type="password" id="pw2" name="password_confirm" minlength="8" placeholder="Re-enter password" required>
                  <button type="button" class="toggle" data-t="pw2" aria-label="Show password"><i class="fas fa-eye"></i></button></div></div>
            </div>
            <div class="hint" id="pwHint"><i class="fas fa-circle-info"></i> Use at least 8 characters. Keep it private.</div>

            <button class="btn" type="submit"><i class="fas fa-shield-halved"></i> Verify &amp; Activate Account</button>
            <p class="privacy"><i class="fas fa-lock"></i> Your information is used solely for official BEC Property Management Office records and is kept confidential.</p>
          </form>
        <?php endif; ?>
      </section>

    </div>
  </div>

<script>
  // password visibility toggles
  document.querySelectorAll('.toggle').forEach(function(b){
    b.addEventListener('click', function(){
      var el = document.getElementById(b.getAttribute('data-t'));
      if(!el) return;
      var show = el.type === 'password';
      el.type = show ? 'text' : 'password';
      b.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
    });
  });
  // live length + match hint
  var pw = document.getElementById('pw'), pw2 = document.getElementById('pw2'), hint = document.getElementById('pwHint');
  function check(){
    if(!pw || !hint) return;
    var a = pw.value, b = pw2 ? pw2.value : '';
    if(a.length === 0){ hint.className='hint'; hint.innerHTML='<i class="fas fa-circle-info"></i> Use at least 8 characters. Keep it private.'; return; }
    if(a.length < 8){ hint.className='hint bad'; hint.innerHTML='<i class="fas fa-circle-xmark"></i> Password must be at least 8 characters.'; return; }
    if(b.length === 0){ hint.className='hint'; hint.innerHTML='<i class="fas fa-circle-check"></i> Strong enough — now confirm it.'; return; }
    if(a !== b){ hint.className='hint bad'; hint.innerHTML='<i class="fas fa-circle-xmark"></i> Passwords do not match.'; return; }
    hint.className='hint ok'; hint.innerHTML='<i class="fas fa-circle-check"></i> Passwords match.';
  }
  if(pw) pw.addEventListener('input', check);
  if(pw2) pw2.addEventListener('input', check);
</script>
<script src="../assets/page_loader.js"></script>
</body>
</html>
