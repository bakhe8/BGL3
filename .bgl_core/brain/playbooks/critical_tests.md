---
id: PB_CRITICAL_TESTS
type: testing
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

# Critical Tests Playbook

## الهدف
تأمين السيناريوهات الحرجة (إنشاء/تحديث/تصدير) بمجموعة اختبارات قصيرة وسريعة.

## الخطوات المختصرة
1) إضافة PHPUnit group `critical` يغطي: إنشاء بنك، تحديث مورد، تصدير بيانات.
2) تشغيل مجموعة critical في SafetyNet/Master Verify عند تغييرات الخدمات أو الاستيراد.

## معايير القبول
- جميع اختبارات critical تمر على sandbox.
- زمن التنفيذ < 90 ثانية.

## نقاط الحقن
- tests/Critical/* أو tests/Gap/* مع وسم @group critical.
