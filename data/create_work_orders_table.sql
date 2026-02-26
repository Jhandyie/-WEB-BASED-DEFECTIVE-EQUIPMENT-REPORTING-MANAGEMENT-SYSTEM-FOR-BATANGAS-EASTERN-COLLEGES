-- Migration: create work_orders table
-- Date: 2026-02-26
-- Run once against bec_equipment_db

CREATE TABLE IF NOT EXISTS `work_orders` (
  `work_order_id` VARCHAR(32) NOT NULL,
  `report_id` VARCHAR(32) DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `department` VARCHAR(120) DEFAULT NULL,
  `priority` ENUM('critical','high','medium','low') NOT NULL DEFAULT 'medium',
  `due_date` DATE DEFAULT NULL,
  `assigned_technician` VARCHAR(32) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `completion_note` TEXT DEFAULT NULL,
  `status` ENUM('open','in_progress','completed','closed','on_hold','deleted') NOT NULL DEFAULT 'open',
  `created_by` VARCHAR(32) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`work_order_id`),
  KEY `idx_wo_report_id` (`report_id`),
  KEY `idx_wo_status` (`status`),
  KEY `idx_wo_priority` (`priority`),
  KEY `idx_wo_due_date` (`due_date`),
  KEY `idx_wo_assigned_technician` (`assigned_technician`),
  KEY `idx_wo_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
