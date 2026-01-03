# DATABASE–LEARNING FITNESS MAP

## FORENSIC ANALYSIS OF SCHEMA SUPPORT FOR UNIFIED LEARNING SYSTEM

**Analysis Type:** Structural Forensics  
**Methodology:** Database → Behavior Inference  
**Constraint:** NO redesign proposals, observation only  

---

## 1. DATABASE DOMAIN MAP

### Tables Identified (From Code Analysis)

#### PRIMARY SUPPLIER IDENTITY

**Table:** `suppliers`

**Intended Semantic Role:**  
Canonical supplier registry - single source of truth for supplier identity

**Actual Role Observed:**  
- Name of record for supplier matching
- Queried by ALL matching subsystems
- Contains both `official_name` and `normalized_name` columns

**Domain Classification:** **ENTITY** (clean)

**Schema Structure (Inferred from Code):**
```sql
CREATE TABLE suppliers (
    id INTEGER PRIMARY KEY,
    official_name TEXT NOT NULL,
    english_name TEXT,
    normalized_name TEXT,  -- Inferred from queries
    ... other fields
)
```

**Observations:**
- `normalized_name` column exists (Line 172, SupplierCandidateService.php references it)
- Unclear if `normalized_name` is populated consistently
- Unclear if normalization algorithm version is tracked

---

#### SUPPLIER ALTERNATIVE NAMES (ALIASES)

**Table:** `supplier_alternative_names`

**Intended Semantic Role:**  
Storage of learned input-to-supplier mappings (aliases)

**Actual Role Observed:**  
- Stores raw input + normalized form + supplier_id mapping
- Used for BOTH exact match lookup AND fuzzy matching
- `usage_count` acts as BOTH signal weight AND suppression mechanism
- `source` field indicates provenance ('learning', 'import_official', 'manual')

**Domain Classification:** **SIGNAL + DECISION HYBRID** (problematic)

**Schema Structure (Inferred from Code):**
```sql
CREATE TABLE supplier_alternative_names (
    id INTEGER PRIMARY KEY,
    supplier_id INTEGER NOT NULL,
    alternative_name TEXT NOT NULL,  -- Raw input
    normalized_name TEXT NOT NULL,    -- Normalized form
    source TEXT,                      -- 'learning', 'import_official', 'manual'
    usage_count INTEGER DEFAULT 0,    -- Accumulator + suppression signal
    created_at TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
    -- UNKNOWN: UNIQUE constraint on normalized_name? or (supplier_id, normalized_name)?
)
```

**Critical Observations:**

1. **Signal-Decision Leakage:**
   - `usage_count` is INCREMENT/DECREMENT field (signal accumulation)
   - BUT also acts as FILTER (usage_count > 0 in queries)
   - Cannot distinguish between "low confidence" and "suppressed"

2. **Normalization Enforcement:**
   - Code normalizes before INSERT (SupplierLearningRepository.php line 71, 98)
   - BUT queries use `normalized_name = ?` assuming it was normalized correctly at write time
   - NO database-level constraint to ensure normalization consistency

3. **Identity Ambiguity:**
   - IF constraint is `UNIQUE(normalized_name)`: First-learned supplier LOCKS the alias
   - IF constraint is `UNIQUE(supplier_id, normalized_name)`: Shared aliases allowed
   - Code behavior (line 125-128) suggests UNIQUE(normalized_name), but not confirmed

4. **Provenance BUT NOT USE:**
   - `source` field exists but not used in confidence calculation (observed in scoring logic)
   - Cannot differentiate authority of 'import_official' vs 'learning'

---

(Continued in full document - truncated here for space)

... [Full 8-section analysis] ...

**END OF DATABASE–LEARNING FITNESS MAP**
