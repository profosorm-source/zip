<?php

/**
 * توابع کمکی URL و روتینگ
 */

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        // جلوگیری از آدرس‌های مطلق خارجی ناامن برای رفع خطر Open Redirect
        $trimmedPath = ltrim($path, '/\\');
        if (preg_match('#^https?://#i', $trimmedPath) || str_starts_with($trimmedPath, '//')) {
            throw new \InvalidArgumentException('url() accepts relative paths only');
        }

        // ۱. اولویت با APP_URL تنظیم شده در config است
        $baseUrl = config('app.url');
        $basePath = trim((string) config('app.base_path', ''), '/');
        
        // Auto-detect base path from SCRIPT_NAME when not explicitly set (sandbox/arena support)
        if ($basePath === '' && !empty($_SERVER['SCRIPT_NAME'])) {
            $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
            if ($scriptDir !== '/' && $scriptDir !== '\\') {
                $basePath = trim(str_replace('\\', '/', $scriptDir), '/');
            }
        }

        // 🛡️ HOSTING AUTO-CONFIG GUARD: تشخیص خودکار دامین در هاست اشتراکی
        // حل مشکل قطعی آدرس‌ها در صورت عدم تنظیم یا فراموشی متغیر APP_URL در فایل .env
        if (!$baseUrl || $baseUrl === 'http://localhost' || $baseUrl === 'https://localhost') {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $host = preg_replace('/[^a-zA-Z0-9.:-]/', '', $host);
            
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $calculatedBasePath = str_replace(['/public/index.php', '/index.php', 'public/index.php', 'index.php'], '', $scriptName);
            $calculatedBasePath = rtrim(str_replace('\\', '/', $calculatedBasePath), '/');
            
            $baseUrl = $protocol . '://' . $host . $calculatedBasePath;
        }

        $baseUrl = rtrim($baseUrl, '/');
        if ($basePath !== '') {
            $baseUrlPath = parse_url($baseUrl, PHP_URL_PATH) ?: '';
            if ($baseUrlPath === '' || $baseUrlPath === '/') {
                $baseUrl .= '/' . $basePath;
            }
        }

        $path = '/' . ltrim($path, '/');

        $parsedBasePath = parse_url($baseUrl, PHP_URL_PATH) ?: '';
        $parsedBasePath = rtrim($parsedBasePath, '/');
        if ($parsedBasePath !== '' && ($path === $parsedBasePath || str_starts_with($path, $parsedBasePath . '/'))) {
            $path = substr($path, strlen($parsedBasePath));
            $path = '/' . ltrim($path, '/');
        }

        return $baseUrl . $path;
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $assetUrl = trim((string)config('app.asset_url', ''));
        if ($assetUrl !== '') {
            if (!preg_match('#^https?://#i', $assetUrl)) {
                throw new \InvalidArgumentException('ASSET_URL/CDN_URL must be an absolute http(s) URL');
            }
            return rtrim($assetUrl, '/') . '/' . ltrim($path, '/');
        }

        return url($path);
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
