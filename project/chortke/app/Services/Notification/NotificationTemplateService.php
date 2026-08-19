<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use Core\Cache;
use App\Contracts\LoggerInterface;

class NotificationTemplateService
{
    private const TEMPLATE_CACHE_PREFIX = 'notif_tpl:';
    private const TEMPLATE_CACHE_TTL = 30;

    private \App\Contracts\CacheInterface $cache;
    private Notification $model;
    private ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation;

    public function __construct(
        \App\Contracts\CacheInterface $cache,
        Notification $model,
        ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation = null
    ) {
        $this->cache = $cache;
        $this->model = $model;
        $this->cacheInvalidation = $cacheInvalidation;
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    public function renderTemplate(string $templateKey, array $vars = []): array
    {
        $template = $this->getTemplate($templateKey);

        $title = $template['title'] ?? null;
        $message = $template['message'] ?? null;
        if (!is_string($title) || !is_string($message)) {
            throw new \UnexpectedValueException('Notification template title and message must be strings.');
        }
        return [
            'title' => $this->interpolate($title, $vars),
            'message' => $this->interpolate($message, $vars),
        ];
    }

    /** @return array<string, mixed> */
    public function getTemplate(string $templateKey): array
    {
        $cacheKey = self::TEMPLATE_CACHE_PREFIX . $templateKey;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            if (!is_array($cached)) throw new \UnexpectedValueException('Notification template cache must contain an array.');
            return $cached;
        }

        $dbTemplate = $this->model->getTemplateFromDb($templateKey);
        if ($dbTemplate) {
            $title = $dbTemplate->title ?? null;
            $message = $dbTemplate->message ?? null;
            if (!is_string($title) || !is_string($message)) {
                throw new \UnexpectedValueException('Persisted notification template must contain string title and message.');
            }
            $variables = json_decode(str_value($dbTemplate->variables ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($variables)) throw new \UnexpectedValueException('Template variables must decode to an array.');
            $result = ['title' => $title, 'message' => $message, 'variables' => $variables];
            $this->cache->put($cacheKey, $result, self::TEMPLATE_CACHE_TTL);
            return $result;
        }

        $default = $this->getDefaultTemplate($templateKey);
        $this->cache->put($cacheKey, $default, self::TEMPLATE_CACHE_TTL);
        return $default;
    }

    /** @param array<string, mixed> $vars */
    private function interpolate(string $text, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $text = str_replace("{{{$key}}}", str_value($value), $text);
        }
        return $text;
    }

    /** @return array<string, mixed> */
    public function getAllTemplatesWithVariables(): array
    {
        $templates = [];
        $defaults = $this->getDefaultTemplates();

        foreach ($defaults as $key => $template) {
            // نرمال‌سازی variables به شکل assoc [var => description] که view انتظار دارد
            $variables = is_array($template['variables'] ?? null) ? $template['variables'] : [];
            $variablesAssoc = [];
            foreach ($variables as $var => $desc) {
                if (is_int($var)) {
                    $variablesAssoc[$desc] = $desc;
                } else {
                    $variablesAssoc[$var] = $desc;
                }
            }

            $dbTemplate = $this->model->getTemplateFromDb($key);
            $hasOverride = $dbTemplate !== null;

            // متغیرهای override را با متغیرهای پیش‌فرض ادغام می‌کنیم (اولویت با DB)
            if ($hasOverride) {
                $dbVars = json_decode((string)($dbTemplate->variables ?? '{}'), true) ?: [];
                foreach ((array)$dbVars as $var => $desc) {
                    if (is_int($var)) {
                        $variablesAssoc[$desc] = $desc;
                    } else {
                        $variablesAssoc[$var] = $desc;
                    }
                }
            }

            $templates[$key] = [
                'default_title'    => $template['title'] ?? '',
                'default_message'  => $template['message'] ?? '',
                'override_title'   => $hasOverride ? ($dbTemplate->title ?? null) : null,
                'override_message' => $hasOverride ? ($dbTemplate->message ?? null) : null,
                'has_override'     => $hasOverride,
                'is_custom'        => $hasOverride,
                'variables'        => $variablesAssoc,
            ];
        }

        return $templates;
    }

    public function saveTemplateOverride(string $key, string $title, string $message): bool
    {
        $success = $this->model->saveTemplateOverride($key, $title, $message);
        if ($success) {
            if ($this->cacheInvalidation) {
                $this->cacheInvalidation->invalidateNotificationTemplate($key);
            } else {
                $this->cache->forget(self::TEMPLATE_CACHE_PREFIX . $key);
            }
        }
        return $success;
    }

    public function deleteTemplateOverride(string $key): bool
    {
        $success = $this->model->deleteTemplateOverride($key);
        if ($success) {
            if ($this->cacheInvalidation) {
                $this->cacheInvalidation->invalidateNotificationTemplate($key);
            } else {
                $this->cache->forget(self::TEMPLATE_CACHE_PREFIX . $key);
            }
        }
        return $success;
    }

    /** @return array{title:string, message:string, variables:list<string>} */
    private function getDefaultTemplate(string $templateKey): array
    {
        $defaults = $this->getDefaultTemplates();
        $template = $defaults[$templateKey] ?? $defaults['system'] ?? null;
        if (!is_array($template)) throw new \LogicException('Default system notification template is missing.');
        return $template;
    }

    /** @return array<string, array{title:string, message:string, variables:list<string>}> */
    private function getDefaultTemplates(): array
    {
        return [
            'deposit' => [
                'title'     => 'واریز موفق ✅',
                'message'   => 'مبلغ {{amount}} {{currency}} با موفقیت به کیف پول شما واریز شد.',
                'variables' => ['amount', 'currency'],
            ],
            'withdrawal' => [
                'title'     => 'برداشت تأیید شد 💸',
                'message'   => 'درخواست برداشت {{amount}} {{currency}} تأیید و پردازش شد.',
                'variables' => ['amount', 'currency'],
            ],
            'withdrawal_rejected' => [
                'title'     => 'برداشت رد شد ❌',
                'message'   => 'درخواست برداشت {{amount}} رد شد. دلیل: {{reason}}. مبلغ به کیف پول بازگشت.',
                'variables' => ['amount', 'reason'],
            ],
            'task' => [
                'title'     => 'تسک جدید 📋',
                'message'   => 'تسک جدید «{{task_title}}» برای شما در دسترس است.',
                'variables' => ['task_title'],
            ],
            'kyc_approved' => [
                'title'     => 'احراز هویت تأیید شد ✅',
                'message'   => 'احراز هویت شما تأیید شد. اکنون می‌توانید از تمام امکانات سایت استفاده کنید.',
                'variables' => [],
            ],
            'kyc_rejected' => [
                'title'     => 'احراز هویت رد شد ❌',
                'message'   => 'احراز هویت شما رد شد. دلیل: {{reason}}. لطفاً مدارک را مجدداً ارسال کنید.',
                'variables' => ['reason'],
            ],
            'lottery_winner' => [
                'title'     => '🎉 تبریک! برنده شدید!',
                'message'   => 'شما برنده قرعه‌کشی شدید! مبلغ {{amount}} به کیف پول شما واریز شد.',
                'variables' => ['amount'],
            ],
            'referral' => [
                'title'     => 'کمیسیون معرفی 💰',
                'message'   => 'از فعالیت «{{referred_user}}» مبلغ {{amount}} کمیسیون دریافت کردید.',
                'variables' => ['referred_user', 'amount'],
            ],
            'security' => [
                'title'     => '⚠️ هشدار امنیتی',
                'message'   => '{{message}}',
                'variables' => ['message', 'ip'],
            ],
            'investment_completed' => [
                'title'     => 'سرمایه‌گذاری تکمیل شد 📈',
                'message'   => 'سرمایه‌گذاری شما به پایان رسید. سود: {{profit}} — مجموع: {{total}}.',
                'variables' => ['profit', 'total'],
            ],
            'system' => [
                'title'     => '{{title}}',
                'message'   => '{{message}}',
                'variables' => ['title', 'message'],
            ],
            'critical_feature_change' => [
                'title'     => '⚠️ تغییر در فیچر حیاتی',
                'message'   => 'فیچر «{{feature}}» با موفقیت {{action}} شد.',
                'variables' => ['feature', 'action'],
            ],
            'task_approved' => [
                'title'     => 'وظیفه شما تایید شد ✅',
                'message'   => 'وظیفه «{{task_title}}» توسط مدیریت تایید و فعال شد.',
                'variables' => ['task_title'],
            ],
            'task_rejected' => [
                'title'     => 'وظیفه شما رد شد ❌',
                'message'   => 'وظیفه «{{task_title}}» توسط مدیریت رد شد. دلیل: {{reason}}',
                'variables' => ['task_title', 'reason'],
            ],
            'submission_auto_approved' => [
                'title'     => 'مدرک شما به صورت خودکار تایید شد ✅',
                'message'   => 'مدرک شما برای وظیفه «{{task_title}}» به دلیل عدم بررسی توسط تبلیغ‌کننده، خودکار تایید و پاداش پرداخت شد.',
                'variables' => ['task_title'],
            ],
            'submission_rejected' => [
                'title'     => 'مدرک شما رد شد ❌',
                'message'   => 'متأسفانه مدرک شما برای وظیفه «{{task_title}}» رد شد. دلیل: {{reason}}',
                'variables' => ['task_title', 'reason'],
            ],
            'rating_received' => [
                'title'     => 'امتیاز جدید دریافت کردید ⭐',
                'message'   => 'امتیاز {{rating}} ستاره برای وظیفه «{{task_title}}» دریافت کردید.',
                'variables' => ['rating', 'task_title'],
            ],
            'dispute_resolved' => [
                'title'     => 'رأی داوری صادر شد ⚖️',
                'message'   => 'داور سیستم رأی پرونده اختلاف را صادر کرد.',
                'variables' => [],
            ],
            'dispute_agreement' => [
                'title'     => 'حل اختلاف به صورت دوستانه 🤝',
                'message'   => 'اختلاف سفارش شما به توافق طرفین خاتمه یافت.',
                'variables' => [],
            ],
            'session_expired' => [
                'title'     => 'پایان نشست قدیمی 🔒',
                'message'   => 'یک نشست قدیمی از دستگاه "{{browser}}" روی "{{device}}" به پایان رسید.',
                'variables' => ['browser', 'device'],
            ],
            'moderation_warning' => [
                'title'     => 'اخطار مدیریت پیام‌ها ⚠️',
                'message'   => 'شما یک اخطار به دلیل گزارش‌های دریافتی از پیام‌هایتان دریافت کرده‌اید ({{count}}/3). لطفاً قوانین سایت را رعایت کنید.',
                'variables' => ['count'],
            ],
            'account_banned' => [
                'title'     => 'مسدودسازی حساب کاربری 🚫',
                'message'   => 'حساب کاربری شما به دلیل نقض مکرر قوانین مسدود شد.',
                'variables' => [],
            ],
            'vitrine_approved' => [
                'title'     => 'آگهی شما تایید شد ✅',
                'message'   => 'آگهی «{{listing_title}}» توسط تیم ویترین تایید و منتشر شد.',
                'variables' => ['listing_title'],
            ],
            'vitrine_new_listing' => [
                'title'     => 'آگهی مشابه جدید در ویترین 🆕',
                'message'   => 'آگهی جدیدی در دسته «{{category}}» منتشر شد: «{{listing_title}}»',
                'variables' => ['category', 'listing_title'],
            ],
            'payment_critical' => [
                'title'     => 'خطای بحرانی پرداخت 🚨',
                'message'   => 'پرداخت شماره {{payment_id}} پس از تلاش‌های مکرر ناموفق بود. کاربر: {{user_id}}',
                'variables' => ['payment_id', 'user_id'],
            ],
            'antifraud_critical' => [
                'title'     => 'هشدار: رد بحرانی تشخیص داده شد 🛡️',
                'message'   => 'رد بحرانی برای کاربر {{user_id}} شناسایی شد. امتیاز: {{task_score}}، ریسک: {{risk_score}}',
                'variables' => ['user_id', 'task_score', 'risk_score'],
            ],
        ];
    }
}
