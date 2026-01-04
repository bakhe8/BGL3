# BGL3 System - Business Logic Forensics Analysis

**Version**: 1.0  
**Date**: 2025-12-28  
**Purpose**: Complete decision logic map of the BGL System v3.0

---

## Overview

This document exposes the COMPLETE decision logic of the BGL3 system as it actually behaves in production. It identifies all decision points, outcome states, edge cases, and contradictions between intended and actual behavior.

> **Note**: This analysis documents actual behavior, not intended behavior. Bugs and contradictions are first-class logic.

---

## 1. Domain Entities Summary

### 1.1 Core Tables

| Table | Purpose | Key Fields |
|-------|---------|------------|
| `guarantees` | Raw data storage (immutable) | id, guarantee_number, raw_data (JSON), import_source |
| `guarantee_decisions` | Current state & matching | guarantee_id, status, supplier_id, bank_id, is_locked |
| `guarantee_history` | Unified timeline events | event_type, event_subtype, snapshot_data, event_details |
| `supplier_learning_cache` | Learning-based suggestions | normalized_input, supplier_id, usage_count, block_count |
| `suppliers` | Official supplier master data | id, official_name, normalized_name |
| `banks` | Official bank master data | id, arabic_name, short_code, normalized_name |

### 1.2 Status Values

| Status | Meaning | Trigger |
|--------|---------|---------|
| `pending` | Missing supplier or bank | Default on import |
| `approved` / `ready` | Both supplier_id AND bank_id set | StatusEvaluator check |
| `released` | After release action | ActionService.issueRelease |

### 1.3 Lock States

| State | Condition | Effect |
|-------|-----------|--------|
| `is_locked = 0` | Normal | Modifications allowed |
| `is_locked = 1` | After release | Blocks modifications (via DecisionService only) |

---

## 2. Decision Points Inventory

### D-01: Excel Import Validation
- **File**: `ImportService.php:82-114`
- **Required fields**: supplier, bank, guarantee_number, amount, expiry_date, contract_number
- **Behavior**: Skip row if missing required fields

### D-02: Header Detection
- **File**: `ImportService.php:55-68`
- **Scans**: First 5 rows for supplier+bank columns
- **Fallback**: `contract_type` defaults to 'contract'

### D-03: Supplier Matching (UI)
- **File**: `SupplierCandidateService.php:104-280`
- **Sources**: Learning cache → Overrides → Official → Alternative
- **Thresholds**: AUTO=0.90, REVIEW=0.70, WEAK=0.80

### D-04: Bank Matching
- **File**: `BankCandidateService.php:50-139`
- **Logic**: Short code (exact/fuzzy) → Full name (exact/fuzzy)
- **Thresholds**: Short fuzzy ≥0.90, Full fuzzy ≥0.95

### D-05: Status Evaluation
- **File**: `StatusEvaluator.php:27-35`
- **Rule**: `if (supplier_id && bank_id) return 'approved'`
- **Ignores**: Lock status, conflicts, action history

### D-06: Learning Policy
- **File**: `LearningService.php:39-44`
- **Policy**: All user decisions are always learned. No blocks or gates.
- **Protection**: Caps only (`USAGE_BONUS_MAX=75`, `floor=-5`)

### D-07: Auto-Accept
- **File**: `AutoAcceptService.php:27-68`
- **Conditions**: No conflicts, source in [official, override, alternative], score ≥ AUTO_THRESHOLD

### D-08: Supplier/Bank Resolution on Save
- **File**: `save-and-next.php:35-135`
- **Behavior**: ID/Name mismatch → trust name; Not found → auto-create

### D-09: Action Prerequisites
- **File**: `ActionService.php:33-39`
- **Requirement**: Both supplier_id AND bank_id must exist

### D-10: Release Lock
- **File**: `ActionService.php:115-119`
- **Actions**: Set is_locked=1, locked_reason='released'

---

## 3. Key Contradictions Identified

### C-01: Lock Bypass via save-and-next

| Expected | Actual |
|----------|--------|
| DecisionService.save() checks is_locked | save-and-next.php uses direct SQL REPLACE |
| Locked decisions cannot be modified | Locked decisions CAN be modified via API |

**Impact**: Released guarantees can have their decisions overwritten.

### C-02: Extension After Release Possible

| Expected | Actual |
|----------|--------|
| DB trigger blocks extension after release | v4 migration renamed guarantee_actions to backup |
| ActionService checks decision.status | extend.php bypasses ActionService |

**Impact**: Guarantees can be extended after release.

### C-03: Learning Score Cap

| Expected | Actual |
|----------|--------|
| High-confidence learning auto-approves | Score capped at 0.90 (equals AUTO_THRESHOLD) |
| Learning suggestions can trigger auto-accept | Never auto-accepts due to ≥ vs > comparison |

**Impact**: All learning-based matches require manual confirmation.

### C-04: StatusEvaluator Incomplete

| Expected | Actual |
|----------|--------|
| Status reflects complete state | Only checks supplier_id + bank_id presence |
| Lock/release affects status | Ignored by StatusEvaluator |

### C-05: Auto-Creation of Master Data

| Expected | Actual |
|----------|--------|
| Suppliers/banks are admin-managed | Auto-created on save if not found |
| Master data controlled | Any user can create new entities |

---

## 4. Timeline Event Types

| event_type | event_subtype | Trigger |
|------------|---------------|---------|
| `import` | excel, manual, smart_paste | ImportService |
| `modified` | extension, reduction, manual_edit, ai_match | User/System actions |
| `release` | release | ActionService.issueRelease |
| `status_change` | status_change | StatusEvaluator transitions |
| `reimport` | - | Duplicate guarantee_number |

---

## 5. Learning System Flow

```
User saves decision
       ↓
LearningService.learnFromDecision()
       ↓
   Gate 1: Session load < 20? ──NO──→ Skip (silent)
       ↓ YES
   Gate 2: Not circular? ──NO──→ Skip (silent)
       ↓ YES
   Gate 3: No official conflict? ──NO──→ Skip (silent)
       ↓ YES
   Learn alias + Increment usage
       ↓
   Log to supplier_decisions_log
```

---

## 6. Matching Pipeline

### Import-Time (MatchingService)
```
1. Check supplier_learning_cache (effective_score ≥ 180)
2. Check overrides (exact match)
3. Check official by normalized_key
4. Check exact normalized match
5. Check alternative names
6. Fuzzy match (≥ 0.90)
   └→ Result: supplier_id + match_status
```

### UI-Time (SupplierCandidateService)
```
1. Get blocked IDs from cache
2. Get cached suggestions (score forced to 0.90)
3. Match overrides
4. Match official suppliers (fuzzy)
5. Match alternative names (exact + fuzzy)
6. Deduplicate by supplier_id
7. Filter by weak threshold (0.80)
8. Limit to 20 candidates
   └→ Result: Ranked candidate list with stars
```

---

## 7. Action Flow Constraints

### Extension
```
Prerequisite: supplier_id AND bank_id exist
Validation: status ≠ 'released' (via ActionService)
Bypass: extend.php modifies raw_data directly
Result: expiry_date = current + 1 year
```

### Reduction
```
Prerequisite: supplier_id AND bank_id exist
Validation: new_amount < current_amount
Result: amount updated in raw_data
```

### Release
```
Prerequisite: supplier_id AND bank_id exist
Result: is_locked=1, locked_reason='released'
```

---

## 8. Configuration Thresholds

| Setting | Default | Purpose |
|---------|---------|---------|
| `MATCH_AUTO_THRESHOLD` | 0.90 | Auto-accept if score ≥ this |
| `MATCH_REVIEW_THRESHOLD` | 0.70 | Minimum to appear as candidate |
| `MATCH_WEAK_THRESHOLD` | 0.80 | Filter for final list |
| `CONFLICT_DELTA` | - | Min difference between top 2 scores |
| `WEIGHT_OFFICIAL` | 1.0 | Weight for official source |
| `WEIGHT_FUZZY` | - | Weight for fuzzy matches |
| `CANDIDATES_LIMIT` | 20 | Max candidates returned |

---

## 9. Edge Cases

| Case | Behavior |
|------|----------|
| Empty expiry date on extension | Sets to 1 year from NOW (not error) |
| Duplicate guarantee_number | SQLite constraint violation |
| Score between 0.70-0.80 | May or may not appear based on service |
| High block_count | Filtered by both effective_score AND blockedIds |
| Bank short_code collision | UNKNOWN if UNIQUE constraint exists |
| Row with only "0" values | Skipped as empty |

---

## 10. File Reference Map

| Purpose | Primary File |
|---------|-------------|
| Excel Import | `ImportService.php` |
| Supplier Matching | `SupplierCandidateService.php`, `MatchingService.php` |
| Bank Matching | `BankCandidateService.php` |
| Decision Saving | `save-and-next.php`, `DecisionService.php` |
| Status Evaluation | `StatusEvaluator.php` |
| Learning | `LearningService.php` |
| Actions | `ActionService.php`, `extend.php`, `release.php`, `reduce.php` |
| Timeline | `TimelineRecorder.php` |
| Conflict Detection | `ConflictDetector.php` |
| Auto-Accept | `AutoAcceptService.php` |

---

## Appendix: Schema Reference

See migrations:
- `v3_001_create_core_tables.sql` - Core tables and triggers
- `v3_002_create_learning_tables.sql` - Learning cache
- `v4_001_unify_timeline.sql` - Unified timeline migration

---

**Document Generated**: 2025-12-28
