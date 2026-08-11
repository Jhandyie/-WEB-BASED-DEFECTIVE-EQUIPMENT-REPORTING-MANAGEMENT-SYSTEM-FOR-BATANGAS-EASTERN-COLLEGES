<?php
/**
 * includes/becca_widget.php — Becca AI floating assistant (shared widget).
 * Extracted verbatim from student_index.php. Relies on the host page's :root
 * design tokens (--maroon, --gold, --ink*, --surface, --border, ...),
 * Font Awesome, and DM Sans being loaded. Posts to chat_proxy.php in web root.
 */
?>
<style>
/* ══ FLOATING CHAT LAUNCHER (left side) ══ */
#chatFab {
  position: fixed; left: 1.35rem; bottom: 1.35rem; z-index: 9997;
  display: flex; align-items: center; justify-content: center;
  width: 62px; height: 62px; padding: 0;
  background: linear-gradient(135deg, rgba(74,14,14,.9), rgba(45,5,5,.9));
  -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
  border: 2px solid rgba(201,150,12,.6);
  border-radius: 50%; cursor: pointer;
  box-shadow: 0 10px 30px rgba(44,10,10,.4), 0 0 20px rgba(201,150,12,.25);
  transition: transform .25s cubic-bezier(.22,1,.36,1), box-shadow .3s ease;
  animation: fabFloat 6s ease-in-out infinite, fabGlow 4.5s ease-in-out infinite;
}
#chatFab:hover { animation: none; transform:none; box-shadow: 0 16px 40px rgba(44,10,10,.5), 0 0 32px rgba(201,150,12,.5); }
#chatFab:active { transform: translateY(-1px) scale(1); }
#chatFab .fab-ic {
  width: 46px; height: 46px; border-radius: 50%; flex-shrink: 0; overflow: hidden;
  background: rgba(255,255,255,.14); border: 1.5px solid rgba(255,255,255,.25);
  display: flex; align-items: center; justify-content: center; position: relative;
  box-shadow: 0 0 14px rgba(201,150,12,.35);
}
#chatFab .fab-ic img { width: 100%; height: 100%; object-fit: cover; display: block; }
#chatFab .fab-ic i { color: #fff; font-size: 1.05rem; }
#chatFab .fab-ic::after {
  content: ''; position: absolute; top: 1px; right: 1px;
  width: 10px; height: 10px; border-radius: 50%;
  background: #F0C040; border: 2px solid rgba(45,5,5,.9);
  box-shadow: 0 0 0 0 rgba(240,192,64,.7); animation: fabPulse 2.2s infinite;
}
#chatFab .fab-txt { display: none; }
@keyframes fabPulse { 0% { box-shadow: 0 0 0 0 rgba(240,192,64,.6); } 70% { box-shadow: 0 0 0 8px rgba(240,192,64,0); } 100% { box-shadow: 0 0 0 0 rgba(240,192,64,0); } }
@keyframes fabFloat { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
@keyframes fabGlow { 0%,100% { box-shadow: 0 10px 30px rgba(44,10,10,.4), 0 0 16px rgba(201,150,12,.2); } 50% { box-shadow: 0 12px 34px rgba(44,10,10,.46), 0 0 30px rgba(201,150,12,.45); } }
@media (prefers-reduced-motion: reduce) { #chatFab, #chatFab .fab-ic::after { animation: none; } }
@media (max-width: 560px) {
  /* 1rem leaves the button sitting on the iPhone home indicator; the inset
     lifts it clear on phones that have one and changes nothing on those that don't. */
  #chatFab { width: 56px; height: 56px; left: 1rem;
             bottom: calc(1rem + env(safe-area-inset-bottom)); }
  #chatFab .fab-ic { width: 42px; height: 42px; }
}

/* ══ CHATBOT MODAL ══ */
#chatOverlay {
  position: fixed; inset: 0;
  background: rgba(20,5,5,.52);
  z-index: 9998;
  display: flex; align-items: flex-end; justify-content: flex-start;
  padding: 1.25rem;
  opacity: 0; pointer-events: none;
  transition: opacity .22s ease;
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
}
#chatOverlay.open { opacity: 1; pointer-events: all; }
#chatModal {
  width: 100%; max-width: 385px;
  /* vh on a phone measures past the collapsing address bar, so the panel ran
     off the bottom of the screen; dvh follows what is actually visible. */
  height: 570px; max-height: calc(100vh - 2.5rem); max-height: calc(100dvh - 2.5rem);
  background: rgba(255,255,255,.85);
  -webkit-backdrop-filter: blur(22px) saturate(1.2); backdrop-filter: blur(22px) saturate(1.2);
  border-radius: 22px;
  border: 1px solid rgba(255,255,255,.6);
  box-shadow: 0 20px 60px rgba(44,10,10,.32), 0 4px 14px rgba(44,10,10,.14), inset 0 0 0 1px rgba(255,255,255,.3);
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
  background: linear-gradient(135deg, var(--maroon-dd), var(--maroon)); padding: .95rem 1rem .9rem;
  display: flex; align-items: center; gap: .7rem; flex-shrink: 0;
}
.ch-av {
  width: 40px; height: 40px; border-radius: 50%;
  background: rgba(255,255,255,.14); border: 1.5px solid rgba(201,150,12,.6);
  box-shadow: 0 0 14px rgba(201,150,12,.4);
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
/* The header controls and language toggles were 28-30px on a phone — under the
   44px that WCAG 2.5.5 and both platform guidelines ask for, and sitting close
   together in a corner. The button keeps its look; the touch area around it
   grows to 44px through a transparent pseudo-element. */
@media (pointer: coarse), (max-width: 640px) {
  .ch-btn, .lbtn, .sbtn { position: relative; }
  .ch-btn::after, .lbtn::after, .sbtn::after {
    content: ''; position: absolute; top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    min-width: 44px; min-height: 44px; width: 100%; height: 100%;
  }
  /* A 44px overlay needs 44px of pitch or the overlays sit on top of each
     other. The two header buttons are 29px on a .38rem (6px) gap — a 35px
     pitch — so Close's overlay covered the right 10px of New chat's, and a tap
     there closed the panel instead. Measured on index.php: New chat was
     reachable from x=282..315 only, while its overlay claimed 281..325.
     The button lays out at 28.5px, so the gap has to clear 44 - 28.5; 16px
     does, and both targets are whole with neither stealing from the other.
     Scoped to touch/narrow, so the desktop header keeps its tight gap. */
  .ch-btns { gap: 16px; }
}
.lbar {
  padding: .48rem .88rem; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: .5rem; flex-shrink: 0;
}
.lbar-lbl { font-size: .7rem; color: var(--ink3); text-transform: uppercase; letter-spacing: .9px; font-weight: 600; }
/* These are real controls, not labels — they were 10px with 3px of vertical
   padding, which is both hard to read and hard to hit on a phone. */
.lbtn {
  padding: .34rem .7rem; border-radius: 20px; font-size: .75rem; font-weight: 600;
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
.mav-ai { overflow: hidden; background: #fff; border: 1px solid rgba(201,150,12,.45); box-shadow: 0 0 8px rgba(201,150,12,.3); }
.mav-ai img { width: 100%; height: 100%; object-fit: cover; display: block; }
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
.tdots span { width: 5px; height: 5px; border-radius: 50%; background: var(--maroon); animation: db 1.3s infinite; }
.tdots span:nth-child(2) { animation-delay: .2s; }
.tdots span:nth-child(3) { animation-delay: .4s; }
@keyframes db { 0%,80%,100%{opacity:.2;transform:scale(.85)} 40%{opacity:1;transform:scale(1)} }
.chips { display: flex; flex-wrap: wrap; gap: .32rem; margin-top: .48rem; }
.chip {
  font-size: .72rem; padding: .34rem .74rem; border-radius: 18px;
  border: 1px solid rgba(123,29,29,.18); background: rgba(123,29,29,.05);
  color: var(--maroon); cursor: pointer; font-family: 'DM Sans', sans-serif;
  font-weight: 600; transition: all .15s; white-space: nowrap;
  text-transform: none; letter-spacing: 0; line-height: 1.25;
}
.chip:hover { border-color: var(--maroon); color: var(--maroon); background: var(--maroon-soft); transform:none; box-shadow: 0 2px 8px rgba(123,29,29,.12); }
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
.chat-link:hover { border-color: var(--maroon); background: var(--maroon-soft); transform:none; }
.inp {
  padding: .72rem .85rem .78rem; border-top: 1px solid var(--border);
  background: var(--surface); flex-shrink: 0;
}
.inp-row { display: flex; gap: .52rem; align-items: flex-end; }
.inp-wrap { flex: 1; }
.ci {
  width: 100%; padding: .6rem .88rem;
  border: 1.5px solid var(--border); border-radius: 11px;
  font-family: 'DM Sans', sans-serif; font-size: 1rem; color: var(--ink);
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
  box-shadow:none; transition: all .17s; -webkit-appearance: none;
}
.sbtn:hover { background: var(--maroon); transform:none; box-shadow:none; }
.sbtn:active { transform:none; box-shadow:none; }
.sbtn:disabled { opacity: .45; cursor: not-allowed; transform: none; }
.inp-meta { margin-top: .38rem; font-size: .62rem; color: var(--ink3); display: flex; align-items: center; gap: .28rem; }
.inp-meta i { font-size: .58rem; }

@media (max-width: 767px) {
  #chatOverlay { align-items: flex-end; justify-content: center; padding: 0; }
  /* dvh handles the URL bar; JS (visualViewport) handles the on-screen keyboard */
  #chatModal { max-width: 100%; width: 100%; border-radius: 20px 20px 0 0; height: 88vh; height: 88dvh; max-height: 88dvh; }

  /* ── type floor ────────────────────────────────────────────────────────
     On a phone the panel goes full-bleed, so this text is being read at arm's
     length rather than glanced at in a corner of a laptop screen. The status
     line under the name, the message timestamps and the meta line under the
     composer were 9.9-10.9px. This is the shared widget, so every portal that
     includes it gets the same floor. */
  .ch-status { font-size: .7rem; }
  .mtime { font-size: .68rem; }
  .inp-meta { font-size: .68rem; }
  .inp-meta i { font-size: .64rem; }
}
</style>

<!-- ══ FLOATING CHAT LAUNCHER (left) ══ -->
<button id="chatFab" type="button" onclick="openChat()" aria-label="Open BEC Support assistant">
  <span class="fab-ic"><img src="assets/Gemini_Generated_Image_e35zfue35zfue35z.png" alt="Becca AI" onerror="this.style.display='none';this.insertAdjacentHTML('afterend','<i class=\'fas fa-robot\'></i>')"></span>
  <span class="fab-txt"><b>Ask Becca</b><span>BEC Support AI</span></span>
</button>

<!-- ══ AI CHATBOT MODAL ══ -->
<div id="chatOverlay" onclick="overlayClick(event)">
  <div id="chatModal">

    <div class="ch">
      <div class="ch-av">
        <img src="assets/Gemini_Generated_Image_e35zfue35zfue35z.png" alt="BEC Support AI" style="width:100%;height:100%;object-fit:cover;display:block;border-radius:50%">
        <div class="ch-av-dot"></div>
      </div>
      <div class="ch-info">
        <div class="ch-name">Becca <span style="font-weight:500;opacity:.75;font-size:.82em;">· BEC Support AI</span></div>
        <div class="ch-status">Online &middot; Ready to help</div>
      </div>
      <div class="ch-btns">
        <button class="ch-btn" type="button" onclick="clearChat()" title="New chat" aria-label="Start a new chat"><i aria-hidden="true" class="fas fa-rotate-right"></i></button>
        <button class="ch-btn" type="button" onclick="closeChat()" title="Close" aria-label="Close chat"><i aria-hidden="true" class="fas fa-xmark"></i></button>
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
        <button class="sbtn" id="sbtn" type="button" onclick="send()" aria-label="Send message">
          <i aria-hidden="true" class="fas fa-paper-plane"></i>
        </button>
      </div>
      <div class="inp-meta"><i aria-hidden="true" class="fas fa-shield-alt"></i> AI-powered · BEC knowledge base · Urgent: ext. 215</div>
    </div>

  </div>
</div>

<script>
/* ══════════════════════════════════════
   BEC SUPPORT AI  —  "Becca"
   A warm, intelligent equipment-support assistant.

   Routes through chat_proxy.php so the
   API key stays safely on the server.

   This build adds a consistent persona, emotional
   awareness, short-term memory, and natural varied
   phrasing so the bot feels alive and friendly —
   while staying honest about being an AI assistant.
══════════════════════════════════════ */

const PROXY = 'chat_proxy.php';

let lang    = 'en';
let history = [];
let busy    = false;
let greeted = false;

/* ── Becca's short-term memory ──
   Persona + lightweight context she "remembers"
   across the conversation, so she feels aware. */
const persona = {
  name: 'Becca',
  role_en: 'BEC Support AI',
  role_fil: 'BEC Support AI'
};

const memory = {
  userName: null,        // remembered if the user introduces themselves
  lastTopic: null,       // 'projector' | 'computer' | 'ac' | 'track' | ...
  mood: 'neutral',       // 'frustrated' | 'happy' | 'urgent' | 'neutral'
  turns: 0,              // how many times the user has spoken
  troubleshotTopics: []  // topics already walked through (avoid repeating)
};

/* pick a random variant so replies never feel scripted */
function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

/* ── Keep the panel above the on-screen keyboard (mobile) ──
   vh/dvh units don't shrink when the keyboard opens, so the input ends up
   hidden beneath it. We pin the overlay to the *visual* viewport (the area
   above the keyboard) so the bottom-anchored sheet — and its input — stay
   visible. NOTE: the overlay is `inset:0`, so we must clear right/bottom to
   `auto`, otherwise top+bottom both being set makes the browser ignore our
   height and stretch it full-height (the original bug). */
function resetOverlayBox(overlay, modal) {
  overlay.style.top = overlay.style.left = '';
  overlay.style.right = overlay.style.bottom = '';
  overlay.style.width = overlay.style.height = '';
  if (modal) modal.style.height = '';
}
function fitToKeyboard() {
  const vv = window.visualViewport;
  const overlay = document.getElementById('chatOverlay');
  const modal = document.getElementById('chatModal');
  if (!vv || !overlay || !modal || !overlay.classList.contains('open')) return;
  if (window.innerWidth > 767) { resetOverlayBox(overlay, modal); return; } // desktop: leave CSS alone

  const keyboardOpen = (window.innerHeight - vv.height) > 120;
  if (keyboardOpen) {
    // anchor the overlay to exactly the visible area above the keyboard
    overlay.style.top    = vv.offsetTop + 'px';
    overlay.style.left   = vv.offsetLeft + 'px';
    overlay.style.right  = 'auto';
    overlay.style.bottom = 'auto';           // <-- critical: let height win
    overlay.style.width  = vv.width + 'px';
    overlay.style.height = vv.height + 'px';
    modal.style.height   = vv.height + 'px';
    const box = document.getElementById('msgs');
    if (box) box.scrollTop = box.scrollHeight; // keep latest message visible
  } else {
    resetOverlayBox(overlay, modal);
  }
}
if (window.visualViewport) {
  window.visualViewport.addEventListener('resize', fitToKeyboard);
  window.visualViewport.addEventListener('scroll', fitToKeyboard);
}

/* open / close */
function openChat() {
  document.getElementById('chatOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
  if (!greeted) { greeted = true; greet(); }
  const ci = document.getElementById('ci');
  // re-fit whenever the field gains focus (keyboard shows a moment later)
  ci.addEventListener('focus', function () { setTimeout(fitToKeyboard, 250); });
  setTimeout(() => { ci.focus(); fitToKeyboard(); }, 300);
}
function closeChat() {
  const overlay = document.getElementById('chatOverlay');
  overlay.classList.remove('open');
  document.body.style.overflow = '';
  resetOverlayBox(overlay, document.getElementById('chatModal')); // clear keyboard sizing
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

/* ── Emotional / intent awareness ──
   Lets Becca respond to *how* the user feels,
   not just to keywords. */
function readMood(text) {
  const q = String(text || '').toLowerCase();
  if (/\b(asap|urgent|emergency|now na|right now|kanina pa|matagal na|spark|smoke|usok|kuryente|burning|amoy sunog|fire)\b/i.test(q)) return 'urgent';
  if (/\b(angry|frustrated|annoyed|ulit|paulit|nakakainis|inis|badtrip|stupid|useless|wala kayong|hindi gumagana parati|broken again|sira na naman|sawa na|pissed)\b/i.test(q)) return 'frustrated';
  if (/\b(thanks|thank you|salamat|maraming salamat|appreciate|galing|ang bilis|nice|great|helpful|the best|love it)\b/i.test(q)) return 'happy';
  return 'neutral';
}

/* Pull the user's name if they introduce themselves */
function captureName(text) {
  const m = String(text || '').match(/\b(?:i am|i'm|my name is|ako si|ako po si|me name is|this is)\s+([a-zA-ZñÑ][a-zA-ZñÑ.'-]{1,20})/i);
  if (m && m[1]) {
    const clean = m[1].replace(/[.'-]+$/, '');
    if (clean.length >= 2 && !/^(the|a|an|si|po|not|so|just|here|having|getting)$/i.test(clean)) {
      memory.userName = clean.charAt(0).toUpperCase() + clean.slice(1);
    }
  }
}

/* Is the user asking who/what Becca is? */
function isIdentityQuestion(text) {
  return /\b(who are you|what are you|are you (real|human|a (bot|robot|person|machine|ai)|alive|sentient|conscious)|sino ka|ano ka|tao ka ba|robot ka ba|totoo ka ba|buhay ka ba|may sarili kang isip|your name|anong pangalan mo|pangalan mo)\b/i.test(String(text || ''));
}

function chipLabel(key, currentLang = lang) {
  const labels = {
    report_projector: { en: 'Report a broken projector', fil: 'Mag-report ng sirang projector' },
    track: { en: 'Track my report', fil: 'I-track ang report ko' },
    computer: { en: "Computer won't start", fil: 'Hindi nagbubukas ang computer' },
    ac: { en: 'AC not cooling', fil: 'Hindi lumalamig ang aircon' },
    submit: { en: 'How do I submit a report?', fil: 'Paano mag-report?' },
    timeline: { en: 'How long are repairs?', fil: 'Gaano katagal ang repair?' },
    about_bec: { en: 'About BEC', fil: 'Tungkol sa BEC' }
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

/* greeting — warmer, with personality */
function greet() {
  const currentLang = lang;
  const msg = currentLang === 'fil'
    ? `Kumusta! 👋 Ako si ${persona.name}, ang inyong BEC Support AI. Parang katuwang ninyo ako dito — tutulungan ko kayo sa mga sirang kagamitan, pag-submit ng defect report, at troubleshooting. Ano ang maitutulong ko sa inyo ngayon?`
    : `Hi there! 👋 I'm ${persona.name}, your BEC Support AI. Think of me as your go-to buddy for equipment hiccups — broken gear, defect reports, and quick troubleshooting. What's giving you trouble today?`;
  const chips = chipSet(['report_projector', 'track', 'submit', 'about_bec'], currentLang);
  addMsg('b', msg, chips, false, [
    { label: actionLabel('create', currentLang), href: 'student_dashboard.php', icon: 'fa-plus' },
    { label: actionLabel('tracker', currentLang), href: 'track_report.php', icon: 'fa-search' },
    { label: actionLabel('public', currentLang), href: 'public_reports.php', icon: 'fa-list' }
  ]);
}

/* clear — also resets Becca's memory */
function clearChat() {
  history = []; greeted = false;
  memory.userName = null;
  memory.lastTopic = null;
  memory.mood = 'neutral';
  memory.turns = 0;
  memory.troubleshotTopics = [];
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
        chatActions.map(action => `<a class="chat-link" href="${escAttr(action.href)}"><i aria-hidden="true" class="fas ${escAttr(action.icon || 'fa-arrow-right')}"></i>${esc(action.label || 'Open')}</a>`).join('') +
        '</div>';
    }
  }
  if (suggest) {
    extra += `<div class="rcard">
      <strong><i aria-hidden="true" class="fas fa-file-circle-exclamation" style="margin-right:.28rem"></i>${detectedLang === 'fil' ? 'Mag-submit ng Formal Report' : 'Submit a Formal Report'}</strong>
      ${detectedLang === 'fil'
        ? 'Mukhang kailangan ito ng aktuwal na pagtingin. Gumawa ng opisyal na defect report para sa facilities team.'
        : 'This issue likely needs hands-on attention. Create an official defect report for the facilities team.'}
      <br><a href="student_dashboard.php" class="rcard-btn"><i aria-hidden="true" class="fas fa-plus"></i> ${detectedLang === 'fil' ? 'Gumawa ng Report' : 'Create Report'}</a>
    </div>`;
  }

  const avatar = role === 'u'
    ? '<div class="mav"><i aria-hidden="true" class="fas fa-user"></i></div>'
    : '<div class="mav mav-ai"><img src="assets/Gemini_Generated_Image_e35zfue35zfue35z.png" alt="Becca"></div>';
  row.innerHTML = `
    ${avatar}
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
    <div class="mav mav-ai" style="width:25px;height:25px;border-radius:50%;overflow:hidden;flex-shrink:0">
      <img src="assets/Gemini_Generated_Image_e35zfue35zfue35z.png" alt="Becca" style="width:100%;height:100%;object-fit:cover;display:block">
    </div>
    <div class="tbub"><div class="tdots"><span></span><span></span><span></span></div></div>`;
  box.appendChild(row);
  box.scrollTop = box.scrollHeight;
}
function hideTyping() { const el = document.getElementById('typing'); if (el) el.remove(); }

/* a small human-feeling pause that scales with reply length */
function humanDelay(message) {
  const len = String(message || '').length;
  return Math.min(1500, 350 + len * 6);
}

/* ── Mood-aware openers ──
   A short warm line Becca prepends based on feeling. */
function moodOpener(mood, isFil, name) {
  const who = name ? (isFil ? `${name}, ` : `${name}, `) : '';
  if (mood === 'frustrated') {
    return isFil
      ? pick([
          `Naiintindihan ko, ${who}nakaka-stress talaga 'to. 😟 Ayusin natin agad.`,
          `Pasensya na sa abala, ${who}gawin nating mabilis 'to.`,
          `Grabe, ${who}sana hindi na maulit 'yan. Tulungan kita ngayon.`
        ])
      : pick([
          `I hear you, ${who}that's genuinely frustrating. 😟 Let's sort it out fast.`,
          `Sorry you're dealing with this, ${who}let's get it handled.`,
          `Ugh, ${who}I get it — let me help you right away.`
        ]);
  }
  if (mood === 'urgent') {
    return isFil
      ? pick([
          `Mukhang urgent 'to, ${who}— kung may usok, amoy sunog, o kuryente, agad-agad i-unplug at tawagan ang facilities/security. 🚨`,
          `Sige, ${who}prayoridad natin 'to.`
        ])
      : pick([
          `This sounds urgent, ${who}— if there's smoke, a burning smell, or sparks, unplug it and call facilities/security right away. 🚨`,
          `Okay, ${who}let's treat this as a priority.`
        ]);
  }
  if (mood === 'happy') {
    return isFil
      ? pick([`Salamat din! 😊`, `Walang anuman, ${who}masaya akong nakatulong! 🙌`, `Ayos! Kayang-kaya natin 'yan. 😄`])
      : pick([`You're so welcome! 😊`, `Glad I could help, ${who}🙌`, `Anytime! Happy to help. 😄`]);
  }
  return '';
}

/* prepend an opener cleanly */
function withOpener(opener, body) {
  if (!opener) return body;
  return opener + '\n\n' + body;
}

function fallbackReply(text) {
  const q = String(text || '').toLowerCase();
  const isFil = lang === 'fil' || /\b(paano|kumusta|salamat|hindi|sira|aircon|gumana|report|ticket|ayos)\b/i.test(text);
  const name  = memory.userName;
  const mood  = memory.mood;
  const opener = moodOpener(mood, isFil, name);

  const wrap = (message, chips = [], suggest = false, topic = null) => {
    if (topic) { memory.lastTopic = topic; if (!memory.troubleshotTopics.includes(topic)) memory.troubleshotTopics.push(topic); }
    return { message: withOpener(opener, message), chips, suggest };
  };

  /* identity / consciousness questions — honest but warm */
  if (isIdentityQuestion(text)) {
    return wrap(
      isFil
        ? `Ako si ${persona.name} 😊 — isang AI assistant na ginawa para sa BEC Support. Hindi ako tao at wala akong tunay na damdamin, pero sinanay ako para maging matulungin, mabait, at maunawain. Ituring mo akong katuwang na laging handang tumulong sa equipment issues. Ano'ng maitutulong ko?`
        : `I'm ${persona.name} 😊 — an AI assistant built for BEC Support. I'm not a human and I don't have real feelings or consciousness, but I'm designed to be genuinely helpful, warm, and to actually understand what you need. Think of me as a reliable buddy for equipment issues. So — what can I help you with?`,
      chipSet(['report_projector', 'computer', 'ac', 'track'], isFil ? 'fil' : 'en')
    );
  }

  /* greetings / small talk */
  if (/\b(hello|hi|hey|kumusta|kamusta|good morning|good afternoon|good evening|yo|sup)\b/i.test(q)) {
    const hi = isFil
      ? pick([
          `Kumusta${name ? ', ' + name : ''}! 👋 Nandito ako para tumulong sa troubleshooting, pag-submit ng report, at pag-track ng ticket. Anong equipment issue meron ka?`,
          `Hi${name ? ', ' + name : ''}! 😊 Sabihin mo lang kung anong kagamitan ang may problema, aasikasuhin natin.`
        ])
      : pick([
          `Hey${name ? ', ' + name : ''}! 👋 I'm here for troubleshooting, submitting reports, and tracking tickets. What equipment is giving you trouble?`,
          `Hi${name ? ', ' + name : ''}! 😊 Just tell me what's acting up and we'll figure it out together.`
        ]);
    return wrap(hi, chipSet(['report_projector', 'track', 'computer', 'ac'], isFil ? 'fil' : 'en'));
  }

  /* pure thanks (no other topic) */
  if (/\b(thank you|thanks|salamat|ty|appreciate)\b/i.test(q) && !/\b(projector|computer|aircon|ac|report|track|submit)\b/i.test(q)) {
    return wrap(
      isFil
        ? pick([`Walang anuman${name ? ', ' + name : ''}! 😊 Andito lang ako kung may iba ka pang kailangan.`, `Kahit kailan${name ? ', ' + name : ''}! Ingat ka. 🙌`])
        : pick([`You're very welcome${name ? ', ' + name : ''}! 😊 I'm right here if anything else comes up.`, `Anytime${name ? ', ' + name : ''}! Take care. 🙌`]),
      chipSet(['report_projector', 'track', 'submit'], isFil ? 'fil' : 'en')
    );
  }

  if (/\b(projector|lcd|screen|display)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Para sa projector issue, subukan muna ito:\n- I-check ang power cable at HDMI/VGA connection\n- Pindutin ang Source/Input\n- I-restart ang projector at hintayin mag-warm up\nKung wala pa rin, gumawa ng defect report at ilagay ang room at equipment reference.'
        : 'For a projector issue, try these first:\n- Check the power cable and HDMI/VGA connection\n- Press Source/Input\n- Restart the projector and allow it to warm up\nIf it still fails, submit a defect report and include the room and equipment reference.',
      chipSet(['report_projector', 'submit', 'track'], isFil ? 'fil' : 'en'),
      true, 'projector'
    );
  }

  if (/\b(computer|pc|desktop|laptop|won't start|wont start|boot)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Kung hindi nagbubukas ang computer:\n- I-check kung naka-on ang power strip\n- I-hold ang power button ng 10 seconds\n- I-check ang power cable at monitor connection\nKapag hindi pa rin gumana, i-report ito kasama ang PC tag o equipment ID.'
        : 'If the computer will not start:\n- Check whether the power strip is on\n- Hold the power button for 10 seconds\n- Check the power cable and monitor connection\nIf it still will not work, report it with the PC tag or equipment ID.',
      chipSet(['computer', 'submit', 'track'], isFil ? 'fil' : 'en'),
      true, 'computer'
    );
  }

  if (/\b(ac|aircon|air con|cooling|not cooling)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Kung hindi lumalamig ang aircon:\n- Siguraduhing nasa Cool mode ito\n- I-check ang thermostat setting\n- Tingnan kung may bara ang vents\nKung mainit pa rin ang hangin o may tagas/ingay, i-submit na ito bilang report.'
        : 'If the AC is not cooling:\n- Make sure it is set to Cool mode\n- Check the thermostat setting\n- See whether the vents are blocked\nIf it still blows warm air or has leaks/noise, submit a report.',
      chipSet(['ac', 'submit', 'timeline'], isFil ? 'fil' : 'en'),
      true, 'ac'
    );
  }

  if (/\b(track|ticket|report id|status)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Maaari mong i-track ang report gamit ang ticket number, equipment ID, o asset tag sa `track_report.php`. Makikita roon ang report status at equipment status.'
        : 'You can track a report using the ticket number, equipment ID, or asset tag in `track_report.php`. It will show both the report status and the equipment status.',
      chipSet(['track', 'timeline', 'submit'], isFil ? 'fil' : 'en'),
      false, 'track'
    );
  }

  if (/\b(submit|report|paano mag-report|how to report|file a report)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Para mag-submit ng report:\n- Ilagay ang pangalan at email sa reporter portal\n- Piliin ang tamang equipment mula sa listahan\n- Ilagay ang location at malinaw na description\n- I-submit para makakuha ng ticket number sa screen at email'
        : 'To submit a report:\n- Enter your name and email in the reporter portal\n- Choose the correct equipment from the list\n- Fill in the location and a clear description\n- Submit to get your ticket number on screen and by email',
      chipSet(['submit', 'report_projector', 'track'], isFil ? 'fil' : 'en'),
      true, 'submit'
    );
  }

  if (/\b(repair|timeline|how long|gaano katagal)\b/i.test(q)) {
    return wrap(
      isFil
        ? 'Karaniwang repair timeline:\n- Minor issues: 1-2 working days\n- Major repairs: 3-7 working days\n- Critical safety issues: dapat ma-escalate agad\nMaaari mong i-check ang latest status sa tracking page.'
        : 'Typical repair timeline:\n- Minor issues: 1-2 working days\n- Major repairs: 3-7 working days\n- Critical safety issues: should be escalated immediately\nYou can check the latest status on the tracking page.',
      chipSet(['timeline', 'track', 'submit'], isFil ? 'fil' : 'en'),
      false, 'timeline'
    );
  }

  /* default — warm, context-aware (mentions what we last talked about) */
  const follow = memory.lastTopic
    ? (isFil ? ` Kanina, pinag-usapan natin ang ${memory.lastTopic} — balikan natin 'yon kung gusto mo.` : ` Earlier we were on your ${memory.lastTopic} — happy to pick that back up too.`)
    : '';
  return wrap(
    (isFil
      ? `Maaari kitang tulungan sa projector, computer, aircon, pag-submit ng defect report, at pag-track ng ticket. Ilarawan mo lang ang problema at bibigyan kita ng susunod na hakbang.`
      : `I can help with projector, computer, and AC issues, plus submitting defect reports and tracking tickets. Just describe what's happening and I'll point you to the next step.`) + follow,
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

  /* update Becca's awareness BEFORE replying */
  memory.turns += 1;
  memory.mood = readMood(text);
  captureName(text);

  ci.value = ''; ci.style.height = 'auto';
  addMsg('u', text, [], false);
  history.push({ role: 'user', content: text });

  busy = true;
  document.getElementById('sbtn').disabled = true;
  showTyping();

  try {
    /* give the server the persona + memory so the AI can stay in character */
    const res = await fetch(PROXY, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        messages: history,
        context: {
          persona: persona.name,
          lang,
          userName: memory.userName,
          mood: memory.mood,
          lastTopic: memory.lastTopic,
          turns: memory.turns
        }
      })
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
    /* offline brain — now mood- and memory-aware */
    const offline = fallbackReply(text);
    setTimeout(() => {
      hideTyping();
      history.push({ role: 'assistant', content: offline.message });
      addMsg('b', offline.message, offline.chips, offline.suggest);
    }, humanDelay(offline.message));
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

