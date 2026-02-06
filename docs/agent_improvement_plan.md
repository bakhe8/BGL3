# خطة تمكين الوكيل لاقتراح/تنفيذ التحسينات تلقائياً في BGL3

## خلاصة تشغيلية (End-to-End Auto Flow)
1) الوكيل يشغّل فاحصاً ثابتاً + اختبارات فجوة مولَّدة تلقائياً في الساندبوكس.
2) أي فشل أو نمط غياب يولّد intent/proposal مرتبط بـ Playbook و Rule.
3) Decision/Gate يحدد السماح بالتطبيق التجريبي (auto_trial + assisted).
4) يُطبَّق الإصلاح في الساندبوكس، تُشغَّل اختبارات gap/fast، ويُنتَج diff.
5) تُعرض النتائج (gap/proposals/intent/decision/diff) في التقرير والـDashboard مع زر موافقة واحدة.
6) بعد الموافقة، يُطبَّق diff على الشجرة الحية ويُسجَّل outcome والنضج/التعارض.

## Quick Start (تنفيذ هذا الأسبوع)
- Playbook + Rule: استنساخ قالب `production_guard.md` لمحور rate_limit وإضافة Rule في `runtime_safety.yml`.
- Gap Test أولي: RateLimitTest (6 طلبات → 429) في `tests/Gap/`.
- Inference Loader: إضافة `inference_patterns.json` + Plugin `checks/missing_rate_limit_middleware.py`.
- عرض تقرير/Dashboard: قسم Gap Tests + Proposals فقط (diff لاحقاً).
- وضع التشغيل: `execution_mode=auto_trial`, `agent_mode=assisted`, `run_gap_tests=1`, `browser_enabled=1`.

## حالة التنفيذ (تعقب)
- Playbooks/Rules (rate_limit كقالب أول): ✅ تم إنشاء playbook + Rule RS005
- inference_patterns + plugin الأول: ✅ inference_patterns.json + checks/missing_rate_limit_middleware.py
- Gap Test rate_limit: ✅ tests/Gap/RateLimitTest.php
- Sections Gap/Proposals في التقرير/الـDashboard: ✅ التقرير + بطاقات Dashboard مضافة
- Patch templates: ✅ مضافة (rate_limit_laravel.php, audit_trigger.sql, validation_request.php، alerts_handler.php، caching_layer.php، import_safety_guard.php، backup_export_task.php، settings_autosave.js، critical_test.php، db_add_indexes.sql، db_add_foreign_keys.sql، js_split_placeholder.md)
- النضج/التعارض/الكبح: ✅ decision_engine يستهلك maturity/conflicts/suppression، outcomes تُسجَّل من المسار الفعلي (patcher/guardian/master_verify) وتغذي success_rate/nضج.
- الحمايات المفعّلة فعلياً: Import Safety (حجم/نوع + تنبيه)، Data Validation (Email/Phone) + AuditTrail للإنشاءات (banks/suppliers) + RateLimit اختياري (قابل للتعطيل في testing) + Caching بسيط للتقارير + Alerts logging + Backup export command.
- Gap Suite: ✅ جميع اختبارات Gap تمر (9/9) مع متغيّر `BGL_BASE_URL` وخادم PHP محلي، دون Fail/Skip.
- المكتبات الجاهزة: ✅ تم تثبيت schemathesis / email-validator / phonenumbers / python-stdnum (pip user install) و dredd (npm -g). جاهزة للتفعيل في gap suite عند الحاجة.
- Contract Tests: إضافة `contract_tests.py` لتشغيل Schemathesis/Dredd تلقائياً عند تفعيل `run_api_contract=1` في config ووجود openapi أو api.apib.

## 1) المعرفة (Playbooks + قواعد)
- إنشاء Playbooks صغيرة لكل محور:
  - `playbooks/rate_limit.md`
  - `playbooks/audit_trail.md`
  - `playbooks/data_validation.md`
  - `playbooks/alerts.md`
  - `playbooks/caching.md`
  - `playbooks/critical_tests.md`
  - `playbooks/settings_ux.md`
  - `playbooks/import_safety.md`
  - `playbooks/backup_export.md`
- لكل Playbook: الهدف، السياق، الخطوات، معايير القبول، نقاط الحقن (autoload/middleware/controllers/DB).
- إضافة قواعد في `runtime_safety.yml` أو `style_rules.yml` لكل محور (action=WARN) تصف الحالة المطلوبة (مثلاً: وجود RateLimiter للكتابة، سجلات Audit، فحوص Email/Phone، فهارس تقارير، إلخ).
- **كيفية التنفيذ**:
  1) انسخ قالب `playbooks/production_guard.md` وأعد ملء الحقول للمحاور الأخرى (هدف، سياق، خطوات، معايير قبول، نقاط حقن).
  2) لكل Playbook أضف Rule مقابلة في `runtime_safety.yml` أو `style_rules.yml` بصيغة موحدة: id، name، description، action، rationale، scope.
  3) شغّل `master_verify.py` للتأكد من تحميل القواعد بدون أخطاء YAML.
  4) أضف Front-matter YAML لكل Playbook (id, type, risk_if_missing, auto_applicable, conflicts_with[]) ليقرأه inference/decision دون تحليل النص.

## 2) أنماط استدلال (Inference Patterns)
- إضافة ملف/جدول seed (مثلاً `inference_patterns.json`) يحوي:
  - شرط قابل للفحص (غياب middleware للـRateLimit، غياب audit table/trigger، عدم وجود فهرس على حقل تقرير...).
  - توصية مرتبطة بالـPlaybook.
- توسيع InferenceEngine ليقرأ الأنماط ويولّد proposals عند تحقق الشروط.
- **كيفية التنفيذ**:
  1) أنشئ `/.bgl_core/brain/inference_patterns.json` به مصفوفة كائنات: `{ "id": "PAT_RATE_LIMIT", "check": "missing_rate_limit_middleware", "recommendation": "apply playbooks/rate_limit.md", "scope": "api" }`.
  2) اجعل `check` يشير إلى Plugin/دالة في `checks/` (مثلاً `checks/missing_rate_limit_middleware.py`) تُرجع شكل موحد: `{passed, evidence[], scope[]}`.
  3) في `inference.py` أضف Loader يستدعي الـchecks كـ plugins ويولّد proposals.
  4) اربط كل check بـ Playbook لعرضها في التقرير/الـDashboard.

## 3) اختبارات فجوة (Gap Tests)
- إضافة اختبارات/سيناريوهات قصيرة تُشغّل في الساندبوكس:
  - RateLimit: 6 طلبات متتالية → توقع 429.
  - Audit: تحديث بنك → توقع سجل تدقيق.
  - Validation: Email/Phone خاطئ → 422 ورسالة موحّدة.
  - ImportSafety: ملف كبير/نوع خاطئ → 400 برسالة محددة.
  - Critical: سيناريو إنشاء/تحديث/تصدير مختصر.
- وضعها في `tests/Gap/` أو سيناريوهات Playwright قصيرة، وربطها بـ SafetyNet/Master Verify (مُفعّلة عبر config).
- **كيفية التنفيذ**:
  1) أنشئ مجلد `tests/Gap/` وإضافة سكربتات PHPUnit بسيطة لكل محور (مثال RateLimitTest يرسل عدة طلبات ويتوقع 429).
  2) للـUI/Playwright: أضف سيناريو صغير تحت `scenarios/gap_rate_limit.spec.ts`... إلخ.
  3) حدّث SafetyNet أو `master_verify.py` ليشغّل مجموعة gap عند `run_gap_tests=1` في config.
  4) صنّف المسارات قبل التوليد: `idempotent-safe` (مسموح)، `stateful-danger` (تحتاج fixtures)، وفعّل auto-gen فقط للمسارات الموسومة @gap_testable أو ضمن allowlist.

## 4) ربط التقرير والـDashboard
- إضافة بطاقات:
  - Gap Tests (Pass/Fail + playbook المرتبط).
  - Inference Proposals (من الأنماط) مع زر “Apply in sandbox” (assisted).
- إظهار intent/decision/outcome لكل Gap Test فاشل لتبرير الاقتراح.
- **كيفية التنفيذ**:
  1) في `report_template.html` أضف قسم Gap Tests وProposals، يعتمد على مصفوفة `gap_tests` و`proposals` من diagnostic.
  2) في `agent-dashboard.php` أضف بطاقتين مشابهتين مع أزرار “Apply in sandbox” تستدعي مهمة patcher عبر أمر shell مخزن في agent_permissions للتصديق.

## 5) Gate/Decision Integration
- عند فشل Gap Test → يولّد intent “stabilize_<المحور>” + قرار propose_fix، ويسجّل في knowledge.db.
- في `execution_mode=auto_trial` و `agent_mode=assisted`: يطبق الإصلاح في الساندبوكس ويعرض diff للموافقة قبل التطبيق على الشجرة الحية.
- **كيفية التنفيذ**:
  1) بعد تشغيل Gap Tests، اجمع الفاشلة وأرسلها لـ `intent_resolver` كـ intentات مستقلة.
  2) decision_engine يحول كل intent فاشل إلى `propose_fix`، gate يمنع التطبيق المباشر في assisted.
  3) orchestrator/patcher ينفّذ الإصلاح في الساندبوكس فقط، ثم يكتب diff في logs/ ويطلب موافقة.

## 6) مسار التنفيذ
- Phase A (يومين): بناء playbooks والقواعد والأنماط (لا تغيّر السلوك).
- Phase B (يومين): إضافة Gap Tests وتشغيلها في master_verify (اختيارية عبر config).
- Phase D (يومين): تشغيل دورة كاملة CLI (فشل Gap → اقتراح → تطبيق في sandbox → diff → موافقة).
- Phase C (يومين): ربط Inference/Decision بالتقرير والـDashboard مع زر “Apply in sandbox” (بعد استقرار المسار في CLI).

## 7) الإعدادات
- أثناء التشغيل الآلي: `execution_mode=auto_trial`, `agent_mode=assisted`, `run_scenarios=1`, `browser_enabled=1` لتشغيل اختبارات الفجوة.
- إبقاء القدرة على تعطيل الاختبارات الثقيلة عبر config.

## 8) أتمتة توليد القواعد/الاختبارات (تقليل العمل اليدوي)
- كشف فجوات ذاتي:
  - Static scan: فاحص يغطي غياب middleware/RateLimit، فهارس DB، validation، audit hooks.
  - Dynamic gap tests auto-gen: توليد POST/PUT افتراضي لكل مسار كتابة وتشغيله في الساندبوكس ضمن master_verify؛ أي فشل يصبح Intent/Proposal.
  - Runtime signals: مراقبة runtime_events/الأخطاء المتكررة لتحويل الأنماط إلى اقتراحات.
- توليد القواعد/الأنماط تلقائياً:
  - عند تكرار فشل Gap Test، InferenceEngine يضيف pattern seed تلقائي إلى knowledge.db.
  - إنشاء مسودة Playbook/Rule في الساندبوكس عند اكتشاف فجوة، مع تعبئة السياق/الخطوات/معايير القبول وعرضها كمقترح diff.
- تطبيق شبه ذاتي:
  - `execution_mode=auto_trial` + `agent_mode=assisted`: الوكيل يطبق الإصلاح في الساندبوكس، يشغّل الاختبارات، ثم يعرض diff للموافقة الواحدة قبل التطبيق على الشجرة الحية.

## 9) نضج التحسينات (Improvement Maturity)
- لكل Playbook/Proposal احفظ حقل:
  - `maturity.level`: experimental | stable | enforced
  - `first_seen`: تاريخ أول ظهور
  - `success_rate`: نسبة نجاح تطبيقه (من outcomes)
- Decision Engine يستخدم النضج لرفع/خفض أولوية التنفيذ وتحديد الحاجة لموافقة بشرية.
 - أنواع outcomes الدقيقة التي تغذي success_rate: success، success_with_override، failed، false_positive، mode_direct.

## 10) تعارض التحسينات (Trade-offs)
- لكل Playbook أضف:
  - `conflicts_with`: قائمة تحسينات أخرى مع `impact` و`severity`.
- عندما تظهر تعارضات، يولّد الوكيل intent `design_tradeoff` ويعرض خيارات في الـDashboard بدلاً من “إصلاح واحد”.

## 11) كبح الاقتراحات منخفضة القيمة (Intent Suppression)
- قواعد suppression مثل:
  - `suppress_if`: route_usage < 1%، أو feature_flag == "deprecated".
- يمنع الوكيل من اقتراح تحسينات على مسارات ميتة ويقلل الضجيج.

## 12) مكتبات جاهزة (لتقليل الجهد)
- API/Gap Tests: schemathesis (توليد اختبارات من OpenAPI)، Dredd (contract testing).
- Validation: email-validator، phonenumbers.
- Diff عرضي: diff2html أو git diff → HTML.
- DB introspection: INFORMATION_SCHEMA أو مكتبات inspection (SQLAlchemy Inspector).

## 13) حفظ نقاء مصدر الحقيقة
- لا تكتب إلى knowledge.db تلقائياً للأنماط الجديدة؛ اكتبها في `proposed_patterns.json` داخل الساندبوكس واعرضها كـ diff قبل الدمج.

## 14) توحيد مخرجات التقييم (Rules/Gap/Proposals)
- اجعل Rule Evaluation يخرج نفس الشكل: `{evidence:[], scope:[], severity, id}` مثل gap_tests/proposals لضمان قناة بيانات واحدة للـDashboard/Report.
- عدّل loaders (rules, gap_tests, proposals) لتجميعها في diagnostic بقالب موحد، فتتجنب تنوع صيغ العرض/المنطق.

## 15) قوالب التصحيح (Patch Templates)
- أنشئ مجلد `patch_templates/` يحوي قوالب جاهزة: `rate_limit_laravel.php`, `audit_trigger.sql`, `validation_request.php`.
- Inference يختار template بناءً على playbook ويملأ placeholders (route/controller/field/index...) ثم يمرره للـpatcher في الساندبوكس.
- الفائدة: نتائج متكررة، أقل أخطاء، موافقات أسرع.

## 16) سياق الدومين (Domain Context)
- خارطة دومين موحدة: `docs/domain_map.yml` (كيانات، علاقات، قواعد ثابتة، KPIs تشغيلية).
- تدفقات حرجة موثقة تحت `docs/flows/` (مثال مبدئي: `create_guarantee.md`)، يتم التوسع لتشمل extend/release/import/export.
- ربط هذه الملفات بالـDashboard/التقرير (interpretation) لعرضها للمطور والوكيل، وتغذيتها لـ intent_resolver.
- توسيع runtime_events لإضافة entity_type/id ونتيجة العملية (success/fail) لتغذية experiences/decisions بسياق أعمال واضح.

## 17) وعي قاعدة البيانات (DB Awareness)
- استخراج مخطط قاعدة البيانات تلقائياً إلى `.bgl_core/brain/db_schema.json` و`docs/db_schema.md`.
- فحوص Inference للـDB:
  - `db_index_missing`: يرصد الفهارس المفقودة على أعمدة التقارير/الفلاتر.
  - `db_fk_missing`: يتحقق من وجود مفاتيح خارجية بين guarantees -> banks/suppliers.
- قوالب تصحيح جاهزة:
  - `patch_templates/db_add_indexes.sql`
  - `patch_templates/db_add_foreign_keys.sql`
- سكربت تطبيق سريع في الساندبوكس: `python .bgl_core/brain/apply_db_fixes.py --db storage/database/app.sqlite`

## ترابط المكونات (من الكشف إلى القرار)
- **كشف/تحقق**: قواعد + Gap Tests + Checks Plugins → تنتج evidence/scope موحدة.
- **استدلال**: inference_patterns → Proposals مرتبطة بـ Playbooks/Rules.
- **نية/قرار**: intent_resolver يكوّن intent، decision_engine يطبّق السياسات مع النضج/التعارض/الكبح.
- **بوابة التنفيذ**: execution_gate يحدد auto_trial/assisted ويمنع المسارات المحظورة.
- **التطبيق**: patcher/سيناريوهات تعمل في الساندبوكس باستخدام قوالب التصحيح والحد من التداخل.
- **النتائج**: outcomes تُسجَّل (success / success_with_override / failed / false_positive / mode_direct) وتُحدِّث success_rate والمستوى maturity، وتظهر في التقرير/Dashboard مع tradeoffs/suppression.

## المخرجات المتوقعة لكل مرحلة
- Quick Start: Playbook/Rule لمحور rate_limit + Gap Test واحد + Loader أنماط أولي + عرض Gap/Proposals في التقرير/الـDashboard، يعمل في auto_trial/assisted بدون تغيير الشجرة الحية إلا بعد موافقة.
- Playbooks/Rules (المحاور كلها): لكل محور ملف MD + Front-matter + Rule مقابلة؛ عند فشل الفحص أو الاختبار تُولد intent/proposal مرتبطة.
- Inference Patterns: قائمة checks plugins + inference_patterns.json؛ عند تحقق شرط يُضاف proposal تلقائياً في التقرير والـDashboard.
- Gap Tests: مجموعة اختبارات تغطي المحاور؛ أي فشل يتحول إلى intent “stabilize_<المحور>” ويظهر كـ Gap فاشل مع evidence/scope.
- Gate/Decision: قرارات مبنية على النضج/التعارض/الكبح؛ يمنع التنفيذ المباشر ويطبق في الساندبوكس ثم يعرض diff للموافقة.
- Execution Path (A/B/D/C): مسار CLI مكتمل (كشف → اقتراح → تطبيق sandbox → diff) ثم ربط واجهة التقرير/الـDashboard.
- الإعدادات: وضع auto_trial + assisted + run_gap_tests=1 + browser_enabled=1 لتشغيل الأتمتة بأقل تدخل.
- الأتمتة (القواعد/الاختبارات): كشف ذاتي + توليد أنماط/Playbooks كمسودات (proposed_patterns.json) + تطبيق شبه ذاتي بعد الموافقة.
- النضج/التعارض/الكبح: يحدد أولوية التنفيذ، يعرض tradeoffs، ويمنع الاقتراحات منخفضة القيمة (routes ميتة/ميزات deprecated).
- المكتبات الجاهزة: تقليل جهد بناء الاختبارات/التحقق/diff/inspection ورفع موثوقية النتائج.
- توحيد المخرجات: كل evidence/scope/severity/id في قناة واحدة للتقرير والـDashboard، مما يبقي العرض والقرارات متسقة.
- قوالب التصحيح: إصلاحات جاهزة قابلة للملء تقلل الأخطاء وتسرع الموافقات.

النتيجة النهائية: الوكيل يشغّل كشفاً واختبارات فجوة آلية، يولّد intents/proposals مرتبطة بـPlaybooks موصوفة، يطبّق الإصلاحات في الساندبوكس مع Gate ونضج/تعارض/كبح واضح، ويعرض كل شيء في تقرير/Dashboard موحد. دورك يقتصر على الموافقة النهائية على الـdiff. 
