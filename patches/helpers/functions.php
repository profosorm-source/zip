<?php

/**
 * Global Helper Hub - چرتکه
 * 
 * این فایل شامل توابع هسته (Core) است. 
 * سایر توابع تخصصی در فایل‌های هلوپر مجزا (view, url, auth, ...) تعریف شده‌اند
 * و توسط Composer بارگذاری می‌شوند.
 */

if (!function_exists('app')) {
    /**
     * دریافت Application Instance یا حل وابستگی از Container
     *
     * @template T of object
     * @param class-string<T>|null $abstract
     * @return ($abstract is null ? \Core\Application : T)
     */
    function app(?string $abstract = null)
    {
        $instance = \Core\Application::getInstance();
        if ($abstract === null) {
            return $instance;
        }
        return $instance->container->make($abstract);
    }
}

if (!function_exists('db')) {
    /**
     * دریافت Database Instance
     */
    function db(): \Core\Database
    {
        return app(\Core\Database::class);
    }
}

if (!function_exists('cache')) {
    /**
     * دسترسی به Cache از طریق Container (CacheInterface)
     *
     * تلاش می‌کند CacheInterface را از Container resolve کند.
     * در صورت عدم موفقیت (مثلاً پیش از بوت کامل)، مستقیماً Core\Cache را برمی‌گرداند
     * که در زمان اجرا با CacheInterface سازگار است.
     */
    function cache(): \App\Contracts\CacheInterface|\Core\Cache
    {
        try {
            return app(\App\Contracts\CacheInterface::class);
        } catch (\Throwable $e) {
            // Fallback: Core\Cache تمام متدهای CacheInterface را پشتیبانی می‌کند
            return \Core\Cache::getInstance();
        }
    }
}

if (!function_exists('session')) {
    /**
     * دسترسی سریع به Session singleton
     */
    function session(): \Core\Session
    {
        return \Core\Session::getInstance();
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        global $env;

        if (!isset($env)) {
            $env = [];
        }

        if (isset($env[$key])) {
            $value = $env[$key];
        } else {
            $runtimeValue = getenv($key);
            if ($runtimeValue !== false) {
                $value = $runtimeValue;
            } elseif (array_key_exists($key, $_ENV)) {
                $value = $_ENV[$key];
            } elseif (array_key_exists($key, $_SERVER)) {
                $value = $_SERVER[$key];
            } else {
                return $default;
            }
        }

            if (is_string($value)) {
                $value = trim($value);
                // Unquote values
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                $value = trim($value);

                $lower = strtolower($value);
                if ($lower === 'true') return true;
                if ($lower === 'false') return false;
                if ($lower === 'null') return null;
                if (is_numeric($value)) {
                    if (str_contains($value, '.')) {
                        return (float)$value;
                    }

                    // Only cast to int when the integer representation
                    // round-trips back to the original string. This protects
                    // values that merely *look* numeric (e.g. a 32-char numeric
                    // APP_KEY, IDs with leading zeros, or numbers that overflow
                    // PHP_INT_MAX) from being silently corrupted/clamped.
                    $asInt = (int)$value;
                    if ((string)$asInt === ltrim($value, '+')) {
                        return $asInt;
                    }

                    // Numeric but not safely an int — keep it as the raw string.
                    return $value;
                }
            }

            return $value;
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        global $configData, $configLoaded, $configOverrides;

        if (!is_array($configData)) {
            $configData = [];
        }

        if (!is_array($configLoaded)) {
            $configLoaded = [];
        }

        if (!is_array($configOverrides)) {
            $configOverrides = [];
        }

        $loadConfig = function(string $name) use (&$configData, &$configLoaded) {
            if (isset($configLoaded[$name])) {
                return;
            }
            $configLoaded[$name] = true;

            $file = __DIR__ . "/../config/{$name}.php";
            if (file_exists($file)) {
                // عمداً require (نه require_once): تکرارِ بارگذاری همین‌جا با
                // $configLoaded مهار می‌شود. با require_once، پس از هر
                // config_reload() فایل بار دومْ true برمی‌گرداند نه آرایه، و
                // پیکربندیِ فایلی برای همیشه از دست می‌رفت — که در
                // QueueWorker (config_reload() بعد از هر job) یعنی از دست رفتن
                // دائمی تنظیمات در worker های بلندمدت.
                $content = require $file;
                if (is_array($content)) {
                    $configData[$name] = $content;
                }
            }
        };

        $traverse = function(array $source, array $keys, bool &$found) {
            $value = $source;
            foreach ($keys as $segment) {
                if (is_array($value) && array_key_exists($segment, $value)) {
                    $value = $value[$segment];
                    continue;
                }
                $found = false;
                return null;
            }
            $found = true;
            return $value;
        };

        if ($key === null) {
            $configDir = __DIR__ . '/../config/';
            if (is_dir($configDir)) {
                foreach ((glob($configDir . '*.php') ?: []) as $file) {
                    $loadConfig(basename($file, '.php'));
                }
            }

            $merged = [];
            foreach ($configData as $name => $content) {
                if ($name === 'config') {
                    $merged = array_merge($merged, $content);
                } else {
                    $merged[$name] = $content;
                }
            }

            return array_replace_recursive($merged, $configOverrides);
        }

        $keys = explode('.', $key);
        $file = $keys[0];

        $loadConfig($file);
        $loadConfig('config');

        if (!empty($configOverrides)) {
            $found = false;
            $override = $traverse($configOverrides, $keys, $found);
            if ($found) {
                return $override;
            }
        }

        if (isset($configData[$file])) {
            $found = false;
            $value = $traverse($configData[$file], array_slice($keys, 1), $found);
            if ($found) {
                return $value;
            }
        }

        if (isset($configData['config'])) {
            $found = false;
            $value = $traverse($configData['config'], $keys, $found);
            if ($found) {
                return $value;
            }
        }

        return $default;
    }
}

if (!function_exists('config_set')) {
    function config_set(string $key, mixed $value): void
    {
        global $configOverrides;

        if (!is_array($configOverrides)) {
            $configOverrides = [];
        }

        $segments = explode('.', $key);
        $target = &$configOverrides;
        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }

        $target = $value;
    }
}

if (!function_exists('config_reload')) {
    function config_reload(?string $key = null): void
    {
        global $configData, $configLoaded, $configOverrides;

        if (!is_array($configData)) {
            $configData = [];
        }
        if (!is_array($configLoaded)) {
            $configLoaded = [];
        }
        if (!is_array($configOverrides)) {
            $configOverrides = [];
        }

        if ($key === null) {
            // Reload file-backed configuration while preserving explicit runtime
            // overrides. Clearing overrides here makes long-running workers lose
            // immutable bootstrap configuration after every processed job.
            $configData = [];
            $configLoaded = [];
            return;
        }

        $segments = explode('.', $key);
        unset($configData[$segments[0]], $configLoaded[$segments[0]]);
    }
}

if (!function_exists('settings')) {
    /**
     * @return array<string, mixed>
     */
    function settings(bool $forceReload = false): array
    {
        $service = app(\App\Services\Settings\AppSettings::class);
        if ($forceReload) {
            $service->clearCache();
        }
        $settings = $service->load();
        return is_array($settings) ? $settings : [];
    }
}

if (!function_exists('base_path')) {
    /**
     * مسیر ریشه پروژه
     */
    function base_path(string $path = ''): string
    {
        return app(\Core\PathResolver::class)->base($path);
    }
}

if (!function_exists('view_path')) {
    /**
     * مسیر فایل ویو
     */
    function view_path(string $viewName): string
    {
        $viewPath = defined('VIEW_PATH') ? VIEW_PATH : base_path('views');
        return rtrim($viewPath, '/\\') . '/' . str_replace('.', '/', trim($viewName, '/')) . '.php';
    }
}


if (!function_exists('storage_path')) {
    /**
     * Get the path to the storage folder.
     */
    function storage_path(string $path = ''): string
    {
        return app(\Core\PathResolver::class)->storage($path);
    }
}

if (!function_exists('public_path')) {
    /**
     * Get the path to the public folder.
     */
    function public_path(string $path = ''): string
    {
        return app(\Core\PathResolver::class)->public($path);
    }
}

if (!function_exists('setting')) {
    /**
     * دریافت یک تنظیم خاص
     */
    function setting(string $key, mixed $default = null): mixed
    {
        $settings = settings();
        return $settings[$key] ?? $default;
    }
}

if (!function_exists('logger')) {
    /**
     * Logger Helper
     */
    function logger(): \App\Contracts\LoggerInterface
    {
        // Fail-safe: اگر لاگر اصلی از کانتینر قابل resolve نبود (مثلاً پیش از بوت کامل یا در
        // شرایط خطا)، به‌جای پرتاب استثنا و کرش اپ، از FallbackLogger استفاده می‌شود.
        try {
            return app(\App\Contracts\LoggerInterface::class);
        } catch (\Throwable $e) {
            return new \App\Support\FallbackLogger();
        }
    }
}

if (!function_exists('dd')) {
    /**
     * Dump and Die
     */
    function dd(mixed ...$vars): never
    {
        if (!config('app.debug')) {
            try {
                logger()->error('dd() called in production environment', [
                    'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
                ]);
            } catch (\Throwable $e) {
                // Fallback if logger is unavailable
            }
            die('An error occurred. Please contact administrator.');
        }

        echo '<pre style="background: #1e1e1e; color: #ddd; padding: 20px; direction: ltr; text-align: left;">';
        foreach ($vars as $var) {
            var_dump($var);
        }
        echo '</pre>';
        die(1);
    }
}

if (!function_exists('format_amount')) {
    /**
     * فرمت‌دهی مبالغ مالی به صورت فارسی یا عددی خوانا
     */
    function format_amount(mixed $amount): string
    {
        return number_format(float_value($amount), 0, '.', ',');
    }
}

/* -------------------------------------------------------------
 | Lazy Loading Helpers Hub
 | فایل‌های راهنما به صورت هوشمند و تنها در صورت نیاز لود می‌شوند.
 * ------------------------------------------------------------- */

if (!function_exists('csrf_token')) {
    require_once __DIR__ . '/csrf_helper.php';
}
if (!function_exists('secure_key')) {
    require_once __DIR__ . '/security.php';
}
if (!function_exists('view')) {
    require_once __DIR__ . '/view_helper.php';
}

// ✅ e() باید همیشه تعریف باشد — مستقل از view() load شدن
// در view_helper.php هم تعریف شده، اما اگر view() از جای دیگری load شده باشد
// view_helper.php skip می‌شود و e() undefined می‌ماند → Fatal Error
if (!function_exists('e')) {
    /**
     * HTML escape — محافظت از XSS در views
     * استفاده: <?= e($userInput) ?>
     */
    function e(mixed $value, int $flags = ENT_QUOTES | ENT_SUBSTITUTE, string $encoding = 'UTF-8'): string
    {
        return htmlspecialchars(
            str_value($value),
            $flags,
            $encoding
        );
    }
}
if (!function_exists('url')) {
    require_once __DIR__ . '/url_helper.php';
}
if (!function_exists('auth')) {
    require_once __DIR__ . '/auth_helper.php';
}
if (!function_exists('today')) {
    require_once __DIR__ . '/date_helper.php';
}
if (!function_exists('json_response')) {
    require_once __DIR__ . '/response_helper.php';
}
if (!function_exists('rate_limit')) {
    require_once __DIR__ . '/rate_limit_helper.php';
}
if (!function_exists('captcha')) {
    require_once __DIR__ . '/captcha_helper.php';
}
if (!function_exists('feature_enabled')) {
    require_once __DIR__ . '/feature_flag_helpers.php';
}
if (!function_exists('site_logo')) {
    require_once __DIR__ . '/site_helper.php';
}
if (!function_exists('unread_notifications_count') || !function_exists('notify')) {
    require_once __DIR__ . '/notifications.php';
}

// Backward-compatible aliases used by legacy views/controllers after the auth
// refactor. `auth()` remains the source of truth; `user()` and `h()` prevent
// old live routes from fatally failing while those call sites are migrated.
if (!function_exists('user')) {
    function user(): ?object
    {
        return auth();
    }
}

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return e($value);
    }
}

if (!function_exists('str_value')) {
    /**
     * تبدیل امن مقدار به رشته.
     * فقط مقادیر اسکالر تبدیل می‌شوند؛ array/object/null به $default برمی‌گردند.
     * (جایگزینِ cast کورِ `(string)$mixed` در PHPStan Level 9)
     */
    function str_value(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string)$value : $default;
    }
}

if (!function_exists('int_value')) {
    /**
     * تبدیل امن مقدار به عدد صحیح.
     * فقط مقادیر عددی/رشته‌ی عددی تبدیل می‌شوند؛ سایر مقادیر به $default برمی‌گردند.
     */
    function int_value(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int)$value : $default;
    }
}

if (!function_exists('float_value')) {
    /**
     * تبدیل امن مقدار به عدد اعشاری.
     * فقط مقادیر عددی/رشته‌ی عددی تبدیل می‌شوند؛ سایر مقادیر به $default برمی‌گردند.
     */
    function float_value(mixed $value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float)$value : $default;
    }
}


if (!function_exists('custom_task_status_labels_map')) {
    require_once __DIR__ . '/label_helpers.php';
}
if (!function_exists('banner_type_label')) {
    require_once __DIR__ . '/banner_helpers.php';
}
if (!function_exists('xss_clean')) {
    require_once __DIR__ . '/xss_protection.php';
}
if (!class_exists('\\Helpers\\JalaliDate', false)) {
    require_once __DIR__ . '/JalaliDate.php';
}
