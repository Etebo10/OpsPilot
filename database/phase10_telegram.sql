

ALTER TABLE channel_accounts
    MODIFY channel_type ENUM('website_chat', 'email', 'whatsapp', 'instagram', 'telegram') NOT NULL;

ALTER TABLE conversations
    MODIFY channel_type ENUM('website_chat', 'email', 'whatsapp', 'instagram', 'telegram') NOT NULL;


-- ------------------------------------------------------------
-- Telegram (and Instagram, later) don't identify people by email
-- or phone -- they use their own internal IDs (a Telegram chat_id,
-- an Instagram PSID). Rather than adding a new column to `contacts`
-- for every channel forever, this one JSON column holds all of
-- them: {"telegram": "123456789", "instagram": "..."}.
-- ------------------------------------------------------------

SET @col_exists = (
    SELECT COUNT(1) FROM information_schema.columns
    WHERE table_schema = 'opspilot'
      AND table_name = 'contacts'
      AND column_name = 'channel_identities'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE contacts ADD COLUMN channel_identities JSON NULL AFTER phone',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
