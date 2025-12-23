-- V3: Add Suppliers and Banks Tables
-- These tables were missing from V3 but exist in original system

-- Suppliers Table
CREATE TABLE IF NOT EXISTS suppliers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    official_name TEXT NOT NULL,
    display_name TEXT,
    normalized_name TEXT NOT NULL UNIQUE,
    supplier_normalized_key TEXT UNIQUE,
    is_confirmed INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_suppliers_normalized ON suppliers(normalized_name);
CREATE INDEX IF NOT EXISTS idx_suppliers_key ON suppliers(supplier_normalized_key);

-- Banks Table
CREATE TABLE IF NOT EXISTS banks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    official_name TEXT NOT NULL,
    official_name_en TEXT,
    short_code TEXT NOT NULL UNIQUE,
    normalized_name TEXT NOT NULL UNIQUE,
    is_confirmed INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_banks_normalized ON banks(normalized_name);
CREATE INDEX IF NOT EXISTS idx_banks_code ON banks(short_code);
