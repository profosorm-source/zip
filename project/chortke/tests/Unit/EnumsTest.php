<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enums\InteractionType;
use App\Enums\UserStatus;
use App\Enums\TransactionStatus;

class EnumsTest extends TestCase
{
    /** @test */
    public function verify_all_enums_are_defined_correctly(): void
    {
        // 1. InteractionType (Backed Enum)
        $this->assertEquals('rating', InteractionType::RATING->value);
        $this->assertEquals('report', InteractionType::REPORT->value);

        // 2. UserStatus (Class with constants)
        $this->assertEquals('active', UserStatus::ACTIVE);
        $this->assertEquals('banned', UserStatus::BANNED);

        // 3. TransactionStatus (Class with constants)
        $this->assertEquals('completed', TransactionStatus::COMPLETED);
        $this->assertEquals('failed', TransactionStatus::FAILED);
    }
}
