# خطة التنفيذ الكاملة لوكيل BGL3 (منظمة ومحدّثة)

**التاريخ:** 2026-02-11  
**الهدف النهائي:** وكيل يعمل كـ “بلوق حي” لمشروع قائم: يفهم الكود والواجهة والسلوك runtime، يكتشف الفجوات، يقترح الإصلاحات، ينفّذها بأمان، ويقيس الأثر بدقة.  
**اتفاق الإنهاء:** عند تحقق معايير DoD كاملة، يُعتبر الوكيل جاهزًا ولا تُجرى تطويرات إضافية إلا بطلب جديد صريح.

---

## 1) الوضع الحالي (مختصر بناءً على آخر تقرير)
**مرجع التقرير:** `latest_report.json` بتاريخ 2026-02-11 10:48  
- **success_rate:** 0.575 (أقل من هدف DoD 0.75).  
- **context_digest:** ok=false (timeout=120s) — ما زال يفشل.  
- **ui_action_coverage:** 23.31% (أقل من هدف DoD 30%).  
- **flow_coverage:** 100% (sequence) مع **operational_coverage_ratio = 100%** (تحسّن كبير في التسلسل).  
- **ui_semantic_delta:** changed=true, change_count=89 (تحقق دلالي فعلي).  
- **gap_scenarios:** موجودة (5)، مع **gap_runs=3** و **gap_changed=0** (الأثر على UI coverage ما زال محدودًا).  
- **scenario_run_stats:** status=`ok` مع `event_delta=839` و `duration_s≈802` (تشغيل فعلي بأحداث).  
- **canary_status:** evaluated = 0 (ما زال دون تقييم فعلي).  
- **diagnostic_status:** complete (انتهى التشغيل؛ لا أقفال نشطة في السجل).  
- **طبقة اختيار السيناريوهات الذكية:** مفعّلة + Auto‑Budget نشط (تعديل ذاتي للأوزان/الصرامة والميزانية).

---

## 2) مبادئ التنفيذ (غير قابلة للتجاوز)
- لا يتم تشغيل أي فحوصات/تشخيصات جديدة إلا بعد موافقة صريحة من صاحب المشروع.
- التغيير يُفعّل عبر القدرات الموجودة أولًا قبل بناء جديد.
- الربط يكون **شاملًا عبر الذاكرة** وليس فقط عبر التقرير.
- أي نتيجة جزئية تُوسم بوضوح حتى لا تُقرأ كنهائية.

## 2.1 ملخص الأولويات (Now / Next / Later)
**Now (يُنفّذ عبر Priority Loop الدائم):**
- Context Digest Integrity: ok=false (timeout=120s) — أعلى كسر حالي.  
- Run Integrity: اجتازت آخر تشغيل (diagnostic_status=complete) — مراقبة فقط.  
- Event Integrity: اجتازت آخر تشغيل (event_delta=839, source=db) — مراقبة فقط.  

**Next (بعد استقرار التشغيل):**
- رفع UI Action Coverage إلى ≥ 30%.  
- تثبيت Flow Coverage (sequence) عبر جلسات متعددة (حاليًا 100%).  
- استعادة success_rate ثم رفعه إلى ≥ 0.75.  
- إعادة تفعيل تقييم canary (evaluated>0).

**Later (تثبيت واستدامة):**
- بقية معايير DoD طويلة المدى + تحسينات الجودة المستمرة.

---

## 3) خطة توحيد تنفيذ الفحوصات + ربطها بالذاكرة (محور تشغيل جديد)

**الهدف:** تشغيل آلي بدون انتظار يدوي، مع تمييز الجديد عن القديم، وربط ذلك بذاكرة الوكيل وفهمه.

### 3.1 النقاط الخمس الأساسية (تنفيذ موحّد)
1) **تشغيل مجدول + سجل حالة التشغيل**  
   - **تم:** سجل حالة تشغيل مركزي عبر `diagnostic_status.json` (running/cached/timeout/complete).
2) **تقرير مرحلي (Checkpoint)**  
   - **تم:** `diagnostic_checkpoint` في `runtime_events` (profile/scenarios/route_scan).
3) **فحص سريع تلقائي + فحص كامل دوري**  
   - **تم برمجيًا:** وضع `fast` يعمل بمسح محدود (scan) بدل التعليق/الـ stub.
4) **إشعار اكتمال الفحص**  
   - **تم:** `diagnostic_run_complete` + تحديث status.
5) **Auto-timeout + تقرير جزئي**  
   - **تم:** budget داخل `guardian` + fallback على تقرير مخزّن عند timeout.

### 3.2 دلتا واضحة (الجديد vs القديم)
- **تم:** مقارنة تلقائية بين آخر تقريرين داخل `diagnostic_comparison`.
- تصنيف التغيّر: `improvement`, `regression`, `noise`.
- **تم:** حفظ baseline مستقل (Full فقط) + مقارنة baseline داخل التقرير.

### 3.3 تمكين الضبط الآمن لحدود الفحوصات (الوقت/المسارات/السيناريوهات)
- تمكين الوكيل من ضبط:
  - `diagnostic_timeout_sec`
  - `route_scan_limit`
  - `route_scan_max_seconds`
  - `scenario_batch_limit`
- التعديل يتم عبر **مسار آمن** فقط: `storage/agent_flags.json`.
- التعديل يعتمد على نتائج التشغيل السابقة (مدة الفحص، نسبة النجاح، نسبة التوقف).
  - **تم:** عبر `config_tuner.py` (SAFE_KEYS).

### 3.4 ربط الفحوصات بمسار الذاكرة والفهم
- تسجيل أحداث الفحوصات في `runtime_events`:
  - `diagnostic_run_started`
  - `diagnostic_checkpoint`
  - `diagnostic_run_complete`
  - `diagnostic_delta`
- تحويل هذه الأحداث تلقائيًا عبر `context_digest` إلى Experiences.
- ربطها بـ `decision_traces` كي يقرأ الوكيل أثرها عند التخطيط أو التصحيح.
  - **تم:** الأحداث + التحويل عبر `context_digest.py` + `decision_traces`.

### 3.5 طبقة حماية الفحوصات (منع الأخطاء غير المقصودة)
- **تم:** تأجيل الفحص تلقائيًا عند نشاط المستخدم (`diagnostic_idle_guard_sec`).
- **تم:** كشف الإنهاء القسري وتحويله إلى `aborted` بدل نتائج مضللة.
- **تم:** عدم تحديث baseline إلا عند ثقة كافية (`diagnostic_confidence >= 0.7`).
- **تم:** تشغيل الخلفية بدون نافذة عبر daemon.

### 3.6 تصنيف أخطاء الفحوصات (Fault Taxonomy)
- **تم:** إضافة `diagnostic_faults` مع أسباب واضحة (timeout/aborted/low_events/low_coverage).
- تُسجَّل للوكيل ضمن snapshots حتى لا يبني قرارات على فحص معيوب.

### 3.7 طبقة اختيار ذكية للسيناريوهات (Scenario Scheduler)
- **تم:** طبقة اختيار تعتمد على نتائج التشغيل السابقة بدل تشغيل دفعة ثابتة.  
- **تم:** ضبط ذاتي للصرامة/الأوزان والـ cooldown حسب معدلات الفشل.  
- **تم:** ربط “الوقت الضائع” بميزانية تشغيل تلقائية (Auto‑Budget).  
- **مخرجاتها:** `scenario_selection.json` + إدراج selection ضمن Scenario Run Stats.
- **تم:** ضمان وجود سيناريو UI واحد على الأقل عند توفره + إظهار توزيع الأنواع (`selected_kinds`).

### 3.8 مهمة دائمة للأولويات (Priority Loop)
**الوصف:** مهمة دائمة تُنفّذ **أول 3 أولويات بالتتالي** قبل كل تشخيص.  
**المسار:** `.bgl_core/brain/priority_loop.py`  
**المخرجات:**  
- سجل تشغيل: `.bgl_core/logs/priority_loop_state.json`  
- أحداث تشغيل: `runtime_events` (event_type: `priority_*`)  
**خطواتها الثابتة:**  
1) **Run Integrity** (أقفال stale + حالة diagnostic_status).  
2) **Event Integrity** (event_delta + fallback + db_write_locked).  
3) **Context Digest Integrity** (timeout + auto-tuning آمن).

---

## 4) البنود المتبقية من الخطة الأصلية (مرتبة حسب الأثر)

## 3.8 طبقات نزاهة التشغيل (Integrity Layers) + مؤشرات لكل طبقة
**الغرض:** تمكين الوكيل من تفسير سبب الانحراف بدقة بدل إطلاق حكم عام على الاستكشاف.  
**القاعدة:** كل طبقة لها **مؤشرات واضحة** + **قرار تشغيلي**.

### 1) طبقة سلامة التشغيل (Run Integrity)
**قرارها:** إذا فشلت → **أوقف القراءة فورًا**.
- **مؤشرات:**  
  - `diagnostic_status.status=running` بعد وجود تقرير مكتمل (`latest_report.json.timestamp`).  
  - أقفال stale (مثل `run_scenarios.lock` بحالة `stale_dead_pid` أو `active_stale`).  
  - `stage_history` يتوقف طويلًا على نفس المرحلة (no progress).  
  - غياب `success_rate` من التقرير (تقرير ناقص).  
  - `scenario_run_stats.duration_s=0` مع `attempted=true`.

### 2) طبقة سلامة الأحداث (Event Integrity)
**قرارها:** إذا فشلت → **اقرأ بحذر ولا تستنتج**.
- **مؤشرات:**  
  - `event_delta=0` مع وجود تشغيل فعلي.  
  - الاعتماد على fallback كمصدر أساسي (`runtime_events_fallback.jsonl` مرتفع).  
  - أحداث بلا `run_id` أو `run_id` مختلف عن الدفعة الحالية.  
  - `db_write_locked` متكرر أثناء التشغيل.

### 3) طبقة سلامة الاستكشاف (Exploration Integrity)
**قرارها:** إذا فشلت → **وسم النتائج low_coverage**.
- **مؤشرات:**  
  - `scenario_selection.json` بلا سيناريو UI فعلي.  
  - `route_scan_limit` منخفض مقارنة بعدد المسارات.  
  - تغطية UI منخفضة جدًا (`ui_action_coverage < 30%`).  
  - gaps تتكرر دون تحسن (`gap_runs` مرتفع مع `gap_changed=0`).  
  - تعطل الشبكة/الجلسات (أخطاء HTTP/Session متكررة).

### 4) طبقة سلامة الفهم (Understanding Integrity)
**قرارها:** إذا فشلت → **لا تُحدّث الذاكرة أو السياسات**.
- **مؤشرات:**  
  - `context_digest.ok=false` أو timeout متكرر.  
  - `ui_semantic_delta.changed=false` عبر أكثر من جلسة.  
  - عدم ترابط flow docs مع الأحداث (`flow_coverage.sequence` منخفض).  
  - selectors غير ثابتة (تغيرات كبيرة في gaps دون سبب واضح).  

> **ملاحظة تشغيلية:** هذه الطبقات تُفعّل كمنطق قرار قبل أي تحليل للنتائج، وتُسجّل الحالة النهائية كـ `integrity_gate` في `runtime_events`.

### 3.8.1 ربط المؤشرات بالمخرجات الفعلية (Mapping)
**الهدف:** تمكين أي مطوّر (والوكيل لاحقًا) من معرفة أين تُقرأ كل إشارة.

| الطبقة | المؤشر | مصدر القراءة (مسار/ملف) | الإجراء عند الفشل |
|---|---|---|---|
| Run Integrity | `diagnostic_status.status=running` بعد اكتمال تقرير | `latest_report.json.timestamp` + `.bgl_core/logs/diagnostic_status.json` | أوقف القراءة |
| Run Integrity | أقفال stale | `.bgl_core/logs/diagnostic_status.json` → `locks.*.status` | أوقف القراءة |
| Run Integrity | `scenario_run_stats.duration_s=0` مع `attempted=true` | `latest_report.json.scenario_run_stats` | أوقف القراءة |
| Run Integrity | `success_rate` مفقود | `latest_report.json.success_rate` | أوقف القراءة |
| Event Integrity | `event_delta=0` مع تشغيل فعلي | `latest_report.json.scenario_run_stats.event_delta` | اقرأ بحذر |
| Event Integrity | fallback مسيطر | `.bgl_core/logs/runtime_events_fallback.jsonl` | اقرأ بحذر |
| Event Integrity | أحداث بلا `run_id` | `runtime_events` (DB) أو `runtime_events_fallback.jsonl` | اقرأ بحذر |
| Event Integrity | `db_write_locked` متكرر | `runtime_events` + `diagnostic_faults` | اقرأ بحذر |
| Exploration Integrity | لا يوجد UI scenario | `scenario_selection.json` → `selected_kinds` | وسم low_coverage |
| Exploration Integrity | `route_scan_limit` منخفض | `storage/agent_flags.json` | وسم low_coverage |
| Exploration Integrity | `ui_action_coverage < 30%` | `latest_report.json.ui_action_coverage.coverage_ratio` | وسم low_coverage |
| Exploration Integrity | gaps تتكرر بلا تحسن | `latest_report.json.ui_action_coverage.gap_runs` + `gap_changed` | وسم low_coverage |
| Understanding Integrity | `context_digest.ok=false` | `latest_report.json.context_digest.ok` | لا تحدّث الذاكرة |
| Understanding Integrity | `ui_semantic_delta.changed=false` | `latest_report.json.ui_semantic_delta.changed` | لا تحدّث الذاكرة |
| Understanding Integrity | `flow_coverage.sequence` منخفض | `latest_report.json.flow_coverage.sequence_coverage_ratio` | لا تحدّث الذاكرة |
| Understanding Integrity | selectors غير ثابتة | `latest_report.json.ui_action_coverage.gaps` | لا تحدّث الذاكرة |

### 4.0 ترتيب الأولويات المتبقية (مختصر وواضح)
**Now (تُنفّذ عبر Priority Loop الدائم):**
1) **إصلاح context_digest timeout**  
   - لأنه يقطع الحلقة الذاتية ويمنع تغذية الذاكرة تلقائيًا.  
2) **Run Integrity**  
   - اجتازت آخر تشغيل (diagnostic_status=complete) — مراقبة فقط.  
3) **Event Integrity**  
   - اجتازت آخر تشغيل (`event_delta=839`، المصدر DB) — مراقبة فقط.  

**Next:**
4) **رفع UI Action Coverage** إلى ≥ 30%.  
5) **تثبيت Flow Coverage (sequence)** عبر جلسات متعددة (حاليًا 100%).  
6) **استعادة success_rate** ثم رفعه إلى ≥ 0.75.  
7) **Canary/Rollback**: إعادة `evaluated>0` بثبات.

#### 4.0.1 تحديثات برمجية منفّذة الآن (بدون تشغيل فحوصات)
- **Semantic Delta**: تم تفعيل دلتا عبر مقارنة آخر Snapshot مع أي Snapshot سابق (حتى عبر URL مختلف) + وسم `new_url` عند ظهور صفحة جديدة.  
- **UI Action Coverage**: تم تسجيل محاولات التفاعل التي لم تغيّر الـ DOM كـ `no_change` داخل `exploration_novelty` لرفع التغطية الواقعية وتقليل التكرار.  
- **Flow Sequence Coverage**: تم إضافة **Sequence Inference** عبر الجلسات (مع الحفاظ على القياس الصارم)، وإرجاع نسبتين: strict + inferred.  
- **Scenario Run Reliability**: تم احتساب أحداث fallback عند قفل قاعدة البيانات (`runtime_events_fallback.jsonl`) وربطها بـ `event_delta_total` بدل قراءة 0 مضللة.  
- **UI Gap Scenarios (ثبات أعلى)**: تم تمرير `selector_key` و`needs_hover` داخل فجوات UI وتوليد محددات أكثر ثباتًا + hover اختياري قبل النقر.  
- **Scenario Batch Timing**: تم تسجيل `scenario_batch_start/complete` مع مدة التنفيذ وإدراج `scenario_batch_duration_s` في التقرير لتحديد زمن التعليق بدقة.  
- **UI Action Snapshots (استقرار المطابقة)**: تم تضمين `selector_key` داخل `ui_action_snapshots` لتقليل فجوات وهمية وتحسين التطابق مع سجل الاستكشاف.  
- **Flow Gap Scenarios (ترتيب أوضح)**: تم استخدام `step_routes` عند توفرها لبناء سيناريوهات فجوة بتسلسل أقرب للمسار الفعلي، مع تمرير `step_events` في الميتاداتا.  
- **Semantic Delta (مصادر إضافية)**: تم احتساب تغيّر الدلالة عبر `runtime_events` من نوع `ui_semantic_change` + دلتا `ui_flow_transitions` كمسار احتياطي عند غياب دلتا مباشرة من الـ snapshots.  
- **Scenario Run Reliability (Timeouts واضحة)**: تم تمييز timeout بشكل صريح في `scenario_run_stats` (status=timeout + reason + duration_s + timeout_sec) بدل status=fail الغامض.  
- **UI Action Coverage (ربط gaps)**: تم اعتبار `gap_scenario_done` كمصدر تغطية فعلي عبر مطابقة `selector_key/selector` مع عناصر الـ UI snapshot.  
- **Flow Sequence Coverage (ربط gaps + تطبيع المسارات)**: تم احتساب gap runs كدليل تسلسل، وإزالة `/` النهائية من المسارات لتقليل عدم التطابق.  
- **تنظيف أقفال run_scenarios stale**: تم إضافة تنظيف تلقائي في `master_verify` قبل التشغيل لتفادي `event_delta=0` الناتج عن أقفال ميتة.  
- **Heartbeat لأقفال run_scenarios**: تم إضافة تحديث دوري للـ lock أثناء التشغيل لتجنب اعتباره stale أثناء التشغيل الطويل.  
- **توسيع صيغة الأقفال**: تم حفظ `created_at` و`ttl_recorded` داخل ملف القفل لتشخيص أدق (مع بقاء التوافق مع الصيغة القديمة).  
- **Heartbeat لقفل master_verify**: تم إضافة نبض دوري لقفل `master_verify.lock` لمنع اعتباره stale أثناء الفحص الطويل.  
- **Integrity Gate (تفعيل فعلي)**: تم احتساب طبقات النزاهة وإرفاق `integrity_gate` داخل التقرير + تسجيل الحدث في `runtime_events` قبل بناء التقرير.  
- **استعادة success_rate**: تم إرفاق `success_rate` كحقل أعلى في التقرير بناءً على `execution_stats.success_rate` لضمان ظهوره في القراءة السريعة.  
- **تعزيز تغطية UI في الجدولة**: تم إضافة `ui_boost` في `scenario_scheduler` لرفع أولوية سيناريوهات UI عندما تكون `ui_action_coverage` أقل من الهدف.  
- **تعزيز تغطية Flow في الجدولة**: تم إضافة `flow_boost` لرفع أولوية سيناريوهات `gap_flow_*` عندما يكون `sequence_coverage_ratio` أقل من الهدف.  
- **Context Digest Timeout Guard**: تم تمرير `--timeout` من المشغّل + حارس استعلامات SQLite (progress handler) لقطع الاستعلامات الطويلة وتسجيل timeout بوضوح.  
- **Scenario Runner Digest Timeout**: تم إزالة مهلة الـ 30s الثابتة بعد السيناريو، وربط مهلة `context_digest` بقيم الإعدادات (`auto_digest_timeout_sec` / `context_digest_timeout_sec`) مع تمرير `--timeout` للمشغّل.  
- **Atomic Lock Acquisition**: تم تحويل أقفال التشغيل إلى إنشاء حصري (exclusive create) لمنع تشغيلين متوازيين من أخذ نفس القفل.  
- **apply_proposal Guard + Lock**: تم منع تطبيق المقترحات أثناء تشغيل التشخيص، مع قفل مستقل لـ `apply_proposal` لتفادي تداخل الكتابة على قاعدة البيانات.  
- **Context Digest Adaptive Timeout + Interval**: تم تفعيل ضبط تلقائي للمهلة بناءً على آخر مدة ناجحة + حد أدنى بين التشغيلات لمنع التكرار غير الضروري.  
- **Context Digest Tuning Range**: تم توسيع حدود الضبط في `config_tuner` حتى لا تُقصّ المهلة بشكل مبالغ.  
- **Context Digest Indexes + Incremental Window**: تم إنشاء فهارس زمنية للأحداث/النتائج + استخدام نافذة incremental مع overlap لتقليل زمن الهضم.  
- **تحرير قفل scenario_runner**: تم إنهاء القفل تلقائيًا عند اكتمال التنفيذ أو عدم وجود سيناريوهات، لتقليل stale locks.  
- **event_delta أكثر موثوقية**: تمت إضافة `event_delta_db` و`event_delta_source` واستخدام الفallback كقيمة فعّالة عند تعذر الكتابة للـ DB.  
- **Fallback أقوى في scenario_runner**: تم تسجيل الأحداث في `runtime_events_fallback.jsonl` حتى عند فشل فتح قاعدة البيانات.  
- **Scenario run failure logging**: عند فشل تشغيل السيناريوهات يتم تسجيل `scenario_run_failed` وحساب `event_delta` في التقرير بدل تركه فارغًا.  
- **رفع وقت التشخيص**: تم ضبط `diagnostic_timeout_sec` و`diagnostic_budget_seconds` إلى 4200s للجولة الطويلة.  
- **رفع زمن تشغيل السيناريوهات**: تم رفع `scenario_batch_timeout_sec` إلى 1200s لتقليل timeouts.  

> **مهم:** هذه التعديلات مفعّلة برمجيًا، وتحتاج **تحقق تشغيلي واحد** لإثبات أثرها في التقرير.

### 4.1 ثبات التشغيل والقياس
> هذه البنود مفعّلة برمجيًا، والتقرير الأخير أظهر **event_delta=839** (status=ok) لكن **context_digest ما زال ok=false**.

- **Timeouts/Guards تشغيلية:** تعمل لكن يجب ربطها بالسبب التشغيلي عند فشل الدفعة.  
- **context_digest timeout:** يمنع تغذية الذاكرة ويعطل الحلقة الذاتية جزئيًا.  
- **مراقبة الأقفال:** لا توجد أقفال نشطة في آخر تشغيل، ويجب إبقاؤها تحت المراقبة.  
- **C4 اختبار سياقي بعد التعديل:** مفعّل برمجيًا ويحتاج تحقق عند التشغيل الكامل.  
- **C5 Runtime Profiling:** مفعّل ويحتاج ثبات في تسجيل القياسات.  
- **C6 Safe Patch Intelligence:** مفعّل ويحتاج بيانات تشغيلية كافية لتأثير فعلي على القرار.

### 4.2 تغطية الاستكشاف (نتائج فعلية من التقرير)
- **UI Action Coverage**: 23.31% — **ما زال أقل من الهدف**.  
- **Flow Coverage**: 100% (sequence) / 100% (operational) — تحسّن كبير لكن يحتاج ثبات عبر جلسات متعددة.  
- **Gap Loop**: يعمل (gaps موجودة وتُشغَّل) لكن تأثيرها على UI coverage ما زال ضعيفًا (gap_runs=3 و gap_changed=0).  
- **Semantic Delta**: changed=true, change_count=89 — **تحقق دلالي فعلي** لكن يحتاج ثبات عبر جلسات متكررة.

### 4.3 Loop & Canary
- **Proposals مستمرة**: موجودة لكن العدد منخفض (آخر تقرير: 1) — تحتاج ثبات تشغيل لتكثيف التوليد.  
- **Canary/Rollback**: في التقرير الأخير evaluated=0 — يحتاج بيانات تشغيل متسقة لإعادة التقييم.

---

## 4.4 ما تبقّى فعليًا الآن (تشغيلي فقط)
- **إصلاح context_digest timeout** لضمان دخول الخبرات للذاكرة تلقائيًا (حاليًا ok=false).  
- **رفع UI Action Coverage** عبر تشغيل موجّه للعناصر غير المُغطّاة (بدون تكرار غير مفيد).  
- **تثبيت Flow Coverage (sequence)** عبر جلسات متعددة (حاليًا 100%).  
- **استعادة مقياس success_rate** داخل التقرير ثم رفعه إلى ≥ 0.75.  
- **إعادة تفعيل تقييم canary** (evaluated>0).

---

## 4.5 خطة تشغيل تحقق واحدة (عند الموافقة)
**الهدف:** إثبات أن البنود التشغيلية تعمل كما هو متوقع، دون تشغيل متكرر أو طويل.

1) تشغيل تقرير واحد فقط (full diagnostic) بعد الموافقة.  
2) استخراج المؤشرات التالية من `latest_report.json`:  
   - `ui_action_coverage.operational_coverage_ratio`  
   - `flow_coverage.operational_coverage_ratio`  
   - `ui_semantic_delta.changed` + `change_count`  
   - وجود `gap_coverage_refresh` في runtime_events  
3) مقارنة النتائج قبل/بعد في `diagnostic_comparison`.

---

## 4.6 Checklist قبل طلب الموافقة (5 عناصر فقط)
1) **runtime_events غير معطلة**  
   تأكد أن الكتابة الفعلية تتم (وليس فقط fallback).  
2) **Scenario Scheduler لا يختار سيناريوهات ميتة**  
   راجع `scenario_selection.json` (هل فيها UI فعلي؟).  
3) **diagnostic_idle_guard لا يمنع التشغيل**  
   لا يوجد نشاط مستخدم أثناء التشغيل.  
4) **DB lock لم يعد المسار الأساسي + لا توجد أقفال stale**  
   fallback يجب أن يكون ثانويًا، لا المصدر الوحيد.  
5) **route_scan_limit ليس منخفضًا أكثر من اللازم**  
   حتى لا تُقتل flow coverage مبكرًا.  

إذا هذه الخمسة سليمة → التشغيل سيُنتج دلتا حقيقية.

---

## 5) Definition of Done (DoD)
**الهدف:** تعريف نهاية البناء بمؤشرات واضحة.

1) success_rate ≥ 0.75 لمدة 7 أيام متواصلة  
2) لا skipped بدون سبب، و timeouts < 3%  
3) ui_action_coverage ≥ 30%  
4) flow_coverage ≥ 60% مع events حقيقية  
5) gap loop يعمل بالكامل خلال 24 ساعة  
6) semantic_delta يظهر في ≥ 50% من الجلسات  
7) proposals أسبوعية مع أثر واضح  
8) canary_evaluated > 0 أسبوعيًا + rollback تلقائي  
9) blocked < 30% أو مع بديل قياس  
10) سجل موحّد واضح في القرار والنتيجة والأثر

---

## 6) فصل مساري التحسين (Self vs Project)

### مسار 1: تحسين الوكيل (Self-Improvement Loop)
- تحديث السياسات والاستكشاف والتغطية والقياس.
- لا يغيّر ملفات المشروع الإنتاجية.

### مسار 2: تحسين المشروع (Project Loop)
- تطبيق الاقتراحات على ملفات المشروع.
- يبدأ فقط بعد تحقق DoD كامل.

---

## 7) دليل التنفيذ لأي مطوّر

### 7.1 مصادر الحقيقة
- `.bgl_core/logs/latest_report.json`
- `.bgl_core/config.yml`
- `storage/agent_flags.json`
- `.bgl_core/brain/master_verify.py`
- `.bgl_core/brain/guardian.py`
- `.bgl_core/brain/authority.py`
- `.bgl_core/brain/apply_proposal.py`

### 7.2 خطوات تنفيذ أي بند
1) سجل baseline من التقرير الحالي.
2) فعّل capability موجودة بدل بناء جديد.
3) نفّذ دفعة تغييرات تخدم capability واحدة فقط.
4) عند الحاجة للتشغيل: اطلب موافقة صريحة.
5) قارن المؤشرات قبل/بعد.

### 7.3 طريقة العمل (ترتيب ثابت لمنع التشعب)
1) **حدد البند + مخرجاته**: ما الذي يجب أن يظهر في التقرير/السجل؟
2) **تحقق من المسار الحالي**: هل البنية موجودة أم ناقصة؟
3) **نفّذ التفعيل بأقل تعديل ممكن**.
4) **وثّق الأثر المتوقع** في نفس البند (حتى لو لم تُشغّل فحوصات).

### 7.4 ربط تلقائي مع ما سبق
- كل بند يجب أن يُظهر أثره في:
  - `runtime_events` (حدث واضح).
  - `decision_traces` (سبب القرار).
  - `latest_report.json` (قراءة التقرير).

### 7.5 منع بناء جديد غير ضروري
- أي تعديل يجب أن يمر عبر سؤالين قبل التنفيذ:
  1) هل يوجد مكوّن موجود يمكن تفعيله بدل بناء جديد؟
  2) هل يوجد جزء من الخطة الحالية يغطي نفس الهدف؟

---

## 8) توضيح ختامي
بعد اكتمال هذه الخطة، سيصبح الوكيل قادرًا على **تشخيص ومعالجة معظم مشاكل المشروع تلقائيًا** عبر حلقة ذاتية.  
لكن بعض الحالات ستبقى تتطلب تدخلًا بشريًا (شبكات خارجية، قرارات معمارية كبيرة، بيانات إنتاج حساسة).
