<?php

declare(strict_types=1);

namespace App\Traits;

use App\Contracts\ValidatorFactoryInterface;

/**
 * UsesValidatorFactory — Trait برای استفاده تزریق‌شده از ValidatorFactory در کنترلرها و سرویس‌ها
 *
 * استفاده در Controller:
 *   $validator = $this->validatorFactory()->make($data, $rules);
 *
 * متد makeValidator() حذف شد — چون همه جا مستقیم از validatorFactory()->make() استفاده می‌شود.
 */
trait UsesValidatorFactory
{
    private ?ValidatorFactoryInterface $validatorFactory = null;

    /**
     * دریافت نمونه ValidatorFactoryInterface
     * lazy: مقدار از constructor inject می‌شود و throw می‌کند اگر نشده باشد.
     */
    protected function validatorFactory(): ValidatorFactoryInterface
    {
        if ($this->validatorFactory === null) {
            throw new \LogicException(
                'ValidatorFactory not initialized. ' .
                'Ensure it is injected via constructor or setValidatorFactory().'
            );
        }
        return $this->validatorFactory;
    }

    public function setValidatorFactory(ValidatorFactoryInterface $factory): void
    {
        $this->validatorFactory = $factory;
    }
}
