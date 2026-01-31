# Playbook: Canonical Rename Class Pipeline

## الهدف
تنفيذ rename_class بطريقة ثابتة، موحدة، قابلة للتوسع، وبلا تكرار للنقاشات أو الإصلاحات.

## الخطوات (بالتسلسل الإلزامي)
1) AST rename عبر patcher (nikic/php-parser) داخل الساندبوكس.
2) PSR-4: إعادة تسمية الملف إذا تطلب الأمر (الـ patcher يعالج ذلك).
3) تحديث المراجع عبر AST (rename_reference) في المسارات المسموحة **بما فيها tests/**.
4) تحديث autoload في الساندبوكس:
   - `composer dump-autoload` (أبسط حل موثوق حاليًا). تحسين لاحق ممكن (PSR-4 فقط / classmap خفيف).
5) Re-index للملفات المتأثرة في knowledge.db (نسخة الساندبوكس فقط).
6) SafetyNet.validate (بعد تحديث المراجع والautoload):
   - lint
   - phpunit --group fast
   - browser (يمكن تخطيه مؤقتًا بـ BGL_SKIP_BROWSER=1)
7) rollback عند الفشل مع رسالة سبب واضحة.

## قواعد إلزامية
- ممنوع تشغيل PHPUnit قبل خطوة (3) + (4).
- الساندبوكس يستخدم نسخة مؤقتة من knowledge.db بوضع WAL؛ ممنوع لمس القاعدة الرئيسية.
- الساندبوكس يجب أن يملك vendor عبر junction موحد (لا نسخ مزدوج).
- الملفات غير المتتبعة تُنسخ إلى الساندبوكس (robocopy مع استثناءات ثقيلة) لضمان تكافؤ المحتوى.

## التعامل مع Composer
- مسار composer يُكتشف من الإعدادات/الجذر (composer.bat / vendor/bin/composer / composer.phar مع php).
- إذا تعذر العثور على composer: فشل صريح مع رسالة واضحة (policy حالية: hard requirement).

## تحسينات مستقبلية (مذكورة كمرجعية)
- استبدال dump-autoload بخيار PSR-4-only أو classmap خفيف لتقليل الزمن.
- تحسين دقة AST لاحقًا للسيناريوهات الخاصة (aliases/trait imports) عند الحاجة.
- تشغيل rename self-test تلقائي في CI بعد كل تغيير يتعلق بالوكيل.

## محظورات
- تشغيل phpunit قبل تحديث autoload.
- فتح اتصال طويل بقاعدة المعرفة في الساندبوكس.
- لمس vendor بنسخ كامل داخل الساندبوكس (junction فقط).
