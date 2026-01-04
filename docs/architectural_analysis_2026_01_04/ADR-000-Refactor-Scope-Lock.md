# ADR-000: Refactor Scope Lock (No Implementation)

> **Status**: Approved for Diagnosis Phase  
> **Date**: 2026-01-04  
> **Purpose**: Lock refactoring scope boundaries BEFORE any implementation begins  
> **Critical**: This is a **DIAGNOSIS-ONLY** document. No code, no solutions, no implementation paths.

---

## 1. المشاكل المثبتة بالأدلة (Evidence-Based Issues)

### 1.1 God Objects - مُثبت بقياسات فعلية

| الملف | الحجم | عدد الأسطر | Complexity | الدليل |
|------|------|-----------|------------|--------|
| `index.php` | 94KB | 2551 | ~50 | [index_php_analysis.md](./index_php_analysis.md) |
| `records.controller.js` | 41KB | 918 | ~60 | [repositories_js_analysis.md](./repositories_js_analysis.md) |
| `parse-paste.php` (API) | 31KB | 688 | ~60 | [api_inventory.md](./api_inventory.md) |
| `TimelineRecorder.php` | 25KB | 631 | ~45 | [services_analysis.md](./services_analysis.md) |
| `SmartProcessingService.php` | 21KB | 477 | ~50 | [services_analysis.md](./services_analysis.md) |
| `ImportService.php` | 19KB | 479 | ~35 | [services_analysis.md](./services_analysis.md) |

**Total**: 6 files = 265KB (43% من الكود)

---

### 1.2 Code Duplication - مُثبت بـ Usage Proof

#### أ. API Endpoints Duplication

| الزوج | الملف الأول | الملف الثاني | Usage Context | الدليل |
|------|------------|-------------|---------------|--------|
| Supplier Creation | `create-supplier.php` | `create_supplier.php` | Main UI vs Settings | [deep_analysis_duplicates.md](./deep_analysis_duplicates.md) |
| Bank Creation | `add-bank.php` | `create_bank.php` | Modal vs Settings | [deep_analysis_duplicates.md](./deep_analysis_duplicates.md) |

**Findings**:
- `create-supplier.php`: مستدعى من `records.controller.js:789` (واجهة السجلات)
- `create_supplier.php`: مستدعى من `views/settings.php:474` (صفحة الإعدادات)
- `add-bank.php`: مستدعى من `partials/add-bank-modal.php:273` (المودال)
- `create_bank.php`: مستدعى من `views/settings.php:455` (صفحة الإعدادات)

**Critical**: كل زوج له **contexts مختلفة** - ليس duplication بسيط!

#### ب. Business Logic Duplication

| الوظيفة | Implementation 1 | Implementation 2 | الدليل |
|---------|-----------------|------------------|--------|
| Text Parsing | `TextParsingService.php` (15KB، **غير مستخدم**) | `api/parse-paste.php` (inline logic) | [services_analysis.md](./services_analysis.md) |
| Action Logic | `ActionService.php` (6KB، **غير مستخدم**) | `api/extend.php`, `reduce.php`, `release.php` | [services_analysis.md](./services_analysis.md) |
| Column Detection | `ExcelColumnDetector.php` (**غير مستخدم**) | `ImportService::detectColumns()` (120 lines) | [services_analysis.md](./services_analysis.md) |

**Total Duplication**: ~1500 LOC (25% من الكود)

---

### 1.3 index.php Forensics - مُثبت بتحليل معمق

| المكون | الحجم/العدد | المشكلة | الدليل |
|--------|-----------|---------|--------|
| **Inline CSS** | ~800 lines | Mixed concerns | [index_php_analysis.md](./index_php_analysis.md) Section 3.2 |
| **Inline JavaScript** | ~400 lines | Mixed concerns | [index_php_analysis.md](./index_php_analysis.md) Section 3.3 |
| **Database Queries** | 31 queries | Direct DB access in view | [index_php_analysis.md](./index_php_analysis.md) Section 4.1 |
| **N+1 Queries** | Timeline loop | Performance issue | [index_php_analysis.md](./index_php_analysis.md) Lines 2298-2350 |
| **Dependencies** | 16 require/include | Tight coupling | [index_php_analysis.md](./index_php_analysis.md) Section 2.1 |

---

### 1.4 Naming Inconsistency - مُثبت بالجرد

| Pattern | Count | Examples | الدليل |
|---------|-------|----------|--------|
| **kebab-case** | 13 APIs | `create-supplier.php`, `save-and-next.php` | [api_inventory.md](./api_inventory.md) |
| **snake_case** | 13 APIs | `create_supplier.php`, `get_banks.php` | [api_inventory.md](./api_inventory.md) |
| **Mixed** | 7 APIs | لا نمط واضح | [api_inventory.md](./api_inventory.md) |

---

### 1.5 Security - Context Required

**Finding**: لا يوجد Authentication/Authorization في 33 API endpoint

**Source**: [api_inventory.md](./api_inventory.md), [executive_summary.md](./executive_summary.md)

**⚠️ Requires Context Classification**:
- [ ] **High Risk**: System exposed to public/wide network
- [ ] **Medium Risk**: Internal network with untrusted users
- [ ] **Low Risk**: Localhost only / closed environment / behind proxy

**Action Required**: تصنيف سطح التعرض قبل رفعها لأولوية "CRITICAL"

---

## 2. حدود المرحلة القادمة (Scope Lock)

### 2.1 ما هو **مسموح** في Phase Refactor

#### ✅ Structural Changes (بدون تغيير سلوك)

1. **Extract CSS/JS من index.php**
   - Move CSS → `public/css/index.css`
   - Move JS → `public/js/index-*.js`
   - **Constraint**: نفس الـ selectors، نفس السلوك بالضبط

2. **Split God Files إلى Modules**
   - `index.php` → Controllers/Views separation
   - `records.controller.js` → Multiple modules
   - **Constraint**: نفس الـ public interface

3. **Use Existing Unused Services**
   - `ActionService` بدلاً من logic في `extend/reduce/release.php`
   - `TextParsingService` بدلاً من inline في `parse-paste.php`
   - **Constraint**: نفس الـ API response format

#### ✅ Code Quality (بدون breaking changes)

1. **Add PHPDoc/JSDoc comments**
2. **Extract magic numbers to constants**
3. **Rename variables (داخلياً فقط)**

---

### 2.2 ما هو **ممنوع** المساس به

#### ❌ Critical System Components

1. **Learning System** (كاملاً)
   - `app/Services/Learning/**` (15 files)
   - `LearningRepository`
   - Learning tables
   - **Reason**: Enterprise-grade, working, complex

2. **Timeline/History System**
   - `TimelineRecorder` (رغم أنه God Object)
   - `timeline_events` table
   - Snapshot mechanism
   - **Reason**: Audit trail - critical for compliance

3. **Lock/Action State Logic**
   - `active_action` في `guarantee_decisions`
   - ADR-007 logic
   - **Reason**: Recently stabilized, tested

4. **Database Schema**
   - لا تغيير في Tables/Columns
   - لا migrations
   - **Reason**: Data integrity risk

---

### 2.3 ما يحتاج **قرار إداري** قبل المساس به

#### ⚠️ Requires ADR

1. **Merge Duplicate APIs**
   - `create-supplier` vs `create_supplier`
   - `add-bank` vs `create_bank`
   - **Reason**: Different usage contexts - needs impact analysis

2. **Change API Response Formats**
   - أي تعديل على JSON structure
   - **Reason**: Frontend contracts

3. **Rename Routes**
   - أي تغيير في URL paths
   - **Reason**: Breaking change for any external integrations

---

## 3. تعريف "نجاح إعادة الهيكلة" (Success Criteria)

### 3.1 مقاييس قابلة للفحص (Measurable)

| المقياس | الحالة الحالية | الهدف | كيفية القياس |
|---------|----------------|-------|--------------|
| **God Files Count** | 6 files > 20KB | ≤ 2 files > 20KB | `find . -name "*.php" -o -name "*.js" \| xargs wc -c \| awk '$1 > 20000'` |
| **Inline CSS in PHP** | 800+ lines | 0 lines | `grep -c "<style>" index.php` |
| **Inline JS in PHP** | 400+ lines | 0 lines | `grep -c "<script>" index.php` |
| **API Naming Consistency** | 2 patterns | 1 pattern (kebab-case) | Manual review |
| **Unused Services** | 2 (TextParsing, Action) | 0 | `grep -r "new TextParsingService" api/` |

### 3.2 مقاييس غير قابلة للكسر (Non-Breaking)

| المقياس | كيفية التحقق |
|---------|--------------|
| **All Tests Pass** | `php vendor/bin/phpunit` (إذا وُجدت اختبارات) |
| **No JavaScript Errors** | فتح index.php في المتصفح + فحص Console |
| **API Contracts Intact** | مقارنة Response samples قبل/بعد |
| **Database Queries عدد** | يجب ألا يزيد (تحسين أو ثابت فقط) |

---

## 4. قائمة Contracts التي لا يجوز كسرها

### 4.1 API Response Formats (مُثبتة بالكود)

#### أ. `save-and-next.php`

**Current Contract** (من api_inventory.md):
```json
{
  "success": true,
  "finished": false,
  "record": { 
    "id": 123, 
    "guarantee_number": "...",
    "supplier_name": "...",
    "bank_name": "...",
    "status": "..."
  },
  "banks": [...],
  "currentIndex": 2,
  "totalRecords": 100
}
```

**Used By**: `records.controller.js:415-449`

**Constraint**: لا تغيير في structure أو field names

---

#### ب. `parse-paste.php`

**Current Contract** (من api_inventory.md):
```json
{
  "success": true,
  "id": 456,
  "extracted": {
    "guarantee_number": "...",
    "supplier": "...",
    "bank": "...",
    "amount": 100000,
    "expiry_date": "2026-12-31"
  },
  "exists_before": false
}
```

**Used By**: `input-modals.controller.js:116-251`

**Constraint**: لا تغيير في extraction fields

---

#### ج. Supplier/Bank CRUD APIs

**Current Contract**:
```json
{
  "success": true,
  "id": 789,
  "message": "تم الإضافة بنجاح"
}
```

**Used By**: 
- `settings.php:455` (create_bank)
- `settings.php:474` (create_supplier)
- `records.controller.js:789` (create-supplier)

**Constraint**: نفس الـ response structure

---

### 4.2 JavaScript Public APIs (مُثبتة بالـ Usage)

#### `RecordsController` Methods

**Contract** (من repositories_js_analysis.md):
```javascript
class RecordsController {
  // Public methods - لا يجوز تغيير signatures
  saveAndNext()
  extend()
  reduce()
  release()
  selectSupplier(target)
  loadRecord(index)
}
```

**Used By**: `index.php` event handlers (onclick bindings)

**Constraint**: Method names و parameters يجب أن تبقى

---

#### `InputModalsController` Methods

**Contract**:
```javascript
// Global functions
showManualInput()
showPasteModal()
showImportModal()
```

**Used By**: `index.php` buttons

**Constraint**: Function names يجب أنتبقى global

---

### 4.3 Database Query Behaviors (مُثبتة بـ index_php_analysis)

#### Timeline Rendering

**Current Behavior** (Lines 2298-2350 في index.php):
- Fetches timeline events
- Loops through events
- For each event: fetches bank_name, supplier_name, user_name
- **Problem**: N+1 queries

**Constraint**: 
- ✅ مسموح: Fix N+1 بـ JOIN
- ❌ ممنوع: تغيير HTML output structure
- ❌ ممنوع: تغيير event display order

---

#### Guarantee Fetching

**Current Behavior**:
- Single record by ID
- Includes decision if exists
- Includes timeline

**Constraint**: نفس البيانات المُرجعة (يمكن تحسين الـ query)

---

### 4.4 Server-Driven UI Contracts

**Pattern** (من api_inventory.md):
```
API returns HTML fragments
Frontend injects via innerHTML
```

**Examples**:
- `get-record.php` → returns `<div id="record-form-section">...</div>`
- `suggestions-learning.php` → returns supplier chips HTML

**Constraint**: 
- ✅ مسموح: تحسين HTML داخلياً
- ❌ ممنوع: تغيير wrapper IDs/classes
- ❌ ممنوع: تغيير من HTML إلى JSON

---

## 5. المخاطر التي تمنع الانزلاق للتنفيذ

### 5.1 خطر: "دمج endpoints بدون فهم الفروقات"

**السيناريو**:
```
مبرمج يرى: create-supplier.php + create_supplier.php
يقرر: أدمجهم في api/suppliers/create.php
النتيجة: كسر واجهة الإعدادات أو المودال
```

**الوقاية**:
- ✅ يجب إثبات أن **كل caller** تم اختباره بعد الدمج
- ✅ يجب كتابة ADR منفصل قبل أي دمج

---

### 5.2 خطر: "تغيير Response Format بحجة التحسين"

**السيناريو**:
```
مبرمج يرى: save-and-next.php يرجع "finished": false
يقرر: أحسنه لـ "hasMore": true (أوضح)
النتيجة: records.controller.js يتعطل
```

**الوقاية**:
- ✅ لا تغيير في field names بدون ADR
- ✅ إذا لا بد: إضافة field جديد + keep old deprecated

---

### 5.3 خطر: "Extract logic بدون فهم Side Effects"

**السيناريو**:
```
مبرمج ينقل منطق من save-and-next.php إلى Service
لكنه ينسى: Learning feedback loop
النتيجة: توقف تعلم النظام
```

**الوقاية**:
- ✅ يجب توثيق **كل** side effect قبل Extract
- ✅ يجب اختبار Learning بعد أي تعديل

---

## 6. الخلاصة: ما هو Locked

### ✅ Locked for Diagnosis Phase (COMPLETE)

1. **المشاكل المثبتة**: 6 God Objects، 25% duplication، Naming chaos
2. **التقارير**: 10 ملفات في `docs/architectural_analysis_2026_01_04/`
3. **القياسات**: Sizes، LOC، Complexity، Usage proof

### ⚠️ Locked for Refactor Phase (MUST RESPECT)

1. **Contracts**: API responses، JS methods، HTML structures
2. **Forbidden Areas**: Learning، Timeline، Lock logic، Database schema
3. **Success Criteria**: Measurable، Non-breaking
4. **ADR Required**: Merge APIs، Change formats، Rename routes

### 🚫 Locked Against Implementation (NO CODE YET)

- ❌ لا كود مقترح في هذا الـ ADR
- ❌ لا مسارات ملفات جديدة
- ❌ لا refactoring steps

**Next Step**: 
- إنشاء ADRs منفصلة لكل تغيير مقترح
- كل ADR يحتاج: Decision، Context، Consequences، Testing plan

---

**Status**: ✅ **LOCKED**  
**Date**: 2026-01-04  
**Signed Off By**: Architectural Analysis Team
