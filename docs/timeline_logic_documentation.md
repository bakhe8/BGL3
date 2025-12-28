# وثيقة منطق التايم لاين الكاملة
## BGL System v3.0 - Timeline Logic Documentation

---

## 📋 جدول المحتويات

1. [المفاهيم الأساسية](#المفاهيم-الأساسية)
2. [بنية البيانات](#بنية-البيانات)
3. [منطق إنشاء الأحداث](#منطق-إنشاء-الأحداث)
4. [منطق الـ Snapshots](#منطق-الـ-snapshots)
5. [عرض التايم لاين](#عرض-التايم-لاين)
6. [السيناريوهات المختلفة](#السيناريوهات-المختلفة)
7. [القواعد الذهبية](#القواعد-الذهبية)
8. [استكشاف الأخطاء](#استكشاف-الأخطاء)

---

## المفاهيم الأساسية

### 1. التايم لاين (Timeline)
**التعريف:** السجل التاريخي الكامل لجميع الأحداث التي حدثت على ضمان بنكي، مرتبة زمنياً من الأقدم إلى الأحدث.

**الهدف:**
- تتبع دورة حياة الضمان البنكي
- توفير شفافية كاملة لجميع التغييرات
- إمكانية الرجوع لأي نقطة زمنية سابقة

### 2. الحدث (Event)
**التعريف:** نقطة زمنية محددة حدث فيها تغيير على الضمان البنكي.

**أنواع الأحداث:**

| النوع | event_type | event_subtype | الوصف |
|-------|-----------|---------------|-------|
| استيراد | import | excel/manual/smart_paste | أول إدخال للضمان في النظام |
| تطابق تلقائي | auto_matched | ai_match | النظام طابق المورد تلقائياً |
| تطابق يدوي | modified | manual_edit | المستخدم طابق المورد أو البنك يدوياً |
| تمديد | modified | extension | تمديد تاريخ الانتهاء |
| تخفيض | modified | reduction | تخفيض قيمة الضمان |
| إفراج | modified/released | release | إفراج الضمان |
| تغيير حالة | status_change | status_change | تغيير تلقائي في الحالة |

### 3. اللقطة (Snapshot)
**التعريف:** صورة كاملة لحالة الضمان **قبل** حدوث الحدث.

**محتويات اللقطة:**
```json
{
  "guarantee_number": "ABC123",
  "contract_number": "CT-001",
  "amount": 10000,
  "expiry_date": "2024-12-31",
  "issue_date": "2024-01-01",
  "type": "Initial",
  "supplier_id": 123,
  "supplier_name": "شركة XYZ",
  "bank_id": 5,
  "bank_name": "البنك الأهلي",
  "status": "approved"
}
```

---

## بنية البيانات

### جدول guarantee_history

```sql
CREATE TABLE guarantee_history (
    id INTEGER PRIMARY KEY,
    guarantee_id INTEGER NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_subtype VARCHAR(50),
    snapshot_data TEXT,           -- JSON: حالة الضمان قبل الحدث
    event_details TEXT,           -- JSON: تفاصيل التغيير
    created_at DATETIME NOT NULL,
    created_by VARCHAR(100),
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id)
);
```

### مثال على event_details:

```json
{
  "action": "تطابق يدوي",
  "changes": [
    {
      "field": "bank_id",
      "old_value": {
        "name": "Saudi Investment Bank",
        "id": null
      },
      "new_value": {
        "name": "البنك السعودي للاستثمار",
        "id": 7
      }
    }
  ]
}
```

---

## منطق إنشاء الأحداث

### القاعدة الأساسية

**كل حدث يتبع نمط ثلاثي:**

```php
// 1. SNAPSHOT: التقاط الحالة قبل التعديل
$oldSnapshot = TimelineRecorder::createSnapshot($guaranteeId);

// 2. UPDATE: تنفيذ التعديل الفعلي
$guaranteeRepo->updateRawData($guaranteeId, $newData);

// 3. RECORD: تسجيل الحدث مع الـ snapshot
TimelineRecorder::recordEvent(
    $guaranteeId,
    $eventType,
    $oldSnapshot,  // ← الحالة قبل التعديل
    $changes,
    $createdBy
);
```

### أمثلة عملية

#### 1. حدث الاستيراد (Import)

**الموقع:** `api/import.php`

```php
// لا يوجد snapshot قبل الاستيراد (أول حدث)
TimelineRecorder::recordImportEvent($guaranteeId, 'excel');
```

**الـ Snapshot:**
```json
{
  "supplier_name": "MEDICAL SUPPLIES CO.",
  "supplier_id": null,
  "bank_name": "Saudi Investment Bank",
  "bank_id": null,
  "amount": 50000,
  "expiry_date": "2025-12-31",
  "status": "pending"
}
```

#### 2. حدث التطابق التلقائي (Auto-Match)

**الموقع:** `SmartProcessingService.php`

```php
// Snapshot = الحالة من Excel (قبل المطابقة)
$snapshot = [
    'supplier_name' => $rawData['supplier'],  // MEDICAL SUPPLIES CO.
    'supplier_id' => null,
    'bank_name' => $rawData['bank'],          // Saudi Investment Bank
    'bank_id' => null,
    'status' => 'pending'
];

TimelineRecorder::recordEvent(
    $guaranteeId,
    'auto_matched',
    $snapshot,
    $changes,
    'System AI'
);
```

#### 3. حدث التمديد (Extension)

**الموقع:** `api/extend.php`

```php
// 1. Snapshot قبل التمديد
$oldSnapshot = TimelineRecorder::createSnapshot($guaranteeId);
// النتيجة: {
//   "supplier_id": 123,
//   "supplier_name": "شركة التوريدات...",
//   "bank_id": null,              ← لم يُطابق بعد!
//   "bank_name": "Saudi Investment Bank",
//   "expiry_date": "2024-12-31",  ← قبل التمديد
//   "amount": 50000,
//   "status": "pending"
// }

// 2. تنفيذ التمديد
$newExpiry = date('Y-m-d', strtotime($oldExpiry . ' +1 year'));
$guaranteeRepo->updateRawData($guaranteeId, $newData);

// 3. تسجيل الحدث
TimelineRecorder::recordExtensionEvent(
    $guaranteeId,
    $oldSnapshot,  // ← يحتوي على الحالة قبل التمديد
    $newExpiry
);
```

**النقطة الحرجة:** حتى لو تمت مطابقة البنك **لاحقاً**، الـ snapshot المحفوظ **لن يتغير**!

---

## منطق الـ Snapshots

### 1. إنشاء Snapshot جديد

**الدالة:** `TimelineRecorder::createSnapshot($guaranteeId)`

**المصادر:**
```php
public static function createSnapshot($guaranteeId, $decisionData = null) {
    if (!$decisionData) {
        // Join مع guarantee_decisions للحصول على الحالة الحالية
        $stmt = $db->prepare("
            SELECT 
                g.raw_data,
                d.supplier_id,
                s.official_name as supplier_name,
                d.bank_id,
                b.arabic_name as bank_name,
                d.status
            FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            LEFT JOIN suppliers s ON s.id = d.supplier_id
            LEFT JOIN banks b ON b.id = d.bank_id
            WHERE g.id = ?
        ");
        $stmt->execute([$guaranteeId]);
        $data = $stmt->fetch();
    }
    
    $rawData = json_decode($data['raw_data'], true);
    
    return [
        'guarantee_number' => $rawData['guarantee_number'],
        'amount' => $rawData['amount'],
        'expiry_date' => $rawData['expiry_date'],
        // ... من raw_data
        
        'supplier_id' => $data['supplier_id'],      // من guarantee_decisions
        'supplier_name' => $data['supplier_name'],  // من suppliers table
        'bank_id' => $data['bank_id'],              // من guarantee_decisions
        'bank_name' => $data['bank_name'],          // من banks table
        'status' => $data['status']                 // من guarantee_decisions
    ];
}
```

**⚠️ تحذير مهم:**
هذه الدالة تأخذ البيانات من **الحالة الحالية** للجداول. 
**يجب استخدامها فقط عند إنشاء حدث جديد، وليس لإعادة بناء snapshot قديم!**

### 2. حفظ Snapshot

**كل** snapshot يُحفظ في `guarantee_history.snapshot_data` كـ JSON:

```php
INSERT INTO guarantee_history (
    guarantee_id,
    event_type,
    snapshot_data,  -- ← يُحفظ هنا!
    event_details,
    created_at,
    created_by
) VALUES (?, ?, ?, ?, ?, ?)
```

### 3. استرجاع Snapshot

**الواجهة:** `partials/timeline-section.php`

```php
// يستخدم snapshot_data المحفوظ (لا يعيد بناءه!)
<div data-snapshot='<?= htmlspecialchars($event['snapshot_data'] ?? '{}') ?>'>
```

**JavaScript:** `public/js/timeline.controller.js`

```javascript
const snapshot = JSON.parse(card.dataset.snapshot);
// يعرض البيانات المحفوظة كما هي
```

---

## عرض التايم لاين

### 1. استرجاع الأحداث

**الموقع:** `index.php`

```php
$stmt = $db->prepare("
    SELECT * FROM guarantee_history
    WHERE guarantee_id = ?
    ORDER BY created_at DESC  -- من الأحدث للأقدم
");
$stmt->execute([$guaranteeId]);
$timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

### 2. تحديد التسمية

**الموقع:** `TimelineRecorder::getEventDisplayLabel()`

```php
public static function getEventDisplayLabel(array $event): string {
    $subtype = $event['event_subtype'] ?? '';
    $type = $event['event_type'] ?? '';
    
    // أولوية لـ event_subtype
    if ($subtype) {
        return match ($subtype) {
            'ai_match' => 'تطابق تلقائي',
            'manual_edit' => 'تطابق يدوي',
            'extension' => 'تمديد',
            'reduction' => 'تخفيض',
            'release' => 'إفراج',
            // ...
        };
    }
    
    // احتياطي: event_type
    if ($type === 'auto_matched') return 'تطابق تلقائي';
    if ($type === 'import') return 'استيراد';
    // ...
}
```

### 3. تحديد الأيقونة

```php
public static function getEventIcon(array $event): string {
    $label = self::getEventDisplayLabel($event);
    return match ($label) {
        'استيراد' => '📥',
        'تطابق تلقائي' => '🤖',
        'تطابق يدوي' => '✍️',
        'تمديد' => '⏱️',
        'تخفيض' => '💰',
        'إفراج' => '🔓',
        'تغيير حالة' => '🔄',
        default => '📝'
    };
}
```

### 4. تحديد المصدر

**الموقع:** `index.php`

```php
'source_badge' => in_array(
    $event['created_by'] ?? 'system',
    ['system', 'System', 'System AI', 'النظام', 'بواسطة النظام']
) ? '🤖 نظام' : '👤 مستخدم'
```

---

## السيناريوهات المختلفة

### السيناريو 1: الضمان المثالي

```
الزمن    الحدث                  snapshot_data                           النتيجة
────────────────────────────────────────────────────────────────────────────────
10:00   استيراد                supplier: MEDISERV (null)               ✅ من Excel
                               bank: Saudi Bank (null)
                               status: pending

10:01   تطابق تلقائي          supplier: MEDISERV (null)               ✅ snapshot قبل المطابقة
                               bank: Saudi Bank (null)
        → يطابق المورد         status: pending
        
12:00   تطابق يدوي            supplier: شركة التوريدات (123) ✓       ✅ المورد مطابق
                               bank: Saudi Bank (null)                  ✅ البنك لم يُطابق بعد
        → يطابق البنك          status: pending

12:01   تغيير حالة            supplier: شركة التوريدات (123) ✓       ✅ كل شيء مطابق
                               bank: البنك السعودي (7) ✓
        → approved             status: pending                          ✅ قبل تغيير الحالة
```

### السيناريو 2: تمديد قبل مطابقة البنك

```
الزمن    الحدث                  snapshot_data                           الملاحظات
────────────────────────────────────────────────────────────────────────────────
10:00   استيراد                supplier: MEDISERV (null)
                               bank: Saudi Bank (null)
                               expiry: 2024-12-31

10:01   تطابق تلقائي          supplier: MEDISERV (null)
        → مورد فقط             bank: Saudi Bank (null)

11:00   🔥 تمديد               supplier: شركة التوريدات (123) ✓       ✅ المورد مطابق
                               bank: Saudi Bank (null)                  ✅ البنك خام
                               expiry: 2024-12-31                       ✅ قبل التمديد

14:00   تطابق يدوي            supplier: شركة التوريدات (123) ✓
        → بنك                  bank: Saudi Bank (null)                  ⚠️ البنك لم يُطابق بعد
                               expiry: 2025-12-31                       ✅ بعد التمديد
```

**الحالة النهائية للضمان:**
- المورد: مطابق ✓
- البنك: مطابق ✓
- تاريخ الانتهاء: 2025-12-31

**لكن عند النقر على حدث "تمديد":**
- ✅ يعرض البنك **قبل** المطابقة (Saudi Bank)
- ✅ يعرض المورد **بعد** التطابق التلقائي (شركة التوريدات)
- ✅ يعرض التاريخ **قبل** التمديد (2024-12-31)

### السيناريو 3: تخفيض ثم إفراج

```
الزمن    الحدث                  snapshot_data                           
────────────────────────────────────────────────────────────────────────────────
10:00   استيراد                amount: 100000
                               status: pending

10:01   تطابق تلقائي          amount: 100000
                               status: pending

12:00   اعتماد                 amount: 100000
                               status: pending

12:01   تغيير حالة            amount: 100000
        → approved             status: pending                          ✅ قبل الاعتماد

15:00   تخفيض                 amount: 100000                           ✅ قبل التخفيض
        → 80000                status: approved

16:00   إفراج                  amount: 80000                            ✅ بعد التخفيض
                               status: approved                         ✅ قبل الإفراج
```

---

## القواعد الذهبية

### 1. قاعدة الترتيب الزمني

**كل حدث له timestamp = وقت حدوثه فعلياً:**

```php
// ❌ خطأ: استخدام NOW()
created_at = NOW()  // سيكون بتوقيت التشغيل، ليس وقت الحدث!

// ✅ صحيح: استخدام التوقيت الفعلي
created_at = $importedAt + 1  // للتطابق التلقائي
created_at = NOW()             // للأحداث الحالية فقط
```

### 2. قاعدة الـ Snapshot

**Snapshot = الحالة قبل الحدث (بعد جميع الأحداث السابقة):**

```php
// ✅ صحيح
$snapshot = createSnapshot($guaranteeId);  // قبل التعديل
updateGuarantee($guaranteeId, $newData);   // التعديل
recordEvent($guaranteeId, $type, $snapshot);  // تسجيل

// ❌ خطأ
updateGuarantee($guaranteeId, $newData);   // التعديل
$snapshot = createSnapshot($guaranteeId);  // بعد التعديل!
recordEvent($guaranteeId, $type, $snapshot);
```

### 3. قاعدة عدم التعديل

**Snapshots المحفوظة immutable (لا تتغير أبداً):**

```php
// ❌ لا تفعل هذا!
UPDATE guarantee_history 
SET snapshot_data = ...  
WHERE id = ?

// ✅ snapshot يُحفظ مرة واحدة فقط عند الإنشاء
```

### 4. قاعدة المصدر الموحد للحقيقة

**`guarantees.raw_data` هو المصدر الوحيد للحقيقة للبيانات الأساسية:**

- `amount`, `expiry_date`, `issue_date`, `guarantee_number` → من `raw_data`
- `supplier_id`, `bank_id`, `status` → من `guarantee_decisions`

### 5. قاعدة الأحداث المتسلسلة

**كل حدث يبني على الحدث السابق:**

```
Import → Auto-match → Manual match → Extension → Status change
  ↓          ↓             ↓             ↓             ↓
Excel    مورد مطابق    بنك مطابق    تاريخ جديد   حالة جديدة

كل snapshot يحفظ الحالة بعد جميع الأحداث السابقة!
```

---

## استكشاف الأخطاء

### مشكلة: Snapshot يعرض بيانات من المستقبل

**الأعراض:**
- عند النقر على حدث "تمديد"، البنك يظهر مطابقاً رغم أن المطابقة حدثت لاحقاً

**السبب:**
- `createSnapshot()` تم استدعاؤه **بعد** التعديل على `guarantee_decisions`
- أو تم إعادة بناء snapshot قديم باستخدام `createSnapshot()`

**الحل:**
```php
// ✅ استخدم snapshot المحفوظ
$snapshot = json_decode($event['snapshot_data'], true);

// ❌ لا تعيد بناء snapshot
$snapshot = TimelineRecorder::createSnapshot($guaranteeId);
```

### مشكلة: الأحداث بترتيب خاطئ

**الأعراض:**
- التطابق التلقائي يظهر **بعد** التطابق اليدوي زمنياً

**السبب:**
- `created_at` تم ضبطه على `NOW()` عند إعادة إنشاء الأحداث

**الحل:**
```php
// ✅ استخدم التوقيت الأصلي
$created_at = date('Y-m-d H:i:s', strtotime($importedAt) + 1);

// ❌ لا تستخدم NOW للأحداث التاريخية
$created_at = NOW();
```

### مشكلة: حدث مفقود من التايم لاين

**الأعراض:**
- المنطق يقول أن الحدث موجود (علامة ✓ بجانب المورد)
- لكن لا يوجد حدث في التايم لاين

**السبب المحتمل:**
- الحدث لم يُسجل أصلاً في `guarantee_history`
- الحدث تم حذفه بالخطأ (مثل `delete_illogical_events.php`)

**التحقق:**
```sql
-- تحقق من وجود decision بدون event
SELECT d.guarantee_id 
FROM guarantee_decisions d
WHERE d.supplier_id IS NOT NULL
AND NOT EXISTS (
    SELECT 1 FROM guarantee_history gh
    WHERE gh.guarantee_id = d.guarantee_id
    AND gh.event_type = 'auto_matched'
);
```

**الحل:**
- أعد إنشاء الحدث المفقود بالتوقيت الصحيح (`imported_at + 1`)

---

## الخلاصة

**المبادئ الأساسية:**

1. ✅ **كل حدث له snapshot واحد فقط** يُحفظ عند الإنشاء
2. ✅ **Snapshot = الحالة قبل الحدث** (بعد جميع الأحداث السابقة)
3. ✅ **Snapshots لا تتغير** بعد الحفظ (immutable)
4. ✅ **الترتيب الزمني مقدس** (`created_at` يعكس الوقت الفعلي)
5. ✅ **المصدر الموحد** (`raw_data` للبيانات، `decisions` للحالة)

**الضمانات:**

- ✅ عند النقر على أي حدث، ستعرض **بالضبط** حالة الضمان في تلك اللحظة
- ✅ لا تأثير رجعي: الأحداث اللاحقة **لا تؤثر** على snapshots السابقة
- ✅ شفافية كاملة: كل تغيير مسجل بدقة

---

**تاريخ التوثيق:** 28 ديسمبر 2024  
**الإصدار:** 3.0.0  
**المؤلف:** BGL Development Team
