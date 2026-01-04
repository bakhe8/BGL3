# BGL System — Phase 3: Logic Authority Model

**Date**: 2025-12-28  
**Phase**: 3 of Multi-Phase System Forensics  
**Purpose**: Establish clear authorities and boundaries to eliminate contradictions

---

## 1. AUTHORITY DECLARATION TABLE

| Business Concept | Proposed Single Authority | Scope | Cannot Override |
|------------------|---------------------------|-------|-----------------|
| **Decision (Supplier/Bank)** | Decision Authority | All assignments and modifications | Lock Authority |
| **Status** | Status Authority | Status determination and transitions | Lock Authority context |
| **Action Execution** | Action Authority | All lifecycle actions | Lock Authority; Terminal States |
| **Locking** | Lock Authority | Immutability enforcement | Nothing — supreme |
| **Learning Memory** | Learning Authority | All learning recording and queries | User selection (advisory) |
| **Timeline** | Timeline Authority | Event recording only | Cannot influence decisions |
| **Entity Creation** | Registry Authority | All entity creation/validation | Uniqueness constraints |

---

## 2. TERMINALITY DEFINITION

| State | Terminal? | Enforced By | Forbidden After |
|-------|-----------|-------------|-----------------|
| **Approved** | NO | Status Authority | Nothing — operational state |
| **Released** | YES | Lock Authority | Extensions, reductions, modifications |
| **Locked** | YES | Lock Authority | All decision modifications |
| **Reduced** | NO | Action Authority | Nothing specific |
| **Extended** | NO | Action Authority | Nothing specific |

---

## 3. STATUS REDEFINITION

| Status | Meaning | Guarantees | Does NOT Guarantee |
|--------|---------|------------|-------------------|
| **Pending** | Missing identification | Supplier OR Bank not confirmed | Nothing about lock/actions |
| **Approved** | Fully identified | Both supplier AND bank confirmed | Not modifiable if locked |
| **Released** | Lifecycle complete | Lock active; no further actions | N/A — terminal |

---

## 4. LOCK POLICY

### Decision: **LOCK IS ABSOLUTE**

- Lock is supreme authority
- No modification possible through any path
- All authorities must respect Lock

**What Becomes Impossible**:
- Modifying supplier/bank after release
- Extending released guarantees
- Overwriting locked decisions

**What Remains Allowed**:
- Timeline recording
- New guarantee creation
- Notes/attachments

---

## 5. LEARNING AUTHORITY

### Decision: **ALWAYS LEARN — CAPS ONLY**

| Aspect | Policy |
|--------|--------|
| Can learning be blocked? | **NO** — never |
| Can trigger automatic decisions? | **NO** — suggestions only |
| Who can override? | User selection |
| Score cap | **0.90** (90%) — prevents auto-accept |
| Protection mechanism | **Caps only**: `USAGE_BONUS_MAX=75`, `floor=-5` |

### Removed Gates (Previously Existed)

| Gate | Reason for Removal |
|------|-------------------|
| Session Fatigue | System doesn't judge user intent or mental state |
| Circular Learning | Caps already prevent score inflation |
| Official Name Conflict | Learning adds suggestions, doesn't override official names |

**Philosophy**: *"Always learn, but with limits"* not *"Block learning"*

---

## 6. TIMELINE ROLE

### Decision: **PURELY DOCUMENTARY**

- Records everything, decides nothing
- Never consulted for business decisions
- Never influences status, lock, or actions

---

## 7. BYPASS PATH POLICY

| Bypass | New Status |
|--------|------------|
| Decision modification while locked | **FORBIDDEN** |
| Extension after release | **FORBIDDEN** |
| Auto-creation without approval | **OFFICIAL** (with conditions) |
| Database trigger circumvention | **FORBIDDEN** |
| Silent learning skip | **FORBIDDEN** |
| Conflicts as non-blocking | **OFFICIAL** (advisory) |

---

## 8. AUTHORITY HIERARCHY

```
LOCK AUTHORITY (Supreme)
        │
        ▼
DECISION AUTHORITY
        │
        ▼
ACTION AUTHORITY
        │
        ▼
STATUS AUTHORITY

LEARNING AUTHORITY (Advisory, parallel)
REGISTRY AUTHORITY (Entity control)
TIMELINE AUTHORITY (Documentary only)
```

---

## CONTRADICTION RESOLUTION

| Contradiction | Resolution |
|---------------|------------|
| C1: Lock path-dependent | Absolute, path-independent |
| C2: Release porous | Triggers Lock; terminal |
| C3: Learning cannot auto-accept | Official safety policy |
| C4: Status ignores context | Must reflect lock state |
| C5: Conflicts non-blocking | Official — advisory by design |
| C6: Auto-creation uncontrolled | Official with notification |
| C7: Silent learning skip | Forbidden — must notify |
| C8: Timeline unused | Official — documentary only |
| C9: Dual-path lock | Forbidden — single absolute path |
| C10: Prerequisite asymmetry | Action Authority validates all |

---

**END OF PHASE 3 — AUTHORITY MODEL FINALIZED**
