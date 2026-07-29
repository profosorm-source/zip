<?php

if (!function_exists('view')) {
    function view($viewName, $data = [])
    {
        $session = \Core\Session::getInstance();

        $globals = [];

        $globals['currentUser'] = auth();
        $globals['isLoggedIn'] = $globals['currentUser'] !== null;

        $globals['flashSuccess'] = $session->getFlash('success');
        $globals['flashError']   = $session->getFlash('error');
        $globals['flashWarning'] = $session->getFlash('warning');
        $globals['errors']       = $session->getFlash('errors') ?? [];
        $globals['old']          = $session->getFlash('old')    ?? [];

        $globals['showResendVerification'] = $session->getFlash('show_resend_verification') ?? false;
        $globals['resendEmail']            = $session->getFlash('resend_email') ?? '';

        // HIGH-11 Fix: Inject CSP Nonce from the current request into all views
        try {
            $request = app(\Core\Request::class);
            $globals['cspNonce'] = $request->nonce();
        } catch (\Throwable $e) {
            $globals['cspNonce'] = '';
        }

        // BUGFIX-SIDEBAR-DB-IN-VIEW-2026-06:
        //   The admin sidebar partial (views/partials/admin/sidebar.php) used
        //   to call Database::getInstance() eight separate times to populate
        //   nav badges (pending KYC, pending withdrawals, open tickets, …).
        //   Each admin page therefore issued 8 extra round-trips and had raw
        //   SQL strings inside the view layer — a violation of the project's
        //   "models own DB access" rule.
        //
        //   We now resolve those counters once per admin request, reusing
        //   the existing service / model layer (no new service class), and
        //   expose them to the view as `$sidebarBadges`. Non-admin requests
        //   skip the work entirely.
        $isAdminUser = $globals['isLoggedIn']
            && (
                ($globals['currentUser']->is_admin ?? 0) == 1
                || in_array(($globals['currentUser']->role ?? 'user'), ['admin', 'super_admin'], true)
            );
        $globals['sidebarBadges'] = $isAdminUser ? sidebar_badges() : [];

        // BUGFIX-SIDEBAR-DB-IN-VIEW-2026-06 (user panel):
        //   views/partials/user/sidebar.php used to call db()->fetch() twice
        //   directly inside the view (dispute + influencer-order counters),
        //   duplicating the same anti-pattern already fixed for the admin
        //   sidebar. Resolved here once per request through the model layer
        //   and exposed as `$userSidebarBadges` — the view now stays SQL-free.
        $globals['userSidebarBadges'] = $globals['isLoggedIn']
            ? user_sidebar_badges((int)($globals['currentUser']->id ?? 0))
            : ['disputes_open' => 0, 'influencer_orders_pending' => 0];

        $viewVars = array_merge($globals, (array)$data);
        // Avoid shadowing a legitimate view variable named `$data` with this
        // helper's own parameter. Several analytics views intentionally pass a
        // `data` payload; EXTR_SKIP would otherwise leave the parameter array in
        // place and make `$data['users']` fail inside the view.
        unset($data);

        ob_start();
        extract($viewVars, EXTR_SKIP);

        $viewPath = __DIR__ . '/../views/' . str_replace('.', '/', $viewName) . '.php';

        if (!file_exists($viewPath)) {
            ob_end_clean();
            throw new \Exception("View not found: {$viewName}");
        }

        require $viewPath;
        $html = ob_get_clean();
        echo $html;
        // Legacy compatibility: this helper historically both echoed and
        // returned the HTML. Router::handleResult uses this marker to avoid
        // echoing the same returned view a second time when controllers use
        // `return view(...)`.
        $GLOBALS['_last_view_helper_echo_hash'] = hash('sha256', $html);
        return '';
    }
}

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function old(string $key, $default = ''): string
{
    $val = app()->session->getOld($key, $default);
    return e($val);
}

function error(string $field): ?string
{
    $errors = app()->session->getFlash('errors');
    
    if ($errors === null || !is_array($errors)) {
        return null;
    }
    
    return $errors[$field] ?? null;
}

function flash(string $key): ?string
{
    $value = app()->session->getFlash($key);
    return $value;
}

if (!function_exists('get_flash')) {
    function get_flash($key, $default = null)
    {
        return app()->session->getFlash($key, $default);
    }
}



if (!function_exists('sidebar_badges')) {
    /**
     * BUGFIX-SIDEBAR-DB-IN-VIEW-2026-06.
     *
     * Resolve all admin-sidebar counter badges in one place using the
     * existing service / model layer instead of inline DB queries inside
     * the view file. Result is per-request memoized so a layout that
     * includes the partial multiple times still only computes once.
     *
     * Counters returned:
     *   kyc_pending             pending KYC review queue
     *   withdrawals_pending     pending withdrawal approvals
     *   account_deletions       pending account-deletion requests
     *   payment_logs_pending    payment gateway verifications waiting
     *   tickets_open            open or pending support tickets
     *   bug_reports             open bug-report comments
     *   sentry_unresolved       unresolved Sentry issues
     *   system_alerts_active    active, unacknowledged system alerts
     *
     * Every individual lookup is wrapped in try/catch — a single failing
     * counter must never break the entire sidebar render.
     *
     * @return array<string,int>
     */
    function sidebar_badges(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $c = \Core\Container::getInstance();
        $zero = [
            'kyc_pending'           => 0,
            'withdrawals_pending'   => 0,
            'account_deletions'     => 0,
            'payment_logs_pending'  => 0,
            'tickets_open'          => 0,
            'bug_reports'           => 0,
            'sentry_unresolved'     => 0,
            'system_alerts_active'  => 0,
        ];
        $cache = $zero;

        $safe = static function (callable $fn, string $key) use (&$cache): void {
            try {
                $cache[$key] = (int) $fn();
            } catch (\Throwable $e) {
                // already 0 — keep going
            }
        };

        // 1. KYC pending — KYCQueryService is the canonical reader.
        $safe(fn() => $c->make(\App\Services\KYC\KYCQueryService::class)
                       ->count(['status' => 'pending']), 'kyc_pending');

        // 2. Withdrawals pending — dedicated query service method.
        $safe(fn() => $c->make(\App\Services\Withdrawal\WithdrawalQueryService::class)
                       ->countPendingWithdrawals(), 'withdrawals_pending');

        // 3. Account-deletion requests pending — model already has getPendingDeletions().
        $safe(fn() => count(
            $c->make(\App\Models\AccountDeletionLog::class)->getPendingDeletions()
        ), 'account_deletions');

        // 4-8. The remaining counters have no dedicated service method yet.
        //      Going through the canonical model layer (one tiny COUNT each)
        //      is the project's existing convention and keeps view files SQL-free.

        // 4. Tickets open or pending — Ticket model has countForAdmin() but it
        //    only accepts one status at a time, so we sum two calls. Cheap,
        //    and keeps the view free of multi-status SQL knowledge.
        $safe(static function () use ($c) {
            $m = $c->make(\App\Models\Ticket::class);
            return $m->countForAdmin(['status' => 'open'])
                 + $m->countForAdmin(['status' => 'pending']);
        }, 'tickets_open');

        // 5-8. The remaining four counters live on tables that do not yet
        //      have a dedicated model count() helper. To honour the rule
        //      "no SQL inside the view layer" while not creating new files,
        //      we do a single COUNT each through Core\Database — the same
        //      thing the application already does inside models. Adding a
        //      one-line count() method per model is the natural next step;
        //      see the FOLLOWUP comments below.

        // FOLLOWUP: prefer App\Models\PaymentLog::countByStatus('pending_verification')
        //           once that method exists.
        $safe(fn() => (int) ($c->make(\Core\Database::class)->fetch(
            "SELECT COUNT(*) AS c FROM payment_logs WHERE status = 'pending_verification'"
        )->c ?? 0), 'payment_logs_pending');

        // FOLLOWUP: prefer App\Models\BugReportComment::count() once it exists.
        //           The "id > 0" predicate matches the existing view query.
        $safe(fn() => (int) ($c->make(\Core\Database::class)->fetch(
            "SELECT COUNT(*) AS c FROM bug_report_comments WHERE id > 0"
        )->c ?? 0), 'bug_reports');

        // FOLLOWUP: prefer App\Models\SentryModel::countUnresolvedIssues().
        $safe(fn() => (int) ($c->make(\Core\Database::class)->fetch(
            "SELECT COUNT(*) AS c FROM sentry_issues WHERE status = 'unresolved'"
        )->c ?? 0), 'sentry_unresolved');

        // FOLLOWUP: prefer App\Models\SentryModel::countActiveAlerts().
        $safe(fn() => (int) ($c->make(\Core\Database::class)->fetch(
            "SELECT COUNT(*) AS c FROM system_alerts WHERE is_active = 1 AND acknowledged_at IS NULL"
        )->c ?? 0), 'system_alerts_active');

        return $cache;
    }
}

if (!function_exists('user_sidebar_badges')) {
    /**
     * BUGFIX-SIDEBAR-DB-IN-VIEW-2026-06 (user panel).
     *
     * Same rationale as sidebar_badges() above, but for the regular user
     * sidebar (views/partials/user/sidebar.php), which used to run two raw
     * db()->fetch() queries directly inside the view file.
     *
     * Counters returned:
     *   disputes_open               open/unresolved disputes involving the user
     *   influencer_orders_pending   incoming influencer orders needing action
     *
     * Every lookup is wrapped in try/catch and memoized per user per request.
     *
     * @return array<string,int>
     */
    function user_sidebar_badges(int $userId): array
    {
        static $cache = [];
        if ($userId <= 0) {
            return ['disputes_open' => 0, 'influencer_orders_pending' => 0];
        }
        if (isset($cache[$userId])) {
            return $cache[$userId];
        }

        $result = ['disputes_open' => 0, 'influencer_orders_pending' => 0];
        $c = \Core\Container::getInstance();

        try {
            $result['disputes_open'] = $c->make(\App\Models\Dispute::class)->countOpenByUser($userId);
        } catch (\Throwable $e) {
            // already 0 — a single failing counter must never break sidebar render
        }

        try {
            $result['influencer_orders_pending'] = $c->make(\App\Models\StoryOrder::class)
                ->countPendingForInfluencer($userId);
        } catch (\Throwable $e) {
            // already 0
        }

        try {
            $notifModel = $c->make(\App\Models\Notification::class);
            $result['unread_notifications'] = $notifModel->getUnreadCount($userId);
            $result['top_notifications'] = $notifModel->getUserNotifications($userId, true, 5);
            if (empty($result['top_notifications'])) {
                $result['top_notifications'] = $notifModel->getUserNotifications($userId, false, 5);
            }
        } catch (\Throwable $e) {
            $result['unread_notifications'] = 0;
            $result['top_notifications'] = [];
        }

        return $cache[$userId] = $result;
    }
}
