<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\CreatesTypedMockeryMocks;
use App\Adapters\TapsellVideoRewardAdapter;
use App\Contracts\LoggerInterface;
use App\Models\AuditTrail;
use App\Models\LedgerEntry;
use App\Services\Settings\AppSettings;
use Core\Database;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/** Fast financial invariants; real wallet/escrow/ledger flows live under Integration/Financial and E2E. */
final class FinancialIntegrityTest extends TestCase
{
    use CreatesTypedMockeryMocks;
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    public function test_ledger_rejects_empty_transaction_identity_before_persistence(): void
    {
        $db=m::mock(Database::class);$db->shouldNotReceive('insert');
        $ledger=new LedgerEntry($db);
        $this->expectException(\InvalidArgumentException::class);
        $ledger->createEntry(['transaction_id'=>'']);
    }

    public function test_audit_chain_verifier_accepts_linked_rows_and_reports_fork(): void
    {
        $zero=str_repeat('0',64);$first=str_repeat('a',64);$second=str_repeat('b',64);
        $db=m::mock(Database::class);
        $db->shouldReceive('fetchAll')->twice()->andReturn(
            [(object)['id'=>1,'event'=>'one','prev_hash'=>$zero,'hash'=>$first],(object)['id'=>2,'event'=>'two','prev_hash'=>$first,'hash'=>$second]],
            [(object)['id'=>1,'event'=>'one','prev_hash'=>$zero,'hash'=>$first],(object)['id'=>2,'event'=>'two','prev_hash'=>$zero,'hash'=>$second]]
        );
        $model=new AuditTrail($db);
        $valid=$model->verifyChainIntegrity();
        $this->assertTrue((bool)$valid['success']);
        $this->assertSame(2,$valid['checked_count']);
        $fork=$model->verifyChainIntegrity();
        $this->assertFalse((bool)$fork['success']);
        $errors = $fork['errors'] ?? null;
        $this->assertIsArray($errors);
        $firstError = $errors[0] ?? null;
        $this->assertIsArray($firstError);
        $this->assertSame('prev_hash_mismatch',$firstError['type']);
    }

    public function test_reward_webhook_hmac_is_validated_and_fails_closed(): void
    {
        require_once dirname(__DIR__,2).'/app/Adapters/AdVideoRewardManager.php';
        global $configOverrides;
        $previous=is_array($configOverrides??null)?$configOverrides:[];
        try{
            $settings=m::mock(AppSettings::class);$settings->shouldReceive('get')->andReturn('');
            $adapter=new TapsellVideoRewardAdapter($settings,$this->lenientMock(LoggerInterface::class));
            $payload=['user_id'=>7,'reward'=>'1'];
            $configOverrides=['services'=>['tapsell'=>['webhook_secret'=>'test-secret']]];
            $encoded=json_encode($payload,JSON_UNESCAPED_UNICODE);$this->assertIsString($encoded);$signature=hash_hmac('sha256',$encoded,'test-secret');
            $this->assertTrue($adapter->verifyS2SHmac($payload,$signature));
            $this->assertFalse($adapter->verifyS2SHmac($payload,'wrong'));
            $configOverrides=['services'=>['tapsell'=>['webhook_secret'=>'']]];
            $this->assertFalse($adapter->verifyS2SHmac($payload,$signature));
        }finally{$configOverrides=$previous;}
    }
}
