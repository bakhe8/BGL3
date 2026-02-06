# DB Schema (auto)

Source: storage\database\app.sqlite

## guarantees
`sql
CREATE TABLE guarantees (
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
    imported_by TEXT, normalized_supplier_name TEXT, test_batch_id TEXT NULL, test_note TEXT NULL, is_test_data INTEGER DEFAULT 0 NOT NULL,  -- Future: username
    
    -- Constraints
    UNIQUE(guarantee_number)
)
`
Columns:
- id (INTEGER) NULL PK
- guarantee_number (TEXT) NOT NULL
- raw_data (JSON) NOT NULL
- import_source (TEXT) NOT NULL
- imported_at (DATETIME) NOT NULL
- imported_by (TEXT) NULL
- normalized_supplier_name (TEXT) NULL
- test_batch_id (TEXT) NULL
- test_note (TEXT) NULL
- is_test_data (INTEGER) NOT NULL
Indexes:
- idx_guarantees_is_test_data: is_test_data
- idx_guarantees_test_batch: test_batch_id
- idx_guarantees_normalized_supplier: normalized_supplier_name
- idx_guarantees_related_to: 
- idx_guarantees_type: 
- idx_guarantees_expiry: 
- idx_guarantees_date_source: , import_source
- idx_guarantees_imported_at: imported_at
- idx_guarantees_import_source: import_source
- idx_guarantees_number: guarantee_number
- sqlite_autoindex_guarantees_1 UNIQUE: guarantee_number

## supplier_learning_cache
`sql
CREATE TABLE supplier_learning_cache (
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
    
    FOREIGN KEY (supplier_id) REFERENCES "suppliers_old"(id) ON DELETE CASCADE,
    UNIQUE(normalized_input, supplier_id)
)
`
Columns:
- id (INTEGER) NULL PK
- normalized_input (TEXT) NOT NULL
- supplier_id (INTEGER) NOT NULL
- fuzzy_score (REAL) NULL
- source_weight (INTEGER) NULL
- usage_count (INTEGER) NULL
- block_count (INTEGER) NULL
- last_used_at (DATETIME) NULL
Foreign Keys:
- supplier_id -> suppliers_old.id (on_delete=CASCADE)
Indexes:
- idx_learning_last_used: last_used_at
- idx_learning_supplier: supplier_id
- idx_learning_normalized_score: normalized_input, effective_score
- sqlite_autoindex_supplier_learning_cache_1 UNIQUE: normalized_input, supplier_id

## supplier_decisions_log
`sql
CREATE TABLE supplier_decisions_log (
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
    FOREIGN KEY (chosen_supplier_id) REFERENCES "suppliers_old"(id) ON DELETE CASCADE
)
`
Columns:
- id (INTEGER) NULL PK
- guarantee_id (INTEGER) NOT NULL
- raw_input (TEXT) NOT NULL
- normalized_input (TEXT) NOT NULL
- chosen_supplier_id (INTEGER) NOT NULL
- chosen_supplier_name (TEXT) NOT NULL
- decision_source (TEXT) NOT NULL
- confidence_score (REAL) NULL
- was_top_suggestion (BOOLEAN) NULL
- decided_at (DATETIME) NULL
Foreign Keys:
- chosen_supplier_id -> suppliers_old.id (on_delete=CASCADE)
- guarantee_id -> guarantees.id (on_delete=CASCADE)
Indexes:
- idx_supplier_log_normalized_supplier: normalized_input, chosen_supplier_id
- idx_supplier_log_supplier: chosen_supplier_id
- idx_supplier_log_guarantee: guarantee_id
- idx_supplier_log_decided_at: decided_at
- idx_supplier_log_source: decision_source
- idx_supplier_log_normalized: normalized_input

## supplier_alternative_names
`sql
CREATE TABLE supplier_alternative_names (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier_id INTEGER NOT NULL,
    alternative_name TEXT NOT NULL,
    normalized_name TEXT,
    source TEXT DEFAULT 'manual',
    usage_count INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES "suppliers_old"(id) ON DELETE CASCADE
)
`
Columns:
- id (INTEGER) NULL PK
- supplier_id (INTEGER) NOT NULL
- alternative_name (TEXT) NOT NULL
- normalized_name (TEXT) NULL
- source (TEXT) NULL
- usage_count (INTEGER) NULL
- created_at (TIMESTAMP) NULL
Foreign Keys:
- supplier_id -> suppliers_old.id (on_delete=CASCADE)
Indexes:
- idx_supplier_norm_name: normalized_name
- idx_alt_names_norm: normalized_name

## guarantee_attachments
`sql
CREATE TABLE guarantee_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL,
    file_name TEXT NOT NULL,
    file_path TEXT NOT NULL,
    file_size INTEGER,
    file_type TEXT,
    uploaded_by TEXT DEFAULT 'system',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE
)
`
Columns:
- id (INTEGER) NULL PK
- guarantee_id (INTEGER) NOT NULL
- file_name (TEXT) NOT NULL
- file_path (TEXT) NOT NULL
- file_size (INTEGER) NULL
- file_type (TEXT) NULL
- uploaded_by (TEXT) NULL
- created_at (TIMESTAMP) NULL
Foreign Keys:
- guarantee_id -> guarantees.id (on_delete=CASCADE)
Indexes:
- idx_attachments_guarantee: guarantee_id

## guarantee_notes
`sql
CREATE TABLE guarantee_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    created_by TEXT DEFAULT 'system',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE
)
`
Columns:
- id (INTEGER) NULL PK
- guarantee_id (INTEGER) NOT NULL
- content (TEXT) NOT NULL
- created_by (TEXT) NULL
- created_at (TIMESTAMP) NULL
- updated_at (TIMESTAMP) NULL
Foreign Keys:
- guarantee_id -> guarantees.id (on_delete=CASCADE)
Indexes:
- idx_notes_guarantee: guarantee_id

## guarantee_history
`sql
CREATE TABLE "guarantee_history" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    event_subtype TEXT,
    snapshot_data TEXT,
    event_details TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by TEXT, letter_snapshot TEXT NULL,
    
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE
)
`
Columns:
- id (INTEGER) NULL PK
- guarantee_id (INTEGER) NOT NULL
- event_type (TEXT) NOT NULL
- event_subtype (TEXT) NULL
- snapshot_data (TEXT) NULL
- event_details (TEXT) NULL
- created_at (DATETIME) NULL
- created_by (TEXT) NULL
- letter_snapshot (TEXT) NULL
Foreign Keys:
- guarantee_id -> guarantees.id (on_delete=CASCADE)
Indexes:
- idx_history_created_at: created_at
- idx_history_subtype: event_subtype
- idx_history_guarantee: guarantee_id

## bank_alternative_names
`sql
CREATE TABLE bank_alternative_names (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bank_id INTEGER NOT NULL,
            alternative_name TEXT NOT NULL,
            normalized_name TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE CASCADE
        )
`
Columns:
- id (INTEGER) NULL PK
- bank_id (INTEGER) NOT NULL
- alternative_name (TEXT) NOT NULL
- normalized_name (TEXT) NOT NULL
- created_at (DATETIME) NULL
Foreign Keys:
- bank_id -> banks.id (on_delete=CASCADE)
Indexes:
- idx_bank_alt_bank_id: bank_id
- idx_bank_alt_normalized: normalized_name

## learning_confirmations
`sql
CREATE TABLE learning_confirmations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    raw_supplier_name TEXT NOT NULL,
    supplier_id INTEGER NOT NULL,
    confidence INTEGER,
    matched_anchor TEXT,
    anchor_type TEXT,
    action TEXT CHECK(action IN ('confirm', 'reject')),
    decision_time_seconds REAL,
    guarantee_id INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, count INTEGER DEFAULT 1, updated_at DATETIME, normalized_supplier_name TEXT,
    FOREIGN KEY (supplier_id) REFERENCES "suppliers_old"(id)
)
`
Columns:
- id (INTEGER) NULL PK
- raw_supplier_name (TEXT) NOT NULL
- supplier_id (INTEGER) NOT NULL
- confidence (INTEGER) NULL
- matched_anchor (TEXT) NULL
- anchor_type (TEXT) NULL
- action (TEXT) NULL
- decision_time_seconds (REAL) NULL
- guarantee_id (INTEGER) NULL
- created_at (TIMESTAMP) NULL
- count (INTEGER) NULL
- updated_at (DATETIME) NULL
- normalized_supplier_name (TEXT) NULL
Foreign Keys:
- supplier_id -> suppliers_old.id (on_delete=NO ACTION)
Indexes:
- idx_learning_confirmations_normalized: normalized_supplier_name, action
- idx_learning_confirmations_raw_supplier: raw_supplier_name
- idx_learning_action: action
- idx_learning_raw_name: raw_supplier_name

## banks
`sql
CREATE TABLE "banks"(
  id,
  arabic_name TEXT,
  english_name TEXT,
  short_name TEXT,
  created_at NUM,
  updated_at NUM,
  department TEXT,
  address_line1 TEXT,
  contact_email TEXT
, normalized_name TEXT)
`
Columns:
- id () NULL
- arabic_name (TEXT) NULL
- english_name (TEXT) NULL
- short_name (TEXT) NULL
- created_at (NUM) NULL
- updated_at (NUM) NULL
- department (TEXT) NULL
- address_line1 (TEXT) NULL
- contact_email (TEXT) NULL
- normalized_name (TEXT) NULL
Indexes:
- idx_banks_normalized_name: normalized_name
- idx_banks_short_name: short_name
- idx_banks_arabic_name: arabic_name

## suppliers
`sql
CREATE TABLE suppliers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        official_name TEXT NOT NULL,
        display_name TEXT,
        normalized_name TEXT NOT NULL,
        supplier_normalized_key TEXT,
        is_confirmed INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        english_name TEXT
    )
`
Columns:
- id (INTEGER) NULL PK
- official_name (TEXT) NOT NULL
- display_name (TEXT) NULL
- normalized_name (TEXT) NOT NULL
- supplier_normalized_key (TEXT) NULL
- is_confirmed (INTEGER) NULL
- created_at (DATETIME) NULL
- english_name (TEXT) NULL

## batch_metadata
`sql
CREATE TABLE batch_metadata (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            import_source TEXT NOT NULL UNIQUE,
            
            -- User fields only
            batch_name TEXT,
            batch_notes TEXT,
            status TEXT DEFAULT 'active' CHECK(status IN ('active', 'completed')),
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
`
Columns:
- id (INTEGER) NULL PK
- import_source (TEXT) NOT NULL
- batch_name (TEXT) NULL
- batch_notes (TEXT) NULL
- status (TEXT) NULL
- created_at (DATETIME) NULL
Indexes:
- idx_batch_metadata_status: status
- idx_batch_metadata_source: import_source
- sqlite_autoindex_batch_metadata_1 UNIQUE: import_source

## guarantee_decisions
`sql
CREATE TABLE "guarantee_decisions" (
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
)
`
Columns:
- id (INTEGER) NULL PK
- guarantee_id (INTEGER) NOT NULL
- status (TEXT) NOT NULL
- is_locked (BOOLEAN) NULL
- locked_reason (TEXT) NULL
- supplier_id (INTEGER) NULL
- bank_id (INTEGER) NULL
- decision_source (TEXT) NULL
- confidence_score (REAL) NULL
- decided_at (DATETIME) NULL
- decided_by (TEXT) NULL
- last_modified_at (DATETIME) NULL
- last_modified_by (TEXT) NULL
- manual_override (BOOLEAN) NULL
- active_action (TEXT) NULL
- active_action_set_at (TEXT) NULL
- created_at (DATETIME) NULL
- updated_at (DATETIME) NULL
Foreign Keys:
- bank_id -> banks.id (on_delete=SET NULL)
- supplier_id -> suppliers.id (on_delete=SET NULL)
- guarantee_id -> guarantees.id (on_delete=CASCADE)
Indexes:
- idx_guarantee_decisions_status: status
- idx_guarantee_decisions_guarantee_id: guarantee_id
- sqlite_autoindex_guarantee_decisions_1 UNIQUE: guarantee_id

## guarantee_occurrences
`sql
CREATE TABLE guarantee_occurrences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            guarantee_id INTEGER NOT NULL,
            batch_identifier VARCHAR(255) NOT NULL,
            batch_type VARCHAR(50) NOT NULL,
            occurred_at DATETIME NOT NULL,
            raw_hash CHAR(64),
            FOREIGN KEY (guarantee_id) REFERENCES guarantees(id)
        )
`
Columns:
- id (INTEGER) NULL PK
- guarantee_id (INTEGER) NOT NULL
- batch_identifier (VARCHAR(255)) NOT NULL
- batch_type (VARCHAR(50)) NOT NULL
- occurred_at (DATETIME) NOT NULL
- raw_hash (CHAR(64)) NULL
Foreign Keys:
- guarantee_id -> guarantees.id (on_delete=NO ACTION)
Indexes:
- idx_occurrences_guarantee: guarantee_id
- idx_occurrences_batch: batch_identifier

## batch_actions
`sql
CREATE TABLE batch_actions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_identifier TEXT NOT NULL,
        guarantee_id INTEGER NOT NULL,
        action_type TEXT NOT NULL,
        action_payload TEXT,
        action_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        action_by TEXT,
        UNIQUE(batch_identifier, guarantee_id),
        FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE
    )
`
Columns:
- id (INTEGER) NULL PK
- batch_identifier (TEXT) NOT NULL
- guarantee_id (INTEGER) NOT NULL
- action_type (TEXT) NOT NULL
- action_payload (TEXT) NULL
- action_at (DATETIME) NULL
- action_by (TEXT) NULL
Foreign Keys:
- guarantee_id -> guarantees.id (on_delete=CASCADE)
Indexes:
- idx_batch_actions_guarantee: guarantee_id
- idx_batch_actions_batch: batch_identifier
- sqlite_autoindex_batch_actions_1 UNIQUE: batch_identifier, guarantee_id

---

## ملاحظة
هذه الوثيقة تخص قاعدة بيانات التطبيق الأساسية (`storage/database/app.sqlite`).  
مخطط قاعدة بيانات الوكيل (Agent Brain) موثّق في: `docs/knowledge_db_schema.md`.
