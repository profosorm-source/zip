<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class IssueTokenRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'email'       => 'required|email|max:255',
            'password'    => 'required|string|min:1|max:255',
            'token_name'  => 'nullable|string|max:100',
            'scopes'      => 'nullable|string|max:200',
            'otp'         => 'nullable|string|max:10',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'    => 'ایمیل الزامی است',
            'email.email'       => 'فرمت ایمیل نامعتبر است',
            'password.required' => 'رمز الزامی است',
        ];
    }
}
