<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class AlertRequestedEvent extends Event
{
    /** @var array<string, mixed> */
    public array $alert;

    /**
     * @param array<string, mixed> $alert
     */
    public function __construct(array $alert) {
        parent::__construct($alert);
        $this->alert = $alert;
    }
}
