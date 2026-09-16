

SET @col_exists = (
    SELECT COUNT(1) FROM information_schema.columns
    WHERE table_schema = 'opspilot'
      AND table_name = 'contacts'
      AND column_name = 'email_bounced_at'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE contacts ADD COLUMN email_bounced_at DATETIME NULL AFTER phone',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ------------------------------------------------------------
-- Same idea as payment_webhook_events from the Paystack phase --
-- a permanent record of every webhook your email provider sends,
-- whether it was an inbound message, a bounce, or anything else.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS email_webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(60) NULL,
    recipient VARCHAR(190) NULL,
    signature_valid TINYINT(1) NOT NULL DEFAULT 0,
    processing_result ENUM('processed', 'duplicate_ignored', 'error', 'ignored_unknown_recipient')
        NOT NULL,
    error_message TEXT NULL,
    raw_payload JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_email_events_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
