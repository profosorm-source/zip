<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * قراردادِ گام‌های جبران‌پذیر (compensatable) در بازیابی Saga.
 *
 * برخی گام‌های saga با `$step['class']` تعریف می‌شوند و در بازیابیِ
 * (recovery) باید بتوانند افکت قبلی خود را جبران کنند. این interface امضای
 * واقعیِ فراخوانی در SagaRecoveryWorker را توصیف می‌کند.
 */
interface SagaCompensatableStep
{
    /**
     * جبران/برگشت اثر یک گام اجرا شده.
     *
     * @param array<string, mixed> $payload        داده‌ی ورودی گام
     * @param mixed                $result         خروجی/نتیجه‌ی اجرای قبلی گام
     * @param \Throwable|null      $originalError  خطایی که باعث شروع بازیابی شد
     */
    public function compensate(array $payload, mixed $result, ?\Throwable $originalError = null): void;
}
