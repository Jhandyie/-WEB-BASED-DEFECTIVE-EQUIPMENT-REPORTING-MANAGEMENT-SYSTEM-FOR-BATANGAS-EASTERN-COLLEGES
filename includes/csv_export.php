<?php
/**
 * includes/csv_export.php
 * One place for writing CSV that Excel opens cleanly.
 *
 * Three things every CSV download here has to get right, and none of them are
 * defaults:
 *
 *   1. A UTF-8 BOM. Without it Excel on Windows opens the file as ANSI, and the
 *      em dashes and "ñ" this system is full of arrive as "â€”" and "Ã±".
 *   2. CRLF line endings — fputcsv() writes "\n", which older Excel builds and
 *      Numbers read as one long row.
 *   3. A guard against formula injection. Equipment names and issue
 *      descriptions are typed by reporters; a value starting with "=", "+",
 *      "-" or "@" is executed as a formula the moment the file is opened.
 *
 * Usage:
 *   require_once __DIR__ . '/csv_export.php';
 *   $out = becCsvOpen('defect_reports');       // sends the headers + BOM
 *   becCsvRow($out, ['Ticket', 'Equipment']);  // header row
 *   becCsvRow($out, [$id, $name]);
 *   fclose($out);
 */

if (!function_exists('becCsvSafe')) {
    /**
     * Neutralise a value that a spreadsheet would treat as a formula.
     * The leading apostrophe is Excel's own "this is text" marker; it is not
     * shown in the cell.
     */
    function becCsvSafe($v): string {
        $s = (string)$v;
        if ($s === '') return '';
        if (is_int($v) || is_float($v)) return $s;
        // A negative number is not a formula. Guarding it would turn every
        // "-42" in a numeric column into text, which is worse than the risk.
        if (is_numeric($s)) return $s;
        if (strpbrk($s[0], "=+-@\t\r") !== false) { return "'" . $s; }
        return $s;
    }
}

if (!function_exists('becCsvOpen')) {
    /**
     * Send the download headers and return a stream ready for becCsvRow().
     * $basename is stamped with the date; do not include ".csv".
     */
    function becCsvOpen(string $basename) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $basename . '_' . date('Y-m-d_His') . '.csv"');
        header('Cache-Control: max-age=0, must-revalidate');
        header('Pragma: public');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM — see note 1 above
        return $out;
    }
}

if (!function_exists('becCsvRow')) {
    /** Write one CSV row: injection-guarded, CRLF-terminated. */
    function becCsvRow($out, array $row): void {
        $clean = array_map('becCsvSafe', $row);
        $buf = fopen('php://temp', 'r+');
        fputcsv($buf, $clean);
        rewind($buf);
        $line = stream_get_contents($buf);
        fclose($buf);
        fwrite($out, rtrim($line, "\r\n") . "\r\n");
    }
}

if (!function_exists('becCsvBlank')) {
    /** A spacer line between sections. */
    function becCsvBlank($out): void { fwrite($out, "\r\n"); }
}

if (!function_exists('becCsvLetterhead')) {
    /**
     * The three-line institutional heading every export document carries, so a
     * printed CSV matches the XLSX and the print-to-PDF version.
     */
    function becCsvLetterhead($out, string $docTitle, array $meta = []): void {
        require_once __DIR__ . '/export_branding.php';
        becCsvRow($out, ['BATANGAS EASTERN COLLEGES']);
        becCsvRow($out, ['Property Management Office']);
        becCsvRow($out, [strtoupper($docTitle)]);
        becCsvRow($out, ['Generated', date('F j, Y \a\t g:i A')]);
        becCsvRow($out, ['Prepared by', becExportPreparedBy()]);
        becCsvRow($out, ['Reference No.', becExportRef()]);
        foreach ($meta as $k => $v) {
            if ($v === '' || $v === null) continue;
            becCsvRow($out, [$k, $v]);
        }
        becCsvBlank($out);
    }
}

if (!function_exists('becCsvSection')) {
    /** A titled block: heading line, then a header row, then rows. */
    function becCsvSection($out, string $label, array $headers, array $rows): void {
        becCsvRow($out, [strtoupper($label)]);
        if ($headers) { becCsvRow($out, $headers); }
        foreach ($rows as $r) { becCsvRow($out, array_values($r)); }
        becCsvBlank($out);
    }
}

if (!function_exists('becCsvFooter')) {
    function becCsvFooter($out, string $endLabel = 'End of report'): void {
        becCsvRow($out, ['Batangas Eastern Colleges — Property Management Office']);
        becCsvRow($out, ['Confidential — for authorized administrative use only']);
        becCsvRow($out, [$endLabel]);
    }
}
