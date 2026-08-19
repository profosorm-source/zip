<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class UserUpdateRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'full_name' => 'nullable|string|min:3|max:100',
            'email'     => 'nullable|email|max:255',
            'password'  => 'nullable|string|min:8|max:255',
            'role'      => 'nullable|in:user,admin,support,super_admin',
            'status'    => 'nullable|in:active,inactive,suspended,banned',
            'mobile'    => 'nullable|string|min:10|max:15',
            'username'  => 'nullable|string|min:3|max:64',
            'bio'       => 'nullable|string|max:1000',
        ];
    }

    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }

        // At least one field must be present
        $validated = $this->validated();
        $nonNull = array_filter($validated, fn($v) => $v !== null && $v !== '');
        if (empty($nonNull)) {
            $this->errors['general'][] = 'حداقل یک فیلد باید برای به‌روزرسانی ارسال شود';
            return false;
        }

        return true;
    }

    public function messages(): array
    {
        return [
            'full_name.min'   => 'نام باید حداقل ۳ کاراکتر باشد',
            'full_name.max'   => 'نام نمی‌تواند بیش از ۱۰۰ کاراکتر باشد',
            'email.email'     => 'فرمت ایمیل نامعتبر است',
            'email.max'       => 'ایمیل نمی‌تواند بیش از ۲۵۵ کاراکتر باشد',
            'password.min'    => 'رمز عبور باید حداقل ۸ کاراکتر باشد',
            'role.in'         => 'نقش انتخاب شده نامعتبر است',
            'status.in'       => 'وضعیت انتخاب شده نامعتبر است',
            'mobile.min'      => 'شماره موبایل نامعتبر است',
            'username.min'    => 'نام کاربری باید حداقل ۳ کاراکتر باشد',
            'username.max'    => 'نام کاربری نمی‌تواند بیش از ۶۴ کاراکتر باشد',
            'bio.max'         => 'بیوگرافی نمی‌تواند بیش از ۱۰۰۰ کاراکتر باشد',
        ];
    }
}
