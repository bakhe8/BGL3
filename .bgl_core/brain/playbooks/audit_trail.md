---
id: PB_AUDIT_TRAIL
type: compliance
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

# Audit Trail Playbook

## الهدف
ضمان تسجيل كل عمليات CRUD الحساسة (بنوك/موردين/ضمانات) مع من ومتى وماذا تغير.

## السياق
- يطبق على طبقة الكتابة (controllers/services) لمسارات إدارة البنوك والموردين والضمانات.

## الخطوات المختصرة
1) إضافة جدول `audit_logs` أو استخدام existing logger مع حقل entity/id/action/user/ip/timestamp/changes.
2) حقن middleware/observer يلتقط كل write (create/update/delete/import).
3) توحيد تنسيق الرسالة وحفظ diff الحقول الحساسة فقط.

## معايير القبول
- كل عملية تحديث بنك أو مورد تُسجل صفاً في audit_logs.
- استعلام API /audit/{entity}/{id} يعيد السجل خلال < 200ms.

## نقاط الحقن
- Laravel middleware أو model observers (Bank/Supplier).
- Service layer قبل persistence.
