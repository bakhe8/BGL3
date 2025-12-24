# JavaScript Architecture Policy (Updated)
## سياسة معمارية ملزمة ونهائية - Server-Driven Partial Updates

> **تاريخ الاعتماد:** 2025-12-24  
> **التحديث:** 2025-12-24 03:47  
> **الحالة:** ملزمة وغير قابلة للنقاش  
> **النطاق:** جميع الأكواد الحالية والمستقبلية  
> **ملاحظة:** HTMX غير معتمد في هذا المشروع

---

## القاعدة التنفيذية العامة (Mandatory Rule)

**كل جزء في البرنامج — بدون استثناء — يجب أن يعمل وفق القاعدة التالية:**

> **أرسل طلبًا إلى السيرفر عبر `fetch`، استقبل الـ HTML الجديد للجزء المتأثر، ثم استبدله في الـ DOM تلقائيًا كما هو، دون أي تدخل يدوي أو تعديل بصري عبر JavaScript.**

**أي كود لا يلتزم بهذه القاعدة يُعتبر مرفوضًا معماريًا.**

---

## المبدأ الأساسي

### Single Source of Truth

* **السيرفر** هو مصدر الحقيقة الوحيد
* **JavaScript** ليس مصدر حالة ولا قرار
* **HTML القادم من السيرفر** هو التمثيل الوحيد المعتمد للحالة

### معيار الصمود

أي تغيير مرئي دائم يجب أن:

1. ✅ يُحفظ في السيرفر
2. ✅ يُعاد تمثيله عبر HTML قادم من السيرفر
3. ✅ يصمد بعد Reload كامل

---

## سياسة التحديث (تنطبق على جميع الأجزاء)

### التحديث اللحظي (Partial Update) - الطريقة المعتمدة

**يُستخدم في جميع الحالات الممكنة** بشرط الالتزام التام بالقاعدة العامة.

#### الآلية المعتمدة (الوحيدة):

```
[ User Action ]
      ↓
[ JavaScript: fetch() ]
      ↓
[ Server: Process + Save ]
      ↓
[ Server: Return HTML Fragment ]
      ↓
[ JavaScript: Replace DOM Element ]
      ↓
[ Done - No Further Processing ]
```

#### التفصيل:

**1. JavaScript (دور محدود):**
```javascript
// ✅ CORRECT
async function handleAction() {
    const res = await fetch('/api/action.php', {
        method: 'POST',
        body: JSON.stringify({...})
    });
    
    const html = await res.text(); // HTML fragment
    document.getElementById('targetElement').outerHTML = html; // Replace as-is
}
```

**2. Server (PHP):**
```php
// ✅ CORRECT
<?php
// Process logic
$result = processAction($data);

// Save to database
saveToDatabase($result);

// Return ONLY the affected HTML fragment
echo renderPartialHTML($result);
exit;
?>
```

**3. JavaScript (استبدال فقط):**
```javascript
// ✅ CORRECT - Direct replacement
element.outerHTML = htmlFromServer;

// ❌ WRONG - Any manipulation
element.innerHTML = modifyHTML(htmlFromServer); // ❌
element.classList.add('updated'); // ❌
element.textContent = extractText(htmlFromServer); // ❌
```

---

### ❌ الممنوع تمامًا

```javascript
// ❌ تعديل نص أو حالة عبر JS
element.textContent = 'تم الحفظ'; // ❌

// ❌ إنشاء أو تغيير Chips محليًا
chip.classList.add('chip-approved'); // ❌

// ❌ الاعتماد على State في المتصفح
this.currentState = {...}; // ❌

// ❌ "تحسين" أو "تنعيم" التغيير بصريًا
element.style.opacity = '0.5'; // ❌
setTimeout(() => updateUI(), 300); // ❌
```

---

### متى نستخدم Reload كامل للصفحة؟

**يُستخدم Reload كامل فقط في الحالات التالية:**

* عمليات كبيرة تشمل عدة أجزاء مترابطة
* انتقالات رئيسية بين سجلات أو مراحل
* إعادة بناء شاملة للواجهة

**حتى في هذه الحالات:**
* HTML يجب أن يكون ناتجًا بالكامل من السيرفر
* لا يُسمح بمحاكاة أي حالة عبر JavaScript

---

## استخدام JavaScript — الدور المسموح فقط

### ✅ المسموح

```javascript
// ✅ ربط الأحداث
button.addEventListener('click', handleClick);

// ✅ إرسال الطلبات
await fetch('/api/endpoint.php', {...});

// ✅ استبدال HTML (كما هو)
element.outerHTML = htmlFromServer;

// ✅ Toast غير حاجب (إعلام فقط)
showToast('تم الحفظ', 'success');

// ✅ فتح/إغلاق Modal (تأكيد فقط)
modal.style.display = 'block';
```

### ❌ الممنوع

JavaScript **لا**:
- ❌ يمثل حالة
- ❌ يحفظ قرار
- ❌ يفسّر منطق
- ❌ يغيّر واجهة يدويًا
- ❌ يخزن بيانات
- ❌ يتخذ قرارات

---

## سياسة الرسائل (Messages)

### ✅ المسموح

```javascript
// ✅ Toast - بعد نجاح حقيقي فقط
const res = await fetch('/api/save.php');
const html = await res.text();
if (res.ok) {
    element.outerHTML = html; // Replace first
    showToast('تم الحفظ', 'success'); // Then notify
}
```

```html
<!-- ✅ Modal HTML - للتأكيد فقط -->
<div id="confirmModal">
    <p>هل أنت متأكد؟</p>
    <button data-action="confirmYes">نعم</button>
</div>
```

### ❌ الممنوع

```javascript
// ❌ FORBIDDEN
alert('تم الحفظ'); // ❌
confirm('هل أنت متأكد؟'); // ❌
Swal.fire('نجاح!', '...', 'success'); // ❌

// ❌ رسالة قبل HTML من السيرفر
showToast('تم الحفظ'); // قبل fetch ❌
```

**القاعدة:** الرسالة لا تُنشئ حقيقة، بل تعكس حقيقة حدثت (HTML تم استبداله).

---

## سياسة الاقتراحات (Suggestions)

### القاعدة الصارمة
**الاقتراح ≠ قرار**

### ✅ الآلية الصحيحة

```javascript
// 1. Fetch suggestions from server
const res = await fetch('/api/suggestions.php?raw=...');
const html = await res.text();

// 2. Replace suggestions container
document.getElementById('suggestions-container').outerHTML = html;

// 3. User clicks suggestion
async function selectSuggestion(id) {
    // Save to server
    const res = await fetch('/api/select-suggestion.php', {
        method: 'POST',
        body: JSON.stringify({id})
    });
    
    // Replace affected parts with server HTML
    const html = await res.text();
    document.getElementById('supplier-section').outerHTML = html;
}
```

### ❌ الممنوع

```javascript
// ❌ تخزين في State محلي
this.suggestions = [...]; // ❌

// ❌ تحويل اقتراح لقيمة بدون حفظ
input.value = suggestions[0].name; // ❌

// ❌ اقتراح يختفي بعد Reload
// إذا اختفى = كان وهميًا ❌
```

---

## سياسة الـ Chips

### التعريف الصحيح
**الـ Chip تمثل حالة قرار محفوظ فقط**

### ✅ الآلية الصحيحة

```php
<!-- Server renders chips based on saved state -->
<?php foreach ($suggestions as $s): ?>
    <button class="chip chip-<?= $s['status'] ?>" 
            data-action="selectChip" 
            data-id="<?= $s['id'] ?>">
        <?= $s['name'] ?>
    </button>
<?php endforeach; ?>
```

```javascript
// JavaScript only sends action and replaces HTML
async function selectChip(target) {
    const id = target.dataset.id;
    
    // Save to server
    const res = await fetch('/api/select-chip.php', {
        method: 'POST',
        body: JSON.stringify({id})
    });
    
    // Replace entire chips container with server HTML
    const html = await res.text();
    document.getElementById('chips-container').outerHTML = html;
}
```

### ❌ الممنوع

```javascript
// ❌ Chip تتغير بـ JavaScript فقط
chip.classList.add('chip-approved'); // ❌ بدون حفظ

// ❌ Chip تمثل "اختيار مؤقت"
chip.dataset.selected = 'true'; // ❌

// ❌ Chip تختفي بعد Reload
// إذا اختفت = كانت وهمية ❌
```

**القاعدة:** أي Chip لا تصمد بعد Reload = Chip وهمية.

---

## معيار القبول النهائي (Acceptance Rule)

**أي جزء في الواجهة يجب أن ينجح في الاختبار التالي:**

```
1. نفّذ الإجراء (click button)
      ↓
2. أرسل طلبًا للسيرفر عبر fetch
      ↓
3. استقبل HTML جديد للجزء المتأثر
      ↓
4. استبدل الجزء في الـ DOM تلقائيًا (outerHTML)
      ↓
5. نفّذ Reload كامل (F5)
      ↓
6. يجب أن ترى نفس النتيجة تمامًا
```

**إذا فشل أي جزء في هذا الاختبار → التعديل مرفوض.**

---

## أمثلة تطبيقية

### ❌ مثال خاطئ

```javascript
// ❌ WRONG - Client-side state + manual UI update
async function saveRecord() {
    this.record.saved = true; // ❌ Client state
    
    await fetch('/api/save.php', {...});
    
    // ❌ Manual UI update
    document.getElementById('status').textContent = 'محفوظ';
    document.getElementById('saveBtn').disabled = true;
}
```

### ✅ مثال صحيح

```javascript
// ✅ CORRECT - Server-driven partial update
async function saveRecord() {
    const res = await fetch('/api/save.php', {
        method: 'POST',
        body: JSON.stringify({...})
    });
    
    // Server returns HTML for the entire record section
    const html = await res.text();
    
    // Replace as-is, no manipulation
    document.getElementById('record-section').outerHTML = html;
    
    // Optional: Toast for user feedback only
    showToast('تم الحفظ', 'success');
}
```

**Server (save.php):**
```php
<?php
// Process and save
$record = saveRecord($_POST);

// Render the ENTIRE affected section
include 'partials/record-section.php';
exit;
?>
```

---

## الخلاصة (قواعد العمل)

### المبادئ الأساسية

1. **قاعدة واحدة** - Server-driven partial updates
2. **آلية واحدة** - fetch → HTML → outerHTML
3. **مصدر حقيقة واحد** - السيرفر
4. **لا State في المتصفح** - أبداً
5. **لا ذكاء وهمي** - JavaScript لا يقرر
6. **HTML هو الحقيقة** - دائماً
7. **Reload هو الحكم النهائي** - الاختبار الأخير

### ممنوع قطعًا

- ❌ `alert()` / `confirm()` / `prompt()`
- ❌ SweetAlert2
- ❌ Client-side state
- ❌ Manual DOM manipulation
- ❌ Phantom chips/suggestions
- ❌ UI updates before server confirmation

### مطلوب دائمًا

- ✅ `fetch()` للتواصل مع السيرفر
- ✅ HTML fragments من السيرفر
- ✅ `outerHTML` للاستبدال المباشر
- ✅ Toast للإعلام فقط (بعد النجاح)
- ✅ Reload يُظهر نفس النتيجة

---

## التطبيق والالتزام

- ✅ **جميع الأكواد الجديدة** يجب أن تلتزم بهذه السياسة
- ✅ **الأكواد الحالية** يجب مراجعتها وإصلاحها
- ✅ **أي انتهاك** يُعتبر bug يجب إصلاحه فورًا

**هذه السياسة ليست تحسينًا شكليًا، بل تصحيح معماري يهدف لضمان أن الواجهة تعكس حقيقة النظام ولا تصنع وهمًا بصريًا.**

---

## ملاحظة نهائية

**HTMX غير معتمد في هذا المشروع.**

نحن نستخدم Vanilla JavaScript مع `fetch()` و `outerHTML` لتحقيق نفس النتيجة بتحكم كامل ودون dependencies خارجية.


---

## أولًا: رسائل النظام (Messages)

### ❌ الممنوع قطعًا

```javascript
// ❌ FORBIDDEN
alert('تم الحفظ بنجاح');
confirm('هل أنت متأكد؟');
prompt('أدخل القيمة');

// ❌ FORBIDDEN
Swal.fire('نجاح!', 'تم الحفظ', 'success');

// ❌ FORBIDDEN - إظهار نجاح قبل التأكد
showToast('تم الحفظ'); // قبل استجابة السيرفر
```

### ✅ المسموح

```javascript
// ✅ ALLOWED - Toast بعد تأكيد السيرفر
const res = await fetch('/api/save.php', {...});
const data = await res.json();
if (data.success) {
    showToast('تم الحفظ بنجاح', 'success');
}

// ✅ ALLOWED - Modal HTML حقيقي للتأكيد
<div id="confirmModal">
    <p>هل أنت متأكد؟</p>
    <button data-action="confirmYes">نعم</button>
</div>
```

**القاعدة:** الرسالة لا تُنشئ حقيقة، بل تعكس حقيقة حدثت بالفعل.

---

## ثانيًا: الاقتراحات (Suggestions)

### القاعدة الصارمة
**الاقتراح ≠ قرار**

### ✅ المسموح

```javascript
// ✅ الاقتراحات من السيرفر فقط
const res = await fetch('/api/suggestions.php?raw=...');
const suggestions = await res.json();

// ✅ عرض كمعلومة فقط
renderSuggestions(suggestions);

// ✅ لا تغيّر القيمة تلقائيًا
// المستخدم يجب أن يختار صراحة
```

### ❌ الممنوع

```javascript
// ❌ تخزين في State محلي
this.suggestions = [...]; // ❌

// ❌ تحويل اقتراح لقيمة معتمدة بدون حفظ
input.value = suggestions[0].name; // ❌

// ❌ اقتراح يختفي بعد Reload
// إذا اختفى = كان وهميًا
```

---

## ثالثًا: الـ Chips

### التعريف الصحيح
**الـ Chip تمثل حالة قرار محفوظ فقط**

### الحالات المسموحة
- `suggested` - اقتراح
- `approved` - معتمد
- `rejected` - مرفوض

### ✅ المسموح

```php
<!-- ✅ Chip من HTML قادم من السيرفر -->
<?php foreach ($suggestions as $s): ?>
    <button class="chip chip-<?= $s['status'] ?>">
        <?= $s['name'] ?>
    </button>
<?php endforeach; ?>
```

```javascript
// ✅ Chip تتغير فقط بعد حفظ وإعادة توليد
await fetch('/api/approve-suggestion.php', {...});
location.reload(); // أو إعادة توليد HTML
```

### ❌ الممنوع

```javascript
// ❌ Chip تتغير بـ JavaScript فقط
chip.classList.add('chip-approved'); // ❌ بدون حفظ

// ❌ Chip تمثل "اختيار مؤقت"
chip.dataset.selected = 'true'; // ❌

// ❌ Chip تختفي بعد Reload
// إذا اختفت = كانت وهمية
```

**القاعدة:** أي Chip لا تصمد بعد Reload = Chip وهمية.

---

## رابعًا: JavaScript (نطاقه وحدوده)

### ✅ دور JavaScript المسموح فقط

```javascript
// ✅ ربط أحداث
button.addEventListener('click', handleClick);

// ✅ إرسال طلب للسيرفر
await fetch('/api/save.php', {...});

// ✅ إظهار Toast
showToast('تم الحفظ', 'success');

// ✅ فتح/إغلاق Modal
modal.style.display = 'block';
```

### ❌ الممنوع قطعًا

```javascript
// ❌ تخزين حالة السجل
const currentRecord = {...}; // ❌

// ❌ تمثيل قرار
const isApproved = true; // ❌

// ❌ محاكاة نجاح
showSuccess(); // قبل استجابة السيرفر ❌

// ❌ State يؤثر على UI بدون سيرفر
this.selectedSupplier = {...}; // ❌
```

**القاعدة:**
- JavaScript ليس مصدر قرار
- JavaScript ليس مصدر حقيقة

---

## النموذج الصحيح المعتمد

```
[ User Action ]
      ↓
[ Server Decision ]
      ↓
[ Persisted State ]
      ↓
[ HTML Re-render ]
      ↓
[ Chips Updated ]
      ↓
[ Toast Notification ]
```

**أي مسار غير هذا = خطأ معماري**

---

## الخلاصة (قواعد العمل)

### ❌ ممنوع
- `alert()` / `confirm()` / `prompt()`
- SweetAlert2 أو أي مكتبة مشابهة
- State محلي في JavaScript
- Chips مؤقتة
- Suggestions تغيّر القيم تلقائيًا

### ✅ مطلوب
- **Reload هو الحكم النهائي**
- الواجهة تعكس الحقيقة
- لا تُخفي الأخطاء
- لا تُجمّل غياب الربط الحقيقي

---

## أمثلة تطبيقية

### ❌ مثال خاطئ

```javascript
// ❌ WRONG - State محلي + Alert
function saveRecord() {
    this.record.saved = true; // State محلي
    alert('تم الحفظ!'); // قبل السيرفر
}
```

### ✅ مثال صحيح

```javascript
// ✅ CORRECT - Server-driven
async function saveRecord() {
    const res = await fetch('/api/save.php', {
        method: 'POST',
        body: JSON.stringify({...})
    });
    
    const data = await res.json();
    
    if (data.success) {
        showToast('تم الحفظ', 'success');
        location.reload(); // أو إعادة توليد HTML
    } else {
        showToast('فشل الحفظ: ' + data.error, 'error');
    }
}
```

---

## التطبيق والالتزام

- ✅ **جميع الأكواد الجديدة** يجب أن تلتزم بهذه السياسة
- ✅ **الأكواد الحالية** يجب مراجعتها وإصلاحها
- ✅ **أي انتهاك** يُعتبر bug يجب إصلاحه فورًا

**هذه السياسة ليست تحسينًا شكليًا، بل تصحيح معماري يهدف لضمان أن الواجهة تعكس حقيقة النظام ولا تصنع وهمًا بصريًا.**
