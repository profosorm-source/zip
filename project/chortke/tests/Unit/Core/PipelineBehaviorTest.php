<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Core\Application;
use Core\Pipeline;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PipelineBehaviorTest extends TestCase
{
    public function test_object_and_callable_middleware_execute_in_declared_order(): void
    {
        $execution = [];
        $first = new PipelineRecordingMiddleware('first', $execution);
        $second = static function (string $value, callable $next) use (&$execution): string {
            $execution[] = 'second-before';
            $result = $next($value . '-second');
            $execution[] = 'second-after';
            return $result;
        };

        $result = (new Pipeline(Application::getInstance()->container))
            ->send('start')
            ->through([$first, $second])
            ->then(static function (string $value) use (&$execution): string {
                $execution[] = 'destination';
                return $value . '-done';
            });

        $this->assertSame('start-first-second-done', $result);
        $this->assertSame(
            ['first-before', 'second-before', 'destination', 'second-after', 'first-after'],
            $execution
        );
    }

    public function test_class_string_middleware_receives_trimmed_parameters(): void
    {
        $result = (new Pipeline(Application::getInstance()->container))
            ->send('start')
            ->through([PipelineParameterMiddleware::class . ': one, two '])
            ->then(static fn(string $value): string => $value);

        $this->assertSame('start-one-two', $result);
    }

    public function test_then_before_send_fails_closed(): void
    {
        $this->expectException(LogicException::class);
        (new Pipeline(Application::getInstance()->container))
            ->through([])
            ->then(static fn(): string => 'unreachable');
    }

    /**
     * @dataProvider invalidPipeProvider
     * @param mixed $pipe
     */
    public function test_invalid_pipe_is_rejected_at_pipeline_boundary($pipe): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Pipeline(Application::getInstance()->container))->through([$pipe]);
    }

    /** @return array<string, array{0: mixed}> */
    public function invalidPipeProvider(): array
    {
        return [
            'empty string' => ['   '],
            'integer' => [42],
            'array not callable' => [['not', 'callable']],
        ];
    }

    public function test_missing_middleware_class_fails_before_destination(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');
        (new Pipeline(Application::getInstance()->container))
            ->send('start')
            ->through(['Tests\\MissingMiddleware'])
            ->then(static fn(string $value): string => $value);
    }
}

final class PipelineRecordingMiddleware
{
    /** @var list<string> */
    private array $execution;

    /** @param list<string> $execution */
    public function __construct(private string $name, array &$execution)
    {
        $this->execution =& $execution;
    }

    /** @return list<string> */
    public function execution(): array { return $this->execution; }

    public function handle(string $value, callable $next): string
    {
        $this->execution[] = $this->name . '-before';
        $result = $next($value . '-' . $this->name);
        $this->execution[] = $this->name . '-after';
        return $result;
    }
}

final class PipelineParameterMiddleware
{
    public function handle(string $value, callable $next, string $first, string $second): string
    {
        return $next($value . '-' . $first . '-' . $second);
    }
}
