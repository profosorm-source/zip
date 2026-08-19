<?php
// app/Services/SocialAccountService.php

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\User;
use Core\Session;

use App\Contracts\LoggerInterface;
class SocialAccountService
{
    private \App\Models\Notification $notificationModel;
    private \App\Models\SocialAccount $socialAccountModel;
    private \App\Models\User $userModel;

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        \App\Models\SocialAccount $socialAccountModel,
        \App\Models\User $userModel,
        \App\Models\Notification $notificationModel
    ) {        $this->logger = $logger;

                $this->socialAccountModel = $socialAccountModel;
        $this->userModel = $userModel;
        $this->notificationModel = $notificationModel;
    }

    /**
     * ROOT FIX (principled): Centralized `toObject` helper (standard pattern).
     * Guarantees ?object from DB results (Model.find / raw). Guard with isset($x->id) before access.
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        /** @var \stdClass $obj */
        $obj = is_object($data) ? $data : (object)(is_array($data) ? $data : (array)$data);
        return $obj;
    }


    /** Canonicalize social-account payloads from web forms, API clients and legacy aliases. */
    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    private function normalizeAccountData(array $data): array
    {
        $platformVal = $data['platform'] ?? $data['provider'] ?? null;
        $platform = trim(is_scalar($platformVal) ? (string)$platformVal : '');
        $usernameVal = $data['username'] ?? $data['provider_user_id'] ?? $data['account_handle'] ?? null;
        $username = trim(is_scalar($usernameVal) ? (string)$usernameVal : '');
        $data['platform'] = $platform;
        $data['username'] = $username;
        $profileUrl = $data['profile_url'] ?? ($platform !== '' && $username !== '' ? 'https://example.com/' . rawurlencode($platform) . '/' . rawurlencode($username) : '');
        $data['profile_url'] = is_scalar($profileUrl) ? (string)$profileUrl : '';
        $data['follower_count'] = (int)(is_numeric($data['follower_count'] ?? null) ? $data['follower_count'] : 0);
        $data['following_count'] = (int)(is_numeric($data['following_count'] ?? null) ? $data['following_count'] : 0);
        $data['post_count'] = (int)(is_numeric($data['post_count'] ?? null) ? $data['post_count'] : 0);
        $data['engagement_rate'] = (float)(is_numeric($data['engagement_rate'] ?? null) ? $data['engagement_rate'] : 0);
        $data['account_age_months'] = (int)(is_numeric($data['account_age_months'] ?? null) ? $data['account_age_months'] : 0);
        return $data;
    }

    /** Validate canonical social-account payload before model calls. */
    /** @return array{success: bool, message: string}|null */
    /** @param array<string, mixed> $data
     *  @return array{success: bool, message: string}|null */
    private function validateNormalizedAccountData(array $data): ?array
    {
        if (($data['platform'] ?? '') === '' || ($data['username'] ?? '') === '') {
            return ['success' => false, 'message' => 'پلتفرم و نام کاربری الزامی است.'];
        }
        return null;
    }

    /**
     * ثبت حساب اجتماعی جدید
     */
    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    public function register(int $userId, array $data): array
    {
        $data = $this->normalizeAccountData($data);
        $invalid = $this->validateNormalizedAccountData($data);
        if ($invalid !== null) {
            return $invalid;
        }

        // بررسی وجود کاربر
        $user = $this->toObject($this->userModel->find($userId));
        if (!$user) { 
        return ['success' => false, 'message' => 'کاربر یافت نشد.'];
        }

        // بررسی تکراری نبودن
        if ($this->socialAccountModel->existsByPlatformAndUsername((string)(is_scalar($data['platform'] ?? null) ? $data['platform'] : ''), (string)(is_scalar($data['username']) ? $data['username'] : ''))) {
            return ['success' => false, 'message' => 'این نام کاربری قبلاً در این پلتفرم ثبت شده است.'];
        }

        // بررسی تعداد حساب‌های کاربر در هر پلتفرم (حداکثر 1)
        $existing = $this->socialAccountModel->findByUserAndPlatform($userId, (string)(is_scalar($data['platform'] ?? null) ? $data['platform'] : ''));
        if ($existing) {
            return ['success' => false, 'message' => 'شما قبلاً یک حساب در این پلتفرم ثبت کرده‌اید.'];
        }

        // بررسی حداقل‌ها
        $validation = $this->validateAccountQuality($data);
        if (!$validation['passed']) {
            return ['success' => false, 'message' => $validation['message']];
        }

        // ایجاد
        $account = $this->socialAccountModel->createAccount([
            'user_id'             => $userId,
            'platform'            => $data['platform'],
            'username'            => $data['username'],
            'profile_url'         => $data['profile_url'],
            'follower_count'      => (int)(is_numeric($data['follower_count'] ?? null) ? $data['follower_count'] : 0),
            'following_count'     => (int)(is_numeric($data['following_count'] ?? null) ? $data['following_count'] : 0),
            'post_count'          => (int)(is_numeric($data['post_count'] ?? null) ? $data['post_count'] : 0),
            'engagement_rate'     => (float)(is_numeric($data['engagement_rate'] ?? null) ? $data['engagement_rate'] : 0),
            'account_age_months'  => (int)(is_numeric($data['account_age_months'] ?? null) ? $data['account_age_months'] : 0),
        ]);

        if (!$account) {
            return ['success' => false, 'message' => 'خطا در ثبت حساب. لطفاً دوباره تلاش کنید.'];
        }

        // لاگ
$this->logger->info('social.account.registered', [
    'channel' => 'social_account',
    'user_id' => $userId,
    'platform' => $data['platform'] ?? null,
    'username' => $data['username'] ?? null,
]);
        return [
            'success' => true,
            'message' => 'حساب شما با موفقیت ثبت شد و در انتظار بررسی قرار گرفت.',
            'account' => $account,
        ];
    }

    /**
     * بررسی کیفیت حساب (ضد فیک)
     */
    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    private function validateAccountQuality(array $data): array
    {
        $platform = $data['platform'];
        $followerCount = (int)(is_numeric($data['follower_count'] ?? null) ? $data['follower_count'] : 0);
        $postCount = (int)(is_numeric($data['post_count'] ?? null) ? $data['post_count'] : 0);
        $accountAge = (int)(is_numeric($data['account_age_months'] ?? null) ? $data['account_age_months'] : 0);

        // حداقل سن حساب: 3 ماه
        if ($accountAge < 3) {
            return [
                'passed'  => false,
                'message' => 'حساب شما باید حداقل ۳ ماه قدمت داشته باشد.',
            ];
        }

        // حداقل تعداد پست
        $minPosts = $this->getMinPosts((string)(is_scalar($platform) ? $platform : ''));
        if ($postCount < $minPosts) {
            return [
                'passed'  => false,
                'message' => "حساب شما باید حداقل {$minPosts} پست/ویدیو داشته باشد.",
            ];
        }

        // حداقل فالوور
        $minFollowers = $this->getMinFollowers((string)(is_scalar($platform) ? $platform : ''));
        if ($followerCount < $minFollowers) {
            return [
                'passed'  => false,
                'message' => "حساب شما باید حداقل {$minFollowers} فالوور/دنبال‌کننده داشته باشد.",
            ];
        }

        // نسبت فالوور به فالووینگ (تشخیص فیک)
        $followingCount = (int)(is_numeric($data['following_count'] ?? null) ? $data['following_count'] : 0);
        if ($followingCount > 0 && $followerCount > 0) {
            $ratio = $followingCount / $followerCount;
            // اگر فالووینگ بیش از 5 برابر فالوور باشد → مشکوک
            if ($ratio > 5) {
                return [
                    'passed'  => false,
                    'message' => 'نسبت فالوور به فالووینگ حساب شما غیرطبیعی است.',
                ];
            }
        }

        return ['passed' => true, 'message' => ''];
    }

    /**
     * حداقل پست بر اساس پلتفرم
     */
    private function getMinPosts(string $platform): int
    {
        $defaults = [
            'instagram' => 10,
            'youtube'   => 5,
            'telegram'  => 0,
            'tiktok'    => 5,
            'twitter'   => 10,
        ];
        return $defaults[$platform] ?? 10;
    }

    /**
     * حداقل فالوور بر اساس پلتفرم
     */
    private function getMinFollowers(string $platform): int
    {
        $defaults = [
            'instagram' => 50,
            'youtube'   => 20,
            'telegram'  => 0,
            'tiktok'    => 30,
            'twitter'   => 20,
        ];
        return $defaults[$platform] ?? 50;
    }

    /**
     * تایید حساب توسط ادمین
     */
    /** @return array<string, mixed> */
    public function verify(int $accountId, int $adminId): array
    {
        /** @var \stdClass|null $account */
        $account = $this->toObject($this->socialAccountModel->find($accountId));
        if (!$account) { 
        return ['success' => false, 'message' => 'حساب یافت نشد.'];
        }

        if ($account->status === 'verified') {
            return ['success' => false, 'message' => 'این حساب قبلاً تایید شده است.'];
        }

        $result = $this->socialAccountModel->update($accountId, [
            'status'      => 'verified',
            'verified_by' => $adminId,
            'verified_at' => \date('Y-m-d H:i:s'),
        ]);

        if (!$result) {
            return ['success' => false, 'message' => 'خطا در تایید حساب.'];
        }

        $this->logger->info("Admin {$adminId} verified social account #{$accountId}", ['channel' => 'social_account']);

        // نوتیفیکیشن به کاربر
        $this->notifyUser($account->user_id, 'حساب اجتماعی شما تایید شد', 
            "حساب {$account->username} در " . $this->socialAccountModel->platformLabel($account->platform) . " تایید شد. اکنون می‌توانید تسک‌ها را انجام دهید.",
            'success'
        );

        return ['success' => true, 'message' => 'حساب با موفقیت تایید شد.'];
    }

    /**
     * رد حساب توسط ادمین
     */
    /** @return array<string, mixed> */
    public function reject(int $accountId, int $adminId, string $reason): array
    {
        /** @var \stdClass|null $account */
        $account = $this->toObject($this->socialAccountModel->find($accountId));
        if (!$account) { 
        return ['success' => false, 'message' => 'حساب یافت نشد.'];
        }

        // ثبت تاریخچه رد
        $history = [];
        if ($account->rejection_history) {
            $decoded = \json_decode($account->rejection_history, true);
            $history = is_array($decoded) ? $decoded : [];
        }
        $history[] = [
            'reason'     => $reason,
            'admin_id'   => $adminId,
            'date'       => \date('Y-m-d H:i:s'),
        ];

        $result = $this->socialAccountModel->update($accountId, [
            'status'            => 'rejected',
            'rejection_reason'  => $reason,
            'rejection_history' => \json_encode($history, \JSON_UNESCAPED_UNICODE),
        ]);

        if (!$result) {
            return ['success' => false, 'message' => 'خطا در رد حساب.'];
        }

        $this->logger->info("Admin {$adminId} rejected social account #{$accountId}: {$reason}", ['channel' => 'social_account']);

        // نوتیفیکیشن به کاربر
        $this->notifyUser($account->user_id, 'حساب اجتماعی شما رد شد',
            "حساب {$account->username} رد شد. دلیل: {$reason}",
            'danger'
        );

        return ['success' => true, 'message' => 'حساب با موفقیت رد شد.'];
    }

    /**
     * ویرایش حساب توسط کاربر (فقط اگر رد شده یا در انتظار)
     */
    /** @return array{success: bool, message: string} */
    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    public function updateByUser(int $accountId, int $userId, array $data): array
    {
        /** @var \stdClass|null $account */
        $account = $this->toObject($this->socialAccountModel->find($accountId));
        if (!$account || !isset($account->id) || $account->user_id !== $userId) {
            return ['success' => false, 'message' => 'حساب یافت نشد.'];
        }

        if ($account->status === 'verified') {
            return ['success' => false, 'message' => 'حساب تایید‌شده قابل ویرایش نیست.'];
        }

        $data = $this->normalizeAccountData($data);
        if (($data['platform'] ?? '') === '') {
            $data['platform'] = (string)(is_scalar($account->platform) ? $account->platform : '');
        }
        if (($data['username'] ?? '') === '') {
            $data['username'] = (string)$account->username;
        }
        $invalid = $this->validateNormalizedAccountData($data);
        if ($invalid !== null) {
            return $invalid;
        }

        // بررسی تکراری
        if (!empty($data['username']) && $data['username'] !== $account->username) {
            if ($this->socialAccountModel->existsByPlatformAndUsername((string)(is_scalar($account->platform) ? $account->platform : ''), (string)(is_scalar($data['username']) ? $data['username'] : ''), $accountId)) {
                return ['success' => false, 'message' => 'این نام کاربری قبلاً ثبت شده است.'];
            }
        }

        $updateData = [
            'username'           => $data['username'] ?? $account->username,
            'profile_url'        => $data['profile_url'] ?? $account->profile_url,
            'follower_count'     => intval($data['follower_count'] ?? $account->follower_count),
            'following_count'    => intval($data['following_count'] ?? $account->following_count),
            'post_count'         => intval($data['post_count'] ?? $account->post_count),
            'account_age_months' => intval($data['account_age_months'] ?? $account->account_age_months),
            'status'             => 'pending', // بازگشت به انتظار
        ];

        $result = $this->socialAccountModel->update($accountId, $updateData);

        if (!$result) {
            return ['success' => false, 'message' => 'خطا در بروزرسانی.'];
        }

        return ['success' => true, 'message' => 'اطلاعات حساب بروزرسانی شد و مجدداً برای بررسی ارسال گردید.'];
    }

    /**
     * حذف حساب (Soft Delete)
     */
    /** @return array{success: bool, message: string} */
    public function delete(int $accountId, int $userId): array
    {
        /** @var \stdClass|null $account */
        $account = $this->toObject($this->socialAccountModel->find($accountId));
        if (!$account || !isset($account->id) || $account->user_id !== $userId) {
            return ['success' => false, 'message' => 'حساب یافت نشد.'];
        }

        $this->socialAccountModel->softDelete($accountId);

        $this->logger->info("User {$userId} deleted social account #{$accountId}", ['channel' => 'social_account']);

        return ['success' => true, 'message' => 'حساب با موفقیت حذف شد.'];
    }

    /**
     * ارسال نوتیفیکیشن
     */
    private function notifyUser(int $userId, string $title, string $message, string $type = 'info'): void
    {
        try {
            if (\class_exists(\App\Models\Notification::class)) {
                ($this->notificationModel)->create([
                    'user_id' => $userId,
                    'title'   => $title,
                    'message' => $message,
                    'type'    => $type,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->info('notification_error', ['message' => $e->getMessage()]);
        }
    }

    // ─── Query Methods (برای Controllers) ───────────────────────

    /** @return list<\App\Models\SocialAccount> */
    public function getByUser(int $userId): array
    {
        return $this->socialAccountModel->getByUser($userId);
    }

    public function find(int $id): ?\stdClass
    {
        $acc = $this->toObject($this->socialAccountModel->find($id));
        if (!$acc) { return null; }
        return $acc;
    }

    /**
     * ادمن کے لیے تمام حسابات حاصل کریں (pagination کے ساتھ)
     */
    /** @param array<string, mixed> $filters
     *  @return list<\stdClass> */
    public function getAllForAdmin(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        return $this->socialAccountModel->getAll($filters, $limit, $offset);
    }

    /**
     * ادمن کے لیے حسابات کی تعداد گنتی کریں
     */
    /** @param array<string, mixed> $filters */
    public function countForAdmin(array $filters = []): int
    {
        return $this->socialAccountModel->countAll($filters);
    }

    /**
     * ادمن کے لیے حساب تلاش کریں
     */
    public function findForAdmin(int $id): ?object
    {
        return $this->socialAccountModel->find($id);
    }

    /**
     * ادمن کے لیے حسابات تلاش کریں (filter کے ساتھ)
     */
    /** @param array<string, mixed> $filters
     *  @return list<\stdClass> */
    public function searchForAdmin(string $search, array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $filters['search'] = $search;
        return $this->getAllForAdmin($filters, $limit, $offset);
    }
}
