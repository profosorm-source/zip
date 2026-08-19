<?php

/**
 * توابع کمکی URL و روتینگ
 */

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        return app(\Core\UrlGenerator::class)->to($path);
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return app(\Core\UrlGenerator::class)->asset($path);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path, int $statusCode = 302): never
    {
        if (!preg_match('#^https?://#i', $path) && function_exists('url')) {
            $path = url($path);
        }
        app(\Core\Response::class)->redirect($path, $statusCode);
        exit;
    }
}

if (!function_exists('back')) {
    function back(): never
    {
        app(\Core\Response::class)->back();
        exit;
    }
}

if (!function_exists('current_url')) {
    function current_url(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return url($uri);
    }
}
