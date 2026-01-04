# TextParsingService - Deprecated Code Archive

> **Status**: ❌ DEPRECATED  
> **Date Archived**: 2026-01-04  
> **Reason**: Completely unused - superseded by parse-paste.php

---

## ⚠️ WARNING

**DO NOT USE THIS CODE**

This code is archived for historical reference only.

---

## Why This Code Was Deprecated

### Original Purpose
Parse unstructured text (paste) into structured guarantee data using "Sequential Consumption" strategy.

### Why Deprecated

**Superseded by**: `api/parse-paste.php` (688 lines, fully integrated)

**Reasons**:
1. ❌ **Never integrated** - Created but never used
2. ❌ **Missing features** compared to parse-paste.php:
   - No TAB-separated table detection
   - No Timeline recording
   - No SmartProcessing integration
   - No duplicate checking
   - No comprehensive logging
3. ❌ **Less sophisticated** - 377 lines vs 688 lines
4. ❌ **Zero usage** - Grep confirmed no references

---

## Current Solution

**Use**: `api/parse-paste.php`

**Features**:
- ✅ Advanced table parsing (TAB detection)
- ✅ 20+ specific patterns
- ✅ Full integration (Repo + Timeline + SmartProcessing)
- ✅ Duplicate detection
- ✅ Comprehensive logging
- ✅ Multi-row support
- ✅ Arabic/English bilingual

---

## Files Archived

- `TextParsingService.php` - Main service class (377 lines)

---

## References

- [ADR-003: TextParsingService Deprecation](../../docs/architectural_analysis_2026_01_04/ADR-003-TextParsingService-Deprecation.md)
- [parse-paste.php](../../api/parse-paste.php) - Current active implementation

---

**Last Updated**: 2026-01-04  
**Status**: Permanently Archived
