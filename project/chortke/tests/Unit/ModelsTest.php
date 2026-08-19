<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Models\AccountDeletionLog;
use App\Models\ActivityLog;
use App\Models\Ads;
use App\Models\ApiToken;
use App\Models\BankCard;
use App\Models\ContentRevenue;
use Mockery as m;

class ModelsTest extends TestCase
{
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock('Core\Database');
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function verify_all_models_can_be_instantiated(): void
    {
        $this->assertInstanceOf(AccountDeletionLog::class, new AccountDeletionLog($this->db));
        $this->assertInstanceOf(ActivityLog::class, new ActivityLog($this->db));
        $this->assertInstanceOf(Ads::class, new Ads($this->db));
        $this->assertInstanceOf(ApiToken::class, new ApiToken($this->db));
        $this->assertInstanceOf(BankCard::class, new BankCard($this->db));
        $this->assertInstanceOf(ContentRevenue::class, new ContentRevenue($this->db));
    }
}
