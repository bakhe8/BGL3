# Learning Systems Overlap Matrix

## التقرير: مصفوفة التداخلات بين أنظمة التعلم

**التاريخ**: 2026-01-03  
**الهدف**: توثيق كل تداخل، اعتماد، أو ربط بين الأنظمة الخمسة

---

## 📊 Overlap Matrix (5x5)

|  | System #1<br/>Explicit | System #2<br/>Aliases | System #3<br/>Historical | System #4<br/>Fuzzy | System #5<br/>Anchors |
|---|---|---|---|---|---|
| **#1 Explicit** | - | ❌ None | ⚠️ Indirect | ❌ None | 📝 Metadata |
| **#2 Aliases** | ❌ None | - | ❌ None | ❌ None | ❌ None |
| **#3 Historical** | ✅ Same Goal | ❌ None | - | ❌ None | ❌ None |
| **#4 Fuzzy** | ❌ None | ❌ None | ❌ None | - | ❌ None |
| **#5 Anchors** | 📝 Metadata | ❌ None | ❌ None | ❌ None | - |

**Legend**:
- ❌ None: لا تداخل
- ⚠️ Indirect: تداخل غير مباشر
- ✅ Direct: تداخل مباشر
- 📝 Metadata only: بيانات وصفية فقط

---

## 🔍 تحليل التداخلات التفصيلي

### OVERLAP #1: Explicit Learning ↔ Historical Selections

**النوع**: ⚠️ **Indirect / Same Goal**

**الوصف**:
- **System #1** يسجل قرارات المستخدم في `learning_confirmations`
- **System #3** يقرأ قرارات المستخدم من `guarantee_decisions` + `guarantees.raw_data`

**التداخل**:
```
User selects Supplier X for "شركة النورس"
  ↓
System #1: INSERT INTO learning_confirmations (
  raw_supplier_name = "شركة النورس",
  supplier_id = X,
  action = 'confirm'
)
  ↓
AND
  ↓
guarantee_decisions: UPDATE (
  supplier_id = X
)
  ↓
Future request for "شركة النورس":
  System #1 reads: 1 confirmation for X
  System #3 reads: 1 historical selection for X
  
Result: BOTH systems boost Supplier X
```

**⚠️ DUPLICATION OF INTENT**: نفس القصد (تعزيز اختيار X) مُسجل في مكانين

**الفرق**:
- System #1: يحسب confirmations/rejections **بشكل صريح**
- System #3: يحسب **any selection** regardless of how decided (auto or manual)

**Failure Coupling**: ❌ لا يوجد
- إذا فشل أحدهما، الآخر يعمل بشكل مستقل

---

### OVERLAP #2: Explicit Learning ↔ Entity Anchors

**النوع**: 📝 **Metadata Only**

**الوصف**:
- System #1 يسجل `matched_anchor` في learning_confirmations
- System #5 يستخرج anchors ديناميكياً

**التداخل**:
```
System #5 (during suggestion):
  Extracts anchors: ["النورس", "التجارة"]
  Matches suppliers
  ↓
  Returns: SignalDTO(metadata: {matched_anchor: "النورس"})

Later, System #1 (during save):
  Stores in learning_confirmations:
    matched_anchor = "النورس"  ← من metadata
    anchor_type = "learned"
```

**الاستخدام**:
- `matched_anchor` **لا يُقرأ** من learning_confirmations حالياً
- مُخزن **للتحليل المستقبلي** فقط

**Failure Coupling**: ❌ لا يوجد
- لو System #5 لم يُرجع anchor، System #1 يخزن NULL

---

### OVERLAP #3: Aliases ↔ Other Systems

**النوع**: ❌ **None (Isolated)**

**الملاحظة**: System #2 (Aliases) **معزول تماماً**

- لا يقرأ من أنظمة أخرى
- لا يكتب لأنظمة أخرى
- **فقط** مستدعى من UnifiedLearningAuthority كـ signal feeder

**استثناء**: Conflict Detection
- `SmartProcessingService::evaluateTrust()` **يقرأ** من aliases table
- **لكن**: هذا ليس نظام تعلم، بل Trust Gate logic

---

### OVERLAP #4: Fuzzy & Anchors ↔ Others

**النوع**: ❌ **None (Computational)**

**الملاحظة**: Systems #4 و #5 **computational only**

- **لا يقرأون** من أنظمة تعلم أخرى
- **لا يكتبون** لأنظمة تعلم أخرى
- Stateless (لا حالة مُخزنة)

**Coupling**: ✅ **Single Point**: UnifiedLearningAuthority
- كلاهما يُستدعى فقط من Authority
- يُرجعان signals، لا يتفاعلان مع بعضهما البعض

---

## 🔗 Data Sharing Analysis

### Shared Data Source #1: `suppliers` table

**Who Reads**:
- System #2 (Aliases) → via `supplier_id` FK
- System #4 (Fuzzy) → reads `official_name`, `normalized_name`
- System #5 (Anchors) → reads `official_name`

**How Shared**:
- ✅ **Read-Only** by all
- ❌ **No Write** from any learning system

**Coupling**: ⚠️ **Schema Coupling**
- إذا `suppliers.official_name` تغير → Systems #4 & #5 تتأثر
- إذا `suppliers.normalized_name` removed → System #4 breaks

---

### Shared Data Source #2: `guarantees.raw_data`

**Who Reads**:
- System #1 (Explicit) → `raw_data['supplier']` for logging
- System #3 (Historical) → `raw_data['supplier']` for matching

**How Shared**:
- ✅ **Read-Only** by both
- ⚠️ **Fragile**: Both use JSON field (different query patterns)

**Coupling**: 🔴 **Format Coupling**
- إذا JSON structure تغير → **BOTH** break
- إذا `raw_data['supplier']` renamed → **BOTH** break

---

### Shared Data Source #3: `guarantee_decisions`

**Who Reads**:
- System #3 (Historical) → `supplier_id` for counting

**Who Writes**:
- ❌ None of the learning systems (written by decision flow)

**How Shared**:
- ✅ **Read-Only** by System #3
- **Passive**: Learning systems don't control this data

**Coupling**: ⚠️ **Weak Coupling**
- إذا `guarantee_decisions` schema changes → only System #3 affected

---

## ⚠️ Accidental vs Intentional Overlaps

### Intentional Overlap #1: Dual Confirmation Tracking

**Who**: System #1 (Explicit) + System #3 (Historical)

**القصد**:
- System #1: تتبع تأكيدات/رفض **صريحة**
- System #3: تتبع **كل** الاختيارات التاريخية

**Intentional?**: ⚠️ **Unclear**
- يبدو **accidental** (نفس الهدف، مصدرين مختلفين)
- أو **intentional** (System #1 للتعلم السريع، System #3 للنمط طويل المدى)

**Recommendation**: **توضيح القصد** في documentation

---

### Accidental Overlap #1: Fragmented Supplier Name Storage

**Who**: System #1 (Explicit) + System #3 (Historical)

**المشكلة**:
- **System #1**: يخزن `raw_supplier_name` (original text)
- **System #3**: يبحث في `raw_data['supplier']` (original JSON)
- **Result**: نفس المورد بأسماء مختلفة → counted separately

**Example**:
```
Import 1: supplier = "شركة النورس"
Import 2: supplier = "شركة النورس " (extra space)
Import 3: supplier = "شركة النورس للتجارة"

System #1 counts:
  "شركة النورس" → 1 confirmation
  "شركة النورس " → 1 confirmation
  
System #3 counts:
  "شركة النورس" → 1 selection
  "شركة النورس " → 1 selection
  
Result: Same supplier, fragmented counts
```

**Intentional?**: ❌ **Accidental** (known issue, TODO Phase 6)

---

## 💥 Failure Coupling Analysis

### Scenario #1: System #1 (Explicit) fails to log

**Impact on Others**:
- System #3 (Historical): ❌ **No Impact** (uses different table)
- System #4 (Fuzzy): ❌ **No Impact** (computational)
- System #5 (Anchors): ❌ **No Impact** (computational)

**Conclusion**: ✅ **Isolated failure**

---

### Scenario #2: System #3 (Historical) JSON query breaks

**Impact on Others**:
- System #1 (Explicit): ❌ **No Impact** (different table)
- Other systems: ❌ **No Impact**

**Conclusion**: ✅ **Isolated failure**

---

### Scenario #3: `suppliers` table schema change

**Impact**:
- System #2 (Aliases): ⚠️ **May Break** (FK to supplier_id)
- System #4 (Fuzzy): ⚠️ **May Break** (reads normalized_name)
- System #5 (Anchors): ⚠️ **May Break** (reads official_name)

**Conclusion**: 🔴 **Cascading failure possible**

---

### Scenario #4: `guarantees.raw_data` format change

**Impact**:
- System #1 (Explicit): 🔴 **Breaks** (reads `raw_data['supplier']`)
- System #3 (Historical): 🔴 **Breaks** (JSON LIKE query)

**Conclusion**: 🔴 **Dual failure**

---

## 🎯 Coupling Summary

| نوع الربط | الأنظمة المتأثرة | شدة الربط |
|-----------|-------------------|-----------|
| Data Format (JSON) | #1, #3 | 🔴 High |
| Schema (suppliers) | #2, #4, #5 | ⚠️ Medium |
| Intent Duplication | #1, #3 | ⚠️ Medium |
| Metadata Storage | #1, #5 | ✅ Low |
| Independent | #4, #5 | ✅ None |

---

## 📋 Overlap Types

### Type A: Data Sharing

**Examples**:
- System #4 & #5 both read `suppliers` table
- System #1 & #3 both read supplier names (different sources)

**Risk**: Schema changes affect multiple systems

---

### Type B: Intent Duplication

**Example**:
- System #1 logs confirmations
- System #3 counts historical selections
- **Both** boost same supplier

**Risk**: Unclear which system is authoritative for what

---

### Type C: Metadata Flow

**Example**:
- System #5 extracts anchor → metadata
- System #1 stores anchor → `learning_confirmations.matched_anchor`

**Risk**: Low (metadata only, not used in logic)

---

## ✅ الخلاصة

**Total Overlaps**: 3 significant

1. **#1 ↔ #3**: Intent duplication (both track selections)
2. **#1, #3 ↔ raw_data**: Format coupling (JSON fragility)
3. **#2, #4, #5 ↔ suppliers**: Schema coupling

**Failure Coupling**:
- ⚠️ **Medium**: JSON format change breaks #1 & #3
- ⚠️ **Medium**: suppliers schema change affects #2, #4, #5
- ✅ **Low**: Otherwise isolated

**Intentional vs Accidental**:
- **Intentional**: Metadata storage (#1 ← #5)
- **Accidental**: Intent duplication (#1 ↔ #3)
- **Accidental**: Fragmented names (#1, #3)

---

*هذا التقرير يوضح أن الأنظمة **ليست معزولة تماماً**، لكن الربط **محدود ومُدار**.*
