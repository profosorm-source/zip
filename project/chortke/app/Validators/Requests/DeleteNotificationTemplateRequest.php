<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class DeleteNotificationTemplateRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'template_key' => 'required|string|min:2|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'template_key.required' => 'کلید قالب الزامی است',
        ];
    }
}
