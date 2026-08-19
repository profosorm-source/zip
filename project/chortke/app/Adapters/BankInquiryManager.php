<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\LoggerInterface;

/**
 * BankInquiryManager
 * یک الگو (Provider Chain / Composite) برای مدیریت چندین آداپتور استعلام بانکی.
 * در صورت خطا در یک آداپتور، به طور خودکار به سراغ آداپتور بعدی (Fallback) می‌رود.
 */
class BankInquiryManager implements BankInquiryAdapter
{
    /**
     * @var list<BankInquiryAdapter>
     */
    private array $adapters;
    private LoggerInterface $logger;

    /** @param list<BankInquiryAdapter> $adapters */
    public function __construct(LoggerInterface $logger, array $adapters) {
        $this->logger = $logger;
        $this->adapters = $adapters;
    }

    public function addAdapter(BankInquiryAdapter $adapter): void
    {
        $this->adapters[] = $adapter;
    }

    /**
     * @inheritDoc
     * @return array<string, mixed>
     */
    public function inquireIban(string $iban): array
    {
        if (empty($this->adapters)) {
            $this->logger->error('bank_inquiry_manager.empty_chain', ['iban' => $iban]);
            return [
                'success' => false,
                'message' => 'هیچ سرویس استعلام بانکی پیکربندی نشده است.'
            ];
        }

        $lastError = 'خطای نامشخص';

        foreach ($this->adapters as $index => $adapter) {
            $providerName = get_class($adapter);
            try {
                $result = $adapter->inquireIban($iban);
                
                // If successful, return immediately
                if (!empty($result['success'])) {
                    return $result;
                }

                // Collect error message to return if all fail
                $lastError = $result['message'] ?? 'خطای سرویس';
                $this->logger->warning('bank_inquiry_manager.adapter_failed', [
                    'provider' => $providerName,
                    'iban' => $iban,
                    'error' => $lastError,
                    'fallback_to_next' => $index < (count($this->adapters) - 1)
                ]);

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->logger->error('bank_inquiry_manager.adapter_exception', [
                    'provider' => $providerName,
                    'iban' => $iban,
                    'error' => $lastError,
                    'fallback_to_next' => $index < (count($this->adapters) - 1)
                ]);
            }
        }

        // All providers failed
        $this->logger->critical('bank_inquiry_manager.all_providers_failed', [
            'iban' => $iban,
            'last_error' => $lastError
        ]);

        return [
            'success' => false,
            'message' => 'تمامی سرویس‌های استعلام بانکی با خطا مواجه شدند. آخرین خطا: ' . $lastError
        ];
    }

    /**
     * @inheritDoc
     */
    public function isConfigured(): bool
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->isConfigured()) {
                return true;
            }
        }
        return false;
    }
}
