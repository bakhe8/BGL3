# Learning Database Map

## التقرير: خريطة قاعدة البيانات لأنظمة التعلم

**التاريخ**: 2026-01-03  
**الهدف**: توثيق دقيق لجدادول قاعدة البيانات المستخدمة في جميع أنظمة التعلم

---

## 📊 جداول التعلم الرئيسية

### الجدول #1: `learning_confirmations`
**النظام**: Explicit Learning (System #1)  
**الاستخدام**: تسجيل confirm/reject الصريح من المستخدم

#### البنية (Schema)

```sql
CREATE TABLE learning_confirmations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    raw_supplier_name TEXT NOT NULL,         -- الاسم الأصلي من raw_data
    supplier_id INTEGER NOT NULL,             -- المورد (confirm) أو المرفوض (reject)
    confidence REAL,                          -- ثقة الاقتراح الأصلي
    matched_anchor TEXT,                      -- الـ anchor الذي تم المطابقة عليه
    anchor_type TEXT,                         -- نوع الـ anchor
    action TEXT NOT NULL,                     -- 'confirm' أو 'reject'
    decision_time_seconds INTEGER DEFAULT 0,  -- وقت اتخاذ القرار
    guarantee_id INTEGER,                     -- رقم الضمان (nullable)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id)
);
```

#### الحقول الحرجة

| الحقل | النوع | القراءة | الكتابة | الغرض |
|-------|------|---------|---------|-------|
| `raw_supplier_name` | TEXT | ✅ | ✅ | المفتاح للاستعلام (WHERE raw_supplier_name = ?) |
| `supplier_id` | INTEGER | ✅ | ✅ | المورد المُؤكد أو المرفوض |
| `action` | TEXT | ✅ | ✅ | تحديد نوع الإشارة (confirm/reject) |
| `confidence` | REAL | ❌ | ✅ | metadata فقط، لا يُستخدم في الحسابات |
| `guarantee_id` | INTEGER | ❌ | ✅ | للربط التاريخي، لا يُستخدم في queries |

#### استعلامات القراءة

**Query #1**: `LearningRepository::getUserFeedback()`
```sql
SELECT supplier_id, action, COUNT(*) as count
FROM learning_confirmations
WHERE raw_supplier_name = ?
GROUP BY supplier_id, action
```

**الأداء**: 
- ⚠️ NO INDEX on `raw_supplier_name` → full table scan
- ⚠️ Fragmentation: same supplier with different raw names counted separately

**Query #2**: `LearningRepository::getRejections()`
```sql
SELECT DISTINCT supplier_id
FROM learning_confirmations
WHERE raw_supplier_name = ? AND action = 'reject'
```

#### استعلامات الكتابة

**Write #1**: `LearningRepository::logDecision()`
```sql
INSERT INTO learning_confirmations (
    raw_supplier_name, supplier_id, confidence, matched_anchor,
    anchor_type, action, decision_time_seconds, guarantee_id
) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
```

**المحفز**:
- `save-and-next.php:273-281` → confirm
- `save-and-next.php:290-298` → reject

**التكرار**: مرة واحدة لكل قرار مستخدم

#### دورة حياة البيانات

```
Import → (no learning) →
Manual Decision → logDecision(action='confirm') → stored →
Next Suggestion → getUserFeedback() → counted →
Higher confidence → More likely auto-match
```

**الاحتفاظ**: دائم (no cleanup)  
**النمو**: تراكمي (insert-only, no updates/deletes)

#### المشاكل المعروفة

- ⚠️ **Fragmentation**: `raw_supplier_name` variants not normalized
  - "شركة النورس" ≠ "شركة النورس " (extra space)
  - Solution planned: `2026_01_03_add_normalized_to_learning.sql`
- ⚠️ **No index**: slow queries with large data
- ⚠️ **No cleanup**: rows never deleted (infinite growth)

---

### الجدول #2: `supplier_alternative_names`
**النظام**: Alternative Names (System #2)  
**الاستخدام**: أسماء بديلة للموردين (aliases)

#### البنية (Schema)

```sql
CREATE TABLE supplier_alternative_names (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier_id INTEGER NOT NULL,
    alternative_name TEXT NOT NULL,      -- الاسم البديل الأصلي
    normalized_name TEXT NOT NULL,       -- بعد التطبيع
    source TEXT NOT NULL,                -- 'learning' | 'manual' | 'import'
    usage_count INTEGER DEFAULT 0,       -- positive/negative learning counter
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    UNIQUE (normalized_name, supplier_id)  -- prevent duplicate aliases
);

CREATE INDEX idx_alt_names_normalized ON supplier_alternative_names(normalized_name);
```

#### الحقول الحرجة

| الحقل | النوع | القراءة | الكتابة | الغرض |
|-------|------|---------|---------|-------|
| `normalized_name` | TEXT | ✅ | ✅ | المفتاح للاستعلام (indexed) |
| `supplier_id` | INTEGER | ✅ | ✅ | المورد المرتبط |
| `source` | TEXT | ✅ | ✅ | يؤثر على Trust Gate |
| `usage_count` | INTEGER | ❌ | ⚠️ | **غير مستخدم فعلياً** في filtering |
| `created_at` | DATETIME | ❌ | ✅ | metadata |

#### استعلامات القراءة

**Query #1**: `SupplierAlternativeNameRepository::findAllByNormalizedName()`
```sql
SELECT * FROM supplier_alternative_names
WHERE normalized_name = ?
-- NO usage_count filter (Query Pattern Audit #9)
```

**الاستدعاء من**: `AliasSignalFeeder::getSignals()`

**Query #2**: `SupplierLearningRepository::findConflictingAliases()`
```sql
SELECT supplier_id, source
FROM supplier_alternative_names
WHERE normalized_name = ? AND supplier_id != ?
```

**الاستدعاء من**: `SmartProcessingService::evaluateTrust()` → Trust Gate

#### استعلامات الكتابة

**Write #1**: `SupplierLearningRepository::learnAlias()`
```sql
-- Check if exists
SELECT id FROM supplier_alternative_names WHERE normalized_name = ?

-- Insert if new
INSERT INTO supplier_alternative_names 
(supplier_id, alternative_name, normalized_name, source, usage_count)
VALUES (?, ?, ?, 'learning', 1)
```

**⚠️ غير مستدعى** في الكود المفحوص (legacy?)

**Write #2**: `SupplierLearningRepository::incrementUsage()`
```sql
UPDATE supplier_alternative_names 
SET usage_count = usage_count + ?
WHERE normalized_name = ? AND supplier_id = ?
```

**⚠️ غير مستدعى** في الكود المفحوص

**Write #3**: `SupplierLearningRepository::decrementUsage()`
```sql
UPDATE supplier_alternative_names 
SET usage_count = usage_count - ?
WHERE normalized_name = ? AND supplier_id = ?
```

**⚠️ غير مستدعى** في الكود المفحوص

**التعليق في الكود** (SupplierLearningRepository.php:102-104):
```php
// Prevent infinite negativity (cap at -5)
$newCount usage_count - $decrement;
if ($newCount < -5) $newCount = -5;
```

#### دورة حياة البيانات

```
Manual creation (admin UI?) → stored in table →
AliasSignalFeeder reads → exact match (1.0 strength) →
IF conflict detected → Trust Gate blocks auto-match
```

**الاحتفاظ**: دائم  
**النمو**: بطيء (manual additions only, no auto-learning active)

#### المشاكل المعروفة

- ⚠️ **unused write methods**: `learnAlias`, `incrementUsage`, `decrementUsage` موجودة لكن غير مستدعاة
- ✅ **good index**: `idx_alt_names_normalized` يسرع الاستعلامات
- 🔴 **conflict detection**: الكود يفترض conflicts خطيرة، لكن لا data cleanup

---

### الجدول #3: `guarantees` (raw_data column)
**النظام**: Historical Selections (System #3) - جزئي  
**الاستخدام**: مصدر أسماء الموردين التاريخية

#### البنية (Schema - الصلة بالتعلم)

```sql
CREATE TABLE guarantees (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_number TEXT UNIQUE NOT NULL,
    raw_data TEXT NOT NULL,  -- JSON: {"supplier": "...", "bank": "...", ...}
    import_source TEXT,
    imported_at DATETIME,
    imported_by TEXT
);
```

#### raw_data (JSON Structure)

```json
{
    "guarantee_number": "...",
    "supplier": "شركة النورس للتجارة",  ← used in historical learning
    "bank": "البنك الأهلي",
    "amount": 50000,
    "expiry_date": "2026-12-31",
    ...
}
```

#### استعلامات القراءة (للتعلم)

**Query #1**: `GuaranteeDecisionRepository::getHistoricalSelections()`
```sql
pattern = '%"supplier":"' . $normalizedInput . '"%';

SELECT d.supplier_id, COUNT(*) as count
FROM guarantees g
JOIN guarantee_decisions d ON g.id = d.guarantee_id
WHERE g.raw_data LIKE ? AND d.supplier_id IS NOT NULL
GROUP BY d.supplier_id
```

**🔴 CRITICAL FRAGILITY**: JSON LIKE pattern matching

**الاستدعاء من**: `HistoricalSignalFeeder::getSignals()`

#### استعلامات الكتابة (للتعلم - NO)

**لا توجد كتابة** من أنظمة التعلم.

raw_data يُكتب فقط من:
- Import endpoints
- Action endpoints (extend/reduce) → updates amount/expiry

#### دورة حياة البيانات

```
Import → raw_data contains supplier name (original) →
(years later) → HistoricalSignalFeeder queries by name →
Counts how many times each supplier was chosen for this name
```

**الاحتفاظ**: دائم  
**النمو**: ثابت (لكل ضمان raw_data واحد، يُحدث لكن لا يُحذف)

#### المشاكل المعروفة

- 🔴 **FRAGILE QUERY**: LIKE '%"supplier":"name"%' breaks on JSON format changes
- ⚠️ **NO INDEX**: no index on raw_data (can't index JSON TEXT in SQLite easily)
- ⚠️ **performance**: full table scan for every query
- ⚠️ **TODO Phase 6**: extract `normalized_supplier_name` column

---

### الجدول #4: `guarantee_decisions`
**النظام**: Historical Selections (System #3) - جزئي  
**الاستخدام**: القرارات الفعلية (supplier_id المختار)

#### البنية (Schema - الصلة بالتعلم)

```sql
CREATE TABLE guarantee_decisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER UNIQUE NOT NULL,
    supplier_id INTEGER,  -- الاختيار النهائي
    bank_id INTEGER,
    status TEXT DEFAULT 'pending',
    decision_source TEXT,  -- 'auto_match' | 'manual'
    confidence_score REAL,
    decided_at DATETIME,
    decided_by TEXT,
    is_locked INTEGER DEFAULT 0,
    locked_reason TEXT,
    active_action TEXT,  -- 'extension' | 'reduction' | 'release' | NULL
    active_action_set_at DATETIME,
    
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (bank_id) REFERENCES banks(id)
);
```

#### الحقول الحرجة (للتعلم)

| الحقل | القراءة | معنى |
|-------|---------|-----|
| `supplier_id` | ✅ | المورد المختار (يُعد في historical) |
| `decision_source` | ❌ | metadata فقط |
| `decided_at` | ❌ | metadata فقط |

#### استعلامات القراءة (للتعلم)

**مستخدم في**: Historical query (مع join لـ guarantees)

```sql
SELECT d.supplier_id, COUNT(*) as count
FROM guarantees g
JOIN guarantee_decisions d ON g.id = d.guarantee_id
WHERE g.raw_data LIKE ? AND d.supplier_id IS NOT NULL
GROUP BY d.supplier_id
```

#### دورة حياة البيانات

```
Decision created → supplier_id set →
(future) → counted in historical selections →
Boosts supplier for same input name
```

**الاحتفاظ**: دائم (ما دام الضمان موجود)  
**النمو**: 1 قرار لكل ضمان (upsert pattern)

---

### الجدول #5: `suppliers`
**النظام**: Fuzzy Matching (System #4), Entity Anchors (System #5)  
**الاستخدام**: أسماء الموردين الرسمية

#### البنية (Schema)

```sql
CREATE TABLE suppliers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    official_name TEXT UNIQUE NOT NULL,  -- الاسم الرسمي
    normalized_name TEXT NOT NULL,        -- بعد التطبيع
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_suppliers_normalized ON suppliers(normalized_name);
```

#### الحقول الحرجة

| الحقل | القراءة | الكتابة | الاستخدام |
|-------|---------|---------|-----------|
| `official_name` | ✅ | ❌ | Entity anchor matching |
| `normalized_name` | ✅ | ❌ | Fuzzy matching |

#### استعلامات القراءة (للتعلم)

**Query #1**: `SupplierRepository::getAllSuppliers()`
```sql
SELECT id, official_name, normalized_name FROM suppliers
```

**الاستدعاء من**: `FuzzySignalFeeder::getSignals()` → calculates similarity for ALL

**Query #2**: `SupplierRepository::findByAnchor()`
```sql
SELECT id, official_name FROM suppliers
WHERE official_name LIKE '%' || ? || '%'
```

**الاستدعاء من**: `AnchorSignalFeeder::getSignals()` → exact anchor match

**Query #3**: `SupplierRepository::countSuppliersWithAnchor()`
```sql
SELECT COUNT(*) FROM suppliers
WHERE official_name LIKE '%' || ? || '%'
```

**الاستدعاء من**: `AnchorSignalFeeder::calculateAnchorFrequencies()` → determines signal type

#### استعلامات الكتابة (للتعلم - NO)

**لا توجد كتابة** من أنظمة التعلم.

suppliers يُكتب فقط من:
- Admin UI
- Import if new supplier detected

#### دورة حياة البيانات

```
Supplier created → official_name & normalized_name stored →
EVERY suggestion request → read by Fuzzy + Anchor feeders →
Similarity calculated (Fuzzy) + Anchors extracted (Anchor) →
Signals emitted
```

**الاحتفاظ**: دائم (الموردين لا يُحذفون عادة)  
**النمو**: بطيء (إضافة موردين جدد فقط)

#### المشاكل المعروفة

- ⚠️ **performance**: `getAllSuppliers()` loads ALL → O(n) similarity calculations
- ⚠️ **LIKE queries**: anchor matching uses `LIKE '%anchor%'` → can be slow
- ✅ **good index**: `idx_suppliers_normalized` helps

---

###الجدول #6 (Inactive): `supplier_learning_cache`
**الحالة**: موجود لكن **غير مستخدم فعلياً**  
**المخطط**: حذفه (`2026_01_03_drop_learning_cache.sql`)

#### البنية (Schema)

```sql
CREATE TABLE supplier_learning_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    normalized_input TEXT NOT NULL,
    supplier_id INTEGER NOT NULL,
    fuzzy_score REAL DEFAULT 0.0,
    source_weight INTEGER DEFAULT 0,
    usage_count INTEGER DEFAULT 0,
    block_count INTEGER DEFAULT 0,
    total_score REAL GENERATED ALWAYS AS 
        (fuzzy_score + source_weight + (usage_count * 0.1) - (block_count * 0.2)) STORED,
    effective_score REAL GENERATED ALWAYS AS 
        (CASE WHEN block_count > 0 THEN 0 ELSE total_score END) STORED,
    star_rating INTEGER GENERATED ALWAYS AS 
        (CASE 
            WHEN effective_score >= 0.9 THEN 5
            WHEN effective_score >= 0.7 THEN 4
            WHEN effective_score >= 0.5 THEN 3
            WHEN effective_score >= 0.3 THEN 2
            ELSE 1
        END) STORED,
    last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    UNIQUE (normalized_input, supplier_id)
);

CREATE INDEX idx_learning_cache_input ON supplier_learning_cache(normalized_input, effective_score DESC);
```

#### لماذا غير مستخدم؟

**الدليل**:
1. تعليق في `SupplierLearningRepository.php:36`: "Here we can fetch from supplier_learning_cache if populated"
2. **لا استد عاءات** لـ `SupplierLearningCacheRepository` في الكود المفحوص
3. Migration `drop_learning_cache.sql` موجود

**الوظيفة المقصودة** (لو كان مفعلاً):
- Pre-calculated suggestions cache
- Gradual learning via `usage_count` / `block_count`
- Star rating system (1-5 stars)
- Generated columns for automatic scoring

**لماذا تم إهماله؟**
- غالباً: `UnifiedLearningAuthority` يحسب ديناميكياً (أدق)
- Cache يصبح stale بسرعة
- Overhead في التحديث

---

## 📊 ملخص الجداول

| الجدول | النظام | القراءة | الكتابة | Indexed | Active |
|--------|--------|---------|---------|---------|--------|
| `learning_confirmations` | #1 | ✅ | ✅ | ❌ | ✅ |
| `supplier_alternative_names` | #2 | ✅ | ⚠️ Partial | ✅ | ✅ |
| `guarantees.raw_data` | #3 | ✅ | ❌ | ❌ | ✅ |
| `guarantee_decisions` | #3 | ✅ | ❌ | ❌ | ✅ |
| `suppliers` | #4, #5 | ✅ | ❌ | ✅ | ✅ |
| `supplier_learning_cache` | (inactive) | ❌ | ❌ | ✅ | ❌ |

---

## 🔍 أنماط الاستعلامات

### Pattern #1: Aggregation by Supplier
**الاستخدام**: `learning_confirmations`, historical selections

```sql
SELECT supplier_id, action, COUNT(*) as count
FROM learning_confirmations
WHERE raw_supplier_name = ?
GROUP BY supplier_id, action
```

**الأداء**: O(n) where n = rows matching raw_supplier_name  
**المشكلة**: no index on raw_supplier_name

---

### Pattern #2: JSON LIKE Query (Fragile)
**الاستخدام**: historical selections

```sql
WHERE g.raw_data LIKE '%"supplier":"name"%'
```

**الأداء**: O(n) full table scan  
**المشكلة**: breaks on JSON format changes

---

### Pattern #3: Full Table Scan + Computation
**الاستخدام**: fuzzy matching

```sql
SELECT id, normalized_name FROM suppliers;
-- Then: calculate levenshtein(input, each supplier)
```

**الأداء**: O(n * m) where n = suppliers, m = string length  
**المشكلة**: no caching, recalculates every request

---

## 🎯 توصيات الأداء (للعلم فقط، لا تنفيذ)

### High Priority
1. Add index on `learning_confirmations.raw_supplier_name`
2. Extract `normalized_supplier_name` column from `guarantees.raw_data`
3. Add index on `guarantees.normalized_supplier_name`

### Medium Priority
4. Cache fuzzy matching results (or use `supplier_learning_cache` properly)
5. Limit fuzzy matching to top 50 suppliers by some heuristic

### Low Priority
6. Cleanup old `learning_confirmations` rows (archive after 1 year?)

---

## ✅ الخلاصة

**Active Tables**: 5  
**Inactive Tables**: 1 (supplier_learning_cache)

**Total Storage** (estimated):
- `learning_confirmations`: growing (insert-only)
- `supplier_alternative_names`: stable (manual additions)
- `guarantees.raw_data`: growing (1 per guarantee)
- `suppliers`: stable (slow growth)

**Performance Bottlenecks**:
1. JSON LIKE queries → full table scan
2. No index on `learning_confirmations.raw_supplier_name`
3. Fuzzy matching → O(n) calculations per request

**Migration Files** (فحصها لاحقاً):
- `005_create_learning_tables.sql` → creates learning_confirmations
- `2026_01_03_add_normalized_to_learning.sql` → adds normalized column
- `2026_01_03_drop_learning_cache.sql` → removes cache table

---

*هذا التقرير يوثق البنية الحالية لقاعدة البيانات بدقة. أي تعديلات مستقبلية يجب أن تُحدَّث هنا.*
