<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Application;
use Core\Container;
use Core\Session;
use Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class HomePageTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalServer;

    protected function setUp(): void
    {
        ini_set('error_log', sys_get_temp_dir() . '/chortke-homepage-integration.log');
        global $env;

        $this->originalServer = $_SERVER;

        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        if (!defined('VIEW_PATH')) {
            define('VIEW_PATH', BASE_PATH . '/views');
        }

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SCRIPT_NAME' => '/index.php',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        $_GET = [];
        $_POST = [];
        $_SESSION = [];

        require BASE_PATH . '/routes/routes.php';
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $_SERVER = $this->originalServer;
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SESSION = [];
    }

    public function testHomePageReturnsRenderedHtml(): void
    {
        $app = Application::getInstance();

        ob_start();
        $app->run();
        $content = ob_get_clean();

        $this->assertNotEmpty($content, 'Home page output should not be empty.');
        $this->assertStringContainsString('چرتکه', $content);
        $this->assertStringContainsString('<section', $content);
    }
}
