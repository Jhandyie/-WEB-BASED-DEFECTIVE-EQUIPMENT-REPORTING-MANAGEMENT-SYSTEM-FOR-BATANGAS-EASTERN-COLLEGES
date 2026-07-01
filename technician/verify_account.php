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
<title>Activate Technician Account — BEC</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  :root{--m:#7B1D1D;--md:#4A0E0E;--g:#C9960C;--ink:#1C1008;--ink2:#5C3838;--ink3:#9E8070;--paper:#F8F3EA;--surface:#fff;--border:#E8DDD0;}
  *{box-sizing:border-box}
  body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.2rem;}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:18px;max-width:540px;width:100%;overflow:hidden;box-shadow:0 12px 40px rgba(44,10,10,.12);}
  .head{background:linear-gradient(135deg,var(--md),var(--m));color:#fff;padding:1.4rem 1.6rem;}
  .head .k{font-size:.66rem;letter-spacing:1.2px;text-transform:uppercase;color:#E9C77B;}
  .head h1{margin:.25rem 0 0;font-size:1.3rem;}
  .body{padding:1.6rem;}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:.85rem;}
  .fg{display:flex;flex-direction:column;gap:.3rem;}
  .fg.full{grid-column:1/-1;}
  label{font-size:.72rem;font-weight:700;color:var(--ink2);text-transform:uppercase;letter-spacing:.4px;}
  input{padding:.7rem .85rem;border:1.5px solid var(--border);border-radius:10px;font:inherit;}
  input:focus{outline:none;border-color:var(--m);}
  .btn{margin-top:1.1rem;width:100%;padding:.85rem;border:none;border-radius:11px;background:linear-gradient(135deg,var(--md),var(--m));color:#fff;font-weight:700;font-size:.95rem;cursor:pointer;}
  .btn:hover{filter:brightness(1.08);}
  .alert{padding:.8rem 1rem;border-radius:10px;font-size:.85rem;margin-bottom:1rem;display:flex;gap:.5rem;align-items:flex-start;}
  .alert.err{background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;}
  .note{font-size:.74rem;color:var(--ink3);margin:.2rem 0 1rem;line-height:1.5;}
  .done{text-align:center;padding:1rem .5rem;}
  .done .ic{width:64px;height:64px;border-radius:50%;background:#F0FDF4;color:#16A34A;display:flex;align-items:center;justify-content:center;font-size:1.8rem;margin:0 auto 1rem;}
  .done h2{color:var(--m);margin:.2rem 0;}
  .done p{color:var(--ink2);font-size:.9rem;line-height:1.6;}
  .done a{display:inline-block;margin-top:1rem;background:var(--m);color:#fff;text-decoration:none;padding:.75rem 1.4rem;border-radius:10px;font-weight:700;}
</style>
</head>
<body>
  <div class="card">
    <div class="head">
      <div class="k">Batangas Eastern Colleges · Technician Onboarding</div>
      <h1><?php echo $done ? 'Account Activated' : 'Activate Your Account'; ?></h1>
    </div>
    <div class="body">
      <?php if ($done): ?>
        <div class="done">
          <div class="ic"><i class="fas fa-check"></i></div>
          <h2>You're all set!</h2>
          <p>Your technician account has been verified and activated. You can now sign in with your email and the password you just created.</p>
          <a href="login.html"><i class="fas fa-right-to-bracket"></i> Go to Login</a>
        </div>
      <?php elseif (!$invite): ?>
        <div class="alert err"><i class="fas fa-circle-exclamation"></i> <?php echo vh($error); ?></div>
        <p class="note">Need a new invitation? Please contact the Property Management Office.</p>
      <?php else: ?>
        <?php if ($error): ?><div class="alert err"><i class="fas fa-circle-exclamation"></i> <?php echo vh($error); ?></div><?php endif; ?>
        <p class="note">Welcome! Confirm your details and set a password to activate your technician account for <strong><?php echo vh($invite['email']); ?></strong>.</p>
        <form method="POST" action="verify_account.php?token=<?php echo vh($token); ?>">
          <input type="hidden" name="token" value="<?php echo vh($token); ?>">
          <div class="grid">
            <div class="fg full"><label>Full Name</label><input type="text" name="fullname" value="<?php echo vh($invite['fullname'] ?? ''); ?>" required></div>
            <div class="fg"><label>Employee ID</label><input type="text" name="employee_id" value="<?php echo vh($_POST['employee_id'] ?? ''); ?>" required></div>
            <div class="fg"><label>Contact Number</label><input type="text" name="contact_number" value="<?php echo vh($_POST['contact_number'] ?? ''); ?>" required></div>
            <div class="fg"><label>Department</label><input type="text" name="department" value="<?php echo vh($invite['department'] ?? ''); ?>" required></div>
            <div class="fg"><label>Specialization</label><input type="text" name="specialization" value="<?php echo vh($invite['specialization'] ?? ''); ?>" required></div>
            <div class="fg"><label>Password</label><input type="password" name="password" minlength="8" required></div>
            <div class="fg"><label>Confirm Password</label><input type="password" name="password_confirm" minlength="8" required></div>
          </div>
          <button class="btn" type="submit"><i class="fas fa-shield-halved"></i> Verify &amp; Activate</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
