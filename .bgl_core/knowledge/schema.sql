-- ACTIVE PROJECT SCHEMA (Synchronized from docs/db_schema.md)
-- Database Type: SQLite

-- 1. Core Guarantees
CREATE TABLE guarantees (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_number TEXT NOT NULL UNIQUE,
    raw_data JSON NOT NULL,
    import_source TEXT NOT NULL,  -- 'excel', 'smart_paste', 'manual'
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    imported_by TEXT,
    normalized_supplier_name TEXT,
    test_batch_id TEXT NULL,
    test_note TEXT NULL,
    is_test_data INTEGER DEFAULT 0 NOT NULL
);

-- 2. Decisions & Status
CREATE TABLE guarantee_decisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'pending', -- 'ready', 'pending', 'released'
    is_locked BOOLEAN DEFAULT 0,
    locked_reason TEXT,
    supplier_id INTEGER,
    bank_id INTEGER,
    decision_source TEXT DEFAULT 'manual', -- 'manual', 'ai_match', 'auto_match'
    confidence_score REAL,
    decided_at DATETIME,
    decided_by TEXT,
    active_action TEXT NULL, -- 'extend', 'reduce', 'release'
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE SET NULL
);

-- 3. Historical Tracking
CREATE TABLE guarantee_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    event_subtype TEXT,
    snapshot_data TEXT,
    event_details TEXT,
    letter_snapshot TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by TEXT,
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE
);

CREATE TABLE guarantee_occurrences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL,
    batch_identifier VARCHAR(255) NOT NULL,
    batch_type VARCHAR(50) NOT NULL,
    occurred_at DATETIME NOT NULL,
    raw_hash CHAR(64),
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id)
);

-- 4. Entities (Banks & Suppliers)
CREATE TABLE banks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    arabic_name TEXT,
    english_name TEXT,
    short_name TEXT,
    normalized_name TEXT,
    contact_email TEXT
);

CREATE TABLE suppliers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    official_name TEXT NOT NULL,
    display_name TEXT,
    normalized_name TEXT NOT NULL,
    is_confirmed INTEGER DEFAULT 0
);

-- 5. Metadata & Batches
CREATE TABLE batch_metadata (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    import_source TEXT NOT NULL UNIQUE,
    batch_name TEXT,
    batch_notes TEXT,
    status TEXT DEFAULT 'active' CHECK(status IN ('active', 'completed')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 6. Learning & AI Signals
CREATE TABLE supplier_learning_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    normalized_input TEXT NOT NULL,
    supplier_id INTEGER NOT NULL,
    fuzzy_score REAL DEFAULT 0.0,
    usage_count INTEGER DEFAULT 0,
    block_count INTEGER DEFAULT 0,
    effective_score REAL, -- Computed
    star_rating INTEGER, -- 1, 2, or 3
    last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(normalized_input, supplier_id)
);

CREATE TABLE learning_confirmations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    raw_supplier_name TEXT NOT NULL,
    supplier_id INTEGER NOT NULL,
    action TEXT CHECK(action IN ('confirm', 'reject')),
    count INTEGER DEFAULT 1,
    normalized_supplier_name TEXT
);
