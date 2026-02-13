# الخطة التنفيذية التقنية: تشغيل ذاتي كامل للوكيل (مرتّبة حسب الأولوية)

**التاريخ:** 2026-02-12
**الهدف:** تحويل النتائج (التشغيل + التدقيق) إلى خطط إصلاح وتنفيذ ذاتي تلقائي داخل نفس الدورة، مع سجل أثر واضح.

---

## تحديث الإغلاق — 2026-02-13

**الحالة التشغيلية الحالية (مثبتة):**
- `latest_report.json` (run_id=`diag_1770998625`) أظهر:
  - `audit_status=ok`
  - `scenario_status=ok`
  - `ui_action_coverage=32.08%` (أعلى من الهدف 30%)
  - `auto_review.status=OK` بدون أسباب Risk
- لا توجد عمليات `master_verify.py` عالقة بعد اكتمال التشغيل.

**مصفوفة الإنجاز (حسب الأولوية):**
- **P0‑1:** مكتمل (audit → proposals مفعّل).
- **P0‑2:** مكتمل (توليد patch plans تلقائيًا مفعّل).
- **P0‑3:** مكتمل (Action Mapping بين `patcher` و`write_engine` مفعّل).
- **P0‑4:** مكتمل (`add_import`, `replace_block`, `toggle_flag`, `insert_event` مدعومة).
- **P0‑5:** مكتمل (ربط المسار داخل دورة التشغيل).
- **P1:** مكتمل تشغيلًا (report/run_audit/locks).
- **P2:** مكتمل (وجود وتحديث `strategy_state.json`, `scenario_value_scores.json`, `auto_gate_policy.json`).
- **P3:** مفعّل (`retention_enabled=1`, `retention_disable_time_prune=1` عبر `agent_flags`).

**الإغلاق الرسمي للحلقات:**
- حلقة التعليق/التداخل: **مغلقة**.
- حلقة `ui_action_coverage_low`: **مغلقة** بعد تجاوز 30%.

---

## 0) الحالة الحالية المؤكدة (آخر تقرير Full)
- `latest_report.json` عند `2026-02-13 03:25:44` (run_id=`diag_1770941614`).
- `diagnostic_profile=full` و`cache_used=false`.
- `ui_action_coverage=28.23%` (35/124) وما زالت أقل من الهدف `30%`.
- `flow_coverage.sequence=100%` و`operational_coverage_ratio=100%`.
- `context_digest.ok=true` (timeout=120s).
- `scenario_run_stats.status=ok` مع `event_delta=652` و`duration_s≈475s`.
- `canary_status.evaluated=0` مع `skipped=true` و`reason=integrity_gate_not_ok`.
- `integrity_gate.overall.status=warn` بسبب `ui_coverage_low` و`gap_no_change` و`selectors_unstable`.
- `auto_review.status=Risk` بأسباب: `ui_action_coverage_low`, `canary_not_ok`, `canary_skipped`.

### 0.1) إجراءات فورية مشتقة من Risk الحالي (ملزمة قبل أي دورة جديدة)
1) **إغلاق فجوة UI Coverage (سبب: `ui_action_coverage_low`)**
- تفعيل مسار Non‑Write Coverage داخل `scenario_runner.py` للعناصر `safe` فقط (focus/blur/hover/scroll) مع منع أي submit/click خطِر.
- تسجيل `failure_reason`, `safe_action_candidates`, `last_attempt` لكل selector فجوة في سجل قابل للتعلم.
- معيار الإغلاق: وصول `ui_action_coverage >= 30%` في دورتين متتاليتين.

2) **إيقاف حلقة gap_no_change (سبب: `gap_no_change`)**
- في `scenario_scheduler.py` خفّض وزن العناصر التي لم تتغير عبر 3 دورات (`gap_changed=0`) وارفع وزن مسارات UI جديدة.
- أدخل قاعدة إعادة توزيع: لا يُسمح بأن تتجاوز مجموعة gaps المتكررة 50% من الاختيار في الدورة.
- معيار الإغلاق: `gap_changed > 0` أو انخفاض `gap_count` الفعلي عبر دورتين.

3) **منع تخطي Canary بشكل دائم (سبب: `canary_skipped`, `canary_not_ok`)**
- في `agency_core.py` طبّق سياسة gate مشروطة: إذا `integrity overall=warn` و`event_delta>0` و`scenario status=ok` يتم تقييم Canary مشروط بدل skip.
- اجعل `canary_status.evaluated` إلزاميًا رقمًا >0 عند وجود releases.
- معيار الإغلاق: إلغاء `skipped=true` وظهور نتيجة Canary مقيمة في التقرير.

4) **تثبيت استدامة التعلم الذاتي (سبب: `selectors_unstable`)**
- إضافة تحديث تلقائي لملفات الاستراتيجية بعد كل دورة: `strategy_state.json`, `scenario_value_scores.json`, `auto_gate_policy.json` (مفعّل حاليًا) مع ربط قرار الجدولة بها.
- معيار الإغلاق: تغير فعلي في `top_selected` أو `weights` مبني على نتائج الدورة السابقة بدل التكرار الثابت.

---

## 1) الفجوات التقنية الحالية (نواقص فعلية في الكود)
1) لا يوجد مسار برمجي يحوّل نتائج التدقيق إلى **Proposals** تلقائيًا.
2) `auto_plan` يعمل فقط على Proposals موجودة، ولا يقرأ نتائج التدقيق مباشرة.
3) لا يوجد ربط تلقائي ينفّذ خطط التدقيق عبر `patcher.php`.
4) قدرات `patcher.php` محدودة (`rename_class`, `rename_reference`, `add_method`).
5) نتائج التدقيق تعيش في `docs/` فقط ولا تدخل الـ runtime pipeline.
6) لا توجد خريطة Action Mapping تحدد متى نستخدم `patcher.php` ومتى `write_engine`.
7) مخرجات Self‑Strategy (`strategy_state.json`, `scenario_value_scores.json`, `auto_gate_policy.json`) غير مُولّدة فعليًا.
8) Canary ما زال يُتجاوز بسبب Gate بدون سياسة تجاوز مشروط.
9) `retention_enabled` غير مُفعّل فعليًا في `agent_flags` رغم وجود المحرك.

---

# P0 — خط التنفيذ الذاتي end‑to‑end (الأولوية القصوى)
**الهدف:** تحويل كل مخرجات التدقيق/التشغيل إلى إصلاحات تُنفّذ تلقائيًا داخل نفس الدورة.

## P0‑1) محول التدقيق → Proposals
**إضافة:** `audit_to_proposals.py`
- الإدخال: `docs/brain_functional_audit_core.json`
- الإخراج: Proposals في `decision_db` مع `reason`, `scope`, `confidence`, `recommended_action`.
- التوثيق: `run_audit.jsonl` مع `source=audit_to_proposals`.

## P0‑2) توليد Patch Plans تلقائيًا
- إعادة استخدام `auto_plan` في `agency_core.py`.
- تفعيل `auto_plan` على Proposals الجديدة من التدقيق.
- المخرجات: `.bgl_core/patch_plans/*.json`.

## P0‑3) Action Mapping (patcher.php vs write_engine)
**قاعدة تنفيذ موحّدة:**
- إذا كانت الخطة قابلة للتحويل إلى Action مدعوم في `patcher.php` → تنفيذ عبر `patcher.py`.
- غير ذلك → تنفيذ عبر `apply_proposal.py` و`write_engine`.

## P0‑4) توسيع قدرات patcher.php
**Actions مطلوبة:**
- `add_import`
- `replace_block`
- `toggle_flag`
- `insert_event`

## P0‑5) ربط المسار بالدورة التشغيلية
**موضع الربط:**
- `master_verify.py` أو `agency_core.py` بعد كل تقرير Full:
  1) `audit_to_proposals.py`
  2) `auto_plan`
  3) تنفيذ وفق Action Mapping
  4) تحديث التقرير والخطط تلقائيًا

**مخرجات P0 الإلزامية:**
- Proposals من التدقيق
- Patch Plans تلقائية
- تنفيذ فعلي عبر `patcher.php` أو `write_engine`
- تحديث تلقائي للخطط بعد التنفيذ

---

# P1 — ضمان الاتساق والحوكمة
**الهدف:** لا تشغيل بلا أثر، ولا تقرير مفقود، ولا غموض في المصدر.

1) **التقرير دائمًا يُكتب**
- عند timeout → تقرير جزئي أو cached.

2) **توثيق مصدر التشغيل**
- `run_audit.jsonl` يحفظ `source, trigger, task_name, pid, timestamp`.

3) **أقفال صارمة**
- Atomic lock لكل تشغيل.
- منع توازي apply_proposal مع master_verify.

---

# P2 — Self‑Strategy Engine (الحل الدائم المستدام)
**مخرجات إلزامية:**
- `strategy_state.json`
- `scenario_value_scores.json`
- `auto_gate_policy.json`

**محاور العمل:**
1) علاج السبب بدل العرض (Failure Typing + Synthetic Coverage).
2) تعلم تكتيكي (Auto Strategy + إعادة دمج السيناريوهات).
3) تقييم ذاتي + تجاوز مشروط للـ gate.
4) تحديث تلقائي للخطط (مكتمل/باقٍ).

---

# P3 — Value‑Based Retention (بدون زمن)
**الهدف:** الحذف يصبح مصدر معرفة ويمنع إعادة توليد الضوضاء.

- تفعيل `retention_enabled`.
- Catalog موحّد لكل الأنواع (scenarios/patch_plans/backups/playbooks/logs/insights).
- Tombstones في `prune_index.jsonl` مع `block_budget`.

---

## المخرجات الإلزامية بعد كل دورة
- `latest_report.json` (محدث، ذرّي).
- `auto_review.status`.
- `run_audit.jsonl`.
- تحديث تلقائي للخطط.
- `strategy_state.json` + `scenario_value_scores.json` + `auto_gate_policy.json`.

---

## مصادر الحقيقة
- `.bgl_core/logs/latest_report.json`
- `.bgl_core/logs/diagnostic_status.json`
- `.bgl_core/logs/run_audit.jsonl`
- `.bgl_core/logs/prune_index.jsonl`
- `.bgl_core/brain/master_verify.py`
- `.bgl_core/brain/agency_core.py`
- `.bgl_core/brain/patcher.py`
- `.bgl_core/actuators/patcher.php`
