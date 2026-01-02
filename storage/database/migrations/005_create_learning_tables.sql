-- Create learning_confirmations table for ADR-009 Learning System
CREATE TABLE IF NOT EXISTS learning_confirmations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    raw_supplier_name TEXT NOT NULL,
    supplier_id INTEGER NOT NULL,
    confidence INTEGER,
    matched_anchor TEXT,
    anchor_type TEXT,
    action TEXT CHECK(action IN ('confirm', 'reject')),
    decision_time_seconds REAL,
    guarantee_id INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
);

CREATE INDEX IF NOT EXISTS idx_learning_raw_name ON learning_confirmations(raw_supplier_name);
CREATE INDEX IF NOT EXISTS idx_learning_action ON learning_confirmations(action);
