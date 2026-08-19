<?php

namespace Core;

class Application
{
    private static ?Application $instance = null;
    private static bool $exceptionHandlerRegistered = false;

    public Container $container;
    private mixed $dbInstance = null;
    public Router    $router;
    public Request   $request;
    public Response  $response;
    public Session   $session;
    /** @var array<string, mixed> */
    public array $config;
    private ?object $cachedUser = null;
    private bool $userResolved = false;

    private function __construct() {
        // ── ۱. Config ────────────────────────────────────────────
        $this->config = is_array(config()) ? config() : [];

        // ── ۲. Session — getInstance + start (یک‌جا، یک‌بار) ──────
        $this->session = Session::getInstance(
            Container::getInstance()->make(PathResolver::class)
        );

        // ── ۳. ExceptionHandler ──────────────────────────────────
        if (!self::$exceptionHandlerRegistered) {
            ExceptionHandler::register();
            self::$exceptionHandlerRegistered = true;
        }

        // ── ۴. Core Objects ──────────────────────────────────────
        $this->request  = new Request();
        $this->response = new Response();
        $this->container = Container::getInstance();
        $this->router = new Router($this->request, $this->container);

        // ── ۵. Database Lazy Loading ─────────────────────────────

        // ── ۶. Container Bindings ────────────────────────────────
        $this->registerCoreBindings();
    }

    private function registerCoreBindings(): void
    {
        $c = $this->container;
        $c->instance(Application::class, $this);
        $c->instance(Container::class,   $c);
        $c->instance(Request::class,     $this->request);
        $c->instance(Response::class,    $this->response);
        $c->instance(Session::class,     $this->session);
        $c->instance(Router::class,      $this->router);
        if (!$c->has(\Core\Cache::class)) {
            $c->singleton(\Core\Cache::class, fn() => \Core\Cache::getInstance());
        }
    }


    /**
     * Resolve an abstract from the application container.
     *
     * @template T of object
     * @param class-string<T> $abstract
     * @return T
     */
    public function make(string $abstract): object
    {
        $resolved = $this->container->make($abstract);
        /** @var T $resolved */
        return $resolved;
    }

    public function db(): Database
    {
        if ($this->dbInstance === null) {
            try {
                $this->dbInstance = $this->container->make(Database::class);
            } catch (\Throwable $e) {
                throw new \RuntimeException('System database resolution failed', 0, $e);
            }
        }
        if (!$this->dbInstance instanceof Database) {
            throw new \RuntimeException('Database instance is not valid');
        }
        return $this->dbInstance;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'db') return $this->db();
        throw new \RuntimeException("Property $name does not exist");
    }

    public function user(): ?object
    {
        if ($this->userResolved) return $this->cachedUser;
        $userId = $this->session->get('user_id');
        if (!$userId) {
            $this->userResolved = true;
            $this->cachedUser = null;
            return null;
        }
        try {
            $userModel = $this->container->make(\App\Models\User::class);
            $this->cachedUser = $userModel->find(int_value($userId));
        } catch (\Throwable $e) {
            $this->cachedUser = null;
        }
        $this->userResolved = true;
        return $this->cachedUser;
    }

    public function forgetUser(): void
    {
        $this->cachedUser = null;
        $this->userResolved = false;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __clone() {}
    public function __wakeup() { throw new \RuntimeException("Cannot unserialize singleton"); }

    public function run(): void
    {
        $startTime = microtime(true);
        $this->router->dispatch();
        $durationMs = (microtime(true) - $startTime) * 1000;
        $slaThresholdMs = float_value(config('app.sla_threshold_ms', 1000));
        if ($durationMs > $slaThresholdMs) {
            try {
                $logger = $this->container->make(\App\Contracts\LoggerInterface::class);
                $logger->warning('sla_breach_detected', [
                    'duration_ms' => round($durationMs, 2),
                    'threshold_ms' => $slaThresholdMs,
                    'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                ]);
            } catch (\Throwable $ignore) {}
        }
    }
}
