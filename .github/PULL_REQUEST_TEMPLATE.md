## وصف التغيير
<!-- اشرح بوضوح: ما الذي يفعله هذا PR؟ لماذا؟ -->



## نوع التغيير
- [ ] Bug fix (تصليح خلل)
- [ ] New feature (ميزة جديدة)
- [ ] Refactoring (إعادة هيكلة)
- [ ] Documentation (توثيق)
- [ ] Other (حدد): _______________

---

## ✅ Learning System Impact Checklist

**هل هذا PR يؤثر على نظام الاقتراحات/التعلم للموردين؟**
- [ ] نعم - يرجى ملء القسم التالي
- [ ] لا - تخطى للأسفل

---

### إذا نعم - يرجى التحقق من التالي:

#### ❌ محظورات مطلقة (إذا أي منها "نعم" → PR مرفوض):

- [ ] **هل يُنشئ هذا PR خدمة اقتراحات جديدة؟**
  - إذا نعم: ❌ **مرفوض** - نحن في مرحلة Consolidation
  - المرجع: `charter_part3_ui_and_governance.md` (Section 7)

- [ ] **هل يحسب confidence/score خارج UnifiedLearningAuthority؟**
  - إذا نعم: ❌ **مرفوض** - Authority هي المصدر الوحيد
  - المرجع: `authority_intent_declaration.md` (Section 2.5)

- [ ] **هل يضيف منطق decision filtering في SQL queries؟**
  - مثال: `WHERE usage_count > 0`, `ORDER BY confidence DESC`
  - إذا نعم: ❌ **مرفوض** - Database لا تتخذ قرارات
  - المرجع: `database_role_declaration.md` (Article 4.1)

- [ ] **هل يخزن قرارات نهائية في جداول Signal؟**
  - مثال: حفظ confidence محسوبة في جدول signals
  - إذا نعم: ❌ **مرفوض** - Signal-Decision leakage
  - المرجع: `database_role_declaration.md` (Article 3)

---

#### ⚠️ يتطلب موافقة ARB:

- [ ] **هل يُعدّل SuggestionDTO schema؟**
  - إضافة/حذف/تعديل حقول
  - تغيير نوع بيانات
  - **المطلوب:** موافقة ARB + تحديث Charter

- [ ] **هل يُضيف/يعدل جدول في قاعدة البيانات متعلق بالتعلم؟**
  - **المطلوب:** تحديد دور الجدول (Signal/Decision/Entity/Audit)
  - **المطلوب:** توثيق في Database Role Declaration

- [ ] **هل يغير normalization algorithm؟**
  - **المطلوب:** خطة migration للبيانات القديمة
  - **المطلوب:** موافقة ARB

---

#### ✅ مسموح (مع توثيق):

- [ ] **هل يضيف Signal Feeder جديد؟**
  - ✓ مسموح إذا:
    - يعيد SignalDTO فقط (ليس SuggestionDTO)
    - لا يحسب confidence
    - موثق في Service Classification Matrix
  - **أرفق:** خطة تكامل مع Authority

- [ ] **هل يصلح bug في feeder موجود؟**
  - ✓ مسموح إذا:
    - لا يغير Signal semantics
    - يحافظ على Role Declaration compliance
  - **وضح:** أي جدول/service متأثر

- [ ] **هل يحسن performance بدون تغيير logic؟**
  - ✓ مسموح
  - **وضح:** القياسات (before/after)

---

## 📋 Database Role Compliance

**إذا PR يتفاعل مع قاعدة البيانات:**

### الجداول المتأثرة:
- [ ] `suppliers` (Role: ENTITY)
- [ ] `supplier_alternative_names` (Role: SIGNAL STORE - hybrid)
- [ ] `learning_confirmations` (Role: SIGNAL)
- [ ] `supplier_learning_cache` (Role: CACHE - misaligned)
- [ ] `guarantees` / `guarantee_decisions` (Role: HISTORICAL SIGNAL)
- [ ] أخرى:  _______________

### التحقق من الامتثال:
- [ ] Queries تسترجع signals فقط (لا decision filtering في SQL)
- [ ] Writes لا تخلط signal + decision
- [ ] ال normalization يُطبق بشكل متسق

**مرجع:** `database_role_declaration.md`

---

## 🧪 Tests

- [ ] Unit tests added/updated
- [ ] Integration tests (إذا لزم)
- [ ] Manual testing completed
- [ ] Tests pass locally

**Test coverage:**
- Current: ___%
- After PR: ___%

---

## 📚 Documentation

- [ ] Code comments added لمنطق معقد
- [ ] README updated (إذا لزم)
- [ ] Charter documents updated (إذا schema/contract تغير)

---

## 👥 المراجعون المطلوبون

### مراجعة عادية:
- [ ] Code review من teammate
- [ ] QA review (للـfeatures الكبيرة)

### مراجعة ARB (إذا أي من التالي):
- [ ] تعديل على نظام الاقتراحات
- [ ] إضافة جدول/عمود في DB
- [ ] تغيير في Confidence calculation
- [ ] تعديل SuggestionDTO
- [ ] تغيير كبير في architecture

**ARB Members:** _[سيتم تحديثها بعد التشكيل]_

---

## ✅ Checklist النهائي

- [ ] قرأت الوثائق ذات الصلة
- [ ] PR يتوافق مع Charter
- [ ] لا محظورات مطلقة
- [ ] Tests تمر
- [ ] Documentation محدثة
- [ ] المراجعون المطلوبون محددون

---

## 📎 روابط إضافية

<!-- أضف links لـissues, designs, أو وثائق أخرى -->

**Related Issues:** #___
**Design Doc:** ___
**Charter Reference:** ___

---

**ملاحظة:** إذا غير متأكد من أي نقطة، راجع `docs/Supplier_Learning_Forensics/README.md` أو اتصل بـARB.
