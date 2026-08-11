<?php
/**
 * BEC official directory helper (#1 — user verification).
 * Imports the official BEC user list (CSV; .xlsx supported only if the PHP zip
 * extension is available) and verifies reporter emails against it.
 */
require_once __DIR__ . '/../config/database.php';

/** Map varied header labels to canonical field names. */
function becdir_canon_header(string $h): ?string {
    // Excel's "CSV UTF-8" writes a byte-order mark in front of the very first
    // cell. It survives trim(), so "Full Name" arrived as "\xEF\xBB\xBFFull
    // Name", matched nothing, and every imported name came out blank while the
    // import still reported success.
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    // Slashes are separators here too. The registrar's enrolment export heads
    // its columns "College/Program" and "Program/Qualifications"; without the
    // slash in this class neither matched anything, and both columns — along
    // with every reporter's course — were silently dropped on import.
    $k = strtolower(trim(preg_replace('/[\s_\-\.\/]+/', ' ', $h)));
    $map = [
        'full name' => 'full_name', 'fullname' => 'full_name', 'name' => 'full_name', 'complete name' => 'full_name',
        'email' => 'email', 'bec email' => 'email', 'email address' => 'email', 'bec email address' => 'email', 'school email' => 'email',
        'employee number' => 'employee_number', 'employee no' => 'employee_number', 'emp no' => 'employee_number', 'employee id' => 'employee_number',
        'student number' => 'student_number', 'student no' => 'student_number', 'student id' => 'student_number', 'sr code' => 'student_number',
        'department' => 'department', 'dept' => 'department', 'office' => 'department',
        'college' => 'department', 'college program' => 'department', 'academic unit' => 'department',
        'program' => 'program', 'course' => 'program', 'strand' => 'program',
        'program qualifications' => 'program', 'qualification' => 'program', 'qualifications' => 'program',
        'year level' => 'year_level', 'yearlevel' => 'year_level', 'grade level' => 'year_level',
        'year' => 'year_level', 'level' => 'year_level', 'year grade level' => 'year_level',
        'user type' => 'user_type', 'type' => 'user_type', 'role' => 'user_type', 'category' => 'user_type', 'user role' => 'user_type',
        // HR lists name the job rather than the affiliation. It is the only
        // reliable way to tell a teacher from an office clerk when neither the
        // registrar's "User Type" nor a year level is present.
        'position' => 'position', 'designation' => 'position', 'job title' => 'position',
        'title' => 'position', 'rank' => 'position', 'plantilla position' => 'position',
        // official letterhead export combines these into one column ("BSIT / Student")
        'program user role' => 'program_role', 'program role' => 'program_role', 'course role' => 'program_role',
    ];
    return $map[$k] ?? null;
}

/**
 * Repair a mis-decoded string.
 *
 * A UTF-8 export opened as Windows-1252 and saved again turns "ñ" into "Ã±".
 * Reversing the second encoding gives the original bytes back. Left alone the
 * damage reaches the addresses themselves ("bañez" → "baÃ±ez"), and those rows
 * fail validation and disappear.
 */
function becdir_fix_mojibake(string $s): string {
    if ($s === '' || !preg_match('/Ã[\x80-\xBF]|Â[\x80-\xBF]/u', $s)) { return $s; }
    $repaired = @mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    return ($repaired !== false && $repaired !== '' && mb_check_encoding($repaired, 'UTF-8')) ? $repaired : $s;
}

/** Fold accented Latin letters down to ASCII (ñ→n, á→a). */
function becdir_deaccent(string $s): string {
    $from = ['á','à','â','ä','ã','å','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','ö','õ','ú','ù','û','ü','ñ','ç','Á','À','Â','Ä','Ã','Å','É','È','Ê','Ë','Í','Ì','Î','Ï','Ó','Ò','Ô','Ö','Õ','Ú','Ù','Û','Ü','Ñ','Ç'];
    $to   = ['a','a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','n','c','A','A','A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U','N','C'];
    return str_replace($from, $to, $s);
}

/**
 * Normalise an address from the registrar's export into one that can actually
 * receive mail, or '' if it cannot be salvaged.
 *
 * Two faults account for nearly every address the importer used to drop, and
 * both come from the export rather than from the mailbox really being unusable:
 *
 *   "sherwin.jr..cueto@bec.edu.ph"   — a name ending in "Jr." joined to the
 *                                      surname leaves a doubled dot, which is
 *                                      invalid in the local part.
 *   "xamantha.roxette.bañez@…"       — the tilde survived into the address.
 *
 * Both were rejected by filter_var() and skipped with no message, so the
 * student was simply told later that they are not in the official directory.
 */
function becdir_clean_email(string $raw): string {
    $e = strtolower(trim(becdir_deaccent(becdir_fix_mojibake($raw))));
    if ($e === '') { return ''; }
    $e = preg_replace('/\s+/', '', $e);
    $at = strrpos($e, '@');
    if ($at === false) { return ''; }
    $local  = substr($e, 0, $at);
    $domain = substr($e, $at + 1);
    // Drop anything still outside the addressable set, then repair the dots.
    $local  = preg_replace('/[^a-z0-9._%+\-]/', '', $local);
    $local  = trim(preg_replace('/\.{2,}/', '.', $local), '.');
    $domain = trim(preg_replace('/\.{2,}/', '.', preg_replace('/[^a-z0-9.\-]/', '', $domain)), '.');
    if ($local === '' || $domain === '' || strpos($domain, '.') === false) { return ''; }
    $e = $local . '@' . $domain;
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}

/**
 * Split "Grade 11 - STEM" into the standing and whatever follows it.
 * The suffix is a strand for Senior High and a section name ("Diamond") lower
 * down, so it is only adopted as the programme when no programme was given.
 */
function becdir_split_year_level(string $raw): array {
    $raw = trim(preg_replace('/\s+/', ' ', $raw));
    if ($raw === '') { return ['', '']; }
    $parts = preg_split('/\s+-\s+/', $raw, 2);
    return [trim($parts[0]), trim($parts[1] ?? '')];
}

/**
 * Decide whether a row is a student, faculty or staff member.
 *
 * The registrar's enrolment export carries no "User Type" column at all, and an
 * HR list of employees carries neither that nor a year level — so before this,
 * every faculty and staff row imported with a blank affiliation. Blank is read
 * as "student" by the counts and filters, which meant uploading the teaching
 * roster would have silently inflated the student total and still left Faculty
 * reading zero.
 *
 * Order matters: what a person *does* outranks the office they sit in, so a
 * janitor assigned to Senior High School is staff, not faculty.
 */
function becdir_infer_user_type(
    string $explicit, string $position, string $department,
    string $yearLevel, string $studentNo, string $employeeNo
): string {
    $t = becdir_canon_type($explicit);
    if ($t !== '') { return $t; }

    // Anyone with a standing or a student number is enrolled.
    if ($yearLevel !== '' || $studentNo !== '') { return 'student'; }

    $p = strtolower(trim($position));
    if ($p !== '') {
        // Job titles that mean the person teaches. "Dean" and "Principal" are
        // included: they hold teaching appointments and are counted as faculty.
        if (preg_match('/\b(teacher|instructor|professor|faculty|lecturer|dean|principal|department head|academic head|adviser|professorial)\b/', $p)) {
            return 'faculty';
        }
        return 'staff';   // named a job, and it is not a teaching one
    }

    // No job title. An academic unit implies teaching; anything else is an office.
    if (preg_match('/\b(college of|senior high|junior high|grade school|pre-?school|elementary|technical-?vocational|academy|department of)\b/i', $department)) {
        return 'faculty';
    }
    if ($employeeNo !== '' || trim($department) !== '') { return 'staff'; }

    return '';   // nothing to go on — reported back to the admin rather than guessed
}

/**
 * Rank a year level the way a school does, so pickers read Nursery → 4th Year.
 * Sorting these as text puts "Grade 10" before "Grade 7" and "1st Year" before
 * "Nursery 1", which makes a dropdown of 19 levels almost unusable.
 */
function becdir_year_level_rank(string $level): int {
    $l = strtolower(trim($level));
    if ($l === '') return 9999;
    if (preg_match('/nursery\s*(\d+)/', $l, $m)) return 100 + (int)$m[1];
    if (str_contains($l, 'nursery'))             return 100;
    if (str_contains($l, 'kinder') || str_contains($l, 'prep')) return 150;
    if (preg_match('/grade\s*(\d{1,2})/', $l, $m)) return 200 + (int)$m[1];
    $ordinals = ['1st' => 1, '2nd' => 2, '3rd' => 3, '4th' => 4, '5th' => 5, '6th' => 6];
    foreach ($ordinals as $word => $n) {
        if (str_starts_with($l, $word)) return 300 + $n;
    }
    return 900;
}

/** Year levels in school order; unknown values keep a stable alphabetical tail. */
function becdir_sort_year_levels(array $levels): array {
    usort($levels, static function ($a, $b) {
        $ra = becdir_year_level_rank((string)$a);
        $rb = becdir_year_level_rank((string)$b);
        return $ra === $rb ? strcasecmp((string)$a, (string)$b) : $ra <=> $rb;
    });
    return array_values($levels);
}

/**
 * Work out the BEC department from the year level and programme.
 *
 * The enrolment export names no department at all — it states a year level and
 * a programme and leaves the academic unit implied. These are the same
 * department names the reporter form offers ($becPrograms in
 * student_dashboard.php), so a matched reporter lands on a valid option.
 */
function becdir_infer_department(string $yearLevel, string $program): string {
    $y = strtolower(trim($yearLevel));
    $p = strtolower(trim($program));
    if ($y === '') { return ''; }

    if (str_contains($y, 'nursery') || str_contains($y, 'kinder') || str_contains($y, 'pre-school')) {
        return 'Pre-School';
    }
    if (preg_match('/grade\s*(\d{1,2})/', $y, $m)) {
        $g = (int)$m[1];
        if ($g >= 1  && $g <= 6)  { return 'Grade School'; }
        if ($g >= 7  && $g <= 10) { return 'Junior High School'; }
        if ($g >= 11 && $g <= 12) { return 'Senior High School'; }
    }
    if (preg_match('/(1st|2nd|3rd|4th|5th)\s*year/', $y)) {
        // Ladderised TESDA qualifications sit under the Tech-Voc Center; a
        // degree belongs to the college that grants it.
        if (preg_match('/\bnc\s*[ivx]+\b/', $p) || str_contains($p, 'diploma in hospitality')
            || str_contains($p, 'bartending') || str_contains($p, 'cookery')
            || str_contains($p, 'housekeeping') || str_contains($p, 'front office')
            || str_contains($p, 'food and beverage') || str_contains($p, 'visual graphics')) {
            return 'Technical-Vocational Center';
        }
        if (str_contains($p, 'elementary education') || str_contains($p, 'secondary education')
            || str_contains($p, 'teacher certificate')) {
            return 'College of Teacher Education';
        }
        // Business is tested first on purpose: "Accounting Information System"
        // is an accountancy degree, and matching "information system" ahead of
        // it would file every AIS student under Computer Studies.
        if (str_contains($p, 'business') || str_contains($p, 'accountancy')
            || str_contains($p, 'accounting') || str_contains($p, 'entrepreneur')) {
            return 'College of Business';
        }
        if (str_contains($p, 'information system') || str_contains($p, 'computer')
            || str_contains($p, 'information technology')) {
            return 'College of Computer Studies';
        }
        return '';  // a degree we do not recognise — leave it for the reporter to pick
    }
    return '';
}

/** Normalise user_type to student/faculty/staff. */
function becdir_canon_type(string $t): string {
    $t = strtolower(trim($t));
    if (str_starts_with($t, 'stud')) return 'student';
    if (str_starts_with($t, 'fac') || str_contains($t, 'teacher') || str_contains($t, 'instructor')) return 'faculty';
    if ($t !== '') return 'staff';
    return '';
}

/**
 * Parse an uploaded directory file into rows of canonical fields.
 * Returns ['rows'=>array, 'error'=>string].
 */
function becdir_parse_file(string $tmpPath, string $origName): array {
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $records = [];

    if ($ext === 'xlsx') {
        $records = becdir_read_xlsx($tmpPath);
        if ($records === null) return ['rows' => [], 'error' => 'Could not read that .xlsx file. Please re-save it or export as CSV.'];
    } elseif (in_array($ext, ['csv', 'txt'], true)) {
        $fh = fopen($tmpPath, 'r');
        if (!$fh) return ['rows' => [], 'error' => 'Could not open the uploaded file.'];
        // Detect delimiter from first line
        $first = fgets($fh);
        $delim = (substr_count($first, ';') > substr_count($first, ',')) ? ';' : ((substr_count($first, "\t") > substr_count($first, ',')) ? "\t" : ',');
        rewind($fh);
        while (($row = fgetcsv($fh, 0, $delim)) !== false) { $records[] = $row; }
        fclose($fh);
    } else {
        return ['rows' => [], 'error' => 'Unsupported file type. Please upload a .csv file (or .xlsx if supported).'];
    }

    if (!$records) return ['rows' => [], 'error' => 'The file appears to be empty.'];

    // Find the header row. Official letterhead exports have title rows above it
    // ("BATANGAS EASTERN COLLEGES", "Property Management Office", …), so scan the
    // first rows for the one that actually contains an Email column.
    //
    // 40, not 15: this system's own exports now carry a full letterhead plus an
    // executive-summary block, which puts the User List header on line 17. At 15
    // a list exported from admin_users.php and uploaded straight back was
    // rejected as having no Email column. The scan only tests labels, so a
    // larger window costs nothing.
    $colMap = [];
    $headerRow = 0;
    $scan = min(count($records), 40);
    for ($i = 0; $i < $scan; $i++) {
        $probe = [];
        foreach ($records[$i] as $j => $label) {
            $canon = becdir_canon_header((string)$label);
            if ($canon) $probe[$canon] = $j;
        }
        if (isset($probe['email'])) { $colMap = $probe; $headerRow = $i; $records = array_slice($records, $i + 1); break; }
    }
    if (!isset($colMap['email'])) {
        return ['rows' => [], 'error' => 'No "Email" column was found. Required headers include: Full Name, Email, Employee Number, Student Number, Department, Program, User Type (letterhead rows above the header are fine).'];
    }

    $rows = [];
    $skipped = [];   // rows that carry no usable address, with the reason
    $repaired = [];  // addresses accepted only after being cleaned up
    $untyped  = [];  // rows whose affiliation could not be worked out
    $seen = [];      // address => list of names, to catch one mailbox shared by many

    foreach ($records as $i => $r) {
        // A blank trailing line is not a row anyone needs to be told about.
        if (!array_filter(array_map('trim', array_map('strval', $r)), 'strlen')) { continue; }
        $get = static fn($key) => isset($colMap[$key]) ? trim((string)($r[$colMap[$key]] ?? '')) : '';
        $line = $i + $headerRow + 2;   // 1-based line number in the uploaded file
        $name = becdir_fix_mojibake($get('full_name'));
        $rawEmail = $get('email');
        $email = becdir_clean_email($rawEmail);

        if ($email === '') {
            $skipped[] = [
                'line'   => $line,
                'name'   => $name,
                'email'  => $rawEmail,
                'reason' => trim($rawEmail) === '' ? 'No email address on this row'
                                                   : 'Not a usable email address',
            ];
            continue;
        }
        if (strtolower(trim($rawEmail)) !== $email) {
            $repaired[] = ['line' => $line, 'name' => $name, 'from' => trim($rawEmail), 'to' => $email];
        }
        $seen[$email][] = $name;

        $program = becdir_fix_mojibake($get('program'));
        $type    = becdir_canon_type($get('user_type'));
        // combined "PROGRAM / USER ROLE" column, e.g. "BSIT / Student"
        if (isset($colMap['program_role']) && ($pr = $get('program_role')) !== '') {
            $bits = array_map('trim', explode('/', $pr, 2));
            if ($program === '') $program = $bits[0];
            if ($type === '' && isset($bits[1])) $type = becdir_canon_type($bits[1]);
        }

        // "Grade 11 - STEM" carries the standing and the strand in one cell.
        [$yearLevel, $suffix] = becdir_split_year_level($get('year_level'));
        if ($program === '' && $suffix !== '' && !preg_match('/^(diamond|ruby|emerald|pearl|sapphire|gold|silver)$/i', $suffix)) {
            $program = $suffix;   // a strand, not a section name
        }

        $department = becdir_fix_mojibake($get('department'));
        if ($department === '') { $department = becdir_infer_department($yearLevel, $program); }

        if ($type === '') {
            $type = becdir_infer_user_type(
                '', $get('position'), $department,
                $yearLevel, $get('student_number'), $get('employee_number')
            );
        }
        if ($type === '') {
            $untyped[] = ['line' => $line, 'name' => $name, 'email' => $email];
        }

        $rows[] = [
            'full_name'       => $name,
            'email'           => $email,
            'employee_number' => $get('employee_number'),
            'student_number'  => $get('student_number'),
            'department'      => $department,
            'program'         => $program,
            'year_level'      => $yearLevel,
            'user_type'       => $type,
        ];
    }

    // One address on many people is the worst thing that can be in this file:
    // the upsert keys on email, so every earlier person is overwritten, and
    // whoever signs in with it becomes whichever name landed last. The import
    // still proceeds — the admin is told exactly which addresses to send back
    // to the registrar.
    $shared = [];
    foreach ($seen as $em => $names) {
        if (count($names) > 1) { $shared[] = ['email' => $em, 'count' => count($names), 'names' => $names]; }
    }
    usort($shared, static fn($a, $b) => $b['count'] <=> $a['count']);

    if (!$rows) return ['rows' => [], 'error' => 'No valid rows with email addresses were found.', 'skipped' => $skipped, 'repaired' => [], 'shared' => [], 'untyped' => []];
    return ['rows' => $rows, 'error' => '', 'skipped' => $skipped, 'repaired' => $repaired, 'shared' => $shared, 'untyped' => $untyped];
}

/** Read one entry out of a ZIP archive: ZipArchive when available, else a
 *  pure-PHP central-directory parser (needs only zlib for deflated entries). */
function becdir_zip_entry(string $path, string $entry): ?string {
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return null;
        $data = $zip->getFromName($entry);
        $zip->close();
        return $data === false ? null : $data;
    }
    $data = file_get_contents($path);
    if ($data === false) return null;
    $eocd = strrpos($data, "PK\x05\x06");             // end-of-central-directory record
    if ($eocd === false) return null;
    $count = unpack('v', substr($data, $eocd + 10, 2))[1];
    $p     = unpack('V', substr($data, $eocd + 16, 4))[1];
    for ($i = 0; $i < $count; $i++) {
        if (substr($data, $p, 4) !== "PK\x01\x02") break;
        $method = unpack('v', substr($data, $p + 10, 2))[1];
        $csize  = unpack('V', substr($data, $p + 20, 4))[1];
        $nlen   = unpack('v', substr($data, $p + 28, 2))[1];
        $elen   = unpack('v', substr($data, $p + 30, 2))[1];
        $clen   = unpack('v', substr($data, $p + 32, 2))[1];
        $off    = unpack('V', substr($data, $p + 42, 4))[1];
        if (substr($data, $p + 46, $nlen) === $entry) {
            if (substr($data, $off, 4) !== "PK\x03\x04") return null;
            $lnlen = unpack('v', substr($data, $off + 26, 2))[1];
            $lelen = unpack('v', substr($data, $off + 28, 2))[1];
            $raw = substr($data, $off + 30 + $lnlen + $lelen, $csize);
            if ($method === 0) return $raw;                       // stored
            if ($method === 8) { $out = @gzinflate($raw); return $out === false ? null : $out; } // deflated
            return null;
        }
        $p += 46 + $nlen + $elen + $clen;
    }
    return null;
}

/** Minimal XLSX reader (works with or without ext-zip). Returns array of rows or null. */
function becdir_read_xlsx(string $path): ?array {
    $shared = [];
    if (($ss = becdir_zip_entry($path, 'xl/sharedStrings.xml')) !== null) {
        $xml = @simplexml_load_string($ss);
        if ($xml) { foreach ($xml->si as $si) { $shared[] = (string)($si->t ?? implode('', (array)$si->xpath('.//t'))); } }
    }
    $sheet = becdir_zip_entry($path, 'xl/worksheets/sheet1.xml');
    if ($sheet === null) return null;
    $xml = @simplexml_load_string($sheet);
    if (!$xml) return null;
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r']; $col = preg_replace('/\d+/', '', $ref);
            $idx = 0; $len = strlen($col);
            for ($i = 0; $i < $len; $i++) { $idx = $idx * 26 + (ord($col[$i]) - 64); }
            $idx--;
            $t = (string)$c['t'];
            if ($t === 'inlineStr') { $v = (string)($c->is->t ?? ''); }
            else {
                $v = (string)$c->v;
                if ($t === 's') { $v = $shared[(int)$v] ?? ''; }
            }
            $cells[$idx] = $v;
        }
        if ($cells) {
            // rebuild by real column index — Excel omits empty cells, and a
            // union+array_values would shift later columns into the gaps
            $out = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $k => $v) { $out[$k] = $v; }
            $rows[] = $out;
        }
    }
    return $rows;
}

/** Upsert directory rows. Returns ['inserted'=>, 'updated'=>, 'total'=>]. */
function becdir_upsert(array $rows): array {
    $pdo = getPgsqlPdoConnection();
    $ins = 0; $upd = 0;

    // year_level arrives with scripts/2026_08_directory_year_level.sql. Importing
    // must not fail on an installation where that has not been run yet.
    $cols = getTableColumns('bec_directory');
    $hasYear = isset($cols['year_level']);

    // Collapse rows that repeat an address before they reach the database.
    // Postgres refuses an ON CONFLICT statement that would touch the same row
    // twice, and the roster does contain one mailbox shared by many students —
    // so without this a real import fails outright. Last occurrence wins, which
    // is what row-by-row upserting did anyway; becdir_parse_file() reports the
    // shared addresses so the admin can have them corrected at source.
    $unique = [];
    foreach ($rows as $r) { $unique[strtolower($r['email'])] = $r; }
    $rows = array_values($unique);

    $cols = ['full_name','email','employee_number','student_number','department','program','user_type'];
    if ($hasYear) { $cols[] = 'year_level'; }
    $updates = [];
    foreach ($cols as $c) { if ($c !== 'email') { $updates[] = "$c=EXCLUDED.$c"; } }
    $updates[] = 'imported_at=now()';

    // Sending one row at a time meant one network round trip per person: a full
    // college roster took minutes and ran past the request limit, leaving the
    // directory half written. A batch is a single statement, and a transaction
    // per batch means a failure rolls back cleanly instead of part-importing.
    $batchSize = 500;
    foreach (array_chunk($rows, $batchSize) as $bi => $batch) {
        $tuples = []; $params = [];
        foreach ($batch as $ri => $r) {
            $names = [];
            foreach ($cols as $c) {
                $ph = ":{$c}_{$ri}";
                $names[] = $ph;
                $params[substr($ph, 1)] = (string)($r[$c] ?? '');
            }
            $tuples[] = '(' . implode(',', $names) . ', now())';
        }
        $sql = "INSERT INTO public.bec_directory (" . implode(',', $cols) . ",imported_at) VALUES "
             . implode(',', $tuples)
             . " ON CONFLICT (email) DO UPDATE SET " . implode(', ', $updates)
             . " RETURNING (xmax = 0) AS inserted";

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $res) {
                if (!empty($res['inserted'])) { $ins++; } else { $upd++; }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }
    return ['inserted' => $ins, 'updated' => $upd, 'total' => $ins + $upd];
}

function becdir_count(): int {
    try { return (int) getPgsqlPdoConnection()->query("SELECT COUNT(*) FROM public.bec_directory")->fetchColumn(); }
    catch (\Throwable $e) { return 0; }
}

/** True if the email is in the official directory. */
function becdir_email_exists(string $email): bool {
    $email = strtolower(trim($email));
    if ($email === '') return false;
    try {
        $st = getPgsqlPdoConnection()->prepare("SELECT 1 FROM public.bec_directory WHERE lower(email) = ? LIMIT 1");
        $st->execute([$email]);
        return (bool) $st->fetchColumn();
    } catch (\Throwable $e) { return false; }
}

/**
 * True when the email belongs to a system account (admin, PMO, technician,
 * faculty…).
 *
 * Staff hold BEC accounts but are not necessarily listed in the imported
 * student directory, so checking the directory alone locked every technician
 * and admin out of filing a report — even though they are exactly the people
 * most likely to notice broken equipment.
 */
function becdir_is_system_user(string $email): bool {
    $email = strtolower(trim($email));
    if ($email === '') return false;
    try {
        $st = getPgsqlPdoConnection()->prepare(
            "SELECT 1 FROM public.users
             WHERE lower(email) = ?
               AND COALESCE(status, 'active') NOT IN ('deleted', 'inactive')
             LIMIT 1");
        $st->execute([$email]);
        return (bool) $st->fetchColumn();
    } catch (\Throwable $e) { return false; }
}

/** The person's name on file, from the directory or their system account. */
/**
 * Turn a registrar-style name into one a person can be addressed by.
 *
 * The official lists are written surname-first for sorting — "MACAISA, Ken
 * Christian E" — which is correct on a roster and wrong everywhere the system
 * speaks to the reporter. Taking the part before the comma, as the portal did,
 * greeted people by their surname in capitals.
 *
 * Returns the name in reading order with ordinary capitalisation. Names already
 * written that way are left alone.
 */
function becdir_display_name(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') { return ''; }

    // Each half is recased on its own. The roster shouts the surname but often
    // writes the given names normally ("MACAISA, Ken Christian E"), so testing
    // the joined string would find mixed case and leave the surname shouting.
    $soften = static function (string $part): string {
        $part = trim($part);
        if ($part === '' || $part !== mb_strtoupper($part, 'UTF-8')) {
            return $part;   // already how the person writes it — leave it alone
        }
        return mb_convert_case(mb_strtolower($part, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    };

    if (strpos($name, ',') !== false) {
        [$surname, $rest] = array_map('trim', explode(',', $name, 2));
        return trim($soften($rest) . ' ' . $soften($surname));
    }
    return $soften($name);
}

/** Just the given name, for a greeting. */
function becdir_first_name(string $name): string {
    $display = becdir_display_name($name);
    if ($display === '') { return ''; }
    $first = explode(' ', $display)[0];
    return strlen($first) < 2 ? $display : $first;   // an initial alone is no greeting
}

function becdir_known_name(string $email): string {
    $email = strtolower(trim($email));
    if ($email === '') return '';
    $row = becdir_lookup($email);
    if ($row && trim((string)($row['full_name'] ?? '')) !== '') {
        return trim((string)$row['full_name']);
    }
    try {
        $st = getPgsqlPdoConnection()->prepare("SELECT fullname FROM public.users WHERE lower(email) = ? LIMIT 1");
        $st->execute([$email]);
        return trim((string)$st->fetchColumn());
    } catch (\Throwable $e) { return ''; }
}

/** Strip a label down to its comparable core: letters and digits only. */
function becdir_norm_option(string $s): string {
    return preg_replace('/[^a-z0-9]+/', '', strtolower(becdir_deaccent($s)));
}

/**
 * Find the entry in an official list that a directory value refers to.
 *
 * The registrar's wording and the reporter form's wording are not the same
 * text, and pre-filling only on an exact match would quietly leave the field
 * blank for most of the college — worse than not pre-filling at all, because
 * the reporter believes it is already answered. Observed in the real roster:
 *
 *   "Visual Graphics Design NC III"      vs  "Visual Graphic Design NC III"
 *   "…Major in English"                  vs  "…major in English"
 *   "SCIENCE, TECHNOLOGY, ENGINEERING…"  vs  "STEM — Science, Technology…"
 *
 * Returns '' when nothing matches — some roster programmes genuinely are not
 * on the form's list (Marketing Management, Diploma in Hospitality Management),
 * and the reporter must pick for themselves rather than be given a wrong one.
 */
function becdir_match_option(string $value, array $options): string {
    $value = trim($value);
    if ($value === '' || !$options) { return ''; }
    if (in_array($value, $options, true)) { return $value; }

    $n = becdir_norm_option($value);
    if ($n === '') { return ''; }

    foreach ($options as $opt) {
        if (becdir_norm_option($opt) === $n) { return $opt; }
    }
    // Compare against the part of the option after a dash or before a bracket,
    // which is how the form writes strands: "STEM — Science, Technology, …".
    foreach ($options as $opt) {
        foreach (preg_split('/\s+[—–-]\s+|\s*\(/u', $opt) as $piece) {
            $p = becdir_norm_option((string)$piece);
            if ($p !== '' && ($p === $n || (strlen($p) > 6 && (str_contains($n, $p) || str_contains($p, $n))))) {
                return $opt;
            }
        }
    }
    // The leading code the form uses for Senior High strands.
    foreach ($options as $opt) {
        if (preg_match('/^([A-Z]{3,5})\b/', $opt, $m)) {
            $code = becdir_norm_option($m[1]);
            if ($code !== '' && str_starts_with($n, $code)) { return $opt; }
        }
    }
    // A single close spelling. The registrar writes "Visual Graphics Design NC
    // III" where the form says "Visual Graphic Design NC III" — one letter, and
    // 31 students behind it. Only accepted when exactly one option is that
    // close, so a genuine near-miss can never be silently mistaken for its
    // neighbour.
    $len = strlen($n);
    if ($len >= 12) {
        $tolerance = min(3, intdiv($len, 8));
        $best = ''; $bestD = PHP_INT_MAX; $runnerUp = PHP_INT_MAX;
        foreach ($options as $opt) {
            $d = levenshtein($n, becdir_norm_option($opt));
            if ($d < $bestD) { $runnerUp = $bestD; $bestD = $d; $best = $opt; }
            elseif ($d < $runnerUp) { $runnerUp = $d; }
        }
        if ($bestD <= $tolerance && $bestD < $runnerUp) { return $best; }
    }
    return '';
}

/**
 * The reporter's saved answers, mapped onto the options the form offers.
 * Returns ['department'=>, 'course'=>, 'level'=>, 'phone'=>] with '' for
 * anything that cannot be matched.
 */
function becdir_form_prefill(?array $dir, array $becPrograms, array $becLevels): array {
    $out = ['department' => '', 'course' => '', 'level' => '', 'phone' => ''];
    if (!$dir) { return $out; }

    $dept = becdir_match_option((string)($dir['department'] ?? ''), array_keys($becPrograms));
    $out['department'] = $dept;
    $out['phone'] = trim((string)($dir['phone'] ?? ''));
    if ($dept === '') { return $out; }

    $program   = (string)($dir['program'] ?? '');
    $yearLevel = (string)($dir['year_level'] ?? '');

    // Pre-School, Grade School and Junior High name the level *as* the course
    // ("Grade 7"), and those rows carry no programme at all — so for them the
    // year level is what the course field wants.
    $courseSource = $program !== '' ? $program : $yearLevel;
    $out['course'] = becdir_match_option($courseSource, $becPrograms[$dept] ?? []);
    if ($out['course'] === '' && $program !== '') {
        $out['course'] = becdir_match_option($yearLevel, $becPrograms[$dept] ?? []);
    }

    if (isset($becLevels[$dept])) {
        $out['level'] = becdir_match_option($yearLevel, $becLevels[$dept]);
    }
    return $out;
}

/** Fetch a directory record by email (or null). */
function becdir_lookup(string $email): ?array {
    $email = strtolower(trim($email));
    if ($email === '') return null;
    try {
        $st = getPgsqlPdoConnection()->prepare("SELECT * FROM public.bec_directory WHERE lower(email) = ? LIMIT 1");
        $st->execute([$email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) { return null; }
}
