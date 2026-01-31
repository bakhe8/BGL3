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

        if (!empty($data['iban']) && !self::isValidIban($data['iban'])) {
            $errors[] = 'IBAN غير صالح';
        }

        return $errors;
    }

    private static function isValidIban(string $iban): bool
    {
        $iban = strtolower(str_replace(' ', '', $iban));
        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return false;
        }
        // خوارزمية مبسطة للتحقق
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $converted = '';
        foreach (str_split($rearranged) as $ch) {
            $converted .= ctype_alpha($ch) ? (ord($ch) - 87) : $ch;
        }
        $mod = intval(substr($converted, 0, 1));
        for ($i = 1, $len = strlen($converted); $i < $len; $i++) {
            $mod = ($mod * 10 + intval($converted[$i])) % 97;
        }
        return $mod === 1;
    }
}
