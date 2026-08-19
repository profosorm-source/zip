<?php

if (!function_exists('banner_type_label')) {
    function banner_type_label(string $type): string
    {
        $labels = [
            'system' => 'سیستمی',
            'startup' => 'استارتاپی',
            'user' => 'کاربری',
            'promo' => 'تبلیغاتی',
        ];
        return $labels[$type] ?? $type;
    }
}

if (!function_exists('banner_status_badge')) {
    function banner_status_badge($banner): string
    {
        $status = (string)($banner->status ?? '');
        if ($status === 'rejected' || !empty($banner->rejection_reason) || !empty($banner->reject_reason)) {
            return '<span class="badge badge-danger">رد شده</span>';
        }
        if ($status === 'cancelled') {
            return '<span class="badge badge-secondary">لغو شده</span>';
        }
        if ($status === 'completed') {
            return '<span class="badge badge-primary">تکمیل‌شده</span>';
        }
        if ($status === 'expired' || (!empty($banner->end_date) && strtotime((string)$banner->end_date) < time())) {
            return '<span class="badge badge-secondary">منقضی</span>';
        }
        if (in_array((string)($banner->banner_type ?? 'system'), ['user', 'startup'], true) && empty($banner->approved_at) && !in_array($status, ['active','approved'], true)) {
            return '<span class="badge badge-warning">در انتظار تایید</span>';
        }
        if (!empty($banner->is_active) || in_array($status, ['active', 'approved'], true)) {
            return '<span class="badge badge-success">فعال</span>';
        }
        return '<span class="badge badge-secondary">غیرفعال</span>';
    }
}
