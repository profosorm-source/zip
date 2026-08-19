<?php

// ═══════════════════════════════════════════════════════════════════════════
// Label & Display Helper Functions
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('social_status_label')) {
    function social_status_label(?string $status): string
    {
        $labels = [
            'verified' => 'تایید شده',
            'pending'  => 'در انتظار بررسی',
            'rejected' => 'رد شده',
        ];
        return $labels[(string)$status] ?? ((string)$status ?: 'نامشخص');
    }
}

if (!function_exists('task_execution_status_label')) {
    function task_execution_status_label(?string $status): string
    {
        $labels = [
            'pending'   => 'در انتظار',
            'submitted' => 'ارسال شده',
            'approved'  => 'تایید شده',
            'rejected'  => 'رد شده',
            'expired'   => 'منقضی',
        ];
        return $labels[(string)$status] ?? ((string)$status ?: 'نامشخص');
    }
}

if (!function_exists('task_execution_status_badge')) {
    function task_execution_status_badge(?string $status): string
    {
        $badges = [
            'pending'   => 'badge-warning',
            'submitted' => 'badge-info',
            'approved'  => 'badge-success',
            'rejected'  => 'badge-danger',
        ];
        return $badges[(string)$status] ?? 'badge-secondary';
    }
}

if (!function_exists('social_status_badge')) {
    function social_status_badge(?string $status): string
    {
        $badges = [
            'verified' => 'badge-success',
            'pending'  => 'badge-warning',
            'rejected' => 'badge-danger',
        ];
        return $badges[(string)$status] ?? 'badge-secondary';
    }
}

if (!function_exists('task_dispute_status_label')) {
    function task_dispute_status_label(?string $status): string
    {
        $labels = [
            'pending'  => 'در انتظار بررسی',
            'resolved' => 'حل شده',
            'rejected' => 'رد شده',
            'closed'   => 'بسته شده',
        ];
        return $labels[(string)$status] ?? ((string)$status ?: 'نامشخص');
    }
}

if (!function_exists('ad_task_type_label')) {
    function ad_task_type_label(?string $type): string
    {
        $labels = [
            'custom' => 'سفارشی',
            'social' => 'شبکه اجتماعی',
            'seo'    => 'بازدید سئو',
            'banner' => 'بنری',
            'adtube' => 'ویدیو پاداش',
        ];
        return $labels[(string)$type] ?? ((string)$type ?: 'عمومی');
    }
}

/**
 * نام فارسی شبکه‌های اجتماعی
 */
if (!function_exists('social_platform_label')) {
    function social_platform_label(?string $platform): string
    {
        $platform = strtolower((string)$platform);
        $labels = [
            'instagram' => 'اینستاگرام',
            'telegram'  => 'تلگرام',
            'youtube'   => 'یوتیوب',
            'twitter'   => 'توییتر / X',
            'tiktok'    => 'تیک‌تاک',
            'aparat'    => 'آپارات',
            'linkedin'  => 'لینکدین',
        ];
        return $labels[$platform] ?? ($platform ?: 'شبکه اجتماعی');
    }
}

/**
 * فرمت‌دهی زمان خروجی
 */
if (!function_exists('format_time')) {
    function format_time(mixed $time): string
    {
        if (!$time) return '—';
        if (is_numeric($time)) {
            return date('Y-m-d H:i:s', (int)$time);
        }
        return (string)$time;
    }
}

/**
 * badge کلاس وضعیت اختلافات تسک
 */
if (!function_exists('task_dispute_status_badge')) {
    function task_dispute_status_badge(?string $status): string
    {
        $badges = [
            'pending'  => 'badge-warning',
            'resolved' => 'badge-success',
            'rejected' => 'badge-danger',
            'closed'   => 'badge-secondary',
        ];
        return $badges[(string)$status] ?? 'badge-info';
    }
}

/**
 * نام فارسی وضعیت اجرای SEO
 */
if (!function_exists('seo_execution_status_label')) {
    function seo_execution_status_label(string $status): string
    {
        $labels = [
            'pending'   => 'در انتظار',
            'approved'  => 'تایید شده',
            'rejected'  => 'رد شده',
            'expired'   => 'منقضی',
        ];
        return $labels[$status] ?? $status;
    }
}

/**
 * کلاس CSS badge وضعیت اجرای SEO
 */
if (!function_exists('seo_execution_status_badge')) {
    function seo_execution_status_badge(string $status): string
    {
        $badges = [
            'pending'  => 'warning',
            'approved' => 'success',
            'rejected' => 'danger',
            'expired'  => 'secondary',
        ];
        return $badges[$status] ?? 'secondary';
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// Map helpers (برای views که به array نیاز دارند)
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('custom_task_status_labels_map')) {
    function custom_task_status_labels_map(): array  { return ['draft'=>'پیشنویس','pending_review'=>'در انتظار بررسی','review_pending'=>'در انتظار بررسی','active'=>'فعال','paused'=>'متوقف','completed'=>'تکمیل‌شده','rejected'=>'رد شده','expired'=>'منقضی']; }
}
if (!function_exists('custom_task_status_classes_map')) {
    function custom_task_status_classes_map(): array { return ['draft'=>'badge-secondary','pending_review'=>'badge-warning','review_pending'=>'badge-warning','active'=>'badge-success','paused'=>'badge-info','completed'=>'badge-primary','rejected'=>'badge-danger','expired'=>'badge-danger']; }
}
if (!function_exists('custom_task_types')) {
    function custom_task_types(): array { return ['custom'=>'سفارشی','follow'=>'فالو','like'=>'لایک','comment'=>'کامنت','subscribe'=>'سابسکرایب','view'=>'بازدید','join'=>'عضویت','signup'=>'ثبت‌نام','install'=>'نصب اپلیکیشن','survey'=>'نظرسنجی','content'=>'تولید محتوا','seo'=>'سئو','social'=>'شبکه اجتماعی']; }
}
if (!function_exists('story_order_status_labels_map')) {
    function story_order_status_labels_map(): array  { return ['pending'=>'در انتظار','active'=>'فعال','completed'=>'تکمیل‌شده','cancelled'=>'لغو شده','rejected'=>'رد شده']; }
}
if (!function_exists('story_order_status_classes_map')) {
    function story_order_status_classes_map(): array { return ['pending'=>'badge-warning','active'=>'badge-success','completed'=>'badge-primary','cancelled'=>'badge-secondary','rejected'=>'badge-danger']; }
}
