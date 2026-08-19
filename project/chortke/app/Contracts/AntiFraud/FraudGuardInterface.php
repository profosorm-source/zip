<?php

declare(strict_types=1);

namespace App\Contracts\AntiFraud;

/**
 * قرارداد گیت ضدتقلب.
 *
 * با استخراج این اینترفیس، سرویس‌های مصرف‌کننده (مثل WalletMutationService) به‌جای
 * وابستگی به کلاس final کانکریت، به این قرارداد وابسته می‌شوند که هم تست‌پذیری را
 * بهبود می‌دهد و هم امکان جایگزینی پیاده‌سازی را فراهم می‌کند.
 */
interface FraudGuardInterface
{
    /**
     * بررسی یک کنش (action) از نظر ریسک تقلب.
     *
     * @param int    $userId  شناسه کاربر
     * @param string $action  نوع کنش (مثلاً 'withdrawal.create')
     * @param array<string, mixed> $context داده‌های زمینه‌ای (amount, currency, ip, device_fingerprint, ...)
     * @return array{allowed: bool, action: string, score: int, reason: string, details: array<string, mixed>}
     */
    public function checkAction(int $userId, string $action, array $context = []): array;
}
