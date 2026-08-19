<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * PaymentVerificationException - پیمنٹ کی تصدیق میں خرابی
 * 
 * جب پیمنٹ کی تصدیق ناکام ہو
 * - Invalid transaction ID
 * - Amount mismatch
 * - Signature verification failed
 * - Transaction not found
 */
class PaymentVerificationException extends PaymentGatewayException
{
    private const DEFAULT_CODE = 402;
    private const DEFAULT_MESSAGE = 'Payment verification failed';

    private ?string $transactionId = null;
    /** @var array<string, mixed>|null */
    private ?array $details = null;

    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        string $message = '',
        ?string $transactionId = null,
        ?array $details = null,
        ?\Throwable $previous = null
    ) {
        $fullMessage = $message ?: self::DEFAULT_MESSAGE;
        
        if ($transactionId) {
            $fullMessage .= " [Transaction: {$transactionId}]";
        }
        
        parent::__construct($fullMessage, self::DEFAULT_CODE, $previous);
        
        $this->transactionId = $transactionId;
        if ($details) {
            $this->details = $details;
        }
    }

    /**
     * Transaction ID حاصل کریں
     */
    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    /**
     * تصدیق کی تفصیلات (مثال: amount mismatch وغیرہ)
     */
    /**
     * @return array<string, mixed>|null
     */
    public function getDetails(): ?array
    {
        return $this->details;
    }
}
