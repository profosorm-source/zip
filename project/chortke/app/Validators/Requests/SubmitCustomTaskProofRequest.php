<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

/**
 * درخواست ارسال مدرک (Proof) برای تسک سفارشی
 */
class SubmitCustomTaskProofRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'task_execution_id' => 'required|integer|min:1',
            'proof_text'        => 'nullable|string|min:10|max:2000',
            'proof_url'         => 'nullable|url|max:1000',
            'proof_code'        => 'nullable|string|min:2|max:255',
            'proof_file'        => 'nullable|string',
            'proof_data'        => 'nullable|string|max:4000', // در عمل فایل آپلود شده مدیریت می‌شود
            'idempotency_key'   => 'required|string|min:10|max:128',
        ];
    }

    public function messages(): array
    {
        return [
            'task_execution_id.required' => 'شناسه اجرای تسک الزامی است',
            'proof_text.min'             => 'مدرک متنی باید حداقل ۱۰ کاراکتر باشد',
            'proof_text.max'             => 'مدرک متنی نمی‌تواند بیشتر از ۲۰۰۰ کاراکتر باشد',
            'idempotency_key.required'   => 'کلید یکتای درخواست الزامی است',
            'proof_url.url'              => 'لینک مدرک معتبر نیست',
        ];
    }
}
