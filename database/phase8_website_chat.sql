

SET @col_exists = (
    SELECT COUNT(1) FROM information_schema.columns
    WHERE table_schema = 'opspilot'
      AND table_name = 'channel_accounts'
      AND column_name = 'public_config'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE channel_accounts ADD COLUMN public_config JSON NULL AFTER config_encrypted',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ------------------------------------------------------------
-- Simple rate-limiting for the public widget endpoint -- one row
-- per request attempt. We just count recent rows per IP; old
-- rows are harmless to keep around and cheap to ignore.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS widget_rate_limit_hits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_rate_limit_ip_time (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
