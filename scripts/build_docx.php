<?php
/**
 * build_docx.php — Markdown → Microsoft Word (.docx) converter.
 *
 * Produces a genuine OOXML WordprocessingML package (not HTML renamed .doc), so the
 * result opens natively in Word/LibreOffice/Google Docs with real heading styles,
 * real tables, and working hyperlinks.
 *
 * Usage:  php scripts/build_docx.php <input.md> [output.docx]
 *
 * Supports the subset of Markdown used by the BEC docs: ATX headings, GFM pipe
 * tables (with column alignment), bold/italic/code spans, links, bullet and
 * numbered lists, horizontal rules, and $$...$$ math blocks (rendered as plain
 * text formulas — Word has no LaTeX engine).
 */

declare(strict_types=1);

// ── BEC brand ────────────────────────────────────────────────────────────────
const MAROON   = '7B1D1D';
const GOLD     = 'C9960C';
const INK      = '1A1A1A';
const MUTED    = '5A5A5A';
const RULE     = 'D8D2C6';
const BAND     = 'F4F1EA';

// ── Entry point ──────────────────────────────────────────────────────────────
$in  = $argv[1] ?? null;
$out = $argv[2] ?? null;
if (!$in || !is_file($in)) {
    fwrite(STDERR, "Usage: php scripts/build_docx.php <input.md> [output.docx]\n");
    exit(1);
}
if (!$out) $out = preg_replace('/\.md$/i', '', $in) . '.docx';

$md = file_get_contents($in);
$md = str_replace(["\r\n", "\r"], "\n", $md);

$body = renderBlocks(parseBlocks($md));
try {
    writeDocx($out, $body['xml'], $body['rels'], basename($in));
} catch (RuntimeException $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
clearstatcache(true, $out);
printf("Wrote %s (%s KB)\n", $out, number_format(filesize($out) / 1024, 1));

// ═════════════════════════════════════════════════════════════════════════════
// BLOCK PARSER
// ═════════════════════════════════════════════════════════════════════════════

function parseBlocks(string $md): array
{
    $lines  = explode("\n", $md);
    $blocks = [];
    $n      = count($lines);
    $i      = 0;
    $para   = [];

    $flush = function () use (&$para, &$blocks) {
        if ($para) {
            $blocks[] = ['type' => 'p', 'text' => implode(' ', $para)];
            $para = [];
        }
    };

    while ($i < $n) {
        $line = $lines[$i];
        $t    = trim($line);

        // Blank line
        if ($t === '') { $flush(); $i++; continue; }

        // Fenced code — passed through verbatim
        if (preg_match('/^```/', $t)) {
            $flush();
            $code = [];
            $i++;
            while ($i < $n && !preg_match('/^```/', trim($lines[$i]))) { $code[] = $lines[$i]; $i++; }
            $i++;
            $blocks[] = ['type' => 'code', 'lines' => $code];
            continue;
        }

        // Horizontal rule
        if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $t)) {
            $flush();
            $blocks[] = ['type' => 'hr'];
            $i++;
            continue;
        }

        // Display math  $$ ... $$
        if (strpos($t, '$$') === 0) {
            $flush();
            $math = substr($t, 2);
            if (substr(rtrim($math), -2) === '$$') {
                $math = substr(rtrim($math), 0, -2);
            } else {
                $i++;
                while ($i < $n && strpos(trim($lines[$i]), '$$') === false) { $math .= ' ' . trim($lines[$i]); $i++; }
                $math .= ' ' . str_replace('$$', '', trim($lines[$i] ?? ''));
            }
            $blocks[] = ['type' => 'math', 'text' => trim($math)];
            $i++;
            continue;
        }

        // ATX heading
        if (preg_match('/^(#{1,6})\s+(.*)$/', $t, $m)) {
            $flush();
            $blocks[] = ['type' => 'h', 'level' => strlen($m[1]), 'text' => trim($m[2])];
            $i++;
            continue;
        }

        // GFM pipe table: header row followed by a delimiter row
        if ($t !== '' && $t[0] === '|' && isset($lines[$i + 1]) && preg_match('/^\|[\s:|-]+\|$/', trim($lines[$i + 1]))) {
            $flush();
            $header = splitRow($t);
            $align  = array_map(function ($c) {
                $c = trim($c);
                $l = strpos($c, ':') === 0;
                $r = substr($c, -1) === ':';
                return $l && $r ? 'center' : ($r ? 'right' : 'left');
            }, splitRow(trim($lines[$i + 1])));
            $i += 2;
            $rows = [];
            while ($i < $n && trim($lines[$i]) !== '' && trim($lines[$i])[0] === '|') {
                $rows[] = splitRow(trim($lines[$i]));
                $i++;
            }
            $blocks[] = ['type' => 'table', 'header' => $header, 'align' => $align, 'rows' => $rows];
            continue;
        }

        // Bullet list
        if (preg_match('/^[-*+]\s+(.*)$/', $t, $m)) {
            $flush();
            $items = [];
            while ($i < $n && preg_match('/^[-*+]\s+(.*)$/', trim($lines[$i]), $mm)) {
                $items[] = $mm[1];
                $i++;
                // fold continuation lines
                while ($i < $n && trim($lines[$i]) !== '' && !preg_match('/^([-*+]|\d+\.|#|\|)\s*/', trim($lines[$i]))) {
                    $items[count($items) - 1] .= ' ' . trim($lines[$i]);
                    $i++;
                }
            }
            $blocks[] = ['type' => 'ul', 'items' => $items];
            continue;
        }

        // Numbered list
        if (preg_match('/^(\d+)\.\s+(.*)$/', $t, $m)) {
            $flush();
            $items = [];
            while ($i < $n && preg_match('/^(\d+)\.\s+(.*)$/', trim($lines[$i]), $mm)) {
                $items[] = ['num' => $mm[1], 'text' => $mm[2]];
                $i++;
                while ($i < $n && trim($lines[$i]) !== '' && !preg_match('/^([-*+]|\d+\.|#|\|)\s*/', trim($lines[$i]))) {
                    $items[count($items) - 1]['text'] .= ' ' . trim($lines[$i]);
                    $i++;
                }
            }
            $blocks[] = ['type' => 'ol', 'items' => $items];
            continue;
        }

        // Blockquote
        if (preg_match('/^>\s?(.*)$/', $t, $m)) {
            $flush();
            $q = [$m[1]];
            $i++;
            while ($i < $n && preg_match('/^>\s?(.*)$/', trim($lines[$i]), $mm)) { $q[] = $mm[1]; $i++; }
            $blocks[] = ['type' => 'quote', 'text' => implode(' ', $q)];
            continue;
        }

        $para[] = $t;
        $i++;
    }
    $flush();
    return $blocks;
}

function splitRow(string $line): array
{
    $line = preg_replace('/^\||\|$/', '', $line);
    // Split on pipes that are not escaped
    $parts = preg_split('/(?<!\\\\)\|/', $line);
    return array_map(fn($c) => str_replace('\\|', '|', trim($c)), $parts);
}

// ═════════════════════════════════════════════════════════════════════════════
// BLOCK RENDERER
// ═════════════════════════════════════════════════════════════════════════════

function renderBlocks(array $blocks): array
{
    $xml   = '';
    $rels  = [];
    $first = true;

    foreach ($blocks as $b) {
        switch ($b['type']) {

            case 'h':
                $txt = $b['text'];
                // Page breaks before the major front-matter sections
                $brk = false;
                if ($b['level'] === 2 && preg_match('/^(ABSTRACT|TABLE OF CONTENTS)$/i', strip_md($txt))) $brk = true;
                if ($b['level'] === 2 && preg_match('/^1\.\s/', strip_md($txt))) $brk = true;

                if ($first && $b['level'] === 1) {
                    $xml .= para(inline($txt, $rels), 'BECTitle');
                    $first = false;
                    break;
                }
                if ($b['level'] === 2 && $first === false && preg_match('/^An Economic Evaluation/i', strip_md($txt))) {
                    $xml .= para(inline($txt, $rels), 'BECSubtitle');
                    break;
                }
                $style = 'Heading' . min($b['level'], 4);
                $xml  .= para(inline($txt, $rels), $style, $brk);
                break;

            case 'p':
                $xml .= para(inline($b['text'], $rels), 'Normal');
                break;

            case 'quote':
                $xml .= para(inline($b['text'], $rels), 'BECQuote');
                break;

            case 'math':
                $xml .= para(run(latex2text($b['text']), ['font' => 'Cambria Math', 'sz' => 22, 'color' => MAROON]), 'BECMath');
                break;

            case 'hr':
                $xml .= '<w:p><w:pPr><w:pStyle w:val="BECRule"/></w:pPr></w:p>';
                break;

            case 'ul':
                foreach ($b['items'] as $it) {
                    $xml .= para(run('•  ', ['color' => GOLD, 'b' => true]) . inline($it, $rels), 'BECList');
                }
                break;

            case 'ol':
                foreach ($b['items'] as $it) {
                    $xml .= para(run($it['num'] . '.  ', ['color' => MAROON, 'b' => true]) . inline($it['text'], $rels), 'BECList');
                }
                break;

            case 'code':
                foreach ($b['lines'] as $l) {
                    $xml .= para(run($l === '' ? ' ' : $l, ['font' => 'Consolas', 'sz' => 18]), 'BECCode');
                }
                break;

            case 'table':
                $xml .= table($b, $rels);
                break;
        }
    }
    return ['xml' => $xml, 'rels' => $rels];
}

// ── Table ────────────────────────────────────────────────────────────────────

function table(array $t, array &$rels): string
{
    $cols  = max(count($t['header']), 1);
    $total = 9360; // 6.5in at 1440 twips/in

    // Weight columns by longest content, clamped so no column collapses or dominates
    $w = [];
    for ($c = 0; $c < $cols; $c++) {
        $len = mb_strlen(strip_md($t['header'][$c] ?? ''));
        foreach ($t['rows'] as $r) $len = max($len, mb_strlen(strip_md($r[$c] ?? '')));
        $w[$c] = max(6, min(60, $len));
    }
    $sum   = array_sum($w) ?: 1;
    $width = array_map(fn($x) => (int) round($x / $sum * $total), $w);
    $width[$cols - 1] += $total - array_sum($width);

    $grid = '<w:tblGrid>';
    foreach ($width as $x) $grid .= '<w:gridCol w:w="' . $x . '"/>';
    $grid .= '</w:tblGrid>';

    $xml = '<w:tbl><w:tblPr>'
        . '<w:tblStyle w:val="BECTable"/>'
        . '<w:tblW w:w="' . $total . '" w:type="dxa"/>'
        . '<w:tblLayout w:type="fixed"/>'
        . '<w:tblCellMar>'
        . '<w:top w:w="60" w:type="dxa"/><w:left w:w="90" w:type="dxa"/>'
        . '<w:bottom w:w="60" w:type="dxa"/><w:right w:w="90" w:type="dxa"/>'
        . '</w:tblCellMar>'
        . '</w:tblPr>' . $grid;

    // Header row — repeats across page breaks
    $xml .= '<w:tr><w:trPr><w:tblHeader/><w:cantSplit/></w:trPr>';
    for ($c = 0; $c < $cols; $c++) {
        $xml .= cell(
            inline($t['header'][$c] ?? '', $rels, ['b' => true, 'color' => 'FFFFFF']),
            $width[$c],
            $t['align'][$c] ?? 'left',
            MAROON
        );
    }
    $xml .= '</w:tr>';

    $i = 0;
    foreach ($t['rows'] as $r) {
        $shade = ($i % 2 === 1) ? BAND : null;   // zebra striping
        $xml  .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
        for ($c = 0; $c < $cols; $c++) {
            $xml .= cell(inline($r[$c] ?? '', $rels), $width[$c], $t['align'][$c] ?? 'left', $shade);
        }
        $xml .= '</w:tr>';
        $i++;
    }
    $xml .= '</w:tbl>';
    // Breathing room after every table
    $xml .= '<w:p><w:pPr><w:spacing w:after="0" w:line="120" w:lineRule="exact"/></w:pPr></w:p>';
    return $xml;
}

function cell(string $runs, int $w, string $align, ?string $shade): string
{
    $p = '<w:p><w:pPr><w:pStyle w:val="BECCell"/>'
        . ($align !== 'left' ? '<w:jc w:val="' . $align . '"/>' : '')
        . '</w:pPr>' . ($runs !== '' ? $runs : '<w:r><w:t xml:space="preserve"></w:t></w:r>') . '</w:p>';
    return '<w:tc><w:tcPr>'
        . '<w:tcW w:w="' . $w . '" w:type="dxa"/>'
        . ($shade ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $shade . '"/>' : '')
        . '<w:vAlign w:val="center"/>'
        . '</w:tcPr>' . $p . '</w:tc>';
}

// ── Paragraph / run helpers ──────────────────────────────────────────────────

function para(string $runs, string $style = 'Normal', bool $pageBreakBefore = false): string
{
    return '<w:p><w:pPr><w:pStyle w:val="' . $style . '"/>'
        . ($pageBreakBefore ? '<w:pageBreakBefore/>' : '')
        . '</w:pPr>' . $runs . '</w:p>';
}

function run(string $text, array $f = []): string
{
    $rpr = '';
    if (!empty($f['font']))  $rpr .= '<w:rFonts w:ascii="' . $f['font'] . '" w:hAnsi="' . $f['font'] . '" w:cs="' . $f['font'] . '"/>';
    if (!empty($f['b']))     $rpr .= '<w:b/>';
    if (!empty($f['i']))     $rpr .= '<w:i/>';
    if (!empty($f['u']))     $rpr .= '<w:u w:val="single"/>';
    if (!empty($f['color'])) $rpr .= '<w:color w:val="' . $f['color'] . '"/>';
    if (!empty($f['sz']))    $rpr .= '<w:sz w:val="' . $f['sz'] . '"/><w:szCs w:val="' . $f['sz'] . '"/>';
    if (!empty($f['shd']))   $rpr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $f['shd'] . '"/>';
    $rpr = $rpr ? '<w:rPr>' . $rpr . '</w:rPr>' : '';
    return '<w:r>' . $rpr . '<w:t xml:space="preserve">' . xml($text) . '</w:t></w:r>';
}

// ── Inline Markdown → runs ───────────────────────────────────────────────────

function inline(string $s, array &$rels, array $base = []): string
{
    // Links first, so their label can carry further inline formatting
    $out   = '';
    $offset = 0;
    if (preg_match_all('/\[([^\]]*)\]\(([^)\s]+)\)/', $s, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $whole = $hit[0][0];
            $pos   = $hit[0][1];
            $label = $hit[1][0];
            $url   = $hit[2][0];
            $out  .= plain(substr($s, $offset, $pos - $offset), $rels, $base);
            if (strpos($url, '#') === 0) {
                $out .= spans($label, $base);                     // internal anchor → plain text
            } else {
                $rid   = 'rIdL' . (count($rels) + 1);
                $rels[$rid] = $url;
                $out  .= '<w:hyperlink r:id="' . $rid . '">'
                       . spans($label, $base + ['color' => '0B5FA5', 'u' => true])
                       . '</w:hyperlink>';
            }
            $offset = $pos + strlen($whole);
        }
    }
    $out .= plain(substr($s, $offset), $rels, $base);
    return $out;
}

/** Emit text, turning bare http(s) URLs into real Word hyperlinks. */
function plain(string $s, array &$rels, array $base = []): string
{
    if ($s === '') return '';
    $out   = '';
    $parts = preg_split('~(https?://[^\s<>()\[\],]+)~', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    foreach ($parts as $p) {
        if (preg_match('~^https?://~', $p)) {
            $url = rtrim($p, '.,;:');                  // trailing sentence punctuation is not part of the URL
            $tail = substr($p, strlen($url));
            $rid  = 'rIdL' . (count($rels) + 1);
            $rels[$rid] = $url;
            $out .= '<w:hyperlink r:id="' . $rid . '">'
                  . spans($url, $base + ['color' => '0B5FA5', 'u' => true])
                  . '</w:hyperlink>';
            if ($tail !== '') $out .= spans($tail, $base);
        } else {
            $out .= spans($p, $base);
        }
    }
    return $out;
}

function spans(string $s, array $base = []): string
{
    if ($s === '') return '';
    // Inline math → plain-text formula.
    // The opening delimiter must NOT follow an alphanumeric, otherwise a line
    // like "US$25/month = US$300/yr" is misread as math and the $ signs vanish.
    $s = preg_replace_callback('/(?<![A-Za-z0-9])\$([^$]+)\$/', fn($m) => latex2text($m[1]), $s);

    $out = '';
    // Tokenise **bold**, *italic*/_italic_, `code`
    $re = '/(\*\*.+?\*\*|__.+?__|(?<![\w*])\*(?!\s)[^*]+?\*|(?<![\w_])_(?!\s)[^_]+?_|`[^`]+`)/s';
    foreach (preg_split($re, $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $tok) {
        if (preg_match('/^\*\*(.+)\*\*$/s', $tok, $m) || preg_match('/^__(.+)__$/s', $tok, $m)) {
            $out .= run(unesc($m[1]), $base + ['b' => true]);
        } elseif (preg_match('/^`(.+)`$/s', $tok, $m)) {
            $out .= run(unesc($m[1]), $base + ['font' => 'Consolas', 'sz' => 18, 'shd' => 'F0EDE6']);
        } elseif (preg_match('/^\*(.+)\*$/s', $tok, $m) || preg_match('/^_(.+)_$/s', $tok, $m)) {
            $out .= run(unesc($m[1]), $base + ['i' => true]);
        } else {
            $out .= run(unesc($tok), $base);
        }
    }
    return $out;
}

function unesc(string $s): string
{
    return str_replace(['\\*', '\\_', '\\|', '\\#', '\\-'], ['*', '_', '|', '#', '-'], $s);
}

function strip_md(string $s): string
{
    $s = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $s);
    return trim(str_replace(['**', '__', '*', '`', '$'], '', $s));
}

// ── LaTeX → readable plain text (Word has no LaTeX engine) ───────────────────

function latex2text(string $s): string
{
    $s = str_replace('{,}', ',', $s);
    $s = preg_replace('/\\\\(d?frac)\s*/', '\\frac', $s);

    // Resolve \frac{A}{B} innermost-first via brace matching
    $guard = 0;
    while (($p = strpos($s, '\frac')) !== false && $guard++ < 40) {
        $a = braceAt($s, $p + 5);
        if ($a === null) break;
        $b = braceAt($s, $a['end'] + 1);
        if ($b === null) break;
        $num = trim(latex2text($a['body']));
        $den = trim(latex2text($b['body']));
        $rep = '(' . $num . ') / (' . $den . ')';
        $s   = substr($s, 0, $p) . $rep . substr($s, $b['end'] + 1);
    }

    $s = preg_replace('/\\\\sum_\{([^}]*)\}\^\{([^}]*)\}/u', 'Σ[$1→$2]', $s);
    $s = preg_replace('/\\\\sum_\{([^}]*)\}/u', 'Σ[$1]', $s);
    $s = preg_replace('/\\\\(?:boxed|text|mathrm|mathit|bar)\{([^{}]*)\}/u', '$1', $s);
    $s = preg_replace('/\\\\lvert|\\\\rvert|\\\\vert/u', '|', $s);

    $map = [
        '\\times' => ' × ', '\\div' => ' ÷ ', '\\cdot' => '·', '\\approx' => ' ≈ ',
        '\\leq' => ' ≤ ', '\\geq' => ' ≥ ', '\\neq' => ' ≠ ', '\\pm' => '±',
        '\\quad' => '   ', '\\qquad' => '      ', '\\,' => ' ', '\\;' => ' ',
        '\\%' => '%', '\\$' => '$', '\\left' => '', '\\right' => '', '\\!' => '',
    ];
    $s = strtr($s, $map);
    $s = preg_replace('/\\\\[a-zA-Z]+/', '', $s);        // drop unknown commands
    $s = str_replace(['{', '}'], '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = str_replace([' ,', ' )', '( '], [',', ')', '('], $s);
    return trim($s);
}

function braceAt(string $s, int $i): ?array
{
    while ($i < strlen($s) && $s[$i] === ' ') $i++;
    if (($s[$i] ?? '') !== '{') return null;
    $depth = 0;
    for ($j = $i; $j < strlen($s); $j++) {
        if ($s[$j] === '{') $depth++;
        elseif ($s[$j] === '}') {
            $depth--;
            if ($depth === 0) return ['body' => substr($s, $i + 1, $j - $i - 1), 'end' => $j];
        }
    }
    return null;
}

function xml(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// ═════════════════════════════════════════════════════════════════════════════
// OOXML PACKAGE
// ═════════════════════════════════════════════════════════════════════════════

function writeDocx(string $path, string $bodyXml, array $rels, string $title): void
{
    $files = [
        '[Content_Types].xml'      => contentTypes(),
        '_rels/.rels'              => packageRels(),
        'docProps/core.xml'        => coreProps($title),
        'docProps/app.xml'         => appProps(),
        'word/document.xml'        => document($bodyXml),
        'word/styles.xml'          => styles(),
        'word/_rels/document.xml.rels' => documentRels($rels),
    ];
    zipWrite($path, $files);
}

function document(string $body): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<w:body>' . $body
        . '<w:sectPr>'
        . '<w:pgSz w:w="12240" w:h="15840"/>'                                    // US Letter
        . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"'
        . ' w:header="720" w:footer="720" w:gutter="0"/>'
        . '<w:docGrid w:linePitch="360"/>'
        . '</w:sectPr></w:body></w:document>';
}

function styles(): string
{
    $s = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">';

    // Document defaults
    $s .= '<w:docDefaults><w:rPrDefault><w:rPr>'
        . '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="Times New Roman" w:cs="Times New Roman"/>'
        . '<w:color w:val="' . INK . '"/><w:sz w:val="22"/><w:szCs w:val="22"/>'
        . '</w:rPr></w:rPrDefault>'
        . '<w:pPrDefault><w:pPr><w:spacing w:after="140" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault>'
        . '</w:docDefaults>';

    $s .= style('Normal', 'Normal', null,
        '<w:jc w:val="both"/>', '');

    $s .= style('BECTitle', 'Title', 'Normal',
        '<w:jc w:val="center"/><w:spacing w:before="1200" w:after="120"/>',
        '<w:rFonts w:ascii="Cambria" w:hAnsi="Cambria"/><w:b/><w:color w:val="' . MAROON . '"/><w:sz w:val="52"/><w:szCs w:val="52"/>');

    $s .= style('BECSubtitle', 'Subtitle', 'Normal',
        '<w:jc w:val="center"/><w:spacing w:before="0" w:after="480"/>'
        . '<w:pBdr><w:bottom w:val="single" w:sz="12" w:space="8" w:color="' . GOLD . '"/></w:pBdr>',
        '<w:rFonts w:ascii="Cambria" w:hAnsi="Cambria"/><w:color w:val="' . MUTED . '"/><w:sz w:val="26"/><w:szCs w:val="26"/><w:i/>');

    $s .= style('Heading1', 'heading 1', 'Normal',
        '<w:keepNext/><w:jc w:val="left"/><w:spacing w:before="360" w:after="160"/>'
        . '<w:pBdr><w:bottom w:val="single" w:sz="8" w:space="6" w:color="' . GOLD . '"/></w:pBdr>'
        . '<w:outlineLvl w:val="0"/>',
        '<w:rFonts w:ascii="Cambria" w:hAnsi="Cambria"/><w:b/><w:color w:val="' . MAROON . '"/><w:sz w:val="34"/><w:szCs w:val="34"/>');

    $s .= style('Heading2', 'heading 2', 'Normal',
        '<w:keepNext/><w:jc w:val="left"/><w:spacing w:before="320" w:after="140"/>'
        . '<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="5" w:color="' . RULE . '"/></w:pBdr>'
        . '<w:outlineLvl w:val="1"/>',
        '<w:rFonts w:ascii="Cambria" w:hAnsi="Cambria"/><w:b/><w:color w:val="' . MAROON . '"/><w:sz w:val="28"/><w:szCs w:val="28"/>');

    $s .= style('Heading3', 'heading 3', 'Normal',
        '<w:keepNext/><w:jc w:val="left"/><w:spacing w:before="240" w:after="100"/><w:outlineLvl w:val="2"/>',
        '<w:rFonts w:ascii="Cambria" w:hAnsi="Cambria"/><w:b/><w:color w:val="4A1111"/><w:sz w:val="24"/><w:szCs w:val="24"/>');

    $s .= style('Heading4', 'heading 4', 'Normal',
        '<w:keepNext/><w:jc w:val="left"/><w:spacing w:before="200" w:after="80"/><w:outlineLvl w:val="3"/>',
        '<w:rFonts w:ascii="Cambria" w:hAnsi="Cambria"/><w:b/><w:i/><w:color w:val="' . MUTED . '"/><w:sz w:val="22"/><w:szCs w:val="22"/>');

    $s .= style('BECMath', 'Formula', 'Normal',
        '<w:jc w:val="center"/><w:spacing w:before="160" w:after="160"/><w:keepLines/>',
        '<w:rFonts w:ascii="Cambria Math" w:hAnsi="Cambria Math"/><w:color w:val="' . MAROON . '"/>');

    $s .= style('BECList', 'List Paragraph', 'Normal',
        '<w:ind w:left="482" w:hanging="284"/><w:jc w:val="left"/><w:spacing w:after="80"/>', '');

    $s .= style('BECQuote', 'Quote', 'Normal',
        '<w:ind w:left="454" w:right="284"/><w:spacing w:before="140" w:after="140"/>'
        . '<w:pBdr><w:left w:val="single" w:sz="18" w:space="10" w:color="' . GOLD . '"/></w:pBdr>',
        '<w:i/><w:color w:val="' . MUTED . '"/>');

    $s .= style('BECCode', 'Code', 'Normal',
        '<w:jc w:val="left"/><w:spacing w:after="0" w:line="240" w:lineRule="auto"/><w:ind w:left="284"/>',
        '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:sz w:val="18"/><w:szCs w:val="18"/>');

    $s .= style('BECCell', 'Table Text', 'Normal',
        '<w:jc w:val="left"/><w:spacing w:before="20" w:after="20" w:line="240" w:lineRule="auto"/>',
        '<w:sz w:val="19"/><w:szCs w:val="19"/>');

    $s .= style('BECRule', 'Rule', 'Normal',
        '<w:spacing w:before="120" w:after="120"/>'
        . '<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="' . RULE . '"/></w:pBdr>', '');

    // Table style: hairline grid in the brand's warm neutral
    $s .= '<w:style w:type="table" w:styleId="BECTable"><w:name w:val="BEC Table"/>'
        . '<w:tblPr><w:tblBorders>'
        . '<w:top w:val="single" w:sz="4" w:color="' . RULE . '"/>'
        . '<w:left w:val="single" w:sz="4" w:color="' . RULE . '"/>'
        . '<w:bottom w:val="single" w:sz="4" w:color="' . RULE . '"/>'
        . '<w:right w:val="single" w:sz="4" w:color="' . RULE . '"/>'
        . '<w:insideH w:val="single" w:sz="4" w:color="' . RULE . '"/>'
        . '<w:insideV w:val="single" w:sz="4" w:color="' . RULE . '"/>'
        . '</w:tblBorders></w:tblPr></w:style>';

    return $s . '</w:styles>';
}

function style(string $id, string $name, ?string $basedOn, string $ppr, string $rpr): string
{
    return '<w:style w:type="paragraph" w:styleId="' . $id . '"'
        . ($id === 'Normal' ? ' w:default="1"' : '') . '>'
        . '<w:name w:val="' . $name . '"/>'
        . ($basedOn ? '<w:basedOn w:val="' . $basedOn . '"/>' : '')
        . '<w:qFormat/>'
        . ($ppr ? '<w:pPr>' . $ppr . '</w:pPr>' : '')
        . ($rpr ? '<w:rPr>' . $rpr . '</w:rPr>' : '')
        . '</w:style>';
}

function contentTypes(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';
}

function packageRels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';
}

function documentRels(array $rels): string
{
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    foreach ($rels as $id => $url) {
        $x .= '<Relationship Id="' . $id . '"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink"'
            . ' Target="' . xml($url) . '" TargetMode="External"/>';
    }
    return $x . '</Relationships>';
}

function coreProps(string $title): string
{
    $now = gmdate('Y-m-d\TH:i:s\Z');
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . xml(pathinfo($title, PATHINFO_FILENAME)) . '</dc:title>'
        . '<dc:creator>Batangas Eastern Colleges — Property Management Office</dc:creator>'
        . '<cp:lastModifiedBy>BEC PMO</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
        . '</cp:coreProperties>';
}

function appProps(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
        . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>BEC PMO Document Builder</Application><Company>Batangas Eastern Colleges</Company>'
        . '</Properties>';
}

// ── ZIP writer (ZipArchive when available, otherwise a stored-mode writer) ────

function zipWrite(string $path, array $files): void
{
    if (class_exists('ZipArchive')) {
        @unlink($path);
        $z = new ZipArchive();
        if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create $path");
        }
        foreach ($files as $name => $data) $z->addFromString($name, $data);
        // close() writes the archive; it returns false if the target is locked
        // (e.g. the .docx is currently open in Word) — never report success then.
        if (!@$z->close()) {
            throw new RuntimeException(
                "Could not write $path — the file is locked.\n"
                . "        Close it in Word/LibreOffice and run this again."
            );
        }
        return;
    }

    // Fallback: hand-rolled store-only ZIP (same approach as includes/xlsx_writer.php)
    $out = '';
    $cd  = '';
    $off = 0;
    foreach ($files as $name => $data) {
        $crc = crc32($data);
        $len = strlen($data);
        $lfh = "PK\x03\x04" . "\x14\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            . pack('VVV', $crc, $len, $len) . pack('vv', strlen($name), 0) . $name;
        $out .= $lfh . $data;
        $cd  .= "PK\x01\x02" . "\x14\x00\x14\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            . pack('VVV', $crc, $len, $len)
            . pack('vvvvvVV', strlen($name), 0, 0, 0, 0, 32, $off) . $name;
        $off += strlen($lfh) + $len;
    }
    $out .= $cd . "PK\x05\x06\x00\x00\x00\x00"
        . pack('vvVV', count($files), count($files), strlen($cd), $off) . "\x00\x00";
    file_put_contents($path, $out);
}
