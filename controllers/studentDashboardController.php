<?php
/**
 * Student Dashboard Controller
 * Handles all backend logic for student dashboard functionality
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/DefectReport.php';

class StudentDashboardController {
    private $db;
    private $defectReport;
    
    public function __construct() {
        $database = Database::getInstance();
        $this->db = $database->getConnection();
        $this->defectReport = new DefectReport($this->db);
    }
    
    /**
     * Get dashboard statistics for user
     */
    public function getDashboardStats($user_id) {
        try {
            // Get defect report statistics
            $reportStats = $this->defectReport->getStatistics($user_id);
            
            // Get reservation statistics
            $reservationStats = $this->getReservationStats($user_id);
            
            // Get equipment statistics (public)
            $equipmentStats = $this->getEquipmentStats();
            
            // Get notification count
            $notificationCount = $this->getUnreadNotificationCount($user_id);
            
            return [
                'success' => true,
                'data' => [
                    'reports' => $reportStats,
                    'reservations' => $reservationStats,
                    'equipment' => $equipmentStats,
                    'notifications' => $notificationCount
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Submit defect report
     */
    public function submitDefectReport($data) {
        if (!isset($_SESSION['user_id'])) {
            return ['success' => false, 'message' => 'User not authenticated'];
        }
        
        $data['user_id'] = $_SESSION['user_id'];
        
        // Handle multiple photos
        if (isset($_FILES['defect_photos'])) {
            $photos = [];
            $fileCount = count($_FILES['defect_photos']['name']);
            
            for ($i = 0; $i < $fileCount; $i++) {
                if ($_FILES['defect_photos']['error'][$i] === UPLOAD_ERR_OK) {
                    $photos[] = [
                        'name' => $_FILES['defect_photos']['name'][$i],
                        'type' => $_FILES['defect_photos']['type'][$i],
                        'tmp_name' => $_FILES['defect_photos']['tmp_name'][$i],
                        'error' => $_FILES['defect_photos']['error'][$i],
                        'size' => $_FILES['defect_photos']['size'][$i]
                    ];
                }
            }
            
            $data['photos'] = $photos;
        }
        
        return $this->defectReport->create($data);
    }
    
    /**
     * Get user's defect reports
     */
    public function getMyReports($user_id, $limit = null) {
        try {
            $reports = $this->defectReport->getUserReports($user_id, $limit);
            
            return [
                'success' => true,
                'data' => $reports
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get recent reports (public view)
     */
    public function getRecentReports($limit = 5) {
        try {
            $reports = $this->defectReport->getAll(['limit' => $limit]);
            
            // Filter to show only active reports
            $activeReports = array_filter($reports, function($report) {
                return in_array($report['status'], ['pending', 'in_progress']);
            });
            
            return [
                'success' => true,
                'data' => array_slice($activeReports, 0, $limit)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get report details
     */
    public function getReportDetails($report_id) {
        try {
            $report = $this->defectReport->getById($report_id);
            
            if (!$report) {
                return ['success' => false, 'message' => 'Report not found'];
            }
            
            // Get status history
            $statusHistory = $this->defectReport->getStatusHistory($report_id);
            
            return [
                'success' => true,
                'data' => [
                    'report' => $report,
                    'history' => $statusHistory
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get equipment for reservation
     */
    public function getAvailableEquipment() {
        try {
            $query = "SELECT e.*, 
                            COALESCE(SUM(CASE WHEN r.status = 'approved' 
                                         AND r.reservation_date >= CURDATE() 
                                         THEN r.quantity ELSE 0 END), 0) as reserved_qty
                     FROM equipment e
                     LEFT JOIN reservations r ON e.id = r.equipment_id
                     WHERE e.status = 'active' AND e.quantity > 0
                     GROUP BY e.id
                     HAVING (e.quantity - reserved_qty) > 0
                     ORDER BY e.equipment_name ASC";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create equipment reservation
     */
    public function createReservation($data) {
        try {
            if (!isset($_SESSION['user_id'])) {
                return ['success' => false, 'message' => 'User not authenticated'];
            }
            
            // Validate equipment availability
            $availability = $this->checkEquipmentAvailability(
                $data['equipment_id'], 
                $data['reservation_date'], 
                $data['quantity']
            );
            
            if (!$availability['available']) {
                return [
                    'success' => false, 
                    'message' => 'Equipment not available for selected date and quantity. Only ' . $availability['available_quantity'] . ' units available.'
                ];
            }
            
            $query = "INSERT INTO reservations 
                     (user_id, equipment_id, reservation_date, return_date, quantity, purpose, 
                      contact_person, contact_number, department, special_instructions, 
                      status, request_date) 
                     VALUES (:user_id, :equipment_id, :reservation_date, :return_date, :quantity, :purpose,
                             :contact_person, :contact_number, :department, :special_instructions,
                             'pending', NOW())";
            
            $stmt = $this->db->prepare($query);
            
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->bindParam(':equipment_id', $data['equipment_id']);
            $stmt->bindParam(':reservation_date', $data['reservation_date']);
            $stmt->bindParam(':return_date', $data['return_date']);
            $stmt->bindParam(':quantity', $data['quantity']);
            $stmt->bindParam(':purpose', $data['purpose']);
            $stmt->bindParam(':contact_person', $data['contact_person']);
            $stmt->bindParam(':contact_number', $data['contact_number']);
            $stmt->bindParam(':department', $data['department']);
            $stmt->bindParam(':special_instructions', $data['special_instructions']);
            
            if ($stmt->execute()) {
                $reservation_id = $this->db->lastInsertId();
                
                // Create notification
                $this->createNotification(
                    $_SESSION['user_id'], 
                    'reservation', 
                    'Reservation Request Submitted',
                    'Your equipment reservation request has been submitted for approval.',
                    $reservation_id
                );
                
                return [
                    'success' => true,
                    'reservation_id' => $reservation_id,
                    'message' => 'Reservation request submitted successfully! You will be notified once it is reviewed.'
                ];
            }
            
            return ['success' => false, 'message' => 'Failed to create reservation'];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get user's reservations
     */
    public function getMyReservations($user_id, $limit = null) {
        try {
            $query = "SELECT r.*, 
                            e.equipment_name,
                            e.equipment_category,
                            e.location
                     FROM reservations r
                     LEFT JOIN equipment e ON r.equipment_id = e.id
                     WHERE r.user_id = :user_id
                     ORDER BY r.request_date DESC";
            
            if ($limit) {
                $query .= " LIMIT :limit";
            }
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            
            if ($limit) {
                $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            }
            
            $stmt->execute();
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get user notifications
     */
    public function getNotifications($user_id, $limit = 10, $unread_only = false) {
        try {
            $query = "SELECT * FROM notifications 
                     WHERE user_id = :user_id";
            
            if ($unread_only) {
                $query .= " AND is_read = 0";
            }
            
            $query .= " ORDER BY created_at DESC LIMIT :limit";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Mark notification as read
     */
    public function markNotificationRead($notification_id, $user_id) {
        try {
            $query = "UPDATE notifications 
                     SET is_read = 1, read_at = NOW() 
                     WHERE id = :id AND user_id = :user_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $notification_id);
            $stmt->bindParam(':user_id', $user_id);
            
            return [
                'success' => $stmt->execute(),
                'message' => 'Notification marked as read'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Mark all notifications as read
     */
    public function markAllNotificationsRead($user_id) {
        try {
            $query = "UPDATE notifications 
                     SET is_read = 1, read_at = NOW() 
                     WHERE user_id = :user_id AND is_read = 0";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            
            return [
                'success' => $stmt->execute(),
                'message' => 'All notifications marked as read'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check equipment availability
     */
    private function checkEquipmentAvailability($equipment_id, $date, $quantity) {
        $query = "SELECT e.quantity,
                        COALESCE(SUM(CASE WHEN r.status = 'approved' 
                                     AND r.reservation_date <= :date 
                                     AND r.return_date >= :date 
                                     THEN r.quantity ELSE 0 END), 0) as reserved
                 FROM equipment e
                 LEFT JOIN reservations r ON e.id = r.equipment_id
                 WHERE e.id = :equipment_id
                 GROUP BY e.id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':equipment_id', $equipment_id);
        $stmt->bindParam(':date', $date);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return ['available' => false];
        }
        
        $available_qty = $result['quantity'] - $result['reserved'];
        
        return [
            'available' => $available_qty >= $quantity,
            'available_quantity' => $available_qty
        ];
    }
    
    /**
     * Get reservation statistics
     */
    private function getReservationStats($user_id) {
        $query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                 FROM reservations
                 WHERE user_id = :user_id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get equipment statistics
     */
    private function getEquipmentStats() {
        $query = "SELECT 
                    COUNT(*) as total_items,
                    SUM(quantity) as total_quantity,
                    SUM(CASE WHEN status = 'active' THEN quantity ELSE 0 END) as available_quantity
                 FROM equipment";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get unread notification count
     */
    private function getUnreadNotificationCount($user_id) {
        $query = "SELECT COUNT(*) as count 
                 FROM notifications 
                 WHERE user_id = :user_id AND is_read = 0";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'];
    }
    
    /**
     * Create notification
     */
    private function createNotification($user_id, $type, $title, $message, $related_id = null) {
        $query = "INSERT INTO notifications 
                 (user_id, type, title, message, related_id, created_at) 
                 VALUES (:user_id, :type, :title, :message, :related_id, NOW())";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':related_id', $related_id);
        
        return $stmt->execute();
    }
    
    /**
     * Get report status updates (for real-time tracking)
     */
    public function getReportStatusUpdates($user_id, $since_timestamp = null) {
        try {
            $query = "SELECT dr.id, dr.status, dr.equipment_id, e.equipment_name,
                            dr.report_date, dr.completed_date,
                            (SELECT MAX(changed_date) FROM defect_report_status_history 
                             WHERE report_id = dr.id) as last_update
                     FROM defect_reports dr
                     LEFT JOIN equipment e ON dr.equipment_id = e.id
                     WHERE dr.user_id = :user_id";
            
            if ($since_timestamp) {
                $query .= " AND (SELECT MAX(changed_date) FROM defect_report_status_history 
                            WHERE report_id = dr.id) > :since";
            }
            
            $query .= " ORDER BY dr.report_date DESC";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            
            if ($since_timestamp) {
                $stmt->bindParam(':since', $since_timestamp);
            }
            
            $stmt->execute();
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}