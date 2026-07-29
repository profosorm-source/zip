<?php

// ═══════════════════════════════════════════════════════════════════════════
// CustomTaskSubmission
// ═══════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════
// StoryOrder
// ═══════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════
// SEOExecution
// ═══════════════════════════════════════════════════════════════════════════

/**
 * نام فارسی وضعیت اجرای SEO
 */
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

/**
 * کلاس CSS badge وضعیت اجرای SEO
 */
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


// ═══════════════════════════════════════════════════════════════════════════
// Map helpers (برای views که از array کامل نیاز دارند)
// ═══════════════════════════════════════════════════════════════════════════

function custom_task_status_labels_map(): array  { return ['draft'=>'پیشنویس','pending_review'=>'در انتظار بررسی','review_pending'=>'در انتظار بررسی','active'=>'فعال','paused'=>'متوقف','completed'=>'تکمیل‌شده','rejected'=>'رد شده','expired'=>'منقضی']; }
function custom_task_status_classes_map(): array { return ['draft'=>'badge-secondary','pending_review'=>'badge-warning','review_pending'=>'badge-warning','active'=>'badge-success','paused'=>'badge-info','completed'=>'badge-primary','rejected'=>'badge-danger','expired'=>'badge-danger']; }
function custom_task_types(): array { return ['custom'=>'سفارشی','follow'=>'فالو','like'=>'لایک','comment'=>'کامنت','subscribe'=>'سابسکرایب','view'=>'بازدید','join'=>'عضویت','signup'=>'ثبت‌نام','install'=>'نصب اپلیکیشن','survey'=>'نظرسنجی','content'=>'تولید محتوا','seo'=>'سئو','social'=>'شبکه اجتماعی']; }
function story_order_status_labels_map(): array  { return ['pending'=>'در انتظار','active'=>'فعال','completed'=>'تکمیل‌شده','cancelled'=>'لغو شده','rejected'=>'رد شده']; }
function story_order_status_classes_map(): array { return ['pending'=>'badge-warning','active'=>'badge-success','completed'=>'badge-primary','cancelled'=>'badge-secondary','rejected'=>'badge-danger']; }
