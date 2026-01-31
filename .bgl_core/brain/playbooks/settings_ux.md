---
id: PB_SETTINGS_UX
type: usability
risk_if_missing: low
auto_applicable: false
conflicts_with:
  - playbook: PB_RATE_LIMIT
    impact: usability
    severity: medium
maturity:
  level: experimental
  first_seen: 2026-01-31
  success_rate: 0.0
origin: auto_generated
confidence: 0.65
---

# Settings UX Playbook

## الهدف
تحسين تجربة صفحة الإعدادات: حفظ تلقائي، إشعار نجاح، وإظهار آخر من عدّل الإعدادات.

## الخطوات المختصرة
1) تفعيل auto-save للحقول المناسبة مع debounce.
2) Toast نجاح موحد مع timestamp.
3) وسم كل تغيير باسم المستخدم ووقت التعديل وعرضه في الواجهة.

## معايير القبول
- أي تعديل يُحفظ تلقائياً خلال 3 ثوانٍ دون أخطاء.
- يظهر آخر معدل ووقته لكل حقل رئيسي.

## نقاط الحقن
- واجهة settings.php + API settings.
