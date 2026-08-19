<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\BankCardService;
use App\Models\BankCard;
use App\Models\User;
use Core\Database;
use Core\Encryption;
use App\Contracts\LoggerInterface;
use App\Contracts\ValidatorFactoryInterface;

/**
 * Whitebox tests for BankCardService helpers (Luhn, name matching, digit
 * normalization). Use reflection because these helpers are intentionally
 * `private`.
 *
 * Note: the previous version of this file passed constructor arguments in
 * the wrong order and used a now-typed mock of IdempotencyKey, which broke
 * after the BankCardService signature was hardened. The factory below pins
 * the canonical signature in ONE place so future signature drifts only
 * require one edit.
 */
class KycBankCardSecurityTest extends TestCase
{
    /** @var Database&\PHPUnit\Framework\MockObject\MockObject */
    private Database $dbMock;
    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private LoggerInterface $loggerMock;
    /** @var Encryption&\PHPUnit\Framework\MockObject\MockObject */
    private Encryption $encryptionMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbMock         = $this->createMock(Database::class);
        $this->loggerMock     = $this->createMock(LoggerInterface::class);
        $this->encryptionMock = $this->createMock(Encryption::class);
    }

    private function makeService(): BankCardService
    {
        $cardModelMock      = $this->createMock(BankCard::class);
        $userModelMock      = $this->createMock(User::class);
        $inquiryAdapterMock = $this->createMock(\App\Adapters\BankInquiryAdapter::class);
        $validatorFactory   = $this->createMock(ValidatorFactoryInterface::class);
        // Pass an explicit mock so BankCardService doesn't fall back to the
        // container (which doesn't have IdempotencyService bound during
        // PHPUnit runs and would throw "Cannot resolve LoggerInterface").
        $idempotencyService = $this->createMock(\App\Services\Shared\IdempotencyService::class);

        // Canonical constructor signature:
        // (db, logger, model, userModel, inquiryAdapter, encryption, validatorFactory, idempotencyService)
        return new BankCardService(
            $this->dbMock,
            $this->loggerMock,
            $cardModelMock,
            $userModelMock,
            $this->encryptionMock,
            $validatorFactory,
            $idempotencyService
        );
    }

    /** @param list<mixed> $args */
    private function invoke(BankCardService $service, string $method, array $args): mixed
    {
        $ref = new \ReflectionClass(BankCardService::class);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($service, $args);
    }

    /** @test */
    public function test_luhn_validation_supports_15_to_19_digits(): void
    {
        $service = $this->makeService();

        // Standard 16 digit card (valid)
        $this->assertTrue($this->invoke($service, 'validateLuhn', ['4111111111111111']));
        // Invalid standard card
        $this->assertFalse($this->invoke($service, 'validateLuhn', ['4111111111111112']));
        // Valid 15 digit card (AMEX-style)
        $this->assertTrue($this->invoke($service, 'validateLuhn', ['378282246310005']));
        // Valid 19 digit card
        $this->assertTrue($this->invoke($service, 'validateLuhn', ['1111111111111111113']));
    }

    /** @test */
    public function test_name_matching_threshold_is_strict_and_includes_levenshtein(): void
    {
        $service = $this->makeService();

        // Exact match
        $this->assertTrue($this->invoke($service, 'matchName', ['علیرضا رضاپور', 'علیرضا رضاپور']));
        // Match with prefix title
        $this->assertTrue($this->invoke($service, 'matchName', ['سید علیرضا رضاپور', 'علیرضا رضاپور']));
        // Match with minor whitespace variation (still high similarity)
        $this->assertTrue($this->invoke($service, 'matchName', ['علیرضا رضا پور', 'علیرضا رضاپور']));

        // Rejected matches: swapped order / dropped syllable
        $this->assertFalse($this->invoke($service, 'matchName', ['علیرضا پوررضا', 'علیرضا رضاپور']));
        $this->assertFalse($this->invoke($service, 'matchName', ['علی رضاپور', 'علیرضا رضاپور']));
    }

    /** @test */
    public function test_arabic_and_persian_digit_normalization(): void
    {
        $service = $this->makeService();

        $this->assertSame('1234567890', $this->invoke($service, 'normalizeDigits', ['۱۲۳۴۵۶۷۸۹۰']));
        $this->assertSame('1234567890', $this->invoke($service, 'normalizeDigits', ['١٢٣٤٥٦٧٨٩٠']));
    }
}
