<?php
/**
 * includes/export_branding.php
 * Shared professional letterhead / branding for admin export documents
 * (print-to-PDF). Gives every export a consistent, institutional, BEC PMO
 * look: embedded logo, official letterhead, summary cards, data table,
 * signatures, and a confidential footer.
 *
 * Usage:
 *   require_once __DIR__ . '/export_branding.php';
 *   echo becExportCss(); (inside <style>)
 *   echo becExportToolbar();
 *   echo becExportHeader('Defect Reports', ['Period'=>'…','Total records'=>42]);
 *   echo becExportSummaryCards(['Total'=>42, 'Completed'=>30]);
 *   ... your <table class="data-table"> ...
 *   echo becExportSignatures();
 *   echo becExportFooter();
 */

if (!function_exists('becExportLogoDataUri')) {
    /** Embed the BEC logo as a base64 data URI so it renders even when saved as PDF. */
    function becExportLogoDataUri(): string {
        static $uri = null;
        if ($uri !== null) return $uri;
        $uri = '';
        foreach (['logs.png', 'logo.png'] as $name) {
            $path = __DIR__ . '/../assets/' . $name;
            if (is_file($path)) {
                $data = @file_get_contents($path);
                if ($data !== false && $data !== '') {
                    $uri = 'data:image/png;base64,' . base64_encode($data);
                    break;
                }
            }
        }
        return $uri;
    }
}

if (!function_exists('becExportPreparedBy')) {
    /** Best-effort name of the admin generating the document, from the session. */
    function becExportPreparedBy(): string {
        foreach (['fullname', 'full_name', 'name', 'admin_name', 'user_name', 'username'] as $k) {
            if (!empty($_SESSION[$k]) && is_string($_SESSION[$k])) return $_SESSION[$k];
        }
        return 'Property Management Office';
    }
}

if (!function_exists('becExportRef')) {
    /** Stable per-document reference number. */
    function becExportRef(): string {
        static $ref = null;
        if ($ref === null) $ref = 'BEC-PMO-' . date('Ymd-His');
        return $ref;
    }
}

if (!function_exists('becExportCss')) {
    function becExportCss(): string {
        return <<<'CSS'
:root{--maroon:#7B1D1D;--maroon-d:#4A0E0E;--gold:#C9960C;--ink:#1C1008;--ink2:#5C3838;--ink3:#755B4E;--border:#E8DDD0;--paper:#F8F3EA;}
*{box-sizing:border-box;}
html,body{margin:0;}
body{font-family:'Segoe UI',Arial,Helvetica,sans-serif;color:var(--ink);padding:26px 30px 40px;font-size:12.5px;line-height:1.5;}
/* toolbar (screen only) */
.exp-toolbar{display:flex;gap:10px;margin-bottom:18px;}
.exp-toolbar button{background:var(--maroon);color:#fff;border:0;padding:9px 18px;border-radius:7px;cursor:pointer;font-size:13px;font-weight:600;}
.exp-toolbar button.sec{background:#6b5b50;}
.exp-toolbar button:hover{background:var(--maroon-d);}
/* official letterhead */
.lh{display:flex;align-items:center;justify-content:center;gap:16px;text-align:center;}
.lh-logo{height:72px;width:72px;object-fit:contain;}
.lh-name{font-family:'Times New Roman',Georgia,serif;font-size:21px;font-weight:800;color:var(--ink);letter-spacing:.3px;line-height:1.15;}
.lh-office{font-family:'Times New Roman',Georgia,serif;font-style:italic;font-size:13px;color:var(--ink);margin-top:1px;}
.lh-title{font-size:12px;font-weight:700;color:var(--ink);text-transform:uppercase;letter-spacing:.5px;margin-top:3px;}
.lh-accent{height:3px;border-radius:4px;margin:8px 0 0;background:linear-gradient(90deg,var(--maroon-d),var(--maroon) 55%,var(--gold));}
.lh-datebar{display:flex;justify-content:space-between;font-size:10px;color:var(--ink3);margin-top:8px;}
/* meta grid */
.meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:2px 26px;margin:14px 0 4px;border:1px solid var(--border);border-radius:8px;padding:12px 16px;background:var(--paper);}
.meta-row{display:flex;gap:8px;font-size:11px;padding:2px 0;}
.meta-row .k{color:var(--ink3);font-weight:700;text-transform:uppercase;letter-spacing:.4px;min-width:120px;}
.meta-row .v{color:var(--ink);font-weight:600;}
/* summary cards */
.sec-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1.4px;color:var(--maroon);margin:22px 0 10px;padding-bottom:5px;border-bottom:2px solid var(--border);}
.cards{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 6px;}
.card{flex:1 1 130px;border:1px solid var(--border);border-left:4px solid var(--gold);border-radius:8px;padding:10px 14px;background:#fff;}
.card .n{font-size:21px;font-weight:800;color:var(--maroon);line-height:1.1;}
.card .l{font-size:9.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--ink2);margin-top:3px;font-weight:600;}
/* table */
.data-table{width:100%;border-collapse:collapse;font-size:10.5px;margin-top:4px;}
.data-table thead th{background:var(--maroon);color:#fff;text-align:left;padding:8px 9px;font-size:10px;text-transform:uppercase;letter-spacing:.4px;border:1px solid var(--maroon-d);}
.data-table tbody td{border:1px solid var(--border);padding:6px 9px;vertical-align:top;}
.data-table tbody tr:nth-child(even){background:var(--paper);}
.data-table tr.grp td{background:#c9c2b8;color:#2D0505;font-weight:800;text-transform:uppercase;letter-spacing:.5px;font-size:10px;border:1px solid #b7aea2;}
.data-table .empty{text-align:center;padding:20px;color:var(--ink3);font-style:italic;}
.legend{margin:14px 0 0;font-size:10px;color:var(--ink2);background:#fff;border:1px dashed var(--border);border-radius:8px;padding:10px 14px;line-height:1.7;}
.legend b{color:var(--maroon);}
/* signatories (official BEC form) */
.sign-row{display:flex;gap:24px;margin:44px 0 0;}
.sig{flex:1;font-size:11px;}
.sig-label{color:var(--ink2);margin-bottom:38px;}
.sig-name{font-weight:700;color:var(--ink);border-top:1px solid var(--ink);padding-top:3px;}
.sig-title{font-weight:700;font-size:10.5px;color:var(--ink);margin-top:1px;}
/* footer */
.doc-footer{margin-top:26px;border-top:1px solid var(--border);padding-top:10px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;font-size:9.5px;color:var(--ink3);}
.doc-footer b{color:var(--ink2);}
@page{size:A4;margin:14mm 12mm;}
@media print{
  body{padding:0;}
  .exp-toolbar{display:none;}
  .data-table thead{display:table-header-group;}
  .data-table tr{page-break-inside:avoid;}
  .sign-row{page-break-inside:avoid;}
}
CSS;
    }
}

if (!function_exists('becExportToolbar')) {
    function becExportToolbar(): string {
        return '<div class="exp-toolbar">'
             . '<button onclick="window.print()">&#128424; Save as PDF / Print</button>'
             . '<button type="button" class="sec" onclick="window.close()">Close</button>'
             . '</div>';
    }
}

if (!function_exists('becExportHeader')) {
    /**
     * @param string $docTitle  e.g. "Defect Reports"
     * @param array  $metaExtra ordered label=>value pairs appended to the meta grid
     */
    function becExportHeader(string $docTitle, array $metaExtra = []): string {
        $h    = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $logo = becExportLogoDataUri();

        $meta = array_merge([
            'Reference No.' => becExportRef(),
            'Date Generated' => date('F j, Y \a\t g:i A'),
            'Prepared By' => becExportPreparedBy(),
            'Classification' => 'Official · Confidential',
        ], $metaExtra);

        $rows = '';
        foreach ($meta as $k => $v) {
            if ($v === '' || $v === null) continue;
            $rows .= '<div class="meta-row"><span class="k">' . $h($k) . '</span><span class="v">' . $h($v) . '</span></div>';
        }

        return '<div class="lh">'
             . ($logo ? '<img class="lh-logo" src="' . $logo . '" alt="BEC logo">' : '')
             . '<div class="lh-org">'
             . '<div class="lh-name">BATANGAS EASTERN COLLEGES</div>'
             . '<div class="lh-office">Property Management Office</div>'
             . '<div class="lh-title">' . $h($docTitle) . '</div>'
             . '</div></div>'
             . '<div class="lh-accent"></div>'
             . '<div class="lh-datebar"><span>Date: ' . $h(date('F j, Y')) . '</span><span>Ref. ' . $h(becExportRef()) . '</span></div>'
             . '<div class="meta-grid">' . $rows . '</div>';
    }
}

if (!function_exists('becExportSummaryCards')) {
    /** @param array $cards label=>value */
    function becExportSummaryCards(array $cards, string $label = 'Executive Summary'): string {
        if (!$cards) return '';
        $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $out = '<div class="sec-label">' . $h($label) . '</div><div class="cards">';
        foreach ($cards as $l => $n) {
            $out .= '<div class="card"><div class="n">' . $h($n) . '</div><div class="l">' . $h($l) . '</div></div>';
        }
        return $out . '</div>';
    }
}

if (!function_exists('becExportSignatures')) {
    function becExportSignatures(): string {
        $box = static function ($label, $name, $title) {
            $n = $name !== '' ? htmlspecialchars($name) : '&nbsp;';
            return '<div class="sig"><div class="sig-label">' . htmlspecialchars($label) . '</div>'
                 . '<div class="sig-name">' . $n . '</div>'
                 . '<div class="sig-title">' . htmlspecialchars($title) . '</div></div>';
        };
        return '<div class="sign-row">'
             . $box('Prepared by:', 'Adrian Ilao', 'Asst. PMO')
             . $box('Noted by:', 'Monaliza Mancilla', 'PMO')
             . $box('Approved by:', 'Edison Dumo', 'School Administrator')
             . $box('Received by:', '', 'Accounting Department')
             . '</div>';
    }
}

if (!function_exists('becExportFooter')) {
    function becExportFooter(): string {
        return '<div class="doc-footer">'
             . '<span><b>Batangas Eastern Colleges</b> &middot; Property Management Office</span>'
             . '<span>Confidential — for authorized administrative use only</span>'
             . '<span>Generated ' . htmlspecialchars(date('Y-m-d H:i')) . '</span>'
             . '</div>';
    }
}
