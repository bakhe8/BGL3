# Contributing to BGL3

## 👋 مرحباً بك!

شكراً لاهتمامك بالمساهمة في **BGL3**! هذا الدليل سيساعدك على البدء.

---

## 🚀 البداية السريعة

### 1. Fork & Clone

```bash
# Fork the repository on GitHub first
git clone https://github.com/YOUR_USERNAME/BGL3.git
cd BGL3

# Add upstream remote
git remote add upstream https://github.com/bakhe8/BGL3.git
```

### 2. Install Dependencies

```bash
# Install PHP dependencies (if using Composer)
composer install

# Verify PHP version
php -v  # Should be 8.3+
```

### 3. Run Development Server

```bash
php -S localhost:8000
```

Open `http://localhost:8000` in your browser.

---

## 📋 قبل البدء

### ⚠️ قواعد مهمة

1. **لا تعمل commit مباشرة على `main`**
2. **دائماً اعمل branch جديد لكل feature/fix**
3. **افتح Issue قبل البدء بأي عمل كبير**
4. **اتبع معايير الكود الموجودة**

---

## 🔄 سير العمل (Workflow)

### خطوة 1: افتح Issue

قبل البدء بأي عمل:

```markdown
1. اذهب إلى Issues
2. اضغط "New Issue"
3. اختر Template المناسب:
   - 🐛 Bug Report
   - ✨ Feature Request
   - 📚 Documentation
4. املأ التفاصيل بوضوح
```

### خطوة 2: أنشئ Branch

```bash
# للميزات الجديدة
git checkout -b feature/short-description

# لإصلاح Bugs
git checkout -b fix/bug-description

# للوثائق
git checkout -b docs/what-you-are-documenting
```

**أمثلة:**
```bash
git checkout -b feature/add-pdf-export
git checkout -b fix/timeline-sorting-issue
git checkout -b docs/update-api-reference
```

### خطوة 3: اعمل التغييرات

```bash
# Edit files
# Test your changes
# Commit frequently with clear messages
```

**Commit Message Format:**

```
type: Short description (max 50 chars)

Detailed description if needed:
- Point 1
- Point 2
- Fixes #123
```

**Types:**
- `feat` - ميزة جديدة
- `fix` - إصلاح bug
- `docs` - تعديلات على الوثائق
- `style` - تنسيق الكود (لا يؤثر على الوظيفة)
- `refactor` - إعادة هيكلة الكود
- `test` - إضافة tests
- `chore` - مهام صيانة

**أمثلة:**
```bash
git commit -m "feat: Add PDF export for letters

- Added PDFService class
- Integrated with letter generation
- Added download button to UI
- Fixes #45"
```

### خطوة 4: Push & Pull Request

```bash
# Push to your fork
git push origin feature/your-feature

# ثم افتح Pull Request على GitHub
```

**PR Title Format:**
```
type: Description (same as commit)
```

**PR Description Template:**
```markdown
## 📋 الوصف
وصف واضح للتغييرات

## 🎯 نوع التغيير
- [ ] 🐛 Bug fix
- [ ] ✨ Feature جديدة
- [ ] 📚 Documentation
- [ ] 🔧 Improvement

## ✅ Checklist
- [ ] الكود يتبع معايير المشروع
- [ ] راجعت الكود بنفسي
- [ ] أضفت comments للأجزاء المعقدة
- [ ] حدثت الوثائق
- [ ] لا توجد warnings جديدة
- [ ] اختبرت محلياً
- [ ] ربطت الـ Issue المتعلق

## 🧪 الاختبار
كيف اختبرت التغييرات؟

## 📝 ملاحظات إضافية
أي معلومات أخرى مفيدة
```

---

## 💻 معايير الكود

### PHP

```php
<?php

namespace App\Services;

/**
 * Service class documentation
 */
class ExampleService
{
    /**
     * Method documentation
     * 
     * @param string $input The input parameter
     * @return array The result
     */
    public function doSomething(string $input): array
    {
        // Clear comments for complex logic
        $result = $this->processInput($input);
        
        return [
            'success' => true,
            'data' => $result
        ];
    }
}
```

**قواعد PHP:**
- ✅ استخدم Type hints دائماً
- ✅ اكتب DocBlocks للـ classes و methods
- ✅ اتبع PSR-12 coding standard
- ✅ استخدم meaningful variable names
- ❌ لا تستخدم global variables
- ❌ لا تستخدم `eval()`

### JavaScript

```javascript
/**
 * Function documentation
 * @param {string} guaranteeId - The guarantee ID
 * @returns {Promise<Object>} The result
 */
async function saveGuarantee(guaranteeId) {
    try {
        const response = await fetch('/api/save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ guarantee_id: guaranteeId })
        });
        
        return await response.json();
    } catch (error) {
        console.error('Error saving guarantee:', error);
        throw error;
    }
}
```

**قواعد JavaScript:**
- ✅ استخدم Vanilla JavaScript (no jQuery)
- ✅ استخدم `const` و `let` (ليس `var`)
- ✅ استخدم async/await للـ promises
- ✅ اكتب JSDoc comments
- ❌ لا تستخدم `alert()` (استخدم Toast system)
- ❌ لا تستخدم inline event handlers

### CSS

```css
/* Component: Card */
.card {
    background: var(--bg-card);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    box-shadow: var(--shadow-sm);
}

.card-title {
    font-size: var(--font-size-lg);
    font-weight: 600;
    color: var(--text-primary);
}
```

**قواعد CSS:**
- ✅ استخدم CSS Variables من design-system.css
- ✅ اتبع BEM naming convention
- ✅ اكتب comments للـ sections
- ✅ Mobile-first approach
- ❌ لا تستخدم `!important` إلا للضرورة
- ❌ لا تستخدم inline styles

---

## 🧪 الاختبار

### Manual Testing

```bash
# Test your changes in browser
php -S localhost:8000

# Test different scenarios:
# - Happy path
# - Error cases
# - Edge cases
```

### الاختبار المطلوب:

- ✅ الميزة تعمل كما متوقع
- ✅ لا توجد أخطاء في Console
- ✅ التصميم responsive على Mobile
- ✅ التوافق مع Chrome, Firefox, Safari
- ✅ الـ Forms تتحقق من المدخلات
- ✅ رسائل الأخطاء واضحة بالعربية

---

## 📚 الوثائق

إذا غيرت أي من هذه الأشياء، حدّث الوثائق:

- ✅ API endpoint جديد → `docs/api-contracts.md`
- ✅ Database schema تغير → `docs/database-schema.md`
- ✅ قرار معماري → `docs/wiki-templates/Decisions.md`
- ✅ Component جديد → `docs/wiki-templates/Design-System.md`

---

## ❓ أسئلة متكررة

### كيف أزامن fork الخاص بي؟

```bash
git checkout main
git fetch upstream
git merge upstream/main
git push origin main
```

### كيف أصلح conflicts؟

```bash
# Update your branch with main
git checkout your-branch
git fetch upstream
git merge upstream/main

# Resolve conflicts in files
# Then commit
git add .
git commit -m "fix: Resolve merge conflicts"
```

### كيف أغير آخر commit؟

```bash
# If not pushed yet
git add .
git commit --amend

# If already pushed (use with caution)
git push --force-with-lease
```

---

## 🎯 أفكار للمساهمة

### للمبتدئين

- 📝 تحسين الوثائق
- 🐛 إصلاح bugs بسيطة (tagged as `good first issue`)
- 🎨 تحسين UI/UX
- ✅ إضافة tests

### للمتقدمين

- ✨ ميزات جديدة
- 🔧 تحسينات Performance
- 🏗️ Refactoring
- 🔒 أمان

---

## 💬 التواصل

- **Issues:** للمشاكل التقنية والطلبات
- **Discussions:** للنقاشات والأسئلة
- **Pull Requests:** لمراجعة الكود

---

## 📜 الترخيص

بالمساهمة في هذا المشروع، توافق على أن مساهماتك ستكون مرخصة تحت نفس ترخيص المشروع.

---

## 🙏 شكراً

شكراً لمساهمتك! كل مساهمة، صغيرة كانت أو كبيرة، تساعد على تحسين BGL3.

**مع التقدير،**  
فريق BGL3

---

*Last updated: 2026-01-10*
