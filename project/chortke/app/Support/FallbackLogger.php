<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\LoggerInterface;

/**
 * لاگر جایگزین (Fallback) که هنگام در دسترس نبودنِ لاگر اصلی (مثلاً پیش از بوت کامل
 * کانتینر یا در شرایط خطا) استفاده می‌شود تا فراخوانی logger() هرگز باعث کرش اپ نشود.
 *
 * خروجی به error_log سیستم نوشته می‌شود (نه null) تا اطلاعات تشخیصی از دست نرود.
 */
final class FallbackLogger implements LoggerInterface
{
    public function emergency(string $message, array $context = []): void { $this->write('emergency', $message, $context); }
    public function alert(string $message, array $context = []): void { $this->write('alert', $message, $context); }
    public function critical(string $message, array $context = []): void { $this->write('critical', $message, $context); }
    public function error(string $message, array $context = []): void { $this->write('error', $message, $context); }
    public function warning(string $message, array $context = []): void { $this->write('warning', $message, $context); }
    public function notice(string $message, array $context = []): void { $this->write('notice', $message, $context); }
    public function info(string $message, array $context = []): void { $this->write('info', $message, $context); }
    public function debug(string $message, array $context = []): void { $this->write('debug', $message, $context); }
    public function log(string $level, string $message, array $context = []): void { $this->write($level, $message, $context); }
    public function activity(string $action, string $description, ?int $userId = null, array $metadata = []): void { $this->write('info', "[activity] {$action}: {$description}", $metadata); }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $ctx = $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $line = "[fallback-logger][{$level}] {$message}{$ctx}";
        if (PHP_SAPI === 'cli') {
            echo $line . PHP_EOL;
            if (defined('STDOUT')) {
                @fflush(STDOUT);
            }
            return;
        }
        @error_log($line);
    }
}
