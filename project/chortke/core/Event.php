<?php
namespace Core;

/**
 * Event Class
 * 
 * کلاس پایه برای رویدادها
 */
abstract class Event
{
    /** @var array<string, mixed> */
    protected array $data = [];
    protected bool $stopped = false;

    /** @param array<string, mixed> $data */
    public function __construct(mixed $data = [])
    {
        $this->data = is_array($data) ? $data : ['value' => $data];
    }

    /**
     * دریافت داده
     *
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function getData(mixed $key = null): mixed
    {
        if ($key === null) {
            return $this->data;
        }
        return $this->data[$key] ?? null;
    }

    /**
     * تنظیم داده
     */
    public function setData(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * توقف انتشار
     */
    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    /**
     * بررسی توقف
     */
    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
