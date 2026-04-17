-- Hybrid PMO workflow migration
-- Apply after repairing the MySQL instance.

ALTER TABLE users
    MODIFY role ENUM('admin','pmo','dean','finance','technician','student','faculty','handler','guest') NOT NULL;

ALTER TABLE defect_reports
    MODIFY status ENUM(
        'reported',
        'pmo_review',
        'dean_review',
        'finance_review',
        'on_hold_budget',
        'ready_for_assignment',
        'assigned',
        'in_progress',
        'for_replacement',
        'completed',
        'verified',
        'closed',
        'rejected'
    ) NOT NULL DEFAULT 'reported';

ALTER TABLE defect_reports
    ADD COLUMN location VARCHAR(200) NULL AFTER issue_description,
    ADD COLUMN category VARCHAR(100) NULL AFTER location,
    ADD COLUMN reporter_name VARCHAR(150) NULL AFTER reported_by,
    ADD COLUMN reporter_email VARCHAR(150) NULL AFTER reporter_name,
    ADD COLUMN pmo_review_status VARCHAR(40) NULL AFTER status,
    ADD COLUMN pmo_reviewed_by VARCHAR(20) NULL AFTER pmo_review_status,
    ADD COLUMN pmo_reviewed_at DATETIME NULL AFTER pmo_reviewed_by,
    ADD COLUMN pmo_notes TEXT NULL AFTER pmo_reviewed_at,
    ADD COLUMN dean_approval_status VARCHAR(40) NULL AFTER pmo_notes,
    ADD COLUMN dean_approved_by VARCHAR(20) NULL AFTER dean_approval_status,
    ADD COLUMN dean_approved_at DATETIME NULL AFTER dean_approved_by,
    ADD COLUMN dean_notes TEXT NULL AFTER dean_approved_at,
    ADD COLUMN finance_approval_status VARCHAR(40) NULL AFTER dean_notes,
    ADD COLUMN finance_approved_by VARCHAR(20) NULL AFTER finance_approval_status,
    ADD COLUMN finance_approved_at DATETIME NULL AFTER finance_approved_by,
    ADD COLUMN finance_notes TEXT NULL AFTER finance_approved_at,
    ADD COLUMN budget_status VARCHAR(40) NULL AFTER finance_notes,
    ADD COLUMN pmo_verified_by VARCHAR(20) NULL AFTER budget_status,
    ADD COLUMN pmo_verified_at DATETIME NULL AFTER pmo_verified_by,
    ADD COLUMN replacement_notes TEXT NULL AFTER pmo_verified_at,
    ADD COLUMN assigned_by VARCHAR(20) NULL AFTER assigned_to;

CREATE INDEX idx_defect_status_workflow ON defect_reports(status, priority, report_date);
