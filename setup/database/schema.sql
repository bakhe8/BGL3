-- ============================================================================
-- Seed Data Import - Temporary Database Schema
-- ============================================================================
-- This database is ISOLATED and will be DELETED after migration
-- Location: setup/database/import.sqlite
-- ============================================================================

PRAGMA foreign_keys = ON;

-- ============================================================================
-- 1. TEMPORARY SUPPLIERS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS temp_suppliers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- Original data from CSV
    supplier_name TEXT NOT NULL,
    supplier_name_en TEXT, -- English name if available
    
    -- Computed (for matching)
    normalized_name TEXT NOT NULL,
    
    -- Statistics
    occurrence_count INTEGER DEFAULT 1,  -- How many times in CSV
    
    -- Review status
    status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'confirmed', 'rejected', 'duplicate')),
    notes TEXT,
    user_edited_name TEXT,  -- If user edits the name
    
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_temp_suppliers_status ON temp_suppliers(status);
CREATE INDEX idx_temp_suppliers_normalized ON temp_suppliers(normalized_name);

-- ============================================================================
-- 2. TEMPORARY BANKS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS temp_banks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- Original data from CSV
    bank_name TEXT NOT NULL,
    
    -- Computed
    normalized_name TEXT NOT NULL,
    
    -- Statistics
    occurrence_count INTEGER DEFAULT 1,
    
    -- Additional info (JSON: department, email, address)
    bank_info TEXT,  -- JSON field
    
    -- Review status
    status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'confirmed', 'rejected', 'duplicate')),
    notes TEXT,
    user_edited_name TEXT,
    
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_temp_banks_status ON temp_banks(status);
CREATE INDEX idx_temp_banks_normalized ON temp_banks(normalized_name);

-- ============================================================================
-- 3. IMPORT METADATA TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS import_metadata (
    id INTEGER PRIMARY KEY CHECK (id = 1),  -- Only one row
    
    csv_filename TEXT,
    total_rows INTEGER DEFAULT 0,
    suppliers_found INTEGER DEFAULT 0,
    banks_found INTEGER DEFAULT 0,
    
    uploaded_at TEXT DEFAULT CURRENT_TIMESTAMP,
    processed BOOLEAN DEFAULT 0
);

-- ============================================================================
-- Verification
-- ============================================================================

SELECT name FROM sqlite_master 
WHERE type='table' 
AND name LIKE 'temp_%'
ORDER BY name;
