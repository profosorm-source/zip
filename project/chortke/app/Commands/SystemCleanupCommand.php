<?php

declare(strict_types=1);

namespace App\Commands;

use App\Contracts\CommandInterface;

/**
 * System Cleanup Command
 */
class SystemCleanupCommand implements CommandInterface
{
    public function __construct() {
        // Constructor اختیاری
    }

    /** @param array<string, mixed> $args */

    public function run(array $args = []): void
    {
        $this->output("[SystemCleanupCommand] شروع پاکسازی سیستم...\n");
        
        // مثال ساده
        $this->output("پاکسازی لاگ‌های قدیمی...\n");
        $this->output("پاکسازی رکوردهای منقضی شده...\n");
        
        $this->output("[SystemCleanupCommand] پاکسازی با موفقیت انجام شد (نسخه موقتی).\n");
    }

    /**
     * 🛡️ خروجی هوشمند و تشخیص استریم‌های غیرتعاملی (Non-TTY TTY Log Stripping Guard)
     * حذف خودکار کدهای رنگی ANSI در زمان اجرای دیمن‌ها در داکر، کرون و کوبرنتیز جهت جلوگیری از درج کاراکترهای فاسد در سیستم‌های لاگ
     */
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
