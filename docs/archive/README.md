# BGL System Documentation

**Bank Guarantee Lifecycle Management System**  
**Version:** 3.0 (MVP)  
**Last Updated:** 2025-12-31

---

## Documentation Suite

This directory contains comprehensive documentation following professional software architecture practices.

### Read in Order

📄 **[01-as-is-current-system.md](01-as-is-current-system.md)**  
*Current System Behavior (As-Is)*

- What the system does NOW
- Based on actual code, not assumptions
- Sources of truth for each component
- Where implicit coupling exists
- **Read this first** to understand what we have

---

📄 **[02-conceptual-model.md](02-conceptual-model.md)**  
*Guarantee Conceptual Model (To-Be)*

- Correct mental model (agreed, not yet implemented)
- Status = Data Confidence + Legal Gate
- Active Action = Current Official Procedure
- Timeline = Audit Trail only
- **Read this second** to understand the ideal model

---

📄 **[03-impact-analysis.md](03-impact-analysis.md)**  
*Impact & Migration Study*

- What would change if we implement To-Be model?
- Layer-by-layer impact analysis
- Migration plan (4 phases)
- Cost-benefit analysis
- **Read this third** to understand the cost of change

---

📄 **[04-adr-action-state.md](04-adr-action-state.md)**  
*ADR-001: Status vs Active Action Separation*

- Why we separate Status and Active Action
- Rationale and alternatives considered
- Decision authority and review notes
- **Read this fourth** to understand the decision

---

📄 **[05-roadmap.md](05-roadmap.md)**  
*Development Roadmap*

- v3.0 (Current) - MVP features
- v3.1 (Next) - User feedback + polish
- v3.2 (Conditional) - Active Action implementation
- v4.0 (Future) - Workflow engine
- **Read this last** to see the future

---

## Quick Reference

### For Developers

**Understanding current code:**
→ Read `01-as-is-current-system.md`

**Planning new features:**
→ Read `02-conceptual-model.md` + `04-adr-action-state.md`

**Migrating to v3.2:**
→ Read `03-impact-analysis.md`

---

### For Product Owners

**What we have:**
→ `01-as-is-current-system.md` (sections 1-2)

**What we decided conceptually:**
→ `02-conceptual-model.md` (sections 1-5)

**When to implement it:**
→ `05-roadmap.md` (v3.2 triggers)

---

### For New Team Members

**Day 1:** Read `01-as-is-current-system.md`  
**Day 2:** Read `02-conceptual-model.md`  
**Day 3:** Skim `03-impact-analysis.md`  
**Day 4:** Review `04-adr-action-state.md`  
**Day 5:** Check `05-roadmap.md`

---

## Key Concepts

### Status (PENDING / READY / RELEASED)

```
Status = Data Confidence Level + Legal Safety Gate

PENDING  = Unverified data → No legal actions allowed
READY    = Verified data   → Legal actions safe to perform
RELEASED = Locked          → Immutable, archived
```

**See:** `02-conceptual-model.md` section 1

---

### Active Action (Conceptual - Not Yet Implemented)

```
Active Action = Current official procedure that determines letter content

NULL      = No active procedure (standard guarantee)
extension = Extension request is active
reduction = Reduction request is active
release   = Release request is active
```

**Currently:** Inferred from Timeline (temporary solution)  
**Future:** Explicit DB field (when needed)

**See:** `02-conceptual-model.md` section 2

---

### Timeline (Implemented)

```
Timeline = Immutable audit trail of all events

Purpose:
✅ Record historical events
✅ Display history to users
✅ Compliance and audit

NOT Used For:
❌ Deriving current state
❌ Business logic decisions
```

**See:** `02-conceptual-model.md` section 3

---

## Decision Matrix

### Should I Implement Active Action Field Now?

| Question | Answer | Decision |
|----------|--------|----------|
| Does current system work? | Yes ✅ | Wait |
| Are users complaining? | No ✅ | Wait |
| Is Timeline inference painful? | No ✅ | Wait |
| Do users need "Cancel Action"? | No ✅ | Wait |
| Is there free bandwidth (3-4 weeks)? | No ❌ | **Wait** |

**If ANY changes to "No"→"Yes":** Implement v3.2

**See:** `03-impact-analysis.md` section 8

---

## Guiding Principles

### 1. Documentation Before Code

```
As-Is → Conceptual Model → Impact Study → ADR → Roadmap → Implementation
```

**Never skip documentation** - it prevents:
- Forgetting decisions
- Breaking conceptual models
- Improvised development

---

### 2. Separation of Concerns

```
Status ≠ Active Action ≠ Timeline

Each has single responsibility.
Mixing them creates implicit coupling.
```

---

### 3. Build for NOW, Plan for NEXT

```
v3.0 (NOW)  → MVP working perfectly
v3.1 (NEXT) → User feedback + polish
v3.2 (LATER)→ Active Action (when needed)
```

Don't over-engineer. But be ready to evolve.

---

## Maintenance

### This Documentation is a Living Document

**Update when:**
- Architecture decisions change
- Major features implemented
- User needs shift
- New team members need onboarding

**Review schedule:**
- Quarterly reviews (Q1, Q2, Q3, Q4)
- Ad-hoc when triggered by events

**Last reviewed:** 2025-12-31

---

## Contact

**For questions about:**
- Current system → Refer to `01-as-is-current-system.md`
- Conceptual model → Refer to `02-conceptual-model.md`
- Migration plan → Refer to `03-impact-analysis.md`
- Architectural decisions → Refer to `04-adr-action-state.md`
- Future plans → Refer to `05-roadmap.md`

---

## Summary

This documentation suite ensures that:

✅ **Current system is understood** (As-Is)  
✅ **Ideal model is documented** (To-Be)  
✅ **Migration path is clear** (Impact)  
✅ **Decisions are recorded** (ADR)  
✅ **Future is planned** (Roadmap)

**Result:** Professional, maintainable, evolvable system.
