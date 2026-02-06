---
id: PB_DATA_VALIDATION
type: quality
risk_if_missing: medium
auto_applicable: true
conflicts_with: []
maturity:
  level: experimental
  first_seen: 2026-01-31
  success_rate: 0.0
origin: auto_generated
confidence: 0.65
---

# Data Validation Playbook

## الهدف
منع إدخال بيانات غير صالحة (Email/Phone/Required fields) قبل الحفظ أو الاستيراد.

## الخطوات المختصرة
1) Requests/Validators للحقول: email, phone, required.
2) رسائل خطأ موحّدة بصيغة JSON {field, code, message}.
3) فحص الاستيراد: رفض الملف عند حجم/نوع غير مسموح وإرجاع تقرير أخطاء.

## معايير القبول
- Email/Phone غير صحيحين → 422.
- ملف استيراد أكبر من الحد → 400 بخطأ واضح.

## نقاط الحقن
- FormRequest/Validator في controllers.
- Import service قبل الكتابة.
