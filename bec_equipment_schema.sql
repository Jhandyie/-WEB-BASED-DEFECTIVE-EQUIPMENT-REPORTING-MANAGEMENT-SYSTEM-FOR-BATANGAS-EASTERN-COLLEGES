-- Database Schema for Student Dashboard System
-- BEC Equipment Management System

-- Table: users (if not exists)
-- Stores user information
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL UNIQUE,
  `password` varchar(255) NOT NULL,
  `fullname` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL UNIQUE,
  `role` enum('admin','student','faculty','guest') DEFAULT 'student',
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_username` (`username`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: equipment (if not exists)
-- Stores equipment information
CREATE TABLE IF NOT EXISTS `equipment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `equipment_name` varchar(255) NOT NULL,
  `equipment_category` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `location` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `status` enum('active','inactive','maintenance','retired') DEFAULT 'active',
  `purchase_date` date DEFAULT NULL,
  `warranty_expiry` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`equipment_category`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: defect_reports
-- Stores equipment defect reports submitted by students
CREATE TABLE IF NOT EXISTS `defect_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `equipment_id` varchar(255) NOT NULL COMMENT 'User-entered equipment name/text',
  `issue_description` text NOT NULL,
  `location` varchar(255) NOT NULL,
  `photo_paths` text DEFAULT NULL COMMENT 'JSON array of photo paths',
  `status` enum('pending','in_progress','completed','rejected') DEFAULT 'pending',
  `report_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `assigned_to` int(11) DEFAULT NULL,
  `completed_date` datetime DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_equipment_id` (`equipment_id`(100)),
  KEY `idx_status` (`status`),
  KEY `idx_assigned_to` (`assigned_to`),
  CONSTRAINT `fk_defect_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_defect_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores equipment defect reports with user-entered equipment names';

-- Table: defect_report_status_history
-- Tracks status changes for defect reports
CREATE TABLE IF NOT EXISTS `defect_report_status_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` int(11) NOT NULL,
  `status` enum('pending','in_progress','completed','rejected') NOT NULL,
  `notes` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_report_id` (`report_id`),
  KEY `idx_changed_by` (`changed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign key constraints after table creation
ALTER TABLE `defect_report_status_history`
  ADD CONSTRAINT `fk_history_report` FOREIGN KEY (`report_id`) REFERENCES `defect_reports` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_history_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Table: reservations
-- Stores equipment reservation requests
CREATE TABLE IF NOT EXISTS `reservations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `equipment_id` int(11) NOT NULL,
  `reservation_date` date NOT NULL,
  `return_date` date NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `purpose` text NOT NULL,
  `status` enum('pending','approved','rejected','completed','cancelled') DEFAULT 'pending',
  `request_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_by` int(11) DEFAULT NULL,
  `approval_date` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_equipment_id` (`equipment_id`),
  KEY `idx_status` (`status`),
  KEY `idx_reservation_date` (`reservation_date`),
  KEY `idx_approved_by` (`approved_by`),
  CONSTRAINT `fk_reservation_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reservation_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reservation_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: notifications
-- Stores user notifications for various events
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `related_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_type` (`type`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample data for testing

-- Sample equipment items
INSERT INTO `equipment` (`equipment_name`, `equipment_category`, `location`, `quantity`, `status`) VALUES
('Carrier Air Conditioner 1.5HP', 'Air Conditioner', 'Building 1, Room 101', 5, 'active'),
('TCL 50" Smart TV', 'Television', 'Building 4, Diamond Room 101', 3, 'active'),
('Ceiling Fan Industrial', 'Fan', 'Building 3, Room 125', 10, 'active'),
('Glass Whiteboard 4x6ft', 'Whiteboard', 'Building 13, BSTC 101', 8, 'active'),
('Steel Locker 15 Compartments', 'Locker', 'Building 2, Faculty Office', 6, 'active'),
('Executive Office Chair', 'Office Chair', 'Building 4, Deans Office', 15, 'active'),
('Projector Epson EB-X41', 'Projector', 'Building 5, Conference Room', 4, 'active'),
('Dell Desktop Computer i5', 'Computer', 'Building 7, Computer Lab', 25, 'active'),
('Wooden Table 6-seater', 'Table', 'Building 8, Cafeteria', 20, 'active'),
('HP LaserJet Printer', 'Printer', 'Building 2, Admin Office', 7, 'active')
ON DUPLICATE KEY UPDATE `equipment_name` = VALUES(`equipment_name`);

-- Indexes for performance optimization
CREATE INDEX idx_defect_report_date ON defect_reports(report_date DESC);
CREATE INDEX idx_reservation_dates ON reservations(reservation_date, return_date);
CREATE INDEX idx_notification_user_unread ON notifications(user_id, is_read, created_at DESC);
CREATE INDEX idx_status_history_date ON defect_report_status_history(changed_date DESC);

-- Triggers for automatic notifications

DELIMITER $$

-- Trigger: After defect report status update
CREATE TRIGGER IF NOT EXISTS after_defect_status_update
AFTER UPDATE ON defect_reports
FOR EACH ROW
BEGIN
    IF NEW.status != OLD.status THEN
        INSERT INTO notifications (user_id, type, title, message, related_id, created_at)
        VALUES (
            NEW.user_id,
            'defect_report',
            'Defect Report Status Updated',
            CONCAT('Your defect report status has been changed to: ', UPPER(REPLACE(NEW.status, '_', ' '))),
            NEW.id,
            NOW()
        );
    END IF;
END$$

-- Trigger: After reservation approval/rejection
CREATE TRIGGER IF NOT EXISTS after_reservation_status_update
AFTER UPDATE ON reservations
FOR EACH ROW
BEGIN
    IF NEW.status != OLD.status AND NEW.status IN ('approved', 'rejected') THEN
        INSERT INTO notifications (user_id, type, title, message, related_id, created_at)
        VALUES (
            NEW.user_id,
            'reservation',
            CONCAT('Reservation ', UPPER(NEW.status)),
            CASE
                WHEN NEW.status = 'approved' THEN 'Your equipment reservation has been approved.'
                WHEN NEW.status = 'rejected' THEN CONCAT('Your equipment reservation has been rejected. Reason: ', COALESCE(NEW.rejection_reason, 'Not specified'))
                ELSE 'Your reservation status has been updated.'
            END,
            NEW.id,
            NOW()
        );
    END IF;
END$$

DELIMITER ;

-- Views for reporting

-- View: Active defect reports summary
CREATE OR REPLACE VIEW v_active_defect_reports AS
SELECT
    dr.id,
    dr.user_id,
    u.fullname as reporter_name,
    u.email as reporter_email,
    dr.equipment_id as equipment_name, -- Now stores user-entered text
    'User Specified' as equipment_category, -- No longer linked to equipment table
    dr.location,
    dr.issue_description,
    dr.status,
    dr.report_date,
    dr.assigned_to,
    a.fullname as assigned_admin_name,
    DATEDIFF(NOW(), dr.report_date) as days_pending
FROM defect_reports dr
LEFT JOIN users u ON dr.user_id = u.id
LEFT JOIN users a ON dr.assigned_to = a.id
WHERE dr.status IN ('pending', 'in_progress')
ORDER BY dr.report_date ASC;

-- View: Active reservations summary
CREATE OR REPLACE VIEW v_active_reservations AS
SELECT
    r.id,
    r.user_id,
    u.fullname as user_name,
    u.email as user_email,
    r.equipment_id,
    e.equipment_name,
    e.equipment_category,
    r.reservation_date,
    r.return_date,
    r.quantity,
    r.status,
    r.request_date,
    DATEDIFF(r.reservation_date, CURDATE()) as days_until_reservation
FROM reservations r
LEFT JOIN users u ON r.user_id = u.id
LEFT JOIN equipment e ON r.equipment_id = e.id
WHERE r.status IN ('pending', 'approved')
  AND r.return_date >= CURDATE()
ORDER BY r.reservation_date ASC;

-- View: User statistics
CREATE OR REPLACE VIEW v_user_statistics AS
SELECT
    u.id as user_id,
    u.fullname,
    u.email,
    COUNT(DISTINCT dr.id) as total_reports,
    SUM(CASE WHEN dr.status = 'pending' THEN 1 ELSE 0 END) as pending_reports,
    SUM(CASE WHEN dr.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_reports,
    SUM(CASE WHEN dr.status = 'completed' THEN 1 ELSE 0 END) as completed_reports,
    COUNT(DISTINCT r.id) as total_reservations,
    SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) as approved_reservations,
    (SELECT COUNT(*) FROM notifications WHERE user_id = u.id AND is_read = 0) as unread_notifications
FROM users u
LEFT JOIN defect_reports dr ON u.id = dr.user_id
LEFT JOIN reservations r ON u.id = r.user_id
WHERE u.role = 'student'
GROUP BY u.id;

-- Comments for documentation
ALTER TABLE defect_reports
  COMMENT = 'Stores equipment defect reports with photo upload and status tracking';

ALTER TABLE defect_report_status_history
  COMMENT = 'Maintains history of all status changes for defect reports';

ALTER TABLE reservations
  COMMENT = 'Manages equipment reservation requests and approvals';

ALTER TABLE notifications
  COMMENT = 'User notification system for real-time updates';
