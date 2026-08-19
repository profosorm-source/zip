<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\ModuleContext;

/**
 * قرارداد استراتژی‌های گیمیفیکیشن و تعاملات
 */
interface GamificationStrategyInterface
{
    /**
     * اجرای منطق استراتژی
     * 
     * @param object $user کاربری که اکشن را انجام داده یا مشمول استراتژی است
     * @param ModuleContext $context ماژولی که استراتژی در آن اجرا می‌شود
     * @param array<string, mixed> $payload دیتای اضافی مورد نیاز برای محاسبه (مثل مبلغ سرمایه‌گذاری، نوع اکشن)
     * @return float|int مقدار محاسبه شده برای تغییرات (مثلا +10 XP یا -5 Trust)
     */
    public function calculate(object $user, ModuleContext $context, array $payload = []): float|int;
    
    /**
     * مشخص می‌کند این استراتژی برای کدام نوع از متریک‌هاست
     */
    public function getMetricType(): \App\Enums\MetricType;
}
