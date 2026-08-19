<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class CreateBugReportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'page_url'           => 'nullable|string|max:2048',
            'page_title'         => 'nullable|string|max:500',
            'category'           => 'required|in:ui,functional,performance,security,other',
            'description'        => 'required|string|min:10|max:5000',
            'screen_resolution'  => 'nullable|string|max:20',
            'device_fingerprint' => 'nullable|string|max:255',
        ];
    }

    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }

        $validated = $this->validated();
        $pageUrl   = str_value($validated['page_url'] ?? '');

        if ($pageUrl !== '') {
            $sanitizedUrl = (string)filter_var($pageUrl, FILTER_SANITIZE_URL);
            if (!filter_var($sanitizedUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $sanitizedUrl)) {
                $this->errors['page_url'][] = 'آدرس صفحه نامعتبر است';
                return false;
            }
        }

        $screenRes = str_value($validated['screen_resolution'] ?? '');
        if ($screenRes !== '' && !preg_match('/^\d{1,5}x\d{1,5}$/', $screenRes)) {
            $this->validated['screen_resolution'] = '';
        }

        return true;
    }

    public function messages(): array
    {
        return [
            'category.required'    => 'دسته‌بندی باگ الزامی است',
            'category.in'          => 'دسته‌بندی نامعتبر است',
            'description.required' => 'توضیحات باگ الزامی است',
            'description.min'      => 'توضیحات باید حداقل ۱۰ کاراکتر باشد',
            'description.max'      => 'توضیحات نمی‌تواند بیش از ۵۰۰۰ کاراکتر باشد',
        ];
    }
}
