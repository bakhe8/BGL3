# BGL System — Phase 2: Pure Decision Graph Analysis

**Analysis Date**: 2025-12-28  
**Phase**: 2 of Multi-Phase System Forensics  
**Purpose**: Abstract decision machine reconstruction

---

## 1. PURE DOMAIN MODEL

### 1.1 Entity: GUARANTEE

A **Guarantee** is a financial instrument representing a bank's promise to pay on behalf of a supplier. It enters the system through import and progresses through a lifecycle of identification, validation, and eventual conclusion.

**States**:

| State | Business Meaning |
|-------|------------------|
| **Unidentified** | The guarantee exists but its supplier and bank are not yet confirmed. It is raw data awaiting human or machine interpretation. |
| **Partially Identified** | Either the supplier or the bank has been confirmed, but not both. The guarantee cannot proceed to operational actions. |
| **Fully Identified** | Both supplier and bank have been confirmed. The guarantee is now eligible for operational actions. |
| **Released** | The guarantee has been formally released. Its lifecycle is conceptually complete. |

---

### 1.2 Entity: DECISION

A **Decision** is the binding determination of which supplier and which bank a guarantee belongs to. A decision transforms a guarantee from unidentified to identified.

**States**:

| State | Business Meaning |
|-------|------------------|
| **Absent** | No decision has been made. The guarantee has no confirmed supplier or bank. |
| **Proposed** | The system has suggested a match, but it has not been confirmed. |
| **Confirmed** | A human or automated process has confirmed the supplier and bank. |
| **Locked** | The decision is frozen and cannot be modified through normal means. |

---

### 1.3 Entity: ACTION

An **Action** is an operational event that changes a guarantee's business state: extending its validity, reducing its amount, or releasing it entirely.

**States**:

| State | Business Meaning |
|-------|------------------|
| **Pending** | The action has been initiated but not yet executed. |
| **Executed** | The action has been completed and its effects applied. |
| **Voided** | The action was cancelled or superseded. |

**Action Types**:
- **Extension** — Adds time to the guarantee's validity
- **Reduction** — Decreases the guarantee's monetary amount
- **Release** — Terminates the guarantee's active status

---

### 1.4 Entity: LEARNING MEMORY

**Learning Memory** is the system's accumulated knowledge about how raw supplier names map to official suppliers. It remembers past decisions and uses them to suggest future matches.

**States**:

| State | Business Meaning |
|-------|------------------|
| **Unknown** | No prior mapping exists for this raw name. |
| **Learned** | The system has observed this mapping before and remembers it. |
| **Penalized** | The system has observed this mapping being rejected and de-prioritizes it. |
| **Blocked** | The system has observed sufficient rejections to actively exclude this mapping. |

---

### 1.5 Entity: TIMELINE

A **Timeline** is the complete historical record of everything that has happened to a guarantee. Every transition, every decision, every action is recorded.

- The Timeline is an append-only log
- It records the state of OTHER entities at moments of transition

---

### 1.6 Entity: CANDIDATE

A **Candidate** is a potential match between a raw name (from import) and an official entity (supplier or bank). Candidates are ranked and presented for selection.

**States**:

| State | Business Meaning |
|-------|------------------|
| **Suggested** | The candidate appears in the suggestion list. |
| **Selected** | The candidate was chosen and became a decision. |
| **Ignored** | The candidate was shown but not chosen. |
| **Excluded** | The candidate was explicitly blocked from appearing. |

---

## 2. DECISION GRAPH

### NODE D1: Import Acceptance

**Description**: When raw guarantee data arrives, the system decides whether to accept it.

**Branches**:
- All required fields present → Accept and create guarantee
- Missing required fields → Reject and log skip
- Identifier already exists → Reject or flag reimport

---

### NODE D2: Candidate Generation

**Description**: Given a raw name, the system generates ranked potential matches.

**Sources checked** (priority order):
1. Learning Memory
2. Override rules
3. Official name registry
4. Fuzzy matching

---

### NODE D3: Candidate Selection

**Description**: A candidate is selected to become the confirmed decision.

**Key branches**:
- Decision not locked → Accept selection
- Decision locked (strict path) → Reject
- Decision locked (bypass path) → Accept anyway
- Name not found → Auto-create new entity

---

### NODE D4: Learning Policy

**Description**: All user decisions are recorded for learning.

**Policy**:
- No blocks or gates
- All decisions are learned
- Protection via caps only (`USAGE_BONUS_MAX`, `floor`)

Learning always happens. Score inflation prevented by caps.

---

### NODE D5: Negative Learning

**Description**: Ignored high-confidence candidates are penalized.

Penalty reduces future appearance probability for that mapping.

---

### NODE D6: Status Evaluation

**Description**: Evaluates if guarantee is complete.

**Logic**: Both supplier AND bank confirmed → Approved; Otherwise → Pending

**NOT checked**: Lock state, conflicts, action history

---

### NODE D7: Action Prerequisite Check

**Description**: Verifies requirements before allowing actions.

**Requirement**: Both supplier and bank must be confirmed.

---

### NODE D8: Extension Execution

**Description**: Extends guarantee validity.

**Paths**:
- Strict: Checks release status, blocks if released
- Bypass: Modifies data directly without release check

---

### NODE D9: Reduction Execution

**Description**: Reduces guarantee amount.

Validates new amount < current amount.

---

### NODE D10: Release Execution

**Description**: Formally releases guarantee.

**Effects**: Decision locked, status becomes Released.

---

### NODE D11: Lock Enforcement (Split Reality)

**Description**: Lock check may or may not occur.

**Path A**: Lock checked → Modification rejected
**Path B**: Lock not checked → Modification succeeds

Both paths active in production.

---

### NODE D12: Auto-Creation

**Description**: Creates new official entity when name unresolved.

No approval workflow. Immediate creation.

---

### NODE D13: Conflict Detection

**Description**: Detects ambiguous matching situations.

**Conflicts are informational only** — they do not block selection.

---

## 3. CROSS-ENTITY INTERACTIONS

| From | To | Effect |
|------|-----|--------|
| Decision → Learning | Recording mapping in memory |
| Learning → Candidates | Boosting learned suggestions |
| Ignored Candidate → Learning | Penalizing rejected mappings |
| Release Action → Decision | Applying lock |
| Lock → Decision (Path A) | Blocking modification |
| Lock → Decision (Path B) | Allowing modification despite lock |
| Status Eval → Actions | Determining eligibility |
| Actions → Timeline | Recording history |

---

## 4. STRESS TESTS

### 4.A Authority Removal

| Removed Authority | Consequence |
|-------------------|-------------|
| Save Decision | Guarantees never progress; Learning frozen |
| Action Execution | No extensions/releases; Lock never triggers |
| Automatic Matching | All decisions require manual entry |
| Learning Memory | No historical patterns; Each guarantee independent |

### 4.B Boundary Violation

When "Released" or "Locked" is ignored:
- Released guarantees can be extended
- Locked decisions can be overwritten
- Timeline shows inconsistent history

### 4.C Duplicate Reality

Same raw name with divergent decisions creates:
- Version A: Maps to Supplier X (reinforced)
- Version B: Maps to Supplier Y (after rejection)
- Future: Suggestions diverge based on cumulative behavior

---

## 5. INVARIANTS

### Actually Enforced
- Approved = Both IDs present
- Timeline is append-only
- Import requires minimum fields
- Reduction requires valid amount

### NOT Enforced
- Locked = Immutable (UX enforced only)
- Released = Terminal (UX enforced only)
- Official entities are admin-managed (auto-creation exists)

---

## 6. LOGIC CONTRADICTIONS

| # | Contradiction | Status |
|---|---------------|--------|
| C1 | Lock is path-dependent | **Resolved** — UX Lock unified |
| C2 | Release is modifiable | **Resolved** — UX Lock prevents |
| C3 | Learning cannot auto-accept | **By Design** — score cap is intentional |
| C4 | Status ignores lock | **By Design** — filtering handles this |
| C5 | Conflicts non-blocking | **By Design** — advisory only |
| C6 | Auto-creation uncontrolled | **By Design** — accepted behavior |
| C7 | Learning gates silent | **Resolved** — Gates removed |
| C8 | Timeline unused | **By Design** — documentary only |
| C9 | Lock path-dependent | **Resolved** — UX Lock unified |
| C10 | Prerequisite asymmetry | **By Design** — Action Authority validates |

---

**END OF PHASE 2 — DECISION GRAPH COMPLETE**
