<?php
// config/database.php - Centralized Database Configuration
// Updated: 2026-01-15

class Database {
    private static $instance = null;
    private $connection;
    private $is_connected = false;

    private $host = "127.0.0.1";
    private $username = "root";
    private $password = "";
    private $database = "bec_equipment_db";

    private function __construct() {
        try {
            $this->connection = new mysqli($this->host, $this->username, $this->password, $this->database);
            if ($this->connection->connect_error) {
                throw new Exception("Connection failed: " . $this->connection->connect_error);
            }
            $this->connection->set_charset("utf8mb4");
            $this->connection->query("SET time_zone = '+08:00';");
            $this->is_connected = true;
        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            die("Database connection failed. Please contact support.");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    public function __destruct() {
        if ($this->connection && $this->is_connected) {
            try {
                if ($this->connection instanceof mysqli) {
                    $this->connection->close();
                }
            } catch (Throwable $e) {
                // Connection already closed or invalid, ignore
            }
            $this->is_connected = false;
        }
    }
}

// Helper function for backwards compatibility
function getDBConnection() {
    return Database::getInstance()->getConnection();
}

function equipmentTableColumns($conn) {
    static $columns = null;
    if ($columns !== null) {
        return $columns;
    }

    $columns = [];
    $res = $conn->query("SHOW COLUMNS FROM equipment");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columns[$row['Field']] = true;
        }
    }
    return $columns;
}

function equipmentIdColumn($conn) {
    $cols = equipmentTableColumns($conn);
    return isset($cols['id']) ? 'id' : 'equipment_id';
}
// ============================================
// EQUIPMENT FUNCTIONS
// ============================================

function getAllEquipment() {
    $conn = getDBConnection();

    $cols = equipmentTableColumns($conn);
    if (isset($cols['equipment_category'])) {
        $categoryExpr = "e.equipment_category AS category_name";
        $join = "";
    } elseif (isset($cols['category'])) {
        $categoryExpr = "e.category AS category_name";
        $join = "";
    } elseif (isset($cols['category_id'])) {
        $categoryExpr = "c.category_name AS category_name";
        $join = "LEFT JOIN categories c ON c.category_id = e.category_id";
    } else {
        $categoryExpr = "NULL AS category_name";
        $join = "";
    }

    $sql = "SELECT e.*, {$categoryExpr}
            FROM equipment e
            {$join}
            WHERE e.status != 'deleted'
            ORDER BY e.equipment_name ASC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getAllCategories() {
    $conn = getDBConnection();
    $sql = "SELECT * FROM categories ORDER BY category_name ASC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getEquipmentById($equipment_id) {
    $conn = getDBConnection();

    $cols = equipmentTableColumns($conn);
    if (isset($cols['equipment_category'])) {
        $categoryExpr = "e.equipment_category AS category_name";
        $join = "";
    } elseif (isset($cols['category'])) {
        $categoryExpr = "e.category AS category_name";
        $join = "";
    } elseif (isset($cols['category_id'])) {
        $categoryExpr = "c.category_name AS category_name";
        $join = "LEFT JOIN categories c ON c.category_id = e.category_id";
    } else {
        $categoryExpr = "NULL AS category_name";
        $join = "";
    }
    $idCol = isset($cols['id']) ? 'id' : 'equipment_id';

    $stmt = $conn->prepare("SELECT e.*, {$categoryExpr}
                            FROM equipment e
                            {$join}
                            WHERE e.{$idCol} = ?");
    $stmt->bind_param("s", $equipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $equipment = $result->fetch_assoc();
    $stmt->close();
    return $equipment;
}

/**
 * Get defect reports grouped by category
 */
function getAllDefectReports() {
    return getDefectReportsWithFilters('all', 'all', '');
}

function getDefectReportColumns() {
    static $columns = null;

    if ($columns !== null) {
        return $columns;
    }

    $columns = [];
    $conn = getDBConnection();
    $result = $conn->query("SHOW COLUMNS FROM defect_reports");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = true;
        }
    }

    return $columns;
}

function inferDefectReportPhotoPaths(array $report) {
    $photos = [];

    foreach (['photo_path', 'photo_url', 'photo', 'image_path'] as $field) {
        $value = trim((string)($report[$field] ?? ''));
        if ($value !== '') {
            $photos[] = str_replace('\\', '/', $value);
        }
    }

    foreach (['defect_photos', 'photo_paths', 'photos'] as $field) {
        $raw = $report[$field] ?? null;
        if (empty($raw)) {
            continue;
        }

        if (is_array($raw)) {
            foreach ($raw as $path) {
                $path = trim((string)$path);
                if ($path !== '') {
                    $photos[] = str_replace('\\', '/', $path);
                }
            }
            continue;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                foreach ($decoded as $path) {
                    $path = trim((string)$path);
                    if ($path !== '') {
                        $photos[] = str_replace('\\', '/', $path);
                    }
                }
            } else {
                $raw = trim($raw);
                if ($raw !== '') {
                    $photos[] = str_replace('\\', '/', $raw);
                }
            }
        }
    }

    $reportId = trim((string)($report['report_id'] ?? ''));
    if ($reportId !== '') {
        $patterns = [
            __DIR__ . '/../uploads/reports/' . $reportId . '.*',
            __DIR__ . '/../uploads/defect_reports/' . $reportId . '.*',
            __DIR__ . '/../uploads/defect_photos/' . $reportId . '.*',
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $realFile = realpath($file);
                if ($realFile === false) {
                    continue;
                }
                $relative = str_replace('\\', '/', str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', $realFile));
                $photos[] = ltrim($relative, '/');
            }
        }
    }

    $photos = array_values(array_unique(array_filter($photos, static function ($path) {
        if (!is_string($path) || trim($path) === '') {
            return false;
        }
        if (preg_match('#^https?://#i', $path)) {
            return true;
        }
        $fullPath = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/'));
        return is_file($fullPath);
    })));

    return $photos;
}

function normalizeDefectReportRow($report) {
    if (!is_array($report)) {
        return $report;
    }

    $photos = inferDefectReportPhotoPaths($report);
    $report['photos'] = $photos;
    $report['photo_path'] = $photos[0] ?? (string)($report['photo_path'] ?? '');

    if (!isset($report['defect_photos']) || $report['defect_photos'] === null || $report['defect_photos'] === '') {
        $report['defect_photos'] = !empty($photos) ? json_encode($photos) : null;
    }

    return $report;
}

function normalizeDefectReportRows(array $reports) {
    foreach ($reports as $index => $report) {
        $reports[$index] = normalizeDefectReportRow($report);
    }

    return $reports;
}

function defectWorkflowStatuses(): array {
    return [
        'reported' => 'Submitted',
        'pmo_review' => 'PMO Review',
        'dean_review' => 'Dean Approval',
        'finance_review' => 'Finance Review',
        'on_hold_budget' => 'On Hold for Budget',
        'ready_for_assignment' => 'Ready for Assignment',
        'assigned' => 'Assigned',
        'in_progress' => 'In Progress',
        'for_replacement' => 'For Replacement',
        'completed' => 'Completed',
        'verified' => 'Verified',
        'closed' => 'Closed',
        'rejected' => 'Rejected',
    ];
}

function defectResolvedStatuses(): array {
    return ['completed', 'verified', 'closed'];
}

function defectTerminalStatuses(): array {
    return ['verified', 'closed', 'rejected'];
}

function defectAssignmentReadyStatuses(): array {
    return ['ready_for_assignment', 'assigned', 'in_progress'];
}

function defectStatusLabel($status): string {
    $status = strtolower(trim((string)$status));
    $labels = defectWorkflowStatuses();
    return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function defectStatusCategory($status): string {
    $status = strtolower(trim((string)$status));
    return match ($status) {
        'reported', 'pmo_review', 'dean_review', 'finance_review', 'on_hold_budget', 'ready_for_assignment' => 'pending',
        'assigned', 'in_progress', 'for_replacement' => 'in_progress',
        'completed', 'verified', 'closed' => 'completed',
        'rejected' => 'rejected',
        default => 'pending',
    };
}

function defectTimelineSteps(array $report): array {
    $status = strtolower(trim((string)($report['status'] ?? 'reported')));
    $hasReached = static fn(array $statuses): bool => in_array($status, $statuses, true);

    $steps = [
        ['label' => 'Submitted', 'done' => true, 'active' => $status === 'reported'],
        ['label' => 'PMO Review', 'done' => $hasReached(['dean_review', 'finance_review', 'on_hold_budget', 'ready_for_assignment', 'assigned', 'in_progress', 'for_replacement', 'completed', 'verified', 'closed']), 'active' => $status === 'pmo_review'],
        ['label' => 'Dean Approval', 'done' => $hasReached(['finance_review', 'on_hold_budget', 'ready_for_assignment', 'assigned', 'in_progress', 'for_replacement', 'completed', 'verified', 'closed']), 'active' => $status === 'dean_review'],
        ['label' => 'Finance Review', 'done' => $hasReached(['on_hold_budget', 'ready_for_assignment', 'assigned', 'in_progress', 'for_replacement', 'completed', 'verified', 'closed']), 'active' => $status === 'finance_review'],
        ['label' => 'Technician Assignment', 'done' => $hasReached(['assigned', 'in_progress', 'for_replacement', 'completed', 'verified', 'closed']), 'active' => $status === 'ready_for_assignment'],
        ['label' => 'Repair / Assessment', 'done' => $hasReached(['for_replacement', 'completed', 'verified', 'closed']), 'active' => in_array($status, ['assigned', 'in_progress'], true)],
        ['label' => 'PMO Verification', 'done' => $hasReached(['verified', 'closed']), 'active' => $status === 'completed'],
    ];

    if ($status === 'on_hold_budget') {
        array_splice($steps, 4, 0, [[
            'label' => 'On Hold for Budget',
            'done' => false,
            'active' => true,
        ]]);
    }

    if ($status === 'for_replacement') {
        array_splice($steps, 6, 0, [[
            'label' => 'Replacement Required',
            'done' => true,
            'active' => true,
        ]]);
    }

    if ($status === 'rejected') {
        return [
            ['label' => 'Submitted', 'done' => true, 'active' => false],
            ['label' => 'Rejected', 'done' => true, 'active' => true],
        ];
    }

    return $steps;
}

/**
 * Auto-classify responsible department from reported equipment context.
 * Returns "ITSO" or "PMO" with PMO as conservative fallback.
 */
function classifyDepartmentByEquipment($equipment_id = null, $equipment_name = '', $category_name = '', $location = '', $issue_description = '') {
    $equipment_id = (string)($equipment_id ?? '');
    $equipment_name = (string)($equipment_name ?? '');
    $category_name = (string)($category_name ?? '');
    $location = (string)($location ?? '');
    $issue_description = (string)($issue_description ?? '');

    if ($equipment_id !== '' && ($equipment_name === '' || $category_name === '' || $location === '')) {
        try {
            $conn = getDBConnection();
            if ($conn) {
                $sql = "SELECT e.equipment_name, e.location,
                               COALESCE(c.category_name, '') AS category_name
                        FROM equipment e
                        LEFT JOIN categories c ON e.category_id = c.category_id
                        WHERE e.equipment_id = ?
                        LIMIT 1";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("s", $equipment_id);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($row) {
                        if ($equipment_name === '' && !empty($row['equipment_name'])) $equipment_name = (string)$row['equipment_name'];
                        if ($category_name === '' && !empty($row['category_name'])) $category_name = (string)$row['category_name'];
                        if ($location === '' && !empty($row['location'])) $location = (string)$row['location'];
                    }
                }
            }
        } catch (Exception $e) {}
    }

    $hay = strtolower(trim($equipment_name . ' ' . $category_name . ' ' . $location . ' ' . $issue_description));
    if ($hay === '') return 'PMO';

    $itsoKeywords = [
        'computer','desktop','laptop','notebook','macbook','pc',
        'monitor','display','projector','printer','scanner','router','switch','modem',
        'wifi','network','server','ups','keyboard','mouse','cpu','system unit',
        'it lab','laboratory computer','av','audio visual','smart tv','television',
        'cctv','camera','biometric','access point'
    ];
    $pmoKeywords = [
        'chair','table','desk','cabinet','drawer','shelf','door','window','ceiling','floor',
        'wall','toilet','sink','faucet','plumbing','pipe','drain','aircon','aircon unit',
        'air conditioner','hvac','electrical','wiring','outlet','socket','breaker','light',
        'bulb','fan','facility','building','room','furniture','paint'
    ];

    $scoreItso = 0;
    foreach ($itsoKeywords as $kw) { if (strpos($hay, $kw) !== false) $scoreItso++; }
    $scorePmo = 0;
    foreach ($pmoKeywords as $kw) { if (strpos($hay, $kw) !== false) $scorePmo++; }

    if ($scoreItso > $scorePmo) return 'ITSO';
    if ($scorePmo > $scoreItso) return 'PMO';
    if (strpos($hay, 'lab') !== false || strpos($hay, 'network') !== false || strpos($hay, 'computer') !== false) return 'ITSO';
    return 'PMO';
}

// ============================================
// DEFECT REPORT FUNCTIONS
// ============================================

function addDefectReport($data) {
    $conn = getDBConnection();
    $validColumns = getDefectReportColumns();

    if (isset($data['defect_photos']) && is_array($data['defect_photos'])) {
        $data['defect_photos'] = json_encode($data['defect_photos']);
    }

    $filtered = [];
    foreach ($data as $field => $value) {
        if (!isset($validColumns[$field])) {
            continue;
        }
        $filtered[$field] = $value;
    }

    if (empty($filtered)) {
        return false;
    }

    $fields = array_keys($filtered);
    $placeholders = array_fill(0, count($fields), '?');
    $types = str_repeat('s', count($fields));

    $extraFields = [];
    $extraValues = [];
    if (!isset($filtered['report_date']) && isset($validColumns['report_date'])) {
        $extraFields[] = 'report_date';
        $extraValues[] = 'NOW()';
    }

    $sql = "INSERT INTO defect_reports (" . implode(', ', array_merge($fields, $extraFields)) . ")
            VALUES (" . implode(', ', array_merge($placeholders, $extraValues)) . ")";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $values = array_values($filtered);
    $stmt->bind_param($types, ...$values);

    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

function getDefectReportById($report_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.asset_tag as asset_tag,
                            COALESCE(c.category_name, CAST(e.category_id AS CHAR)) as category_name,
                            COALESCE(u.fullname, u.username, dr.reported_by) as reporter_name
                            FROM defect_reports dr
                            JOIN equipment e ON dr.equipment_id = e.equipment_id
                            LEFT JOIN categories c ON e.category_id = c.category_id
                            LEFT JOIN users u ON dr.reported_by = u.user_id
                            WHERE dr.report_id = ?");
    $stmt->bind_param("s", $report_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $report = $result->fetch_assoc();
    $stmt->close();
    return normalizeDefectReportRow($report);
}

function getReportByIdPublic($report_id) {
    return getDefectReportById($report_id);
}

function getUserDefectReports($user_id) {
    $conn = getDBConnection();

    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.asset_tag as asset_tag,
                            COALESCE(u.fullname, u.username, dr.reported_by) as reporter_name
                            FROM defect_reports dr
                            JOIN equipment e ON dr.equipment_id = e.equipment_id
                            LEFT JOIN users u ON dr.reported_by = u.user_id
                            WHERE dr.reported_by = ?
                            ORDER BY dr.report_date DESC");
    $stmt->bind_param("s", $user_id);

    $stmt->execute();
    $result = $stmt->get_result();
    $reports = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return normalizeDefectReportRows($reports);
}

function updateDefectReport($report_id, $data) {
    $conn = getDBConnection();
    $validColumns = [];
    $colRes = $conn->query("SHOW COLUMNS FROM defect_reports");
    if ($colRes) {
        while ($col = $colRes->fetch_assoc()) {
            $validColumns[$col['Field']] = true;
        }
    }

    $updates = [];
    $types = "";
    $values = [];

    foreach ($data as $key => $value) {
        if (!isset($validColumns[$key])) {
            continue;
        }
        $updates[] = "$key = ?";
        $types .= "s";
        $values[] = $value;
    }

    if (empty($updates)) {
        return false;
    }

    $values[] = $report_id;
    $types .= "s";

    $sql = "UPDATE defect_reports SET " . implode(", ", $updates) . " WHERE report_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

function deleteDefectReport($report_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("DELETE FROM defect_reports WHERE report_id = ?");
    $stmt->bind_param("s", $report_id);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

function getDefectReportsWithFilters($status = 'all', $priority = 'all', $search = '') {
    $conn = getDBConnection();

    $sql = "SELECT dr.*, e.equipment_name, e.asset_tag as asset_tag,
            COALESCE(c.category_name, CAST(e.category_id AS CHAR)) as category_name,
            mt.fullname as technician_name,
            COALESCE(u.fullname, u.username, dr.reported_by) as reporter_name
            FROM defect_reports dr
            JOIN equipment e ON dr.equipment_id = e.equipment_id
            LEFT JOIN categories c ON e.category_id = c.category_id
            LEFT JOIN maintenance_technicians mt ON dr.assigned_to = mt.technician_id
            LEFT JOIN users u ON dr.reported_by = u.user_id
            WHERE 1=1";

    $params = [];
    $types = "";

    if ($status !== 'all') {
        $sql .= " AND dr.status = ?";
        $params[] = $status;
        $types .= "s";
    }

    if ($priority !== 'all') {
        $sql .= " AND dr.priority = ?";
        $params[] = $priority;
        $types .= "s";
    }

    if (!empty($search)) {
        $sql .= " AND (dr.report_id LIKE ? OR e.equipment_name LIKE ? OR c.category_name LIKE ? OR dr.issue_description LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "ssss";
    }

    $sql .= " ORDER BY dr.report_date DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $reports = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return normalizeDefectReportRows($reports);
}
/**
 * Dashboard helpers
 * These wrappers provide 7/30-day chart/stat blocks used by admin_dashboard.php.
 */
function getDefectsOverTime($days = 7) {
    $days = max(1, (int)$days);
    $all = getAllDefectReports();
    $buckets = [];

    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} day"));
        $buckets[$d] = 0;
    }

    foreach ($all as $row) {
        $raw = $row['report_date'] ?? null;
        if (!$raw) continue;
        $d = date('Y-m-d', strtotime($raw));
        if (isset($buckets[$d])) {
            $buckets[$d]++;
        }
    }

    $out = [];
    foreach ($buckets as $date => $count) {
        $out[] = ['date' => $date, 'count' => $count];
    }
    return $out;
}

function getReservationsOverTime($days = 7) {
    $conn = getDBConnection();
    $days = max(1, (int)$days);
    $buckets = [];

    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} day"));
        $buckets[$d] = 0;
    }

    $hasReservations = false;
    if ($res = $conn->query("SHOW TABLES LIKE 'reservations'")) {
        $hasReservations = $res->num_rows > 0;
    }
    if (!$hasReservations) {
        $out = [];
        foreach ($buckets as $date => $count) $out[] = ['date' => $date, 'count' => $count];
        return $out;
    }

    $cols = [];
    if ($cr = $conn->query("SHOW COLUMNS FROM reservations")) {
        while ($cr && ($r = $cr->fetch_assoc())) {
            $cols[$r['Field']] = true;
        }
    }

    $dateCol = isset($cols['request_date']) ? 'request_date'
        : (isset($cols['created_at']) ? 'created_at' : null);

    if (!$dateCol) {
        $out = [];
        foreach ($buckets as $date => $count) $out[] = ['date' => $date, 'count' => $count];
        return $out;
    }

    $sql = "SELECT DATE($dateCol) AS d, COUNT(*) AS c
            FROM reservations
            WHERE $dateCol >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE($dateCol)";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $days);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $d = $row['d'];
            if (isset($buckets[$d])) {
                $buckets[$d] = (int)$row['c'];
            }
        }
        $stmt->close();
    }

    $out = [];
    foreach ($buckets as $date => $count) {
        $out[] = ['date' => $date, 'count' => $count];
    }
    return $out;
}

function getEquipmentUsageOverTime($days = 7) {
    $conn = getDBConnection();
    $days = max(1, (int)$days);
    $buckets = [];

    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} day"));
        $buckets[$d] = 0;
    }

    $hasReservations = false;
    if ($res = $conn->query("SHOW TABLES LIKE 'reservations'")) {
        $hasReservations = $res->num_rows > 0;
    }
    if (!$hasReservations) {
        $out = [];
        foreach ($buckets as $date => $count) $out[] = ['date' => $date, 'count' => $count];
        return $out;
    }

    $cols = [];
    if ($cr = $conn->query("SHOW COLUMNS FROM reservations")) {
        while ($cr && ($r = $cr->fetch_assoc())) {
            $cols[$r['Field']] = true;
        }
    }

    $statusCol = isset($cols['status']) ? 'status' : null;
    $startCol = isset($cols['start_date']) ? 'start_date' : (isset($cols['request_date']) ? 'request_date' : null);
    if (!$startCol) {
        $out = [];
        foreach ($buckets as $date => $count) $out[] = ['date' => $date, 'count' => $count];
        return $out;
    }

    $sql = "SELECT DATE($startCol) AS d, COUNT(*) AS c
            FROM reservations
            WHERE $startCol >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
    if ($statusCol) {
        $sql .= " AND $statusCol IN ('approved','active','completed')";
    }
    $sql .= " GROUP BY DATE($startCol)";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $days);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $d = $row['d'];
            if (isset($buckets[$d])) {
                $buckets[$d] = (int)$row['c'];
            }
        }
        $stmt->close();
    }

    $out = [];
    foreach ($buckets as $date => $count) {
        $out[] = ['date' => $date, 'count' => $count];
    }
    return $out;
}

function getSystemStatistics() {
    $conn = getDBConnection();
    $stats = [
        'available_equipment' => 0,
        'in_use_equipment' => 0,
        'maintenance_equipment' => 0,
        'defective_equipment' => 0,
    ];

    $hasEquipment = false;
    if ($res = $conn->query("SHOW TABLES LIKE 'equipment'")) {
        $hasEquipment = $res->num_rows > 0;
    }
    if (!$hasEquipment) return $stats;

    $statusExpr = "LOWER(COALESCE(status,''))";
    $sql = "SELECT
                SUM(CASE WHEN $statusExpr IN ('operational','available') THEN 1 ELSE 0 END) AS available_equipment,
                SUM(CASE WHEN $statusExpr IN ('in_use','in use','borrowed') THEN 1 ELSE 0 END) AS in_use_equipment,
                SUM(CASE WHEN $statusExpr IN ('under_maintenance','maintenance') THEN 1 ELSE 0 END) AS maintenance_equipment,
                SUM(CASE WHEN $statusExpr IN ('defective','faulty') THEN 1 ELSE 0 END) AS defective_equipment
            FROM equipment
            WHERE $statusExpr <> 'deleted'";

    $res = $conn->query($sql);
    if ($res && ($row = $res->fetch_assoc())) {
        $stats['available_equipment'] = (int)($row['available_equipment'] ?? 0);
        $stats['in_use_equipment'] = (int)($row['in_use_equipment'] ?? 0);
        $stats['maintenance_equipment'] = (int)($row['maintenance_equipment'] ?? 0);
        $stats['defective_equipment'] = (int)($row['defective_equipment'] ?? 0);
    }

    return $stats;
}
function getDefectReportsByCategory($category_id = null, $status_filter = 'all') {
    $conn = getDBConnection();

    $sql = "SELECT e.equipment_category as category_name,
            COUNT(dr.report_id) as total_defects,
            COUNT(CASE WHEN dr.status IN ('reported', 'assigned', 'in_progress') THEN 1 END) as pending_defects,
            COUNT(CASE WHEN dr.status = 'completed' THEN 1 END) as resolved_defects,
            COUNT(CASE WHEN dr.priority = 'critical' THEN 1 END) as critical_defects,
            COUNT(CASE WHEN dr.priority = 'high' THEN 1 END) as high_defects,
            MAX(dr.report_date) as last_defect_date
            FROM equipment e
            LEFT JOIN defect_reports dr ON e.id = dr.equipment_id";

    $params = [];
    $types = "";

    if ($category_id) {
        $sql .= " WHERE e.equipment_category = ?";
        $params[] = $category_id;
        $types .= "s";
    }

    if ($status_filter !== 'all') {
        $where_clause = $category_id ? " AND" : " WHERE";
        if ($status_filter === 'pending') {
            $sql .= "$where_clause dr.status IN ('reported', 'assigned', 'in_progress')";
        } elseif ($status_filter === 'resolved') {
            $sql .= "$where_clause dr.status = 'completed'";
        } else {
            $sql .= "$where_clause dr.status = ?";
            $params[] = $status_filter;
            $types .= "s";
        }
    }

    $sql .= " GROUP BY e.equipment_category ORDER BY e.equipment_category ASC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $reports = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $reports;
}

/**
 * Get equipment defect statistics by category
 */
function getEquipmentDefectStats() {
    $conn = getDBConnection();

    $sql = "SELECT e.equipment_category as category_name,
            COUNT(DISTINCT e.id) as total_equipment,
            COUNT(DISTINCT CASE WHEN e.status = 'defective' THEN e.id END) as defective_equipment,
            COUNT(dr.report_id) as total_defects,
            ROUND(AVG(CASE WHEN dr.status = 'completed'
                          THEN DATEDIFF(dr.completion_date, dr.report_date) END), 1) as avg_resolution_days
            FROM equipment e
            LEFT JOIN defect_reports dr ON e.id = dr.equipment_id
            GROUP BY e.equipment_category
            ORDER BY total_defects DESC, category_name ASC";

    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Get defect trends by category over time
 */
function getDefectTrendsByCategory($days = 30) {
    $conn = getDBConnection();

    $sql = "SELECT e.equipment_category as category_name,
            DATE(dr.report_date) as report_date,
            COUNT(dr.report_id) as defect_count
            FROM equipment e
            LEFT JOIN defect_reports dr ON e.id = dr.equipment_id
            WHERE dr.report_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY e.equipment_category, DATE(dr.report_date)
            ORDER BY e.equipment_category ASC, report_date ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $result = $stmt->get_result();
    $trends = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $trends;
}

// ============================================
// RESERVATION FUNCTIONS
// ============================================

function addReservation($data) {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("INSERT INTO reservations 
        (reservation_id, equipment_id, user_id, start_date, end_date, purpose, status, request_date) 
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    
    $stmt->bind_param("sssssss",
        $data['reservation_id'],
        $data['equipment_id'],
        $data['user_id'],
        $data['start_date'],
        $data['end_date'],
        $data['purpose'],
        $data['status']
    );
    
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function checkReservationConflict($equipment_id, $start_date, $end_date) {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count 
                            FROM reservations 
                            WHERE equipment_id = ? 
                            AND status IN ('pending', 'approved', 'active') 
                            AND NOT (end_date < ? OR start_date > ?)");
    
    $stmt->bind_param("sss", $equipment_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $stmt->close();
    
    return $count > 0;
}

function getUserReservations($user_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT r.*, e.equipment_name, e.id as asset_tag 
                            FROM reservations r 
                            JOIN equipment e ON r.equipment_id = e.id 
                            WHERE r.user_id = ? 
                            ORDER BY r.request_date DESC");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $reservations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $reservations;
}

// ============================================
// NOTIFICATION FUNCTIONS
// ============================================

if (!function_exists('addNotification')) {
    function addNotification($user_id, $message, $type, $related_id = null) {
        $conn = getDBConnection();
        $notification_id = 'NOT-' . uniqid();

        $stmt = $conn->prepare("INSERT INTO notifications
            (notification_id, user_id, message, type, related_id, created_date, is_read)
            VALUES (?, ?, ?, ?, ?, NOW(), 0)");

        $stmt->bind_param("sssss", $notification_id, $user_id, $message, $type, $related_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result ? $notification_id : false;
    }
}

// ============================================
// TECHNICIAN FUNCTIONS
// ============================================

function getAvailableTechnicians() {
    $conn = getDBConnection();
    $sql = "SELECT
                u.user_id AS technician_id,
                u.fullname,
                COALESCE(NULLIF(mt.specialization, ''), 'General') AS specialization,
                u.status
            FROM users u
            LEFT JOIN maintenance_technicians mt
                ON mt.technician_id = u.user_id
                OR mt.username = u.username
                OR mt.email = u.email
            WHERE u.role = 'technician' AND u.status = 'active'

            UNION

            SELECT
                mt.technician_id,
                mt.fullname,
                COALESCE(NULLIF(mt.specialization, ''), 'General') AS specialization,
                mt.status
            FROM maintenance_technicians mt
            LEFT JOIN users u
                ON u.user_id = mt.technician_id
                OR u.username = mt.username
                OR u.email = mt.email
            WHERE mt.status = 'active' AND u.user_id IS NULL

            ORDER BY fullname ASC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function assignDefectReportToTechnician(string $reportId, string $technicianId, array $options = []): array {
    $reportId = trim($reportId);
    $technicianId = trim($technicianId);

    if ($reportId === '' || $technicianId === '') {
        return ['ok' => false, 'message' => 'Report and technician are required.'];
    }

    $conn = getDBConnection();
    $actorId = trim((string)($options['actor_id'] ?? ''));
    $priority = trim((string)($options['priority'] ?? 'medium'));
    $instructions = trim((string)($options['instructions'] ?? ''));
    $department = trim((string)($options['department'] ?? ''));

    try {
        $report = getDefectReportById($reportId);
        if (!$report) {
            return ['ok' => false, 'message' => 'Report not found.'];
        }

        $currentStatus = trim((string)($report['status'] ?? ''));
        if (!in_array($currentStatus, ['ready_for_assignment', 'assigned'], true)) {
            return ['ok' => false, 'message' => 'Only reports ready for assignment can be assigned here.'];
        }

        $technicianExists = false;
        foreach (getAvailableTechnicians() as $technician) {
            $candidateId = trim((string)($technician['technician_id'] ?? $technician['user_id'] ?? ''));
            if ($candidateId === $technicianId) {
                $technicianExists = true;
                break;
            }
        }

        if (!$technicianExists) {
            return ['ok' => false, 'message' => 'Selected technician is not active.'];
        }

        $availableCols = [];
        $colRes = $conn->query("SHOW COLUMNS FROM defect_reports");
        if ($colRes) {
            while ($col = $colRes->fetch_assoc()) {
                $availableCols[$col['Field']] = true;
            }
        }

        $sets = [];
        $types = '';
        $params = [];

        if (isset($availableCols['assigned_to'])) {
            $sets[] = 'assigned_to = ?';
            $types .= 's';
            $params[] = $technicianId;
        }
        if (isset($availableCols['status'])) {
            $sets[] = "status = 'assigned'";
        }
        if (isset($availableCols['priority'])) {
            $sets[] = 'priority = ?';
            $types .= 's';
            $params[] = $priority;
        }
        if (isset($availableCols['handler_instructions'])) {
            $sets[] = 'handler_instructions = ?';
            $types .= 's';
            $params[] = $instructions;
        }
        if ($department !== '' && isset($availableCols['department_assigned'])) {
            $sets[] = 'department_assigned = ?';
            $types .= 's';
            $params[] = $department;
        }
        if (isset($availableCols['assigned_date'])) {
            $sets[] = 'assigned_date = NOW()';
        }
        if ($actorId !== '' && isset($availableCols['assigned_by'])) {
            $sets[] = 'assigned_by = ?';
            $types .= 's';
            $params[] = $actorId;
        }

        if (empty($sets)) {
            return ['ok' => false, 'message' => 'No compatible assignment columns found in defect_reports.'];
        }

        $params[] = $reportId;
        $types .= 's';
        $sql = "UPDATE defect_reports SET " . implode(",\n                ", $sets) . "\n            WHERE report_id = ?";

        $conn->begin_transaction();
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare assignment update.');
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();

        addNotification($technicianId, "New maintenance task assigned - Report #{$reportId}", 'task_assigned', $reportId);
        $conn->commit();

        return ['ok' => true, 'message' => "Report #{$reportId} assigned successfully."];
    } catch (Throwable $e) {
        if ($conn->errno === 0 || $conn->errno >= 0) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }
        }
        return ['ok' => false, 'message' => 'Assignment failed: ' . $e->getMessage()];
    }
}

function getTechnicianStatistics($technician_id) {
    $conn = getDBConnection();

    $stmt = $conn->prepare("SELECT
        COUNT(CASE WHEN status IN ('assigned', 'in_progress', 'ready_for_assignment') AND assigned_to = ? THEN 1 END) as assigned_tasks,
        COUNT(CASE WHEN status = 'in_progress' AND assigned_to = ? THEN 1 END) as in_progress,
        COUNT(CASE WHEN status = 'completed' AND DATE(completion_date) = CURDATE() AND assigned_to = ? THEN 1 END) as completed_today,
        COUNT(CASE WHEN status = 'completed' AND assigned_to = ? THEN 1 END) as total_completed
        FROM defect_reports");

    $stmt->bind_param("ssss", $technician_id, $technician_id, $technician_id, $technician_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();

    // Pending for technicians should only include work assigned to them.
    $stats['pending_tasks'] = $stats['assigned_tasks'];

    return $stats;
}

function getAssignedTasks($technician_id) {
    $conn = getDBConnection();
    $equipmentIdCol = equipmentIdColumn($conn);
    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.{$equipmentIdCol} as asset_tag, e.location,
                            CASE WHEN dr.assigned_to IS NULL THEN 'unassigned' ELSE 'assigned' END as task_type
                            FROM defect_reports dr
                            JOIN equipment e ON dr.equipment_id = e.{$equipmentIdCol}
                            WHERE dr.assigned_to = ? AND dr.status IN ('assigned', 'in_progress', 'ready_for_assignment')
                            ORDER BY dr.priority DESC, dr.assigned_date ASC, dr.report_date ASC");
    $stmt->bind_param("s", $technician_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $tasks = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $tasks;
}

function getTechnicianWorkHistory($technician_id) {
    $conn = getDBConnection();
    $equipmentIdCol = equipmentIdColumn($conn);
    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.{$equipmentIdCol} as asset_tag, e.location
                            FROM defect_reports dr
                            JOIN equipment e ON dr.equipment_id = e.{$equipmentIdCol}
                            WHERE dr.assigned_to = ?
                            AND dr.status = 'completed'
                            ORDER BY dr.completion_date DESC");
    $stmt->bind_param("s", $technician_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $history;
}

function getCompletedWorkForVerification() {
    $conn = getDBConnection();
    $equipmentIdCol = equipmentIdColumn($conn);
    $sql = "SELECT dr.*, e.equipment_name, e.{$equipmentIdCol} as asset_tag,
            mt.fullname as technician_name
            FROM defect_reports dr
            JOIN equipment e ON dr.equipment_id = e.{$equipmentIdCol}
            JOIN maintenance_technicians mt ON dr.assigned_to = mt.technician_id
            WHERE dr.status = 'completed'
            ORDER BY dr.completion_date DESC";
    $result = $conn->query($sql);
    return $result ? normalizeDefectReportRows($result->fetch_all(MYSQLI_ASSOC)) : [];
}

function getUnassignedReports() {
    $conn = getDBConnection();
    $equipmentIdCol = equipmentIdColumn($conn);
    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.{$equipmentIdCol} as asset_tag, e.location
                            FROM defect_reports dr
                            JOIN equipment e ON dr.equipment_id = e.{$equipmentIdCol}
                            WHERE dr.status = 'ready_for_assignment' AND dr.assigned_to IS NULL
                            ORDER BY dr.priority DESC, dr.report_date ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    $reports = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return normalizeDefectReportRows($reports);
}

function getAvailableTasks() {
    return getUnassignedReports();
}

function getRecentAssignedTasks($technician_id, $limit = 5) {
    $conn = getDBConnection();
    $equipmentIdCol = equipmentIdColumn($conn);
    $sql = "SELECT dr.*, e.equipment_name, e.{$equipmentIdCol} as asset_tag, e.location
            FROM defect_reports dr
            JOIN equipment e ON dr.equipment_id = e.{$equipmentIdCol}
            WHERE dr.assigned_to = ? AND dr.status IN ('assigned', 'in_progress', 'completed', 'for_replacement')
            ORDER BY dr.assigned_date DESC, dr.report_date DESC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $limit = (int)$limit;
    $stmt->bind_param("si", $technician_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $tasks = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return normalizeDefectReportRows($tasks);
}

// ============================================
// UTILITY FUNCTIONS
// ============================================

function getPriorityClass($priority) {
    $classes = [
        'critical' => 'danger',
        'high' => 'warning',
        'medium' => 'info',
        'low' => 'secondary'
    ];
    return $classes[$priority] ?? 'secondary';
}

function getStatusClass($status) {
    $classes = [
        'reported' => 'warning',
        'pmo_review' => 'warning',
        'dean_review' => 'warning',
        'finance_review' => 'warning',
        'on_hold_budget' => 'warning',
        'ready_for_assignment' => 'info',
        'assigned' => 'info',
        'in_progress' => 'primary',
        'for_replacement' => 'danger',
        'completed' => 'success',
        'verified' => 'success',
        'closed' => 'secondary',
        'rejected' => 'danger',
        'available' => 'success',
        'in-use' => 'primary',
        'maintenance' => 'warning',
        'defective' => 'danger'
    ];
    return $classes[$status] ?? 'secondary';
}

function getReservationStatusClass($status) {
    $classes = [
        'pending' => 'warning',
        'approved' => 'success',
        'active' => 'primary',
        'completed' => 'secondary',
        'rejected' => 'danger',
        'cancelled' => 'danger'
    ];
    return $classes[$status] ?? 'secondary';
}



// ============================================
// USER AUTHENTICATION FUNCTIONS
// ============================================

/**
 * Verify user login credentials
 * @param string $email
 * @param string $password
 * @param string $role (admin, pmo, dean, finance, handler, technician, faculty, student)
 * @return array|false User data or false
 */
function authenticateUser($email, $password, $role) {
    $conn = getDBConnection();

    // Query the unified users table
    $sql = "SELECT * FROM `users`
            WHERE email = ? AND role = ? AND status = 'active'
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return false;
    }

    $user = $result->fetch_assoc();

    // Verify password
    if (password_verify($password, $user['password'])) {
        // Update last login - use user_id since that's the primary key in users table
        $updateSql = "UPDATE `users` SET last_login = NOW() WHERE user_id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("s", $user['user_id']);
        $updateStmt->execute();

        return $user;
    }

    return false;
}

/**
 * Get user by ID and role
 */
function getUserById($user_id, $role) {
    $conn = getDBConnection();

    $roleTableMap = [
        'admin' => ['table' => 'admins', 'id_field' => 'admin_id'],
        'pmo' => ['table' => 'users', 'id_field' => 'user_id'],
        'dean' => ['table' => 'users', 'id_field' => 'user_id'],
        'finance' => ['table' => 'users', 'id_field' => 'user_id'],
        'technician' => ['table' => 'maintenance_technicians', 'id_field' => 'technician_id'],
        'faculty' => ['table' => 'faculty_members', 'id_field' => 'faculty_id'],
        'student' => ['table' => 'students', 'id_field' => 'student_id']
    ];

    if (!isset($roleTableMap[$role])) {
        return null;
    }

    $config = $roleTableMap[$role];
    $sql = in_array($role, ['pmo', 'dean', 'finance'], true)
        ? "SELECT * FROM `users` WHERE user_id = ? AND role = ? LIMIT 1"
        : "SELECT * FROM `{$config['table']}` WHERE {$config['id_field']} = ? LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (in_array($role, ['pmo', 'dean', 'finance'], true)) {
        $stmt->bind_param("ss", $user_id, $role);
    } else {
        $stmt->bind_param("s", $user_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    return null;
}

/**
 * Create new user account
 */
function createUser($role, $userData) {
    $conn = getDBConnection();

    $roleTableMap = [
        'admin' => ['table' => 'admins', 'id_prefix' => 'ADM'],
        'pmo' => ['table' => 'users', 'id_prefix' => 'PMO'],
        'dean' => ['table' => 'users', 'id_prefix' => 'DEAN'],
        'finance' => ['table' => 'users', 'id_prefix' => 'FIN'],
        'technician' => ['table' => 'maintenance_technicians', 'id_prefix' => 'TEC'],
        'faculty' => ['table' => 'faculty_members', 'id_prefix' => 'FAC'],
        'student' => ['table' => 'students', 'id_prefix' => 'STU']
    ];

    if (!isset($roleTableMap[$role])) {
        return false;
    }

    $config = $roleTableMap[$role];
    $table = $config['table'];
    $idField = in_array($role, ['pmo', 'dean', 'finance'], true) ? 'user_id' : $role . '_id';

    // Generate ID
    if (!isset($userData[$idField])) {
        $userData[$idField] = $config['id_prefix'] . '-' . strtoupper(substr(uniqid(), -6));
    }

    // Hash password
    if (isset($userData['password'])) {
        $userData['password'] = password_hash($userData['password'], PASSWORD_BCRYPT);
    }

    // Build INSERT query
    $fields = array_keys($userData);
    $placeholders = array_fill(0, count($fields), '?');

    $sql = "INSERT INTO `$table` (" . implode(', ', $fields) . ")
            VALUES (" . implode(', ', $placeholders) . ")";

    $stmt = $conn->prepare($sql);

    // Bind parameters dynamically
    $types = str_repeat('s', count($fields));
    $values = array_values($userData);
    $stmt->bind_param($types, ...$values);

    return $stmt->execute();
}

/**
 * Update user information
 */
function updateUser($user_id, $role, $updateData) {
    $conn = getDBConnection();

    $roleTableMap = [
        'admin' => ['table' => 'admins', 'id_field' => 'admin_id'],
        'pmo' => ['table' => 'users', 'id_field' => 'user_id'],
        'dean' => ['table' => 'users', 'id_field' => 'user_id'],
        'finance' => ['table' => 'users', 'id_field' => 'user_id'],
        'technician' => ['table' => 'maintenance_technicians', 'id_field' => 'technician_id'],
        'faculty' => ['table' => 'faculty_members', 'id_field' => 'faculty_id'],
        'student' => ['table' => 'students', 'id_field' => 'student_id']
    ];

    if (!isset($roleTableMap[$role])) {
        return false;
    }

    $config = $roleTableMap[$role];

    // Hash password if being updated
    if (isset($updateData['password'])) {
        $updateData['password'] = password_hash($updateData['password'], PASSWORD_BCRYPT);
    }

    // Build UPDATE query
    $sets = [];
    $values = [];
    foreach ($updateData as $field => $value) {
        $sets[] = "$field = ?";
        $values[] = $value;
    }
    $values[] = $user_id;

    $sql = "UPDATE `{$config['table']}` SET " . implode(', ', $sets) .
           " WHERE {$config['id_field']} = ?";

    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($values));
    $stmt->bind_param($types, ...$values);

    return $stmt->execute();
}

/**
 * Get all users by role
 */
function getAllUsersByRole($role) {
    $conn = getDBConnection();

    $roleTableMap = [
        'admin' => 'admins',
        'pmo' => 'users',
        'dean' => 'users',
        'finance' => 'users',
        'technician' => 'maintenance_technicians',
        'faculty' => 'faculty_members',
        'student' => 'students'
    ];

    if (!isset($roleTableMap[$role])) {
        return [];
    }

    $table = $roleTableMap[$role];
    $sql = in_array($role, ['pmo', 'dean', 'finance'], true)
        ? "SELECT * FROM `users` WHERE role = ? ORDER BY fullname"
        : "SELECT * FROM `$table` ORDER BY fullname";

    if (in_array($role, ['pmo', 'dean', 'finance'], true)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $role);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }

    if ($result) {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        if (isset($stmt)) {
            $stmt->close();
        }
        return $rows;
    }

    return [];
}

/**
 * Check if username exists
 */
function usernameExists($username, $excludeUserId = null) {
    $conn = getDBConnection();

    $tables = ['admins', 'maintenance_technicians',
               'faculty_members', 'students'];

    foreach ($tables as $table) {
        $sql = "SELECT COUNT(*) as count FROM `$table` WHERE username = ?";

        if ($excludeUserId) {
            $sql .= " AND (admin_id != ? OR technician_id != ? OR faculty_id != ? OR student_id != ?)";
        }

        $stmt = $conn->prepare($sql);

        if ($excludeUserId) {
            $stmt->bind_param("ssssss", $username, $excludeUserId, $excludeUserId,
                            $excludeUserId, $excludeUserId, $excludeUserId);
        } else {
            $stmt->bind_param("s", $username);
        }

        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result['count'] > 0) {
            return true;
        }
    }

    return false;
}

/**
 * Check if email exists
 */
function emailExists($email, $excludeUserId = null) {
    $conn = getDBConnection();

    $tables = ['admins', 'maintenance_technicians',
               'faculty_members', 'students'];

    foreach ($tables as $table) {
        $sql = "SELECT COUNT(*) as count FROM `$table` WHERE email = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result['count'] > 0) {
            return true;
        }
    }

    return false;
}

/**
 * Log user activity (optional security feature)
 */
function logActivity($user_id, $user_role, $action_type, $description = '') {
    $conn = getDBConnection();
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $sql = "INSERT INTO activity_log (user_id, user_role, action_type, action_description, ip_address)
            VALUES (?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssss", $user_id, $user_role, $action_type, $description, $ip_address);

    return $stmt->execute();
}

/**
 * Get employees on vacation from dbrrhh database
 */
function getEmployeesOnVacation() {
    try {
        // Connect to dbrrhh database
        $dbrrhh_conn = new mysqli("localhost", "root", "", "dbrrhh");

        if ($dbrrhh_conn->connect_error) {
            error_log("dbrrhh database connection error: " . $dbrrhh_conn->connect_error);
            return [];
        }

        $dbrrhh_conn->set_charset("utf8mb4");

        // Query for current month approved leave requests
        $sql = "SELECT
            e.first_name,
            e.last_name,
            p.start_time,
            p.end_time,
            p.employee_id,
            p.start_date,
            p.end_date,
            p.total_days,
            p.total_hours
        FROM
            employees AS e,
            leave_approvals AS a,
            leave_requests AS p
        WHERE
            e.id = p.employee_id
            AND a.leave_request_id = p.id
            AND a.`status` = 'approved'
            AND year(p.start_date)=year(now()) and month(p.start_date)=month(now())
        ORDER BY p.start_date ASC";

        $result = $dbrrhh_conn->query($sql);
        $vacations = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $vacations[] = [
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'start_date' => $row['start_date'],
                    'end_date' => $row['end_date'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                    'total_days' => $row['total_days'],
                    'total_hours' => $row['total_hours']
                ];
            }
        }

        $dbrrhh_conn->close();
        return $vacations;

    } catch (Exception $e) {
        error_log("Error fetching vacation data: " . $e->getMessage());
        return [];
    }
}

/**
 * Create user session record
 */
function createSession($user_id, $user_role) {
    $conn = getDBConnection();

    $session_id = session_id();
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    $sql = "INSERT INTO user_sessions (session_id, user_id, user_role, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssss", $session_id, $user_id, $user_role, $ip_address, $user_agent);

    return $stmt->execute();
}

/**
 * Close user session
 */
function closeSession($session_id) {
    $conn = getDBConnection();

    $sql = "UPDATE user_sessions SET logout_time = NOW() WHERE session_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $session_id);

    return $stmt->execute();
}

// ============================================
// SYSTEM STATISTICS FUNCTIONS
// ============================================







/**
 * Get total user count across all roles
 */
function getTotalUserCount() {
    $conn = getDBConnection();
    $tables = ['admins', 'maintenance_technicians', 'faculty_members', 'students'];
    $total = 0;

    foreach ($tables as $table) {
        $result = $conn->query("SELECT COUNT(*) as count FROM `$table` WHERE status = 'active'");
        if ($result) {
            $total += $result->fetch_assoc()['count'];
        }
    }

    return $total;
}





// ============================================
// INITIALIZATION
// ============================================

/**
 * Initialize system with sample data if needed
 */
function initializeSystem() {
    global $fileStorage;

    // Check if equipment data exists
    $equipment = getAllEquipment();

    if (empty($equipment)) {
        // Generate sample data
        generateSampleData();
        return true;
    }

    return false;
}

// Auto-initialize on first run
// initializeSystem();



