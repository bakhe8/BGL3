---
id: PAT_DB_INDEX_MISSING
type: reliability
risk_if_missing: medium
auto_applicable: false
origin: auto_generated
confidence: 0.65
conflicts_with: []
maturity:
  level: experimental
  first_seen: 2026-01-31
  success_rate: 0.0
---
# Playbook: Pat Db Index Missing

## الهدف
add indexes for reporting/filters (see db_schema.md)

## السياق
- مُولّد تلقائياً من فحص: db_index_missing
- الدليل: suppliers missing index on normalized_name; suppliers missing index on official_name; banks missing index on contact_email
- النطاق: db

## الخطوات (مبدئية)
1. حدّد نقطة الحقن المناسبة.
2. طبّق قوالب التصحيح أو أضف منطقك الخاص.
3. شغّل اختبارات Gap ذات الصلة.

## معايير القبول
- لا توجد تحذيرات في Gap Tests المرتبطة.
- نجاح مسار العمل الأساسي دون أخطاء.
