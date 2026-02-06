# مخطط قاعدة بيانات المعرفة (knowledge.db)

هذا الملف يوثّق الجداول التشغيلية الخاصة بالوكيل (Agent Brain) داخل:
`.bgl_core/brain/knowledge.db`

> هذه الجداول منفصلة عن قاعدة بيانات التطبيق الأساسية (`storage/database/app.sqlite`).

## 1) القرار والنتائج
- `intents`: نوايا الوكيل مع سبب/ثقة وسياق.
- `decisions`: قرارات مبنية على النوايا.
- `overrides`: قرارات بشرية (approve/reject/defer).
- `outcomes`: نتيجة القرار (success/fail/blocked...).

## 2) الاستكشاف والنتائج
- `exploration_outcomes`: نتائج الاستكشاف (error/api_result/gap/search_query).
- `exploration_outcome_relations`: علاقات بين النتائج (reinforcement/contradiction).
- `exploration_outcome_scores`: درجة نتيجة الاستكشاف.
- `exploration_history`: العناصر التي تم استكشافها.
- `exploration_novelty`: قياس تكرار/حداثة العناصر.

## 3) الأهداف الذاتية
- `autonomy_goals`: أهداف تُولد من الإشارات/القرار/الاستكشاف.
- `autonomy_goal_strategy`: إحصاءات نجاح/فشل لكل استراتيجية.
- `autonomous_plans`: بصمة خطط الاستقلالية لتقليل التكرار.

## 4) الفرضيات
- `hypotheses`: فرضيات تشغيلية قابلة للتأكيد/النقض.
- `hypothesis_outcomes`: ربط النتائج بالفرضيات (supports/contradicts).

## 5) التعلم التراكمي
- `learning_events`: سجل تعلم موحد (learned_events.tsv + learning_confirmations).
- `learning_confirmations`: (قديمة) تأكيد/رفض الحالات الشاذة.

## 6) الذاكرة البنيوية (AST)
- `files`, `entities`, `methods`, `calls`: بنية الكود والتبعيات.
- `routes`: فهرس المسارات.
- `runtime_events`: أحداث التشغيل.
- `experiences`: ملخصات الخبرات.

## 7) الإرادة (Purpose)
- `volitions`: الهدف/التركيز الحالي للوكيل عبر الزمن.

## 8) سجلات التشغيل
- `agent_permissions`: قائمة الموافقات البشرية.
- `agent_activity`: سجل نشاط الوكيل (مركزّي).
- `agent_blockers`: معوقات معرفية تحتاج حل.
