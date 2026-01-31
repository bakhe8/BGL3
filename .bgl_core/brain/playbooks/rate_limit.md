---
id: PB_RATE_LIMIT
type: reliability
risk_if_missing: high
auto_applicable: true
conflicts_with:
  - playbook: PB_CACHING
    impact: performance
    severity: low
maturity:
  level: experimental
  first_seen: 2026-01-31
  success_rate: 0.0
origin: auto_generated
confidence: 0.65
---
# Playbook: Rate Limit Guard

## الهدف
ضمان وجود طبقة Rate Limiting للعمليات الكتابية في الـAPI لمنع إساءة الاستخدام والضغط المفاجئ.

## السياق
- مسارات الكتابة (POST/PUT/PATCH/DELETE) في BGL3 لا تملك حماية معدل طلبات موحّدة.
- الاعتماد الحالي على “إخفاء” أو تحذير فقط غير كافٍ تحت الضغط.

## الخطوات
1) إضافة Middleware/حارس معدل الطلبات (مثلاً RateLimit) يُطبّق على كل مسارات الكتابة أو مجموعة محددة.
2) ضبط الحدود (requests/minute) في config آمن وقابل للتعديل.
3) استثناء مسارات النظام الضرورية عند الحاجة (قائمة allowlist صغيرة).
4) تسجيل تجاوز الحدود في audit/logs.

## معايير القبول
- أي عميل يتجاوز الحد يحصل على 429.
- يتم تسجيل محاولة التجاوز مع route + client_id/IP.
- يمكن تعديل الحدود بدون نشر كود (config/ENV).

## نقاط الحقن
- autoload/middleware أو طبقة router التي تطبّق middleware موحّد.
- config: مفاتيح مثل RATE_LIMIT_WRITE_PER_MIN.
