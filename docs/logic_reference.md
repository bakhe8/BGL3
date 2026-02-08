# BGL3 Logic Reference (Assistant Snapshot – Jan 31, 2026)

وثيقة مرجعية تغطي منطق عمل النظام كما هو موجود في المستودع الحالي، لاختصار الرجوع وفهم السريان بدون الحاجة لتصفّح الشيفرة كل مرة.

## 1) نقاط الدخول والواجهات
- `index.php`: الواجهة الرئيسية لاستعراض ضمان واحد في كل مرة، يبني الفلاتر (all/ready/pending/released) والبحث، ويطلب أول سجل مناسب ثم يحمّل الملاحة (`NavigationService`) والإحصاءات (`StatsService`).
- واجهات API (مجلد `api/`): كل إجراء منفصل في ملف PHP (مثل `import.php`, `save-and-next.php`, `extend.php`, `release.php`, `reduce.php`, `get-record.php`, إلخ). كل ملف يبدأ بتحميل `app/Support/autoload.php` ثم ينفّذ منطق الإجراء ويرد JSON أو HTML جزئي.
- واجهات الإدارة:
  - `views/settings.php`: ضبط الإعدادات وProduction Mode.
  - `views/maintenance.php`: إدارة بيانات الاختبار (حذف دفعات/تاريخ)، مع قفل كامل في Production Mode.
  - `agent-dashboard.php`: عرض الـ “Explained AI” والحوكمة (جزء من نظام الوكيل في `.bgl_core`).

## 2) البنية الطبقية
- **Support**: أدوات مساعدة (autoload, Config, Settings, Database, Logger، وغيرها مثل TypeNormalizer وInput).
- **Models**: كائنات بيانات بسيطة تعكس الصفوف (Guarantee, GuaranteeDecision, Supplier, Bank, TrustDecision, AuditLog…).
- **Repositories**: وصول موحّد للبيانات لكل جدول (GuaranteeRepository، GuaranteeDecisionRepository، SupplierRepository، BankRepository…)، وتحتوي أحيانًا على منطق تجاري خفيف (عزل بيانات الاختبار، إعادة تحميل الكائن بعد الإنشاء للتأكد من حالة ما بعد الحفظ).
- **Services**: منطق نطاق العمل (ImportService، SmartProcessingService، ConflictDetector، NavigationService، StatusEvaluator، TimelineRecorder، LetterBuilder، إلخ). كذلك مجلد `Services/Learning` يحوي AuthorityFactory و UnifiedLearningAuthority لتوليد الترشيحات.
- **partials/views/public**: قوالب HTML/CSS/JS للواجهات.

## 3) قاعدة البيانات (SQLite)
- جدول الضمانات `guarantees` هو المصدر الوحيد للبيانات الخام:
  - حقول أساسية: `guarantee_number`، `raw_data` (JSON)، `import_source`، `imported_at/by`.
  - أعمدة دعم: `normalized_supplier_name`، `is_test_data`، `test_batch_id`، `test_note`.
  - فهارس: على `guarantee_number` (unique)، `normalized_supplier_name`, `is_test_data`, `import_source`, `imported_at`.
  - ملاحظة: `raw_data` تُعد “immutable source of truth”، لكن يوجد مسار واحد يستبدل حقل البنك بمطابقة AI (انظر §7).
- جداول مساندة:
  - `guarantee_decisions`: الحالة الحالية لكل ضمان (supplier_id, bank_id, status, is_locked, active_action, manual_override, metadata تواريخ).
  - `guarantee_history`: سجل الأحداث والزمن والتقاطات snapshots.
  - `guarantee_occurrences` + `batch_metadata`: ربط كل ضمان بالدفعة (batch_identifier, batch_type) مع أسماء عربية للدفعات.
  - تعلم: `learning_confirmations`, `supplier_learning_cache`, `supplier_decisions_log`, `learning_log`.
  - بنوك: `banks` + `bank_alternative_names`.
  - موردون: `suppliers` + `supplier_alternative_names`, `supplier_overrides`, `supplier_learning`.
  - مرفقات/ملاحظات: جداول `attachments`, `notes` (حسب الشيفرة).

## 4) الإعدادات وProduction Mode
- `Settings` تقرأ/تكتب `storage/settings.json` بدمج مع افتراضات في الكود.
- مفاتيح مؤثرة:
  - `PRODUCTION_MODE`: عند تفعيله يتم إخفاء واجهات الاختبار، منع إنشاء بيانات اختبار عبر API، فلترة `is_test_data` في العرض/الإحصاءات، وإخفاء صفحة الصيانة.
  - عتبات المطابقة: `MATCH_AUTO_THRESHOLD` (افتراضي 95)، `MATCH_REVIEW_THRESHOLD` (0.70، تتحول إلى 70 عند التطبيع)، `CONFLICT_DELTA` (0.1).
  - حدود العرض: `CANDIDATES_LIMIT` وغيرها.
- autoload يضبط timezone افتراضيًا ثم يعيد الضبط بعد تحميل Settings.

## 5) مسار الإدخال (Import & Entry)
- **Excel/CSV**: `ImportService::importFromExcel($file, $user, $originalFilename, $isTestData)`:
  1) يقرأ الصفوف عبر `SimpleXlsxReader`.
  2) يكتشف الأعمدة (supplier/bank/guarantee/amount/issue/expiry/type/contract) بذكاء لغوي عربي/إنجليزي + تمييز نوع العقد مقابل أمر الشراء.
  3) يبني `raw_data` موحدة ويحدد `related_to`.
  4) ينشئ دفعة `batch_identifier` (excel_ أو test_excel_) + اسم عربي للعرض في الدفاتر.
  5) يحفظ الضمان عبر `GuaranteeRepository::create` (يملأ normalized_supplier_name).
  6) يسجل الظهور في `guarantee_occurrences` ويعالج التكرارات: عند UNIQUE constraint يسجل ظهور جديد ويضيف حدث Duplicate للتايملاين.
- **إدخال يدوي**: `ImportService::createManually` يبني batch يومي `manual_paste_YYYYMMDD`، يتحقق من الحقول الإلزامية، ويدعم وسم بيانات اختبار.
- **لصق ذكي**: `parse-paste*.php` + `SmartPaste` و`ParseCoordinatorService` لتحويل النصوص شبه الحرة إلى حقول، ثم تمر بنفس مسار الحفظ.
- كل الإدخال يمرر `import_source` ويحفظ `imported_at/by`.

## 6) المعالجة الذكية بعد الإدخال (Smart Processing)
- `SmartProcessingService::processNewGuarantees($limit=500)`:
  1) يجلب الضمانات التي لا قرار لها (`LEFT JOIN guarantee_decisions d IS NULL`) بترتيب تنازلي (الأحدث أولًا).
  2) تطابق البنك أولًا:
     - تطبيع الاسم (`BankNormalizer`)، يبحث في `bank_alternative_names.normalized_name` أو `banks.short_name`.
     - عند النجاح: يسجل حدث auto-match للبنك في `guarantee_history` ويحدّث `raw_data['bank']` بالاسم المطابق (انظر ملاحظة §7).
  3) تطابق المورد:
     - يستدعي `UnifiedLearningAuthority` عبر `AuthorityFactory`.
     - يحول DTOs إلى مصفوفة تحتوي (id, official_name, score, level, reason_ar, source, confirmation/rejection counts).
     - يمرّر المرشح الأعلى إلى `evaluateTrust` لإقرار السماح.
  4) كشف التعارضات: `ConflictDetector::detect` يحلل الفرق بين أعلى مرشحين، مصدر الاسم (override/alternative)، طول التطبيع، وتنبيهات نمطية (حاليًا يستخدم استعلامًا على حقل غير موجود g.raw_supplier؛ ينبغي استبداله بـ JSON_EXTRACT من raw_data).
  5) إذا توافر supplier_id و bank_id ولا تعارض: يُنشئ قرار تلقائي في `guarantee_decisions` (status=ready، source=auto، confidence)، ويسجل حدث `auto_matched` في `guarantee_history`.
  6) إحصاءات ترجع عدد المعالجات والمطابقات للبنوك/المورّدين.

## 7) القرارات والحالة
- `GuaranteeDecisionRepository`:
  - `createOrUpdate` لإنشاء/تحديث قرار واحد لكل ضمان.
  - الحالة `status`: “ready” إذا وُجد supplier_id و bank_id، وإلا “pending” (من `StatusEvaluator`).
  - حقول دعم: `is_locked` (لـ released)، `active_action` (extension/reduction/release)، `manual_override`.
  - سجلات التعلم/التاريخ تُحدّث عند كل تغيير.
- `StatusEvaluator` يوفر:
  - `evaluate` من ID/ID
  - `evaluateFromDatabase`
  - `getReasons` لتفسير الحالة للواجهة.
- مسار الحفظ اليدوي الرئيسي: `api/save-and-next.php`:
  - يتأكد من توافق supplier_id مع الاسم، وإلا يُسقط الـ ID.
  - يحاول إيجاد/إنشاء مورد تلقائي إذا غاب الـ ID.
  - يشترط وجود bank_id مخزّن في القرار؛ إذا لم يوجد يرجع 400 (`bank_required`).
  - يحدّد مصدر القرار (manual / ai_match / auto_create_on_save).
  - يُحدث القرار ويسجل تغيرات المورد والبنك في التاريخ، ثم يرجع HTML جزئي للسجل التالي (Next).

## 8) الملاحة والإحصاءات
- `NavigationService` يحسب:
  - إجمالي السجلات، الموضع الحالي (COUNT قبل id)، السابق/التالي، أو القفز للـ index (`getIdByIndex`).
  - يطبق فلتر الحالة والبحث، ويستثني بيانات الاختبار تلقائيًا في Production Mode فقط (ملاحظة: باقي المستودعات تستثني الاختبار افتراضيًا إلا إذا طُلب عكس ذلك).
- `StatsService`:
  - يحسب ready/pending/released عبر `guarantee_decisions` مع تضمين الضمانات بلا قرارات.
  - لا يفلتر بيانات الاختبار حاليًا، ما قد يعطي أرقامًا أعلى من واجهات أخرى إذا كانت بيانات اختبار موجودة.

## 9) الإجراءات على الضمان (Actions)
- ملفات API منفصلة:
  - `extend.php`, `reduce.php`, `release.php`: تتعامل مع `active_action` في `guarantee_decisions` وتضيف أحداث timeline.
  - `get-letter-preview.php` + `LetterBuilder`: توليد الخطاب المناسب حسب الإجراء والحالة والحقول (مبلغ/تواريخ/أطراف).
  - `history.php`, `get-timeline.php`, `get-history-snapshot.php`: تعرض سجل الأحداث.

## 10) إدارة بيانات الاختبار والصيانة
- `views/maintenance.php`:
  - إحصاءات اختبار: العدد، دفعات فريدة، أقدم/أحدث.
  - إجراءات حذف: كل الاختبار، حسب batch_id، أو أقدم من تاريخ محدد.
  - تتطلب كتابة “DELETE” للتأكيد. مخفية بالكامل في Production Mode.
- `GuaranteeRepository::deleteTestData`:
  - يحذف من الجداول التابعة (history, decisions, metadata, learning_confirmations, guarantee_occurrences) ثم الضمانات، ثم ينظف `batch_metadata`.
  - ملاحظة: يستدعي `commit()` بدون فتح معاملة؛ يحتاج التفاف بـ transaction لتفادي خطأ SQLite “no transaction is active”.

## 11) التعلم والترشيحات
- `AuthorityFactory` ينتج `UnifiedLearningAuthority` (النسخة الجديدة بعد إلغاء `LearningService`).
- مصادر الثقة:
  - Cache في `supplier_learning_cache` (fuzzy + weights + usage/rejection).
  - `learning_confirmations` (قبول/رفض المستخدمين).
  - Overrides وأسماء بديلة.
  - تاريخ القرارات السابقة للمورد نفسه (HistoricalSignalFeeder عبر `GuaranteeDecisionRepository::getHistoricalSelections`).
- مستويات الثقة (Levels A/B/C/D) تُستخدم في الـ UI، مع عتبات من الإعدادات.
- Penalties/Boosts: `REJECTION_PENALTY_PERCENTAGE`, `CONFIRMATION_BOOST_TIER1..3`, `LEARNING_SCORE_CAP`.

## 12) التحقق من التعارض (ConflictDetector)
- يعتمد على:
  - فرق الدرجات بين أول مرشحين مقارنة بـ `CONFLICT_DELTA`.
  - مصدر الترشيح (alternative/override) مقابل العتبة.
  - طول التطبيع والاسم الخام.
  - تحذير “ذكاء” إذا كان الاسم الخام رُبط سابقًا بمورد آخر ناجح.
- ثغرة حالية: الاستعلام يستخدم `g.raw_supplier` غير الموجود؛ يجب تعديل الاستعلام إلى `json_extract(g.raw_data, '$.supplier')` أو عمود مخزّن.

## 13) تفاصيل متعلقة بالبنك
- التطبيع عبر `BankNormalizer`.
- البحث في البدائل والاختصارات.
- عند تطابق البنك: يسجل حدث مستقل في التاريخ، ويُحدّث `raw_data['bank']` إلى الاسم المطابق (يطمس الاسم الأصلي؛ قد يؤثر على أثر المصدر).

## 14) الحوكمة والوكيل الذكي
- المجلد `.bgl_core/brain/` يحوي:
  - `domain_rules.yml`: قواعد تحظر/تحذر.
  - `style_rules.yml`: قواعد شكلية غير حاجبة.
  - `runtime_safety.yml`: صلاحيات الكتابة وفحوص التشغيل.
  - Playbooks (مثال: rename_class) + ADR في `docs/adr`.
  - تقارير صحية في `.bgl_core/logs/latest_report.html`.
- الوكيل يعمل بمفهوم “Executive Guardian”: كل تعديل يمر بثلاث طبقات تحقق، Sandbox git worktree، وRollback عند الفشل.

## 15) الواجهة وتجربة المستخدم
- CSS في `public/css` (design-system, components, layout).
- JS للتايملاين وغيره في `public/js`.
- التنقل بين السجلات يعتمد على URL params (`id`, `filter`, `search`, `jump_to_index`).
- البحث في `index.php` يستخدم `LIKE` على `raw_data` واسم المورد، ما يعني مسح كامل الجدول عند غياب فهارس مشتقة.

## 16) إنتاج الخطابات
- `LetterBuilder` يُنشئ محتوى الخطابات حسب نوع الإجراء وحالة الضمان (تمديد، تخفيض، إطلاق) بالاعتماد على بيانات القرار والـ raw_data، ويستخدمها `get-letter-preview.php` لتقديم معاينة.

## 17) اعتبارات الأداء والمخاطر المعروفة (من الشيفرة الحالية)
- استعلامات LIKE على JSON غير مفهرس (البحث والملاحة) قد تبطئ مع حجم بيانات كبير؛ يفضل أعمدة مشتقة (`raw_supplier_name`, `raw_bank_name`) أو FTS.
- استبدال حقل البنك في `raw_data` يزيل الأثر الأصلي؛ أفضل حفظ الحقل الأصلي في `raw_bank_name`.
- ترتيب معالجة الذكاء الاصطناعي تنازليًا بالأحدث قد يترك ضمانات قديمة عالقة “pending” عند تدفق مستمر؛ يفضل FIFO أو طابور بوسم `processed_at`.
- حذف بيانات الاختبار يحتاج معاملة لضمان الذرّية.
- اختلاف سياسة فلترة `is_test_data`: المستودعات تستبعد افتراضيًا إلا إذا طُلب، بينما Navigation يعتمد فقط على Production Mode؛ يلزم توحيد السياسة عبر معامل صريح.

## 18) خريطة سريعة للملفات الحرجة
- دخول: `index.php`, `agent-dashboard.php`.
- إعدادات/صيانة: `views/settings.php`, `views/maintenance.php`.
- مستودعات: `app/Repositories/*.php` (خصوصًا GuaranteeRepository, GuaranteeDecisionRepository, LearningRepository, BankRepository, SupplierRepository).
- خدمات أساسية: `app/Services/ImportService.php`, `SmartProcessingService.php`, `ConflictDetector.php`, `NavigationService.php`, `StatusEvaluator.php`, `TimelineRecorder.php`, `LetterBuilder.php`, `StatsService.php`.
- تعلم: `app/Services/Learning/AuthorityFactory.php` + UnifiedLearningAuthority وما يجاورها.
- دعم: `app/Support/{Settings,Database,Config,TypeNormalizer,BankNormalizer,ArabicNormalizer,Input,Logger}.php`.
- واجهات إدخال/قرارات: `api/import.php`, `api/manual-entry.php`, `api/parse-paste*.php`, `api/save-and-next.php`, إجراءات `extend/reduce/release`, وملفات القراءة `get-record.php`, `get-current-state.php`, `get-timeline.php`.

## 19) ماذا يحدث عند Production Mode
- UI: إخفاء إدخال/إنشاء بيانات اختبار، وإخفاء maintenance.
- API: منع إنشاء بيانات اختبار جديدة (يُضبط عبر Settings ويُقرأ في نقاط متعددة).
- تصفية: معظم الشاشات تستبعد `is_test_data`; Navigation يطبّقها فقط في الإنتاج؛ الإحصاءات لا تطبقها حاليًا (يستلزم ضبط).

## 20) وكيل الماوس الإدراكي (فبراير 2026)
- المجلد `.bgl_core/brain/` يحتوي الآن طبقات منفصلة: `perception.py` (سياق موضعي للهدف والـ viewport)، `policy.py` (الحلقة استكشف → اقترب → قيّم → قرّر وتسجيل `runtime_events` و `learned_events`)، `motor.py` (المسار البشري الوحيد المسموح به للحركة/الضغط) و `hand_profile.py` (ملف هوية يد ثابت لكل جلسة ينتج السرعة والجِتر والترددات).
- كل الأوامر الحركية تمر عبر `Motor.move_to`؛ تم منع أي استخدام مباشر لـ `page.mouse.*` بواسطة حارس `check_mouse_layer.py`.
- الحالة الداخلية للماوس `mouse_state ∈ {idle, approaching, at_target, invalid_target}`؛ الأفعال (click/press) مسموحة فقط من حالة `at_target` مع مسار تعافٍ عند `invalid_target` بدل التعليق.
- الإدراك موضعي وخفيف: نص العنصر وحالته ومرئيته + جار قريب وعنوان قريب؛ لا تُلتقط الصفحة كاملة إلا عند فشل غير مفسَّر.
- الاستكشاف الآمن مرة لكل صفحة (scroll خفيف أو hover محايد) قابل للتعطيل عبر المتغير `BGL_EXPLORATION`، وتُسجل النتائج في `learned_events.tsv`. السرعات والمسافات ديناميكية نسبية لحجم الشاشة وملف اليد.
- تشغيل مرجعي مختصر عبر `scenario_runner.py --include index_load` يستخدم الجلسة الواحدة والـ pointer المرئي؛ `metrics_summary.py` يلخص أزمنة move→click و click→DOM، واللقطات تحفظ في `storage/logs/captures`.
- قياس DOM بعد النقر أصبح عبر MutationObserver سريع (بدلاً من طول innerHTML) لرصد أول تغيير بعد الضغط.
- أوامر تشغيل موحّدة مضافة: `run_ui.ps1` (مراجعة بصرية headless=0) و `run_ci.ps1` (قياس headless=1 + metrics_summary + check_mouse_layer).
- معايير القبول الحالية: عدم وجود قفز مرئي، تطابق مؤشر النقر مع الموضع، قرارات تتغير بعد تقييم عند الهدف، وتنوع مسارات الحركة (overshoot/hesitation) ضمن hand_profile.
- ضبط hand_profile قابل عبر متغيرات البيئة (`BGL_BASE_SPEED_MIN/MAX`, `BGL_JITTER_MIN/MAX`, `BGL_OVERSHOOT_MIN/MAX`, `BGL_HESITATION_MIN/MAX_MS`) دون تعديل الكود.
- `metrics_summary.py` يحفظ ملخصًا JSON إلى `analysis/metrics_summary.json`؛ حارس القياس `metrics_guard.py` يتحقق من نطاق move→click (افتراضي 2000–6000ms) ويستطيع إلزام عينات DOM (`BGL_REQUIRE_DOM_CHANGE=1`).
- المساهمات اللاحقة مسموحة لتحسين hand_profile أو سيناريوهات DOM إضافية؛ الحارس check_mouse_layer وقيود Motor تبقى إلزامية.

هذه الوثيقة تعكس الحالة الراهنة في المستودع حتى 1 فبراير 2026 (مع إضافة طبقة وكيل الماوس). أي اختلافات مستقبلية تتطلب تحديثها. 
