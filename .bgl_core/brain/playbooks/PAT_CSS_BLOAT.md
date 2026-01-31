---
id: PAT_CSS_BLOAT
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
# Playbook: Pat Css Bloat

## الهدف
refactor large CSS files (split, purge unused, minify)

## السياق
- مُولّد تلقائياً من فحص: css_bloat
- الدليل: public\css\index-main.css size=42213 bytes lines=1768
- النطاق: css

## الخطوات (مبدئية)
1. حدّد نقطة الحقن المناسبة.
2. طبّق قوالب التصحيح أو أضف منطقك الخاص.
3. شغّل اختبارات Gap ذات الصلة.

## معايير القبول
- لا توجد تحذيرات في Gap Tests المرتبطة.
- نجاح مسار العمل الأساسي دون أخطاء.
