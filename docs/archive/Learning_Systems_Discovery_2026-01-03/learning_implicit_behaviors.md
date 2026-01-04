# Learning Implicit Behaviors Catalog

## التقرير: السلوكيات الضمنية في أنظمة التعلم

**التاريخ**: 2026-01-03  
**الهدف**: توثيق كل سلوك يحدث **بدون قرار صريح** من المستخدم

---

## 🎯 تعريف السلوك الضمني

السلوك الضمني هو أي إجراء:
1. **يحدث تلقائياً** بدون طلب المستخدم المباشر
2. **side-effect** من إجراء آخر
3. **غير موثق** في واجهة المستخدم
4. **يؤثر على التعلم** بدون علم المستخدم

---

## IMPLICIT BEHAVIOR #1: Automatic Rejection Logging

### الوصف
**عندما يختار المستخدم مورداً مختلفاً عن الاقتراح الأول، يُسجل رفض ضمني للاقتراح الأول.**

### الموقع
`api/save-and-next.php:283-303`

### الكود الفعلي
```php
// ✅ Step 2: Log REJECTION for ignored top suggestion (implicit learning)
// Get current suggestions to identify what user ignored
$authority = \App\Services\Learning\AuthorityFactory::create();
$suggestions = $authority->getSuggestions($rawSupplierName);

if (!empty($suggestions)) {
    $topSuggestion = $suggestions[0];
    
    // If user chose DIFFERENT supplier than top suggestion → implicit rejection
    if ($topSuggestion->supplier_id != $supplierId) {
        $learningRepo->logDecision([
            'guarantee_id' => $guaranteeId,
            'raw_supplier_name' => $rawSupplierName,
            'supplier_id' => $topSuggestion->supplier_id,  ← المرفوض
            'action' => 'reject',
            'confidence' => $topSuggestion->confidence,
            'matched_anchor' => $topSuggestion->official_name,
            'decision_time_seconds' => 0
        ]);
    }
}
```

### المحفز
```
User action: Select Supplier B
System sees: Top Suggestion was Supplier A
Result: Log 'reject' for A (implicit)
```

### المستخدم يرى
- ✅ يرى اقتراح A
- ✅ يختار B
- ❌ **لا يرى** "rejecting A" message
- ❌ **لا يُسأل** "هل تريد رفض A?"

### التأثير المستقبلي
- Supplier A يحصل على rejection count +1
- في المرات القادمة، A confidence ينخفض
- **المستخدم لا يدرك** أنه "علّم" النظام

### هل هذا مقصود؟
✅ **YES** - موثق في `LEARNING_ANALYSIS.md`  
✅ **Implemented** - الكود موجود ويعمل

### هل هذا خطر؟
⚠️ **MAYBE**
- **إيجابي**: التعلم السريع من خيارات المست خدم
- **سلبي**: قد يرفض اقتراحاً جيداً بالخطأ (مثلاً المستخدم كان يجرب فقط)

---

## IMPLICIT BEHAVIOR #2: Historical Selection Counting (Passive)

### الوصف
**كل قرار يُحفظ يُضاف تلقائياً إلى التاريخ، حتى لو كان auto-match.**

### الموقع
System #3 (Historical Selections) - passive data collection

### الآلية
```
Decision created:
  guarantee_decisions.supplier_id = X
  ↓
Later (any time):
  HistoricalSignalFeeder queries:
    "How many times was X chosen for this name?"
  ↓
  Counts this decision automatically
```

### المستخدم يرى
- ❌ **لا يرى** "adding to history"
- ❌ **لا يُسأل** "save this as pattern?"

### التأثير المستقبلي
- Supplier X gets historical boost
- **حتى لو** القرار كان خاطئاً

### هل هذا مقصود؟
✅ **YES** - passive learning design

### هل هذا خطر؟
⚠️ **MAYBE**
- **إيجابي**: يتعلم من كل شيء، حتى auto-match
- **سلبي**: خطأ واحد يُكرر (garbage in, garbage out)

---

## IMPLICIT BEHAVIOR #3: Conflict Detection Blocking

### الوصف
**إذا alias من التعلم له تعارض، auto-match يُحظر تلقائياً.**

### الموقع
`SmartProcessingService::evaluateTrust():431-474`

### الكود الفعلي
```php
// Check if ANY alias for this normalized name points to different suppliers
$conflicts = $learningRepo->findConflictingAliases($supplierId, $normalized);

if (!empty($conflicts)) {
    // Now check: is OUR alias from learning?
    $currentAliasStmt = $learningRepo->db->prepare("
        SELECT source FROM supplier_alternative_names
        WHERE normalized_name = ? AND supplier_id = ?
    ");
    $currentAliasStmt->execute([$normalized, $supplierId]);
    $currentAlias = $currentAliasStmt->fetch();
    
    // If THIS alias is from learning AND there are conflicts, BLOCK
    if ($currentAlias && $currentAlias['source'] === 'learning') {
        return new TrustDecision(
            isTrusted: false,
            reason: 'learning_alias_conflict',
            detail: "Supplier $supplierId has learning-sourced alias with conflicts"
        );
    }
}
```

### المحفز
```
Scenario:
  Alias "شركة النورس" → Supplier A (source='learning')
  Alias "شركة النورس" → Supplier B (source='manual')
  
Auto-match attempts:
  Try to match "شركة النورس" → A
  ↓
  System detects conflict
  ↓
  A's alias is from 'learning' → BLOCK
  ↓
  Status remains 'pending'
```

### المستخدم يرى
- ✅ يرى status='pending' (لم يتم التطابق)
- ❌ **لا يرى** "blocked due to conflict"
- ❌ **لا يعرف** أن التعارض هو السبب

### التأثير
- Auto-match فشل → manual review required
- المستخدم **لا يعرف لماذا**

### هل هذا مقصود؟
✅ **YES** - safety mechanism

### هل هذا خطر؟
✅ **NO** - هذا حماية
- يمنع auto-match خاطئ
- **لكن**: UX يمكن تحسينه (show reason to user)

---

## IMPLICIT BEHAVIOR #4: Supplier ID/Name Mismatch Auto-Correction

### الوصف
**إذا ID/Name لا يطابقان، النظام يُصفِّر ID تلقائياً ويثق بالاسم.**

### الموقع
`api/save-and-next.php:34-46`

### الكود الفعلي
```php
// ID/Name Mismatch Safeguard
if ($supplierId) {
    $stmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
    $stmt->execute([$supplierId]);
    $officialName = $stmt->fetchColumn();
    
    if ($officialName) {
        $normalizedOfficial = \App\Utils\ArabicNormalizer::normalize($officialName);
        $normalizedSupplied = \App\Utils\ArabicNormalizer::normalize($supplierName);
        
        if ($normalizedOfficial !== $normalizedSupplied) {
            // Mismatch detected → trust the name, clear stale ID
            error_log("⚠️ Supplier ID/Name mismatch: ID=$supplierId, Name=$supplierName");
            $supplierId = null;  ← تصفير ضانيع
        }
    }
}
```

### المحفز
```
User edits suggestion:
  Original: ID=5, Name="شركة النورس للتجارة"
  Edited:   ID=5, Name="شركة النورس"
  
System detects:
  normalize("شركة النورس للتجارة") ≠ normalize("شركة النورس")
  ↓
  Mismatch!
  ↓
  supplierId = null
  ↓
  Re-resolve from name...
```

### المستخدم يرى
- ❌ **لا يرى** "ID cleared"
- ✅ يرى (ربما) error "يجب اختيار مورد" إذا re-resolve فشل

### التأثير
- Prevents stale ID from being logged
- **Side effect**: User's edit triggers full re-resolution

### هل هذا مقصود؟
✅ **صريح** في الكود (line 41 comment: "trust the name, clear stale ID")

### هل هذا خطر؟
✅ **NO** - هذا حماية
- يمنع ID poisoning

---

## IMPLICIT BEHAVIOR #5: Bank Name Mutation (Silent Update)

### الوصف
**عند auto-match للبنك، raw_data.bank يُحدّث بالاسم الرسمي بدون إشعار.**

### الموقع
`SmartProcessingService::updateBankNameInRawData():305-322`

### الكود الفعلي
```php
private function updateBankNameInRawData(int $guaranteeId, string $matchedBankName): void
{
    $guarantee = $this->guaranteeRepo->find($guaranteeId);
    $rawData = $guarantee->rawData;
    
    // ⚠️ MUTATION: Update bank name to official name
    $rawData['bank'] = $matchedBankName;
    
    $this->guaranteeRepo->updateRawData($guaranteeId, $rawData);
}
```

### المحفز
```
Import:
  raw_data['bank'] = "الأهلي"  ← اسم مختصر
  
Auto-match:
  Matches to bank_id = 3
  Official name = "البنك الأهلي السعودي"
  ↓
  raw_data['bank'] = "البنك الأهلي السعودي"  ← تحديث ضمني
```

### المستخدم يرى
- ❌ **لا يرى** "bank name updated"
- ✅ يرى الاسم الجديد في UI (لكن قد يظن أنه الأصلي)

### التأثير
- **Original name lost** (overwritten)
- Timeline snapshot preserves old name, but raw_data changed

### هل هذا مقصود؟
✅ **YES** - normalization strategy

### هل هذا خطر؟
⚠️ **MAYBE**
- **إيجابي**: consistency (كل الضمانات تستخدم الاسم الرسمي)
- **سلبي**: فقدان البيانات الأصلية (لو كان هناك variation مهم)

---

## IMPLICIT BEHAVIOR #6: Decision Time Recording (Unused)

### الوصف
**`decision_time_seconds` يُسجل لكن غير مستخدم في أي حسابات.**

### الموقع
`LearningRepository::logDecision()` يستقبل `decision_time_seconds` parameter

### الكود
```php
$stmt->execute([
    $data['raw_supplier_name'],
    $data['supplier_id'],
    $data['confidence'],
    $data['matched_anchor'] ?? null,
    $data['anchor_type'] ?? 'learned',
    $data['action'],
    $data['decision_time_seconds'] ?? 0,  ← مُسجل
    $data['guarantee_id'] ?? null
]);
```

### الاستخدام الحالي
```php
// save-and-next.php:278, 296
'decision_time_seconds' => 0  ← دائماً صفر
```

### المستخدم يرى
- ❌ لا شيء (حقل داخلي)

### التأثير
- **NONE** currently
- مُسجل **للتحليل المستقبلي**

### هل هذا مقصود؟
⚠️ **Unclear** - prepared for future use, but not implemented

---

## IMPLICIT BEHAVIOR #7: Fuzzy All-Suppliers Scan

### الوصف
**كل طلب اقتراح يفحص **ALL** suppliers لحساب similarity.**

### الموقع
`FuzzySignalFeeder::getSignals()`

### الكود
```php
$allSuppliers = $this->supplierRepo->getAllSuppliers();  ← ALL

foreach ($allSuppliers as $supplier) {
    $similarity = $this->calculateSimilarity($normalizedInput, $supplier['normalized_name']);
    // ...
}
```

### المحفز
```
EVERY suggestion request:
  Load ALL suppliers (100s or 1000s)
  Calculate levenshtein for EACH
  Emit signals for matches >= 0.55
```

### المستخدم يرى
- ✅ يرى الاقتراحات النهائية
- ❌ **لا يرى** "scanned 500 suppliers"

### التأثير
- **Performance**: O(n * m) where n=suppliers, m=string length
- **Silent cost**: كل طلب = full scan

### هل هذا مقصود؟
⚠️ **Design trade-off** - accuracy vs performance

### هل هذا خطر؟
⚠️ **Performance risk** at scale (>1000 suppliers)

---

## IMPLICIT BEHAVIOR #8: Anchor Frequency Calculation

### الوصف
**لكل anchor مُستخرج، النظام يحسب كم مورد يحتوي عليه (frequency).**

### الموقع
`AnchorSignalFeeder::calculateAnchorFrequencies()`

### الكود
```php
foreach ($anchors as $anchor) {
    $matchCount = $this->supplierRepo->countSuppliersWithAnchor($anchor);
      ↓
      SELECT COUNT(*) FROM suppliers
      WHERE official_name LIKE '%' || anchor || '%'
      
    $frequencies[$anchor] = $matchCount;
}
```

### المحفز
```
Input: "شركة النورس للتجارة"
Anchors extracted: ["النورس", "التجارة"]

For "النورس":
  Query: SELECT COUNT(*) WHERE name LIKE '%النورس%'
  Result: 2 suppliers
  
For "التجارة":
  Query: SELECT COUNT(*) WHERE name LIKE '%التجارة%'
  Result: 50 suppliers
```

### المستخدم يرى
- ❌ **لا يرى** anchor frequency calculations

### التأثير
- "التجارة" marked as generic (strength=0.5)
- "النورس" marked as distinctive (strength=0.9)
- **Automatic tier assignment**

### هل هذا مقصود؟
✅ **YES** - algorithm design

---

## 📊 ملخص السلوكيات الضمنية

| # | السلوك | Auto-Triggered? | User Aware? | Risk Level |
|---|---------|-----------------|-------------|------------|
| 1 | Implicit Rejection | ✅ | ❌ | ⚠️ Medium |
| 2 | Historical Counting | ✅ | ❌ | ⚠️ Low |
| 3 | Conflict Blocking | ✅ | ❌ | ✅ Safe |
| 4 | ID/Name Mismatch Fix | ✅ | ❌ | ✅ Safe |
| 5 | Bank Name Mutation | ✅ | ❌ | ⚠️ Low |
| 6 | Decision Time Logging | ✅ | ❌ | ✅ Safe |
| 7 | Full Supplier Scan | ✅ | ❌ | ⚠️ Performance |
| 8 | Anchor Frequency Calc | ✅ | ❌ | ✅ Safe |

---

## 🎯 تأثيرات مركبة (Compound Effects)

### Scenario: User Tries Different Suppliers

```
User opens guarantee for "شركة النورس"

Attempt 1:
  System suggests: Supplier A (90%)
  User selects: Supplier B
  ↓
  Implicit: reject A, confirm B

Attempt 2 (same name, different guarantee):
  System suggests: Supplier A (85%) ← confidence dropped
  User selects: Supplier C
  ↓
  Implicit: reject A again, confirm C

Attempt 3:
  System suggests: Supplier B (now higher due to confirm)
  User thinks: "Oh, maybe B is right"
  Selects: B
  ↓
  Implicit: confirm B again
  
Result:
  A: 2 rejects (confidence tanked)
  B: 2 confirms (confidence boosted)
  C: 1 confirm
  
**All without explicit feedback request**
```

---

## ✅ الخلاصة

**Total Implicit Behaviors**: 8 documented

**Intentional & Safe**: 4
- Conflict blocking (#3)
- ID/Name mismatch fix (#4)
- Decision time logging (#6)
- Anchor frequency calc (#8)

**Intentional but Potentially Risky**: 3
- Implicit rejection (#1) - **critical learning mechanism**
- Bank name mutation (#5) - data normalization
- Full supplier scan (#7) - performance trade-off

**Passive/Automatic**: 1
- Historical counting (#2) - byproduct of decisions

**Recommendation**: 
✅ **Document these behaviors** for users  
⚠️ **Consider UX improvements** (show implicit rejections?)  
⚠️ **Monitor performance** (fuzzy scan at scale)

---

*كل سلوك ضمني موثق هنا بدقة. المستخدم **يجب** أن يعرف هذه السلوكيات لفهم كيف يتعلم النظام.*
