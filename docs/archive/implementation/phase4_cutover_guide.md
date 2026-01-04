# Phase 4: Production Cutover - Complete Guide

**Phase:** 4 - Production Cutover  
**Duration:** 3-4 weeks  
**Status:** 🟢 Infrastructure Complete - Ready to Execute  
**Risk Level:** HIGH (production changes)  
**Safety:** Gradual rollout + instant rollback  

---

## 🎯 Objectives

1. ✅ Gradually route production traffic from Legacy → Authority
2. ✅ Monitor real-time metrics during rollout
3. ✅ Instant rollback capability if issues arise
4. ✅ Achieve 100% Authority with zero user impact
5. ✅ Validate Charter compliance in production

---

## 📦 Infrastructure Built

### Core Components (4 files)

✅ **CutoverManager.php** - Rollout control
- Gradual percentage-based routing (0-100%)
- Deterministic hash (same input → same system)
- Enable/disable cutover
- Emergency rollback

✅ **ProductionRouter.php** - Traffic routing
- Routes to Authority or Legacy based on CutoverManager
- Automatic fallback (Authority fails → Legacy)
- Metrics collection
- Zero user impact guarantee

✅ **ProductionMetrics.php** - Real-time monitoring
- Error rates (Authority vs Legacy)
- Performance (p50, p95, p99)
- Fallback tracking
- Rollout criteria validation

✅ **cutover.php** - CLI control panel
- Status checking
- Percentage adjustment
- Metrics viewing
- Emergency rollback

---

## 🚀 Gradual Rollout Strategy

### Recommended Timeline (4 weeks)

| Week | Rollout % | Duration | Success Criteria | Action if Fail |
|------|-----------|----------|------------------|----------------|
| **1** | 10% | 7 days | Error rate < 5%, P95 < +100ms | Hold at 10%, investigate |
| **2** | 25% | 7 days | Same criteria | Rollback to 10% |
| **3** | 50% | 7 days | Same criteria | Rollback to 25% |
| **4** | 100% | Permanent | Same criteria | Rollback to 50% |

**Why gradual?**
- Limits blast radius (10% = 10% of users affected)
- Early detection of issues
- Time to investigate/fix between increments
- Builds team confidence

---

## 📋 Week-by-Week Execution

### Week 1: Initial Rollout (10%)

**Day 1 (Monday): Deploy**

```bash
# 1. Deploy code with ProductionRouter integration
git pull
composer install  # or equivalent

# 2. Verify infrastructure
php cutover.php status
# Should show: Disabled, 0%

# 3. Enable at 10%
php cutover.php enable 10

# 4. Verify
php cutover.php status
# Should show: Enabled, 10%, "Gradual Rollout"
```

**Day 1-2: Monitor Closely**

```bash
# Check metrics every hour
php cutover.php metrics

# Check criteria
php cutover.php check
```

**Watch for:**
- Authority error rate > 5%
- P95 performance > +100ms vs Legacy
- Fallback rate > 1%
- User complaints (support tickets)

**Day 3-7: Continuous Monitoring**

- Check metrics 2x per day (morning, evening)
- Review error logs daily
- If stable for 7 days → proceed to Week 2

**Emergency Rollback (if needed):**
```bash
php cutover.php rollback "Reason: high error rate on endpoint X"
```

---

### Week 2: Increase to 25%

**Day 8 (Monday): Pre-Flight Check**

```bash
# 1. Check Week 1 metrics
php cutover.php metrics

# 2. Validate criteria
php cutover.php check
# Must show: ✅ READY TO INCREASE ROLLOUT

# 3. Review error logs
# - No critical errors in Week 1
# - Fallbacks < 10 total
```

**If checks pass:**
```bash
# Increase to 25%
php cutover.php set 25
```

**If checks fail:**
```bash
# Stay at 10%, investigate issues
# Fix problems
# Re-check after 3-5 days
```

**Day 8-14: Monitor at 25%**

- Same monitoring as Week 1
- Larger user base = more data
- Watch for edge cases not seen at 10%

---

### Week 3: Increase to 50%

**Day 15 (Monday): Pre-Flight Check**

```bash
php cutover.php check
# Must pass all criteria
```

**If pass:**
```bash
php cutover.php set 50
```

**Day 15-21: Monitor at 50%**

- Authority now handles majority of traffic
- Performance should be stable
- Error rate should match or beat Legacy

**Critical:** At 50%, Authority is mission-critical. Any rollback impacts significant traffic.

---

### Week 4: Full Rollout (100%)

**Day 22 (Monday): Final Check**

```bash
# 1. Comprehensive metrics review
php cutover.php metrics

# 2. Criteria check
php cutover.php check

# 3. ARB approval (if required)
# Present metrics, get sign-off
```

**If approved:**
```bash
php cutover.php set 100
```

**Day 22+: Post-Cutover Monitoring**

- Monitor for 48 hours intensively
- Check for any Legacy-specific issues
- Validate ALL endpoints work with Authority
- If stable for 7 days → Phase 4 COMPLETE ✅

---

## 🔧 Integration Example

### Controller Before (Phase 3)

```php
class SupplierController
{
    private LearningSuggestionService $legacyService;

    public function getSuggestions(Request $request)
    {
        $input = $request->input('supplier_name');
        $suggestions = $this->legacyService->getSuggestions($input);
        return response()->json($suggestions);
    }
}
```

### Controller After (Phase 4)

```php
class SupplierController
{
    private ProductionRouter $router;

    public function __construct()
    {
        $authority = AuthorityFactory::create();
        $legacyService = new LearningSuggestionService();
        $cutoverManager = new CutoverManager();
        
        $this->router = new ProductionRouter(
            $authority,
            $legacyService,
            $cutoverManager
        );
    }

    public function getSuggestions(Request $request)
    {
        $input = $request->input('supplier_name');
        
        // Routed to Authority or Legacy based on cutoverManager
        $suggestions = $this->router->getSuggestions($input);
        
        return response()->json($suggestions);
    }
}
```

**That's it!** Router handles everything:
- Percentage-based routing
- Automatic fallback
- Metrics collection

---

## 📊 Success Criteria

### Must Meet ALL Before Each Increment

| Metric | Target | How to Check |
|--------|--------|--------------|
| Error Rate | < 5% | `php cutover.php check` |
| Performance (P95) | < +100ms vs Legacy | `php cutover.php metrics` |
| Fallback Rate | < 1% | `php cutover.php metrics` |
| User Complaints | 0 critical | Support ticket review |

**Formula:**
- ALL ✅ = Safe to proceed
- ANY ❌ = Hold/rollback, investigate

---

## 🚨 Emergency Procedures

### When to Rollback

**Immediate Rollback:**
- Error rate > 10%
- Critical user-facing bugs
- Data integrity issues
- Server overload/crash

**Investigate & Hold:**
- Error rate 5-10%
- Performance degradation (but acceptable)
- Minor bugs (non-blocking)

**Continue Monitoring:**
- Error rate < 5%
- All metrics green
- No user complaints

---

### How to Rollback

**Option 1: Disable (Safe, Full Rollback)**
```bash
php cutover.php disable
# ALL traffic → Legacy
# Zero Authority traffic
```

**Option 2: Reduce Percentage**
```bash
# From 50% → 25%
php cutover.php set 25
```

**Option 3: Emergency Rollback**
```bash
php cutover.php rollback "Reason: Authority returning wrong confidence scores"
# Sets to 0% AND disables
# Logs reason for audit
```

**Impact:** Rollback is INSTANT (next request)
- No deploy needed
- No code changes
- Just configuration change

---

## 🎓 Troubleshooting Guide

### Issue: High Error Rate on Authority

**Symptoms:**
- `php cutover.php check` shows ERROR RATE ❌ FAIL
- `storage/logs/` shows Authority exceptions

**Investigation:**
1. Check error logs: `tail -f storage/logs/dual_run/authority_errors.log`
2. Identify failing feeder or service
3. Reproduce error locally: `php test_authority.php`

**Common Causes:**
- Missing data (e.g., supplier no longer exists)
- Database connection issues
- Timeout (slow feeder)
- Bug in feeder logic

**Fix:**
- Apply hotfix to Authority/feeder
- Deploy fix
- Monitor for 24hrs
- If resolved, resume rollout

---

### Issue: Performance Degradation

**Symptoms:**
- P95 > +100ms vs Legacy
- API timeouts
- User complaints of slowness

**Investigation:**
1. Check metrics: `php cutover.php metrics`
2. Identify slow feeder: Add timing logs (see Phase 3 guide)
3. Profile database queries

**Common Causes:**
- FuzzySignalFeeder scanning all suppliers (no index)
- N+1 query problem in feeders
- Database not optimized
- Too many feeders called

**Fix:**
- Add database index on `normalized_name`
- Cache frequently-accessed data
- Optimize slow queries
- Consider disabling non-critical feeders temporarily

---

### Issue: Fallback Rate Too High

**Symptoms:**
- `php cutover.php metrics` shows fallbacks > 1%
- Authority failing frequently but Legacy succeeds

**Investigation:**
1. Review fallback logs
2. Compare inputs that fallback vs succeed
3. Check for pattern (e.g., specific characters fail)

**Common Causes:**
- Authority stricter validation than Legacy
- Missing error handling in Authority
- Specific input type not covered

**Fix:**
- Add error handling
- Relax validation (if Charter-compliant)
- Add missing feeder for specific case

---

## 📈 Monitoring Dashboard (Optional)

**If you have a monitoring system (Grafana, Datadog, etc.):**

**Metrics to Track:**
- `cutover.rollout_percentage` (gauge)
- `cutover.authority. requests_total` (counter)
- `cutover.authority.errors_total` (counter)
- `cutover.authority.response_time` (histogram)
- `cutover.fallbacks_total` (counter)

**Alerts:**
- Error rate > 5% for 5 minutes → Page on-call
- P95 > +200ms for 10 minutes → Warning
- Fallback rate > 2% for 5 minutes → Warning

---

## ✅ Phase 4 Completion Checklist

- [ ] Week 1 (10%) completed successfully
- [ ] Week 2 (25%) completed successfully
- [ ] Week 3 (50%) completed successfully
- [ ] Week 4 (100%) deployed and stable for 7 days
- [ ] All metrics green for 7 consecutive days at 100%
- [ ] Zero critical bugs reported
- [ ] ARB final approval obtained
- [ ] Team trained on Authority maintenance

**When ALL checked:** Phase 4 COMPLETE ✅ → Proceed to Phase 5 (UI Consolidation)

---

## 🔮 What's Next (Phase 5 Preview)

After 100% cutover:
- Authority is primary system ✅
- Legacy still exists (fallback)
- UI still supports both formats (compatibility layer)

**Phase 5 Goals:**
- Remove UI compatibility layer
- Standardize on SuggestionDTO everywhere
- Simplify frontend components
- Remove Legacy-specific UI code

**Phase 6:** Deprecate Legacy completely

---

**Status:** ✅ **PHASE 4 INFRASTRUCTURE COMPLETE**  
**Next Action:** Deploy ProductionRouter, start Week 1 at 10%  
**Timeline:** 4 weeks gradual rollout  
**Risk Mitigation:** Instant rollback, gradual percentage, continuous monitoring  

**Last Updated:** 2026-01-03
