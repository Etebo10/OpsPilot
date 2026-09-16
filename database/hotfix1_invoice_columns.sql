




SET @col_exists = (
    SELECT COUNT(1) FROM information_schema.columns
    WHERE table_schema = 'opspilot' AND table_name = 'invoices' AND column_name = 'notes'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE invoices ADD COLUMN notes TEXT NULL AFTER due_date',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;




SET @col_exists = (
    SELECT COUNT(1) FROM information_schema.columns
    WHERE table_schema = 'opspilot' AND table_name = 'invoices' AND column_name = 'public_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE invoices ADD COLUMN public_id CHAR(36) NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


UPDATE invoices SET public_id = UUID() WHERE public_id IS NULL;

SET @idx_exists = (
    SELECT COUNT(1) FROM information_schema.statistics
    WHERE table_schema = 'opspilot' AND table_name = 'invoices' AND index_name = 'uq_invoices_public_id'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE invoices ADD UNIQUE KEY uq_invoices_public_id (public_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
