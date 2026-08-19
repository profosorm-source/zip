<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class ContactMessageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name'    => 'required|string|min:3|max:100',
            'email'   => 'required|email|max:150',
            'subject' => 'required|string|min:5|max:200',
            'message' => 'required|string|min:10|max:5000',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'    => 'نام شما الزامی است',
            'email.required'   => 'ایمیل شما الزامی است',
            'email.email'      => 'فرمت ایمیل معتبر نیست',
            'subject.required' => 'موضوع پیام الزامی است',
            'message.required' => 'متن پیام الزامی است',
        ];
    }
}
