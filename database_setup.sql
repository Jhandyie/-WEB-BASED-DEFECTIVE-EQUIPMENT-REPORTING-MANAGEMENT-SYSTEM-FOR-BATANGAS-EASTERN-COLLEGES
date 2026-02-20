-- BEC Equipment Management System Database Schema
-- Run this script to create the necessary database tables

-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS bec_equipment_db;
USE bec_equipment_db;

-- Users Tables (Authentication)
CREATE TABLE IF NOT EXISTS admins (
    admin_id VARCHAR(20) PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_email (email)
);



CREATE TABLE IF NOT EXISTS maintenance_technicians (
    technician_id VARCHAR(20) PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20),
    specialization VARCHAR(100),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_email (email)
);

CREATE TABLE IF NOT EXISTS faculty_members (
    faculty_id VARCHAR(20) PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20),
    department VARCHAR(100),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_email (email)
);

CREATE TABLE IF NOT EXISTS students (
    student_id VARCHAR(20) PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20),
    student_id_number VARCHAR(20),
    course VARCHAR(100),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_email (email)
);

-- Operational Data Tables
CREATE TABLE IF NOT EXISTS categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_category_name (category_name)
);

CREATE TABLE IF NOT EXISTS equipment (
    equipment_id VARCHAR(20) PRIMARY KEY,
    asset_tag VARCHAR(50) UNIQUE NOT NULL,
    equipment_name VARCHAR(200) NOT NULL,
    category_id INT,
    description TEXT,
    location VARCHAR(200),
    status ENUM('available', 'reserved', 'maintenance', 'deleted') DEFAULT 'available',
    condition_status ENUM('excellent', 'good', 'fair', 'poor', 'damaged') DEFAULT 'good',
    purchase_date DATE,
    purchase_cost DECIMAL(10,2),
    warranty_expiry DATE,
    -- Inventory Management Fields
    quantity INT DEFAULT 1,
    min_stock_level INT DEFAULT 1,
    reorder_point INT DEFAULT 0,
    supplier_info TEXT,
    last_inventory_check TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_asset_tag (asset_tag),
    INDEX idx_category (category_id),
    INDEX idx_status (status),
    INDEX idx_quantity (quantity),
    INDEX idx_min_stock_level (min_stock_level)
);

CREATE TABLE IF NOT EXISTS defect_reports (
    report_id VARCHAR(20) PRIMARY KEY,
    equipment_id VARCHAR(20) NOT NULL,
    reported_by VARCHAR(20) NOT NULL,
    issue_description TEXT NOT NULL,
    priority ENUM('critical', 'high', 'medium', 'low') DEFAULT 'medium',
    status ENUM('reported', 'assigned', 'in_progress', 'completed', 'verified', 'closed') DEFAULT 'reported',
    assigned_to VARCHAR(20) NULL,
    assigned_date TIMESTAMP NULL,
    completion_date TIMESTAMP NULL,
    technician_notes TEXT,
    verification_notes TEXT,
    report_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (equipment_id) REFERENCES equipment(equipment_id),
    INDEX idx_reported_by (reported_by),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_report_date (report_date)
);

CREATE TABLE IF NOT EXISTS reservations (
    reservation_id VARCHAR(20) PRIMARY KEY,
    equipment_id VARCHAR(20) NOT NULL,
    user_id VARCHAR(20) NOT NULL,
    request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    purpose TEXT,
    status ENUM('pending', 'approved', 'active', 'completed', 'cancelled') DEFAULT 'pending',
    approved_by VARCHAR(20) NULL,
    approval_date TIMESTAMP NULL,
    notes TEXT,
    FOREIGN KEY (equipment_id) REFERENCES equipment(equipment_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_start_date (start_date),
    INDEX idx_end_date (end_date)
);

-- Activity Logging Tables
CREATE TABLE IF NOT EXISTS activity_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(20) NOT NULL,
    user_role VARCHAR(20) NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    action_description TEXT,
    ip_address VARCHAR(45),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action_type (action_type),
    INDEX idx_timestamp (timestamp)
);

CREATE TABLE IF NOT EXISTS user_sessions (
    session_id VARCHAR(255) PRIMARY KEY,
    user_id VARCHAR(20) NOT NULL,
    user_role VARCHAR(20) NOT NULL,
    login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    logout_time TIMESTAMP NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    INDEX idx_user_id (user_id),
    INDEX idx_login_time (login_time)
);

-- Insert default admin user
INSERT IGNORE INTO admins (admin_id, username, password, fullname, email, phone, status)
VALUES ('ADM-001', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'thesterads@gmail.com', '+1234567890', 'active');



-- Insert default technician user
INSERT IGNORE INTO maintenance_technicians (technician_id, username, password, fullname, email, phone, specialization, status)
VALUES ('TEC-001', 'technician', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maintenance Technician', 'thesterads@gmail.com', '+1234567892', 'General Maintenance', 'active');

-- Insert default faculty user (using admin email for testing)
INSERT IGNORE INTO faculty_members (faculty_id, username, password, fullname, email, phone, department, status)
VALUES ('FAC-001', 'faculty', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Faculty Member', 'thesterads@gmail.com', '+1234567893', 'Computer Science', 'active');

-- Insert default student user (using admin email for testing)
INSERT IGNORE INTO students (student_id, username, password, fullname, email, phone, student_id_number, course, status)
VALUES ('STU-001', 'student', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Student User', 'thesterads@gmail.com', '+1234567894', '2021001', 'Bachelor of Science in Computer Science', 'active');

-- Insert sample categories
INSERT IGNORE INTO categories (category_name, description) VALUES
('Computers', 'Desktop computers, laptops, and peripherals'),
('Audio/Visual', 'Projectors, speakers, microphones, and AV equipment'),
('Laboratory', 'Scientific instruments and lab equipment'),
('Sports', 'Sports equipment and facilities'),
('Tools', 'Hand tools and power tools'),
('Furniture', 'Classroom and office furniture');

-- Insert sample equipment
INSERT IGNORE INTO equipment (equipment_id, asset_tag, equipment_name, category_id, description, location, status, condition_status, quantity, min_stock_level, reorder_point, supplier_info) VALUES
('EQ-001', 'COMP-001', 'Dell Latitude Laptop', 1, '15.6" business laptop with Windows 11', 'Room 101', 'available', 'excellent', 5, 2, 3, 'Dell Technologies - Contact: John Smith, Phone: +1-800-123-4567'),
('EQ-002', 'COMP-002', 'HP Desktop Computer', 1, 'Core i5 desktop with 16GB RAM', 'Computer Lab', 'available', 'good', 3, 1, 2, 'HP Inc. - Contact: Jane Doe, Phone: +1-800-987-6543'),
('EQ-003', 'AV-001', 'Epson Projector', 2, '3000 lumen HD projector', 'Auditorium', 'available', 'good', 2, 1, 1, 'Epson America - Contact: Mike Johnson, Phone: +1-800-555-0123'),
('EQ-004', 'LAB-001', 'Digital Microscope', 3, 'High-resolution digital microscope', 'Science Lab', 'maintenance', 'fair', 1, 1, 1, 'Olympus Corporation - Contact: Sarah Wilson, Phone: +1-800-444-5678'),
('EQ-005', 'SPORT-001', 'Basketball Set', 4, 'Complete basketball equipment set', 'Gymnasium', 'available', 'good', 8, 3, 5, 'Nike Sports - Contact: Bob Brown, Phone: +1-800-333-7890');

-- Insert sample defect report
INSERT IGNORE INTO defect_reports (report_id, equipment_id, reported_by, issue_description, priority, status) VALUES
('REP-001', 'EQ-004', 'FAC-001', 'Microscope lens appears foggy and images are unclear', 'high', 'reported');

-- Unified Users Table for Authentication
CREATE TABLE IF NOT EXISTS users (
    user_id VARCHAR(20) PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20),
    role ENUM('admin', 'handler', 'technician', 'faculty', 'student') NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_status (status)
);

-- Populate users table from individual user tables
INSERT IGNORE INTO users (user_id, username, password, fullname, email, phone, role, status, created_at, last_login)
SELECT admin_id, username, password, fullname, email, phone, 'admin', status, created_at, last_login FROM admins;



INSERT IGNORE INTO users (user_id, username, password, fullname, email, phone, role, status, created_at, last_login)
SELECT technician_id, username, password, fullname, email, phone, 'technician', status, created_at, last_login FROM maintenance_technicians;

INSERT IGNORE INTO users (user_id, username, password, fullname, email, phone, role, status, created_at, last_login)
SELECT faculty_id, username, password, fullname, email, phone, 'faculty', status, created_at, last_login FROM faculty_members;

INSERT IGNORE INTO users (user_id, username, password, fullname, email, phone, role, status, created_at, last_login)
SELECT student_id, username, password, fullname, email, phone, 'student', status, created_at, last_login FROM students;

-- Notifications Table
CREATE TABLE IF NOT EXISTS notifications (
    notification_id VARCHAR(50) PRIMARY KEY,
    user_id VARCHAR(20) NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) NOT NULL,
    related_id VARCHAR(50) NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_type (type),
    INDEX idx_created_date (created_date),
    INDEX idx_is_read (is_read)
);

-- Insert sample notifications
INSERT IGNORE INTO notifications (notification_id, user_id, message, type, related_id, is_read, created_date) VALUES
('NOT-001', 'ADM-001', 'New defect report submitted for Digital Microscope (EQ-004)', 'new_defect_report', 'REP-001', 0, NOW()),
('NOT-002', 'ADM-001', 'Reservation request pending approval for Epson Projector', 'new_reservation', 'RES-001', 0, NOW()),
('NOT-003', 'ADM-001', 'Maintenance task completed for HP Desktop Computer', 'task_completed', 'REP-001', 1, NOW()),
('NOT-004', NULL, 'System backup completed successfully', 'system_alert', NULL, 0, NOW());

-- Password Reset Table
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_token (token),
    INDEX idx_expires_at (expires_at)
);

-- Create OTP table for email-based authentication
CREATE TABLE IF NOT EXISTS `email_otp` (
  `otp_id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL,
  `otp_code` VARCHAR(6) NOT NULL,
  `user_role` ENUM('admin', 'handler', 'technician', 'faculty', 'student') NOT NULL DEFAULT 'admin',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_used` TINYINT(1) DEFAULT 0,
  `attempts` INT DEFAULT 0,
  INDEX `idx_email` (`email`),
  INDEX `idx_otp_code` (`otp_code`),
  INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add email column to admins table if it doesn't exist (check first)
ALTER TABLE `admins`
ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) DEFAULT NULL AFTER `fullname`,
ADD UNIQUE INDEX IF NOT EXISTS `idx_admin_email` (`email`);

-- Clean up expired OTPs automatically (older than 1 hour)
-- You can run this periodically or create an event
-- CREATE EVENT IF NOT EXISTS cleanup_expired_otps
-- ON SCHEDULE EVERY 1 HOUR
-- DO
--   DELETE FROM email_otp WHERE expires_at < NOW();
