<?php
/**
 * includes/xlsx_writer.php
 * Dependency-free .xlsx (OOXML) writer — no ZipArchive, no Composer.
 * Produces a branded Excel workbook with the BEC logo embedded, a styled
 * letterhead, an executive-summary block, and a formatted data table.
 *
 *   require_once __DIR__ . '/xlsx_writer.php';
 *   becRenderBrandedXlsx('Defect Reports', $headers, $rows, $summary, $metaExtra, 'defect_reports');
 */

require_once __DIR__ . '/export_branding.php'; // becExportPreparedBy(), becExportRef()

if (!function_exists('becXlsxZip')) {
    /** Minimal STORE-method ZIP writer. $files = [name => bytes]. */
    function becXlsxZip(array $files): string {
        $local = '';
        $central = '';
        $offset = 0;
        foreach ($files as $name => $data) {
            $crc  = crc32($data);
            $len  = strlen($data);
            $nlen = strlen($name);
            $lfh  = "PK\x03\x04" . "\x14\x00" . "\x00\x00" . "\x00\x00" . "\x00\x00\x00\x00"
                  . pack('V', $crc) . pack('V', $len) . pack('V', $len)
                  . pack('v', $nlen) . "\x00\x00" . $name;
            $local .= $lfh . $data;
            $central .= "PK\x01\x02" . "\x14\x00" . "\x14\x00" . "\x00\x00" . "\x00\x00" . "\x00\x00\x00\x00"
                  . pack('V', $crc) . pack('V', $len) . pack('V', $len)
                  . pack('v', $nlen) . "\x00\x00" . "\x00\x00" . "\x00\x00" . "\x00\x00"
                  . "\x00\x00\x00\x00" . pack('V', $offset) . $name;
            $offset += strlen($lfh) + $len;
        }
        $eocd = "PK\x05\x06" . "\x00\x00" . "\x00\x00"
              . pack('v', count($files)) . pack('v', count($files))
              . pack('V', strlen($central)) . pack('V', $offset) . "\x00\x00";
        return $local . $central . $eocd;
    }
}

if (!function_exists('becXlsxCol')) {
    /** 1-based column index -> letter (1=A). */
    function becXlsxCol(int $i): string {
        $s = '';
        while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); }
        return $s;
    }
}

if (!function_exists('becXlsxIsNumeric')) {
    /**
     * Should this value be written as a real number?
     *
     * Only when Excel would gain something by it — counts, days, currency. A
     * leading zero means an identifier (asset tag "0042", room "007"), and a
     * long digit string is a property number, not a quantity: both must stay
     * text or the value is silently rewritten in the sheet.
     */
    function becXlsxIsNumeric($v): bool {
        if (is_int($v) || is_float($v)) return true;
        if (!is_string($v)) return false;
        $s = trim($v);
        if ($s === '' || !is_numeric($s)) return false;
        if (strlen($s) > 12) return false;                       // property numbers
        if (preg_match('/^-?0\d/', $s)) return false;            // leading zero = an ID
        return true;
    }
}

if (!function_exists('becXlsxCell')) {
    /**
     * One cell. Numbers go out as numbers (right-aligned, summable); everything
     * else as inline text. $numStyle is the right-aligned twin of $style.
     */
    function becXlsxCell(string $ref, int $style, $val, ?int $numStyle = null): string {
        if ($numStyle !== null && becXlsxIsNumeric($val)) {
            return '<c r="' . $ref . '" s="' . $numStyle . '"><v>' . (0 + trim((string)$val)) . '</v></c>';
        }
        $t = htmlspecialchars((string)$val, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $t . '</t></is></c>';
    }
}

if (!function_exists('becBuildXlsx')) {
    /**
     * Build a branded .xlsx workbook (with embedded logo) and return the binary.
     */
    function becBuildXlsx(string $title, array $headers, array $rows, array $summary = [], array $meta = [], ?int $groupBy = null): string {
        $n    = max(1, count($headers));
        $last = becXlsxCol($n);
        $decl = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

        // ---- build sheet rows ----
        $metaBits = ['Generated ' . date('M j, Y g:i A'), 'Prepared by ' . becExportPreparedBy(), 'Ref. ' . becExportRef()];
        foreach ($meta as $k => $v) { if ($v !== '' && $v !== null) $metaBits[] = $k . ': ' . $v; }
        $metaLine = implode('    ·    ', $metaBits);
        $metaLine = str_replace('·', "\xC2\xB7", $metaLine);

        $sheet  = '';
        $merges = [];

        // rows 1-4 reserved for the floating logo
        $sheet .= '<row r="1" ht="21" customHeight="1"/><row r="2" ht="21" customHeight="1"/><row r="3" ht="21" customHeight="1"/><row r="4" ht="12" customHeight="1"/>';

        $r = 5;
        $titleRows = [
            ['Batangas Eastern Colleges', 1],
            ['Property Management Office', 2],
            [$title !== '' ? $title : 'Report', 3],
            ['02 Javier Street, Poblacion, San Juan, Batangas 4226  ·  043-575-3616  ·  info@bec.edu.ph', 4],
            [$metaLine, 4],
        ];
        foreach ($titleRows as $tr) {
            $text = str_replace('·', "\xC2\xB7", $tr[0]);
            $sheet .= '<row r="' . $r . '">' . becXlsxCell('A' . $r, $tr[1], $text) . '</row>';
            if ($n > 1) $merges[] = 'A' . $r . ':' . $last . $r;
            $r++;
        }
        $r++; // spacer

        if ($summary) {
            $sheet .= '<row r="' . $r . '">' . becXlsxCell('A' . $r, 9, 'Executive Summary') . '</row>';
            $r++;
            foreach ($summary as $k => $v) {
                $sheet .= '<row r="' . $r . '">' . becXlsxCell('A' . $r, 8, $k) . becXlsxCell('B' . $r, 10, $v) . '</row>';
                $r++;
            }
            $r++; // spacer
        }

        // header row — frozen and filterable, so a long list stays readable
        $headerRowNum = $r;
        $hr = '<row r="' . $r . '" ht="26" customHeight="1">';
        for ($i = 1; $i <= $n; $i++) { $hr .= becXlsxCell(becXlsxCol($i) . $r, 5, $headers[$i - 1] ?? ''); }
        $sheet .= $hr . '</row>';
        $r++;

        // widest string seen per column, for the column widths further down
        $widths = [];
        foreach ($headers as $ci => $hdr) { $widths[$ci] = mb_strlen((string)$hdr); }

        // data rows (optionally grouped into gray banded sections)
        $emitRow = function (array $row, int $st) use (&$sheet, &$r, &$widths, $n) {
            $rr = '<row r="' . $r . '">';
            $i = 0;
            foreach ($row as $cell) {
                $i++;
                if ($i > $n) break;
                // styles 6/7 are the plain and banded body cells; 12/13 are the
                // same two right-aligned for numbers.
                $numSt = ($st === 6) ? 12 : (($st === 7) ? 13 : null);
                $rr .= becXlsxCell(becXlsxCol($i) . $r, $st, $cell, $numSt);
                $len = mb_strlen((string)$cell);
                if (!isset($widths[$i - 1]) || $len > $widths[$i - 1]) { $widths[$i - 1] = $len; }
            }
            $sheet .= $rr . '</row>';
            $r++;
        };
        $alt = false;
        if (!$rows) {
            $sheet .= '<row r="' . $r . '">' . becXlsxCell('A' . $r, 6, 'No records found for the selected filters.') . '</row>';
            $r++;
        } elseif ($groupBy !== null) {
            $groups = [];
            foreach ($rows as $row) {
                $vals = array_values($row);
                $key = trim((string)($vals[$groupBy] ?? ''));
                if ($key === '' || $key === '—') { $key = 'Unspecified'; }
                $groups[$key][] = $row;
            }
            ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($groups as $gLabel => $gRows) {
                $band = '<row r="' . $r . '">' . becXlsxCell('A' . $r, 11, $gLabel . ' (' . count($gRows) . ')');
                for ($ci = 2; $ci <= $n; $ci++) { $band .= '<c r="' . becXlsxCol($ci) . $r . '" s="11"/>'; }
                $sheet .= $band . '</row>'; $r++;
                foreach ($gRows as $row) { $emitRow($row, $alt ? 7 : 6); $alt = !$alt; }
            }
        } else {
            foreach ($rows as $row) { $emitRow($row, $alt ? 7 : 6); $alt = !$alt; }
        }

        $lastDataRow = max($headerRowNum, $r - 1);

        // signatories (official BEC form)
        $r++;
        $sigLabels = ['Prepared by:', 'Noted by:', 'Approved by:', 'Received by:'];
        $sigNames  = ['Adrian Ilao', 'Monaliza Mancilla', 'Edison Dumo', ''];
        $sigTitles = ['Asst. PMO', 'PMO', 'School Administrator', 'Accounting Department'];
        $rowXml = '<row r="' . $r . '">'; for ($i = 0; $i < 4; $i++) { $rowXml .= becXlsxCell(becXlsxCol($i + 1) . $r, 8, $sigLabels[$i]); } $sheet .= $rowXml . '</row>'; $r += 2;
        $rowXml = '<row r="' . $r . '">'; for ($i = 0; $i < 4; $i++) { $rowXml .= becXlsxCell(becXlsxCol($i + 1) . $r, 9, $sigNames[$i]); } $sheet .= $rowXml . '</row>'; $r++;
        $rowXml = '<row r="' . $r . '">'; for ($i = 0; $i < 4; $i++) { $rowXml .= becXlsxCell(becXlsxCol($i + 1) . $r, 9, $sigTitles[$i]); } $sheet .= $rowXml . '</row>'; $r++;

        $maxRow = $r - 1;

        // Columns sized to their widest cell rather than a flat 20, so nothing
        // reads as "####" or trails off under the next column.
        $cols = '<cols>';
        for ($ci = 1; $ci <= max(1, $n); $ci++) {
            $w = ($widths[$ci - 1] ?? 12) + 3;
            $w = max(10, min(46, $w));
            $cols .= '<col min="' . $ci . '" max="' . $ci . '" width="' . $w . '" customWidth="1"/>';
        }
        $cols .= '</cols>';

        // Freeze everything above the data so the header stays put while scrolling.
        $freezeAt = $headerRowNum + 1;
        $sheetViews = '<sheetViews><sheetView showGridLines="0" workbookViewId="0">'
            . '<pane ySplit="' . $headerRowNum . '" topLeftCell="A' . $freezeAt . '" activePane="bottomLeft" state="frozen"/>'
            . '<selection pane="bottomLeft" activeCell="A' . $freezeAt . '" sqref="A' . $freezeAt . '"/>'
            . '</sheetView></sheetViews>';

        // Filter dropdowns on the header row (schema order: after sheetData,
        // before mergeCells). Skipped on a grouped sheet — filtering there
        // leaves the section bands behind with nothing under them.
        $autoFilter = ($groupBy === null && $rows)
            ? '<autoFilter ref="A' . $headerRowNum . ':' . $last . $lastDataRow . '"/>'
            : '';

        $mergeXml = '';
        if ($merges) {
            $mergeXml = '<mergeCells count="' . count($merges) . '">';
            foreach ($merges as $m) { $mergeXml .= '<mergeCell ref="' . $m . '"/>'; }
            $mergeXml .= '</mergeCells>';
        }

        // Printing straight out of Excel: landscape once the table is wide,
        // squeezed to one page across, with the header repeated on every sheet.
        $landscape = $n > 6 ? 'landscape' : 'portrait';
        $pageSetup = '<printOptions horizontalCentered="1"/>'
            . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            . '<pageSetup paperSize="9" orientation="' . $landscape . '" fitToWidth="1" fitToHeight="0" horizontalDpi="300" verticalDpi="300"/>';

        $sheetXml = $decl
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<dimension ref="A1:' . $last . $maxRow . '"/>'
            . $sheetViews
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $cols
            . '<sheetData>' . $sheet . '</sheetData>'
            . $autoFilter
            . $mergeXml
            . $pageSetup
            . '<drawing r:id="rId1"/>'
            . '</worksheet>';

        // ---- static parts ----
        $contentTypes = $decl
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>'
            . '</Types>';

        $rels = $decl
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbook = $decl
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets>'
            // repeat the header row at the top of every printed page
            . '<definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">Report!$'
            . $headerRowNum . ':$' . $headerRowNum . '</definedName></definedNames>'
            . '</workbook>';

        $workbookRels = $decl
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $styles = $decl
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            // thousands separator, and decimals only when the value has them
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.##"/></numFmts>'
            . '<fonts count="6">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="18"/><color rgb="FF7B1D1D"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF5C3838"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><sz val="9"/><color rgb="FF9E8070"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="12"/><color rgb="FF7B1D1D"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="5">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF7B1D1D"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF8F3EA"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFC9C2B8"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFE8DDD0"/></left><right style="thin"><color rgb="FFE8DDD0"/></right><top style="thin"><color rgb="FFE8DDD0"/></top><bottom style="thin"><color rgb="FFE8DDD0"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="14">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            // 12 / 13: the plain and banded body cells, right-aligned for numbers
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';

        $sheetRels = $decl
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
            . '</Relationships>';

        $drawing = $decl
            . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<xdr:oneCellAnchor>'
            . '<xdr:from><xdr:col>0</xdr:col><xdr:colOff>38100</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>38100</xdr:rowOff></xdr:from>'
            . '<xdr:ext cx="762000" cy="762000"/>'
            . '<xdr:pic>'
            . '<xdr:nvPicPr><xdr:cNvPr id="2" name="BEC Logo" descr="Batangas Eastern Colleges"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
            . '<xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="762000" cy="762000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
            . '</xdr:pic>'
            . '<xdr:clientData/>'
            . '</xdr:oneCellAnchor>'
            . '</xdr:wsDr>';

        $drawingRels = $decl
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.png"/>'
            . '</Relationships>';

        $logo = '';
        foreach (['logs.png', 'logo.png'] as $ln) {
            $p = __DIR__ . '/../assets/' . $ln;
            if (is_file($p)) { $logo = (string)@file_get_contents($p); if ($logo !== '') break; }
        }

        $parts = [
            '[Content_Types].xml'                 => $contentTypes,
            '_rels/.rels'                         => $rels,
            'xl/workbook.xml'                     => $workbook,
            'xl/_rels/workbook.xml.rels'          => $workbookRels,
            'xl/styles.xml'                       => $styles,
            'xl/worksheets/sheet1.xml'            => $sheetXml,
            'xl/worksheets/_rels/sheet1.xml.rels' => $sheetRels,
            'xl/drawings/drawing1.xml'            => $drawing,
            'xl/drawings/_rels/drawing1.xml.rels' => $drawingRels,
        ];
        if ($logo !== '') { $parts['xl/media/image1.png'] = $logo; }

        return becXlsxZip($parts);
    }
}

if (!function_exists('becRenderBrandedXlsx')) {
    /** Build + stream a branded .xlsx download. */
    function becRenderBrandedXlsx(string $title, array $headers, array $rows, array $summary = [], array $meta = [], string $filename = 'report', ?int $groupBy = null): void {
        $xlsx = becBuildXlsx($title, $headers, $rows, $summary, $meta, $groupBy);
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d_H-i-s') . '.xlsx"');
        header('Content-Length: ' . strlen($xlsx));
        header('Cache-Control: max-age=0');
        echo $xlsx;
    }
}
