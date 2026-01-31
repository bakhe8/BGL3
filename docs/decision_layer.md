# طبقة Decision / Intent — الحالة الحالية والخطة والنتائج المتوقعة

## 1) الوضع الحالي (قبل الترقية)
- المراقبة: Guardian وBrowserCore يجمعان الأعطال، أسوأ المسارات، وحالة الموارد مع حارس ديناميكي.
- الحوكمة: agent_mode (safe/assisted/auto)، أذونات واضحة في `agent_permissions`.
- الخبرة: experiences تُسجل، وتُستهلك في التوصيات والتقرير.
- التفسير: طبقة interpretation تلخّص الحالة ولا تتحكم في التنفيذ.
- مشكلة الباقي: لا توجد ذاكرة قرارات، ولا بوابة تنفيذ موحدة تمنع التكرار أو الضجيج، والتنفيذ الآلي يعتمد على سياسات عامة فقط.

## 2) ما سنضيفه
1) **Decision DB (SQLite منفصل)**  
   جداول intents، decisions، outcomes، overrides. نسخة sandbox منفصلة لتجنب أقفال knowledge.db.
   - لكل قرار سنحفظ **Decision Context Snapshot** غير قابل للتعديل: health، active_route، recent_changes، guardian_top، browser_state… لضمان إمكانية تتبّع “لماذا قررنا؟”.
2) **Intent Resolver (وضع observe أولاً)**  
   يجمع من: experiences، runtime_events، Guardian priorities، user actions، تغيرات health. يُخرج intent + confidence + reason + scope.
3) **Decision Engine (Hybrid)**  
   - Rules حتمية + Heuristics؛ LLM مستشار فقط.  
   - يحدد decision (auto_fix | propose_fix | block | observe | defer)، risk_level، requires_human، justification.  
   - يسجل كل قرار في Decision DB (Decision Memory).
4) **Execution Gate**  
   يلفّ المسارات الحساسة (auto-fix/patcher، scenario batch، reindex الكبيرة).  
   سياسة مبدئية:  
   - auto_fix يُنفّذ فقط إذا risk=low وconfidence>0.75 وagent_mode يسمح.  
   - refactor/rename يتطلب human approval في assisted، وممنوع في safe.  
   - observe-only في المرحلة الأولى لتجنب تعطيل العمل.
5) **Dashboard/Report تكامل**  
   بطاقات: Intents Detected، Pending Decisions/Overrides، سجل قرارات مختصر. أزرار: Review Diff، Run Scenarios، Ignore Once، Mark Known Risk.
   - تبسيط: لا دفق بيانات جديد؛ نضيف الحقول إلى diagnostic نفسه ويُستهلك في التقرير والـDashboard كما هو.

## 3) خطة التنفيذ المرحلية (زمن تقديري)
- المرحلة 0 (يوم واحد): إنشاء decision.db + schema، وضع observe-only، عرض القرارات في التقرير فقط.
- المرحلة 1: Intent Resolver من البيانات الحالية (experiences + runtime_events + worst_routes). تسجيل intents.
- المرحلة 2: Decision Engine بسياسات YAML بسيطة؛ Execution Gate على auto-fix (مفعّل) والسيناريوهات (مفعّل).
- المرحلة 3: توسيع gate ليشمل reindex الكبيرة؛ إضافة human override في Dashboard.
- المرحلة 4: تحسين التوصيات (Actionable): اقتراح أوامر جاهزة، وتشغيل سيناريوهات تلقائياً في agent_mode=auto مع سجل كامل.
- المرحلة 5: تجربة خروج مباشر مشروط (execution_mode=direct) بعد N نجاحات sandbox، مع تسجيل outcomes mode_direct وتحذير Dashboard.

## 4) السياسات الأولية (مقترحة وبسيطة داخل config.yml)
- decision:
    - mode: assisted (افتراضي؛ safe/assisted/auto)
    - auto_fix:
        - min_confidence: 0.75
        - max_risk: low
    - refactor:
        - requires_human: true
    - reindex_full:
        - requires_human: true
- refactor/rename ممنوع في safe؛ في auto مسموح إذا intent عالي الثقة ولا تحذيرات موارد.
- sandbox لا يلمس decision.db الرئيسي؛ يعمل على نسخة محلية transient.
- كل إدخال decision يُسجَّل مع context_snapshot للحالة الزمنية لتسهيل التحقيقات لاحقاً.
- حالة **defer** اختيارية الآن، لكنها مفيدة لاحقاً لتمييز “مهم لكن ليس الآن” عن observe (لا شيء مهم).

## 5) النتائج المتوقعة بعد التنفيذ
- إنذارات ذات معنى: تتحول إلى نوايا + قرارات مبررة.  
- تقليل التنفيذ الزائد: Execution Gate يمنع التكرار والضجيج.  
- قابلية المراجعة: Decision Memory توثق لماذا وماذا حدث ونتيجته.  
- تدخل بشري مركّز: Dashboard يعرض قرارات/نوايا واضحة بأزرار فعلية.  
- مسار ترقيات لاحق: يمكن إضافة أوزان مخاطر، توصيات قابلة للتنفيذ، وتنبيهات خارجية (Slack/Email) دون إعادة هيكلة.
- تحسينات مستقبلية للـ outcome (لاحقاً): بدلاً من success/fail فقط، يمكن تسجيل قيم مثل prevented_regression / false_positive / confirmed_issue لزيادة قيمة التعلم.

## 6) المتطلبات والخطوات العملية
- ملفات جديدة خفيفة: `.bgl_core/brain/decision_db.py` (اتصال قصير ودوال init/insert)، `.bgl_core/brain/intent_resolver.py` (دالة واحدة resolve)، `.bgl_core/brain/decision_engine.py` (decide)، `.bgl_core/brain/execution_gate.py` (check).  
- تعديل: ربط gate بنقاط التنفيذ (patcher auto-fix، scenario runner، reindex الكبيرة) مع **إعادة استخدام** نفس أنماط الاتصال/التهيئة الموجودة (كما في knowledge.db) وعدم فتح مسارات موازية جديدة.  
- واجهة Dashboard/HTML report تعيد استعمال قنوات البيانات الحالية: إضافة أقسام Intents/Decisions فوق نفس تدفق التقرير دون إنشاء دفق جديد.  
- ضمان نسخ decision.db إلى sandbox كوحدة مستقلة (لا أقفال مشتركة).
- ملف مخطط قاعدة البيانات: `.bgl_core/brain/decision_schema.sql` (مقترح مرفق) مع جداول intents/decisions/overrides/outcomes وحقل context_snapshot لكل intent.

## 7) المبدأ الحاكم
“بدون طبقة Decision، الوكيل ذكي؛ ومعها، النظام حكيم.”  
نبدأ بالملاحظة (observe-only) ثم نفعّل المنع التدريجي لضمان الاستقرار وعدم تعطيل العمل القائم.

## 8) مخاطر يجب الانتباه لها
- أقفال SQLite: التزم باتصالات قصيرة وWAL في نسخة الساندبوكس فقط.
- ازدواج قرارات: بوابة واحدة center-point (`execution_gate.check_decision`) لكل المسارات الحساسة، لا مسارين متوازيين.
- أداء: `context_snapshot` صغير (نص JSON) حتى لا يثقل السجلات.
