<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Constants\CryptoConstants;
use App\Constants\SessionKeys;
use App\Constants\TimeConstants;
use App\Constants\PercentageConstants;
use App\Constants\TrustScoreConstants;
use App\Constants\RateLimitConstants;
use App\Constants\TaskScoringConstants;
use App\Constants\SystemConstants;
use App\Constants\PaginationConstants;
use App\Constants\RedisConstants;
use App\Constants\PaymentConstants;
use App\Constants\ValidationConstants;
use App\Constants\FeatureConstants;

class ConstantsTest extends TestCase
{
    /** @test */
    public function crypto_constants_are_defined_correctly(): void
    {
        $this->assertEquals(19, CryptoConstants::DEFAULT_MIN_CONFIRMATIONS_TRC20);
        $this->assertEquals(15, CryptoConstants::DEFAULT_MIN_CONFIRMATIONS_BNB20);
        $this->assertEquals(30, CryptoConstants::DEFAULT_INTENT_EXPIRE_MINUTES);
    }

    /** @test */
    public function session_keys_are_defined_correctly(): void
    {
        $this->assertEquals('user_id', SessionKeys::USER_ID);
        $this->assertEquals('user_role', SessionKeys::USER_ROLE);
        $this->assertEquals('oauth_state', SessionKeys::OAUTH_STATE);
    }

    /** @test */
    public function magic_numbers_constants_are_defined_correctly(): void
    {
        $this->assertEquals(60, TimeConstants::SECONDS_PER_MINUTE);
        $this->assertEquals(3600, TimeConstants::SECONDS_PER_HOUR);
        $this->assertEquals(86400, TimeConstants::SECONDS_PER_DAY);

        $this->assertEquals(20, PercentageConstants::AD_TUBE_FEE_PERCENT);
        $this->assertEquals(15, PercentageConstants::SOCIAL_TASK_FEE_PERCENT);

        $this->assertEquals(50, TrustScoreConstants::INITIAL);
        $this->assertEquals(100, TrustScoreConstants::MAXIMUM);

        $this->assertEquals(10, RateLimitConstants::LOGIN_MAX_ATTEMPTS);
        $this->assertEquals(100, RateLimitConstants::API_MAX_REQUESTS);
    }
}
