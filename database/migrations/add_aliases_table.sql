-- Create supplier_alternative_names table
CREATE TABLE IF NOT EXISTS supplier_alternative_names (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier_id INTEGER NOT NULL,
    alternative_name TEXT NOT NULL,
    normalized_name TEXT,
    source TEXT DEFAULT 'manual',
    usage_count INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_alt_names_norm ON supplier_alternative_names(normalized_name);
