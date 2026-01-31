---
id: PAT_DB_FK_MISSING
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
# Playbook: Pat Db Fk Missing

## الهدف
add foreign keys guarantees->bank/supplier

## السياق
- مُولّد تلقائياً من فحص: db_fk_missing
- الدليل: guarantees table has no bank_id/supplier_id columns (skipped FK check)
- النطاق: db

## الخطوات (مبدئية)
1. حدّد نقطة الحقن المناسبة.
2. طبّق قوالب التصحيح أو أضف منطقك الخاص.
3. شغّل اختبارات Gap ذات الصلة.

## معايير القبول
- لا توجد تحذيرات في Gap Tests المرتبطة.
- نجاح مسار العمل الأساسي دون أخطاء.
