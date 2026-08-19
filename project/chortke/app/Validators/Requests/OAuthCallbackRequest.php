<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class OAuthCallbackRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'code'  => 'required|string|min:1|max:2048',
            'state' => 'required|string|min:1|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required'  => 'پارامترهای بازگشتی نامعتبر است',
            'state.required' => 'پارامترهای بازگشتی نامعتبر است',
        ];
    }
}
