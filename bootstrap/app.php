<?php

/**
 * bootstrap/app.php — Slim Application Bootstrap
 * 
 * اصلاحات اعمال شده:
 * ✅ کاهش از ۳۴۳ خط به ~۱۱۰ خط
 * ✅ استفاده از AppServiceProvider واحد
 * ✅ حذف Container::getInstance() مستقیم از Factoryها
 * ✅ حفظ تمام security checks و configها
 */

use Core\Container;

// ── Constants ───────────────────────────────────────────────
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// ── Tracing Context (Correlation ID) ────────────────────────
if (!isset($_SERVER['REQUEST_ID'])) {
    $_SERVER['REQUEST_ID'] = $_SERVER['HTTP_X_REQUEST_ID']
        ?? bin2hex(random_bytes(16));
}

// ── Load Constants & Autoloader ─────────────────────────────
require_once BASE_PATH . '/app/Constants/MagicNumbers.php';

$vendorAutoload = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    $isCli = (PHP_SAPI === 'cli' || defined('STDIN'));
    $errorMessage = "Error: vendor/autoload.php not found. Run 'composer install'.";
    if ($isCli) { fwrite(STDERR, $errorMessage . "\n"); exit(1); }
    http_response_code(500);
    die('<h1>خطای راه‌اندازی: Composer autoload یافت نشد</h1>');
}
require_once $vendorAutoload;

// ── Environment & Security Checks ───────────────────────────
global $env;
if (empty($env)) {
    // Production/deployment configuration is injected through process env or an
    // untracked .env file. Local developer configuration lives in .env.local so
    // a real .env is never required in the repository/archive.
    $envPath = BASE_PATH . '/.env';
    if (!file_exists($envPath)) {
        $envPath = BASE_PATH . '/.env.local';
    }
    $env = file_exists($envPath) ? parse_ini_file($envPath, false, INI_SCANNER_RAW) : [];
    if ($env === false) { $env = []; error_log('[Chortke] .env invalid'); }
}

if (!defined('SECURITY_API_TOKEN_SECRET')) {
    $secret = $env['SECURITY_API_TOKEN_SECRET'] ?? getenv('SECURITY_API_TOKEN_SECRET') ?? $_ENV['SECURITY_API_TOKEN_SECRET'] ?? null;
    if ($secret) define('SECURITY_API_TOKEN_SECRET', (string)$secret);
}

$appKey = secure_key();
if (empty($appKey) || strlen($appKey) < 32 || $appKey === 'default_key') {
    if (config('app.env') === 'production') {
        http_response_code(500);
        die('<h1>خطای امنیتی: APP_KEY نامعتبر</h1>');
    }
    throw new Exception('APP_KEY must be >= 32 chars and not "default_key"');
}

$appUrl = (string)config('app.url');
if (empty($appUrl) || !filter_var($appUrl, FILTER_VALIDATE_URL)) {
    throw new Exception('APP_URL missing or invalid');
}

if (!defined('SECURITY_API_TOKEN_SECRET') || strlen(SECURITY_API_TOKEN_SECRET) < 32) {
    throw new Exception('SECURITY_API_TOKEN_SECRET missing or too weak');
}

// ── Config & Timezone ──────────────────────────────────────
$config = config();
date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Tehran');

// ── Error Configuration ────────────────────────────────────
$isDebug = (bool) config('app.debug', false);
$isProduction = config('app.env', 'production') === 'production';

if ($isDebug && !$isProduction) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// ── Container & Service Registration ───────────────────────
$container = Container::getInstance();

// ثبت Session (نیاز فوری برای security)
$container->singleton(\Core\Session::class, fn() => \Core\Session::getInstance());

// ثبت LogService (قبل از providerها)
$container->singleton(\App\Services\LogService::class);

// استفاده از AppServiceProvider واحد
\App\Providers\AppServiceProvider::register($container);

// ثبت EmailDeliveryStore
$container->singleton(\App\Services\EmailDeliveryStore::class);

// Projection Sync Events — search index
$projectionSyncEvents = [
    'wallet.deposit.completed',
    'wallet.withdraw.completed',
    'withdrawal.created',
    'ticket.created',
    'direct_message.sent',
    'content.created',
    'content.approved',
    'content.deleted',
    'crypto_deposit.created',
    'crypto_deposit.completed',
    'bank_card.created',
    'bank_card.deleted',
    'escrow.created',
    'escrow.released',
    'social_task.created',
    'social_task.deleted',
    'coupon.created',
    'coupon.deleted',
    'kyc.created',
    'kyc.approved',
    'prediction.created',
    'prediction.deleted',
    'lottery.created',
    'lottery.deleted',
    'ticket_message.created',
    'investment.created',
    'investment.deleted',
];

// CLI Command Registration
$container->singleton(\Core\Console\CliDispatcher::class);
$cli = $container->make(\Core\Console\CliDispatcher::class);
$cli->register('queue:work', \App\Commands\QueueWorkCommand::class, 'Process queue jobs');
$cli->register('outbox:publish', \App\Commands\OutboxPublishCommand::class, 'Publish outbox events');
$cli->register('dlq:work', \App\Commands\DlqWorkCommand::class, 'Process dead-letter queue');
$cli->register('distributed:health', \App\Commands\DistributedHealthCommand::class, 'Distributed systems health check');
$cli->register('simulate:traceable-event', \App\Commands\SimulateTraceableEventCommand::class, 'Simulate a traceable event');
$cli->register('feature', \App\Commands\FeatureFlagCommand::class, 'Feature flag management');
$cli->register('idempotency', \App\Commands\IdempotencyCommand::class, 'Idempotency key management');
$cli->register('route:audit', \App\Commands\RouteAuditCommand::class, 'Audit routes');
$cli->register('rate-limit:audit', \App\Commands\RateLimitAuditCommand::class, 'Audit rate limits');
$cli->register('queue:failed', \App\Commands\QueueFailedCommand::class, 'List failed queue jobs');
$cli->register('system:cleanup', \App\Commands\SystemCleanupCommand::class, 'System cleanup');
$cli->register('escrow:cleanup', \App\Commands\EscrowCleanupCommand::class, 'Cleanup expired escrows');
$cli->register('analytics:warmup', \App\Commands\AnalyticsCacheWarmupCommand::class, 'Warm analytics cache');
$cli->register('search:backfill', \App\Commands\BackfillSearchProjectionCommand::class, 'Backfill search projections');
$cli->register('dlq:retry', \App\Commands\DLQRetryCommand::class, 'Retry DLQ entries');
$cli->register('migration', \App\Commands\MigrationManager::class, 'Run migrations');
$cli->register('schedule:run', \App\Commands\ProcessScheduledTasksCommand::class, 'Run scheduled tasks');
$cli->register('stuck-withdrawal:review', \App\Commands\StuckWithdrawalReviewCommand::class, 'Review stuck withdrawals');
$cli->register('tor:update', \App\Commands\UpdateTorExitNodesCommand::class, 'Update Tor exit nodes');
$cli->register('db:analyze', \App\Commands\DatabaseAnalyzerCommand::class, 'Database analysis and health check');
$cli->register('alert-rules:bootstrap', \App\Commands\AlertRulesBootstrapCommand::class, 'Bootstrap alert rules');
