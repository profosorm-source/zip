<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class PlacePredictionBetRequest extends BaseFormRequest
{
    public function __construct(array $data = [])
    {
        if (isset($data['amount']) && !isset($data['amount_usdt'])) {
            $data['amount_usdt'] = $data['amount'];
        }
        parent::__construct($data);
    }

    public function rules(): array
    {
        return [
            'prediction' => 'required|in:home,away,draw',
            'amount_usdt' => 'required|numeric|min:0.01',
            'idempotency_key' => 'nullable|string',
        ];
    }
}
