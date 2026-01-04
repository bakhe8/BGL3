-- Migration: Remove Unused amount Column from guarantee_decisions
-- Date: 2026-01-04
-- Reason: Column is unused in codebase, redundant with raw_data['amount']
-- Safety: Verified via grep - no references in PHP code

-- IMPORTANT: Foreign keys must be disabled during table recreation
PRAGMA foreign_keys = OFF;

BEGIN TRANSACTION;

-- Step 1: Create new table without amount column
CREATE TABLE guarantee_decisions_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL,
    
    -- Status & Lock
    status TEXT NOT NULL DEFAULT 'pending',
    is_locked BOOLEAN DEFAULT 0,
    locked_reason TEXT,
    
    -- Decisions
    supplier_id INTEGER,
    bank_id INTEGER,
    
    -- Decision Metadata
    decision_source TEXT DEFAULT 'manual',
    confidence_score REAL,
    decided_at DATETIME,
    decided_by TEXT,
    
    -- Last modification (distinct from decision)
    last_modified_at DATETIME,
    last_modified_by TEXT,
    manual_override BOOLEAN DEFAULT 0,
    
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    -- Active Action (UI cache)
    active_action TEXT NULL,
    active_action_set_at TEXT NULL,
    
    -- Foreign Keys
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE SET NULL,
    
    -- Constraints
    UNIQUE(guarantee_id)
);

-- Step 2: Copy all data (excluding amount column)
INSERT INTO guarantee_decisions_new (
    id, guarantee_id, status, is_locked, locked_reason,
    supplier_id, bank_id, decision_source, confidence_score,
    decided_at, decided_by, last_modified_at, last_modified_by,
    manual_override, created_at, updated_at, active_action, active_action_set_at
)
SELECT 
    id, guarantee_id, status, is_locked, locked_reason,
    supplier_id, bank_id, decision_source, confidence_score,
    decided_at, decided_by, last_modified_at, last_modified_by,
    manual_override, created_at, updated_at, active_action, active_action_set_at
FROM guarantee_decisions;

-- Step 3: Drop old table
DROP TABLE guarantee_decisions;

-- Step 4: Rename new table
ALTER TABLE guarantee_decisions_new RENAME TO guarantee_decisions;

-- Step 5: Recreate all indexes
CREATE INDEX idx_decisions_guarantee ON guarantee_decisions(guarantee_id);
CREATE INDEX idx_decisions_status ON guarantee_decisions(status);
CREATE INDEX idx_decisions_locked ON guarantee_decisions(is_locked) WHERE is_locked = 1;
CREATE INDEX idx_decisions_source ON guarantee_decisions(decision_source);
CREATE INDEX idx_decisions_decided_at ON guarantee_decisions(decided_at DESC);
CREATE INDEX idx_decisions_status_decided ON guarantee_decisions(status, decided_at DESC);
CREATE INDEX idx_decisions_status_supplier ON guarantee_decisions(status, supplier_id) WHERE supplier_id IS NOT NULL;
CREATE INDEX idx_decisions_supplier_bank ON guarantee_decisions(supplier_id, bank_id);
CREATE INDEX idx_active_action ON guarantee_decisions(active_action);
CREATE INDEX idx_guarantee_decisions_supplier ON guarantee_decisions(supplier_id) WHERE supplier_id IS NOT NULL;

COMMIT;

-- Re-enable foreign keys
PRAGMA foreign_keys = ON;

-- Verify integrity
PRAGMA foreign_key_check(guarantee_decisions);
