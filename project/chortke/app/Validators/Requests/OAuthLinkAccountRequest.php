<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class OAuthLinkAccountRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'provider' => 'required|in:google,facebook',
        ];
    }

    public function messages(): array
    {
        return [
            'provider.required' => 'انتخاب سرویس‌دهنده الزامی است',
            'provider.in'       => 'سرویس‌دهنده نامعتبر است',
        ];
    }
}
