<?php
declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Minimal FormRequest-equivalent to satisfy validation guards and enable shared rules.
 */
abstract class BaseApiRequest
{
    /** Override with associative array of field => callable|regex|string rule. */
    abstract public function rules(): array;

    public function validate(array $input): array
    {
        $errors = [];
        foreach ($this->rules() as $field => $rule) {
            $value = $input[$field] ?? null;
            if (is_callable($rule)) {
                $res = $rule($value);
                if ($res !== true) {
                    $errors[] = $res ?: "Invalid {$field}";
                }
            } elseif (is_string($rule) && $rule === 'required') {
                if ($value === null || $value === '') {
                    $errors[] = "{$field} is required";
                }
            } elseif (is_string($rule) && $rule === 'email') {
                if ($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "{$field} must be a valid email";
                }
            }
        }
        return $errors;
    }
}
