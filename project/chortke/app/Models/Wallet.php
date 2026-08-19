<?php

namespace App\Models;

use Core\Model;

/**
 * Wallet Model - Fully Hardened financial transactions
 */
class Wallet extends Model

{
    /**
     * Relations برای eager loading با allWith/loadRelations
     *
     * استفاده:
     *   // کیف‌پول یک کاربر با تراکنش‌هایش
     *   $wallet = $walletModel->findWith($walletId, ['user', 'transactions']);
     *
     *   // لیست کیف‌پول‌ها با صاحبانشان
     *   $wallets = $walletModel->allWith(['user'], 50);
     */
    protected array $relations = [
        'user'         => [\App\Models\User::class,        'user_id', 'one'],
        'transactions' => [\App\Models\Transaction::class,  'user_id', 'many'],
    ];

    protected array $fillable = [
        'user_id', 'balance_irt', 'balance_usdt',
        'pending_balance_irt', 'pending_balance_usdt',
        'is_frozen', 'frozen_reason',
    ];

    protected static string $table = 'wallets';

    public function __construct(\Core\Database $db) {
        parent::__construct($db);
    }

    private function currencyField(string $currency): string
    {
        $currency = \strtolower(\trim((string)$currency));
        if (!\in_array($currency, ['irt', 'usdt'], true)) {
            throw new \InvalidArgumentException("Invalid currency: {$currency}");
        }
        return $currency === 'usdt' ? 'balance_usdt' : 'balance_irt';
    }

    private function lockedField(string $currency): string
    {
        $currency = \strtolower(\trim((string)$currency));
        if (!\in_array($currency, ['irt', 'usdt'], true)) {
            throw new \InvalidArgumentException("Invalid currency: {$currency}");
        }
        return $currency === 'usdt' ? 'locked_usdt' : 'locked_irt';
    }

    /**
     * ایجاد کیف پول برای کاربر جدید
     */
    public function createForUser(int $userId): ?\stdClass
    {
        $sql = "
            INSERT IGNORE INTO `" . static::$table . "` (user_id, created_at, updated_at)
            VALUES (:user_id, NOW(), NOW())
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        return $this->findByUserId($userId);
    }

    /**
     * دریافت کیف پول بر اساس user_id
     */
    public function findByUserId(int $userId): ?\stdClass
    {
        $sql = "SELECT * FROM `" . static::$table . "` WHERE user_id = :user_id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        /** @var \stdClass|false $result */
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        if (!$result) {
            $this->createForUser($userId);
            $stmt->execute(['user_id' => $userId]);
            /** @var \stdClass|false $result */
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
        }

        return $result ?: null;
    }

    /**
     * M42: دریافت چند کیف پول بر اساس user_ids
     * برای جلوگیری از N+1 query problem در حلقه‌ها
     */
    /**
     * @param list<int> $userIds
     * @return list<\stdClass>
     */
    public function findByUserIds(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $placeholders = implode(',', array_map(function() { return '?'; }, $userIds));
        $sql = "SELECT * FROM `" . static::$table . "` WHERE user_id IN ({$placeholders})";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($userIds);

        $results = $stmt->fetchAll(\PDO::FETCH_OBJ);
        $walletsByUserId = [];
        foreach ($results as $wallet) {
            $walletsByUserId[(int)$wallet->user_id] = $wallet;
        }

        return $walletsByUserId; // key: user_id, value: wallet object
    }

    /**
     * دریافت موجودی (بر اساس ارز)
     */
    public function getBalance(int $userId, string $currency = 'irt'): string
    {
        $wallet = $this->findByUserId($userId);
        if (!$wallet) return '0';

        $field = $this->currencyField($currency);
        return (string)($wallet->{$field} ?? '0');
    }

    /**
     * ✅ دریافت موجودی با قفل - برای عملیات مالی
     * استفاده این متد الزامی است برای: Withdraw, Transfer, Purchase
     */
    public function getBalanceForUpdate(int $userId, string $currency = 'irt'): string
    {
        // 🛡️ SECURITY FIX (TOCTOU / Concurrency): بازگردانی قفل بدبینانه FOR UPDATE
        // تضمین همگام‌سازی کامل موجودی در تراکنش‌های هم‌زمان دیتابیس
        $wallet = $this->findByUserIdForUpdate($userId);
        if (!$wallet) return '0';

        $field = $this->currencyField($currency);
        return (string)($wallet->{$field} ?? '0');
    }

    /**
     * دریافت موجودی قفل‌شده
     */
    public function getLockedBalance(int $userId, string $currency = 'irt'): string
    {
        $wallet = $this->findByUserId($userId);
        if (!$wallet) return '0';

        $field = $this->lockedField($currency);
        return (string)($wallet->{$field} ?? '0');
    }

    /**
     * بررسی وضعیت مسدود بودن کیف پول
     */
    public function isFrozen(int $userId, bool $forUpdate = false): bool
    {
        // FOR UPDATE logic removed to prevent DB contention.
        $wallet = $this->findByUserId($userId);
        if (!$wallet) {
            return false;
        }

        return (bool)($wallet->is_frozen ?? 0);
    }

    /**
     * مسدود کردن کیف پول برای کاربر
     */
    public function freezeWallet(int $userId): bool
    {
        $sql = "UPDATE `" . static::$table . "` SET is_frozen = 1, updated_at = NOW() WHERE user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['user_id' => $userId]);
    }

    /**
     * رفع مسدودیت کیف پول برای کاربر
     */
    public function unfreezeWallet(int $userId): bool
    {
        $sql = "UPDATE `" . static::$table . "` SET is_frozen = 0, updated_at = NOW() WHERE user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['user_id' => $userId]);
    }

    /**
     * بروزرسانی موجودی
     * M41: Frozen check merged into WHERE clause for atomic TOCTOU prevention
     */
    public function updateBalance(int $userId, string $amount, string $currency = 'irt'): bool
    {
        if (\Core\ValueObjects\Money::fromString((string)($amount))->getAmount() === \Core\ValueObjects\Money::fromString((string)('0'))->getAmount()) {
            throw new \InvalidArgumentException("Zero amount not allowed");
        }

        $field = $this->currencyField($currency);

        // H24 Fix: Enforce atomic database-level protection against negative balance for debit operations
        $sql = "
            UPDATE `" . static::$table . "`
            SET `{$field}` = `{$field}` + :amount, updated_at = NOW()
            WHERE user_id = :user_id
              AND (is_frozen IS NULL OR is_frozen = 0)
              AND (:amount_check >= 0 OR `{$field}` >= ABS(:amount_abs))
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'amount' => $amount,
            'amount_check' => $amount,
            'amount_abs' => $amount,
            'user_id' => $userId,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new \Core\Exceptions\InsufficientBalanceException('موجودی حساب کافی نیست یا کیف پول مسدود شده است.');
        }

        return true;
    }

    /**
     * قفل کردن موجودی (برای برداشت)
     * M41: Frozen check merged into WHERE clause for atomic TOCTOU prevention
     */
    public function lockBalance(int $userId, string $amount, string $currency = 'irt'): bool
    {
        if (bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException("Lock amount must be positive.");
        }

        $balanceField = $this->currencyField($currency);
        $lockedField  = $this->lockedField($currency);

        $sql = "
            UPDATE `" . static::$table . "`
            SET
              `{$balanceField}` = `{$balanceField}` - ?,
              `{$lockedField}`  = `{$lockedField}` + ?,
              updated_at = NOW()
            WHERE user_id = ?
              AND `{$balanceField}` >= ?
              AND (is_frozen IS NULL OR is_frozen = 0)
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $amount,
            $amount,
            $userId,
            $amount,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new \Core\Exceptions\InsufficientBalanceException('موجودی حساب کافی نیست یا کیف پول مسدود شده است.');
        }

        return true;
    }

    /**
     * آزاد کردن موجودی قفل‌شده
     * M41: Frozen check merged into WHERE clause for atomic TOCTOU prevention
     */
    public function unlockBalance(int $userId, string $amount, string $currency = 'irt'): bool
    {
        if (bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException("Unlock amount must be positive.");
        }

        $balanceField = $this->currencyField($currency);
        $lockedField  = $this->lockedField($currency);

        $sql = "
            UPDATE `" . static::$table . "`
            SET
              `{$balanceField}` = `{$balanceField}` + ?,
              `{$lockedField}`  = `{$lockedField}` - ?,
              updated_at = NOW()
            WHERE user_id = ?
              AND `{$lockedField}` >= ?
              AND (is_frozen IS NULL OR is_frozen = 0)
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $amount,
            $amount,
            $userId,
            $amount,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new \Core\Exceptions\InsufficientBalanceException('موجودی قفل‌شده کافی نیست یا کیف پول مسدود شده است.');
        }

        return true;
    }

    /**
     * کسر از موجودی قفل‌شده (برای تکمیل برداشت)
     */
    public function deductLocked(int $userId, string $amount, string $currency = 'irt'): bool
    {
        if (bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException("Deduction amount must be positive.");
        }
        if ($this->isFrozen($userId, true)) {
            throw new \Core\Exceptions\InvalidStateException('کیف پول شما مسدود شده است.');
        }

        $lockedField = $this->lockedField($currency);

        $sql = "
            UPDATE `" . static::$table . "`
            SET `{$lockedField}` = `{$lockedField}` - ?, updated_at = NOW()
            WHERE user_id = ?
              AND `{$lockedField}` >= ?
              AND (is_frozen IS NULL OR is_frozen = 0)
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $amount,
            $userId,
            $amount,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new \Core\Exceptions\InsufficientBalanceException('موجودی قفل‌شده کافی نیست یا کیف پول مسدود شده است.');
        }

        return true;
    }

    /**
     * بروزرسانی زمان آخرین برداشت
     */
    public function updateLastWithdrawal(int $userId): bool
    {
        $sql = "
            UPDATE `" . static::$table . "`
            SET last_withdrawal_at = NOW(), updated_at = NOW()
            WHERE user_id = ?
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$userId]);
    }

    /**
     * بررسی امکان برداشت (روزی یکبار)
     */
    public function canWithdrawToday(int $userId): bool
    {
        $wallet = $this->findByUserId($userId);
        if (!$wallet || !$wallet->last_withdrawal_at) return true;

        $lastWithdrawal = \strtotime((string)$wallet->last_withdrawal_at);
        $today = \strtotime('today');

        return $lastWithdrawal < $today;
    }

    /**
     * موجودی کل (آزاد + قفل‌شده)
     */
    public function getTotalBalance(int $userId, string $currency = 'irt'): string
    {
        $wallet = $this->findByUserId($userId);
        if (!$wallet) return '0';

        $scale = \strtolower(\trim((string)$currency)) === 'usdt' ? 8 : 4;
        if (\strtolower(\trim((string)$currency)) === 'usdt') {
            return \Core\ValueObjects\Money::fromString((string)((string)$wallet->balance_usdt))->add(\Core\ValueObjects\Money::fromString((string)((string)$wallet->locked_usdt)))->getAmount();
        }

        return \Core\ValueObjects\Money::fromString((string)((string)$wallet->balance_irt))->add(\Core\ValueObjects\Money::fromString((string)((string)$wallet->locked_irt)))->getAmount();
    }

    /**
     * تنظیم موجودی به مقدار مشخص (نه افزایش/کاهش)
     * برای استفاده داخل تراکنش‌ها با مقدار از پیش محاسبه‌شده
     */
    public function setBalance(int $userId, string $newBalance, string $currency = 'irt'): bool
    {
        if (\Core\ValueObjects\Money::fromString((string)('0'))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)($newBalance)))) {
            throw new \InvalidArgumentException("Balance cannot be negative: {$newBalance}");
        }

        $field = $this->currencyField($currency);

        // M41: Frozen check merged into WHERE clause for atomic TOCTOU prevention
        $sql = "UPDATE `" . static::$table . "`
                SET `{$field}` = :balance, updated_at = NOW()
                WHERE user_id = :user_id
                  AND (is_frozen IS NULL OR is_frozen = 0)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['balance' => $newBalance, 'user_id' => $userId]);

        if ($stmt->rowCount() === 0) {
            throw new \Core\Exceptions\InvalidStateException('امکان بروزرسانی موجودی وجود ندارد؛ کیف پول مسدود است یا یافت نشد.');
        }

        return true;
    }

    /**
     * دریافت wallet با قفل (SELECT FOR UPDATE) برای استفاده داخل تراکنش
     * اگر wallet وجود نداشت ایجاد می‌کند (UPSERT) و سپس قفل می‌زند
     */
    public function findByUserIdForUpdate(int $userId): ?\stdClass
    {
        // UPSERT - اگر وجود نداشت بساز، اگر داشت همان row رو برگردون
        $upsertSql = "INSERT INTO `" . static::$table . "` (user_id, balance_irt, balance_usdt, created_at)
                      VALUES (:user_id, 0, 0, NOW())
                      ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)";

        $stmt = $this->db->prepare($upsertSql);
        $stmt->execute(['user_id' => $userId]);

        // 🛡️ SECURITY FIX: اعمال قفل سطح سطر (FOR UPDATE) جهت جلوگیری از Race Condition در عملیات مالی
        $sql = "SELECT * FROM `" . static::$table . "` WHERE user_id = :user_id FOR UPDATE";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        /** @var \stdClass|false $result */
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * بروزرسانی موجودی و زمان آخرین برداشت با هم
     */
    public function setBalanceAndWithdrawalTime(int $userId, string $newBalance, string $currency = 'irt'): bool
    {
        if (\Core\ValueObjects\Money::fromString((string)('0'))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)($newBalance)))) {
            throw new \InvalidArgumentException("Balance cannot be negative: {$newBalance}");
        }

        $field = $this->currencyField($currency);

        // M41: Frozen check merged into WHERE clause for atomic TOCTOU prevention
        $sql = "UPDATE `" . static::$table . "`
                SET `{$field}` = :balance, last_withdrawal_at = NOW(), updated_at = NOW()
                WHERE user_id = :user_id
                  AND (is_frozen IS NULL OR is_frozen = 0)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['balance' => $newBalance, 'user_id' => $userId]);

        if ($stmt->rowCount() === 0) {
            throw new \Core\Exceptions\InvalidStateException('امکان بروزرسانی موجودی وجود ندارد؛ کیف پول مسدود است یا یافت نشد.');
        }

        return true;
    }

    /**
     * دریافت wallet برای یک کاربر با قفل‌زدن row کناری (SELECT FOR UPDATE)
     * برای استفاده در transfer بین کاربران
     */
    public function findByUserIdLocked(int $userId): ?\stdClass
    {
        // 🛡️ SECURITY FIX: اعمال قفل سطح سطر (FOR UPDATE) جهت جلوگیری از Race Condition
        $sql = "SELECT * FROM `" . static::$table . "` WHERE user_id = :user_id FOR UPDATE";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        /** @var \stdClass|false $result */
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }
}