-- Workflow role portal extension
-- Apply if the users.role enum still does not include Dean and Finance.

ALTER TABLE users
    MODIFY role ENUM('admin','pmo','dean','finance','technician','student','faculty','handler','guest') NOT NULL;
