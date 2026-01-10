# Security Policy | سياسة الأمان

[English](#english) | [العربية](#arabic)

---

<a name="english"></a>
## 🔒 Security Policy (English)

### Reporting a Vulnerability

The BGL3 team takes security seriously. If you discover a security vulnerability, please report it responsibly.

**⚠️ DO NOT open a public issue for security vulnerabilities.**

### How to Report

Please report security vulnerabilities by emailing:

**Email:** bakheet@gmail.com

Or [Create a private security advisory](https://github.com/bakhe8/BGL3/security/advisories/new)

### What to Include

When reporting a vulnerability, please include:

1. **Description** of the vulnerability
2. **Steps to reproduce** the issue
3. **Potential impact** of the vulnerability
4. **Suggested fix** (if you have one)
5. **Your contact information** (if you want to be credited)

### Response Timeline

- **Initial Response:** Within 48 hours
- **Status Update:** Within 7 days
- **Fix Timeline:** Depends on severity
  - Critical: 1-7 days
  - High: 7-14 days
  - Medium: 14-30 days
  - Low: 30-90 days

### Security Best Practices

When contributing to BGL3, please follow these security guidelines:

#### Input Validation

- ✅ Validate all user inputs
- ✅ Sanitize data before database operations
- ✅ Use parameterized queries (prepared statements)
- ❌ Never trust user input

#### Authentication & Authorization

- ✅ Use strong authentication mechanisms
- ✅ Implement proper session management
- ✅ Check permissions before operations
- ❌ Never store passwords in plain text

#### File Operations

- ✅ Validate file types and sizes
- ✅ Store uploads outside web root
- ✅ Use safe file names
- ❌ Never execute uploaded files

#### Database Security

- ✅ Use prepared statements
- ✅ Limit database permissions
- ✅ Don't expose database errors
- ❌ Never concatenate SQL queries

#### Code Security

- ✅ Keep dependencies updated
- ✅ Use HTTPS for all connections
- ✅ Implement CSRF protection
- ✅ Set proper file permissions
- ❌ Never commit secrets/credentials

### Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 3.x     | ✅ Yes            |
| < 3.0   | ❌ No             |

### Known Security Features

- SQLite database with parameterized queries
- File upload validation
- No framework dependencies (reduced attack surface)
- Regular dependency updates via Dependabot

---

<a name="arabic"></a>
## 🔒 سياسة الأمان (العربية)

### الإبلاغ عن ثغرة أمنية

فريق BGL3 يأخذ الأمان على محمل الجد. إذا اكتشفت ثغرة أمنية، يرجى الإبلاغ عنها بمسؤولية.

**⚠️ لا تفتح issue عام للثغرات الأمنية.**

### كيفية الإبلاغ

يرجى الإبلاغ عن الثغرات الأمنية عبر البريد الإلكتروني:

**البريد:** bakheet@gmail.com

أو [إنشاء تقرير أمان خاص](https://github.com/bakhe8/BGL3/security/advisories/new)

### ما يجب تضمينه

عند الإبلاغ عن ثغرة، يرجى تضمين:

1. **وصف** الثغرة
2. **خطوات إعادة إنتاج** المشكلة
3. **التأثير المحتمل** للثغرة
4. **إصلاح مقترح** (إن كان لديك)
5. **معلومات الاتصال** (إذا أردت الإشادة بك)

### الجدول الزمني للرد

- **الرد الأولي:** خلال 48 ساعة
- **تحديث الحالة:** خلال 7 أيام
- **الجدول الزمني للإصلاح:** حسب الخطورة
  - حرجة: 1-7 أيام
  - عالية: 7-14 يوم
  - متوسطة: 14-30 يوم
  - منخفضة: 30-90 يوم

### أفضل ممارسات الأمان

عند المساهمة في BGL3، يرجى اتباع إرشادات الأمان:

#### التحقق من المدخلات

- ✅ التحقق من جميع مدخلات المستخدم
- ✅ تنظيف البيانات قبل عمليات قاعدة البيانات
- ✅ استخدام الاستعلامات المعلمة (prepared statements)
- ❌ عدم الثقة بمدخلات المستخدم أبداً

#### المصادقة والترخيص

- ✅ استخدام آليات مصادقة قوية
- ✅ تطبيق إدارة جلسات صحيحة
- ✅ التحقق من الصلاحيات قبل العمليات
- ❌ عدم تخزين كلمات المرور كنص صريح

#### عمليات الملفات

- ✅ التحقق من أنواع وأحجام الملفات
- ✅ تخزين الملفات المرفوعة خارج جذر الويب
- ✅ استخدام أسماء ملفات آمنة
- ❌ عدم تنفيذ الملفات المرفوعة أبداً

#### أمان قاعدة البيانات

- ✅ استخدام prepared statements
- ✅ تقييد صلاحيات قاعدة البيانات
- ✅ عدم إظهار أخطاء قاعدة البيانات
- ❌ عدم دمج استعلامات SQL أبداً

#### أمان الكود

- ✅ تحديث الاعتماديات باستمرار
- ✅ استخدام HTTPS لجميع الاتصالات
- ✅ تطبيق حماية CSRF
- ✅ تعيين صلاحيات ملفات صحيحة
- ❌ عدم commit الأسرار/بيانات الاعتماد أبداً

### الإصدارات المدعومة

| الإصدار | مدعوم              |
| ------- | ------------------ |
| 3.x     | ✅ نعم            |
| < 3.0   | ❌ لا             |

### ميزات الأمان المعروفة

- قاعدة بيانات SQLite مع استعلامات معلمة
- التحقق من رفع الملفات
- عدم وجود اعتماديات على framework (تقليل سطح الهجوم)
- تحديثات منتظمة للاعتماديات عبر Dependabot

---

## 🙏 Acknowledgments | الشكر والتقدير

We appreciate responsible disclosure and will credit security researchers who help improve BGL3's security.

نقدر الإفصاح المسؤول وسنشيد بباحثي الأمان الذين يساعدون في تحسين أمان BGL3.

---

**Stay Safe! | ابق آمناً!** 🛡️
