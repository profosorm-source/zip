<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\User\LevelController;
use App\Services\User\UserLevelService;
use Core\Application;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class LevelIntegrationTest extends TestCase
{
    public function test_real_container_resolves_level_module(): void
    {
        $container = Application::getInstance()->container;
        \App\Providers\AppServiceProvider::register($container);

        $this->assertInstanceOf(
            UserLevelService::class,
            $container->make(UserLevelService::class)
        );
        $this->assertInstanceOf(
            LevelController::class,
            $container->make(LevelController::class)
        );
    }
}
