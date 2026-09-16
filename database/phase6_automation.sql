

CREATE TABLE IF NOT EXISTS automation_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    event VARCHAR(60) NOT NULL,
    action VARCHAR(60) NOT NULL,
    action_config JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_rules_org_event (organization_id, event, is_active),

    CONSTRAINT fk_rules_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- The durable queue. One row = one piece of work that MUST
-- happen, survives a worker crashing, and will never run twice
-- for the same real-world event thanks to idempotency_key.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS jobs_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    automation_rule_id BIGINT UNSIGNED NULL,

    job_type VARCHAR(60) NOT NULL,
    payload JSON NULL,

    -- Guarantees the same real-world event can never be queued
    -- twice, even if emit_event() somehow gets called twice for it.
    idempotency_key VARCHAR(191) NOT NULL UNIQUE,

    status ENUM('pending', 'processing', 'completed', 'failed', 'dead_letter')
        NOT NULL DEFAULT 'pending',

    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 5,

    -- A job isn't picked up again until this time -- this is how
    -- backoff delay works (wait longer after each failure).
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Which worker currently has this job -- prevents two workers
    -- from grabbing and running the same job at once.
    locked_by VARCHAR(120) NULL,
    locked_at DATETIME NULL,

    last_error TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_queue_pickup (status, available_at),
    INDEX idx_queue_org (organization_id),

    CONSTRAINT fk_queue_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- Permanent history of every attempt a job made -- this is what
-- the "inspect failed runs" admin page reads from. jobs_queue
-- tells you CURRENT state; this tells you the whole story.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS automation_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    job_queue_id BIGINT UNSIGNED NOT NULL,
    event VARCHAR(60) NULL,
    action VARCHAR(60) NOT NULL,
    attempt_number INT UNSIGNED NOT NULL,
    status ENUM('success', 'failed') NOT NULL,
    result_message TEXT NULL,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_runs_org (organization_id),
    INDEX idx_runs_job (job_queue_id),

    CONSTRAINT fk_runs_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- Basic in-app notifications, written by the "notify staff"
-- action. A real notification bell/UI can build on this later --
-- for now they're visible on the automation admin page.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS staff_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    message VARCHAR(255) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_notifications_org (organization_id, is_read),

    CONSTRAINT fk_notifications_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
