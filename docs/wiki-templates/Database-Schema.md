# Database Schema

## 🗄️ Overview

BGL3 uses SQLite as its database with a carefully designed schema that separates immutable data from mutable decisions.

---

## Core Tables

### 1. `guarantees` - Raw Immutable Data

**Purpose:** Store imported guarantee data as immutable records.

```sql
CREATE TABLE guarantees (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_number TEXT NOT NULL UNIQUE,
    raw_data JSON NOT NULL,
    import_source TEXT NOT NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    imported_by TEXT,
    normalized_supplier_name TEXT
)
```

**Key Points:**
- ✅ Immutable after import
- ✅ `raw_data` contains original Excel data as JSON
- ✅ `guarantee_number` is unique across system
- ✅ All modifications go to `guarantee_decisions`, not here

---

### 2. `guarantee_decisions` - Operational State

**Purpose:** Store mutable operational data and user decisions.

```sql
CREATE TABLE guarantee_decisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'pending',
    is_locked BOOLEAN DEFAULT 0,
    supplier_id INTEGER,
    bank_id INTEGER,
    decision_source TEXT DEFAULT 'manual',
    confidence_score REAL,
    active_action TEXT NULL,
    active_action_set_at TEXT NULL,
    decided_at DATETIME,
    last_modified_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE SET NULL
)
```

**Status Values:**
- `pending` - Awaiting supplier/bank selection
- `ready` - All data confirmed, ready for action
- `released` - Finalized and locked

**Key Points:**
- ✅ One decision per guarantee (UNIQUE constraint)
- ✅ `active_action` tracks current operation (extension/reduction/release)
- ✅ `is_locked` prevents further modifications

---

### 3. `guarantee_history` - Audit Trail

**Purpose:** Immutable log of all state changes and actions.

```sql
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
)
```

**Event Types:**
- `import` - Initial import
- `ai_match` - AI matching suggestion
- `decision` - User decision
- `modified` - Data modification (extension/reduction)
- `release` - Final release action
- `status_change` - Status updates

**Key Points:**
- ✅ Append-only (never delete)
- ✅ `snapshot_data` preserves state at event time
- ✅ `letter_snapshot` stores generated letter HTML

---

## Reference Tables

### 4. `suppliers`

```sql
CREATE TABLE suppliers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    official_name TEXT NOT NULL,
    normalized_name TEXT NOT NULL,
    display_name TEXT,
    is_confirmed INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
```

### 5. `banks`

```sql
CREATE TABLE banks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    arabic_name TEXT,
    english_name TEXT,
    normalized_name TEXT,
    short_name TEXT,
    department TEXT,
    address_line1 TEXT,
    contact_email TEXT,
    created_at DATETIME,
    updated_at DATETIME
)
```

---

## Learning System

### 6. `supplier_learning_cache`

**Purpose:** AI matching performance optimization with scoring.

```sql
CREATE TABLE supplier_learning_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    normalized_input TEXT NOT NULL,
    supplier_id INTEGER NOT NULL,
    fuzzy_score REAL DEFAULT 0.0,
    source_weight INTEGER DEFAULT 0,
    usage_count INTEGER DEFAULT 0,
    block_count INTEGER DEFAULT 0,
    total_score REAL GENERATED ALWAYS AS (...) STORED,
    effective_score REAL GENERATED ALWAYS AS (...) STORED,
    star_rating INTEGER GENERATED ALWAYS AS (...) STORED,
    last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    UNIQUE(normalized_input, supplier_id)
)
```

**Key Points:**
- ✅ Uses SQLite generated columns for computed scores
- ✅ `usage_count` increases on user confirmation
- ✅ `block_count` increases on user rejection
- ✅ Star rating (1-5) computed from scores

---

## Data Flow

```
Excel Import → guarantees (immutable)
                    ↓
            guarantee_decisions (mutable)
                    ↓
            guarantee_history (audit)
```

---

## Key Principles

1. **Immutability**: Raw data never changes after import
2. **Auditability**: All actions logged in history table
3. **Referential Integrity**: Foreign keys enforce data consistency
4. **Normalization**: Suppliers and banks are normalized entities
5. **Learning**: AI system learns from user decisions

---

*For detailed column definitions, see `/docs/database-schema.md`*
