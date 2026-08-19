<?php

namespace Core;

use App\Middleware\TracingMiddleware;

/**
 * Router
 *
 * جریان صحیح dispatch:
 *
 *   User Request
 *       ↓
 *   Router::dispatch()
 *       ↓
 *   matchRoute()  →  پیدا کردن route
 *       ↓
 *   Container::make(Middleware)  →  اجرای middleware‌ها
 *       ↓
 *   Container::make(ControllerClass)
 *       ├─→ Container::make(ServiceClass)   [auto-wire از constructor]
 *       │       └─→ Container::make(Model)  [auto-wire از constructor]
 *       └─→ Controller::__construct(Service, ...)
 *       ↓
 *   Controller::method($routeParams)
 *       ↓
 *   Response::send()
 */
class Router
{
    /** @var array{prefix?: string, middleware?: array<int, string>} */
    protected array $groupAttributes = [];
    private Request $request;
    private Container $container;

    /** @var array<string, list<array{uri:string, route:Route}>> */
    private array $routes = [
        'GET'     => [],
        'POST'    => [],
        'PUT'     => [],
        'DELETE'  => [],
        'PATCH'   => [],
        'OPTIONS' => [], // M10 Fix: پشتیبانی بومی از متد پیش‌پرواز OPTIONS جهت تکمیل استاندارد REST
        'HEAD'    => [], // M10 Fix: پشتیبانی از درخواست‌های صرفاً هدر HEAD
    ];

    // CORE-027: Middleware Priority Registry
    /** @var list<class-string> */
    protected array $middlewarePriority = [
        TracingMiddleware::class,
        \App\Middleware\GlobalExceptionMiddleware::class,
        \App\Middleware\SessionMiddleware::class,
        \App\Middleware\CSRFMiddleware::class, // 🔐 Architectural Fix: Enforced automatic CSRF validation globally (Phase 3 Fix)
        \App\Middleware\ConcurrentRequestMiddleware::class,
        \App\Middleware\HttpsMiddleware::class,
        \App\Middleware\SecurityHeadersMiddleware::class,
        \App\Middleware\CorsMiddleware::class,
        \App\Middleware\MaintenanceMiddleware::class,
        \App\Middleware\SafeModeMiddleware::class,
        \App\Middleware\LoggingMiddleware::class,
    ];

    public function __construct(Request $request, Container $container) {
        $this->request = $request;
        $this->container = $container;
    }

    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    // ─────────────────────────────────────────────────────────────
    // Route Registration
    // ─────────────────────────────────────────────────────────────

    /** @param array<int, string> $middleware */
    public function get(string $uri, mixed $action, array $middleware = []): Route
    {
        return $this->addRoute('GET', $uri, $action, $middleware);
    }

    /** @param array<int, string> $middleware */
    public function post(string $uri, mixed $action, array $middleware = []): Route
    {
        return $this->addRoute('POST', $uri, $action, $middleware);
    }

    /** @param array<int, string> $middleware */
    public function put(string $uri, mixed $action, array $middleware = []): Route
    {
        return $this->addRoute('PUT', $uri, $action, $middleware);
    }

    /** @param array<int, string> $middleware */
    public function delete(string $uri, mixed $action, array $middleware = []): Route
    {
        return $this->addRoute('DELETE', $uri, $action, $middleware);
    }

    /** @param array<int, string> $middleware */
    public function patch(string $uri, mixed $action, array $middleware = []): Route
    {
        return $this->addRoute('PATCH', $uri, $action, $middleware);
    }

    /** @param array<int, string> $middleware */
    public function options(string $uri, mixed $action, array $middleware = []): Route
    {
        return $this->addRoute('OPTIONS', $uri, $action, $middleware);
    }

    /** @param array<int, string> $middleware */
    public function head(string $uri, mixed $action, array $middleware = []): Route
    {
        return $this->addRoute('HEAD', $uri, $action, $middleware);
    }

    /** @param array<int, string> $routeMiddleware */
    private function addRoute(string $method, string $uri, mixed $action, array $routeMiddleware = []): Route
    {
        // اعمال group prefix
        $prefix = $this->groupAttributes['prefix'] ?? '';
        $routePath = trim($prefix . '/' . ltrim($uri, '/'), '/');
        $fullUri = $routePath === '' ? '/' : '/' . $routePath;

        $route = new Route($fullUri, $action);

        // اعمال group middleware
        if (!empty($this->groupAttributes['middleware'])) {
            foreach ((array)$this->groupAttributes['middleware'] as $mw) {
                $route->middleware($mw);
            }
        }

        // اعمال inline middleware (پارامتر سوم مستقیم)
        foreach ($routeMiddleware as $mw) {
            $route->middleware($mw);
        }

        $this->routes[$method][] = [
            'uri'   => $fullUri,
            'route' => $route,
        ];

        return $route;
    }

    // ─────────────────────────────────────────────────────────────
    // Group
    // ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $attributes */
    public function group(array $attributes, callable $callback): void
    {
        $previous = $this->groupAttributes;

        if (isset($attributes['middleware'])) {
            $mw = is_array($attributes['middleware'])
                ? $attributes['middleware']
                : [$attributes['middleware']];

            $this->groupAttributes['middleware'] = array_merge(
                $this->groupAttributes['middleware'] ?? [],
                $mw
            );
        }

        if (array_key_exists('prefix', $attributes)) {
            $prefixValue = $attributes['prefix'];
            if (!is_string($prefixValue)) {
                throw new \InvalidArgumentException('Route group prefix must be a string.');
            }

            $currentPrefix = trim($this->groupAttributes['prefix'] ?? '', '/');
            $nextPrefix = trim($prefixValue, '/');
            $combinedPrefix = implode('/', array_filter(
                [$currentPrefix, $nextPrefix],
                static fn(string $segment): bool => $segment !== ''
            ));
            $this->groupAttributes['prefix'] = $combinedPrefix === '' ? '' : '/' . $combinedPrefix;
        }

        $callback($this);

        $this->groupAttributes = $previous;
    }

    // ─────────────────────────────────────────────────────────────
    // Dispatch — قلب Router
    // ─────────────────────────────────────────────────────────────

    public function dispatch(): void
    {
        // H15 Fix: اطمینان از تمیز بودن استک وابستگی‌های کانتینر در هر ورودی جدید
        Container::resetTraceStack();

        // Session initialization is now fully decoupled and handled cleanly by SessionMiddleware at the head of the global pipeline

        // ── Global Middleware Stack ─────────────────────────────────────
        // این میدل‌ویرها برای تمامی درخواست‌ها (حتی صفحات ۴۰۴) اجرا می‌شوند
        $globalMiddlewares = [
            TracingMiddleware::class,
            \App\Middleware\GlobalExceptionMiddleware::class,
            \App\Middleware\SessionMiddleware::class,
            \App\Middleware\CSRFMiddleware::class,
            \App\Middleware\ConcurrentRequestMiddleware::class,
            \App\Middleware\LoggingMiddleware::class,         // رصد دقیق پرفورمنس و مدیریت آسنکرون لاگ
            \App\Middleware\CorsMiddleware::class,
            \App\Middleware\HttpsMiddleware::class,
            \App\Middleware\SecurityHeadersMiddleware::class,
            \App\Middleware\MaintenanceMiddleware::class,     // جایگزین هوشمند لایه سنتی تعمیرات در هسته
            \App\Middleware\SafeModeMiddleware::class         // سپر نهایی محافظت فقط خواندنی (Read-only)
        ];

        // CORE-027: Sort middlewares according to the policy priority registry
        $globalMiddlewares = $this->sortMiddlewares($globalMiddlewares);

        // H10 Fix: کپسوله کردن پایپ‌لاین روت‌ها در try/catch برای گرفتن Exceptionهای پاسخدهی استاندارد
        try {
            // اجرای حلقه اصلی مسیریابی از میان Pipeline سراسری
            $response = (new Pipeline($this->container))
                ->send($this->request)
                ->through($globalMiddlewares)
                ->then(function ($request) {
                    
                    $method = $request->method();
                    $uri    = $request->uri();

                    // CORE-029: HEAD fallback to GET
                    $routesToMatch = $this->routes[$method] ?? [];
                    if ($method === 'HEAD' && empty($routesToMatch)) {
                        $routesToMatch = $this->routes['GET'] ?? [];
                    }

                    // ① جستجوی مسیر منطبق (Route Matching)
                    foreach ($routesToMatch as $routeData) {
                        $params = $this->matchRoute($routeData['uri'], $uri);

                        if ($params === false) {
                            continue;
                        }

                        $request->setParams($params);
                        $GLOBALS['_route_params'] = $params;

                        // RBAC: قرار دادن action مسیرِ منطبق روی Request تا middlewareها
                        // (مثل AdminPermissionGuard) بتوانند Controller@method را تشخیص دهند.
                        $request->setAttribute('_resolved_action', $routeData['route']->getAction());

                        // ② اجرای پایپ‌لاین اختصاصی روت
                        $middlewares = $routeData['route']->getMiddleware();

                        return (new Pipeline($this->container))
                            ->send($request)
                            ->through($middlewares)
                            ->then(function ($req) use ($routeData, $params) {
                                // مقصد نهایی: اجرای Action کنترلر
                                return $this->executeAction($routeData['route']->getAction(), $params);
                            });
                    }

                    // ③ در صورت عدم یافتن مسیر، خروجی ۴۰۴ استاندارد برگردانده می‌شود
                    return $this->generateNotFoundResponse($uri, $method);
                });

            // ④ نهایی‌سازی و تحویل پاسخ نهایی به خروجی
            $this->handleResult($response);
            
        } catch (\Core\Exceptions\HttpResponseException $e) {
            // اگر کنترلر یا میدل‌ویرها Response->send() زده باشند، در اینجا گرفته و منتشر می‌شود
            $this->handleResult($e->getResponse());
        } catch (\Throwable $e) {
            // سایر خطاها جهت مدیریت متمرکز به ExceptionHandler سراسری ارجاع می‌شوند
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Middleware Execution — از Container ساخته می‌شه
    // ─────────────────────────────────────────────────────────────

    // runMiddleware removed - handled by Pipeline class

    // ─────────────────────────────────────────────────────────────
    // Controller Execution — از Container ساخته می‌شه
    // ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $params */
    protected function executeAction(mixed $action, array $params = []): mixed
    {
        // ── Closure action ───────────────────────────────────────
        if ($action instanceof \Closure) {
            // ✅ Fix L2: اجرای Closure با DI خودکار از طریق Container::call
            return $this->container->call($action, $params);
        }

        // ── [ControllerClass, 'method'] ──────────────────────────
        if (!is_array($action) || count($action) !== 2) {
            throw new \RuntimeException('[Router] Invalid route action format.');
        }

        [$controllerClass, $method] = array_values($action);
        if (!is_string($controllerClass) || !class_exists($controllerClass)) {
            throw new \RuntimeException('[Router] Controller action must reference an existing class.');
        }
        if (!is_string($method) || $method === '') {
            throw new \RuntimeException('[Router] Controller action method must be a non-empty string.');
        }

        // ساخت Controller از Container — همه وابستگی‌ها auto-wire می‌شن
        try {
            $controller = $this->container->make($controllerClass);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                "[Router] Cannot resolve Controller '{$controllerClass}': " . $e->getMessage()
            );
        }

        if (!method_exists($controller, $method)) {
            throw new \RuntimeException(
                "[Router] Method '{$method}' not found in '{$controllerClass}'."
            );
        }

        // اجرای action method با route params
        try {
            $ref = new \ReflectionMethod($controller, $method);
            if (!$ref->isPublic()) {
                throw new \RuntimeException("[Router] Action {$controllerClass}::{$method} must be public.");
            }
            $expectedCount = $ref->getNumberOfParameters();
            $args          = $expectedCount > 0
                ? array_slice(array_values($params), 0, $expectedCount)
                : [];

            return $ref->invokeArgs($controller, $args);

        } catch (\ReflectionException $e) {
            throw new \RuntimeException(
                "[Router] Reflection error for {$controllerClass}::{$method}: " . $e->getMessage()
            );
        }
    }

    /**
     * مدیریت نتیجه‌ی action
     */
    private function handleResult(mixed $result): void
    {
        if ($result instanceof Response) {
            // H10 Fix: صدا زدن امیتور فیزیکی به جای متد Send برای جلوگیری از حلقه تکرار exception
            $result->sendToBrowser();
            return;
        }
        if (is_string($result)) {
            // Some legacy helpers (notably helpers/view_helper.php::view) both echo
            // the rendered HTML and return it. When a controller uses
            // `return view(...)`, blindly echoing here duplicates the whole page.
            if (!isset($GLOBALS['_last_view_helper_echo_hash'])) {
                echo $result;
            }
            unset($GLOBALS['_last_view_helper_echo_hash']);
            return;
        }
        if (is_array($result) || is_object($result)) {
            $response = new Response();
            $response->json((array)$result);
            return;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // URI Matching
    // ─────────────────────────────────────────────────────────────

    /** @return array<string, string>|false */
    private function matchRoute(string $routeUri, string $currentUri): array|false
    {
        $routeUri   = trim($routeUri, '/');
        $currentUri = trim($currentUri, '/');

        $paramNames = [];

        // CORE-028 & CORE-062: Route param type validation with strict defaults and inferences
        $pattern = preg_replace_callback('/\{([a-zA-Z0-9_]+)(?::([a-zA-Z0-9_]+))?\}/', function ($m) use (&$paramNames) {
            $name = $m[1];
            $paramNames[] = $name;
            $type = $m[2] ?? null;
            
            // CORE-062: Automatically infer constraint type based on parameter names if omitted
            if ($type === null) {
                $lowerName = strtolower((string)$name);
                if ($lowerName === 'id' || str_ends_with($lowerName, '_id')) {
                    $type = 'int';
                } elseif (str_contains($lowerName, 'slug')) {
                    $type = 'slug';
                } else {
                    $type = 'safe_string';
                }
            }
            
            if ($type === 'int') {
                return '([0-9]+)';
            }
            if ($type === 'slug') {
                return '([a-z0-9\-]+)';
            }
            if ($type === 'alpha') {
                return '([a-zA-Z]+)';
            }
            
            // Safe fallback (alphanumeric plus basic safe url characters, not wild arbitrary symbols)
            return '([a-zA-Z0-9\-\_\.\%\@]+)';
        }, $routeUri);

        $pattern = '#^' . $pattern . '$#u';

        if (!preg_match($pattern, $currentUri, $matches)) {
            return false;
        }

        $params = [];
        foreach ((array)$paramNames as $i => $name) {
            $decoded = urldecode($matches[$i + 1] ?? '');
            // H7: جلوگیری از Path Traversal یا تزریق سگمنت با / بعد از دیکد شدن
            if (strpos($decoded, '/') !== false) {
                return false;
            }
            $params[$name] = $decoded;
        }

        return $params;
    }

    // ─────────────────────────────────────────────────────────────
    // 404 Handler
    // ─────────────────────────────────────────────────────────────

    /**
     * ایجاد پاسخ استاندارد ۴۰۴ بدون قطع اجرای سیستم
     */
    private function generateNotFoundResponse(string $uri, string $method): Response
    {
        $response = new Response();
        $response->status(404);

        ob_start();
        
        if (config('app.debug') && config('app.env') === 'local') {
            echo "<!DOCTYPE html><html lang='fa' dir='rtl'><head><meta charset='UTF-8'>";
            echo "<title>404 - صفحه یافت نشد</title>";
            echo "<style>body{font-family:Tahoma,Arial;padding:40px;background:#f5f5f5;}";
            echo ".box{background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.1);}";
            echo "h1{color:#e74c3c;}code{background:#ecf0f1;padding:2px 6px;border-radius:3px;}</style>";
            echo "</head><body><div class='box'>";
            echo "<h1>404 — صفحه یافت نشد</h1>";
            echo "<p><strong>Method:</strong> <code>{$method}</code></p>";
            echo "<p><strong>URI:</strong> <code>{$uri}</code></p>";
            echo "<h3>مسیرهای ثبت‌شده ({$method}):</h3><ul>";
            foreach ($this->routes[$method] ?? [] as $r) {
                echo "<li><code>{$r['uri']}</code></li>";
            }
            echo "</ul></div></body></html>";
        } else {
            $view = __DIR__ . '/../views/errors/404.php';
            if (file_exists($view)) {
                require $view;
            } else {
                echo '404 - Not Found';
            }
        }

        $response->setContent(ob_get_clean());
        return $response;
    }

    /**
     * تولید آدرس URL بر اساس نام Route
     */
    /** @param array<string, mixed> $params */
    public function route(string $name, array $params = []): string
    {
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $routeData) {
                /** @var Route $route */
                $route = $routeData['route'];
                if ($route->getName() === $name) {
                    $uri = $route->getUri();
                    
                    // جایگزینی پارامترها در URI (مانند /users/{id})
                    foreach ((array)$params as $key => $value) {
                        /** @var string $value */
                        $uri = str_replace('{' . $key . '}', $value, $uri);
                        $uri = str_replace('{' . $key . '?}', $value, $uri);
                    }
                    
                    // پاک‌سازی پارامترهای اختیاری خالی باقی‌مانده
                    $normalizedUri = preg_replace('/\/\{[a-zA-Z0-9_]+\?\}/', '', $uri);
                    $uri = $normalizedUri === null ? $uri : $normalizedUri;

                    // استفاده از هلپر سراسری url() برای تکمیل آدرس نهایی
                    return function_exists('url') ? url($uri) : $uri;
                }
            }
        }

        throw new \InvalidArgumentException("مسیر با نام '{$name}' یافت نشد.");
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * @param list<class-string> $middlewares
     * @return list<class-string>
     */
    protected function sortMiddlewares(array $middlewares): array
    {
        $priority = array_flip($this->middlewarePriority);
        
        usort($middlewares, function($a, $b) use ($priority) {
            $aPrio = $priority[$a] ?? 999;
            $bPrio = $priority[$b] ?? 999;
            return $aPrio <=> $bPrio;
        });
        
        return $middlewares;
    }

    /** @return array<string, list<array{uri:string, route:Route}>> */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
