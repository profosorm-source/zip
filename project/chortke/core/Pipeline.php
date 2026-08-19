<?php

declare(strict_types=1);

namespace Core;

use Closure;

/**
 * Pipeline — مدیریت زنجیره‌ی Middlewareها
 * 
 * این کلاس درخواست (Request) را از بین چندین Middleware عبور می‌دهد.
 * هر Middleware می‌تواند پاسخ را تغییر دهد یا جلوی ادامه‌ی مسیر را بگیرد.
 */
class Pipeline
{
    /** @var list<mixed> */
    protected array $pipes = [];
    protected mixed $passable = null;
    private bool $hasPassable = false;
    protected Container $container;

    public function __construct(Container $container) {
        $this->container = $container;
    }

    /**
     * تنظیم آبجکتی که باید از لوله‌ها عبور کند (معمولاً Request)
     */
    public function send(mixed $passable): self
    {
        $this->passable = $passable;
        $this->hasPassable = true;
        return $this;
    }

    /**
     * تنظیم لیست Middlewareها
     *
     * @param list<mixed> $pipes
     */
    public function through(array $pipes): self
    {
        foreach ($pipes as $pipe) {
            if (is_string($pipe)) {
                if (trim($pipe) === '') {
                    throw new \InvalidArgumentException('Middleware pipe string must not be empty.');
                }
                continue;
            }
            if (!is_object($pipe) && !is_callable($pipe)) {
                throw new \InvalidArgumentException('Middleware pipe must be a class string, object, or callable.');
            }
        }

        $this->pipes = array_values($pipes);
        return $this;
    }

    /**
     * اجرای زنجیره و در نهایت اجرای Callback مقصد
     */
    public function then(Closure $destination): mixed
    {
        if (!$this->hasPassable) {
            throw new \LogicException('Pipeline::send() must be called before Pipeline::then().');
        }

        $pipeline = array_reduce(
            array_reverse($this->pipes),
            $this->carry(),
            $this->prepareDestination($destination)
        );

        return $pipeline($this->passable);
    }

    /**
     * آماده‌سازی مقصد نهایی (Controller Action)
     */
    protected function prepareDestination(Closure $destination): Closure
    {
        return function ($passable) use ($destination) {
            return $destination($passable);
        };
    }

    /**
     * ایجاد حلقه‌ی اتصال بین Middlewareها
     */
    protected function carry(): Closure
    {
        return function ($stack, $pipe) {
            return function ($passable) use ($stack, $pipe) {
                $parameters = [];

                if (is_string($pipe)) {
                    $pipeClass = $pipe;
                    if (str_contains($pipeClass, ':')) {
                        [$pipeClass, $parameterString] = explode(':', $pipeClass, 2);
                        $parameters = $parameterString === ''
                            ? []
                            : array_map('trim', explode(',', $parameterString));
                    }
                    if (!class_exists($pipeClass)) {
                        throw new \RuntimeException("Middleware class '{$pipeClass}' does not exist.");
                    }
                    $pipe = $this->container->make($pipeClass);
                }

                // H10 Ultimate Fix: بستن زنجیره استثناهای پاسخ به صورت پله‌ای به عقب
                // ایجاد یک Wrapper برای استک بعدی، تا در صورت بروز هرگونه Exception در لایه‌های درونی‌تر
                // به شیء خروجی معتبر تبدیل شده و وارد فاز After-Middleware این لایه شود.
                $wrappedStack = function ($req) use ($stack) {
                    try {
                        return $stack($req);
                    } catch (\Core\Exceptions\HttpResponseException $e) {
                        return $e->getResponse();
                    }
                };

                // H20 Fix: اولویت دادن به متد صریح handle بر روی متد جادویی invoke
                if (is_object($pipe)) {
                    $handler = [$pipe, 'handle'];
                    if (is_callable($handler)) {
                        return $handler($passable, $wrappedStack, ...$parameters);
                    }
                }

                if (is_callable($pipe)) {
                    return $pipe($passable, $wrappedStack, ...$parameters);
                }

                throw new \RuntimeException("Middleware " . (is_object($pipe) ? get_class($pipe) : gettype($pipe)) . " must have a handle() method.");
            };
        };
    }
}
