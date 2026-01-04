# Learning Systems Inventory

## التقرير: حصر أنظمة التعلم الموجودة فعلياً

**التاريخ**: 2026-01-03  
**الحالة**: تحليل واقعي بدون اقتراحات  
**الهدف**: توث يق جميع أنظمة التعلم الخمسة قبل مرحلة الدمج

---

## ⚠️ تنبيه مهم

هذا التقرير يوثق **الواقع الموجود حالياً** في النظام، ليس ما يجب أن يكون.  
أي نظام تعلم يؤثر على الاقتراحات أو الثقة أو الترتيب **مُدرج هنا** حتى لو لم يُسمَّ "learning".

---

## النظام #1: Explicit Confirmations & Rejections
### التعلم الصريح (confirm / reject)

**الوصف**: نظام يسجل قرارات المستخدم الصريحة عند الموافقة أو رفض مورد مقترح.

### مصادر البيانات

| المصدر | النوع | الموقع |
|--------|------|--------|
| `learning_confirmations` | جدول قاعدة بيانات | `storage/database/app.sqlite` |
| `LearningRepository` | Repository | `app/Repositories/LearningRepository.php` |
| `LearningSignalFeeder` | Signal Feeder | `app/Services/Learning/Feeders/LearningSignalFeeder.php` |

### التسجيل (Write Operations)

**متى يُسجَّل؟**

1. **Confirm** (تأكيد):
   - **الموقع**: `api/save-and-next.php:273-281`
   - **المحفز**: المستخدم يختار مورداً
   - **الحقول**:
     - `raw_supplier_name`: الاسم من raw_data
     - `supplier_id`: المورد المختار
     - `action`: 'confirm'
     - `confidence`: ثقة الاقتراح الأصلي
     - `guarantee_id`: رقم الضمان

2. **Reject** (رفض):
   - **الموقع**: `api/save-and-next.php:283-303`
   - **المحفز**: المستخدم يختار مورداً **مختلفاً** عن الاقتراح الأول
   - **السلوك**: **implicit rejection** (رفض ضمني)
   - **الحقول**: نفس الحقول لكن `action='reject'` و `supplier_id` هو الاقتراح المرفوض

### القراءة (Read Operations)

**الموقع**: `LearningRepository::getUserFeedback()`  
**الاستدعاء من**: `LearningSignalFeeder::getSignals()`

**الاستعلام**:
```php
SELECT supplier_id, action, COUNT(*) as count
FROM learning_confirmations
WHERE raw_supplier_name = ?
GROUP BY supplier_id, action
```

**الناتج**: يتحول إلى إشارات (Signals):
- `learning_confirmation` signal → قوة = count / 10
- `learning_rejection` signal → قوة = count / 5

### التأثير على الاقتراحات

- يُجمَّع في `UnifiedLearningAuthority`
- يُستخدم في حساب confidence عبر `ConfidenceCalculatorV2`
- **لا يُرشح** بشكل مباشر (Authority تقرر)

### الملاحظات الحرجة

- ⚠️ **fragmentation**: يسجل `raw_supplier_name` (الاسم الأصلي) وليس normalized
- ⚠️ **TODO Phase 6**: تحديث للاعتماد على normalized_supplier_name
- ✅ **implicit rejection** **مُفعّل ويعمل** (save-and-next.php:283-303)

---

## النظام #2: Alternative Names System (Aliases)
### نظام الأسماء البديلة

**الوصف**: نظام يتعلم الأسماء البديلة للموردين (aliases) ويستخدمها في المطابقة الدقيقة.

### مصادر البيانات

| المصدر | النوع | الموقع |
|--------|------|--------|
| `supplier_alternative_names` | جدول قاعدة بيانات | تخزين الأسماء البديلة |
| `SupplierAlternativeNameRepository` | Repository | `app/Repositories/SupplierAlternativeNameRepository.php` |
| `SupplierLearningRepository` | Repository (ثانوي) | `app/Repositories/SupplierLearningRepository.php` |
| `AliasSignalFeeder` | Signal Feeder | `app/Services/Learning/Feeders/AliasSignalFeeder.php` |

### الحقول الأساسية

```sql
supplier_alternative_names (
    id,
    supplier_id,           -- المورد المرتبط
    alternative_name,      -- الاسم البديل الأصلي
    normalized_name,       -- الاسم البديل بعد التطبيع
    source,                -- 'learning' | 'manual' | 'import'
    usage_count,           -- عدد مرات الاستخدام (positive learning)
    created_at
)
```

### التسجيل (Write Operations)

**1. إنشاء Alias جديد**:
- **الموقع**: `SupplierLearningRepository::learnAlias()`
- **المحفز**: غير مستدعى مباشرة في الكود المفحوص
- **الوظيفة**: يضيف اسم بديل جديد بـ `source='learning'` و `usage_count=1`

**2. زيادة usage_count**:
- **الموقع**: `SupplierLearningRepository::incrementUsage()`
- **المحفز**: غير مستدعى في الكود المفحوص (legacy?)
- **التأثير**: `usage_count = usage_count + 1` (حتى 5 كحد أقصى صريح في الكود)

**3. تقليل usage_count**:
- **الموقع**: `SupplierLearningRepository::decrementUsage()`
- **المحفز**: غير مستدعى في الكود المفحوص
- **التأثير**: `usage_count = usage_count - 1` (حد أدنى -5)

### القراءة (Read Operations)

**الموقع**: `AliasSignalFeeder::getSignals()`  
**الاستدعاء**: `SupplierAlternativeNameRepository::findAllByNormalizedName()`

**الاستعلام**:
```php
SELECT * FROM supplier_alternative_names
WHERE normalized_name = ?
-- NO usage_count filtering (Query Pattern Audit #9)
```

**الناتج**: يتحول إلى إشارات:
- `alias_exact` signal → قوة = 1.0 (دائماً، لأنها مطابقة دقيقة)
- **metadata**: يشمل `usage_count` للسياق فقط، ليس للفلترة

### التأثير على الاقتراحات

- **أعلى أولوية**: alias match = exact match
- يُستخدم في Trust Gate (SmartProcessingService)
- 🔴 **CONFLICT DETECTION**: `findConflictingAliases()` يمنع auto-match إذا وُجدت أسماء بديلة متعارضة

### ال conflict Detection Logic

**الموقع**: `SupplierLearningRepository::findConflictingAliases()`

```php
// يبحث عن aliases لنفس الـ normalized_name لكن موردين مختلفين
SELECT supplier_id, source
FROM supplier_alternative_names
WHERE normalized_name = ? AND supplier_id != ?
```

**التأثير**:
- إذا alias source = 'learning' + يوجد conflicts → **BLOCK auto-match**
- العملية في `SmartProcessingService::evaluateTrust():443`

### الملاحظات الحرجة

- ✅ **compliant query**: لا فلترة بـ usage_count (Query Pattern Audit #9)
- ⚠️ **unused methods**: `incrementUsage`, `decrementUsage`, `learnAlias` **غير مستدعاة** في الكود المفحوص
- ⚠️ **migration exists**: `2026_01_03_add_normalized_to_learning.sql` يضيف normalized_name
- 🔴 **critical**: conflict detection يؤثر على Trust Gate

---

## النظام #3: Historical Selections
### التعلم من القرارات التاريخية

**الوصف**: يتتبع المور دين الذين تم اختيارهم تاريخياً لنفس اسم المورد (من raw_data).

### مصادر البيانات

| المصدر | النوع | الموقع |
|--------|------|--------|
| `guarantees` + `guarantee_decisions` | جداول قاعدة بيانات | raw_data + decisions |
| `GuaranteeDecisionRepository` | Repository | `app/Repositories/GuaranteeDecisionRepository.php` |
| `HistoricalSignalFeeder` | Signal Feeder | `app/Services/Learning/Feeders/HistoricalSignalFeeder.php` |

### التسجيل (Write Operations)

**لا يوجد تسجيل صريح** - هذا النظام **READ-ONLY**

يعتمد على:
1. بيانات `guarantees.raw_data` (يحتوي على `supplier` name)
2. بيانات `guarantee_decisions` (يحتوي على `supplier_id` المختار)

### القراءة (Read Operations)

**الموقع**: `GuaranteeDecisionRepository::getHistoricalSelections()`  
**الاستدعاء من**: `HistoricalSignalFeeder::getSignals()`

**الاستعلام**:
```php
$pattern = '%"supplier":"' . $normalizedInput . '"%';

SELECT d.supplier_id, COUNT(*) as count
FROM guarantees g
JOIN guarantee_decisions d ON g.id = d.guarantee_id
WHERE g.raw_data LIKE ? AND d.supplier_id IS NOT NULL
GROUP BY d.supplier_id
```

**⚠️ FRAGILE**: JSON LIKE query (Query Pattern Audit #3)

**الناتج**: يتحول إلى إشارات:
- `historical_frequent` (count >= 5) → قوة حسب logarithmic scale
- `historical_occasional` (count 1-4) → قوة أقل

### حساب القوة

**الصيغة** (HistoricalSignalFeeder.php:88-103):
```php
strength = 0.3 + (0.5 * log(count + 1) / log(20))
// 1 selection = 0.3
// 5 selections = 0.6
// 10 selections = 0.7
// 20+ selections = 0.8+
```

### التأثير على الاقتراحات

- يُضاف كإشارة في `UnifiedLearningAuthority`
- أقل قوة من alias و explicit learning
- يساعد في **cold start** (موردين جدد بدون تاريخ تعلم)

### الملاحظات الحرجة

- 🔴 **CRITICAL FRAGILITY**: JSON LIKE pattern matching
- ⚠️ **TODO Phase 6**: تحديث بعد تحسين schema
- ⚠️ **performance**: full table scan بدون index
- ✅ **passive**: لا يكتب، فقط يقرأ من بيانات موجودة

---

## النظام #4: Fuzzy Matching System
### المطابقة غير الدقيقة

**الوصف**: يحسب similarity بين الإدخال و official_name لكل مورد باستخدام Levenshtein distance.

### مصادر البيانات

| المصدر | النوع | الموقع |
|--------|------|--------|
| `suppliers.official_name` | عمود قاعدة بيانات | أسماء الموردين الرسمية |
| `suppliers.normalized_name` | عمود قاعدة بيانات | أسماء الموردين بعد التطبيع |
| `SupplierRepository` | Repository | `app/Repositories/SupplierRepository.php` |
| `FuzzySignalFeeder` | Signal Feeder | `app/Services/Learning/Feeders/FuzzySignalFeeder.php` |

### التسجيل (Write Operations)

**لا يوجد تسجيل** - هذا النظام **COMPUTATIONAL ONLY**

### القراءة (Read Operations)

**الموقع**: `FuzzySignalFeeder::getSignals()`  
**الاستدعاء**: `SupplierRepository::getAllSuppliers()`

**الخوارزمية**:
```php
foreach (allSuppliers as supplier) {
    similarity = calculateSimilarity(input, supplier.normalized_name);
    
    if (similarity >= 0.55) {  // MIN_SIMILARITY
        emit_signal(similarity);
    }
}
```

**حساب Similarity**:
```php
distance = levenshtein(str1, str2);
similarity = 1 - (distance / max_length);
```

**الناتج**: يتحول إلى إشارات:
- `fuzzy_official_strong` (similarity >= 0.85)
- `fuzzy_official_medium` (similarity >= 0.70)
- `fuzzy_official_weak` (similarity >= 0.55)

### حدود القبول

| النطاق | Signal Type | الحد الأدنى |
|--------|-------------|-------------|
| 0.85+ | strong | ثقة عالية |
| 0.70-0.84 | medium | ثقة متوسطة |
| 0.55-0.69 | weak | ثقة منخفضة |
| < 0.55 | (no signal) | مرفوض |

### التأثير على الاقتراحات

- يُضاف كإشارة في `UnifiedLearningAuthority`
- **أقل قوة** من alias و explicit learning
- **أعلى تكلفة حسابية**: يحسب similarity لكل مورد (O(n))

### الملاحظات الحرجة

- ⚠️ **performance**: no caching, يحسب كل مرة من الصفر
- ⚠️ **full scan**: يفحص **ALL** suppliers
- ✅ **stateless**: no write, pure computation
- 🔍 **reference**: Query Pattern Audit #7 (service-layer violation)

---

## النظام #5: Entity Anchor Extraction
### استخراج الكيانات المحورية

**الوصف**: يستخرج "anchors" (كلمات محورية) من اسم المورد ويطابقها مع الموردين.

### مصادر البيانات

| المصدر | النوع | الموقع |
|--------|------|--------|
| `suppliers.official_name` | عمود قاعدة بيانات | أسماء الموردين |
| `ArabicEntityExtractor` | Service | `app/Services/Suggestions/ArabicEntityExtractor.php` |
| `SupplierRepository` | Repository | `app/Repositories/SupplierRepository.php` |
| `AnchorSignalFeeder` | Signal Feeder | `app/Services/Learning/Feeders/AnchorSignalFeeder.php` |

### التسجيل (Write Operations)

**لا يوجد تسجيل** - هذا النظام **COMPUTATIONAL ONLY**

### القراءة (Read Operations)

**الموقع**: `AnchorSignalFeeder::getSignals()`

**الخوارزمية**:
```php
anchors = ArabicEntityExtractor::extractAnchors(input);

if (empty(anchors)) return [];  // No signals

foreach (anchor in anchors) {
    matchingSuppliers = SupplierRepository::findByAnchor(anchor);
    frequency = countSuppliersWithAnchor(anchor);
    
    foreach (supplier in matchingSuppliers) {
        emit_signal(supplier, anchor, frequency);
    }
}
```

**استخراج Anchors**:
- يزيل كلمات شائعة ("شركة", "مؤسسة", etc.)
- يستخرج الكلمات المميزة
- **logic في**: `ArabicEntityExtractor::extractAnchors()`

**الناتج**: يتحول إلى إشارات:
- `entity_anchor_unique` (frequency <= 2) → قوة عالية
- `entity_anchor_generic` (frequency >= 3) → قوة منخفضة

### حساب القوة

**الصيغة** (AnchorSignalFeeder.php:118-129):
```php
if (frequency === 1) return 1.0;   // Perfectly unique
elseif (frequency === 2) return 0.9;  // Very distinctive
elseif (frequency <= 5) return 0.7;   // Somewhat distinctive
else return 0.5;                      // Generic/common
```

### التأثير على الاقتراحات

- يُضاف كإشارة في `UnifiedLearningAuthority`
- **قوة متوسطة**: أقل من alias، أعلى من fuzzy
- **يدعم التعلم**: anchors تُسجل في learning_confirmations metadata

### الملاحظات الحرجة

- ⚠️ **performance**: anchor extraction + multiple queries
- ⚠️ **ambiguity**: generic anchors (مثل "شركة") تطابق الكثير → ضوضاء
- ✅ **no Golden Rule**: Authority تقرر silence، ليس Feeder
- 🔍 **reference**: Service Classification Matrix (ArabicLevelBSuggestions refactor)

---

## 🔍 نظام سادس محتمل (غير مؤكد): Learning Cache
### supplier_learning_cache table

**الحالة**: **موجود لكن غير مستخدم فعلياً**

### الدليل

1. **الجدول موجود**: `supplier_learning_cache`
2. **Repository موجود**: `SupplierLearningCacheRepository.php`
3. **لكن**: 
   - تعليق في `SupplierLearningRepository.php:36` يقول "Here we can fetch from supplier_learning_cache if populated"
   - **لا استدعاءات فعلية** في الكود المفحوص
   - **migration لحذفه**: `2026_01_03_drop_learning_cache.sql`

### الوظيفة المقصودة (لو كان مفعلاً)

```sql
supplier_learning_cache (
    normalized_input,      -- الإدخال بعد التطبيع
    supplier_id,           -- المورد
    fuzzy_score,           -- نتيجة fuzzy matching
    source_weight,         -- وزن المصدر
    usage_count,           -- عدد الاستخدامات
    block_count,           -- عدد الحظر
    total_score,           -- النتيجة الإجمالية
    effective_score,       -- النتيجة الفعالة
    star_rating            -- تقييم نجمي
)
```

### التصنيف

- ⚠️ **LEGACY / UNUSED**: موجود لكن غير نشط
- ⚠️ **planned for removal**: migration `drop_learning_cache.sql`
- ❓ **potential 6th system**: لو تم تفعيله، يصبح نظام تعلم cache-based

---

## 📊 ملخص: الأنظمة الفعلية

| # | النظام | Type | المصدر | Write? | Read? | Active? |
|---|--------|------|--------|--------|-------|---------|
| 1 | Explicit Learning | User Feedback | learning_confirmations | ✅ | ✅ | ✅ Active |
| 2 | Alternative Names | Alias Matching | supplier_alternative_names | ⚠️ Partial | ✅ | ✅ Active |
| 3 | Historical Selections | Past Decisions | guarantees + decisions | ❌ | ✅ | ✅ Active |
| 4 | Fuzzy Matching | Similarity Calc | suppliers (official_name) | ❌ | ✅ | ✅ Active |
| 5 | Entity Anchors | Anchor Extraction | suppliers + extractor | ❌ | ✅ | ✅ Active |
| 6? | Learning Cache | Cache (unused) | supplier_learning_cache | ❌ | ❌ | ❌ Inactive |

---

## 🎯 نقاط الدخول (Entry Points)

### للاقتراحات (Suggestions)

**الموقع**: `UnifiedLearningAuthority::getSuggestions()`  
**المحفز**:
- index.php:459 (عند تحميل الصفحة)
- save-and-next.php:285 (للتحقق من top suggestion للرفض الضمني)

**التسلسل**:
```
user input → AuthorityFactory::create() → 
    registerFeeder(Alias) →
    registerFeeder(Learning) →
    registerFeeder(Fuzzy) →
    registerFeeder(Anchor) →
    registerFeeder(Historical) →
UnifiedLearningAuthority::getSuggestions() →
    gatherSignals() (من كل feeder) →
    aggregateBySupplier() →
    computeConfidenceScores() →
    filterByThreshold() →
    orderByConfidence() →
    format as SuggestionDTO[]
```

### للتسجيل (Logging)

**الموقع**: `api/save-and-next.php:262-307`

**التسلسل**:
```
user selects supplier →
    LearningRepository::logDecision('confirm') →
    IF (top_suggestion != chosen) THEN
        LearningRepository::logDecision('reject')
```

---

## 🔄 التفاعل بين الأنظمة

### تسلسل زمني نموذجي

1. **Import**: guarantee imported → raw_data contains supplier name → **NO learning yet**

2. **Auto-Match Attempt**:
   - UnifiedLearningAuthority gathers signals from **ALL 5 feeders**
   - Alias (System #2) → exact match if alias exists
   - Learning (System #1) → confirmation/rejection history
   - Historical (System #3) → past selections for this name
   - Fuzzy (System #4) → similarity scores
   - Anchor (System #5) → entity anchor matches
   - **Aggregate** → top suggestion with confidence
   - **Trust Gate** → check conflicts (System #2)
   - **IF trusted** → auto-create decision → **NO learning logged**

3. **Manual Decision**:
   - User selects supplier X
   - **save-and-next.php** logs:
     - System #1: confirm for X
     - System #1: reject for top suggestion (if X != top)
   - **Decision created** → adds to System #3 (historical) for future

4. **Next Guarantee** (same supplier name):
   - System #1: has confirmation for X (+1 strength)
   - System #3: has historical selection for X (+1 frequency)
   - **Combined effect**: higher confidence → more likely auto-match

---

## ✅ الخلاصة

**عدد الأنظمة الفعلية**: **5 أنظمة نشطة**

1. ✅ **Explicit Learning** (confirm/reject) - write + read
2. ✅ **Alternative Names** (aliases) - read (write methods exist but unused)
3. ✅ **Historical Selections** - read-only, passive
4. ✅ **Fuzzy Matching** - computational, stateless
5. ✅ **Entity Anchors** - computational, stateless

**نظام محتمل سادس**: Learning Cache (موجود لكن غير مستخدم، مخطط للحذف)

**كل نظام فعال** ويؤثر على الاقتراحات عبر UnifiedLearningAuthority.

---

*هذا التقرير دقيق بناءً على فحص الكود في 2026-01-03. أي تغيير بعد هذا التاريخ يحتاج تحديث.*
