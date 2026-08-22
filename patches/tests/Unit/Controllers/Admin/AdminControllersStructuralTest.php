<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use App\Controllers\BaseController;

/**
 * Structural + security tests for ALL Admin controllers
 */
/**
 * @group architecture
 */
class AdminControllersStructuralTest extends TestCase
{
    /** @return list<string> */
    private function adminControllers(): array
    {
        return [
            'App\Controllers\Admin\AccountDeletionManagementController',
            'App\Controllers\Admin\AdTaskController',
            'App\Controllers\Admin\AdminAnalyticsController',
            'App\Controllers\Admin\AdminExportController',
            'App\Controllers\Admin\ApiTokenAdminController',
            'App\Controllers\Admin\AuditTrailController',
            'App\Controllers\Admin\AuthController',
            'App\Controllers\Admin\BackupManagementController',
            'App\Controllers\Admin\BankCardController',
                        'App\Controllers\Admin\BugReportController',
            'App\Controllers\Admin\CacheAdminController',
            'App\Controllers\Admin\ContentController',
            'App\Controllers\Admin\CouponController',
            'App\Controllers\Admin\CronController',
            'App\Controllers\Admin\CryptoDepositController',
                        'App\Controllers\Admin\DashboardController',
            'App\Controllers\Admin\EmailQueueController',
            'App\Controllers\Admin\ExecutorTaskController',
            'App\Controllers\Admin\FeatureFlagController',
            'App\Controllers\Admin\FraudController',
            'App\Controllers\Admin\FraudDashboardController',
            'App\Controllers\Admin\FraudManagementController',
            'App\Controllers\Admin\InfluencerController',
            'App\Controllers\Admin\InvestmentController',
            'App\Controllers\Admin\KYCController',
            'App\Controllers\Admin\KpiController',
            'App\Controllers\Admin\LevelController',
            'App\Controllers\Admin\LogController',
            'App\Controllers\Admin\LotteryController',
            'App\Controllers\Admin\MaintenanceController',
            'App\Controllers\Admin\ManualDepositController',
            'App\Controllers\Admin\MessageModerationController',
            'App\Controllers\Admin\NotificationController',
            'App\Controllers\Admin\OnlinePaymentController',
            'App\Controllers\Admin\PredictionController',
            'App\Controllers\Admin\ReferralController',
                        'App\Controllers\Admin\RiskPolicyController',
            'App\Controllers\Admin\RoleController',
            'App\Controllers\Admin\ScoreManagementController',
            'App\Controllers\Admin\SentryAdminController',
            'App\Controllers\Admin\SeoAdController',
            'App\Controllers\Admin\SocialAccountController',
            'App\Controllers\Admin\SocialTaskController',
            'App\Controllers\Admin\LogController',
            'App\Controllers\Admin\SystemSettingController',
            'App\Controllers\Admin\TicketController',
            'App\Controllers\Admin\TransactionController',
            'App\Controllers\Admin\UserController',
            'App\Controllers\Admin\VitrineController',
            'App\Controllers\Admin\WithdrawalController',
        ];
    }

    // ── Structural ──────────────────────────────────────────

    /** @dataProvider controllerClassProvider */
    public function testControllerClassExists(string $class): void
    {
        $this->assertTrue(class_exists($class), "$class باید loadable باشه");
    }

    /** @dataProvider controllerClassProvider */
    public function testControllerExtendsBase(string $class): void
    {
        if (!class_exists($class)) {
            $this->fail("$class not loadable");
        }
        $ref = new \ReflectionClass($class);
        $short = $ref->getShortName();

        $this->assertTrue(
            $ref->isSubclassOf(BaseController::class),
            "$class باید BaseController extend کنه"
        );
    }

    /** @dataProvider controllerClassProvider */
    public function testControllerNotAbstract(string $class): void
    {
        if (!class_exists($class)) {
            $this->fail("$class not loadable");
        }
        $this->assertFalse((new \ReflectionClass($class))->isAbstract());
    }

    /** @dataProvider controllerClassProvider */
    public function testControllerHasConstructor(string $class): void
    {
        if (!class_exists($class)) {
            $this->fail("$class not loadable");
        }
        $this->assertNotNull((new \ReflectionClass($class))->getConstructor());
    }

    // ── Admin auth enforcement ──────────────────────────────

    /** @dataProvider controllerClassProvider */
    public function testAdminAuthEnforced(string $class): void
    {
        if (!class_exists($class)) {
            $this->fail("$class not loadable");
        }
        $ref = new \ReflectionClass($class);
        $short = $ref->getShortName();

        // AuthController is login page — no admin auth needed
        if ($short === 'AuthController') {
            // معافیت عمدی: صفحهٔ ورود نباید پشت auth ادمین باشد، وگرنه حلقهٔ ریدایرکت می‌شود.
            // اما معافیت باید صریح بماند: نباید از BaseAdminController ارث ببرد و
            // در عین حال باید یک کنترلر واقعی با والد BaseController باشد.
            $this->assertFalse(
                $ref->isSubclassOf('App\\Controllers\\Admin\\BaseAdminController'),
                "$class صفحهٔ ورود است و نباید BaseAdminController را گسترش دهد (حلقهٔ ریدایرکت)."
            );
            $this->assertTrue(
                $ref->isSubclassOf('App\\Controllers\\BaseController'),
                "$class باید دست‌کم BaseController را گسترش دهد."
            );
            return;
        }

        $this->assertTrue(
            $ref->isSubclassOf('App\Controllers\Admin\BaseAdminController'),
            "$class باید BaseAdminController را برای اجرای auth/admin middleware contract گسترش دهد"
        );
    }

    // ── Property visibility ─────────────────────────────────

    /** @dataProvider controllerClassProvider */
    public function testNoPrivatePropertyOverridesParentProtected(string $class): void
    {
        if (!class_exists($class)) {
            $this->fail("$class not loadable");
        }
        $ref = new \ReflectionClass($class);
        $parent = $ref->getParentClass();
        // هر کنترلر ادمینِ فهرست‌شده باید والد داشته باشد؛ نبودِ والد یعنی نقض
        // قرارداد ساختاری، نه یک موردِ قابل‌چشم‌پوشی.
        $this->assertNotFalse(
            $parent,
            "$class والد ندارد؛ کنترلرهای ادمین باید BaseAdminController (یا BaseController) را گسترش دهند."
        );

        $parentProtected = [];
        foreach ($parent->getProperties(\ReflectionProperty::IS_PROTECTED) as $prop) {
            $parentProtected[] = $prop->getName();
        }

        $bad = [];
        foreach ($ref->getProperties(\ReflectionProperty::IS_PRIVATE) as $prop) {
            if ($prop->getDeclaringClass()->getName() === $class
                && in_array($prop->getName(), $parentProtected, true)) {
                $bad[] = $prop->getName();
            }
        }

        $this->assertEmpty(
            $bad,
            "$class private override: " . implode(', ', $bad)
        );
    }

    // ── Critical admin controllers have proper methods ──────

    public function testWithdrawalControllerHasCriticalMethods(): void
    {
        $ref = new \ReflectionClass('App\Controllers\Admin\WithdrawalController');
        $this->assertTrue($ref->hasMethod('index'));
        $this->assertTrue($ref->hasMethod('review'));
        $this->assertTrue($ref->hasMethod('process'));
        $this->assertTrue($ref->hasMethod('reject'));
    }

    public function testTransactionControllerHasMethods(): void
    {
        $ref = new \ReflectionClass('App\Controllers\Admin\TransactionController');
        $this->assertTrue($ref->hasMethod('index'));
        $this->assertTrue($ref->hasMethod('show'));
    }

    public function testUserControllerHasCrudMethods(): void
    {
        $ref = new \ReflectionClass('App\Controllers\Admin\UserController');
        foreach (['index', 'create', 'store', 'edit', 'update', 'delete', 'ban', 'suspend'] as $m) {
            $this->assertTrue($ref->hasMethod($m), "UserController.$m missing");
        }
    }

    public function testFraudControllerHasMethods(): void
    {
        $ref = new \ReflectionClass('App\Controllers\Admin\FraudController');
        $this->assertTrue($ref->hasMethod('getRiskReport'));
        $this->assertTrue($ref->hasMethod('recalculateScore'));
        $this->assertTrue($ref->hasMethod('getHighRiskUsers'));
    }

    public function testSentryAdminControllerHasMethods(): void
    {
        $ref = new \ReflectionClass('App\Controllers\Admin\SentryAdminController');
        foreach (['index', 'issues', 'issueDetails', 'failedJobs', 'performance', 'alerts', 'healthCheck'] as $m) {
            $this->assertTrue($ref->hasMethod($m), "SentryAdminController.$m missing");
        }
    }

    public function testInvestmentControllerHasTrading(): void
    {
        $ref = new \ReflectionClass('App\Controllers\Admin\InvestmentController');
        $this->assertTrue($ref->hasMethod('trades'));
        $this->assertTrue($ref->hasMethod('tradeClose'));
        $this->assertTrue($ref->hasMethod('applyProfit'));
        $this->assertTrue($ref->hasMethod('solvencyReport'));
    }

    public function testKYCControllerHasReview(): void
    {
        $ref = new \ReflectionClass('App\Controllers\Admin\KYCController');
        $this->assertTrue($ref->hasMethod('index'));
        $this->assertTrue($ref->hasMethod('review'));
        $this->assertTrue($ref->hasMethod('verify'));
        $this->assertTrue($ref->hasMethod('reject'));
    }

    // ── Provider ────────────────────────────────────────────

    /** @return array<string,array{0:string}> */
    public function controllerClassProvider(): array
    {
        $result = [];
        foreach ($this->adminControllers() as $class) {
            $short = substr($class, strrpos($class, '\\') + 1);
            $result[$short] = [$class];
        }
        return $result;
    }
}
