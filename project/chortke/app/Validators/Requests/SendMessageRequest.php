<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class SendMessageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'recipient_id'  => 'required|integer|min:1',
            'message'       => 'required|string|min:1|max:5000',
            'is_encrypted'  => 'nullable|in:0,1',
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_id.required' => 'گیرنده پیام الزامی است',
            'recipient_id.integer'  => 'شناسه گیرنده نامعتبر است',
            'message.required'      => 'پیام نمی‌تواند خالی باشد',
            'message.max'           => 'متن پیام نمی‌تواند بیش از ۵۰۰۰ کاراکتر باشد',
        ];
    }
}
