---
id: PAT_JS_BLOAT
type: performance
risk_if_missing: medium
auto_applicable: true
conflicts_with:
  - playbook: caching
    impact: complexity
    severity: low
---
# Playbook: معالجة تضخم ملفات JavaScript

## الهدف
تقليل حجم وأثر ملفات JS الكبيرة (تحميل أولي بطيء، صيانة صعبة) عبر تقسيمها أو تحميلها كسولًا.

## السياق
- أكبر ملف حاليًا: `public/js/records.controller.js` (~40KB).
- inventory في `.bgl_core/brain/js_inventory.json` يُحدّث من master_verify.

## الخطوات القياسية
1) حدد الملفات التي تتجاوز عتبة التحذير/الفشل في `checks/js_bloat.py`.
2) اختر إستراتيجية:
   - تقسيم حسب الشاشة/الموديول (dynamic import أو تعدد ملفات).
   - تحميل كسول للمكونات غير الحرجة.
   - إزالة توابع غير مستخدمة أو نقل المنطق إلى API إذا كان حسابيًا.
3) طبّق قالب التصحيح المناسب (مثال: `patch_templates/js_split_placeholder.md`) أو أضف حزمة فرعية جديدة.
4) شغّل `master_verify.py` مع `run_gap_tests=1` للتأكد من عدم تدهور الواجهات.
5) سجّل outcome في knowledge.db مع scope = الملف المتأثر وحجمه الجديد.

## معايير القبول
- لا توجد ملفات JS في حالة FAIL (≥50KB أو ≥1500 سطر) ويفضل أن تبقى تحت WARN.
- زمن تحميل الصفحة الأساسية لا يزيد (إن وُجد قياس).
- لا كسر في سيناريوهات UI الحرجة (تشغيل gap/fast إن وجدت).
