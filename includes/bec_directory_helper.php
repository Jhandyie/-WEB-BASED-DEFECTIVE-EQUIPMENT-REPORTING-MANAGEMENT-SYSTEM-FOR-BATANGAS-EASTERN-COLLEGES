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
        'user type' => 'user_type', 'type' => 'user_type', 'role' => 'user_type', 'category' => 'user_type',
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
        if (!extension_loaded('zip')) {
            return ['rows' => [], 'error' => 'XLSX import is not available on this server (the PHP "zip" extension is disabled). Please save your Excel file as CSV and upload that instead.'];
        }
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

    // First non-empty row = header
    $header = array_shift($records);
    $colMap = [];
    foreach ($header as $i => $label) {
        $canon = becdir_canon_header((string)$label);
        if ($canon) $colMap[$canon] = $i;
    }
    if (!isset($colMap['email'])) {
        return ['rows' => [], 'error' => 'No "Email" column was found. Required headers include: Full Name, Email, Employee Number, Student Number, Department, Program, User Type.'];
    }

    $rows = [];
    foreach ($records as $r) {
        $get = static fn($key) => isset($colMap[$key]) ? trim((string)($r[$colMap[$key]] ?? '')) : '';
        $email = strtolower($get('email'));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $rows[] = [
            'full_name'       => $get('full_name'),
            'email'           => $email,
            'employee_number' => $get('employee_number'),
            'student_number'  => $get('student_number'),
            'department'      => $get('department'),
            'program'         => $get('program'),
            'user_type'       => becdir_canon_type($get('user_type')),
        ];
    }
    if (!$rows) return ['rows' => [], 'error' => 'No valid rows with email addresses were found.'];
    return ['rows' => $rows, 'error' => ''];
}

/** Minimal XLSX reader (used only when ext-zip is available). Returns array of rows or null. */
function becdir_read_xlsx(string $path): ?array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;
    $shared = [];
    if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = @simplexml_load_string($ss);
        if ($xml) { foreach ($xml->si as $si) { $shared[] = (string)($si->t ?? implode('', (array)$si->xpath('.//t'))); } }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false) return null;
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
            $v = (string)$c->v;
            if ((string)$c['t'] === 's') { $v = $shared[(int)$v] ?? ''; }
            $cells[$idx] = $v;
        }
        if ($cells) { ksort($cells); $rows[] = array_values($cells + array_fill(0, max(array_keys($cells)) + 1, '')); }
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
