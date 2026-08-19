<?php

namespace Core;

/**
 * Generic Event (برای رویدادهای ساده)
 *
 * قابل استفاده در هر جایی که نیاز به Event سریع بدون ساخت کلاس اختصاصی دارید.
 * DomainEvent interface رو implement می‌کنه تا با recordEvent سازگار باشه.
 *
 * PSR-4 autoload: Core\GenericEvent → core/GenericEvent.php
 */
class GenericEvent extends Event implements \App\Contracts\DomainEvent
{
    public function aggregateType(): string
    {
        $agg = $this->getData('aggregate_type');
        if (is_string($agg) && $agg !== '') return $agg;
        $evt = $this->getData('event_name');
        if (is_string($evt) && $evt !== '') return $evt;
        return 'general';
    }

    public function aggregateId(): string
    {
        $data = (array)$this->getData();
        foreach (['user_id', 'userId', 'id', 'aggregate_id'] as $key) {
            if (isset($data[$key])) {
                return str_value($data[$key]);
            }
        }
        return '0';
    }

    public function toPayload(): array
    {
        $data = (array)$this->getData();
        return is_array($data) ? $data : [];
    }
}
