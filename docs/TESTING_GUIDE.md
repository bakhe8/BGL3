# Testing & Validation Guide

**Document:** Testing Strategy for Supplier Learning Unification  
**Version:** 1.0  
**Date:** 2026-01-03  

---

## 🧪 Test Suite Overview

### Test Pyramid

```
       /\
      /  \  E2E Tests (Manual - Phase 3/4)
     /____\
    /      \
   / Integ  \ Integration Tests (3 files)
  /__________\
 /            \
/   Unit       \ Unit Tests (2 files)
/________________\
```

**Total Tests Created:** 5 test files  
**Coverage Target:** 80%+ for Authority core  

---

## 📋 Test Files Created

### 1. Unit Tests (Fast, Isolated)

**File:** `tests/Unit/Services/Learning/ConfidenceCalculatorV2Test.php`

**Tests:**
- ✅ Base score calculation (all signal types)
- ✅ Confirmation boosts (+5/+10/+15)
- ✅ Rejection penalties (-10 per rejection)
- ✅ Confidence clamping (0-100)
- ✅ Level assignment (B/C/D)
- ✅ Display threshold (>= 40)
- ✅ Multiple signals aggregation

**Run:**
```bash
vendor/bin/phpunit tests/Unit/Services/Learning/ConfidenceCalculatorV2Test.php
```

---

**File:** `tests/Unit/DTO/SuggestionDTOTest.php`

**Tests:**
- ✅ Valid DTO creation
- ✅ Confidence validation (0-100)
- ✅ Level validation (B/C/D)
- ✅ Level-confidence consistency (B>=85, C=65-84, D<65)
- ✅ reason_ar non-empty validation
- ✅ toArray() method

**Run:**
```bash
vendor/bin/phpunit tests/Unit/DTO/SuggestionDTOTest.php
```

---

### 2. Integration Tests (Database Required)

**File:** `tests/Integration/Services/Learning/UnifiedLearningAuthorityIntegrationTest.php`

**Tests:**
- ✅ Basic suggestion retrieval
- ✅ Silence Rule (no signals → empty array)
- ✅ Confidence ordering (descending)
- ✅ Display threshold compliance
- ✅ SuggestionDTO format consistency
- ✅ Normalization consistency

**Run:**
```bash
vendor/bin/phpunit tests/Integration/Services/Learning/UnifiedLearningAuthorityIntegrationTest.php
```

---

### 3. Validation Scripts

**File:** `validate_authority.php`

**Checks:**
- File structure (18 files)
- Class definitions (7 classes)
- Documentation (8 docs)
- Charter violations (prohibited patterns)
- Compliance checklist (6 requirements)

**Run:**
```bash
php validate_authority.php
```

**Expected Output:**
```
========================================
Authority Validation Script
========================================

--- Checking File Structure ---
✅ app/Contracts/SignalFeederInterface.php
✅ app/DTO/SignalDTO.php
...

========================================
Validation Summary
========================================

Total Checks: 35
Errors: 0
Warnings: 0

✅ VALIDATION PASSED

Authority implementation is structurally sound.
Ready for manual testing and deployment.
```

---

## 🔧 Running Tests

### Prerequisites

```bash
# Install PHPUnit (if not already)
composer require --dev phpunit/phpunit

# Ensure autoloader is configured
composer dump-autoload
```

### Run All Tests

```bash
# All tests
vendor/bin/phpunit

# Only unit tests
vendor/bin/phpunit --testsuite Unit

# Only integration tests (requires DB)
vendor/bin/phpunit --testsuite Integration

# Only Learning Authority tests
vendor/bin/phpunit --testsuite "Learning Authority"
```

### With Coverage

```bash
vendor/bin/phpunit --coverage-html coverage
# Open coverage/index.html in browser
```

---

## ✅ Pre-Deployment Checklist

Before moving to production:

### Code Quality
- [ ] All unit tests pass
- [ ] All integration tests pass
- [ ] `validate_authority.php` shows 0 errors
- [ ] Code coverage >= 80%
- [ ] No PHPStan/Psalm errors (if configured)

### Manual Testing
- [ ] Run `test_authority.php` with real data
- [ ] Verify suggestions make sense
- [ ] Check Arabic reason_ar rendering
- [ ] Test with edge cases (empty input, very long input)

### Documentation
- [ ] All phase guides complete
- [ ] Charter reviewed and approved
- [ ] Team trained on Authority

### Infrastructure
- [ ] Database migrations ready (Phase 6)
- [ ] Monitoring configured
- [ ] Logging properly set up

---

## 🐛 Common Test Issues & Solutions

### Issue: Tests can't find classes

**Symptom:** `Class 'App\Services\Learning\...' not found`

**Solution:**
```bash
composer dump-autoload
```

---

### Issue: Integration tests fail with DB error

**Symptom:** `SQLSTATE[HY000]: General error`

**Solution:**
Ensure test database is configured in `phpunit.xml`:
```xml
<php>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
</php>
```

---

### Issue: Validation script shows missing files

**Symptom:** `❌ app/.../SomeFile.php - MISSING`

**Solution:**
Verify file exists at correct path. If it's a new file, ensure it was created.

---

## 📊 Test Coverage Goals

| Component | Target | Priority |
|-----------|--------|----------|
| ConfidenceCalculatorV2 | 100% | Critical |
| SuggestionDTO | 100% | Critical |
| UnifiedLearningAuthority | 90% | High |
| Signal Feeders | 80% | Medium |
| DualRun Components | 70% | Medium |
| Cutover Components | 60% | Low (manual testing) |

---

## 🔄 Continuous Testing (Phase 7)

After deployment:

**Weekly:**
- [ ] Run full test suite
- [ ] Check for regressions
- [ ] Monitor Authority errors in production

**Monthly:**
- [ ] Review test coverage
- [ ] Add tests for new edge cases discovered
- [ ] Update tests if Charter amended

**Quarterly:**
- [ ] Comprehensive integration test review
- [ ] Performance benchmark tests
- [ ] Load testing (if applicable)

---

## 📝 Adding New Tests

When adding features to Authority:

1. **Write test first** (TDD approach)
2. **Follow naming convention:** `test{FeatureName}`
3. **Document what it tests** (PHPDoc comment)
4. **Keep tests isolated** (no external dependencies in unit tests)
5. **Use descriptive assertions:**
   ```php
   // Bad
   $this->assertTrue($x);
   
   // Good
   $this->assertTrue(
       $x,
       'Expected suggestion to meet display threshold'
   );
   ```

---

## 🎯 Test Success Criteria

**Unit Tests:**
- ✅ All pass
- ✅ < 500ms total execution
- ✅ No database calls
- ✅ 100% coverage for critical components

**Integration Tests:**
- ✅ All pass
- ✅ < 5 seconds total execution
- ✅ Test against real database schema
- ✅ Cover happy path + edge cases

**Validation:**
- ✅ 0 errors
- ✅ < 10 warnings
- ✅ All Charter requirements met

---

**Status:** ✅ Test Suite Complete  
**Total Test Cases:** 20+  
**Coverage:** Ready to measure  
**Next Step:** Run tests and verify  

**Last Updated:** 2026-01-03
