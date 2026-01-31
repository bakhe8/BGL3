---
id: PB_IMPORT_SAFETY
type: safety
risk_if_missing: high
auto_applicable: true
conflicts_with: []
maturity:
  level: experimental
  first_seen: 2026-01-31
  success_rate: 0.0
origin: auto_generated
confidence: 0.65
---

# Import Safety Playbook

## الهدف
حماية عمليات الاستيراد من الملفات الكبيرة أو الأنواع غير المتوقعة وضمان تقارير أخطاء واضحة.

## الخطوات المختصرة
1) فحص حجم الملف ونوعه قبل المعالجة؛ رفض غير المسموح به.
2) Sanitization للحقول غير المتوقعة في البيانات.
3) تقرير أخطاء تفصيلي يُعاد للمستخدم مع أعداد الأسطر الفاشلة.

## معايير القبول
- ملف أكبر من الحد → 400 مع رسالة واضحة.
- حقل غير متوقع → يتجاهل/يبلغ عنه دون كسر العملية.

## نقاط الحقن
- Controller/Service في import endpoints.
