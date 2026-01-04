# Development Roadmap

**Purpose:** Map future development milestones and feature releases.

**Last Updated:** 2025-12-31  
**Current Version:** 3.0 (MVP)

---

## Version History

### v3.0 - Current (MVP) ✅

**Released:** December 2025  
**Status:** Production-ready

**Features:**
- ✅ Excel import with smart parsing
- ✅ Auto-matching (supplier + bank)
- ✅ Manual review and confirmation
- ✅ Status-based gates (PENDING → READY)
- ✅ Card-driven preview system
- ✅ Timeline-based history
- ✅ Event-aware preview (extension/reduction/release)
- ✅ Action APIs (extend, reduce, release)
- ✅ Immutable timeline/audit trail

**Architectural Decisions:**
- Status = Data Confidence + Gate (implemented)
- Active Action = Inferred from Timeline (temporary solution)
- Preview blocked until READY (implemented)
- Event context as temporary View State (implemented)

**Known Limitations:**
- Cannot cancel active action
- Cannot replace action with different action
- Action state inferred from Timeline (not explicit)

---

## v3.1 - User Feedback + Polish (Next)

**Target:** Q1 2026 (Jan-Mar)  
**Focus:** Refinement based on user feedback

### Planned Features

#### 1. Enhanced Import Experience
- [ ] Bulk import preview before commit
- [ ] Import conflict resolution UI
- [ ] Duplicate detection improvements
- [ ] Import templates for different banks

#### 2. Search & Filter
- [ ] Global search across guarantees
- [ ] Filter by status/date/supplier/bank
- [ ] Saved search presets
- [ ] Export filtered results

#### 3. Reporting
- [ ] Summary dashboard (counts by status)
- [ ] Expiry date alerts
- [ ] Action history reports
- [ ] Custom date range reports

#### 4. UI/UX Improvements
- [ ] Keyboard shortcuts
- [ ] Bulk actions (multi-select)
- [ ] Dark mode support
- [ ] Mobile-responsive layout

**Decision Point:**  
If users request "Cancel Action" or "Replace Action" → Advance to v3.2 early.

---

## v3.2 - Active Action State (Conditional)

**Target:** Q2 2026 (Apr-Jun) OR when triggered  
**Focus:** Implement explicit Active Action field

### Implementation Triggers

Implement if **ANY** of these occur:
- ✅ User requests "Cancel Action" feature
- ✅ User requests "Replace Action" (change extension to reduction)
- ✅ Need for multi-step action workflows (request → approval → execute)
- ✅ Timeline inference becomes maintenance burden

### Planned Changes

#### Phase 1: Schema Migration
- [ ] Add `active_action` column to `guarantee_decisions`
- [ ] Add `active_action_created_at` timestamp
- [ ] Backfill existing data from Timeline
- [ ] Migration testing

**Effort:** 1 week  
**Risk:** Low (non-destructive)

#### Phase 2: Backend Refactor
- [ ] Add `setActiveAction()` repository method
- [ ] Update `extend.php` to set active_action
- [ ] Update `reduce.php` to set active_action
- [ ] Update `release.php` to set active_action
- [ ] Add `clearActiveAction()` for cancel feature
- [ ] Integration tests

**Effort:** 1 week  
**Risk:** Low (isolated changes)

#### Phase 3: Frontend Updates
- [ ] Update `record-form.php` to output `active_action`
- [ ] Simplify `records.controller.js` preview logic
- [ ] Remove temporary `event_context` state
- [ ] Remove `hideEventContextBadge()` calls
- [ ] Direct read from `active_action` field

**Effort:** 1 week  
**Risk:** Very Low (simplification)

#### Phase 4: Cleanup
- [ ] Remove Timeline-based action inference
- [ ] Update documentation
- [ ] Final QA and deployment

**Effort:** 1 week  
**Risk:** Very Low

**Total Effort:** 3-4 weeks  
**Total Risk:** Low

---

## v3.3 - Action Management Features

**Target:** Q3 2026 (Jul-Sep)  
**Depends on:** v3.2 completion  
**Focus:** Advanced action workflows

### Planned Features

#### 1. Cancel Action
```
User initiates extension → Changes mind → Cancels
Result: active_action = NULL, timeline records cancel event
```

#### 2. Replace Action
```
User has extension active → Decides to reduce instead
Result: active_action changes from 'extension' to 'reduction'
```

#### 3. Action History per Guarantee
```
Show all actions ever performed on a guarantee
Filter timeline by action type
```

#### 4. Action Templates
```
Pre-fill extension date (+1 year)
Pre-fill reduction amount (common percentages)
```

---

## v4.0 - Workflow Engine (Future)

**Target:** Q4 2026 (Oct-Dec)  
**Focus:** Multi-step action workflows

### Vision

Enable business process workflows:

```
Extension Request → Pending Approval → Approved → Executed
                                    ↓
                                Rejected → Canceled
```

### Planned Components

#### 1. Action Status
```
active_action_status:
- 'draft'     → User preparing request
- 'pending'   → Awaiting approval
- 'approved'  → Ready to execute
- 'rejected'  → Denied
- 'executed'  → Completed
```

#### 2. Approval System
- [ ] User roles (requester, approver, admin)
- [ ] Approval workflow configuration
- [ ] Email notifications
- [ ] Approval history

#### 3. Conditional Logic
- [ ] Auto-approve small extensions
- [ ] Require approval for large reductions
- [ ] Bank-specific rules

**Complexity:** High  
**Effort:** 8-12 weeks  
**Risk:** Medium (requires careful design)

---

## v5.0 - Multi-Tenancy (Long-term)

**Target:** 2027  
**Focus:** Support multiple organizations

### Vision
- Multiple hospitals using same system
- Isolated data per organization
- Shared or custom workflows
- Centralized admin panel

**Complexity:** Very High  
**Effort:** 6 months  
**Risk:** High (major architectural change)

---

## Triggers & Decision Points

### When to Implement v3.2 (Active Action)

**Trigger Events:**
- [ ] First user request for cancel action
- [ ] First user complaint about action ambiguity
- [ ] Timeline query performance issues
- [ ] Developer difficulty maintaining Timeline inference

**Who Decides:** Product Owner + Dev Lead  
**How:** Review this document + `03-impact-analysis.md`

---

### When to Implement v4.0 (Workflows)

**Trigger Events:**
- [ ] Legal requirement for approval process
- [ ] More than 5 users managing guarantees
- [ ] Audit requests for approval trails
- [ ] User requests for delegation

**Who Decides:** Product Owner + Stakeholders  
**How:** Business case analysis + user research

---

## Non-Goals

### What We Will NOT Build

❌ **Integration with Bank APIs**  
   Reason: Banks don't provide real-time APIs

❌ **Mobile Native App**  
   Reason: Web responsive design sufficient

❌ **OCR for Scanned PDFs**  
   Reason: Excel import sufficient for now

❌ **Blockchain / Crypto Signatures**  
   Reason: Not a requirement

---

## Success Metrics

### v3.1 Success Criteria
- ✅ 90% user satisfaction with search/filter
- ✅ Reports used weekly by users
- ✅ <5 bug reports per month

### v3.2 Success Criteria
- ✅ Cancel action used by users
- ✅ No performance degradation
- ✅ Code complexity reduced (simpler logic)

### v4.0 Success Criteria
- ✅ Approval workflow reduces errors
- ✅ Audit compliance met
- ✅ User onboarding <1 hour

---

## Risk Management

### High-Risk Items

| Item | Risk | Mitigation |
|------|------|------------|
| v3.2 Migration | Data loss | Thorough testing, backups, rollback plan |
| v4.0 Workflows | Over-complexity | Start with simplest workflow, iterate |
| v5.0 Multi-tenancy | Performance | Load testing, caching, database optimization |

### Release Strategy

```
Dev → Staging → Limited Production → Full Production
 ↓        ↓            ↓                    ↓
1 week  2 weeks    1 month            Gradual rollout
```

---

## Dependencies

### External Dependencies
- PHP 8.x support
- Modern browser (Chrome/Edge/Firefox)
- Database: MySQL/MariaDB
- Server: Apache/Nginx

### Internal Dependencies
- Maintain backward compatibility with v3.0 data
- No breaking changes to API endpoints
- Timeline remains immutable

---

## Review Schedule

### Quarterly Reviews
- **Q1 2026:** Review v3.1 completion
- **Q2 2026:** Decide on v3.2 trigger
- **Q3 2026:** Plan v4.0 scope
- **Q4 2026:** Long-term vision (v5.0)

### Ad-Hoc Reviews
- User feedback triggers immediate review
- Security issues trigger emergency review
- Performance issues trigger optimization review

---

## Summary

### Current State (v3.0)
✅ Fully functional MVP  
✅ All core features working  
✅ Ready for production use

### Next Steps (v3.1)
🎯 User feedback incorporation  
🎯 Search, filter, reports  
🎯 UI/UX polish

### Future Vision (v3.2+)
🔮 Explicit Active Action state (when needed)  
🔮 Workflow engine (when justified)  
🔮 Multi-tenancy (long-term)

### Guiding Principle
```
Build what users need NOW.
Plan for what they'll need NEXT.
Be ready for what they might need LATER.
```

---

**This roadmap is a living document.**  
Updated quarterly or when major decisions are made.
