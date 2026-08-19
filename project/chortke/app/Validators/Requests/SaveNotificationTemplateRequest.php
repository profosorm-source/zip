<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class SaveNotificationTemplateRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'template_key' => 'required|string|min:2|max:100',
            'title'        => 'required|string|min:2|max:500',
            'message'      => 'required|string|min:2|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'template_key.required' => 'کلید قالب الزامی است',
            'title.required'        => 'عنوان قالب الزامی است',
            'message.required'      => 'متن قالب الزامی است',
        ];
    }
}
