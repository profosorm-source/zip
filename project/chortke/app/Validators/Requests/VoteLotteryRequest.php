<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class VoteLotteryRequest extends BaseFormRequest
{
    public function __construct(array $data = [])
    {
        if (!isset($data['round_id']) && function_exists('app')) {
            $req = app(\Core\Request::class);
            if ($req && $req->param('id')) {
                $data['round_id'] = $req->param('id');
            }
        }
        if (!isset($data['voted_number'])) {
            if (isset($data['choice']) && is_numeric($data['choice'])) {
                $data['voted_number'] = (int)$data['choice'];
            } else {
                $data['voted_number'] = 7;
            }
        }
        parent::__construct($data);
    }

    public function rules(): array
    {
        return [
            'round_id' => 'nullable|numeric|min:1',
            'daily_number_id' => 'nullable|numeric|min:1',
            'voted_number' => 'required|integer|min:1|max:49',
        ];
    }
}
