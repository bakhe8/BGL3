---
id: PAT_DATA_VALIDATION
type: reliability
risk_if_missing: medium
auto_applicable: true
origin: auto_generated
confidence: 0.8
conflicts_with: []
maturity:
  level: stable
  first_seen: 2026-01-31
  success_rate: 0.0
---
# Playbook: Pat Data Validation

## الهدف
apply playbooks/data_validation.md

## السياق
- مُولّد تلقائياً من فحص: PAT_DATA_VALIDATION
- الدليل: No FormRequest classes or validation calls detected in controllers
- النطاق: api

## الخطوات (مبدئية)
1. حدّد نقطة الحقن المناسبة.
2. طبّق قوالب التصحيح أو أضف منطقك الخاص.
3. شغّل اختبارات Gap ذات الصلة.

## معايير القبول
- لا توجد تحذيرات في Gap Tests المرتبطة.
- نجاح مسار العمل الأساسي دون أخطاء.
