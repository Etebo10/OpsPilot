



CREATE TABLE IF NOT EXISTS audit_logs (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  
    organization_id BIGINT UNSIGNED NULL,

    actor_user_id BIGINT UNSIGNED NULL,

    action VARCHAR(100) NOT NULL,

    entity_type VARCHAR(60) NOT NULL,

    entity_id BIGINT UNSIGNED NULL,

    before_data JSON NULL,

    after_data JSON NULL,

    metadata JSON NULL,

    ip_address VARCHAR(45) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_audit_org_time (organization_id, created_at),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_actor (actor_user_id),

    CONSTRAINT fk_audit_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_audit_actor
        FOREIGN KEY (actor_user_id)
        REFERENCES users(id)
        ON DELETE SET NULL
);




SET @idx_exists = (
    SELECT COUNT(1) FROM information_schema.statistics
    WHERE table_schema = 'opspilot'
      AND table_name = 'invoices'
      AND index_name = 'idx_invoices_org_status'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE invoices ADD INDEX idx_invoices_org_status (organization_id, status)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(1) FROM information_schema.statistics
    WHERE table_schema = 'opspilot'
      AND table_name = 'payments'
      AND index_name = 'idx_payments_org_date'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE payments ADD INDEX idx_payments_org_date (organization_id, paid_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(1) FROM information_schema.statistics
    WHERE table_schema = 'opspilot'
      AND table_name = 'jobs'
      AND index_name = 'idx_jobs_org_status'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE jobs ADD INDEX idx_jobs_org_status (organization_id, status)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
