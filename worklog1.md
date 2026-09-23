
ممیزی امنیتی RBAC
36:
بررسی ایستا و فقط‌خواندنی کد پروژه چورتکه. هیچ فایلی از پروژه تغییر نکرده است.

37:
38: 39:
40: ریسک بالا: اعطای دسترسی بیش از حد 41:
نقش support می‌تواند وضعیت اینفلوئنسر را تغییر دهد
42:
گارد مسیرهای ادمین، نقش support را وارد ناحیه ادمین می‌کند و برای کنترلر اینفلوئنسر به‌صورت پیش‌فرض مجوز influencer.manage می‌خواهد. همین مجوز در seed به support داده شده است؛ بنابراین support می‌تواند عملیات تأیید، رد یا تعلیق پروفایل و تأیید یا رد verification را فراخوانی کند.

43:
44:
config/admin_permissions.php:235-240؛ پیش‌فرض کنترلر روی influencer.manage است و فقط orders به مشاهده محدود شده.
45:
database/migrations/2026_08_08_0001_rbac_full_permissions.sql:85-107؛ مجوز influencer.manage به support داده می‌شود.
46:
routes/admin.php:266-274؛ مسیرهای تغییر وضعیت با همان گارد مشترک منتشر شده‌اند.
47:
app/Controllers/Admin/InfluencerController.php:95-152,241-278؛ عملیات تغییر وضعیت کنترلر، گارد full-admin جداگانه ندارد.
48:
49:
اثر: یک حساب support می‌تواند اعتماد/فعال‌بودن پروفایل‌های اینفلوئنسر و نتیجه verification را تغییر دهد. مسیر تسویه اختلاف از این finding مستقیماً قابل سوءاستفاده نیست.

50:
اصلاح: پیش‌فرض کنترلر را به مجوز مشاهده تغییر دهید؛ برای moderation یک مجوز مستقل بسازید و فقط عملیات صریح موردنیاز support را به آن نگاشت کنید. در سرویس verification نیز کنترل مجوز را به‌عنوان دفاع لایه‌ای اضافه کنید.

51:
52: 53:
54:
55:
کنترل‌های سالم تأییدشده
56:
57:
app/Services/Dispute/DisputeCommandService.php:623-661؛ حل اختلاف مالی فقط برای admin و super_admin مجاز است و قفل ردیف از حل دوباره جلوگیری می‌کند.
58:
app/Services/Dispute/DisputeCommandService.php:827-875؛ اختلاف اینفلوئنسر از مسیر escrow حل می‌شود، نه reverse عمومی.
59:
app/Middleware/AdminPermissionGuard.php:55-78؛ مسیرهای بدون نگاشت رد می‌شوند و مجوز از دیتابیس بررسی می‌شود.
60:
مجوزهای مالی support در seed فقط مشاهده‌ای هستند؛ مسیرهای approve/reject مالی مجوز جدا دارند.
61:
مسیر ظاهری ساخت حساب privileged بررسی شد؛ app/Services/User/UserService.php:79-128 role و status ورودی را حذف و نقش حساب جدید را روی user تنظیم می‌کند.
62:
63:
64: 65:
66:
محدودیت و گام بعدی
67:
68:
ممیزی فعلی ایستا است؛ اجرای تست HTTP، دیتابیس و تست نقش‌ها انجام نشد.
69:
PHP CLI در محیط موجود نیست؛ parser خودکار مسیرها اجرا نشد.
70:
تست‌های پیشنهادی: درخواست‌های approve/reject/suspend اینفلوئنسر با support باید 403 شوند؛ همان درخواست‌ها با admin باید طبق policy مجاز باشند؛ حل اختلاف مالی با support باید 403 بماند.
71:
72:
73:
74: 75:
دامنه بررسی: routeها، middlewareهای احراز هویت و RBAC، کنترلرهای ادمین، validatorها و سرویس‌های مالی/اختلاف.
76:
77: 78: (End of file - total 78 lines)
Migration safety and financial uniqueness audit
23:
Reviewed 200 migration files, the migration runner, related schema code, and the available tests. No repository files were changed.

24: 25:
26:
200
migration files: 165 SQL + 35 PHP
27:
3
confirmed findings requiring attention
28:
0
runtime test suites executed
29:
30: 31:
32:
Findings
33:
34: High · data safety 35:
PHP migrations bypass the destructive-operation guard
36:
MigrationService applies ALLOW_DESTRUCTIVE_MIGRATIONS and populated-table checks only inside the SQL statement loop. PHP files are executed directly with require.

37:
app/Services/MigrationService.php:241-254 38: database/migrations/2026_09_22_0001_purge_legacy_api_tokens.php:59-68 DELETE FROM api_tokens 39: database/migrations/2026_09_17_0001_drop_dead_schema_items.php:62-78 DROP TABLE / DROP COLUMN
40:
The purge file documents that it must never run after the test phase, but the code has no environment or explicit opt-in check.

41:
42: 43:
44: High · connection state 45:
Foreign-key checks can remain disabled on a reused connection
46:
Several SQL migrations set FOREIGN_KEY_CHECKS=0 and restore it only on the happy path. If a later statement fails, the runner exits the file without a session cleanup. Web/FPM database connections are persistent.

47:
app/Services/MigrationService.php:406-412 failure path 48: core/Database.php:255-266 persistent FPM PDO 49: database/migrations/2026_06_10_0044_sync_missing.sql:2,123 session toggle
50:
A later request using the same PDO session may run with referential-integrity checks disabled until the connection is recycled.

51:
52: 53:
54: Medium · deployment reliability 55:
Escrow uniqueness migrations temporarily contradict the lifecycle contract
56:
2026_08_24_0002 correctly allows a new lifecycle after a terminal escrow by using a generated key. 2026_09_20_0007 then adds unconditional UNIQUE(order_id, order_type), which blocks that lifecycle and can fail if historical rows already contain repeated lifecycles. 2026_09_22_0002 later removes the simple unique index.

57:
database/migrations/2026_08_24_0002_escrow_active_order_uniqueness.sql:3-10 58: database/migrations/2026_09_20_0007_add_unique_key_to_escrow_transactions.php:17-20,72-73 59: database/migrations/2026_09_22_0002_audit_wave1_schema_convergence.php:119-136 60:
The final schema intent is correct, but a partially completed deployment can stop at the intermediate constraint and prevent new escrow lifecycles.

61:
62:
63: 64:
65:
What is already sound
66:
67:
Both SQL and PHP migrations are discovered, naturally sorted, checksum-tracked, and recorded by full filename.
68:
The final escrow contract is covered by a database integration test: one active hold per order, with terminal reopening allowed.
69:
Feature-flag rollout convergence, BannerPlacement schema convergence, and withdrawal-review cleanup were cross-checked with their consumers.
70:
Unique-index migrations for fraud flags, social ratings, and content revenue include duplicate cleanup before adding the constraint.
71:
72:
73: 74:
75:
Verification limits
76:
PHP is unavailable in the current worker environment, PHPUnit is not runnable, and there is no live MySQL/MariaDB connection. Findings are therefore static and should be confirmed in a disposable database before remediation.

77:
78:
Recommended order: guard PHP migrations first, add connection-state cleanup, then remove the intermediate escrow constraint or make the rollout atomic.
79:
80: 81: (End of file - total 81 lines)
تکمیل ممیزی Ads، AdTube و Reward Boost
17:
محدوده: ایجاد آگهی، تسویه AdTube، پاداش‌های بوست، redirect، race و XSS ایستای پنل ادمین

18:
وضعیت: پیش از production نیازمند اصلاح · هیچ فایل سورس پروژه تغییر نکرده است.

19:
20: 21:
22:
۷
یافته امنیتی قابل اقدام
23:
۳
یافته High
24:
۴
یافته Medium
25:
۱
نقص عملکردی جداگانه
26:
27: 28:
یافته‌های High
29:
30:
HIGH / P1کاربر می‌تواند مبلغ نگهداری آگهی را از بودجه واقعی جدا کند
31:
app/Controllers/User/AdsController.php:79-89 · app/Services/AdSystemManager.php:290-313,348-424
32:
اثر: payload خام فیلدهای ناشناخته را نگه می‌دارد. اگر کاربر بدون کوپن coupon_charge_total مثبت بفرستد، مرحلهٔ مالی همان مبلغ را به‌عنوان total برداشت/escrow قبول می‌کند، درحالی‌که آداپتور رکورد آگهی را با بودجهٔ واقعی می‌سازد. در نتیجه invariant بین بودجهٔ کمپین و escrow می‌شکند و کمپین کم‌پشتوانه ایجاد می‌شود.

33:
اصلاح: فیلدهای داخلی مانند coupon_charge_total را از ورودی حذف کنید و فقط بعد از اعتبارسنجی سروری کوپن مقداردهی کنید. در Saga نیز وجود coupon معتبر، token معتبر و تطابق مبلغ با quote را assert کنید.

34:
35: 36:
37:
HIGH / P1تسویهٔ AdTube با متریک‌های قابل‌جعل پاداش می‌دهد
38:
app/Controllers/User/AdtubeController.php:209-255 · app/Services/Ads/AdsBudgetSettlementService.php:522-580 · public/assets/js/views/useradtubeexecute.js:15-34
39:
اثر: سرور فقط progress_percent >= 80 و سرعت را بررسی می‌کند؛ watch_time و progress به‌صورت مستقل از کلاینت پذیرفته می‌شوند. ارسال progress برابر ۸۰ با watch time صفر پس از حداقل زمان کوتاه از گارد elapsed عبور می‌کند و settlement پاداش را واریز می‌کند. تایمر صفحه نیز صرفاً client-side است.

40:
اصلاح: مدت واقعی را از started_at تا زمان درخواست و سیاست سرعت محاسبه کنید، progress را سرور محاسبه/محدود کنید، حداقل watch time متناسب با duration را الزام کنید و execution را با یک challenge یا signal قابل‌اعتبارسنجی به تسویه متصل کنید.

41:
42: 43:
44:
HIGH / P1پذیرش Reward Boost بدون اثبات تماشا، priority واقعی ایجاد می‌کند
45:
routes/missing.php:79-82 · app/Controllers/User/AdtubeController.php:560-584 · helpers/functions.php:537-588,612-637 · app/Controllers/Admin/*Controller.php:boost_queue_order call-sites
46:
اثر: endpoint accept با احراز هویت و CSRF فقط area را بررسی می‌کند و بدون execution، proof یا transaction معتبر state را accepted می‌نویسد. این state روی صف برداشت، KYC، واریز، اختلاف، تیکت، تسک اجتماعی و ویترین مصرف می‌شود؛ همچنین در تیکت و KYC مستقیماً priority/SLA را تغییر می‌دهد. areaهای مجاز هم هفت ناحیهٔ حساس هستند.

47:
اصلاح: accept را به execution شناسه‌دار و یک‌بارمصرف وصل کنید؛ سرور باید completion، duration، مالکیت و replay را بررسی کند و سپس state را اتمیک بنویسد. CSRF به‌تنهایی جای اثبات کسب پاداش نیست.

48:
49: 50:
یافته‌های Medium
51:
52:
MEDIUM / P2اجرای مستقیم آگهی خود کاربر مسدود نشده است
53:
app/Controllers/User/AdtubeController.php:120-138 · app/Services/Ads/AdsBudgetSettlementService.php:526-580 · app/Models/Ads.php:459-472
54:
اثر: فهرست عمومی مالک آگهی را حذف می‌کند، اما endpoint start فقط type/status را بررسی می‌کند و شرط advertiser_id !== executor_id ندارد. کاربر می‌تواند با ad_id مستقیم execution بسازد، view/completion و سابقهٔ ساختگی ایجاد کند و از شمارش completion برای مسیرهای پاداش استفاده کند.

55:
اصلاح: عدم مالکیت را هم در start و هم داخل settlement، زیر lock ردیف آگهی، الزام کنید؛ فهرست عمومی نباید تنها مرز کنترل دسترسی باشد.

56:
57: 58:
59:
MEDIUM / P2اعتبارسنجی لینک AdTube allowlist واقعی ندارد
60:
app/Adapters/AdTubeAdapter.php:85-101 · views/user/adtube/execute.php:31-35 · helpers/security.php:281-300
61:
اثر: regex فقط وجود رشتهٔ youtube.com یا youtu.be را می‌خواهد. لینکی مثل دامنهٔ مهاجم با query حاوی youtube.com پذیرفته می‌شود و چون استخراج video ID شکست می‌خورد، در صفحهٔ اجرا به لینک خارجی HTTP/HTTPS تبدیل می‌شود؛ sanitize_url فقط scheme را محدود می‌کند، نه host را.

62:
اصلاح: URL را با parse_url و allowlist دقیق host/path کاننیکال کنید، فقط HTTPS و شناسهٔ ویدیو معتبر را بپذیرید و fallback خارجی را حذف یا محدود به همان allowlist کنید.

63:
64: 65:
66:
MEDIUM / P2سقف سه تماشای هم‌زمان، TOCTOU دارد
67:
app/Controllers/User/AdtubeController.php:127-146 · app/Models/AdTubeExecutionModel.php:27-53
68:
اثر: countActiveForUser قبل از findOrCreate و خارج از قفل سراسری بررسی می‌شود. درخواست‌های هم‌زمان برای آگهی‌های متفاوت می‌توانند همگی مقدار کمتر از ۳ را ببینند و بیش از سه execution فعال بسازند. قفل فعلی فقط duplicate همان user+ad را کنترل می‌کند.

69:
اصلاح: پذیرش slot را در transaction با قفل ردیف کاربر/شمارندهٔ اتمیک انجام دهید؛ فقط پس از موفقیت reserve رکورد execution بسازید و row count/rollback را بررسی کنید.

70:
71: 72:
موارد ایستای تکمیلی
73:
74:
MEDIUM / P2Stored XSS در targeting محیط Feature Flag ادمین
75:
views/admin/features/index.php:332-335 · public/assets/js/admin/featuresindex.js:56-68,167-225
76:
اثر: environments در نمای فهرست با implode بدون escape چاپ می‌شود و TagInput/preview نیز مقادیر tag را با innerHTML خام درج می‌کند. اگر ادمینی با سطح پایین‌تر مقدار HTML ذخیره کند، مشاهده یا ویرایش Feature Flag می‌تواند JavaScript را در session ادمین دیگر اجرا کند.

77:
اصلاح: در PHP برای هر مقدار e() و در JS از textContent و createElement استفاده کنید؛ برای داده‌های tag محدودیت کاراکتر/charset و validation سمت سرور اضافه کنید.

78:
79: 80:
81:
LOW / FUNCTIONALقرارداد فیلد صفحهٔ اجرای AdTube با query هماهنگ نیست
82:
app/Models/AdTubeExecutionModel.php:65-75 · views/user/adtube/execute.php:2-5
83:
اثر: query مقدار لینک را با alias video_link برمی‌گرداند، اما view فقط youtube_url و target_url را می‌خواند. صفحهٔ اجرا در مسیر عادی لینک/عنوان مورد انتظار را ندارد و fallback ویدیو نمایش داده نمی‌شود.

84:
اصلاح: alias را به نام قرارداد view تغییر دهید یا view را روی video_link و ad_title تطبیق دهید؛ یک تست integration برای start → execute اضافه شود.

85:
86: 87:
راستی‌آزمایی و محدودیت
88:
89:
90:
CSRF در routeهای AdTube و reward وجود دارد، اما برای findingهای مالی/پاداشی کافی نیست؛ مسئله نبود authorization/proof کسب‌وکاری است.
91:
آپلود عمومی از نظر MIME واقعی، پسوند، magic bytes، ابعاد و re-encode بررسی شد؛ نقص قطعی در این مسیر ثبت نشد.
92:
PHP، Composer و Docker در محیط موجود نصب نیستند؛ PHPUnit، PHPStan و تست runtime/مرورگر اجرا نشدند. یافته‌های بالا از مسیرهای کد و قرارداد داده اثبات شده‌اند، نه از اجرای واقعی.
93:
فایل‌های گزارش قبلی در پوشهٔ outputs دست‌نخورده باقی مانده‌اند.
94:
95:
96:
گزارش نهایی ممیزی ایستای امنیتی
18:
نتیجه: پیش از production نیازمند اصلاح است. فقط کد خوانده شد و هیچ فایل پروژه تغییر نکرد.

19:
20: 21:
22:
۶
یافتهٔ قابل اقدام
23:
۲
High جدید در این مرحله
24:
۳
مسیر مشکوک ردشده
25:
۰
تغییر سورس
26:
27: 28:
یافته‌ها
29:
30:
HIGHکلیدها و credential ادمین داخل فایل محیطی هستند
31:
chortke/.env:19-20, 99, 117-118
32:
فایل local محیطی شامل APP_KEY، SECURITY_API_TOKEN_SECRET و یک رمز عبور ادمین به‌صورت plaintext است. همین فایل همچنین APP_DEBUG=true و SESSION_SECURE=false دارد.

33:
اثر: اگر این فایل در archive، image یا deployment production باقی بماند، کلیدهای رمزنگاری/توکن و credential اولیهٔ ادمین افشا می‌شوند و امکان جعل توکن یا دسترسی مدیریتی ایجاد می‌شود.

34:
اصلاح: همهٔ کلیدها و رمزها را فوراً rotate کنید، فایل واقعی .env را از artifact/repository حذف کنید، secret manager استفاده کنید و در production مقدارهای debug و session را امن کنید. وجود .gitignore در خطوط 1-5 مانع افشای فایل موجود در bundle نمی‌شود.

35:
36: 37:
38:
HIGHخطای تغییر وضعیت escrow هنگام رد تسک بلعیده می‌شود
39:
chortke/app/Services/CustomTask/AdminCustomTaskService.php:177-240
40:
در callback رد تسک، refund کیف پول/outbox و reset بودجه انجام می‌شود؛ سپس UPDATE جدول escrow_transactions در try/catch قرار دارد و Throwable بدون rollback یا failure برگرداندن نادیده گرفته می‌شود. این callback داخل transaction قفل‌شدهٔ StateMachineService::executeTransition() اجرا می‌شود.

41:
اثر: در خطای SQL یا schema، تسک می‌تواند rejected و refund‌شده ثبت شود اما escrow همچنان held/pending بماند؛ موجودی کاربر و liability escrow از هم جدا می‌شوند و recovery/idempotency قابل اتکا نیست.

42:
اصلاح: خطا را دوباره throw کنید تا کل transaction rollback شود، row-count و وجود escrow را بررسی کنید، و refund کیف پول و transition escrow را با یک قرارداد اتمیک و idempotent تست کنید.

43:
44: 45:
46:
MEDIUMفیلتر NULL در فهرست کاربران predicate اشتباه می‌سازد
47:
chortke/app/Models/User.php:792-794 · chortke/core/QueryBuilder.php:312-315
48:
فراخوانی سه‌پارامتری where('email_verified_at', 'IS NULL', null) به shorthand دوپارامتری تبدیل می‌شود؛ نتیجه مقایسهٔ ستون با مقدار IS NULL است، نه شرط SQL مربوط به NULL.

49:
اثر: فیلتر is_verified نتیجهٔ نادرست می‌دهد. call-site فعلی این فیلتر را از کنترلر ادمین ارسال نمی‌کند، بنابراین اثر فعلی latent است.

50:
اصلاح: API صریح whereNull/whereNotNull اضافه کنید یا منطق عملگر NULL را پیش از shorthand پردازش کنید و برای هر دو حالت تست واحد بنویسید.

51:
52: 53:
54:
MEDIUMسقف عمر مطلق نشست قابل دور زدن است
55:
chortke/core/RedisSessionHandler.php:147-158, 181-195
56:
marker زمان ایجاد نشست با NX ساخته می‌شود، اما پس از انقضای marker، نبودن آن در read() به‌عنوان پایان عمر قطعی رد نمی‌شود و write بعدی می‌تواند marker و TTL را دوباره ایجاد کند.

57:
اثر: نشست فعال می‌تواند با ادامهٔ فعالیت از session.absolute_lifetime عبور کند.

58:
اصلاح: نبودن marker برای کلید نشست موجود را expired تلقی کنید و از بازسازی marker پس از شروع چرخه جلوگیری کنید.

59:
60: 61:
62:
MEDIUMقفل نشست فقط با PID آزاد می‌شود
63:
chortke/core/RedisSessionHandler.php:246, 276-279
64:
توکن lock مقدار getmypid() است. در multi-process یا multi-container، PID می‌تواند تکراری باشد و پس از expiry یک process قدیمی قفل process دیگر را آزاد کند.

65:
اثر: احتمال snapshot هم‌زمان و از دست رفتن آخرین تغییرات نشست در شرایط race.

66:
اصلاح: برای هر acquisition توکن تصادفی یکتا بسازید و release را با اسکریپت اتمیک Redis بر اساس همان توکن انجام دهید.

67:
68: 69:
70:
LOW / OPSFallback نشست از Redis به فایل در production
71:
chortke/core/RedisSessionHandler.php:165-168, 199-203, 366-386
72:
پس از خطای Redis، process به فایل fallback می‌کند. در deployment چندنودی، state نشست بین نودها جدا می‌شود و revocation یا تغییرات ممکن است ناسازگار شوند.

73:
اثر: کاهش قابلیت اطمینان کنترل نشست در outage یا partition Redis؛ بررسی دوره‌ای DB در AuthMiddleware اثر را کم می‌کند اما حذف نمی‌کند.

74:
اصلاح: در production Redis را fail-closed و اجباری کنید، یا fallback اشتراکی و قرارداد recovery مشخص داشته باشید.

75:
76: 77:
موارد بررسی‌شده بدون finding قطعی
78:
79:
80:
کاندیداهای SQL در UnifiedTaskService، TicketService، StateMachineService و AccountDeletionService با bind parameter، int bounds یا allowlist کنترل می‌شوند.
81:
redirect مرکزی origin و CRLF را اعتبارسنجی می‌کند؛ path traversal در FileController با regex/realpath مهار شده است.
82:
endpointهای health/metrics جزئیات را پشت IP/token header gate در controller قرار داده‌اند؛ IP نیز در حالت پیش‌فرض از REMOTE_ADDR گرفته می‌شود.
83:
shell executionهای بررسی‌شده از escaping و کنترل exit code استفاده می‌کنند؛ eval/PHP include قابل‌کنترل از request اثبات نشد.
84:
کاندیداهای view/innerHTML یا escape شده‌اند یا به مقادیر allowlist/تولیدشدهٔ داخلی محدودند؛ XSS قطعی در flow بررسی‌شده ثبت نشد.
85:
86:
87: 88:
Coverage و محدودیت
89:
90:
91:
اسکن ایستا روی پروژهٔ /mnt/workspace/63efe6f4-a441-453d-b6b4-631b547eab65/chortke انجام شد؛ vendor/، tests/، storage/، public/assets/، public/uploads/ و generatedها از ارزیابی اصلی کنار گذاشته شدند.
92:
تعداد فایل‌های غیرhidden در scope اصلی: ۱۶۱۸؛ پس از حذف test-likeهای tools/: ۱۵۸۱. فایل .env جداگانه در اسکن secrets بررسی شد.
93:
اسکن‌های استفاده‌شده: SQL interpolation/parameter binding، XSS/HTML sinks، path/file access، redirect، shell/eval، secrets، debug routes، health/metrics، env و empty catches.
94:
PHP در محیط موجود نیست؛ PHP lint، PHPUnit، PHPStan و runtime exploit verification اجرا نشدند. نتیجه static-only است.
95:
فایل‌های قبلی مرتبط: outputs/chortke-static-audit.html و outputs/chortke-code-review.html.
96:
97:
گزارش بازبینی مالی ایستا
40:
بازبینی فقط خواندنی انجام شد؛ هیچ فایل پروژه‌ای تغییر نکرد.

41: 42:
43:
۳۰فایل کامل خوانده‌شده
44:
۱۲٬۳۴۶خط بررسی‌شده
45:
۳یافتهٔ قطعی
46:
۰تغییر کد
47:
48: 49:
یافته‌ها
50: 51:
52:
P1 · ریسک گیرکردن وجه و ناسازگاری دفترکل
53:
Reconciliation قبل از settlement وضعیت withdrawal را terminal می‌کند
54:
55: ReconciliationService در مسیر موفقیت، ردیف تراکنش را در خطوط 56: 477-483 به completed و در مسیر شکست در خطوط 57: 541-546 به failed تغییر می‌دهد، سپس 58: completeWithdrawal/cancelWithdrawal را صدا می‌زند 59: (514-522 و 561-568). 60:

61:
62: این دو primitive در WalletMutationService:796-799 و 63: 847-849 وضعیت terminal را «قبلاً انجام‌شده» تلقی می‌کنند و 64: true برمی‌گردانند؛ بنابراین deductLocked، 65: unlockBalance و ledger legهای خطوط 808-825 و 66: 859-875 اصلاً اجرا نمی‌شوند. نتیجه: برداشت موفق می‌تواند وجه را 67: در locked نگه دارد و برداشت ناموفق می‌تواند hold را برای همیشه قفل کند. 68:

69:
اصلاح: برای نوع withdrawal، update عمومی وضعیت را قبل از settlement انجام ندهید؛ بگذارید خودِ primitive با وضعیت pending/processing عملیات و CAS را انجام دهد. هر نتیجهٔ ناموفق نیز باید exception بدهد تا تراکنش reconciliation rollback شود.

70:
ارجاع: app/Services/ReconciliationService.php:467-522,539-568، app/Services/Wallet/WalletMutationService.php:789-875.

71:
72: 73:
74:
P1 · ریسک باقی‌ماندن locked funds در partial escrow
75:
ورودی partial پذیرفته می‌شود اما primitive بازپرداخت آن را رد می‌کند
76:
77: FinancialEscrowService::refundEscrowToBuyer وضعیت‌های 78: pending,in_escrow,partial,disputed را مجاز اعلام می‌کند 79: (FinancialEscrowService.php:155-163) و سپس از 80: EscrowService::refundFunds استفاده می‌کند. 81:

82:
83: اما EscrowService::findRefundable و update حالت refund فقط 84: in_escrow,pending,disputed را می‌پذیرند 85: (Models/Escrow.php:190-221 و 86: Services/EscrowService.php:623-640). علاوه بر آن، بعد از 87: partialRelease مبلغ escrow باقی‌مانده می‌شود، درحالی‌که مبلغ 88: تراکنش hold اصلی کامل است؛ جست‌وجوی دقیق مبلغ در 89: FinancialEscrowService.php:1145-1203 نیز hold را پیدا نمی‌کند. 90:

91:
اثر: refund، account deletion یا jobهای بازپرداخت روی escrow جزئی fail می‌شوند و بخشی از locked balance باقی می‌ماند.

92:
اصلاح: قرارداد partial را یکپارچه کنید: state/queryهای refund باید partial را قبول کنند و hold را با escrow_id از metadata پیدا کنند، نه با مبلغ فعلیِ باقی‌مانده؛ سپس فقط remaining amount را release و همان hold اصلی را finalize کنید.

93:
ارجاع: app/Domain/Financial/Services/FinancialEscrowService.php:150-171,1140-1203، app/Services/EscrowService.php:607-640، app/Models/Escrow.php:190-221.

94:
95: 96:
97:
P2 · ناسازگاری transactional outbox و خطای کاذب پس از commit
98:
دو رویداد مالی بعد از commit ثبت می‌شوند
99:
100: WithdrawalAdminService تراکنش approve را در خط 101: 163 commit می‌کند و تازه در 175-187 outbox را 102: می‌نویسد. همین الگو برای manual deposit هم وجود دارد: تراکنش در 103: PaymentDepositService.php:90-151 تمام می‌شود و 104: PaymentService.php:134-159 بعد از آن رویداد را ثبت می‌کند. 105:

106:
107: خود OutboxService قرارداد «ثبت در همان transaction» را در 108: OutboxService.php:12-15 اعلام می‌کند. اگر insert outbox بعد از 109: commit شکست بخورد، payout/deposit انجام شده ولی event از دست می‌رود؛ در 110: approve برداشت، exception حتی می‌تواند پاسخ شکست به ادمین بدهد درحالی‌که 111: عملیات مالی قبلاً commit شده است. 112:

113:
اصلاح: outbox record را پیش از commit و داخل همان transaction root انجام دهید، یا event را از سرویس مالی به transaction owner برگردانید تا قبل از commit ثبت شود. مسیرهای صرفاً notification که عمداً non-blocking هستند در این finding نیستند.

114:
ارجاع: app/Services/Withdrawal/WithdrawalAdminService.php:103-204، app/Services/Payment/PaymentService.php:128-162، app/Services/Payment/PaymentDepositService.php:84-151، app/Services/OutboxService.php:12-15,38-87.

115:
116: 117:
Coverage
118: 119: 120: 121: 122: 123: 124: 125: 126: 127: 128: 129:
Scope	Files	Lines
app/Services/Payment/*.php	۱۲	۳٬۳۵۲
app/Services/Wallet/*.php	۳	۲٬۱۲۵
app/Services/Withdrawal/*.php	۳	۱٬۱۶۱
app/Services/CryptoDeposit/*.php	۲	۶۶۰
app/Domain/Financial/Services/*.php	۲	۱٬۷۱۷
سرویس‌های نام‌بردهٔ reconciliation/scheduled/saga/outbox/ad	۵	۲٬۶۰۷
app/Services/Shared/Idempotency*.php و DisputeService.php	۳	۷۲۴
130: 131:
موارد Skip‌شده
132:
133:
134:
Gateway create قبل از ثبت payment_logs: این مرحله فقط authority می‌سازد و از کد فعلی نمی‌توان charged شدن وجه را نتیجه گرفت.
135:
Frozen wallet در cancel/complete: این مسیرها عمداً برای settlement موجودی locked طراحی شده‌اند؛ نشانهٔ قطعیِ دورزدن سیاست freeze دیده نشد.
136:
Transfer idempotency: قفل دو کیف پول، کلید کلاینت و UNIQUE backstop در کد وجود دارد؛ finding قطعی باقی نماند.
137:
فیلتر expiry فقط روی pending: قرارداد فعلی و تست موجود sweep را برای holdهای pending تعریف می‌کنند؛ بدون مشخصات محصول، حذف آن از گزارش قطعی‌تر است.
138:
مشکلات در فایل‌های خارج از scope، از جمله implementationهای جزئی DisputeCommandService، به‌عنوان finding اصلی گزارش نشده‌اند.
139:
140:
141: 142:
روش: static read کامل؛ تست اجرایی یا تغییر کد انجام نشد.
143:
144: 145: (End of file - total 145 lines)
گزارش ممیزی اعلان‌ها
بررسی زنجیرهٔ outbox، queue، in-app، FCM، SMS و email

17:
بدون تغییر در کد منبع
ممیزی ایستا
18:
19:
20:
۷
یافتهٔ قابل اقدام
21:
۵
مورد با اثر مستقیم روی delivery
22:
۰
فایل منبع تغییرکرده
23:
24: 25:
26:
یافته‌ها
27:
28:
HIGH شکست bulk FCM موفقیت اعلام می‌شود
29:
app/Services/Notification/NotificationDispatcher.php:253-277 و app/Jobs/ProcessNotificationJob.php:59-70
30:
dispatchBulk() شکست‌های per-user را فقط در processed جمع می‌کند اما همیشه success=true برمی‌گرداند. خود job هم نتیجه را نادیده می‌گیرد؛ بنابراین حتی شکست همهٔ گیرنده‌ها باعث completion و حذف job از صف می‌شود و retry/DLQ اجرا نمی‌شود.

31:
راهکار: نتیجهٔ واقعی را به worker برسانید؛ شکست‌های transient را throw/retry کنید و برای partial delivery وضعیت per-recipient یا batch را durable ثبت کنید.

32:
33: 34:
35:
HIGH listener مرکزی خطا را می‌بلعد و outbox را منتشرشده می‌کند
36:
app/Listeners/NotificationRequestListener.php:156-181 و app/Services/OutboxPublisher.php:197-200
37:
listener مقدار بازگشتی send() را بررسی نمی‌کند و همهٔ exceptionها را catch کرده و rethrow نمی‌کند. publisher پس از بازگشت listener بلافاصله رکورد outbox را published می‌کند؛ در نتیجه خطای DB، payload نامعتبر یا failure ارسال بدون retry/DLQ گم می‌شود.

38:
راهکار: failure واقعی را propagate کنید؛ فقط opt-out/rate-limit را به‌عنوان drop عمدی طبقه‌بندی کنید و برای آن status/metric جدا ثبت کنید.

39:
40: 41:
42:
HIGH مسیر in-app idempotency پایدار ندارد
43:
app/Jobs/Notification/SendNotificationJob.php:59-80 و database/migrations/2026_06_10_0015_notif_coupons_risk.sql:5-25
44:
هر اجرای مجدد مستقیماً یک ردیف جدید در notifications می‌سازد. event id موجود در payload در این مسیر بررسی یا در ستون یکتا ذخیره نمی‌شود و schema نیز constraint برای event/recipient ندارد. crash بین side effect و mark-as-published یا replay outbox می‌تواند اعلان in-app تکراری بسازد.

45:
راهکار: event idempotency key را با recipient و channel ذخیره کنید و unique constraint/upsert اتمیک اضافه کنید؛ cache فقط accelerator باشد، نه منبع حقیقت.

46:
47: 48:
49:
HIGH retry یک blast ناقص، ادامهٔ blast را skip می‌کند
50:
app/Jobs/Notification/SendToSegmentNotificationJob.php:81-137، app/Jobs/Notification/SendToAllNotificationJob.php:76-121 و app/Controllers/Admin/NotificationController.php:156-164
51:
claim ده‌دقیقه‌ای قبل از شروع loop گرفته می‌شود، اما progress هر batch durable نیست. اگر batchهای ابتدایی موفق و batch بعدی fail شود، اجرای دوباره همان درخواست با claim موجود فوراً deduped برمی‌گردد و کاربران باقی‌مانده هرگز پردازش نمی‌شوند.

52:
راهکار: blast را به batchهای مستقل با cursor و idempotency key تقسیم کنید؛ progress را ذخیره کنید و retry را از آخرین batch ادامه دهید.

53:
54: 55:
56:
HIGH نتیجهٔ false در ثبت outbox در producerها بررسی نمی‌شود
57:
app/Services/OutboxService.php:55-87، app/Jobs/Notification/SendBulkNotificationJob.php:55-72، app/Services/EmailService.php:254-273
58:
OutboxService::record() قرارداد bool دارد، اما چند producer مقدار false را نادیده می‌گیرند و در همان حال موفقیت/شناسهٔ delivery را برمی‌گردانند. در این مسیرها نه fallback تضمین می‌شود و نه خطا به caller می‌رسد.

59:
راهکار: همهٔ callerها باید false را failure قطعی تلقی کنند؛ یا exception بدهند یا fallback اتمیک داشته باشند، و نباید delivery را پیش از ثبت outbox موفق اعلام کنند.

60:
61: 62:
63:
MEDIUM payload خام اعلان وارد log می‌شود
64:
app/Listeners/NotificationRequestListener.php:121-125 و app/Listeners/NotificationRequestListener.php:174-180
65:
در missing-user و listener failure، کل $event بدون masking در context لاگر ثبت می‌شود. این payload می‌تواند message، شناسهٔ کاربر، URL و دادهٔ حساس business باشد؛ برخلاف مسیر EventDispatcher که masking بازگشتی دارد.

66:
راهکار: فقط فیلدهای allowlist‌شده را log کنید و data را با همان masker مرکزی sanitize کنید؛ raw event را هرگز ثبت نکنید.

67:
68: 69:
70:
MEDIUM payload نامعتبر bulk in-app با موفقیت acknowledge می‌شود
71:
app/Jobs/PersistBulkInAppNotificationJob.php:25-56 و app/Services/QueueWorker.php:103-110
72:
برای user_ids خالی یا title/message خالی، job فقط return می‌کند. worker این خروج عادی را موفق تلقی کرده و job را حذف می‌کند؛ بنابراین payload خراب به DLQ یا alert نمی‌رسد.

73:
راهکار: validation failure را با exception نوع permanent به worker بدهید تا DLQ شود؛ skipهای عمدی را با نتیجهٔ صریح و metric جدا کنید.

74:
75:
76: 77:
78:
Coverage و شکاف تست
79:
80:
81:
تست happy-path و cache dedupe برای bulk dispatcher موجود است: tests/Integration/ContainerBacked/Services/NotificationDispatcherTest.php:137-188؛ failure per-recipient تست نشده.
82:
تست retry/DLQ برای listener عمومی وجود دارد: tests/Integration/Queue/OutboxQueueDlqRuntimeTest.php:102-138؛ اما NotificationRequestListener خطا را قبل از رسیدن به publisher می‌بلعد و این مسیر تست نشده.
83:
تست listener صراحتاً رفتار swallow را پذیرفته است: tests/Unit/Listeners/S18p6NotificationUnityTest.php:123-134.
84:
برای partial blast resume، durable in-app idempotency، false-return از outbox و malformed bulk payload تستی پیدا نشد.
85:
مورد email claim بررسی شد و false positive است: migration database/migrations/2026_09_16_0001_email_queue_sending_status.php:40-47 مقدار sending را به schema اضافه می‌کند.
86:
87:

گزارش ممیزی استاتیک امنیتی
19:
پروژه Chortke · تمرکز بر JavaScript اختصاصی، viewها و مسیرهای مرتبط

20:
21:
۴
یافتهٔ قابل‌گزارش
22:
۱
یافتهٔ امنیتی اصلی
23:
۳
functional / hardening
24:
۰
artifact یا تغییر کد
25:
26:
27: 28:
29:
متوسط رو به پایین F-01 · HTML injection ذخیره‌شده در پنل Feature Flags
30:
اثر: ادمینی که مجوز features.manage و مالکیت یک feature را دارد می‌تواند مقدار tag/environment را ذخیره کند. این مقدار در پنل ادمین با innerHTML بازنمایی می‌شود و می‌تواند markup دلخواه، تغییر ظاهر یا محتوای فریبندهٔ ادمین ایجاد کند.

31:
32:
sink تگ‌ها: public/assets/js/admin/featuresindex.js:60-67
33:
sink پیش‌نمایش محیط‌ها: featuresindex.js:214-224
34:
sink تاریخچه و diff: featuresindex.js:472-495 و 528-531
35:
ورودی و ذخیره‌سازی: FeatureFlagController.php:614-636 و FeatureFlagService.php:653-715
36:
37:
محدودیت: CSP فعلی در SecurityHeadersMiddleware.php:116-140 اسکریپت inline را مسدود می‌کند؛ بنابراین XSS اجرایی مستقیم از این مسیر با شواهد فعلی اثبات نشد. بااین‌حال style-src 'unsafe-inline' و HTML injection همچنان دفاع در عمق کافی نیست.

38:
اصلاح: برای داده‌ها از textContent و DOM API استفاده شود؛ مقادیر عددی cast شوند؛ برای action/history allowlist و برای همهٔ متن‌ها escape صریح اعمال شود. در صورت امکان unsafe-inline از CSP حذف شود.

39:
40: 41:
42:
پایین F-02 · توقف initialization با query string در Influencer Hub
43:
مقدار خام influencer_id در selector ترکیبی قرار گرفته است. یک لینک با quote یا bracket نامعتبر باعث SyntaxError در querySelector می‌شود و ادامهٔ IIFE اجرا نمی‌شود؛ در نتیجه handlerهای سفارش، کوپن، proof و dispute ثبت نمی‌شوند.

44:
public/assets/js/views/userinfluencerhub.js:137-140
45:
اصلاح: به‌جای ساخت selector از ورودی خام، عناصر را با data-id مقایسه کنید یا مقدار را با CSS.escape escape کنید؛ همچنین initialization را داخل try/catch محدود قرار دهید.

46:
47: 48:
49:
پایین / functional F-03 · استفادهٔ نادرست از document.currentScript در callbackهای async
50:
در callbackهای Promise یا event، document.currentScript دیگر script اجراشده نیست و معمولاً null است؛ بنابراین کد به fallbackهای absolute می‌افتد. در deployment زیرمسیر /chortke، درخواست‌ها به ریشهٔ اشتباه ارسال می‌شوند.

51:
52:
usersocialaccountsindex.js:16 → /social-accounts
53:
userlotteryindex.js:16,33,53 → /lottery/*
54:
userlevelindex.js:19 → /level/purchase
55:
56:
اصلاح: در ابتدای هر فایل یک‌بار const script = document.currentScript بگیرید و dataset همان reference را در callback‌ها مصرف کنید.

57:
58: 59:
60:
Hardening F-04 · بازنویسی کلید idempotency پرداخت با Math.random
61:
صفحهٔ wallet ابتدا کلید امن با random_bytes(16) و crypto.getRandomValues می‌سازد، اما asset خارجی آن را با Math.random() بازنویسی می‌کند.

62:
views/user/wallet/index.php:581-594, 657-660
public/assets/js/views/userwalletdepositselect.js:4-11
63:
اثر: کاهش entropy و regression در تضمین idempotency؛ شواهدی از دوبرابرشدن مستقیم وجه پیدا نشد و unique/validation سمت سرور همچنان دفاع اصلی است.

64:
اصلاح: تعریف inline امن را حذف یا asset را طوری اصلاح کنید که از همان crypto-based generator استفاده کند و یک تابع فقط یک‌بار مالک این رفتار باشد.

65:
66: 67:
68:
موارد بررسی‌شده و ردشده
69:
70:
URL preview در settingsindex.js:65: URL توسط UploadService ساخته می‌شود؛ false positive.
71:
sink قیمت/نام در userlevelindex.js:11: مقدار قیمت عددی/سروری و title در مسیر امن Swal؛ false positive برای XSS.
72:
open redirect در TwoFactorController.php:115: مقصد redirect داخلی است.
73:
uploadهای بررسی‌شده: اعتبارسنجی backend و در موارد لازم MIME/magic-byte وجود دارد.
74:
75:
76: 77:
78:
اعتبارسنجی
79:
اجرای lint و PHPUnit ممکن نشد: محیط فعلی binary مربوط به PHP را ندارد.

80:
نتیجهٔ بالا static-only است. مسیرهای ادمین با Auth، Admin، CSRF، RateLimit و AdminPermissionGuard محافظت می‌شوند؛ تست runtime با session معتبر admin انجام نشده است.

81:
82:
دامنهٔ بررسی: JavaScript اختصاصی، viewهای PHP و مسیرهای مرتبط؛ vendor/minified و یافته‌های تکراری از گزارش حذف شدند.
83:
84: 85: (End of file - total 85 lines)

ممیزی ایستای کنترلرهای Admin
16:
پروژه Chortke · پوشش کامل کنترلرهای app/Controllers/Admin، routeها، middleware و نگاشت RBAC

17:
18:
۵۳
فایل خوانده‌شده
19:
۱۵٬۱۸۳
خط طبق wc -l
20:
۳
یافتهٔ قطعی در این مرور
21:
۰
فایل منبع تغییرکرده
22:
23:
24: 25:
26:
HIGH · RBAC support مجوز تغییر وضعیت اینفلوئنسر دارد
27:
config/admin_permissions.php:235-240 کل InfluencerController را با پیش‌فرض influencer.manage محافظت می‌کند و فقط orders را مشاهده‌ای کرده است. همان مجوز در database/migrations/2026_08_08_0001_rbac_full_permissions.sql:85-107 به نقش support داده شده است.

28:
در نتیجه عملیات moderation در app/Controllers/Admin/InfluencerController.php:95-152,241-278 برای support قابل فراخوانی است: تأیید، رد، تعلیق پروفایل و تأیید/رد verification.

29:
اصلاح: مجوز مستقل مشاهده و moderation بسازید؛ فقط عملیات موردنیاز support را به مجوز moderation نگاشت کنید و همان محدودیت را در سرویس verification نیز enforce کنید.

30:
31: 32:
33:
MEDIUM · HTML injection دادهٔ Feature Flag بدون escape در DOM قرار می‌گیرد
34:
ورودی‌های environments و tags در FeatureFlagController.php:614-636 و FeatureFlagService.php:653-715 ذخیره می‌شوند؛ سپس در public/assets/js/admin/featuresindex.js:60-67,214-224,472-495,528-531 با innerHTML رندر می‌شوند.

35:
CSP فعلی اجرای مستقیم script inline را اثبات نمی‌کند، اما markup دلخواه و محتوای فریبنده برای کاربران پنل ممکن است.

36:
اصلاح: برای متن از textContent و DOM API استفاده کنید، و در history/diff نیز escape صریح و allowlist داشته باشید.

37:
38: 39:
40:
MEDIUM · functional/security hardening ساخت کاربر از پنل سیاست رمز و role/status را دور می‌زند
41:
app/Controllers/Admin/UserController.php:101-106,154-161 رمز را فقط با min:8 می‌پذیرد و role، status و email_verified_at را به UserService::register() می‌فرستد.

42:
اما app/Services/User/UserService.php:62-83,124-128 این سرویس را برای ثبت‌نام عمومی طراحی کرده: فیلدهای حساس را حذف می‌کند، role/status را روی user/active می‌گذارد و PasswordPolicy رسمی را در این مسیر اعمال نمی‌کند. نتیجهٔ موفقیت به ادمین اعلام می‌شود ولی نقش و وضعیت درخواستی اعمال نمی‌شود.

43:
اصلاح: مسیر جداگانهٔ registerAdminUser با validator و writer صریح بسازید؛ PasswordPolicy را همان‌جا اعمال و role/status را فقط بعد از hierarchy check بنویسید.

44:
45: 46:
47:
بررسی شد کنترل‌های مهمی که finding جدید نشدند
48:
49:
تمام routeهای پنل از routes/admin.php:62-66 از Auth، Admin، CSRF، RateLimit و AdminPermissionGuard استفاده می‌کنند؛ مسیرهای سیستمی نیز در routes/system.php:19-29 هم‌تراز شده‌اند.
50:
AdminPermissionGuard در app/Middleware/AdminPermissionGuard.php:55-78 برای مسیرهای بدون نگاشت deny-by-default است.
51:
RoleController.php:119-172,281-284 اعطای مجوزهای سطح super_admin را برای غیرسوپر رد می‌کند.
52:
UserService.php:440-575 whitelist، hierarchy و writer جداگانه برای role/status دارد؛ escalation از مسیر update در این مرور اثبات نشد.
53:
چهار کنترلر سیستمی عمداً خارج از map هستند و برای غیرسوپر super_admin-only می‌مانند: Cron، Cache، API Token و Email Queue.
54:
55:
56: 57:
58:
یافته‌های مرتبط قبلی
59:
60:
زنجیرهٔ Notification هنوز ۷ finding delivery/retry/idempotency دارد؛ جزئیات در outputs/notification-audit-report.html.
61:
ممیزی عمومی JavaScript/view چهار finding دیگر دارد؛ جزئیات در outputs/security-audit-report.html.
62:
گزارش RBAC قبلی همین finding مربوط به support/influencer را ثبت کرده؛ این مرور آن را با نسخهٔ فعلی route/config/migration دوباره تأیید کرد: outputs/chortke-rbac-audit.html.
63:
64:
65: 66:
67:
Coverage کامل ۵۳ کنترلر
68:
تمام فایل‌های زیر خوانده شدند. عدد خط، خروجی wc -l است؛ برخی فایل‌ها newline انتهایی ندارند، بنابراین برای ارجاع finding از شمارهٔ خط مستقیم استفاده شود.

69:
70: 71: 72: 73: 74: 75: 76: 77: 78: 79: 80: 81: 82: 83: 84: 85: 86: 87: 88: 89: 90: 91: 92: 93: 94: 95: 96: 97: 98: 99: 100: 101: 102: 103: 104: 105: 106: 107: 108: 109: 110: 111: 112: 113: 114: 115: 116: 117: 118: 119: 120: 121: 122: 123: 124: 125: 126: 127:
فایل	خط	دامنهٔ بررسی
AccountDeletionManagementController.php	404	صف حذف حساب، force-delete، لغو، تاریخچه و آمار
AdTaskController.php	157	فهرست/جزئیات تسک تبلیغاتی، approve و آمار
AdminAdsController.php	258	داشبورد تبلیغات، جزئیات، bulk/action و stats
AdminAnalyticsController.php	296	داشبورد analytics، گزارش‌ها، chart و export
AdminExportController.php	131	خروجی کاربران، تراکنش‌ها، برداشت‌ها و audit
ApiTokenAdminController.php	130	فهرست، revoke، renew و revoke-expired توکن‌ها
AuditTrailController.php	270	مشاهده، تاریخچهٔ کاربر، stats و export حسابرسی
AuthController.php	421	ورود ادمین، 2FA و logout
BackupManagementController.php	339	ساخت/بازیابی/تأیید backup، offsite و cleanup
BankCardController.php	192	بازبینی، تأیید و رد کارت بانکی
BannerController.php	328	CRUD، approval، toggle، placement و stats بنر
BaseAdminController.php	181	base مشترک کنترلرهای Admin و helperهای authorization/response
BugReportController.php	284	وضعیت، اولویت، comment، suspicious و حذف bug report
CacheAdminController.php	76	clear/forget cache و reset circuit breaker
ContentController.php	550	moderation محتوا، revenue، publish، suspend و export
CouponController.php	326	CRUD کوپن، فعال‌سازی، redemptions و statistics
CronController.php	208	نمایش و اجرای scheduler
CryptoDepositController.php	244	بازبینی، تأیید و رد واریز رمزارزی
DashboardController.php	164	redirect، داشبورد، فعالیت اخیر و system status
DatabaseHealthController.php	98	نمایش سلامت پایگاه داده
EmailQueueController.php	79	پردازش و retry صف ایمیل
ExecutorTaskController.php	352	force decision، گزارش و dispute تسک‌ها
FeatureFlagController.php	841	CRUD/toggle/advanced، metrics و history فیچرها
FraudController.php	323	risk report، score، flags و suspend/unsuspend
FraudDashboardController.php	43	داشبورد fraud
FraudManagementController.php	252	blacklist IP/device و reset fraud score
FraudReportsAdminController.php	241	بررسی و dismiss گزارش‌های تخلف
InfluencerController.php	291	moderation پروفایل/verification و dispute اینفلوئنسر
InvestmentController.php	412	trades، سود، برداشت‌ها، suspend و solvency
KYCController.php	268	بازبینی KYC، verify/reject و حذف تصویر
KpiController.php	201	KPI، chart و exportهای مالی/کاربری
LevelController.php	426	CRUD level، history و تغییر level کاربر
LogController.php	539	لاگ‌ها، خطاها، channel، rule و mailbox
LotteryController.php	177	قرعه‌کشی، اعداد، finalize، winner و cancel
ManualDepositController.php	273	بازبینی، تأیید و رد واریز دستی
MessageModerationController.php	275	گزارش پیام، approve/dismiss، blocked users و stats
NotificationController.php	365	ارسال، template، آمار، fetch و read state
OnlinePaymentController.php	84	فهرست و تأیید پرداخت درگاه
PredictionController.php	292	CRUD prediction، settle، refund و close betting
ReferralController.php	304	تنظیمات، جزئیات، cancel و batch pay referral
RiskPolicyController.php	104	نمایش و به‌روزرسانی risk policy
RoleController.php	404	CRUD نقش، sync permissions و toggle
ScoreManagementController.php	194	مشاهده، adjust، revoke و history امتیاز
SentryAdminController.php	454	issues، failed jobs، outbox/DLQ، audit و export
SeoAdController.php	81	approve/reject/pause تبلیغ SEO
SocialAccountController.php	100	فهرست، جزئیات، verify و reject حساب اجتماعی
SocialTaskController.php	471	تسک، execution، rating و trust moderation
SystemSettingController.php	387	تنظیمات، captcha و upload/remove تصویر
TicketController.php	397	تیکت، reply، status، assign و attachment download
TransactionController.php	225	فهرست، جزئیات و reverse تراکنش
UserController.php	598	کاربران، status، email verification و role update
VitrineController.php	326	approval، dispute، release/refund و settings ویترین
WithdrawalController.php	347	بازبینی، process و reject برداشت
128:
129:
130: 131:
132:
محدودیت بررسی
133:
این کار static-only است. تست HTTP، session معتبر، دیتابیس و PHPUnit اجرا نشد؛ طبق محیط موجود PHP CLI در دسترس نیست. هیچ فایل منبعی تغییر نکرده است.

134:
135:
گزارش تکمیلی بر پایهٔ بررسی routeها، middlewareها، config RBAC، کنترلرها، validatorها و سرویس‌های درگیر تهیه شد.
136:
137: 138: (End of file - total 138 lines)

14:
15:
ممیزی صف و Cron
16:
بررسی static کد اختصاصی Chortke با تمرکز بر reservation، retry، DLQ، Outbox، scheduler و deployment.

17:
وضعیت: پیش از production نیازمند اصلاح · هیچ فایل سورس پروژه تغییر نکرده است.

18:
19: 20:
21:
۲
مانع Critical/High عملیاتی
22:
۴
ریسک High صف و داده
23:
۳
ریسک Medium/Low
24:
۰
تست قابل اجرا در این محیط
25:
26: 27:
28:
یافته‌های اولویت‌دار
29: 30:
31:
CRITICAL Docker قبل از اجرای migration و worker متوقف می‌شود
32:
deploy/docker/docker-entrypoint.sh:30-41 · cli.php:18-33 · deploy/docker/Dockerfile:39-46 · docker-compose.yml:41-142
33:
اثر: image کاربر پیش‌فرض را به root تغییر نمی‌دهد؛ entrypoint با root اجرا می‌شود و php cli.php migration --force بدون --allow-root با exit code 1 متوقف می‌شود. در نتیجه app و workerهای دارای همین entrypoint بالا نمی‌آیند.

34:
راهکار: entrypoint و commandها را با کاربر non-root اجرا کنید و مالکیت storage را همان‌جا تست کنید، یا guard و deployment را عمداً و مستند هماهنگ کنید. یک smoke test برای boot/migration لازم است.

35:
36: 37:
38:
HIGH production Redis queue با نام متغیر اشتباه پیکربندی شده است
39:
config/queue.php:4 · core/Queue.php:31-50 · deploy/docker/docker-compose.yml:21,57 · deploy/supervisor/chortke-queue.conf:16 · deploy/systemd/chortke-queue.service:15
40:
اثر: برنامه فقط QUEUE_DRIVER را می‌خواند و مقدار پیش‌فرض آن database است، اما همهٔ deploymentهای production مقدار QUEUE_CONNECTION=redis می‌فرستند. بنابراین مسیر اجرا با backend مورد انتظار هم‌خوان نیست و تست Redis موجود نیز با driver صریح، این خطای deployment را نمی‌بیند.

41:
راهکار: یک نام canonical انتخاب کنید و config، env نمونه، compose، Supervisor، systemd و تست boot را با همان نام هماهنگ کنید.

42:
43: 44:
45:
HIGH reservation صف مالک یا fencing token ندارد
46:
core/Queue.php:426-492,531-565,591-676,837-953 · app/Services/QueueWorker.php:98-113,187-200
47:
اثر: job فقط با id و زمان reservation شناخته می‌شود. اگر worker A از visibility timeout عبور کند، worker B همان job را بگیرد، سپس A با همان id heartbeat، release، fail یا delete کند؛ عملیات A می‌تواند reservation یا نتیجهٔ B را تغییر دهد یا job را حذف کند. این مسیر duplicate side effect و از دست‌رفتن اجرای معتبر ایجاد می‌کند.

48:
راهکار: در هر pop یک reservation token/fence بسازید و آن را در DB/metadata Redis ذخیره کنید. keepAlive، ack/delete، release و fail باید فقط با id+token معتبر و به‌صورت شرطی/اتمی موفق شوند.

49:
50: 51:
52:
HIGH Outbox در crash window می‌تواند پیام یا job را دوبار منتشر کند
53:
app/Services/OutboxPublisher.php:101-136,194-215,232-260,369-375,428-437 · app/Commands/OutboxPublishCommand.php:89-105
54:
اثر: ابتدا event به queue/listener خارجی منتشر می‌شود و بعد status به published می‌رود. crash بین این دو مرحله باعث recovery و انتشار دوباره می‌شود. علاوه بر آن، خطای اخذ قفل در publisher catch می‌شود و publish بدون قفل ادامه می‌یابد؛ recovery هفت‌دقیقه‌ای نیز owner token برای تشخیص publisher قدیمی ندارد.

55:
راهکار: lock failure را در production fail-closed کنید، status transitionها را با owner token شرطی کنید، و outbox id/event id را تا consumer یا provider حمل کنید تا side effectها idempotent شوند. تست crash بعد از publish و قبل از mark لازم است.

56:
57: 58:
59:
HIGH انقضای SocialTask می‌تواند slot را چندبار برگرداند
60:
app/Console/Kernel.php:907-928
61:
اثر: پس از expire کردن ردیف‌های جدید، query دوم همهٔ executionهای expired با updated_at در یک ساعت اخیر را می‌شمارد؛ ردیف‌های expire شده در اجرای قبلی نیز دوباره داخل count می‌آیند و remaining_slots بیش از مقدار واقعی افزایش می‌یابد.

62:
راهکار: id/ad_id ردیف‌هایی را که همان UPDATE تغییر داده است در همان مرز اتمیک claim کنید، یا برای بازگرداندن slot ستون/رویداد مستقل با unique constraint داشته باشید. تست اجرای دو دورهٔ متوالی لازم است.

63:
64:
65: 66:
67:
ریسک‌های تکمیلی
68:
69:
MEDIUM Redis push اتمیک نیست و metadata فقط ۷ روز عمر دارد
core/Queue.php:227-276 · core/Queue.php:489-492
setEx metadata و zAdd pending دو عملیات جدا هستند؛ crash بین آن‌ها job orphan می‌سازد. delay بیشتر از ۷ روز نیز قبل از رسیدن job metadata را منقضی می‌کند.

70:
MEDIUM قفل outer cron فقط ۶۰ ثانیه است
cron.php:42-55,99-115 · core/Scheduler.php:291-306
قفل Redis heartbeat ندارد و marker dispatch با get سپس put ساخته می‌شود. اجرای طولانی یا هم‌زمانی خارج از مسیر معمول می‌تواند dispatch تکراری بسازد.

71:
MEDIUM systemd جایگزین کامل Supervisor نیست
deploy/systemd/README.md:5-10 · deploy/systemd/chortke-queue.service:10-15 · deploy/systemd/chortke-outbox.service:10-14
راهنمای systemd فقط queue و outbox را فعال می‌کند؛ unit یا راهنمایی برای cron.php و DLQ وجود ندارد. در این mode، scheduleها و replayهای DLQ می‌توانند بی‌مصرف بمانند.

72:
LOW allowlist چند job با قرارداد worker سازگار نیست
config/queue.php:40,49 · app/Services/QueueWorker.php:163-196 · PayRewardJob.php:48 · ProcessPaymentCallbackJob.php:35
worker همیشه handle($data) صدا می‌زند، اما این دو job امضای چندآرگومانی/typed دارند. enqueue تصادفی آن‌ها TypeError و DLQ noise می‌سازد؛ فعلاً کد آن‌ها را dead/غیرقابل‌صف مستند کرده، اما allowlist همچنان قرارداد را نقض می‌کند.

73:
74:
75: 76:
77:
مواردی که false positive بودند
QueueWorker برای jobهای طولانی heartbeat دارد.
ReleaseVitrineFundsJob از FinancialEscrowService و transaction ریشه استفاده می‌کند.
ReconcilePaymentsJob برای retry_count از compare-and-swap استفاده می‌کند.
DLQ retry و pop از FOR UPDATE SKIP LOCKED استفاده می‌کنند.
78:
اعتبارسنجی
هیچ فایل سورس تغییر نکرده است.
تمام یافته‌ها با line reference فعلی ثبت شدند.
PHP در محیط موجود نصب نیست؛ lint و PHPUnit اجرا نشدند و با php: command not found متوقف شدند.
پیش از release، boot Docker، driver صف، crash window و دو اجرای متوالی expiry در staging تست شوند.
79:
80: 81:
مسیر پروژه: /mnt/workspace/63efe6f4-a441-453d-b6b4-631b547eab65/chortke

82:
83: 84: (End of file - total 84 lines)
گزارش ممیزی مالی
32:
ممیزی static جریان‌های wallet، payment، escrow و vitrine. هیچ فایل production تغییر نکرده است.

33: 34:
35:
36:
P1Expiry escrow بدون تغییر status آگهی، double-refund می‌سازد
37:
app/Jobs/Vitrine/ProcessExpiredVitrineEscrowsJob.php:55-84
app/Jobs/Vitrine/AdminRefundVitrineListingJob.php:93-127

38:
Job انقضای vitrine در `in_escrow` وجه را با refundEscrowToBuyer() برمی‌گرداند، اما listing را از `in_escrow` به وضعیت terminal منتقل نمی‌کند. بعداً refund ادمین escrowِ `refunded` را غیرقابل‌استرداد می‌بیند و به شاخهٔ direct deposit می‌رود.

39:
اثر: خریدار ابتدا از hold refund می‌گیرد و با refund ادمین دوباره مبلغ listing را دریافت می‌کند؛ listing نیز مدتی با escrow refunded اما status in_escrow باقی می‌ماند.

40:
اصلاح: refund expiry و تغییر status listing را در یک transaction ریشه، با قفل listing/escrow و CAS انجام دهید. در refund ادمین، اگر هر escrow برای listing وجود دارد ولی terminal است، fail-closed کنید؛ direct deposit فقط وقتی مجاز باشد که هیچ escrowای وجود نداشته باشد.

41:
42: 43:
44:
P1پاسخ شکست به‌عنوان idempotency موفق ذخیره می‌شود
45:
app/Domain/Financial/Services/FinancialEscrowService.php:89-113, 153-171, 490-541, 669-704
app/Services/Payment/PaymentCommandService.php:124-248, 354-416
core/IdempotencyKey.php:883-899

46:
callbackهای escrow و payment exception را می‌بلعند و آرایهٔ شکست برمی‌گردانند. IdempotencyKey::wrapInstance() هر خروجی callback را در complete() ذخیره می‌کند، بدون اینکه success:false را failure تشخیص دهد.

47:
اثر: یک خطای transient که transaction را rollback کرده، با همان key برای مدت نگهداری idempotency به‌عنوان پاسخ نهایی cache می‌شود. retry مشروع همان پاسخ شکست را می‌گیرد؛ در escrow وجه می‌تواند locked بماند و callback معتبر پرداخت ممکن است pending بماند.

48:
اصلاح: exceptionهای قابل retry را تا wrapper مرکزی propagate کنید تا failed_retryable ثبت شود. برای business failure نیز مسیر مشخص fail() داشته باشید و هرگز نتیجهٔ شکست را در completed ذخیره نکنید. تست failure-then-retry اضافه شود.

49:
50: 51:
52:
P1claim refund ادمین با حرکت پول اتمیک نیست
53:
app/Jobs/Vitrine/AdminRefundVitrineListingJob.php:68-91, 92-170

54:
status listing ابتدا در یک UPDATE جداگانه به cancelled تغییر می‌کند و سپس refund انجام می‌شود. catch معمولی status را برمی‌گرداند، اما crash یا kill شدن worker بین این دو مرحله catch را اجرا نمی‌کند.

55:
اثر: listing cancelled می‌ماند ولی escrow هنوز in_escrow است؛ retry endpoint به‌دلیل status terminal رد می‌شود و وجه می‌تواند برای همیشه locked بماند.

56:
اصلاح: claim و settlement را در یک transaction ریشه انجام دهید، یا یک وضعیت durable مانند refund_processing با recovery job داشته باشید. recovery باید بر اساس وضعیت escrow تصمیم بگیرد، نه فقط status listing.

57:
58: 59:
60:
P2approve و reject ادمین last-writer-wins هستند
61:
app/Jobs/Vitrine/AdminApproveVitrineListingJob.php:45-50
app/Jobs/Vitrine/AdminRejectVitrineListingJob.php:45-50
app/Models/VitrineListing.php:296-324

62:
هر دو job ابتدا status را جداگانه می‌خوانند، سپس updateStatus() را بدون expectedStatus فراخوانی می‌کنند. دو درخواست هم‌زمان می‌توانند هر دو pre-check را قبول کنند و هر دو event موفق منتشر کنند.

63:
اثر: status نهایی می‌تواند با تصمیم ثبت‌شده در event/audit متفاوت باشد؛ مثلاً listing ردشده دوباره active شود.

64:
اصلاح: از updateStatus(..., expectedStatus: pending) استفاده کنید و شکست CAS را به پاسخ conflict تبدیل کنید. برای هر دو مسیر تست هم‌زمانی و بررسی تعداد event اضافه شود.

65:
66:
67: 68:
موارد تأییدشده به‌عنوان false positive: قفل listing در خرید vitrine، CAS در release و dispute resolution، transaction ریشهٔ ReleaseVitrineFundsJob، و status transition قفل‌شدهٔ crypto deposit. تست‌های موجود مسیر موفق و replay را پوشش می‌دهند، اما PHP در محیط فعلی نصب نیست؛ اجرای PHPUnit با خطای /usr/bin/env: php: No such file or directory متوقف شد.
69:
مسیر پروژه: /mnt/workspace/63efe6f4-a441-453d-b6b4-631b547eab65/chortke
70:
71: 72: (End of file - total 72 lines)

30:
HIGH / P1 پذیرش درخواست Vitrine معامله را قبل از پرداخت قفل می‌کند
31:
app/Jobs/Vitrine/AcceptVitrineRequestJob.php:16-19,85-95 · app/Services/VitrineService.php:413-455 · routes/missing.php:120-125
32:
اثر: پذیرش فروشنده وضعیت listing را به in_escrow می‌برد، اما هیچ وجهی در آن job قفل نمی‌شود. سپس endpoint خرید به lockEscrow() می‌رسد و فقط listing با وضعیت active را قبول می‌کند؛ بنابراین مسیر «ارسال درخواست → پذیرش → پرداخت» می‌تواند در وضعیت بدون escrow متوقف شود. تأیید تحویل نیز به همین وضعیت و escrow واقعی نیاز دارد.

33:
راهکار: یک وضعیت صریحِ «پذیرفته‌شده/منتظر پرداخت» تعریف کنید، یا hold را اتمیک در پذیرش انجام دهید. تا پیش از ایجاد escrow واقعی، status را in_escrow نکنید. تست end-to-end پذیرش درخواست، پرداخت، تأیید و refund اضافه شود.

34:
35: 36:
37:
HIGH / P1 امتیاز SocialTask با سیگنال‌های ساختگی کاربر به auto-approve می‌رسد
38:
app/Controllers/User/SocialTaskController.php:125-130 · app/Jobs/SocialTask/SubmitSocialTaskExecutionJob.php:59-67,132-143,206-227 · app/Services/SocialTask/SocialTaskScoringService.php:23-69
39:
اثر: endpoint submit payload را مستقیم به job می‌دهد و behavior_signals کلاینت روی دادهٔ ذخیره‌شده merge می‌شود. با حدود ۴۵ ثانیه انتظار واقعی و سیگنال ساختگیِ tap+scroll، variance و delay «انسانی»، امتیاز رفتاری می‌تواند ۹۵ و امتیاز نهایی حدود ۸۸ شود؛ آستانهٔ auto-approve برابر ۷۰ است و در ادامه escrow آزاد و reward پرداخت می‌شود. خود کد نیز این مسیر را proofless اعلام کرده است.

40:
راهکار: پرداخت خودکار را به telemetry خوداظهاری وابسته نکنید. برای auto-approve از proof قابل‌اعتبارسنجی، callback/API تأییدشدهٔ پلتفرم یا attestation/signed event استفاده کنید؛ در غیر این صورت نتیجه را manual review نگه دارید. مقادیر client را فقط برای signal کمکی و با سقف مصرف کنید، نه به‌عنوان منبع حقیقت.

41:
42:
43: 44:
45:
یافته‌های Medium
46:
47:
MEDIUM / P2 نتیجهٔ دوربین پس از read اولیه، بدون شرط pending یا expiry نوشته می‌شود
48:
app/Services/SocialTask/CameraVerificationService.php:105-136 · app/Models/SocialTaskExecutionModel.php:296-333
49:
اثر: ابتدا درخواست pending و دارای expiry خوانده می‌شود، اما update بعدی فقط با id انجام می‌شود. دو درخواست هم‌زمان یا درخواست تأخیری می‌تواند رکورد را بعد از انقضا/تکمیل قبلی به completed تبدیل کند و نتیجهٔ دوربین را تغییر دهد.

50:
راهکار: update را با WHERE id = ? AND status = 'pending' AND expires_at > NOW() انجام دهید و row count را الزاماً بررسی کنید. در صورت شکست، نتیجه را موفق گزارش نکنید.

51:
52: 53:
54:
MEDIUM / P2 ثبت سیگنال رفتاری read/modify/write غیراتمی است
55:
app/Services/SocialTask/SocialTaskService.php:516-563 · app/Models/SocialTaskExecutionModel.php:152-157
56:
اثر: شمارندهٔ call و دادهٔ JSON ابتدا خوانده، سپس merge و بدون قفل نوشته می‌شود. درخواست‌های هم‌زمان می‌توانند شمارنده را دور بزنند یا یکی از mergeها را از بین ببرند؛ این موضوع محدودیت ضدپمپاژ و سوابق رفتاری را قابل اتکا نمی‌گذارد.

57:
راهکار: قفل ردیف داخل transaction، یا update اتمیک JSON/شمارنده با شرط و بررسی row count استفاده کنید. برای cap، تست concurrency اضافه شود.

58:
59: 60:
61:
MEDIUM / P2 مسیر زندهٔ redeem کوپن در زمان مصرف revalidation کامل ندارد
62:
app/Services/Shared/CouponService.php:234-303,314-363 · app/Services/Influencer/InfluencerCommandService.php:368-414,502-511 · app/Services/AdSystemManager.php:223-276,560-604
63:
اثر: مسیرهای زنده از redeem() استفاده می‌کنند؛ این متد lock و usage-limit را بررسی می‌کند اما در زمان مصرف دوباره active، expiry، applicability و min-purchase را validate نمی‌کند. بنابراین preview معتبرِ قبل از غیرفعال‌شدن/انقضای کوپن می‌تواند تا TTL توکن هنوز redeem شود. متد امن‌تر validateAndRedeem() در همین کلاس وجود دارد اما caller production ندارد.

64:
راهکار: اعتبارسنجی نهایی و محاسبهٔ discount را داخل همان transaction و زیر lock انجام دهید، یا همهٔ callerها را به validateAndRedeem() منتقل کنید. تست race بین preview و redeem لازم است.

65:
66:
67: 68:
69:
70:
مواردی که نقص قطعی پیدا نشد
71:
72:
در مسیر PlaceBet، deadline با زمان DB، قفل بازی، سقف مبلغ و idempotency بررسی می‌شود.
73:
در settlement Prediction قفل بازی، جلوگیری از پرداخت تکراری و rollback تراکنش وجود دارد.
74:
در Vitrine، خود مسیر مستقیم buy از active تا hold و CAS به in_escrow، قفل مالی و idempotency مناسبی دارد؛ نقص در مسیر پذیرش request است.
75:
در بررسی انجام‌شده IDOR قطعی در endpointهای اصلی این محدوده پیدا نشد.
76:
77:
78:

85:
86: