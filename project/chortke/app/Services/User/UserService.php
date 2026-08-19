<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Contracts\LoggerInterface;
use App\Services\AntiFraud\GeoIPService;
use Core\Database;

/**
 * UserService
 *
 * مدیریت موجودیت کاربر (ثبت‌نام، وضعیت، هویت).
 */
class UserService
{
    private User $model;
    private ?GeoIPService $geoService;

    private \Core\TransactionWrapper $transactionWrapper;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\TransactionWrapper $transactionWrapper,
        \App\Contracts\LoggerInterface $logger,
        User $model,
        ?GeoIPService $geoService = null
    ) {        $this->transactionWrapper = $transactionWrapper;
        $this->logger = $logger;

                $this->model = $model;
        $this->geoService = $geoService;
    }

    private function getTransactionWrapper(): \Core\TransactionWrapper
    {
        return $this->transactionWrapper;
    }

    /**
     * فیلدهایی که کاربر مجاز است هنگام ثبت‌نام ارسال کند.
     * هر فیلد دیگری از POST body کاملاً نادیده گرفته می‌شود.
     *
     * SECURITY (Mass Assignment Fix): این لیست تنها gate برای ورود داده
     * به جدول users است. فیلدهای حساس مانند role، status، is_admin،
     * email_verified_at و ... هرگز از ورودی کاربر پذیرفته نمی‌شوند و
     * مستقیماً توسط سیستم با مقادیر امن مقداردهی می‌شوند.
     */
    private const REGISTER_ALLOWED_FIELDS = [
        'full_name',
        'email',
        'password',
        'username',
        'referral_code_used',  // کد معرف (اختیاری)
        'mobile',              // شماره موبایل (اختیاری)
    ];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|false
     */
    public function register(array $data): array|false
    {
        $this->logger->info('user.registration.attempt', ['email' => $data['email'] ?? 'unknown']);

        // ── SECURITY FIX: Mass Assignment Protection ──────────────────────────
        // فقط فیلدهای مجاز از ورودی کاربر پذیرفته می‌شوند.
        // هر فیلد دیگری (role، status، is_admin، email_verified_at و...) دور ریخته می‌شود.
        $data = array_intersect_key($data, array_flip(self::REGISTER_ALLOWED_FIELDS));
        // ─────────────────────────────────────────────────────────────────────

        // MED-07: Transition to cryptographically secure random_int instead of basic rand
        $data['username'] = $data['username'] ?? explode('@', str_value($data['email'] ?? 'user'))[0] . '_' . \random_int(1000, 9999);
        $data['password'] = hash_password(str_value($data['password'] ?? bin2hex(random_bytes(8))));

        $referralCodeUsed = trim(str_value($data['referral_code_used'] ?? ''));
        unset($data['referral_code_used']);

        $data['referral_code'] = $this->generateUniqueReferralCode();

        if ($referralCodeUsed !== '') {
            $referrer = $this->model->findByReferralCode($referralCodeUsed);
            if (!$referrer) {
                throw new \InvalidArgumentException('کد معرف وارد شده معتبر نیست.');
            }
            $incomingEmail = mb_strtolower(trim(str_value($data['email'] ?? '')), 'UTF-8');
            $referrerEmail = mb_strtolower(trim(str_value($referrer->email ?? '')), 'UTF-8');
            $incomingMobile = preg_replace('/\D+/', '', str_value($data['mobile'] ?? ''));
            $referrerMobile = preg_replace('/\D+/', '', str_value($referrer->mobile ?? ''));
            if (($incomingEmail !== '' && $incomingEmail === $referrerEmail)
                || ($incomingMobile !== '' && $referrerMobile !== '' && $incomingMobile === $referrerMobile)) {
                throw new \InvalidArgumentException('استفاده از کد معرف خودتان مجاز نیست.');
            }
            $data['referred_by'] = (int)$referrer->id;
        }

        // CRITICAL-02 Fix: Store hashed token in DB
        $plainToken = bin2hex(random_bytes(32));
        $data['email_verification_token'] = hash_hmac('sha256', $plainToken, secure_key());

        // ── مقادیر امن سیستمی — هرگز از ورودی کاربر نمی‌آیند ────────────────
        $data['role']               = 'user';    // نقش پیش‌فرض؛ attacker نمی‌تواند admin بشود
        $data['status']             = 'active';  // وضعیت پیش‌فرض
        $data['email_verified_at']  = null;      // تا تأیید ایمیل، null می‌ماند
        $data['is_admin']           = 0;         // فیلد دفاعی اضافی
        $data['created_at']         = date('Y-m-d H:i:s');
        // ─────────────────────────────────────────────────────────────────────

        // HIGH-06: DI Architecture Fix — eliminate static Container::make and leverage injected GeoIPService
        try {
            $ipAddress = function_exists('get_client_ip') ? get_client_ip() : get_client_ip();
            if ($ipAddress && filter_var($ipAddress, FILTER_VALIDATE_IP) && $this->geoService) {
                $location = $this->geoService->lookup($ipAddress);
                if ($location && ($location['source'] ?? '') !== 'default') {
                    $data['country_code'] = strtoupper(str_value($location['country_code'] ?? 'IR'));
                    $data['country_name'] = str_value($location['country_name'] ?? 'Iran');
                    
                    if (strlen($data['country_code']) === 2) {
                        $c1 = ord($data['country_code'][0]) + 127397;
                        $c2 = ord($data['country_code'][1]) + 127397;
                        $data['country_flag'] = html_entity_decode("&#$c1;&#$c2;", ENT_HTML5, 'UTF-8');
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('user.registration.geo_detection_failed', ['error' => $e->getMessage()]);
        }

        // Fallbacks
        $data['country_code'] = $data['country_code'] ?? 'IR';
        $data['country_name'] = $data['country_name'] ?? 'Iran';
        $data['country_flag'] = $data['country_flag'] ?? '🇮🇷';

        $userId = $this->model->create($data);

        if ($userId) {
            // ✅ Store verification code in user_verifications table for OTP verification
            $otpCode = strtoupper(substr($plainToken, 0, 6));
            $expiresAt = date('Y-m-d H:i:s', (strtotime('+1 hour') ?: time()));
            
            $this->model->getDb()->query(
                "INSERT INTO user_verifications (user_id, type, token, code, expires_at, created_at) 
                 VALUES (?, 'email', ?, ?, ?, NOW()) 
                 ON DUPLICATE KEY UPDATE code = VALUES(code), expires_at = VALUES(expires_at)",
                [$userId, $plainToken, $otpCode, $expiresAt]
            );

            $this->logger->info('user.registration.success', ['user_id' => $userId]);
            return ['id' => (int)$userId, 'plain_token' => $plainToken];
        }

        return false;
    }

    public function generateUniqueReferralCode(int $maxAttempts = 10): string
    {
        $attempts = 0;
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $attempts++;
            if ($attempts >= $maxAttempts) {
                $this->logger->error('user.referral_code_generation_failed', ['attempts' => $attempts]);
                throw new \RuntimeException('Failed to generate a unique referral code after ' . $maxAttempts . ' attempts.');
            }
        } while ($this->model->findByReferralCode($code));

        return $code;
    }

    public function verifyEmail(int $userId): bool
    {
        return $this->model->verifyEmail($userId);
    }

    public function changePassword(int $userId, string $newPassword): bool
    {
        $success = $this->model->update($userId, [
            'password' => hash_password($newPassword),
            'updated_at' => date('Y-m-d H:i:s'),
            // H-03 Fix: a password change must revoke any persistent "remember me" token so a
            // previously issued cookie cannot grant stale access after credential rotation.
            'remember_token' => null,
            'remember_expires_at' => null,
        ]);

        if ($success) {
            $this->logger->info('user.password_changed', ['user_id' => $userId]);
        }

        return $success;
    }

    public function banUser(int $userId, ?string $reason = null): bool
    {
        return $this->model->update($userId, [
            'status' => 'banned',
            'ban_reason' => $reason,
            'banned_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function unbanUser(int $userId): bool
    {
        return $this->model->update($userId, [
            'status' => 'active',
            'ban_reason' => null,
            'banned_at' => null,
        ]);
    }

    public function recordLogin(int $userId, ?string $ip = null, ?string $userAgent = null): bool
    {
        $ipAddress = $ip ?? (get_client_ip());
        $ua = $userAgent ?? get_user_agent();

        return $this->model->updateLastLogin(
            $userId,
            $ipAddress,
            $ua
        );
    }

    /**
     * ROOT-CAUSE NORMALIZATION (Service Layer)
     * Centralized helper: guarantee object from any DB layer result.
     * This is the principled fix — all find* and query results go through here.
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) {
            return null;
        }
        if ($data instanceof \stdClass) {
            return $data;
        }
        if (is_array($data)) {
            return (object)$data;
        }
        return (object)(array)$data;
    }

    public function find(int $id): ?\stdClass
    {
        $row = $this->toObject($this->model->find($id));
        if (!$row) { return null; }
        return $row;
    }

    public function findById(int $id): ?\stdClass
    {
        return $this->find($id);
    }

    public function findByEmail(string $email): ?\stdClass
    {
        $row = $this->model->findByEmail($email);
        return $this->toObject($row);
    }

    public function findByMobile(string $mobile): ?\stdClass
    {
        $row = $this->model->findByMobile($mobile);
        return $this->toObject($row);
    }

    public function emailExists(string $email): bool
    {
        return $this->model->findByEmail($email) !== null;
    }

    public function mobileExists(string $mobile): bool
    {
        return $this->model->findByMobile($mobile) !== null;
    }

    public function findByCredentials(string $identifier): ?\stdClass
    {
        $row = $this->model->findByCredentials($identifier);
        return $this->toObject($row);
    }

    public function findByReferralCode(string $code): ?\stdClass
    {
        $row = $this->model->findByReferralCode($code);
        return $this->toObject($row);
    }

    public function isBlacklisted(int $userId): bool
    {
        return $this->model->isBlacklisted($userId);
    }

    public function isKycVerified(int $userId): bool
    {
        $status = $this->model->getDb()->fetchColumn("SELECT status FROM kyc_verifications WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$userId]);
        if ($status === 'verified') return true;
        
        $user = $this->findById($userId);
        return $user && in_array($user->kyc_status ?? 'unverified', ['verified', 'approved'], true);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateUser(int $id, array $data): array
    {
        try {
            return $this->getTransactionWrapper()->runWithRetry(function() use ($id, $data) {
                if (isset($data['email'])) {
                    $existing = $this->findByEmail(str_value($data['email']));
                    if ($existing && (int)$existing->id !== $id) {
                        return [
                            'success' => false, 
                            'errors' => ['email' => ['این ایمیل قبلاً توسط کاربر دیگری ثبت شده است']]
                        ];
                    }
                }
    
                // ✅ Enforce Role Hierarchy inside service layer (Defense-in-Depth against privilege escalation)
                if (isset($data['role']) || isset($data['status'])) {
                    $actorId = function_exists('user_id') ? user_id() : null;
                    if ($actorId) {
                        $actor = $this->model->findById($actorId);
                        $target = $this->model->findById($id);
                        if ($actor && $target) {
                            $hierarchy = ['user' => 0, 'admin' => 1, 'super_admin' => 2];
                            $actorLevel = $hierarchy[$actor->role ?? 'user'] ?? 0;
                            $targetLevel = $hierarchy[$target->role ?? 'user'] ?? 0;
                            
                            // Non-super_admins cannot edit other admins
                            if ($actorLevel < 2 && $targetLevel >= 1 && $id !== $actorId) {
                                return [
                                    'success' => false,
                                    'message' => 'شما مجاز به ویرایش سایر مدیران نیستید.'
                                ];
                            }
                            
                            // Cannot assign a role higher than the actor's current role
                            if (isset($data['role'])) {
                                $newRoleLevel = $hierarchy[$data['role']] ?? 0;
                                if ($newRoleLevel > $actorLevel) {
                                    return [
                                        'success' => false,
                                        'message' => 'شما نمی‌توانید سطحی بالاتر از سطح خود تخصیص دهید.'
                                    ];
                                }
                            }
                        }
                    }
                }
    
                $updateData = [];
                $updatableFields = ['full_name', 'email', 'role', 'status'];
                
                foreach ($updatableFields as $field) {
                    if (isset($data[$field])) {
                        $updateData[$field] = $data[$field];
                    }
                }
    
                if (!empty($data['password'])) {
                    // ✅ Validate password strength
                    $complexityPattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/';
                    if (!preg_match($complexityPattern, str_value($data['password']))) {
                        return [
                            'success' => false,
                            'errors' => ['password' => ['رمز عبور باید حداقل ۸ کاراکتر و شامل حروف بزرگ، کوچک، عدد و نماد باشد']]
                        ];
                    }
                    $updateData['password'] = hash_password(str_value($data['password']));
                }
    
                $updateData['updated_at'] = date('Y-m-d H:i:s');
    
                $ok = $this->model->update($id, $updateData);
                
                if ($ok) {
                    return ['success' => true, 'message' => 'کاربر با موفقیت بروزرسانی شد'];
                }
    
                throw new \Core\Exceptions\ApplicationException('خطا در ذخیره مشخصات کاربر');
            });
        } catch (\Throwable $e) {
            $this->logger->error('user.update_failed', ['user_id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'بروز خطا در عملیات بروزرسانی کاربر'];
        }
    }

    /** @return list<\stdClass> */
    public function quickSearch(string $term, int $limit = 5): array
    {
        // M39 Fix: محدود کردن طول عبارت جستجو جهت کاهش فشار پردازشی و فیلتر کاراکترهای کنترلی دیتابیس
        $cleanTerm = \mb_strimwidth(\trim((string)$term), 0, 80, '');
        // حذف کاراکترهای کلیدی وایلدکارد دیتابیس (% و _) جهت پیشگیری از کوئری‌های غیربهینه
        $cleanTerm = \str_replace(['%', '_'], '', $cleanTerm);

        if ($cleanTerm === '') {
            return [];
        }

        // MED-08: Bound search results to safe memory ceilings and verify query components
        $safeLimit = \max(1, \min(50, $limit));

        $query = $this->model->query();

        $query->select('id', 'full_name', 'email', 'mobile', 'kyc_status', 'created_at')
              ->whereNull('deleted_at');

        $this->model->applySearch($query, $cleanTerm);

        return $query->orderBy('created_at', 'DESC')
                     ->limit($safeLimit)
                     ->get();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        return (bool)$this->model->update($id, $data);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<\stdClass>
     */
    public function searchWithFilters(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->model->searchWithFilters($filters, $limit, $offset);
    }

    /** @param array<string, mixed> $filters */
    public function countWithFilters(array $filters = []): int
    {
        return $this->model->countWithFilters($filters);
    }

    public function getAdminStats(): object
    {
        return (object) cache()->remember('user_admin_stats', 300, function() {
            return $this->model->getAdminStats();
        });
    }

    public function getWarningCount(int $userId): int
    {
        $user = $this->findById($userId);
        return $user ? (int)($user->warning_count ?? 0) : 0;
    }

    public function incrementWarningCount(int $userId): bool
    {
        return (bool)$this->model->getDb()->query(
            "UPDATE users SET warning_count = warning_count + 1 WHERE id = ?",
            [$userId]
        );
    }

    public function decrementWarningCount(int $userId): bool
    {
        return (bool)$this->model->getDb()->query(
            "UPDATE users SET warning_count = GREATEST(0, warning_count - 1) WHERE id = ?",
            [$userId]
        );
    }

    public function getFraudScore(int $userId): int
    {
        $user = $this->findById($userId);
        return $user ? (int)($user->fraud_score ?? 0) : 0;
    }

    public function incrementFraudScore(int $userId, int $amount = 5): bool
    {
        return (bool)$this->model->getDb()->query(
            "UPDATE users SET fraud_score = fraud_score + ? WHERE id = ?",
            [$amount, $userId]
        );
    }

    public function getKycStatus(int $userId): string
    {
        $user = $this->findById($userId);
        return $user ? (string)($user->kyc_status ?? 'unverified') : 'unverified';
    }
}