# 📋 Timezone & Learning System - Final Review

## تاريخ المراجعة: 2026-01-10

---

## 1. 🕐 Timezone Settings Review

### 🔍 النتيجة: **يوجد** timezone dropdown في الإعدادات

**الملف:** [`views/settings.php`](file:///c:/Users/Bakheet/Documents/Projects/BGL3/views/settings.php#L319-L359)

### الواجهة الموجودة

```html
<div class="form-group">
    <label class="form-label">المنطقة الزمنية (Timezone)</label>
    <select class="form-input" name="TIMEZONE" required>
        <option value="Asia/Riyadh">🇸🇦 الرياض (Asia/Riyadh) - UTC+3</option>
        <option value="Asia/Dubai">🇦🇪 دبي (Asia/Dubai) - UTC+4</option>
        <option value="Asia/Kuwait">🇰🇼 الكويت (Asia/Kuwait) - UTC+3</option>
        <option value="Asia/Qatar">🇶🇦 الدوحة (Asia/Qatar) - UTC+3</option>
        <option value="Asia/Bahrain">🇧🇭 البحرين (Asia/Bahrain) - UTC+3</option>
        <option value="Africa/Cairo">🇪🇬 القاهرة (Africa/Cairo) - UTC+2</option>
        <option value="UTC">🌍 UTC - التوقيت العالمي</option>
    </select>
</div>
```

### عرض الوقت الحالي

```php
echo 'التوقيت الحالي: ' . date('Y-m-d H:i:s') . ' (' . date_default_timezone_get() . ')';
```

### التكامل

1. ✅ **Frontend:** واجهة dropdown في الإعدادات العامة (Tab 1)
2. ✅ **Backend:** يتم حفظ القيمة في `Settings`
3. ✅ **System-wide:** `Database.php` يضبط timezone عند الاتصال:

   ```php
   date_default_timezone_set('Asia/Riyadh'); // Default
   ```

### العلاقة بمعالجة التواريخ

#### Excel Date Normalization

**الملف:** [`ImportService.php`](file:///c:/Users/Bakheet/Documents/Projects/BGL3/app/Services/ImportService.php#L446-L449)

```php
if (is_numeric($value)) {
    $unixDate = ($value - 25569) * 86400;
    return date('Y-m-d', (int) $unixDate); 
    // Changed from gmdate to use Riyadh timezone
}
```

**التأثير:**

- التحويل من Excel serial number يعتمد على timezone المضبوط
- إذا غيّر المستخدم timezone في الإعدادات، سيؤثر على **تواريخ جديدة** فقط
- التواريخ الموجودة في DB **لن تتغير** (مخزنة كـ `Y-m-d`)

### التوصيات

#### ✅ ما يعمل بشكل صحيح

1. واجهة الإعدادات موجودة وواضحة
2. القيمة الافتراضية `Asia/Riyadh` مناسبة للسعودية
3. خيارات شاملة لدول الخليج

#### ⚠️ نقاط للتوضيح

1. **لا يوجد reload تلقائي:** بعد تغيير timezone، يجب إعادة تشغيل PHP server
2. **تأثير محدود:** timezone يؤثر فقط على:
   - Excel date import
   - `created_at` / `updated_at` timestamps
   - عرض التواريخ في Timeline

#### 📝 التوثيق المطلوب

أضف ملاحظة في `docs/` توضح:

```markdown
### Timezone Configuration

**Location:** Settings → General Settings → System Settings

**Default:** Asia/Riyadh (UTC+3)

**Impact:**
- Excel date import calculations
- Database timestamps (created_at, updated_at)
- Timeline event display

**Note:** Changing timezone requires PHP server restart to take effect.

**Storage:** All dates stored as `YYYY-MM-DD` (timezone-agnostic)
```

---

## 2. 🧠 Learning System - Confidence Scoring Review

### 🔍 النتيجة: النظام **موثق جيداً** في الكود

**الملف الرئيسي:** [`ConfidenceCalculatorV2.php`](file:///c:/Users/Bakheet/Documents/Projects/BGL3/app/Services/Learning/ConfidenceCalculatorV2.php)

---

### آلية حساب الثقة (Confidence Calculation)

#### الصيغة الموحدة

```
Final Confidence = BASE_SCORE + CONFIRMATION_BOOST + STRENGTH_MODIFIER - REJECTION_PENALTY
```

**مثال عملي:**

```
Signal: fuzzy_official_strong (similarity 95%)
Base Score: 85 (from settings)
Confirmations: 3 times
Rejections: 1 time

Calculation:
1. Base Score: 85
2. Confirmation Boost: +10 (tier 2: 3-5 confirmations)
3. Strength Modifier: +5 (raw_strength 1.0)
4. Before Penalty: 100
5. Rejection Penalty: 100 × 0.75 = 75 (25% penalty)
6. Final Confidence: 75%
```

---

### المكونات الرئيسية

#### 1. Base Scores (النقاط الأساسية)

**من Settings (قابلة للتخصيص):**

| نوع الإشارة | Base Score | الوصف |
|-------------|------------|-------|
| `alias_exact` | 100 | مطابقة تامة مع اسم محفوظ |
| `entity_anchor_unique` | 90 | كلمة فريدة مميزة |
| `fuzzy_official_strong` | 85 | تشابه ≥ 95% |
| `entity_anchor_generic` | 75 | كلمة عامة |
| `fuzzy_official_medium` | 70 | تشابه 85-94% |
| `historical_frequent` | 60 | نمط متكرر |
| `fuzzy_official_weak` | 55 | تشابه 75-84% |
| `historical_occasional` | 45 | نمط نادر |

**الكود:**

```php
private function loadBaseScores(): void
{
    $this->baseScores = [
        'alias_exact' => (int) $this->settings->get('BASE_SCORE_ALIAS_EXACT', 100),
        'fuzzy_official_strong' => (int) $this->settings->get('BASE_SCORE_FUZZY_OFFICIAL_STRONG', 85),
        // ... etc
    ];
}
```

---

#### 2. Confirmation Boosts (مكافأة التأكيد)

**التدرج:**

- **Tier 1** (1-2 تأكيدات): +5 نقاط
- **Tier 2** (3-5 تأكيدات): +10 نقاط
- **Tier 3** (6+ تأكيدات): +15 نقاط

**الكود:**

```php
private function calculateConfirmationBoost(int $count): int
{
    if ($count === 0) return 0;
    elseif ($count <= 2) return 5;  // Tier 1
    elseif ($count <= 5) return 10; // Tier 2
    else return 15;                 // Tier 3
}
```

**المنطق:**

- كلما أكّد المستخدم الاقتراح **أكثر**، زادت الثقة
- الزيادة تدريجية (ليست خطية) لتجنب over-confidence

---

#### 3. Rejection Penalty (عقوبة الرفض)

**الصيغة:** Multiplicative penalty (25% per rejection)

```
Penalty_Factor = (1 - penalty_percentage)^rejection_count
Final = Base_Confidence × Penalty_Factor
```

**مثال:**

```
Base Confidence: 100
Rejection 1: 100 × 0.75 = 75
Rejection 2: 75 × 0.75 = 56
Rejection 3: 56 × 0.75 = 42
```

**الكود:**

```php
private function calculateRejectionPenalty(int $count, int $baseConfidence): int
{
    if ($count === 0) return $baseConfidence;
    
    $penaltyPercentage = (int) $this->settings->get('REJECTION_PENALTY_PERCENTAGE', 25);
    $retentionFactor = (100 - $penaltyPercentage) / 100;
    $penaltyFactor = pow($retentionFactor, $count);
    
    return (int) ($baseConfidence * $penaltyFactor);
}
```

**المنطق:**

- عقوبة **ضخمة** لمنع الاقتراحات الخاطئة
- multiplicative (not additive) لتأثير تراكمي قوي

---

#### 4. Strength Modifier (معدّل القوة)

**للإشارات الضبابية فقط:**

```
Modifier = (raw_strength - 0.9) × 50
```

**أمثلة:**

- raw_strength = 1.0 (100%) → modifier = +5
- raw_strength = 0.9 (90%) → modifier = 0
- raw_strength = 0.8 (80%) → modifier = -5

**الكود:**

```php
private function calculateStrengthModifier(SignalDTO $signal): int
{
    if (!str_starts_with($signal->signal_type, 'fuzzy_')) {
        return 0;
    }
    
    return (int) (($signal->raw_strength - 0.9) * 50);
}
```

**المنطق:**

- تمييز دقيق بين fuzzy matches
- 90% هي النقطة المرجعية (neutral)

---

### Confidence Levels (مستويات الثقة)

**التصنيف:**

```
Level B (High):   confidence >= 85
Level C (Medium): confidence >= 65
Level D (Low):    confidence < 65
```

**الكود:**

```php
public function assignLevel(int $confidence): string
{
    $levelBThreshold = (int) $this->settings->get('LEVEL_B_THRESHOLD', 85);
    $levelCThreshold = (int) $this->settings->get('LEVEL_C_THRESHOLD', 65);
    
    if ($confidence >= $levelBThreshold) return 'B';
    elseif ($confidence >= $levelCThreshold) return 'C';
    else return 'D';
}
```

---

### واجهة Settings

**الملف:** [`views/settings.php`](file:///c:/Users/Bakheet/Documents/Projects/BGL3/views/settings.php#L293-L317)

**الحقول القابلة للتخصيص:**

1. **Base Scores** (8 أنواع إشارات)
2. **Confirmation Boosts** (3 tiers)
3. **Rejection Penalty** (نسبة مئوية)

**مثال:**

```html
<div class="form-group">
    <label class="form-label">نسبة العقوبة لكل رفض (%)</label>
    <span class="form-help">الافتراضي: 25%</span>
    <input type="number" name="REJECTION_PENALTY_PERCENTAGE" 
           value="<?= $currentSettings['REJECTION_PENALTY_PERCENTAGE'] ?? 25 ?>" 
           min="0" max="100" required>
</div>
```

---

### View Complexity - Timeline Section

**الملف:** [`partials/timeline-section.php`](file:///c:/Users/Bakheet/Documents/Projects/BGL3/partials/timeline-section.php#L130-L145)

#### منطق التصفية (Filtering Logic)

**المشكلة المُلاحظة في التقرير:** "View logic complexity"

**الكود:**

```php
// Lines 132-144
$allowedFields = [];
if ($eventLabel === 'تمديد الضمان') 
    $allowedFields = ['expiry_date'];
elseif ($eventLabel === 'تخفيض قيمة الضمان') 
    $allowedFields = ['amount'];
elseif ($eventLabel === 'اعتماد بيانات المورد أو البنك') 
    $allowedFields = ['supplier_id', 'bank_id'];
elseif ($eventLabel === 'تطابق تلقائي') 
    $allowedFields = ['bank_name', 'supplier_name', 'supplier_id', 'bank_id'];
// ... etc

$visibleChanges = array_filter($changes, function($change) use ($allowedFields) {
    return in_array($change['field'], $allowedFields);
});
```

**التحليل:**

- ✅ **Intentional Design:** منع عرض تغييرات غير ذات علاقة بنوع الحدث
- ✅ **User Experience:** تحسين وضوح timeline بإخفاء noise
- ⚠️ **Maintainability:** if-elseif chain طويلة

**التوصية:**
استبدال بـ configuration array:

```php
const EVENT_ALLOWED_FIELDS = [
    'تمديد الضمان' => ['expiry_date'],
    'تخفيض قيمة الضمان' => ['amount'],
    'اعتماد بيانات المورد أو البنك' => ['supplier_id', 'bank_id'],
    'تطابق تلقائي' => ['bank_name', 'supplier_name', 'supplier_id', 'bank_id'],
    // ... etc
];

$allowedFields = EVENT_ALLOWED_FIELDS[$eventLabel] ?? [];
```

---

## الخلاصة النهائية

### ✅ Timezone

1. **واجهة موجودة:** dropdown في الإعدادات
2. **خيارات كافية:** 7 مناطق زمنية
3. **تكامل صحيح:** يؤثر على date import و timestamps
4. **توثيق ناقص:** يحتاج documentation عن impact

### ✅ Learning System

1. **توثيق ممتاز:** كود واضح مع comments
2. **صيغة موحدة:** single source of truth في ConfidenceCalculatorV2
3. **قابل للتخصيص:** جميع parameters في Settings
4. **منطق سليم:** base scores + boosts - penalties

### ⚠️ View Complexity

1. **مقصود:** filtering logic لتحسين UX
2. **قابل للتحسين:** استخدام config array بدل if-elseif
3. **ليس bug:** تصميم مدروس

---

## التوصيات النهائية

### Priority 1 (High)

1. ✅ إضافة توثيق timezone في `docs/timezone-configuration.md`
2. ✅ تحويل event filtering logic إلى config array

### Priority 2 (Medium)

3. ⏸️ إضافة user guide لـ Learning System settings
2. ⏸️ إضافة validation عند تغيير base scores

### Priority 3 (Low)

5. ⏸️ Add UI indicator بأن timezone change يحتاج restart

---

**آخر تحديث:** 2026-01-10  
**الحالة:** ✅ مكتمل  
**النتيجة:** النظام مُصمم بشكل صحيح، التوثيق ناقص فقط
