# Phase 3: Dual Run - Implementation Guide

**Phase:** 3 - Dual Run (Shadow Validation)  
**Duration:** 2-4 weeks  
**Status:** 🟢 Infrastructure Complete - Ready to Deploy  
**Goal:** Validate Authority in production shadow mode with zero user impact  

---

## 🎯 Objectives

1. ✅ Run Authority in parallel with Legacy (shadow mode)
2. ✅ Collect comparison metrics (coverage, divergence, performance)
3. ✅ Identify and fix gaps before cutover
4. ✅ Build confidence in Authority correctness
5. ✅ Zero user impact (always return Legacy results)

---

## 📦 Components Built

### Core Infrastructure (4 files)

✅ **ComparisonResult.php** - Metrics calculation
- Coverage percentage
- Missed suppliers (gaps)
- New discoveries (Authority finds more)
- Confidence divergence
- Performance delta

✅ **ComparisonLogger.php** - Data collection
- Daily JSONL logs (`comparisons_YYYY-MM-DD.jsonl`)
- Running summary statistics (`summary.json`)
- Automatic aggregation
- Human-readable reports

✅ **ShadowExecutor.php** - Orchestration
- Executes Legacy + Authority in parallel
- Captures timing for both
- Logs comparison
- ALWAYS returns Legacy (zero impact)
- Error isolation (Authority errors don't affect users)

✅ **generate_dual_run_report.php** - Analysis
- Real-time metrics dashboard
- Pass/Fail criteria evaluation
- Action items for gaps
- Cutover readiness assessment

---

## 🚀 Deployment Steps

### Step 1: Add Shadow Execution to Endpoints

**Choose endpoints to monitor:** (Recommendation: start with 1-2 high-traffic)

1. Learning suggestions endpoint
2. Fuzzy matching endpoint
3. Level B (entity anchor) endpoint

**Integration pattern:**
```php
// BEFORE
$suggestions = $legacyService->getSuggestions($input);

// AFTER
$suggestions = $shadowExecutor->execute(
    rawInput: $input,
    legacyCallable: fn() => $legacyService->getSuggestions($input)
);
```

**See:** `docs/examples/shadow_executor_integration.php` for complete examples

---

### Step 2: Configure Dual Run

**Option A: Always On (Recommended for Week 1)**
```php
// In controller constructor
$authority = AuthorityFactory::create();
$logger = new ComparisonLogger();
$this->shadowExecutor = new ShadowExecutor($authority, $logger, enabled: true);
```

**Option B: Feature Flag (Gradual Rollout)**
```php
// config/features.php
'dual_run_enabled' => env('DUAL_RUN_ENABLED', false),

// In controller
$enabled = config('features.dual_run_enabled');
$this->shadowExecutor = new ShadowExecutor($authority, $logger, enabled: $enabled);
```

**Option C: Sampling (Reduce Load)**
```php
// Only shadow 50% of requests
$shouldSample = (mt_rand() / mt_getrandmax()) < 0.5;
if ($shouldSample) {
    $suggestions = $shadowExecutor->execute(...);
} else {
    $suggestions = $legacyService->getSuggestions($input);
}
```

---

### Step 3: Monitor Logs

**Log Locations:**
```
storage/logs/dual_run/
├── comparisons_2026-01-03.jsonl    # Daily detailed logs
├── comparisons_2026-01-04.jsonl
├── summary.json                     # Running statistics
└── authority_errors.log             # Authority execution errors
```

**Daily Log Format (JSONL):**
```json
{
  "input_raw": "شركة النورس",
  "input_normalized": "شركه النورس",
  "legacy_count": 3,
  "authority_count": 3,
  "coverage_percent": 100.0,
  "missed_suppliers": [],
  "new_discoveries": [],
  "confidence_divergence_avg": 5.2,
  "legacy_execution_ms": 45.3,
  "authority_execution_ms": 52.1,
  "performance_delta_ms": 6.8,
  "timestamp": "2026-01-03T10:15:30+00:00"
}
```

---

### Step 4: Generate Reports

**Daily (Manual):**
```bash
php generate_dual_run_report.php
```

**Output:**
```
=== Dual Run Summary Report ===

Period: 2026-01-03 to 2026-01-10
Total Comparisons: 1,245

--- Coverage ---
Average: 97.5% ✅ PASS
Gaps Detected: 15

--- Performance ---
Average Delta: 12.3 ms ✅ PASS
Authority Faster: 823 times
Authority Slower: 422 times

--- Confidence Divergence ---
Average: 8.5 points ✅ GOOD

--- Overall Assessment ---
Criteria Passed: 3/3
Status: ✅ READY FOR CUTOVER
```

---

## 📊 Success Criteria

### Must Meet ALL Before Phase 4 Cutover

| Metric | Target | Status | Action if Fail |
|--------|--------|--------|----------------|
| **Coverage** | >= 95% | ⏳ TBD | Investigate missed suppliers, enhance feeders |
| **Performance** | < 100ms delta (avg) | ⏳ TBD | Profile slow feeders, optimize queries |
| **Divergence** | < 25 points (avg) | ⏳ TBD | Review confidence formula, adjust weights |
| **Error Rate** | < 1% | ⏳ TBD | Fix bugs in Authority/feeders |

**Measurement Period:** Minimum 7 days, recommended 14 days

---

## 🔍 Gap Analysis Process

### When Coverage < 95%

**Step 1: Identify Missed Suppliers**
```bash
php generate_dual_run_report.php
# Check "Top Missed Suppliers" section
```

**Step 2: Investigate Root Cause**

For each frequently-missed supplier:
1. Check which Legacy service found it (Learning vs Fuzzy vs Anchor)
2. Verify corresponding feeder exists in Authority
3. Check if feeder is returning signal for that supplier
4. Review signal strength - is it too weak (causing filtering)?

**Step 3: Fix**

Common fixes:
- Missing feeder → Add new feeder
- Weak signals → Adjust base scores in ConfidenceCalculatorV2
- Wrong query → Fix repository method
- Legacy uses unique source → Decide: add to Authority or deprecate

**Step 4: Re-test**
- Deploy fix
- Monitor for 2-3 days
- Re-run report
- Verify improvement

---

### When Performance > 100ms

**Step 1: Profile Authority Execution**

Add timing logs to each feeder:
```php
// In Authority
foreach ($this->feeders as $feeder) {
    $start = microtime(true);
    $signals = $feeder->getSignals($normalized);
    $duration = (microtime(true) - $start) * 1000;
    
    error_log("Feeder " . get_class($feeder) . ": {$duration}ms");
}
```

**Step 2: Identify Slow Feeder**

Typical culprits:
- FuzzySignalFeeder (scans all suppliers)
- HistoricalSignalFeeder (JSON LIKE query)

**Step 3: Optimize**

Options:
- Cache ALL suppliers list (FuzzySignalFeeder)
- Add index on normalized_name (database)
- Limit fuzzy scan to top N suppliers
- Batch queries instead of N+1

**Step 4: Re-test**

---

### When Divergence > 25 points

**Step 1: Analyze Divergence Patterns**

Review daily logs - which signal types have highest divergence?

**Step 2: Compare Formulas**

- Legacy: Extract exact formula from old services
- Authority: Review ConfidenceCalculatorV2 base scores
- Map Legacy confidence sources to Authority signal types

**Step 3: Align**

Adjust `BASE_SCORES` in ConfidenceCalculatorV2 to match Legacy intent:
```php
private const BASE_SCORES = [
    'alias_exact' => 100,           // Was Legacy returning 100?
    'entity_anchor_unique' => 90,   // Was Legacy 70-95?
    // etc.
];
```

**Step 4: Validate**

Don't just minimize divergence - ensure Authority follows **Charter**, not Legacy bugs.

---

## ⚠️ Known Issues & Mitigation

### Issue 1: Authority Errors Don't Affect Users

**Behavior:** ShadowExecutor catches Authority exceptions, logs them, returns Legacy.

**Monitoring:** Check `storage/logs/dual_run/authority_errors.log` daily.

**Action:** Fix all Authority errors before cutover  (Phase 4).

---

### Issue 2: Increased Server Load

**Impact:** Running 2 systems in parallel doubles suggestion processing.

**Mitigation:**
- Start with sampling (50%)
- Monitor server CPU/memory
- Scale horizontally if needed
- Dual run is temporary (2-4 weeks)

---

### Issue 3: Log Storage Growth

**Impact:** JSONL logs grow ~100KB per 1000 requests.

**Mitigation:**
- Rotate logs weekly
- Archive old logs to S3/backup
- Delete logs after Phase 4 cutover
- Keep summary.json only

---

## 🎓 How to Read the Data

### Coverage Scenarios

**100% Coverage:**
- Authority finds ALL Legacy suppliers ✅
- Perfect (but verify confidence alignment)

**95-99% Coverage:**
- Minor gaps acceptable ✅
- Investigate missed suppliers
- May proceed to cutover if explainable

**< 95% Coverage:**
- Significant gaps ❌
- Must investigate before cutover
- Likely missing feeder or query bug

---

### Divergence Scenarios

**< 10 points average:**
- Excellent alignment ✅
- Confidence formulas match well

**10-25 points average:**
- Acceptable ✅
- Some differences expected (Charter vs Legacy)
- Verify Authority follows Charter intent

**> 25 points average:**
- High divergence ⚠️
- Review confidence formula
- May indicate misunderstanding of Legacy intent

---

### Performance Scenarios

**Authority Faster:**
- Excellent (common for simple inputs) ✅
- Authority is well-optimized

**Delta < 50ms:**
- Acceptable ✅
- User won't notice

**Delta 50-100ms:**
- Acceptable but monitor ⚠️
- Consider optimization

**Delta > 100ms:**
- Unacceptable for most use cases ❌
- Must optimize before cutover

---

## 📅 Recommended Timeline

### Week 1: Deploy & Monitor
- **Day 1:** Deploy shadow execution to 1 endpoint
- **Day 2-3:** Monitor error logs, fix critical bugs
- **Day 4-7:** Expand to all suggestion endpoints
- **End of Week:** First comprehensive report

### Week 2: Analysis & Iteration
- **Day 8:** Generate report, identify gaps
- **Day 9-11:** Fix gaps, optimize performance
- **Day 12-14:** Re-monitor with fixes

### Week 3 (Optional): Extended Monitoring
- **Day 15-21:** Continuous monitoring for edge cases
- **Weekly:** Generate report, track improvement

### Week 4: Cutover Prep
- **Day 22:** Final report, ARB review
- **Day 23-24:** Cutover planning
- **Day 25:** Proceed to Phase 4 or extend monitoring

---

## ✅ Cutover Checklist

Before proceeding to Phase 4, verify:

- [ ] Dual run active for >= 7 days
- [ ] >= 500 comparisons logged (minimum)
- [ ] Coverage >= 95%
- [ ] Performance delta < 100ms (avg)
- [ ] Confidence divergence < 25 points (avg)
- [ ] Authority error rate < 1%
- [ ] All gaps investigated and documented
- [ ] ARB approves cutover
- [ ] Rollback plan prepared

---

## 🔄 Rollback Plan (If Needed)

If Phase 4 cutover causes issues:

1. **Immediate:** Disable ShadowExecutor (set `enabled: false`)
2. **Revert:** Endpoints return to Legacy-only
3. **Investigate:** Review what went wrong
4. **Fix:** Address issues in shadow mode
5. **Re-test:** Another dual-run cycle
6. **Retry:** Cutover again when ready

No data loss - dual run is read-only observation.

---

## 📞 Support

**For Dual Run Questions:**
- Check this guide first
- Review `docs/examples/shadow_executor_integration.php`
- Consult Phase 2 Completion Report

**For Bugs:**
- Check `authority_errors.log`
- Include input that caused error
- Attach relevant feeder/service code

**For Performance:**
- Run report with `--profile` flag (future enhancement)
- Share slow query logs
- Provide server specs

---

**Status:** ✅ **PHASE 3 INFRASTRUCTURE COMPLETE**  
**Next Action:** Deploy shadow execution to first endpoint  
**Timeline:** 2-4 weeks monitoring  
**Risk:** Low (zero user impact)  

**Last Updated:** 2026-01-03
