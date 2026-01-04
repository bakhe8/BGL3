# تحليل: نظام التعلم الآلي - تسجيل القرارات

## الوضع الحالي

### 1. LearningRepository (الملف المسؤول عن التسجيل)
**المكان**: `app/Repositories/LearningRepository.php`

**الدالة الرئيسية**: `logDecision()`
```php
public function logDecision(array $data): void
{
    $stmt = $this->db->prepare("
        INSERT INTO learning_confirmations (
            raw_supplier_name, supplier_id, confidence, matched_anchor, 
            anchor_type, action, decision_time_seconds, guarantee_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $data['raw_supplier_name'],
        $data['supplier_id'],
        $data['confidence'],
        $data['matched_anchor'] ?? null,
        $data['anchor_type'] ?? 'learned',
        $data['action'],  // ← هنا يتم تحديد 'confirm' أو 'reject'
        $data['decision_time_seconds'] ?? 0,
        $data['guarantee_id'] ?? null
    ]);
}
```

### 2. save-and-next.php (حفظ القرار)
**المكان**: `api/save-and-next.php` السطور 269-288

**ما يحدث حالياً:**
```php
if ($currentGuarantee && isset($currentGuarantee->rawData['supplier']) && $supplierId) {
    $learningRepo = new \App\Repositories\LearningRepository($db);
    
    // ✅ يسجل فقط عند اختيار مورد (confirm)
    $learningRepo->logDecision([
        'guarantee_id' => $guaranteeId,
        'raw_supplier_name' => $currentGuarantee->rawData['supplier'],
        'supplier_id' => $supplierId,
        'action' => 'confirm',  // ← دائماً 'confirm' فقط!
        'confidence' => 100,
        'decision_time_seconds' => 0
    ]);
}
```

**المشكلة:**
- ✅ يسجل `'confirm'` عند اختيار مورد
- ❌ **لا يسجل `'reject'`** عند رفض اقتراح!

### 3. كيف يتم رفض الاقتراحات؟

**السيناريوهات الممكنة:**

#### السيناريو 1: المستخدم يختار مورد مختلف
- يُعرض اقتراح "A" بثقة 90%
- المستخدم يختار "B" من القائمة
- ✅ يسجل `confirm` للمورد "B"
- ❌ **لا يسجل `reject` للمورد "A"**

#### السيناريو 2: المستخدم يكتب اسم جديد
- يُعرض اقتراح "A" بثقة 80%
- المستخدم يكتب اسم مورد جديد ويضيفه
- ✅ يسجل `confirm` للمورد الجديد
- ❌ **لا يسجل `reject` للمورد "A"**

#### السيناريو 3: المستخدم يضغط زر "رفض" (إن وُجد)
- لا يوجد زر رفض صريح في الواجهة حالياً!

## الحل المطلوب

### الخيار 1: تسجيل رفض ضمني (Implicit Rejection)
عند اختيار مورد **مختلف** عن الاقتراح الأول، نسجل:
1. `confirm` للمورد المختار
2. `reject` للاقتراح الأول الذي تم تجاهله

### الخيار 2: إضافة زر رفض صريح
إضافة زر "❌ رفض هذا الاقتراح" لكل اقتراح في الواجهة.

### الخيار 3: المزج بين الاثنين
- رفض ضمني للاقتراحات المتجاهلة
- رفض صريح عند الضغط على زر الرفض

## التوصية

**الخيار 1** هو الأسهل والأسرع:
- لا يحتاج تعديل في الواجهة
- يمكن تطبيقه فوراً في `save-and-next.php`
- منطقي: إذا اخترت "B" بدلاً من "A"، فأنت رفضت "A" ضمنياً

## الكود المطلوب إضافته

في `api/save-and-next.php` بعد حفظ القرار:

```php
// بعد تسجيل confirm للمورد المختار
if ($suggestions && count($suggestions) > 0) {
    $topSuggestion = $suggestions[0];
    
    // إذا المورد المختار مختلف عن الاقتراح الأول
    if ($topSuggestion['id'] != $supplierId) {
        // سجل رفض للاقتراح الأول
        $learningRepo->logDecision([
            'guarantee_id' => $guaranteeId,
            'raw_supplier_name' => $currentGuarantee->rawData['supplier'],
            'supplier_id' => $topSuggestion['id'],
            'action' => 'reject',
            'confidence' => $topSuggestion['score'],
            'decision_time_seconds' => 0
        ]);
    }
}
```
