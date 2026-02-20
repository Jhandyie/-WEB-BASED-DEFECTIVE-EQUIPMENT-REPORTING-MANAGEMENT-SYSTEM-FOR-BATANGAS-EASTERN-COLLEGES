-- BEC Equipment Management System - Maintenance Scheduling Schema
-- This script adds maintenance scheduling functionality to the existing database

USE bec_equipment_db;

-- Maintenance Schedules Table
-- Stores scheduled maintenance activities for equipment
CREATE TABLE IF NOT EXISTS maintenance_schedules (
    schedule_id VARCHAR(20) PRIMARY KEY,
    equipment_id VARCHAR(20) NOT NULL,
    schedule_type ENUM('preventive', 'corrective', 'inspection', 'calibration') DEFAULT 'preventive',
    title VARCHAR(200) NOT NULL,
    description TEXT,
    scheduled_date DATE NOT NULL,
    scheduled_time TIME,
    estimated_duration INT DEFAULT 60, -- in minutes
    priority ENUM('critical', 'high', 'medium', 'low') DEFAULT 'medium',
    status ENUM('scheduled', 'in_progress', 'completed', 'cancelled', 'overdue') DEFAULT 'scheduled',
    assigned_to VARCHAR(20) NULL,
    created_by VARCHAR(20) NOT NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_date TIMESTAMP NULL,
    notes TEXT,
    recurrence_pattern ENUM('none', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly') DEFAULT 'none',
    recurrence_end_date DATE NULL,
    last_recurrence_date DATE NULL,
    FOREIGN KEY (equipment_id) REFERENCES equipment(equipment_id),
    FOREIGN KEY (assigned_to) REFERENCES maintenance_technicians(technician_id),
    INDEX idx_equipment_id (equipment_id),
    INDEX idx_scheduled_date (scheduled_date),
    INDEX idx_status (status),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_schedule_type (schedule_type)
);

-- Maintenance History Table
-- Stores detailed records of completed maintenance activities
CREATE TABLE IF NOT EXISTS maintenance_history (
    history_id VARCHAR(20) PRIMARY KEY,
    schedule_id VARCHAR(20) NULL, -- Links to scheduled maintenance if applicable
    equipment_id VARCHAR(20) NOT NULL,
    maintenance_type ENUM('preventive', 'corrective', 'inspection', 'calibration', 'repair') DEFAULT 'preventive',
    title VARCHAR(200) NOT NULL,
    description TEXT,
    performed_by VARCHAR(20) NOT NULL,
    performed_date DATE NOT NULL,
    start_time TIME,
    end_time TIME,
    actual_duration INT, -- in minutes
    parts_used TEXT, -- JSON array of parts/materials used
    parts_cost DECIMAL(10,2) DEFAULT 0,
    labor_cost DECIMAL(10,2) DEFAULT 0,
    total_cost DECIMAL(10,2) DEFAULT 0,
    findings TEXT, -- What was found during maintenance
    actions_taken TEXT, -- What was done
    recommendations TEXT, -- Future recommendations
    next_maintenance_date DATE NULL,
    status ENUM('completed', 'incomplete', 'deferred') DEFAULT 'completed',
    verified_by VARCHAR(20) NULL,
    verified_date TIMESTAMP NULL,
    notes TEXT,
    attachments TEXT, -- JSON array of file paths
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (schedule_id) REFERENCES maintenance_schedules(schedule_id),
    FOREIGN KEY (equipment_id) REFERENCES equipment(equipment_id),
    FOREIGN KEY (performed_by) REFERENCES maintenance_technicians(technician_id),
    FOREIGN KEY (verified_by) REFERENCES admins(admin_id),
    INDEX idx_equipment_id (equipment_id),
    INDEX idx_performed_date (performed_date),
    INDEX idx_performed_by (performed_by),
    INDEX idx_maintenance_type (maintenance_type)
);

-- Maintenance Reminders Table
-- Stores reminder notifications for upcoming maintenance
CREATE TABLE IF NOT EXISTS maintenance_reminders (
    reminder_id VARCHAR(20) PRIMARY KEY,
    schedule_id VARCHAR(20) NOT NULL,
    equipment_id VARCHAR(20) NOT NULL,
    reminder_type ENUM('advance_warning', 'due_today', 'overdue', 'recurring') DEFAULT 'advance_warning',
    reminder_date DATE NOT NULL,
    reminder_time TIME,
    days_in_advance INT DEFAULT 7, -- How many days before the scheduled date
    message TEXT NOT NULL,
    is_sent TINYINT(1) DEFAULT 0,
    sent_date TIMESTAMP NULL,
    recipient_user_id VARCHAR(20) NOT NULL,
    recipient_role ENUM('admin', 'handler', 'technician') DEFAULT 'handler',
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (schedule_id) REFERENCES maintenance_schedules(schedule_id),
    FOREIGN KEY (equipment_id) REFERENCES equipment(equipment_id),
    FOREIGN KEY (recipient_user_id) REFERENCES users(user_id),
    INDEX idx_schedule_id (schedule_id),
    INDEX idx_reminder_date (reminder_date),
    INDEX idx_is_sent (is_sent),
    INDEX idx_recipient (recipient_user_id, recipient_role)
);

-- Equipment Maintenance Templates Table
-- Stores predefined maintenance templates for different equipment types
CREATE TABLE IF NOT EXISTS maintenance_templates (
    template_id VARCHAR(20) PRIMARY KEY,
    template_name VARCHAR(200) NOT NULL,
    category_id INT NULL, -- If specific to a category
    equipment_id VARCHAR(20) NULL, -- If specific to particular equipment
    maintenance_type ENUM('preventive', 'inspection', 'calibration') DEFAULT 'preventive',
    title VARCHAR(200) NOT NULL,
    description TEXT,
    estimated_duration INT DEFAULT 60, -- in minutes
    frequency_days INT, -- How often this maintenance should be performed
    checklist_items TEXT, -- JSON array of checklist items
    required_parts TEXT, -- JSON array of commonly used parts
    instructions TEXT, -- Step-by-step instructions
    is_active TINYINT(1) DEFAULT 1,
    created_by VARCHAR(20) NOT NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(category_id),
    FOREIGN KEY (equipment_id) REFERENCES equipment(equipment_id),
    FOREIGN KEY (created_by) REFERENCES admins(admin_id),
    INDEX idx_category_id (category_id),
    INDEX idx_equipment_id (equipment_id),
    INDEX idx_maintenance_type (maintenance_type),
    INDEX idx_is_active (is_active)
);

-- Maintenance Parts Inventory Table
-- Tracks parts and consumables used for maintenance
CREATE TABLE IF NOT EXISTS maintenance_parts (
    part_id VARCHAR(20) PRIMARY KEY,
    part_name VARCHAR(200) NOT NULL,
    part_number VARCHAR(100) UNIQUE,
    category VARCHAR(100),
    description TEXT,
    unit_cost DECIMAL(10,2) DEFAULT 0,
    current_stock INT DEFAULT 0,
    minimum_stock INT DEFAULT 0, -- Reorder point
    supplier VARCHAR(200),
    location VARCHAR(200), -- Where the part is stored
    is_active TINYINT(1) DEFAULT 1,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_part_number (part_number),
    INDEX idx_category (category),
    INDEX idx_is_active (is_active)
);

-- Maintenance Parts Usage Table
-- Tracks which parts were used in which maintenance activities
CREATE TABLE IF NOT EXISTS maintenance_parts_usage (
    usage_id INT AUTO_INCREMENT PRIMARY KEY,
    history_id VARCHAR(20) NOT NULL,
    part_id VARCHAR(20) NOT NULL,
    quantity_used INT NOT NULL,
    unit_cost DECIMAL(10,2) NOT NULL,
    total_cost DECIMAL(10,2) NOT NULL,
    notes TEXT,
    FOREIGN KEY (history_id) REFERENCES maintenance_history(history_id),
    FOREIGN KEY (part_id) REFERENCES maintenance_parts(part_id),
    INDEX idx_history_id (history_id),
    INDEX idx_part_id (part_id)
);

-- Insert sample maintenance templates
INSERT IGNORE INTO maintenance_templates (template_id, template_name, category_id, maintenance_type, title, description, estimated_duration, frequency_days, checklist_items, created_by) VALUES
('TPL-001', 'Computer Preventive Maintenance', 1, 'preventive', 'Monthly Computer Maintenance', 'Regular maintenance for desktop computers and laptops', 45, 30,
'["Clean dust from vents and fans", "Check all cable connections", "Update system software", "Run virus scan", "Check hard drive space", "Test peripherals"]', 'HDL-001'),

('TPL-002', 'Projector Lamp Replacement', 2, 'preventive', 'Projector Maintenance', 'Check and replace projector lamps as needed', 30, 90,
'["Check lamp hours", "Clean air filters", "Check cooling fans", "Test projection quality", "Clean lens"]', 'HDL-001'),

('TPL-003', 'Laboratory Equipment Calibration', 3, 'calibration', 'Equipment Calibration', 'Calibrate laboratory instruments', 120, 180,
'["Check calibration certificates", "Perform calibration tests", "Record measurements", "Update calibration stickers", "Document results"]', 'HDL-001');

-- Insert sample maintenance parts
INSERT IGNORE INTO maintenance_parts (part_id, part_name, part_number, category, description, unit_cost, current_stock, minimum_stock, supplier, location) VALUES
('PRT-001', 'Computer Cleaning Kit', 'CCK-001', 'Cleaning Supplies', 'Complete cleaning kit for computer maintenance', 25.00, 10, 5, 'Office Depot', 'Storage Room A'),
('PRT-002', 'Projector Lamp', 'PL-5000', 'Projector Parts', 'Replacement lamp for Epson 5000 series projectors', 150.00, 5, 2, 'Epson Authorized Dealer', 'Storage Room B'),
('PRT-003', 'Thermal Paste', 'TP-001', 'Computer Parts', 'High-quality thermal paste for CPU cooling', 8.50, 20, 5, 'Tech Supplies Inc', 'Storage Room A'),
('PRT-004', 'Air Filter', 'AF-001', 'Filters', 'Replacement air filter for laboratory equipment', 12.00, 15, 3, 'Lab Supplies Co', 'Storage Room C');

-- Insert sample maintenance schedule
INSERT IGNORE INTO maintenance_schedules (schedule_id, equipment_id, schedule_type, title, description, scheduled_date, estimated_duration, priority, created_by) VALUES
('SCH-001', 'EQ-001', 'preventive', 'Monthly Computer Maintenance', 'Regular maintenance check for Dell Latitude Laptop', DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY), 45, 'medium', 'HDL-001'),
('SCH-002', 'EQ-003', 'preventive', 'Projector Lamp Check', 'Check projector lamp hours and clean filters', DATE_ADD(CURRENT_DATE, INTERVAL 14 DAY), 30, 'low', 'HDL-001'),
('SCH-003', 'EQ-004', 'corrective', 'Microscope Repair', 'Repair foggy lens on digital microscope', DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY), 90, 'high', 'HDL-001');

-- Insert sample maintenance history
INSERT IGNORE INTO maintenance_history (history_id, equipment_id, maintenance_type, title, description, performed_by, performed_date, actual_duration, parts_used, total_cost, findings, actions_taken, status) VALUES
('HIS-001', 'EQ-002', 'preventive', 'Monthly Computer Maintenance', 'Regular maintenance performed on HP Desktop', 'TEC-001', DATE_SUB(CURRENT_DATE, INTERVAL 5 DAY), 35,
'["Computer Cleaning Kit"]', 25.00,
'Computer was dusty, some cables loose', 'Cleaned dust, secured cables, updated software', 'completed');

-- Create indexes for better performance
CREATE INDEX idx_maintenance_history_equipment_date ON maintenance_history(equipment_id, performed_date);
CREATE INDEX idx_maintenance_schedules_date_status ON maintenance_schedules(scheduled_date, status);
CREATE INDEX idx_maintenance_reminders_date_sent ON maintenance_reminders(reminder_date, is_sent);
