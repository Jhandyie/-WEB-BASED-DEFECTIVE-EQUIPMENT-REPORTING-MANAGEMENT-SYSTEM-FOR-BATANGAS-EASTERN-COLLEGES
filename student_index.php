<?php
// student/index.php
session_start();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if (empty($name) || empty($email)) {
        $error = 'Please enter both your name and email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($name) < 2) {
        $error = 'Please enter your full name.';
    } else {
        $_SESSION['guest_name']  = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $_SESSION['guest_email'] = strtolower($email);
        $_SESSION['guest_since'] = time();
        header('Location: student_dashboard.php');
        exit();
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>BEC Equipment Reporting — Student Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --maroon: #7B1D1D;
  --maroon-d: #4A0E0E;
  --maroon-dd: #2D0505;
  --maroon-soft: rgba(123,29,29,.08);
  --gold: #C9960C;
  --gold-bg: #FFFBEF;
  --ink: #1C1008;
  --ink2: #5C3838;
  --ink3: #9E8070;
  --paper: #F8F3EA;
  --surface: #FFFFFF;
  --border: #E8DDD0;
  --shadow: 0 2px 8px rgba(44,10,10,.06), 0 12px 40px rgba(44,10,10,.10);
}
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
body {
  font-family: 'DM Sans', sans-serif;
  background: var(--paper);
  min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding: 1.5rem; position: relative; overflow-x: hidden;
}
html { -webkit-text-size-adjust: 100%; }
body::before {
  content: ''; position: fixed; top: -180px; right: -180px;
  width: 520px; height: 520px; border-radius: 50%;
  background: radial-gradient(circle, rgba(201,150,12,.12) 0%, transparent 65%);
  pointer-events: none; z-index: 0;
}
body::after {
  content: ''; position: fixed; bottom: -140px; left: -140px;
  width: 420px; height: 420px; border-radius: 50%;
  background: radial-gradient(circle, rgba(123,29,29,.09) 0%, transparent 65%);
  pointer-events: none; z-index: 0;
}
.bg-grid {
  position: fixed; inset: 0; z-index: 0; pointer-events: none;
  background-image: radial-gradient(circle, rgba(123,29,29,.12) 1px, transparent 1px);
  background-size: 32px 32px;
  mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 0%, transparent 100%);
  -webkit-mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 0%, transparent 100%);
}
.card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 24px; width: 100%; max-width: 460px;
  box-shadow: var(--shadow); position: relative; z-index: 1;
  overflow: hidden; animation: riseIn .6s cubic-bezier(.22,1,.36,1) both;
}
@keyframes riseIn {
  from { opacity: 0; transform: translateY(28px) scale(.98); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}
.card-bar { height: 5px; background: linear-gradient(90deg, var(--maroon-dd), var(--maroon), var(--gold)); }
.card-inner { padding: 2.5rem 2.5rem 2rem; }
.logo-row { display: flex; align-items: center; gap: .75rem; margin-bottom: 2rem; }
.logo-seal {
  width: 42px; height: 42px; flex-shrink: 0; border-radius: 50%;
  background: #fff; border: 1px solid rgba(123,29,29,.14);
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 0 0 3px rgba(123,29,29,.15); overflow: hidden;
}
.logo-seal img { width: 100%; height: 100%; object-fit: cover; display: block; }
.logo-text { line-height: 1.2; }
.logo-text strong { display: block; font-size: .82rem; font-weight: 600; color: var(--ink); }
.logo-text span { font-size: .65rem; color: var(--ink3); text-transform: uppercase; letter-spacing: 1.5px; }
.divider-line { height: 1px; background: var(--border); margin-bottom: 1.75rem; }
.card-title {
  font-family: 'Fraunces', serif; font-size: 1.75rem; font-weight: 700;
  color: var(--ink); line-height: 1.15; margin-bottom: .45rem; letter-spacing: -.02em;
}
.card-title em { font-style: italic; color: var(--maroon); }
.card-sub { font-size: .84rem; color: var(--ink3); line-height: 1.6; margin-bottom: 1.6rem; overflow-wrap: anywhere; }
.pills { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: 1.5rem; }
.pill {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .28rem .62rem; background: var(--maroon-soft);
  border: 1px solid rgba(123,29,29,.12); border-radius: 20px;
  font-size: .68rem; color: var(--maroon); font-weight: 500;
}
.pill i { font-size: .6rem; }
.notice {
  display: flex; align-items: flex-start; gap: .6rem;
  background: var(--gold-bg); border: 1px solid rgba(201,150,12,.25);
  border-left: 3px solid var(--gold); border-radius: 10px;
  padding: .7rem .9rem; margin-bottom: 1.6rem;
  font-size: .76rem; color: var(--ink2); line-height: 1.55;
}
.notice i { color: var(--gold); font-size: .78rem; margin-top: .12rem; flex-shrink: 0; }
.fg { margin-bottom: 1.1rem; }
.fl {
  display: block; font-size: .72rem; font-weight: 600;
  color: var(--ink2); margin-bottom: .4rem; text-transform: uppercase; letter-spacing: .9px;
}
.fl .req { color: var(--maroon); margin-left: .12rem; }
.fi-wrap { position: relative; }
.fi-icon {
  position: absolute; left: .9rem; top: 50%; transform: translateY(-50%);
  color: var(--ink3); font-size: .78rem; pointer-events: none; transition: color .18s;
}
.fi-wrap:focus-within .fi-icon { color: var(--maroon); }
.fi {
  width: 100%; padding: .76rem 1rem .76rem 2.5rem;
  border: 1.5px solid var(--border); border-radius: 11px;
  font-family: 'DM Sans', sans-serif; font-size: .88rem; color: var(--ink);
  background: #fff; transition: border-color .18s, box-shadow .18s;
  outline: none; -webkit-appearance: none;
}
.fi:focus { border-color: var(--maroon); box-shadow: 0 0 0 3.5px rgba(123,29,29,.09); }
.fi::placeholder { color: #C4AFA8; font-size: .84rem; }
.fi-hint { font-size: .68rem; color: var(--ink3); margin-top: .28rem; display: flex; align-items: center; gap: .3rem; }
.alert {
  padding: .7rem .9rem; border-radius: 10px; font-size: .78rem; line-height: 1.5;
  margin-bottom: 1.1rem; display: flex; align-items: flex-start; gap: .5rem;
}
.alert-err { background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B; }
.alert i { font-size: .8rem; margin-top: .1rem; flex-shrink: 0; }
.btn-submit {
  width: 100%; margin-top: 1.4rem; padding: .88rem 1.5rem;
  background: var(--maroon-d); color: #fff; border: none; border-radius: 11px;
  font-family: 'DM Sans', sans-serif; font-size: .95rem; font-weight: 600; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: .55rem;
  transition: all .22s cubic-bezier(.22,1,.36,1);
  box-shadow: 0 4px 0 var(--maroon-dd), 0 8px 20px rgba(74,14,14,.25);
  letter-spacing: -.01em; -webkit-appearance: none;
}
.btn-submit:hover { background: var(--maroon); transform: translateY(-2px); box-shadow: 0 6px 0 var(--maroon-dd), 0 14px 28px rgba(74,14,14,.3); }
.btn-submit:active { transform: translateY(1px); box-shadow: 0 2px 0 var(--maroon-dd), 0 4px 10px rgba(74,14,14,.2); }
.btn-arrow {
  width: 20px; height: 20px; background: rgba(255,255,255,.18); border-radius: 50%;
  display: flex; align-items: center; justify-content: center; font-size: .65rem; transition: transform .2s;
}
.btn-submit:hover .btn-arrow { transform: translateX(3px); }
.or-row {
  display: flex; align-items: center; gap: .75rem; margin: 1.35rem 0;
  font-size: .67rem; color: var(--ink3); text-transform: uppercase; letter-spacing: 1.5px;
}
.or-row::before, .or-row::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.action-row { display: grid; grid-template-columns: 1fr 1fr; gap: .65rem; margin-bottom: 1.25rem; }
.action-btn {
  display: flex; flex-direction: column; align-items: center; gap: .35rem;
  padding: .75rem .6rem; border: 1.5px solid var(--border); border-radius: 11px;
  color: var(--ink2); font-size: .74rem; font-weight: 500;
  text-decoration: none; transition: all .18s; background: #fff; text-align: center; line-height: 1.3;
}
.action-btn i { font-size: .95rem; color: var(--maroon); opacity: .75; transition: opacity .18s, transform .18s; }
.action-btn:hover { border-color: var(--maroon); color: var(--maroon); background: var(--maroon-soft); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(123,29,29,.08); }
.action-btn:hover i { opacity: 1; transform: scale(1.1); }
.footer-link { text-align: center; font-size: .73rem; color: var(--ink3); padding-top: .5rem; border-top: 1px solid var(--border); }
.footer-link a { color: var(--maroon); font-weight: 600; text-decoration: none; margin-left: .25rem; cursor: pointer; }
.footer-link a:hover { text-decoration: underline; }

/* ══ CHATBOT MODAL ══ */
#chatOverlay {
  position: fixed; inset: 0;
  background: rgba(20,5,5,.52);
  z-index: 9998;
  display: flex; align-items: flex-end; justify-content: flex-end;
  padding: 1.25rem;
  opacity: 0; pointer-events: none;
  transition: opacity .22s ease;
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
}
#chatOverlay.open { opacity: 1; pointer-events: all; }
#chatModal {
  width: 100%; max-width: 385px;
  height: 570px; max-height: calc(100vh - 2.5rem);
  background: var(--surface); border-radius: 20px;
  border: 1px solid var(--border);
  box-shadow: 0 10px 50px rgba(44,10,10,.25), 0 2px 10px rgba(44,10,10,.1);
  display: flex; flex-direction: column; overflow: hidden;
  transform: translateY(22px) scale(.97); opacity: 0;
  transition: transform .28s cubic-bezier(.22,1,.36,1), opacity .22s ease;
  position: relative; z-index: 9999;
}
#chatOverlay.open #chatModal { transform: translateY(0) scale(1); opacity: 1; }
#chatModal::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: linear-gradient(90deg, var(--maroon-dd), var(--maroon), var(--gold)); z-index: 2;
}
.ch {
  background: var(--maroon-d); padding: .9rem 1rem .88rem;
  display: flex; align-items: center; gap: .7rem; flex-shrink: 0;
}
.ch-av {
  width: 37px; height: 37px; border-radius: 50%;
  background: rgba(255,255,255,.14); border: 1.5px solid rgba(255,255,255,.18);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; position: relative;
}
.ch-av i { color: #fff; font-size: .9rem; }
.ch-av-dot {
  position: absolute; bottom: 1px; right: 1px;
  width: 9px; height: 9px; border-radius: 50%;
  background: #4ade80; border: 2px solid var(--maroon-d);
}
.ch-info { flex: 1; min-width: 0; }
.ch-name { font-size: .88rem; font-weight: 600; color: #fff; letter-spacing: -.01em; }
.ch-status { font-size: .64rem; color: rgba(255,255,255,.55); margin-top: 1px; display: flex; align-items: center; gap: .3rem; }
.ch-status::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: #4ade80; flex-shrink: 0; }
.ch-btns { display: flex; gap: .38rem; }
.ch-btn {
  width: 29px; height: 29px; border-radius: 7px;
  background: rgba(255,255,255,.1); border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: rgba(255,255,255,.65); font-size: .72rem; transition: background .14s, color .14s;
}
.ch-btn:hover { background: rgba(255,255,255,.2); color: #fff; }
.lbar {
  padding: .48rem .88rem; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: .5rem; flex-shrink: 0;
}
.lbar-lbl { font-size: .62rem; color: var(--ink3); text-transform: uppercase; letter-spacing: .9px; font-weight: 600; }
.lbtn {
  padding: .2rem .62rem; border-radius: 20px; font-size: .64rem; font-weight: 600;
  border: 1.5px solid var(--border); background: none; cursor: pointer;
  color: var(--ink3); font-family: 'DM Sans', sans-serif; transition: all .14s;
}
.lbtn.on { border-color: var(--maroon); color: var(--maroon); background: var(--maroon-soft); }
.msgs {
  flex: 1; overflow-y: auto; padding: .88rem;
  display: flex; flex-direction: column; gap: .62rem; scroll-behavior: smooth;
}
.msgs::-webkit-scrollbar { width: 3px; }
.msgs::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
.mrow {
  display: flex; gap: .48rem; align-items: flex-end; max-width: 90%;
  animation: mIn .18s ease both;
}
@keyframes mIn { from { opacity:0; transform:translateY(5px); } to { opacity:1; transform:none; } }
.mrow.u { align-self: flex-end; flex-direction: row-reverse; }
.mrow.b { align-self: flex-start; }
.mav {
  width: 25px; height: 25px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; font-size: .6rem;
}
.mrow.b .mav { background: var(--maroon); color: #fff; }
.mrow.u .mav { background: var(--maroon-d); color: rgba(255,255,255,.7); }
.mcol { display: flex; flex-direction: column; max-width: 100%; }
.mbub {
  padding: .58rem .82rem; border-radius: 14px;
  font-size: .81rem; line-height: 1.62; word-break: break-word;
}
.mrow.b .mbub { background: #f4ede4; border: 1px solid var(--border); border-bottom-left-radius: 4px; color: var(--ink); }
.mrow.u .mbub { background: var(--maroon-d); color: #fff; border-bottom-right-radius: 4px; }
.mtime { font-size: .6rem; color: var(--ink3); margin-top: .2rem; padding: 0 .15rem; }
.mrow.u .mtime { text-align: right; }
.trow { align-self: flex-start; display: flex; gap: .48rem; align-items: flex-end; }
.tbub { padding: .52rem .82rem; background: #f4ede4; border: 1px solid var(--border); border-radius: 14px; border-bottom-left-radius: 4px; }
.tdots { display: flex; gap: 3px; align-items: center; }
.tdots span { width: 5px; height: 5px; border-radius: 50%; background: var(--ink3); animation: db 1.3s infinite; }
.tdots span:nth-child(2) { animation-delay: .2s; }
.tdots span:nth-child(3) { animation-delay: .4s; }
@keyframes db { 0%,80%,100%{opacity:.2;transform:scale(.85)} 40%{opacity:1;transform:scale(1)} }
.chips { display: flex; flex-wrap: wrap; gap: .32rem; margin-top: .48rem; }
.chip {
  font-size: .7rem; padding: .26rem .68rem; border-radius: 20px;
  border: 1.5px solid var(--border); background: var(--surface);
  color: var(--ink2); cursor: pointer; font-family: 'DM Sans', sans-serif;
  font-weight: 500; transition: all .14s; white-space: nowrap;
}
.chip:hover { border-color: var(--maroon); color: var(--maroon); background: var(--maroon-soft); transform: translateY(-1px); }
.rcard {
  margin-top: .52rem; padding: .62rem .78rem; border-radius: 10px;
  background: var(--gold-bg); border: 1px solid rgba(201,150,12,.2);
  border-left: 3px solid var(--gold); font-size: .76rem; color: var(--ink2); line-height: 1.5;
}
.rcard strong { display: block; font-size: .74rem; color: var(--maroon-d); margin-bottom: .25rem; font-weight: 600; }
.rcard-btn {
  display: inline-flex; align-items: center; gap: .38rem;
  margin-top: .42rem; padding: .36rem .82rem; border-radius: 7px;
  font-size: .71rem; font-weight: 600; background: var(--maroon);
  color: #fff; border: none; cursor: pointer;
  font-family: 'DM Sans', sans-serif; text-decoration: none; transition: background .13s;
}
.rcard-btn:hover { background: var(--maroon-d); }
.chat-actions { display: flex; flex-wrap: wrap; gap: .38rem; margin-top: .52rem; }
.chat-link {
  display: inline-flex; align-items: center; gap: .34rem;
  padding: .34rem .78rem; border-radius: 999px;
  border: 1.5px solid var(--border); background: var(--surface);
  color: var(--maroon-d); text-decoration: none; font-size: .71rem; font-weight: 600;
  transition: all .14s;
}
.chat-link:hover { border-color: var(--maroon); background: var(--maroon-soft); transform: translateY(-1px); }
.inp {
  padding: .72rem .85rem .78rem; border-top: 1px solid var(--border);
  background: var(--surface); flex-shrink: 0;
}
.inp-row { display: flex; gap: .52rem; align-items: flex-end; }
.inp-wrap { flex: 1; }
.ci {
  width: 100%; padding: .6rem .88rem;
  border: 1.5px solid var(--border); border-radius: 11px;
  font-family: 'DM Sans', sans-serif; font-size: .83rem; color: var(--ink);
  background: #fff; resize: none; outline: none;
  min-height: 40px; max-height: 88px; line-height: 1.45;
  transition: border-color .17s, box-shadow .17s; -webkit-appearance: none;
}
.ci:focus { border-color: var(--maroon); box-shadow: 0 0 0 3px rgba(123,29,29,.08); }
.ci::placeholder { color: #C4AFA8; }
.sbtn {
  width: 40px; height: 40px; border-radius: 10px;
  background: var(--maroon-d); border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: .85rem; flex-shrink: 0;
  box-shadow: 0 3px 0 var(--maroon-dd); transition: all .17s; -webkit-appearance: none;
}
.sbtn:hover { background: var(--maroon); transform: translateY(-1px); box-shadow: 0 4px 0 var(--maroon-dd); }
.sbtn:active { transform: translateY(1px); box-shadow: 0 1px 0 var(--maroon-dd); }
.sbtn:disabled { opacity: .45; cursor: not-allowed; transform: none; }
.inp-meta { margin-top: .38rem; font-size: .62rem; color: var(--ink3); display: flex; align-items: center; gap: .28rem; }
.inp-meta i { font-size: .58rem; }

/* ── RESPONSIVE ── */
@media (max-width: 767px) {
  body { align-items: flex-start; padding-top: 1.25rem; }
  .card { border-radius: 20px; }
  .card-inner { padding: 2rem 1.5rem 1.5rem; }
  .card-title { font-size: 1.55rem; }
  .pills { gap: .35rem; }
  .pill { font-size: .66rem; }
  #chatOverlay { align-items: flex-end; justify-content: center; padding: 0; }
  #chatModal { max-width: 100%; width: 100%; border-radius: 20px 20px 0 0; height: 88vh; max-height: 88vh; }
}
@media (max-width: 520px) {
  body { padding: 1rem; align-items: flex-start; padding-top: 1.5rem; }
  .card-inner { padding: 1.75rem 1.5rem 1.5rem; }
  .card-title { font-size: 1.5rem; }
  .action-row { grid-template-columns: 1fr; }
}
@media (max-width: 390px) {
  body { padding: .75rem; padding-top: 1rem; }
  .card { border-radius: 18px; }
  .card-inner { padding: 1.35rem 1rem 1.15rem; }
  .logo-row { margin-bottom: 1.35rem; }
  .divider-line { margin-bottom: 1.2rem; }
  .card-title { font-size: 1.32rem; }
  .card-sub, .notice, .action-btn, .footer-link { font-size: .72rem; }
  .fi { font-size: .84rem; padding: .72rem .9rem .72rem 2.35rem; }
}
@media (max-height: 700px) {
  body { align-items: flex-start; padding-top: 1rem; padding-bottom: 1rem; }
}
</style>
</head>
<body>
<div class="bg-grid"></div>

<div class="card">
  <div class="card-bar"></div>
  <div class="card-inner">
    <div class="logo-row">
      <div class="logo-seal"><img src="assets/logs.png" alt="BEC logo"></div>
      <div class="logo-text">
        <strong>BEC Equipment Reporting</strong>
        <span>User Portal</span>
      </div>
    </div>
    <div class="divider-line"></div>
    <h1 class="card-title">Report <em>defective equipment</em> instantly.</h1>
    <p class="card-sub">No account needed — enter your name and email to continue. We'll send your ticket confirmation right away.</p>
    <div class="pills">
      <span class="pill"><i class="fas fa-bolt"></i> No registration</span>
      <span class="pill"><i class="fas fa-envelope"></i> Email confirmation</span>
      <span class="pill"><i class="fas fa-search"></i> Public tracking</span>
      <span class="pill"><i class="fas fa-shield-alt"></i> Privacy-safe</span>
    </div>
    <div class="notice">
      <i class="fas fa-info-circle"></i>
      <span>Your information is only used to generate and send your report ticket — nothing else.</span>
    </div>
    <?php if ($error): ?>
    <div class="alert alert-err">
      <i class="fas fa-exclamation-circle"></i>
      <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>
    <form method="POST" action="">
      <div class="fg">
        <label class="fl">Full Name <span class="req">*</span></label>
        <div class="fi-wrap">
          <i class="fas fa-user fi-icon"></i>
          <input type="text" name="full_name" class="fi" placeholder="e.g. Maria Santos"
            value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
            autocomplete="name" required>
        </div>
      </div>
      <div class="fg">
        <label class="fl">Email Address <span class="req">*</span></label>
        <div class="fi-wrap">
          <i class="fas fa-envelope fi-icon"></i>
          <input type="email" name="email" class="fi" placeholder="you@school.edu"
            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
            autocomplete="email" required>
        </div>
        <div class="fi-hint"><i class="fas fa-shield-alt"></i> Your ticket confirmation will be sent here.</div>
      </div>
      <button type="submit" class="btn-submit">
        Continue to Report Submission
        <span class="btn-arrow"><i class="fas fa-arrow-right"></i></span>
      </button>
    </form>
    <div class="or-row">or</div>
    <div class="action-row">
      <a href="track_report.php" class="action-btn">
        <i class="fas fa-ticket-alt"></i>Track existing report
      </a>
      <a href="public_reports.php" class="action-btn">
        <i class="fas fa-table-list"></i>View all reports
      </a>
    </div>
    <div class="footer-link">
      Need help?<a onclick="openChat()" role="button" tabindex="0">Contact support</a>
    </div>
  </div>
</div>


<!-- ══ AI CHATBOT MODAL ══ -->
<div id="chatOverlay" onclick="overlayClick(event)">
  <div id="chatModal">

    <div class="ch">
      <div class="ch-av">
        <img src="assets/Gemini_Generated_Image_e35zfue35zfue35z.png" alt="BEC Support AI" style="width:100%;height:100%;object-fit:cover;display:block;border-radius:50%">
        <div class="ch-av-dot"></div>
      </div>
      <div class="ch-info">
        <div class="ch-name">BEC Support AI</div>
        <div class="ch-status">Online &middot; Ready to help</div>
      </div>
      <div class="ch-btns">
        <button class="ch-btn" onclick="clearChat()" title="New chat"><i class="fas fa-rotate-right"></i></button>
        <button class="ch-btn" onclick="closeChat()" title="Close"><i class="fas fa-xmark"></i></button>
      </div>
    </div>

    <div class="lbar">
      <span class="lbar-lbl">Language:</span>
      <button class="lbtn on" id="lb-en" onclick="setLang('en')">English</button>
      <button class="lbtn"    id="lb-fil" onclick="setLang('fil')">Filipino</button>
    </div>

    <div class="msgs" id="msgs"></div>

    <div class="inp">
      <div class="inp-row">
        <div class="inp-wrap">
          <textarea class="ci" id="ci"
            placeholder="Type your message here…"
            rows="1"
            onkeydown="handleKey(event)"
            oninput="grow(this)"></textarea>
        </div>
        <button class="sbtn" id="sbtn" onclick="send()">
          <i class="fas fa-paper-plane"></i>
        </button>
      </div>
      <div class="inp-meta"><i class="fas fa-shield-alt"></i> AI-powered · BEC knowledge base · Urgent: ext. 215</div>
    </div>

  </div>
</div>


<script>
/* ══════════════════════════════════════
   BEC SUPPORT AI
   Routes through chat_proxy.php so the
   API key stays safely on the server.
══════════════════════════════════════ */

const PROXY = 'chat_proxy.php';

let lang    = 'en';
let history = [];
let busy    = false;
let greeted = false;

/* open / close */
function openChat() {
  document.getElementById('chatOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
  if (!greeted) { greeted = true; greet(); }
  setTimeout(() => document.getElementById('ci').focus(), 300);
}
function closeChat() {
  document.getElementById('chatOverlay').classList.remove('open');
  document.body.style.overflow = '';
}
function overlayClick(e) {
  if (e.target === document.getElementById('chatOverlay')) closeChat();
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeChat(); });

/* language */
function setLang(l) {
  lang = l;
  document.getElementById('lb-en').classList.toggle('on', l === 'en');
  document.getElementById('lb-fil').classList.toggle('on', l === 'fil');
}

function detectInputLang(text) {
  const value = String(text || '').toLowerCase().trim();
  if (!value) return lang;

  const filipinoMatches = [
    /\b(kumusta|kamusta|musta|paano|bakit|saan|kailan|sino|ano|alin|gaano|pwede|puwede|gusto|kailangan|salamat|opo|po|hindi|oo|meron|wala|nasaan|pakitulong|patulong|paki|sira|gumagana|gumana|ayaw|nagloloko|mabagal|maingay|lumalamig|mainit|tagas|ingay|ayos|paayos|ipagawa|mag-report|mag track|mag-track|ireport|ulat|isyu|problema)\b/i,
    /\b(nag-|pag-|mag-|ma-|ipa-|ipag-|pinaka)[a-z-]*/i,
    /\b(ako|ikaw|kayo|kami|tayo|nila|namin|atin|ito|iyan|iyon|dito|doon)\b/i
  ].reduce((count, pattern) => count + ((value.match(pattern) || []).length), 0);

  return filipinoMatches >= 2 ? 'fil' : 'en';
}

function chipLabel(key, currentLang = lang) {
  const labels = {
    report_projector: { en: 'Report a broken projector', fil: 'Mag-report ng sirang projector' },
    track: { en: 'Track my report', fil: 'I-track ang report ko' },
    computer: { en: "Computer won't start", fil: 'Hindi nagbubukas ang computer' },
    ac: { en: 'AC not cooling', fil: 'Hindi lumalamig ang aircon' },
    submit: { en: 'How do I submit a report?', fil: 'Paano mag-report?' },
    timeline: { en: 'How long are repairs?', fil: 'Gaano katagal ang repair?' }
  };
  return labels[key]?.[currentLang] || labels[key]?.en || key;
}

function chipSet(keys, currentLang = lang) {
  return keys.map(key => chipLabel(key, currentLang));
}

function actionLabel(type, currentLang = lang) {
  const labels = {
    create: { en: 'Create Report', fil: 'Gumawa ng Report' },
    tracker: { en: 'Open Tracker', fil: 'Buksan ang Tracker' },
    public: { en: 'Public Reports', fil: 'Mga Public Report' }
  };
  return labels[type]?.[currentLang] || labels[type]?.en || type;
}

/* greeting */
function greet() {
  const currentLang = lang;
  const msg = currentLang === 'fil'
    ? 'Kumusta! 👋 Ako ang BEC Support AI. Nandito ako para tulungan ka sa mga isyu sa kagamitan, pag-submit ng defect report, at troubleshooting. Ano ang maitutulong ko sa iyo ngayon?'
    : 'Hi there! 👋 I\'m BEC Support AI, your friendly assistant for equipment issues, defect reports, and troubleshooting. What can I help you with today?';
  const chips = chipSet(['report_projector', 'track', 'computer', 'ac', 'submit', 'timeline'], currentLang);
  addMsg('b', msg, chips, false, [
    { label: actionLabel('create', currentLang), href: 'student_dashboard.php', icon: 'fa-plus' },
    { label: actionLabel('tracker', currentLang), href: 'track_report.php', icon: 'fa-search' },
    { label: actionLabel('public', currentLang), href: 'public_reports.php', icon: 'fa-list' }
  ]);
}

/* clear */
function clearChat() {
  history = []; greeted = false;
  document.getElementById('msgs').innerHTML = '';
  greet(); greeted = true;
}

function getChatActions(text, suggest, explicitActions = []) {
  if (Array.isArray(explicitActions) && explicitActions.length) return explicitActions;

  const q = String(text || '').toLowerCase();
  const currentLang = detectInputLang(text);
  const actions = [];
  if (suggest || /\b(report|submit|projector|computer|aircon|ac)\b/i.test(q)) {
    actions.push({ label: actionLabel('create', currentLang), href: 'student_dashboard.php', icon: 'fa-plus' });
  }
  if (/\b(track|ticket|status|report id|asset tag|equipment id)\b/i.test(q)) {
    actions.push({ label: actionLabel('tracker', currentLang), href: 'track_report.php', icon: 'fa-search' });
  }
  actions.push({ label: actionLabel('public', currentLang), href: 'public_reports.php', icon: 'fa-list' });

  const seen = new Set();
  return actions.filter(action => {
    if (seen.has(action.href)) return false;
    seen.add(action.href);
    return true;
  }).slice(0, 3);
}

/* add a message bubble */
function addMsg(role, text, chips, suggest, actions = []) {
  const box = document.getElementById('msgs');
  const row = document.createElement('div');
  row.className = 'mrow ' + (role === 'u' ? 'u' : 'b');
  const detectedLang = role === 'u' ? detectInputLang(text) : lang;

  const safe = esc(text).replace(/\n/g, '<br>');

  let extra = '';
  if (chips && chips.length) {
    extra += '<div class="chips">' +
      chips.map(c => `<button class="chip" onclick="quickSend(this.dataset.t)" data-t="${escAttr(c)}">${esc(c)}</button>`).join('') +
      '</div>';
  }
  if (role !== 'u') {
    const chatActions = getChatActions(text, suggest, actions);
    if (chatActions.length) {
      extra += '<div class="chat-actions">' +
        chatActions.map(action => `<a class="chat-link" href="${escAttr(action.href)}"><i class="fas ${escAttr(action.icon || 'fa-arrow-right')}"></i>${esc(action.label || 'Open')}</a>`).join('') +
        '</div>';
    }
  }
  if (suggest) {
    extra += `<div class="rcard">
      <strong><i class="fas fa-file-circle-exclamation" style="margin-right:.28rem"></i>${detectedLang === 'fil' ? 'Mag-submit ng Formal Report' : 'Submit a Formal Report'}</strong>
      ${detectedLang === 'fil'
        ? 'Mukhang kailangan ito ng aktuwal na pagtingin. Gumawa ng opisyal na defect report para sa facilities team.'
        : 'This issue likely needs hands-on attention. Create an official defect report for the facilities team.'}
      <br><a href="student_dashboard.php" class="rcard-btn"><i class="fas fa-plus"></i> ${detectedLang === 'fil' ? 'Gumawa ng Report' : 'Create Report'}</a>
    </div>`;
  }

  const icon = role === 'u' ? 'fas fa-user' : 'fas fa-robot';
  row.innerHTML = `
    <div class="mav"><i class="${icon}"></i></div>
    <div class="mcol">
      <div class="mbub">${safe}${extra}</div>
      <div class="mtime">${now()}</div>
    </div>`;
  box.appendChild(row);
  box.scrollTop = box.scrollHeight;
}

/* typing dots */
function showTyping() {
  const box = document.getElementById('msgs');
  const row = document.createElement('div');
  row.className = 'trow'; row.id = 'typing';
  row.innerHTML = `
    <div class="mav" style="background:var(--maroon);color:#fff;width:25px;height:25px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.6rem;flex-shrink:0">
      <i class="fas fa-robot"></i>
    </div>
    <div class="tbub"><div class="tdots"><span></span><span></span><span></span></div></div>`;
  box.appendChild(row);
  box.scrollTop = box.scrollHeight;
}
function hideTyping() { const el = document.getElementById('typing'); if (el) el.remove(); }

function fallbackReply(text) {
  const q = String(text || '').toLowerCase();
  const isFil = lang === 'fil' || /\b(paano|kumusta|salamat|hindi|sira|aircon|gumana|report|ticket|ayos)\b/i.test(text);

  const wrap = (message, chips = [], suggest = false) => ({ message, chips, suggest });

  if (/\b(hello|hi|hey|kumusta|good morning|good afternoon|good evening)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Kumusta po. Maaari ko kayong tulungan sa troubleshooting, pag-submit ng report, at pag-track ng ticket. Sabihin lang kung anong equipment issue ang meron kayo.'
        : 'Hello. I can help with troubleshooting, submitting a report, and tracking a ticket. Just tell me what equipment issue you have.',
      chipSet(['report_projector', 'track', 'computer', 'ac'], isFil ? 'fil' : 'en')
    );
  }

  if (/\b(projector|lcd|screen|display)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Para sa projector issue, subukan muna ito:\n- I-check ang power cable at HDMI/VGA connection\n- Pindutin ang Source/Input\n- I-restart ang projector at hintayin mag-warm up\nKung wala pa rin, gumawa ng defect report at ilagay ang room at equipment reference.'
        : 'For a projector issue, try these first:\n- Check the power cable and HDMI/VGA connection\n- Press Source/Input\n- Restart the projector and allow it to warm up\nIf it still fails, submit a defect report and include the room and equipment reference.',
      chipSet(['report_projector', 'submit', 'track'], isFil ? 'fil' : 'en'),
      true
    );
  }

  if (/\b(computer|pc|desktop|laptop|won't start|wont start|boot)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Kung hindi nagbubukas ang computer:\n- I-check kung naka-on ang power strip\n- I-hold ang power button ng 10 seconds\n- I-check ang power cable at monitor connection\nKapag hindi pa rin gumana, i-report ito kasama ang PC tag o equipment ID.'
        : 'If the computer will not start:\n- Check whether the power strip is on\n- Hold the power button for 10 seconds\n- Check the power cable and monitor connection\nIf it still will not work, report it with the PC tag or equipment ID.',
      chipSet(['computer', 'submit', 'track'], isFil ? 'fil' : 'en'),
      true
    );
  }

  if (/\b(ac|aircon|air con|cooling|not cooling)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Kung hindi lumalamig ang aircon:\n- Siguraduhing nasa Cool mode ito\n- I-check ang thermostat setting\n- Tingnan kung may bara ang vents\nKung mainit pa rin ang hangin o may tagas/ingay, i-submit na ito bilang report.'
        : 'If the AC is not cooling:\n- Make sure it is set to Cool mode\n- Check the thermostat setting\n- See whether the vents are blocked\nIf it still blows warm air or has leaks/noise, submit a report.',
      chipSet(['ac', 'submit', 'timeline'], isFil ? 'fil' : 'en'),
      true
    );
  }

  if (/\b(track|ticket|report id|status)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Maaari mong i-track ang report gamit ang ticket number, equipment ID, o asset tag sa `track_report.php`. Makikita roon ang report status at equipment status.'
        : 'You can track a report using the ticket number, equipment ID, or asset tag in `track_report.php`. It will show both the report status and the equipment status.',
      chipSet(['track', 'timeline', 'submit'], isFil ? 'fil' : 'en')
    );
  }

  if (/\b(submit|report|paano mag-report|how to report|file a report)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Para mag-submit ng report:\n- Ilagay ang pangalan at email sa student portal\n- Piliin ang tamang equipment mula sa listahan\n- Ilagay ang location at malinaw na description\n- I-submit para makakuha ng ticket number sa screen at email'
        : 'To submit a report:\n- Enter your name and email in the student portal\n- Choose the correct equipment from the list\n- Fill in the location and a clear description\n- Submit to get your ticket number on screen and by email',
      chipSet(['submit', 'report_projector', 'track'], isFil ? 'fil' : 'en'),
      true
    );
  }

  if (/\b(repair|timeline|how long|gaano katagal)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Karaniwang repair timeline:\n- Minor issues: 1-2 working days\n- Major repairs: 3-7 working days\n- Critical safety issues: dapat ma-escalate agad\nMaaari mong i-check ang latest status sa tracking page.'
        : 'Typical repair timeline:\n- Minor issues: 1-2 working days\n- Major repairs: 3-7 working days\n- Critical safety issues: should be escalated immediately\nYou can check the latest status on the tracking page.',
      chipSet(['timeline', 'track', 'submit'], isFil ? 'fil' : 'en')
    );
  }

  return wrap(
    isFil
      ? 'Maaari kitang tulungan sa projector, computer, aircon, pag-submit ng defect report, at pag-track ng ticket. Ilarawan mo lang ang problema at bibigyan kita ng susunod na hakbang.'
      : 'I can help with projector, computer, and AC issues, plus submitting defect reports and tracking tickets. Describe the problem and I will suggest the next step.',
    chipSet(['report_projector', 'computer', 'ac', 'track'], isFil ? 'fil' : 'en')
  );
}

/* send */
async function send() {
  if (busy) return;
  const ci   = document.getElementById('ci');
  const text = ci.value.trim();
  if (!text) return;
  setLang(detectInputLang(text));

  ci.value = ''; ci.style.height = 'auto';
  addMsg('u', text, [], false);
  history.push({ role: 'user', content: text });

  busy = true;
  document.getElementById('sbtn').disabled = true;
  showTyping();

  try {
    const res = await fetch(PROXY, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ messages: history })
    });

    const data = await res.json();

    if (!res.ok || data.error) throw new Error(data.error || 'Server error ' + res.status);

    const clean   = (data.reply || '').replace('[SUGGEST_REPORT]', '').trim();
    const suggest = !!data.suggest;
    const chips   = Array.isArray(data.chips) ? data.chips : [];
    const actions = Array.isArray(data.actions) ? data.actions : [];
    if (!clean) throw new Error('Empty AI reply');

    history.push({ role: 'assistant', content: data.reply || clean });
    hideTyping();
    addMsg('b', clean, chips, suggest, actions);

  } catch (err) {
    hideTyping();
    const offline = fallbackReply(text);
    history.push({ role: 'assistant', content: offline.message });
    addMsg('b', offline.message, offline.chips, offline.suggest);
    console.error('[BEC Chat]', err);
  }

  busy = false;
  document.getElementById('sbtn').disabled = false;
  document.getElementById('ci').focus();
}

function quickSend(t) { document.getElementById('ci').value = t; send(); }
function handleKey(e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } }
function grow(el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 88) + 'px'; }
function now() { return new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }); }
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escAttr(s) { return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
</script>

</body>
</html>
