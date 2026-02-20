<?php
/**
 * DefectReport Model - Enhanced Version
 * Handles defect report data management with status tracking, photo uploads, notifications,
 * analytics, priority auto-detection, and advanced features
 * 
 * @version 2.0
 * @author Enhanced Team
 */

class DefectReport {
    private $db;
    private $table = 'defect_reports';
    
    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';
    
    // Priority constants
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_CRITICAL = 'critical';
    
    // Category constants for issue types
    const CATEGORY_HARDWARE = 'hardware';
    const CATEGORY_SOFTWARE = 'software';
    const CATEGORY_PHYSICAL_DAMAGE = 'physical_damage';
    const CATEGORY_PERFORMANCE = 'performance';
    const CATEGORY_OTHER = 'other';
    
    // Properties
    public $id;
    public $user_id;
    public $equipment_id;
    public $issue_description;
    public $photo_path;
    public $status;
    public $priority;
    public $report_date;
    public $assigned_to;
    public $completed_date;
    public $admin_notes;
    public $category;
    public $estimated_repair_time;
    public $actual_repair_time;
    public $cost_estimate;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Create a new defect report with enhanced features
     */
    public function create($data) {
        try {
            // Validate required fields
            $this->validateReportData($data);
            
            // Auto-detect priority based on keywords if not provided
            if (!isset($data['priority']) || empty($data['priority'])) {
                $data['priority'] = $this->detectPriority($data['issue_description']);
            }
            
            // Auto-detect category based on description
            if (!isset($data['category'])) {
                $data['category'] = $this->detectCategory($data['issue_description']);
            }
            
            // Handle multiple photo uploads with compression
            $photo_paths = [];
            if (isset($data['photos']) && is_array($data['photos'])) {
                foreach ($data['photos'] as $photo) {
                    if ($photo['error'] === UPLOAD_ERR_OK) {
                        $photo_path = $this->uploadPhoto($photo, true); // Enable compression
                        if ($photo_path) {
                            $photo_paths[] = $photo_path;
                        }
                    }
                }
            }
            
            // Convert photo paths array to JSON
            $photo_paths_json = !empty($photo_paths) ? json_encode($photo_paths) : null;
            
            // Check for duplicate reports (same equipment, similar issue, within 24 hours)
            if ($this->isDuplicateReport($data)) {
                return [
                    'success' => false, 
                    'message' => 'A similar report for this equipment already exists.',
                    'is_duplicate' => true
                ];
            }
            
            $query = "INSERT INTO " . $this->table . " 
                     (user_id, equipment_id, issue_description, location, photo_paths, 
                      status, priority, category, report_date, estimated_repair_time) 
                     VALUES (:user_id, :equipment_id, :issue_description, :location, :photo_paths, 
                             :status, :priority, :category, NOW(), :estimated_repair_time)";
            
            $stmt = $this->db->prepare($query);
            
            $stmt->bindParam(':user_id', $data['user_id']);
            $stmt->bindParam(':equipment_id', $data['equipment_id']);
            $stmt->bindParam(':issue_description', $data['issue_description']);
            $stmt->bindParam(':location', $data['location']);
            $stmt->bindParam(':photo_paths', $photo_paths_json);
            $status = self::STATUS_PENDING;
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':priority', $data['priority']);
            $stmt->bindParam(':category', $data['category']);
            
            // Estimate repair time based on priority and category
            $estimated_time = $this->estimateRepairTime($data['priority'], $data['category']);
            $stmt->bindParam(':estimated_repair_time', $estimated_time);
            
            if ($stmt->execute()) {
                $this->id = $this->db->lastInsertId();
                
                // Create status history entry
                $this->addStatusHistory($this->id, self::STATUS_PENDING, 'Report created', $data['user_id']);
                
                // Send notification
                $this->createNotification($this->id, $data['user_id'], 'report_created');
                
                // Notify admins for high/critical priority
                if (in_array($data['priority'], [self::PRIORITY_HIGH, self::PRIORITY_CRITICAL])) {
                    $this->notifyAdmins($this->id, $data['priority']);
                }
                
                // Log activity
                $this->logActivity($this->id, 'created', $data['user_id']);
                
                // Update equipment status
                $this->updateEquipmentStatus($data['equipment_id'], 'defective');
                
                return [
                    'success' => true,
                    'report_id' => $this->id,
                    'message' => 'Defect report submitted successfully',
                    'priority' => $data['priority'],
                    'estimated_repair_time' => $estimated_time . ' hours'
                ];
            }
            
            return ['success' => false, 'message' => 'Failed to create report'];
            
        } catch (Exception $e) {
            error_log("DefectReport::create() Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Update report status with enhanced tracking
     */
    public function updateStatus($report_id, $new_status, $admin_id = null, $notes = null) {
        try {
            $old_status = $this->getStatus($report_id);
            
            // Validate status transition
            if (!$this->isValidStatusTransition($old_status, $new_status)) {
                return [
                    'success' => false, 
                    'message' => 'Invalid status transition from ' . $old_status . ' to ' . $new_status
                ];
            }
            
            $query = "UPDATE " . $this->table . " 
                     SET status = :status, 
                         assigned_to = :assigned_to,
                         admin_notes = :admin_notes,
                         completed_date = :completed_date,
                         updated_at = NOW()
                     WHERE id = :id";
            
            $stmt = $this->db->prepare($query);
            
            $completed_date = ($new_status === self::STATUS_COMPLETED) ? date('Y-m-d H:i:s') : null;
            
            $stmt->bindParam(':status', $new_status);
            $stmt->bindParam(':assigned_to', $admin_id);
            $stmt->bindParam(':admin_notes', $notes);
            $stmt->bindParam(':completed_date', $completed_date);
            $stmt->bindParam(':id', $report_id);
            
            if ($stmt->execute()) {
                // Add to status history
                $this->addStatusHistory($report_id, $new_status, $notes, $admin_id);
                
                // Get report details
                $report = $this->getById($report_id);
                
                // Calculate actual repair time if completed
                if ($new_status === self::STATUS_COMPLETED) {
                    $this->calculateActualRepairTime($report_id);
                }
                
                // Send notification to user
                $this->createNotification($report_id, $report['user_id'], 'status_updated', $new_status);
                
                // Update equipment status if completed
                if ($new_status === self::STATUS_COMPLETED) {
                    $this->updateEquipmentStatus($report['equipment_id'], 'available');
                }
                
                // Log activity
                $this->logActivity($report_id, 'status_updated', $admin_id, [
                    'old_status' => $old_status,
                    'new_status' => $new_status
                ]);
                
                return [
                    'success' => true,
                    'message' => 'Status updated successfully',
                    'old_status' => $old_status,
                    'new_status' => $new_status
                ];
            }
            
            return ['success' => false, 'message' => 'Failed to update status'];
            
        } catch (Exception $e) {
            error_log("DefectReport::updateStatus() Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Update priority with recalculation
     */
    public function updatePriority($report_id, $new_priority, $reason = null, $admin_id = null) {
        try {
            $query = "UPDATE " . $this->table . " 
                     SET priority = :priority,
                         updated_at = NOW()
                     WHERE id = :id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':priority', $new_priority);
            $stmt->bindParam(':id', $report_id);
            
            if ($stmt->execute()) {
                // Log priority change
                $this->addStatusHistory($report_id, null, "Priority changed to: $new_priority. Reason: $reason", $admin_id);
                
                // Get report details
                $report = $this->getById($report_id);
                
                // Notify user
                $this->createNotification($report_id, $report['user_id'], 'priority_updated', $new_priority);
                
                return [
                    'success' => true,
                    'message' => 'Priority updated successfully',
                    'new_priority' => $new_priority
                ];
            }
            
            return ['success' => false, 'message' => 'Failed to update priority'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get report by ID with extended information
     */
    public function getById($id) {
        $query = "SELECT dr.*, 
                         u.fullname as reporter_name,
                         u.email as reporter_email,
                         u.phone as reporter_phone,
                         e.equipment_name,
                         e.equipment_category,
                         e.location as equipment_location,
                         e.serial_number,
                         e.brand,
                         e.model,
                         a.fullname as assigned_admin_name,
                         a.email as assigned_admin_email,
                         TIMESTAMPDIFF(HOUR, dr.report_date, COALESCE(dr.completed_date, NOW())) as age_hours,
                         TIMESTAMPDIFF(DAY, dr.report_date, COALESCE(dr.completed_date, NOW())) as age_days
                  FROM " . $this->table . " dr
                  LEFT JOIN users u ON dr.user_id = u.id
                  LEFT JOIN equipment e ON dr.equipment_id = e.id
                  LEFT JOIN users a ON dr.assigned_to = a.id
                  WHERE dr.id = :id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Decode photo paths if exists
        if ($report && !empty($report['photo_paths'])) {
            $report['photo_paths_array'] = json_decode($report['photo_paths'], true);
        }
        
        return $report;
    }
    
    /**
     * Get all reports with advanced filters
     */
    public function getAll($filters = []) {
        $query = "SELECT dr.*, 
                         u.fullname as reporter_name,
                         e.equipment_name,
                         e.equipment_category,
                         e.location,
                         TIMESTAMPDIFF(HOUR, dr.report_date, COALESCE(dr.completed_date, NOW())) as age_hours
                  FROM " . $this->table . " dr
                  LEFT JOIN users u ON dr.user_id = u.id
                  LEFT JOIN equipment e ON dr.equipment_id = e.id
                  WHERE 1=1";
        
        $params = [];
        
        // User filter
        if (isset($filters['user_id'])) {
            $query .= " AND dr.user_id = :user_id";
            $params[':user_id'] = $filters['user_id'];
        }
        
        // Status filter
        if (isset($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = [];
                foreach ($filters['status'] as $idx => $status) {
                    $key = ":status_$idx";
                    $placeholders[] = $key;
                    $params[$key] = $status;
                }
                $query .= " AND dr.status IN (" . implode(',', $placeholders) . ")";
            } else {
                $query .= " AND dr.status = :status";
                $params[':status'] = $filters['status'];
            }
        }
        
        // Priority filter
        if (isset($filters['priority'])) {
            if (is_array($filters['priority'])) {
                $placeholders = [];
                foreach ($filters['priority'] as $idx => $priority) {
                    $key = ":priority_$idx";
                    $placeholders[] = $key;
                    $params[$key] = $priority;
                }
                $query .= " AND dr.priority IN (" . implode(',', $placeholders) . ")";
            } else {
                $query .= " AND dr.priority = :priority";
                $params[':priority'] = $filters['priority'];
            }
        }
        
        // Category filter
        if (isset($filters['category'])) {
            $query .= " AND dr.category = :category";
            $params[':category'] = $filters['category'];
        }
        
        // Equipment filter
        if (isset($filters['equipment_id'])) {
            $query .= " AND dr.equipment_id = :equipment_id";
            $params[':equipment_id'] = $filters['equipment_id'];
        }
        
        // Date range filter
        if (isset($filters['date_from'])) {
            $query .= " AND dr.report_date >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        
        if (isset($filters['date_to'])) {
            $query .= " AND dr.report_date <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        
        // Search filter
        if (isset($filters['search'])) {
            $query .= " AND (dr.issue_description LIKE :search 
                        OR e.equipment_name LIKE :search 
                        OR u.fullname LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        // Assigned to filter
        if (isset($filters['assigned_to'])) {
            $query .= " AND dr.assigned_to = :assigned_to";
            $params[':assigned_to'] = $filters['assigned_to'];
        }
        
        // Unassigned filter
        if (isset($filters['unassigned']) && $filters['unassigned']) {
            $query .= " AND dr.assigned_to IS NULL";
        }
        
        // Overdue filter (estimated time exceeded)
        if (isset($filters['overdue']) && $filters['overdue']) {
            $query .= " AND dr.status NOT IN ('completed', 'rejected', 'cancelled')
                       AND TIMESTAMPDIFF(HOUR, dr.report_date, NOW()) > dr.estimated_repair_time";
        }
        
        // Sorting
        $sort_field = $filters['sort_by'] ?? 'report_date';
        $sort_order = $filters['sort_order'] ?? 'DESC';
        $allowed_sorts = ['report_date', 'priority', 'status', 'equipment_name', 'age_hours'];
        
        if (in_array($sort_field, $allowed_sorts)) {
            $query .= " ORDER BY " . $sort_field . " " . $sort_order;
        } else {
            $query .= " ORDER BY dr.report_date DESC";
        }
        
        // Pagination
        if (isset($filters['limit'])) {
            $query .= " LIMIT :limit";
            if (isset($filters['offset'])) {
                $query .= " OFFSET :offset";
            }
        }
        
        $stmt = $this->db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if (isset($filters['limit'])) {
            $stmt->bindValue(':limit', (int)$filters['limit'], PDO::PARAM_INT);
            if (isset($filters['offset'])) {
                $stmt->bindValue(':offset', (int)$filters['offset'], PDO::PARAM_INT);
            }
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get user's reports with statistics
     */
    public function getUserReports($user_id, $limit = null) {
        return $this->getAll(['user_id' => $user_id, 'limit' => $limit]);
    }
    
    /**
     * Get enhanced statistics with analytics
     */
    public function getStatistics($user_id = null, $date_range = null) {
        $base_query = "SELECT 
                          COUNT(*) as total,
                          SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                          SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                          SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                          SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                          SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                          SUM(CASE WHEN priority = 'low' THEN 1 ELSE 0 END) as priority_low,
                          SUM(CASE WHEN priority = 'medium' THEN 1 ELSE 0 END) as priority_medium,
                          SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as priority_high,
                          SUM(CASE WHEN priority = 'critical' THEN 1 ELSE 0 END) as priority_critical,
                          AVG(CASE WHEN status = 'completed' THEN actual_repair_time END) as avg_repair_time,
                          AVG(CASE WHEN status = 'completed' THEN cost_estimate END) as avg_cost,
                          SUM(CASE WHEN status NOT IN ('completed', 'rejected', 'cancelled') 
                              AND TIMESTAMPDIFF(HOUR, report_date, NOW()) > estimated_repair_time 
                              THEN 1 ELSE 0 END) as overdue
                       FROM " . $this->table . "
                       WHERE 1=1";
        
        $params = [];
        
        if ($user_id) {
            $base_query .= " AND user_id = :user_id";
            $params[':user_id'] = $user_id;
        }
        
        if ($date_range) {
            if (isset($date_range['from'])) {
                $base_query .= " AND report_date >= :date_from";
                $params[':date_from'] = $date_range['from'];
            }
            if (isset($date_range['to'])) {
                $base_query .= " AND report_date <= :date_to";
                $params[':date_to'] = $date_range['to'];
            }
        }
        
        $stmt = $this->db->prepare($base_query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate additional metrics
        $stats['completion_rate'] = $stats['total'] > 0 
            ? round(($stats['completed'] / $stats['total']) * 100, 2) 
            : 0;
        
        $stats['avg_repair_time'] = round($stats['avg_repair_time'] ?? 0, 2);
        $stats['avg_cost'] = round($stats['avg_cost'] ?? 0, 2);
        
        return $stats;
    }
    
    /**
     * Get reports by category breakdown
     */
    public function getCategoryBreakdown($date_range = null) {
        $query = "SELECT category, 
                         COUNT(*) as count,
                         AVG(CASE WHEN status = 'completed' THEN actual_repair_time END) as avg_repair_time
                  FROM " . $this->table . "
                  WHERE 1=1";
        
        $params = [];
        
        if ($date_range) {
            if (isset($date_range['from'])) {
                $query .= " AND report_date >= :date_from";
                $params[':date_from'] = $date_range['from'];
            }
            if (isset($date_range['to'])) {
                $query .= " AND report_date <= :date_to";
                $params[':date_to'] = $date_range['to'];
            }
        }
        
        $query .= " GROUP BY category ORDER BY count DESC";
        
        $stmt = $this->db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get trending issues (most common problems)
     */
    public function getTrendingIssues($limit = 10, $days = 30) {
        $query = "SELECT issue_description,
                         equipment_id,
                         e.equipment_name,
                         COUNT(*) as occurrence_count,
                         MAX(report_date) as last_reported
                  FROM " . $this->table . " dr
                  LEFT JOIN equipment e ON dr.equipment_id = e.id
                  WHERE report_date >= DATE_SUB(NOW(), INTERVAL :days DAY)
                  GROUP BY issue_description, equipment_id
                  HAVING COUNT(*) > 1
                  ORDER BY occurrence_count DESC
                  LIMIT :limit";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get performance metrics for admins/technicians
     */
    public function getAdminPerformance($admin_id = null, $date_range = null) {
        $query = "SELECT assigned_to,
                         u.fullname as admin_name,
                         COUNT(*) as total_assigned,
                         SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                         AVG(CASE WHEN status = 'completed' THEN actual_repair_time END) as avg_resolution_time,
                         AVG(CASE WHEN status = 'completed' 
                             THEN TIMESTAMPDIFF(HOUR, report_date, completed_date) 
                             END) as avg_response_time
                  FROM " . $this->table . " dr
                  LEFT JOIN users u ON dr.assigned_to = u.id
                  WHERE assigned_to IS NOT NULL";
        
        $params = [];
        
        if ($admin_id) {
            $query .= " AND assigned_to = :admin_id";
            $params[':admin_id'] = $admin_id;
        }
        
        if ($date_range) {
            if (isset($date_range['from'])) {
                $query .= " AND report_date >= :date_from";
                $params[':date_from'] = $date_range['from'];
            }
            if (isset($date_range['to'])) {
                $query .= " AND report_date <= :date_to";
                $params[':date_to'] = $date_range['to'];
            }
        }
        
        $query .= " GROUP BY assigned_to ORDER BY completed DESC";
        
        $stmt = $this->db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Export reports to CSV
     */
    public function exportToCSV($filters = []) {
        $reports = $this->getAll($filters);
        
        if (empty($reports)) {
            return ['success' => false, 'message' => 'No reports to export'];
        }
        
        $filename = 'defect_reports_' . date('Y-m-d_His') . '.csv';
        $filepath = __DIR__ . '/../exports/' . $filename;
        
        // Create exports directory if it doesn't exist
        if (!file_exists(dirname($filepath))) {
            mkdir(dirname($filepath), 0777, true);
        }
        
        $file = fopen($filepath, 'w');
        
        // CSV Headers
        fputcsv($file, [
            'Report ID', 'Equipment', 'Reporter', 'Issue', 'Status', 
            'Priority', 'Category', 'Location', 'Report Date', 'Completed Date',
            'Assigned To', 'Age (Hours)', 'Estimated Time', 'Actual Time'
        ]);
        
        // Data rows
        foreach ($reports as $report) {
            fputcsv($file, [
                $report['id'],
                $report['equipment_name'],
                $report['reporter_name'],
                substr($report['issue_description'], 0, 100),
                $report['status'],
                $report['priority'],
                $report['category'] ?? 'N/A',
                $report['location'],
                $report['report_date'],
                $report['completed_date'] ?? 'N/A',
                $report['assigned_admin_name'] ?? 'Unassigned',
                $report['age_hours'],
                $report['estimated_repair_time'] ?? 'N/A',
                $report['actual_repair_time'] ?? 'N/A'
            ]);
        }
        
        fclose($file);
        
        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => 'exports/' . $filename,
            'count' => count($reports)
        ];
    }
    
    /**
     * Bulk status update
     */
    public function bulkUpdateStatus($report_ids, $new_status, $admin_id = null, $notes = null) {
        try {
            $results = [
                'success' => 0,
                'failed' => 0,
                'errors' => []
            ];
            
            foreach ($report_ids as $report_id) {
                $result = $this->updateStatus($report_id, $new_status, $admin_id, $notes);
                if ($result['success']) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Report #$report_id: " . $result['message'];
                }
            }
            
            return [
                'success' => true,
                'message' => "Updated {$results['success']} reports. Failed: {$results['failed']}",
                'results' => $results
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Assign report to admin/technician
     */
    public function assignToAdmin($report_id, $admin_id, $notes = null) {
        try {
            $query = "UPDATE " . $this->table . " 
                     SET assigned_to = :admin_id,
                         status = CASE WHEN status = 'pending' THEN 'in_progress' ELSE status END,
                         updated_at = NOW()
                     WHERE id = :id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':admin_id', $admin_id);
            $stmt->bindParam(':id', $report_id);
            
            if ($stmt->execute()) {
                $this->addStatusHistory($report_id, null, "Assigned to admin. $notes", $admin_id);
                
                $report = $this->getById($report_id);
                $this->createNotification($report_id, $report['user_id'], 'assigned');
                
                return ['success' => true, 'message' => 'Report assigned successfully'];
            }
            
            return ['success' => false, 'message' => 'Failed to assign report'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get status history for a report
     */
    public function getStatusHistory($report_id) {
        $query = "SELECT sh.*, u.fullname as changed_by_name
                  FROM defect_report_status_history sh
                  LEFT JOIN users u ON sh.changed_by = u.id
                  WHERE sh.report_id = :report_id
                  ORDER BY sh.changed_date DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':report_id', $report_id);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Add comment to report
     */
    public function addComment($report_id, $user_id, $comment) {
        try {
            $query = "INSERT INTO defect_report_comments 
                     (report_id, user_id, comment, created_at) 
                     VALUES (:report_id, :user_id, :comment, NOW())";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':report_id', $report_id);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':comment', $comment);
            
            if ($stmt->execute()) {
                return ['success' => true, 'comment_id' => $this->db->lastInsertId()];
            }
            
            return ['success' => false, 'message' => 'Failed to add comment'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get comments for a report
     */
    public function getComments($report_id) {
        $query = "SELECT c.*, u.fullname as user_name, u.role
                  FROM defect_report_comments c
                  LEFT JOIN users u ON c.user_id = u.id
                  WHERE c.report_id = :report_id
                  ORDER BY c.created_at DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':report_id', $report_id);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Add status history entry
     */
    private function addStatusHistory($report_id, $status, $notes = null, $changed_by = null) {
        $query = "INSERT INTO defect_report_status_history 
                  (report_id, status, notes, changed_by, changed_date) 
                  VALUES (:report_id, :status, :notes, :changed_by, NOW())";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':report_id', $report_id);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':notes', $notes);
        $stmt->bindParam(':changed_by', $changed_by);
        
        return $stmt->execute();
    }
    
    /**
     * Upload photo with optional compression
     */
    private function uploadPhoto($file, $compress = true) {
        $upload_dir = __DIR__ . '/../uploads/defect_photos/';
        
        // Create directory if it doesn't exist
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Generate unique filename
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = 'defect_' . time() . '_' . uniqid() . '.' . $extension;
        $target_path = $upload_dir . $filename;
        
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, $allowed_types)) {
            throw new Exception('Invalid file type. Only JPG, PNG, GIF, and WebP allowed.');
        }
        
        // Validate file size (max 10MB before compression)
        if ($file['size'] > 10485760) {
            throw new Exception('File size exceeds 10MB limit.');
        }
        
        // Compress image if requested
        if ($compress && in_array($mime_type, ['image/jpeg', 'image/jpg', 'image/png'])) {
            $this->compressImage($file['tmp_name'], $target_path, $mime_type, 85);
        } else {
            // Move uploaded file without compression
            if (!move_uploaded_file($file['tmp_name'], $target_path)) {
                throw new Exception('Failed to upload photo.');
            }
        }
        
        return 'uploads/defect_photos/' . $filename;
    }
    
    /**
     * Compress image to reduce file size
     */
    private function compressImage($source, $destination, $mime_type, $quality = 85) {
        // Get image info
        list($width, $height) = getimagesize($source);
        
        // Max dimensions
        $max_width = 1920;
        $max_height = 1920;
        
        // Calculate new dimensions
        if ($width > $max_width || $height > $max_height) {
            $ratio = min($max_width / $width, $max_height / $height);
            $new_width = $width * $ratio;
            $new_height = $height * $ratio;
        } else {
            $new_width = $width;
            $new_height = $height;
        }
        
        // Create image resource
        switch ($mime_type) {
            case 'image/jpeg':
            case 'image/jpg':
                $image = imagecreatefromjpeg($source);
                break;
            case 'image/png':
                $image = imagecreatefrompng($source);
                break;
            default:
                copy($source, $destination);
                return;
        }
        
        // Create new image
        $new_image = imagecreatetruecolor($new_width, $new_height);
        
        // Preserve transparency for PNG
        if ($mime_type === 'image/png') {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
        }
        
        // Resize
        imagecopyresampled($new_image, $image, 0, 0, 0, 0, 
                          $new_width, $new_height, $width, $height);
        
        // Save compressed image
        switch ($mime_type) {
            case 'image/jpeg':
            case 'image/jpg':
                imagejpeg($new_image, $destination, $quality);
                break;
            case 'image/png':
                imagepng($new_image, $destination, 9 - round($quality / 10));
                break;
        }
        
        // Free memory
        imagedestroy($image);
        imagedestroy($new_image);
    }
    
    /**
     * Create notification
     */
    private function createNotification($report_id, $user_id, $type, $status = null) {
        $messages = [
            'report_created' => 'Your defect report has been submitted successfully.',
            'status_updated' => 'Status of your defect report has been updated to: ' . ucfirst(str_replace('_', ' ', $status)),
            'assigned' => 'Your defect report has been assigned to a technician.',
            'completed' => 'Your defect report has been completed.',
            'priority_updated' => 'Priority of your defect report has been updated to: ' . ucfirst($status)
        ];
        
        $message = $messages[$type] ?? 'Update on your defect report.';
        
        $query = "INSERT INTO notifications 
                  (user_id, type, title, message, related_id, created_at) 
                  VALUES (:user_id, :type, :title, :message, :related_id, NOW())";
        
        $stmt = $this->db->prepare($query);
        
        $notification_type = 'defect_report';
        $title = 'Defect Report Update';
        
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':type', $notification_type);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':related_id', $report_id);
        
        return $stmt->execute();
    }
    
    /**
     * Notify admins for high/critical priority reports
     */
    private function notifyAdmins($report_id, $priority) {
        try {
            // Get all admin users
            $query = "SELECT id FROM users WHERE role IN ('admin', 'technician')";
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $report = $this->getById($report_id);
            
            foreach ($admins as $admin) {
                $query = "INSERT INTO notifications 
                         (user_id, type, title, message, related_id, created_at) 
                         VALUES (:user_id, :type, :title, :message, :related_id, NOW())";
                
                $stmt = $this->db->prepare($query);
                
                $message = "New " . strtoupper($priority) . " priority defect report: " . 
                          $report['equipment_name'];
                
                $stmt->bindParam(':user_id', $admin['id']);
                $type = 'urgent_report';
                $stmt->bindParam(':type', $type);
                $title = 'Urgent Defect Report';
                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':message', $message);
                $stmt->bindParam(':related_id', $report_id);
                
                $stmt->execute();
            }
            
        } catch (Exception $e) {
            error_log("Failed to notify admins: " . $e->getMessage());
        }
    }
    
    /**
     * Auto-detect priority based on keywords in description
     */
    private function detectPriority($description) {
        $description_lower = strtolower($description);
        
        // Critical keywords
        $critical_keywords = ['fire', 'smoke', 'explosion', 'broken', 'destroyed', 
                             'not working', 'completely broken', 'urgent', 'emergency',
                             'dangerous', 'hazard', 'sparking'];
        
        // High keywords
        $high_keywords = ['cracked', 'damaged', 'leaking', 'overheating', 
                         'malfunction', 'error', 'failed', 'critical'];
        
        // Medium keywords
        $medium_keywords = ['slow', 'issue', 'problem', 'concern', 'defect',
                           'minor damage', 'needs repair'];
        
        foreach ($critical_keywords as $keyword) {
            if (strpos($description_lower, $keyword) !== false) {
                return self::PRIORITY_CRITICAL;
            }
        }
        
        foreach ($high_keywords as $keyword) {
            if (strpos($description_lower, $keyword) !== false) {
                return self::PRIORITY_HIGH;
            }
        }
        
        foreach ($medium_keywords as $keyword) {
            if (strpos($description_lower, $keyword) !== false) {
                return self::PRIORITY_MEDIUM;
            }
        }
        
        return self::PRIORITY_LOW;
    }
    
    /**
     * Auto-detect category based on description
     */
    private function detectCategory($description) {
        $description_lower = strtolower($description);
        
        $categories = [
            self::CATEGORY_HARDWARE => ['keyboard', 'mouse', 'monitor', 'screen', 'port', 'button', 'cable'],
            self::CATEGORY_SOFTWARE => ['software', 'program', 'app', 'system', 'boot', 'install', 'update'],
            self::CATEGORY_PHYSICAL_DAMAGE => ['crack', 'broken', 'scratch', 'dent', 'damage', 'bent'],
            self::CATEGORY_PERFORMANCE => ['slow', 'lag', 'freeze', 'crash', 'performance', 'speed']
        ];
        
        foreach ($categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($description_lower, $keyword) !== false) {
                    return $category;
                }
            }
        }
        
        return self::CATEGORY_OTHER;
    }
    
    /**
     * Estimate repair time based on priority and category
     */
    private function estimateRepairTime($priority, $category) {
        $base_times = [
            self::PRIORITY_CRITICAL => 2,  // 2 hours
            self::PRIORITY_HIGH => 4,      // 4 hours
            self::PRIORITY_MEDIUM => 8,    // 8 hours
            self::PRIORITY_LOW => 24       // 24 hours
        ];
        
        $category_multipliers = [
            self::CATEGORY_HARDWARE => 1.0,
            self::CATEGORY_SOFTWARE => 0.8,
            self::CATEGORY_PHYSICAL_DAMAGE => 1.5,
            self::CATEGORY_PERFORMANCE => 1.2,
            self::CATEGORY_OTHER => 1.0
        ];
        
        $base_time = $base_times[$priority] ?? 8;
        $multiplier = $category_multipliers[$category] ?? 1.0;
        
        return round($base_time * $multiplier, 1);
    }
    
    /**
     * Calculate actual repair time
     */
    private function calculateActualRepairTime($report_id) {
        $query = "UPDATE " . $this->table . " 
                 SET actual_repair_time = TIMESTAMPDIFF(HOUR, report_date, completed_date)
                 WHERE id = :id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $report_id);
        return $stmt->execute();
    }
    
    /**
     * Check if report is duplicate
     */
    private function isDuplicateReport($data) {
        $query = "SELECT COUNT(*) as count
                  FROM " . $this->table . "
                  WHERE equipment_id = :equipment_id
                  AND status NOT IN ('completed', 'rejected', 'cancelled')
                  AND report_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':equipment_id', $data['equipment_id']);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }
    
    /**
     * Validate status transition
     */
    private function isValidStatusTransition($old_status, $new_status) {
        $valid_transitions = [
            self::STATUS_PENDING => [self::STATUS_IN_PROGRESS, self::STATUS_REJECTED, self::STATUS_CANCELLED],
            self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_PENDING, self::STATUS_CANCELLED],
            self::STATUS_COMPLETED => [],
            self::STATUS_REJECTED => [self::STATUS_PENDING],
            self::STATUS_CANCELLED => [self::STATUS_PENDING]
        ];
        
        return isset($valid_transitions[$old_status]) && 
               in_array($new_status, $valid_transitions[$old_status]);
    }
    
    /**
     * Validate report data
     */
    private function validateReportData($data) {
        if (empty($data['user_id'])) {
            throw new Exception('User ID is required');
        }
        
        if (empty($data['equipment_id'])) {
            throw new Exception('Equipment ID is required');
        }
        
        if (empty($data['issue_description'])) {
            throw new Exception('Issue description is required');
        }
        
        if (strlen($data['issue_description']) < 10) {
            throw new Exception('Issue description must be at least 10 characters');
        }
        
        if (empty($data['location'])) {
            throw new Exception('Location is required');
        }
        
        return true;
    }
    
    /**
     * Update equipment status
     */
    private function updateEquipmentStatus($equipment_id, $status) {
        try {
            $query = "UPDATE equipment SET status = :status WHERE id = :id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $equipment_id);
            return $stmt->execute();
        } catch (Exception $e) {
            error_log("Failed to update equipment status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log activity
     */
    private function logActivity($report_id, $action, $user_id, $details = []) {
        try {
            $query = "INSERT INTO activity_log 
                     (entity_type, entity_id, action, user_id, details, created_at) 
                     VALUES (:entity_type, :entity_id, :action, :user_id, :details, NOW())";
            
            $stmt = $this->db->prepare($query);
            
            $entity_type = 'defect_report';
            $details_json = json_encode($details);
            
            $stmt->bindParam(':entity_type', $entity_type);
            $stmt->bindParam(':entity_id', $report_id);
            $stmt->bindParam(':action', $action);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':details', $details_json);
            
            return $stmt->execute();
        } catch (Exception $e) {
            error_log("Failed to log activity: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get current status
     */
    private function getStatus($report_id) {
        $query = "SELECT status FROM " . $this->table . " WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $report_id);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['status'] : null;
    }
    
    /**
     * Delete report with cleanup
     */
    public function delete($id) {
        try {
            // Get report details
            $report = $this->getById($id);
            
            if (!$report) {
                return ['success' => false, 'message' => 'Report not found'];
            }
            
            // Delete from database
            $query = "DELETE FROM " . $this->table . " WHERE id = :id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                // Delete photo files if exist
                if ($report['photo_paths']) {
                    $photo_paths = json_decode($report['photo_paths'], true);
                    foreach ($photo_paths as $photo_path) {
                        $full_path = __DIR__ . '/../' . $photo_path;
                        if (file_exists($full_path)) {
                            unlink($full_path);
                        }
                    }
                }
                
                // Delete related records
                $this->db->exec("DELETE FROM defect_report_status_history WHERE report_id = $id");
                $this->db->exec("DELETE FROM defect_report_comments WHERE report_id = $id");
                
                // Log deletion
                $this->logActivity($id, 'deleted', $report['user_id']);
                
                return ['success' => true, 'message' => 'Report deleted successfully'];
            }
            
            return ['success' => false, 'message' => 'Failed to delete report'];
            
        } catch (Exception $e) {
            error_log("DefectReport::delete() Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}