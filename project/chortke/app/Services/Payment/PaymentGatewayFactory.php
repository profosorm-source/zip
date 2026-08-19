<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\LoggerInterface;
use App\Exceptions\PaymentGatewayException;

/**
 * PaymentGatewayFactory - مسئول ساخت و مدیریت درگاه‌های پرداخت
 * 
 * اصلاحات اعمال شده:
 * ✅ حذف کامل Container::getInstance() 
 * ✅ استفاده از Resolver Callback Pattern به جای ۵ Provider جدا
 * ✅ Dependency Injection مستقیم در Constructor
 * ✅ حفظ تست‌پذیری و سادگی
 */
class PaymentGatewayFactory
{
    /**
     * @var array<string, PaymentGatewayInterface> کش درگاه‌ها
     */
    private array $gateways = [];

    /**
     * @var callable(string): PaymentGatewayInterface ریزالور درگاه
     * 
     * مثال مقداردهی در AppServiceProvider:
     * fn($name) => match($name) {
     *     'zarinpal' => $container->make(ZarinPalGateway::class),
     *     ...
     * }
     */
    private $gatewayResolver;

    private LoggerInterface $logger;

    /**
     * @param callable|null $gatewayResolver تابع ساخت درگاه (lazy)
     */
    public function __construct(
        LoggerInterface $logger,
        ?callable $gatewayResolver = null
    ) {
        $this->logger = $logger;
        $this->gatewayResolver = $gatewayResolver ?? function (string $gateway): PaymentGatewayInterface {
            throw new PaymentGatewayException("هیچ resolver برای درگاه {$gateway} تنظیم نشده است");
        };
    }

    /**
     * ایجاد instance درگاه بر اساس نام
     */
    public function create(string $gateway): PaymentGatewayInterface
    {
        if (empty($gateway) || strlen((string)$gateway) > 50) {
            $this->logger->error("Invalid payment gateway name: empty or too long");
            throw new PaymentGatewayException("درگاه پرداخت نامعتبر است: نام خالی یا بیش‌ازحد طولانی");
        }

        $gateway = strtolower(trim((string)$gateway));

        // بررسی cache
        if (isset($this->gateways[$gateway])) {
            return $this->gateways[$gateway];
        }

        // استفاده از resolver callback (به جای Container::getInstance)
        $resolver = $this->gatewayResolver;
        
        try {
            $instance = $resolver($gateway);
            
            if (!($instance instanceof PaymentGatewayInterface)) {
                $this->logger->error("Gateway instance does not implement PaymentGatewayInterface: {$gateway}");
                throw new PaymentGatewayException("Gateway instance must implement PaymentGatewayInterface");
            }

            $this->gateways[$gateway] = $instance;
            $this->logger->info("Payment gateway created successfully: {$gateway}");
            return $instance;

        } catch (PaymentGatewayException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to resolve gateway {$gateway}: " . $e->getMessage());
            throw new PaymentGatewayException("خطا در راه‌اندازی درگاه پرداخت: {$gateway}");
        }
    }

    /**
     * لیست درگاه‌های فعال
     */
    /** @return array<string, mixed> */
    public static function getAvailableGateways(): array
    {
        return [
            'zarinpal' => [
                'name' => 'زرین‌پال',
                'icon' => 'zarinpal.png',
                'description' => 'پرداخت امن با زرین‌پال'
            ],
            'nextpay' => [
                'name' => 'نکست‌پی',
                'icon' => 'nextpay.png',
                'description' => 'پرداخت سریع با نکست‌پی'
            ],
            'idpay' => [
                'name' => 'آیدی‌پی',
                'icon' => 'idpay.png',
                'description' => 'پرداخت آنلاین آیدی‌پی'
            ],
            'dgpay' => [
                'name' => 'دی‌جی‌پی',
                'icon' => 'dgpay.png',
                'description' => 'درگاه پرداخت دی‌جی‌پی'
            ],
        ];
    }
}
