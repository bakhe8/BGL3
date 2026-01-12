توثيق قرارات (19–28) – بنود التوضيح/الإثبات

## (19) Fuzzy Matching و levenshtein
- تم اعتماد حارس بسيط:
  - إذا أعادت `levenshtein` القيمة -1 تُعامل النتيجة كـ 0% (استبعاد).
- لا تعديل على الخوارزمية نفسها حتى يظهر دليل تشغيلي مخالف.

## (20) مسار Cutover / Dual-Run (ShadowExecutor.php)
- التأكيد على عدم الاستخدام الحالي:
  - لا توجد أي مراجع تشغيلية للمسار ضمن المشروع (لا استدعاءات لـ `ShadowExecutor` في أي مسار API/Service).
- الغرض الأصلي:
  - تشغيل Authority بشكل ظلّي بالتوازي مع Legacy.
  - مقارنة النتائج وتسجيل الفروقات عبر `ComparisonLogger`.
  - إعادة نتائج Legacy للمستخدم (بدون أثر على الواجهة).
- خطة الـ Cutover (الأصلية):
  - تفعيل Shadow لتجميع المقارنات.
  - مراجعة الفروقات حتى الاستقرار.
  - التحوّل لتغذية الواجهة بنتائج Authority بدل Legacy (Switch).
- الحالة الحالية:
  - المسار غير صالح للتشغيل حاليًا (يستدعي `normalizeInput` غير موجودة في `UnifiedLearningAuthority`).
  - موقوف وغير مستخدم فعليًا.

## (21) مسارا الإدخال اليدوي (create-guarantee.php / manual-entry.php)
- `api/create-guarantee.php`:
  - يُستخدم من واجهة الإدخال اليدوي الحالية في الواجهة (`public/js/input-modals.controller.js`).
  - يُنشئ ضمانًا بصيغة raw_data مباشرة ويعيّن `importSource` = "Manual Entry".
  - يُسجّل حدث الاستيراد ويطلق المطابقة الذكية بعدها.
- `api/manual-entry.php`:
  - مسار يعتمد على `ImportService::createManually` (مناسب لتكاملات/سير عمل أقدم أو غير واجهة المودال).
  - يستخدم Batch يومي `manual_paste_YYYYMMDD` ويسجّل occurrence في `guarantee_occurrences`.
  - يطلق المطابقة الذكية في الخلفية.
- الفرق الوظيفي:
  - `create-guarantee.php`: مسار UI مباشر بنمط "Manual Entry" بدون occurrence.
  - `manual-entry.php`: مسار API عام يعتمد نموذج الاستيراد (Batch + Occurrence).

## (22) زر إضافة المورد
- التصحيح:
  - الزر ليس مخفيًا دائمًا؛ يتم إخفاؤه افتراضيًا ثم يُظهره الـ JS عند الحاجة (مثل عدم وجود اقتراحات أو تعديل/مسح الاسم).
  - السلوك مقصود لإجبار قرار صريح.

## (23) التعامل مع bankSelect
- التصحيح:
  - لا يوجد حقل تعديل البنك في واجهة الضمان الحالية؛ لذلك كود التعامل مع `bankSelect` لا يسبب أخطاء فعلية.
  - التحقق في JS مشروط بوجود العنصر، وبالتالي غير مؤثر حاليًا.

## (24) decision_source / decided_by
- القاعدة:
  - أي حدث سببه النظام → `auto`.
  - أي حدث سببه المستخدم → `manual` + `decided_by`.
- تم ضبط مسارات المستخدم لتسجيل `decision_source` و`decided_by` بشكل صريح.

## (25) تتبّع إنشاء الـ Batch
- الاستيراد من Excel:
  - في `ImportService::importFromExcel` يتم توليد `batchIdentifier` بالصيغة:
    - `excel_YYYYMMDD_HHMMSS_<filename>`
  - التعريف يُحسب داخل حلقة الصفوف؛ عادة يكون ثابتًا إن لم تتجاوز المعالجة ثانية واحدة،
    وقد يتغير إذا استغرق الاستيراد أكثر من ثانية.
  - النتيجة: الاستيراد الواحد غالبًا يُنتج Batch واحد، لكنه ليس مضمونًا عند طول المعالجة.
- الإدخال اليدوي:
  - `manual-entry.php` يستخدم Batch يومي `manual_paste_YYYYMMDD`.
  - `create-guarantee.php` يستخدم `importSource = "Manual Entry"` ولا يكتب Occurrence.
- اللصق الذكي:
  - `ParseCoordinatorService` يستخدم Batch يومي `manual_paste_YYYYMMDD` ويسجّل Occurrence.

## (26) ظهور extended / reduced
- التصحيح:
  - هذه ليست حالات ضمان.
  - هي Actions تُعرض في الواجهة فقط.
  - حالة الضمان لا تتغير إلا بالإفراج.

## (27) تنفيذ إجراءات الدُفعات (Contract)
- `batches.php` يدعم الآن قراءة JSON من الـ body ويُوحّد الإدخال مع `$_POST`.
- لا تغيير في منطق التنفيذ، فقط إصلاح التعاقد بين الواجهة والباك-إند.

## (28) Partial Batch Actions – قيد منع تكرار الإجراء
- تم اعتماد تحقق منطقي بسيط يمنع تنفيذ أكثر من إجراء لنفس الضمان داخل نفس الدفعة.
- يعتمد التحقق على وجود `active_action` قبل/أثناء التنفيذ (بدون قيود DB إضافية).
