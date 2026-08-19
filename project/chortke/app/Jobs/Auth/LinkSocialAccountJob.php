<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

class LinkSocialAccountJob
{
    private \App\Models\User $userModel;
    private \Core\Database $db;

    public function __construct(
        \App\Models\User $userModel,
        \Core\Database $db
    ) {
        $this->userModel = $userModel;
        $this->db = $db;
    }

/**
 * @param array<string, mixed> $userData
 * @return array<string, mixed>
 */
public function handle(int $userId, string $provider, array $userData): array
    {
        $user = $this->userModel->find($userId);
        if (!$user || in_array($user->status, ['locked', 'banned', 'suspended'], true)) {
            return ['success' => false, 'message' => 'امکان اتصال حساب برای این کاربر وجود ندارد.'];
        }

        // Check if this social account is already linked to ANOTHER user
        $existing = $this->db->table('social_accounts')
            ->where('provider', '=', $provider)
            ->where('provider_id', '=', str_value($userData['id']))
            ->first();
            
        if ($existing) {
            if ((int)$existing->user_id === $userId) {
                return ['success' => true, 'message' => 'این حساب قبلاً به اکانت شما متصل شده است.'];
            }
            return ['success' => false, 'message' => 'این حساب اجتماعی قبلاً به اکانت دیگری متصل شده است.'];
        }

        $ok = $this->db->table('social_accounts')->insert([
            'user_id'     => $userId,
            'provider'    => $provider,
            'provider_id' => str_value($userData['id']),
            'avatar'      => $userData['picture'] ?? null,
            'created_at'  => date('Y-m-d H:i:s')
        ]);

        return ['success' => $ok, 'message' => $ok ? 'حساب با موفقیت متصل شد.' : 'خطا در اتصال حساب.'];
    }
}
