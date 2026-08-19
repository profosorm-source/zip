<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class StoreSocialAccountRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'provider' => 'required|in:instagram,telegram,youtube,twitter',
            'provider_user_id' => 'required|string|min:3|max:100',
            'access_token' => 'nullable|string',
        ];
    }
}
