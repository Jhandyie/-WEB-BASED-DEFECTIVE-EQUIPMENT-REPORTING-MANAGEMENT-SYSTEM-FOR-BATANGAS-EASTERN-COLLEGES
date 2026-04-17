<?php
require_once __DIR__ . '/config/database.php';

$query = trim($_GET['ticket'] ?? ($_GET['q'] ?? ''));
$report = null;
$relatedReports = [];
$trackSuggestions = [];
$error = '';
$conn = getDBConnection();

$suggestionResult = $conn->query("
    SELECT
        dr.report_id,
        dr.status,
        dr.report_date,
        e.equipment_id,
        COALESCE(e.asset_tag, '') AS asset_tag,
        COALESCE(e.equipment_name, '') AS equipment_name,
        COALESCE(e.location, '') AS location
    FROM defect_reports dr
    JOIN equipment e ON dr.equipment_id = e.equipment_id
    ORDER BY dr.report_date DESC
    LIMIT 120
");

if ($suggestionResult) {
    while ($row = $suggestionResult->fetch_assoc()) {
        $trackSuggestions[] = [
            'report_id' => (string)($row['report_id'] ?? ''),
            'equipment_id' => (string)($row['equipment_id'] ?? ''),
            'asset_tag' => (string)($row['asset_tag'] ?? ''),
            'equipment_name' => (string)($row['equipment_name'] ?? ''),
            'location' => (string)($row['location'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'report_date' => (string)($row['report_date'] ?? ''),
        ];
    }
}

if ($query !== '') {
    $stmt = $conn->prepare("
        SELECT
            dr.report_id,
            dr.priority,
            dr.status,
            dr.issue_description,
            dr.report_date,
            dr.assigned_date,
            dr.completion_date,
            dr.technician_notes,
            dr.verification_notes,
            e.equipment_name,
            e.equipment_id,
            e.asset_tag,
            COALESCE(e.status, '') AS equipment_status,
            COALESCE(e.condition_status, '') AS equipment_condition,
            COALESCE(c.category_name, CAST(e.category_id AS CHAR), 'Uncategorized') AS category_name,
            COALESCE(e.location, '') AS location
        FROM defect_reports dr
        JOIN equipment e ON dr.equipment_id = e.equipment_id
        LEFT JOIN categories c ON e.category_id = c.category_id
        WHERE dr.report_id = ?
           OR e.equipment_id = ?
           OR e.asset_tag = ?
        ORDER BY dr.report_date DESC
        LIMIT 1
    ");
    $stmt->bind_param('sss', $query, $query, $query);
    $stmt->execute();
    $report = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$report) {
        $error = 'No report matched that ticket number or equipment reference.';
    } else {
        $historyStmt = $conn->prepare("
            SELECT
                dr.report_id,
                dr.status,
                dr.priority,
                dr.report_date,
                dr.assigned_date,
                dr.completion_date
            FROM defect_reports dr
            WHERE dr.equipment_id = ?
            ORDER BY dr.report_date DESC
            LIMIT 6
        ");
        $historyStmt->bind_param('s', $report['equipment_id']);
        $historyStmt->execute();
        $relatedReports = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $historyStmt->close();
    }
}

function tr_status_label(string $status): string {
    return defectStatusLabel($status);
}

function tr_status_class(string $status): string {
    return match (defectStatusCategory($status)) {
        'pending' => 'st-open',
        'in_progress' => 'st-prog',
        'completed' => 'st-done',
        'rejected' => 'st-open',
        default => '',
    };
}

function tr_priority_class(string $priority): string {
    return match (strtolower((string)$priority)) {
        'low' => 'sev-low',
        'medium' => 'sev-med',
        'high' => 'sev-high',
        'critical' => 'sev-crit',
        default => '',
    };
}

function tr_equipment_status_label(string $status): string {
    return match (strtolower($status)) {
        'available', 'operational' => 'Operational',
        'maintenance', 'under_maintenance' => 'Under Maintenance',
        'reserved', 'in_use', 'in use', 'borrowed' => 'In Use',
        'defective', 'faulty', 'damaged' => 'Needs Attention',
        default => $status !== '' ? ucwords(str_replace('_', ' ', $status)) : 'Unknown',
    };
}

function tr_equipment_status_class(string $status): string {
    return match (strtolower($status)) {
        'available', 'operational' => 'eq-ok',
        'maintenance', 'under_maintenance' => 'eq-maint',
        'reserved', 'in_use', 'in use', 'borrowed' => 'eq-use',
        'defective', 'faulty', 'damaged' => 'eq-bad',
        default => 'eq-unk',
    };
}

function tr_when(?string $datetime): string {
    if (!$datetime) {
        return '—';
    }
    return date('M j, Y g:i A', strtotime($datetime));
}

function tr_timeline(array $report): array {
    $items = [];
    foreach (defectTimelineSteps($report) as $step) {
        $date = $report['report_date'] ?? null;
        if (str_contains(strtolower($step['label']), 'assignment')) {
            $date = $report['assigned_date'] ?? $date;
        } elseif (str_contains(strtolower($step['label']), 'repair')
            || str_contains(strtolower($step['label']), 'verification')
            || str_contains(strtolower($step['label']), 'replacement')) {
            $date = $report['completion_date'] ?? $report['assigned_date'] ?? $date;
        }

        $items[] = [
            'label' => $step['label'],
            'date' => $date,
            'state' => $step['active'] ? 'active' : ($step['done'] ? 'done' : 'pending'),
        ];
    }

    return $items;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Track Report - BEC Equipment</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --m:#7B1D1D;--md:#4A0E0E;--g:#C9960C;--k:#1C1008;--k2:#5C3838;--k3:#9E8070;--p:#F8F3EA;--s:#FFFFFF;--b:#E8DDD0;
}
*{box-sizing:border-box}
body{margin:0;font-family:'DM Sans',sans-serif;background:var(--p);color:var(--k);padding:1.25rem}
.page{max-width:760px;margin:0 auto}
.top{display:flex;justify-content:space-between;gap:1rem;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:.7rem}
.seal{width:38px;height:38px;border-radius:50%;background:#fff;border:1px solid rgba(123,29,29,.14);display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 3px rgba(123,29,29,.15);overflow:hidden}
.seal img{width:100%;height:100%;object-fit:cover;display:block}
.brand strong{display:block;font-size:.84rem}.brand span{font-size:.68rem;color:var(--k3);text-transform:uppercase;letter-spacing:1.3px}
.link{color:var(--m);text-decoration:none;font-weight:600}
.card{background:var(--s);border:1px solid var(--b);border-radius:18px;padding:1.2rem;box-shadow:0 2px 12px rgba(44,10,10,.06)}
h1{font-family:'Fraunces',serif;font-size:1.8rem;margin:.2rem 0 .35rem}
.sub{color:var(--k3);line-height:1.6;margin-bottom:1rem}
form{display:flex;gap:.7rem;flex-wrap:wrap}
.search-wrap{position:relative;flex:1 1 260px}
.input{flex:1 1 260px;padding:.85rem 1rem;border:1.5px solid var(--b);border-radius:12px;font:inherit}
.btn{padding:.85rem 1.2rem;border:none;border-radius:12px;background:var(--md);color:#fff;font:inherit;font-weight:600;cursor:pointer}
.search-dd{position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:40;background:var(--s);border:1.5px solid var(--m);border-radius:12px;box-shadow:0 10px 30px rgba(44,10,10,.15);max-height:280px;overflow-y:auto;display:none}
.search-dd.open{display:block}
.search-item{display:flex;gap:.7rem;align-items:flex-start;padding:.7rem .85rem;cursor:pointer;border-top:1px solid rgba(232,221,208,.7)}
.search-item:first-child{border-top:none}
.search-item:hover,.search-item.focused{background:#fcf6ed}
.search-icon{width:28px;height:28px;border-radius:50%;background:rgba(123,29,29,.08);color:var(--m);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.8rem}
.search-copy{display:grid;gap:.15rem;min-width:0}
.search-title{font-weight:700;overflow-wrap:anywhere}
.search-meta{font-size:.75rem;color:var(--k3);line-height:1.45;overflow-wrap:anywhere}
.search-empty{padding:.85rem 1rem;font-size:.84rem;color:var(--k3);text-align:center}
.alert{margin-top:1rem;padding:.85rem 1rem;border-radius:12px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b}
.result{margin-top:1.15rem}
.ticket{font-family:'Fraunces',serif;font-size:1.4rem;color:var(--m);margin-bottom:.8rem}
.badges{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem}
.badge{display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .65rem;border-radius:999px;font-size:.76rem;font-weight:700}
.sev-low{background:#F0FDF4;color:#166534}.sev-med{background:#FFFBEB;color:#92400E}.sev-high{background:#FFF7ED;color:#C2410C}.sev-crit{background:#FEF2F2;color:#991B1B}
.st-open{background:#FEF2F2;color:#991B1B}.st-prog{background:#FFF7ED;color:#C2410C}.st-done{background:#F0FDF4;color:#166534}
.eq-ok{background:#F0FDF4;color:#166534}.eq-maint{background:#FFF7ED;color:#C2410C}.eq-use{background:#EBF5FF;color:#1D4ED8}.eq-bad{background:#FEF2F2;color:#991B1B}.eq-unk{background:#F3F4F6;color:#4B5563}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.item{background:#fffaf4;border:1px solid var(--b);border-radius:12px;padding:.85rem}
.label{font-size:.72rem;text-transform:uppercase;letter-spacing:.8px;color:var(--k3);margin-bottom:.25rem}
.value{font-weight:600;overflow-wrap:anywhere}
.full{grid-column:1/-1}
.hint{font-size:.74rem;color:var(--k3);margin-top:.55rem;line-height:1.5}
.timeline{margin-top:1rem;display:grid;gap:.75rem}
.tl-item{display:flex;gap:.75rem;align-items:flex-start}
.tl-dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;flex-shrink:0}
.tl-item.done .tl-dot{background:#F0FDF4;color:#166534}
.tl-item.active .tl-dot{background:#FFF7ED;color:#C2410C}
.tl-item.pending .tl-dot{background:#F3F4F6;color:#6B7280}
.tl-title{font-weight:700;margin-bottom:.12rem}
.tl-date{font-size:.74rem;color:var(--k3)}
.mini-table{margin-top:1rem;border-top:1px solid var(--b);padding-top:1rem}
.mini-row{display:grid;grid-template-columns:120px 1fr 110px;gap:.6rem;padding:.55rem 0;border-bottom:1px solid #f1e6d8}
.mini-row:last-child{border-bottom:none}
.mini-id{font-weight:700;color:var(--m);overflow-wrap:anywhere}
.mini-date{font-size:.74rem;color:var(--k3);text-align:right}
@media (max-width:600px){.grid{grid-template-columns:1fr}h1{font-size:1.45rem}body{padding:.9rem}.mini-row{grid-template-columns:1fr}.mini-date{text-align:left}}
</style>
</head>
<body>
  <div class="page">
    <div class="top">
      <div class="brand">
        <div class="seal"><img src="assets/logs.png" alt="BEC logo"></div>
        <div><strong>BEC Equipment Reporting</strong><span>Track Ticket</span></div>
      </div>
      <a class="link" href="student_index.php">New Report</a>
    </div>

    <div class="card">
      <h1>Track your ticket</h1>
      <div class="sub">Enter the report ticket, equipment ID, or asset tag to check both the report progress and the current equipment status.</div>
      <form method="GET" action="">
        <div class="search-wrap">
          <input class="input" id="track-search" type="text" name="q" placeholder="e.g. BEC-A1B2C3D4, EQ-001, or COMP-001" value="<?php echo htmlspecialchars($query); ?>" autocomplete="off" required>
          <div class="search-dd" id="track-dropdown"></div>
        </div>
        <button class="btn" type="submit">Track Report</button>
      </form>
      <div class="hint">This tracker supports report tickets, equipment IDs, and asset tags. Start typing to see possible matches from recent reports.</div>

      <?php if ($error): ?>
      <div class="alert"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <?php if ($report): ?>
      <div class="result">
        <div class="ticket"><?php echo htmlspecialchars($report['report_id']); ?></div>
        <div class="badges">
          <span class="badge <?php echo htmlspecialchars(tr_priority_class((string)$report['priority'])); ?>"><?php echo htmlspecialchars(ucfirst((string)$report['priority'])); ?></span>
          <span class="badge <?php echo htmlspecialchars(tr_status_class((string)$report['status'])); ?>"><?php echo htmlspecialchars(tr_status_label((string)$report['status'])); ?></span>
          <span class="badge <?php echo htmlspecialchars(tr_equipment_status_class((string)$report['equipment_status'])); ?>"><?php echo htmlspecialchars(tr_equipment_status_label((string)$report['equipment_status'])); ?></span>
        </div>
        <div class="grid">
          <div class="item">
            <div class="label">Equipment</div>
            <div class="value"><?php echo htmlspecialchars((string)$report['equipment_name']); ?></div>
          </div>
          <div class="item">
            <div class="label">Category</div>
            <div class="value"><?php echo htmlspecialchars((string)$report['category_name']); ?></div>
          </div>
          <div class="item">
            <div class="label">Equipment Reference</div>
            <div class="value"><?php echo htmlspecialchars((string)$report['equipment_id']); ?><?php echo !empty($report['asset_tag']) ? ' / ' . htmlspecialchars((string)$report['asset_tag']) : ''; ?></div>
          </div>
          <div class="item">
            <div class="label">Location</div>
            <div class="value"><?php echo htmlspecialchars((string)($report['location'] ?: 'Unspecified')); ?></div>
          </div>
          <div class="item">
            <div class="label">Equipment Status</div>
            <div class="value"><?php echo htmlspecialchars(tr_equipment_status_label((string)$report['equipment_status'])); ?></div>
          </div>
          <div class="item">
            <div class="label">Condition</div>
            <div class="value"><?php echo htmlspecialchars($report['equipment_condition'] !== '' ? ucwords(str_replace('_', ' ', (string)$report['equipment_condition'])) : 'Unknown'); ?></div>
          </div>
          <div class="item full">
            <div class="label">Description</div>
            <div class="value"><?php echo nl2br(htmlspecialchars((string)$report['issue_description'])); ?></div>
          </div>
          <div class="item full">
            <div class="label">Submitted</div>
            <div class="value"><?php echo htmlspecialchars(tr_when($report['report_date'] ?? null)); ?></div>
          </div>
          <?php if (!empty($report['technician_notes'])): ?>
          <div class="item full">
            <div class="label">Technician Notes</div>
            <div class="value"><?php echo nl2br(htmlspecialchars((string)$report['technician_notes'])); ?></div>
          </div>
          <?php endif; ?>
          <?php if (!empty($report['verification_notes'])): ?>
          <div class="item full">
            <div class="label">Verification Notes</div>
            <div class="value"><?php echo nl2br(htmlspecialchars((string)$report['verification_notes'])); ?></div>
          </div>
          <?php endif; ?>
          <div class="item full">
            <div class="label">Timeline</div>
            <div class="timeline">
              <?php foreach (tr_timeline($report) as $step): ?>
              <div class="tl-item <?php echo htmlspecialchars($step['state']); ?>">
                <div class="tl-dot"><?php echo $step['state'] === 'done' ? '<i class="fas fa-check"></i>' : ($step['state'] === 'active' ? '<i class="fas fa-tools"></i>' : '<i class="fas fa-clock"></i>'); ?></div>
                <div>
                  <div class="tl-title"><?php echo htmlspecialchars($step['label']); ?></div>
                  <div class="tl-date"><?php echo htmlspecialchars(tr_when($step['date'] ?? null)); ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php if (!empty($relatedReports)): ?>
          <div class="item full">
            <div class="label">Recent Logs For This Equipment</div>
            <div class="mini-table">
              <?php foreach ($relatedReports as $entry): ?>
              <div class="mini-row">
                <div class="mini-id"><?php echo htmlspecialchars((string)$entry['report_id']); ?></div>
                <div><?php echo htmlspecialchars(tr_status_label((string)$entry['status'])); ?><?php echo !empty($entry['priority']) ? ' · ' . htmlspecialchars(ucfirst((string)$entry['priority'])) : ''; ?></div>
                <div class="mini-date"><?php echo htmlspecialchars(tr_when($entry['report_date'] ?? null)); ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
<script>
const trackInput = document.getElementById('track-search');
const trackDropdown = document.getElementById('track-dropdown');
const trackData = <?php echo json_encode($trackSuggestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let trackFocusIdx = -1;
let trackRenderTimer = null;

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  })[char]);
}

function badgeLabel(status) {
  const normalized = String(status || '').toLowerCase();
  if (['reported','pmo_review','dean_review','finance_review','on_hold_budget','ready_for_assignment'].includes(normalized)) return 'Pending';
  if (['assigned','in_progress','for_replacement'].includes(normalized)) return 'In Progress';
  if (['completed','verified','closed'].includes(normalized)) return 'Resolved';
  if (normalized === 'rejected') return 'Rejected';
  return normalized ? normalized.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : 'Report';
}

function renderTrackDropdown(query) {
  const q = String(query || '').trim().toLowerCase();
  const matches = q
    ? trackData.filter(item =>
        (item.report_id || '').toLowerCase().includes(q) ||
        (item.equipment_id || '').toLowerCase().includes(q) ||
        (item.asset_tag || '').toLowerCase().includes(q) ||
        (item.equipment_name || '').toLowerCase().includes(q) ||
        (item.location || '').toLowerCase().includes(q)
      )
    : trackData.slice(0, 8);

  if (!matches.length) {
    trackDropdown.innerHTML = '<div class="search-empty">No matching tickets or equipment references found yet.</div>';
    trackDropdown.classList.add('open');
    trackFocusIdx = -1;
    return;
  }

  trackDropdown.innerHTML = matches.slice(0, 8).map(item => {
    const title = item.report_id || item.equipment_id || item.asset_tag || 'Reference';
    const refs = [item.equipment_id, item.asset_tag].filter(Boolean).join(' · ');
    const detailParts = [item.equipment_name, refs, item.location].filter(Boolean);
    return `
      <div class="search-item" data-value="${escapeHtml(item.report_id || item.equipment_id || item.asset_tag || '')}">
        <span class="search-icon"><i class="fas fa-search"></i></span>
        <span class="search-copy">
          <span class="search-title">${escapeHtml(title)}</span>
          <span class="search-meta">${escapeHtml(detailParts.join(' • '))}</span>
          <span class="search-meta">${escapeHtml(badgeLabel(item.status || ''))}</span>
        </span>
      </div>
    `;
  }).join('');

  trackDropdown.classList.add('open');
  trackFocusIdx = -1;

  trackDropdown.querySelectorAll('.search-item').forEach(el => {
    el.addEventListener('mousedown', event => {
      event.preventDefault();
      trackInput.value = el.dataset.value || '';
      trackDropdown.classList.remove('open');
      trackInput.form?.requestSubmit();
    });
  });
}

trackInput.addEventListener('input', () => {
  clearTimeout(trackRenderTimer);
  trackRenderTimer = setTimeout(() => {
    renderTrackDropdown(trackInput.value);
  }, 120);
});

trackInput.addEventListener('focus', () => {
  renderTrackDropdown(trackInput.value);
});

trackInput.addEventListener('blur', () => {
  setTimeout(() => trackDropdown.classList.remove('open'), 150);
});

trackInput.addEventListener('keydown', event => {
  const items = trackDropdown.querySelectorAll('.search-item');
  if (!items.length) {
    if (event.key === 'Escape') {
      trackDropdown.classList.remove('open');
    }
    return;
  }

  if (event.key === 'ArrowDown') {
    event.preventDefault();
    trackFocusIdx = Math.min(trackFocusIdx + 1, items.length - 1);
  } else if (event.key === 'ArrowUp') {
    event.preventDefault();
    trackFocusIdx = Math.max(trackFocusIdx - 1, 0);
  } else if (event.key === 'Enter' && trackFocusIdx >= 0) {
    event.preventDefault();
    items[trackFocusIdx].dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
    return;
  } else if (event.key === 'Escape') {
    trackDropdown.classList.remove('open');
    return;
  } else {
    return;
  }

  items.forEach((el, index) => el.classList.toggle('focused', index === trackFocusIdx));
  items[trackFocusIdx]?.scrollIntoView({ block: 'nearest' });
});
</script>
</body>
</html>
