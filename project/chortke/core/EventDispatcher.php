<?php
namespace Core;

/**
 * Event Dispatcher
 * 
 * مدیریت رویدادها و شنوندگان
 */
/**
 * @phpstan-type EventListener callable|class-string|array{0: class-string, 1: string}
 * @phpstan-type EventListenerEntry array{listener: EventListener, priority: int, sequence: int}
 */
class EventDispatcher
{
    private static ?self $instance = null;
    /** @var array<string, list<EventListenerEntry>> */
    private array $listeners = [];
    /** @var array<string, list<EventListenerEntry>> */
    private array $patternListeners = [];  // Store pattern-based listeners for wildcard support
    /** @var array<string, list<EventListenerEntry>> */
    private array $bootstrapListeners = [];
    private Queue $queue;
    private bool $propagateListenerExceptions = false;
    private int $nextListenerSequence = 0;

    #[\Core\Attributes\Inject]
    private Container $container;

    public function __construct(
        Queue $queue,
        private ?\App\Services\AuditTrail $auditTrail = null
    ) {
        $this->queue = $queue;
        $this->container = Container::getInstance();
    }

    /**
     * Get the Container instance — uses injected property when available,
     * falls back to Container::getInstance() when created outside the container.
     */
    private function getContainer(): Container
    {
        return $this->container ?? Container::getInstance();
    }

    /**
     * دریافت Instance (Singleton)
     * Queue را از Container تزریق شده دریافت می‌کند
     */
    /**
     * دریافت Instance (Singleton)
     * M22 Fix: واگذاری و تکیه صددرصدی به Container رسمی پروژه برای تزریق وابستگی‌ها (Pure DI)
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $container = Container::getInstance();
            
            if ($container->has(self::class)) {
                $resolved = $container->make(self::class);
                if (!$resolved instanceof self) {
                    throw new \RuntimeException('Container returned invalid EventDispatcher instance.');
                }
                self::$instance = $resolved;
            } else {
                // ساخت داینامیک با کانتینر و ثبت به عنوان تک‌عضو (Singleton) سراسری
                $resolved = $container->make(self::class);
                if (!$resolved instanceof self) {
                    throw new \RuntimeException('Container returned invalid EventDispatcher instance.');
                }
                $container->instance(self::class, $resolved);
                self::$instance = $resolved;
            }
        }
        
        return self::$instance;
    }

    /**
     * ثبت Listener (مستقیم بر اساس نام دقیق رویداد)
     *
     * @param EventListener $listener
     */
    public function listen(string $eventName, callable|string|array $listener, int $priority = 0): void
    {
        $this->assertValidEventName($eventName, false);
        $listener = $this->validatedListener($listener);

        if (!isset($this->listeners[$eventName])) {
            $this->listeners[$eventName] = [];
        }

        // بررسی یکتا بودن Listener برای جلوگیری از تجمع حافظه (Memory Leak)
        foreach ($this->listeners[$eventName] as $existing) {
            if ($existing['listener'] === $listener) {
                return; // از قبل ثبت شده است، دوباره ثبت نکن
            }
        }

        $this->listeners[$eventName][] = [
            'listener' => $listener,
            'priority' => $priority,
            'sequence' => $this->nextListenerSequence++,
        ];

        usort($this->listeners[$eventName], self::compareListenerEntries(...));
    }

    /**
     * ثبت Listener بر اساس الگوی wildcard (مثل: wallet.*, *.revenue.*)
     * از fnmatch() استفاده می‌کند برای مطابقت‌دهی الگو.
     * Listeners منطبق فقط برای رویدادهای جدید اجرا می‌شوند، نه برای قدیمی‌ها.
     */
    /** @param EventListener $listener */
    public function listenPattern(string $pattern, callable|string|array $listener, int $priority = 0): void
    {
        $this->assertValidEventName($pattern, true);
        $listener = $this->validatedListener($listener);

        if (!isset($this->patternListeners[$pattern])) {
            $this->patternListeners[$pattern] = [];
        }

        foreach ($this->patternListeners[$pattern] as $existing) {
            if ($existing['listener'] === $listener) {
                return;
            }
        }

        $this->patternListeners[$pattern][] = [
            'listener' => $listener,
            'priority' => $priority,
            'sequence' => $this->nextListenerSequence++,
        ];

        usort($this->patternListeners[$pattern], self::compareListenerEntries(...));
    }

    /**
     * ثبت شنوندگان پایه به عنوان مرجع برای ریست کردن (Snapshot)
     */
    public function snapshotBootstrapState(): void
    {
        $this->bootstrapListeners = $this->listeners;
    }

    /**
     * ریست کردن تمامی شنونده‌ها به حالت پیش‌فرضِ اولیه‌ی بوت‌استرپ
     */
    public function restoreBootstrapState(): void
    {
        // بازگردانی شنونده‌ها به حالت اولیه (حذف هرگونه Closure اضافه شده در Job)
        $this->listeners = $this->bootstrapListeners;
        
        // نمی‌خواهیم الگوی listeners را ریست کنیم؛ آنها runtime pattern listeners هستند
        // و باید برای تمام درخواست‌های بعدی فعال بمانند.
        // $this->patternListeners = [];
        
        // Note: $this->auditTrail is injected — do not null it here
        // to preserve the reference across restore cycles.
    }

    /** @var list<string> */
    private array $dispatchingStack = [];

    /**
     * Strict delivery mode for transactional outbox consumers.
     * Normal in-process dispatch remains resilient, while outbox delivery must
     * propagate listener failures so the event can be retried or moved to DLQ.
     */
    public function dispatchOrFail(string $eventName, mixed $event = null): void
    {
        $previous = $this->propagateListenerExceptions;
        $this->propagateListenerExceptions = true;
        try {
            $this->dispatch($eventName, $event);
        } finally {
            $this->propagateListenerExceptions = $previous;
        }
    }

    /**
     * ارسال رویداد
     */
    public function dispatch(string $eventName, mixed $event = null): void
    {
        $this->assertValidEventName($eventName, false);

        // اصلاح کلیدی معماری سیستم‌های توزیع‌شده (Recursive Event Loop Shield):
        // اعتبارسنجی پشته رویدادهای در حال اجرا جهت جلوگیری از افتادن سیستم در حلقه باطل دیسپاچینگ بازگشتی و اتمام حافظه (OOM)
        if (in_array($eventName, $this->dispatchingStack, true)) {
            if (function_exists('logger')) {
                try { logger()->warning('event.recursive_dispatch_prevented', ['event' => $eventName]); } catch (\Throwable $e) {}
            }
            return;
        }
        $this->dispatchingStack[] = $eventName;

        try {
            // جمع‌آوری تمام Listeners: هم دقیق و هم الگو‌based
            $allListeners = [];

            // Exact listeners
            if (isset($this->listeners[$eventName])) {
                $allListeners = array_merge($allListeners, $this->listeners[$eventName]);
            }

            // Pattern-based listeners (خیلی جستجو می‌شود نه efficient نیست اما انعطاف‌پذیر)
            foreach ($this->patternListeners as $pattern => $listeners) {
                if (fnmatch($pattern, $eventName)) {
                    $allListeners = array_merge($allListeners, $listeners);
                }
            }

            // Exact and wildcard registrations form one priority domain.
            usort($allListeners, self::compareListenerEntries(...));

            // اگر هیچ Listener نبود
            if (empty($allListeners)) {
                return;
            }

            // اگر Event شیء نبود، آن را به آرایه تبدیل کن
            if (!$event instanceof Event) {
                $eventData = is_array($event) ? $event : ['value' => $event];
                $event = new GenericEvent($eventData);
            }
            
            foreach ($allListeners as $item) {
                $listener = $item['listener'];
                
                $startTime = microtime(true);
                try {
                    // اجرای Listener
                    if (is_callable($listener)) {
                        $listener($event);
                    } elseif (is_string($listener) && class_exists($listener)) {
                        $listenerInstance = $this->getContainer()->has($listener) ? $this->getContainer()->make($listener) : new $listener();
                        if (method_exists($listenerInstance, 'handle')) {
                            $listenerInstance->handle($event);
                        }
                    } elseif (is_array($listener) && is_string($listener[0]) && class_exists($listener[0])) {
                        $listenerInstance = $this->getContainer()->has($listener[0]) ? $this->getContainer()->make($listener[0]) : new $listener[0]();
                        $methodName = $listener[1] ?? 'handle';
                        if (method_exists($listenerInstance, $methodName)) {
                            $listenerInstance->$methodName($event);
                        }
                    }
                } catch (\Throwable $e) {
                    $listenerName = is_string($listener) ? $listener : (is_array($listener) && is_string($listener[0]) ? $listener[0] : 'closure');
                    
                    // 1. لاگ کردن در سامانه لاگ اصلی (ایمن شده در برابر خطای خود لاگر)
                    try {
                        if (function_exists('logger')) { logger()->error('event.listener_failed', [
                                'event' => $eventName,
                                'listener' => $listenerName,
                                'error' => $e->getMessage()
                            ]); }
                    } catch (\Throwable $logEx) {
                        error_log("Failed to write to primary logger: " . $logEx->getMessage());
                    }

                    // 2. ثبت در Dead-Letter / Event Failure Log اختصاصی دیتابیس برای بازیابی و تحلیل
                    try {
                        if ($this->getContainer()->has(Database::class)) {
                            $db = $this->getContainer()->make(Database::class);
                            $db->execute("
                                INSERT INTO event_failures (event_name, listener, payload, error_message, failed_at)
                                VALUES (?, ?, ?, ?, NOW())
                            ", [
                                $eventName,
                                $listenerName,
                                json_encode($event->getData(), JSON_UNESCAPED_UNICODE),
                                $e->getMessage() . "\n" . $e->getTraceAsString()
                            ]);
                        }
                    } catch (\Throwable $dbEx) {
                        // در صورت خطای دیتابیس، مانع از انتشار بقیه لیسنرها نشود (ایزولاسیون کامل)
                        error_log("Failed to write to event_failures table: " . $dbEx->getMessage());
                    }

                    if ($this->propagateListenerExceptions) {
                        throw $e;
                    }
                }

                $duration = microtime(true) - $startTime;
                if ($duration > 5.0) {
                    try {
                        if (function_exists('logger')) { logger()->warning('event.listener_timeout', [
                                'event' => $eventName,
                                'listener' => is_string($listener) ? $listener : 'closure',
                                'duration' => round($duration, 2) . 's',
                                'threshold' => '5.0s'
                            ]); }
                    } catch (\Throwable $timeoutLogEx) {
                        error_log("Failed to log listener timeout warning: " . $timeoutLogEx->getMessage());
                    }
                }
                
                // بررسی توقف انتشار
                if ($event->isPropagationStopped()) {
                    break;
                }
            }
            
            // M23 Fix: سانسور هوشمند و ایمن سازی اطلاعات حساس قبل از تبدیل به JSON جهت ثبت در لاگ سیستم
            $rawPayload = $event->getData();
            $maskedPayload = is_array($rawPayload) ? $this->maskSensitiveData($rawPayload) : $rawPayload;
            
            $encoded = json_encode($maskedPayload, JSON_UNESCAPED_UNICODE);
            $preview = $encoded !== false ? mb_substr($encoded, 0, 2000) : null;
            
            try {
                if (function_exists('logger')) { logger()->info('event.dispatched', [
                        'channel'      => 'event',
                        'event_name'   => $eventName,
                        'data_preview' => $preview,
                        'data_size'    => $encoded !== false ? strlen($encoded) : null,
                    ]); }
            } catch (\Throwable $infoLogEx) {
                error_log("Failed to log event dispatched info: " . $infoLogEx->getMessage());
            }

            // 🛡️ Audit Fix: غیرفعال — همه Auditها از طریق AuditTrail::record() explicit انجام می‌شود
            // خودکار همه EVENTS را audit می‌کرد که باعث duplication و noise می‌شد
            // $this->auditDispatchedEvent($eventName, $event);
        } finally {
            array_pop($this->dispatchingStack);
        }
    }

    private function auditDispatchedEvent(string $eventName, Event $event): void
    {
        try {
            // Avoid auditing audit-record events themselves to prevent recursion/duplication
            if ($event instanceof \App\Events\AuditRecordedEvent || $eventName === \App\Events\AuditRecordedEvent::class) {
                return;
            }

            $auditTrail = $this->resolveAuditTrail();
            if ($auditTrail === null) {
                return;
            }

            $normalizedEventName = $this->normalizeEventName($eventName);
            $eventData = $event->getData();
            $userId = null;
            $actorId = null;

            if (is_array($eventData)) {
                $userId = $eventData['user_id'] ?? $eventData['userId'] ?? $eventData['user'] ?? null;
                $actorId = $eventData['actor_id'] ?? $eventData['actorId'] ?? $eventData['admin_id'] ?? $eventData['adminId'] ?? null;
            }

            $auditTrail->record(
                $normalizedEventName,
                is_int($userId) ? $userId : null,
                [
                    'event_class' => get_class($event),
                    'event_data' => $eventData,
                    'source' => 'event_dispatcher',
                    '_dispatched_at' => date('Y-m-d H:i:s')
                ],
                is_int($actorId) ? $actorId : null
            );
        } catch (\Throwable $e) {
            if (function_exists('logger')) { logger()->warning('event.audit.record_failed', [
                    'event_name' => $eventName,
                    'error' => $e->getMessage(),
                ]); }
        }
    }

    private function resolveAuditTrail(): ?\App\Services\AuditTrail
    {
        return $this->auditTrail;
    }

    private function normalizeEventName(string $eventName): string
    {
        if (!class_exists($eventName)) {
            return $eventName;
        }

        $parts = explode('\\', $eventName);
        $className = end($parts);
        if (!is_string($className) || $className === '') {
            return $eventName;
        }

        $withoutSuffix = preg_replace('/Event$/', '', $className);
        if (!is_string($withoutSuffix) || $withoutSuffix === '') {
            return $eventName;
        }

        $normalized = preg_replace('/([a-z])([A-Z])/', '$1.$2', $withoutSuffix);
        if (!is_string($normalized) || $normalized === '') {
            return $eventName;
        }

        return strtolower($normalized);
    }

    /**
     * ارسال رویداد به صورت async (از طریق Queue)
     */
    public function dispatchAsync(string $eventName, mixed $event = null, string $queue = 'default'): void
    {
        // اگر Event شیء نبود، آن را به آرایه تبدیل کن
            if (!$event instanceof Event) {
                $eventData = is_array($event) ? $event : ['value' => $event];
                $event = new GenericEvent($eventData);
            }

            // اضافه کردن به Queue
        $this->queue->push('dispatch_event', [
            'event_name' => $eventName,
            'event_data' => $event->getData(),
            'event_class' => get_class($event),
        ], $queue);

        // لاگ
        if (function_exists('logger')) { logger()->info('event.queued', [
                'channel' => 'event',
                'event_name' => $eventName,
                'queue' => $queue
            ]); }
    }

    /**
     * پردازش رویداد از Queue
     */
    /** @param array<string, mixed> $job */
    public function processQueuedEvent(array $job): void
    {
        $payload = is_array($job['data'] ?? null) ? $job['data'] : [];
        $eventNameValue = $payload['event_name'] ?? null;
        $eventData = $payload['event_data'] ?? null;
        $eventClass = $payload['event_class'] ?? null;

        if (!is_string($eventNameValue) || trim($eventNameValue) === '') {
            try {
                if (function_exists('logger')) { logger()->warning('event.queue.missing_payload', [
                        'job_id' => $job['id'] ?? null,
                        'payload' => $payload,
                    ]); }
            } catch (\Throwable $logEx) {
                error_log("Failed to log missing payload warning: " . $logEx->getMessage());
            }
            return;
        }

        $eventName = $eventNameValue;

        try {
            $event = null;

            // بازسازی Event object از کلاس و دیتای JSON (بدون unserialize برای امنیت)
            if ($eventClass && class_exists(str_value($eventClass))) {
                $eventClassStr = str_value($eventClass);
                if (method_exists($eventClassStr, 'fromPayload')) {
                    $event = $eventClassStr::fromPayload($eventData);
                } else {
                    $event = $this->reconstructEvent($eventClassStr, $eventData);
                }
            }

            if ($event === null) {
                // لاگ هشدار: بازسازی typed event ناموفق بود — fallback به GenericEvent
                error_log("[EventDispatcher] reconstruction failed for {$eventClass} in event '{$eventName}', falling back to GenericEvent");
                try {
                    if (function_exists('logger')) { logger()->warning('event.queue.reconstruction_downgraded', [
                            'event_name' => $eventName,
                            'event_class' => $eventClass,
                            'job_id' => $job['id'] ?? null,
                            'data_keys' => is_array($eventData) ? array_keys($eventData) : null,
                        ]); }
                } catch (\Throwable $ignore) {}
                $event = new GenericEvent(is_array($eventData) ? $eventData : []);
            }

            if (!$event instanceof Event) {
                throw new \Exception("Reconstructed object is not an instance of Core\\Event");
            }

            // dispatch عادی
            $this->dispatch($eventName, $event);

        } catch (\Throwable $e) {
            $listenerName = 'queue_worker_reconstruction';

            // 1. لاگ کردن در سامانه لاگ اصلی (ایمن شده در برابر خطای خود لاگر)
            try {
                if (function_exists('logger')) { logger()->error('event.queue.reconstruction_failed', [
                        'event' => $eventName,
                        'job_id' => $job['id'] ?? null,
                        'error' => $e->getMessage()
                    ]); }
            } catch (\Throwable $logEx) {
                error_log("Failed to write queue reconstruction failure to primary logger: " . $logEx->getMessage());
            }

            // 2. ثبت در Dead-Letter / Event Failure Log اختصاصی دیتابیس
            try {
                if ($this->getContainer()->has(Database::class)) {
                    $db = $this->getContainer()->make(Database::class);
                    $db->execute("
                        INSERT INTO event_failures (event_name, listener, payload, error_message, failed_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ", [
                        $eventName,
                        $listenerName,
                        json_encode($eventData ?? $payload, JSON_UNESCAPED_UNICODE),
                        $e->getMessage() . "\n" . $e->getTraceAsString()
                    ]);
                }
            } catch (\Throwable $dbEx) {
                error_log("Failed to write queue reconstruction failure to event_failures table: " . $dbEx->getMessage());
            }
        }
    }

    /**
     * بازسازی امن Event از کلاس و دیتای JSON (جایگزین unserialize)
     *
     * ابتدا سعی می‌کند آرایه را مستقیم به constructor بدهد (برای Event‌هایی مثل GenericEvent/WithdrawalEvent).
     * اگر TypeError رخ داد، با Reflection پارامترهای constructor را از آرایه‌ی event_data map می‌کند.
     * این روش امن است چون فقط مقادیر scalar/array از JSON می‌آیند و هیچ object instantiation دلخواهی ندارد.
     */
    private function reconstructEvent(string $eventClass, mixed $eventData): ?Event
    {
        // مسیر ۱: constructor تک‌آرگومانی آرایه‌ای (مثل GenericEvent, WithdrawalEvent)
        try {
            $event = new $eventClass($eventData);
            if ($event instanceof Event) {
                return $event;
            }
        } catch (\TypeError $e) {
            // ادامه به مسیر ۲
        }

        // مسیر ۲: بازسازی با Reflection از روی event_data
        if (!is_array($eventData)) {
            return null;
        }

        try {
            /** @var class-string<object> $eventClass */
            $ref = new \ReflectionClass($eventClass);
            $constructor = $ref->getConstructor();
            if ($constructor === null) {
                $event = $ref->newInstance();
                return $event instanceof Event ? $event : null;
            }

            $args = [];
            foreach ($constructor->getParameters() as $param) {
                $name = $param->getName();
                // تبدیل camelCase به snake_case برای مطابقت با کلیدهای event_data
                $snakeName = strtolower(str_value(preg_replace('/[A-Z]/', '_$0', $name)));

                if (array_key_exists($name, $eventData)) {
                    $value = $eventData[$name];
                } elseif (array_key_exists($snakeName, $eventData)) {
                    $value = $eventData[$snakeName];
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                    continue;
                } else {
                    // پارامتر اجباری پیدا نشد — بازسازی ناممکن
                    error_log("[EventDispatcher] reconstructEvent({$eventClass}): required param '{$name}' (snake: '{$snakeName}') not found in event_data keys: " . implode(',', array_keys($eventData)));
                    return null;
                }

                // تبدیل نوع برای DateTimeInterface
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();
                    if (is_a($typeName, \DateTimeInterface::class, true) && is_string($value)) {
                        $value = new \DateTimeImmutable($value);
                    }
                }

                $args[] = $value;
            }

            $event = $ref->newInstanceArgs($args);
            return ($event instanceof Event) ? $event : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param EventListenerEntry $left
     * @param EventListenerEntry $right
     */
    private static function compareListenerEntries(array $left, array $right): int
    {
        $priorityOrder = $right['priority'] <=> $left['priority'];

        return $priorityOrder !== 0
            ? $priorityOrder
            : $left['sequence'] <=> $right['sequence'];
    }

    /**
     * @param callable|string|array<mixed> $listener
     * @return EventListener
     */
    private function validatedListener(callable|string|array $listener): callable|string|array
    {
        if (is_callable($listener)) {
            return $listener;
        }

        if (is_string($listener)) {
            if (!class_exists($listener) || !method_exists($listener, 'handle')) {
                throw new \InvalidArgumentException(
                    "Event listener class '{$listener}' must exist and define a public handle method."
                );
            }

            $method = new \ReflectionMethod($listener, 'handle');
            if (!$method->isPublic()) {
                throw new \InvalidArgumentException(
                    "Event listener class '{$listener}' must define a public handle method."
                );
            }

            return $listener;
        }

        if (
            array_is_list($listener)
            && count($listener) === 2
            && is_string($listener[0])
            && class_exists($listener[0])
            && is_string($listener[1])
            && $listener[1] !== ''
            && method_exists($listener[0], $listener[1])
        ) {
            $method = new \ReflectionMethod($listener[0], $listener[1]);
            if ($method->isPublic()) {
                return [$listener[0], $listener[1]];
            }
        }

        throw new \InvalidArgumentException(
            'Event listener must be callable, a listener class with public handle(), or [class-string, public-method].'
        );
    }

    private function assertValidEventName(string $eventName, bool $pattern): void
    {
        if ($eventName === '' || trim($eventName) !== $eventName) {
            throw new \InvalidArgumentException('Event name must be non-empty and must not contain surrounding whitespace.');
        }

        $allowedCharacters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.:-\\';
        if ($pattern) {
            $allowedCharacters .= '*?[]!';
        }

        if (strspn($eventName, $allowedCharacters) !== strlen($eventName)) {
            throw new \InvalidArgumentException('Event name contains unsupported characters.');
        }
    }

    /**
     * حذف Listener
     */
    public function forget(string $eventName): void
    {
        $this->assertValidEventName($eventName, false);
        unset($this->listeners[$eventName]);
    }

    /**
     * دریافت تمام Listeners
     *
     * @return ($eventName is null ? array<string, list<EventListenerEntry>> : list<EventListenerEntry>)
     */
    public function getListeners(?string $eventName = null): array
    {
        if ($eventName === null) {
            return $this->listeners;
        }

        $this->assertValidEventName($eventName, false);
        return $this->listeners[$eventName] ?? [];
    }

    /**
     * جلوگیری از Clone
     */
    private function __clone() {}

    /**
     * جلوگیری از Unserialize
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }

    /**
     * M23 Fix: شناسایی و سانسور کردن اطلاعات حساس به صورت بازگشتی جهت امنیت در فایل لاگ
     */
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function maskSensitiveData(array $data): array
    {
        $sensitivePatterns = ['password', 'pwd', 'token', 'cvv', 'secret', 'card', 'pin', 'pan', 'key', 'auth', 'credential', 'ssn'];
        $result = [];
        
        foreach ($data as $key => $value) {
            $isSensitive = false;
            $keyStr = (string)$key;
            
            foreach ($sensitivePatterns as $pattern) {
                if (stripos($keyStr, $pattern) !== false) {
                    $isSensitive = true;
                    break;
                }
            }
            
            if ($isSensitive) {
                $result[$key] = '******** (masked)';
            } elseif (is_array($value)) {
                $result[$key] = $this->maskSensitiveData($value);
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }
}
