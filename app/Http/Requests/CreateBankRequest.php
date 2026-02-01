<?php
declare(strict_types=1);

namespace App\Http\Requests;

class CreateBankRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'official_name' => 'required',
            'contact_email' => 'email',
            'department' => function ($val) {
                return true; // optional free text
            },
        ];
    }
}
