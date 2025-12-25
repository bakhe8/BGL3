# BGL System V3 - Standalone System

نظام مستقل بالكامل لإدارة الضمانات البنكية.

## 🚀 البدء السريع

```bash
# 1. تثبيت Dependencies
composer install

# 2. إعداد قاعدة البيانات
php migrations/run_migrations.php

# 3. تشغيل السيرفر
php -S localhost:8000 server.php

# 4. فتح المتصفح
http://localhost:8000/
```

## 📁 الهيكل

```
V3/
├── index.php           # Entry point
├── server.php          # Development server router
├── composer.json       # Dependencies
├── vendor/             # Composer packages
├── storage/
│   └── database.sqlite # قاعدة البيانات
├── migrations/         # Database migrations
├── app/
│   ├── Support/        # Database & utilities
│   ├── Models/         # Data models
│   ├── Repositories/   # Data access layer
│   └── Services/       # Business logic
└── api/
    ├── import.php      # Excel import
    ├── save.php        # Save decision
    ├── extend.php      # Extend guarantee
    └── release.php     # Release guarantee
```

## ✨ الميزات

- ✅ نظام مستقل 100% (لا يعتمد على ملفات خارجية)
- ✅ قاعدة بيانات SQLite محلية
- ✅ استيراد Excel
- ✅ واجهة عصرية مع Alpine.js
- ✅ APIs كاملة

## 📊 استخدام

### استيراد ملف Excel
1. افتح الواجهة
2. اضغط "ملف"
3. اختر ملف Excel
4. اضغط "استيراد"

### العمليات
- **حفظ:** حفظ القرار
- **تمديد:** تمديد صلاحية الضمان
- **إفراج:** إصدار خطاب إفراج

## 🛠️ تقنيات

- PHP 8+
- SQLite
- Alpine.js
- PhpSpreadsheet

## 📝 ملاحظات

- قاعدة البيانات في `storage/database.sqlite`
- Logs في `storage/import.log`
- كل شيء portable - انسخه لأي مكان ويعمل!

---

**Version:** 3.0  
**Date:** 2025-12-23
