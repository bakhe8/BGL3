# المساهمة في BGL3 | Contributing to BGL3

[English](#english) | [العربية](#arabic)

---

<a name="english"></a>
## 🤝 Contributing (English)

Thank you for your interest in contributing to BGL3! We welcome contributions from the community.

### Getting Started

1. **Fork the repository** and clone it locally
2. **Create a branch** for your changes
3. **Make your changes** following our guidelines
4. **Test your changes** thoroughly
5. **Submit a Pull Request**

### Development Setup

```bash
# Clone your fork
git clone https://github.com/YOUR_USERNAME/BGL3.git
cd BGL3

# Install dependencies
composer install

# Start development server
php -S localhost:8000

# Run tests
vendor/bin/phpunit
```

### Contribution Guidelines

#### Code Style

- Follow PHP PSR-12 coding standards
- Use meaningful variable and function names
- Add comments for complex logic
- Keep functions small and focused
- Write clean, readable code

#### Commit Messages

Write clear, descriptive commit messages:

```
type: Brief description (max 50 chars)

Detailed explanation of what changed and why.
- Point 1
- Point 2

Fixes #123
```

**Types:**
- `feat:` New feature
- `fix:` Bug fix
- `docs:` Documentation changes
- `style:` Code style/formatting
- `refactor:` Code refactoring
- `test:` Adding/updating tests
- `chore:` Maintenance tasks

#### Before Submitting

- [ ] Code follows project style
- [ ] All tests pass
- [ ] No new warnings
- [ ] Documentation updated (if needed)
- [ ] Self-reviewed the changes
- [ ] Added comments for complex code
- [ ] Linked related issues

### Pull Request Process

1. **Create an Issue first** to discuss major changes
2. **Update documentation** if you're changing functionality
3. **Add tests** for new features
4. **Ensure CI passes** before requesting review
5. **Link the related Issue** in your PR description
6. **Respond to feedback** from reviewers

### Reporting Bugs

Use the [Bug Report template](.github/ISSUE_TEMPLATE/bug_report.md) and include:

- Clear description of the bug
- Steps to reproduce
- Expected vs actual behavior
- Environment details (PHP version, OS, browser)
- Screenshots if applicable

### Requesting Features

Use the [Feature Request template](.github/ISSUE_TEMPLATE/feature_request.md) and include:

- Problem you're trying to solve
- Proposed solution
- Alternative solutions considered
- Why this feature is valuable

### Questions?

- Open a [Discussion](https://github.com/bakhe8/BGL3/discussions) for general questions
- Open an [Issue](https://github.com/bakhe8/BGL3/issues) for bugs or features

---

<a name="arabic"></a>
## 🤝 المساهمة (العربية)

شكراً لاهتمامك بالمساهمة في BGL3! نرحب بمساهمات المجتمع.

### البدء

1. **Fork المستودع** واستنسخه محلياً
2. **أنشئ branch** للتغييرات
3. **قم بإجراء التغييرات** حسب الإرشادات
4. **اختبر التغييرات** بشكل شامل
5. **أرسل Pull Request**

### إعداد بيئة التطوير

```bash
# استنسخ fork الخاص بك
git clone https://github.com/YOUR_USERNAME/BGL3.git
cd BGL3

# تثبيت الاعتماديات
composer install

# تشغيل السيرفر
php -S localhost:8000

# تشغيل الاختبارات
vendor/bin/phpunit
```

### إرشادات المساهمة

#### أسلوب الكود

- اتبع معايير PHP PSR-12
- استخدم أسماء متغيرات ودوال واضحة
- أضف تعليقات للمنطق المعقد
- اجعل الدوال صغيرة ومركزة
- اكتب كود نظيف وقابل للقراءة

#### رسائل Commit

اكتب رسائل commit واضحة:

```
type: وصف مختصر (50 حرف كحد أقصى)

شرح تفصيلي للتغييرات ولماذا تم إجراؤها.
- نقطة 1
- نقطة 2

Fixes #123
```

**الأنواع:**
- `feat:` ميزة جديدة
- `fix:` إصلاح bug
- `docs:` تغييرات في التوثيق
- `style:` تنسيق الكود
- `refactor:` إعادة هيكلة الكود
- `test:` إضافة/تحديث الاختبارات
- `chore:` مهام صيانة

#### قبل الإرسال

- [ ] الكود يتبع أسلوب المشروع
- [ ] جميع الاختبارات تنجح
- [ ] لا توجد تحذيرات جديدة
- [ ] التوثيق محدّث (إن لزم)
- [ ] مراجعة ذاتية للتغييرات
- [ ] تعليقات للكود المعقد
- [ ] ربط Issues المتعلقة

### عملية Pull Request

1. **أنشئ Issue أولاً** لمناقشة التغييرات الكبيرة
2. **حدّث التوثيق** إذا كنت تغير الوظائف
3. **أضف اختبارات** للميزات الجديدة
4. **تأكد من نجاح CI** قبل طلب المراجعة
5. **اربط Issue المتعلق** في وصف PR
6. **رد على الملاحظات** من المراجعين

### الإبلاغ عن Bugs

استخدم [قالب Bug Report](.github/ISSUE_TEMPLATE/bug_report.md) وأضف:

- وصف واضح للـ bug
- خطوات إعادة إنتاج المشكلة
- السلوك المتوقع مقابل الفعلي
- تفاصيل البيئة (نسخة PHP، نظام التشغيل، المتصفح)
- لقطات شاشة إن أمكن

### طلب ميزات

استخدم [قالب Feature Request](.github/ISSUE_TEMPLATE/feature_request.md) وأضف:

- المشكلة التي تحاول حلها
- الحل المقترح
- الحلول البديلة المدروسة
- لماذا هذه الميزة قيّمة

### أسئلة؟

- افتح [Discussion](https://github.com/bakhe8/BGL3/discussions) للأسئلة العامة
- افتح [Issue](https://github.com/bakhe8/BGL3/issues) للـ bugs أو الميزات

---

## 📜 Code of Conduct

Please note that this project is released with a [Code of Conduct](CODE_OF_CONDUCT.md). By participating in this project you agree to abide by its terms.

## 📝 License

By contributing, you agree that your contributions will be licensed under the same license as the project.

---

**شكراً لمساهمتك! | Thank you for contributing!** ❤️
