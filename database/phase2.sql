

-- =========================================================
-- CUSTOMERS
-- =========================================================

CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL UNIQUE,
    organization_id BIGINT UNSIGNED NOT NULL,

    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,

    email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,

    company_name VARCHAR(150) NULL,

    address VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(100) NULL,
    country VARCHAR(100) NULL,

    notes TEXT NULL,

    status ENUM('active','inactive','archived')
        NOT NULL DEFAULT 'active',

    source VARCHAR(80) NULL,

    total_spent DECIMAL(15,2)
        NOT NULL DEFAULT 0.00,

    last_activity_at DATETIME NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_customers_org
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE CASCADE,

    INDEX idx_customers_org (organization_id),
    INDEX idx_customers_email (organization_id, email),
    INDEX idx_customers_phone (organization_id, phone),
    INDEX idx_customers_status (organization_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =========================================================
-- JOBS
-- =========================================================

CREATE TABLE IF NOT EXISTS jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL UNIQUE,

    organization_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,

    job_number VARCHAR(50) NOT NULL,

    title VARCHAR(180) NOT NULL,
    description TEXT NULL,

    status ENUM(
        'draft',
        'scheduled',
        'in_progress',
        'completed',
        'cancelled'
    ) NOT NULL DEFAULT 'draft',

    priority ENUM(
        'low',
        'normal',
        'high',
        'urgent'
    ) NOT NULL DEFAULT 'normal',

    scheduled_start DATETIME NULL,
    scheduled_end DATETIME NULL,

    completed_at DATETIME NULL,

    estimated_amount DECIMAL(15,2)
        NOT NULL DEFAULT 0.00,

    actual_amount DECIMAL(15,2)
        NOT NULL DEFAULT 0.00,

    assigned_to BIGINT UNSIGNED NULL,

    notes TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_jobs_org
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_jobs_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE CASCADE,

    INDEX idx_jobs_org (organization_id),
    INDEX idx_jobs_customer (organization_id, customer_id),
    INDEX idx_jobs_status (organization_id, status),
    INDEX idx_jobs_schedule (organization_id, scheduled_start),

    UNIQUE KEY uq_job_number (organization_id, job_number)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =========================================================
-- INVOICES
-- =========================================================

CREATE TABLE IF NOT EXISTS invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL UNIQUE,

    organization_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,

    job_id BIGINT UNSIGNED NULL,

    invoice_number VARCHAR(50) NOT NULL,

    status ENUM(
        'draft',
        'sent',
        'partially_paid',
        'paid',
        'overdue',
        'cancelled'
    ) NOT NULL DEFAULT 'draft',

    issue_date DATE NOT NULL,
    due_date DATE NULL,

    subtotal DECIMAL(15,2)
        NOT NULL DEFAULT 0.00,

    tax_amount DECIMAL(15,2)
        NOT NULL DEFAULT 0.00,

    discount_amount DECIMAL(15,2)
        NOT NULL DEFAULT 0.00,

    total_amount DECIMAL(15,2)
        NOT NULL DEFAULT 0.00,

    amount_paid DECIMAL(15,2)
        NOT NULL DEFAULT 0.00,

    notes TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_invoices_org
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_invoices_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_invoices_job
        FOREIGN KEY (job_id)
        REFERENCES jobs(id)
        ON DELETE SET NULL,

    INDEX idx_invoices_org (organization_id),
    INDEX idx_invoices_customer (organization_id, customer_id),
    INDEX idx_invoices_status (organization_id, status),
    INDEX idx_invoices_due (organization_id, due_date),

    UNIQUE KEY uq_invoice_number
        (organization_id, invoice_number)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =========================================================
-- INVOICE LINE ITEMS
-- =========================================================

CREATE TABLE IF NOT EXISTS invoice_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    invoice_id BIGINT UNSIGNED NOT NULL,

    description VARCHAR(255) NOT NULL,

    quantity DECIMAL(10,2)
        NOT NULL DEFAULT 1,

    unit_price DECIMAL(15,2)
        NOT NULL DEFAULT 0.00,

    total DECIMAL(15,2)
        NOT NULL DEFAULT 0.00,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_invoice_items_invoice
        FOREIGN KEY (invoice_id)
        REFERENCES invoices(id)
        ON DELETE CASCADE,

    INDEX idx_invoice_items_invoice (invoice_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =========================================================
-- PAYMENTS
-- =========================================================

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    public_id CHAR(36) NOT NULL UNIQUE,

    organization_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,

    amount DECIMAL(15,2) NOT NULL,

    payment_method ENUM(
        'cash',
        'bank_transfer',
        'card',
        'online',
        'other'
    ) NOT NULL DEFAULT 'other',

    reference VARCHAR(120) NULL,

    paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    notes TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_payments_org
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_payments_invoice
        FOREIGN KEY (invoice_id)
        REFERENCES invoices(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_payments_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE CASCADE,

    INDEX idx_payments_org (organization_id),
    INDEX idx_payments_invoice (invoice_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;