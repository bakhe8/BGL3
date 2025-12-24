# Server-Driven Architecture - Complete Reference Guide
## المرجع النهائي والملزم لبنية الواجهة

> **تاريخ الاعتماد:** 2025-12-24  
> **الحالة:** ملزمة وغير قابلة للنقاش  
> **النطاق:** جميع الأكواد الحالية والمستقبلية

---

## أولًا: الخريطة المعمارية العامة (Big Picture)

```text
[ User Action ]
      ↓
[ fetch() ]
      ↓
[ Server Logic + DB Save ]
      ↓
[ Server renders HTML Partial ]
      ↓
[ JS replaces DOM fragment ]
      ↓
[ Toast ]
```

🔒 **لا يوجد أي مسار آخر معتمد.**

---

## ثانيًا: تصنيف APIs

### 🟢 UI APIs → HTML (إلزامي)

**متى تُستخدم:**
- النتيجة تؤثر على ما يراه المستخدم
- تغيّر حالة / Chip / Form / Timeline

**أمثلة:**
- `get-record.php`
- `save-and-next.php`
- `extend.php` / `reduce.php` / `release.php`
- `suggestions.php`
- `timeline.php`
- `notes.php`

**القاعدة:**
> UI API = HTML Fragment  
> ❌ ممنوع JSON

---

### 🔵 Logic APIs → JSON (مسموح)

**متى تُستخدم:**
- لا يوجد تغيير مباشر في الواجهة
- تحقق / فحص / منطق داخلي

**أمثلة:**
- Validation
- Learning
- Logging
- Background processing
- Analytics

**القاعدة:**
> Logic API = JSON  
> ❌ ممنوع تعديل DOM

---

## ثالثًا: JavaScript — الدور المحدد

### ✅ المسموح فقط

```javascript
// ✅ التقاط الحدث
button.addEventListener('click', handleClick);

// ✅ إرسال fetch
await fetch('/api/endpoint.php', {...});

// ✅ استبدال HTML
element.outerHTML = htmlFromServer;

// ✅ إظهار Toast
showToast('message', 'success');

// ✅ فتح/إغلاق Modal
modal.style.display = 'block';
```

### ❌ الممنوع قطعًا

```javascript
// ❌ إنشاء HTML
element.innerHTML = `<div>...</div>`;

// ❌ Templates
items.map(i => `<div>${i}</div>`).join('');

// ❌ تغيير Chips
chip.classList.add('approved');

// ❌ تخزين State
this.currentState = {...};

// ❌ Alerts
alert() / confirm() / SweetAlert2
```

---

## رابعًا: التحديث اللحظي vs Reload

### 🔄 Partial Update (الوضع الافتراضي)

**متى:**
- التغيير محصور في جزء واحد
- يوجد Partial HTML واضح

**الآلية:**
```javascript
const res = await fetch('/api/action.php');
const html = await res.text();
document.getElementById('section').outerHTML = html;
```

---

### 🔁 Full Reload (استثناء)

**متى فقط:**
- العملية تؤثر على عدة أجزاء
- نهاية Workflow
- انتقال رئيسي

**ملاحظة:**
- ❌ Reload ليس بديلاً عن Partial Update
- ✅ Reload أداة تحقق (Verification)

---

## خامسًا: Chips — السياسة النهائية

### القواعد

- Chip = **قرار محفوظ فقط**
- تُرسم فقط من HTML السيرفر
- لا تتغير عبر JS
- Reload يجب أن يُظهر نفس الحالة

### ❌ الممنوع

```javascript
// ❌ Chip مؤقتة
chip.dataset.temp = 'true';

// ❌ Chip تعتمد على JS
chip.classList.add('selected');

// ❌ Chip تختفي بعد Reload
// إذا اختفت = كانت وهمية
```

---

## سادسًا: Suggestions — السياسة النهائية

### القواعد

- الاقتراحات تُجلب من السيرفر
- تُعرض كمعلومة فقط
- لا تتحول إلى قيمة إلا عبر:
  - حفظ
  - HTML جديد من السيرفر

### ❌ الممنوع

```javascript
// ❌ Client-side rendering
renderSupplierSuggestions(data);

// ❌ DOM creation في JS
innerHTML = suggestions.map(...).join('');
```

---

## سابعًا: Messages — السياسة النهائية

### Toast ✅

```javascript
// ✅ إعلام فقط بعد نجاح حقيقي
if (res.ok) {
    element.outerHTML = html;
    showToast('تم الحفظ', 'success');
}
```

### Modal ✅

```html
<!-- ✅ HTML حقيقي للتأكيد -->
<div id="confirmModal">
    <p>هل أنت متأكد؟</p>
    <button data-action="confirm">نعم</button>
</div>
```

### ❌ الممنوع

```javascript
// ❌ FORBIDDEN
alert('message');
confirm('question');
Swal.fire('title', 'text', 'icon');
```

---

## ثامنًا: Acceptance Rule (الاختبار النهائي)

**أي جزء يُعتبر صحيحًا فقط إذا:**

```
1. نفّذت الإجراء
      ↓
2. تم fetch
      ↓
3. عاد HTML جديد
      ↓
4. استُبدل الجزء
      ↓
5. ضغطت F5
      ↓
6. رأيت نفس النتيجة حرفيًا
```

**إن فشل أي بند → التنفيذ مرفوض.**

---

## تاسعًا: خريطة القرار السريعة

**اسأل دائمًا:**

> هل نتيجة هذا الطلب يجب أن تغيّر ما يراه المستخدم الآن؟

- **نعم** → HTML API + Partial Update
- **لا** → JSON API بدون DOM

---

## الخلاصة النهائية

### المبادئ الأساسية

1. **السيرفر هو الحقيقة**
2. **HTML هو التمثيل**
3. **JavaScript ناقل فقط**
4. **Partial Update هو الأساس**
5. **Reload هو الحكم**
6. **لا State في المتصفح**
7. **لا وهم بصري**

### ما بعد اعتماد هذه الوثيقة

- ✅ أي كود جديد يُراجع عليها
- ✅ أي كود يخالفها يُعاد
- ✅ أي استثناء يجب أن يكون مكتوبًا ومبررًا

---

## أمثلة تطبيقية

### ✅ مثال صحيح

```javascript
async function saveRecord() {
    const res = await fetch('/api/save.php', {
        method: 'POST',
        body: JSON.stringify({...})
    });
    
    if (res.ok) {
        const html = await res.text();
        document.getElementById('record-section').outerHTML = html;
        showToast('تم الحفظ', 'success');
    }
}
```

**Server (save.php):**
```php
<?php
// Process and save
$record = saveRecord($_POST);

// Render HTML
include 'partials/record-section.php';
exit;
?>
```

---

### ❌ مثال خاطئ

```javascript
async function saveRecord() {
    this.record.saved = true; // ❌ Client state
    
    await fetch('/api/save.php', {...});
    
    // ❌ Manual UI update
    document.getElementById('status').textContent = 'محفوظ';
    alert('تم الحفظ'); // ❌
}
```

---

## Compliance Checklist

قبل اعتماد أي كود، تأكد من:

- [ ] No `alert()` / `confirm()` / `prompt()`
- [ ] No SweetAlert2
- [ ] No client-side HTML generation
- [ ] No `innerHTML` for dynamic content
- [ ] UI APIs return HTML
- [ ] Logic APIs return JSON
- [ ] All uses `outerHTML` for replacement
- [ ] Toast only after server confirmation
- [ ] Chips survive reload
- [ ] Suggestions don't auto-modify values
- [ ] Passes Acceptance Rule (F5 test)
