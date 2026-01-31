<?php
/**
 * Patch Template: Validation Rules (Laravel-style Request)
 * استبدل {FIELDS} بالحقول الفعلية.
 */

public function rules(): array
{
    return [
        // IBAN
        'iban' => ['required', 'string', 'max:34', 'regex:/^[A-Z0-9]+$/'],
        // Email
        'email' => ['required', 'email'],
        // Phone (مثال دولي بسيط)
        'phone' => ['nullable', 'regex:/^[+0-9\s\-]{7,20}$/'],
        // مثال حقل اسم
        'name' => ['required', 'string', 'max:255'],
        // أضف بقية الحقول هنا {FIELDS}
    ];
}
