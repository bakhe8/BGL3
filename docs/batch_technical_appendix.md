# ملحق تقني: التفاصيل المعتمدة لتنفيذ نظام الدفعات

> **ملحق للوثيقة الأساسية batch_concept.md**
> يحتوي على التفاصيل التقنية المعتمدة فقط

---

## 1) تحسين import_source (معتمد ✅)

### المشكلة المحتملة
استيراد ملفين في نفس الثانية → نفس import_source

### الحل المعتمد
إضافة اسم الملف للتفرّد والوضوح:

```php
// في ImportService.php:importFromExcel()

// استخراج اسم الملف (موجود في api/import.php)
$filename = pathinfo($_FILES['file']['name'], PATHINFO_FILENAME);

// تنظيف الاسم
$cleanName = preg_replace('/[^a-zA-Z0-9_]/', '', $filename);
$cleanName = substr($cleanName, 0, 30);  // حد أقصى 30 حرف

// import_source النهائي
$importSource = 'excel_' . date('Ymd_His') . '_' . $cleanName;

// مثال: excel_20260105_143502_guarantees_jan2026
```

**الفوائد**:
- ✅ يمنع التصادم
- ✅ أسماء دفعات واضحة للمستخدم
- ✅ لا تغيير في الفلسفة (import_source يبقى المعرف الوحيد)

---

## 2) الإدخال اليدوي واللصق المباشر (معتمد ✅)

### القاعدة المعتمدة
دفعة يومية واحدة لكل الإدخالات اليدوية

```php
// في ImportService.php:createManually()
$importSource = 'manual_' . date('Ymd');
// Result: manual_20260105

// في SmartPasteService (إذا وُجد)
$importSource = 'smartpaste_' . date('Ymd');
// Result: smartpaste_20260105
```

**النتيجة**:
- كل الإدخالات اليدوية في نفس اليوم = دفعة واحدة
- كل اللصق المباشر في نفس اليوم = دفعة واحدة
- بسيط وواضح للمستخدم

---

## 3) معاينة الطباعة (معتمد ✅)

### الهدف
حماية المستخدم من طباعة خطابات غير جاهزة

### التطبيق

**الخطوة 1: صفحة المعاينة**
```php
// views/batch-print-preview.php?import_source=excel_20260105_143502

// جلب كل الضمانات في الدفعة
$guarantees = $db->prepare("
    SELECT g.*, d.status, d.supplier_id, d.bank_id
    FROM guarantees g
    LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
    WHERE g.import_source = ?
")->execute([$importSource])->fetchAll();

// تصنيف: جاهز / غير جاهز
$ready = [];
$notReady = [];

foreach ($guarantees as $g) {
    if ($g['status'] === 'approved' && $g['supplier_id'] && $g['bank_id']) {
        $ready[] = $g;
    } else {
        $notReady[] = [
            'guarantee' => $g,
            'reason' => !$g['supplier_id'] ? 'لم يُختر المورد' : 'لم يُعتمد'
        ];
    }
}
```

**الخطوة 2: عرض النتائج**
```html
<div class="preview-summary">
    <h2>معاينة الطباعة</h2>
    <p>✅ جاهز: <?= count($ready) ?></p>
    <p>❌ غير جاهز: <?= count($notReady) ?></p>
</div>

<?php if (count($notReady) > 0): ?>
<table class="not-ready-list">
    <thead><tr><th>رقم الضمان</th><th>السبب</th></tr></thead>
    <tbody>
        <?php foreach ($notReady as $item): ?>
        <tr>
            <td><?= $item['guarantee']['guarantee_number'] ?></td>
            <td><?= $item['reason'] ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<div class="actions">
    <?php if (count($ready) > 0): ?>
    <button onclick="printReady()">
        طباعة الجاهز فقط (<?= count($ready) ?>)
    </button>
    <?php endif; ?>
    <button onclick="window.close()">إلغاء</button>
</div>
```

**الفوائد**:
- ✅ شفافية كاملة
- ✅ لا مفاجآت
- ✅ لا تسجيل أحداث إضافية

---

## 4) العمليات الجماعية (معتمد ✅)

### القاعدة المعتمدة
**منع العملية بالكامل** إذا وُجدت ضمانات غير جاهزة

```php
// مثال: تمديد جماعي
function extendBatch($importSource, $newExpiryDate) {
    global $db;
    
    // جلب كل الضمانات
    $guarantees = $db->prepare("
        SELECT g.id, g.guarantee_number, d.status, d.supplier_id
        FROM guarantees g
        LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
        WHERE g.import_source = ?
    ")->execute([$importSource])->fetchAll();
    
    // فحص الجاهزية - الكل أو لا شيء
    $notReady = [];
    foreach ($guarantees as $g) {
        if ($g['status'] !== 'approved' || !$g['supplier_id']) {
            $notReady[] = $g['guarantee_number'];
        }
    }
    
    // ❌ إذا وُجد غير جاهز → منع بالكامل
    if (!empty($notReady)) {
        return [
            'success' => false,
            'error' => 'لا يمكن التمديد الجماعي',
            'reason' => 'بعض الضمانات غير جاهزة',
            'not_ready_count' => count($notReady),
            'not_ready_list' => $notReady
        ];
    }
    
    // ✅ الكل جاهز → تنفيذ
    $extended = [];
    foreach ($guarantees as $g) {
        // ... تمديد logic ...
        $extended[] = $g['id'];
    }
    
    return [
        'success' => true,
        'extended_count' => count($extended)
    ];
}
```

**الفوائد**:
- ✅ آمن (لا نتائج جزئية)
- ✅ واضح (رسالة محددة)
- ✅ متوقع (لا مفاجآت)

---

## 5) قواعد إغلاق الدفعة (معتمد ✅)

### التعريف
إغلاق الدفعة = **انتهاء ملف العمل الجماعي**

### الجدول الكامل

| الإجراء | دفعة مفتوحة (active) | دفعة مغلقة (completed) |
|---------|---------------------|----------------------|
| **تمديد جماعي** | ✅ مسموح | ❌ ممنوع |
| **إفراج جماعي** | ✅ مسموح | ❌ ممنوع |
| **تخفيض جماعي** | ✅ مسموح | ❌ ممنوع |
| **طباعة جماعية** | ✅ مسموح | ⚠️ **يحتاج قرار** |
| **فتح ضمان منفرد** | ✅ مسموح | ✅ مسموح |
| **تمديد/إفراج فردي** | ✅ مسموح | ✅ مسموح |

### التطبيق

```php
// في batch operations endpoints
function checkBatchStatus($importSource, $operation) {
    global $db;
    
    // البحث عن batch_metadata
    $batch = $db->prepare("
        SELECT status FROM batch_metadata 
        WHERE import_source = ?
    ")->execute([$importSource])->fetch();
    
    // إذا لم يُنشأ metadata → دفعة ضمنية (active)
    if (!$batch) {
        return ['allowed' => true];
    }
    
    // فحص الحالة
    if ($batch['status'] === 'completed') {
        
        // العمليات المسموحة على دفعات مغلقة
        $allowedOps = ['view', 'view_single'];
        // ⚠️ هل نضيف 'print'؟ يحتاج قرار
        
        if (!in_array($operation, $allowedOps)) {
            return [
                'allowed' => false,
                'error' => 'الدفعة مغلقة',
                'hint' => 'يمكنك فتح أي ضمان منفرد والعمل عليه'
            ];
        }
    }
    
    return ['allowed' => true];
}
```

---

## 6) معالجة الضمانات المكررة (معتمد ✅)

### الوضع الحالي
- ملف Excel فيه 21 ضمان
- 3 منهم مكررة (موجودة بالفعل)
- النتيجة: imported = 18 فقط

### الحل المعتمد
**تسجيل حدث duplicate في Timeline فقط**

```php
// في ImportService.php:importFromExcel()

foreach ($dataRows as $row) {
    try {
        // محاولة الإنشاء
        $created = $this->guaranteeRepo->create($guarantee);
        $imported++;
        
        // تسجيل حدث استيراد
        TimelineRecorder::recordImportEvent($created->id, 'excel');
        
    } catch (PDOException $e) {
        // فحص UNIQUE constraint
        if (strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
            
            // البحث عن الضمان الموجود
            $existing = $this->guaranteeRepo->findByNumber($guaranteeNumber);
            
            if ($existing) {
                // ✅ تسجيل حدث duplicate
                TimelineRecorder::recordDuplicateImportEvent(
                    $existing->id, 
                    'excel'
                );
                $duplicates++;
            }
        } else {
            throw $e;
        }
    }
}

return [
    'imported' => $imported,        // 18
    'duplicates' => $duplicates,    // 3
    'total_rows' => count($dataRows) // 21
];
```

**النتيجة**:
- الدفعة تحتوي 18 ضمان (الجدد فقط)
- Timeline يوضح محاولة إعادة الاستيراد للـ 3
- المستخدم يرى: "تم استيراد 18 من 21 (3 مكررة)"

**لا batch_items مطلوب** ✅

---

## 7) Schema النهائي (لا تغيير)

```sql
CREATE TABLE batch_metadata (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    import_source TEXT NOT NULL UNIQUE,
    
    -- حقول المستخدم فقط
    batch_name TEXT,
    batch_notes TEXT,
    status TEXT DEFAULT 'active' CHECK(status IN ('active', 'completed', 'archived'))
);

CREATE INDEX idx_batch_metadata_source ON batch_metadata(import_source);
CREATE INDEX idx_batch_metadata_status ON batch_metadata(status);
```

**3 حقول فقط. لا إضافات.**

---

## 8) ما تم تأجيله (لا يُنفذ الآن)

### ❌ تسجيل نوع الإدراج (new/reimport/manual_add)
- Timeline يغطي الحاجة
- تعقيد غير مطلوب حالياً
- يمكن إضافته لاحقاً إذا لزم

### ❌ Session Context للإضافة لدفعة مفتوحة
- import_source immutable
- لا نغير هذا المبدأ
- الإدخال اليدوي يذهب لدفعة يومية منفصلة

---

## 9) قرار مطلوب

### ⚠️ الطباعة الجماعية بعد إغلاق الدفعة

**السؤال**: 
هل نسمح بطباعة كل خطابات الدفعة بعد إغلاقها؟

**الحجة لصالح "نعم"**:
- الطباعة قراءة فقط (لا تعديل)
- مفيدة للمراجعة والأرشفة
- لا تؤثر على مبدأ "freeze membership"

**الحجة لصالح "لا"**:
- إغلاق = انتهاء كل عمل جماعي
- الطباعة الفردية متاحة دائماً

**التوصية**: السماح (قراءة فقط)

---

## التقدير الزمني النهائي

| المكون | الوقت |
|--------|-------|
| تحديث ImportService (اسم ملف + duplicates) | 1 ساعة |
| batch_metadata table + UI | 1 ساعة |
| Batch operations (extend/release) | 1 ساعة |
| Print preview page | 0.5 ساعة |
| Testing | 0.5 ساعة |
| **المجموع** | **4 ساعات** |
