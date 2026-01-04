# Phase Contract: Learning Merge

## 🔒 Binding Agreement

**النظام**: BGL3  
**المرحلة**: Learning Merge  
**الحالة**: ملزمة (Binding Contract)  
**التاريخ**: 2026-01-03  
**الغاية**: دمج أنظمة التعلم الخمسة بدون فقدان أي سلوك أو خاصية قائمة

---

## 1️⃣ نطاق المرحلة (Scope)

### ✅ المشمول في هذه المرحلة:

- دمج أنظمة التعلم الخمسة
- إزالة الازدواجية التقنية
- تثبيت بديل آمن للاستعلامات الهشة (JSON LIKE queries)
- توحيد نقاط الكتابة (write points)
- تحسين الأداء عبر indexes وstructured columns

### ❌ خارج نطاق المرحلة:

- ❌ تحسين UX
- ❌ تغيير سلوك المستخدم
- ❌ إعادة تفسير المنطق
- ❌ "تنظيف" بدون توثيق
- ❌ تغيير thresholds أو weights
- ❌ إضافة features جديدة

---

## 2️⃣ الأنظمة المشمولة (Non-Negotiable)

**يجب الحفاظ على جميع الأنظمة التالية**:

| # | النظام | الحالة | متطلب الحفاظ |
|---|--------|--------|---------------|
| 1 | Explicit Confirm / Reject | نشط | ✅ إلزامي |
| 2 | Implicit Rejection | نشط | ✅ إلزامي |
| 3 | Historical Selections | نشط | ✅ إلزامي |
| 4 | Alternative Names (Aliases) | نشط | ✅ إلزامي |
| 5 | Fuzzy Matching | نشط | ✅ إلزامي |
| 6 | Entity Anchors | نشط | ✅ إلزامي |

### Methods الكامنة (Dormant):

**يجب الحفاظ عليها**:
- `learnAlias()` - إنشاء alias جديد
- `incrementUsage()` - positive learning
- `decrementUsage()` - negative learning

**المتطلب**: 
- تصميم الدمج يسمح بتفعيلها لاحقاً **بدون إعادة هندسة**
- ❌ لا حذف
- ❌ لا تعليق بأنها "legacy" بدون توثيق واضح

**⚠️ أي دمج يُسقط أحد هذه الأنظمة يُعتبر فشلاً في المرحلة.**

---

## 3️⃣ قرارات ملزمة (Binding Decisions)

### القرار #1: الرفض الضمني (Implicit Rejection)

**الحكم**: ✅ **معتمد**

**الشروط**:
- يُحفظ كما هو (save-and-next.php:283-303)
- **بعقوبة نسبية مخففة** (أقل تأثيراً من الرفض الصريح)
- لا يجوز إلغاؤه
- لا يجوز مساواته بالرفض الصريح (rejection weight ≠ explicit rejection weight)

**الأساس**: 
```
Implicit rejection strength = min(1.0, count / 5)
Explicit confirmation strength = min(1.0, count / 10)
→ Implicit accumulates faster BUT has lower trust
```

**الالتزام**: أي تغيير في هذا المنطق **يتطلب موافقة صريحة**.

---

### القرار #2: ازدواجية التعزيز (Dual Reinforcement)

**الحكم**: ✅ **مقصودة ومعتمدة**

**الواقع**:
- **System #1** (Explicit Learning) يسجل confirmations
- **System #3** (Historical) يعد selections

**النتيجة**: نفس المورد يُعزز من **مصدرين مختلفين**

**التفسير المعتمد**:
- System #1 → **تعلم سريع** (behavioral, explicit user feedback)
- System #3 → **تعلم طويل المدى** (pattern recognition, all decisions)

**الالتزام**:
- ❌ **لا توحيد نية** (no intent merging)
- ❌ **لا حذف أحد المصدرين**
- ✅ **يجوز** توحيد technical implementation (نفس الجدول) **مع الحفاظ على dual signals**

**مثال مقبول**:
```sql
-- نفس الجدول، لكن مصدرين مختلفين
INSERT INTO learning_events (source, type, ...)
VALUES ('explicit', 'confirm', ...);

INSERT INTO learning_events (source, type, ...)
VALUES ('historical', 'selection', ...);
```

---

### القرار #3: Methods غير المستعملة (Dormant Methods)

**الحكم**: ✅ **جزء من المواصفات**

**Methods المقصودة**:
- `SupplierLearningRepository::learnAlias()`
- `SupplierLearningRepository::incrementUsage()`
- `SupplierLearningRepository::decrementUsage()`

**الالتزام**:
- تُحفظ كما هي
- يُراعى تصميم الدمج بحيث يسمح بتفعيلها **بدون إعادة هندسة**
- إذا تم دمج repositories، methods يجب أن **تبقى موجودة وقابلة للاستدعاء**

**مثال مقبول**:
```php
class UnifiedLearningRepository {
    // Methods موجودة، لكن غير مستدعاة (OK)
    public function learnAlias(...) { ... }
    public function incrementUsage(...) { ... }
    public function decrementUsage(...) { ... }
}
```

**غير مقبول**:
```php
// ❌ حذف Methods
// ❌ تعليقها بـ // DEPRECATED - legacy
```

---

### القرار #4: استعلامات JSON الهشة (Fragile JSON Queries)

**الحكم**: 🔴 **غير مقبولة - يجب إصلاحها**

**المشكلة الحالية**:
```sql
-- ❌ هش وبطيء
WHERE raw_data LIKE '%"supplier":"name"%'
```

**البديل الإلزامي**:
```sql
-- ✅ آمن وسريع
ALTER TABLE guarantees ADD COLUMN normalized_supplier_name TEXT;
CREATE INDEX idx_guarantees_normalized_supplier ON guarantees(normalized_supplier_name);

WHERE normalized_supplier_name = ?
```

**الالتزام**:
- **يجب** استبدال JSON LIKE queries **ضمن هذه المرحلة**
- Migration script يملأ العمود الجديد من raw_data
- أي دمج لا يعالج هذا البند = **دمج ناقص**

**الاستثناء المقبول الوحيد**:
- إذا schema change غير ممكن → use JSON_EXTRACT() في SQLite 3.38+
- **لكن**: structured column هو الحل المفضل

---

### القرار #5: تغيير اسم البنك (Bank Name Mutation)

**الحكم**: ✅ **مقبول ولا يحتاج تغيير**

**السلوك الحالي**:
```php
// SmartProcessingService::updateBankNameInRawData()
$rawData['bank'] = $matchedOfficialBankName;
$this->guaranteeRepo->updateRawData($guaranteeId, $rawData);
```

**التبرير**:
- Timeline snapshot يحفظ الاسم الأصلي قبل التحديث
- التطبيع ضروري للاتساق
- الاسم الأصلي **غير ضروري** للعمليات المستقبلية

**الالتزام**:
- لا حاجة للحفاظ على الاسم الأصلي داخل `raw_data`
- Timeline snapshot **كافٍ** للأثر التاريخي
- السلوك الحالي **يُحفظ كما هو**

---

## 4️⃣ قيود التنفيذ (Hard Constraints)

### أثناء Phase: Learning Merge:

| القيد | الوصف | العقوبة |
|-------|-------|---------|
| ❌ No Behavior Change | لا تغيير في سلوك المستخدم | Merge Failure |
| ❌ No Re-ordering | لا تعديل ترتيب الاقتراحات النهائي | Merge Failure |
| ❌ No Threshold Changes | لا تعديل thresholds بدون توثيق | Requires Approval |
| ❌ No Schema Drops | لا إزالة جدول/حقل بدون mapping واضح | Merge Failure |
| ❌ No Concept Renaming | لا إعادة تسمية مفاهيم دون Canonical Mapping | Merge Failure |

### تفاصيل القيود:

#### No Behavior Change
**المعنى**: 
- نفس المدخلات → نفس النتائج
- نفس اختيار المستخدم → نفس التأثير على التعلم

**الاختبار**:
```
Before merge: input "شركة النورس" → suggestions [A, B, C]
After merge:  input "شركة النورس" → suggestions [A, B, C] (same order, same confidence)
```

#### No Re-ordering
**المعنى**: 
- ترتيب الاقتراحات يعتمد على نفس المنطق
- أي تغيير في ranking algorithm **ممنوع**

#### No Threshold Changes
**المعنى**:
- `MATCH_REVIEW_THRESHOLD = 0.70` → **ثابت**
- `LEARNING_SCORE_CAP = 0.90` → **ثابت**
- أي تغيير يتطلب **توثيق صريح في Phase Contract Amendment**

#### No Schema Drops
**المعنى**:
- قبل حذف جدول: يجب mapping واضح (أين ذهبت البيانات؟)
- قبل حذف عمود: يجب دليل (ما هو البديل؟)

**مثال مقبول**:
```
DROP TABLE: learning_confirmations
→ Mapping: data migrated to unified_learning_events
→ Migration script: 2026_01_03_unify_learning_tables.sql
```

#### No Concept Renaming
**المعنى**:
- "confirmation" لا يصبح "approval"
- "rejection" لا يصبح "denial"
- إذا تمت إعادة تسمية → Canonical Mapping **إلزامي**

**مثال Canonical Mapping**:
```markdown
## Concept Mapping

| Old Concept | New Concept | Rationale |
|-------------|-------------|-----------|
| confirmation | positive_signal | Unified terminology |
| rejection | negative_signal | Unified terminology |
```

---

## 5️⃣ مخرجات إلزامية (Mandatory Deliverables)

**لا تُعتبر المرحلة مكتملة بدون**:

### Deliverable #1: Learning Canonical Model

**الوصف**: وثيقة توضح كل Signal → تأثيره → مصدره

**الشكل المطلوب**:
```markdown
## Signal Canonical Model

| Signal Type | Source System | Strength Calculation | Weight in Confidence | Table/Column |
|-------------|---------------|----------------------|----------------------|--------------|
| alias_exact | System #2 | 1.0 (always) | High | supplier_alternative_names |
| learning_confirmation | System #1 | min(1.0, count/10) | Medium | learning_confirmations.action='confirm' |
| learning_rejection | System #1 | min(1.0, count/5) | Medium (penalty) | learning_confirmations.action='reject' |
| historical_frequent | System #3 | log-scale | Low | guarantee_decisions.supplier_id |
| fuzzy_official_strong | System #4 | similarity >= 0.85 | Medium | computed |
| entity_anchor_unique | System #5 | frequency-based | Medium | computed |
```

**الملف**: `learning_canonical_model.md`

---

### Deliverable #2: Backward Compatibility Map

**الوصف**: إثبات أن كل سلوك سابق ما زال يعمل

**الشكل المطلوب**:
```markdown
## Backward Compatibility Map

### Test Case #1: Explicit Confirmation
**Before**:
- User confirms Supplier A for "شركة النورس"
- Written to: learning_confirmations (action='confirm')
- Read from: LearningRepository::getUserFeedback()

**After**:
- User confirms Supplier A for "شركة النورس"
- Written to: [NEW TABLE/COLUMN]
- Read from: [NEW METHOD]
- **Result**: Same signal strength, same confidence boost

**Status**: ✅ Compatible

### Test Case #2: Implicit Rejection
...
```

**الملف**: `backward_compatibility_map.md`

---

### Deliverable #3: Diff Report (Before / After)

**الوصف**: ما تغير تقنياً، وما لم يتغير سلوكياً

**الشكل المطلوب**:
```markdown
## Technical Changes

### Schema Changes
- ✅ Added: guarantees.normalized_supplier_name
- ✅ Added: index idx_guarantees_normalized_supplier
- ⚠️ Dropped: supplier_learning_cache (was unused)
- ❌ No change to: learning_confirmations structure

### Code Changes
- ✅ Refactored: LearningRepository + SupplierLearningRepository → UnifiedLearningRepository
- ✅ Updated: JSON LIKE queries → structured column queries
- ❌ No change to: UnifiedLearningAuthority signal aggregation logic

## Behavioral Invariants

### Invariant #1: Same Input → Same Output
- Tested with 100 real supplier names
- **Result**: 100% match in suggestion order and confidence

### Invariant #2: Same User Action → Same Learning Effect
- Tested confirmation, rejection, implicit rejection
- **Result**: Same signal strength in all cases
```

**الملف**: `merge_diff_report.md`

---

### Deliverable #4: Risk Acknowledgment Section

**الوصف**: أي مخاطرة متبقية ولماذا قُبلت

**الشكل المطلوب**:
```markdown
## Residual Risks

### Risk #1: Migration Data Loss
**Description**: During schema migration, if process fails mid-way
**Mitigation**: Backup before migration, transaction wrapping
**Acceptance**: Low probability, high impact → **Accepted with backup strategy**

### Risk #2: Performance Regression
**Description**: New queries may be slower than expected
**Mitigation**: Indexes added, EXPLAIN QUERY PLAN verified
**Acceptance**: Tested with 10K records, < 50ms → **Accepted**

### Risk #3: Dormant Methods Activation
**Description**: If learnAlias() is activated later, may need adjustments
**Mitigation**: Methods preserved, unit tests exist
**Acceptance**: Future work, not blocking merge → **Accepted**
```

**الملف**: `risk_acknowledgment.md`

---

## 6️⃣ معيار النجاح (Success Criteria)

**تُعتبر Phase: Learning Merge ناجحة فقط إذا**:

### ✅ Criteria Checklist:

| Criterion | Test Method | Status |
|-----------|-------------|--------|
| ✔️ لم نفقد أي سلوك تعلم | Backward Compatibility Map | ⏳ Pending |
| ✔️ لم يتغير قرار المستخدم النهائي | A/B test (before/after) | ⏳ Pending |
| ✔️ لم تتغير نتائج الاقتراحات | Input/Output regression test | ⏳ Pending |
| ✔️ أُزيل الاعتماد على JSON LIKE | Code audit (no LIKE '%"key"%') | ⏳ Pending |
| ✔️ الصورة المعمارية أبسط | Architecture diagram comparison | ⏳ Pending |

### تفاصيل الاختبارات:

#### Test #1: Behavioral Regression Test
```php
// Capture before state
$beforeSuggestions = captureSuggestions('شركة النورس');

// Run merge
// ...

// Capture after state
$afterSuggestions = captureSuggestions('شركة النورس');

// Assert
assertEquals($beforeSuggestions, $afterSuggestions);
```

#### Test #2: Learning Effect Test
```php
// Before merge: confirm Supplier A
confirmSupplier('شركة النورس', supplierA);
$beforeBoost = getConfidence('شركة النورس', supplierA);

// After merge: confirm Supplier B (different guarantee)
confirmSupplier('شركة الصقر', supplierB);
$afterBoost = getConfidence('شركة الصقر', supplierB);

// Assert: Same boost mechanism
assertEquals($beforeBoost, $afterBoost, delta: 0.01);
```

#### Test #3: No JSON LIKE Queries
```bash
# Audit code
grep -r "LIKE '%\"" app/ api/
# Expected: 0 results
```

---

## 🔒 التوقيع المفاهيمي (Conceptual Signature)

**هذه الوثيقة**:
- ✅ **ليست** اقتراحاً
- ✅ **ليست** توجيهاً عاماً
- ✅ **بل** عقد تنفيذي ملزم

**أي خروج عنها يتطلب**:
1. **توثيق صريح** في Phase Contract Amendment
2. **موافقة مسبقة** before code changes

---

## 📋 Compliance Checklist

قبل اعتبار الدمج **مكتملاً**، يجب:

- [ ] ✅ جميع الأنظمة الخمسة محفوظة
- [ ] ✅ Methods الكامنة محفوظة وقابلة للتفعيل
- [ ] ✅ Implicit rejection محفوظ بعقوبة مخففة
- [ ] ✅ Intent duplication محفوظ (dual signals)
- [ ] ✅ JSON LIKE queries مستبدلة
- [ ] ✅ Learning Canonical Model موثق
- [ ] ✅ Backward Compatibility Map مُثبت
- [ ] ✅ Diff Report مكتوب
- [ ] ✅ Risk Acknowledgment موثق
- [ ] ✅ Regression tests تمر بنجاح

---

**تاريخ التوقيع**: 2026-01-03  
**الحالة**: 🔒 **Binding and Active**

*End of Phase Contract*
