<?php

declare(strict_types=1);

namespace Core\Console;

use Core\Container;
use ReflectionMethod;
use ReflectionNamedType;

/** @phpstan-type CommandConfig array{class: class-string<object>, description: string} */
class CliDispatcher
{
    private Container $container;
    /** @var array<string, CommandConfig> */
    private array $commands = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function register(string $name, string $commandClass, string $description = ''): void
    {
        if ($name === '' || preg_match('/^[a-z][a-z0-9_-]*(?::[a-z0-9_*_-]+)*$/', $name) !== 1) {
            throw new \InvalidArgumentException('CLI command name has an invalid format.');
        }
        if (!class_exists($commandClass)) {
            throw new \InvalidArgumentException("CLI command class '{$commandClass}' does not exist.");
        }

        $hasPublicEntryPoint = false;
        foreach (['execute', 'run'] as $methodName) {
            if (method_exists($commandClass, $methodName) && (new ReflectionMethod($commandClass, $methodName))->isPublic()) {
                $hasPublicEntryPoint = true;
                break;
            }
        }
        if (!$hasPublicEntryPoint) {
            throw new \InvalidArgumentException(
                "CLI command class '{$commandClass}' must define a public run() or execute() method."
            );
        }

        $this->commands[$name] = ['class' => $commandClass, 'description' => $description];
    }

    /** @param list<string> $argv */
    public function run(array $argv): void
    {
        if (count($argv) < 2 || in_array('--help', $argv, true) || in_array('-h', $argv, true) || in_array('help', $argv, true)) {
            $this->showHelp();
            return;
        }
        $action = $argv[1];
        $matchedCommand = $this->resolveCommand($action);
        if ($matchedCommand === null) {
            $this->output("\n❌ Error: Command '{$action}' not found.\n", true);
            $this->showHelp();
            exit(1);
        }
        try {
            $commandClass = $matchedCommand['class'];
            $instance = $this->container->make($commandClass);
            $args = array_values(array_slice($argv, 2));
            $exitCode = $this->executeCommand($instance, $argv, $args, $commandClass);
            if ($exitCode !== 0) {
                exit($exitCode);
            }
        } catch (\Throwable $e) {
            $this->output("\n❌ CLI execution failed: " . $e->getMessage() . "\n", true);
            exit(1);
        }
    }

    /** @return CommandConfig|null */
    private function resolveCommand(string $action): ?array
    {
        foreach ($this->commands as $name => $config) {
            if ($action === $name || (str_ends_with($name, ':*') && str_starts_with($action, rtrim($name, '*')))) {
                return $config;
            }
        }
        return null;
    }

    /**
     * @param list<string> $argv
     * @param list<string> $args
     */
    private function executeCommand(object $instance, array $argv, array $args, string $className): int
    {
        if (method_exists($instance, 'execute')) {
            $method = new ReflectionMethod($instance, 'execute');
            $result = $this->invokeBySignature($instance, $method, $argv, $args);
            $this->printReturnValue($result);
            return is_int($result) ? $result : 0;
        }
        if (method_exists($instance, 'run')) {
            $method = new ReflectionMethod($instance, 'run');
            $result = $this->invokeBySignature($instance, $method, $argv, $args);
            $this->printReturnValue($result);
            return is_int($result) ? $result : 0;
        }
        throw new \RuntimeException("Command class {$className} must implement run() or execute() method.");
    }

    /**
     * @param list<string> $argv
     * @param list<string> $args
     */
    private function invokeBySignature(object $instance, ReflectionMethod $method, array $argv, array $args): mixed
    {
        $params = $method->getParameters();
        if (count($params) === 0) {
            return $method->invoke($instance);
        }
        $first = $params[0];
        $type = $first->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
        if ($typeName === 'array' || $first->isArray()) {
            $name = strtolower($first->getName());
            return $method->invoke($instance, in_array($name, ['argv', 'rawargv'], true) ? $argv : $args);
        }
        if ($method->getNumberOfRequiredParameters() === 0) {
            return $method->invoke($instance);
        }
        return $method->invoke($instance, $args);
    }

    private function printReturnValue(mixed $value): void
    {
        if ($value === null || is_int($value)) {
            return;
        }
        if (is_string($value)) {
            $this->output($value . (str_ends_with($value, "\n") ? '' : "\n"));
            return;
        }
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $this->output(($encoded !== false ? $encoded : print_r($value, true)) . "\n");
            return;
        }
        if (is_scalar($value)) {
            $this->output((string)$value . "\n");
        }
    }

    private function showHelp(): void
    {
        $buffer = "\nChortke Enterprise CLI\n========================\nUsage:\n  php cli.php <command> [options] [--help|-h]\n\nAvailable Commands:\n";
        $groups = [];
        foreach ($this->commands as $name => $config) {
            $parts = explode(':', $name, 2);
            $prefix = count($parts) > 1 ? $parts[0] : 'system';
            $groups[$prefix][$name] = $config['description'];
        }
        ksort($groups);
        foreach ($groups as $prefix => $commands) {
            $buffer .= "\n  " . ucfirst($prefix) . "\n";
            foreach ($commands as $name => $desc) {
                $buffer .= "    " . str_pad($name, 25) . " " . $desc . "\n";
            }
        }
        $buffer .= "\n";
        $this->output($buffer);
    }

    private function output(string $message, bool $isErr = false): void
    {
        $stream = $isErr ? (defined('STDERR') ? STDERR : STDOUT) : STDOUT;
        if (!function_exists('stream_isatty') || !stream_isatty($stream)) {
            $message = (string)preg_replace('/\033\[[0-9;]*m/', '', $message);
        }
        if ($stream !== STDOUT && defined('STDERR')) {
            fwrite(STDERR, $message);
            fflush(STDERR);
        } else {
            echo $message;
        }
    }
}
