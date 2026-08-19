<?php

declare(strict_types=1);

namespace App\Contracts;

use Core\Database;

interface ValidatorFactoryInterface
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $messages
     */
    public function make(array $data, array $rules = [], array $messages = [], ?Database $db = null): \Core\Validator;
}
