<?php

declare(strict_types=1);

namespace Core;

abstract class Command
{
    protected function info(string $message): void
    {
        $this->output("[INFO] {$message}\n");
    }

    protected function line(string $message): void
    {
        $this->output("{$message}\n");
    }

    protected function error(string $message): void
    {
        $this->output("[ERROR] {$message}\n", true);
    }

    /**
     * 🛡️ خروجی هوشمند و تشخیص استریم‌های غیرتعاملی (Non-TTY TTY Log Stripping Guard)
     * حذف خودکار کدهای رنگی ANSI در زمان اجرای دیمن‌ها در داکر، کرون و کوبرنتیز جهت جلوگیری از درج کاراکترهای فاسد در سیستم‌های لاگ
     */
    protected function output(string $message, bool $isErr = false): void
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
