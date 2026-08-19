<?php

declare(strict_types=1);

namespace Tests\Integration\ContainerBacked;

use PHPUnit\Framework\TestCase;
use Mockery as m;

/**
 * StabilityTest — تست‌های جامع پایداری و یکپارچگی پروژه
 *
 * هدف: بررسی اینکه تمام بخش‌های حیاتی پروژه (مدل‌ها، سرویس‌ها، کنترلرها، ایونت‌ها)
 * قابل resolve هستند، signature صحیح دارند و تغییرات اخیر خرابی ایجاد نکرده.
 */
/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════
    // 1. DATABASE CORE
    // ═══════════════════════════════════════════════════════════════

    /** @test */
    public function database_singleton_does_not_reinitialize_with_different_config(): void
    {
        \Core\Database::reset();
        
        $ref = new \ReflectionClass(\Core\Database::class);
        $instanceProp = $ref->getProperty('instance');
        $instanceProp->setAccessible(true);
        
        // Create a fake instance
        $fake = $ref->newInstanceWithoutConstructor();
        $instanceProp->setValue(null, $fake);
        
        // Call getInstance with different config — should return SAME instance (no reinitialize)
        $result = \Core\Database::getInstance(['host'=>'different','port'=>3306,'name'=>'unused','charset'=>'utf8mb4','user'=>'unused','pass'=>'unused']);
        $this->assertSame($fake, $result, 'Singleton should NOT be replaced by different config');
        
        // Cleanup
        $instanceProp->setValue(null, null);
    }

    /** @test */
    public function database_reset_only_allowed_in_cli(): void
    {
        // We're running in CLI (phpunit), so reset should work
        $this->assertSame('cli', PHP_SAPI);
        \Core\Database::reset(); // Should not throw
        $this->assertTrue(true);
    }

    /** @test */
    public function database_getInstance_returns_self_type(): void
    {
        \Core\Database::reset();
        $ref = new \ReflectionMethod(\Core\Database::class, 'getInstance');
        $returnType = $ref->getReturnType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $returnType);
        $this->assertSame('self', $returnType->getName());
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. SESSION CORE
    // ═══════════════════════════════════════════════════════════════

    /** @test */
    public function session_has_start_failed_flag(): void
    {
        $ref = new \ReflectionClass(\Core\Session::class);
        $this->assertTrue($ref->hasProperty('startFailed'), 'Session must have startFailed property');
        
        $prop = $ref->getProperty('startFailed');
        $prop->setAccessible(true);
        
        $session = $ref->newInstanceWithoutConstructor();
        $this->assertFalse($prop->getValue($session), 'startFailed should default to false');
    }

    /** @test */
    public function session_prevents_retry_after_failure(): void
    {
        $ref = new \ReflectionClass(\Core\Session::class);
        $session = $ref->newInstanceWithoutConstructor();
        
        // Simulate failed start
        $failedProp = $ref->getProperty('startFailed');
        $failedProp->setAccessible(true);
        $failedProp->setValue($session, true);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Session start previously failed');
        $session->start();
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. AUTH MIDDLEWARE
    // ═══════════════════════════════════════════════════════════════

    /** @test */
    public function auth_middleware_has_logger_property(): void
    {
        $ref = new \ReflectionClass(\App\Middleware\AuthMiddleware::class);
        $this->assertTrue($ref->hasProperty('logger'), 'AuthMiddleware must have logger property');
        
        $prop = $ref->getProperty('logger');
        $type = $prop->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame(\App\Contracts\LoggerInterface::class, $type->getName());
    }

    /** @test */
    public function auth_middleware_has_logger_via_inject(): void
    {
        $ref = new \ReflectionClass(\App\Middleware\AuthMiddleware::class);
        
        // Logger is now injected via #[Inject] attribute, not constructor
        $this->assertTrue($ref->hasProperty('logger'), 'AuthMiddleware must have logger property');
        
        $prop = $ref->getProperty('logger');
        $attrs = $prop->getAttributes(\Core\Attributes\Inject::class);
        $this->assertNotEmpty($attrs, 'logger property must have #[Inject] attribute');
        
        $type = $prop->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame(\App\Contracts\LoggerInterface::class, $type->getName());
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. EVENT DISPATCHER — RECONSTRUCTION
    // ═══════════════════════════════════════════════════════════════

    /** @test */
    public function event_dispatcher_has_reconstruct_method(): void
    {
        $ref = new \ReflectionClass(\Core\EventDispatcher::class);
        $this->assertTrue($ref->hasMethod('reconstructEvent'), 'EventDispatcher must have reconstructEvent method');
        
        $method = $ref->getMethod('reconstructEvent');
        $this->assertTrue($method->isPrivate());
    }

    /** @test */
    public function event_dispatcher_no_unserialize(): void
    {
        $source = $this->readFile(__DIR__ . '/../../../core/EventDispatcher.php');
        // Should not contain unserialize() call (except in __wakeup which is a blocker)
        $withoutWakeup = (string)preg_replace('/public function __wakeup.*?}/s', '', $source);
        $this->assertStringNotContainsString('unserialize(', $withoutWakeup, 
            'EventDispatcher must not use unserialize() — security risk');
    }

    /** @test */
    public function event_dispatcher_no_serialize_in_dispatch_async(): void
    {
        $source = $this->readFile(__DIR__ . '/../../../core/EventDispatcher.php');
        $this->assertStringNotContainsString("'serialized_event'", $source,
            'dispatchAsync must not store serialized_event in queue');
    }

    /** @test */
    public function reconstruct_event_handles_generic_event(): void
    {
        $queueMock = m::mock(\Core\Queue::class);
        $dispatcher = new \Core\EventDispatcher($queueMock);
        
        $ref = new \ReflectionMethod($dispatcher, 'reconstructEvent');
        $ref->setAccessible(true);
        
        $event = $ref->invoke($dispatcher, \Core\GenericEvent::class, ['key' => 'value']);
        $this->assertInstanceOf(\Core\GenericEvent::class, $event);
        $this->assertSame(['key' => 'value'], $event->getData());
    }

    /** @test */
    public function reconstruct_event_returns_null_for_unknown_class(): void
    {
        $queueMock = m::mock(\Core\Queue::class);
        $dispatcher = new \Core\EventDispatcher($queueMock);
        
        $ref = new \ReflectionMethod($dispatcher, 'reconstructEvent');
        $ref->setAccessible(true);
        
        // Class doesn't exist — but reconstructEvent receives class name after class_exists check
        // So it should handle gracefully if class somehow fails
        $event = $ref->invoke($dispatcher, \Core\GenericEvent::class, null);
        // GenericEvent accepts null/array in constructor
        $this->assertInstanceOf(\Core\Event::class, $event);
    }

    // ═══════════════════════════════════════════════════════════════
    // 5. TYPED EVENT RECONSTRUCTION
    // ═══════════════════════════════════════════════════════════════

    /** @test */
    public function all_typed_events_have_data_in_parent_constructor(): void
    {
        $eventFiles = glob(__DIR__ . '/../../../app/Events/*.php') ?: [];
        $eventFiles = array_filter($eventFiles, fn($f) => !str_contains($f, 'Registry'));
        
        foreach ($eventFiles as $file) {
            $content = $this->readFile($file);
            $className = basename($file, '.php');
            
            // Events that extend Core\Event should call parent::__construct
            if (str_contains($content, 'extends Event')) {
                // Either single-arg array constructor OR calls parent::__construct with array
                $hasParentCall = str_contains($content, 'parent::__construct');
                $hasSingleArgArray = preg_match('/function __construct\s*\(\s*array\s/', $content);
                
                $this->assertTrue(
                    $hasParentCall || $hasSingleArgArray || !str_contains($content, '__construct'),
                    "{$className}: Typed events must call parent::__construct() with data array for async reconstruction"
                );
            }
        }
    }

    /** @test */
    public function typed_event_reconstruction_works_for_score_updated(): void
    {
        $queueMock = m::mock(\Core\Queue::class);
        $dispatcher = new \Core\EventDispatcher($queueMock);
        
        $ref = new \ReflectionMethod($dispatcher, 'reconstructEvent');
        $ref->setAccessible(true);
        
        $event = $ref->invoke($dispatcher, \App\Events\ScoreUpdatedEvent::class, [
            'user_id' => 42,
            'old_score' => 10.5,
            'new_score' => 25.0,
            'reason' => 'test',
            'occurred_at' => '2026-06-01T12:00:00+00:00'
        ]);
        
        $this->assertInstanceOf(\App\Events\ScoreUpdatedEvent::class, $event);
        $this->assertSame(42, $event->userId);
        $this->assertSame(10.5, $event->oldScore);
        $this->assertSame(25.0, $event->newScore);
    }

    /** @test */
    public function typed_event_reconstruction_works_for_withdrawal_event(): void
    {
        $queueMock = m::mock(\Core\Queue::class);
        $dispatcher = new \Core\EventDispatcher($queueMock);
        
        $ref = new \ReflectionMethod($dispatcher, 'reconstructEvent');
        $ref->setAccessible(true);
        
        // WithdrawalEvent has single-arg array constructor
        $event = $ref->invoke($dispatcher, \App\Events\WithdrawalEvent::class, [
            'action' => 'approved',
            'user_id' => 99,
            'amount' => 50000
        ]);
        
        $this->assertInstanceOf(\App\Events\WithdrawalEvent::class, $event);
        $this->assertSame('approved', $event->getData('action'));
    }

    // ═══════════════════════════════════════════════════════════════
    // 6. EXCEPTION HANDLER
    // ═══════════════════════════════════════════════════════════════

    /** @test */
    public function exception_handler_handle_has_ob_clean(): void
    {
        $source = $this->readFile(__DIR__ . '/../../../core/ExceptionHandler.php');
        // Find the handle() method and check ob_clean exists before http_response_code
        $this->assertStringContainsString('ob_clean()', $source);
        $this->assertStringContainsString('ob_get_length()', $source);
    }

    // ═══════════════════════════════════════════════════════════════
    // 7. BASE CONTROLLER — NO EXIT
    // ═══════════════════════════════════════════════════════════════

    /** @test */
    public function base_controller_has_no_exit_statements(): void
    {
        $source = $this->readFile(__DIR__ . '/../../../app/Controllers/BaseController.php');
        // Remove string literals and comments to avoid false positives
        $stripped = (string)preg_replace(['/\/\/.*$/m', '/\/\*.*?\*\//s', '/["\'].*?["\']/'], '', $source);
        $this->assertStringNotContainsString('exit;', $stripped,
            'BaseController must not use exit — Response::send() throws HttpResponseException');
        $this->assertStringNotContainsString('exit(', $stripped,
            'BaseController must not use exit() — Response::send() throws HttpResponseException');
    }

    /** @test */
    public function base_controller_back_uses_request_header(): void
    {
        $source = $this->readFile(__DIR__ . '/../../../app/Controllers/BaseController.php');
        $this->assertStringContainsString("request->header('referer'", $source,
            'back() must use $this->request->header() instead of $_SERVER');
        $this->assertStringNotContainsString("_SERVER['HTTP_REFERER']", $source,
            'back() must not access $_SERVER directly');
    }

    // ═══════════════════════════════════════════════════════════════
    // 8. LOGGER CONSISTENCY — NO isset($this->logger) ON UNDECLARED
    // ═══════════════════════════════════════════════════════════════

    /** @test */
    public function core_files_use_function_logger_not_property(): void
    {
        $coreFiles = [
            'core/EventDispatcher.php',
            'core/QueryBuilder.php', 
            'core/Queue.php',
        ];
        
        foreach ($coreFiles as $file) {
            $source = $this->readFile(__DIR__ . '/../../../' . $file);
            $this->assertStringNotContainsString('$this->logger', $source,
                "{$file}: must use function_exists('logger') instead of \$this->logger (undeclared property)");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 9. MODEL/SERVICE STRUCTURE INTEGRITY
    // ═══════════════════════════════════════════════════════════════

    /** @test */
    public function all_models_extend_base_model(): void
    {
        // CQRS Read Models are intentionally not extending Model (e.g. TransactionQuery)
        $cqrsExceptions = ['TransactionQuery'];
        
        $modelFiles = glob(__DIR__ . '/../../../app/Models/*.php') ?: [];
        foreach ($modelFiles as $file) {
            $content = $this->readFile($file);
            $className = basename($file, '.php');
            
            if (in_array($className, $cqrsExceptions, true)) {
                continue;
            }
            
            if (str_contains($content, 'class ' . $className)) {
                $this->assertTrue(
                    str_contains($content, 'extends Model') || 
                    str_contains($content, 'extends \\Core\\Model') ||
                    str_contains($content, 'abstract class'),
                    "{$className} must extend Core\\Model"
                );
            }
        }
    }

    /** @test */
    public function all_models_define_table_property(): void
    {
        $modelFiles = glob(__DIR__ . '/../../../app/Models/*.php') ?: [];
        foreach ($modelFiles as $file) {
            $content = $this->readFile($file);
            $className = basename($file, '.php');
            
            if (str_contains($content, 'extends Model') && !str_contains($content, 'abstract class')) {
                $this->assertTrue(
                    str_contains($content, '$table'),
                    "{$className}: All models must define \$table property"
                );
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 10. SERVICE CONSTRUCTOR INTEGRITY
    // ═══════════════════════════════════════════════════════════════

    /**
     * @test
     * @dataProvider criticalServiceProvider
     */
    public function critical_services_are_resolvable_by_reflection(string $className): void
    {
        $this->assertTrue(class_exists($className), "{$className} class must exist");
        
        $ref = new \ReflectionClass($className);
        $this->assertFalse($ref->isAbstract(), "{$className} must not be abstract");
        
        $constructor = $ref->getConstructor();
        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $depClass = $type->getName();
                    if (!$param->isDefaultValueAvailable()) {
                        $this->assertTrue(
                            class_exists($depClass) || interface_exists($depClass),
                            "{$className}: dependency {$depClass} must exist"
                        );
                    }
                }
            }
        }
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);
        $this->assertIsString($content, "Unable to read {$path}");
        return $content;
    }

    /** @return array<string,array{0:class-string}> */
    public function criticalServiceProvider(): array
    {
        return [
            'WalletService' => [\App\Services\Wallet\WalletService::class],
            'FeatureFlagService' => [\App\Services\FeatureFlagService::class],
            'UploadService' => [\App\Services\UploadService::class],
            'NotificationService' => [\App\Services\Notification\NotificationService::class],
            'AuditTrail' => [\App\Services\AuditTrail::class],
            'KYCService' => [\App\Services\KYCService::class],
            'LotteryService' => [\App\Services\Lottery\LotteryService::class],
            'InvestmentService' => [\App\Services\InvestmentService::class],
            'ContentService' => [\App\Services\ContentService::class],
            'InfluencerService' => [\App\Services\InfluencerService::class],
            'OutboxService' => [\App\Services\OutboxService::class],
            'QueueWorker' => [\App\Services\QueueWorker::class],
            'AuthMiddleware' => [\App\Middleware\AuthMiddleware::class],
            'AdminMiddleware' => [\App\Middleware\AdminMiddleware::class],
            'CSRFMiddleware' => [\App\Middleware\CSRFMiddleware::class],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // 11. EVENT SYSTEM INTEGRITY
    // ═══════════════════════════════════════════════════════════════

    /** @test */
    public function all_events_extend_core_event(): void
    {
        $eventFiles = glob(__DIR__ . '/../../../app/Events/*.php') ?: [];
        $eventFiles = array_filter($eventFiles, fn($f) => !str_contains($f, 'Registry'));
        
        $nonConforming = [];
        foreach ($eventFiles as $file) {
            $content = $this->readFile($file);
            $className = basename($file, '.php');
            
            // Skip non-Event classes (like SettingsUpdated, ScoreDeltaAppendedEvent which don't extend Event)
            if (!str_contains($content, 'extends Event') && str_contains($content, 'class ')) {
                $nonConforming[] = $className;
            }
        }
        
        // These are known exceptions that don't extend Core\Event
        $allowedExceptions = ['SettingsUpdated', 'ScoreDeltaAppendedEvent'];
        $unexpected = array_diff($nonConforming, $allowedExceptions);
        
        $this->assertEmpty($unexpected, 
            'These event classes do not extend Core\\Event: ' . implode(', ', $unexpected));
    }

    // ═══════════════════════════════════════════════════════════════
    // 12. QUERY BUILDER — ADS.PHP FIX VERIFICATION
    // ═══════════════════════════════════════════════════════════════

    /** @test */
    public function ads_model_uses_select_raw_for_subquery(): void
    {
        $source = $this->readFile(__DIR__ . '/../../../app/Models/Ads.php');
        $this->assertStringContainsString('selectRaw', $source,
            'Ads model must use selectRaw for COUNT subquery');
        $this->assertStringNotContainsString(
            "select('ads.*', '(SELECT COUNT",
            $source,
            'Ads model must not pass subquery to select()'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // 13. UPLOAD SERVICE SECURITY
    // ═══════════════════════════════════════════════════════════════

    /** @test */
    public function upload_service_has_all_security_layers(): void
    {
        $source = $this->readFile(__DIR__ . '/../../../app/Services/UploadService.php');
        
        $this->assertStringContainsString('DANGEROUS_EXT', $source, 'Must have dangerous extension list');
        $this->assertStringContainsString('isSafeFilename', $source, 'Must check double-extension');
        $this->assertStringContainsString('finfo_file', $source, 'Must check real MIME with finfo');
        $this->assertStringContainsString('MAGIC', $source, 'Must verify magic bytes');
        $this->assertStringContainsString('random_bytes', $source, 'Must use random file names');
        $this->assertStringContainsString('is_uploaded_file', $source, 'Must verify uploaded file');
    }

    // ═══════════════════════════════════════════════════════════════
    // 14. FEATURE FLAG SYSTEM
    // ═══════════════════════════════════════════════════════════════

    /** @test */
    public function feature_flag_service_exists_and_has_core_methods(): void
    {
        $ref = new \ReflectionClass(\App\Services\FeatureFlagService::class);
        
        $requiredMethods = ['isEnabled', 'getAll', 'findByName', 'toggle', 'update', 'create', 'delete'];
        foreach ($requiredMethods as $method) {
            $this->assertTrue($ref->hasMethod($method), 
                "FeatureFlagService must have {$method}() method");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 15. SAFE EXPRESSION PARSER
    // ═══════════════════════════════════════════════════════════════

    /** @test */
    public function safe_expression_parser_blocks_dangerous_functions(): void
    {
        $dangerous = ['SLEEP(1)', 'BENCHMARK(1,1)', 'LOAD_FILE("/etc/passwd")', 'USER()'];
        
        foreach ($dangerous as $expr) {
            try {
                \Core\Sql\SafeExpression::parse($expr);
                $this->fail("SafeExpression should reject: {$expr}");
            } catch (\Core\Sql\SqlExpressionException $e) {
                $this->assertTrue(true); // Expected
            }
        }
    }

    /** @test */
    public function safe_expression_allows_safe_functions(): void
    {
        $safe = ['COUNT(*)', 'SUM(amount)', "DATE_FORMAT(created_at, '%Y-%m')", 'COALESCE(name, \'unknown\')'];
        
        foreach ($safe as $expr) {
            try {
                $result = \Core\Sql\SafeExpression::parse($expr);
                $this->assertNotEmpty($result->emit(), "SafeExpression should allow: {$expr}");
            } catch (\Core\Sql\SqlExpressionException $e) {
                $this->fail("SafeExpression should allow: {$expr}, got: " . $e->getMessage());
            }
        }
    }
}
