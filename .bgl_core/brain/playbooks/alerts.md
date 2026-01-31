---
id: PB_ALERTS
type: reliability
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

# Alerts & Notifications Playbook

## الهدف
تنبيه الفريق عند فشل استيراد/تصدير أو تكرار أخطاء API بحرجة.

## الخطوات المختصرة
1) مجمّع أحداث يرصد errors >= 3 خلال 5 دقائق لمسار واحد.
2) إرسال تنبيه (Email/Slack) مع ملخص السياق والـtrace.
3) تلخيص يومي للأخطاء المتكررة.

## معايير القبول
- عند 3× 500 في /api/import.php خلال 5 دقائق → تنبيه يُرسل.
- ملخص يومي يحتوي أعلى 5 مسارات فاشلة.

## نقاط الحقن
- Middleware أو central exception handler.
- Cron job لتقرير يومي.
