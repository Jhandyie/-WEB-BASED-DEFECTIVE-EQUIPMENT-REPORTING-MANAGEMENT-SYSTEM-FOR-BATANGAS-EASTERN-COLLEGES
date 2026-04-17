<?php

require_once __DIR__ . '/../config/database.php';

class DefectReport
{
    public const STATUS_PENDING = 'reported';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'closed';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_CRITICAL = 'critical';

    private mysqli $db;

    public function __construct($db = null)
    {
        $this->db = $db instanceof mysqli ? $db : getDBConnection();
    }

    public function create($data)
    {
        try {
            $payload = $this->buildCreatePayload((array)$data);
            if (!$payload['success']) {
                return $payload;
            }

            $saved = addDefectReport($payload['data']);
            if (!$saved) {
                return ['success' => false, 'message' => 'Failed to create report'];
            }

            return [
                'success' => true,
                'report_id' => $payload['data']['report_id'],
                'message' => 'Defect report submitted successfully',
                'priority' => $payload['data']['priority'],
            ];
        } catch (Throwable $e) {
            error_log('DefectReport::create() Error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateStatus($report_id, $new_status, $admin_id = null, $notes = null)
    {
        try {
            $report = getDefectReportById((string)$report_id);
            if (!$report) {
                return ['success' => false, 'message' => 'Report not found'];
            }

            $mappedStatus = $this->normalizeStatus((string)$new_status);
            $update = ['status' => $mappedStatus];

            if ($admin_id !== null && isset(getDefectReportColumns()['assigned_to'])) {
                $update['assigned_to'] = (string)$admin_id;
            }
            if ($notes !== null && isset(getDefectReportColumns()['technician_notes'])) {
                $update['technician_notes'] = (string)$notes;
            }
            if ($mappedStatus === 'completed' && isset(getDefectReportColumns()['completion_date'])) {
                $update['completion_date'] = date('Y-m-d H:i:s');
            }
            if ($mappedStatus === 'assigned' && isset(getDefectReportColumns()['assigned_date'])) {
                $update['assigned_date'] = date('Y-m-d H:i:s');
            }

            $ok = updateDefectReport((string)$report_id, $update);
            return [
                'success' => (bool)$ok,
                'message' => $ok ? 'Status updated successfully' : 'Failed to update status',
                'old_status' => (string)($report['status'] ?? ''),
                'new_status' => $mappedStatus,
            ];
        } catch (Throwable $e) {
            error_log('DefectReport::updateStatus() Error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updatePriority($report_id, $new_priority, $reason = null, $admin_id = null)
    {
        $priority = $this->normalizePriority((string)$new_priority);
        $ok = updateDefectReport((string)$report_id, ['priority' => $priority]);

        return [
            'success' => (bool)$ok,
            'message' => $ok ? 'Priority updated successfully' : 'Failed to update priority',
            'priority' => $priority,
        ];
    }

    public function getById($report_id)
    {
        return getDefectReportById((string)$report_id);
    }

    public function getAll($status = null, $priority = null, $search = '')
    {
        return getDefectReportsWithFilters(
            $status ?: 'all',
            $priority ?: 'all',
            (string)$search
        );
    }

    public function getUserReports($user_id)
    {
        return getUserDefectReports((string)$user_id);
    }

    public function assignToAdmin($report_id, $admin_id, $notes = null)
    {
        return $this->updateStatus((string)$report_id, 'assigned', $admin_id, $notes);
    }

    public function bulkUpdateStatus($report_ids, $new_status, $admin_id = null, $notes = null)
    {
        $results = ['success' => true, 'updated' => 0, 'errors' => []];
        foreach ((array)$report_ids as $reportId) {
            $result = $this->updateStatus((string)$reportId, $new_status, $admin_id, $notes);
            if (!($result['success'] ?? false)) {
                $results['success'] = false;
                $results['errors'][] = 'Report #' . $reportId . ': ' . ($result['message'] ?? 'Failed');
                continue;
            }
            $results['updated']++;
        }
        return $results;
    }

    public function delete($report_id)
    {
        try {
            $ok = deleteDefectReport((string)$report_id);
            return ['success' => (bool)$ok, 'message' => $ok ? 'Report deleted successfully' : 'Failed to delete report'];
        } catch (Throwable $e) {
            error_log('DefectReport::delete() Error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function buildCreatePayload(array $data): array
    {
        $equipmentId = trim((string)($data['equipment_id'] ?? ''));
        $description = trim((string)($data['issue_description'] ?? $data['description'] ?? ''));
        $reportedBy = trim((string)($data['reported_by'] ?? $data['user_id'] ?? ''));

        if ($equipmentId === '' || $description === '' || $reportedBy === '') {
            return ['success' => false, 'message' => 'Equipment, reporter, and description are required.'];
        }

        $reportId = trim((string)($data['report_id'] ?? ''));
        if ($reportId === '') {
            $reportId = 'BEC-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));
        }

        $photos = $this->storePhotosForReport($reportId, $data['photos'] ?? $data['defect_photos'] ?? []);
        $payload = [
            'report_id' => $reportId,
            'equipment_id' => $equipmentId,
            'reported_by' => $reportedBy,
            'issue_description' => $description,
            'priority' => $this->normalizePriority((string)($data['priority'] ?? $this->inferPriority($description))),
            'status' => $this->normalizeStatus((string)($data['status'] ?? 'reported')),
            'location' => trim((string)($data['location'] ?? '')),
            'category' => trim((string)($data['category'] ?? '')),
            'reporter_name' => trim((string)($data['reporter_name'] ?? '')),
            'reporter_email' => trim((string)($data['reporter_email'] ?? '')),
        ];

        if (!empty($photos)) {
            $payload['photo_path'] = $photos[0];
            $payload['defect_photos'] = $photos;
        }

        return ['success' => true, 'data' => $payload];
    }

    private function storePhotosForReport(string $reportId, $rawPhotos): array
    {
        $photos = [];
        $uploads = [];

        if (is_array($rawPhotos)) {
            $isUploadArray = isset($rawPhotos['tmp_name']) || isset($rawPhotos['name']);
            if ($isUploadArray && is_array($rawPhotos['tmp_name'] ?? null)) {
                foreach (($rawPhotos['tmp_name'] ?? []) as $index => $tmpName) {
                    $uploads[] = [
                        'tmp_name' => $tmpName,
                        'name' => $rawPhotos['name'][$index] ?? '',
                        'error' => $rawPhotos['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    ];
                }
            } elseif ($isUploadArray) {
                $uploads[] = $rawPhotos;
            } else {
                foreach ($rawPhotos as $path) {
                    $path = trim((string)$path);
                    if ($path !== '') {
                        $photos[] = str_replace('\\', '/', $path);
                    }
                }
            }
        } elseif (is_string($rawPhotos) && trim($rawPhotos) !== '') {
            $photos[] = str_replace('\\', '/', trim($rawPhotos));
        }

        if (empty($uploads)) {
            return array_values(array_unique($photos));
        }

        $uploadDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'reports';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        foreach ($uploads as $index => $upload) {
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

            $tmpName = (string)($upload['tmp_name'] ?? '');
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                continue;
            }

            $ext = strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                continue;
            }

            $filename = $reportId . ($index > 0 ? '-' . $index : '') . '.' . $ext;
            $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;
            if (move_uploaded_file($tmpName, $destination)) {
                $photos[] = 'uploads/reports/' . $filename;
            }
        }

        return array_values(array_unique($photos));
    }

    private function inferPriority(string $description): string
    {
        $text = strtolower($description);
        foreach (['urgent', 'fire', 'smoke', 'spark', 'shock', 'offline', 'no power'] as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return self::PRIORITY_CRITICAL;
            }
        }
        foreach (['broken', 'not working', 'failed', 'damaged', 'error'] as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return self::PRIORITY_HIGH;
            }
        }
        foreach (['minor', 'loose', 'slow', 'small'] as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return self::PRIORITY_LOW;
            }
        }
        return self::PRIORITY_MEDIUM;
    }

    private function normalizePriority(string $priority): string
    {
        $priority = strtolower(trim($priority));
        return in_array($priority, ['low', 'medium', 'high', 'critical'], true) ? $priority : self::PRIORITY_MEDIUM;
    }

    private function normalizeStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'pending' => 'reported',
            'approved' => 'ready_for_assignment',
            'pmo_review', 'dean_review', 'finance_review', 'on_hold_budget', 'ready_for_assignment',
            'in_progress', 'assigned', 'for_replacement', 'completed', 'verified', 'closed', 'rejected', 'reported'
                => strtolower(trim($status)),
            default => 'reported',
        };
    }
}
