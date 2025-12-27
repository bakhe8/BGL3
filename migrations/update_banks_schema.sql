-- Migration: Update Banks Schema
-- Removes swift_code and adds new structure

-- Step 1: Create new banks table with correct structure
CREATE TABLE banks_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    arabic_name TEXT NOT NULL,
    english_name TEXT NOT NULL,
    short_name TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Step 2: Copy existing data if any (with fallbacks)
INSERT INTO banks_new (id, arabic_name, english_name, short_name, created_at, updated_at)
SELECT 
    id,
    name as arabic_name,
    name as english_name,  -- fallback
    UPPER(SUBSTR(name, 1, 3)) as short_name,  -- fallback
    created_at,
    updated_at
FROM banks;

-- Step 3: Drop old table
DROP TABLE banks;

-- Step 4: Rename new table
ALTER TABLE banks_new RENAME TO banks;

-- Step 5: Create index for performance
CREATE INDEX idx_banks_arabic_name ON banks(arabic_name);
CREATE INDEX idx_banks_short_name ON banks(short_name);

-- Step 6: bank_alternative_names table already exists with correct structure
-- Just ensure index exists
CREATE INDEX IF NOT EXISTS idx_bank_alt_names_bank_id ON bank_alternative_names(bank_id);
CREATE INDEX IF NOT EXISTS idx_bank_alt_names_name ON bank_alternative_names(alternative_name);
