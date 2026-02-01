<?php
declare(strict_types=1);

namespace App\Http\Requests;

class CreateSupplierRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'official_name' => 'required',
            'english_name' => function ($val) {
                return true; // optional
            },
            'is_confirmed' => function ($val) {
                return $val === null || is_numeric($val) ? true : "is_confirmed must be numeric";
            },
        ];
    }
}
