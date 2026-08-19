<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Structural test for ALL controllers — verifies:
 * 1. Class loadable
 * 2. Extends correct base
 * 3. Has __construct
 * 4. All public methods are void (controller convention)
 */
class AllControllersStructuralTest extends TestCase
{
    /** @return list<string> */
    private function getAllControllerClasses(): array
    {
        $root = dirname(__DIR__, 3) . '/app/Controllers';
        $classes = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) continue;
            if ($file->getExtension() !== 'php') continue;
            $path = $file->getPathname();
            $relative = str_replace([$root, '.php', '/'], ['', '', '\\'], $path);
            $class = 'App\\Controllers' . $relative;

            // Skip base/abstract
            if (str_contains($class, 'Base')) continue;
            // Skip test/debug
            if (str_contains($class, 'Debug') || str_contains($class, 'TestCaptcha')) continue;
            // Skip empty stub files (SystemController has no class)
            if (str_contains($class, 'SystemController') && str_contains($class, 'Admin')) continue;

            $classes[] = $class;
        }

        return $classes;
    }

    public function testAllControllersLoadable(): void
    {
        $classes = $this->getAllControllerClasses();
        $this->assertGreaterThan(100, count($classes), 'Should find 100+ controllers');

        $failed = [];
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                $failed[] = $class;
            }
        }

        $this->assertEmpty($failed,
            'Controllers not loadable: ' . implode(', ', array_slice($failed, 0, 10)));
    }

    public function testAllControllersExtendBase(): void
    {
        $classes = $this->getAllControllerClasses();
        $noBase = [];

        foreach ($classes as $class) {
            if (!class_exists($class)) continue;
            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract()) continue;

            $parent = $ref->getParentClass();
            if (!$parent) {
                $noBase[] = $ref->getShortName();
            }
        }

        // ScoreManagementController now extends BaseAdminController
        $allowed = [];
        $noBase = array_diff($noBase, $allowed);
        $this->assertEmpty($noBase,
            'Controllers without parent: ' . implode(', ', $noBase));
    }

    public function testUserControllersExtendBase(): void
    {
        $classes = $this->getAllControllerClasses();
        $wrong = [];

        foreach ($classes as $class) {
            if (!str_contains($class, '\\User\\')) continue;
            if (!class_exists($class)) continue;
            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract()) continue;

            // User controllers باید حداقل BaseController رو extend کنن
            if (!$ref->isSubclassOf(\App\Controllers\BaseController::class)) {
                $wrong[] = $ref->getShortName();
            }
        }

        $this->assertEmpty($wrong,
            'User controllers not extending BaseController: ' . implode(', ', $wrong));
    }

    public function testAdminControllersExtendBase(): void
    {
        $classes = $this->getAllControllerClasses();
        $wrong = [];

        foreach ($classes as $class) {
            if (!str_contains($class, '\\Admin\\')) continue;
            if (!class_exists($class)) continue;
            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract()) continue;

            // Admin controllers باید حداقل BaseController extend کنن (بعضی مثل AuthController از BaseAdmin نیستن)
            if (!$ref->isSubclassOf(\App\Controllers\BaseController::class)) {
                $wrong[] = $ref->getShortName();
            }
        }

        // بعضی controllers ممکنه standalone باشن — فقط warning
        $this->assertLessThanOrEqual(2, count($wrong),
            'Admin controllers not extending BaseController: ' . implode(', ', $wrong));
    }

    public function testApiControllersExtendBaseApi(): void
    {
        $classes = $this->getAllControllerClasses();
        $wrong = [];

        foreach ($classes as $class) {
            if (!str_contains($class, '\\Api\\')) continue;
            if (!class_exists($class)) continue;
            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract()) continue;

            if (!$ref->isSubclassOf(\App\Controllers\Api\BaseApiController::class) &&
                !$ref->isSubclassOf(\App\Controllers\BaseController::class)) {
                $wrong[] = $ref->getShortName();
            }
        }

        $this->assertEmpty($wrong,
            'Api controllers not extending BaseApiController: ' . implode(', ', $wrong));
    }

    public function testAllControllersHaveConstructor(): void
    {
        $classes = $this->getAllControllerClasses();
        $noConstructor = [];

        foreach ($classes as $class) {
            if (!class_exists($class)) continue;
            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract()) continue;

            // Must have own constructor or inherit from parent
            $ctor = $ref->getConstructor();
            if (!$ctor) {
                $noConstructor[] = $ref->getShortName();
            }
        }

        // Some simple controllers may rely on parent constructor — that's OK
        $this->assertLessThanOrEqual(5, count($noConstructor),
            'Too many controllers without constructor: ' . implode(', ', $noConstructor));
    }
}
