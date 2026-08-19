<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Core\Container;

class ContainerIntegrityTest extends TestCase
{
    public function test_all_container_bindings_exist(): void
    {
        // Load bootstrap to register bindings without running the app
        require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
        
        $container = Container::getInstance();
        $bindings = $container->getBindings();

        $missingBindings = [];

        foreach ($bindings as $abstract) {
            // Some bindings might be simple strings, but if it looks like a class/interface name, verify it
            if (str_contains($abstract, '\\') || class_exists($abstract) || interface_exists($abstract)) {
                if (!class_exists($abstract) && !interface_exists($abstract)) {
                    $missingBindings[] = $abstract;
                }
            }
        }

        $this->assertEmpty(
            $missingBindings,
            "Integrity Failure: The following bindings are registered in bootstrap/app.php but do not exist in the codebase:\n- " . implode("\n- ", $missingBindings)
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_object_binding_with_incompatible_contract_fails_closed(): void
    {
        $container = Container::getInstance();
        $container->bind(ContainerFixtureContract::class, new \stdClass());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('incompatible type');
        $container->make(ContainerFixtureContract::class);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_compatible_prebuilt_instance_preserves_identity(): void
    {
        $container = Container::getInstance();
        $fixture = new ContainerFixture();
        $container->instance(ContainerFixtureContract::class, $fixture);

        $this->assertSame($fixture, $container->make(ContainerFixtureContract::class));
    }
}

interface ContainerFixtureContract
{
}

final class ContainerFixture implements ContainerFixtureContract
{
}
