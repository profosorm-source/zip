<?php

declare(strict_types=1);

namespace App\Validators;

use Core\Validator;
use Core\Exceptions\ValidationException;
use App\Contracts\ValidatorFactoryInterface;

abstract class BaseFormRequest
{
    /** @var array<string, mixed> */
    protected array $data;
    /** @var array<string, list<string>> */
    protected array $errors = [];
    /** @var array<string, mixed>|null */
    protected ?array $validated = null;

    private static ?ValidatorFactoryInterface $validatorFactory = null;

    /** @param array<string, mixed> $data */
    public function __construct(array $data = []) {
        $this->data = $data;
    }

    /**
     * تنظیم ValidatorFactory سراسری — باید در bootstrap/AppServiceProvider فراخوانی شود
     */
    public static function setValidatorFactory(ValidatorFactoryInterface $factory): void
    {
        self::$validatorFactory = $factory;
    }

    /** @return array<string, mixed> */
    abstract public function rules(): array;

    /** @return array<string, mixed> */
    public function messages(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function validate(): bool
    {
        if (!$this->authorize()) {
            $this->errors = ['authorization' => ['Unauthorized request.']];
            return false;
        }

        if (self::$validatorFactory !== null) {
            $validator = self::$validatorFactory->make($this->data, $this->rules(), $this->messages());
        } else {
            $validator = new Validator($this->data, $this->rules());
        }

        if ($validator->fails()) {
            $this->errors = $validator->errors();
            return false;
        }

        $this->validated = $validator->data();
        return true;
    }

    /**
     * Validate and throw ValidationException on failure.
     * Simplifies controller code: one call instead of validate()+if(fails()).
     *
     * @return array Validated data
     * @throws ValidationException
     */
    /** @return array<string, mixed> */
    public function validateOrFail(): array
    {
        if (!$this->validate()) {
            throw new ValidationException(
                $this->errors,
                'اطلاعات ورودی نامعتبر است'
            );
        }
        return $this->validated ?? [];
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        return $this->validated ?? [];
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    /** @return array<string, mixed> */
    public function errors(): array
    {
        return $this->errors;
    }
}
