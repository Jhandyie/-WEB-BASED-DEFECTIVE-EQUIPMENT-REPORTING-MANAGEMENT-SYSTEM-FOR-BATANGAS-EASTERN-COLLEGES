<?php
/**
 * includes/inventory_template.php — the shape of an inventory upload.
 *
 * The admin page had an "Upload Inventory Excel" button and nothing anywhere
 * said what to put in the file, because there was no format: the importer swept
 * every cell in the workbook with a regular expression looking for strings
 * shaped like A-0825-0001, counted them by prefix, and wrote the counts to
 * api/data/inventory.json. The equipment table — the 1,329 records that drive
 * reports, QR codes and work orders — was never touched. So nobody could answer
 * "what fields should I provide?" because there were no fields.
 *
 * This file is the answer, and it is deliberately the ONLY answer: the template
 * the admin downloads and the importer that reads it back are generated from
 * the same list below. They cannot drift apart, which is the failure mode a
 * hand-written template always ends in.
 *
 * The equipment table carries several names for one thing (category /
 * equipment_category, condition_status / condition, remarks / notes, quantity /
 * counted, purchase_date / acquired). The template exposes one name per concept
 * and writes to whichever columns exist, so the vocabulary an administrator
 * sees is settled here rather than inherited from the schema's history.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/xlsx_writer.php';

if (!function_exists('becInventoryColumns')) {
    /**
     * The canonical column set, in the order the template presents them.
     *
     * `aliases` are what a real workbook might already call the column, so an
     * office that has been keeping this sheet for years does not have to retype
     * its headers. Matching is case- and punctuation-insensitive.
     *
     * @return array<string,array{label:string,required:bool,example:string,note:string,values:?array}>
     */
    function becInventoryColumns(): array {
        return [
            'property_no' => [
                'label'    => 'Property No.',
                'required' => true,
                'example'  => 'A-0825-0001',
                'note'     => 'The number stamped on the unit. This is the key: re-uploading a corrected sheet updates these records rather than duplicating them. The list ends at the first empty row — keep your rows together.',
                'values'   => null,
                'aliases'  => ['property number', 'propertyno', 'property', 'asset tag', 'asset_tag', 'tag', 'equipment id', 'equipment_id', 'id'],
            ],
            'equipment_name' => [
                'label'    => 'Equipment Name',
                'required' => true,
                'example'  => 'Air Conditioning Unit',
                'note'     => 'What the thing is called. Shown to reporters when they file a fault.',
                'values'   => null,
                'aliases'  => ['equipment', 'name', 'item', 'article', 'description of article'],
            ],
            'category' => [
                'label'    => 'Category',
                'required' => true,
                'example'  => 'Air Conditioner',
                'note'     => 'Groups the item on the inventory page and in reports. Free text — use the same wording for the same kind of thing.',
                'values'   => null,
                'aliases'  => ['type', 'equipment category', 'equipment_category', 'classification'],
            ],
            'location' => [
                'label'    => 'Location',
                'required' => true,
                'example'  => 'Main Campus • Building 3 • Room 301',
                'note'     => 'Where it is. A reporter reads this to confirm they are reporting the right unit.',
                'values'   => null,
                'aliases'  => ['room', 'place', 'building', 'site', 'area'],
            ],
            'unit' => [
                'label'    => 'Responsible Unit',
                'required' => true,
                'example'  => 'PMO',
                'note'     => 'Which office repairs it. This decides who sees the report.',
                'values'   => ['PMO', 'ITSO'],
                'aliases'  => ['office', 'responsible unit', 'responsible', 'owner'],
            ],
            'department' => [
                'label'    => 'Department',
                'required' => false,
                'example'  => 'College of Computer Studies',
                'note'     => 'The department that uses it, if it belongs to one.',
                'values'   => null,
                'aliases'  => ['dept', 'college', 'assigned department'],
            ],
            'brand' => [
                'label'    => 'Brand',
                'required' => false,
                'example'  => 'Carrier',
                'note'     => '',
                'values'   => null,
                'aliases'  => ['make', 'manufacturer'],
            ],
            'model' => [
                'label'    => 'Model',
                'required' => false,
                'example'  => 'FP-53CEA010',
                'note'     => '',
                'values'   => null,
                'aliases'  => ['model no', 'model number', 'serial', 'serial no'],
            ],
            'description' => [
                'label'    => 'Description',
                'required' => false,
                'example'  => '1.5 HP split type, wall mounted',
                'note'     => 'Anything that identifies this unit apart from others like it.',
                'values'   => null,
                'aliases'  => ['details', 'specs', 'specification'],
            ],
            'status' => [
                'label'    => 'Status',
                'required' => false,
                'example'  => 'operational',
                'note'     => 'Blank is treated as operational.',
                'values'   => ['operational', 'under_maintenance', 'faulty', 'retired'],
                'aliases'  => ['state', 'equipment status'],
            ],
            'condition' => [
                'label'    => 'Condition',
                'required' => false,
                'example'  => 'good',
                'note'     => 'Blank is treated as good.',
                'values'   => ['excellent', 'good', 'fair', 'poor'],
                'aliases'  => ['condition status', 'condition_status', 'physical condition'],
            ],
            'quantity' => [
                'label'    => 'Quantity',
                'required' => false,
                'example'  => '1',
                'note'     => 'Whole number. Blank is treated as 1. Use one row per unit where each has its own property number.',
                'values'   => null,
                'aliases'  => ['qty', 'count', 'counted', 'no of units'],
            ],
            'purchase_date' => [
                'label'    => 'Date Acquired',
                'required' => false,
                'example'  => '2025-08-14',
                'note'     => 'YYYY-MM-DD is safest. Day-first dates like 14/08/2025 are also read correctly.',
                'values'   => null,
                'aliases'  => ['acquired', 'date acquired', 'acquisition date', 'purchased', 'purchase date'],
            ],
            'purchase_cost' => [
                'label'    => 'Acquisition Cost',
                'required' => false,
                'example'  => '38500.00',
                'note'     => 'Numbers only. Peso signs and commas are stripped.',
                'values'   => null,
                'aliases'  => ['cost', 'price', 'amount', 'unit cost', 'acquisition cost'],
            ],
            'warranty_expiry' => [
                'label'    => 'Warranty Until',
                'required' => false,
                'example'  => '2027-08-14',
                'note'     => 'YYYY-MM-DD. Drives the Warranty Expiring counter on the inventory page.',
                'values'   => null,
                'aliases'  => ['warranty', 'warranty expiry', 'warranty end'],
            ],
            'supplier' => [
                'label'    => 'Supplier',
                'required' => false,
                'example'  => 'Batangas Aircon Supply Inc.',
                'note'     => '',
                'values'   => null,
                'aliases'  => ['vendor', 'supplier info', 'supplier_info', 'source'],
            ],
            'remarks' => [
                'label'    => 'Remarks',
                'required' => false,
                'example'  => 'Serviced Aug 2026',
                'note'     => '',
                'values'   => null,
                'aliases'  => ['notes', 'note', 'comment', 'comments'],
            ],
        ];
    }
}

if (!function_exists('becInventoryHeaderKey')) {
    /** Fold a header cell to something matchable: lowercase, letters and digits only. */
    function becInventoryHeaderKey(string $raw): string {
        $s = strtolower(trim($raw));
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', (string)$s));
    }
}

if (!function_exists('becInventoryTemplateRows')) {
    /** The header row and one worked example, shared by both template formats. */
    function becInventoryTemplateRows(): array {
        $cols = becInventoryColumns();
        $headers = [];
        $example = [];
        foreach ($cols as $spec) {
            $headers[] = $spec['label'] . ($spec['required'] ? ' *' : '');
            $example[] = $spec['example'];
        }
        return [$headers, $example];
    }
}

if (!function_exists('becInventoryTemplateNotes')) {
    /** The "what goes in each column" table that ships beside the headers. */
    function becInventoryTemplateNotes(): array {
        $rows = [];
        foreach (becInventoryColumns() as $spec) {
            $rows[] = [
                $spec['label'],
                $spec['required'] ? 'Required' : 'Optional',
                $spec['values'] ? implode(' / ', $spec['values']) : 'Free text',
                $spec['example'],
                $spec['note'],
            ];
        }
        return $rows;
    }
}

if (!function_exists('becInventorySendTemplate')) {
    /**
     * Stream the blank template. `$format` is 'xlsx' or 'csv'.
     *
     * The header row carries a trailing asterisk on required columns, and the
     * importer strips it — so the file the admin fills in is exactly the file
     * the importer expects, with no step in between where a person has to
     * remember something.
     */
    function becInventorySendTemplate(string $format = 'xlsx'): void {
        [$headers, $example] = becInventoryTemplateRows();

        if ($format === 'csv') {
            require_once __DIR__ . '/csv_export.php';
            $out = becCsvOpen('bec_inventory_template');
            becCsvLetterhead($out, 'Equipment Inventory Upload Template', [
                'How to use' => 'Replace the example row with your own. Keep the header row exactly as it is.',
            ]);
            becCsvRow($out, $headers);
            becCsvRow($out, $example);
            becCsvBlank($out);
            becCsvSection($out, 'What goes in each column',
                ['Column', 'Required?', 'Allowed values', 'Example', 'Notes'],
                becInventoryTemplateNotes());
            becCsvFooter($out, 'Delete these notes before uploading, or leave them — the importer reads the header row and ignores the rest.');
            fclose($out);
            exit();
        }

        // becBuildXlsx()'s summary block is a flat label => value map, so the
        // per-column guidance is folded into one line each rather than a second
        // table. It sits above the headers, where someone filling the sheet in
        // will actually read it.
        $guide = [];
        foreach (becInventoryColumns() as $spec) {
            $guide[$spec['label'] . ($spec['required'] ? ' *' : '')] =
                ($spec['required'] ? 'Required. ' : 'Optional. ')
                . ($spec['values'] ? 'One of: ' . implode(' / ', $spec['values']) . '. ' : '')
                . 'e.g. ' . $spec['example']
                . ($spec['note'] !== '' ? '  —  ' . $spec['note'] : '');
        }
        $bytes = becBuildXlsx(
            'Equipment Inventory Upload Template',
            $headers,
            [$example],
            $guide,
            ['How to use' => 'Replace the example row with your own rows. Keep the header row exactly as it is. Columns marked * must be filled.']
        );
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="bec_inventory_template_' . date('Y-m-d') . '.xlsx"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit();
    }
}

if (!function_exists('becInventoryNormalizeDate')) {
    /** A date the database will take, or '' if the cell is not a date at all. */
    function becInventoryNormalizeDate(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') { return ''; }
        // Excel keeps dates as a serial day count from 1899-12-30.
        if (preg_match('/^\d{5}(\.\d+)?$/', $raw)) {
            $ts = (int)round(((float)$raw - 25569) * 86400);
            return $ts > 0 ? gmdate('Y-m-d', $ts) : '';
        }
        /* Day-first, because that is how the office writes dates. PHP reads a
           slashed date as month-first, so 14/08/2025 simply failed and the
           acquisition date was silently dropped. Tried explicitly first; the
           genuinely ambiguous 01/02/2025 still resolves day-first, which is the
           local convention and is stated in the template. */
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'j/n/Y', 'd/m/y'] as $fmt) {
            $d = DateTime::createFromFormat($fmt . '|', $raw);
            if ($d instanceof DateTime && $d->format($fmt) === $raw) { return $d->format('Y-m-d'); }
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : '';
    }
}

if (!function_exists('becInventoryParseWorkbook')) {
    /**
     * Read an uploaded workbook into validated rows.
     *
     * Returns rows that are ready to write and, separately, every row that was
     * refused with the reason why. A silent partial import is the thing to
     * avoid here: an office uploading 1,300 assets needs to know which six did
     * not land and why, not a number.
     *
     * @return array{ok:bool,message:string,mode:string,rows:array,errors:array,
     *               sheet:string,matched:array,ignored:array}
     */
    function becInventoryParseWorkbook(string $path): array {
        $out = ['ok' => false, 'message' => '', 'mode' => 'columns', 'rows' => [],
                'errors' => [], 'sheet' => '', 'matched' => [], 'ignored' => []];

        $sheets = becXlsxSheets($path);
        if (!$sheets) {
            $out['message'] = 'That file could not be read as an Excel workbook. Save it as .xlsx and try again.';
            return $out;
        }

        $cols = becInventoryColumns();
        // header key => canonical field
        $lookup = [];
        foreach ($cols as $field => $spec) {
            $lookup[becInventoryHeaderKey($spec['label'])] = $field;
            $lookup[becInventoryHeaderKey($field)] = $field;
            foreach (($spec['aliases'] ?? []) as $a) { $lookup[becInventoryHeaderKey($a)] = $field; }
        }

        // Find the header row: the row, on any sheet, that names the most columns
        // we recognise. Not row 1 and not a fixed window — a real workbook opens
        // with a letterhead and a summary block, and this system's own template
        // carries seventeen lines of guidance above the headings. Scanning a
        // hundred rows covers all of that; data rows never score, because they
        // hold values rather than column names.
        $bestSheet = ''; $bestRow = 0; $bestMap = []; $bestScore = 0; $bestIgnored = [];
        foreach ($sheets as $sheetName => $grid) {
            $seen = 0;
            foreach ($grid as $rowNo => $cells) {
                if (++$seen > 100) { break; }
                $map = []; $ignored = [];
                foreach ($cells as $colIdx => $text) {
                    $key = becInventoryHeaderKey(rtrim((string)$text, " *"));
                    if ($key === '') { continue; }
                    if (isset($lookup[$key]) && !in_array($lookup[$key], $map, true)) {
                        $map[$colIdx] = $lookup[$key];
                    } else {
                        $ignored[] = trim((string)$text);
                    }
                }
                if (count($map) > $bestScore) {
                    $bestScore = count($map); $bestSheet = $sheetName;
                    $bestRow = $rowNo; $bestMap = $map; $bestIgnored = $ignored;
                }
            }
        }

        if ($bestScore < 3) {
            $out['mode'] = 'legacy';
            $out['message'] = 'No column headings were recognised in that workbook.';
            return $out;
        }

        $missing = [];
        foreach ($cols as $field => $spec) {
            if ($spec['required'] && !in_array($field, $bestMap, true)) { $missing[] = $spec['label']; }
        }
        if ($missing) {
            $out['message'] = 'That sheet is missing required column(s): ' . implode(', ', $missing)
                            . '. Download the template to see the expected headings.';
            return $out;
        }

        $out['sheet']   = $bestSheet;
        $out['matched'] = array_values($bestMap);
        $out['ignored'] = array_values(array_unique(array_filter($bestIgnored)));

        $grid = $sheets[$bestSheet];
        $seenTags = [];
        $prevRow  = $bestRow;
        foreach ($grid as $rowNo => $cells) {
            if ($rowNo <= $bestRow) { continue; }

            /* The table ends at the first empty row — the spreadsheet
               convention, and the only rule that survives contact with real
               workbooks. Below that blank line sits whatever the office keeps
               there: a signature block, a footer, a note. This system's own
               template ends with "Prepared by / Noted by / Approved by", four
               populated cells that would otherwise be read as an item and
               rejected, so the file we hand someone would greet them with
               errors on import. Rows are absent from the grid when blank, so a
               gap in the numbering is the blank line. */
            if ($rowNo > $prevRow + 1 && ($out['rows'] || $out['errors'])) { break; }
            $prevRow = $rowNo;

            $rec = [];
            $filled = 0;
            foreach ($bestMap as $colIdx => $field) {
                $rec[$field] = trim((string)($cells[$colIdx] ?? ''));
                if ($rec[$field] !== '') { $filled++; }
            }
            /* A row that fills fewer than two of the mapped columns is
               structure, not data: a spacer, a footer, a note somebody typed
               under the table. A genuine item always carries at least a
               property number and a name. Without this the template's own
               "Confidential — for authorized administrative use only" footer
               came back as three rejected rows, which is a confusing way to
               greet someone importing the file we just handed them. */
            if ($filled < 2) { continue; }

            $why = [];
            $tag = strtoupper(preg_replace('/\s+/', '', $rec['property_no'] ?? ''));
            if ($tag === '')                                  { $why[] = 'no property number'; }
            elseif (!preg_match('/^[A-Z0-9][A-Z0-9\-_\/]{1,48}$/', $tag)) { $why[] = 'property number has characters that cannot be used'; }
            elseif (isset($seenTags[$tag]))                   { $why[] = 'property number ' . $tag . ' appears earlier in this file (row ' . $seenTags[$tag] . ')'; }
            if (trim((string)($rec['equipment_name'] ?? '')) === '') { $why[] = 'no equipment name'; }
            if (trim((string)($rec['category'] ?? '')) === '')       { $why[] = 'no category'; }
            if (trim((string)($rec['location'] ?? '')) === '')       { $why[] = 'no location'; }

            $unit = strtoupper(trim((string)($rec['unit'] ?? '')));
            if ($unit === '')                              { $why[] = 'no responsible unit (PMO or ITSO)'; }
            elseif (!in_array($unit, ['PMO', 'ITSO'], true)) { $why[] = 'responsible unit must be PMO or ITSO, not "' . $rec['unit'] . '"'; }

            $status = strtolower(str_replace([' ', '-'], '_', trim((string)($rec['status'] ?? ''))));
            if ($status === '') { $status = 'operational'; }
            if (!in_array($status, $cols['status']['values'], true)) {
                $why[] = 'status must be one of ' . implode(', ', $cols['status']['values']) . ' (found "' . $rec['status'] . '")';
            }

            $cond = strtolower(trim((string)($rec['condition'] ?? '')));
            if ($cond === '') { $cond = 'good'; }
            if (!in_array($cond, $cols['condition']['values'], true)) {
                $why[] = 'condition must be one of ' . implode(', ', $cols['condition']['values']) . ' (found "' . $rec['condition'] . '")';
            }

            $qtyRaw = trim((string)($rec['quantity'] ?? ''));
            $qty = $qtyRaw === '' ? 1 : (int)round((float)str_replace(',', '', $qtyRaw));
            if ($qtyRaw !== '' && (!is_numeric(str_replace(',', '', $qtyRaw)) || $qty < 1)) {
                $why[] = 'quantity must be a whole number of 1 or more (found "' . $qtyRaw . '")';
            }

            // A date that cannot be read is worth saying so about. Dropping it
            // silently means an asset quietly loses its acquisition date and
            // nobody finds out until the depreciation report is wrong.
            $acq = becInventoryNormalizeDate((string)($rec['purchase_date'] ?? ''));
            $war = becInventoryNormalizeDate((string)($rec['warranty_expiry'] ?? ''));
            if (trim((string)($rec['purchase_date'] ?? '')) !== '' && $acq === '') {
                $why[] = 'date acquired could not be read (found "' . $rec['purchase_date'] . '") — use YYYY-MM-DD';
            }
            if (trim((string)($rec['warranty_expiry'] ?? '')) !== '' && $war === '') {
                $why[] = 'warranty date could not be read (found "' . $rec['warranty_expiry'] . '") — use YYYY-MM-DD';
            }

            if ($why) {
                $out['errors'][] = ['row' => $rowNo, 'tag' => $tag, 'why' => implode('; ', $why)];
                continue;
            }

            $seenTags[$tag] = $rowNo;
            $out['rows'][] = [
                'row'             => $rowNo,
                'property_no'     => $tag,
                'equipment_name'  => $rec['equipment_name'],
                'category'        => $rec['category'],
                'location'        => $rec['location'],
                'unit'            => $unit,
                'department'      => $rec['department'] ?? '',
                'brand'           => $rec['brand'] ?? '',
                'model'           => $rec['model'] ?? '',
                'description'     => $rec['description'] ?? '',
                'status'          => $status,
                'condition'       => $cond,
                'quantity'        => $qty,
                'purchase_date'   => $acq,
                'purchase_cost'   => preg_replace('/[^0-9.]/', '', (string)($rec['purchase_cost'] ?? '')),
                'warranty_expiry' => $war,
                'supplier'        => $rec['supplier'] ?? '',
                'remarks'         => $rec['remarks'] ?? '',
            ];
        }

        if (!$out['rows'] && !$out['errors']) {
            $out['message'] = 'The headings were recognised but the sheet has no data rows under them.';
            return $out;
        }

        $out['ok'] = true;
        $out['message'] = count($out['rows']) . ' row(s) ready, ' . count($out['errors']) . ' rejected.';
        return $out;
    }
}

if (!function_exists('becInventoryClassify')) {
    /**
     * Split parsed rows into what would be added and what would be changed.
     * One query, not one per row — this list runs to thousands.
     */
    function becInventoryClassify(array $rows): array {
        $existing = [];
        try {
            $pdo = getPgsqlPdoConnection();
            foreach ($pdo->query('SELECT equipment_id FROM public.equipment')->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $existing[strtoupper((string)$id)] = true;
            }
        } catch (Throwable $e) { error_log('becInventoryClassify: ' . $e->getMessage()); }

        $new = 0; $upd = 0;
        foreach ($rows as $r) {
            if (isset($existing[strtoupper($r['property_no'])])) { $upd++; } else { $new++; }
        }
        return ['new' => $new, 'updated' => $upd];
    }
}

if (!function_exists('becInventoryApply')) {
    /**
     * Write the parsed rows into `equipment`, keyed on the property number.
     *
     * An UPSERT rather than a replace: an office correcting six locations
     * re-uploads its sheet and gets six changes, not 1,329 deletions and 1,329
     * inserts. Runs in one transaction, so a failure halfway leaves nothing
     * behind.
     *
     * The duplicated schema columns are written together — category and
     * equipment_category, condition_status and condition — so whichever one a
     * given page happens to read, it sees the same answer.
     *
     * @return array{ok:bool,message:string,new:int,updated:int}
     */
    function becInventoryApply(array $rows): array {
        if (!$rows) { return ['ok' => false, 'message' => 'Nothing to import.', 'new' => 0, 'updated' => 0]; }

        $counts = becInventoryClassify($rows);
        $pdo = getPgsqlPdoConnection();

        $have = [];
        foreach ($pdo->query("SELECT column_name FROM information_schema.columns
                               WHERE table_schema='public' AND table_name='equipment'")->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $have[(string)$c] = true;
        }

        // canonical field => the schema columns it fills
        $writes = [
            'equipment_id'       => 'property_no',
            'asset_tag'          => 'property_no',
            'equipment_name'     => 'equipment_name',
            'category'           => 'category',
            'equipment_category' => 'category',
            'location'           => 'location',
            'unit'               => 'unit',
            'department'         => 'department',
            'brand'              => 'brand',
            'model'              => 'model',
            'description'        => 'description',
            'status'             => 'status',
            'condition_status'   => 'condition',
            'condition'          => 'condition',
            'quantity'           => 'quantity',
            'purchase_date'      => 'purchase_date',
            'acquired'           => 'purchase_date',
            'purchase_cost'      => 'purchase_cost',
            'warranty_expiry'    => 'warranty_expiry',
            'supplier_info'      => 'supplier',
            'remarks'            => 'remarks',
            'notes'              => 'remarks',
        ];
        $writes = array_filter($writes, static fn($f, $col) => isset($have[$col]), ARRAY_FILTER_USE_BOTH);

        $colNames = array_keys($writes);
        $ph = array_map(static fn($c) => ':' . $c, $colNames);
        $updates = [];
        foreach ($colNames as $c) {
            if ($c === 'equipment_id') { continue; }
            $updates[] = '"' . $c . '" = EXCLUDED."' . $c . '"';
        }
        if (isset($have['updated_at'])) { $updates[] = '"updated_at" = now()'; }

        $sql = 'INSERT INTO public.equipment ("' . implode('","', $colNames) . '") VALUES (' . implode(',', $ph) . ') '
             . 'ON CONFLICT ("equipment_id") DO UPDATE SET ' . implode(', ', $updates);

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare($sql);
            foreach ($rows as $r) {
                $params = [];
                foreach ($writes as $col => $field) {
                    $v = $r[$field] ?? '';
                    // Empty is absence, not the string "": a blank date column
                    // must be NULL or Postgres refuses it outright.
                    if ($v === '' && in_array($col, ['purchase_date', 'acquired', 'warranty_expiry', 'purchase_cost'], true)) { $v = null; }
                    $params[$col] = $v;
                }
                $stmt->execute($params);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('becInventoryApply failed: ' . $e->getMessage());
            return ['ok' => false, 'new' => 0, 'updated' => 0,
                    'message' => 'The import was rolled back and nothing was changed. The database refused a row: ' . $e->getMessage()];
        }

        return [
            'ok'      => true,
            'new'     => $counts['new'],
            'updated' => $counts['updated'],
            'message' => 'Imported ' . number_format(count($rows)) . ' item(s) — '
                       . number_format($counts['new']) . ' added, ' . number_format($counts['updated']) . ' updated.',
        ];
    }
}

if (!function_exists('becInventoryPendingDir')) {
    /** Where an upload waits between its preview and its confirmation. */
    function becInventoryPendingDir(): string {
        $dir = dirname(__DIR__) . '/data/inventory_pending';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        // Anything left by an admin who walked away mid-preview.
        foreach (glob($dir . '/*') ?: [] as $old) {
            if (is_file($old) && filemtime($old) < time() - 3600) { @unlink($old); }
        }
        return $dir;
    }
}
