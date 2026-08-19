<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class CreateInfluencerOrderRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'influencer_id' => 'required|numeric|min:1',
            'order_type' => 'required|in:story,post',
            'duration_hours' => 'required|numeric|min:1',
        ];
    }
}
