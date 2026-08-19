<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class CreateTransferRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'recipient' => 'required|string|min:3|max:255',
            'amount'    => 'required|numeric|min:100',
        ];
    }

    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }

        $amount = float_value($this->validated['amount'] ?? 0);
        if ($amount <= 0) {
            $this->errors['amount'][] = 'مبلغ انتقال باید بیشتر از صفر باشد';
            return false;
        }

        return true;
    }

    public function messages(): array
    {
        return [
            'recipient.required' => 'شناسه گیرنده الزامی است',
            'recipient.min'      => 'شناسه گیرنده نامعتبر است',
            'amount.required'    => 'مبلغ انتقال الزامی است',
            'amount.numeric'     => 'مبلغ باید عدد باشد',
            'amount.min'         => 'حداقل مبلغ انتقال ۱۰۰ تومان است',
        ];
    }
}
