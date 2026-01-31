---
id: PB_CACHING
type: performance
risk_if_missing: medium
auto_applicable: false
conflicts_with:
  - playbook: PB_AUDIT_TRAIL
    impact: consistency
    severity: medium
maturity:
  level: experimental
  first_seen: 2026-01-31
  success_rate: 0.0
origin: auto_generated
confidence: 0.65
---

# Caching Playbook

## الهدف
تسريع لوحات القيادة والتقارير عبر كاش بسيط للبيانات المتكررة.

## الخطوات المختصرة
1) تحديد استعلامات التقارير الثقيلة وإضافة كاش (per company/filters) بمدة TTL قصيرة.
2) تفريغ الكاش عند عمليات الكتابة ذات الصلة.
3) مراقبة hit/miss لقياس الفائدة.

## معايير القبول
- زمن استعلام التقرير ينخفض ≥30%.
- تفريغ الكاش يحدث عند التعديل المرتبط بالبيانات.

## نقاط الحقن
- Repository أو Service layer.
- Observer لتفريغ الكاش بعد عمليات write.
