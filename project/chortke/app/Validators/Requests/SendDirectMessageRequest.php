<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class SendDirectMessageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'sender_id'    => 'required|integer|min:1',
            'recipient_id' => 'required|integer|min:1',
            'message'      => 'required|string|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'sender_id.required'    => 'شناسه فرستنده الزامی است',
            'recipient_id.required' => 'شناسه گیرنده الزامی است',
            'message.required'      => 'متن پیام الزامی است',
        ];
    }
}
