<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ValidatorFactoryInterface;
use Core\Database;

class ValidatorFactory implements ValidatorFactoryInterface
{


    public function __construct(
        private Database $db
    ) {}

    /**
     * ساختن یک Validator با پشتیبانی پیام‌های سفارشی
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $messages
     */
    public function make(array $data, array $rules = [], array $messages = [], ?Database $db = null): \Core\Validator
    {
        $validator = new \Core\Validator($data, $rules, $db ?? $this->db);
        if (!empty($messages)) {
            $validator->messages($messages);
        }
        return $validator;
    }
}
