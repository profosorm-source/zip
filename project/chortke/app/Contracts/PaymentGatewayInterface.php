<?php

namespace App\Contracts;

/**
 * PaymentGatewayInterface - قرارداد مشترک برای همه پردازشگرهای پرداخت
 *
 * این interface تضمین می‌کند که همه gatewayها متدهای یکسانی داشته باشند
 */
interface PaymentGatewayInterface
{
    /**
     * ایجاد پرداخت جدید
     *
     * @param string $amount مبلغ
     * @param string $description توضیحات
     * @param string $callbackUrl آدرس بازگشت
     * @param array<string, mixed> $options اختیاری (ایمیل، موبایل و ...)
     * @return array<string, mixed> نتیجه شامل success, authority, message
     */
    public function createPayment(string $amount, string $description, string $callbackUrl, array $options = []): array;

    /**
     * بررسی وضعیت پرداخت (Verify payment with gateway)
     *
     * @param string $authority شناسه پرداخت (Transaction ID from gateway)
     * @param string $amount مبلغ تراکنش به تومان جهت تطابق (Amount in TOMAN - IRT)
     *                      - ZarinPal: sends Toman as-is
     *                      - NextPay: sends Toman as-is
     *                      - IDPay: doesn't send amount (API limitation)
     *                      - DgPay: converts Toman to Rial (*10)
     * @return array<string, mixed> نتیجه شامل success, status, amount, refId
     */
    public function verifyPayment(string $authority, string $amount): array;

    /**
     * تایید اعتبار callback دریافتی از gateway
     *
     * @param array<string, mixed> $callbackData داده‌های callback
     * @return bool true اگر callback معتبر باشد
     */
    public function verifyCallback(array $callbackData): bool;

    /**
     * برگرداندن پرداخت (در صورت امکان)
     *
     * @param string $authority شناسه پرداخت
     * @return array<string, mixed> نتیجه برگرداندن
     */
    public function refundPayment(string $authority): array;

    /**
     * نام gateway
     */
    public function getName(): string;

    /**
     * آیا gateway فعال است
     */
    public function isActive(): bool;
}