<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

class UnlinkSocialAccountJob
{
    private \Core\Database $db;

    public function __construct(\Core\Database $db)
    {
        $this->db = $db;
    }

/** @return array<string, mixed> */
public function handle(int $userId, string $provider): array
    {
        $ok = $this->db->table('social_accounts')
            ->where('user_id', '=', $userId)
            ->where('provider', '=', $provider)
            ->delete();
        return ['success' => $ok, 'message' => $ok ? 'اتصال حساب با موفقیت جدا شد.' : 'خطا در جدا کردن اتصال حساب.'];
    }
}
