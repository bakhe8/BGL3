# 📐 BGL SYSTEM – SAFE LEARNING & DECISION SPEC (AS-IS COMPATIBLE)

**Document Type:** Technical & Functional Specification  
**Status:** Draft for Implementation  
**Compatibility:** AS-IS System Architecture  
**Date:** 2025-12-26

---

## الهدف التقني

تحييد حلقة **Alias Learning Death Spiral** عبر:

1. ✅ تعديل شروط التعلّم
2. ✅ تعديل شروط الاستخدام
3. ✅ تعديل تسلسل القرار

### القيود الهندسية

❌ **بدون تغيير بنية الجداول**  
❌ **بدون تعطيل Smart Processing**  
❌ **بدون إعادة تصميم UI كامل**

---

## 1️⃣ إعادة تعريف أنواع المعرفة (Programmatic)

### 1.1 تصنيف المعرفة (Implicit – بدون جدول جديد)

| النوع | الوصف | سلوك النظام |
|-------|--------|-------------|
| `official` | supplier/bank من الجدول الرسمي | ✅ مسموح auto-approve |
| `override` | mapping إداري | ✅ مسموح auto-approve |
| `learned_manual` | alias ناتج عن قرار يدوي | ❌ **ممنوع auto-approve** |
| `learned_auto` | ناتج عن قرار آلي | ❌ لا يُنشئ تعلم |

### 📌 التطبيق البرمجي

يُستنتج النوع من:
- `supplier_alternative_names.source`
- أو `decision_source`

**لا نضيف أعمدة، فقط نغيّر طريقة التفسير.**

---

## 2️⃣ تعديل مسار التعلّم (Learning Gate)

### 2.1 شرط التعلّم (Hard Gate)

#### الوضع الحالي
```php
if ($source === 'manual') {
    learnAlias(...)
}
```

#### الوضع المطلوب (منطقيًا)
```
ALLOW_LEARNING =
    source == 'manual'
    AND decision_was_not_auto
    AND NOT decision_was_suggested_by_alias
    AND session_load < MAX_SAFE_LOAD
    AND no_official_name_conflict
```

### 📌 التفسير

- ❌ **قرار يدوي ≠ معرفة مؤكدة**
- 🔍 إذا جاء الاقتراح أصلاً من alias → لا نعيد تعليمه
- 😓 إذا كان المستخدم في ضغط (جلسة طويلة) → نوقف التعلم

### 2.2 تعريف `session_load` (بدون ML)

```
session_load = decisions_in_last_30_minutes

if session_load >= 20:
    disable learning silently
```

**التطبيق:**
- ❌ لا رسالة
- ❌ لا UI
- ✅ حماية خفية من الإرهاق البشري

---

## 3️⃣ تعديل استخدام المعرفة (Usage Gate)

### 3.1 قاعدة ذهبية (Core Rule)

> **المعرفة المتعلَّمة لا تُنشئ قرارًا آليًا مباشرًا**

### 3.2 في `SupplierCandidateService`

#### الوضع الحالي
```php
if (alias_match) {
    score = 1.0;
}
```

#### الوضع المطلوب
```php
if alias.source == 'learning':
    score = 0.90
    requires_human_review = true
```

**النتيجة:**
- 📌 alias ما زال يظهر
- 📌 ما زال في أعلى القائمة
- ❌ **لكنه لا يتجاوز الإنسان**

### 3.3 في `SmartProcessingService`

#### الوضع الحالي
```php
if score >= 90 AND no_conflicts:
    auto_approve
```

#### الوضع المطلوب
```php
if score >= 90
   AND no_conflicts
   AND candidate.source != 'learning':
       auto_approve
else:
       require_manual_review
```

### 🔥 هذا السطر وحده يكسر حلقة الموت

---

## 4️⃣ كسر حلقة التعزيز (Reinforcement Break)

### 4.1 منع "تعلم من تعلم"

**الوضع الحالي:**
- auto-decision لا يعلّم ✅
- لكن alias قد يقود auto-decision ❌

**الوضع المطلوب:**
```php
if decision_source == 'auto'
   AND match_source == 'learning':
       DO NOT increment usage_count
```

### 📌 `usage_count` يصبح:
- دليل استخدام بشري فقط
- لا تضخيم ذاتي

---

## 5️⃣ ضبط `usage_count` (بدون تغيير الجدول)

### 5.1 الاستخدام الصحيح

```
usage_count++ ONLY IF:
    decision_source == 'manual'
    AND user_explicitly_confirmed_choice
```

### 5.2 الاستخدام الخاطئ (ممنوع)

❌ auto-match  
❌ re-import  
❌ background processing

---

## 6️⃣ تعريف حالات خطرة (Flagging Logic – بدون UI جديد)

### 6.1 تعريف Alias عالي الخطورة

```
alias_is_risky IF:
    source == 'learning'
    AND usage_count == 1
```

### 6.2 سلوك النظام

- ❌ لا auto-approve
- ❌ لا auto-learn
- ❌ لا تعزيز

**📌 مجرد تحييد، لا حذف.**

---

## 7️⃣ حماية القرار النهائي (Decision Firewall)

### 7.1 قاعدة حماية

```php
if decision_uses_learning_alias:
    decision_source = 'manual_review_required'
```

**النتيجة:**
- 📌 حتى لو المستخدم ضغط "Save"
- 🛡️ النظام يعرف أن القرار غير صالح للأتمتة

---

## 8️⃣ قابلية الاكتشاف (Minimum Observability)

### 8.1 تسجيل داخلي (Log only)

**بدون UI جديد:**

```json
log: {
  "alias_id": 147,
  "supplier_id": 25,
  "source": "learning",
  "usage_count": 1,
  "first_seen_at": "2025-12-26 10:30:45",
  "last_used_at": "2025-12-26 10:30:45"
}
```

**📌 حتى لو لم يُعرض**  
يمكن التحقيق لاحقًا بدون DB surgery.

---

## 9️⃣ ماذا لم نغيّر (مهم)

### ❌ لم نغيّر:

- ✗ الجداول
- ✗ الخوارزميات الأساسية
- ✗ واجهة المستخدم
- ✗ سير العمل العام

### ✅ غيّرنا فقط:

- ✓ شروط
- ✓ حدود
- ✓ تسلسل قرار

---

## 🔚 الخلاصة البرمجية الصريحة

### المبدأ الأساسي

> **النظام لا يتوقف عن التعلّم**  
> **لكنه يتوقف عن تصديق نفسه دون إنسان**

### النتائج المضمونة

1. ✅ الخطأ البشري يبقى **محليًا**
2. ✅ لا يتحول إلى **حقيقة نظامية**
3. ✅ لا يتكاثر **آليًا**
4. ✅ لا يفسد البيانات **بصمت**

---

## 📋 Implementation Checklist

### Phase 1: Learning Gate (Priority: HIGH)
- [ ] Modify `LearningService::learnFromDecision()`
  - [ ] Add session_load check
  - [ ] Add alias self-reference check
  - [ ] Add conflict detection before learning
- [ ] Implement session tracking (decisions in last 30 min)
- [ ] Unit tests for learning conditions

### Phase 2: Usage Gate (Priority: CRITICAL)
- [ ] Modify `SupplierCandidateService::supplierCandidates()`
  - [ ] Change learned alias score from 1.0 to 0.90
  - [ ] Add `requires_human_review` flag
- [ ] Modify `SmartProcessingService::processNewGuarantees()`
  - [ ] Block auto-approve if source='learning'
  - [ ] Log blocked auto-approvals

### Phase 3: Reinforcement Break (Priority: HIGH)
- [ ] Modify `SupplierLearningRepository::incrementUsage()`
  - [ ] Check decision_source before increment
  - [ ] Check match_source (if from learning)
  - [ ] Log skipped increments

### Phase 4: Observability (Priority: MEDIUM)
- [ ] Add structured logging for alias usage
- [ ] Create background job to flag risky aliases
- [ ] Dashboard query for alias audit (SQL only, no UI)

### Phase 5: Testing (Priority: HIGH)
- [ ] Integration test: manual decision with learned alias
- [ ] Integration test: auto-processing blocked by learned alias
- [ ] Integration test: session_load > 20 disables learning
- [ ] Regression test: official suppliers still auto-approve

---

## 📊 Success Metrics

| Metric | Current (Before) | Target (After) |
|--------|------------------|----------------|
| Auto-approvals from learned aliases | ~90% | 0% |
| Learned alias accuracy | Unknown | Measurable |
| False negative rate (missed auto-approvals) | N/A | <5% |
| User review workload increase | 0 | <10% |

---

## 🚨 Rollback Plan

If implementation causes issues:

1. **Emergency disable:** Set `LEARNING_ENABLED = false` in config
2. **Revert scoring:** Change learned alias score back to 1.0
3. **Re-enable auto-approve:** Remove source check in SmartProcessingService
4. **Investigate:** Review logs to identify root cause

All changes are configuration/logic only, no schema changes required for rollback.

---

**Document Status:** Ready for Implementation  
**Review Required:** Senior Engineer + Product Owner  
**Estimated Effort:** 2-3 days (with testing)
