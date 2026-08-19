<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

use App\Services\Auth\PasswordRecoveryService;

class ResetPasswordJob
{
    private PasswordRecoveryService $passwordService;

    public function __construct(
        PasswordRecoveryService $passwordService
    ) {
        $this->passwordService = $passwordService;
    }

    /** @return array<string, mixed> */
public function handle(string $token, string $newPassword, ?string $email = null): array
    {
        return $this->passwordService->resetPassword($token, $newPassword, $email);
    }
}
