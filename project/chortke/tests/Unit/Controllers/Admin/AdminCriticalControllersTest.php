<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Admin;

use PHPUnit\Framework\TestCase;

/**
 * @group architecture
 */
class AdminCriticalControllersTest extends TestCase
{
    public function testAdminAuthControllerExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\Admin\AuthController::class));
        $ref = new \ReflectionClass(\App\Controllers\Admin\AuthController::class);
        // Admin AuthController extends BaseController (not BaseAdminController) — login page doesn't need admin auth
        $this->assertTrue($ref->isSubclassOf(\App\Controllers\BaseController::class));
    }

    public function testAdminAuthControllerNormalizesIpv6ToIts64BitPrefix(): void
    {
        $controller = (new \ReflectionClass(\App\Controllers\Admin\AuthController::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'normalizeIp');
        $method->setAccessible(true);

        $this->assertSame('2001:db8:abcd:12::', $method->invoke($controller, '2001:db8:abcd:12:1234:5678:9abc:def0'));
        $this->assertSame('192.0.2.10', $method->invoke($controller, '192.0.2.10'));
    }

    public function testWithdrawalControllerExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\Admin\WithdrawalController::class));
        $this->assertTrue(
            method_exists(\App\Controllers\Admin\WithdrawalController::class, 'index'),
            'Admin WithdrawalController باید index داشته باشه'
        );
    }

    public function testTransactionControllerExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\Admin\TransactionController::class));
    }

    public function testUserControllerExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\Admin\UserController::class));
    }

    public function testSentryAdminControllerExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\Admin\SentryAdminController::class));
    }

    public function testFraudControllerExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\Admin\FraudController::class));
    }

    public function testInvestmentControllerExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\Admin\InvestmentController::class));
    }

    public function testKYCControllerExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\Admin\KYCController::class));
    }

    public function testVitrineControllerInjectsListingModel(): void
    {
        $ctor = (new \ReflectionClass(\App\Controllers\Admin\VitrineController::class))->getConstructor();
        $this->assertNotNull($ctor);
        $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $ctor->getParameters());
        $this->assertContains('listingModel', $names);
    }

    public function testGetUserDetailsReadsStdClassUserFromUserService(): void
    {
        $controller = (new \ReflectionClass(\App\Controllers\Admin\AccountDeletionManagementController::class))
            ->newInstanceWithoutConstructor();

        $request = \Mockery::mock(\Core\Request::class);
        $request->shouldReceive('int')->with('user_id')->andReturn(7);

        $response = new \Core\Response();

        $logger = \Mockery::mock(\Core\Logger::class);
        $logger->shouldReceive('warning')->once();

        $userService = \Mockery::mock(\App\Services\User\UserService::class);
        $userService->shouldReceive('find')->once()->with(7)->andReturn((object)[
            'id' => 7,
            'email' => 'ali@example.com',
            'mobile' => '09121234567',
            'national_id' => '1234567890',
            'full_name' => 'Ali Test',
            'username' => 'ali',
            'created_at' => '2026-01-01 00:00:00',
            'last_activity_at' => '2026-01-02 00:00:00',
        ]);

        $deletionLog = \Mockery::mock(\App\Models\AccountDeletionLog::class);
        $deletionLog->shouldReceive('getUserDeletionRequest')->once()->with(7)->andReturn(null);

        $this->setControllerProperty($controller, 'request', $request);
        $this->setControllerProperty($controller, 'response', $response);
        $this->setControllerProperty($controller, 'logger', $logger);
        $this->setControllerProperty($controller, 'userService', $userService);
        $this->setControllerProperty($controller, 'deletionLogModel', $deletionLog);

        try {
            $controller->getUserDetails();
            $this->fail('HttpResponseException was expected');
        } catch (\Core\Exceptions\HttpResponseException $e) {
            $payload = json_decode($e->getResponse()->getContent(), true);
        }

        $this->assertIsArray($payload);
        $this->assertTrue($payload['success']);
        $this->assertSame(7, $payload['user']['id']);
        $this->assertSame('ali', $payload['user']['username']);
        $this->assertSame('a***@example.com', $payload['user']['email']);
        $this->assertSame('0912***67', $payload['user']['mobile']);
        $this->assertNull($payload['deletion']);
    }

    private function setControllerProperty(object $controller, string $name, mixed $value): void
    {
        $ref = new \ReflectionClass($controller);
        while ($ref instanceof \ReflectionClass && !$ref->hasProperty($name)) {
            $ref = $ref->getParentClass();
        }
        $this->assertInstanceOf(\ReflectionClass::class, $ref);
        $property = $ref->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($controller, $value);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
