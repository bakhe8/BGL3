-- ============================================================================
-- Migration: Create V3 Learning Tables
-- Created: 2025-12-23
-- Description: Learning cache and decisions log for AI improvements
-- ============================================================================

PRAGMA foreign_keys = OFF;

-- ============================================================================
-- 4. SUPPLIER_LEARNING_CACHE TABLE (Fast Suggestions)
-- ============================================================================

CREATE TABLE IF NOT EXISTS supplier_learning_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    normalized_input TEXT NOT NULL,
    supplier_id INTEGER NOT NULL,
    
    -- Scoring Components (from ScoringConfig)
    fuzzy_score REAL DEFAULT 0.0 CHECK(fuzzy_score >= 0.0 AND fuzzy_score <= 1.0),
    source_weight INTEGER DEFAULT 0,
    usage_count INTEGER DEFAULT 0,
    block_count INTEGER DEFAULT 0,
    
    -- Computed Scores (GENERATED columns for performance)
    total_score REAL GENERATED ALWAYS AS (
        (fuzzy_score * 100) + 
        source_weight + 
        CASE 
            WHEN (usage_count * 15) > 75 THEN 75
            ELSE (usage_count * 15)
        END
    ) STORED,
    
    effective_score REAL GENERATED ALWAYS AS (
        (fuzzy_score * 100) + 
        source_weight + 
        CASE 
            WHEN (usage_count * 15) > 75 THEN 75
            ELSE (usage_count * 15)
        END - 
        (block_count * 50)
    ) STORED,
    
    star_rating INTEGER GENERATED ALWAYS AS (
        CASE
            WHEN ((fuzzy_score * 100) + source_weight + 
                  CASE WHEN (usage_count * 15) > 75 THEN 75 ELSE (usage_count * 15) END) >= 200
            THEN 3
            WHEN ((fuzzy_score * 100) + source_weight + 
                  CASE WHEN (usage_count * 15) > 75 THEN 75 ELSE (usage_count * 15) END) >= 120
            THEN 2
            ELSE 1
        END
    ) STORED,
    
    -- Metadata
    last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    UNIQUE(normalized_input, supplier_id)
);

-- Indexes for learning cache
CREATE INDEX IF NOT EXISTS idx_learning_normalized_score 
ON supplier_learning_cache(normalized_input, effective_score DESC) 
WHERE effective_score > 0;

CREATE INDEX IF NOT EXISTS idx_learning_supplier 
ON supplier_learning_cache(supplier_id);

CREATE INDEX IF NOT EXISTS idx_learning_last_used 
ON supplier_learning_cache(last_used_at DESC);

-- ============================================================================
-- 5. SUPPLIER_DECISIONS_LOG TABLE (Learning History)
-- ============================================================================

CREATE TABLE IF NOT EXISTS supplier_decisions_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL,
    
    -- Input
    raw_input TEXT NOT NULL,
    normalized_input TEXT NOT NULL,
    
    -- Decision
    chosen_supplier_id INTEGER NOT NULL,
    chosen_supplier_name TEXT NOT NULL,
    
    -- Context
    decision_source TEXT NOT NULL CHECK(decision_source IN ('manual', 'ai_quick', 'ai_assisted', 'propagated', 'auto_match')),
    confidence_score REAL,
    was_top_suggestion BOOLEAN DEFAULT 0,  -- For analysis
    
    -- Timestamps
    decided_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE,
    FOREIGN KEY (chosen_supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
);

-- Indexes for decisions log
CREATE INDEX IF NOT EXISTS idx_supplier_log_normalized 
ON supplier_decisions_log(normalized_input);

CREATE INDEX IF NOT EXISTS idx_supplier_log_source 
ON supplier_decisions_log(decision_source);

CREATE INDEX IF NOT EXISTS idx_supplier_log_decided_at 
ON supplier_decisions_log(decided_at DESC);

CREATE INDEX IF NOT EXISTS idx_supplier_log_guarantee 
ON supplier_decisions_log(guarantee_id);

CREATE INDEX IF NOT EXISTS idx_supplier_log_supplier 
ON supplier_decisions_log(chosen_supplier_id);

-- Composite index for analysis queries
CREATE INDEX IF NOT EXISTS idx_supplier_log_normalized_supplier 
ON supplier_decisions_log(normalized_input, chosen_supplier_id);

-- ============================================================================
-- LEARNING TRIGGERS
-- ============================================================================

-- Trigger: Update last_used_at when usage_count increases
CREATE TRIGGER IF NOT EXISTS update_learning_last_used
AFTER UPDATE OF usage_count ON supplier_learning_cache
BEGIN
    UPDATE supplier_learning_cache
    SET last_used_at = CURRENT_TIMESTAMP
    WHERE id = NEW.id;
END;

-- ============================================================================
-- Re-enable foreign keys
-- ============================================================================
PRAGMA foreign_keys = ON;

-- ============================================================================
-- Verification
-- ============================================================================

SELECT name FROM sqlite_master 
WHERE type='table' 
AND name IN ('supplier_learning_cache', 'supplier_decisions_log')
ORDER BY name;
