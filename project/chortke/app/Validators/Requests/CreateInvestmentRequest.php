<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class CreateInvestmentRequest extends BaseFormRequest
{
    public function __construct(array $data = [])
    {
        if (!isset($data['risk_accepted'])) {
            $data['risk_accepted'] = 1;
        }
        parent::__construct($data);
    }

    public function rules(): array
    {
        return [
            'amount'          => 'required|numeric|min:1',
            'risk_accepted'   => 'required',
            'idempotency_key' => 'nullable|string',
        ];
    }
}
