<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class WithdrawInvestmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'withdrawal_type' => 'required|in:profit_only,full_close',
        ];
    }
}
