<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * تست فاز C — Fat Job Audit + Dead Code Removal
 */
class FatJobAuditTest extends TestCase
{
    public function testDeadFatJobsRemoved(): void
    {
        $deadJobs = [
            'app/Jobs/Payment/CreateManualDepositJob.php',
            'app/Jobs/Payment/RejectManualDepositJob.php',
            'app/Jobs/User/PurchaseUserLevelJob.php',
            'app/Jobs/Influencer/RefundInfluencerOrderJob.php',
            'app/Jobs/Referral/ProcessModularReferralCommissionJob.php',
            // حذف‌شده در فاز پاکسازیِ کد مرده/رهاشده:
            'app/Jobs/KYC/SubmitKYCJob.php',
            'app/Jobs/KYC/VerifyKYCJob.php',
            'app/Jobs/KYC/RejectKYCJob.php',
            'app/Jobs/Dispute/AdminResolveDisputeJob.php',
            'app/Jobs/Dispute/ResolveDisputeByAgreementJob.php',
            'app/Jobs/Dispute/EscalateDisputeToAdminJob.php',
            'app/Jobs/Dispute/ProcessExpiredDisputesJob.php',
            'app/Jobs/Payment/ApproveManualDepositJob.php',
            'app/Jobs/Referral/DistributeMonthlyReferralRewardsJob.php',
            'app/Jobs/Withdrawal/RequestWithdrawalUserJob.php',
            'app/Jobs/SystemCleanupJob.php',
        ];

        $root = dirname(__DIR__, 3);
        foreach ($deadJobs as $file) {
            $this->assertFileNotExists($root . '/' . $file,
                "$file باید حذف شده باشه (dead code)");
        }
    }

    public function testManualDepositServiceHasCreateMethod(): void
    {
        // CreateManualDepositJob حذف شد — ManualDepositService.create جایگزینشه
        $this->assertTrue(
            method_exists(\App\Services\ManualDepositService::class, 'create'),
            'ManualDepositService باید create method داشته باشه'
        );
    }

    public function testManualDepositServiceHasRejectMethod(): void
    {
        // RejectManualDepositJob حذف شد — ManualDepositService.reject جایگزینشه
        $this->assertTrue(
            method_exists(\App\Services\ManualDepositService::class, 'reject'),
            'ManualDepositService باید reject method داشته باشه'
        );
    }
}
