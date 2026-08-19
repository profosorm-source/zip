<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class CreateDepositRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'gateway' => 'required|string|in:zarinpal,idpay,nextpay,dgpay,mock',
            'amount' => 'required|numeric|min:1000',
            'idempotency_key' => 'required|string|min:10|max:128',
        ];
    }
}
