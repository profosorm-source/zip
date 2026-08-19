<?php

namespace App\Contracts;

/**
 * Contract برای تمام سیستم‌های تبلیغاتی
 * 
 * این interface تمام سیستم‌های تبلیغاتی را یکسان‌سازی می‌کند:
 * - CustomTask, SeoAd, Banner, Vitrine, StoryPromotion, AdTube/Influencer
 */
interface AdSystemContract
{
    /**
     * ایجاد آگهی/تسک جدید
     * 
     * @param int $userId کاربر ایجادکننده
     * @param array<string, mixed> $data داده‌های آگهی
     * @return array<string, mixed> ['success' => bool, 'id' => int|null, 'message' => string]
     */
    public function create(int $userId, array $data): array;

    /**
     * بررسی اعتبار داده‌های آگهی
     * 
     * @param array<string, mixed> $data داده‌های آگهی
     * @param bool $isUpdate آیا این یک بروزرسانی است؟
     * @return array<string, mixed> ['valid' => bool, 'errors' => array]
     */
    public function validate(array $data, bool $isUpdate = false): array;

    /**
     * بررسی انقضای آگهی
     * 
     * @param int $adId شناسه آگهی
     * @return bool
     */
    public function isExpired(int $adId): bool;

    /**
     * محاسبه هزینه/کمیسیون سایت
     * 
     * @param string $amount مبلغ اولیه
     * @param array<string, mixed> $context متادیتای اضافی
     * @return string
     */
    public function calculateCost(string $amount, array $context = []): string;

    /**
     * پردازش پرداخت/کسب بودجه
     * 
     * @param int $adId شناسه آگهی
     * @param int $userId آیدی کاربر
     * @param string $amount مبلغ
     * @param string $currency واحد پول
     * @return array<string, mixed> ['success' => bool, 'transaction_id' => int|null, 'message' => string]
     */
    public function processPayment(int $adId, int $userId, string $amount, string $currency): array;

    /**
     * ردیابی تعاملات (کلیک، نمایش، تکمیل)
     * 
     * @param int $adId شناسه آگهی
     * @param string $eventType نوع رویداد (click, view, complete)
     * @param int|null $userId آیدی کاربر (اختیاری)
     * @return array<string, mixed> ['success' => bool, 'message' => string]
     */
    public function track(int $adId, string $eventType, ?int $userId = null): array;

    /**
     * دریافت وضعیت آگهی
     * 
     * @param int $adId شناسه آگهی
     * @return array<string, mixed>|null
     */
    public function getStatus(int $adId): ?array;

    /**
     * دریافت نوع سیستم
     * 
     * @return string
     */
    public function getType(): string;
}
