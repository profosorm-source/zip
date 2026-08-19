<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class SendNotificationRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'target'       => 'required|in:all,segment,user',
            'segment'      => 'nullable|string|max:50',
            'type'         => 'required|in:info,success,warning,error',
            'title'        => 'required|string|min:2|max:500',
            'message'      => 'required|string|min:2|max:2000',
            'user_id'      => 'nullable|integer|min:1',
            'priority'     => 'nullable|in:low,normal,high',
            'scheduled_at' => 'nullable|string',
            'action_url'   => 'nullable|string|max:2048',
            'action_text'  => 'nullable|string|max:100',
        ];
    }

    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }

        $validated = $this->validated();

        if (($validated['target'] ?? '') === 'user' && empty($validated['user_id'])) {
            $this->errors['user_id'][] = 'برای ارسال به کاربر مشخص، شناسه کاربر الزامی است';
            return false;
        }

        return true;
    }

    public function messages(): array
    {
        return [
            'target.required'  => 'هدف ارسال الزامی است',
            'target.in'        => 'هدف ارسال نامعتبر است',
            'type.required'    => 'نوع اعلان الزامی است',
            'type.in'          => 'نوع اعلان نامعتبر است',
            'title.required'   => 'عنوان اعلان الزامی است',
            'title.min'        => 'عنوان باید حداقل ۲ کاراکتر باشد',
            'message.required' => 'متن اعلان الزامی است',
            'message.min'      => 'متن باید حداقل ۲ کاراکتر باشد',
        ];
    }
}
