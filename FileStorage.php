<?php
/**
 * BEC Equipment Management System
 * File Storage Handler
 * 
 * Manages all system data in JSON files instead of database
 * Only user authentication uses MySQL database
 */

class FileStorage {
    private $dataDir = 'data/';
    private $uploadsDir = 'uploads/';
    
    public function __construct() {
        $this->ensureDirectories();
    }
    
    /**
     * Ensure all required directories exist
     */
    private function ensureDirectories() {
        $directories = [
            $this->dataDir,
            $this->uploadsDir,
            $this->uploadsDir . 'equipment/',
            $this->uploadsDir . 'defect_reports/',
            $this->uploadsDir . 'completed_work/',
            $this->uploadsDir . 'profiles/'
        ];
        
        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        
        // Create default JSON files if they don't exist
        $this->initializeDataFiles();
    }
    
    /**
     * Initialize data files with empty arrays
     */
    private function initializeDataFiles() {
        $files = [
            'equipment.json' => [],
            'categories.json' => $this->getDefaultCategories(),
            'defect_reports.json' => [],
            'reservations.json' => [],
            'maintenance_history.json' => [],
            'notifications.json' => []
        ];
        
        foreach ($files as $filename => $defaultData) {
            $filepath = $this->dataDir . $filename;
            if (!file_exists($filepath)) {
                $this->writeJsonFile($filepath, $defaultData);
            }
        }
    }
    
    /**
     * Get default equipment categories
     */
    private function getDefaultCategories() {
        return [
            [
                'category_id' => 'CAT-001',
                'name' => 'Sports Equipment',
                'description' => 'Balls, nets, and sports gear',
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'category_id' => 'CAT-002',
                'name' => 'Audio Visual',
                'description' => 'Projectors, screens, speakers',
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'category_id' => 'CAT-003',
                'name' => 'Furniture',
                'description' => 'Tables, chairs, storage',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ];
    }
    
    /**
     * Read JSON file
     */
    private function readJsonFile($filepath) {
        if (!file_exists($filepath)) {
            return [];
        }
        
        $content = file_get_contents($filepath);
        $data = json_decode($content, true);
        
        return $data !== null ? $data : [];
    }
    
    /**
     * Write JSON file
     */
    private function writeJsonFile($filepath, $data) {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return file_put_contents($filepath, $json, LOCK_EX);
    }
    
    // ============================================
    // EQUIPMENT METHODS
    // ============================================
    
    public function getAllEquipment() {
        return $this->readJsonFile($this->dataDir . 'equipment.json');
    }
    
    public function getEquipmentById($equipment_id) {
        $equipment = $this->getAllEquipment();
        foreach ($equipment as $item) {
            if ($item['equipment_id'] === $equipment_id) {
                return $item;
            }
        }
        return null;
    }
    
    public function addEquipment($data) {
        $equipment = $this->getAllEquipment();
        
        // Generate ID if not provided
        if (!isset($data['equipment_id'])) {
            $data['equipment_id'] = 'EQP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        }
        
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        $equipment[] = $data;
        
        return $this->writeJsonFile($this->dataDir . 'equipment.json', $equipment);
    }
    
    public function updateEquipment($equipment_id, $data) {
        $equipment = $this->getAllEquipment();
        
        foreach ($equipment as $key => $item) {
            if ($item['equipment_id'] === $equipment_id) {
                $data['updated_at'] = date('Y-m-d H:i:s');
                $equipment[$key] = array_merge($item, $data);
                return $this->writeJsonFile($this->dataDir . 'equipment.json', $equipment);
            }
        }
        
        return false;
    }
    
    public function deleteEquipment($equipment_id) {
        $equipment = $this->getAllEquipment();
        
        foreach ($equipment as $key => $item) {
            if ($item['equipment_id'] === $equipment_id) {
                unset($equipment[$key]);
                $equipment = array_values($equipment); // Re-index array
                return $this->writeJsonFile($this->dataDir . 'equipment.json', $equipment);
            }
        }
        
        return false;
    }
    
    public function getAvailableEquipment() {
        $equipment = $this->getAllEquipment();
        return array_filter($equipment, function($item) {
            return isset($item['status']) && $item['status'] === 'available';
        });
    }
    
    // ============================================
    // DEFECT REPORTS METHODS
    // ============================================
    
    public function getAllDefectReports() {
        return $this->readJsonFile($this->dataDir . 'defect_reports.json');
    }
    
    public function getDefectReportById($report_id) {
        $reports = $this->getAllDefectReports();
        foreach ($reports as $report) {
            if ($report['report_id'] === $report_id) {
                return $report;
            }
        }
        return null;
    }
    
    public function addDefectReport($data) {
        $reports = $this->getAllDefectReports();
        
        if (!isset($data['report_id'])) {
            $data['report_id'] = 'DR-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        }
        
        $data['report_date'] = date('Y-m-d H:i:s');
        $data['status'] = $data['status'] ?? 'reported';
        
        $reports[] = $data;
        
        return $this->writeJsonFile($this->dataDir . 'defect_reports.json', $reports);
    }
    
    public function updateDefectReport($report_id, $data) {
        $reports = $this->getAllDefectReports();
        
        foreach ($reports as $key => $report) {
            if ($report['report_id'] === $report_id) {
                $data['updated_at'] = date('Y-m-d H:i:s');
                $reports[$key] = array_merge($report, $data);
                return $this->writeJsonFile($this->dataDir . 'defect_reports.json', $reports);
            }
        }
        
        return false;
    }
    
    public function getDefectReportsByUser($user_id) {
        $reports = $this->getAllDefectReports();
        return array_filter($reports, function($report) use ($user_id) {
            return isset($report['reported_by']) && $report['reported_by'] === $user_id;
        });
    }
    
    public function getDefectReportsByTechnician($technician_id) {
        $reports = $this->getAllDefectReports();
        return array_filter($reports, function($report) use ($technician_id) {
            return isset($report['assigned_to']) && $report['assigned_to'] === $technician_id;
        });
    }
    
    public function getDefectReportsByStatus($status) {
        $reports = $this->getAllDefectReports();
        return array_filter($reports, function($report) use ($status) {
            return isset($report['status']) && $report['status'] === $status;
        });
    }
    
    // ============================================
    // RESERVATIONS METHODS
    // ============================================
    
    public function getAllReservations() {
        return $this->readJsonFile($this->dataDir . 'reservations.json');
    }
    
    public function getReservationById($reservation_id) {
        $reservations = $this->getAllReservations();
        foreach ($reservations as $reservation) {
            if ($reservation['reservation_id'] === $reservation_id) {
                return $reservation;
            }
        }
        return null;
    }
    
    public function addReservation($data) {
        $reservations = $this->getAllReservations();
        
        if (!isset($data['reservation_id'])) {
            $data['reservation_id'] = 'RES-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        }
        
        $data['request_date'] = date('Y-m-d H:i:s');
        $data['status'] = $data['status'] ?? 'pending';
        
        $reservations[] = $data;
        
        return $this->writeJsonFile($this->dataDir . 'reservations.json', $reservations);
    }
    
    public function updateReservation($reservation_id, $data) {
        $reservations = $this->getAllReservations();
        
        foreach ($reservations as $key => $reservation) {
            if ($reservation['reservation_id'] === $reservation_id) {
                $data['updated_at'] = date('Y-m-d H:i:s');
                $reservations[$key] = array_merge($reservation, $data);
                return $this->writeJsonFile($this->dataDir . 'reservations.json', $reservations);
            }
        }
        
        return false;
    }
    
    public function getReservationsByUser($user_id) {
        $reservations = $this->getAllReservations();
        return array_filter($reservations, function($reservation) use ($user_id) {
            return isset($reservation['user_id']) && $reservation['user_id'] === $user_id;
        });
    }
    
    public function getReservationsByStatus($status) {
        $reservations = $this->getAllReservations();
        return array_filter($reservations, function($reservation) use ($status) {
            return isset($reservation['status']) && $reservation['status'] === $status;
        });
    }
    
    public function checkReservationConflict($equipment_id, $start_date, $end_date, $exclude_id = null) {
        $reservations = $this->getAllReservations();
        
        foreach ($reservations as $reservation) {
            // Skip if this is the same reservation being updated
            if ($exclude_id && $reservation['reservation_id'] === $exclude_id) {
                continue;
            }
            
            // Skip cancelled or rejected reservations
            if (in_array($reservation['status'], ['cancelled', 'rejected'])) {
                continue;
            }
            
            // Check if equipment matches
            if ($reservation['equipment_id'] !== $equipment_id) {
                continue;
            }
            
            // Check for date overlap
            $resStart = strtotime($reservation['start_date']);
            $resEnd = strtotime($reservation['end_date']);
            $newStart = strtotime($start_date);
            $newEnd = strtotime($end_date);
            
            if (($newStart <= $resEnd) && ($newEnd >= $resStart)) {
                return true; // Conflict found
            }
        }
        
        return false; // No conflict
    }
    
    // ============================================
    // CATEGORIES METHODS
    // ============================================
    
    public function getAllCategories() {
        return $this->readJsonFile($this->dataDir . 'categories.json');
    }
    
    public function getCategoryById($category_id) {
        $categories = $this->getAllCategories();
        foreach ($categories as $category) {
            if ($category['category_id'] === $category_id) {
                return $category;
            }
        }
        return null;
    }
    
    public function addCategory($data) {
        $categories = $this->getAllCategories();
        
        if (!isset($data['category_id'])) {
            $count = count($categories) + 1;
            $data['category_id'] = 'CAT-' . str_pad($count, 3, '0', STR_PAD_LEFT);
        }
        
        $data['created_at'] = date('Y-m-d H:i:s');
        
        $categories[] = $data;
        
        return $this->writeJsonFile($this->dataDir . 'categories.json', $categories);
    }
    
    // ============================================
    // NOTIFICATIONS METHODS
    // ============================================
    
    public function getAllNotifications() {
        return $this->readJsonFile($this->dataDir . 'notifications.json');
    }
    
    public function addNotification($data) {
        $notifications = $this->getAllNotifications();
        
        if (!isset($data['notification_id'])) {
            $data['notification_id'] = 'NOT-' . uniqid();
        }
        
        $data['created_date'] = date('Y-m-d H:i:s');
        $data['is_read'] = false;
        
        $notifications[] = $data;
        
        return $this->writeJsonFile($this->dataDir . 'notifications.json', $notifications);
    }
    
    public function getNotificationsByUser($user_id) {
        $notifications = $this->getAllNotifications();
        return array_filter($notifications, function($notification) use ($user_id) {
            return isset($notification['user_id']) && $notification['user_id'] === $user_id;
        });
    }
    
    public function getUnreadNotifications($user_id) {
        $notifications = $this->getNotificationsByUser($user_id);
        return array_filter($notifications, function($notification) {
            return !$notification['is_read'];
        });
    }
    
    public function markNotificationAsRead($notification_id) {
        $notifications = $this->getAllNotifications();
        
        foreach ($notifications as $key => $notification) {
            if ($notification['notification_id'] === $notification_id) {
                $notifications[$key]['is_read'] = true;
                $notifications[$key]['read_at'] = date('Y-m-d H:i:s');
                return $this->writeJsonFile($this->dataDir . 'notifications.json', $notifications);
            }
        }
        
        return false;
    }
    
    // ============================================
    // MAINTENANCE HISTORY METHODS
    // ============================================
    
    public function addMaintenanceHistory($data) {
        $history = $this->readJsonFile($this->dataDir . 'maintenance_history.json');
        
        if (!isset($data['history_id'])) {
            $data['history_id'] = 'MH-' . uniqid();
        }
        
        $data['maintenance_date'] = date('Y-m-d H:i:s');
        
        $history[] = $data;
        
        return $this->writeJsonFile($this->dataDir . 'maintenance_history.json', $history);
    }
    
    public function getMaintenanceHistoryByEquipment($equipment_id) {
        $history = $this->readJsonFile($this->dataDir . 'maintenance_history.json');
        return array_filter($history, function($record) use ($equipment_id) {
            return isset($record['equipment_id']) && $record['equipment_id'] === $equipment_id;
        });
    }
    
    // ============================================
    // FILE UPLOAD METHODS
    // ============================================
    
    public function uploadFile($file, $subfolder = '') {
        $uploadDir = $this->uploadsDir . $subfolder;
        
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $filename = uniqid() . '_' . basename($file['name']);
        $filepath = $uploadDir . '/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            return $filepath;
        }
        
        return false;
    }
    
    public function deleteFile($filepath) {
        if (file_exists($filepath)) {
            return unlink($filepath);
        }
        return false;
    }
    
    // ============================================
    // STATISTICS METHODS
    // ============================================
    
    public function getStatistics() {
        $equipment = $this->getAllEquipment();
        $reports = $this->getAllDefectReports();
        $reservations = $this->getAllReservations();
        
        $stats = [
            'total_equipment' => count($equipment),
            'available_equipment' => count(array_filter($equipment, function($item) {
                return $item['status'] === 'available';
            })),
            'defective_equipment' => count(array_filter($equipment, function($item) {
                return $item['status'] === 'defective';
            })),
            'maintenance_equipment' => count(array_filter($equipment, function($item) {
                return $item['status'] === 'maintenance';
            })),
            'total_reports' => count($reports),
            'pending_reports' => count(array_filter($reports, function($item) {
                return $item['status'] === 'reported';
            })),
            'in_progress_reports' => count(array_filter($reports, function($item) {
                return $item['status'] === 'in_progress';
            })),
            'completed_reports' => count(array_filter($reports, function($item) {
                return $item['status'] === 'completed';
            })),
            'total_reservations' => count($reservations),
            'pending_reservations' => count(array_filter($reservations, function($item) {
                return $item['status'] === 'pending';
            })),
            'approved_reservations' => count(array_filter($reservations, function($item) {
                return $item['status'] === 'approved';
            }))
        ];
        
        return $stats;
    }
    
    // ============================================
    // BACKUP METHODS
    // ============================================
    
    public function createBackup() {
        $backupDir = $this->dataDir . 'backups/';
        
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = $backupDir . 'backup_' . $timestamp . '.zip';
        
        $zip = new ZipArchive();
        
        if ($zip->open($backupFile, ZipArchive::CREATE) === TRUE) {
            // Add all JSON files
            $files = glob($this->dataDir . '*.json');
            foreach ($files as $file) {
                $zip->addFile($file, basename($file));
            }
            
            $zip->close();
            return $backupFile;
        }
        
        return false;
    }
}

// Initialize global file storage instance
$fileStorage = new FileStorage();
?>