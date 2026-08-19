<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class JoinLotteryRequest extends BaseFormRequest
{
    public function __construct(array $data = [])
    {
        if (!isset($data['round_id']) && function_exists('app')) {
            $req = app(\Core\Request::class);
            if ($req && $req->param('id')) {
                $data['round_id'] = $req->param('id');
            }
        }
        parent::__construct($data);
    }

    public function rules(): array
    {
        return [
            'round_id' => 'required|numeric|min:1',
            'idempotency_key' => 'nullable|string',
        ];
    }
}
