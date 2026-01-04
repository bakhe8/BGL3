# Operational Map: Why Duplications Exist
## ربط التكرار بالمنطق الوظيفي ضمن العقد الفكري

> **المرجع الأعلى**: [System Understanding Lock](./System-Understanding-Lock.md) + [Non-Negotiable Rules](./Appendix-Non-Negotiable-Rules.md)  
> **الهدف**: تفسير **لماذا** كل تكرار موجود ضمن منطق النظام  
> **التاريخ**: 2026-01-04  
> **Status**: Operational Reference

---

## المبدأ الأساسي

**التكرار في هذا النظام ليس خطأ برمجي - بل انعكاس لتعدد السياقات التشغيلية.**

وفقاً للعقد الفكري:
- النظام state machine بمراحل زمنية متعددة
- كل مرحلة لها سياق مختلف
- نفس الوظيفة في سياقين = تكرار مشروع

---

## 1. تكرار API Endpoints

### 1.1 Supplier Creation APIs

#### الملفات المكررة:
- `create-supplier.php` (kebab-case)
- `create_supplier.php` (snake_case)

#### لماذا التكرار موجود؟

**السياق التشغيلي المختلف**:

```
create-supplier.php:
├─ يُستدعى من: واجهة معالجة السجلات (index.php)
├─ المرحلة: قرار المستخدم (Decision Phase)
├─ السلوك: إنشاء فوري + إغلاق modal + تحديث UI
├─ التوقع: استجابة في < 500ms
└─ الهدف: عدم إيقاف تدفق العمل

create_supplier.php:
├─ يُستدعى من: صفحة الإعدادات (settings.php)
├─ المرحلة: إدارة Master Data
├─ السلوك: إنشاء + refresh جدول + pagination
├─ التوقع: يمكن أن يأخذ وقت
└─ الهدف: إدارة كاملة للموردين
```

#### أي منطق يخدم؟

**create-supplier.php** يخدم **القاعدة #1** (سيادة القرار البشري):
- المستخدم في منتصف اتخاذ قرار
- لا يوجد مورد مطابق → يضيف جديد فوراً
- يجب العودة للسجل **بنفس الحالة**

**create_supplier.php** يخدم **إدارة منفصلة**:
- ليس جزء من قرار
- تحديث Master Data
- لا علاقة بـ Timeline

#### ما الخطر من الدمج؟

**لو دمجنا في endpoint واحد**:
```
خطر 1: كسر تدفق القرار
  - Modal لا يُغلق تلقائياً
  - UI لا يتحدث
  - المستخدم يفقد السياق

خطر 2: تعقيد المنطق
  - if (from === 'decision') { ... }
  - if (from === 'settings') { ... }
  - منطق متشعب = bugs

خطر 3: انتهاك Separation of Concerns
  - Decision phase != Management phase
  - كل واحد له عقد خاص
```

**القرار**: **التكرار مشروع** - يخدم سياقين مختلفين تماماً

---

### 1.2 Bank Creation APIs

#### الملفات المكررة:
- `add-bank.php`
- `create_bank.php`

#### لماذا التكرار موجود؟

**الفرق الوظيفي**:

```
add-bank.php:
├─ يُستدعى من: modal في معالجة السجلات
├─ البيانات: أساسية فقط (name، short_name)
├─ الغرض: إضافة سريعة لاستكمال القرار
└─ Post-action: تحديث dropdown + auto-select

create_bank.php:
├─ يُستدعى من: صفحة الإعدادات
├─ البيانات: كاملة (name، contacts، aliases، address)
├─ الغرض: إدارة شاملة
└─ Post-action: refresh جدول + pagination
```

#### أي منطق يخدم؟

**add-bank.php** يخدم **القاعدة #10** (شرط الجاهزية):
- الضمان pending (لا بنك)
- المستخدم يجب أن يكمل القرار → ready
- إضافة بنك = جزء من Decision Flow

**create_bank.php** يخدم **Master Data Management**:
- إدارة مستقلة عن أي قرار
- Aliases، Contacts، Full details

#### ما الخطر من الدمج؟

```
خطر 1: Complexity Explosion
  - Modal بسيط يتحول لـ form معقد
  - User confused: لماذا كل هذه الحقول؟

خطر 2: Breaking Decision Flow
  - المستخدم يريد إضافة بنك بـ 3 حقول
  - لا يريد ملء 10 حقول الآن

خطر 3: Mixed Responsibilities
  - Quick add != Full management
```

**القرار**: **التكرار ضروري** - workflows مختلفة

---

## 2. تكرار Services Logic

### 2.1 TextParsingService vs parse-paste.php

#### الملفات المكررة:
- `TextParsingService.php` (15KB، غير مستخدم)
- `parse-paste.php` (منطق inline، 31KB)

#### لماذا التكرار موجود؟

**تطور تاريخي**:

```
Phase 1 (قديم):
  TextParsingService → Clean، OOP، Testable
  ↓
  لكن: لم يغطِ كل حالات parse-paste

Phase 2 (حالي):
  parse-paste.php → Expanded inline
  ↓
  سبب: Multi-row detection، Table parsing
  
Result: Service قديم موجود لكن غير كافٍ
```

#### أي منطق يخدم؟

**TextParsingService** يخدم **single record parsing**:
- استخراج أساسي
- Regex patterns
- Normalization

**parse-paste.php** يخدم **Import Phase الكاملة**:
- Multi-row detection
- Table structure parsing
- Auto-matching بعد الاستخراج
- Timeline recording
- Duplicate detection

#### ما الخطر من الدمج؟

```
الفرصة: استخدام TextParsingService داخل parse-paste
  ✅ Reduce duplication
  ✅ Testable parsing logic
  ⚠️ لكن: يحتاج توسيع Service أولاً

الخطر الحالي: لو استخدمنا Service كما هو:
  ❌ لن يغطي multi-row
  ❌ لن يغطي table detection
  ❌ سيكسر استيراد معقد
```

**القرار**: **توسيع Service ثم استخدامه** (Refactor مستقبلي آمن)

---

### 2.2 ActionService vs extend/reduce/release.php

#### الملفات المكررة:
- `ActionService.php` (clean، غير مستخدم)
- `extend.php`, `reduce.php`, `release.php` (منطق مكرر)

#### لماذا التكرار موجود؟

**Service موجود لكن APIs لا تستخدمه**:

```
التطور:
  1. APIs كُتبت أولاً (inline logic)
  2. ActionService أُنشئ لاحقاً (refactor جزئي)
  3. APIs لم تُحدّث (legacy)

النتيجة: منطق العمل موجود مرتين
```

#### أي منطق يخدم؟

**كلاهما يخدم نفس المنطق**:
- القاعدة #10 (شرط الجاهزية)
- القاعدة #8 (إلغاء القفل عند التعديل)
- إنشاء Action + Timeline event

**الفرق**: ActionService **أنظف**:
- Separation of concerns
- Testable
- Reusable

#### ما الخطر من الدمج؟

```
الفرصة: استخدام ActionService في APIs
  ✅ Safe refactor
  ✅ Reduce 400+ lines duplication
  ⏱️ Effort: Low

الخطر: صفر (إذا حافظنا على API response format)
  ✅ نفس المنطق
  ✅ نفس Validation
  ✅ نفس Timeline events
```

**القرار**: **استخدام ActionService فوراً** (Quick win)

---

## 3. خريطة الاستدعاءات المنطقية

### 3.1 Import Flow

```
المرحلة: Import Phase (00:00 - 00:10)

User Action: Paste text / Upload Excel
  ↓
parse-paste.php OR import.php
  ↓ [Extract data]
  ↓
ImportService::importFromExcel / createManually
  ↓ [Normalize]
  ↓
GuaranteeRepository::create
  ↓ [Save to DB]
  ↓
TimelineRecorder::recordImportEvent
  ↓ [Log history]
  ↓
SmartProcessingService::processNewGuarantees
  ↓ [Auto-match]
  ↓
IF confidence >= 90%:
  GuaranteeDecisionRepository::create (auto-decision)
  TimelineRecorder::recordAutoMatchEvents
```

**القاعدة المطبقة**: القاعدة #11 (التعلم من كل قرار)

---

### 3.2 Decision Flow

```
المرحلة: Decision Phase (01:00 - 02:07)

User Action: Load record
  ↓
get-record.php
  ↓ [Fetch guarantee]
  ↓
IF status = pending:
  SmartProcessingService (retry auto-match)
  ↓
UnifiedLearningAuthority::getSuggestions
  ↓ [5 Signal Feeders]
  ↓
Return: HTML با suggestions

---

User Action: Select supplier / Save
  ↓
save-and-next.php
  ↓ [Validate]
  ↓
CHECK: supplier name matches current input?
  IF mismatch → Clear supplier_id (القاعدة #8)
  ↓
GuaranteeDecisionRepository::createOrUpdate
  ↓
TimelineRecorder::recordDecisionEvent
  ↓
LearningRepository::logDecision (feedback)
  ↓
Navigate to next record
```

**القاعدة المطبقة**: القاعدة #1 (سيادة القرار البشري)

---

### 3.3 Action Flow

```
المرحلة: Action Phase (03:00 - 04:05)

User Action: Click "تمديد"
  ↓
extend.php
  ↓ [Validate status = ready] (القاعدة #10)
  ↓
IF status != ready: REJECT
  ↓
Calculate new expiry (+1 year)
  ↓
GuaranteeActionRepository::create (pending)
  ↓
GuaranteeRepository::updateRawData (new expiry)
  ↓
TimelineRecorder::recordExtensionEvent
  ↓
active_action = 'extension' (القاعدة #8)

---

User Action: Print letter
  ↓
Letter HTML generation
  ↓
TimelineRecorder (save HTML snapshot) (القاعدة #12)
  ↓
GuaranteeActionRepository::markAsIssued
  ↓
User prints
```

**القاعدة المطبقة**: القاعدة #8 + #10 + #12

---

## 4. ربط الملفات بالأهداف التشغيلية

### 4.1 index.php

**الهدف التشغيلي**: معالجة سجل واحد كاملاً

**المرحلة الزمنية**: Decision Phase (01:00 - 02:07)

**السلوك الذي لا يجب أن يتغير**:
- ✅ "حفظ والتالي" ينتقل دائماً (حتى مع أخطاء)
- ✅ المعاينة تتحدث في الوقت الفعلي
- ✅ Timeline يظهر كل شيء
- ✅ الاقتراحات تظهر فوراً عند الكتابة

**ما سيحدث لو تغير**:
- تغيير DOM structure → كسر JavaScript
- تغيير URL params → كسر Navigation
- تغيير response format → كسر AJAX

---

### 4.2 parse-paste.php

**الهدف التشغيلي**: تحويل نص فوضوي إلى بيانات منظمة

**المرحلة الزمنية**: Import Phase (00:00 - 00:05)

**السلوك الذي لا يجب أن يتغير**:
- ✅ Multi-row detection (20+ patterns)
- ✅ Sequential consumption parsing
- ✅ Duplicate detection و Timeline logging
- ✅ Auto-matching بعد Import مباشرة

**ما سيحدث لو تغير**:
- تغيير regex → فشل استخراج
- إزالة multi-row → كسر table imports
- تغيير response → كسر modal

---

### 4.3 save-and-next.php

**الهدف التشغيلي**: حفظ القرار + الانتقال للتالي

**المرحلة الزمنية**: Decision Phase (02:00 - 02:07)

**السلوك الذي لا يجب أن يتغير**:
- ✅ Supplier name mismatch detection
- ✅ Active action clearing (ADR-007)
- ✅ Learning feedback logging
- ✅ Navigation logic (prev/next IDs)

**ما سيحدث لو تغير**:
- إزالة mismatch check → stale IDs (Bug)
- عدم clear active_action → stale letters (القاعدة #8)
- تغيير navigation → user lost

---

### 4.4 TimelineRecorder

**الهدف التشغيلي**: append-only audit trail

**المرحلة الزمنية**: كل المراحل (00:00 - 04:05)

**السلوك الذي لا يجب أن يتغير**:
- ✅ Append-only (لا UPDATE/DELETE) (القاعدة #4)
- ✅ Full snapshot في كل حدث
- ✅ Letter HTML snapshot عند الطباعة (القاعدة #12)
- ✅ Sequential numbering

**ما سيحدث لو تغير**:
- السماح بـ UPDATE → كسر audit trail (Bug قانوني)
- عدم snapshot → لا proof للقرارات
- تغيير event types → كسر contracts

---

### 4.5 UnifiedLearningAuthority

**الهدف التشغيلي**: اقتراحات ذكية بناءً على التعلم

**المرحلة الزمنية**: Decision Phase (01:02 - 01:03)

**السلوك الذي لا يجب أن يتغير**:
- ✅ 5 Signal Feeders (Alias، Anchor، Historical، Fuzzy، Learning)
- ✅ Confidence threshold (< 70% = صمت) (القاعدة #6)
- ✅ Ranked suggestions (أعلى ثقة أولاً)
- ✅ لا override لقرار يدوي (القاعدة #1)

**ما سيحدث لو تغير**:
- خفض threshold → ضجيج (violations القاعدة #6)
- تغيير ranking → user confusion
- override قرار → فقدان ثقة (القاعدة #1)

---

## 5. Decision Matrix: متى نحتفظ بالتكرار؟

| السيناريو | التكرار مشروع؟ | السبب |
|-----------|----------------|-------|
| **نفس الوظيفة، سياقين مختلفين** | ✅ نعم | Quick add vs Full management |
| **نفس الوظيفة، مراحل مختلفة** | ✅ نعم | Import vs Decision vs Action |
| **نفس الوظيفة، contracts مختلفة** | ✅ نعم | Modal response vs Page refresh |
| **نفس الوظيفة، Service موجود غير مستخدم** | ❌ لا | Use Service (refactor آمن) |
| **نفس الوظيفة، inline duplication** | ❌ لا | Extract to helper |

---

## الخلاصة

**التكرار في BGL ليس عشوائياً - هو map لـ business context.**

**قبل دمج أي تكرار، اسأل**:
1. هل السياقان متطابقان فعلاً؟
2. هل contracts الاستدعاء متطابقة؟
3. هل المرحلة الزمنية واحدة؟
4. هل سيكسر القاعدة #1-#15؟

**إذا الإجابة "لا" على أي سؤال → التكرار مشروع.**

---

**المرجع**: System Understanding Lock + Non-Negotiable Rules  
**Status**: ✅ Operational Map Ready  
**Use**: قبل أي Refactor Decision
