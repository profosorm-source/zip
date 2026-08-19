<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * PaymentGatewayException - بنیادی Payment Gateway استثنیٰ
 * 
 * تمام payment gateway کی خرابیوں کے لیے استعمال ہوتا ہے
 * - Gateway errors
 * - Configuration errors
 * - API errors
 */
class PaymentGatewayException extends \Core\Exceptions\ExternalServiceException
{
    private const DEFAULT_CODE = 500;
    private const DEFAULT_MESSAGE = 'Payment gateway error occurred';

    public function __construct(
        string $message = '',
        int $code = self::DEFAULT_CODE,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message ?: self::DEFAULT_MESSAGE, $code, $previous);
    }
}
