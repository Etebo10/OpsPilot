





CREATE TABLE IF NOT EXISTS organization_payment_providers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(30) NOT NULL DEFAULT 'paystack',
    public_key VARCHAR(255) NOT NULL,
    secret_key_encrypted TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_org_provider (organization_id, provider),

    CONSTRAINT fk_opp_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;




CREATE TABLE IF NOT EXISTS paystack_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL UNIQUE,
    organization_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,

    reference VARCHAR(100) NOT NULL UNIQUE,

    amount_kobo BIGINT UNSIGNED NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'NGN',

    status ENUM('pending', 'success', 'failed', 'refunded', 'disputed')
        NOT NULL DEFAULT 'pending',

    paystack_transaction_id BIGINT UNSIGNED NULL,
    channel VARCHAR(50) NULL,
    gateway_response VARCHAR(255) NULL,

    customer_email VARCHAR(190) NULL,

    verified_at DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_pt_org (organization_id),
    INDEX idx_pt_invoice (invoice_id),
    INDEX idx_pt_reference (reference),

    CONSTRAINT fk_pt_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_pt_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- Refunds are tracked separately from payments (rather than as
-- a "negative payment") so your payment history stays a clean
-- record of money actually collected.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS payment_refunds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    paystack_transaction_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_refund_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_refund_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_refund_transaction
        FOREIGN KEY (paystack_transaction_id) REFERENCES paystack_transactions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- A permanent record of every webhook Paystack ever sent you,
-- whether we acted on it, ignored it, or rejected it. This is
-- your reconciliation trail -- if a customer ever disputes a
-- payment, this table shows exactly what Paystack told us and
-- when.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(30) NOT NULL DEFAULT 'paystack',
    event_type VARCHAR(60) NULL,
    reference VARCHAR(100) NULL,
    signature_valid TINYINT(1) NOT NULL DEFAULT 0,
    processing_result ENUM('processed', 'duplicate_ignored', 'error', 'ignored_event_type', 'ignored_unknown_reference')
        NOT NULL,
    error_message TEXT NULL,
    raw_payload JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_webhook_reference (reference),
    INDEX idx_webhook_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
