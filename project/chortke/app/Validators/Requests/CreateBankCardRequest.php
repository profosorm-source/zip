<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class CreateBankCardRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'card_number' => 'required|string|min:16|max:16',
            'cardholder_name' => 'required|string|min:3|max:100',
            'sheba' => 'nullable|string',
        ];
    }
}
