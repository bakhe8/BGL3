# ADR: Rename Class Pipeline in Sandbox with Autoload Refresh

**Status:** Accepted  
**Date:** 2026-01-30  

## Context
- Rename_class يتطلب تحديث المراجع، نقل الملف وفق PSR-4، وإعادة توليد autoload قبل تشغيل PHPUnit.
- SQLite knowledge.db كانت تُقفل بسبب اتصالات طويلة وبسبب مشاركة الساندبوكس للقاعدة الرئيسية.
- git clone لا يجلب الملفات غير المتتبعة، فيفشل rename/verify داخل الساندبوكس.
- Composer autoload (classmap/optimized) لا يُحدّث تلقائياً بعد rename، ما يؤدي لفشل PHPUnit.

## Decision
- الساندبوكس يستخدم نسخة مؤقتة من knowledge.db بوضع WAL، مع اتصالات قصيرة العمر فقط.
- نسخ untracked إلى الساندبوكس (robocopy مع استثناءات ثقيلة).
- vendor في الساندبوكس عبر junction إلى vendor الرئيسي.
- بعد rename وتحديث المراجع، يُشغَّل `composer dump-autoload` داخل الساندبوكس (hard requirement حالياً).
- ترتيب إلزامي: rename + تحديث المراجع → dump-autoload → reindex → validate (lint/phpunit/browser) → apply/rollback.

## Consequences
- تقليل الأقفال على القاعدة الرئيسية، وعزل كامل للساندبوكس.
- توحيد autoload بعد rename يزيل فشل PHPUnit بسبب classmap قديم.
- تكلفة زمنية بسيطة لتشغيل dump-autoload في الساندبوكس، مقبولة مقابل الاتساق.
- الحاجة المستقبلية الممكنة: تحسين أداء autoload (PSR-4-only أو classmap خفيف) لتقليل الزمن.

## Notes
- إذا لم يتوفر composer في الساندبوكس: العملية تفشل برسالة صريحة (policy حالية: hard requirement).
- يمكن لاحقاً تحويل policy إلى soft مع وضع degraded mode، لكن ذلك يتطلب تغطية اختبارات إضافية.
