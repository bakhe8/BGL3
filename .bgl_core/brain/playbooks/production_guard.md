# Playbook: Production Mode Guard

## الهدف
ضمان أن تفعيل `PRODUCTION_MODE` يمنع أي عمليات كتابة (POST/PUT/PATCH/DELETE) غير مصرح بها عبر الـAPI/الواجهة، وليس مجرد إخفاء أو فلترة في الـUI.

## السياق
- الإعداد موجود في `Settings::PRODUCTION_MODE` ويُفعّل من `views/settings.php`.
- معظم مسارات الـAPI تمر عبر `app/Support/autoload.php` قبل التنفيذ.

## الخطوات القياسية
1. اكتشف الحالة: إذا كان `PRODUCTION_MODE=true` مع عدم وجود حارس تنفيذي مركزي.
2. أضف حارسًا في `app/Support/autoload.php`:
   - منع `POST/PUT/PATCH/DELETE` عندما `PRODUCTION_MODE=true`.
   - استثناء مسار أو اثنين ضروريين (مثل `api/settings.php`) حسب السياسة.
   - إرجاع 403 برسالة واضحة.
3. سجل التغيير في decision/outcome مع scope autoload.php.
4. شغّل اختبار الفجوة:
   - فعّل `PRODUCTION_MODE` في settings.
   - أرسل POST تجريبيًا لمسار إنشاء (مثلاً `api/create-bank.php`).
   - توقّع 403. إذا لم يتحقق → اقتراح إصلاح.
5. أعِد تشغيل `master_verify.py` وتحقق أن التقرير/الـDashboard يُظهر القرار.

## معايير القبول
- أي طلب كتابة في وضع الإنتاج يُعاد بـ 403 (ما عدا الاستثناءات المصرح بها).
- اختبار الفجوة يمرّ بعد إضافة الحارس.
- القرار موثق في knowledge.db مع outcome نجاح.
