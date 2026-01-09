-- Migration 004: Remove extra_columns from guarantee_decisions
-- Date: 2026-01-10
-- Purpose: Clean up unused extra_columns field from schema

-- SQLite doesn't support DROP COLUMN directly, so we recreate the table

-- Step 1: Create new table without extra_columns
CREATE TABLE guarantee_decisions_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    is_locked BOOLEAN DEFAULT 0,
    locked_reason TEXT,
    supplier_id INTEGER,
    bank_id INTEGER,
    decision_source TEXT DEFAULT 'manual',
    confidence_score REAL,
    decided_at DATETIME,
    decided_by TEXT,
    last_modified_at DATETIME,
    last_modified_by TEXT,
    manual_override BOOLEAN DEFAULT 0,
    active_action TEXT NULL,
    active_action_set_at TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE SET NULL,
    UNIQUE(guarantee_id)
);

-- Step 2: Copy data from old table to new table
INSERT INTO guarantee_decisions_new 
    (id, guarantee_id, status, is_locked, locked_reason, 
     supplier_id, bank_id, decision_source, confidence_score,
     decided_at, decided_by, last_modified_at, last_modified_by,
     manual_override, active_action, active_action_set_at,
     created_at, updated_at)
SELECT 
    id, guarantee_id, status, is_locked, locked_reason, 
    supplier_id, bank_id, decision_source, confidence_score,
    decided_at, decided_by, last_modified_at, last_modified_by,
    manual_override, active_action, active_action_set_at,
    created_at, updated_at
FROM guarantee_decisions;

-- Step 3: Drop old table
DROP TABLE guarantee_decisions;

-- Step 4: Rename new table
ALTER TABLE guarantee_decisions_new RENAME TO guarantee_decisions;

-- Step 5: Recreate indexes
CREATE INDEX IF NOT EXISTS idx_guarantee_decisions_guarantee_id ON guarantee_decisions(guarantee_id);
CREATE INDEX IF NOT EXISTS idx_guarantee_decisions_status ON guarantee_decisions(status);

-- Verify migration
-- SELECT COUNT(*) FROM guarantee_decisions;
-- PRAGMA table_info(guarantee_decisions);
