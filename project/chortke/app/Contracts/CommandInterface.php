<?php

namespace App\Contracts;

/**
 * Command Interface برای دستورات CLI
 * 
 * تمام دستورات CLI پروژه باید این اینترفیس را پیاده‌سازی کنند.
 */
interface CommandInterface
{
    /**
     * اجرای دستور
     *
     * @param array<string, mixed> $args آرگومان‌های دستور
     * @return void
     */
    public function run(array $args = []): void;
}