# Flow: إنشاء ضمان (create_guarantee)

## الهدف
إنشاء ضمان جديد يربط بنكاً بمورد بقيمة ومدة محددة، مع التحقق من صلاحية البيانات وتسجيل المرفقات عند الحاجة.

## المسار الأساسي (Happy Path)
1) الطلب: `/api/create-guarantee.php` مع الحقول (bank_id, supplier_id, amount, currency, start_date, end_date, notes?).
2) التحقق:
   - bank_id/supplier_id موجودان وصالحان.
   - amount > 0، currency مدعومة.
   - end_date > start_date.
3) الإنشاء:
   - إدراج سجل guarantee بحالة `active`.
   - ربطه بالبنك والمورد.
4) المخرجات:
   - JSON نجاح مع guarantee_id.
   - تسجيل حدث runtime_events من النوع `guarantee_created`.

## المسارات الفرعية/الأخطاء الشائعة
- بنك غير موجود -> 404/422 مع رسالة واضحة.
- مورد غير موجود -> 404/422.
- بيانات نقدية غير صالحة (amount/currency) -> 422.
- تواريخ غير صحيحة -> 422.

## الحواجز (Guards)
- Rate limit على مسارات الكتابة (playbook rate_limit).
- Validation قوية (Email/Phone للمورد عبر playbook data_validation).
- Audit trail على عمليات الضمان (create/extend/release/reduce).

## معايير القبول (Acceptance)
- اختبار Gap: إرسال 6 طلبات متتالية → أحدها يرفض بسبب rate limit.
- اختبار بيانات: إنشاء ضمان بقيم غير صالحة → 422 برسالة موحدة.
- إنشاء ناجح → guarantee_id يُرجع، يُسجَّل حدث runtime_events، ولا يظهر أي Blocker في التقرير.

## المخرجات المتوقعة في التقارير
- experiences: scenario=create_guarantee مع ثقة >70%.
- gap_tests: create_guarantee_passed=true.
- decision/intent: لا قرارات تصحيحية عند النجاح؛ propose_fix عند فشل validation/rate_limit.
