# Business Rules & System Constraints
## Banking Guarantees Management System

### 1. Mandatory Data Integrity Rules
**(قواعد سلامة البيانات الإلزامية)**

#### BR-01 — Mandatory Fields
كل ضمان بنكي يجب أن يحتوي إلزاميًا على القيم التالية:
- **Supplier Name** (اسم المورد)
- **Bank Name** (اسم البنك)
- **Guarantee Amount** (القيمة)
- **Expiry Date** (تاريخ الانتهاء)
- **Contract Number** أو **Purchase Order Number**

#### BR-02 — Entry Prevention Rule
أي ضمان بنكي لا يحتوي على جميع الحقول الإلزامية الخمسة:
- يُمنع إدخاله إلى النظام
- لا يُنشأ له سجل جزئي أو مؤقت

#### BR-03 — Validation Timing Rule
يتم التحقق من اكتمال الحقول الإلزامية عند لحظة الإدخال في جميع مسارات الإدخال:
- الاستيراد من ملف
- اللصق الذكي
- الإدخال اليدوي

#### BR-04 — Data Cleanup Rule
أي سجل موجود في قاعدة البيانات لا يحتوي على جميع الحقول الإلزامية الخمسة:
- يُحذف
- لا يُرحَّل
- لا يُعرض في الواجهة
- *وجود حقول إضافية مسموح به.*

---

### 2. UI Display & Edit Constraints
**(قيود الواجهة والعرض)**

#### SC-01 — Info Grid Display-Only Constraint
واجهة **Info Grid**:
- للعرض فقط
- لا تسمح بأي تعديل مباشر
- لا تُستخدم كمصدر إدخال

#### SC-02 — Guaranteed Completeness Rule
كل ما يُعرض في Info Grid يجب أن يكون:
- مكتمل البيانات
- فعلي (آخر حالة)
- غير فارغ

---

### 3. User Edit Permissions
**(صلاحيات المستخدم)**

#### BR-05 — Limited Manual Edit Rule
المستخدم مسموح له بالتعديل اليدوي فقط على:
- اسم المورد
- اسم البنك
*ويتم ذلك حصريًا عبر:*
- التعديل
- الضغط على زر **حفظ**

#### BR-06 — Controlled Field Modification Rule
تعديل الحقول التالية يتم فقط عبر أزرار مخصصة:
- **Expiry Date** → زر **تمديد**
- **Guarantee Amount** → زر **تخفيض**
- **Status / Lifecycle** → زر **إفراج**

#### BR-07 — Forbidden Manual Edits Rule
أي تعديل يدوي مباشر خارج ما سبق:
- غير مسموح
- مرفوض منطقيًا وتقنيًا

---

### 4. User Actions & Database Consistency
**(الأزرار وتناسق قاعدة البيانات)**

#### BR-08 — Action-Based Modification Rule
لا يتم أي تغيير على بيانات الضمان إلا نتيجة:
- ضغط زر صريح من المستخدم
- أو تحديث نظامي داخلي (Learning / Matching)

#### BR-09 — Dual Write Rule
عند ضغط المستخدم على أي زر من الأزرار التالية (حفظ، تمديد، تخفيض، إفراج)، يجب على النظام تنفيذ عمليتين إجباريتين ومتزامنتين:
1. **تحديث السجل الأساسي**
   - تحديث بيانات الضمان في الجدول الرئيسي
   - تعكس القيم آخر حالة فعلية للضمان
2. **إنشاء Snapshot تاريخي**
   - إنشاء سجل جديد في جدول التاريخ
   - يحتوي على حالة الضمان قبل تنفيذ الحدث
   - *يُحفظ لأغراض التتبع فقط*

---

### 5. History & Timeline Rules
**(قواعد التاريخ والتايم لاين)**

#### BR-10 — Immutable History Rule
جميع السجلات التاريخية:
- للعرض فقط
- غير قابلة للتعديل
- غير قابلة للحذف من قبل المستخدم
- لا تؤثر على الحالة الحالية للضمان

#### BR-11 — Event-Centric Snapshot Rule
كل سجل تاريخي يجب أن يحتوي على:
- نوع الحدث (Save / Extend / Reduce / Release / System Update)
- تاريخ ووقت التنفيذ
- مصدر التغيير (User / System)
- مرجع واضح للضمان الأساسي

#### BR-12 — Timeline Read-Only Projection
واجهة Timeline:
- تعرض التاريخ فقط
- لا تحتوي على أدوات تعديل
- لا تُستخدم لاسترجاع أو تعديل الحالة الحالية

---

### 6. System-Driven Updates
**(تحديثات النظام الخلفية)**

#### BR-13 — Background System Adjustments
قد يقوم النظام بتحديث:
- اسم المورد
- اسم البنك
*وفق:*
- درجات التطابق
- نظام التعلم

*هذه التحديثات:*
- لا تتم عبر الواجهة
- لا ترتبط بأزرار المستخدم
- تُسجَّل كسجل تاريخي فقط

---

### 7. System Principles
**(المبادئ الحاكمة)**

#### PR-01 — Single Source of Truth
الجدول الرئيسي للضمان:
- هو المصدر الوحيد للحالة الحالية
- لا يُشتق من التاريخ
- لا يُعدل عبر التاريخ

#### PR-02 — Action → State → History Principle
أي تغيير يمر دائمًا بالتسلسل التالي فقط:
`User/System Action → Update Current Guarantee → Persist Historical Snapshot → Refresh UI`
ولا يُسمح بأي مسار بديل.

---

### 8. Guarantee Status Transition Policy
**(سياسة تحوّل حالة الضمان البنكي – Score-Based / Implementation-Agnostic)**

#### P7-1. Purpose & Scope
تنظم هذه السياسة:
- منطق تحوّل حالة الضمان
- العلاقة بين درجة المطابقة، قرار المستخدم، وسياسات النظام
- *دون التدخل في تفاصيل خوارزميات المطابقة أو أوزانها*

#### P7-2. Canonical Guarantee States
- **GS-01 — Needs Decision**
- **GS-02 — Ready**

#### P7-3. Explicit Non-States
- نقص البيانات ليس حالة (راجع BR-02)
- لا Draft / Partial / Pending

#### P7-4. Decision-Critical Fields
- **Supplier**
- **Bank**
*فقط هذان الحقلان يؤثران على الحالة.*

#### P7-5. Matching Score Model
**(نموذج درجة المطابقة – بدون افتراضات خوارزمية)**
كل اقتراح تطابق (Supplier أو Bank) يجب أن ينتج على الأقل:
- **Score**: رقم من 0 إلى 100
- **Context**: معلومات كافية لتحديد:
    - وجود تعارض من عدمه
    - مصدر الاقتراح (Canonical / Alias / Learning / Guess)
*❗ هذه السياسة لا تفترض كيف حُسبت الدرجة أو العوامل المستخدمة.*

#### P7-6. Confidence Interpretation (Policy-Level)
السياسة تتعامل مع الدرجة كنطاقات دلالية، وليس كمعادلة:
- **High Confidence**: درجة مرتفعة بدون تعارض ومن مصدر موثوق
- **Medium Confidence**: درجة متوسطة أو مرتفعة لكن بدون تأكيد صريح
- **Low Confidence**: درجة منخفضة أو ناتجة عن تخمين
*تحويل الدرجة الرقمية إلى هذه الفئات يتم عبر منطق مركزي في الكود. السياسة لا تُحدِّد حدًّا رقميًا صريحًا.*

#### P7-7. Resolution Definition
**(متى يُعتبر المورد/البنك محسومًا)**
يُعتبر Supplier أو Bank **Resolved** إذا تحقق أحد التالي:
1. محكوم عليه كـ **High Confidence** (بدون تعارض وبمصدر موثوق).
2. أو **المستخدم ثبّت الاختيار صراحة** (عبر زر Save وتم تسجيل القرار تاريخيًا BR-09).

*لا يُعتبر Resolved إذا:*
- الدرجة وحدها عالية لكن السياق غير محسوم
- أو وُجد تعارض
- أو لم يوجد قرار مستخدم

#### P7-8. Status Evaluation Rule
**(قاعدة تحديد الحالة – غير مرتبطة بالأوزان)**
- **Ready** إذا:
    - Supplier Resolved
    - Bank Resolved
    - لا توجد Conflict Flags
    - الحقول الإلزامية مكتملة (BR-01)
- **Needs Decision** في جميع الحالات الأخرى، ومنها:
    - أي عنصر غير Resolved
    - وجود ambiguity
    - تعارض ناتج عن إعادة تقييم

#### P7-9. Role Separation
- **User**: يحسم، لا يحسب. قراره يتجاوز أي Score. يتم عبر Save فقط.
- **Matching Engine**: يحسب الدرجات، يحدد السياق، لا يغيّر الحالة مباشرة.
- **Learning System**: يحسّن الاقتراحات، يؤثر على الدرجات مستقبلًا، لا يحسم ولا يغيّر الحالة وحده.

#### P7-10. Authorized Transition Triggers
- إدخال جديد
- Save من المستخدم
- تحديث القواميس

#### P7-11. Reverse Transition Rules
**Ready → Needs Decision** فقط عند:
- تعديل المورد/البنك
- ظهور تعارض
- تغيير قاموس مؤثر
*ويُسجّل في Timeline (BR-10).*

#### P7-12. Single Authority Principle
**(مبدأ بالغ الأهمية هنا)**
- السياسة تفسّر
- الكود يحسب
- ولا يوجد تداخل بينهما. السياسة لا تعرف أوزان أو معادلات.

#### P7-13. Integration References
- متكاملة مع Business Rules
- ملتزمة بـ Dual Write + Snapshot
- لا تمس Info Grid

#### P7-14. Final Governing Principle
**(محدّث بدقة)**
- الكود يقيّم
- السياسة تفسّر
- المستخدم يحسم
- والنظام يوثّق
