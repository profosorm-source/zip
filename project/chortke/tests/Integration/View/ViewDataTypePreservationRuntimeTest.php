<?php

declare(strict_types=1);

namespace Tests\Integration\View;

use PHPUnit\Framework\TestCase;

/**
 * Behavioral contract for the real view() rendering boundary.
 * The helper may extract variables, but must never coerce their types.
 */
final class ViewDataTypePreservationRuntimeTest extends TestCase
{
    private string $fixtureDirectory;
    private string $fixtureFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDirectory = base_path('views/__runtime_contract');
        $this->fixtureFile = $this->fixtureDirectory . '/type-preservation.php';
        if (!is_dir($this->fixtureDirectory) && !mkdir($this->fixtureDirectory, 0770, true) && !is_dir($this->fixtureDirectory)) {
            $this->fail('Unable to create temporary view fixture directory.');
        }
        $fixture = <<<'PHP'
<?php
echo json_encode([
    'object_type' => get_debug_type($objectPayload),
    'object_id' => spl_object_id($objectPayload),
    'object_value' => $objectPayload->name,
    'array_type' => get_debug_type($arrayPayload),
    'array_value' => $arrayPayload['name'],
    'nested_object_type' => get_debug_type($nestedPayload['object']),
    'nested_object_id' => spl_object_id($nestedPayload['object']),
], JSON_THROW_ON_ERROR);
PHP;
        if (file_put_contents($this->fixtureFile, $fixture) === false) {
            $this->fail('Unable to write temporary view fixture.');
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->fixtureFile)) {
            unlink($this->fixtureFile);
        }
        if (is_dir($this->fixtureDirectory)) {
            rmdir($this->fixtureDirectory);
        }
        unset($GLOBALS['_last_view_helper_echo_hash']);
        parent::tearDown();
    }

    public function test_view_boundary_preserves_object_array_and_nested_identity(): void
    {
        $object = (object)['name' => 'runtime-object'];
        $array = ['name' => 'runtime-array'];
        $nestedObject = (object)['name' => 'nested-object'];
        $nested = ['object' => $nestedObject];

        ob_start();
        $returnValue = view('__runtime_contract.type-preservation', [
            'objectPayload' => $object,
            'arrayPayload' => $array,
            'nestedPayload' => $nested,
        ]);
        $rendered = ob_get_clean();

        $this->assertSame('', $returnValue);
        $this->assertIsString($rendered);
        $payload = json_decode($rendered, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertSame('stdClass', $payload['object_type'] ?? null);
        $this->assertSame(spl_object_id($object), $payload['object_id'] ?? null);
        $this->assertSame('runtime-object', $payload['object_value'] ?? null);
        $this->assertSame('array', $payload['array_type'] ?? null);
        $this->assertSame('runtime-array', $payload['array_value'] ?? null);
        $this->assertSame('stdClass', $payload['nested_object_type'] ?? null);
        $this->assertSame(spl_object_id($nestedObject), $payload['nested_object_id'] ?? null);
        $this->assertSame('runtime-object', $object->name);
        $this->assertSame('runtime-array', $array['name']);
    }
}
