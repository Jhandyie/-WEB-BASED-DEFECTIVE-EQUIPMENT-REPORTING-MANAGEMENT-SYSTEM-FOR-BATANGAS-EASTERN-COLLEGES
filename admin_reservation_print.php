<?php
/**
 * admin_reservation_print.php — the file copy of a Venue Reservation Form.
 *
 * Reproduces the PMO's paper VRF with the record's values filled in, so an
 * approved reservation can be printed, signed and kept in the folder the office
 * already keeps. Print-to-PDF is the same action (the browser's own dialog),
 * which is why there is no PDF library here — the same approach the rest of the
 * admin exports take.
 *
 * Named admin_* deliberately: includes/session_bootstrap.php derives the session
 * cookie from the filename, so any other name would silently read an empty
 * session and bounce a signed-in admin to the login page.
 *
 * The layout follows the paper form top to bottom: reference numbers, applicant,
 * venue, nature of activity, date and time, participants, description,
 * materials, then the signature and accounting blocks. The blank boxes are kept
 * even when the system has no value for them — a form is signed on paper, and
 * the empty line is where that happens.
 */
require_once __DIR__ . '/config/features.php';
if (!becVenueEnabled()) { header('Location: admin_dashboard.php'); exit; }
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/reservation_helper.php';
require_once __DIR__ . '/includes/export_branding.php';
requireRole('admin');

$id = (int)($_GET['id'] ?? 0);
$pdo = getPgsqlPdoConnection();
$st = $pdo->prepare("SELECT * FROM public.venue_reservations WHERE id = :id");
$st->execute(['id' => $id]);
$r = $st->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>Not found</title>'
       . '<p style="font-family:system-ui;padding:2rem">That reservation no longer exists. '
       . '<a href="admin_reservations.php">Back to the queue</a>.</p>';
    exit;
}

function pr_e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
/** A filled value, or a blank line to write on. */
function pr_val($v){ $v = trim((string)$v); return $v !== '' ? pr_e($v) : '&nbsp;'; }
function pr_date($v){ $t = strtotime((string)$v); return $t ? date('F j, Y', $t) : ''; }
function pr_money($v){ return ($v === null || $v === '') ? '' : '₱' . number_format((float)$v, 2); }

$materials = vrMaterials($r['materials']);
$logo      = becExportLogoDataUri();
$startTs   = strtotime((string)$r['starts_at']);
$endTs     = strtotime((string)$r['ends_at']);
$isApproved = ($r['status'] === 'approved');
$isRefused  = ($r['status'] === 'disapproved');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?php echo pr_e($r['vrf_no'] ?: 'Venue Reservation Form'); ?> — BEC PMO</title>
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<style>
  /* The form is a document, not a page of the app: black on white, one sheet,
     and every rule visible in print. Screen just previews the same thing. */
  :root{--ink:#111;--line:#111;--muted:#555;}
  *{box-sizing:border-box}
  body{margin:0;background:#6b6b6b;font-family:'DM Sans',Arial,Helvetica,sans-serif;color:var(--ink);font-size:11pt;}
  .toolbar{position:sticky;top:0;z-index:5;display:flex;gap:.5rem;justify-content:center;padding:.8rem;background:rgba(20,20,20,.85);}
  .toolbar button,.toolbar a{font:inherit;font-size:.85rem;font-weight:700;padding:.55rem 1.1rem;border-radius:8px;border:none;cursor:pointer;text-decoration:none;background:#fff;color:#111;}
  .toolbar .sec{background:#3a3a3a;color:#fff;}
  .sheet{background:#fff;width:8.5in;min-height:11in;margin:1rem auto 3rem;padding:.6in .7in;box-shadow:0 6px 30px rgba(0,0,0,.35);}

  .formcode{font-size:7.5pt;color:var(--muted);line-height:1.35;margin-bottom:.15in;}
  .head{display:flex;align-items:flex-start;gap:.25in;border-bottom:2px solid var(--line);padding-bottom:.12in;margin-bottom:.18in;}
  .head img{width:.75in;height:.75in;object-fit:contain;}
  .head .org{flex:1;text-align:center;}
  .head .org .school{font-size:12pt;font-weight:800;letter-spacing:.5px;}
  .head .org .office{font-size:9.5pt;color:var(--muted);}
  .head .org .title{font-size:14pt;font-weight:800;letter-spacing:1.5px;margin-top:.06in;text-decoration:underline;}
  .head .refs{width:2.1in;font-size:9pt;}
  .head .refs div{margin-bottom:.06in;}
  .head .refs .lbl{font-weight:700;}

  /* A labelled value sitting on a ruled line, like the printed form. */
  .fld{display:flex;align-items:flex-end;gap:.08in;margin-bottom:.13in;}
  .fld .lbl{font-size:9pt;font-weight:700;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;}
  .fld .line{flex:1;border-bottom:1px solid var(--line);padding:0 .05in 1px;min-height:.22in;font-size:11pt;}
  .cols{display:flex;gap:.3in;}
  .cols > *{flex:1;min-width:0;}

  .boxes{display:flex;flex-wrap:wrap;gap:.06in .3in;margin:.06in 0 .16in;}
  .box{display:flex;align-items:center;gap:.07in;font-size:10pt;}
  .tick{width:.14in;height:.14in;border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;font-size:9pt;font-weight:900;line-height:1;}
  .desc-lines{border:1px solid var(--line);min-height:.9in;padding:.07in .09in;font-size:10.5pt;line-height:1.5;white-space:pre-wrap;}

  table.mat{width:100%;border-collapse:collapse;font-size:10pt;margin-top:.06in;}
  table.mat th,table.mat td{border:1px solid var(--line);padding:.05in .08in;text-align:left;}
  table.mat th{font-size:8.5pt;text-transform:uppercase;letter-spacing:.4px;background:#f0f0f0;}
  table.mat td.q{width:1in;text-align:center;}

  .sec-t{font-size:8.5pt;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--muted);border-bottom:1px solid #bbb;padding-bottom:.03in;margin:.2in 0 .1in;}
  .sigs{display:flex;gap:.4in;margin-top:.28in;}
  .sig{flex:1;text-align:center;}
  .sig .on{border-bottom:1px solid var(--line);min-height:.34in;font-size:10.5pt;display:flex;align-items:flex-end;justify-content:center;padding-bottom:2px;}
  .sig .cap{font-size:8pt;font-style:italic;color:var(--muted);margin-top:.03in;}
  .sig .role{font-size:9pt;font-weight:700;text-transform:uppercase;letter-spacing:.3px;}
  .dec{display:flex;gap:.4in;margin-top:.22in;}
  .dec > div{flex:1;}
  .pay{display:flex;gap:.3in;margin-top:.1in;}
  /* The stamp sits with the reference numbers rather than floating into the
     body: floated, it cut the applicant's signing line short, and that line is
     the one someone actually signs on. */
  .stamp{display:inline-block;border:3px solid #1a7a33;color:#1a7a33;font-weight:900;font-size:11pt;letter-spacing:2px;padding:.04in .14in;transform:rotate(-5deg);margin-top:.06in;}
  .stamp.no{border-color:#b42318;color:#b42318;}
  .remarks{font-size:9.5pt;margin-top:.1in;line-height:1.5;}
  .foot{margin-top:.3in;border-top:1px solid #bbb;padding-top:.06in;font-size:7.5pt;color:var(--muted);display:flex;justify-content:space-between;}

  @media print {
    body{background:#fff;}
    .toolbar{display:none;}
    .sheet{width:auto;min-height:0;margin:0;padding:0;box-shadow:none;}
    @page{size:letter portrait;margin:.5in;}
  }
</style>
</head>
<body>
<div class="toolbar">
  <button onclick="window.print()">🖨 Print / Save as PDF</button>
  <a class="sec" href="admin_reservations.php">Back to the queue</a>
</div>

<div class="sheet">
  <?php /* The printed pad carries a form control number in this corner. It is
           not recorded anywhere in this system, so the slot is left for the
           office to fill rather than invented here. */ ?>
  <div class="formcode">Form No. ______________ &nbsp;·&nbsp; Rev. No. ______ &nbsp;·&nbsp; Page 1 of 1</div>

  <div class="head">
    <?php if ($logo): ?><img src="<?php echo $logo; ?>" alt="BEC seal"><?php endif; ?>
    <div class="org">
      <div class="school">BATANGAS EASTERN COLLEGES</div>
      <div class="office">Property Management Office</div>
      <div class="title">VENUE RESERVATION FORM</div>
    </div>
    <div class="refs">
      <div><span class="lbl">VRF NO.:</span> <?php echo pr_e($r['vrf_no'] ?: '________'); ?></div>
      <div><span class="lbl">DATE:</span> <?php echo pr_e(pr_date($r['created_at'])); ?></div>
      <div><span class="lbl">CF #:</span> <?php echo pr_e($r['cf_no'] ?: '________'); ?></div>
      <?php if ($isApproved): ?><div class="stamp">APPROVED</div>
      <?php elseif ($isRefused): ?><div class="stamp no">DISAPPROVED</div><?php endif; ?>
    </div>
  </div>

  <div class="fld"><span class="lbl">Name of Applicant:</span><span class="line"><?php echo pr_val($r['applicant_name']); ?></span></div>
  <div class="fld"><span class="lbl">Department / Organization:</span><span class="line"><?php echo pr_val($r['department_org']); ?></span></div>
  <div class="fld"><span class="lbl">Venue:</span><span class="line"><?php echo pr_val($r['venue']); ?></span></div>

  <div class="cols">
    <div>
      <div class="fld" style="margin-bottom:.04in"><span class="lbl">Nature of Activity:</span></div>
      <div class="boxes">
        <?php foreach (vrNatures() as $nv => $nl): $on = ((string)$r['nature'] === $nv); ?>
          <span class="box"><span class="tick"><?php echo $on ? '&#10003;' : '&nbsp;'; ?></span><?php echo pr_e($nl); ?></span>
        <?php endforeach; ?>
      </div>
      <div class="fld"><span class="lbl">Pls. specify:</span><span class="line"><?php echo pr_val($r['nature_other']); ?></span></div>
    </div>
    <div>
      <div class="fld" style="margin-bottom:.04in"><span class="lbl">Date and Time:</span></div>
      <div class="fld"><span class="lbl">Start:</span>
        <span class="line"><?php echo $startTs ? pr_e(date('F j, Y', $startTs)) : '&nbsp;'; ?></span>
        <span class="line" style="max-width:1.1in"><?php echo $startTs ? pr_e(date('g:i A', $startTs)) : '&nbsp;'; ?></span>
      </div>
      <div class="fld"><span class="lbl">End:&nbsp;&nbsp;</span>
        <span class="line"><?php echo $endTs ? pr_e(date('F j, Y', $endTs)) : '&nbsp;'; ?></span>
        <span class="line" style="max-width:1.1in"><?php echo $endTs ? pr_e(date('g:i A', $endTs)) : '&nbsp;'; ?></span>
      </div>
    </div>
  </div>

  <div class="fld"><span class="lbl">Expected Number of Participants:</span>
    <span class="line"><?php echo $r['participants'] !== null ? (int)$r['participants'] : '&nbsp;'; ?></span></div>

  <div class="fld" style="margin-bottom:.05in"><span class="lbl">Description of Activity:</span></div>
  <div class="desc-lines"><?php echo pr_e($r['description']); ?></div>

  <div class="sec-t">Materials Requested</div>
  <?php if ($materials): ?>
    <table class="mat">
      <thead><tr><th>Item</th><th class="q">Quantity</th></tr></thead>
      <tbody>
        <?php foreach ($materials as $m): ?>
          <tr><td><?php echo pr_e($m['item'] ?? ''); ?></td>
              <td class="q"><?php echo !empty($m['qty']) ? (int)$m['qty'] : '—'; ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <table class="mat"><tbody>
      <?php for ($i = 0; $i < 3; $i++): ?><tr><td>&nbsp;</td><td class="q">&nbsp;</td></tr><?php endfor; ?>
    </tbody></table>
  <?php endif; ?>

  <div class="sigs">
    <div class="sig">
      <div class="on"><?php echo pr_val($r['applicant_name']); ?></div>
      <div class="cap">(Signature over printed name)</div>
      <div class="role">Applicant</div>
    </div>
    <div class="sig">
      <div class="on"><?php echo pr_val($r['adviser_name']); ?></div>
      <div class="cap">(Signature over printed name)</div>
      <div class="role">Dept. Head / Org. Adviser</div>
    </div>
  </div>

  <div class="dec">
    <div>
      <div class="sec-t">Approved</div>
      <div class="sig">
        <div class="on"><?php echo pr_val($r['approved_by']); ?></div>
        <div class="role">Property Management Officer</div>
        <div class="cap">Date: <?php echo pr_e(pr_date($r['approved_at'])) ?: '______________'; ?></div>
      </div>
    </div>
    <div>
      <div class="sec-t">Disapproved</div>
      <div class="sig">
        <div class="on"><?php echo pr_val($r['disapproved_by']); ?></div>
        <div class="role">School Administrator</div>
        <div class="cap">Date: <?php echo pr_e(pr_date($r['disapproved_at'])) ?: '______________'; ?></div>
      </div>
    </div>
  </div>

  <?php if (trim((string)$r['decision_remarks']) !== ''): ?>
    <div class="remarks"><strong>Remarks:</strong> <?php echo pr_e($r['decision_remarks']); ?></div>
  <?php endif; ?>

  <div class="sec-t">Accounting</div>
  <div class="cols">
    <div>
      <div class="fld"><span class="lbl">Assessment:</span><span class="line"><?php echo pr_e(pr_money($r['assessment_amount'])); ?></span></div>
      <div class="fld"><span class="lbl">Accounting:</span><span class="line"><?php echo pr_val($r['assessment_by']); ?></span></div>
    </div>
    <div>
      <div class="pay">
        <span class="box"><span class="tick"><?php echo $r['payment_type'] === 'down' ? '&#10003;' : '&nbsp;'; ?></span>Down Payment</span>
        <span class="box"><span class="tick"><?php echo $r['payment_type'] === 'full' ? '&#10003;' : '&nbsp;'; ?></span>Full Payment</span>
      </div>
      <div class="fld"><span class="lbl">Amount Paid:</span><span class="line"><?php echo pr_e(pr_money($r['amount_paid'])); ?></span></div>
      <?php /* Short date here: the long form wrapped onto a second line inside
               the narrow OR row and broke the rule under it. */ ?>
      <div class="fld"><span class="lbl">OR #:</span><span class="line"><?php echo pr_val($r['or_no']); ?></span>
        <span class="lbl">Date:</span>
        <span class="line" style="max-width:1.4in;white-space:nowrap"><?php
          $orTs = strtotime((string)$r['or_date']);
          echo $orTs ? pr_e(date('M j, Y', $orTs)) : '&nbsp;';
        ?></span></div>
      <div class="fld"><span class="lbl">Cashier:</span><span class="line"><?php echo pr_val($r['cashier_name']); ?></span></div>
    </div>
  </div>

  <div class="foot">
    <span><b>Batangas Eastern Colleges</b> · Property Management Office</span>
    <span>Printed <?php echo pr_e(date('Y-m-d H:i')); ?> by <?php echo pr_e(becExportPreparedBy()); ?></span>
  </div>
</div>

<script>
// ?auto=1 opens the print dialog straight away — the queue's Print button uses
// it, so printing a filed copy is one click rather than two.
<?php if (isset($_GET['auto'])): ?>
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });
<?php endif; ?>
</script>
</body>
</html>
