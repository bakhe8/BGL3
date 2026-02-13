# خارطة الطريق التنفيذية لتشغيل ذاتي كامل للوكيل (مرتبة حسب الأولوية)

**التاريخ:** 2026-02-12

**الهدف النهائي:**
- تشغيل ذاتي كامل يقرأ النتائج، يولّد خطط الإصلاح، ينفّذها تلقائيًا، ويثبت أثرها في التقرير والخبرات دون تدخل بشري.
- جعل هذه الخطة نفسها قابلة للتنفيذ الذاتي من الوكيل (Self‑Executing Plan).

---

## **تحديث الإغلاق — 2026-02-13**

**الحالة التشغيلية الحالية (مثبتة):**
- `latest_report.json` (run_id=`diag_1770998625`) أظهر:
  - `audit_status=ok`
  - `scenario_status=ok`
  - `ui_action_coverage=32.08%` (تجاوز الهدف 30%)
  - `auto_review.status=OK` بدون أسباب Risk
- لا توجد عمليات `master_verify.py` عالقة بعد اكتمال التشغيل.

**مصفوفة الإنجاز مقابل الأولويات:**
- **P0‑1 (audit → proposals):** مكتمل ومفعّل.
- **P0‑2 (auto plan generation):** مكتمل ومتحقق فعليًا.
- **P0‑3 (Action Mapping patcher/write_engine):** مكتمل ومستخدم في مسار التطبيق.
- **P0‑4 (patcher actions: add_import/replace_block/toggle_flag/insert_event):** مكتمل.
- **P0‑5 (الربط داخل دورة التشغيل):** مكتمل.
- **P1 (الحوكمة التشغيلية: report/run_audit/locks):** مكتمل تشغيلًا.
- **P2 (Self‑Strategy outputs):** مكتمل (`strategy_state.json`, `scenario_value_scores.json`, `auto_gate_policy.json`).
- **P3 (Value‑Based Retention):** مفعّل (`retention_enabled=1` و `retention_disable_time_prune=1` عبر `agent_flags`).

**الإغلاق الرسمي للحلقة السابقة:**
- حلقة التعليق والتداخل: **مغلقة**.
- حلقة `ui_action_coverage_low`: **مغلقة** بعد تجاوز 30%.

---

**الوضع الحالي المؤكد (آخر تقرير Full):**
- `latest_report.json` عند `2026-02-12 17:56`.
- `success_rate=0.735`.
- `ui_action_coverage=25.62%` (أقل من الهدف 30%).
- `flow_coverage.sequence=100%` و `operational_coverage_ratio=100%`.
- `context_digest.ok=true` (timeout≈157s).
- `scenario_run_stats.status=ok` مع `event_delta=653` و `duration_s≈779`.
- `canary_status.evaluated=0` (skipped بسبب gate).
- `auto_review.status=Risk`.
- تشغيل مجدول كل 3 ساعات (`diagnostic_min_interval_sec=10800`).
- التقرير يُكتب ذريًا مع قفل (report writer lock).
- الحذف الزمني معطّل في التنظيف (`retention_disable_time_prune=1`).

---

**الفجوات الحالية التي تمنع الأتمتة الكاملة (نواقص حقيقية في الكود):**
1) لا يوجد مسار برمجي يقرأ نتائج التدقيق ويحوّلها تلقائيًا إلى **Proposals**.
2) `auto_plan` يعمل فقط على Proposals الموجودة في القرار، وليس على نتائج التدقيق.
3) لا يوجد ربط تلقائي ينفّذ خطط التدقيق عبر `patcher.php`.
4) `patcher.php` يدعم فقط `rename_class`, `rename_reference`, `add_method`.
5) نتائج التدقيق موجودة في `docs/` ولا تدخل في الـ runtime pipeline.
6) لا توجد خريطة “Action Mapping” تحدد متى نستخدم `patcher.php` ومتى نستخدم `write_engine`.
7) `Self‑Strategy` outputs (`strategy_state.json`, `scenario_value_scores.json`, `auto_gate_policy.json`) غير مُولدة فعليًا بعد.
8) Canary ما زال يُتجاوز بسبب Integrity Gate بدون سياسة تجاوز مشروط.
9) `retention_enabled` غير مُفعّل فعليًا في `agent_flags` رغم وجود محرك الاحتفاظ.

---

# **الأولوية القصوى P0 — خط التنفيذ الذاتي end‑to‑end**
**الهدف:** تحويل كل مخرجات التشغيل والتدقيق إلى إصلاحات تُنفّذ تلقائيًا داخل نفس الدورة.

**P0‑1) ربط نتائج التدقيق بـ Proposals تلقائيًا**
- إضافة مسار: `audit_to_proposals.py` يقرأ `docs/brain_functional_audit_core.json` ويولّد Proposals في `decision_db`.
- كل Proposal يضم: السبب، النطاق، الثقة، والإجراء المقترح.
- توثيق المصدر في `run_audit.jsonl`.

**P0‑2) توليد Patch Plans تلقائيًا**
- استخدام الآلية الموجودة `auto_plan` (`agency_core.py`) بعد حقن Proposals الجديدة.
- توحيد خطط التدقيق مع نفس مسار الخطط المعتادة (`.bgl_core/patch_plans`).

**P0‑3) تنفيذ تلقائي للخطط**
- إضافة “Action Mapping” واضح:
  - إذا كان التعديل مدعومًا في `patcher.php` → التنفيذ عبر `patcher.py`.
  - غير ذلك → التنفيذ عبر `apply_proposal.py` و`write_engine`.
- الهدف: لا يتم تجاهل أي خطة بسبب اختلاف المسار.

**P0‑4) توسيع قدرات `patcher.php`**
- إضافة Actions أساسية للحوكمة الذاتية، مثل: `add_import`, `replace_block`, `toggle_flag`, `insert_event`.
- بدون هذه الإضافات، سيبقى التنفيذ الذاتي ناقصًا.

**P0‑5) ربط المسار في `master_verify.py` أو `agency_core.py`**
- بعد كل تقرير Full، يتم:
  - تحويل التدقيق إلى Proposals.
  - توليد Patch Plans.
  - تنفيذ الخطط تلقائيًا حسب Action Mapping.
  - تحديث التقرير والخطط بشكل فوري.

**مخرجات P0 المطلوبة:**
- Proposals مولدة من التدقيق.
- Patch Plans تلقائية.
- تنفيذ فعلي عبر `patcher.php` أو `write_engine`.
- تحديث تلقائي للخطط بعد التنفيذ.

---

# **P1 — ضمان الاتساق والحوكمة التشغيلية**
**الهدف:** لا تشغيل بلا أثر، ولا تقرير مفقود، ولا غموض في المصدر.

**P1‑1) التقرير دائمًا يُكتب**
- لا يسمح بإتمام تشغيل بدون تحديث `latest_report.json`.
- عند timeout: تقرير جزئي أو cached بدل ترك التقرير القديم.

**P1‑2) مصدر التشغيل واضح دائمًا**
- `run_audit.jsonl` يجب أن يحوي `source`, `trigger`, `task_name`, `pid`, `timestamp` لكل تشغيل.

**P1‑3) إدارة أقفال صارمة**
- منع التوازي غير المقصود عبر atomic lock acquisition.
- منع تشغيل متزامن مع `apply_proposal`.

---

# **P2 — الحل الدائم المستدام (حلقة التحسين الذاتي 4 طبقات)**
**1) علاج السبب بدل العرض**
- تسجيل `failure_reason`, `safe_action_candidates`, `last_attempt` لكل عنصر.
- ترقية العناصر المتكررة إلى `Synthetic Coverage` تلقائيًا.
- تحويل فشل write إلى actions غير كتابية.

**2) تعلم تكتيكي (Auto Strategy)**
- `value_score` لكل سيناريو.
- دمج/إعادة بناء منخفض القيمة تلقائيًا.
- رفع وزن السيناريوهات عالية القيمة.

**3) تقييم ذاتي + تصحيح ذاتي**
- تجاوز مشروط لـ Integrity Gate عند سلامة المؤشرات.
- تعديل سياسة gate بعد X محاولات تخطٍ.

**4) توليد وصيانة ذاتية للخطط**
- تحديث تلقائي لقسم “مكتمل/باقٍ”.
- حذف بند بعد نجاح 3 مرات متتالية.

**مخرجات P2 المطلوبة:**
- `strategy_state.json`.
- `scenario_value_scores.json`.
- `auto_gate_policy.json`.

---

# **P3 — Value‑Based Retention (بدون زمن)**
**الهدف:** الحذف يصبح مصدر معرفة ويمنع إعادة توليد الضوضاء.

**P3‑1) Catalog موحّد لكل الأنواع**
- `scenarios`, `patch_plans`, `backups`, `playbooks`, `logs`, `snapshots`, `insights`.

**P3‑2) Fingerprint ثابت + Utility Score**
- `hash(kind + steps + route + selectors + method + url)`.
- الحفاظ على Top‑K لكل Route/Kind.

**P3‑3) Tombstones وربطها بالجدولة**
- `prune_index.jsonl` مع `block_budget`.
- منع إعادة توليد الضوضاء قبل انتهاء الميزانية.

---

# **خطة التنفيذ المرحلية (مرتبة حسب الأولوية)**
1) بناء `audit_to_proposals.py` وربطه بـ `agency_core.py`.
2) ضمان `auto_plan` يعمل على مخرجات التدقيق مباشرة.
3) Action Mapping: `patcher.php` أو `write_engine`.
4) توسيع قدرات `patcher.php`.
5) ربط المسار بـ `master_verify.py` بعد كل تقرير Full.
6) تفعيل مخرجات Self‑Strategy (`strategy_state.json`, إلخ).
7) Canary Auto Gate Policy.
8) تفعيل `retention_enabled` مع إزالة الحذف الزمني نهائيًا.

---

# **المخرجات الإلزامية التي يجب ظهورها بعد كل دورة**
- تقرير محدث `latest_report.json`.
- إدراج `auto_review.status`.
- تسجيل `run_audit.jsonl`.
- تحديث الخطط تلقائيًا (مكتمل/باقٍ).
- `strategy_state.json` و`scenario_value_scores.json` و`auto_gate_policy.json`.

---

# **مصادر الحقيقة**
- `.bgl_core/logs/latest_report.json`
- `.bgl_core/logs/diagnostic_status.json`
- `.bgl_core/logs/run_audit.jsonl`
- `.bgl_core/logs/prune_index.jsonl`
- `.bgl_core/brain/master_verify.py`
- `.bgl_core/brain/agency_core.py`
- `.bgl_core/brain/patcher.py`
- `.bgl_core/actuators/patcher.php`
