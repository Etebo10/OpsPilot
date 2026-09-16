





CREATE TABLE IF NOT EXISTS invoice_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(12, 2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_invoice_items_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    INDEX idx_invoice_items_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



SET @col_exists = (
    SELECT COUNT(1) FROM information_schema.columns
    WHERE table_schema = 'opspilot'
      AND table_name = 'invoices'
      AND column_name = 'description'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE invoices MODIFY description VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;




SET @sql = IF(@col_exists > 0,
    'INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total, created_at)
     SELECT i.id, i.description, i.quantity, i.unit_price, i.subtotal, i.created_at
     FROM invoices i
     WHERE i.description IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.invoice_id = i.id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
