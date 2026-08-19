<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\Support\CreatesTypedMockeryMocks;
use App\Contracts\LoggerInterface;
use App\Models\Dispute;
use App\Models\InfluencerModel;
use App\Models\KYCVerification;
use App\Services\Dispute\DisputeQueryService;
use App\Services\Influencer\InfluencerQueryService;
use App\Services\KYC\KYCQueryService;
use Core\Database;
use Core\Encryption;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/** Behavioral read-side contracts for split services; no source inspection. */
final class SplitServicesTest extends TestCase
{
    use CreatesTypedMockeryMocks;
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    public function test_influencer_search_forces_verified_status_and_maps_sort(): void
    {
        $model=m::mock(InfluencerModel::class);
        $model->shouldReceive('searchNative')->once()->with(
            '',m::on(static fn(array $f): bool=>($f['status']??'')==='verified'&&($f['sort']??'')==='followers'),10,0,'follower_count','DESC'
        )->andReturn(['items'=>[(object)['id'=>1]],'total'=>1]);
        $result=(new InfluencerQueryService($model))->searchInfluencers(['sort'=>'followers'],10,0);
        $this->assertSame(1,$result['total']);
        $this->assertSame(1,$result['items'][0]->id);
    }

    public function test_dispute_query_delegates_safe_find_messages_and_counts(): void
    {
        $model=m::mock(Dispute::class);
        $model->shouldReceive('getSafe')->once()->with(42)->andReturn((object)['id'=>42,'status'=>'open']);
        $model->shouldReceive('getMessages')->once()->with(42)->andReturn([(object)['id'=>7]]);
        $model->shouldReceive('countByUser')->once()->with(9)->andReturn(3);
        $service=new DisputeQueryService(m::mock(Database::class),$model);
        $this->assertSame(42,$service->find(42)?->id);
        $this->assertSame(7,$service->getMessages(42)[0]->id);
        $this->assertSame(3,$service->countUserDisputes(9));
    }

    public function test_kyc_image_deletion_requires_existing_record_and_updates_status(): void
    {
        $model=m::mock(KYCVerification::class);
        $model->shouldReceive('find')->once()->with(8)->andReturn((object)['id'=>8]);
        $model->shouldReceive('updateImageStatusToDeleted')->once()->with(8)->andReturn(true);
        $service=new KYCQueryService($this->lenientMock(LoggerInterface::class),m::mock(Database::class),$model,m::mock(Encryption::class));
        $this->assertTrue($service->deleteVerificationImage(8));
    }

    /** @dataProvider kycSubmissionStates */
    public function test_kyc_submission_eligibility_follows_persisted_status(?string $status,bool $expected): void
    {
        $model=m::mock(KYCVerification::class);
        $model->shouldReceive('findByUserId')->once()->with(5)->andReturn($status===null?null:(object)['status'=>$status]);
        $service=new KYCQueryService($this->lenientMock(LoggerInterface::class),m::mock(Database::class),$model,m::mock(Encryption::class));
        $result=$service->canSubmitKYC(5);
        $this->assertSame($expected,(bool)$result['can']);
        if(!$expected)$this->assertArrayHasKey('reason',$result);
    }

    /** @return list<array{?string,bool}> */
    public function kycSubmissionStates(): array
    {
        return [[null,true],['verified',false],['pending',false],['under_review',false],['rejected',true]];
    }
}
