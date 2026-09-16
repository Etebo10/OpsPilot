

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS invoice_items;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS jobs;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS organization_settings;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS organizations;

SET FOREIGN_KEY_CHECKS = 1;


/*
|--------------------------------------------------------------------------
| ORGANIZATIONS
|--------------------------------------------------------------------------
*/

CREATE TABLE organizations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(150) NOT NULL,

    slug VARCHAR(180) NOT NULL UNIQUE,

    industry VARCHAR(100) NULL,

    email VARCHAR(190) NULL,

    phone VARCHAR(50) NULL,

    website VARCHAR(255) NULL,

    address TEXT NULL,

    city VARCHAR(100) NULL,

    state VARCHAR(100) NULL,

    country VARCHAR(100) DEFAULT 'Nigeria',

    timezone VARCHAR(80) DEFAULT 'Africa/Lagos',

    currency VARCHAR(10) DEFAULT 'NGN',

    onboarding_completed BOOLEAN DEFAULT FALSE,

    is_active BOOLEAN DEFAULT TRUE,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);


/*
|--------------------------------------------------------------------------
| USERS
|--------------------------------------------------------------------------
*/

CREATE TABLE users (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    organization_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(150) NOT NULL,

    email VARCHAR(190) NOT NULL,

    password_hash VARCHAR(255) NOT NULL,

    role ENUM(
        'owner',
        'admin',
        'manager',
        'staff'
    ) DEFAULT 'owner',

    is_active BOOLEAN DEFAULT TRUE,

    email_verified BOOLEAN DEFAULT FALSE,

    last_login_at TIMESTAMP NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_org_email (
        organization_id,
        email
    ),

    INDEX idx_users_organization (
        organization_id
    ),

    CONSTRAINT fk_users_organization

        FOREIGN KEY (
            organization_id
        )

        REFERENCES organizations(id)

        ON DELETE CASCADE
);


/*
|--------------------------------------------------------------------------
| ORGANIZATION SETTINGS
|--------------------------------------------------------------------------
*/

CREATE TABLE organization_settings (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    organization_id BIGINT UNSIGNED NOT NULL UNIQUE,

    business_hours JSON NULL,

    working_days JSON NULL,

    communication_channels JSON NULL,

    services_config JSON NULL,

    booking_settings JSON NULL,

    notification_settings JSON NULL,

    ai_settings JSON NULL,

    onboarding_data JSON NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_settings_organization

        FOREIGN KEY (
            organization_id
        )

        REFERENCES organizations(id)

        ON DELETE CASCADE
);


/*
|--------------------------------------------------------------------------
| LOGIN SECURITY / RATE LIMITING
|--------------------------------------------------------------------------
*/

CREATE TABLE login_attempts (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    email VARCHAR(190) NOT NULL,

    ip_address VARCHAR(45) NOT NULL,

    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_login_attempts (
        email,
        ip_address,
        attempted_at
    )
);


/*
|--------------------------------------------------------------------------
| CUSTOMERS
|--------------------------------------------------------------------------
*/

CREATE TABLE customers (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    public_id CHAR(36) NOT NULL UNIQUE,

    organization_id BIGINT UNSIGNED NOT NULL,

    first_name VARCHAR(100) NOT NULL,

    last_name VARCHAR(100) NOT NULL,

    email VARCHAR(190) NULL,

    phone VARCHAR(50) NULL,

    company_name VARCHAR(150) NULL,

    notes TEXT NULL,

    source VARCHAR(50) NOT NULL DEFAULT 'manual',

    total_spent DECIMAL(15, 2) NOT NULL DEFAULT 0,

    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',

    last_activity_at TIMESTAMP NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_customers_organization (organization_id),

    CONSTRAINT fk_customers_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE CASCADE
);


/*
|--------------------------------------------------------------------------
| JOBS
|--------------------------------------------------------------------------
*/

CREATE TABLE jobs (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    public_id CHAR(36) NOT NULL UNIQUE,

    organization_id BIGINT UNSIGNED NOT NULL,

    customer_id BIGINT UNSIGNED NOT NULL,

    job_number VARCHAR(40) NOT NULL UNIQUE,

    title VARCHAR(200) NOT NULL,

    description TEXT NULL,

    priority ENUM('low', 'normal', 'high', 'urgent')
        NOT NULL DEFAULT 'normal',

    status ENUM(
        'draft',
        'scheduled',
        'in_progress',
        'completed',
        'cancelled'
    ) NOT NULL DEFAULT 'draft',

    estimated_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,

    actual_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,

    scheduled_start DATETIME NULL,

    scheduled_end DATETIME NULL,

    assigned_to BIGINT UNSIGNED NULL,

    notes TEXT NULL,

    completed_at TIMESTAMP NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_jobs_organization (organization_id),
    INDEX idx_jobs_customer (customer_id),

    CONSTRAINT fk_jobs_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_jobs_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE RESTRICT
);


/*
|--------------------------------------------------------------------------
| INVOICES
|--------------------------------------------------------------------------
*/

CREATE TABLE invoices (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    organization_id BIGINT UNSIGNED NOT NULL,

    customer_id BIGINT UNSIGNED NULL,

    job_id BIGINT UNSIGNED NULL,

    invoice_number VARCHAR(40) NOT NULL UNIQUE,

    issue_date DATE NOT NULL,

    due_date DATE NULL,

    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(12, 2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(12, 2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    tax DECIMAL(12, 2) NOT NULL DEFAULT 0,

    tax_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,

    discount_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,


    amount_paid DECIMAL(12, 2) NOT NULL DEFAULT 0,

    status ENUM(
        'draft',
        'sent',
        'partially_paid',
        'paid',
        'overdue',
        'cancelled'
    )
        NOT NULL DEFAULT 'draft',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_invoices_organization (organization_id),
    INDEX idx_invoices_customer (customer_id),
    INDEX idx_invoices_job (job_id),

    CONSTRAINT fk_invoices_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_invoices_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_invoices_job
        FOREIGN KEY (job_id)
        REFERENCES jobs(id)
        ON DELETE SET NULL
);


/*
|--------------------------------------------------------------------------
| INVOICE ITEMS
|--------------------------------------------------------------------------
*/

CREATE TABLE invoice_items (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    invoice_id BIGINT UNSIGNED NOT NULL,

    description VARCHAR(255) NOT NULL,

    quantity DECIMAL(12, 2) NOT NULL DEFAULT 1,

    unit_price DECIMAL(12, 2) NOT NULL DEFAULT 0,

    total DECIMAL(12, 2) NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_invoice_items_invoice
        FOREIGN KEY (invoice_id)
        REFERENCES invoices(id)
        ON DELETE CASCADE,

    INDEX idx_invoice_items_invoice (invoice_id)
);


/*
|--------------------------------------------------------------------------
| PAYMENTS
|--------------------------------------------------------------------------
*/

CREATE TABLE payments (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    public_id CHAR(36) NOT NULL UNIQUE,

    organization_id BIGINT UNSIGNED NOT NULL,

    invoice_id BIGINT UNSIGNED NOT NULL,

    customer_id BIGINT UNSIGNED NOT NULL,

    amount DECIMAL(12, 2) NOT NULL,

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

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_payments_organization
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

    INDEX idx_payments_organization (organization_id),
    INDEX idx_payments_invoice (invoice_id)
);