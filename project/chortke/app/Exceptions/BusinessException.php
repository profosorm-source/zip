<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * BusinessException - برای خطاهای قوانین بیزینسی (Section 8.6)
 *
 * این exception برای اعتبارسنجی لایه Business Guard استفاده می‌شود
 * و باید در Controller به صورت کاربرپسند (422) نمایش داده شود.
 */
class BusinessException extends \Core\Exceptions\BusinessException
{
    /** @var array<string, mixed> */
    private array $errors = [];

    public function __construct(string $message = 'خطای بیزینسی رخ داد', int|array $code = 0, ?\Throwable $previous = null) {
        // اگر code آرایه بود، آن را به عنوان خطاها ذخیره کرده و کد پیش‌فرض 422 را می‌فرستیم
        if (is_array($code)) {
            $this->errors = $code;
            parent::__construct($message, 422, $previous);
        } else {
            parent::__construct($message, (int)$code, $previous);
        }
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
