

CREATE TABLE IF NOT EXISTS contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_contacts_org_email (organization_id, email),
    INDEX idx_contacts_org_phone (organization_id, phone),

    CONSTRAINT fk_contacts_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_contacts_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- One row per connected channel (their support email, their
-- WhatsApp Business number, etc). Credentials are encrypted the
-- same way Paystack keys are (see app/Helpers/crypto.php).
-- Mostly empty for now -- Phases 6-9 fill these in as each
-- channel gets connected. website_chat needs no external
-- credentials at all, so it can work today.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS channel_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    channel_type ENUM('website_chat', 'email', 'whatsapp', 'instagram') NOT NULL,
    display_name VARCHAR(150) NULL,
    external_account_id VARCHAR(190) NULL,
    config_encrypted TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    connected_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_org_channel (organization_id, channel_type),

    CONSTRAINT fk_channel_accounts_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- One conversation = one back-and-forth thread with one contact
-- on one channel.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL UNIQUE,
    organization_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    channel_account_id BIGINT UNSIGNED NULL,
    channel_type ENUM('website_chat', 'email', 'whatsapp', 'instagram') NOT NULL,
    external_thread_id VARCHAR(190) NULL,
    subject VARCHAR(255) NULL,

    status ENUM('open', 'pending', 'resolved', 'archived') NOT NULL DEFAULT 'open',
    assigned_to_user_id BIGINT UNSIGNED NULL,

    unread_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_message_at TIMESTAMP NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_conv_org_status (organization_id, status),
    INDEX idx_conv_org_assigned (organization_id, assigned_to_user_id),
    INDEX idx_conv_org_last_message (organization_id, last_message_at),
    INDEX idx_conv_contact (contact_id),

    CONSTRAINT fk_conversations_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversations_contact
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversations_channel_account
        FOREIGN KEY (channel_account_id) REFERENCES channel_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_conversations_assigned_user
        FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- Every message in every conversation. `direction` = 'internal'
-- is how internal staff notes work -- they live in the same
-- thread but are never sent to the contact.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL UNIQUE,
    conversation_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,

    direction ENUM('inbound', 'outbound', 'internal') NOT NULL,
    sender_type ENUM('contact', 'staff', 'system') NOT NULL,
    sender_user_id BIGINT UNSIGNED NULL,

    body TEXT NOT NULL,

    attachment_path VARCHAR(255) NULL,
    attachment_original_name VARCHAR(255) NULL,
    attachment_mime VARCHAR(100) NULL,
    attachment_size_bytes INT UNSIGNED NULL,

    provider_message_id VARCHAR(190) NULL,
    status ENUM('queued', 'sent', 'delivered', 'failed') NOT NULL DEFAULT 'delivered',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_messages_conversation (conversation_id, created_at),
    INDEX idx_messages_org (organization_id),

    CONSTRAINT fk_messages_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender_user
        FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- One row per attempt to actually deliver an outbound message
-- through its channel -- separate from jobs_queue's own retry
-- bookkeeping, this is the channel-specific delivery history
-- (useful once real providers are connected in later phases).
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS message_delivery_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    attempt_number INT UNSIGNED NOT NULL,
    status ENUM('success', 'failed') NOT NULL,
    provider_response TEXT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_delivery_message (message_id),

    CONSTRAINT fk_delivery_message
        FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
