<?php
declare(strict_types=1);

namespace App\Support;

class Normalizer
{
    public function normalizeName(string $value): string
    {
        $value = trim(mb_strtolower($value));
        // توحيد مسافات
        $value = preg_replace('/\s+/u', ' ', $value);
        // توحيد بعض الحروف العربية الشائعة
        $value = str_replace(
            ['أ', 'إ', 'آ', 'ة', 'ى', 'ئ', 'ؤ'],
            ['ا', 'ا', 'ا', 'ه', 'ي', 'ي', 'و'],
            $value
        );
        // إزالة رموز زائدة
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value);
        return trim($value);
    }

    /**
     * تطبيع أسماء الموردين مع إزالة الكلمات العامة الخاصة بالشركات.
     */
    public function normalizeSupplierName(string $value): string
    {
        $value = $this->normalizeName($value);
        if ($value === '') {
            return '';
        }
        // كلمات عامة للموردين تُزال لتقليل الضوضاء
        $stop = [
            'شركة', 'شركه', 'مؤسسة', 'مؤسسه', 'مؤسسة', 'مؤسسه', 'مكتب', 'مصنع', 'مقاولات',
            'trading', 'est', 'est.', 'establishment', 'company', 'co', 'co.', 'ltd', 'ltd.',
            'limited', 'llc', 'inc', 'inc.', 'international', 'global'
        ];
        $parts = preg_split('/\s+/u', $value);
        $filtered = array_filter($parts, fn($p) => $p !== '' && !in_array($p, $stop, true));
        $clean = implode(' ', $filtered);
        // دمج المسافات مجدداً ثم إزالة التكرارات
        $clean = preg_replace('/\s+/u', ' ', $clean ?? '');
        return trim($clean);
    }

    /**
     * تطبيع أسماء البنوك (نفس قواعد الأسماء العامة حالياً).
     */
    public function normalizeBankName(string $value): string
    {
        // نفس تطبيع الأسماء، مع إزالة الفراغات لجعل المفتاح متوافقاً مع normalized_key الرسمي (riyadbank بدلاً من riyad bank)
        $val = $this->normalizeName($value);
        $val = str_replace(' ', '', $val);
        return $val;
    }

    /**
     * تطبيع مختصرات البنوك (short_code): تحويل للحروف الكبيرة وإزالة الرموز والمسافات.
     */
    public function normalizeBankShortCode(string $code): string
    {
        $code = strtoupper(trim($code));
        return preg_replace('/[^A-Z0-9]/', '', $code) ?? '';
    }

    /**
     * إنشاء مفتاح هوية ثابت للمورد (normalized بدون مسافات).
     * 
     * ⚠️ SYNC WARNING: This logic is duplicated in JavaScript!
     * @see www/assets/js/decision.js - makeSupplierKey() function (line ~159)
     * 
     * السبب: نحتاج التحقق الفوري (client-side) قبل إرسال الطلب للخادم.
     * إذا عدّلت هذه الدالة، يجب تحديث نسخة JS أيضاً!
     */
    public function makeSupplierKey(string $value): string
    {
        $norm = $this->normalizeSupplierName($value);
        return str_replace(' ', '', $norm);
    }
}
