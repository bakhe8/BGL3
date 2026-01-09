# Arabic Numerals Usage Guide

## القاعدة العامة

استخدام الأرقام في النظام يتبع القاعدة التالية:

- **الأرقام الغربية (0-9):** تُستخدم في **جميع أجزاء النظام**
- **الأرقام العربية (٠-٩):** تُستخدم **فقط** في case:
  1. معاينة الخطابات (Letter Preview)
  2. الخطابات المطبوعة النهائية (Generated Letters)

---

## الأماكن التي تستخدم الأرقام العربية

### 1. Letter Preview (`preview-section.php` أو partials)

- المبالغ المالية
- أرقام العقود
- التواريخ
- أرقام الضمانات

### 2. Generated Letters (`LetterBuilder.php`)

- جميع الحقول المطبوعة في الخطاب النهائي
- خطابات التمديد، التخفيض، والإفراج

---

## الأماكن التي تستخدم الأرقام الغربية

### 1. Database Storage

- جميع الحقول الرقمية تُخزن كـ Western numerals (0-9)
- لا يتم تحويل أي رقم عربي إلى قاعدة البيانات

### 2. User Interface (Views/Forms)

- جداول العرض (Tables)
- نماذج الإدخال (Forms)
- الحقول القابلة للتعديل (Editable Fields)
- Dropdowns و Inputs

### 3. API Responses

- جميع الـ JSON responses
- التواريخ والأرقام في الـ API

### 4. Internal Processing

- الحسابات الرياضية
- المقارنات (Comparisons)
- التحقق من الصحة (Validation)
- Sorting and Filtering

---

## Implementation Details

### PHP (LetterBuilder/Preview)

```php
/**
 * Convert Western numerals to Arabic
 * Used ONLY in letter generation and preview
 */
function convertToArabicNumerals($number) {
    $western = ['0','1','2','3','4','5','6','7','8','9'];
    $arabic = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    return str_replace($western, $arabic, (string)$number);
}

// Usage in LetterBuilder
$formattedAmount = convertToArabicNumerals(number_format($amount, 2));
```

### JavaScript (Records Controller - Preview Only)

```javascript
// Arabic numerals map (for preview rendering)
const arabicNumerals = {
    '0': '٠', '1': '١', '2': '٢', '3': '٣', '4': '٤',
    '5': '٥', '6': '٦', '7': '٧', '8': '٨', '9': '٩'
};

// Only used in preview rendering, NOT in forms
function toArabicNumerals(str) {
    return str.replace(/[0-9]/g, d => arabicNumerals[d]);
}
```

---

## ⚠️ IMPORTANT Rules

### ❌ Never Do

1. **Never** convert Arabic numerals (٠-٩) back to Western for storage
2. **Never** show Arabic numerals in editable form fields
3. **Never** use Arabic numerals in calculations
4. **Never** send Arabic numerals in API requests

### ✅ Always Do

1. **Always** store numbers as Western numerals (0-9)
2. **Always** display Western numerals in UI forms
3. **Only** convert to Arabic numerals for final output (letters/preview)
4. **Always** validate inputs as Western numerals

---

## Data Flow Example

```
User Input (Form):     123456.78  (Western)
       ↓
Database Storage:      123456.78  (Western)
       ↓
API Response:          123456.78  (Western)
       ↓
Preview/Letter:        ١٢٣٤٥٦٫٧٨  (Arabic - ONLY HERE)
```

---

## Files Involved

### Files that convert to Arabic

- `app/Services/LetterBuilder.php` - Letter generation
- `partials/preview-section.php` - Preview rendering
- `app/Services/TimelineRecorder.php` - Letter snapshots (uses LetterBuilder)

### Files that use Western ONLY

- All API endpoints (`api/*.php`)
- All forms (`views/*.php`, `partials/*-form.php`)
- All repositories (`app/Repositories/*.php`)
- All services except LetterBuilder (`app/Services/*.php`)
- Database schema and migrations

---

## Testing Guidelines

### Test Case 1: Form Input

```
Input: 50000
Expected في Form: 50000 (Western)
Expected في Database: 50000 (Western)
Expected في Preview: ٥٠٠٠٠ (Arabic)
```

### Test Case 2: Date Display

```
Database: 2026-01-10
UI Display: 2026-01-10 (or formatted: 10/01/2026)
Letter: ٢٠٢٦-٠١-١٠ (Arabic)
```

---

## Common Mistakes to Avoid

### ❌ Mistake 1: Converting in Forms

```javascript
// BAD - Don't do this in editable fields
<input type="text" value="<?= toArabicNumerals($amount) ?>">
```

```javascript
// GOOD - Keep Western in forms
<input type="text" value="<?= $amount ?>">
```

### ❌ Mistake 2: Storing Arabic

```php
// BAD - Don't store Arabic numerals
$data['amount'] = convertToArabicNumerals($input);
$db->insert('guarantees', $data);
```

```php
// GOOD - Always store Western
$data['amount'] = floatval($input); // Ensures Western
$db->insert('guarantees', $data);
```

---

## Summary

| Context | Numerals | Example |
|---------|----------|---------|
| Database | Western | `123.45` |
| Forms / UI | Western | `123.45` |
| API | Western | `{"amount": 123.45}` |
| Calculations | Western | `$total = 123 + 45` |
| **Letter Preview** | **Arabic** | `**١٢٣٫٤٥** |
| **Generated Letter** | **Arabic** | **`١٢٣٫٤٥`** |

---

**Last Updated:** 2026-01-10  
**Author:** System Documentation  
**Purpose:** Clarify Arabic vs Western numeral usage policy
