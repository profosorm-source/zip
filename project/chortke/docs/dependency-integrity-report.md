# گزارش کامل: رفع نقص‌های وابستگی غیرتعریف‌شده

**تاریخ**: ۱۱ خرداد ۱۴۰۵  
**شدت**: بحرانی (Critical)  
**وضعیت**: ✅ حل شد

---

## خلاصه مشکل

سه سرویس/کنترلر اصلی در سیستم از وابستگی‌هایی استفاده می‌کردند که:
1. ✗ در تعریف‌های property وجود نداشتند
2. ✗ در constructor inject نشده بودند
3. ✗ این باعث `Undefined Property` errors و runtime failures می‌شد

---

## نقص‌های شناسایی‌شده و حل‌ شده

### 1️⃣ PaymentService

#### مشکل
```php
// ❌ خصوصیات تعریف‌شده
private Database $db;           // ❌ تعریف نشده
private EventDispatcher $eventDispatcher; // ❌ تعریف نشده  
private NotificationServiceInterface $notifier; // ❌ تعریف نشده
```

**استفاده‌های اشتباه:**
- خط 143: `$this->db->selectOne(...)` 
- خط 416: `$this->eventDispatcher->dispatchAsync(...)`
- خط 436: `$this->notifier->depositSuccess(...)`

#### حل ✅
```php
// اضافه شده به properties:
private Database $db;
private EventDispatcher $eventDispatcher;
private NotificationServiceInterface $notifier;

// اضافه شده به constructor:
public function __construct(
    ...
    Database $db,
    EventDispatcher $eventDispatcher,
    NotificationServiceInterface $notifier,
    ...
)
```

**فایل تغییر یافته:** `app/Services/Payment/PaymentService.php`

---

### 2️⃣ LotteryService

#### مشکل
```php
// ❌ خصوصیات استفاده‌شده اما تعریف نشده:
$this->scoreService        // خط 153-154
$this->context             // خط 173, 359
$this->logger              // خط 214, 317, 336, 421, 427, 570
$this->walletLockManager   // خط 292
$this->cacheInvalidation   // خط 416
$this->cache               // استفاده در getCacheValue/setCache
```

#### حل ✅
```php
// اضافه شده به properties:
private LoggerInterface $logger;
private ScoreService $scoreService;
private Application $context;
private WalletLockManager $walletLockManager;
private Cache $cache;
private EventDispatcher $eventDispatcher;
private CacheInvalidationService $cacheInvalidation;

// اضافه شده به constructor:
public function __construct(
    ...
    LoggerInterface $logger,
    ScoreService $scoreService,
    Application $context,
    WalletLockManager $walletLockManager,
    Cache $cache,
    EventDispatcher $eventDispatcher,
    CacheInvalidationService $cacheInvalidation,
    ...
)
```

**فایل تغییر یافته:** `app/Services/Lottery/LotteryService.php`

---

### 3️⃣ InfluencerController

#### مشکل
```php
// ❌ Model properties استفاده شده اما تعریف نشده:
$this->profileModel   // خط 109, 117, 119, 143, 314
$this->orderModel     // خط 321, 322, 390, 424, 428
$this->disputeModel   // خط 398, 401, 428, 455, 478
```

#### حل ✅
```php
// اضافه شده به properties:
private InfluencerModel $profileModel;
private StoryOrder $orderModel;
private Dispute $disputeModel;

// اضافه شده به constructor:
public function __construct(
    ...
    InfluencerModel $profileModel,
    StoryOrder $orderModel,
    Dispute $disputeModel,
    ...
)
```

**فایل تغییر یافته:** `app/Controllers/Api/InfluencerController.php`

---

## فایل‌های تغییر یافته

| فایل | تغییرات |
|------|----------|
| `app/Services/Payment/PaymentService.php` | ✅ 3 dependency اضافه شد |
| `app/Services/Lottery/LotteryService.php` | ✅ 7 dependency اضافه شد |
| `app/Controllers/Api/InfluencerController.php` | ✅ 3 model dependency اضافه شد |
| `tests/Unit/DependencyIntegrityTest.php` | ✨ تست‌های جدید اضافه شد |
| `bin/static-dependency-check.php` | ✨ ابزار چک کننده استاتیک |

---

## ابزار و تست‌های اضافه شده

### 1. Static Dependency Checker

**مسیر:** `bin/static-dependency-check.php`

استفاده:
```bash
php bin/static-dependency-check.php
```

**قابلیت‌ها:**
- ✓ بررسی تمام property declarations
- ✓ بررسی constructor parameters
- ✓ شناسایی `$this->` usages
- ✓ تشخیص خصوصیات غیرتعریف‌شده
- ✓ تشخیص خصوصیات تعریف‌شده اما استفاده نشده

### 2. Dependency Integrity Test Suite

**مسیر:** `tests/Unit/DependencyIntegrityTest.php`

اجرا:
```bash
php vendor/bin/phpunit tests/Unit/DependencyIntegrityTest.php
```

**تست‌ها:**
- ✓ `testPaymentServiceInstantiation` - درست‌ترین instantiation
- ✓ `testLotteryServiceInstantiation` - instantiation
- ✓ `testInfluencerControllerInstantiation` - instantiation
- ✓ `testPaymentServiceDependencyAccess` - تحقق از تمام dependencies
- ✓ `testLotteryServiceDependencyAccess` - تحقق
- ✓ `testInfluencerControllerModelAccess` - تحقق Models

---

## نتیجه معماری

### قبل ❌
```
PaymentService: 11 properties, 3 missing from constructor
LotteryService: 14+ properties, 7+ missing from constructor
InfluencerController: 8 properties, 3 models missing
```

### بعد ✅
```
PaymentService: 14 properties, ✓ all injected
LotteryService: 21 properties, ✓ all injected
InfluencerController: 11 properties, ✓ all injected
```

---

## پیشنهادات اضافی برای جلوگیری

### 1. Pre-Commit Hook
```bash
# فایل: .git/hooks/pre-commit
php bin/static-dependency-check.php || exit 1
```

### 2. CI/CD Integration
```yaml
# در CI pipeline:
- name: Static Dependency Check
  run: php bin/static-dependency-check.php
- name: Run Integrity Tests
  run: php vendor/bin/phpunit tests/Unit/DependencyIntegrityTest.php
```

### 3. IDE Configuration

**PhpStorm/VSCode:** اضافه کردن پیش‌گویی برای خصوصیات

### 4. Code Review Checklist

- [ ] همه `private` properties تعریف شده؟
- [ ] همه property declarations در constructor inject شده؟
- [ ] هیچ `$this->undefined` usage وجود ندارد؟
- [ ] تست‌های smoke test اجرا شده‌اند؟

---

## نحوه تایید

### فوری
```bash
php bin/static-dependency-check.php
```

### کامل
```bash
php vendor/bin/phpunit tests/Unit/DependencyIntegrityTest.php --verbose
```

### ماژول‌های مالی/حساس
```bash
# تست‌های اضافی برای ماژول‌های پرخطر:
php vendor/bin/phpunit tests/Integration/Payment/ --verbose
php vendor/bin/phpunit tests/Integration/Lottery/ --verbose
php vendor/bin/phpunit tests/Integration/Influencer/ --verbose
```

---

## موارد نیازمند Attention

### ⚠️ Container Configuration

اطمینان حاصل کنید که `Container` تمام dependencies را صحیح‌تر تعریف کرده:

```php
// در config/services.php یا bootstrap:
$container->bind(Database::class, function() { ... });
$container->bind(EventDispatcher::class, function() { ... });
$container->bind(ScoreService::class, function() { ... });
$container->bind(WalletLockManager::class, function() { ... });
// ... و غیره
```

### ⚠️ Constructor Signature Changes

اگر Container به صورت manual instantiate می‌کند، باید update شود:

```php
// قدیم ❌
new PaymentService($logger, $log, $factory, ...);

// جدید ✅
new PaymentService($logger, $log, $factory, ..., $db, $dispatcher, $notifier);
```

---

## Timeline

| مرحله | تاریخ | وضعیت |
|------|------|------|
| شناسایی مشکل | ۱۱ خرداد | ✅ |
| ایجاد checker | ۱۱ خرداد | ✅ |
| رفع PaymentService | ۱۱ خرداد | ✅ |
| رفع LotteryService | ۱۱ خرداد | ✅ |
| رفع InfluencerController | ۱۱ خرداد | ✅ |
| تست‌های جامع | ۱۱ خرداد | ✅ |

---

## منابع

- [Dependency Injection Pattern](https://www.php-fig.org/psr/psr-11/)
- [Static Analysis in PHP](https://psalm.dev/)
- [PHPUnit Testing](https://phpunit.de/)

---

**نوشته شده:** ۱۱ خرداد ۱۴۰۵  
**وضعیت نهایی:** ✅ بسته شد
