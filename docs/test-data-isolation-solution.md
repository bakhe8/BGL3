# وثيقة تحليل المخاطر المعمارية - Test Data Isolation

## تقرير شامل محدّث بناءً على الكود الفعلي

**التاريخ:** 14 يناير 2026  
**النظام:** BGL3 v3.0  
**مستوى التقييم:** Production-Critical Architecture Review

---

## 🔍 ملخص تنفيذي

بعد المراجعة العميقة للكود الفعلي للنظام، تم الكشف عن **5 مخاطر معمارية حرجة** تتعلق بعزل بيانات الاختبار عن بيانات الإنتاج. هذا التقرير يحل محل الوثيقة الأولية ويقدم تحليلاً مبنياً على الواقع البرمجي للنظام.

### القضايا الحرجة المكتشفة

| # | الخطر | الخطورة | التأثير |
|---|-------|---------|---------|
| 1 | **تلوث نظام التعلم (Learning Pollution)** | 🔴 حرج | بيانات اختبار تُدرّب النظام بشكل دائم |
| 2 | **تلوث الإحصائيات بدون حماية افتراضية** | 🟡 عالي | تقارير غير دقيقة تؤثر على القرارات |
| 3 | **بيانات يتيمة في 8+ جداول** | 🟡 عالي | حذف غير نظيف يترك آثاراً خفية |
| 4 | **إساءة استخدام وضع الاختبار** | 🟠 متوسط | إخفاء أو حذف بيانات حقيقية عن طريق الخطأ |
| 5 | **فقدان الاستفادة من التجربة** | 🟠 متوسط | عزل كامل يمنع التعلم التجريبي المفيد |

---

## 📊 RISK 1: تلوث نظام التعلم (Learning Pollution)

### التحليل المبني على الكود الفعلي

#### أين يحدث التعلم؟

بناءً على فحص الملفات، تم تحديد **4 نقاط حقن تعلم رئيسية**:

**1. Smart Paste → Auto-Matching → Learning**

```php
// File: app/Services/ParseCoordinatorService.php (Line 447)
$processor = new \App\Services\SmartProcessingService('manual', 'web_user');
$autoMatchStats = $processor->processNewGuarantees($newCount);
```

**المسار:**

```
Smart Paste 
  → createGuaranteeFromExtracted()
    → triggerAutoMatching()
      → SmartProcessingService->processNewGuarantees()
        → UnifiedLearningAuthority->getSuggestions()
          → LearningRepository (writes to learning_confirmations)
```

**2. Manual Decisions → Learning Log**

```php
// File: app/Services/AutoAcceptService.php (Lines 51-54)
$this->learningLog->create([
    'guarantee_id' => $guaranteeId,
    'supplier' => $supplierName,
    ...
]);
```

**3. Confirmation/Rejection → Learning Table**

```php
// File: app/Repositories/LearningRepository.php (Line 75)
INSERT INTO learning_confirmations (
    supplier_id, original_text, ...
)
```

**4. Supplier Alias Usage → Weight Adjustment**

```php
// File: app/Repositories/SupplierLearningRepository.php (Line 79-82)
// SAFE_LEARNING: Log when usage is incremented
error_log(
   "[SAFE_LEARNING] Incremented usage_count for supplier_id=%d, alias='%s'",
    $supplierId, $alias
);
```

### السيناريو الخطر الفعلي

**⚠️ المشكلة:**  
إذا أدخل مستخدم 50 ضمان اختبار عبر Smart Paste:

1. ✅ السجلات تُنشأ بنجاح في `guarantees`
2. ✅ يتم تفعيل `triggerAutoMatching()` تلقائياً
3. ❌ **النظام يبدأ التعلم من بيانات وهمية**:
   - `UnifiedLearningAuthority` يستعلم عن تطابقات
   - `SupplierLearningRepository` يزيد `usage_count`
   - `LearningRepository` يسجل confirmations/rejections
   - **الأوزان يتم ضبطها بناءً على بيانات غير حقيقية**

4. 🗑️ يحذف المستخدم السجلات لاحقاً
5. ❌ **لكن التعلم يبقى!** الجداول التالية **لا تُحذف**:
   - `learning_confirmations`
   - `supplier_learning_cache`
   - `supplier_alternatives` (usage_count modified)

### الحل المقترح

#### ✅ Option A: Learning Gate (بوابة تعلم)

```php
// في SmartProcessingService->processNewGuarantees()

function processNewGuarantees($count) {
    // قبل بدء المعالجة
    $repo = new GuaranteeRepository($this->db);
    $guarantees = $repo->getLatestN($count);
    
    // 🛡️ SAFETY: تحقق من وضع الاختبار
    foreach ($guarantees as $g) {
        if ($g->is_test_data) {
            error_log("[LEARNING_GATE] Skipping test guarantee #{$g->id} - No learning");
            continue; // تخطي التعلم لهذا السجل
        }
        
        // معالجة عادية للسجلات الحقيقية فقط
        $this->processGuarantee($g);
    }
}
```

**في LearningRepository->logDecision():**

```php
function logDecision(array $data) {
    // 🛡️ CRITICAL: منع تسجيل قرارات الاختبار
    $guarantee = $this->guaranteeRepo->findById($data['guarantee_id']);
    
    if ($guarantee && $guarantee->is_test_data) {
        error_log("[LEARNING_GATE] Blocked learning from test guarantee #{$guarantee->id}");
        return; // لا تسجل
    }
    
    // تسجيل عادي للبيانات الحقيقية
    $stmt = $this->db->prepare("INSERT INTO learning_confirmations ...");
    // ...
}
```

**الفائدة:**

- ✅ منع دخول بيانات الاختبار لنظام التعلم من المصدر
- ✅ لا حاجة لتنظيف تعلم لاحق (لم يحدث أصلاً)
- ✅ حماية نظيفة وواضحة

---

## 📊 RISK 2: تلوث الإحصائيات بدون حماية

### نقاط إنتاج الإحصائيات المكتشفة

```php
// File: views/statistics.php
SELECT COUNT(*) FROM guarantees WHERE ...
SELECT SUM(amount) FROM guarantees WHERE ...
SELECT AVG(...) FROM guarantees WHERE ...
```

**⚠️ المشكلة الحالية:**  
لا يوجد أي من هذه الاستعلامات يحتوي على:

```sql
WHERE is_test_data = 0
```

### الحل المقترح

#### المستوى 1: Query Wrapper (غلاف استعلامات)

إنشاء Repository method يضيف الفلتر افتراضياً:

```php
// File: app/Repositories/GuaranteeRepository.php

/**
 * Get production-only guarantees (excludes test data by default)
 * 
 * @param bool $includeTestData Override to include test data
 */
function getProductionGuarantees(bool $includeTestData = false) {
    $query = "SELECT * FROM guarantees WHERE 1=1";
    
    // 🛡️ DEFAULT: Exclude test data
    if (!$includeTestData) {
        $query .= " AND (is_test_data = 0 OR is_test_data IS NULL)";
    }
    
    return $this->db->query($query)->fetchAll();
}

/**
 * Count production guarantees
 */
function countProduction(array $filters = []) {
    $query = "SELECT COUNT(*) as total FROM guarantees WHERE 1=1";
    
    // 🛡️ ALWAYS exclude test data in stats
    $query .= " AND (is_test_data = 0 OR is_test_data IS NULL)";
    
    // Add other filters...
    
    return $this->db->query($query)->fetch()['total'];
}
```

#### المستوى 2: تحديث Statistics View

```php
// في views/statistics.php - استبدال جميع الاستعلامات

// ❌ قبل:
$total = $db->query("SELECT COUNT(*) FROM guarantees")->fetch();

// ✅ بعد:
$total = $guaranteeRepo->countProduction();
```

**الفائدة:**

- ✅ حماية مركزية - كل التقارير آمنة افتراضياً
- ✅ Opt-in للاختبار (يجب طلبه صراحة)

---

## 📊 RISK 3: البيانات اليتيمة (Orphan Records)

### جرد الجداول المرتبطة

بناءً على فحص الكود، تم تحديد **8 جداول** مرتبطة بجدول `guarantees`:

| # | الجدول | العلاقة | خطر اليُتم |
|---|--------|---------|-----------|
| 1 | `guarantee_timeline` | `guarantee_id` | ⚠️ عالي |
| 2 | `guarantee_decisions` | `guarantee_id` | ⚠️ عالي |
| 3 | `guarantee_notes` | `guarantee_id` | 🟡متوسط |
| 4 | `guarantee_attachments` | `guarantee_id` | 🟡 متوسط |
| 5 | `learning_confirmations` | `guarantee_id` | 🔴 حرج |
| 6 | `supplier_alternatives` | modified via decisions | 🟠 غير مباشر |
| 7 | `batch_occurrences` | `guarantee_id` | 🟡 متوسط |
| 8 | `trust_decisions` | `guarantee_id` | 🟡 متوسط |

#### ⚠️ ملاحظة معمارية حرجة: `supplier_alternatives` لا يُنظف بالحذف

**السبب:**  
جدول `supplier_alternatives` هو **Shared State** (حالة مشتركة) - نفس السجل قد يُستخدم من قبل بيانات اختبار وبيانات حقيقية معاً.

**لماذا لا يمكن حذفه؟**

- لو حذفنا rows، قد نحذف aliases مستخدمة في بيانات حقيقية
- لو قللنا `usage_count`، لا نعرف كم استخدام كان من الاختبار بالضبط

**الحل المعماري:**  
✅ **لا نُنظف هذا الجدول بالحذف**  
✅ **بدلاً من ذلك:** نمنع التلوث من البداية عبر **Learning Gate**  
✅ بيانات الاختبار **لا تُحدث** `usage_count` أبداً → الجدول يبقى نظيفاً

### الحل المقترح (محدّث)

```php
// File: api/maintenance/delete-test-data.php

function deleteTestDataComplete($mode, $params) {
    $db = Database::connect();
    
    try {
        $db->beginTransaction();
        
        // 1. جمع IDs
        $idsToDelete = /* logic from previous doc */;
        
        if (empty($idsToDelete)) {
            return ['success' => true, 'deleted' => 0];
        }
        
        $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
        
        // 2. ✅ حذف من جميع الجداول المرتبطة (بالترتيب الصحيح)
        
        // Timeline first (no dependencies)
        $db->prepare("
            DELETE FROM guarantee_timeline 
            WHERE guarantee_id IN ($placeholders)
        ")->execute($idsToDelete);
        
        // Attachments
        $db->prepare("
            DELETE FROM guarantee_attachments 
            WHERE guarantee_id IN ($placeholders)
        ")->execute($idsToDelete);
        
        // Notes
        $db->prepare("
            DELETE FROM guarantee_notes 
            WHERE guarantee_id IN ($placeholders)
        ")->execute($idsToDelete);
        
        // Trust decisions
        $db->prepare("
            DELETE FROM trust_decisions 
            WHERE guarantee_id IN ($placeholders)
        ")->execute($idsToDelete);
        
        // 🔴 CRITICAL: Learning data
        $db->prepare("
            DELETE FROM learning_confirmations 
            WHERE guarantee_id IN ($placeholders)
        ")->execute($idsToDelete);
        
        // Decisions (may have FK to learning)
        $db->prepare("
            DELETE FROM guarantee_decisions 
            WHERE guarantee_id IN ($placeholders)
        ")->execute($idsToDelete);
        
        // Batch occurrences
        $db->prepare("
            DELETE FROM batch_occurrences 
            WHERE guarantee_id IN ($placeholders)
        ")->execute($idsToDelete);
        
        // 3. Finally, main table
        $db->prepare("
            DELETE FROM guarantees 
            WHERE id IN ($placeholders)
        ")->execute($idsToDelete);
        
        $db->commit();
        
        return [
            'success' => true,
            'deleted' => count($idsToDelete),
            'tables_cleaned' => 8
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("[DELETE_TEST_DATA] Failed: " . $e->getMessage());
        
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
```

---

## 📊 RISK 4: إساءة استخدام وضع الاختبار

### السيناريو الخطر

**مستخدم عادي يضع ضماناً حقيقياً كـ"اختبار" عن طريق الخطأ:**

1. يُدخل ضمان حقيقي بقيمة 5 مليون ريال
2. ✅ يضع علامة "وضع اختبار" بالخطأ (checkbox)
3. الضمان يُخفى من العرض الافتراضي (فلتر `real_only`)
4. **مدير يحذف "بيانات الاختبار"**
5. ❌ **الضمان الحقيقي يُحذف!**

### الحل: Governance Layer

```php
// في نموذج الإدخال اليدوي/اللصق

<div class="test-mode-toggle">
    <label class="warning-label">
        <input type="checkbox" 
               id="isTestData" 
               name="is_test_data" 
               value="1"
               onchange="confirmTestMode(this)">
        <span>🧪 هذا ضمان تجريبي</span>
    </label>
</div>

<script>
function confirmTestMode(checkbox) {
    if (checkbox.checked) {
        const confirmed = confirm(
            '⚠️ تحذير مهم:\\n\\n' +
            'أنت على وشك تحديد هذا الضمان كـ "بيانات اختبار".\\n\\n' +
            'بيانات الاختبار:\n' +
            '- لن تظهر في التقارير الرسمية\n' +
            '- لن تؤثر على الإحصائيات\n' + 
            '- يمكن حذفها جماعياً لاحقاًن' +
            '\\nهل أنت متأكد أن هذا ليس ضماناً حقيقياً؟'
        );
        
        if (!confirmed) {
            checkbox.checked = false;
        }
    }
}
</script>
```

**إضافة: زر "تحويل إلى حقيقي"**

```php
// في صفحة الضمان المعلّم كاختبار

<?php if ($guarantee->is_test_data): ?>
    <div class="test-data-actions">
        <button onclick="convertToProduction(<?= $guarantee->id ?>)">
            ♻️ تحويل إلى ضمان حقيقي
        </button>
    </div>
<?php endif; ?>

<script>
function convertToProduction(id) {
    if (confirm('هل تريد إزالة علامة "اختبار" من هذا الضمان؟')) {
        fetch('/api/convert-to-production', {
            method: 'POST',
            body: JSON.stringify({ guarantee_id: id })
        }).then(r => r.json()).then(data => {
            if (data.success) {
                location.reload();
            }
        });
    }
}
</script>
```

---

## 📊 RISK 5: فقدان الاستفادة من التجربة

### التحدي

**المشكلة:** إذا عزلنا بيانات الاختبار بالكامل عن التعلم، نفقد فرصة التعلم من تجارب حقيقية تتم في بيئة آمنة.

**مثال:** مطوّر يختبر ميزة Smart Paste بـ50 نصاً حقيقياً. النتائج قد تكون **مفيدة للتعلم**، لكن حالياً:

- ✅ يتم التعلم منها (خطر تلوث)
- ❌ أو لا يتم التعلم نهائياً (خسارة معرفة)

### الحل: Dual-Channel Learning Architecture

#### البنية المقترحة

```
┌─────────────────────────────────────┐
│   Dual-Channel Learning System      │
└─────────────────────────────────────┘
           ↓               ↓
  ┌────────────────┐  ┌───────────────┐
  │ PRODUCTION     │  │ EXPERIMENTAL  │
  │ Learning       │  │ Learning      │
  │ Channel        │  │ Channel       │
  └────────────────┘  └───────────────┘
         ↓                    ↓
   Always Active      Isolated Sandbox
   is_test_data=0     is_test_data=1
         ↓                    ↓
         └──────── ⬇ ────────┘
               Promotion Gate
            (Manual Review & Approve)
```

#### التطبيق العملي

**1. جدولين منفصلين:**

```sql
-- قناة الإنتاج (موجودة حالياً)
CREATE TABLE learning_confirmations (
    id INTEGER PRIMARY KEY,
    guarantee_id INTEGER,
    -- ... fields
);

-- قناة التجريب (جديدة)
CREATE TABLE learning_experimental (
    id INTEGER PRIMARY KEY,
    guarantee_id INTEGER,
    promoted_to_production BOOLEAN DEFAULT 0,
    review_status TEXT, -- 'pending', 'approved', 'rejected'
    reviewed_at DATETIME,
    reviewed_by TEXT,
    -- ... same fields as learning_confirmations
);
```

**2. Learning Gate المحدّث:**

```php
function logDecision(array $data) {
    $guarantee = $this->guaranteeRepo->findById($data['guarantee_id']);
    
    if ($guarantee->is_test_data) {
        // 🧪 تسجيل في القناة التجريبية
        $this->logToExperimentalChannel($data);
        error_log("[DUAL_LEARNING] Logged to experimental channel");
    } else {
        // ✅ تسجيل مباشر في قناة الإنتاج
        $this->logToProductionChannel($data);
    }
}
```

**3. واجهة المراجعة:**

```html
<!-- صفحة Settings > Learning Review -->
<div class="learning-review">
    <h3>مراجعة التعلم التجريبي</h3>
    
    <table>
        <tr>
            <th>النص الأصلي</th>
            <th>الموّرد المستخرج</th>
            <th>الثقة</th>
            <th>القرار</th>
            <th>الإجراء</th>
        </tr>
        <?php foreach ($experimentalLearnings as $exp): ?>
        <tr>
            <td><?= substr($exp->original_text, 0, 50) ?>...</td>
            <td><?= $exp->supplier_name ?></td>
            <td><?= $exp->confidence ?>%</td>
            <td><?= $exp->decision ?></td>
            <td>
                <button onclick="promote(<?= $exp->id ?>)">
                    ✅ ترقية للإنتاج
                </button>
                <button onclick="reject(<?= $exp->id ?>">
                    ❌ رفض
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
```

**4. عملية الترقية:**

```php
function promoteToProduction($experimentalId) {
    $db = Database::connect();
    $db->beginTransaction();
    
    try {
        // 1. جلب السجل التجريبي
        $exp = $db->prepare("
            SELECT * FROM learning_experimental WHERE id = ?
        ")->execute([$experimentalId])->fetch();
        
        // 2. نسخ إلى قناة الإنتاج
        $db->prepare("
            INSERT INTO learning_confirmations 
            (supplier_id, original_text, decision, ...)
            VALUES (?, ?, ?, ...)
        ")->execute([...]);
        
        // 3. تحديث حالة التجريبي
        $db->prepare("
            UPDATE learning_experimental 
            SET promoted_to_production = 1,
                review_status = 'approved',
                reviewed_at = ?,
                reviewed_by = ?
            WHERE id = ?
        ")->execute([date('Y-m-d H:i:s'), 'Admin', $experimentalId]);
        
        $db->commit();
        
        return ['success' => true];
        
    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
```

**الفوائد:**

- ✅ لا تلوث تلقائي
- ✅ استفادة من البيانات التجريبية بعد المراجعة
- ✅ شفافية كاملة
- ✅ قابلية التدقيق

#### حوكمة Dual Learning (Governance Limits)

**القيود الصريحة المطلوبة:**

1. **من يملك صلاحية الترقية؟**
   - يُنصح: مدير النظام أو مشرف التعلم فقط
   - يجب تسجيل هوية المُرقّي في `reviewed_by`

2. **هل الترقية Audited؟**
   - ✅ نعم - كل ترقية تُسجل في:`reviewed_at`, `reviewed_by`, `review_status`

3. **هل يمكن Rollback الترقية؟**
   - ⚠️ يجب تحديد السياسة:
     - **خيار آمن:** لا rollback - الترقية نهائية (مع تأكيد مزدوج)
     - **خيار مرن:** إضافة حقل `reverted` للتراجع الطارئ

4. **هل يُسمح بالترقية الجماعية؟**
   - ⚠️ يُنصح: **فردية فقط** لضمان المراجعة الدقيقة
   - إذا سُمح بالجماعية، يجب:
     - حد أقصى (مثلاً: 10 سجلات دفعة واحدة)
     - معاينة كاملة قبل التأكيد

**⚠️ ملاحظة: الإحصائيات الزمنية والـ Caching**

بعض التقارير قد تستخدم **Cached Aggregates** أو **Archived Snapshots**:

- Timeline-derived statistics
- Exported reports (CSV/PDF)
- Cached dashboard data

**التوصية:**  
✅ بعد حذف test data، يجب **إعادة بناء أي cache موجود**  
✅ إضافة زر "Refresh Cache" في صفحة الصيانة بعد التنظيف  
✅ توثيق أي cached layers في الكود

---

## 🎯 خطة التنفيذ النهائية

### Priority 1: منع التلوث (حرج)

- [ ] إضافة حقول DB (`is_test_data`, `test_batch_id`, `test_note`)
- [ ] تطبيق Learning Gate في جميع نقاط الحقن
- [ ] حماية الإحصائيات بـ Query Wrappers

**الجهد:** 6-8 ساعات  
**الخطورة:** منخفضة (فقط إضافة checks)

### Priority 2: التنظيف الآمن (مهم)

- [ ] كود الحذف الشامل لـ8 جداول
- [ ] واجهة أدوات الصيانة
- [ ] تأكيد مزدوج قبل الحذف

**الجهد:** 4-6 ساعات  
**الخطورة:** متوسطة (عمليات حذف)

### Priority 3: الحوكمة (موصى به)

- [ ] تحذير عند تفعيل وضع الاختبار
- [ ] زر "تحويل إلى إنتاج"
- [ ] تقارير وفلاتر منفصلة

**الجهد:** 2-3 ساعات  
**الخطورة:** منخفضة

### Priority 4: Dual Learning (اختياري - ممتاز)

- [ ] جدول `learning_experimental`
- [ ] تحديث Learning Gate للقنوات
- [ ] واجهة مراجعة وترقية

**الجهد:** 8-10 ساعات  
**الخطورة:** متوسطة (معماري)

---

## ⚠️ تحذيرات نهائية

### ما يجب عدم افتراضه

❌ **لا تفترض** أن حذف `guarantees` فقط كافٍ  
❌ **لا تفترض** أن الفلاتر ستُطبّق تلقائياً في كل مكان  
❌ **لا تفترض** أن المستخدمين لن يسيئوا استخدام وضع الاختبار  
❌ **لا تفترض** أن التعلم معزول بطبيعته

### ما يجب ضمانه

٪ **ضمان:** كل نقطة حقن تعلم محمية بـ `is_test_data` check  
✅ **ضمان:** كل query إحصائي يستبعد الاختبار افتراضياً  
✅ **ضمان:** كل عملية حذف تغطي جميع الجداول المرتبطة  
✅ **ضمان:** النسخ الاحتياطي إلزامي قبل أي حذف جماعي

---

**المرجع:** تحليل كود BGL3 v3.0 - 14 يناير 2026  
**الملفات المرجعية:**

- `app/Services/Learning/UnifiedLearningAuthority.php`
- `app/Services/ParseCoordinatorService.php`
- `app/Repositories/LearningRepository.php`
- `app/Repositories/SupplierLearningRepository.php`
- `app/Services/AutoAcceptService.php`

⚠️ 2️⃣ ملاحظات دقيقة للتحسين (Minor but Important)

هذه ليست أخطاء، بل تحسينات تجعل الوثيقة أقوى رسمياً.

✍️ ملاحظة لغوية / تنسيقية بسيطة

في أكثر من موضع يوجد التصاق أحرف:

مثلاً:

الوثيقةالأولية
نقاطإنتاج
السيناريوالخطر
بيانات الاختبار:ن

يفضل تصحيحها فقط للاحترافية.

🔒 ملاحظة معمارية #1 — supplier_alternatives لا يتم "تنظيفه"

أنت ذكرت:

supplier_alternatives (usage_count modified)

لكن في كود الحذف:
لم يتم عمل أي معالجة له.

وهذا صحيح من ناحية أن:

لا يمكن حذف rows بسهولة

لأنها Shared State

لكن من المهم توثيق هذا صراحة:

أن هذا الجدول لا يُنظف بالحذف
وإنما يتم تحييد التلوث عبر Learning Gate فقط.

أنصح بإضافة فقرة قصيرة تشرح هذا حتى لا يظن أحد لاحقاً أن هناك نقصاً.

🧪 ملاحظة #2 — Dual Learning يحتاج "حدود صريحة"

حالياً التصميم ممتاز، لكن أنصح بإضافة قيد حوكمة صغير:

من يملك صلاحية الترقية؟

هل الترقية Audit-ed؟

هل يمكن rollback الترقية؟

هل يسمح بالترقية الجماعية أم فردية فقط؟

ليس للتنفيذ الآن — فقط توثيق.

هذا يحميك مستقبلاً من فوضى معرفية.

📊 ملاحظة #3 — الإحصائيات الزمنية (Historical Drift)

بعض التقارير قد تكون مبنية على snapshots تاريخية:

Timeline derived stats

Archived exports

Cached aggregates

لو عندك أي caching طبقة (حتى بسيطة):
يفضل التنبيه أن cache يجب إعادة بنائه بعد حذف test data.

ذكرتها جزئياً — يمكن توكيدها أكثر.

🔍 3️⃣ Checklist اعتماد قبل إغلاق هذا الملف

أنصحك أن تطلب من المبرمج تأكيد هذه النقاط صراحة:

✅ تأكيدات إلزامية

 تم تحديد جميع نقاط حقن التعلم في الكود.

 تم تطبيق Learning Gate في كل نقطة.

 لا يوجد أي Query إحصائي مباشر خارج Repository.

 كل الجداول المرتبطة تم جردها وتوثيقها.

 حذف البيانات لا يترك orphan records.

 تم اختبار سيناريو:

إدخال Test

حدوث تعلم

الحذف

التأكد أن لا أثر معرفي بقي.

 تم توثيق Dual Learning كخيار معماري مستقبلي (حتى لو لم يُنفذ الآن).
