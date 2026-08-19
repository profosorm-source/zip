<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class SettingsUpdated extends Event
{
    /** @var list<string> */
    public array $changedKeys;

    /** @param list<string> $changedKeys */
    public function __construct(array $changedKeys = [])
    {
        $this->changedKeys = array_values($changedKeys);
        parent::__construct(['changed_keys' => $this->changedKeys]);
    }
}
