<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class UpdateGeneralSettingsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'language'    => 'required|in:fa,en',
            'timezone'    => 'required|string|max:50',
            'theme'       => 'required|in:light,dark,auto',
            'date_format' => 'required|in:jalali,gregorian',
        ];
    }

    public function messages(): array
    {
        return [
            'language.required'    => 'زبان الزامی است',
            'language.in'          => 'زبان نامعتبر است',
            'timezone.required'    => 'منطقه زمانی الزامی است',
            'theme.required'       => 'پوسته الزامی است',
            'theme.in'             => 'پوسته نامعتبر است',
            'date_format.required' => 'فرمت تاریخ الزامی است',
            'date_format.in'       => 'فرمت تاریخ نامعتبر است',
        ];
    }
}
