<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * CouponService - سرویس اشتراکی مدیریت کوپن و تخفیف‌ها
 *
 * این سرویس جایگزین App\Services\CouponService شده است.
 */
class CouponService
{
    private \Core\TransactionWrapper $transactionWrapper;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private Coupon $couponModel;
    private CouponRedemption $redemptionModel;

    /**
     * Centralized toObject (root-cause normalization for DB results).
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        if ($data instanceof \stdClass) return $data;
        if (is_object($data) || is_array($data)) return (object)(array)$data;
        return (object)[$data];
    }

    public function __construct(
        \Core\TransactionWrapper $transactionWrapper,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        Coupon $couponModel,
        CouponRedemption $redemptionModel
    ) {        $this->transactionWrapper = $transactionWrapper;
        $this->db = $db;
        $this->logger = $logger;
        $this->couponModel = $couponModel;
        $this->redemptionModel = $redemptionModel;
    }

    private function getTransactionWrapper(): \Core\TransactionWrapper
    {
        return $this->transactionWrapper;
    }

    private function isCouponActive(\stdClass $c): bool
    {
        if (empty($c->active)) return false;
        $now = date('Y-m-d H:i:s');
        if (!empty($c->start_date) && $c->start_date > $now) return false;
        if (!empty($c->end_date) && $c->end_date < $now) return false;
        if (!empty($c->usage_limit) && $c->usage_limit > 0 && $c->usage_count >= $c->usage_limit) return false;
        return true;
    }

    /**
     * مقیاس اعشاری بر اساس واحد پول (فیات=4، کریپتو=8).
     */
    private function scaleForCurrency(string $currency): int
    {
        return in_array(strtolower($currency), ['irt', 'irr', 'toman'], true) ? 4 : 8;
    }

    /**
     * محاسبهٔ دقیقِ تخفیف و مبلغ نهایی با bcmath (بدون float).
     *
     * @return array{discount: string, final: string}
     */
    private function computeDiscount(\stdClass $coupon, string $amount, string $currency): array
    {
        $scale = $this->scaleForCurrency($currency);
        $value = str_value($coupon->value ?? 0);
        if (!is_numeric($value) || !is_numeric($amount)) {
            return ['discount' => '0', 'final' => is_numeric($amount) ? $amount : '0'];
        }

        if (($coupon->type ?? '') === 'percent') {
            $discount = bcdiv(bcmul($amount, $value, $scale + 2), '100', $scale);
            $maxDiscount = str_value($coupon->max_discount ?? 0);
            if (is_numeric($maxDiscount)
                && bccomp($maxDiscount, '0', $scale) > 0
                && bccomp($discount, $maxDiscount, $scale) > 0
            ) {
                $discount = $maxDiscount;
            }
        } else {
            // مبلغ ثابت: تخفیف = min(value, amount)
            $discount = bccomp($value, $amount, $scale) < 0 ? $value : $amount;
        }

        $final = bcsub($amount, $discount, $scale);
        if (bccomp($final, '0', $scale) < 0) {
            $final = '0';
        }

        return ['discount' => $discount, 'final' => $final];
    }

    /**
     * مقایسهٔ دو مبلغ با تلورانس (برای اعتبارسنجی توکن).
     */
    private function amountsDiffer(mixed $a, mixed $b, string $tolerance = '0.01'): bool
    {
        $as = str_value($a);
        $bs = str_value($b);
        if (!is_numeric($as) || !is_numeric($bs)) {
            return true;
        }
        $diff = bcsub($as, $bs, 8);
        if (str_starts_with($diff, '-')) {
            $diff = substr($diff, 1);
        }
        return bccomp($diff, $tolerance, 8) > 0;
    }

    /**
     * اعتبارسنجی و محاسبه تخفیف
     */
    /**
     * @return array{valid:false,error:string}|array{
     *   valid:true,coupon_id:int,coupon_code:string,original_amount:string|int|float,
     *   discount_amount:string,final_amount:string,currency:string,validation_token:string
     * }
     */
    public function validateAndCalculate(
        string $code,
        string|int|float $amount,
        string $currency,
        int $userId,
        string $applicableTo = 'all'
    ): array {
        $amountStr = (string)$amount;
        $coupon = $this->couponModel->findByCode($code);

        if (!$coupon) {
            return ['valid' => false, 'error' => 'کد تخفیف معتبر نیست'];
        }

        if (!$this->isCouponActive($coupon)) {
            return ['valid' => false, 'error' => 'کد تخفیف منقضی شده یا غیرفعال است'];
        }

        // Double-check usage limit explicitly to safeguard validation context
        if ($coupon->usage_limit !== null && $coupon->usage_limit > 0 && $coupon->usage_count >= $coupon->usage_limit) {
            return ['valid' => false, 'error' => 'ظرفیت استفاده از این کد تخفیف به پایان رسیده است.'];
        }

        if ($coupon->applicable_to !== 'all' && $coupon->applicable_to !== $applicableTo) {
            return ['valid' => false, 'error' => 'این کد تخفیف برای این نوع عملیات قابل استفاده نیست'];
        }

        if ($coupon->min_purchase !== null
            && bccomp(str_value($coupon->min_purchase), '0', 4) > 0
            && bccomp($amountStr, str_value($coupon->min_purchase), 4) < 0
        ) {
            return ['valid' => false, 'error' => sprintf('مبلغ خرید باید حداقل %s باشد', number_format((float)$coupon->min_purchase))];
        }

        if ($this->redemptionModel->hasUserUsedCoupon($userId, $coupon->id)) {
            return ['valid' => false, 'error' => 'شما قبلاً از این کد تخفیف استفاده کرده‌اید'];
        }

        $calc = $this->computeDiscount($coupon, $amountStr, $currency);
        $discount = $calc['discount'];
        $finalAmount = $calc['final'];
        $couponId = int_value($coupon->id ?? 0);
        $couponCode = str_value($coupon->code ?? '');
        if ($couponId <= 0 || $couponCode === '') {
            throw new \UnexpectedValueException('Coupon model returned an invalid identity.');
        }

        // H-C2 Fix: Generate temporary validation token cached for 15 minutes to guarantee checkout integrity
        $validationToken = bin2hex(random_bytes(16));
        $cacheKey = "coupon_val_token_{$userId}_{$validationToken}";
        $cacheData = [
            'coupon_id' => $couponId,
            'coupon_code' => $couponCode,
            'original_amount' => $amount,
            'discount_amount' => $discount,
            'final_amount' => $finalAmount,
            'currency' => $currency
        ];
        cache()->put($cacheKey, $cacheData, 15);

        return [
            'valid' => true,
            'coupon_id' => $couponId,
            'coupon_code' => $couponCode,
            'original_amount' => $amount,
            'discount_amount' => $discount,
            'final_amount' => $finalAmount,
            'currency' => $currency,
            'validation_token' => $validationToken
        ];
    }

    /**
     * ثبت مصرف کوپن
     */
    public function redeem(
        int $couponId,
        int $userId,
        string $originalAmount,
        string $discountAmount,
        string $finalAmount,
        string $currency,
        string $entityType,
        ?int $entityId = null,
        ?string $validationToken = null
    ): bool {
        return $this->getTransactionWrapper()->runWithRetry(function() use (
            $couponId, $userId, $originalAmount, $discountAmount, $finalAmount, $currency, $entityType, $entityId, $validationToken
        ) {
            // H-C2 Fix: Verify validation token parameter integrity if supplied
            if ($validationToken !== null) {
                $cacheKey = "coupon_val_token_{$userId}_{$validationToken}";
                $cacheData = cache()->get($cacheKey);
                
                if (!is_array($cacheData)) {
                    throw new \Core\Exceptions\InvalidStateException('توکن اعتبارسنجی کد تخفیف نامعتبر یا منقضی شده است. لطفا مجددا کد تخفیف را اعتبارسنجی کنید.');
                }

                $cachedCouponId = $cacheData['coupon_id'] ?? null;
                $cachedOriginal = $cacheData['original_amount'] ?? null;
                $cachedDiscount = $cacheData['discount_amount'] ?? null;
                $cachedFinal = $cacheData['final_amount'] ?? null;
                $cachedCurrency = $cacheData['currency'] ?? null;

                $hasValidShape = (is_int($cachedCouponId) || (is_string($cachedCouponId) && is_numeric($cachedCouponId)))
                    && (is_int($cachedOriginal) || is_float($cachedOriginal) || (is_string($cachedOriginal) && is_numeric($cachedOriginal)))
                    && (is_int($cachedDiscount) || is_float($cachedDiscount) || (is_string($cachedDiscount) && is_numeric($cachedDiscount)))
                    && (is_int($cachedFinal) || is_float($cachedFinal) || (is_string($cachedFinal) && is_numeric($cachedFinal)))
                    && is_string($cachedCurrency);

                if (!$hasValidShape) {
                    throw new \Core\Exceptions\InvalidStateException('ساختار توکن اعتبارسنجی کد تخفیف نامعتبر است.');
                }

                // Verify parameters match exactly.
                if (
                    (int)$cachedCouponId !== $couponId ||
                    $this->amountsDiffer($cachedOriginal, $originalAmount) ||
                    $this->amountsDiffer($cachedDiscount, $discountAmount) ||
                    $this->amountsDiffer($cachedFinal, $finalAmount) ||
                    strtolower($cachedCurrency) !== strtolower($currency)
                ) {
                    throw new \Core\Exceptions\InvalidStateException('پارامترهای اعتبارسنجی کد تخفیف با مقادیر نهایی مغایرت دارند. لطفا مجددا تلاش کنید.');
                }
                
                // Consume the validation token so it cannot be reused
                cache()->forget($cacheKey);
            }

            // Architectural Fix: Utilize locked Model locator instead of writing inline RAW FOR UPDATE.
            $coupon = $this->couponModel->findWithLock($couponId);
            if (!$coupon) {
                return false;
            }

            // H-C2 & H-C3 Fix: Pessimistic lock on user usage + idempotency
            if ($this->redemptionModel->hasUserUsedCouponForUpdate($userId, $couponId)) {
                throw new \Core\Exceptions\InvalidStateException('کد تخفیف قبلا توسط این کاربر استفاده شده است.');
            }

            if ($coupon->usage_limit !== null && (int)$coupon->usage_count >= (int)$coupon->usage_limit) {
                throw new \Core\Exceptions\InvalidStateException('ظرفیت استفاده از این کد تخفیف به پایان رسیده است.');
            }

            // Idempotency: prevent double spend if entity already has a coupon applied
            $existing = $this->toObject($this->db->query(
                "SELECT id FROM coupon_redemptions WHERE entity_type = ? AND entity_id = ? LIMIT 1",
                [$entityType, $entityId]
            )->fetch());
            if ($existing) {
                throw new \Core\Exceptions\InvalidStateException('برای این تراکنش قبلاً کد تخفیف اعمال شده است.');
            }

            $redemptionId = $this->redemptionModel->create([
                'coupon_id' => $couponId,
                'user_id' => $userId,
                'original_amount' => $originalAmount,
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount,
                'currency' => $currency,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => \get_client_ip()
            ]);

            if (!$redemptionId) {
                throw new \Core\Exceptions\ApplicationException('خطا در ثبت رکورد استفاده از کد تخفیف.');
            }

            $success = $this->couponModel->incrementUsage($couponId);
            if (!$success) {
                throw new \Core\Exceptions\ApplicationException('خطا در بروزرسانی تعداد استفاده از کد تخفیف.');
            }

            // Success Structured Logging
            $this->logger->info('coupon.redeemed', [
                'coupon_id' => $couponId,
                'user_id' => $userId,
                'discount' => $discountAmount,
                'final' => $finalAmount,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);

            return true;
        });
    }

    /**
     * اعتبارسنجی و ثبت مصرف کوپن به صورت کاملاً اتمیک (حل Race Condition)
     */
    /**
     * @return array{
     *   success:bool,message?:string,coupon_id?:int,original_amount?:string,
     *   discount_amount?:string,final_amount?:string,redemption_id?:int
     * }
     */
    public function validateAndRedeem(
        int $userId,
        string $code,
        string $amount,
        string $currency,
        string $entityType,
        ?int $entityId = null,
        string $applicableTo = 'all'
    ): array {
        return $this->getTransactionWrapper()->runWithRetry(function() use (
            $userId, $code, $amount, $currency, $entityType, $entityId, $applicableTo
        ) {
            $coupon = $this->couponModel->findByCodeWithLock($code);
            if (!$coupon) {
                return ['success' => false, 'message' => 'کد تخفیف معتبر نیست'];
            }
            $couponId = int_value($coupon->id ?? 0);
            if ($couponId <= 0) {
                throw new \UnexpectedValueException('Locked coupon row has no valid id.');
            }

            if (!$this->isCouponActive($coupon)) {
                return ['success' => false, 'message' => 'کد تخفیف منقضی شده یا غیرفعال است'];
            }

            // بررسی محدودیت استفاده کلی با قفل FOR UPDATE
            if ($coupon->usage_limit !== null && $coupon->usage_limit > 0 && $coupon->usage_count >= $coupon->usage_limit) {
                return ['success' => false, 'message' => 'ظرفیت استفاده از این کد تخفیف به پایان رسیده است.'];
            }

            if ($coupon->applicable_to !== 'all' && $coupon->applicable_to !== $applicableTo) {
                return ['success' => false, 'message' => 'این کد تخفیف برای این نوع عملیات قابل استفاده نیست'];
            }

            if ($coupon->min_purchase !== null
                && bccomp(str_value($coupon->min_purchase), '0', 4) > 0
                && bccomp($amount, str_value($coupon->min_purchase), 4) < 0
            ) {
                return ['success' => false, 'message' => sprintf('مبلغ خرید باید حداقل %s باشد', number_format((float)$coupon->min_purchase))];
            }

            // بررسی استفاده قبلی کاربر با قفل FOR UPDATE
            if ($this->redemptionModel->hasUserUsedCouponForUpdate($userId, $couponId)) {
                return ['success' => false, 'message' => 'شما قبلاً از این کد تخفیف استفاده کرده‌اید'];
            }

            // جلوگیری از ثبت مجدد برای همان ماهیت (Idempotency)
            $existing = $this->toObject($this->db->query(
                "SELECT id FROM coupon_redemptions WHERE entity_type = ? AND entity_id = ? FOR UPDATE",
                [$entityType, $entityId]
            )->fetch());
            if ($existing) {
                return ['success' => false, 'message' => 'برای این تراکنش قبلاً کد تخفیف اعمال شده است.'];
            }

            // محاسبه تخفیف
            $calc = $this->computeDiscount($coupon, $amount, $currency);
            $discount = $calc['discount'];
            $finalAmount = $calc['final'];

            $redemptionId = $this->redemptionModel->create([
                'coupon_id' => $couponId,
                'user_id' => $userId,
                'original_amount' => $amount,
                'discount_amount' => $discount,
                'final_amount' => $finalAmount,
                'currency' => $currency,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => \get_client_ip()
            ]);

            if (!$redemptionId) {
                throw new \Core\Exceptions\ApplicationException('خطا در ثبت رکورد استفاده از کد تخفیف.');
            }

            $success = $this->couponModel->incrementUsage($couponId);
            if (!$success) {
                throw new \Core\Exceptions\ApplicationException('خطا در بروزرسانی تعداد استفاده از کد تخفیف.');
            }

            $this->logger->info('coupon.redeemed', [
                'coupon_id' => $couponId,
                'user_id' => $userId,
                'discount' => $discount,
                'final' => $finalAmount,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);

            return [
                'success' => true,
                'coupon_id' => $couponId,
                'original_amount' => $amount,
                'discount_amount' => $discount,
                'final_amount' => $finalAmount,
                'redemption_id' => $redemptionId
            ];
        });
    }

    /**
     * دریافت آمار کوپن
     */
    /** @return array<string, mixed> */
    public function getCouponStatistics(int $couponId): array
    {
        $coupon = $this->toObject($this->couponModel->find($couponId));
        return [
            'coupon' => $coupon,
            'stats' => $this->redemptionModel->getCouponStats($couponId),
            'recent_uses' => $this->redemptionModel->getCouponHistory($couponId, 10)
        ];
    }

    /**
     * آمار کلی سیستم کوپن
     */
    /** @return array<string, mixed> */
    public function getOverallStatistics(): array
    {
        return [
            'overall' => $this->redemptionModel->getOverallStats(),
            'active_coupons_count' => count($this->couponModel->getActiveCoupons()),
            'expired_coupons_count' => count($this->couponModel->getExpiredCoupons()),
            'today_redemptions_count' => count($this->redemptionModel->getTodayRedemptions())
        ];
    }

    /**
     * تمام کوپن‌ها دریافت کنید (با pagination)
     */
    /** @return array<int, object> */
    public function all(?int $limit = null, int $offset = 0): array
    {
        if ($limit === null) {
            return $this->couponModel->getAll(100, $offset);
        }
        
        return $this->couponModel->getAll($limit, $offset);
    }

    /**
     * کوپن کد سے تلاش کنید
     */
    public function findByCode(string $code): ?object
    {
        return $this->couponModel->findByCode($code);
    }

    /**
     * کوپن ID سے تلاش کنید
     */
    public function find(int $id): ?\stdClass
    {
        $c = $this->toObject($this->couponModel->find($id));
        return $c;
    }

    /**
     * نیا کوپن بنائیں
     */
    /** @param array<string, mixed> $data */
    public function create(array $data): ?int
    {
        // Validate code exists
        $codeValue = $data['code'] ?? null;
        if (!is_string($codeValue) || trim($codeValue) === '') {
            $this->logger->warning('coupon.create.empty_code');
            return null;
        }
        $code = strtoupper(trim($codeValue));

        // Check for duplicates
        if ($this->couponModel->findByCode($code)) {
            $this->logger->warning('coupon.create.duplicate', ['code' => $code]);
            return null;
        }

        // Ensure data has defaults
        $data['code'] = $code;
        $data['usage_count'] = $data['usage_count'] ?? 0;
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');

        try {
            $result = $this->couponModel->create($data);

            if ($result) {
                $this->logger->info('coupon.created', ['code' => $data['code'], 'id' => $result]);
            }

            return is_int($result) && $result > 0 ? $result : null;
        } catch (\Exception $e) {
            $this->logger->error('coupon.create.failed', ['code' => $data['code'], 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * کوپن کو اپ‌ڈیٹ کنید
     */
    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        $coupon = $this->toObject($this->couponModel->find($id));
        if (!$coupon) {
            $this->logger->warning('coupon.update.not_found', ['id' => $id]);
            return false;
        }

        // Prevent code changes if not provided
        if (array_key_exists('code', $data)) {
            $newCodeValue = $data['code'];
            if (!is_string($newCodeValue) || trim($newCodeValue) === '') return false;
            $newCode = strtoupper(trim($newCodeValue));
            if ($newCode !== str_value($coupon->code ?? '')) {
                if ($this->couponModel->findByCode($newCode)) {
                    $this->logger->warning('coupon.update.duplicate_code', ['id' => $id, 'code' => $newCode]);
                    return false;
                }
                $data['code'] = $newCode;
            }
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        try {
            $result = $this->couponModel->update($id, $data);

            if ($result) {
                $this->logger->info('coupon.updated', ['id' => $id, 'fields' => array_keys($data)]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('coupon.update.failed', ['id' => $id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * کوپن کو حذف کنید
     */
    public function delete(int $id): bool
    {
        $coupon = $this->toObject($this->couponModel->find($id));
        if (!$coupon) {
            $this->logger->warning('coupon.delete.not_found', ['id' => $id]);
            return false;
        }

        try {
            $result = $this->couponModel->delete($id);

            if ($result) {
                $this->logger->info('coupon.deleted', ['id' => $id, 'code' => $coupon->code]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('coupon.delete.failed', ['id' => $id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * کوپن کی حالت toggle کنید (فعال/غیرفعال)
     */
    public function toggle(int $id): bool
    {
        $coupon = $this->toObject($this->couponModel->find($id));
        if (!$coupon) {
            return false;
        }

        $newStatus = !$coupon->active;
        return $this->update($id, ['active' => $newStatus ? 1 : 0]);
    }

    /**
     * کوپنز کو صفحہ بندی کے ساتھ تلاش کنید
     */
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function paginate(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        // Secure architectural refactor: Replace dynamic raw concatenations with Safe Query Builder
        $query = $this->db->table('coupons')->whereNull('deleted_at');

        if (!empty($filters['status'])) {
            $query->where('active', '=', $filters['status'] === 'active' ? 1 : 0);
        }

        if (!empty($filters['type'])) {
            $query->where('type', '=', $filters['type']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('code', 'LIKE', $search)
                  ->orWhere('description', 'LIKE', $search);
            });
        }

        $total = $query->count();

        $coupons = $this->toObject($query->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get());

        return [
            'data' => $coupons ?? [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
        ];
    }

    /**
     * Wrap closures within atomic database transactions.
     */
    protected function transaction(callable $callback, int $maxRetries = 3): mixed
    {
        $started = !$this->db->inTransaction();
        if ($started) {
            $this->db->beginTransaction();
        }
        try {
            $result = $callback();
            if ($started) {
                $this->db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($started && $this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }
    /**
     * دریافت لیست تاریخچه استفاده از کوپن‌ها
     */
    /** @return list<\stdClass> */
    public function getRedemptions(int $limit = 100, int $offset = 0): array
    {
        return $this->redemptionModel->all(); // Or implement pagination if needed
    }
}

