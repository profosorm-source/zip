<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class ToggleFavoriteRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'type' => 'required|string|max:50',
            'id'   => 'required|integer|min:1',
            'context' => 'required|string|max:50',
        ];
    }
}
