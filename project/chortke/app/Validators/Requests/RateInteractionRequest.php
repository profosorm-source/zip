<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class RateInteractionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'type' => 'required|string|max:50',
            'id'   => 'required|integer|min:1',
            'rating' => 'required|integer|min:1|max:5',
            'context' => 'required|string|max:50',
        ];
    }
}
