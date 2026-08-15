<?php
/**
 * reserve_venue.php — the Venue Reservation Form, filled online.
 *
 * Field for field the same as the PMO's paper VRF, minus the boxes that are not
 * the applicant's to fill (approval, assessment, OR number) — those are the
 * PMO's actions on admin_reservations.php. What the paper cannot do, and the
 * reason to fill it here, is tell you straight away that the room is already
 * taken at that hour.
 *
 * Open to anyone who can reach the site, like issue_report.php: a student
 * organisation asking for the AVR should not need an account first. A signed-in
 * session just pre-fills the applicant.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('main');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rate_limiter.php';
require_once __DIR__ . '/includes/reservation_helper.php';

$pdo = getPgsqlPdoConnection();
function rq_e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$isWalkIn = isset($_GET['walkin']);   // PMO filing a counter request on someone's behalf
$sessName = trim((string)($_SESSION['fullname'] ?? ''));
$sessMail = trim((string)($_SESSION['user_email'] ?? ($_SESSION['email'] ?? '')));
$sessDept = trim((string)($_SESSION['department'] ?? ''));
$sessUid  = trim((string)($_SESSION['user_id'] ?? ''));

$flash = null; $conflicts = []; $created = null;
$fv = [
    'applicant_name' => $isWalkIn ? '' : $sessName,
    'applicant_email'=> $isWalkIn ? '' : $sessMail,
    'applicant_phone'=> '',
    'department_org' => $isWalkIn ? '' : $sessDept,
    'venue' => '', 'nature' => 'meeting', 'nature_other' => '',
    'date' => '', 'start_time' => '', 'end_time' => '',
    'participants' => '', 'description' => '', 'cf_no' => '',
    'adviser_name' => '',
];
$materials = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    foreach ($fv as $k => $_) { $fv[$k] = trim((string)($_POST[$k] ?? '')); }
    // Hard caps matching the column widths — a value longer than the column is
    // a database error, not a validation message, so it is cut here first.
    foreach (['applicant_name' => 160, 'applicant_email' => 160, 'applicant_phone' => 40,
              'department_org' => 160, 'venue' => 200, 'nature_other' => 160,
              'adviser_name' => 160, 'cf_no' => 30] as $k => $max) {
        $fv[$k] = mb_substr($fv[$k], 0, $max);
    }
    $fv['description'] = mb_substr($fv['description'], 0, 2000);
    $materials = vrMaterialsFromPost($_POST);

    $nature = array_key_exists($fv['nature'], vrNatures()) ? $fv['nature'] : 'meeting';
    $date   = $fv['date'];
    $errors = [];
    if ($fv['applicant_name'] === '')  { $errors[] = 'your name'; }
    if ($fv['department_org'] === '')  { $errors[] = 'your department or organisation'; }
    if ($fv['venue'] === '')           { $errors[] = 'the venue'; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $errors[] = 'the date'; }
    if (!preg_match('/^\d{2}:\d{2}$/', $fv['start_time']) || !preg_match('/^\d{2}:\d{2}$/', $fv['end_time'])) {
        $errors[] = 'the start and end time';
    }
    if ($fv['applicant_email'] !== '' && !filter_var($fv['applicant_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'a valid email address (or leave it blank)';
    }
    // A booking two years out is not a booking, it is a squat on the room.
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) > strtotime('+1 year')) {
        $errors[] = 'a date within the next year';
    }
    $paxIn = $fv['participants'];
    if ($paxIn !== '' && (!ctype_digit($paxIn) || (int)$paxIn < 1 || (int)$paxIn > 100000)) {
        $errors[] = 'a sensible number of participants';
    }

    if ($errors) {
        $flash = ['err', 'Please give ' . implode(', ', $errors) . '.'];
    } else {
        $startsAt = $date . ' ' . $fv['start_time'] . ':00';
        $endsAt   = $date . ' ' . $fv['end_time']   . ':00';
        if (strtotime($endsAt) <= strtotime($startsAt)) {
            $flash = ['err', 'The end time has to be after the start time.'];
        } elseif (strtotime($startsAt) < strtotime('today')) {
            $flash = ['err', 'That date has already passed — choose a date from today onwards.'];
        } else {
            // Name the clash before the database refuses it, so the applicant
            // can pick another slot instead of reading a constraint error.
            $conflicts = vrConflicts($pdo, $fv['venue'], $startsAt, $endsAt);
            if ($conflicts) {
                $c = $conflicts[0];
                $flash = ['err', $fv['venue'] . ' is already taken for ' . vrRange($c['starts_at'], $c['ends_at'])
                               . ' by ' . $c['department_org'] . '. Pick another time or venue.'];
            } else {
                /* This form is open to anyone who can reach the site, and a
                   submitted request *holds the room* — so unthrottled it is a
                   way to block every venue on campus for the price of a loop.
                   The quota is charged here rather than at the top of the
                   handler so that only bookings that actually get created count
                   against it: someone who mistypes their email three times is
                   making mistakes, not flooding, and must not be locked out for
                   an hour for it. */
                try {
                    RateLimiter::enforce('vrf_submit_' . RateLimiter::clientIp(), $sessUid !== '' ? 12 : 6, 3600);
                } catch (\Throwable $e) {
                    $flash = ['err', $e->getMessage()];
                }
            }
            if (!$flash && !$conflicts) {
                try {
                    $st = $pdo->prepare("INSERT INTO public.venue_reservations
                        (applicant_user_id,applicant_name,applicant_email,applicant_phone,department_org,
                         venue,nature,nature_other,starts_at,ends_at,participants,description,materials,
                         adviser_name,cf_no,status,created_by,created_at,updated_at)
                        VALUES (:uid,:nm,:em,:ph,:org,:vn,:nat,:noth,:sa,:ea,:pax,:desc,CAST(:mats AS jsonb),
                                :adv,:cf,'submitted',:cb,now(),now())
                        RETURNING id");
                    $st->execute([
                        'uid'  => $sessUid !== '' && !$isWalkIn ? $sessUid : null,
                        'nm'   => $fv['applicant_name'],
                        'em'   => $fv['applicant_email'] ?: null,
                        'ph'   => $fv['applicant_phone'] ?: null,
                        'org'  => $fv['department_org'],
                        'vn'   => $fv['venue'],
                        'nat'  => $nature,
                        'noth' => $nature === 'others' ? ($fv['nature_other'] ?: null) : null,
                        'sa'   => $startsAt,
                        'ea'   => $endsAt,
                        'pax'  => $fv['participants'] !== '' ? (int)$fv['participants'] : null,
                        'desc' => $fv['description'] ?: null,
                        'mats' => json_encode($materials, JSON_UNESCAPED_UNICODE),
                        'adv'  => $fv['adviser_name'] ?: null,
                        'cf'   => $fv['cf_no'] ?: null,
                        'cb'   => $sessUid ?: 'guest',
                    ]);
                    $created = (int)$st->fetchColumn();
                    if (function_exists('logActivity')) {
                        try { logActivity($sessUid ?: 'guest', 'reservation', 'vrf.submit', 'Filed venue reservation #' . $created . ' — ' . $fv['venue']); } catch (\Throwable $e) {}
                    }
                    /* A receipt, so the applicant has the slot they booked in
                       writing. Never blocks the submission — the request is
                       already saved, and sendEmail() keeps its own retry outbox. */
                    $mailedReceipt = vrNotifyApplicant([
                        'applicant_email' => $fv['applicant_email'],
                        'applicant_name'  => $fv['applicant_name'],
                        'department_org'  => $fv['department_org'],
                        'venue'           => $fv['venue'],
                        'nature'          => $nature,
                        'nature_other'    => $fv['nature_other'],
                        'starts_at'       => $startsAt,
                        'ends_at'         => $endsAt,
                        'participants'    => $fv['participants'],
                    ], 'submitted');
                } catch (\Throwable $e) {
                    // Two people submitting the same slot at the same moment: the
                    // exclusion constraint decides, and this is the loser.
                    error_log('vrf.submit failed: ' . $e->getMessage());
                    $flash = ['err', 'That slot was taken while you were filling the form. Please choose another time.'];
                }
            }
        }
    }
}

$venues = vrVenueSuggestions($pdo);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Venue Reservation — BEC Property Management Office</title>
<link rel="icon" type="image/png" href="assets/logs.png">
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<style>
  :root{--m:#7B1D1D;--md:#4A0E0E;--g:#C9960C;--ink:#1A0808;--ink2:#5C3838;--ink3:#9C7A7A;--paper:#F4EFE6;--surface:#fff;--border:#E5D9C6;--danger:#B42318;--success:#1A7A33;}
  *{box-sizing:border-box}
  body{margin:0;font-family:'DM Sans',system-ui,sans-serif;background:var(--paper);color:var(--ink);font-size:.9rem;}
  .top{background:linear-gradient(135deg,var(--md),var(--m));color:#fff;padding:1.1rem 1.25rem;display:flex;align-items:center;gap:.85rem;}
  .top img{width:38px;height:38px;object-fit:contain;}
  .top h1{margin:0;font-family:'Outfit',sans-serif;font-size:1.02rem;font-weight:700;}
  .top p{margin:.15rem 0 0;font-size:.72rem;opacity:.85;}
  .top a.back{margin-left:auto;color:#fff;text-decoration:none;font-size:.78rem;font-weight:700;opacity:.9;}
  .wrap{max-width:860px;margin:0 auto;padding:1.4rem 1.1rem 4rem;}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.3rem;box-shadow:0 1px 3px rgba(44,10,10,.06);}
  .lede{font-size:.82rem;color:var(--ink3);line-height:1.6;margin:0 0 1.2rem;}
  .flash{display:flex;gap:.55rem;padding:.85rem 1rem;border-radius:11px;margin-bottom:1.1rem;font-size:.85rem;font-weight:600;line-height:1.5;}
  .flash.ok{background:#E9F9EF;border:1px solid #b6e6c6;color:var(--success);}
  .flash.err{background:#FEF2F2;border:1px solid #FECACA;color:var(--danger);}
  fieldset{border:none;padding:0;margin:0 0 1.3rem;}
  legend{font-size:.68rem;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--g);margin-bottom:.6rem;padding:0;}
  .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.9rem;}
  .fg{display:flex;flex-direction:column;min-width:0;}.fg.full{grid-column:1/-1;}
  label{font-size:.7rem;font-weight:700;color:var(--ink2);text-transform:uppercase;letter-spacing:.4px;margin-bottom:.3rem;}
  input,select,textarea{width:100%;padding:.7rem .8rem;border:1.5px solid var(--border);border-radius:10px;font:inherit;font-size:.9rem;background:#fff;color:var(--ink);}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--m);box-shadow:0 0 0 3px rgba(123,29,29,.1);}
  textarea{resize:vertical;min-height:76px;}
  .natures{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.5rem;}
  .nat{display:flex;align-items:center;gap:.5rem;padding:.6rem .75rem;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;background:#fff;font-size:.85rem;}
  .nat:hover{border-color:var(--m);}
  .nat input{width:auto;margin:0;accent-color:var(--m);}
  .nat.on{border-color:var(--m);background:#fdf7f0;font-weight:700;}
  .mrow{display:grid;grid-template-columns:1fr 6.5rem 2.5rem;gap:.5rem;margin-bottom:.5rem;align-items:center;}
  .mrow button{border:1.5px solid var(--border);background:#fff;border-radius:10px;height:2.7rem;cursor:pointer;color:var(--ink3);}
  .mrow button:hover{border-color:var(--danger);color:var(--danger);}
  .addmat{background:#f1eadf;border:none;border-radius:10px;padding:.6rem 1rem;font:inherit;font-weight:700;font-size:.8rem;color:var(--ink2);cursor:pointer;}
  .addmat:hover{background:#e7dac6;}
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;padding:.85rem 1.4rem;border-radius:11px;border:none;font:inherit;font-weight:700;font-size:.9rem;cursor:pointer;text-decoration:none;}
  .btn.m{background:var(--m);color:#fff;width:100%;} .btn.m:hover{background:var(--md);}
  .btn.ghost{background:#f1eadf;color:var(--ink2);}
  .note{font-size:.75rem;color:var(--ink3);line-height:1.6;margin-top:.9rem;}
  .clash{background:#FFFBEB;border:1px solid #FDE68A;border-radius:11px;padding:.85rem 1rem;font-size:.8rem;line-height:1.6;color:#92600A;margin-bottom:1.1rem;}
  .clash strong{color:#7c4a02;}
  .done{text-align:center;padding:1.4rem .5rem;}
  .done i{font-size:2.4rem;color:var(--success);display:block;margin-bottom:.7rem;}
  .done h2{font-family:'Outfit',sans-serif;font-size:1.15rem;margin:0 0 .5rem;}
  .done p{font-size:.85rem;color:var(--ink2);line-height:1.65;margin:0 auto 1.2rem;max-width:34rem;}
  .done .acts{display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap;}
  @media(max-width:640px){.grid{grid-template-columns:1fr}.mrow{grid-template-columns:1fr 5rem 2.4rem}}
</style>
</head>
<body>
<div class="top">
  <img src="assets/logs.png" alt="BEC" onerror="this.style.display='none'">
  <div>
    <h1>Venue Reservation Form</h1>
    <p>Batangas Eastern Colleges · Property Management Office</p>
  </div>
  <a class="back" href="<?php echo $isWalkIn ? 'admin_reservations.php' : 'index.php'; ?>"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div class="wrap">
  <?php if ($created): ?>
    <div class="card done">
      <i class="fas fa-circle-check"></i>
      <h2>Reservation request submitted</h2>
      <p>The venue is now held for your window while the Property Management Office reviews it.
         Your VRF number is issued when it is approved. Your department head or organisation adviser
         still needs to endorse it — the PMO records that signature on their side.</p>
      <?php if (!empty($mailedReceipt)): ?>
        <p style="font-size:.82rem;color:var(--ink3);margin-top:-.6rem;">
          <i class="fas fa-envelope"></i> A copy has been emailed to <?php echo rq_e($fv['applicant_email']); ?>.
          You will be emailed again once the PMO decides.</p>
      <?php elseif (trim($fv['applicant_email']) === ''): ?>
        <p style="font-size:.82rem;color:var(--ink3);margin-top:-.6rem;">
          <i class="fas fa-circle-info"></i> You did not give an email address, so check with the
          Property Management Office for the outcome.</p>
      <?php endif; ?>
      <div class="acts">
        <a class="btn ghost" href="reserve_venue.php<?php echo $isWalkIn ? '?walkin=1' : ''; ?>"><i class="fas fa-plus"></i> File another</a>
        <?php if ($isWalkIn): ?><a class="btn m" style="width:auto" href="admin_reservations.php"><i class="fas fa-list"></i> Back to the queue</a><?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="card">
      <p class="lede">The same form the PMO keeps on paper. Fill it here and the office can see straight away
         whether the venue is free — a room already reserved for your time window will be refused before you submit.</p>

      <?php if ($flash): ?>
        <div class="flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>">
          <i class="fas fa-<?php echo $flash[0] === 'ok' ? 'circle-check' : 'circle-exclamation'; ?>"></i>
          <div><?php echo rq_e($flash[1]); ?></div>
        </div>
      <?php endif; ?>

      <?php if ($conflicts): ?>
        <div class="clash">
          <strong><i class="fas fa-triangle-exclamation"></i> Already reserved</strong><br>
          <?php foreach ($conflicts as $c): ?>
            <?php echo rq_e(vrRange($c['starts_at'], $c['ends_at'])); ?> — <?php echo rq_e($c['department_org']); ?>
            (<?php echo rq_e(vrStatusLabel((string)$c['status'])); ?>)<br>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="POST" id="vrfForm">
        <?php echo csrf_field(); ?>

        <fieldset>
          <legend>Applicant</legend>
          <div class="grid">
            <div class="fg full">
              <label for="an">Name of applicant *</label>
              <input type="text" id="an" name="applicant_name" value="<?php echo rq_e($fv['applicant_name']); ?>" required>
            </div>
            <div class="fg">
              <label for="ao">Department / organisation *</label>
              <input type="text" id="ao" name="department_org" value="<?php echo rq_e($fv['department_org']); ?>" required>
            </div>
            <div class="fg">
              <label for="ad">Dept. head / org. adviser</label>
              <input type="text" id="ad" name="adviser_name" value="<?php echo rq_e($fv['adviser_name']); ?>" placeholder="Who will endorse it">
            </div>
            <div class="fg">
              <label for="ae">Email</label>
              <input type="email" id="ae" name="applicant_email" value="<?php echo rq_e($fv['applicant_email']); ?>" placeholder="So the PMO can reach you">
            </div>
            <div class="fg">
              <label for="ap">Contact number</label>
              <input type="text" id="ap" name="applicant_phone" value="<?php echo rq_e($fv['applicant_phone']); ?>">
            </div>
          </div>
        </fieldset>

        <fieldset>
          <legend>Venue, date and time</legend>
          <div class="grid">
            <div class="fg full">
              <label for="vn">Venue *</label>
              <input type="text" id="vn" name="venue" list="venueList" value="<?php echo rq_e($fv['venue']); ?>"
                     placeholder="Start typing — pick from the list where you can" required autocomplete="off">
              <datalist id="venueList">
                <?php foreach ($venues as $v): ?><option value="<?php echo rq_e($v); ?>"></option><?php endforeach; ?>
              </datalist>
            </div>
            <div class="fg">
              <label for="dt">Date *</label>
              <input type="date" id="dt" name="date" value="<?php echo rq_e($fv['date']); ?>" min="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="fg">
              <label for="pax">Expected participants</label>
              <input type="number" id="pax" name="participants" min="1" max="100000" value="<?php echo rq_e($fv['participants']); ?>">
            </div>
            <div class="fg">
              <label for="st">Start time *</label>
              <input type="time" id="st" name="start_time" value="<?php echo rq_e($fv['start_time']); ?>" required>
            </div>
            <div class="fg">
              <label for="et">End time *</label>
              <input type="time" id="et" name="end_time" value="<?php echo rq_e($fv['end_time']); ?>" required>
            </div>
          </div>
        </fieldset>

        <fieldset>
          <legend>Nature of activity</legend>
          <div class="natures">
            <?php foreach (vrNatures() as $nv => $nl): ?>
              <label class="nat<?php echo $fv['nature'] === $nv ? ' on' : ''; ?>">
                <input type="radio" name="nature" value="<?php echo $nv; ?>" <?php echo $fv['nature'] === $nv ? 'checked' : ''; ?>>
                <?php echo rq_e($nl); ?>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="fg" id="otherWrap" style="margin-top:.7rem;<?php echo $fv['nature'] === 'others' ? '' : 'display:none;'; ?>">
            <label for="no">Please specify</label>
            <input type="text" id="no" name="nature_other" value="<?php echo rq_e($fv['nature_other']); ?>">
          </div>
        </fieldset>

        <fieldset>
          <legend>Description of activity</legend>
          <textarea name="description" placeholder="What is happening, and anything the PMO should know."><?php echo rq_e($fv['description']); ?></textarea>
        </fieldset>

        <fieldset>
          <legend>Materials needed</legend>
          <div id="mats">
            <?php if ($materials): foreach ($materials as $m): ?>
              <div class="mrow">
                <input type="text" name="material_item[]" value="<?php echo rq_e($m['item']); ?>" placeholder="e.g. Plastic chairs">
                <input type="number" name="material_qty[]" min="1" value="<?php echo $m['qty'] !== null ? (int)$m['qty'] : ''; ?>" placeholder="Qty">
                <button type="button" class="rm" aria-label="Remove this material"><i class="fas fa-xmark"></i></button>
              </div>
            <?php endforeach; else: ?>
              <div class="mrow">
                <input type="text" name="material_item[]" placeholder="e.g. Plastic chairs">
                <input type="number" name="material_qty[]" min="1" placeholder="Qty">
                <button type="button" class="rm" aria-label="Remove this material"><i class="fas fa-xmark"></i></button>
              </div>
            <?php endif; ?>
          </div>
          <button type="button" class="addmat" id="addMat"><i class="fas fa-plus"></i> Add another material</button>
          <p class="note">Tables, chairs, sound system, projector — whatever the PMO needs to prepare.
             Leave empty if you need nothing.</p>
        </fieldset>

        <button class="btn m" type="submit"><i class="fas fa-paper-plane"></i> Submit reservation request</button>
        <p class="note">Submitting holds the venue for your window while the PMO reviews it. Approval, assessment
           and payment are recorded by the office on their copy.</p>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/csrf_inject.php'; ?>
<script src="assets/date_picker.js"></script>
<script>
/* Nature: highlight the chosen box and reveal "Please specify" for Others. */
document.querySelectorAll('.nat input').forEach(function (r) {
  r.addEventListener('change', function () {
    document.querySelectorAll('.nat').forEach(function (l) { l.classList.remove('on'); });
    r.closest('.nat').classList.add('on');
    document.getElementById('otherWrap').style.display = (r.value === 'others') ? '' : 'none';
  });
});

/* Materials: repeatable rows. The last row is never removed, so the list cannot
   end up with nowhere to type. */
var mats = document.getElementById('mats');
document.getElementById('addMat').addEventListener('click', function () {
  var row = mats.firstElementChild.cloneNode(true);
  row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
  mats.appendChild(row);
  row.querySelector('input').focus();
});
mats.addEventListener('click', function (ev) {
  var b = ev.target.closest('.rm'); if (!b) { return; }
  if (mats.children.length > 1) { b.closest('.mrow').remove(); }
  else { b.closest('.mrow').querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
});

/* End must follow start — caught here so the trip to the server is not wasted. */
document.getElementById('vrfForm').addEventListener('submit', function (ev) {
  var s = document.getElementById('st').value, e = document.getElementById('et').value;
  if (s && e && e <= s) {
    ev.preventDefault();
    document.getElementById('et').focus();
    alert('The end time has to be after the start time.');
  }
});
</script>
</body>
</html>
