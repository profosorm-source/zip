# گزارش Audit ماژول‌های کسب درآمد / تسک‌ها

تاریخ: 2026-06-19  
دامنه بررسی: مسیرها و فایل‌های مرتبط با:

- `/tasks`
- `/custom-tasks/*`
- `/social-tasks/*`
- `/seo/*`
- `/adtube/*`
- `/content/*`
- `/influencer/*`
- مسیرهای تبلیغ‌دهنده مرتبط با `/ads` و `/influencer/ads`

---

## خلاصه اجرایی

این بخش واقعاً چند بار ریفکت شده و ترکیبی از سه نوع کد دارد:

1. **مسیرهای جدید و معتبر** که باید حفظ و تکمیل شوند.
2. **مسیرهای سازگاری / Legacy Compatibility** که نباید منطق مستقل جدید داشته باشند و بهتر است فقط redirect یا wrapper باشند.
3. **کدهای ناقص یا جامانده از ریفکت** که باعث خطاهای runtime یا parse error می‌شوند.

بنابراین برای این بخش نباید صرفاً هر `Method not found` را با اضافه کردن منطق جدید حل کنیم. ابتدا باید تشخیص دهیم که آن مسیر واقعاً متعلق به معماری جدید است یا فقط legacy route است.

---

## تصمیم معماری پیشنهادی برای بازار تسک‌ها

### بازار یکپارچه تسک‌ها

این سه نوع باید در یک صفحه واحد نمایش داده شوند:

- Social Tasks
- SEO Tasks
- Custom Tasks

صفحه اصلی پیشنهادی:

```text
/tasks
/tasks?type=social
/tasks?type=seo
/tasks?type=custom_task
```

### ماژول‌های مستقل

این موارد نباید داخل بازار تسک‌ها حل شوند و باید مستقل بمانند:

- AdTube
- Influencer
- Content / درآمد از محتوا

---

# 1. Audit مسیرهای Route و متدهای Controller

## 1.1 مسیرهای OK

این مسیرها از نظر وجود Controller و Method فعلاً OK هستند:

```text
GET  /tasks                              TaskFeedController::index
GET  /seo                                SeoController::index
GET  /seo/history                        SeoController::history
POST /seo/start                          SeoController::start
GET  /seo/{id}/execute                   SeoController::execute
POST /seo/{id}/complete                  SeoController::complete
GET  /seo/execution/{id}                 SeoController::showExecution
POST /seo/{id}/cancel                    SeoController::cancel
GET  /custom-tasks                       CustomTaskController::index
GET  /custom-tasks/available             CustomTaskController::available
GET  /custom-tasks/my-submissions        CustomTaskController::mySubmissions
GET  /custom-tasks/{id}                  CustomTaskController::show
POST /custom-tasks/start                 CustomTaskController::start
POST /custom-tasks/{id}/submit-proof     CustomTaskController::submitProof
POST /custom-tasks/review                CustomTaskController::review
GET  /social-tasks                       SocialTaskController::index
GET  /social-tasks/dashboard             SocialTaskController::executorDashboard
GET  /social-tasks/history               SocialTaskController::history
POST /social-tasks/start                 SocialTaskController::start
GET  /social-tasks/{id}/execute          SocialTaskController::showExecute
POST /social-tasks/{id}/submit           SocialTaskController::submit
GET  /adtube                             AdtubeController::index
GET  /adtube/history                     AdtubeController::history
POST /adtube/start                       AdtubeController::start
GET  /adtube/{id}/execute                AdtubeController::showExecute
POST /adtube/{id}/submit                 AdtubeController::submit
GET  /influencer                         InfluencerController::myProfile
GET  /influencer/register                InfluencerController::register
POST /influencer/register                InfluencerController::storeProfile
POST /influencer/verify                  InfluencerController::submitVerification
GET  /influencer/orders                  InfluencerController::myOrders
GET  /influencer/ads                     InfluencerController::advertise
GET  /influencer/ads/create              InfluencerController::createOrder
POST /influencer/ads/store               InfluencerController::storeOrder
GET  /ads                                AdsController::index
GET  /ads/create                         AdsController::create
POST /ads/store                          AdsController::store
GET  /ads/{id}                           AdsController::show
```

---

## 1.2 مسیرهای مشکوک / Legacy باقی‌مانده

این مسیرها در Route ثبت شده‌اند ولی متدهای Controller وجود ندارند:

```text
GET  /social-ads/execution/{id}          SocialTaskController::executionDetail       MISSING
POST /social-ads/execution/{id}/approve  SocialTaskController::approveExecution      MISSING
POST /social-ads/execution/{id}/reject   SocialTaskController::rejectExecution       MISSING
GET  /social-tasks/{id}/rate             SocialTaskController::rateExecutionForm     MISSING
POST /social-tasks/{id}/rate             SocialTaskController::rateExecution         MISSING
GET  /social-ratings/history             SocialTaskController::ratingHistory         MISSING
```

### تحلیل

احتمال زیاد این‌ها از ریفکت‌های قبلی Social Ads / Rating باقی مانده‌اند.

### پیشنهاد

- اگر rating/review هنوز بخشی از محصول نیست: این routeها حذف یا به صفحه تاریخچه/داشبورد redirect شوند.
- اگر قرار است rating فعال باشد: باید service و view مربوطه به‌صورت کامل و تست‌شده پیاده‌سازی شوند، نه فقط stub.

---

# 2. Audit Viewها و خطاهای Syntax

اجرای `php -l` روی Viewهای مرتبط نشان داد این فایل‌ها هنوز parse error دارند:

```text
views/user/content/revenues.php
views/user/social-tasks/execute.php
views/user/social-tasks/execution-detail.php
views/user/social-tasks/rating-form.php
```

## تحلیل

این‌ها به احتمال زیاد فایل‌هایی هستند که در مرحله استخراج assetها / حذف inline script / ریفکت viewها ناقص مانده‌اند.

## اولویت اصلاح

### فوری

```text
views/user/social-tasks/execute.php
```

چون مسیر واقعی اجرای Social Task از آن استفاده می‌کند:

```text
GET /social-tasks/{id}/execute
```

اگر این فایل خراب باشد، کاربر نمی‌تواند تسک social را کامل کند.

### متوسط

```text
views/user/social-tasks/execution-detail.php
views/user/social-tasks/rating-form.php
```

چون routeهای مرتبط با rating/detail فعلاً مشکوک یا legacy هستند.

### مستقل / بعدی

```text
views/user/content/revenues.php
```

چون Content ماژول مستقل است و بهتر است در فاز طراحی Content Hub بررسی شود.

---

# 3. Audit الگوی دوبار رندر شدن View

در این پروژه helper زیر هم `echo` می‌کند و هم string برمی‌گرداند:

```php
view(...)
```

پس هر Controller که بنویسد:

```php
return view(...)
```

باعث دوبار رندر شدن صفحه می‌شود.

## موارد یافت‌شده

```text
app/Controllers/User/ContentController.php
app/Controllers/User/AdsController.php
```

### ContentController

```text
return view('user.content.index', ...)
return view('user.content.create', ...)
return view('user.content.show', ...)
return view('user.content.revenues', ...)
return view('errors.500')
return view('errors.404')
```

### AdsController

```text
return view('user.ads.index', ...)
return view('user.ads.create', ...)
return view('user.ads.show', ...)
```

## پیشنهاد

برای جلوگیری از duplicate render:

```php
view(...);
return;
```

یا استفاده از:

```php
$this->view(...)
```

ولی باید یک سیاست واحد برای کل پروژه انتخاب شود.

---

# 4. Audit DI / Container

## مشکل پیدا شده

```text
App\Services\AdSystemManager::__construct(array $adapters)
```

Container نمی‌تواند primitive array بسازد.

## وضعیت فعلی

برای جلوگیری از خطای AdTube، در `bootstrap/app.php` binding اختصاصی برای `AdSystemManager` اضافه شده است.

## نکته معماری

اگر AdTube در سمت executor واقعاً به `AdSystemManager` نیاز ندارد، بهتر است dependency از `AdtubeController` حذف شود تا کنترلر سبک‌تر شود.

---

# 5. Audit Search

## مشکل پیدا شده

برخی Controllerها هنوز صدا می‌زنند:

```php
searchAdTasks()
```

ولی اگر provider مناسب register نشده باشد، `SearchOrchestrator` خطا می‌دهد.

## وضعیت فعلی

متد fallback زیر اضافه شده است:

```php
SearchOrchestrator::searchAdTasks()
```

## پیشنهاد معماری

باید مشخص شود `searchAdTasks` API رسمی بماند یا همه controllerها به یکی از این‌ها مهاجرت کنند:

```php
searchAdminModule()
searchModules()
SearchQuery
```

فعلاً برای compatibility نگه داشتن `searchAdTasks` قابل قبول است چون در چند Controller استفاده شده است.

---

# 6. Audit Custom Tasks

## وضعیت فعلی

Routeهای زیر وجود دارند:

```text
/custom-tasks
/custom-tasks/available
/custom-tasks/my-submissions
/custom-tasks/{id}
/custom-tasks/start
/custom-tasks/{id}/submit-proof
/custom-tasks/review
/custom-tasks/detail/{id}
/custom-tasks/{id}/start-execution
/custom-tasks/submissions/{id}/submit-proof-action
/custom-tasks/my-submissions-list
/custom-tasks/disputes-list
/custom-tasks/submissions/{id}/dispute-action
```

## تحلیل

بخشی از این مسیرها legacy هستند و بخشی compatibility alias.

## تصمیم پیشنهادی

برای Worker-side:

```text
/tasks?type=custom_task          لیست
/custom-tasks/{id}               جزئیات
/custom-tasks/{id}/start-execution شروع
/custom-tasks/{id}/submit-proof  ارسال مدرک
/custom-tasks/my-submissions     اجراهای من
```

مسیرهای alias مثل زیر بهتر است فقط redirect شوند:

```text
/custom-tasks/available → /tasks?type=custom_task
/custom-tasks/detail/{id} → /custom-tasks/{id}
/custom-tasks/my-submissions-list → /custom-tasks/my-submissions
```

---

# 7. Audit Social Tasks

## وضعیت فعلی

تصمیم جدید این است که Social Tasks در بازار یکپارچه نمایش داده شود:

```text
/tasks?type=social
```

اما مسیرهای زیر هنوز وجود دارند:

```text
/social-tasks
/social-tasks/dashboard
/social-tasks/history
/social-tasks/{id}/execute
/social-tasks/{id}/submit
/social-tasks/{id}/rate
/social-ratings/history
/social-ads/execution/{id}
```

## تصمیم پیشنهادی

- `/social-tasks` باید redirect/landing به `/tasks?type=social` باشد.
- `/social-tasks/{id}/execute` مسیر واقعی اجرای تسک باقی بماند.
- `/social-tasks/{id}/submit` مسیر واقعی ارسال اجرای تسک باقی بماند.
- Rating و social-ads legacy باید یا حذف شوند یا کامل شوند.

## مشکل فوری

```text
views/user/social-tasks/execute.php
```

parse error دارد و باید قبل از تست عملی اجرای social task اصلاح شود.

---

# 8. Audit SEO Tasks

## وضعیت فعلی

Routeهای SEO همگی متد دارند:

```text
/seo
/seo/history
/seo/start
/seo/{id}/execute
/seo/{id}/complete
/seo/execution/{id}
/seo/{id}/cancel
```

## نکته

لیست SEO باید در بازار یکپارچه نمایش داده شود:

```text
/tasks?type=seo
```

ولی execute/complete/cancel مستقل باقی بمانند.

## ریسک

`SeoController::index` از `searchAdTasks` استفاده می‌کند؛ فعلاً fallback اضافه شده است اما بهتر است در فاز بعد با `/tasks?type=seo` همسو شود.

---

# 9. Audit AdTube

## وضعیت فعلی

AdTube مستقل است و باید مستقل بماند.

Routeها OK هستند:

```text
/adtube
/adtube/history
/adtube/start
/adtube/{id}/execute
/adtube/{id}/submit
```

## مشکل قبلی

DI مربوط به `AdSystemManager` رفع شد.

## پیشنهاد

در فاز طراحی AdTube، `AdtubeController` بررسی شود که آیا واقعاً `AdSystemManager` نیاز دارد یا dependency اضافه است.

---

# 10. Audit Content

Content مستقل است.

## مشکلات فعلی

- چند `return view(...)` دارد که می‌تواند duplicate render ایجاد کند.
- `views/user/content/revenues.php` parse error دارد.

## پیشنهاد

قبل از طراحی Content Hub، این دو مورد باید اصلاح شوند.

---

# 11. Audit Influencer

Influencer مستقل است و باید مستقل بماند.

Routeهای موجود در `routes/missing.php` از نظر متدها OK هستند.

## پیشنهاد

چون در `routes/missing.php` تعریف شده، بهتر است بعداً به فایل route رسمی‌تر منتقل شود یا حداقل با کامنت `compatibility routes` مشخص شود.

---

# جمع‌بندی اولویت‌های اصلاح بعدی

## P0 — قبل از تست واقعی بازار تسک‌ها

1. اصلاح `views/user/social-tasks/execute.php`
2. بررسی سناریوی کامل:
   - نمایش social در `/tasks?type=social`
   - شروع اجرا
   - نمایش صفحه execute
   - submit proof / submit execution
   - تغییر status
3. بررسی سناریوی SEO:
   - نمایش در `/tasks?type=seo`
   - execute
   - complete
4. بررسی سناریوی Custom:
   - نمایش در `/tasks?type=custom_task`
   - show
   - start
   - submit proof

## P1 — پاکسازی routeهای legacy

1. تعیین تکلیف `/social-ads/*`
2. تعیین تکلیف `/social-tasks/{id}/rate`
3. تعیین تکلیف `/social-ratings/history`
4. تعیین تکلیف aliasهای custom-task

## P2 — ماژول‌های مستقل

1. طراحی AdTube مستقل
2. طراحی Influencer مستقل
3. طراحی Content مستقل

---

# نتیجه نهایی Audit

بازار تسک‌های سه‌گانه ایده درست و مسیر اصلی است، اما قبل از توسعه بیشتر باید چند قطعه legacy و چند view ناقص پاکسازی شوند. مهم‌ترین خطر فعلی این است که UI جدید `/tasks` خوب نمایش داده شود، اما مسیر اجرای واقعی Social/SEO/Custom به دلیل viewهای ناقص یا routeهای legacy شکست بخورد.
