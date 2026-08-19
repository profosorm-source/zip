<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Core\Application;
use Core\Console\CliDispatcher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CliDispatcherBehaviorTest extends TestCase
{
    public function test_registered_command_is_resolved_and_receives_argument_list(): void
    {
        $dispatcher = new CliDispatcher(Application::getInstance()->container);
        $dispatcher->register('fixture:run', CliCommandFixture::class, 'Fixture command');
        $this->expectOutputString("alpha|beta\n");

        $dispatcher->run(['cli.php', 'fixture:run', 'alpha', 'beta']);
    }

    public function test_help_uses_typed_command_metadata(): void
    {
        $dispatcher = new CliDispatcher(Application::getInstance()->container);
        $dispatcher->register('fixture:run', CliCommandFixture::class, 'Fixture command');
        $this->expectOutputRegex('/fixture:run\s+Fixture command/');

        $dispatcher->run(['cli.php', '--help']);
    }

    /**
     * @dataProvider invalidRegistrationProvider
     */
    public function test_invalid_registration_fails_during_composition(string $name, string $class): void
    {
        $dispatcher = new CliDispatcher(Application::getInstance()->container);
        $this->expectException(InvalidArgumentException::class);

        $dispatcher->register($name, $class);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public function invalidRegistrationProvider(): array
    {
        return [
            'invalid name' => ['Invalid Command', CliCommandFixture::class],
            'missing class' => ['fixture:missing', 'Tests\\MissingCliCommand'],
            'no public entry point' => ['fixture:hidden', CliCommandWithoutPublicEntryPoint::class],
        ];
    }
}

final class CliCommandFixture
{
    /** @param list<string> $args */
    public function execute(array $args): string
    {
        return implode('|', $args);
    }
}

final class CliCommandWithoutPublicEntryPoint
{
    public function __construct() { $this->execute(); }

    private function execute(): void
    {
    }
}
