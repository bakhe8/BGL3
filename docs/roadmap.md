# BGL3 Agent Roadmap (Full Domain/UX/DB Assurance)  

## الهدف
وكيل يفهم الـAPI والدومين والـUI بعمق، يشخّص ويصلح أوتوماتيكياً في الساندبوكس، ويقدّم قرارات مبررة مع إدارة تعارضات وتخفيض الضجيج.

## الحالة الحالية (مختصر)
- متصفح واحد، rename عبر AST، حوكمة/قرارات knowledge.db، تقارير HTML/JSON، Gap Tests أساسية، callgraph جزئي، OpenAPI جزئي مولّد.

## خارطة التنفيذ المتتالية

### A) فهم الدومين والـAPI
1. OpenAPI مكتمل لكل مسار كتابة (حقول/تحقق/أمثلة) في `docs/openapi.yaml` (manual + generated).
2. اختبارات عقود Schemathesis/Dredd مفعّلة في كل `master_verify`، ونتائجها تظهر في التقرير/الـDashboard.
3. Callgraph غني route→controller→service→repo مع الكيان (guarantee/bank/supplier/import_export) محفوظ في knowledge.db و`docs/api_callgraph.json`.
4. Gap Tests وظيفية (CRUD/422/400) لكل نموذج رئيسي، وأي فشل يرفع intent “stabilize_api_contract”.

### B) واجهة المستخدم والأداء
5. خرائط تغطية UI (Playwright) تُسجّل الأحداث وتعرض نسبة تغطية الشاشات الحرجة.
6. Hotspots JS: تفعيل `measure_perf=1`، إبراز WARN/FAIL من js_bloat، وتطبيق قوالب التفكيك/التحميل الكسول مع Gap UI بعد أي تعديل JS كبير.

### C) قاعدة البيانات والعمليات
7. Profiling خفيف: تسجيل latency_ms والاستعلامات البطيئة لمسارات الكتابة، مع لوحة “Top Slow Queries”.
8. فهارس/مفاتيح أجنبية أوتوماتيكية: فحص السكيمة مقابل playbooks DB_INDEX/DB_FK وتطبيق التصحيحات في الساندبوكس ثم إعادة عقود/Gap.
9. صلاحيات النظام: فحص/إصلاح صلاحيات الكتابة للمجلدات الحساسة قبل أي تعديل (junction/مسارات مؤقتة).

### D) القرار والتعاضد البشري
10. Trade-offs/Conflicts: عند وجود conflicts_with في playbook يُولد intent design_tradeoff بخيارات للموافقة (assisted) أو منع في safe.
11. نصوص وتحذيرات محسّنة: قوالب رسائل عربي/إنجليزي في playbooks/patch_templates لاستخدامها عند إنشاء تنبيهات للمستخدم النهائي.

## الحالة الأمنية
- المسار الأحادي للمتصفح إلزامي.
- direct mode مسموح فقط بعد N نجاحات sandbox متتالية ويُسجَّل في outcomes.

## التفعيل والضبط
- `run_api_contract: 1` مفعّل؛ `measure_perf: 1` موصى به.
- `run_gap_tests: 1`, `run_scenarios: 1` لتغطية UI عند الحاجة.

## تحقق دوري
- تشغيل `python .bgl_core/brain/master_verify.py` بعد كل تعديل مهم؛ راقب External Checks, Contract/Gap Tests, Callgraph/Route map, Perf/JS hotspots.
