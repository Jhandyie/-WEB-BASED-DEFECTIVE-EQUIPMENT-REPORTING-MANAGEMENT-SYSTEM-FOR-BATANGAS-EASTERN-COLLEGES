<?php
// admin/reset_password.php — landing page for the admin password-reset link.
// The token is validated (and consumed) server-side by admin_login_process.php (action=reset_password).
$token = trim((string)($_GET['token'] ?? ''));
$hasToken = $token !== '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/png" href="../assets/logs.png">
<title>BEC PMO — Reset Password</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  :root{--maroon:#7B1D1D;--maroon-d:#4A0E0E;--gold:#C9960C;--ink:#1C1008;--ink2:#5C3838;--ink3:#755B4E;--surface:#FFFFFF;--border:#E2D9CC;--field:#FBF9F6;--danger:#B42318;--success:#1A7A33;}
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'DM Sans',system-ui,sans-serif;color:var(--ink);background:var(--maroon-d);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;position:relative;}
  body::before{content:'';position:fixed;inset:0;z-index:-2;background:url('../assets/bec background (2).png') center/cover no-repeat;filter:blur(5px) brightness(.9);transform:scale(1.06);}
  body::after{content:'';position:fixed;inset:0;z-index:-1;background:linear-gradient(135deg,rgba(45,5,5,.60),rgba(74,14,14,.45));}
  a{color:var(--maroon);text-decoration:none;} a:hover{text-decoration:underline;}
  .auth-card{width:100%;max-width:430px;background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:0 10px 28px rgba(44,10,10,.18);overflow:hidden;}
  .auth-accent{height:3px;background:linear-gradient(90deg,var(--maroon-d),var(--maroon) 60%,var(--gold));}
  .auth-head{background:var(--maroon-d);color:#fff;padding:22px 28px 20px;text-align:center;}
  .auth-seal{width:56px;height:56px;border-radius:50%;background:#fff;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;overflow:hidden;box-shadow:0 0 0 3px rgba(255,255,255,.12);}
  .auth-seal img{width:100%;height:100%;object-fit:cover;}
  .auth-head h1{font-family:'Fraunces',serif;font-weight:700;font-size:1.05rem;line-height:1.3;}
  .auth-head .role{display:inline-block;margin-top:12px;font-size:.66rem;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--gold);border:1px solid rgba(201,150,12,.4);border-radius:4px;padding:3px 10px;}
  .auth-body{padding:26px 28px 24px;}
  .form-title{font-size:1.05rem;font-weight:700;} .form-hint{font-size:.82rem;color:var(--ink3);margin:3px 0 18px;}
  .field{margin-bottom:15px;} .field label{display:block;font-size:.76rem;font-weight:600;color:var(--ink2);margin-bottom:6px;}
  .input-wrapper{position:relative;}
  input[type=password],input[type=text]{width:100%;padding:11px 40px 11px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--field);font-size:.92rem;font-family:inherit;color:var(--ink);outline:none;transition:border-color .15s,box-shadow .15s;}
  input:focus{border-color:var(--maroon);box-shadow:0 0 0 3px rgba(123,29,29,.09);}
  .toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--ink3);cursor:pointer;padding:6px;font-size:.85rem;}
  .meter{height:5px;border-radius:99px;background:#EFE7DC;overflow:hidden;margin-top:7px;}
  .meter i{display:block;height:100%;width:0;background:var(--danger);transition:width .2s,background .2s;}
  .req-hint{font-size:.7rem;color:var(--ink3);margin-top:6px;}
  .btn{width:100%;padding:12px;border:none;border-radius:8px;background:linear-gradient(135deg,var(--maroon-d),var(--maroon));color:#fff;font-family:inherit;font-weight:700;font-size:.92rem;cursor:pointer;margin-top:6px;transition:filter .15s;}
  .btn:hover:not(:disabled){filter:brightness(1.08);} .btn:disabled{opacity:.55;cursor:not-allowed;}
  .msg{display:none;padding:11px 13px;border-radius:8px;font-size:.82rem;margin-bottom:16px;line-height:1.5;}
  .msg.err{display:block;background:#FDECEA;color:var(--danger);border:1px solid #F4C7C1;}
  .msg.ok{display:block;background:#E8F6EC;color:var(--success);border:1px solid #BEE6C7;}
  .foot{text-align:center;margin-top:16px;font-size:.8rem;color:var(--ink3);}
</style>
</head>
<body>
  <div class="auth-card">
    <div class="auth-accent"></div>
    <div class="auth-head">
      <div class="auth-seal"><img src="../assets/logs.png" alt="BEC"></div>
      <h1>Batangas Eastern Colleges</h1>
      <span class="role">Administrator · Reset Password</span>
    </div>
    <div class="auth-body">
      <div id="msg" class="msg"></div>
      <?php if (!$hasToken): ?>
        <div class="form-title">Invalid reset link</div>
        <p class="form-hint">This password-reset link is missing its token. Please request a new one from the sign-in page.</p>
        <a class="btn" href="admin_login_otp.html" style="display:block;text-align:center;text-decoration:none;">Back to sign in</a>
      <?php else: ?>
        <div class="form-title">Set a new password</div>
        <p class="form-hint">Choose a strong password of at least 8 characters for your admin account.</p>
        <form id="resetForm" autocomplete="off">
          <input type="hidden" id="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
          <div class="field">
            <label for="pw">New password</label>
            <div class="input-wrapper">
              <input type="password" id="pw" minlength="8" maxlength="72" required placeholder="At least 8 characters">
              <button type="button" class="toggle" data-t="pw"><i class="fas fa-eye"></i></button>
            </div>
            <div class="meter"><i id="meter"></i></div>
          </div>
          <div class="field">
            <label for="pw2">Confirm new password</label>
            <div class="input-wrapper">
              <input type="password" id="pw2" minlength="8" maxlength="72" required placeholder="Re-enter your new password">
              <button type="button" class="toggle" data-t="pw2"><i class="fas fa-eye"></i></button>
            </div>
            <div class="req-hint" id="matchHint"></div>
          </div>
          <button type="submit" class="btn" id="submitBtn">Reset password</button>
        </form>
        <div class="foot"><a href="admin_login_otp.html">Back to sign in</a></div>
      <?php endif; ?>
    </div>
  </div>
<script>
(function () {
  var form = document.getElementById('resetForm');
  var msg = document.getElementById('msg');
  function show(t, cls) { msg.textContent = t; msg.className = 'msg ' + cls; }
  document.querySelectorAll('.toggle').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = document.getElementById(b.dataset.t);
      f.type = f.type === 'password' ? 'text' : 'password';
      b.querySelector('i').className = f.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
    });
  });
  var pw = document.getElementById('pw'), pw2 = document.getElementById('pw2');
  if (pw) pw.addEventListener('input', function () {
    var v = pw.value, s = 0;
    if (v.length >= 8) s++; if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++; if (/\d/.test(v)) s++; if (/[^A-Za-z0-9]/.test(v)) s++;
    var m = document.getElementById('meter');
    m.style.width = (s * 25) + '%';
    m.style.background = s <= 1 ? '#B42318' : (s === 2 ? '#C9960C' : (s === 3 ? '#4E9A2A' : '#1A7A33'));
  });
  function checkMatch() {
    var h = document.getElementById('matchHint');
    if (pw2.value && pw.value !== pw2.value) { h.textContent = 'Passwords do not match.'; h.style.color = '#B42318'; }
    else if (pw2.value) { h.textContent = 'Passwords match.'; h.style.color = '#1A7A33'; }
    else { h.textContent = ''; }
  }
  if (pw2) pw2.addEventListener('input', checkMatch);

  async function getCsrf() {
    try { var r = await fetch('admin_login_process.php?action=get_csrf', { credentials: 'same-origin' }); var j = await r.json(); return j.token || ''; }
    catch (e) { return ''; }
  }
  if (form) form.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (pw.value.length < 8) { show('Password must be at least 8 characters.', 'err'); return; }
    if (pw.value !== pw2.value) { show('The two passwords do not match.', 'err'); return; }
    var btn = document.getElementById('submitBtn'); btn.disabled = true; btn.textContent = 'Resetting…';
    var body = new URLSearchParams();
    body.set('action', 'reset_password');
    body.set('token', document.getElementById('token').value);
    body.set('new_password', pw.value);
    body.set('csrf_token', await getCsrf());
    try {
      var r = await fetch('admin_login_process.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
      var j = await r.json();
      if (j.success) {
        show((j.message || 'Password reset successful.') + ' Redirecting to sign in…', 'ok');
        form.style.display = 'none';
        setTimeout(function () { window.location.href = 'admin_login_otp.html'; }, 1800);
      } else {
        show(j.message || 'Could not reset your password. The link may have expired — request a new one.', 'err');
        btn.disabled = false; btn.textContent = 'Reset password';
      }
    } catch (err) {
      show('Connection error. Please try again.', 'err');
      btn.disabled = false; btn.textContent = 'Reset password';
    }
  });
})();
</script>
</body>
</html>
