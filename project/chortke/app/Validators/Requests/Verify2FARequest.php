<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class Verify2FARequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'code' => 'required|string|min:6|max:6',
        ];
    }

    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }

        $code = str_value($this->validated['code'] ?? '');
        if (!ctype_digit($code) || strlen((string)$code) !== 6) {
            $this->errors['code'][] = 'کد تأیید باید ۶ رقم باشد';
            return false;
        }

        return true;
    }

    public function messages(): array
    {
        return [
            'code.required' => 'کد تأیید الزامی است',
            'code.min'      => 'کد تأیید باید ۶ رقم باشد',
            'code.max'      => 'کد تأیید باید ۶ رقم باشد',
        ];
    }
}
