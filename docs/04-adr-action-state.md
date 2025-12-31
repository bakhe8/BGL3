# ADR-001: Status vs Active Action Separation

**Architectural Decision Record**

**Date:** 2025-12-31  
**Status:** ACCEPTED (implementation deferred)  
**Context:** Guarantee lifecycle management and letter preview system

---

## Decision

We adopt the conceptual model where **Status** (PENDING/READY/RELEASED) and **Active Action** (extension/reduction/release) are **separate concerns**.

```
Status ≠ Active Action
Status = Data Confidence + Legal Gate
Active Action = Current Official Procedure
```

---

## Context

### Problem Statement

The system needs to:
1. Track whether guarantee data is verified (trustworthy)
2. Track what official action (if any) is currently active
3. Generate appropriate letter content based on active action
4. Maintain immutable audit trail

### Historical Approach

Initially, these concerns were **implicitly mixed**:
- Status field served as both "data confidence" and "action eligibility"
- Active action was **inferred from Timeline** (latest event)
- No explicit field for "what action is currently active"

### Why This Worked (MVP)
✅ Simpler initial implementation  
✅ Fewer database fields  
✅ Timeline naturally records all changes  
✅ No need to manage action state explicitly

### Why This is Limiting (Long-term)
⚠️ Status has dual meaning (confidence + gate)  
⚠️ Active action is inferred, not explicit  
⚠️ Timeline used for both audit AND state derivation  
⚠️ Hard to implement "cancel action" or "replace action"  
⚠️ Conceptual coupling between history and current state

---

## Decision: Separate Concerns

### Principle #1: Status = Data Confidence

```
Status answers: "Is this data trustworthy enough for legal/official use?"

PENDING  = NO  (unverified, imported, auto-matched)
READY    = YES (verified, human-confirmed)
RELEASED = N/A (locked, immutable)
```

**This is a PERMANENT property** of the guarantee decision.

---

### Principle #2: Active Action = Current Intent

```
Active Action answers: "What official procedure is active right now?"

NULL      = No active procedure (standard guarantee)
extension = Extension request is active
reduction = Reduction request is active
release   = Release request is active
```

**This is a TEMPORARY state** that can change.

---

### Principle #3: Timeline = Audit Only

```
Timeline answers: "What happened historically?"

Timeline is:
✅ Immutable record of events
✅ For compliance and audit
✅ For displaying history to users

Timeline is NOT:
❌ Source of truth for current state
❌ Queried for business logic
❌ Used to derive active action
```

---

## Rationale

### Why Separate Status and Action?

#### Reason #1: Conceptual Clarity
```
"Guarantee is READY" does NOT mean "action exists"
"Guarantee is READY" means "action is now SAFE to perform"

Example:
- A guarantee can be READY with no active action
- This is a valid state (standard guarantee)
```

#### Reason #2: State Independence
```
Status changes trigger:
├─ Preview availability (gate)
└─ Action button enablement (gate)

Action changes trigger:
├─ Letter content change
└─ Timeline event recording

These are DIFFERENT concerns.
```

#### Reason #3: Maintainability
```
Adding "Cancel Action" feature:

With Timeline Inference:
- Add cancel event to Timeline
- Add logic to detect "latest event is cancel"
- Handle edge cases (cancel after cancel?)
- Complex inference logic

With Active Action Field:
- Set active_action = NULL
- Done.
```

#### Reason #4: Testability
```
Testing "Is extension active?":

With Timeline:
- Query latest event
- Check event_type
- Handle missing events
- Mock Timeline

With Active Action:
- Check active_action field
- Done.
```

---

## Consequences

### Positive

✅ **Clear Separation of Concerns**  
   Status, Action, and Timeline each have single responsibility

✅ **Explicit State**  
   No inference needed - active_action field is direct truth

✅ **Scalability**  
   Easy to add new actions, multi-step workflows, approvals

✅ **Maintainability**  
   Less complex logic, easier to reason about

✅ **Performance**  
   Direct field access vs Timeline queries

### Negative

⚠️ **One More Field**  
   Database schema slightly more complex

⚠️ **Migration Needed**  
   Requires backfilling existing data from Timeline

⚠️ **State Management**  
   Need to explicitly set/clear active_action

### Mitigation

- Migration is one-time, low-risk (backfill script)
- State management is simpler than Timeline inference
- Benefits outweigh added complexity

---

## Implementation Status

### Current Status: ACCEPTED but NOT IMPLEMENTED

**Why Deferred:**
- Current MVP works well
- No blocking use cases yet
- Team bandwidth limited
- Migration can be gradual

**Implementation Triggers:**
- User requests "Cancel Action"
- User requests "Replace Action"
- Multi-step workflows needed
- Timeline inference becomes painful

**Estimated Effort:** 3-4 weeks (see `03-impact-analysis.md`)

---

## Alternatives Considered

### Alternative #1: Keep Status + Action Mixed

**Pros:**
- Simpler schema
- Fewer fields to manage

**Cons:**
- Conceptual confusion
- Hard to extend
- Status has dual meaning

**Decision:** ❌ Rejected - violates single responsibility

---

### Alternative #2: Use Timeline as Source of Truth

**Pros:**
- Already have Timeline
- No new fields needed
- History naturally derives state

**Cons:**
- Timeline queried for every state check
- Inference logic complex
- Hard to implement cancel/replace
- Couples audit trail to business logic

**Decision:** ❌ Rejected - tight coupling, scalability issues

---

### Alternative #3: Separate Status Table

**Pros:**
- Ultra-explicit separation
- Could track multiple actions

**Cons:**
- Over-engineering for current needs
- Extra JOIN for every query
- More complex schema

**Decision:** ❌ Rejected - YAGNI (You Aren't Gonna Need It)

---

## Related Decisions

### ADR-002: Card-Driven Preview (implemented)
- Preview reads from Data Card, not Timeline
- Supports this decision (single source of truth)

### ADR-003: Event Context as View State (implemented)
- Temporary state for historical views
- Will be REPLACED by active_action when implemented

---

## References

### Documentation
- `01-as-is-current-system.md` - Current implementation
- `02-conceptual-model.md` - Target model
- `03-impact-analysis.md` - Migration plan

### Code Locations
- `app/Models/GuaranteeDecision.php` - Status model
- `guarantee_history` table - Timeline storage
- `public/js/records.controller.js` - Preview logic

---

## Review Notes

### 2025-12-31: Initial Decision
- Team agrees on conceptual separation
- Implementation deferred pending use cases
- Migration plan documented

### Future Reviews
- Review every 3 months or when triggered
- Re-evaluate if Timeline inference becomes burden
- Monitor user feedback for cancel/replace needs

---

## Decision Authority

**Decided by:** Development Team + Product Owner  
**Reviewers:** [To be filled]  
**Approved by:** [To be filled]

---

## Summary

### What We Decided
```
Status and Active Action are SEPARATE CONCEPTS.
Status = Data trust level
Active Action = Current procedure
Timeline = Audit only
```

### Why We Decided This
```
Clarity, maintainability, scalability
Avoids conceptual coupling
Enables future features (cancel, replace, workflows)
```

### When We'll Implement
```
When user needs justify it (cancel action, workflows)
OR when Timeline inference becomes maintenance burden
Estimated: 6-12 months from now
```

### How We'll Know It's Right
```
✅ Code is clearer
✅ New features are easier
✅ No Timeline queries for state
✅ Tests are simpler
```

---

**This decision is BINDING for future development.**  
Any deviation must update this ADR with rationale.
