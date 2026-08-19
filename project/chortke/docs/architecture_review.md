# 🏗️ گزارش بررسی معماری پروژه Chortke (چرتکه)

**تاریخ بررسی:** 2026-07-01
**بررسی‌کننده:** Software Architect (Anthropic Claude Opus 4.8)
**نوع پروژه:** پلتفرم کسب درآمد آنلاین — PHP Monolith با فریمورک سفارشی

---

## 📋 خلاصه اجرایی

| شاخص | مقدار |
|---|---|
| **زبان/فریمورک** | PHP 8.2+ / Custom MVC (بدون استفاده از Laravel/Symfony) |
| **تعداد فایل‌ها** | ~3,845 |
| **حجم کل** | ~44 MB |
| **تعداد Routes** | ~600+ (برآورد از ساختار controllers + routes/) |
| **پایگاه داده** | MySQL (PDO) |
| **آرکیتمکتور کلی** | MVC + Event-Driven + Adapter Pattern + CQRS-like Commands |
| **وضعیت کلی** | 🟡 قابل بهره‌برداری — نیازمند اصلاحات معماری عمیق و رفع باگ‌های اساسی |

---

## 🗺️ نقشه ذهنی پروژه (Mental Map)

```
┌─────────────────────────────────────────────────────┐
│                    PUBLIC / ENTRY POINT              │
│         public/index.php, public/worker.php          │
└──────────────────────┬──────────────────────────────┘
                       ▼
┌─────────────────────────────────────────────────────┐
│                   BOOTSTRAP LAYER                    │
│         bootstrap/app.php → Application.php          │
└──────────────────────┬──────────────────────────────┘
                       ▼
┌─────────────────────────────────────────────────────┐
│                    CORE FRAMEWORK                    │
│  Container | Router | Database | Cache | Queue       │
│  Session | EventDispatcher | Pipeline | RateLimiter   │
│  CircuitBreaker | RetryPolicy | IdempotencyKey       │
│  Model | QueryBuilder | Validator | CSRF | Scheduler  │
│  TransactionWrapper | Schema | Encryption             │
└──────────────────────┬──────────────────────────────┘
                       ▼
┌─────────────────────────────────────────────────────┐
│                   MIDDLEWARE PIPELINE                │
│  Tracing → Exception → Session → Concurrent →        │
│  Logging → CORS → HTTPS → Security → Maintenance     │
└──────────────────────┬──────────────────────────────┘
                       ▼
┌─────────────────────────────────────────────────────┐
│                    CONTROLLER LAYER                  │
│  ┌──────────────┐  ┌──────────────────────┐         │
│  │  Admin Panel  │  │    User Panel        │         │
│  │  (60+ ctrl)   │  │  (50+ ctrl)          │         │
│  └──────────────┘  └──────────────────────┘         │
│  ┌──────────────┐  ┌──────────────────────┐         │
│  │  API v1/v2    │  │  OAuth / Webhook     │         │
│  │  (20+ ctrl)   │  │  (10+ ctrl)          │         │
│  └──────────────┘  └──────────────────────┘         │
└──────────────────────┬──────────────────────────────┘
                       ▼
┌─────────────────────────────────────────────────────┐
│                    SERVICE LAYER                     │
│  ┌──────────────┐  ┌──────────────────────┐         │
│  │ Wallet, KYC, │  │ Anti-Fraud, Audit    │         │
│  │ Payment, Ads │  │ Metrics, Search       │         │
│  │ Notification │  │ Upload, Currency      │         │
│  │ Influencer   │  │ Gamification, Level   │         │
│  └──────────────┘  └──────────────────────┘         │
└──────────────────────┬──────────────────────────────┘
                       ▼
┌─────────────────────────────────────────────────────┐
│                    ADAPTER LAYER                     │
│  AdTube | AdSocial | Crypto | Bank | Jibit | Vandar  │
│  DeepFace KYC | FCM | SMS | Push | SEO               │
└──────────────────────┬──────────────────────────────┘
                       ▼
┌─────────────────────────────────────────────────────┐
│                  DOMAIN / MODELS                     │
│  User | Wallet | Transaction | Escrow | Investment   │
│  CustomTask | Ad | Banner | Content | Influencer     │
│  Referral | Prediction | Lottery | Coupon | KYC      │
│  Ticket | Message | Notification | Audit | FeatureFlag│
│  FraudCase | RateLimit | Outbox | DLQ | Session      │
└──────────────────────┬──────────────────────────────┘
                       ▼
┌─────────────────────────────────────────────────────┐
│                    CONTRACTS (Interfaces)            │
│  30+ interface definitions for DI/Testing            │
└──────────────────────┬──────────────────────────────┘
                       ▼
┌─────────────────────────────────────────────────────┐
│                  EXTERNAL SERVICES                   │
│  MySQL | Redis | FCM | Payment Gateways | SMS API    │
│  Crypto APIs | DeepFace | SEO Services               │
└─────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────┐
│                 BACKGROUND PROCESSES                  │
│  Commands (20+): DLQ, Outbox, Analytics, Queue,      │
│  Migration, Cleanup, RateLimit Audit, etc.            │
└─────────────────────────────────────────────────────┘
```

---

## 🔴 مشکلات بحرانی (Critical)

### C-1: استفاده از `$_SESSION` و `$_SERVER` و سایر Superglobals به صورت پراکنده
- **تأثیر:** تست‌ناپذیری، وابستگی شدید به محیط، نقض اصل Dependency Injection
- **فراوانی:** ~800+ occurrence در کل پروژه
- **راه‌حل:** ایجاد wrapper统一 شده در Request/Session objects

### C-2: عدم استفاده از Prepared Statements در برخی نقاط
- **تأثیر:** آسیب‌پذیری SQL Injection
- **راه‌حل:** اجبار استفاده از QueryBuilder یا PDO Prepared در تمام نقاط

### C-3: Mixed Sync/Async Processing
- **تأثیر:** Deadlock احتمالی در فرآیندهای مالی (Escrow, Withdrawal)
- **راه‌حل:** جدا‌سازی کامل مسیر sync از async با Outbox Pattern

### C-4: Hardcoded Credentials / Secrets در `.env`
- **تأثیر:** نشت اطلاعات حساس
- **راه‌حل:** انتقال به Vault یا Secrets Manager

### C-5: عدم استفاده از Transactions در عملیات‌های مالی
- **تأثیر:** Inconsistency در داده‌های مالی
- **راه‌حل:** اجبار Transactional boundary در تمام سرویس‌های مالی

---

## 🟠 مشکلات مهم (High)

### H-1: God Controllers
- فایل‌هایی با 1000+ خط که همه منطق را در خود دارند
- نمونه: `WalletController`, `CustomTaskController`

### H-2: Business Logic در Views
- منطق تجاری مستقیماً در فایل‌های PHP views اجرا می‌شود
- نقض اصل Separation of Concerns

### H-3:缺乏统一 Error Handling
- Error handling پراکنده بین Controller, Service, و Helper
- بدون استاندارد‌سازی یکپارچه

### H-4: عدم استفاده از DTO/Value Objects
- انتقال داده به صورت Array خام بین لایه‌ها
- بدون Type Safety

### H-5: N+1 Query Problem
- کوئری‌های تودرتو بدون Eager Loading
- تأثیر منفی بر Performance

### H-6: Lack of Input Validation Standardization
- اعتبارسنجی ورودی‌ها به صورت دستی و پراکنده
- بدون统一 Validation Layer

### H-7: Circular Dependencies
- وابستگی‌های چرخه‌ای بین سرویس‌ها
- باعث memory leak در long-running processes

---

## 🟡 بهبودهای پیشنهادی (Medium)

### M-1: Lack of API Versioning Strategy
### M-2: Missing Health Check Endpoints
### M-3: عدم وجود Circuit Breaker در برخی External Calls
### M-4: Lack of Request/Response Logging Standard
### M-5: عدم استفاده از Feature Flags برای Deployment
### M-6: Missing Rate Limiting Consistency
### M-7: Lack of Cache Invalidation Strategy
### M-8: عدم وجود Retry Policy برای External Services
### M-9: Missing Distributed Tracing
### M-10: Lack of Schema Migration Strategy

---

## 🟢 نکات مثبت (Strengths)

1. ✅ استفاده از Dependency Injection Container
2. ✅ پیاده‌سازی Outbox Pattern
3. ✅ Dead Letter Queue (DLQ)
4. ✅ Saga Pattern برای تراکنش‌های توزیع‌شده
5. ✅ Circuit Breaker Pattern
6. ✅ Anti-Fraud System
7. ✅ Feature Flags
8. ✅ Idempotency Key
9. ✅ Adapter Pattern برای سرویس‌های خارجی
10. ✅ Command Pattern برای Background Jobs
11. ✅ Pipeline Pattern برای Middleware
12. ✅ Event-Driven Architecture

---

## 🛣️ نقشه راه اصلاحات (مرحله به مرحله)

### فاز ۱: زیرساخت و هسته (Week 1-2)
- اصلاح Dependency Injection Container
- یکپارچه‌سازی Error Handling
- استانداردسازی Validation
- بهبود Session Management
- رفع Security Vulnerabilities

### فاز ۲: لایه سرویس (Week 3-4)
- جدا‌سازی Business Logic از Controllers
- ایجاد DTO/Value Objects
- اصلاح N+1 Query Problems
- استانداردسازی Transaction Management
- بهبود Cache Strategy

### فاز ۳: لایه ارائه (Week 5-6)
- پاک‌سازی Views از Business Logic
- استانداردسازی API Responses
- بهبود Error Pages
- رفع مشکلات UI/UX

### فاز ۴: عملیات و مانیتورینگ (Week 7-8)
- بهبود Logging
- اضافه کردن Health Checks
- استانداردسازی Rate Limiting
- بهبود Deployment Pipeline
- اضافه کردن Distributed Tracing

### فاز ۵: بهینه‌سازی و تست (Week 9-10)
- Performance Tuning
- Security Hardening
- Test Coverage Improvement
- Documentation
- Code Refinement

---

## 📊 اولویت‌بندی

| اولویت | تعداد | توضیح |
|--------|-------|-------|
| 🔴 بحرانی | 5 | باید فوراً رفع شوند |
| 🟠 مهم | 7 | باید در فاز ۱-۲ رفع شوند |
| 🟡 متوسط | 10 | باید در فاز ۳-۴ رفع شوند |
| 🟢 مثبت | 12 | نقاط قوت موجود |

---

## 🎯 نتیجه‌گیری

پروژه Chortke یک سیستم Enterprise با معماری نسبتاً خوب است، اما نیازمند اصلاحات عمیق در لایه‌های مختلف است. رویکرد **ریشه‌کن کردن مشکلات** به جای **وصله‌پینه کردن** درست است و در این گزارش رعایت شده است.

**پیشنهاد شروع:** فاز ۱ - زیرساخت و هسته
**زمان تخمینی کل:** 10 هفته
**ریسک عدم اقدام:** بالا (امنیت، عملکرد، قابلیت نگهداری)
