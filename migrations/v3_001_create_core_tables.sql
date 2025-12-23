-- ============================================================================
-- Migration: Create V3 Schema (Clean Architecture)
-- Created: 2025-12-23
-- Description: New clean schema without sessions, with proper separation
-- ============================================================================

-- Disable foreign keys temporarily for migration
PRAGMA foreign_keys = OFF;

-- ============================================================================
-- 1. GUARANTEES TABLE (Raw Data Storage)
-- ============================================================================

CREATE TABLE IF NOT EXISTS guarantees (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_number TEXT NOT NULL,
    
    -- Raw Data (JSON - immutable source of truth)
    raw_data JSON NOT NULL,
    /*
    Example structure:
    {
      "supplier": "K.F.S.H",
      "bank": "الراجحي",
      "amount": "500000.00",
      "issue_date": "2024-01-15",
      "expiry_date": "2025-01-15",
      "document_reference": "C-2024-100",
      "related_to": "contract",  -- or "purchase_order"
      "type": "FINAL",
      "comment": null
    }
    */
    
    -- Import Tracking (per guarantee)
    import_source TEXT NOT NULL,  -- 'excel', 'smart_paste', 'manual'
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    imported_by TEXT,  -- Future: username
    
    -- Constraints
    UNIQUE(guarantee_number)
);

-- Indexes for guarantees
CREATE INDEX IF NOT EXISTS idx_guarantees_number ON guarantees(guarantee_number);
CREATE INDEX IF NOT EXISTS idx_guarantees_import_source ON guarantees(import_source);
CREATE INDEX IF NOT EXISTS idx_guarantees_imported_at ON guarantees(imported_at DESC);
CREATE INDEX IF NOT EXISTS idx_guarantees_date_source ON guarantees(DATE(imported_at), import_source);

-- JSON indexes for common queries
CREATE INDEX IF NOT EXISTS idx_guarantees_expiry 
ON guarantees(JSON_EXTRACT(raw_data, '$.expiry_date'));

CREATE INDEX IF NOT EXISTS idx_guarantees_type 
ON guarantees(JSON_EXTRACT(raw_data, '$.type'));

CREATE INDEX IF NOT EXISTS idx_guarantees_related_to 
ON guarantees(JSON_EXTRACT(raw_data, '$.related_to'));

-- ============================================================================
-- 2. GUARANTEE_DECISIONS TABLE (Current State)
-- ============================================================================

CREATE TABLE IF NOT EXISTS guarantee_decisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL,
    
    -- Status & Lock
    status TEXT NOT NULL DEFAULT 'pending',  -- 'pending', 'ready', 'approved', 'locked'
    is_locked BOOLEAN DEFAULT 0,
    locked_reason TEXT,  -- 'released', 'extended', 'manual_override'
    
    -- Decisions
    supplier_id INTEGER,
    bank_id INTEGER,
    
    -- Decision Metadata
    decision_source TEXT DEFAULT 'manual',  -- 'manual', 'ai_quick', 'ai_assisted', 'propagated', 'auto_match'
    confidence_score REAL,
    decided_at DATETIME,
    decided_by TEXT,
    
    -- Last modification (distinct from decision)
    last_modified_at DATETIME,
    last_modified_by TEXT,
    manual_override BOOLEAN DEFAULT 0,  -- Prevents auto-matching
    
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign Keys
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE SET NULL,
    
    -- Constraints
    UNIQUE(guarantee_id)
);

-- Indexes for decisions
CREATE INDEX IF NOT EXISTS idx_decisions_guarantee ON guarantee_decisions(guarantee_id);
CREATE INDEX IF NOT EXISTS idx_decisions_status ON guarantee_decisions(status);
CREATE INDEX IF NOT EXISTS idx_decisions_locked ON guarantee_decisions(is_locked) WHERE is_locked = 1;
CREATE INDEX IF NOT EXISTS idx_decisions_source ON guarantee_decisions(decision_source);
CREATE INDEX IF NOT EXISTS idx_decisions_decided_at ON guarantee_decisions(decided_at DESC);

-- Composite indexes for advanced filtering
CREATE INDEX IF NOT EXISTS idx_decisions_status_decided 
ON guarantee_decisions(status, decided_at DESC);

CREATE INDEX IF NOT EXISTS idx_decisions_status_supplier 
ON guarantee_decisions(status, supplier_id) WHERE supplier_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_decisions_supplier_bank 
ON guarantee_decisions(supplier_id, bank_id);

-- ============================================================================
-- 3. GUARANTEE_ACTIONS TABLE (Extension/Release/Reduction)
-- ============================================================================

CREATE TABLE IF NOT EXISTS guarantee_actions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL,
    
    -- Action Type
    action_type TEXT NOT NULL,  -- 'extension', 'release', 'reduction'
    action_date DATE NOT NULL,
    
    -- Extension (always +1 year)
    previous_expiry_date DATE,
    new_expiry_date DATE,
    
    -- Reduction (amount only)
    previous_amount DECIMAL(15,2),
    new_amount DECIMAL(15,2),
    
    -- Release (no changes - status only)
    release_reason TEXT,
    
    -- Status
    action_status TEXT DEFAULT 'pending',  -- 'pending', 'issued', 'cancelled'
    notes TEXT,
    
    -- Metadata
    letter_generated BOOLEAN DEFAULT 0,
    letter_issued_at DATETIME,
    
    -- Audit
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by TEXT,
    
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE
);

-- Indexes for actions
CREATE INDEX IF NOT EXISTS idx_actions_guarantee ON guarantee_actions(guarantee_id);
CREATE INDEX IF NOT EXISTS idx_actions_type ON guarantee_actions(action_type);
CREATE INDEX IF NOT EXISTS idx_actions_date ON guarantee_actions(action_date DESC);
CREATE INDEX IF NOT EXISTS idx_actions_status ON guarantee_actions(action_status);
CREATE INDEX IF NOT EXISTS idx_actions_guarantee_type 
ON guarantee_actions(guarantee_id, action_type, action_status);

-- ============================================================================
-- BUSINESS RULES TRIGGERS
-- ============================================================================

-- Trigger: Prevent extension after release
CREATE TRIGGER IF NOT EXISTS prevent_extension_after_release
BEFORE INSERT ON guarantee_actions
WHEN NEW.action_type = 'extension'
BEGIN
    SELECT CASE
        WHEN EXISTS (
            SELECT 1 FROM guarantee_actions
            WHERE guarantee_id = NEW.guarantee_id
            AND action_type = 'release'
            AND action_status = 'issued'
        )
        THEN RAISE(ABORT, 'Cannot extend after release')
    END;
END;

-- Trigger: Extension is always +1 year
CREATE TRIGGER IF NOT EXISTS enforce_extension_one_year
BEFORE INSERT ON guarantee_actions
WHEN NEW.action_type = 'extension'
BEGIN
    UPDATE guarantee_actions
    SET new_expiry_date = DATE(NEW.previous_expiry_date, '+1 year')
    WHERE id = NEW.id;
END;

-- Trigger: Reduction must be lower than previous
CREATE TRIGGER IF NOT EXISTS enforce_reduction_lower
BEFORE INSERT ON guarantee_actions
WHEN NEW.action_type = 'reduction'
BEGIN
    SELECT CASE
        WHEN NEW.new_amount >= NEW.previous_amount
        THEN RAISE(ABORT, 'Reduction amount must be less than previous')
    END;
END;

-- Trigger: Update guarantee_decisions.updated_at automatically
CREATE TRIGGER IF NOT EXISTS update_decisions_timestamp
AFTER UPDATE ON guarantee_decisions
BEGIN
    UPDATE guarantee_decisions
    SET updated_at = CURRENT_TIMESTAMP
    WHERE id = NEW.id;
END;

-- ============================================================================
-- Re-enable foreign keys
-- ============================================================================
PRAGMA foreign_keys = ON;

-- ============================================================================
-- Verification
-- ============================================================================

-- Check tables created
SELECT name FROM sqlite_master 
WHERE type='table' 
AND name IN ('guarantees', 'guarantee_decisions', 'guarantee_actions')
ORDER BY name;
