<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notification_helper.php';

class StudentDashboardController
{
    private $conn;

    public function __construct()
    {
        $this->conn = getDBConnection();
    }

    public function getDashboardStats(string $userId): array
    {
        $reports = getUserDefectReports($userId);
        $pending = 0;
        $inProgress = 0;
        $completed = 0;

        foreach ($reports as $report) {
            $status = $this->mapReportStatus((string)($report['status'] ?? 'reported'));
            if ($status === 'pending') {
                $pending++;
            } elseif ($status === 'in_progress') {
                $inProgress++;
            } elseif ($status === 'completed') {
                $completed++;
            }
        }

        return [
            'success' => true,
            'data' => [
                'reports' => [
                    'total_reports' => count($reports),
                    'pending_reports' => $pending,
                    'in_progress_reports' => $inProgress,
                    'completed_reports' => $completed,
                ],
                'notifications' => $this->getUnreadNotificationCount($userId),
            ],
        ];
    }

    public function getMyReports(string $userId, ?int $limit = null): array
    {
        $reports = array_map(fn($row) => $this->formatReport($row), getUserDefectReports($userId));
        if ($limit !== null && $limit > 0) {
            $reports = array_slice($reports, 0, $limit);
        }

        return ['success' => true, 'data' => $reports];
    }

    public function getRecentReports(int $limit = 5): array
    {
        $reports = array_map(fn($row) => $this->formatReport($row), getAllDefectReports());
        return ['success' => true, 'data' => array_slice($reports, 0, max(1, $limit))];
    }

    public function getMyReservations(string $userId, ?int $limit = null): array
    {
        if (!$this->tableExists('reservations')) {
            return ['success' => true, 'data' => []];
        }

        $equipmentIdCol = equipmentIdColumn($this->conn);
        $sql = "SELECT r.*, e.equipment_name, COALESCE(e.asset_tag, e.{$equipmentIdCol}) AS asset_tag
                FROM reservations r
                LEFT JOIN equipment e ON r.equipment_id = e.{$equipmentIdCol}
                WHERE r.user_id = ?
                ORDER BY r.request_date DESC";

        if ($limit !== null && $limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('s', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return ['success' => true, 'data' => $rows];
    }

    public function getNotifications(string $userId, int $limit = 10, bool $unreadOnly = false): array
    {
        if (!$this->tableExists('notifications')) {
            return ['success' => true, 'data' => []];
        }

        $sql = "SELECT notification_id, message, type, related_id, is_read, created_date
                FROM notifications
                WHERE (user_id = ? OR user_id IS NULL)";
        if ($unreadOnly) {
            $sql .= " AND is_read = 0";
        }
        $sql .= " ORDER BY created_date DESC LIMIT ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('si', $userId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $data = array_map(function (array $row): array {
            return [
                'id' => "'" . str_replace("'", "\\'", (string)$row['notification_id']) . "'",
                'notification_id' => (string)$row['notification_id'],
                'title' => $this->notificationTitle((string)($row['type'] ?? 'notification')),
                'message' => (string)($row['message'] ?? ''),
                'type' => (string)($row['type'] ?? 'notification'),
                'related_id' => (string)($row['related_id'] ?? ''),
                'is_read' => (int)($row['is_read'] ?? 0),
                'created_at' => (string)($row['created_date'] ?? ''),
            ];
        }, $rows);

        return ['success' => true, 'data' => $data];
    }

    public function markNotificationRead(string $notificationId, string $userId): array
    {
        if (!$this->tableExists('notifications')) {
            return ['success' => false, 'message' => 'Notifications are unavailable.'];
        }

        $stmt = $this->conn->prepare(
            "UPDATE notifications
             SET is_read = 1
             WHERE notification_id = ? AND (user_id = ? OR user_id IS NULL)"
        );
        $stmt->bind_param('ss', $notificationId, $userId);
        $ok = $stmt->execute();
        $stmt->close();

        return ['success' => (bool)$ok];
    }

    public function markAllNotificationsRead(string $userId): array
    {
        if (!$this->tableExists('notifications')) {
            return ['success' => false, 'message' => 'Notifications are unavailable.'];
        }

        $stmt = $this->conn->prepare(
            "UPDATE notifications
             SET is_read = 1
             WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0"
        );
        $stmt->bind_param('s', $userId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return ['success' => (bool)$ok, 'count' => max(0, $affected)];
    }

    public function getAvailableEquipment(): array
    {
        $equipment = [];
        foreach (getAllEquipment() as $row) {
            $status = strtolower((string)($row['status'] ?? ''));
            if ($status === 'deleted') {
                continue;
            }

            $equipment[] = [
                'id' => (string)($row['equipment_id'] ?? $row['id'] ?? ''),
                'equipment_id' => (string)($row['equipment_id'] ?? $row['id'] ?? ''),
                'equipment_name' => (string)($row['equipment_name'] ?? $row['name'] ?? ''),
                'equipment_category' => (string)($row['category_name'] ?? $row['category'] ?? 'Uncategorized'),
                'location' => (string)($row['location'] ?? ''),
                'quantity' => max(0, (int)($row['quantity'] ?? 1)),
                'reserved_qty' => 0,
                'asset_tag' => (string)($row['asset_tag'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
            ];
        }

        return ['success' => true, 'data' => $equipment];
    }

    public function getReportDetails(string $reportId): array
    {
        $report = getDefectReportById($reportId);
        if (!$report) {
            return ['success' => false, 'message' => 'Report not found.'];
        }

        return ['success' => true, 'data' => $this->formatReport($report, true)];
    }

    public function submitReport(string $userId, array $post, array $files): array
    {
        $equipmentRef = trim((string)($post['equipment_id'] ?? $post['equipment'] ?? $post['equipment_name'] ?? ''));
        $equipmentName = trim((string)($post['equipment_name'] ?? $post['equipment'] ?? ''));
        $equipmentId = $this->resolveEquipmentId($equipmentRef);
        $description = trim((string)($post['issue_description'] ?? $post['description'] ?? ''));
        $location = trim((string)($post['location'] ?? ''));
        $category = trim((string)($post['category'] ?? ''));
        $reporterName = trim((string)($_SESSION['fullname'] ?? $_SESSION['username'] ?? ''));
        $reporterEmail = trim((string)($_SESSION['email'] ?? $_SESSION['user_email'] ?? ''));

        if ($equipmentId === '' && $equipmentName === '' && $equipmentRef !== '') {
            $equipmentName = $equipmentRef;
        }

        if ($equipmentId === '' && $equipmentName !== '') {
            $manualEquipment = $this->createManualEquipment($equipmentName, $category, $location);
            $equipmentId = (string)($manualEquipment['id'] ?? '');
        }

        if ($equipmentId === '' || $description === '') {
            return ['success' => false, 'message' => 'Equipment and issue description are required.'];
        }

        // Duplicate guard: the same unit may already have an open report.
        if (empty($post['duplicate_override']) && function_exists('findOpenReportForEquipment')) {
            $dup = findOpenReportForEquipment($equipmentId);
            if ($dup) {
                $dupStatus = ucwords(str_replace('_', ' ', (string)$dup['status']));
                return [
                    'success' => false,
                    'duplicate' => true,
                    'existing_report_id' => (string)$dup['report_id'],
                    'message' => 'This equipment already has an open report (' . $dup['report_id'] . ' — ' . $dupStatus
                        . '). You can track that ticket instead; if this is a different issue, resubmit with the "separate issue" confirmation.',
                ];
            }
        }

        require_once __DIR__ . '/../includes/ticket.php';
        $reportId = generateTicketNumber();
        $savedPhotos = $this->saveUploadedPhotos($reportId, $files);

        $payload = [
            'report_id' => $reportId,
            'equipment_id' => $equipmentId,
            'reported_by' => $userId,
            'issue_description' => $description,
            'priority' => $this->inferPriority($description),
            'status' => 'reported',
            'location' => $location,
            'category' => $category,
            'reporter_name' => $reporterName,
            'reporter_email' => $reporterEmail,
        ];

        if (!empty($savedPhotos)) {
            $payload['photo_path'] = $savedPhotos[0];
            $payload['defect_photos'] = $savedPhotos;
        }

        if (!addDefectReport($payload)) {
            return ['success' => false, 'message' => 'Failed to submit the report.'];
        }

        $this->notifyWorkflowReviewers($reportId, $equipmentId);

        return [
            'success' => true,
            'message' => 'Defect report submitted successfully.',
            'report_id' => $reportId,
        ];
    }

    public function createReservation(string $userId, array $post): array
    {
        if (!$this->tableExists('reservations')) {
            return ['success' => false, 'message' => 'Reservation feature is not available right now.'];
        }

        $equipmentId = trim((string)($post['equipment_id'] ?? ''));
        $startDate = trim((string)($post['reservation_date'] ?? $post['start_date'] ?? ''));
        $endDate = trim((string)($post['return_date'] ?? $post['end_date'] ?? $startDate));
        $purpose = trim((string)($post['purpose'] ?? 'Equipment reservation'));

        if ($equipmentId === '' || $startDate === '' || $endDate === '') {
            return ['success' => false, 'message' => 'Equipment and reservation dates are required.'];
        }

        if (checkReservationConflict($equipmentId, $startDate, $endDate)) {
            return ['success' => false, 'message' => 'That equipment is already reserved for the selected dates.'];
        }

        $reservationId = 'RSV-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));
        $saved = addReservation([
            'reservation_id' => $reservationId,
            'equipment_id' => $equipmentId,
            'user_id' => $userId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'purpose' => $purpose,
            'status' => 'pending',
        ]);

        return [
            'success' => (bool)$saved,
            'message' => $saved ? 'Reservation submitted successfully.' : 'Failed to submit reservation.',
            'reservation_id' => $saved ? $reservationId : null,
        ];
    }

    public function updateProfile(string $userId, string $name, string $email): array
    {
        $name = trim($name);
        $email = trim($email);

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'A valid name and email are required.'];
        }

        if (userExistsByEmail($email, $userId)) {
            return ['success' => false, 'message' => 'That email address is already in use.'];
        }

        $ok = updateUserFieldsById($userId, [
            'fullname' => $name,
            'email' => $email,
        ]);

        if ($ok) {
            $_SESSION['fullname'] = $name;
            $_SESSION['email'] = $email;
            $_SESSION['user_email'] = $email;
        }

        return ['success' => (bool)$ok, 'message' => $ok ? 'Profile updated successfully.' : 'Failed to update profile.'];
    }

    public function changePassword(string $userId, string $currentPassword, string $newPassword): array
    {
        if (strlen($newPassword) < 8) {
            return ['success' => false, 'message' => 'Password must be at least 8 characters.'];
        }

        $user = findUserById($userId, ['password']);

        if (!$user || !password_verify($currentPassword, (string)$user['password'])) {
            return ['success' => false, 'message' => 'Current password is incorrect.'];
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $ok = updateUserFieldsById($userId, ['password' => $hash]);

        return ['success' => (bool)$ok, 'message' => $ok ? 'Password updated successfully.' : 'Failed to update password.'];
    }

    private function getUnreadNotificationCount(string $userId): int
    {
        if (!$this->tableExists('notifications')) {
            return 0;
        }

        $stmt = $this->conn->prepare("SELECT COUNT(*) AS count FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0");
        $stmt->bind_param('s', $userId);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0);
        $stmt->close();

        return $count;
    }

    private function formatReport(array $report, bool $includeDetails = false): array
    {
        $photos = array_values(array_filter(array_map('strval', $report['photos'] ?? [])));

        $formatted = [
            'id' => (string)($report['report_id'] ?? ''),
            'report_id' => (string)($report['report_id'] ?? ''),
            'equipment_id' => (string)($report['equipment_id'] ?? ''),
            'equipment_name' => (string)($report['equipment_name'] ?? ''),
            'category' => (string)($report['category_name'] ?? $report['category'] ?? ''),
            'location' => (string)($report['location'] ?? ''),
            'issue_description' => (string)($report['issue_description'] ?? ''),
            'description' => (string)($report['issue_description'] ?? ''),
            'priority' => (string)($report['priority'] ?? ''),
            'status' => $this->mapReportStatus((string)($report['status'] ?? 'reported')),
            'raw_status' => (string)($report['status'] ?? 'reported'),
            'report_date' => (string)($report['report_date'] ?? ''),
            'completion_date' => (string)($report['completion_date'] ?? ''),
            'remarks' => (string)($report['technician_notes'] ?? $report['verification_notes'] ?? ''),
            'photo_url' => $photos[0] ?? (string)($report['photo_path'] ?? ''),
            'photos' => $photos,
        ];

        if ($includeDetails) {
            $formatted['assigned_date'] = (string)($report['assigned_date'] ?? '');
            $formatted['verification_notes'] = (string)($report['verification_notes'] ?? '');
        }

        return $formatted;
    }

    private function mapReportStatus(string $status): string
    {
        return match (strtolower($status)) {
            'reported', 'pending', 'pmo_review', 'ready_for_assignment' => 'pending',
            'assigned', 'in_progress', 'for_replacement' => 'in_progress',
            'completed', 'verified', 'closed' => 'completed',
            'rejected' => 'rejected',
            default => 'pending',
        };
    }

    private function notificationTitle(string $type): string
    {
        return match ($type) {
            'new_defect_report', 'defect_report' => 'New Report',
            'new_reservation' => 'Reservation Update',
            'task_completed', 'completed' => 'Task Completed',
            'support_response' => 'Support Reply',
            default => 'Notification',
        };
    }

    private function inferPriority(string $description): string
    {
        $text = strtolower($description);
        foreach (['urgent', 'fire', 'smoke', 'spark', 'shock', 'offline', 'no power'] as $word) {
            if (strpos($text, $word) !== false) {
                return 'critical';
            }
        }
        foreach (['broken', 'not working', 'failed', 'damaged', 'error', 'black screen'] as $word) {
            if (strpos($text, $word) !== false) {
                return 'high';
            }
        }
        foreach (['minor', 'loose', 'slow', 'small'] as $word) {
            if (strpos($text, $word) !== false) {
                return 'low';
            }
        }
        return 'medium';
    }

    private function saveUploadedPhotos(string $reportId, array $files): array
    {
        $saved = [];
        $uploads = [];

        if (isset($files['photo']) && is_uploaded_file($files['photo']['tmp_name'] ?? '')) {
            $uploads[] = $files['photo'];
        }

        if (isset($files['defect_photos'])) {
            $batch = $files['defect_photos'];
            if (is_array($batch['name'] ?? null)) {
                foreach (($batch['name'] ?? []) as $index => $name) {
                    $uploads[] = [
                        'name' => $name,
                        'type' => $batch['type'][$index] ?? '',
                        'tmp_name' => $batch['tmp_name'][$index] ?? '',
                        'error' => $batch['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $batch['size'][$index] ?? 0,
                    ];
                }
            }
        }

        $targetDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'reports';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        foreach ($uploads as $index => $upload) {
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

            $tmpName = (string)($upload['tmp_name'] ?? '');
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                continue;
            }

            $extension = strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                continue;
            }

            $filename = $reportId . ($index > 0 ? '-' . $index : '') . '.' . $extension;
            $destination = $targetDir . DIRECTORY_SEPARATOR . $filename;
            if (move_uploaded_file($tmpName, $destination)) {
                $saved[] = 'uploads/reports/' . $filename;
            }
        }

        return $saved;
    }

    private function notifyWorkflowReviewers(string $reportId, string $equipmentId): void
    {
        $equipment = getEquipmentById($equipmentId);
        $equipmentName = trim((string)($equipment['equipment_name'] ?? $equipmentId));
        $message = 'New defect report ' . $reportId . ' submitted for ' . $equipmentName . ' and is awaiting PMO review.';

        $result = $this->conn->query("SELECT user_id FROM users WHERE role IN ('admin', 'pmo') AND status = 'active'");
        if (!$result) {
            return;
        }

        while ($row = $result->fetch_assoc()) {
            $adminId = trim((string)($row['user_id'] ?? ''));
            if ($adminId === '') {
                continue;
            }
            addNotification($adminId, $message, 'new_defect_report', $reportId);
        }
    }

    private function ensureManualCategoryId(string $category): ?int
    {
        $category = trim($category) !== '' ? trim($category) : 'Other / Not sure';

        $stmt = $this->conn->prepare("SELECT category_id FROM categories WHERE category_name = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $category);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!empty($row['category_id'])) {
            return (int)$row['category_id'];
        }

        $description = 'Created from a manual student report entry.';
        $stmt = $this->conn->prepare("INSERT INTO categories (category_name, description) VALUES (?, ?)");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $category, $description);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $categoryId = (int)$this->conn->insert_id;
        $stmt->close();

        return $categoryId > 0 ? $categoryId : null;
    }

    private function createManualEquipment(string $name, string $category, string $location): ?array
    {
        $name = trim($name);
        $category = trim($category) !== '' ? trim($category) : 'Other / Not sure';
        $location = trim($location);

        if ($name === '') {
            return null;
        }

        $seed = strtoupper(substr(md5($name . '|' . $location . '|' . microtime(true)), 0, 10));
        $categoryId = $this->ensureManualCategoryId($category);
        $description = 'Manual student report entry. Review and merge with inventory if needed.';

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $equipmentId = 'MAN-' . $seed;
            $assetTag = 'MAN-' . $seed;
            $stmt = $this->conn->prepare("INSERT INTO equipment (equipment_id, asset_tag, equipment_name, category_id, description, location, status, condition_status, quantity, min_stock_level, reorder_point) VALUES (?, ?, ?, ?, ?, ?, 'available', 'fair', 1, 1, 0)");
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('sssiss', $equipmentId, $assetTag, $name, $categoryId, $description, $location);
            if ($stmt->execute()) {
                $stmt->close();
                return [
                    'id' => $equipmentId,
                    'name' => $name,
                    'category' => $category,
                    'asset_tag' => $assetTag,
                    'location' => $location,
                ];
            }
            $stmt->close();
            $seed = strtoupper(substr(md5($seed . '|' . $attempt . '|' . microtime(true)), 0, 10));
        }

        return null;
    }

    private function resolveEquipmentId(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }

        $equipment = getEquipmentById($reference);
        if (!empty($equipment['equipment_id'])) {
            return (string)$equipment['equipment_id'];
        }

        $stmt = $this->conn->prepare(
            "SELECT equipment_id
             FROM equipment
             WHERE equipment_id = ?
                OR asset_tag = ?
                OR equipment_name = ?
                OR equipment_name LIKE ?
             ORDER BY CASE WHEN equipment_name = ? THEN 0 ELSE 1 END, equipment_name ASC
             LIMIT 1"
        );
        $like = '%' . $reference . '%';
        $stmt->bind_param('sssss', $reference, $reference, $reference, $like, $reference);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (string)($row['equipment_id'] ?? '');
    }

    private function tableExists(string $table): bool
    {
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if ($safeTable === '') {
            return false;
        }
        $result = $this->conn->query("SHOW TABLES LIKE '{$safeTable}'");
        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
