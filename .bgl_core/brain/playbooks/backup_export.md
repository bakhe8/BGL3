---
id: PB_BACKUP_EXPORT
type: resilience
risk_if_missing: medium
auto_applicable: false
conflicts_with: []
maturity:
  level: experimental
  first_seen: 2026-01-31
  success_rate: 0.0
origin: auto_generated
confidence: 0.65
---

# Backup & Export Playbook

## الهدف
إتاحة نسخة تصدير خفيفة للإعدادات والبنوك/الموردين يمكن استعادتها جزئياً.

## الخطوات المختصرة
1) إضافة أمر/cron لتصدير JSON مؤرّخ للإعدادات والبنوك/الموردين.
2) نقطة استعادة جزئية تقبل ملف التصدير وتطبّق فقط الحقول المختارة.
3) تخزين النسخ في مسار آمن مع حد أقصى للنسخ.

## معايير القبول
- أمر export يعمل ويولّد ملفاً بأقل من 5 ثوانٍ.
- استعادة جزئية لا تكسر البيانات القائمة.

## نقاط الحقن
- console command + API اختيارية.
