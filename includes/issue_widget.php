<?php
/**
 * issue_widget.php — "Report an issue" button and dialog.
 *
 * Sits opposite Becca so the two never overlap: Becca answers questions about
 * campus equipment, this one is for when the software itself misbehaves. Two
 * different jobs, so two different controls rather than one that has to ask
 * which you meant.
 *
 * Self-contained. Include it once before </body>; it posts to issue_report.php
 * and needs nothing else from the page.
 */
?>
<style>
#iss-fab{position:fixed;right:18px;bottom:18px;z-index:940;display:inline-flex;align-items:center;gap:.5rem;
  background:#4A0E0E;color:#fff;border:1.5px solid rgba(201,150,12,.55);border-radius:26px;
  padding:.6rem 1rem;font-family:'DM Sans',system-ui,sans-serif;font-size:.82rem;font-weight:600;
  cursor:pointer;box-shadow:0 8px 22px rgba(44,10,10,.32);transition:background .18s,transform .18s;}
#iss-fab:hover{background:#7B1D1D;transform:translateY(-2px);}
#iss-fab i{color:#F0C040;font-size:.9rem;}
@media(max-width:640px){#iss-fab{padding:.6rem;border-radius:50%;right:14px;bottom:14px;}
  #iss-fab .iss-lbl{display:none;}}

/* Share the bottom-right corner instead of fighting over it.
   The back-to-top button (#toTop, defined in the host page's head) also sits at
   bottom-right, and at z-index 9996 against this pill's 940 it landed squarely
   on top of the label. Nothing was broken — you just could not read "Report an
   issue" or reliably hit either one.
   This rule stacks the button above the pill. It lives here, not in the host
   page, because the pill is what creates the conflict: a page without this
   widget keeps its own bottom offset untouched. No !important needed — this
   <style> is emitted at the end of <body>, so it wins on source order against
   an equally specific #toTop rule in the <head>.
   The numbers are the pill's own box: 18px offset + ~40px tall + 12px gap. */
#toTop{bottom:70px;}
@media(max-width:640px){
  /* the pill collapses to a ~38px circle at a 14px offset */
  #toTop{bottom:62px;}
}

#iss-ov{position:fixed;inset:0;z-index:960;background:rgba(26,8,8,.55);backdrop-filter:blur(3px);
  display:none;align-items:center;justify-content:center;padding:1rem;}
#iss-ov.on{display:flex;}
.iss-card{background:#fff;border-radius:16px;width:100%;max-width:560px;max-height:92vh;overflow:auto;
  font-family:'DM Sans',system-ui,sans-serif;color:#1C1008;box-shadow:0 24px 60px rgba(28,16,8,.4);}
.iss-hd{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.1rem 1.3rem;
  border-bottom:1px solid #E6DACB;}
.iss-hd h2{font-size:1.15rem;font-weight:700;color:#4A0E0E;margin:0;}
.iss-x{background:none;border:0;font-size:1.4rem;line-height:1;color:#7A6255;cursor:pointer;padding:0 .2rem;}
.iss-bd{padding:1.1rem 1.3rem;display:flex;flex-direction:column;gap:.9rem;}
.iss-note{display:flex;gap:.8rem;align-items:flex-start;background:#FBF7F0;border:1px solid #E6DACB;
  border-radius:10px;padding:.8rem .95rem;}
.iss-note i{color:#C9960C;font-size:1.1rem;margin-top:.1rem;}
.iss-note b{display:block;font-size:.9rem;}
.iss-note span{font-size:.82rem;color:#5C3838;line-height:1.55;}
.iss-two{display:grid;grid-template-columns:1fr 1fr;gap:.9rem;}
@media(max-width:520px){.iss-two{grid-template-columns:1fr;}}
.iss-f label{display:block;font-size:.68rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;
  color:#7A6255;margin-bottom:.3rem;}
.iss-f input[type=text],.iss-f input[type=email],.iss-f select,.iss-f textarea{
  width:100%;padding:.65rem .8rem;border:1.5px solid #E6DACB;border-radius:9px;background:#FBF9F6;
  font-family:inherit;font-size:.9rem;color:#1C1008;}
.iss-f textarea{min-height:110px;resize:vertical;line-height:1.6;}
.iss-f input:focus,.iss-f select:focus,.iss-f textarea:focus{outline:none;border-color:#7B1D1D;background:#fff;}
.iss-att{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;}
.iss-att .iss-sub{font-size:.78rem;color:#7A6255;}
.iss-add{display:inline-flex;align-items:center;gap:.4rem;background:#fff;border:1.5px solid #E6DACB;
  border-radius:9px;padding:.5rem .85rem;font-family:inherit;font-size:.82rem;font-weight:600;
  color:#4A0E0E;cursor:pointer;}
.iss-add:hover{border-color:#7B1D1D;}
.iss-files{display:flex;flex-direction:column;gap:.35rem;}
.iss-file{display:flex;align-items:center;gap:.5rem;font-size:.8rem;color:#5C3838;background:#FBF7F0;
  border:1px solid #E6DACB;border-radius:8px;padding:.4rem .6rem;}
.iss-file button{margin-left:auto;background:none;border:0;color:#B42318;cursor:pointer;font-size:.9rem;}
.iss-msg{font-size:.84rem;padding:.6rem .8rem;border-radius:8px;display:none;}
.iss-msg.err{display:block;background:#FDF3F2;border:1px solid #F3D2CF;color:#B42318;}
.iss-msg.ok{display:block;background:#EEF7F0;border:1px solid #CFE9D6;color:#1A7A33;}
.iss-ft{display:flex;justify-content:flex-end;gap:.7rem;padding:1rem 1.3rem;border-top:1px solid #E6DACB;}
.iss-cancel{background:#fff;border:1.5px solid #E6DACB;border-radius:9px;padding:.65rem 1.1rem;
  font-family:inherit;font-size:.88rem;font-weight:600;color:#5C3838;cursor:pointer;}
.iss-send{background:#C9960C;border:0;border-radius:9px;padding:.65rem 1.2rem;font-family:inherit;
  font-size:.88rem;font-weight:700;color:#1C1008;cursor:pointer;}
.iss-send:hover{background:#B0840A;}
.iss-send:disabled{opacity:.6;cursor:progress;}
.iss-hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;}
@media (prefers-reduced-motion: reduce){#iss-fab{transition:none;}}
</style>

<button type="button" id="iss-fab" aria-haspopup="dialog">
  <i class="fas fa-bug" aria-hidden="true"></i><span class="iss-lbl">Report an issue</span>
</button>

<div id="iss-ov" role="dialog" aria-modal="true" aria-labelledby="iss-t">
  <div class="iss-card">
    <div class="iss-hd">
      <h2 id="iss-t">Report an Issue</h2>
      <button type="button" class="iss-x" data-iss-close aria-label="Close">&times;</button>
    </div>
    <form id="iss-form" novalidate>
      <div class="iss-bd">
        <div class="iss-note">
          <i class="fas fa-circle-info" aria-hidden="true"></i>
          <span><b>Something wrong with this website?</b>
          Tell us what happened and we will look into it. To report broken
          <em>equipment</em> on campus, use the reporting portal instead.</span>
        </div>

        <div class="iss-two">
          <div class="iss-f">
            <label for="iss-cat">Category</label>
            <select id="iss-cat" name="category">
              <option>Bug</option><option>Suggestion</option><option>Question</option><option>Other</option>
            </select>
          </div>
          <div class="iss-f">
            <label for="iss-em">Contact email <span style="text-transform:none;font-weight:400;">(optional)</span></label>
            <input type="email" id="iss-em" name="email" placeholder="you@bec.edu.ph" autocomplete="email">
          </div>
        </div>

        <div class="iss-f">
          <label for="iss-sub">Subject</label>
          <input type="text" id="iss-sub" name="subject" maxlength="150" placeholder="Short summary of the problem">
        </div>

        <div class="iss-f">
          <label for="iss-desc">Description</label>
          <textarea id="iss-desc" name="description" maxlength="5000"
            placeholder="What happened, what you expected, and anything you did just before it."></textarea>
        </div>

        <div class="iss-f">
          <div class="iss-att">
            <div>
              <label style="margin:0;">Attachments</label>
              <div class="iss-sub">Optional. Up to 3 images or videos, 15MB each.</div>
            </div>
            <button type="button" class="iss-add" id="iss-pick">
              <i class="fas fa-paperclip" aria-hidden="true"></i> Add files
            </button>
          </div>
          <input type="file" id="iss-files" accept="image/*,video/*" multiple hidden>
          <div class="iss-files" id="iss-list" style="margin-top:.5rem;"></div>
        </div>

        <div class="iss-msg" id="iss-msg" role="status"></div>
        <div class="iss-hp"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      </div>
      <div class="iss-ft">
        <button type="button" class="iss-cancel" data-iss-close>Cancel</button>
        <button type="submit" class="iss-send" id="iss-send">Submit report</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var fab = document.getElementById('iss-fab'),
      ov  = document.getElementById('iss-ov'),
      frm = document.getElementById('iss-form'),
      pick= document.getElementById('iss-pick'),
      inp = document.getElementById('iss-files'),
      list= document.getElementById('iss-list'),
      msg = document.getElementById('iss-msg'),
      send= document.getElementById('iss-send');
  if (!fab || !ov) { return; }

  var MAX = 3, MAX_BYTES = 15 * 1024 * 1024, chosen = [];
  var lastFocus = null;

  function open()  { lastFocus = document.activeElement; ov.classList.add('on');
                     document.getElementById('iss-sub').focus(); }
  function close() { ov.classList.remove('on'); if (lastFocus) { try { lastFocus.focus(); } catch (e) {} } }

  fab.addEventListener('click', open);
  ov.addEventListener('click', function (e) {
    if (e.target === ov || e.target.closest('[data-iss-close]')) { close(); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && ov.classList.contains('on')) { close(); }
  });

  function say(kind, text) { msg.className = 'iss-msg ' + kind; msg.textContent = text; }
  function fmt(b) { return b < 1048576 ? Math.round(b / 1024) + ' KB' : (b / 1048576).toFixed(1) + ' MB'; }

  function render() {
    list.innerHTML = '';
    chosen.forEach(function (f, i) {
      var row = document.createElement('div');
      row.className = 'iss-file';
      row.innerHTML = '<i class="fas fa-' + (/^video\//.test(f.type) ? 'film' : 'image') + '"></i>' +
                      '<span></span><span style="color:#9E8070">' + fmt(f.size) + '</span>' +
                      '<button type="button" aria-label="Remove">&times;</button>';
      row.querySelector('span').textContent = f.name;   // never as markup
      row.querySelector('button').addEventListener('click', function () {
        chosen.splice(i, 1); render();
      });
      list.appendChild(row);
    });
  }

  pick.addEventListener('click', function () { inp.click(); });
  inp.addEventListener('change', function () {
    var errs = [];
    Array.prototype.forEach.call(inp.files, function (f) {
      if (chosen.length >= MAX)      { errs.push('Up to ' + MAX + ' files.'); return; }
      if (f.size > MAX_BYTES)        { errs.push(f.name + ' is over 15MB.'); return; }
      if (!/^(image|video)\//.test(f.type)) { errs.push(f.name + ' is not an image or video.'); return; }
      chosen.push(f);
    });
    inp.value = '';                 // so the same file can be chosen again later
    render();
    if (errs.length) { say('err', errs[0]); } else { msg.className = 'iss-msg'; }
  });

  frm.addEventListener('submit', function (e) {
    e.preventDefault();
    var subj = document.getElementById('iss-sub').value.trim();
    var desc = document.getElementById('iss-desc').value.trim();
    if (!subj) { say('err', 'Please give the problem a short subject.'); return; }
    if (!desc) { say('err', 'Please describe what happened.'); return; }

    var fd = new FormData();
    fd.append('category',    document.getElementById('iss-cat').value);
    fd.append('email',       document.getElementById('iss-em').value.trim());
    fd.append('subject',     subj);
    fd.append('description', desc);
    fd.append('page',        location.href);
    fd.append('website',     frm.querySelector('[name=website]').value);
    chosen.forEach(function (f) { fd.append('files[]', f); });

    send.disabled = true; say('ok', 'Sending…');
    fetch('issue_report.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Unexpected reply from the server.' }; }); })
      .then(function (d) {
        if (!d.ok) { say('err', d.message || 'Could not send that report.'); send.disabled = false; return; }
        say('ok', d.message);
        frm.reset(); chosen = []; render();
        setTimeout(function () { close(); send.disabled = false; msg.className = 'iss-msg'; }, 2200);
      })
      .catch(function () {
        say('err', 'Could not reach the server. Please check your connection and try again.');
        send.disabled = false;
      });
  });
})();
</script>
