# Database Schema Reference

This document represents the **Physical Data Model** verified from `scripts/db_schema_report.json` and migration scripts.

## Tables

### 1. `guarantees`
*   **Role**: Immutable Raw Data Container.
*   **DDL**:
    ```sql
    CREATE TABLE guarantees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        guarantee_number TEXT NOT NULL,
        raw_data JSON NOT NULL, -- Source of Truth
        import_source TEXT NOT NULL,
        imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        imported_by TEXT,
        normalized_supplier_name TEXT,
        UNIQUE(guarantee_number)
    )
    ```

### 2. `guarantee_decisions`
*   **Role**: Operational State (Mutable).
*   **DDL**:
    ```sql
    CREATE TABLE guarantee_decisions (
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
        extra_columns JSON, -- Note: Schema report doesn't show this but code might usage it? No, code uses explicit columns.
        active_action TEXT NULL, -- Added in Phase 3
        active_action_set_at TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
        FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE SET NULL,
        UNIQUE(guarantee_id)
    )
    ```

### 3. `guarantee_history`
*   **Role**: Audit Trail.
*   **DDL**:
    ```sql
    CREATE TABLE guarantee_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        guarantee_id INTEGER NOT NULL,
        event_type TEXT NOT NULL,
        event_subtype TEXT,
        snapshot_data TEXT, -- JSON
        event_details TEXT, -- JSON
        letter_snapshot TEXT NULL, -- HTML Content
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by TEXT,
        FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE
    )
    ```

### 4. `suppliers`
*   **Role**: Normalized Registry.
*   **DDL**:
    ```sql
    CREATE TABLE suppliers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        official_name TEXT NOT NULL,
        display_name TEXT,
        normalized_name TEXT NOT NULL,
        supplier_normalized_key TEXT,
        is_confirmed INTEGER DEFAULT 0,
        english_name TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
    ```

### 5. `banks`
*   **Role**: Normalized Registry.
*   **DDL**:
    ```sql
    CREATE TABLE banks (
        id, -- Integer PK implied
        arabic_name TEXT,
        english_name TEXT,
        short_name TEXT,
        department TEXT,
        address_line1 TEXT,
        contact_email TEXT,
        created_at NUM,
        updated_at NUM
    )
    ```

### 6. `supplier_learning_cache`
*   **Role**: Performance & Intelligence.
*   **DDL**:
    ```sql
    CREATE TABLE supplier_learning_cache (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        normalized_input TEXT NOT NULL,
        supplier_id INTEGER NOT NULL,
        fuzzy_score REAL DEFAULT 0.0,
        source_weight INTEGER DEFAULT 0,
        usage_count INTEGER DEFAULT 0,
        block_count INTEGER DEFAULT 0,
        
        -- GENERATED COLUMNS (Computed by SQLite)
        total_score REAL GENERATED ALWAYS AS ( ...internal formula... ) STORED,
        effective_score REAL GENERATED ALWAYS AS ( ...internal formula... ) STORED,
        star_rating INTEGER GENERATED ALWAYS AS ( ...internal formula... ) STORED,
        
        last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
        UNIQUE(normalized_input, supplier_id)
    )
    ```

## Evidence Index
*   **Tables & DDL**: Extracted from `scripts/db_schema_report.json`.
*   **Generated Columns**: `supplier_learning_cache` in `scripts/db_schema_report.json`.
*   **Initial Schema**: `database/migrations/001_initial_schema.sql` (Inferred source).
