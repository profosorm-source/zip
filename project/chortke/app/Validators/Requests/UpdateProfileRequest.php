<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class UpdateProfileRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'full_name'   => 'nullable|string|min:3|max:100',
            'mobile'      => 'nullable|string|min:10|max:15',
            'national_id' => 'nullable|string|min:10|max:10',
            'birth_date'  => 'nullable|string|max:10',
            'gender'      => 'nullable|in:male,female,other',
            'address'     => 'nullable|string|max:500',
            'bio'         => 'nullable|string|max:1000',
            'website'     => 'nullable|string|max:255',
            'location'    => 'nullable|string|max:100',
            'avatar'      => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.min'      => 'نام باید حداقل ۳ کاراکتر باشد',
            'full_name.max'      => 'نام نمی‌تواند بیش از ۱۰۰ کاراکتر باشد',
            'mobile.min'         => 'شماره موبایل نامعتبر است',
            'national_id.min'    => 'کد ملی باید ۱۰ رقم باشد',
            'national_id.max'    => 'کد ملی باید ۱۰ رقم باشد',
            'gender.in'          => 'جنسیت نامعتبر است',
            'address.max'        => 'آدرس نمی‌تواند بیش از ۵۰۰ کاراکتر باشد',
            'bio.max'            => 'بیوگرافی نمی‌تواند بیش از ۱۰۰۰ کاراکتر باشد',
            'website.max'        => 'وبسایت نمی‌تواند بیش از ۲۵۵ کاراکتر باشد',
        ];
    }
}
