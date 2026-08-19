# گزارش اصلاح و تست SocialTask موبایل‌محور — ۲۰۲۶/۰۶/۱۹

## تصمیم معماری نهایی
SocialTask برای انجام‌دهنده **proof محور نیست**.

کاربر برای SocialTask نباید متن، لینک، فایل یا اسکرین‌شات به عنوان مدرک دستی بفرستد. تصمیم سیستم باید بر اساس امتیاز چند المان انجام شود:

- الگوی انسانی رفتار کاربر
- زمان فعال نسبت به زمان مورد انتظار
- تنوع تعامل‌ها مثل tap / scroll / swipe
- مکث‌ها و delay طبیعی
- focus/blur و app lifecycle در موبایل
- در صورت مشکوک بودن: camera verification موبایل به عنوان المان کمکی امتیازی

## Camera Verification موبایل
این منطق فقط برای موبایل/اپ موبایل در نظر گرفته شده است.

در وضعیت مشکوک:

1. سرور از روی behavior signals تشخیص می‌دهد که verification لازم است.
2. اپ موبایل از کاربر اجازه دوربین می‌گیرد.
3. چند نمونه frame در ابتدا/میانه/انتها گرفته می‌شود.
4. تحلیل محلی انجام می‌شود.
5. تصویر خام یا screenshot به سرور ارسال نمی‌شود.
6. فقط score و signalها ارسال می‌شوند.

Payload نمونه:

```json
{
  "execution_id": 9001,
  "camera_score": 85,
  "task_type": "follow",
  "verified_signals": [
    "camera_permission_granted",
    "no_raw_image_uploaded",
    "frame_count_3",
    "live_video_stream",
    "local_frame_analysis",
    "task_type_follow"
  ],
  "frame_count": 3,
  "frame_signals": {
    "luminance_start": 0,
    "luminance_middle": 0,
    "luminance_end": 0,
    "luminance_delta": 0,
    "local_analysis": true
  },
  "client_context": {
    "client_mode": "mobile_app",
    "raw_image_uploaded": false
  }
}
```

## تغییرات DB/Migration
Migration اضافه شد:

```text
database/migrations/2026_06_19_0002_social_task_mobile_scoring.sql
```

ستون‌های امتیازدهی به `social_task_executions` اضافه شد:

```text
submitted_at
completed_at
client_mode
client_version
device_context
behavior_score
time_score
interaction_score
camera_score
final_score
score_breakdown
verification_required
verification_method
verification_requested_at
verification_completed_at
```

ستون‌های تکمیلی به `social_camera_requests` اضافه شد:

```text
method
frame_count
frame_signals
raw_image_stored
client_context
verification_score
```

## تغییرات کد

### SubmitSocialTaskExecutionJob
مسیر submit به complete امتیازی تبدیل شد:

- دیگر proof_text/proof_url لازم نیست.
- اگر score بالا باشد: auto approved و پاداش پرداخت می‌شود.
- اگر score پایین باشد: rejected.
- اگر score میانی باشد: submitted + flag_review.
- اگر موبایل و مشکوک باشد و camera_score هنوز موجود نباشد: `camera_required=true` برمی‌گردد.

### SocialTaskApiController
- `recordBehavior()` رفتار را تحلیل می‌کند و در صورت نیاز camera request می‌سازد.
- `cameraVerify()` خروجی تحلیل محلی دوربین را از اپ موبایل می‌گیرد.

### usersocialtasksexecute.js
- فرم proof حذف شد.
- behavior signals جمع‌آوری می‌شود.
- اگر verification لازم باشد، فلو camera اجرا می‌شود.
- submit نهایی JSON و proofless است.

## نصب و تست DB واقعی
در محیط تست:

```text
MariaDB 11.8
pdo_mysql فعال
DB: chortk
```

Migrationها اجرا شدند:

```text
Executed 87 migrations + migration جدید social_task_mobile_scoring
```

## تست مرورگر موبایل
اسکریپت:

```text
/home/user/browser-test/social-camera-flow-test.js
```

نتیجه:

```json
{
  "ok": true,
  "errors": []
}
```

## تست DB/Service واقعی
اسکریپت:

```text
tools/social-camera-db-test.php
```

نتیجه مهم:

```json
{
  "camera_required": true,
  "camera_result": {
    "success": true,
    "camera_score": 85
  },
  "submit": {
    "success": true,
    "status": "submitted",
    "task_score": 47.77,
    "score_breakdown": {
      "decision": "manual_review_with_mobile_camera"
    }
  },
  "execution": {
    "proof_text": null,
    "final_score": "47.77",
    "verification_required": 1,
    "camera_score": "85.00"
  },
  "camera_row": {
    "status": "completed",
    "image_path": null,
    "raw_image_stored": 0
  }
}
```

## تست Auto-Approve امتیازی
سناریوی رفتار انسانی با DB واقعی تست شد:

- تعامل ترکیبی
- variance انسانی
- active_time مناسب
- بدون proof دستی
- بدون camera

نتیجه:

```json
{
  "status": "approved",
  "task_score": 78.25,
  "decision": "auto_approved",
  "reward_paid": 1
}
```

## جمع‌بندی
SocialTask اکنون به سمت منطق موبایل‌محور و امتیازی اصلاح شده است:

```text
No manual proof
Behavior score
Time score
Interaction score
Optional mobile camera score
Final decision by scoring engine
```
