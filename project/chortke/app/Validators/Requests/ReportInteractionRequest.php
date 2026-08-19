<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class ReportInteractionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'type' => 'required|string|max:50',
            'id'   => 'required|integer|min:1',
            'reason' => 'required|string|min:3|max:100',
            'description' => 'nullable|string|max:1000',
            'context' => 'required|string|max:50',
        ];
    }
}
