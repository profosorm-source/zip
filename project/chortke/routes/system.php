<?php

/**
 * مسیرهای سیستمی ادمین — Cron، Email Queue، Cache، API Tokens، Debug
 */

use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;
use App\Controllers\Admin\CronController;
use App\Controllers\Admin\EmailQueueController;
use App\Controllers\Admin\ApiTokenAdminController;
use App\Controllers\Admin\CacheAdminController;
use App\Controllers\DebugController;

use App\Middleware\CSRFMiddleware;

$admin      = [AuthMiddleware::class, AdminMiddleware::class, CSRFMiddleware::class];
$adminRead  = [AuthMiddleware::class, AdminMiddleware::class];   // برای GET های debug بدون CSRF
$r          = app()->router;

// ── Debug (فقط ادمین — در production باید APP_DEBUG=false باشد) ──────────
if ((bool) config('app.debug', false)) {
    $r->get('/admin/debug/router', [DebugController::class, 'router'], $adminRead);
}

// ── Cron ──────────────────────────────────────────────────────────────────
$r->get('/admin/cron',      [CronController::class, 'index'], $admin);
$r->post('/admin/cron/run', [CronController::class, 'run'],   $admin);

// ── صف ایمیل ─────────────────────────────────────────────────────────────
$r->get('/admin/email-queue',               [EmailQueueController::class, 'index'],       $admin);
$r->post('/admin/email-queue/process',      [EmailQueueController::class, 'process'],     $admin);
$r->post('/admin/email-queue/retry-failed', [EmailQueueController::class, 'retryFailed'], $admin);
$r->post('/admin/email-queue/{id}/retry',   [EmailQueueController::class, 'retry'],       $admin);

// ── توکن‌های API (ادمین) ──────────────────────────────────────────────────
$r->get('/admin/api-tokens',                 [ApiTokenAdminController::class, 'index'],         $admin);
$r->post('/admin/api-tokens/{id}/revoke',    [ApiTokenAdminController::class, 'revoke'],        $admin);
$r->post('/admin/api-tokens/revoke-expired', [ApiTokenAdminController::class, 'revokeExpired'], $admin);

// ── Cache ─────────────────────────────────────────────────────────────────
$r->get('/admin/cache',         [CacheAdminController::class, 'index'],  $admin);
$r->post('/admin/cache/clear',  [CacheAdminController::class, 'clear'],  $admin);
$r->post('/admin/cache/forget', [CacheAdminController::class, 'forget'], $admin);
$r->post('/admin/cache/reset-circuit-breaker', [CacheAdminController::class, 'resetCircuitBreaker'], $admin);

// ── Health metrics (authenticated) ─────────────────────────────────
$r->get('/admin/health', [\App\Controllers\MetricsController::class, 'health'], [\App\Middleware\AuthMiddleware::class, \App\Middleware\AdminMiddleware::class]);
