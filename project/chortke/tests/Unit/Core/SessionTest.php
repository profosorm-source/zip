<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Core\Session;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SessionTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $originalServer;

    protected function setUp(): void
    {
        global $env;

        ob_start();

        $this->originalServer = $_SERVER;

        $_SERVER = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'PHPUnit',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US',
            'REQUEST_URI' => '/test',
            'SCRIPT_NAME' => '/index.php',
        ];

        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SESSION = [];

        $env = array_merge($env ?? [], [
            'APP_ENV' => 'local',
            'APP_URL' => 'http://localhost',
            'REDIS_ENABLED' => 'false',
            'SESSION_LIFETIME' => '7200',
            'APP_DEBUG' => 'true',
            'TRUSTED_PROXIES' => '127.0.0.1',
        ]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->resetSessionSingleton();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        $_SERVER = $this->originalServer;
        $_SESSION = [];
        $this->resetSessionSingleton();
    }

    public function testFileFallbackSessionStartAndSetGet(): void
    {
        $session = Session::getInstance();
        $session->ensureStarted();

        $session->set('foo', 'bar');

        $this->assertTrue($session->has('foo'));
        $this->assertSame('bar', $session->get('foo'));
    }

    public function testFlashOldRemovesSensitiveFields(): void
    {
        $_POST = [
            'name' => 'ali',
            'email' => 'ali@example.com',
            'password' => 'secret',
            '_token' => 'csrf',
            'csrf_token' => 'csrf2',
            'old_password' => 'old'
        ];

        $session = Session::getInstance();
        $session->ensureStarted();
        $session->flashOld();

        $this->assertSame('ali', $session->getOld('name'));
        $this->assertNull($session->getOld('password'));
        $this->assertNull($session->getOld('_token'));
        $this->assertNull($session->getOld('csrf_token'));
        $this->assertNull($session->getOld('old_password'));
    }

    public function testFlashGettersRemoveValuesAfterRead(): void
    {
        $session = Session::getInstance();
        $session->ensureStarted();

        $session->setFlash('notice', 'Hello');

        $this->assertTrue($session->hasFlash('notice'));
        $this->assertSame('Hello', $session->getFlash('notice'));
        $this->assertFalse($session->hasFlash('notice'));
    }

    public function testRegenerateChangesSessionId(): void
    {
        $session = Session::getInstance();
        $session->ensureStarted();

        $currentId = $session->getId();
        $session->regenerate();

        $this->assertNotSame($currentId, $session->getId());
    }

    public function testDestroyByIdRejectsInvalidSessionId(): void
    {
        $session = Session::getInstance();

        $this->assertFalse($session->destroyById(''));
        $this->assertFalse($session->destroyById('../etc/passwd'));
        $this->assertFalse($session->destroyById('sess id with spaces'));
    }

    public function testDestroyByIdRemovesPersistedFileSession(): void
    {
        $sessionId = 'phpunit' . bin2hex(random_bytes(8));
        $sessionsDir = dirname(__DIR__, 3) . '/storage/sessions';
        if (!is_dir($sessionsDir)) {
            mkdir($sessionsDir, 0700, true);
        }
        $path = $sessionsDir . '/sess_' . $sessionId;
        file_put_contents($path, 'user_id|i:42;');

        $session = Session::getInstance();
        $this->assertTrue($session->destroyById($sessionId));
        $this->assertFalse(is_file($path));
    }

    public function testDestroyByIdOnCurrentSessionClearsCurrentPayload(): void
    {
        $session = Session::getInstance();
        $session->ensureStarted();
        $session->set('foo', 'bar');

        $currentId = $session->getId();
        $this->assertNotSame('', $currentId);
        $this->assertTrue($session->destroyById($currentId));
        $this->assertFalse($session->has('foo'));
    }

    private function resetSessionSingleton(): void
    {
        $reflection = new \ReflectionClass(Session::class);
        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
