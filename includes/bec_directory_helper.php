<?php
/**
 * BEC official directory helper (#1 — user verification).
 * Imports the official BEC user list (CSV; .xlsx supported only if the PHP zip
 * extension is available) and verifies reporter emails against it.
 */
require_once __DIR__ . '/../config/database.php';

/** Map varied header labels to canonical field names. */
function becdir_canon_header(string $h): ?string {
    $k = strtolower(trim(preg_replace('/[\s_\-\.]+/', ' ', $h)));
    $map = [
        'full name' => 'full_name', 'fullname' => 'full_name', 'name' => 'full_name', 'complete name' => 'full_name',
        'email' => 'email', 'bec email' => 'email', 'email address' => 'email', 'bec email address' => 'email', 'school email' => 'email',
        'employee number' => 'employee_number', 'employee no' => 'employee_number', 'emp no' => 'employee_number', 'employee id' => 'employee_number',
        'student number' => 'student_number', 'student no' => 'student_number', 'student id' => 'student_number', 'sr code' => 'student_number',
        'department' => 'department', 'dept' => 'department', 'office' => 'department',
        'program' => 'program', 'course' => 'program', 'strand' => 'program',
        'user type' => 'user_type', 'type' => 'user_type', 'role' => 'user_type', 'category' => 'user_type', 'user role' => 'user_type',
        // official letterhead export combines these into one column ("BSIT / Student")
        'program / user role' => 'program_role', 'program / role' => 'program_role', 'course / role' => 'program_role',
    ];
    return $map[$k] ?? null;
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
    $colMap = [];
    $scan = min(count($records), 15);
    for ($i = 0; $i < $scan; $i++) {
        $probe = [];
        foreach ($records[$i] as $j => $label) {
            $canon = becdir_canon_header((string)$label);
            if ($canon) $probe[$canon] = $j;
        }
        if (isset($probe['email'])) { $colMap = $probe; $records = array_slice($records, $i + 1); break; }
    }
    if (!isset($colMap['email'])) {
        return ['rows' => [], 'error' => 'No "Email" column was found. Required headers include: Full Name, Email, Employee Number, Student Number, Department, Program, User Type (letterhead rows above the header are fine).'];
    }

    $rows = [];
    foreach ($records as $r) {
        $get = static fn($key) => isset($colMap[$key]) ? trim((string)($r[$colMap[$key]] ?? '')) : '';
        $email = strtolower($get('email'));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $program = $get('program');
        $type    = becdir_canon_type($get('user_type'));
        // combined "PROGRAM / USER ROLE" column, e.g. "BSIT / Student"
        if (isset($colMap['program_role']) && ($pr = $get('program_role')) !== '') {
            $bits = array_map('trim', explode('/', $pr, 2));
            if ($program === '') $program = $bits[0];
            if ($type === '' && isset($bits[1])) $type = becdir_canon_type($bits[1]);
        }
        // no explicit type → infer from which ID number the row carries
        if ($type === '') {
            $type = $get('student_number') !== '' ? 'student'
                  : ($get('employee_number') !== '' ? 'staff' : '');
        }
        $rows[] = [
            'full_name'       => $get('full_name'),
            'email'           => $email,
            'employee_number' => $get('employee_number'),
            'student_number'  => $get('student_number'),
            'department'      => $get('department'),
            'program'         => $program,
            'user_type'       => $type,
        ];
    }
    if (!$rows) return ['rows' => [], 'error' => 'No valid rows with email addresses were found.'];
    return ['rows' => $rows, 'error' => ''];
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
    $stmt = $pdo->prepare("INSERT INTO public.bec_directory (full_name,email,employee_number,student_number,department,program,user_type,imported_at)
        VALUES (:fn,:em,:en,:sn,:dep,:prog,:ut, now())
        ON CONFLICT (email) DO UPDATE SET
            full_name=EXCLUDED.full_name, employee_number=EXCLUDED.employee_number, student_number=EXCLUDED.student_number,
            department=EXCLUDED.department, program=EXCLUDED.program, user_type=EXCLUDED.user_type, imported_at=now()
        RETURNING (xmax = 0) AS inserted");
    foreach ($rows as $r) {
        $stmt->execute([
            'fn'=>$r['full_name'], 'em'=>$r['email'], 'en'=>$r['employee_number'], 'sn'=>$r['student_number'],
            'dep'=>$r['department'], 'prog'=>$r['program'], 'ut'=>$r['user_type'],
        ]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($res['inserted'])) { $ins++; } else { $upd++; }
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
