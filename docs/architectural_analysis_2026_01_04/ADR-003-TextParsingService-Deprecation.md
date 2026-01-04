# ADR-003: TextParsingService Deprecation
## Architecture Decision Record  

> **Status**: ✅ Approved  
> **Date**: 2026-01-04  
> **Type**: Deprecation & Archival  
> **Related**: ADR-002 (ActionService Deprecation)

---

## Decision

**TextParsingService يُعتبر رسمياً Deprecated ويُؤرشف كإرث معماري.**

لن يُستخدم، لن يُحدّث، ولن يُدمج في أي execution flow حالي أو مستقبلي.

---

## Summary

**TextParsingService**: Legacy parsing service (377 lines, unused)  
**Current System**: Uses `parse-paste.php` (688 lines, active)

**Reason**: TextParsingService was superseded by enhanced inline logic in `parse-paste.php` with:
- Better multi-row table detection
- More patterns (20+ specific cases)
- Integration with SmartProcessingService
- Timeline recording
- Duplicate detection

**Action**: Archive + Delete (same as ActionService)

---

## 1. لماذا أُنشئ TextParsingService

### Original Purpose

Parse unstructured text (paste/copy) into structured guarantee data.

### Features Designed:

```php
TextParsingService
├─ parseBulk() - Multi-row detection
├─ parse() - Single record parsing
├─ Sequential Consumption Strategy:
│  ├─ extractAmount()
│  ├─ extractCurrency()
│  ├─ extractGuaranteeRef()
│  ├─ extractSupplier()
│  ├─ extractBank()
│  ├─ extractDate()
│  ├─ extractType()
│  └─ extractContractNumber()
└─ Bilingual (Arabic/English)
```

### Strategy:
**Sequential Consumption** - Match and "consume" (replace with spaces) to prevent re-matching.

---

## 2. لماذا تُرك ولم يعد مستخدمًا

### Current System: parse-paste.php

**File**: `api/parse-paste.php` (688 lines)

**Enhanced Features** (not in TextParsingService):

1. **Table Detection** (57-196):
   ```php
   parseTabularData($text)
   - Detects TAB-separated columns
   - Parses entire tables (multi-row)
   - Column type detection
   - Much more sophisticated than Service
   ```

2. **20+ Specific Patterns**:
   - Multiple contract patterns
   - Multiple supplier patterns
   - Bank acronyms + full names
   - Date formats (Arabic months)

3. **Integration**:
   ```php
   - GuaranteeRepository (create records)
   - TimelineRecorder (log import events)
   - SmartProcessingService (auto-match after import)
   - Duplicate detection (via DB)
   ```

4. **Logging**:
   ```php
   logPasteAttempt() - Debug logging
   Comprehensive error tracking
   ```

### Missing in TextParsingService:

| Feature | parse-paste.php | TextParsingService |
|---------|----------------|-------------------|
| **Table parsing** | ✅ Yes (TAB detection) | ❌ No |
| **Integration** | ✅ Full (Repo + Timeline + Smart) | ❌ Standalone |
| **Multi-row** | ✅ Advanced (20+ patterns) | ⚠️ Basic (simple bulk) |
| **Duplicate check** | ✅ Yes (DB query) | ❌ No |
| **Timeline** | ✅ Yes (recordImportEvent) | ❌ No |
| **Logging** | ✅ Comprehensive | ❌ None |
| **Auto-match** | ✅ Yes (SmartProcessing) | ❌ No |

---

## 3. لماذا لن نعود إليه

### Current parse-paste.php is Superior

**Reasons**:

1. **More Sophisticated**:
   - 688 lines vs 377 lines
   - TAB-separated table support
   - 20+ patterns vs 8 patterns

2. **Fully Integrated**:
   - Creates guarantees directly
   - Records timeline events
   - Triggers auto-matching
   - Checks duplicates

3. **Production-Tested**:
   - Used in actual workflow
   - Debugged and improved
   - Handles edge cases

4. **Sequential Consumption** already in parse-paste:
   - Same strategy as Service
   - But with better patterns

### Would Using Service Break Anything?

**YES** - Missing critical features:

❌ **No Timeline Recording**
- Events wouldn't be logged in `guarantee_history`
- Breaks audit trail

❌ **No Auto-Matching**
- SmartProcessingService wouldn't trigger
- UX degradation

❌ **No Table Detection**
- TAB-separated imports would fail
- Data loss

❌ **No Duplicate Check**
- Would create duplicate guarantees
- Data integrity issue

---

## 4. Usage Analysis

### Grep Results:

```
TextParsingService usage: ZERO
- No API calls it
- No Service uses it
- No Repository imports it
- No UI references it
```

**Status**: Orphaned code (created but never integrated)

---

## 5. Comparison Table

| Aspect | TextParsingService | parse-paste.php |
|--------|-------------------|-----------------|
| **Lines** | 377 | 688 |
| **Strategy** | Sequential Consumption | Sequential + TAB detection |
| **Multi-row** | Basic (by line) | Advanced (TAB columns) |
| **Patterns** | 8 extractors | 20+ specific patterns |
| **Integration** | ❌ None | ✅ Full (Repo+Timeline+Smart) |
| **Timeline** | ❌ No | ✅ Yes |
| **Auto-match** | ❌ No | ✅ Yes |
| **Duplicates** | ❌ No check | ✅ DB check |
| **Logging** | ❌ No | ✅ Comprehensive |
| **Usage** | ❌ ZERO | ✅ Active |

---

## 6. Archival Plan

### Files to Archive:

```
app/Services/TextParsingService.php (377 lines)
```

### Destination:

```
deprecated/text-parsing-service/
├─ TextParsingService.php
└─ README.md (explains why deprecated)
```

---

## 7. Decision

**Same as ActionService** (ADR-002):

✅ Archive code  
✅ Delete original  
✅ Document reason  
✅ Run tests after deletion  

**Reason**: Completely unused, superseded by better implementation

---

## References

- [ADR-002: ActionService Deprecation](./ADR-002-ActionService-Deprecation.md)
- [parse-paste.php](../../../api/parse-paste.php) - Current active implementation
- [TextParsingService.php](../../../app/Services/TextParsingService.php) - Deprecated

---

**Status**: ✅ **DEPRECATED - DO NOT USE**  
**Replacement**: Use `api/parse-paste.php` directly  
**Date**: 2026-01-04
