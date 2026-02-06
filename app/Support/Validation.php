<?php

namespace App\Support;

class Validation
{
    public static function validateBank(array $data): array
    {
        $errors = [];

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email غير صالح';
        }

        if (!empty($data['phone']) && !preg_match('/^[0-9+()\\-\\s]{6,20}$/', $data['phone'])) {
            $errors[] = 'رقم الهاتف غير صالح';
        }

        return $errors;
    }
}
