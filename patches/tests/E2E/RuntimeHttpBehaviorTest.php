<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\TestCase;

/**
 * Black-box HTTP tests. Requests cross Router, middleware, controllers,
 * services, MariaDB and Redis through the real web entry point.
 */
final class RuntimeHttpBehaviorTest extends TestCase
{
    private string $baseUrl;
    private string $cookieJar;

    protected function setUp(): void
    {
        parent::setUp();
        $configured = getenv('CHORTKE_E2E_BASE_URL');
        $this->baseUrl = rtrim(is_string($configured) && $configured !== '' ? $configured : 'http://127.0.0.1:8090', '/');
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'chortke-e2e-cookie-');
        if ($this->cookieJar === false) {
            $this->fail('Unable to create an E2E cookie jar.');
        }

        $this->resetSeedSecurityState();
    }

    protected function tearDown(): void
    {
        if (isset($this->cookieJar) && is_file($this->cookieJar)) {
            unlink($this->cookieJar);
        }
        parent::tearDown();
    }

    public function test_homepage_is_rendered_through_the_real_http_stack(): void
    {
        $response = $this->request('GET', '/');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('<title>چرتکه', $response['body']);
        $this->assertStringContainsString('<section', $response['body']);
        $this->assertNoRuntimeDiagnostics($response['body']);
    }

    public function test_health_and_distributed_endpoints_return_parseable_runtime_data(): void
    {
        foreach (['/health', '/api/health', '/health/distributed', '/metrics/distributed'] as $path) {
            $response = $this->request('GET', $path);
            $this->assertSame(200, $response['status'], "{$path} did not return HTTP 200.");
            $this->assertNoRuntimeDiagnostics($response['body']);
            $decoded = $this->decodeJsonObject($response['body']);
            $this->assertIsArray($decoded);
        }

        $distributed = $this->decodeJsonObject($this->request('GET', '/health/distributed')['body']);
        $this->assertSame('ok', $distributed['status'] ?? null);
        $this->assertArrayHasKey('outbox', $distributed['checks'] ?? []);
        $this->assertArrayHasKey('dlq', $distributed['checks'] ?? []);
    }

    public function test_security_headers_are_emitted_by_the_runtime_pipeline(): void
    {
        $response = $this->request('GET', '/login');

        $this->assertSame(200, $response['status']);
        $this->assertSame('nosniff', strtolower($this->header($response, 'x-content-type-options')));
        $this->assertSame('sameorigin', strtolower($this->header($response, 'x-frame-options')));
        $this->assertNotSame('', $this->header($response, 'content-security-policy'));
        $this->assertStringContainsString('HttpOnly', $this->header($response, 'set-cookie'));
    }

    public function test_seed_user_can_login_with_csrf_and_reach_authenticated_dashboard(): void
    {
        $loginPage = $this->request('GET', '/login');
        $this->assertSame(200, $loginPage['status']);
        $csrf = $this->extractCsrfToken($loginPage['body']);

        $login = $this->request('POST', '/login', [
            '_csrf_token' => $csrf,
            'email' => 'user@chortke.ir',
            'password' => '123456',
            'remember' => 'on',
        ]);

        $this->assertSame(302, $login['status']);
        $this->assertSame('/dashboard', (string) parse_url($this->header($login, 'location'), PHP_URL_PATH));

        $dashboard = $this->request('GET', '/dashboard');
        $this->assertSame(200, $dashboard['status']);
        $this->assertStringContainsString('<title>داشبورد', $dashboard['body']);
        $this->assertNoRuntimeDiagnostics($dashboard['body']);
    }

    public function test_guest_is_redirected_from_user_and_admin_dashboards(): void
    {
        $userDashboard = $this->request('GET', '/dashboard');
        $this->assertSame(302, $userDashboard['status']);
        $this->assertSame('/login', (string) parse_url($this->header($userDashboard, 'location'), PHP_URL_PATH));

        $adminDashboard = $this->request('GET', '/admin/dashboard');
        $this->assertSame(302, $adminDashboard['status']);
        $this->assertSame('/login', (string) parse_url($this->header($adminDashboard, 'location'), PHP_URL_PATH));
    }

    public function test_regular_user_session_cannot_access_admin_dashboard(): void
    {
        $login = $this->login('/login', 'user@chortke.ir', '123456');
        $this->assertSame('/dashboard', (string) parse_url($this->header($login, 'location'), PHP_URL_PATH));

        $adminDashboard = $this->request('GET', '/admin/dashboard');
        $this->assertSame(302, $adminDashboard['status']);
        $this->assertNotSame('/admin/dashboard', (string) parse_url($this->header($adminDashboard, 'location'), PHP_URL_PATH));
    }

    public function test_admin_and_support_roles_can_enter_the_real_admin_area(): void
    {
        foreach (['admin@chortke.ir', 'support@chortke.ir'] as $email) {
            if (is_file($this->cookieJar)) {
                file_put_contents($this->cookieJar, '');
            }
            $login = $this->login('/admin/login', $email, '123456');
            $this->assertSame(302, $login['status']);
            $this->assertSame('/admin/dashboard', (string) parse_url($this->header($login, 'location'), PHP_URL_PATH));

            $dashboard = $this->request('GET', '/admin/dashboard');
            $this->assertSame(200, $dashboard['status']);
            $this->assertStringContainsString('<title>داشبورد مدیریت', $dashboard['body']);
            $this->assertNoRuntimeDiagnostics($dashboard['body']);
        }
    }

    public function test_view_contract_renders_canonical_array_and_object_admin_pages_without_coercion(): void
    {
        $login = $this->login('/admin/login', 'superadmin@chortke.ir', '123456');
        $this->assertSame('/admin/dashboard', (string)parse_url($this->header($login, 'location'), PHP_URL_PATH));

        $pages = [
            '/admin/kpi' => 'داشبورد KPI',
            '/admin/kpi/financial' => 'آنالیتیکس مالی',
            '/admin/kpi/users' => 'آنالیتیکس کاربران',
            '/admin/roles/create' => 'ایجاد نقش جدید',
            '/admin/levels/create' => 'ایجاد سطح جدید',
            '/admin/api-tokens' => 'توکن‌های API',
            '/admin/audit-trail' => 'Audit Trail',
            '/admin/cache' => 'مدیریت Cache',
            '/admin/email-queue' => 'صف ایمیل',
            '/admin/backups' => 'مدیریت پشتیبان',
            '/admin/users/1/scores' => 'مدیریت امتیاز',
            '/admin/sentry' => 'Sentry Dashboard',
            '/admin/sentry/issues' => 'Sentry Issues',
            '/admin/sentry/failed-jobs' => 'Failed Jobs',
            '/admin/sentry/outbox-dlq' => 'Outbox DLQ',
            '/admin/sentry/performance' => 'Sentry Performance',
            '/admin/sentry/analytics' => 'Sentry Analytics',
            '/admin/sentry/alerts' => 'Sentry Alerts',
            '/admin/sentry/audit' => 'Sentry Audit Trail',
        ];
        foreach ($pages as $path => $expectedText) {
            $response = $this->request('GET', $path);
            $this->assertSame(200, $response['status'], "{$path} did not render through its canonical view-data contract.");
            $this->assertStringContainsString($expectedText, $response['body']);
            if (str_starts_with($path, '/admin/sentry')) {
                // Sentry pages intentionally render captured error text such as
                // SQLSTATE and exception names; only live PHP diagnostics are forbidden.
                $this->assertStringNotContainsString('<b>Warning</b>', $response['body']);
                $this->assertStringNotContainsString('<b>Fatal error</b>', $response['body']);
                $this->assertStringNotContainsString('Stack trace:</b>', $response['body']);
            } else {
                $this->assertNoRuntimeDiagnostics($response['body']);
            }
        }

    }

    public function test_logout_with_csrf_invalidates_the_authenticated_session(): void
    {
        $this->login('/login', 'user@chortke.ir', '123456');
        $dashboard = $this->request('GET', '/dashboard');
        $this->assertSame(200, $dashboard['status']);
        $csrf = $this->extractCsrfToken($dashboard['body']);

        $logout = $this->request('POST', '/logout', ['_csrf_token' => $csrf]);
        $this->assertSame(302, $logout['status']);
        $this->assertSame('/login', (string) parse_url($this->header($logout, 'location'), PHP_URL_PATH));

        $afterLogout = $this->request('GET', '/dashboard');
        $this->assertSame(302, $afterLogout['status']);
        $this->assertSame('/login', (string) parse_url($this->header($afterLogout, 'location'), PHP_URL_PATH));
    }

    public function test_login_without_csrf_is_rejected_before_authentication(): void
    {
        $this->request('GET', '/login');
        $response = $this->request('POST', '/login', [
            'email' => 'user@chortke.ir',
            'password' => '123456',
        ]);

        $this->assertContains($response['status'], [302, 403, 419]);
        $this->assertStringNotContainsString('/dashboard', $this->header($response, 'location'));

        $dashboard = $this->request('GET', '/dashboard');
        $this->assertSame(302, $dashboard['status'], 'CSRF rejection must not create an authenticated session.');
        $this->assertSame('/login', (string) parse_url($this->header($dashboard, 'location'), PHP_URL_PATH));
    }

    public function test_registration_creates_safe_user_wallet_outbox_and_email_verification_flow(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $suffix = bin2hex(random_bytes(5));
        $email = "runtime-register-{$suffix}@example.test";
        $username = "runtime_{$suffix}";
        $password = 'V3ry!Strong#Password2026';
        $userId = 0;

        try {
            $registerPage = $this->request('GET', '/register');
            $this->assertSame(200, $registerPage['status']);
            $csrf = $this->extractCsrfToken($registerPage['body']);
            $captcha = $this->fetchMathCaptcha();
            $response = $this->request('POST', '/register', [
                '_csrf_token' => $csrf,
                'captcha_token' => $captcha['token'],
                'captcha_response' => (string)$captcha['answer'],
                'full_name' => 'Runtime Contract User',
                'email' => $email,
                'username' => $username,
                'mobile' => '0912' . random_int(1000000, 9999999),
                'password' => $password,
                'password_confirmation' => $password,
                // Mass-assignment attempts must be ignored by Controller/UserService.
                'role' => 'super_admin',
                'is_admin' => '1',
                'status' => 'banned',
            ]);

            $this->assertSame(302, $response['status']);
            $this->assertSame('/email/verify-code', (string)parse_url($this->header($response, 'location'), PHP_URL_PATH));

            $user = $database->fetch('SELECT * FROM users WHERE email=?', [$email]);
            $this->assertNotNull($user);
            $userId = (int)$user->id;
            $this->assertSame('user', (string)$user->role);
            $this->assertSame(0, (int)$user->is_admin);
            $this->assertSame('active', (string)$user->status);
            $this->assertNull($user->email_verified_at);
            $this->assertNotSame($password, (string)$user->password);
            $this->assertTrue(verify_user_password($password, (string)$user->password));
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM wallets WHERE user_id=?', [$userId]));
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM outbox_events WHERE aggregate_type='auth' AND aggregate_id=? AND event_type='auth.register'", [(string)$userId]));

            $verification = $database->fetch("SELECT code,expires_at FROM user_verifications WHERE user_id=? AND type='email' ORDER BY id DESC LIMIT 1", [$userId]);
            $this->assertNotNull($verification);
            $this->assertRegExp('/^[A-F0-9]{6}$/', (string)$verification->code);
            $this->assertGreaterThan(time(), strtotime((string)$verification->expires_at));

            $verifyPage = $this->request('GET', '/email/verify-code');
            $this->assertSame(200, $verifyPage['status']);
            $verifyCsrf = $this->extractCsrfToken($verifyPage['body']);
            $verified = $this->request('POST', '/email/verify-code', [
                '_csrf_token' => $verifyCsrf,
                'code' => (string)$verification->code,
            ]);
            $this->assertSame(302, $verified['status']);
            $this->assertSame('/login', (string)parse_url($this->header($verified, 'location'), PHP_URL_PATH));
            $this->assertNotNull($database->fetchColumn('SELECT email_verified_at FROM users WHERE id=?', [$userId]));
        } finally {
            if ($userId > 0) {
                $database->query('DELETE FROM outbox_events WHERE aggregate_type=? AND aggregate_id=?', ['auth', (string)$userId]);
                $database->query('DELETE FROM user_verifications WHERE user_id=?', [$userId]);
                $database->query('DELETE FROM user_roles WHERE user_id=?', [$userId]);
                $database->query('DELETE FROM users WHERE id=?', [$userId]);
            }
            $database->query('DELETE FROM email_queue WHERE to_email=?', [$email]);
            $this->flushTestRedis();
        }
    }

    public function test_duplicate_registration_and_weak_password_create_no_user_or_side_effect(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $existingEmail = 'user@chortke.ir';
        $before = (int)$database->fetchColumn('SELECT COUNT(*) FROM users WHERE email=?', [$existingEmail]);
        $page = $this->request('GET', '/register');
        $csrf = $this->extractCsrfToken($page['body']);
        $captcha = $this->fetchMathCaptcha();

        $duplicate = $this->request('POST', '/register', [
            '_csrf_token' => $csrf,
            'captcha_token' => $captcha['token'],
            'captcha_response' => (string)$captcha['answer'],
            'full_name' => 'Duplicate Runtime User',
            'email' => $existingEmail,
            'username' => 'duplicate_' . bin2hex(random_bytes(3)),
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $this->assertSame(302, $duplicate['status']);
        $this->assertSame('/register', (string)parse_url($this->header($duplicate, 'location'), PHP_URL_PATH));
        $this->assertSame($before, (int)$database->fetchColumn('SELECT COUNT(*) FROM users WHERE email=?', [$existingEmail]));
    }

    public function test_password_reset_request_is_non_enumerating_and_token_is_one_time(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $email = 'user@chortke.ir';
        $originalHash = (string)$database->fetchColumn('SELECT password FROM users WHERE email=?', [$email]);
        $knownToken = bin2hex(random_bytes(32));
        $newPassword = 'N3w!Runtime#Password2026';

        try {
            $forgotPage = $this->request('GET', '/forgot-password');
            $this->assertSame(200, $forgotPage['status']);
            $csrf = $this->extractCsrfToken($forgotPage['body']);
            $requested = $this->request('POST', '/forgot-password', [
                '_csrf_token' => $csrf,
                'email' => $email,
            ]);
            $this->assertSame(302, $requested['status']);
            $this->assertSame('/login', (string)parse_url($this->header($requested, 'location'), PHP_URL_PATH));
            $stored = $database->fetch('SELECT token FROM password_resets WHERE email=?', [$email]);
            $this->assertNotNull($stored);
            $this->assertRegExp('/^[a-f0-9]{64}$/', (string)$stored->token);

            // Replace the generated token with a known HMAC, equivalent to the link delivered by email.
            $database->query(
                'UPDATE password_resets SET token=?,created_at=NOW() WHERE email=?',
                [hash_hmac('sha256', $knownToken, $this->runtimeApplicationKey()), $email]
            );

            $consumeLink = $this->request('GET', '/reset-password?token=' . rawurlencode($knownToken));
            $this->assertSame(302, $consumeLink['status']);
            $this->assertSame('/reset-password', (string)parse_url($this->header($consumeLink, 'location'), PHP_URL_PATH));

            $resetPage = $this->request('GET', '/reset-password');
            $this->assertSame(200, $resetPage['status']);
            $this->assertSame('no-referrer', strtolower($this->header($resetPage, 'referrer-policy')));
            $resetCsrf = $this->extractCsrfToken($resetPage['body']);
            $reset = $this->request('POST', '/reset-password', [
                '_csrf_token' => $resetCsrf,
                'token' => $knownToken,
                'password' => $newPassword,
                'password_confirm' => $newPassword,
            ]);
            $this->assertSame(302, $reset['status']);
            $this->assertSame('/login', (string)parse_url($this->header($reset, 'location'), PHP_URL_PATH));
            $newHash = (string)$database->fetchColumn('SELECT password FROM users WHERE email=?', [$email]);
            $this->assertNotSame($originalHash, $newHash);
            $this->assertTrue(verify_user_password($newPassword, $newHash));
            $this->assertSame(0, (int)$database->fetchColumn('SELECT COUNT(*) FROM password_resets WHERE email=?', [$email]));

            // Replay the consumed token must fail and must not rotate the password again.
            $replayPage = $this->request('GET', '/forgot-password');
            $replayCsrf = $this->extractCsrfToken($replayPage['body']);
            $replay = $this->request('POST', '/reset-password', [
                '_csrf_token' => $replayCsrf,
                'token' => $knownToken,
                'password' => 'An0ther!Runtime#Password',
                'password_confirm' => 'An0ther!Runtime#Password',
            ]);
            $this->assertSame(302, $replay['status']);
            $this->assertSame('/forgot-password', (string)parse_url($this->header($replay, 'location'), PHP_URL_PATH));
            $this->assertSame($newHash, (string)$database->fetchColumn('SELECT password FROM users WHERE email=?', [$email]));
        } finally {
            $database->query('UPDATE users SET password=?,remember_token=NULL,remember_expires_at=NULL WHERE email=?', [$originalHash, $email]);
            $database->query('DELETE FROM password_resets WHERE email=?', [$email]);
            $database->query('DELETE FROM email_queue WHERE to_email=? AND subject LIKE ?', [$email, '%بازیابی%']);
            $this->flushTestRedis();
        }
    }

    public function test_password_reset_response_is_identical_for_existing_and_unknown_email(): void
    {
        $this->flushTestRedis();
        $emails = ['user@chortke.ir', 'missing-' . bin2hex(random_bytes(5)) . '@example.test'];
        $locations = [];
        foreach ($emails as $email) {
            $page = $this->request('GET', '/forgot-password');
            $csrf = $this->extractCsrfToken($page['body']);
            $response = $this->request('POST', '/forgot-password', ['_csrf_token' => $csrf, 'email' => $email]);
            $this->assertSame(302, $response['status']);
            $locations[] = (string)parse_url($this->header($response, 'location'), PHP_URL_PATH);
        }
        $this->assertSame(['/login', '/login'], $locations, 'Password reset endpoint leaks account existence through redirects.');
        \Core\Application::getInstance()->container->make(\Core\Database::class)
            ->query('DELETE FROM password_resets WHERE email=?', ['user@chortke.ir']);
        $this->flushTestRedis();
    }

    public function test_full_http_two_factor_setup_login_replay_and_disable_flow(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        config_set('app.key', $this->runtimeApplicationKey());
        $twoFactor = \Core\Application::getInstance()->container->make(\App\Services\Auth\TwoFactorService::class);
        $original = $database->fetch(
            'SELECT two_factor_enabled,two_factor_secret,last_2fa_timeslice,remember_token,remember_expires_at FROM users WHERE id=1'
        );
        $this->assertNotNull($original);
        $database->query('DELETE FROM two_factor_codes WHERE user_id=1');
        $database->query(
            'UPDATE users SET two_factor_enabled=0,two_factor_secret=NULL,last_2fa_timeslice=NULL,remember_token=NULL,remember_expires_at=NULL WHERE id=1'
        );

        try {
            $login = $this->login('/login', 'user@chortke.ir', '123456');
            $this->assertSame('/dashboard', (string)parse_url($this->header($login, 'location'), PHP_URL_PATH));

            $confirm = $this->request('GET', '/two-factor');
            $this->assertSame(200, $confirm['status']);
            $confirmCsrf = $this->extractCsrfToken($confirm['body']);
            $authorized = $this->request('POST', '/two-factor/authorize', [
                '_csrf_token' => $confirmCsrf,
                'password' => '123456',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $authorized['status']);
            $authorizedPayload = $this->decodeJsonObject($authorized['body']);
            $this->assertTrue((bool)($authorizedPayload['success'] ?? false), $authorized['body']);

            $setup = $this->request('GET', '/two-factor');
            $this->assertSame(200, $setup['status']);
            $setupCsrf = $this->extractCsrfToken($setup['body']);
            $encryptedSecret = (string)$database->fetchColumn('SELECT two_factor_secret FROM users WHERE id=1');
            $this->assertNotSame('', $encryptedSecret);
            $plainSecret = $twoFactor->decryptSecret($encryptedSecret);
            $this->assertRegExp('/^[A-Z2-7]{32}$/', $plainSecret);
            $this->assertStringNotContainsString($plainSecret, $setup['body']);
            $this->assertStringNotContainsString('otpauth://', $setup['body']);

            $qr = $this->request('GET', '/two-factor/qr');
            $this->assertSame(200, $qr['status']);
            $this->assertStringContainsString('image/svg+xml', strtolower($this->header($qr, 'content-type')));
            $this->assertStringContainsString('no-store', strtolower($this->header($qr, 'cache-control')));
            $this->assertStringContainsString('<svg', $qr['body']);
            $this->assertStringNotContainsString($plainSecret, $qr['body']);
            $this->assertStringNotContainsString('otpauth://', $qr['body']);

            // Consume the previous accepted RFC6238 window during setup, leaving the
            // current window available for the first real login challenge.
            $enableCode = $this->totp($plainSecret, (int)floor(time() / 30) - 1);
            $enable = $this->request('POST', '/two-factor/enable', [
                '_csrf_token' => $setupCsrf,
                'code' => $enableCode,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $enable['status']);
            $enablePayload = $this->decodeJsonObject($enable['body']);
            $this->assertTrue((bool)($enablePayload['success'] ?? false), $enable['body']);
            $this->assertCount(8, $enablePayload['recovery_codes'] ?? []);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT two_factor_enabled FROM users WHERE id=1'));
            $this->assertSame(8, (int)$database->fetchColumn('SELECT COUNT(*) FROM two_factor_codes WHERE user_id=1 AND used=0'));
            $this->assertNull($database->fetchColumn('SELECT remember_token FROM users WHERE id=1'));

            file_put_contents($this->cookieJar, '');
            $stepUpLogin = $this->login('/login', 'user@chortke.ir', '123456');
            $this->assertSame('/verify-2fa', (string)parse_url($this->header($stepUpLogin, 'location'), PHP_URL_PATH));
            $blockedDashboard = $this->request('GET', '/dashboard');
            $this->assertSame(302, $blockedDashboard['status']);
            $this->assertSame('/verify-2fa', (string)parse_url($this->header($blockedDashboard, 'location'), PHP_URL_PATH));

            $verifyPage = $this->request('GET', '/verify-2fa');
            $this->assertSame(200, $verifyPage['status']);
            $verifyCsrf = $this->extractCsrfToken($verifyPage['body']);
            $loginCode = $this->totp($plainSecret, (int)floor(time() / 30));
            $verified = $this->request('POST', '/verify-2fa', [
                '_csrf_token' => $verifyCsrf,
                'code' => $loginCode,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $verified['status']);
            $verifiedPayload = $this->decodeJsonObject($verified['body']);
            $this->assertTrue((bool)($verifiedPayload['success'] ?? false), $verified['body']);
            $this->assertSame('/dashboard', (string)parse_url((string)($verifiedPayload['redirect'] ?? ''), PHP_URL_PATH));
            $this->assertSame(200, $this->request('GET', '/dashboard')['status']);
            $this->assertNotNull($database->fetchColumn('SELECT remember_token FROM users WHERE id=1'));
            $authenticatedCookies = file_get_contents($this->cookieJar);
            $this->assertIsString($authenticatedCookies);

            // A second password session must not be able to reuse the consumed TOTP slice.
            file_put_contents($this->cookieJar, '');
            $replayLogin = $this->login('/login', 'user@chortke.ir', '123456');
            $this->assertSame('/verify-2fa', (string)parse_url($this->header($replayLogin, 'location'), PHP_URL_PATH));
            $replayPage = $this->request('GET', '/verify-2fa');
            $replayCsrf = $this->extractCsrfToken($replayPage['body']);
            $replay = $this->request('POST', '/verify-2fa', [
                '_csrf_token' => $replayCsrf,
                'code' => $loginCode,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $replay['status']);
            $replayPayload = $this->decodeJsonObject($replay['body']);
            $this->assertFalse((bool)($replayPayload['success'] ?? true), 'A consumed TOTP was accepted by the HTTP step-up flow.');
            $this->assertSame(302, $this->request('GET', '/dashboard')['status']);

            // Restore the already authenticated browser session to verify password-gated disable.
            file_put_contents($this->cookieJar, $authenticatedCookies);
            $security = $this->request('GET', '/two-factor');
            $this->assertSame(200, $security['status']);
            $disableCsrf = $this->extractCsrfToken($security['body']);
            $wrongDisable = $this->request('POST', '/two-factor/disable', [
                '_csrf_token' => $disableCsrf,
                'password' => 'incorrect-password',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $wrongPayload = $this->decodeJsonObject($wrongDisable['body']);
            $this->assertFalse((bool)($wrongPayload['success'] ?? true));
            $this->assertSame(1, (int)$database->fetchColumn('SELECT two_factor_enabled FROM users WHERE id=1'));

            $disabled = $this->request('POST', '/two-factor/disable', [
                '_csrf_token' => $disableCsrf,
                'password' => '123456',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $disabled['status']);
            $disabledPayload = $this->decodeJsonObject($disabled['body']);
            $this->assertTrue((bool)($disabledPayload['success'] ?? false), $disabled['body']);
            $this->assertSame(0, (int)$database->fetchColumn('SELECT two_factor_enabled FROM users WHERE id=1'));
            $this->assertNull($database->fetchColumn('SELECT two_factor_secret FROM users WHERE id=1'));
            $this->assertNull($database->fetchColumn('SELECT remember_token FROM users WHERE id=1'));
            $this->assertSame(0, (int)$database->fetchColumn('SELECT COUNT(*) FROM two_factor_codes WHERE user_id=1'));
        } finally {
            $database->query('DELETE FROM two_factor_codes WHERE user_id=1');
            $database->query(
                'UPDATE users SET two_factor_enabled=?,two_factor_secret=?,last_2fa_timeslice=?,remember_token=?,remember_expires_at=? WHERE id=1',
                [$original->two_factor_enabled,$original->two_factor_secret,$original->last_2fa_timeslice,$original->remember_token,$original->remember_expires_at]
            );
            $database->query('DELETE FROM user_sessions WHERE user_id=1');
            config_set('app.key', 'testing-app-key-32-characters-long!!');
            $this->flushTestRedis();
        }
    }

    public function test_kyc_submission_persists_encrypted_identity_and_private_document(): void
    {
        $this->flushTestRedis();
        $this->login('/login', 'user@chortke.ir', '123456');
        $page = $this->request('GET', '/kyc/upload');
        $this->assertSame(200, $page['status']);
        $csrf = $this->extractCsrfToken($page['body']);
        $png = $this->createTempFile($this->onePixelPng());
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $database->query('DELETE FROM kyc_documents WHERE kyc_id IN (SELECT id FROM kyc_verifications WHERE user_id=1)');
        $database->query('DELETE FROM kyc_verifications WHERE user_id=1');
        $filename = '';

        try {
            $response = $this->request('POST', '/kyc/submit', [
                '_csrf_token' => $csrf,
                'national_code' => '0013547839',
                'birth_date' => '1990-01-01',
                'verification_image' => new \CURLFile($png, 'image/png', 'kyc-selfie.png'),
            ]);
            $this->assertSame(302, $response['status']);
            $this->assertSame('/kyc', (string)parse_url($this->header($response, 'location'), PHP_URL_PATH));

            $kyc = $database->fetch('SELECT * FROM kyc_verifications WHERE user_id=1');
            $this->assertNotNull($kyc);
            $this->assertContains((string)$kyc->status, ['pending','under_review']);
            $this->assertNotSame('0013547839', (string)$kyc->national_code);
            $this->assertNotSame('1990-01-01', (string)$kyc->birth_date);
            $this->assertSame(hash_hmac('sha256', '0013547839', $this->runtimeApplicationKey()), (string)$kyc->national_code_hash);
            $filename = basename((string)$kyc->verification_image);
            $this->assertRegExp('/^[a-f0-9]{24}\.png$/', $filename);
            $this->assertFileExists(base_path('storage/uploads/kyc/' . $filename));

            $status = $this->request('GET', '/kyc/status', [], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $status['status']);
            $statusPayload = $this->decodeJsonObject($status['body']);
            $this->assertTrue((bool)($statusPayload['success'] ?? false));
            $this->assertContains($statusPayload['kyc']['status'] ?? '', ['pending','under_review']);
        } finally {
            if ($filename !== '') @unlink(base_path('storage/uploads/kyc/' . $filename));
            $database->query('DELETE FROM kyc_documents WHERE kyc_id IN (SELECT id FROM kyc_verifications WHERE user_id=1)');
            $database->query('DELETE FROM kyc_verifications WHERE user_id=1');
            $database->query("UPDATE users SET kyc_status='unverified',kyc_level=0,kyc_verified_at=NULL WHERE id=1");
            @unlink($png);
            $this->flushTestRedis();
        }
    }

    public function test_kyc_idor_and_admin_review_verify_reject_lifecycle(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $this->assertSame(0, (int)$database->fetchColumn('SELECT COUNT(*) FROM kyc_verifications WHERE user_id IN (1,2)'), 'Seed KYC fixtures were not clean.');
        $userStates = $database->fetchAll('SELECT id,kyc_status,kyc_level,kyc_verified_at FROM users WHERE id IN (1,2) ORDER BY id');
        $outboxFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM outbox_events');
        $notificationFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM notifications');
        config_set('app.key', $this->runtimeApplicationKey());
        $encryption = new \Core\Encryption();
        $kycModel = \Core\Application::getInstance()->container->make(\App\Models\KYCVerification::class);
        $ownFile = 'e2e-kyc-own-' . bin2hex(random_bytes(6)) . '.png';
        $foreignFile = 'e2e-kyc-foreign-' . bin2hex(random_bytes(6)) . '.png';
        $ownPath = base_path('storage/uploads/kyc/' . $ownFile);
        $foreignPath = base_path('storage/uploads/kyc/' . $foreignFile);
        if (!is_dir(dirname($ownPath))) {
            mkdir(dirname($ownPath), 0770, true);
        }
        file_put_contents($ownPath, $this->onePixelPng());
        file_put_contents($foreignPath, $this->onePixelPng());
        $ownId = (int)$kycModel->create([
            'user_id' => 1,
            'verification_image' => $ownFile,
            'national_code' => $encryption->encrypt('0013547839', 'kyc.national_code'),
            'national_code_hash' => hash_hmac('sha256', '0013547839', $this->runtimeApplicationKey()),
            'birth_date' => $encryption->encrypt('1990-01-01', 'kyc.birth_date'),
            'status' => 'pending',
        ]);
        $foreignId = (int)$kycModel->create([
            'user_id' => 2,
            'verification_image' => $foreignFile,
            'national_code' => $encryption->encrypt('0084575949', 'kyc.national_code'),
            'national_code_hash' => hash_hmac('sha256', '0084575949', $this->runtimeApplicationKey()),
            'birth_date' => $encryption->encrypt('1991-01-01', 'kyc.birth_date'),
            'status' => 'pending',
        ]);
        $this->assertGreaterThan(0, $ownId);
        $this->assertGreaterThan(0, $foreignId);

        try {
            $this->login('/login', 'user@chortke.ir', '123456');
            $own = $this->request('GET', '/kyc/show/' . $ownId, [], ['Accept: application/json']);
            $this->assertSame(200, $own['status']);
            $ownPayload = $this->decodeJsonObject($own['body']);
            $this->assertTrue((bool)($ownPayload['success'] ?? false));
            $this->assertSame($ownId, (int)($ownPayload['kyc']['id'] ?? 0));
            $this->assertSame(1, (int)($ownPayload['kyc']['user_id'] ?? 0));
            $this->assertSame($ownFile, (string)($ownPayload['kyc']['verification_image'] ?? ''));

            $foreign = $this->request('GET', '/kyc/show/' . $foreignId, [], ['Accept: application/json']);
            $this->assertSame(403, $foreign['status']);
            $foreignPayload = $this->decodeJsonObject($foreign['body']);
            $this->assertFalse((bool)($foreignPayload['success'] ?? true));
            $this->assertStringNotContainsString($foreignFile, $foreign['body']);
            $this->assertStringNotContainsString('0084575949', $foreign['body']);

            file_put_contents($this->cookieJar, '');
            $adminLogin = $this->login('/admin/login', 'admin@chortke.ir', '123456');
            $this->assertSame('/admin/dashboard', (string)parse_url($this->header($adminLogin, 'location'), PHP_URL_PATH));

            $foreignReview = $this->request('GET', '/admin/kyc/review/' . $foreignId);
            $this->assertSame(200, $foreignReview['status']);
            $this->assertStringContainsString($foreignFile, $foreignReview['body']);
            $adminCsrf = $this->extractCsrfToken($foreignReview['body']);
            $locked = $database->fetch('SELECT status,under_review_by FROM kyc_verifications WHERE id=?', [$foreignId]);
            $this->assertSame('under_review', (string)$this->requireRow($locked)->status);
            $this->assertSame(3, (int)$this->requireRow($locked)->under_review_by);

            $verified = $this->request('POST', '/admin/kyc/verify/' . $foreignId, ['_csrf_token' => $adminCsrf], [
                'X-Requested-With: XMLHttpRequest', 'Accept: application/json',
            ]);
            $this->assertSame(200, $verified['status']);
            $verifiedPayload = $this->decodeJsonObject($verified['body']);
            $this->assertTrue((bool)($verifiedPayload['success'] ?? false), $verified['body']);
            $verifiedRow = $database->fetch('SELECT status,reviewed_by,verified_at,under_review_by FROM kyc_verifications WHERE id=?', [$foreignId]);
            $this->assertSame('verified', (string)$this->requireRow($verifiedRow)->status);
            $this->assertSame(3, (int)$this->requireRow($verifiedRow)->reviewed_by);
            $this->assertNotNull($this->requireRow($verifiedRow)->verified_at);
            $this->assertNull($this->requireRow($verifiedRow)->under_review_by);
            $verifiedUser = $database->fetch('SELECT kyc_status,kyc_level,kyc_verified_at FROM users WHERE id=2');
            $this->assertSame('verified', (string)$this->requireRow($verifiedUser)->kyc_status);
            $this->assertGreaterThanOrEqual(1, (int)$this->requireRow($verifiedUser)->kyc_level);
            $this->assertNotNull($this->requireRow($verifiedUser)->kyc_verified_at);

            $ownReview = $this->request('GET', '/admin/kyc/review/' . $ownId);
            $this->assertSame(200, $ownReview['status']);
            $rejectCsrf = $this->extractCsrfToken($ownReview['body']);
            $shortReason = $this->request('POST', '/admin/kyc/reject/' . $ownId, [
                '_csrf_token' => $rejectCsrf, 'reason' => 'short',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(422, $shortReason['status']);
            $this->assertSame('under_review', (string)$database->fetchColumn('SELECT status FROM kyc_verifications WHERE id=?', [$ownId]));

            $reason = 'Runtime rejection reason for lifecycle verification';
            $rejected = $this->request('POST', '/admin/kyc/reject/' . $ownId, [
                '_csrf_token' => $rejectCsrf, 'reason' => $reason,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $rejected['status']);
            $rejectedPayload = $this->decodeJsonObject($rejected['body']);
            $this->assertTrue((bool)($rejectedPayload['success'] ?? false), $rejected['body']);
            $rejectedRow = $database->fetch('SELECT status,reviewed_by,rejection_reason,under_review_by FROM kyc_verifications WHERE id=?', [$ownId]);
            $this->assertSame('rejected', (string)$this->requireRow($rejectedRow)->status);
            $this->assertSame(3, (int)$this->requireRow($rejectedRow)->reviewed_by);
            $this->assertSame($reason, (string)$this->requireRow($rejectedRow)->rejection_reason);
            $this->assertNull($this->requireRow($rejectedRow)->under_review_by);
            $this->assertSame('rejected', (string)$database->fetchColumn('SELECT kyc_status FROM users WHERE id=1'));

            $repeatVerify = $this->request('POST', '/admin/kyc/verify/' . $foreignId, ['_csrf_token' => $rejectCsrf], [
                'X-Requested-With: XMLHttpRequest', 'Accept: application/json',
            ]);
            $this->assertSame(400, $repeatVerify['status']);
            $this->assertSame('verified', (string)$database->fetchColumn('SELECT status FROM kyc_verifications WHERE id=?', [$foreignId]));
        } finally {
            @unlink($ownPath);
            @unlink($foreignPath);
            $database->query('DELETE FROM kyc_documents WHERE kyc_id IN (?,?)', [$ownId,$foreignId]);
            $database->query('DELETE FROM kyc_verifications WHERE id IN (?,?)', [$ownId,$foreignId]);
            foreach ($userStates as $state) {
                $database->query('UPDATE users SET kyc_status=?,kyc_level=?,kyc_verified_at=? WHERE id=?', [
                    $state->kyc_status,$state->kyc_level,$state->kyc_verified_at,$state->id,
                ]);
            }
            $database->query('DELETE FROM outbox_events WHERE id>? AND aggregate_type=? AND aggregate_id IN (?,?)', [$outboxFloor,'kyc',(string)$ownId,(string)$foreignId]);
            $database->query('DELETE FROM notifications WHERE id>? AND user_id IN (1,2)', [$notificationFloor]);
            config_set('app.key', 'testing-app-key-32-characters-long!!');
            $this->flushTestRedis();
        }
    }

    public function test_session_list_and_termination_enforce_ownership(): void
    {
        $this->login('/login', 'user@chortke.ir', '123456');
        $sessionsPage = $this->request('GET', '/sessions');
        $this->assertSame(200, $sessionsPage['status']);
        $csrf = $this->extractCsrfToken($sessionsPage['body']);
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $ownSessionId = 'e2e-own-' . bin2hex(random_bytes(6));
        $foreignSessionId = 'e2e-foreign-' . bin2hex(random_bytes(6));

        try {
            foreach ([[1,$ownSessionId],[2,$foreignSessionId]] as [$userId,$sessionId]) {
                $database->query(
                    "INSERT INTO user_sessions (user_id,session_id,ip_address,user_agent,device_type,browser,os,last_activity,created_at,updated_at,is_active)"
                    . " VALUES (?,?,'127.0.0.1','E2E Session','desktop','Runtime','Linux',NOW(),NOW(),NOW(),1)",
                    [$userId,$sessionId]
                );
            }
            $ownId = (int)$database->fetchColumn('SELECT id FROM user_sessions WHERE session_id=?', [$ownSessionId]);
            $foreignId = (int)$database->fetchColumn('SELECT id FROM user_sessions WHERE session_id=?', [$foreignSessionId]);

            $terminated = $this->request('POST', '/sessions/terminate/' . $ownId, ['_csrf_token'=>$csrf]);
            $this->assertSame(200, $terminated['status']);
            $payload = $this->decodeJsonObject($terminated['body']);
            $this->assertTrue((bool)($payload['success'] ?? false));
            $this->assertSame(0, (int)$database->fetchColumn('SELECT is_active FROM user_sessions WHERE id=?', [$ownId]));

            $denied = $this->request('POST', '/sessions/terminate/' . $foreignId, ['_csrf_token'=>$csrf]);
            $this->assertSame(400, $denied['status']);
            $deniedPayload = $this->decodeJsonObject($denied['body']);
            $this->assertFalse((bool)($deniedPayload['success'] ?? true));
            $this->assertSame(1, (int)$database->fetchColumn('SELECT is_active FROM user_sessions WHERE id=?', [$foreignId]));
        } finally {
            $database->query('DELETE FROM user_sessions WHERE session_id IN (?,?)', [$ownSessionId,$foreignSessionId]);
        }
    }

    public function test_direct_messages_enforce_participant_ownership_read_reaction_and_delete_contracts(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $previousConversation = $database->fetch('SELECT * FROM user_conversations WHERE user1_id=1 AND user2_id=2');
        $createdMessageId = 0;

        try {
            $this->login('/login', 'user@chortke.ir', '123456');
            $messagesPage = $this->request('GET', '/messages');
            $this->assertSame(200, $messagesPage['status']);
            $csrf = $this->extractCsrfToken($messagesPage['body']);
            $rawMessage = 'Runtime <script>alert("dm-xss")</script> participant contract';
            $sent = $this->request('POST', '/messages/send', [
                '_csrf_token' => $csrf,
                'recipient_id' => 2,
                'message' => $rawMessage,
                'is_encrypted' => '0',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $sent['status']);
            $sentPayload = $this->decodeJsonObject($sent['body']);
            $this->assertTrue((bool)($sentPayload['success'] ?? false), $sent['body']);
            $createdMessageId = (int)($sentPayload['data']['message_id'] ?? 0);
            $this->assertGreaterThan(0, $createdMessageId);

            $stored = $database->fetch('SELECT * FROM direct_messages WHERE id=?', [$createdMessageId]);
            $this->assertSame(1, (int)$this->requireRow($stored)->sender_id);
            $this->assertSame(2, (int)$this->requireRow($stored)->recipient_id);
            $this->assertStringNotContainsString('<script>', (string)$this->requireRow($stored)->message);
            $this->assertStringContainsString('&lt;script&gt;', (string)$this->requireRow($stored)->message);
            $this->assertSame($createdMessageId, (int)$database->fetchColumn('SELECT last_message_id FROM user_conversations WHERE user1_id=1 AND user2_id=2'));

            $selfSend = $this->request('POST', '/messages/send', [
                '_csrf_token' => $csrf,
                'recipient_id' => 1,
                'message' => 'This message to self must be rejected',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(422, $selfSend['status']);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM direct_messages WHERE id=?', [$createdMessageId]));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'support@chortke.ir', '123456');
            $unread = $this->request('GET', '/messages/unread/count', [], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $unread['status'], 'Static unread route was shadowed by /messages/{id}.');
            $unreadPayload = $this->decodeJsonObject($unread['body']);
            $this->assertSame(1, int_value($this->requireArray($unreadPayload['data'] ?? null)['count'] ?? 0));
            $typing = $this->request('GET', '/messages/typing/users', [], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $typing['status'], 'Static typing route was shadowed by /messages/{id}.');

            $conversation = $this->request('GET', '/messages/1');
            $this->assertSame(200, $conversation['status']);
            $this->assertStringNotContainsString('<script>', $conversation['body']);
            $this->assertStringContainsString('dm-xss', $conversation['body']);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT is_read FROM direct_messages WHERE id=?', [$createdMessageId]));
            $this->assertNotNull($database->fetchColumn('SELECT read_at FROM direct_messages WHERE id=?', [$createdMessageId]));
            $recipientCsrf = $this->extractCsrfToken($conversation['body']);

            $reaction = $this->request('POST', '/messages/' . $createdMessageId . '/reaction', [
                '_csrf_token' => $recipientCsrf,
                'emoji' => '👍',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $reaction['status']);
            $this->assertSame('👍', (string)$database->fetchColumn('SELECT emoji FROM message_reactions WHERE message_id=? AND user_id=2', [$createdMessageId]));

            $recipientDelete = $this->request('POST', '/messages/' . $createdMessageId . '/delete', [
                '_csrf_token' => $recipientCsrf,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(404, $recipientDelete['status']);
            $this->assertNull($database->fetchColumn('SELECT deleted_at FROM direct_messages WHERE id=?', [$createdMessageId]));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'user@chortke.ir', '123456');
            $senderConversation = $this->request('GET', '/messages/2');
            $senderCsrf = $this->extractCsrfToken($senderConversation['body']);
            $senderDelete = $this->request('POST', '/messages/' . $createdMessageId . '/delete', [
                '_csrf_token' => $senderCsrf,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $senderDelete['status']);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT deleted_by FROM direct_messages WHERE id=?', [$createdMessageId]));
            $afterDelete = $this->request('GET', '/messages/2');
            $this->assertStringNotContainsString('dm-xss', $afterDelete['body']);
        } finally {
            if ($createdMessageId > 0) {
                $database->query('DELETE FROM message_reactions WHERE message_id=?', [$createdMessageId]);
                $database->query('DELETE FROM message_attachments WHERE message_id=?', [$createdMessageId]);
                $database->query('DELETE FROM direct_messages WHERE id=?', [$createdMessageId]);
            }
            if ($previousConversation) {
                $database->query(
                    'INSERT INTO user_conversations (user1_id,user2_id,last_message_id,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE last_message_id=VALUES(last_message_id),updated_at=VALUES(updated_at)',
                    [$previousConversation->user1_id,$previousConversation->user2_id,$previousConversation->last_message_id,$previousConversation->updated_at]
                );
            } else {
                $database->query('DELETE FROM user_conversations WHERE user1_id=1 AND user2_id=2');
            }
            $this->flushTestRedis();
        }
    }

    public function test_ticket_user_idor_admin_reply_status_and_closed_transition(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $ticketId = 0;
        $outboxFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM outbox_events');

        try {
            $this->login('/login', 'user@chortke.ir', '123456');
            $createPage = $this->request('GET', '/tickets/create');
            $this->assertSame(200, $createPage['status']);
            $csrf = $this->extractCsrfToken($createPage['body']);
            $created = $this->request('POST', '/tickets/store', [
                '_csrf_token' => $csrf,
                'category_id' => 1,
                'subject' => 'Runtime ticket lifecycle contract',
                'message' => 'Initial <script>alert("ticket-xss")</script> support message',
                'priority' => 'normal',
                'idempotency_key' => 'e2e-ticket-' . bin2hex(random_bytes(8)),
            ]);
            $this->assertSame(302, $created['status']);
            $location = (string)parse_url($this->header($created, 'location'), PHP_URL_PATH);
            $this->assertRegExp('#^/tickets/show/\d+$#', $location);
            $ticketId = (int)basename($location);
            $this->assertGreaterThan(0, $ticketId);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT user_id FROM tickets WHERE id=?', [$ticketId]));
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM ticket_messages WHERE ticket_id=?', [$ticketId]));
            $initialMessage = (string)$database->fetchColumn('SELECT message FROM ticket_messages WHERE ticket_id=? ORDER BY id LIMIT 1', [$ticketId]);
            $this->assertStringNotContainsString('<script>', $initialMessage);
            $this->assertStringContainsString('ticket-xss', $initialMessage);

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'support@chortke.ir', '123456');
            $foreignShow = $this->request('GET', '/tickets/show/' . $ticketId);
            $this->assertSame(302, $foreignShow['status']);
            $this->assertSame('/tickets', (string)parse_url($this->header($foreignShow, 'location'), PHP_URL_PATH));
            $supportTickets = $this->request('GET', '/tickets');
            $supportCsrf = $this->extractCsrfToken($supportTickets['body']);
            $foreignReply = $this->request('POST', '/tickets/reply', [
                '_csrf_token' => $supportCsrf,
                'ticket_id' => $ticketId,
                'message' => 'Foreign user must not reply to this ticket',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(403, $foreignReply['status']);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM ticket_messages WHERE ticket_id=?', [$ticketId]));

            file_put_contents($this->cookieJar, '');
            $adminLogin = $this->login('/admin/login', 'admin@chortke.ir', '123456');
            $this->assertSame('/admin/dashboard', (string)parse_url($this->header($adminLogin, 'location'), PHP_URL_PATH));
            $adminShow = $this->request('GET', '/admin/tickets/show/' . $ticketId);
            $this->assertSame(200, $adminShow['status']);
            $adminCsrf = $this->extractCsrfToken($adminShow['body']);
            $adminReply = $this->request('POST', '/admin/tickets/reply', [
                '_csrf_token' => $adminCsrf,
                'ticket_id' => $ticketId,
                'message' => 'Runtime admin reply for unread lifecycle',
            ], ['Content-Type: application/json', 'X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $adminReply['status']);
            $adminReplyPayload = $this->decodeJsonObject($adminReply['body']);
            $this->assertTrue((bool)($adminReplyPayload['success'] ?? false), $adminReply['body']);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM ticket_messages WHERE ticket_id=? AND is_admin=1', [$ticketId]));
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM ticket_messages tm JOIN tickets t ON t.id=tm.ticket_id WHERE t.user_id=1 AND tm.is_admin=1 AND tm.is_read=0 AND t.id=?', [$ticketId]));

            $closed = $this->request('POST', '/admin/tickets/change-status', [
                '_csrf_token' => $adminCsrf,
                'id' => $ticketId,
                'status' => 'closed',
            ], ['Content-Type: application/json', 'X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $closed['status']);
            $closedPayload = $this->decodeJsonObject($closed['body']);
            $this->assertTrue((bool)($closedPayload['success'] ?? false), $closed['body']);
            $this->assertSame('closed', (string)$database->fetchColumn('SELECT status FROM tickets WHERE id=?', [$ticketId]));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'user@chortke.ir', '123456');
            $userTicket = $this->request('GET', '/tickets/show/' . $ticketId);
            $this->assertSame(200, $userTicket['status']);
            $this->assertStringContainsString('Runtime admin reply', $userTicket['body']);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT is_read FROM ticket_messages WHERE ticket_id=? AND is_admin=1', [$ticketId]));
            $userCsrf = $this->extractCsrfToken($userTicket['body']);
            $replyClosed = $this->request('POST', '/tickets/reply', [
                '_csrf_token' => $userCsrf,
                'ticket_id' => $ticketId,
                'message' => 'Reply after closure must not be accepted',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(403, $replyClosed['status']);
        } finally {
            if ($ticketId > 0) {
                $database->query('DELETE FROM ticket_messages WHERE ticket_id=?', [$ticketId]);
                $database->query('DELETE FROM tickets WHERE id=?', [$ticketId]);
                $database->query('DELETE FROM outbox_events WHERE id>? AND aggregate_type=? AND aggregate_id=?', [$outboxFloor,'ticket',(string)$ticketId]);
            }
            $database->query("DELETE FROM idempotency_keys WHERE action IN ('ticket.create','ticket.reply') AND user_id IN (1,3)");
            $this->flushTestRedis();
        }
    }

    public function test_notifications_enforce_ownership_read_archive_delete_and_analytics(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $ownId = 0;
        $foreignId = 0;
        try {
            $database->query("INSERT INTO notifications (user_id,type,title,message,priority,is_read,is_archived,is_deleted,channel,created_at) VALUES (1,'system','E2E own notification','Ownership contract','high',0,0,0,'in_app',NOW())");
            $ownId = (int)$database->lastInsertId();
            $database->query("INSERT INTO notifications (user_id,type,title,message,priority,is_read,is_archived,is_deleted,channel,created_at) VALUES (2,'system','E2E foreign notification','Must stay private','normal',0,0,0,'in_app',NOW())");
            $foreignId = (int)$database->lastInsertId();

            $this->login('/login', 'user@chortke.ir', '123456');
            $page = $this->request('GET', '/notifications');
            $this->assertSame(200, $page['status']);
            $this->assertStringContainsString('E2E own notification', $page['body']);
            $this->assertStringNotContainsString('E2E foreign notification', $page['body']);
            $csrf = $this->extractCsrfToken($page['body']);

            $get = $this->request('GET', '/notifications/get?unread=true', [], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $get['status']);
            $getPayload = $this->decodeJsonObject($get['body']);
            $ids = array_map(fn(mixed $row): int => int_value($this->requireArray($row)['id'] ?? 0), $this->requireArray($getPayload['notifications'] ?? null));
            $this->assertContains($ownId, $ids);
            $this->assertNotContains($foreignId, $ids);

            $foreignRead = $this->request('POST', '/notifications/mark-read', [
                '_csrf_token' => $csrf, 'notification_id' => $foreignId,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $foreignReadPayload = $this->decodeJsonObject($foreignRead['body']);
            $this->assertFalse((bool)($foreignReadPayload['success'] ?? true));
            $this->assertSame(0, (int)$database->fetchColumn('SELECT is_read FROM notifications WHERE id=?', [$foreignId]));

            $foreignArchive = $this->request('POST', '/notifications/archive', [
                '_csrf_token' => $csrf, 'notification_id' => $foreignId,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $foreignArchivePayload = $this->decodeJsonObject($foreignArchive['body']);
            $this->assertFalse((bool)($foreignArchivePayload['success'] ?? true), 'Foreign notification archive reported false success.');
            $this->assertSame(0, (int)$database->fetchColumn('SELECT is_archived FROM notifications WHERE id=?', [$foreignId]));

            $shownForeign = $this->request('POST', '/notifications/events/shown', [
                '_csrf_token' => $csrf, 'notification_id' => $foreignId, 'source' => 'e2e',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $shownPayload = $this->decodeJsonObject($shownForeign['body']);
            $this->assertFalse((bool)($shownPayload['success'] ?? true));
            $this->assertNull($database->fetchColumn('SELECT shown_at FROM notifications WHERE id=?', [$foreignId]));

            $ownRead = $this->request('POST', '/notifications/' . $ownId . '/mark-read', [
                '_csrf_token' => $csrf,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $ownReadPayload = $this->decodeJsonObject($ownRead['body']);
            $this->assertTrue((bool)($ownReadPayload['success'] ?? false));
            $this->assertSame(1, (int)$database->fetchColumn('SELECT is_read FROM notifications WHERE id=?', [$ownId]));
            $this->assertNotNull($database->fetchColumn('SELECT read_at FROM notifications WHERE id=?', [$ownId]));

            $ownArchive = $this->request('POST', '/notifications/archive', [
                '_csrf_token' => $csrf, 'notification_id' => $ownId,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $ownArchivePayload = $this->decodeJsonObject($ownArchive['body']);
            $this->assertTrue((bool)($ownArchivePayload['success'] ?? false));
            $this->assertSame(1, (int)$database->fetchColumn('SELECT is_archived FROM notifications WHERE id=?', [$ownId]));

            $foreignDelete = $this->request('POST', '/notifications/delete', [
                '_csrf_token' => $csrf, 'notification_id' => $foreignId,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $foreignDeletePayload = $this->decodeJsonObject($foreignDelete['body']);
            $this->assertFalse((bool)($foreignDeletePayload['success'] ?? true));
            $this->assertSame(0, (int)$database->fetchColumn('SELECT is_deleted FROM notifications WHERE id=?', [$foreignId]));

            $ownDelete = $this->request('POST', '/notifications/delete', [
                '_csrf_token' => $csrf, 'notification_id' => $ownId,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $ownDeletePayload = $this->decodeJsonObject($ownDelete['body']);
            $this->assertTrue((bool)($ownDeletePayload['success'] ?? false));
            $this->assertSame(1, (int)$database->fetchColumn('SELECT is_deleted FROM notifications WHERE id=?', [$ownId]));
            $this->assertNotNull($database->fetchColumn('SELECT deleted_at FROM notifications WHERE id=?', [$ownId]));
        } finally {
            if ($ownId > 0 || $foreignId > 0) {
                $database->query('DELETE FROM notifications WHERE id IN (?,?)', [$ownId,$foreignId]);
            }
            $this->flushTestRedis();
        }
    }

    public function test_fcm_token_http_boundary_validates_and_upserts_one_device_per_platform(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $original = $database->fetchAll('SELECT * FROM user_devices WHERE user_id=1');
        try {
            $database->query('DELETE FROM user_devices WHERE user_id=1');
            $this->login('/login', 'user@chortke.ir', '123456');
            $page = $this->request('GET', '/notifications');
            $csrf = $this->extractCsrfToken($page['body']);

            $invalid = $this->request('POST', '/notifications/fcm-token', [
                '_csrf_token'=>$csrf,'token'=>'short','platform'=>'web',
            ], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(422, $invalid['status']);
            $this->assertFalse((bool)($this->decodeJsonObject($invalid['body'])['success'] ?? true));
            $this->assertSame(0, (int)$database->fetchColumn('SELECT COUNT(*) FROM user_devices WHERE user_id=1'));

            $tokenOne = 'fcm-http-token-' . str_repeat('a',64);
            $saved = $this->request('POST', '/notifications/fcm-token', [
                '_csrf_token'=>$csrf,'token'=>$tokenOne,'platform'=>'web',
            ], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $saved['status'], $saved['body']);
            $this->assertTrue((bool)($this->decodeJsonObject($saved['body'])['success'] ?? false));
            $this->assertSame($tokenOne, (string)$database->fetchColumn("SELECT fcm_token FROM user_devices WHERE user_id=1 AND platform='web'"));

            $tokenTwo = 'fcm-http-token-' . str_repeat('b',64);
            $updated = $this->request('POST', '/notifications/fcm-token', [
                '_csrf_token'=>$csrf,'token'=>$tokenTwo,'platform'=>'web',
            ], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $updated['status']);
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM user_devices WHERE user_id=1 AND platform='web'"));
            $this->assertSame($tokenTwo, (string)$database->fetchColumn("SELECT fcm_token FROM user_devices WHERE user_id=1 AND platform='web'"));

            $badPlatform = $this->request('POST', '/notifications/fcm-token', [
                '_csrf_token'=>$csrf,'token'=>$tokenOne,'platform'=>'desktop',
            ], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(422, $badPlatform['status']);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM user_devices WHERE user_id=1'));
        } finally {
            $database->query('DELETE FROM user_devices WHERE user_id=1');
            foreach ($original as $row) {
                $database->query(
                    'INSERT INTO user_devices (id,user_id,fcm_token,platform,last_activity,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',
                    [$row->id,$row->user_id,$row->fcm_token,$row->platform,$row->last_activity,$row->created_at,$row->updated_at]
                );
            }
            $this->flushTestRedis();
        }
    }

    public function test_custom_task_submission_and_dispute_participant_lifecycle(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $adId = 0;
        $submissionId = 0;
        $disputeId = 0;
        $outboxFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM outbox_events');

        try {
            $database->query(
                "INSERT INTO ads (user_id,title,description,type,platform,task_type,price_per_task,total_budget,remaining_budget,total_count,remaining_count,pending_count,completed_count,status,is_active,proof_type,proof_schema,currency,deadline_hours,created_at,updated_at)"
                . " VALUES (2,'E2E custom task','Runtime custom-task contract','custom_task','other','custom',1000,5000,5000,5,5,0,0,'active',1,'text',?, 'irt',24,NOW(),NOW())",
                [json_encode(['proof_text' => ['required' => true, 'min' => 10]], JSON_UNESCAPED_UNICODE)]
            );
            $adId = (int)$database->lastInsertId();

            $this->login('/login', 'user@chortke.ir', '123456');
            $taskPage = $this->request('GET', '/custom-tasks/' . $adId);
            $this->assertSame(200, $taskPage['status']);
            $csrf = $this->extractCsrfToken($taskPage['body']);
            $started = $this->request('POST', '/custom-tasks/' . $adId . '/start', [
                '_csrf_token' => $csrf,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $started['status']);
            $startedPayload = $this->decodeJsonObject($started['body']);
            $this->assertTrue((bool)($startedPayload['success'] ?? false), $started['body']);
            $submissionId = (int)($startedPayload['submission_id'] ?? 0);
            $this->assertGreaterThan(0, $submissionId);
            $submission = $database->fetch('SELECT * FROM custom_task_submissions WHERE id=?', [$submissionId]);
            $this->assertSame(1, (int)$this->requireRow($submission)->worker_id);
            $this->assertSame('in_progress', (string)$this->requireRow($submission)->status);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT pending_count FROM ads WHERE id=?', [$adId]));

            $duplicateStart = $this->request('POST', '/custom-tasks/' . $adId . '/start', [
                '_csrf_token' => $csrf,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(422, $duplicateStart['status']);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM custom_task_submissions WHERE task_id=? AND worker_id=1', [$adId]));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'support@chortke.ir', '123456');
            $foreignProof = $this->request('GET', '/custom-tasks/submissions/' . $submissionId . '/proof');
            $this->assertSame(302, $foreignProof['status']);
            $supportTask = $this->request('GET', '/custom-tasks/' . $adId);
            $supportCsrf = $this->extractCsrfToken($supportTask['body']);
            $selfStart = $this->request('POST', '/custom-tasks/' . $adId . '/start', [
                '_csrf_token' => $supportCsrf,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(422, $selfStart['status']);

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'user@chortke.ir', '123456');
            $proofPage = $this->request('GET', '/custom-tasks/submissions/' . $submissionId . '/proof');
            $this->assertSame(200, $proofPage['status']);
            $proofCsrf = $this->extractCsrfToken($proofPage['body']);
            $proofText = 'Runtime proof text with sufficient behavioral detail';
            $submitted = $this->request('POST', '/custom-tasks/' . $submissionId . '/submit-proof', [
                '_csrf_token' => $proofCsrf,
                'proof_text' => $proofText,
                'idempotency_key' => 'e2e-custom-proof-' . bin2hex(random_bytes(6)),
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $submitted['status']);
            $submittedPayload = $this->decodeJsonObject($submitted['body']);
            $this->assertTrue((bool)($submittedPayload['success'] ?? false), $submitted['body']);
            $this->assertSame('submitted', (string)$database->fetchColumn('SELECT status FROM custom_task_submissions WHERE id=?', [$submissionId]));
            $this->assertSame($proofText, (string)$database->fetchColumn('SELECT proof_text FROM custom_task_submissions WHERE id=?', [$submissionId]));

            $repeatProof = $this->request('POST', '/custom-tasks/' . $submissionId . '/submit-proof', [
                '_csrf_token' => $proofCsrf,
                'proof_text' => $proofText,
                'idempotency_key' => 'e2e-custom-proof-repeat',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(422, $repeatProof['status']);

            // The advertiser rejection is an upstream transition; create that state
            // directly so this test can focus on worker dispute behavior.
            $database->query("UPDATE custom_task_submissions SET status='rejected',rejection_reason='Runtime advertiser rejection' WHERE id=?", [$submissionId]);
            $reason = 'Runtime custom task dispute with enough details';
            $opened = $this->request('POST', '/custom-tasks/submissions/' . $submissionId . '/dispute-action', [
                '_csrf_token' => $proofCsrf,
                'reason' => $reason,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $opened['status']);
            $openedPayload = $this->decodeJsonObject($opened['body']);
            $this->assertTrue((bool)($openedPayload['success'] ?? false), $opened['body']);
            $disputeId = (int)($openedPayload['dispute_id'] ?? 0);
            $this->assertGreaterThan(0, $disputeId);
            $dispute = $database->fetch('SELECT * FROM disputes WHERE id=?', [$disputeId]);
            $this->assertSame('custom_task_submission', (string)$this->requireRow($dispute)->ref_type);
            $this->assertSame(1, (int)$this->requireRow($dispute)->user_id);
            $this->assertSame(2, (int)$this->requireRow($dispute)->target_user_id);
            $this->assertSame('worker', (string)$this->requireRow($dispute)->role);
            $this->assertNotNull($this->requireRow($dispute)->peer_deadline);
            $this->assertSame('disputed', (string)$database->fetchColumn('SELECT status FROM custom_task_submissions WHERE id=?', [$submissionId]));
            $this->assertSame($disputeId, (int)$database->fetchColumn('SELECT dispute_id FROM custom_task_submissions WHERE id=?', [$submissionId]));
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM dispute_messages WHERE dispute_id=?', [$disputeId]));

            $duplicateDispute = $this->request('POST', '/custom-tasks/submissions/' . $submissionId . '/dispute-action', [
                '_csrf_token' => $proofCsrf,
                'reason' => $reason,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(422, $duplicateDispute['status']);
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM disputes WHERE ref_type='custom_task_submission' AND ref_id=?", [$submissionId]));

            $genericDispute = $this->request('GET', '/disputes/' . $disputeId);
            $this->assertSame(200, $genericDispute['status']);
            $disputeCsrf = $this->extractCsrfToken($genericDispute['body']);
            $workerReply = $this->request('POST', '/disputes/' . $disputeId . '/reply', [
                '_csrf_token' => $disputeCsrf,
                'message' => 'Worker participant reply',
            ]);
            $this->assertSame(302, $workerReply['status']);
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM dispute_messages WHERE dispute_id=? AND user_id=1 AND message='Worker participant reply'", [$disputeId]));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'support@chortke.ir', '123456');
            $advertiserView = $this->request('GET', '/disputes/' . $disputeId);
            $this->assertSame(200, $advertiserView['status']);
            $advertiserCsrf = $this->extractCsrfToken($advertiserView['body']);
            $advertiserReply = $this->request('POST', '/disputes/' . $disputeId . '/reply', [
                '_csrf_token' => $advertiserCsrf,
                'message' => 'Advertiser participant reply',
            ]);
            $this->assertSame(302, $advertiserReply['status']);
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM dispute_messages WHERE dispute_id=? AND user_id=2 AND message='Advertiser participant reply'", [$disputeId]));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'admin@chortke.ir', '123456');
            $foreignView = $this->request('GET', '/disputes/' . $disputeId);
            $this->assertSame(302, $foreignView['status']);
            $adminPage = $this->request('GET', '/dashboard');
            $adminCsrf = $this->extractCsrfToken($adminPage['body']);
            $beforeForeignReply = (int)$database->fetchColumn('SELECT COUNT(*) FROM dispute_messages WHERE dispute_id=?', [$disputeId]);
            $foreignReply = $this->request('POST', '/disputes/' . $disputeId . '/reply', [
                '_csrf_token' => $adminCsrf,
                'message' => 'Non participant reply must be denied',
            ]);
            $this->assertSame(302, $foreignReply['status']);
            $this->assertSame($beforeForeignReply, (int)$database->fetchColumn('SELECT COUNT(*) FROM dispute_messages WHERE dispute_id=?', [$disputeId]));

            $database->query("UPDATE disputes SET status='closed' WHERE id=?", [$disputeId]);
            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'user@chortke.ir', '123456');
            $closedView = $this->request('GET', '/disputes/' . $disputeId);
            $closedCsrf = $this->extractCsrfToken($closedView['body']);
            $beforeClosedReply = (int)$database->fetchColumn('SELECT COUNT(*) FROM dispute_messages WHERE dispute_id=?', [$disputeId]);
            $closedReply = $this->request('POST', '/disputes/' . $disputeId . '/reply', [
                '_csrf_token' => $closedCsrf,
                'message' => 'Closed dispute must reject new messages',
            ]);
            $this->assertSame(302, $closedReply['status']);
            $this->assertSame($beforeClosedReply, (int)$database->fetchColumn('SELECT COUNT(*) FROM dispute_messages WHERE dispute_id=?', [$disputeId]));
        } finally {
            if ($submissionId > 0) {
                $fixtureDisputes = $database->fetchAll(
                    "SELECT id FROM disputes WHERE ref_type='custom_task_submission' AND ref_id=?",
                    [$submissionId]
                );
                foreach ($fixtureDisputes as $fixtureDispute) {
                    $database->query('DELETE FROM dispute_messages WHERE dispute_id=?', [(int)$fixtureDispute->id]);
                    $database->query('DELETE FROM disputes WHERE id=?', [(int)$fixtureDispute->id]);
                }
            } elseif ($disputeId > 0) {
                $database->query('DELETE FROM dispute_messages WHERE dispute_id=?', [$disputeId]);
                $database->query('DELETE FROM disputes WHERE id=?', [$disputeId]);
            }
            if ($submissionId > 0) {
                $database->query('DELETE FROM custom_task_submissions WHERE id=?', [$submissionId]);
            }
            if ($adId > 0) {
                $database->query('DELETE FROM ads WHERE id=?', [$adId]);
            }
            $database->query("DELETE FROM idempotency_keys WHERE user_id=1 AND action IN ('dispute.open_custom_task','custom_task.submit_proof')");
            $database->query('DELETE FROM outbox_events WHERE id>? AND aggregate_id IN (?,?)', [$outboxFloor,(string)$submissionId,(string)$adId]);
            $this->flushTestRedis();
        }
    }

    public function test_social_task_start_ownership_behavior_submit_and_idempotent_replay(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $adId = 0;
        $executionId = 0;
        try {
            $database->query(
                "INSERT INTO ads (user_id,title,description,type,platform,task_type,target_url,link,price_per_task,total_budget,remaining_budget,total_count,remaining_count,pending_count,completed_count,status,is_active,currency,created_at,updated_at)"
                . " VALUES (2,'E2E social task','Runtime social-task contract','social_task','instagram','follow','https://example.test/social-target','https://example.test/social-target',500,2500,2500,5,5,0,0,'active',1,'irt',NOW(),NOW())"
            );
            $adId = (int)$database->lastInsertId();

            $this->login('/login', 'user@chortke.ir', '123456');
            $list = $this->request('GET', '/social-tasks');
            $this->assertSame(200, $list['status']);
            $this->assertStringContainsString('تسک‌های شبکه اجتماعی', $list['body']);
            $csrf = $this->extractCsrfToken($list['body']);
            // Path parameter must be sufficient; no duplicate task_id body field.
            $started = $this->request('POST', '/social-tasks/' . $adId . '/start', [
                '_csrf_token' => $csrf,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $started['status']);
            $startedPayload = $this->decodeJsonObject($started['body']);
            $this->assertTrue((bool)($startedPayload['success'] ?? false), $started['body']);
            $executionId = (int)($startedPayload['execution_id'] ?? 0);
            $this->assertGreaterThan(0, $executionId);
            $execution = $database->fetch('SELECT * FROM social_task_executions WHERE id=?', [$executionId]);
            $this->assertSame(1, (int)$this->requireRow($execution)->executor_id);
            $this->assertSame('in_progress', (string)$this->requireRow($execution)->status);
            $this->assertSame(4, (int)$database->fetchColumn('SELECT remaining_count FROM ads WHERE id=?', [$adId]));
            $this->assertSame(1, (int)$database->fetchColumn('SELECT pending_count FROM ads WHERE id=?', [$adId]));

            $repeatStart = $this->request('POST', '/social-tasks/' . $adId . '/start', [
                '_csrf_token' => $csrf,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(422, $repeatStart['status']);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM social_task_executions WHERE ad_id=? AND executor_id=1', [$adId]));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'support@chortke.ir', '123456');
            $foreignExecution = $this->request('GET', '/social-tasks/' . $executionId . '/execute');
            $this->assertSame(302, $foreignExecution['status']);
            $supportList = $this->request('GET', '/social-tasks');
            $supportCsrf = $this->extractCsrfToken($supportList['body']);
            $selfStart = $this->request('POST', '/social-tasks/' . $adId . '/start', [
                '_csrf_token' => $supportCsrf,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(422, $selfStart['status']);

            $foreignSubmit = $this->request('POST', '/social-tasks/' . $executionId . '/submit', [
                '_csrf_token' => $supportCsrf,
                'active_time' => 10,
                'behavior_signals' => ['tap_count' => 1],
            ], ['Content-Type: application/json', 'X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(422, $foreignSubmit['status']);
            $this->assertSame('in_progress', (string)$database->fetchColumn('SELECT status FROM social_task_executions WHERE id=?', [$executionId]));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'user@chortke.ir', '123456');
            $executePage = $this->request('GET', '/social-tasks/' . $executionId . '/execute');
            $this->assertSame(200, $executePage['status']);
            $executeCsrf = $this->extractCsrfToken($executePage['body']);
            $submitPayload = [
                '_csrf_token' => $executeCsrf,
                'active_time' => 22,
                'idempotency_key' => 'e2e-social-submit-' . bin2hex(random_bytes(6)),
                'behavior_signals' => [
                    'tap_count' => 1,
                    'scroll_count' => 0,
                    'swipe_count' => 0,
                    'touch_timing_variance' => 50,
                    'avg_action_delay_ms' => 100,
                    'session_duration' => 22,
                    'active_time' => 22,
                ],
            ];
            $submitted = $this->request('POST', '/social-tasks/' . $executionId . '/submit', $submitPayload, [
                'Content-Type: application/json', 'X-Requested-With: XMLHttpRequest', 'Accept: application/json',
            ]);
            $this->assertSame(200, $submitted['status']);
            $submittedPayload = $this->decodeJsonObject($submitted['body']);
            $this->assertTrue((bool)($submittedPayload['success'] ?? false), $submitted['body']);
            $this->assertSame('submitted', (string)($submittedPayload['status'] ?? ''));
            $scored = $database->fetch('SELECT status,decision,final_score,flag_review,submitted_at FROM social_task_executions WHERE id=?', [$executionId]);
            $this->assertSame('submitted', (string)$this->requireRow($scored)->status);
            $this->assertSame('manual_review', (string)$this->requireRow($scored)->decision);
            $this->assertGreaterThanOrEqual(45.0, (float)$this->requireRow($scored)->final_score);
            $this->assertLessThan(70.0, (float)$this->requireRow($scored)->final_score);
            $this->assertSame(1, (int)$this->requireRow($scored)->flag_review);
            $this->assertNotNull($this->requireRow($scored)->submitted_at);

            $replayed = $this->request('POST', '/social-tasks/' . $executionId . '/submit', $submitPayload, [
                'Content-Type: application/json', 'X-Requested-With: XMLHttpRequest', 'Accept: application/json',
            ]);
            $this->assertSame(200, $replayed['status']);
            $replayedPayload = $this->decodeJsonObject($replayed['body']);
            $this->assertSame($submittedPayload['status'] ?? null, $replayedPayload['status'] ?? null);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM social_task_executions WHERE id=?', [$executionId]));
        } finally {
            if ($executionId > 0) {
                $database->query('DELETE FROM social_task_executions WHERE id=?', [$executionId]);
            }
            if ($adId > 0) {
                $database->query('DELETE FROM ads WHERE id=?', [$adId]);
            }
            $database->query("DELETE FROM idempotency_keys WHERE action='social_task.complete' AND user_id IN (1,2)");
            $this->flushTestRedis();
        }
    }

    public function test_vitrine_escrow_dispute_ownership_and_admin_refund_lifecycle(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $listingId = 0;
        $disputeId = 0;
        $originalUsers = $database->fetchAll('SELECT id,kyc_status,kyc_level,kyc_verified_at FROM users WHERE id IN (1,2) ORDER BY id');
        $outsiderState = $database->fetch('SELECT role,is_admin FROM users WHERE id=4');
        $originalWallets = $database->fetchAll('SELECT user_id,balance_irt,balance_usdt,locked_irt,locked_usdt FROM wallets WHERE user_id IN (1,2) ORDER BY user_id');
        $txFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM transactions');
        $ledgerFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM ledger_entries');
        $outboxFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM outbox_events');
        $idemFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM idempotency_keys');
        $escrowFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM escrow_transactions');
        $auditFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM escrow_audit');

        try {
            $database->query("UPDATE users SET kyc_status='verified',kyc_level=1,kyc_verified_at=NOW() WHERE id IN (1,2)");
            $database->query("UPDATE wallets SET balance_usdt=1000,locked_usdt=0 WHERE user_id=1");
            $database->query("UPDATE wallets SET balance_usdt=0,locked_usdt=0 WHERE user_id=2");
            $database->query(
                "INSERT INTO vitrine_listings (user_id,seller_id,title,description,category,platform,listing_type,type,price,price_usdt,status,currency,member_count,created_at,updated_at)"
                . " VALUES (2,2,'E2E vitrine escrow','Runtime escrow listing','channel','telegram','sell','sell',100,100,'active','usdt',5000,NOW(),NOW())"
            );
            $listingId = (int)$database->lastInsertId();

            $this->login('/login', 'user@chortke.ir', '123456');
            $listingPage = $this->request('GET', '/vitrine/' . $listingId);
            $this->assertSame(200, $listingPage['status'], 'Vitrine route parameter was not resolved by the controller.');
            $this->assertStringContainsString('E2E vitrine escrow', $listingPage['body']);
            $csrf = $this->extractCsrfToken($listingPage['body']);
            $bought = $this->request('POST', '/vitrine/' . $listingId . '/buy', [
                '_csrf_token' => $csrf,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $bought['status']);
            $boughtPayload = $this->decodeJsonObject($bought['body']);
            $this->assertTrue((bool)($boughtPayload['success'] ?? false), $bought['body']);

            $listing = $database->fetch('SELECT * FROM vitrine_listings WHERE id=?', [$listingId]);
            $this->assertSame('in_escrow', (string)$this->requireRow($listing)->status);
            $this->assertSame(1, (int)$this->requireRow($listing)->buyer_id);
            $escrow = $database->fetch("SELECT * FROM escrow_transactions WHERE order_id=? AND order_type='vitrine_listing'", [(string)$listingId]);
            $this->assertNotNull($escrow);
            $this->assertSame('in_escrow', (string)$escrow->status);
            $this->assertSame(1, (int)$escrow->buyer_id);
            $this->assertSame(2, (int)$escrow->seller_id);
            $this->assertSame('100.00000000', number_format((float)$escrow->amount, 8, '.', ''));
            $buyerWallet = $database->fetch('SELECT balance_usdt,locked_usdt FROM wallets WHERE user_id=1');
            $this->assertSame('900.00000000', number_format((float)$this->requireRow($buyerWallet)->balance_usdt, 8, '.', ''));
            $this->assertSame('100.00000000', number_format((float)$this->requireRow($buyerWallet)->locked_usdt, 8, '.', ''));

            $repeatBuy = $this->request('POST', '/vitrine/' . $listingId . '/buy', [
                '_csrf_token' => $csrf,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $repeatPayload = $this->decodeJsonObject($repeatBuy['body']);
            $this->assertFalse((bool)($repeatPayload['success'] ?? true));
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM escrow_transactions WHERE order_id=? AND order_type='vitrine_listing'", [(string)$listingId]));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'support@chortke.ir', '123456');
            $sellerPage = $this->request('GET', '/vitrine/' . $listingId);
            $sellerCsrf = $this->extractCsrfToken($sellerPage['body']);
            $sellerConfirm = $this->request('POST', '/vitrine/' . $listingId . '/confirm', [
                '_csrf_token' => $sellerCsrf,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $sellerConfirmPayload = $this->decodeJsonObject($sellerConfirm['body']);
            $this->assertFalse((bool)($sellerConfirmPayload['success'] ?? true));
            $this->assertSame('in_escrow', (string)$database->fetchColumn('SELECT status FROM vitrine_listings WHERE id=?', [$listingId]));

            $sellerDispute = $this->request('POST', '/vitrine/' . $listingId . '/dispute', [
                '_csrf_token' => $sellerCsrf,
                'reason' => 'Runtime seller dispute keeps escrow frozen safely',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $sellerDisputePayload = $this->decodeJsonObject($sellerDispute['body']);
            $this->assertTrue((bool)($sellerDisputePayload['success'] ?? false), $sellerDispute['body']);
            $this->assertSame('disputed', (string)$database->fetchColumn('SELECT status FROM vitrine_listings WHERE id=?', [$listingId]));
            $this->assertSame('disputed', (string)$database->fetchColumn('SELECT status FROM escrow_transactions WHERE id=?', [$escrow->id]));
            $dispute = $database->fetch("SELECT * FROM disputes WHERE ref_type='vitrine_listing' AND ref_id=?", [$listingId]);
            $this->assertNotNull($dispute);
            $disputeId = (int)$dispute->id;
            $this->assertSame(2, (int)$dispute->user_id);
            $this->assertSame(1, (int)$dispute->target_user_id);

            $sellerMessage = $this->request('POST', '/vitrine/' . $listingId . '/dispute/message', [
                '_csrf_token' => $sellerCsrf,
                'message' => 'Seller vitrine dispute evidence',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $sellerMessagePayload = $this->decodeJsonObject($sellerMessage['body']);
            $this->assertTrue((bool)($sellerMessagePayload['success'] ?? false));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'user@chortke.ir', '123456');
            $buyerDisputePage = $this->request('GET', '/vitrine/' . $listingId . '/dispute/messages', [], ['Accept: application/json']);
            $this->assertSame(200, $buyerDisputePage['status']);
            $buyerMessages = $this->decodeJsonObject($buyerDisputePage['body']);
            $this->assertCount(1, $this->requireArray($buyerMessages['messages'] ?? null));
            $buyerDashboard = $this->request('GET', '/dashboard');
            $buyerCsrf = $this->extractCsrfToken($buyerDashboard['body']);
            $buyerMessage = $this->request('POST', '/vitrine/' . $listingId . '/dispute/message', [
                '_csrf_token' => $buyerCsrf,
                'message' => 'Buyer vitrine dispute response',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $buyerMessagePayload = $this->decodeJsonObject($buyerMessage['body']);
            $this->assertTrue((bool)($buyerMessagePayload['success'] ?? false));
            $this->assertSame(2, (int)$database->fetchColumn('SELECT COUNT(*) FROM dispute_messages WHERE dispute_id=?', [$disputeId]));

            // Demote a seed administrator only for this fixture to exercise a true
            // non-participant user path; restore it in finally.
            $database->query("UPDATE users SET role='user',is_admin=0 WHERE id=4");
            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'superadmin@chortke.ir', '123456');
            $foreignMessages = $this->request('GET', '/vitrine/' . $listingId . '/dispute/messages', [], ['Accept: application/json']);
            $foreignMessagesPayload = $this->decodeJsonObject($foreignMessages['body']);
            $this->assertSame([], $foreignMessagesPayload['messages'] ?? null, 'Vitrine dispute messages leaked to a non-participant.');
            $foreignDashboard = $this->request('GET', '/dashboard');
            $foreignCsrf = $this->extractCsrfToken($foreignDashboard['body']);
            $beforeForeign = (int)$database->fetchColumn('SELECT COUNT(*) FROM dispute_messages WHERE dispute_id=?', [$disputeId]);
            $foreignMessage = $this->request('POST', '/vitrine/' . $listingId . '/dispute/message', [
                '_csrf_token' => $foreignCsrf,
                'message' => 'Vitrine outsider must not join dispute',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $foreignMessagePayload = $this->decodeJsonObject($foreignMessage['body']);
            $this->assertFalse((bool)($foreignMessagePayload['success'] ?? true));
            $this->assertSame($beforeForeign, (int)$database->fetchColumn('SELECT COUNT(*) FROM dispute_messages WHERE dispute_id=?', [$disputeId]));

            file_put_contents($this->cookieJar, '');
            $adminLogin = $this->login('/admin/login', 'admin@chortke.ir', '123456');
            $this->assertSame('/admin/dashboard', (string)parse_url($this->header($adminLogin, 'location'), PHP_URL_PATH));
            $adminPage = $this->request('GET', '/admin/dashboard');
            $adminCsrf = $this->extractCsrfToken($adminPage['body']);
            $resolved = $this->request('POST', '/admin/vitrine/' . $listingId . '/resolve', [
                '_csrf_token' => $adminCsrf,
                'csrf_token' => $adminCsrf,
                'winner' => 'buyer',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $resolved['status']);
            $resolvedPayload = $this->decodeJsonObject($resolved['body']);
            $this->assertTrue((bool)($resolvedPayload['success'] ?? false), $resolved['body']);
            $this->assertSame('cancelled', (string)$database->fetchColumn('SELECT status FROM vitrine_listings WHERE id=?', [$listingId]));
            $this->assertSame('refunded', (string)$database->fetchColumn('SELECT status FROM escrow_transactions WHERE id=?', [$escrow->id]));
            $this->assertSame('resolved_admin', (string)$database->fetchColumn('SELECT status FROM disputes WHERE id=?', [$disputeId]));
            $refundedWallet = $database->fetch('SELECT balance_usdt,locked_usdt FROM wallets WHERE user_id=1');
            $this->assertSame('1000.00000000', number_format((float)$this->requireRow($refundedWallet)->balance_usdt, 8, '.', ''));
            $this->assertSame('0.00000000', number_format((float)$this->requireRow($refundedWallet)->locked_usdt, 8, '.', ''));

            $repeatResolution = $this->request('POST', '/admin/vitrine/' . $listingId . '/resolve', [
                '_csrf_token' => $adminCsrf,
                'csrf_token' => $adminCsrf,
                'winner' => 'buyer',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $repeatResolutionPayload = $this->decodeJsonObject($repeatResolution['body']);
            $this->assertFalse((bool)($repeatResolutionPayload['success'] ?? true));
            $this->assertSame('1000.00000000', number_format((float)$database->fetchColumn('SELECT balance_usdt FROM wallets WHERE user_id=1'), 8, '.', ''));
        } finally {
            if ($disputeId > 0) {
                $database->query('DELETE FROM dispute_messages WHERE dispute_id=?', [$disputeId]);
                $database->query('DELETE FROM disputes WHERE id=?', [$disputeId]);
            }
            if ($listingId > 0) {
                $database->query('DELETE FROM vitrine_requests WHERE listing_id=?', [$listingId]);
                $database->query('DELETE FROM vitrine_watchlist WHERE listing_id=?', [$listingId]);
                $database->query('DELETE FROM vitrine_listings WHERE id=?', [$listingId]);
            }
            $database->query('DELETE FROM escrow_audit WHERE id>?', [$auditFloor]);
            $database->query('DELETE FROM escrow_transactions WHERE id>?', [$escrowFloor]);
            $database->query('DELETE FROM ledger_entries WHERE id>?', [$ledgerFloor]);
            $database->query('DELETE FROM transactions WHERE id>?', [$txFloor]);
            $database->query('DELETE FROM outbox_events WHERE id>?', [$outboxFloor]);
            $database->query('DELETE FROM idempotency_keys WHERE id>?', [$idemFloor]);
            foreach ($originalWallets as $wallet) {
                $database->query('UPDATE wallets SET balance_irt=?,balance_usdt=?,locked_irt=?,locked_usdt=? WHERE user_id=?', [
                    $wallet->balance_irt,$wallet->balance_usdt,$wallet->locked_irt,$wallet->locked_usdt,$wallet->user_id,
                ]);
            }
            foreach ($originalUsers as $user) {
                $database->query('UPDATE users SET kyc_status=?,kyc_level=?,kyc_verified_at=? WHERE id=?', [
                    $user->kyc_status,$user->kyc_level,$user->kyc_verified_at,$user->id,
                ]);
            }
            if ($outsiderState) {
                $database->query('UPDATE users SET role=?,is_admin=? WHERE id=4', [$outsiderState->role,$outsiderState->is_admin]);
            }
            $this->flushTestRedis();
        }
    }

    public function test_influencer_order_escrow_proof_dispute_and_admin_refund_lifecycle(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $profileId = 0;
        $orderId = 0;
        $disputeId = 0;
        $originalWallets = $database->fetchAll('SELECT user_id,balance_irt,balance_usdt,locked_irt,locked_usdt FROM wallets WHERE user_id IN (1,2) ORDER BY user_id');
        $txFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM transactions');
        $ledgerFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM ledger_entries');
        $outboxFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM outbox_events');
        $idemFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM idempotency_keys');
        $escrowFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM escrow_transactions');
        $auditFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM escrow_audit');

        try {
            $database->query('UPDATE wallets SET balance_irt=1000000,locked_irt=0 WHERE user_id=1');
            $database->query('UPDATE wallets SET balance_irt=0,locked_irt=0 WHERE user_id=2');
            $database->query(
                "INSERT INTO influencer_profiles (user_id,username,platform,status,is_active,follower_count,followers_count,story_price_24h,price_story,currency,total_orders,created_at,updated_at)"
                . " VALUES (2,'runtime_influencer','instagram','verified',1,50000,50000,10000,10000,'irt',0,NOW(),NOW())"
            );
            $profileId = (int)$database->lastInsertId();

            $this->login('/login', 'user@chortke.ir', '123456');
            $market = $this->request('GET', '/influencer');
            $this->assertSame(200, $market['status']);
            $this->assertStringContainsString('runtime_influencer', $market['body']);
            $csrf = $this->extractCsrfToken($market['body']);
            $created = $this->request('POST', '/influencer/ads/store', [
                '_csrf_token' => $csrf,
                'influencer_id' => $profileId,
                'order_type' => 'story',
                'duration_hours' => 24,
                'caption' => 'Runtime influencer order contract',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $created['status']);
            $createdPayload = $this->decodeJsonObject($created['body']);
            $this->assertTrue((bool)($createdPayload['success'] ?? false), $created['body']);
            $orderId = (int)($createdPayload['order']['id'] ?? 0);
            $this->assertGreaterThan(0, $orderId);
            $order = $database->fetch('SELECT * FROM story_orders WHERE id=?', [$orderId]);
            $this->assertSame('pending_acceptance', (string)$this->requireRow($order)->status);
            $this->assertSame(1, (int)$this->requireRow($order)->customer_id);
            $this->assertSame(2, (int)$this->requireRow($order)->influencer_user_id);
            $this->assertSame('10000.00000000', number_format((float)$this->requireRow($order)->price, 8, '.', ''));
            $escrow = $database->fetch("SELECT * FROM escrow_transactions WHERE order_id=? AND order_type='influencer_order'", [(string)$orderId]);
            $this->assertNotNull($escrow);
            $this->assertSame('in_escrow', (string)$escrow->status);
            $heldWallet = $database->fetch('SELECT balance_irt,locked_irt FROM wallets WHERE user_id=1');
            $this->assertSame('990000.00000000', number_format((float)$this->requireRow($heldWallet)->balance_irt, 8, '.', ''));
            $this->assertSame('10000.00000000', number_format((float)$this->requireRow($heldWallet)->locked_irt, 8, '.', ''));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'admin@chortke.ir', '123456');
            $outsider = $this->request('GET', '/influencer');
            $outsiderCsrf = $this->extractCsrfToken($outsider['body']);
            $foreignAccept = $this->request('POST', '/influencer/orders/' . $orderId . '/respond', [
                '_csrf_token' => $outsiderCsrf,
                'action' => 'accept',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $foreignAcceptPayload = $this->decodeJsonObject($foreignAccept['body']);
            $this->assertFalse((bool)($foreignAcceptPayload['success'] ?? true));
            $this->assertSame('pending_acceptance', (string)$database->fetchColumn('SELECT status FROM story_orders WHERE id=?', [$orderId]));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'support@chortke.ir', '123456');
            $influencerHub = $this->request('GET', '/influencer');
            $influencerCsrf = $this->extractCsrfToken($influencerHub['body']);
            $accepted = $this->request('POST', '/influencer/orders/' . $orderId . '/respond', [
                '_csrf_token' => $influencerCsrf,
                'action' => 'accept',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $acceptedPayload = $this->decodeJsonObject($accepted['body']);
            $this->assertTrue((bool)($acceptedPayload['success'] ?? false), $accepted['body']);
            $this->assertSame('accepted', (string)$database->fetchColumn('SELECT status FROM story_orders WHERE id=?', [$orderId]));

            $proof = $this->request('POST', '/influencer/orders/' . $orderId . '/proof', [
                '_csrf_token' => $influencerCsrf,
                'proof_link' => 'https://example.test/runtime-proof',
                'proof_notes' => 'Runtime publication completed',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $proofPayload = $this->decodeJsonObject($proof['body']);
            $this->assertTrue((bool)($proofPayload['success'] ?? false), $proof['body']);
            $proofOrder = $database->fetch('SELECT status,proof_link,proof_submitted_at,buyer_check_deadline FROM story_orders WHERE id=?', [$orderId]);
            $this->assertSame('awaiting_buyer_check', (string)$this->requireRow($proofOrder)->status);
            $this->assertSame('https://example.test/runtime-proof', (string)$this->requireRow($proofOrder)->proof_link);
            $this->assertNotNull($this->requireRow($proofOrder)->proof_submitted_at);
            $this->assertNotNull($this->requireRow($proofOrder)->buyer_check_deadline);

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'user@chortke.ir', '123456');
            $buyerHub = $this->request('GET', '/influencer');
            $buyerCsrf = $this->extractCsrfToken($buyerHub['body']);
            $disputed = $this->request('POST', '/influencer/ads/orders/' . $orderId . '/dispute', [
                '_csrf_token' => $buyerCsrf,
                'reason' => 'Runtime buyer dispute freezes influencer escrow',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $disputedPayload = $this->decodeJsonObject($disputed['body']);
            $this->assertTrue((bool)($disputedPayload['success'] ?? false), $disputed['body']);
            $this->assertSame('dispute', (string)$database->fetchColumn('SELECT status FROM story_orders WHERE id=?', [$orderId]));
            $this->assertSame('disputed', (string)$database->fetchColumn('SELECT status FROM escrow_transactions WHERE id=?', [$escrow->id]));
            $dispute = $database->fetch("SELECT * FROM disputes WHERE ref_type='influencer_order' AND ref_id=?", [$orderId]);
            $this->assertNotNull($dispute);
            $disputeId = (int)$dispute->id;
            $this->assertSame(1, (int)$dispute->user_id);
            $this->assertSame(2, (int)$dispute->target_user_id);

            $buyerMessage = $this->request('POST', '/influencer/orders/' . $orderId . '/dispute/message', [
                '_csrf_token' => $buyerCsrf,
                'message' => 'Buyer influencer dispute evidence',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $buyerMessagePayload = $this->decodeJsonObject($buyerMessage['body']);
            $this->assertTrue((bool)($buyerMessagePayload['success'] ?? false));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'admin@chortke.ir', '123456');
            $outsiderHub = $this->request('GET', '/influencer');
            $outsiderCsrf = $this->extractCsrfToken($outsiderHub['body']);
            $beforeOutsider = (int)$database->fetchColumn('SELECT COUNT(*) FROM dispute_messages WHERE dispute_id=?', [$disputeId]);
            $outsiderMessage = $this->request('POST', '/influencer/orders/' . $orderId . '/dispute/message', [
                '_csrf_token' => $outsiderCsrf,
                'message' => 'Outsider must not enter participant dispute',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $outsiderMessagePayload = $this->decodeJsonObject($outsiderMessage['body']);
            $this->assertFalse((bool)($outsiderMessagePayload['success'] ?? true));
            $this->assertSame($beforeOutsider, (int)$database->fetchColumn('SELECT COUNT(*) FROM dispute_messages WHERE dispute_id=?', [$disputeId]));

            file_put_contents($this->cookieJar, '');
            $adminLogin = $this->login('/admin/login', 'admin@chortke.ir', '123456');
            $this->assertSame('/admin/dashboard', (string)parse_url($this->header($adminLogin, 'location'), PHP_URL_PATH));
            $adminPage = $this->request('GET', '/admin/dashboard');
            $adminCsrf = $this->extractCsrfToken($adminPage['body']);
            $resolved = $this->request('POST', '/admin/influencer/disputes/' . $disputeId . '/resolve', [
                '_csrf_token' => $adminCsrf,
                'dispute_id' => $disputeId,
                'verdict' => 'favor_customer',
                'note' => 'Runtime administrator refunds buyer after evidence review',
                'refund_percent' => '100',
            ], ['Content-Type: application/json', 'X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $resolved['status']);
            $resolvedPayload = $this->decodeJsonObject($resolved['body']);
            $this->assertTrue((bool)($resolvedPayload['success'] ?? false), $resolved['body']);
            $this->assertSame('refunded', (string)$database->fetchColumn('SELECT status FROM story_orders WHERE id=?', [$orderId]));
            $this->assertSame('refunded', (string)$database->fetchColumn('SELECT status FROM escrow_transactions WHERE id=?', [$escrow->id]));
            $this->assertSame('resolved_admin', (string)$database->fetchColumn('SELECT status FROM disputes WHERE id=?', [$disputeId]));
            $refundedWallet = $database->fetch('SELECT balance_irt,locked_irt FROM wallets WHERE user_id=1');
            $this->assertSame('1000000.00000000', number_format((float)$this->requireRow($refundedWallet)->balance_irt, 8, '.', ''));
            $this->assertSame('0.00000000', number_format((float)$this->requireRow($refundedWallet)->locked_irt, 8, '.', ''));

            $replayed = $this->request('POST', '/admin/influencer/disputes/' . $disputeId . '/resolve', [
                '_csrf_token' => $adminCsrf,
                'dispute_id' => $disputeId,
                'verdict' => 'favor_customer',
                'note' => 'Runtime repeated resolution must not pay twice',
                'refund_percent' => '100',
            ], ['Content-Type: application/json', 'X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(422, $replayed['status']);
            $this->assertSame('1000000.00000000', number_format((float)$database->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id=1'), 8, '.', ''));
        } finally {
            if ($disputeId > 0) {
                $database->query('DELETE FROM dispute_messages WHERE dispute_id=?', [$disputeId]);
                $database->query('DELETE FROM disputes WHERE id=?', [$disputeId]);
            }
            if ($orderId > 0) {
                $database->query('DELETE FROM story_orders WHERE id=?', [$orderId]);
            }
            if ($profileId > 0) {
                $database->query('DELETE FROM influencer_verifications WHERE profile_id=? OR influencer_id=?', [$profileId,$profileId]);
                $database->query('DELETE FROM influencer_profiles WHERE id=?', [$profileId]);
            }
            $database->query('DELETE FROM escrow_audit WHERE id>?', [$auditFloor]);
            $database->query('DELETE FROM escrow_transactions WHERE id>?', [$escrowFloor]);
            $database->query('DELETE FROM ledger_entries WHERE id>?', [$ledgerFloor]);
            $database->query('DELETE FROM transactions WHERE id>?', [$txFloor]);
            $database->query('DELETE FROM outbox_events WHERE id>?', [$outboxFloor]);
            $database->query('DELETE FROM idempotency_keys WHERE id>?', [$idemFloor]);
            foreach ($originalWallets as $wallet) {
                $database->query('UPDATE wallets SET balance_irt=?,balance_usdt=?,locked_irt=?,locked_usdt=? WHERE user_id=?', [
                    $wallet->balance_irt,$wallet->balance_usdt,$wallet->locked_irt,$wallet->locked_usdt,$wallet->user_id,
                ]);
            }
            $this->flushTestRedis();
        }
    }

    public function test_prediction_detail_route_uses_path_identifier_and_renders_real_game(): void
    {
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $gameId = 0;
        try {
            $database->query("INSERT INTO prediction_games (title,sport_type,team_home,team_away,created_by,start_time,bet_deadline,match_date,status,min_bet_usdt,max_bet_usdt,created_at,updated_at) VALUES ('E2E Prediction Contract','football','Runtime Home','Runtime Away',3,DATE_ADD(NOW(),INTERVAL 2 DAY),DATE_ADD(NOW(),INTERVAL 1 DAY),DATE_ADD(NOW(),INTERVAL 2 DAY),'open',1,100,NOW(),NOW())");
            $gameId = (int)$database->lastInsertId();
            $this->login('/login', 'user@chortke.ir', '123456');
            $response = $this->request('GET', '/prediction/' . $gameId);
            $this->assertSame(200, $response['status']);
            $this->assertStringContainsString('E2E Prediction Contract', $response['body']);
            $this->assertStringContainsString('Runtime Home', $response['body']);
            $this->assertStringContainsString('Runtime Away', $response['body']);
            $this->assertNoRuntimeDiagnostics($response['body']);
        } finally {
            if ($gameId > 0) {
                $database->query('DELETE FROM prediction_bets WHERE game_id=?', [$gameId]);
                $database->query('DELETE FROM prediction_games WHERE id=?', [$gameId]);
            }
        }
    }

    public function test_prediction_place_bet_settlement_and_exactly_once_financial_lifecycle(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $gameId = 0;
        $wallets = $database->fetchAll('SELECT user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,is_frozen,last_withdrawal_at FROM wallets WHERE user_id IN (1,2) ORDER BY user_id');
        $predictionScores = $database->fetchAll("SELECT user_id,domain,score FROM user_scores WHERE user_id IN (1,2) AND domain='prediction_accuracy'");
        $txFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM transactions');
        $ledgerFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM ledger_entries');
        $idemFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM idempotency_keys');
        $scoreEventFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM score_events');
        $userScoreEventFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM user_score_events');
        $auditFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM audit_logs');

        try {
            $database->query('UPDATE wallets SET balance_usdt=1000,locked_usdt=0,is_frozen=0,last_withdrawal_at=NULL WHERE user_id IN (1,2)');
            $database->query("INSERT INTO prediction_games (title,sport_type,team_home,team_away,created_by,start_time,bet_deadline,match_date,status,min_bet_usdt,max_bet_usdt,commission_percent,bonus_pool_usdt,created_at,updated_at) VALUES ('E2E Prediction Settlement','football','Runtime Winners','Runtime Losers',3,DATE_ADD(NOW(),INTERVAL 2 DAY),DATE_ADD(NOW(),INTERVAL 1 DAY),DATE_ADD(NOW(),INTERVAL 2 DAY),'open',1,100,5,0,NOW(),NOW())");
            $gameId = (int)$database->lastInsertId();

            $this->login('/login', 'user@chortke.ir', '123456');
            $page = $this->request('GET', '/prediction/' . $gameId);
            $this->assertSame(200, $page['status']);
            $csrf = $this->extractCsrfToken($page['body']);
            $key = 'e2e-prediction-' . bin2hex(random_bytes(8));
            $placed = $this->request('POST', '/prediction/' . $gameId . '/bet', [
                '_csrf_token' => $csrf,
                'prediction' => 'home',
                'amount_usdt' => '40',
                'idempotency_key' => $key,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $placed['status']);
            $placedPayload = $this->decodeJsonObject($placed['body']);
            $this->assertTrue((bool)($placedPayload['success'] ?? false), $placed['body']);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM prediction_bets WHERE game_id=? AND user_id=1', [$gameId]));
            $userOneHeld = $database->fetch('SELECT balance_usdt,locked_usdt FROM wallets WHERE user_id=1');
            $this->assertSame('960.00000000', number_format((float)$this->requireRow($userOneHeld)->balance_usdt, 8, '.', ''));
            $this->assertSame('40.00000000', number_format((float)$this->requireRow($userOneHeld)->locked_usdt, 8, '.', ''));

            $replayed = $this->request('POST', '/prediction/' . $gameId . '/bet', [
                '_csrf_token' => $csrf,
                'prediction' => 'home',
                'amount_usdt' => '40',
                'idempotency_key' => $key,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(422, $replayed['status']);
            $replayedPayload = $this->decodeJsonObject($replayed['body']);
            $this->assertFalse((bool)($replayedPayload['success'] ?? true));
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM prediction_bets WHERE game_id=? AND user_id=1', [$gameId]));
            $this->assertSame('40.00000000', number_format((float)$database->fetchColumn('SELECT locked_usdt FROM wallets WHERE user_id=1'), 8, '.', ''));

            $freshDuplicate = $this->request('POST', '/prediction/' . $gameId . '/bet', [
                '_csrf_token'=>$csrf,'prediction'=>'away','amount_usdt'=>'30',
                'idempotency_key'=>'e2e-prediction-fresh-'.bin2hex(random_bytes(6)),
            ], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(422, $freshDuplicate['status']);
            $this->assertFalse((bool)($this->decodeJsonObject($freshDuplicate['body'])['success'] ?? true));
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM prediction_bets WHERE game_id=? AND user_id=1', [$gameId]));
            $this->assertSame('40.00000000', number_format((float)$database->fetchColumn('SELECT locked_usdt FROM wallets WHERE user_id=1'),8,'.',''));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'support@chortke.ir', '123456');
            $secondPage = $this->request('GET', '/prediction/' . $gameId);
            $secondCsrf = $this->extractCsrfToken($secondPage['body']);
            $secondPlaced = $this->request('POST', '/prediction/' . $gameId . '/bet', [
                '_csrf_token' => $secondCsrf,
                'prediction' => 'away',
                'amount_usdt' => '60',
                'idempotency_key' => 'e2e-prediction-' . bin2hex(random_bytes(8)),
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $secondPlaced['status']);
            $this->assertTrue((bool)($this->decodeJsonObject($secondPlaced['body'])['success'] ?? false), $secondPlaced['body']);
            $this->assertSame('940.00000000', number_format((float)$database->fetchColumn('SELECT balance_usdt FROM wallets WHERE user_id=2'), 8, '.', ''));
            $this->assertSame('60.00000000', number_format((float)$database->fetchColumn('SELECT locked_usdt FROM wallets WHERE user_id=2'), 8, '.', ''));

            file_put_contents($this->cookieJar, '');
            $this->login('/admin/login', 'superadmin@chortke.ir', '123456');
            $adminGame = $this->request('GET', '/admin/prediction/' . $gameId);
            $this->assertSame(200, $adminGame['status'], 'Admin prediction detail must bind the route identifier.');
            $this->assertStringContainsString('E2E Prediction Settlement', $adminGame['body']);
            $adminCsrf = $this->extractCsrfToken($adminGame['body']);
            $settled = $this->request('POST', '/admin/prediction/' . $gameId . '/settle', [
                '_csrf_token' => $adminCsrf,
                'result' => 'home',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $settled['status'], $settled['body']);
            $settledPayload = $this->decodeJsonObject($settled['body']);
            $this->assertTrue((bool)($settledPayload['success'] ?? false), $settled['body']);
            $this->assertSame(1, (int)($settledPayload['summary']['winners_paid'] ?? 0));
            $this->assertSame(1, (int)($settledPayload['summary']['losers_marked'] ?? 0));
            $this->assertSame('3.00000000', number_format((float)($settledPayload['summary']['site_fee_amount'] ?? 0), 8, '.', ''));

            $game = $database->fetch('SELECT status,result,winners_paid,site_fee_usdt,settled_by FROM prediction_games WHERE id=?', [$gameId]);
            $this->assertSame('finished', (string)$this->requireRow($game)->status);
            $this->assertSame('home', (string)$this->requireRow($game)->result);
            $this->assertSame(1, (int)$this->requireRow($game)->winners_paid);
            $this->assertSame('3.00000000', number_format((float)$this->requireRow($game)->site_fee_usdt, 8, '.', ''));
            $bets = $database->fetchAll('SELECT user_id,status,payout_usdt,payment_transaction_id,payout_transaction_id FROM prediction_bets WHERE game_id=? ORDER BY user_id', [$gameId]);
            $this->assertCount(2, $bets);
            $this->assertSame('won', (string)$bets[0]->status);
            $this->assertSame('97.00000000', number_format((float)$bets[0]->payout_usdt, 8, '.', ''));
            $this->assertNotEmpty($bets[0]->payout_transaction_id);
            $this->assertSame('lost', (string)$bets[1]->status);
            $this->assertSame('0.00000000', number_format((float)$bets[1]->payout_usdt, 8, '.', ''));

            $winnerWallet = $database->fetch('SELECT balance_usdt,locked_usdt FROM wallets WHERE user_id=1');
            $loserWallet = $database->fetch('SELECT balance_usdt,locked_usdt FROM wallets WHERE user_id=2');
            $this->assertSame('1057.00000000', number_format((float)$this->requireRow($winnerWallet)->balance_usdt, 8, '.', ''));
            $this->assertSame('0.00000000', number_format((float)$this->requireRow($winnerWallet)->locked_usdt, 8, '.', ''));
            $this->assertSame('940.00000000', number_format((float)$this->requireRow($loserWallet)->balance_usdt, 8, '.', ''));
            $this->assertSame('0.00000000', number_format((float)$this->requireRow($loserWallet)->locked_usdt, 8, '.', ''));
            $this->assertSame(2, (int)$database->fetchColumn("SELECT COUNT(*) FROM transactions WHERE id>? AND type='prediction_stake_settlement' AND status='completed'", [$txFloor]));
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM transactions WHERE id>? AND type='prediction_payout' AND status='completed'", [$txFloor]));
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM transactions WHERE id>? AND type='prediction_platform_fee' AND status='completed'", [$txFloor]));
            $unbalanced = (int)$database->fetchColumn('SELECT COUNT(*) FROM (SELECT transaction_id FROM ledger_entries WHERE id>? GROUP BY transaction_id HAVING ABS(SUM(debit)-SUM(credit)) > 0.00000001) x', [$ledgerFloor]);
            $this->assertSame(0, $unbalanced, 'Every prediction ledger transaction must be double-entry balanced.');

            $beforeReplay = $database->fetch('SELECT balance_usdt,locked_usdt FROM wallets WHERE user_id=1');
            $settledAgain = $this->request('POST', '/admin/prediction/' . $gameId . '/settle', [
                '_csrf_token' => $adminCsrf,
                'result' => 'home',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(422, $settledAgain['status']);
            $this->assertFalse((bool)($this->decodeJsonObject($settledAgain['body'])['success'] ?? true));
            $this->assertSame((string)$this->requireRow($beforeReplay)->balance_usdt, (string)$database->fetchColumn('SELECT balance_usdt FROM wallets WHERE user_id=1'));
            $this->assertSame((string)$this->requireRow($beforeReplay)->locked_usdt, (string)$database->fetchColumn('SELECT locked_usdt FROM wallets WHERE user_id=1'));
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM transactions WHERE id>? AND type='prediction_payout'", [$txFloor]));
        } finally {
            if ($gameId > 0) $database->query('DELETE FROM prediction_bets WHERE game_id=?', [$gameId]);
            if ($gameId > 0) $database->query('DELETE FROM prediction_games WHERE id=?', [$gameId]);
            $database->query('DELETE FROM ledger_entries WHERE id>?', [$ledgerFloor]);
            $database->query('DELETE FROM transactions WHERE id>?', [$txFloor]);
            $database->query('DELETE FROM idempotency_keys WHERE id>?', [$idemFloor]);
            $database->query('DELETE FROM score_events WHERE id>?', [$scoreEventFloor]);
            $database->query('DELETE FROM user_score_events WHERE id>?', [$userScoreEventFloor]);
            $database->query("DELETE FROM user_scores WHERE user_id IN (1,2) AND domain='prediction_accuracy'");
            foreach ($predictionScores as $score) {
                $database->query('INSERT INTO user_scores (user_id,domain,score,updated_at) VALUES (?,?,?,NOW())', [$score->user_id,$score->domain,$score->score]);
            }
            $database->query('DELETE FROM audit_logs WHERE id>?', [$auditFloor]);
            foreach ($wallets as $wallet) {
                $database->query('UPDATE wallets SET balance_irt=?,balance_usdt=?,locked_irt=?,locked_usdt=?,is_frozen=?,last_withdrawal_at=? WHERE user_id=?', [$wallet->balance_irt,$wallet->balance_usdt,$wallet->locked_irt,$wallet->locked_usdt,$wallet->is_frozen,$wallet->last_withdrawal_at,$wallet->user_id]);
            }
            $this->flushTestRedis();
        }
    }

    public function test_prediction_cancel_refunds_hold_exactly_once(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $gameId = 0;
        $wallet = $database->fetch('SELECT balance_irt,balance_usdt,locked_irt,locked_usdt,is_frozen,last_withdrawal_at FROM wallets WHERE user_id=1');
        $txFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM transactions');
        $ledgerFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM ledger_entries');
        $idemFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM idempotency_keys');
        try {
            $database->query('UPDATE wallets SET balance_usdt=1000,locked_usdt=0,is_frozen=0,last_withdrawal_at=NULL WHERE user_id=1');
            $database->query("INSERT INTO prediction_games (title,sport_type,team_home,team_away,created_by,start_time,bet_deadline,match_date,status,min_bet_usdt,max_bet_usdt,commission_percent,bonus_pool_usdt,created_at,updated_at) VALUES ('E2E Prediction Cancellation','football','Runtime A','Runtime B',3,DATE_ADD(NOW(),INTERVAL 2 DAY),DATE_ADD(NOW(),INTERVAL 1 DAY),DATE_ADD(NOW(),INTERVAL 2 DAY),'open',1,100,5,0,NOW(),NOW())");
            $gameId = (int)$database->lastInsertId();

            $this->login('/login', 'user@chortke.ir', '123456');
            $page = $this->request('GET', '/prediction/' . $gameId);
            $csrf = $this->extractCsrfToken($page['body']);
            $placed = $this->request('POST', '/prediction/' . $gameId . '/bet', [
                '_csrf_token'=>$csrf,'prediction'=>'draw','amount_usdt'=>'25','idempotency_key'=>'e2e-prediction-cancel-'.bin2hex(random_bytes(8)),
            ], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $placed['status']);
            $this->assertSame('975.00000000', number_format((float)$database->fetchColumn('SELECT balance_usdt FROM wallets WHERE user_id=1'),8,'.',''));
            $this->assertSame('25.00000000', number_format((float)$database->fetchColumn('SELECT locked_usdt FROM wallets WHERE user_id=1'),8,'.',''));

            file_put_contents($this->cookieJar, '');
            $this->login('/admin/login', 'superadmin@chortke.ir', '123456');
            $adminPage = $this->request('GET', '/admin/prediction/' . $gameId);
            $adminCsrf = $this->extractCsrfToken($adminPage['body']);
            $cancelled = $this->request('POST', '/admin/prediction/' . $gameId . '/cancel', ['_csrf_token'=>$adminCsrf], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $cancelled['status']);
            $cancelledPayload = $this->decodeJsonObject($cancelled['body']);
            $this->assertTrue((bool)($cancelledPayload['success'] ?? false), $cancelled['body']);
            $this->assertSame(1, (int)($cancelledPayload['refunded_count'] ?? 0));
            $this->assertSame('cancelled', (string)$database->fetchColumn('SELECT status FROM prediction_games WHERE id=?', [$gameId]));
            $this->assertSame('refunded', (string)$database->fetchColumn('SELECT status FROM prediction_bets WHERE game_id=?', [$gameId]));
            $this->assertSame('1000.00000000', number_format((float)$database->fetchColumn('SELECT balance_usdt FROM wallets WHERE user_id=1'),8,'.',''));
            $this->assertSame('0.00000000', number_format((float)$database->fetchColumn('SELECT locked_usdt FROM wallets WHERE user_id=1'),8,'.',''));
            $this->assertSame('cancelled', (string)$database->fetchColumn("SELECT status FROM transactions WHERE id>? AND type='withdraw' ORDER BY id DESC LIMIT 1", [$txFloor]));

            $cancelledAgain = $this->request('POST', '/admin/prediction/' . $gameId . '/cancel', ['_csrf_token'=>$adminCsrf], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $cancelledAgainPayload = $this->decodeJsonObject($cancelledAgain['body']);
            $this->assertFalse((bool)($cancelledAgainPayload['success'] ?? true));
            $this->assertSame('1000.00000000', number_format((float)$database->fetchColumn('SELECT balance_usdt FROM wallets WHERE user_id=1'),8,'.',''));
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM ledger_entries WHERE id>? AND account='wallet:1' AND credit>0", [$ledgerFloor]));
        } finally {
            if ($gameId > 0) $database->query('DELETE FROM prediction_bets WHERE game_id=?', [$gameId]);
            if ($gameId > 0) $database->query('DELETE FROM prediction_games WHERE id=?', [$gameId]);
            $database->query('DELETE FROM ledger_entries WHERE id>?', [$ledgerFloor]);
            $database->query('DELETE FROM transactions WHERE id>?', [$txFloor]);
            $database->query('DELETE FROM idempotency_keys WHERE id>?', [$idemFloor]);
            $database->query('UPDATE wallets SET balance_irt=?,balance_usdt=?,locked_irt=?,locked_usdt=?,is_frozen=?,last_withdrawal_at=? WHERE user_id=1', [$this->requireRow($wallet)->balance_irt,$this->requireRow($wallet)->balance_usdt,$this->requireRow($wallet)->locked_irt,$this->requireRow($wallet)->locked_usdt,$this->requireRow($wallet)->is_frozen,$this->requireRow($wallet)->last_withdrawal_at]);
            $this->flushTestRedis();
        }
    }

    public function test_lottery_join_vote_winner_and_cancel_financial_lifecycle(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $winnerRoundId = 0;
        $cancelRoundId = 0;
        $dailyId = 0;
        $feature = $database->fetch("SELECT enabled FROM feature_flags WHERE name='lottery'");
        $wallets = $database->fetchAll('SELECT user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,is_frozen,last_withdrawal_at FROM wallets WHERE user_id IN (1,2) ORDER BY user_id');
        $txFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM transactions');
        $ledgerFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM ledger_entries');
        $idemFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM idempotency_keys');
        $outboxFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM outbox_events');
        $auditFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM audit_trail');

        try {
            $database->query("UPDATE feature_flags SET enabled=1 WHERE name='lottery'");
            $database->query('UPDATE wallets SET balance_irt=1000000,locked_irt=0,is_frozen=0,last_withdrawal_at=NULL WHERE user_id IN (1,2)');
            $database->query("INSERT INTO lottery_rounds (title,prize_pool,status,winner_user_id,created_at,updated_at,is_deleted,start_date,end_date,prize_amount,ticket_price,max_capacity,currency,entry_fee) VALUES ('E2E Lottery Winner',100,'active',NULL,NOW(),NOW(),0,NOW(),DATE_ADD(NOW(),INTERVAL 7 DAY),100,100,10,'irt',100)");
            $winnerRoundId = (int)$database->lastInsertId();
            $this->flushTestRedis();

            $this->login('/login', 'user@chortke.ir', '123456');
            $page = $this->request('GET', '/lottery');
            $this->assertSame(200, $page['status']);
            $csrf = $this->extractCsrfToken($page['body']);
            $joinKey = 'e2e-lottery-' . bin2hex(random_bytes(8));
            $joined = $this->request('POST', '/lottery/' . $winnerRoundId . '/join', [
                '_csrf_token'=>$csrf,'idempotency_key'=>$joinKey,
            ], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $joined['status'], $joined['body']);
            $joinedPayload = $this->decodeJsonObject($joined['body']);
            $this->assertTrue((bool)($joinedPayload['success'] ?? false), $joined['body']);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM lottery_participations WHERE round_id=? AND user_id=1', [$winnerRoundId]));
            $ticket = (string)$database->fetchColumn('SELECT ticket_number FROM lottery_participations WHERE round_id=? AND user_id=1', [$winnerRoundId]);
            $this->assertRegExp('/^([1-9]|[1-4][0-9]),([1-9]|[1-4][0-9]),([1-9]|[1-4][0-9])$/', $ticket);
            $this->assertSame('999900.00000000', number_format((float)$database->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id=1'),8,'.',''));
            $this->assertSame('100.00000000', number_format((float)$database->fetchColumn('SELECT locked_irt FROM wallets WHERE user_id=1'),8,'.',''));

            $joinedAgain = $this->request('POST', '/lottery/' . $winnerRoundId . '/join', [
                '_csrf_token'=>$csrf,'idempotency_key'=>$joinKey,
            ], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $joinedAgain['status']);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM lottery_participations WHERE round_id=? AND user_id=1', [$winnerRoundId]));
            $this->assertSame('100.00000000', number_format((float)$database->fetchColumn('SELECT locked_irt FROM wallets WHERE user_id=1'),8,'.',''));

            $database->query("INSERT INTO lottery_daily_numbers (winning_number,date,created_at,round_id,number1,number2,number3,selected_number,status,seed_hash,is_deleted,updated_at) VALUES (7,CURDATE(),NOW(),?,7,11,23,NULL,'pending','e2e-seed',0,NOW())", [$winnerRoundId]);
            $dailyId = (int)$database->lastInsertId();
            $vote = $this->request('POST', '/lottery/' . $winnerRoundId . '/vote', [
                '_csrf_token'=>$csrf,'daily_number_id'=>$dailyId,'voted_number'=>11,
            ], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $vote['status'], $vote['body']);
            $this->assertTrue((bool)($this->decodeJsonObject($vote['body'])['success'] ?? false));
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM lottery_votes WHERE daily_number_id=? AND user_id=1', [$dailyId]));
            $duplicateVote = $this->request('POST', '/lottery/' . $winnerRoundId . '/vote', [
                '_csrf_token'=>$csrf,'daily_number_id'=>$dailyId,'voted_number'=>11,
            ], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(422, $duplicateVote['status']);
            $this->assertFalse((bool)($this->decodeJsonObject($duplicateVote['body'])['success'] ?? true));

            file_put_contents($this->cookieJar, '');
            $this->login('/admin/login', 'superadmin@chortke.ir', '123456');
            $adminPage = $this->request('GET', '/admin/lottery/' . $winnerRoundId);
            $this->assertSame(200, $adminPage['status']);
            $adminCsrf = $this->extractCsrfToken($adminPage['body']);
            $selected = $this->request('POST', '/admin/lottery/' . $winnerRoundId . '/select-winner', ['_csrf_token'=>$adminCsrf], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $selected['status'], $selected['body']);
            $this->assertTrue((bool)($this->decodeJsonObject($selected['body'])['success'] ?? false), $selected['body']);
            $round = $database->fetch('SELECT status,winner_user_id FROM lottery_rounds WHERE id=?', [$winnerRoundId]);
            $this->assertSame('completed', (string)$this->requireRow($round)->status);
            $this->assertSame(1, (int)$this->requireRow($round)->winner_user_id);
            $this->assertSame('1000000.00000000', number_format((float)$database->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id=1'),8,'.',''));
            $this->assertSame('0.00000000', number_format((float)$database->fetchColumn('SELECT locked_irt FROM wallets WHERE user_id=1'),8,'.',''));
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM transactions WHERE id>? AND type='lottery_ticket_settlement' AND status='completed'", [$txFloor]));
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM transactions WHERE id>? AND type='lottery_prize' AND status='completed'", [$txFloor]));

            $selectedAgain = $this->request('POST', '/admin/lottery/' . $winnerRoundId . '/select-winner', ['_csrf_token'=>$adminCsrf], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(422, $selectedAgain['status']);
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM transactions WHERE id>? AND type='lottery_prize'", [$txFloor]));
            $this->assertSame('1000000.00000000', number_format((float)$database->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id=1'),8,'.',''));

            $database->query("INSERT INTO lottery_rounds (title,prize_pool,status,winner_user_id,created_at,updated_at,is_deleted,start_date,end_date,prize_amount,ticket_price,max_capacity,currency,entry_fee) VALUES ('E2E Lottery Cancel',50,'active',NULL,NOW(),NOW(),0,NOW(),DATE_ADD(NOW(),INTERVAL 7 DAY),50,50,10,'irt',50)");
            $cancelRoundId = (int)$database->lastInsertId();
            file_put_contents($this->cookieJar, '');
            $this->flushTestRedis();
            $this->login('/login', 'support@chortke.ir', '123456');
            $cancelPage = $this->request('GET', '/lottery');
            $cancelCsrf = $this->extractCsrfToken($cancelPage['body']);
            $cancelJoin = $this->request('POST', '/lottery/' . $cancelRoundId . '/join', ['_csrf_token'=>$cancelCsrf,'idempotency_key'=>'e2e-lottery-cancel-'.bin2hex(random_bytes(6))], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $cancelJoin['status'], $cancelJoin['body']);
            $this->assertSame('50.00000000', number_format((float)$database->fetchColumn('SELECT locked_irt FROM wallets WHERE user_id=2'),8,'.',''));

            // Capacity is a financial invariant: rejection must happen before
            // any second wallet hold is created.
            $database->query('UPDATE lottery_rounds SET max_capacity=1 WHERE id=?', [$cancelRoundId]);
            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'user@chortke.ir', '123456');
            $capacityPage = $this->request('GET', '/lottery');
            $capacityCsrf = $this->extractCsrfToken($capacityPage['body']);
            $capacityRejected = $this->request('POST', '/lottery/' . $cancelRoundId . '/join', [
                '_csrf_token'=>$capacityCsrf,'idempotency_key'=>'e2e-lottery-capacity-'.bin2hex(random_bytes(6)),
            ], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(422, $capacityRejected['status']);
            $this->assertFalse((bool)($this->decodeJsonObject($capacityRejected['body'])['success'] ?? true));
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM lottery_participations WHERE round_id=?', [$cancelRoundId]));
            $this->assertSame('0.00000000', number_format((float)$database->fetchColumn('SELECT locked_irt FROM wallets WHERE user_id=1'),8,'.',''));

            file_put_contents($this->cookieJar, '');
            $this->login('/admin/login', 'superadmin@chortke.ir', '123456');
            $cancelAdminPage = $this->request('GET', '/admin/lottery/' . $cancelRoundId);
            $cancelAdminCsrf = $this->extractCsrfToken($cancelAdminPage['body']);
            $cancelled = $this->request('POST', '/admin/lottery/' . $cancelRoundId . '/cancel', ['_csrf_token'=>$cancelAdminCsrf], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $cancelled['status'], $cancelled['body']);
            $this->assertTrue((bool)($this->decodeJsonObject($cancelled['body'])['success'] ?? false), $cancelled['body']);
            $this->assertSame('cancelled', (string)$database->fetchColumn('SELECT status FROM lottery_rounds WHERE id=?', [$cancelRoundId]));
            $this->assertSame('1000000.00000000', number_format((float)$database->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id=2'),8,'.',''));
            $this->assertSame('0.00000000', number_format((float)$database->fetchColumn('SELECT locked_irt FROM wallets WHERE user_id=2'),8,'.',''));
            $cancelledAgain = $this->request('POST', '/admin/lottery/' . $cancelRoundId . '/cancel', ['_csrf_token'=>$cancelAdminCsrf], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(422, $cancelledAgain['status']);
            $this->assertSame('1000000.00000000', number_format((float)$database->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id=2'),8,'.',''));
            $unbalanced = (int)$database->fetchColumn('SELECT COUNT(*) FROM (SELECT transaction_id FROM ledger_entries WHERE id>? GROUP BY transaction_id HAVING ABS(SUM(debit)-SUM(credit)) > 0.00000001) x', [$ledgerFloor]);
            $this->assertSame(0, $unbalanced);
        } finally {
            if ($dailyId > 0) $database->query('DELETE FROM lottery_votes WHERE daily_number_id=?', [$dailyId]);
            if ($dailyId > 0) $database->query('DELETE FROM lottery_daily_numbers WHERE id=?', [$dailyId]);
            foreach ([$winnerRoundId,$cancelRoundId] as $roundId) {
                if ($roundId > 0) $database->query('DELETE FROM lottery_participations WHERE round_id=?', [$roundId]);
                if ($roundId > 0) $database->query('DELETE FROM lottery_rounds WHERE id=?', [$roundId]);
            }
            $database->query('DELETE FROM ledger_entries WHERE id>?', [$ledgerFloor]);
            $database->query('DELETE FROM transactions WHERE id>?', [$txFloor]);
            $database->query('DELETE FROM idempotency_keys WHERE id>?', [$idemFloor]);
            $database->query('DELETE FROM outbox_events WHERE id>?', [$outboxFloor]);
            $database->query('DELETE FROM audit_trail WHERE id>?', [$auditFloor]);
            $database->query("UPDATE feature_flags SET enabled=? WHERE name='lottery'", [(int)($feature->enabled ?? 0)]);
            foreach ($wallets as $wallet) {
                $database->query('UPDATE wallets SET balance_irt=?,balance_usdt=?,locked_irt=?,locked_usdt=?,is_frozen=?,last_withdrawal_at=? WHERE user_id=?', [$wallet->balance_irt,$wallet->balance_usdt,$wallet->locked_irt,$wallet->locked_usdt,$wallet->is_frozen,$wallet->last_withdrawal_at,$wallet->user_id]);
            }
            $this->flushTestRedis();
        }
    }

    public function test_investment_create_full_withdraw_approve_and_reject_financial_lifecycle(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $feature = $database->fetch("SELECT enabled FROM feature_flags WHERE name='investment'");
        $wallets = $database->fetchAll('SELECT user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,is_frozen,last_withdrawal_at FROM wallets WHERE user_id IN (1,2) ORDER BY user_id');
        $txFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM transactions');
        $ledgerFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM ledger_entries');
        $idemFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM idempotency_keys');
        $outboxFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM outbox_events');
        $investmentIds = [];
        try {
            $database->query("UPDATE feature_flags SET enabled=1 WHERE name='investment'");
            $database->query('UPDATE wallets SET balance_usdt=1000,locked_usdt=0,is_frozen=0,last_withdrawal_at=NULL WHERE user_id IN (1,2)');
            $this->flushTestRedis();

            $this->login('/login', 'user@chortke.ir', '123456');
            $page = $this->request('GET', '/investment/create');
            $this->assertSame(200, $page['status']);
            $csrf = $this->extractCsrfToken($page['body']);
            $investmentKey = 'e2e-investment-' . bin2hex(random_bytes(8));
            $created = $this->request('POST', '/investment/store', [
                '_csrf_token'=>$csrf,'amount'=>'100','risk_accepted'=>'1','idempotency_key'=>$investmentKey,
            ], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $created['status'], $created['body']);
            $createdPayload = $this->decodeJsonObject($created['body']);
            $this->assertTrue((bool)($createdPayload['success'] ?? false), $created['body']);
            $investmentId = (int)($createdPayload['investment_id'] ?? 0);
            $this->assertGreaterThan(0, $investmentId);
            $investmentIds[] = $investmentId;
            $this->assertSame('900.00000000', number_format((float)$database->fetchColumn('SELECT balance_usdt FROM wallets WHERE user_id=1'),8,'.',''));
            $this->assertSame('0.00000000', number_format((float)$database->fetchColumn('SELECT locked_usdt FROM wallets WHERE user_id=1'),8,'.',''));
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM transactions WHERE id>? AND type='investment_capital_settlement'", [$txFloor]));

            $replayedCreate = $this->request('POST', '/investment/store', [
                '_csrf_token'=>$csrf,'amount'=>'100','risk_accepted'=>'1','idempotency_key'=>$investmentKey,
            ], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(422, $replayedCreate['status']);
            $this->assertFalse((bool)($this->decodeJsonObject($replayedCreate['body'])['success'] ?? true));
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM investments WHERE user_id=1 AND status='active'", []));
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM transactions WHERE id>? AND type='withdraw' AND metadata LIKE '%investment_creation%'", [$txFloor]));
            $this->assertSame('900.00000000', number_format((float)$database->fetchColumn('SELECT balance_usdt FROM wallets WHERE user_id=1'),8,'.',''));

            $secondActive = $this->request('POST', '/investment/store', [
                '_csrf_token'=>$csrf,'amount'=>'120','risk_accepted'=>'1','idempotency_key'=>'e2e-investment-second-'.bin2hex(random_bytes(6)),
            ], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(422, $secondActive['status']);
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM investments WHERE user_id=1 AND status='active'", []));

            $database->query('UPDATE investments SET start_date=DATE_SUB(NOW(),INTERVAL 8 DAY),created_at=DATE_SUB(NOW(),INTERVAL 8 DAY) WHERE id=?', [$investmentId]);
            $withdraw = $this->request('POST', '/investment/withdraw', ['_csrf_token'=>$csrf,'withdrawal_type'=>'full_close'], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $withdraw['status'], $withdraw['body']);
            $withdrawPayload = $this->decodeJsonObject($withdraw['body']);
            $this->assertTrue((bool)($withdrawPayload['success'] ?? false), $withdraw['body']);
            $withdrawalId = (int)($withdrawPayload['withdrawal_id'] ?? 0);
            $this->assertSame('0.00000000', number_format((float)$database->fetchColumn('SELECT current_balance FROM investments WHERE id=?', [$investmentId]),8,'.',''));

            file_put_contents($this->cookieJar, '');
            $this->login('/admin/login', 'superadmin@chortke.ir', '123456');
            $adminPage = $this->request('GET', '/admin/investment/' . $investmentId);
            $this->assertSame(200, $adminPage['status']);
            $adminCsrf = $this->extractCsrfToken($adminPage['body']);
            $approved = $this->request('POST', '/admin/investment/withdrawals/' . $withdrawalId . '/approve', ['_csrf_token'=>$adminCsrf], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $approved['status'], $approved['body']);
            $this->assertTrue((bool)($this->decodeJsonObject($approved['body'])['success'] ?? false), $approved['body']);
            $this->assertSame('approved', (string)$database->fetchColumn('SELECT status FROM investment_withdrawals WHERE id=?', [$withdrawalId]));
            $this->assertSame('closed', (string)$database->fetchColumn('SELECT status FROM investments WHERE id=?', [$investmentId]));
            $this->assertSame('1000.00000000', number_format((float)$database->fetchColumn('SELECT balance_usdt FROM wallets WHERE user_id=1'),8,'.',''));
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM transactions WHERE id>? AND type='investment_withdrawal'", [$txFloor]));
            $approveAgain = $this->request('POST', '/admin/investment/withdrawals/' . $withdrawalId . '/approve', ['_csrf_token'=>$adminCsrf], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(422, $approveAgain['status']);
            $this->assertSame('1000.00000000', number_format((float)$database->fetchColumn('SELECT balance_usdt FROM wallets WHERE user_id=1'),8,'.',''));

            file_put_contents($this->cookieJar, '');
            $this->flushTestRedis();
            $this->login('/login', 'support@chortke.ir', '123456');
            $page2 = $this->request('GET', '/investment/create');
            $csrf2 = $this->extractCsrfToken($page2['body']);
            $created2 = $this->request('POST', '/investment/store', ['_csrf_token'=>$csrf2,'amount'=>'80','risk_accepted'=>'1'], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $created2['status'], $created2['body']);
            $investmentId2 = int_value($this->decodeJsonObject($created2['body'])['investment_id'] ?? 0);
            $investmentIds[] = $investmentId2;
            $database->query('UPDATE investments SET start_date=DATE_SUB(NOW(),INTERVAL 8 DAY),created_at=DATE_SUB(NOW(),INTERVAL 8 DAY) WHERE id=?', [$investmentId2]);
            $withdraw2 = $this->request('POST', '/investment/withdraw', ['_csrf_token'=>$csrf2,'withdrawal_type'=>'full_close'], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $withdraw2['status'], $withdraw2['body']);
            $withdrawalId2 = int_value($this->decodeJsonObject($withdraw2['body'])['withdrawal_id'] ?? 0);

            file_put_contents($this->cookieJar, '');
            $this->login('/admin/login', 'superadmin@chortke.ir', '123456');
            $adminPage2 = $this->request('GET', '/admin/investment/' . $investmentId2);
            $adminCsrf2 = $this->extractCsrfToken($adminPage2['body']);
            $rejected = $this->request('POST', '/admin/investment/withdrawals/' . $withdrawalId2 . '/reject', ['_csrf_token'=>$adminCsrf2,'reason'=>'Runtime rejection restores reserved investment balance'], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $rejected['status'], $rejected['body']);
            $this->assertTrue((bool)($this->decodeJsonObject($rejected['body'])['success'] ?? false));
            $this->assertSame('rejected', (string)$database->fetchColumn('SELECT status FROM investment_withdrawals WHERE id=?', [$withdrawalId2]));
            $this->assertSame('80.00000000', number_format((float)$database->fetchColumn('SELECT current_balance FROM investments WHERE id=?', [$investmentId2]),8,'.',''));
            $this->assertSame('920.00000000', number_format((float)$database->fetchColumn('SELECT balance_usdt FROM wallets WHERE user_id=2'),8,'.',''));
            $rejectAgain = $this->request('POST', '/admin/investment/withdrawals/' . $withdrawalId2 . '/reject', ['_csrf_token'=>$adminCsrf2,'reason'=>'Runtime repeated rejection must remain idempotently rejected'], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(422, $rejectAgain['status']);
            $this->assertSame('80.00000000', number_format((float)$database->fetchColumn('SELECT current_balance FROM investments WHERE id=?', [$investmentId2]),8,'.',''));
            $unbalanced = (int)$database->fetchColumn('SELECT COUNT(*) FROM (SELECT transaction_id FROM ledger_entries WHERE id>? GROUP BY transaction_id HAVING ABS(SUM(debit)-SUM(credit)) > 0.00000001) x', [$ledgerFloor]);
            $this->assertSame(0, $unbalanced);
        } finally {
            if ($investmentIds !== []) {
                $marks = implode(',', array_fill(0,count($investmentIds),'?'));
                $database->query("DELETE FROM investment_withdrawals WHERE investment_id IN ($marks)", $investmentIds);
                $database->query("DELETE FROM investment_profits WHERE investment_id IN ($marks)", $investmentIds);
                $database->query("DELETE FROM investments WHERE id IN ($marks)", $investmentIds);
            }
            $database->query('DELETE FROM ledger_entries WHERE id>?', [$ledgerFloor]);
            $database->query('DELETE FROM transactions WHERE id>?', [$txFloor]);
            $database->query('DELETE FROM idempotency_keys WHERE id>?', [$idemFloor]);
            $database->query('DELETE FROM outbox_events WHERE id>?', [$outboxFloor]);
            $database->query("UPDATE feature_flags SET enabled=? WHERE name='investment'", [(int)($feature->enabled ?? 0)]);
            foreach ($wallets as $wallet) {
                $database->query('UPDATE wallets SET balance_irt=?,balance_usdt=?,locked_irt=?,locked_usdt=?,is_frozen=?,last_withdrawal_at=? WHERE user_id=?', [$wallet->balance_irt,$wallet->balance_usdt,$wallet->locked_irt,$wallet->locked_usdt,$wallet->is_frozen,$wallet->last_withdrawal_at,$wallet->user_id]);
            }
            $this->flushTestRedis();
        }
    }

    public function test_crypto_deposit_intent_ownership_idempotency_approve_and_reject_lifecycle(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $wallet = $database->fetch('SELECT balance_irt,balance_usdt,locked_irt,locked_usdt FROM wallets WHERE user_id=1');
        $txFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM transactions');
        $ledgerFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM ledger_entries');
        $idemFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM idempotency_keys');
        $settingKeys = ['site_usdt_bnb20_address','crypto_network_bnb20_enabled','min_crypto_deposit_usdt'];
        $settings = [];
        foreach ($settingKeys as $settingKey) $settings[$settingKey] = $database->fetch('SELECT * FROM system_settings WHERE `key`=?', [$settingKey]);
        $cryptoFlag = $database->fetch('SELECT * FROM feature_flags WHERE name=?', ['crypto_deposit']);
        $intentId = 0;
        $depositId = 0;
        $rejectId = 0;
        try {
            $database->query("INSERT INTO feature_flags (name,enabled,created_at,updated_at) VALUES ('crypto_deposit',1,NOW(),NOW()) ON DUPLICATE KEY UPDATE enabled=1,updated_at=NOW()");
            foreach (['site_usdt_bnb20_address'=>'0x1111111111111111111111111111111111111111','crypto_network_bnb20_enabled'=>'true','min_crypto_deposit_usdt'=>'1'] as $key=>$value) {
                $database->query("INSERT INTO system_settings (`key`,`value`,`group`,`type`,created_at,updated_at) VALUES (?,?,'crypto','string',NOW(),NOW()) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),updated_at=NOW()", [$key,$value]);
            }
            $this->flushTestRedis();
            $database->query('UPDATE wallets SET balance_usdt=0,locked_usdt=0 WHERE user_id=1');

            $this->login('/login', 'user@chortke.ir', '123456');
            $dashboard = $this->request('GET', '/dashboard');
            $csrf = $this->extractCsrfToken($dashboard['body']);
            $intent = $this->request('POST', '/wallet/deposit/crypto/intent', ['_csrf_token'=>$csrf,'network'=>'BNB20','requested_amount'=>'12'], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(201, $intent['status']);
            $intentPayload = $this->decodeJsonObject($intent['body']);
            $this->assertTrue((bool)($intentPayload['success'] ?? false), $intent['body']);
            $intentId = (int)($intentPayload['intent_id'] ?? 0);
            $expected = (string)($intentPayload['expected_amount'] ?? '');
            $this->assertGreaterThan(0, $intentId);
            $this->assertNotSame('12', $expected, 'Server must assign a unique expected amount.');
            $this->assertSame('open', (string)$database->fetchColumn('SELECT status FROM crypto_deposit_intents WHERE id=?', [$intentId]));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'support@chortke.ir', '123456');
            $foreignPage = $this->request('GET', '/dashboard');
            $foreignCsrf = $this->extractCsrfToken($foreignPage['body']);
            $hash = '0x' . str_repeat('a', 64);
            $foreign = $this->request('POST', '/wallet/deposit/crypto', ['_csrf_token'=>$foreignCsrf,'intent_id'=>$intentId,'network'=>'bnb20','tx_hash'=>$hash], ['Idempotency-Key: e2e-crypto-foreign']);
            $this->assertSame(302, $foreign['status']);
            $this->assertSame(0, (int)$database->fetchColumn('SELECT COUNT(*) FROM crypto_deposits WHERE tx_hash=?', [$hash]));
            $this->assertSame('open', (string)$database->fetchColumn('SELECT status FROM crypto_deposit_intents WHERE id=?', [$intentId]));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'user@chortke.ir', '123456');
            $ownerPage = $this->request('GET', '/dashboard');
            $ownerCsrf = $this->extractCsrfToken($ownerPage['body']);
            $idemKey = 'e2e-crypto-' . bin2hex(random_bytes(8));
            $form = ['_csrf_token'=>$ownerCsrf,'intent_id'=>$intentId,'network'=>'bnb20','tx_hash'=>$hash];
            $stored = $this->request('POST', '/wallet/deposit/crypto', $form, ['Idempotency-Key: '.$idemKey]);
            $this->assertSame(302, $stored['status']);
            $depositId = (int)$database->fetchColumn('SELECT id FROM crypto_deposits WHERE tx_hash=?', [$hash]);
            $this->assertGreaterThan(0, $depositId);
            $this->assertSame('claimed', (string)$database->fetchColumn('SELECT status FROM crypto_deposit_intents WHERE id=?', [$intentId]));
            $this->assertSame(number_format((float)$expected, 8, '.', ''), number_format((float)$database->fetchColumn('SELECT amount FROM crypto_deposits WHERE id=?', [$depositId]), 8, '.', ''));
            $this->request('POST', '/wallet/deposit/crypto', $form, ['Idempotency-Key: '.$idemKey]);
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM crypto_deposits WHERE tx_hash=?', [$hash]));
            // Provider-unavailable/ambiguous results move pending deposits into
            // the explicit manual-review state before an administrator can credit.
            $database->query("UPDATE crypto_deposits SET verification_status='manual_review' WHERE id=?", [$depositId]);

            $database->query("INSERT INTO crypto_deposits (user_id,amount,currency,tx_hash,network,wallet_address,verification_status,created_at,updated_at) VALUES (1,5,'usdt',?,'BNB20','0x1111111111111111111111111111111111111111','pending',NOW(),NOW())", ['0x'.str_repeat('b',64)]);
            $rejectId = (int)$database->lastInsertId();

            file_put_contents($this->cookieJar, '');
            $this->login('/admin/login', 'superadmin@chortke.ir', '123456');
            $admin = $this->request('GET', '/admin/dashboard');
            $adminCsrf = $this->extractCsrfToken($admin['body']);
            $approved = $this->request('POST', '/admin/crypto-deposits/verify', ['_csrf_token'=>$adminCsrf,'deposit_id'=>$depositId], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $approved['status'], $approved['body']);
            $approvedPayload = $this->decodeJsonObject($approved['body']);
            $this->assertTrue((bool)($approvedPayload['success'] ?? false), $approved['body']);
            $this->assertSame('verified', (string)$database->fetchColumn('SELECT verification_status FROM crypto_deposits WHERE id=?', [$depositId]));
            $this->assertSame(number_format((float)$expected,8,'.',''), number_format((float)$database->fetchColumn('SELECT balance_usdt FROM wallets WHERE user_id=1'),8,'.',''));
            $repeat = $this->request('POST', '/admin/crypto-deposits/verify', ['_csrf_token'=>$adminCsrf,'deposit_id'=>$depositId], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(422, $repeat['status']);
            $this->assertSame(number_format((float)$expected,8,'.',''), number_format((float)$database->fetchColumn('SELECT balance_usdt FROM wallets WHERE user_id=1'),8,'.',''));

            $rejected = $this->request('POST', '/admin/crypto-deposits/reject', ['_csrf_token'=>$adminCsrf,'deposit_id'=>$rejectId,'rejection_reason'=>'Runtime chain mismatch'], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $rejected['status']);
            $this->assertSame('rejected', (string)$database->fetchColumn('SELECT verification_status FROM crypto_deposits WHERE id=?', [$rejectId]));
            $this->assertSame(number_format((float)$expected,8,'.',''), number_format((float)$database->fetchColumn('SELECT balance_usdt FROM wallets WHERE user_id=1'),8,'.',''));
        } finally {
            if ($depositId > 0 || $rejectId > 0) $database->query('DELETE FROM crypto_deposits WHERE id IN (?,?)', [$depositId,$rejectId]);
            if ($intentId > 0) $database->query('DELETE FROM crypto_deposit_intents WHERE id=?', [$intentId]);
            $database->query('DELETE FROM ledger_entries WHERE id>?', [$ledgerFloor]);
            $database->query('DELETE FROM transactions WHERE id>?', [$txFloor]);
            $database->query('DELETE FROM idempotency_keys WHERE id>?', [$idemFloor]);
            $database->query('UPDATE wallets SET balance_irt=?,balance_usdt=?,locked_irt=?,locked_usdt=? WHERE user_id=1', [$this->requireRow($wallet)->balance_irt,$this->requireRow($wallet)->balance_usdt,$this->requireRow($wallet)->locked_irt,$this->requireRow($wallet)->locked_usdt]);
            foreach ($settings as $key=>$row) {
                if ($row) $database->query('UPDATE system_settings SET `value`=?,`group`=?,`type`=?,updated_at=? WHERE `key`=?', [$row->value,$row->group,$row->type,$row->updated_at,$key]);
                else $database->query('DELETE FROM system_settings WHERE `key`=?', [$key]);
            }
            if ($cryptoFlag) $database->query('UPDATE feature_flags SET enabled=?,updated_at=? WHERE name=?', [$cryptoFlag->enabled,$cryptoFlag->updated_at,'crypto_deposit']);
            else $database->query('DELETE FROM feature_flags WHERE name=?', ['crypto_deposit']);
            $this->flushTestRedis();
        }
    }

    public function test_manual_deposit_idempotent_approve_reject_and_exact_credit_lifecycle(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $wallet = $database->fetch('SELECT balance_irt,balance_usdt,locked_irt,locked_usdt FROM wallets WHERE user_id=1');
        $txFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM transactions');
        $ledgerFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM ledger_entries');
        $idemFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM idempotency_keys');
        $cardId = 0;
        $approvedId = 0;
        $rejectedId = 0;
        try {
            $database->query('UPDATE wallets SET balance_irt=0,locked_irt=0 WHERE user_id=1');
            $database->query("INSERT INTO bank_cards (user_id,card_number,owner_name,sheba,bank_name,status,is_default,created_at,updated_at) VALUES (1,'manual-runtime-card','Runtime User','IR111111111111111111111111','Runtime Bank','verified',1,NOW(),NOW())");
            $cardId = (int)$database->lastInsertId();

            $this->login('/login', 'user@chortke.ir', '123456');
            $walletPage = $this->request('GET', '/wallet');
            $csrf = $this->extractCsrfToken($walletPage['body']);
            $key = 'e2e-manual-' . bin2hex(random_bytes(8));
            $form = ['_csrf_token'=>$csrf,'bank_card_id'=>$cardId,'amount'=>'75000','tracking_code'=>'TRACK-E2E-APPROVE','description'=>'Runtime manual deposit','idempotency_key'=>$key];
            $created = $this->request('POST', '/wallet/deposit/manual', $form);
            $this->assertSame(302, $created['status']);
            $approvedId = (int)$database->fetchColumn("SELECT id FROM manual_deposits WHERE tracking_code='TRACK-E2E-APPROVE'");
            $this->assertGreaterThan(0, $approvedId);
            $this->assertSame('pending', (string)$database->fetchColumn('SELECT status FROM manual_deposits WHERE id=?', [$approvedId]));
            $replayed = $this->request('POST', '/wallet/deposit/manual', $form);
            $this->assertSame(302, $replayed['status']);
            $this->assertSame(1, (int)$database->fetchColumn("SELECT COUNT(*) FROM manual_deposits WHERE tracking_code='TRACK-E2E-APPROVE'"));

            $form2 = ['_csrf_token'=>$csrf,'bank_card_id'=>$cardId,'amount'=>'30000','tracking_code'=>'TRACK-E2E-REJECT','description'=>'Runtime reject deposit','idempotency_key'=>'e2e-manual-'.bin2hex(random_bytes(8))];
            $this->request('POST', '/wallet/deposit/manual', $form2);
            $rejectedId = (int)$database->fetchColumn("SELECT id FROM manual_deposits WHERE tracking_code='TRACK-E2E-REJECT'");
            $this->assertGreaterThan(0, $rejectedId);

            file_put_contents($this->cookieJar, '');
            $this->login('/admin/login', 'superadmin@chortke.ir', '123456');
            $admin = $this->request('GET', '/admin/dashboard');
            $adminCsrf = $this->extractCsrfToken($admin['body']);
            $verify = $this->request('POST', '/admin/manual-deposits/verify', ['_csrf_token'=>$adminCsrf,'deposit_id'=>$approvedId,'admin_note'=>'Runtime verified'], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $verify['status']);
            $verifyPayload = $this->decodeJsonObject($verify['body']);
            $this->assertTrue((bool)($verifyPayload['success'] ?? false), $verify['body']);
            $this->assertSame('approved', (string)$database->fetchColumn('SELECT status FROM manual_deposits WHERE id=?', [$approvedId]));
            $this->assertSame('75000.00000000', number_format((float)$database->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id=1'), 8, '.', ''));
            $repeatVerify = $this->request('POST', '/admin/manual-deposits/verify', ['_csrf_token'=>$adminCsrf,'deposit_id'=>$approvedId], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $repeatVerify['status']);
            $repeatVerifyPayload = $this->decodeJsonObject($repeatVerify['body']);
            $this->assertTrue((bool)($repeatVerifyPayload['success'] ?? false));
            $this->assertSame('75000.00000000', number_format((float)$database->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id=1'), 8, '.', ''));

            $reject = $this->request('POST', '/admin/manual-deposits/reject', ['_csrf_token'=>$adminCsrf,'deposit_id'=>$rejectedId,'rejection_reason'=>'Runtime receipt mismatch'], ['X-Requested-With: XMLHttpRequest','Accept: application/json']);
            $this->assertSame(200, $reject['status']);
            $this->assertSame('rejected', (string)$database->fetchColumn('SELECT status FROM manual_deposits WHERE id=?', [$rejectedId]));
            $this->assertSame('75000.00000000', number_format((float)$database->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id=1'), 8, '.', ''));
        } finally {
            if ($approvedId > 0 || $rejectedId > 0) $database->query('DELETE FROM manual_deposits WHERE id IN (?,?)', [$approvedId,$rejectedId]);
            if ($cardId > 0) $database->query('DELETE FROM bank_cards WHERE id=?', [$cardId]);
            $database->query('DELETE FROM ledger_entries WHERE id>?', [$ledgerFloor]);
            $database->query('DELETE FROM transactions WHERE id>?', [$txFloor]);
            $database->query('DELETE FROM idempotency_keys WHERE id>?', [$idemFloor]);
            $database->query('UPDATE wallets SET balance_irt=?,balance_usdt=?,locked_irt=?,locked_usdt=? WHERE user_id=1', [$this->requireRow($wallet)->balance_irt,$this->requireRow($wallet)->balance_usdt,$this->requireRow($wallet)->locked_irt,$this->requireRow($wallet)->locked_usdt]);
            $this->flushTestRedis();
        }
    }

    public function test_withdrawal_request_idempotency_ownership_and_cancel_refund_lifecycle(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $userState = $database->fetch('SELECT kyc_status,kyc_level,kyc_verified_at FROM users WHERE id=1');
        $wallet = $database->fetch('SELECT balance_irt,balance_usdt,locked_irt,locked_usdt,is_frozen,last_withdrawal_at FROM wallets WHERE user_id=1');
        $txFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM transactions');
        $ledgerFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM ledger_entries');
        $outboxFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM outbox_events');
        $idemFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM idempotency_keys');
        $cardId = 0;
        $withdrawalId = 0;
        try {
            $database->query("UPDATE users SET kyc_status='verified',kyc_level=1,kyc_verified_at=NOW() WHERE id=1");
            $database->query('UPDATE wallets SET balance_irt=200000,locked_irt=0,is_frozen=0,last_withdrawal_at=NULL WHERE user_id=1');
            $database->query("INSERT INTO bank_cards (user_id,card_number,owner_name,sheba,bank_name,status,is_default,created_at,updated_at) VALUES (1,'runtime-card-token','Runtime User','IR000000000000000000000000','Runtime Bank','verified',1,NOW(),NOW())");
            $cardId = (int)$database->lastInsertId();

            $this->login('/login', 'user@chortke.ir', '123456');
            $page = $this->request('GET', '/wallet/withdraw');
            $this->assertSame(200, $page['status']);
            $csrf = $this->extractCsrfToken($page['body']);
            $key = 'e2e-withdrawal-' . bin2hex(random_bytes(8));
            $form = [
                '_csrf_token' => $csrf,
                'amount' => '60000',
                'currency' => 'IRT',
                'bank_card_id' => $cardId,
                'idempotency_key' => $key,
                'user_description' => 'Runtime withdrawal contract',
            ];
            $created = $this->request('POST', '/wallet/withdraw', $form, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $created['status']);
            $createdPayload = $this->decodeJsonObject($created['body']);
            $this->assertTrue((bool)($createdPayload['success'] ?? false), $created['body']);
            $withdrawalId = (int)($createdPayload['data']['withdrawal_id'] ?? 0);
            $this->assertGreaterThan(0, $withdrawalId);
            $withdrawal = $database->fetch('SELECT * FROM withdrawals WHERE id=?', [$withdrawalId]);
            $this->assertSame(1, (int)$this->requireRow($withdrawal)->user_id);
            $this->assertSame('pending', (string)$this->requireRow($withdrawal)->status);
            $this->assertSame('60000.00000000', number_format((float)$this->requireRow($withdrawal)->amount, 8, '.', ''));
            $held = $database->fetch('SELECT balance_irt,locked_irt FROM wallets WHERE user_id=1');
            $this->assertSame('140000.00000000', number_format((float)$this->requireRow($held)->balance_irt, 8, '.', ''));
            $this->assertSame('60000.00000000', number_format((float)$this->requireRow($held)->locked_irt, 8, '.', ''));

            $replayed = $this->request('POST', '/wallet/withdraw', $form, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $replayed['status'], $replayed['body']);
            $replayedPayload = $this->decodeJsonObject($replayed['body']);
            $this->assertSame($withdrawalId, int_value($this->requireArray($replayedPayload['data'] ?? null)['withdrawal_id'] ?? 0));
            $this->assertSame(1, (int)$database->fetchColumn('SELECT COUNT(*) FROM withdrawals WHERE id>=?', [$withdrawalId]));
            $this->assertSame('60000.00000000', number_format((float)$database->fetchColumn('SELECT locked_irt FROM wallets WHERE user_id=1'), 8, '.', ''));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'support@chortke.ir', '123456');
            $supportPage = $this->request('GET', '/wallet');
            $supportCsrf = $this->extractCsrfToken($supportPage['body']);
            $foreignCancel = $this->request('POST', '/withdrawals/' . $withdrawalId . '/cancel', [
                '_csrf_token' => $supportCsrf,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $foreignPayload = $this->decodeJsonObject($foreignCancel['body']);
            $this->assertFalse((bool)($foreignPayload['success'] ?? true));
            $this->assertSame('pending', (string)$database->fetchColumn('SELECT status FROM withdrawals WHERE id=?', [$withdrawalId]));

            file_put_contents($this->cookieJar, '');
            $this->login('/login', 'user@chortke.ir', '123456');
            $ownerPage = $this->request('GET', '/wallet');
            $ownerCsrf = $this->extractCsrfToken($ownerPage['body']);
            $cancelled = $this->request('POST', '/withdrawals/' . $withdrawalId . '/cancel', [
                '_csrf_token' => $ownerCsrf,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $cancelled['status']);
            $cancelledPayload = $this->decodeJsonObject($cancelled['body']);
            $this->assertTrue((bool)($cancelledPayload['success'] ?? false), $cancelled['body']);
            $this->assertSame('cancelled', (string)$database->fetchColumn('SELECT status FROM withdrawals WHERE id=?', [$withdrawalId]));
            $restored = $database->fetch('SELECT balance_irt,locked_irt FROM wallets WHERE user_id=1');
            $this->assertSame('200000.00000000', number_format((float)$this->requireRow($restored)->balance_irt, 8, '.', ''));
            $this->assertSame('0.00000000', number_format((float)$this->requireRow($restored)->locked_irt, 8, '.', ''));

            $repeatCancel = $this->request('POST', '/withdrawals/' . $withdrawalId . '/cancel', [
                '_csrf_token' => $ownerCsrf,
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $repeatCancelPayload = $this->decodeJsonObject($repeatCancel['body']);
            $this->assertFalse((bool)($repeatCancelPayload['success'] ?? true));
            $this->assertSame('200000.00000000', number_format((float)$database->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id=1'), 8, '.', ''));
        } finally {
            if ($withdrawalId > 0) $database->query('DELETE FROM withdrawals WHERE id=?', [$withdrawalId]);
            if ($cardId > 0) $database->query('DELETE FROM bank_cards WHERE id=?', [$cardId]);
            $database->query('DELETE FROM ledger_entries WHERE id>?', [$ledgerFloor]);
            $database->query('DELETE FROM transactions WHERE id>?', [$txFloor]);
            $database->query('DELETE FROM outbox_events WHERE id>?', [$outboxFloor]);
            $database->query('DELETE FROM idempotency_keys WHERE id>?', [$idemFloor]);
            $database->query('UPDATE wallets SET balance_irt=?,balance_usdt=?,locked_irt=?,locked_usdt=?,is_frozen=?,last_withdrawal_at=? WHERE user_id=1', [
                $this->requireRow($wallet)->balance_irt,$this->requireRow($wallet)->balance_usdt,$this->requireRow($wallet)->locked_irt,$this->requireRow($wallet)->locked_usdt,$this->requireRow($wallet)->is_frozen,$this->requireRow($wallet)->last_withdrawal_at,
            ]);
            $database->query('UPDATE users SET kyc_status=?,kyc_level=?,kyc_verified_at=? WHERE id=1', [$this->requireRow($userState)->kyc_status,$this->requireRow($userState)->kyc_level,$this->requireRow($userState)->kyc_verified_at]);
            $this->flushTestRedis();
        }
    }

    public function test_wallet_transfer_preserves_balances_double_entry_and_failure_atomicity(): void
    {
        $this->flushTestRedis();
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $wallets = $database->fetchAll('SELECT user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,is_frozen FROM wallets WHERE user_id IN (1,2) ORDER BY user_id');
        $txFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM transactions');
        $ledgerFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM ledger_entries');
        $idemFloor = (int)$database->fetchColumn('SELECT COALESCE(MAX(id),0) FROM idempotency_keys');
        try {
            $database->query('UPDATE wallets SET balance_irt=100000,locked_irt=0,is_frozen=0 WHERE user_id=1');
            $database->query('UPDATE wallets SET balance_irt=5000,locked_irt=0,is_frozen=0 WHERE user_id=2');

            $this->login('/login', 'user@chortke.ir', '123456');
            $page = $this->request('GET', '/wallet/transfer');
            $this->assertSame(200, $page['status']);
            $csrf = $this->extractCsrfToken($page['body']);
            $transferRequestId = 'e2e-transfer-' . bin2hex(random_bytes(8));
            $transfer = $this->request('POST', '/wallet/transfer', [
                '_csrf_token' => $csrf,
                'recipient' => 'support@chortke.ir',
                'amount' => '12500',
            ], ['X-Request-ID: ' . $transferRequestId, 'X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(200, $transfer['status']);
            $payload = $this->decodeJsonObject($transfer['body']);
            $this->assertTrue((bool)($payload['success'] ?? false), $transfer['body']);

            $this->assertSame('87500.00000000', number_format((float)$database->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id=1'), 8, '.', ''));
            $this->assertSame('17500.00000000', number_format((float)$database->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id=2'), 8, '.', ''));
            $rows = $database->fetchAll("SELECT transaction_id,user_id,amount,balance_before,balance_after FROM transactions WHERE id>? AND type='transfer' ORDER BY id", [$txFloor]);
            $this->assertCount(2, $rows);
            $this->assertSame([1,2], array_map(static fn(object $row): int => (int)$row->user_id, $rows));
            $this->assertSame('0.00000000', number_format(array_sum(array_map(static fn(object $row): float => (float)$row->amount, $rows)), 8, '.', ''));
            $sourceTransactionId = (string)$rows[0]->transaction_id;
            $ledger = $database->fetchAll('SELECT account,debit,credit,currency FROM ledger_entries WHERE id>? AND transaction_id=? ORDER BY id', [$ledgerFloor,$sourceTransactionId]);
            $this->assertCount(2, $ledger);
            $this->assertSame('12500.00000000', number_format(array_sum(array_map(static fn(object $row): float => (float)$row->debit, $ledger)), 8, '.', ''));
            $this->assertSame('12500.00000000', number_format(array_sum(array_map(static fn(object $row): float => (float)$row->credit, $ledger)), 8, '.', ''));

            $beforeSelf = (string)$database->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id=1');
            $self = $this->request('POST', '/wallet/transfer', [
                '_csrf_token' => $csrf, 'recipient' => 'user@chortke.ir', 'amount' => '1000',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $selfPayload = $this->decodeJsonObject($self['body']);
            $this->assertFalse((bool)($selfPayload['success'] ?? true));
            $this->assertSame($beforeSelf, (string)$database->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id=1'));

            $insufficient = $this->request('POST', '/wallet/transfer', [
                '_csrf_token' => $csrf, 'recipient' => 'support@chortke.ir', 'amount' => '999999999',
            ], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
            $this->assertSame(422, $insufficient['status']);
            $this->assertSame('87500.00000000', number_format((float)$database->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id=1'), 8, '.', ''));
            $this->assertSame(2, (int)$database->fetchColumn("SELECT COUNT(*) FROM transactions WHERE id>? AND type='transfer'", [$txFloor]));
        } finally {
            $database->query('DELETE FROM ledger_entries WHERE id>?', [$ledgerFloor]);
            $database->query('DELETE FROM transactions WHERE id>?', [$txFloor]);
            $database->query('DELETE FROM idempotency_keys WHERE id>?', [$idemFloor]);
            foreach ($wallets as $wallet) {
                $database->query('UPDATE wallets SET balance_irt=?,balance_usdt=?,locked_irt=?,locked_usdt=?,is_frozen=? WHERE user_id=?', [
                    $wallet->balance_irt,$wallet->balance_usdt,$wallet->locked_irt,$wallet->locked_usdt,$wallet->is_frozen,$wallet->user_id,
                ]);
            }
            $this->flushTestRedis();
        }
    }

    public function test_avatar_upload_accepts_real_png_persists_and_serves_securely(): void
    {
        $this->login('/login', 'user@chortke.ir', '123456');
        $dashboard = $this->request('GET', '/dashboard');
        $csrf = $this->extractCsrfToken($dashboard['body']);
        $png = $this->createTempFile($this->onePixelPng());
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $oldAvatar = (string) $database->fetchColumn('SELECT avatar FROM users WHERE email=?', ['user@chortke.ir']);
        $newAvatar = '';

        try {
            $upload = $this->request('POST', '/profile/upload-avatar', [
                '_csrf_token' => $csrf,
                'avatar' => new \CURLFile($png, 'image/png', 'avatar.png'),
            ]);
            $this->assertSame(200, $upload['status']);
            $payload = $this->decodeJsonObject($upload['body']);
            $this->assertTrue((bool) ($payload['success'] ?? false), $upload['body']);

            $newAvatar = (string) $database->fetchColumn('SELECT avatar FROM users WHERE email=?', ['user@chortke.ir']);
            $this->assertRegExp('/^[a-f0-9]{24}\.png$/', $newAvatar);
            $uploadLog = $database->fetch(
                'SELECT user_id,mime_type,size_bytes FROM file_logs WHERE folder=? AND filename=? ORDER BY id DESC LIMIT 1',
                ['avatars', $newAvatar]
            );
            $this->assertNotNull($uploadLog, 'A successful upload must persist its file audit record.');
            $this->assertSame(1, (int)$uploadLog->user_id);
            $this->assertSame('image/png', (string)$uploadLog->mime_type);
            $this->assertGreaterThan(0, (int)$uploadLog->size_bytes);
            $storedPath = base_path('public/uploads/avatars/' . $newAvatar);
            $this->assertFileExists($storedPath);
            $this->assertSame('image/png', mime_content_type($storedPath));
            $storedHash = hash_file('sha256', $storedPath);
            $this->assertIsString($storedHash);

            $served = $this->request('GET', '/file/view/avatars/' . rawurlencode($newAvatar));
            $this->assertSame(200, $served['status']);
            $this->assertSame('image/png', $this->header($served, 'content-type'));
            $this->assertStringContainsString('inline', strtolower($this->header($served, 'content-disposition')));
            $this->assertSame('nosniff', strtolower($this->header($served, 'x-content-type-options')));
            $this->assertSame($storedHash, hash('sha256', $served['body']));
        } finally {
            if ($newAvatar !== '') {
                @unlink(base_path('public/uploads/avatars/' . $newAvatar));
                $database->query('DELETE FROM file_logs WHERE folder=? AND filename=?', ['avatars', $newAvatar]);
            }
            $database->query('UPDATE users SET avatar=? WHERE email=?', [$oldAvatar, 'user@chortke.ir']);
            @unlink($png);
        }
    }

    public function test_upload_rejects_double_extension_mime_mismatch_and_oversize_files(): void
    {
        $this->login('/login', 'user@chortke.ir', '123456');
        $dashboard = $this->request('GET', '/dashboard');
        $csrf = $this->extractCsrfToken($dashboard['body']);
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $avatarBefore = (string) $database->fetchColumn('SELECT avatar FROM users WHERE email=?', ['user@chortke.ir']);

        $cases = [
            [$this->createTempFile($this->onePixelPng()), 'avatar.php.png', 'image/png'],
            [$this->createTempFile("not an image\n"), 'avatar.png', 'image/png'],
            [$this->createTempFile($this->onePixelPng() . str_repeat("\0", 2 * 1024 * 1024 + 1)), 'oversize.png', 'image/png'],
        ];

        try {
            foreach ($cases as [$path, $name, $declaredMime]) {
                $response = $this->request('POST', '/profile/upload-avatar', [
                    '_csrf_token' => $csrf,
                    'avatar' => new \CURLFile($path, $declaredMime, $name),
                ]);
                $this->assertGreaterThanOrEqual(400, $response['status'], "Unsafe upload '{$name}' was accepted.");
                $this->assertLessThan(500, $response['status'], "Unsafe upload '{$name}' caused an internal server error.");
                $this->assertSame($avatarBefore, (string) $database->fetchColumn('SELECT avatar FROM users WHERE email=?', ['user@chortke.ir']));
            }
        } finally {
            foreach ($cases as [$path]) {
                @unlink($path);
            }
        }
    }

    public function test_file_route_rejects_encoded_path_traversal(): void
    {
        foreach ([
            '/file/view/%2e%2e/passwd.png',
            '/file/view/avatars/%2e%2e%2f.env.png',
            '/file/view/avatars/%252e%252e%252f.env.png',
        ] as $path) {
            $response = $this->request('GET', $path);
            $this->assertContains($response['status'], [403, 404]);
            $this->assertStringNotContainsString('APP_KEY=', $response['body']);
            $this->assertStringNotContainsString('root:', $response['body']);
        }
    }

    public function test_payment_callback_get_rate_limit_returns_real_429(): void
    {
        $this->flushTestRedis();
        try {
            $path = '/payment/callback/runtime-test-' . bin2hex(random_bytes(6));
            $first = $this->request('GET', $path);
            $this->assertSame(405, $first['status']);

            $second = $this->request('GET', $path);
            $this->assertSame(429, $second['status']);
            $this->assertSame('1', $this->header($second, 'x-ratelimit-limit'));
            $this->assertSame('0', $this->header($second, 'x-ratelimit-remaining'));
            $this->assertGreaterThan(0, (int) $this->header($second, 'retry-after'));
            $payload = $this->decodeJsonObject($second['body']);
            $this->assertFalse((bool) ($payload['success'] ?? true));
        } finally {
            $this->flushTestRedis();
        }
    }

    /** @return array{status:int,headers:array<string,list<string>>,body:string} */
    private function login(string $path, string $email, string $password): array
    {
        $page = $this->request('GET', $path);
        $this->assertSame(200, $page['status']);
        $csrf = $this->extractCsrfToken($page['body']);

        return $this->request('POST', $path, [
            '_csrf_token' => $csrf,
            'email' => $email,
            'password' => $password,
            'remember' => 'on',
        ]);
    }

    private function requireRow(?\stdClass $row): \stdClass
    {
        $this->assertInstanceOf(\stdClass::class, $row);
        return $row;
    }

    /** @return array<int|string, mixed> */
    private function requireArray(mixed $value): array
    {
        $this->assertIsArray($value);
        return $value;
    }

    /** @return array<int|string, mixed> */
    private function decodeJsonObject(string $json): array
    {
        $decoded = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    /**
     * @param array<string, mixed> $form
     * @param list<string> $extraHeaders
     * @return array{status:int,headers:array<string,list<string>>,body:string}
     */
    private function request(string $method, string $path, array $form = [], array $extraHeaders = []): array
    {
        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            $this->fail('Unable to initialize cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERAGENT => 'Chortke-E2E/1.0',
            CURLOPT_HTTPHEADER => array_merge(['Accept-Language: fa-IR', 'Accept-Encoding: identity'], $extraHeaders),
        ]);
        if ($method === 'POST') {
            $isMultipart = false;
            foreach ($form as $value) {
                if ($value instanceof \CURLFile) {
                    $isMultipart = true;
                    break;
                }
            }
            $isJson = false;
            foreach ($extraHeaders as $header) {
                if (str_starts_with(strtolower($header), 'content-type: application/json')) {
                    $isJson = true;
                    break;
                }
            }
            $postBody = $isMultipart
                ? $form
                : ($isJson ? json_encode($form, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : http_build_query($form));
            curl_setopt($handle, CURLOPT_POSTFIELDS, $postBody);
            if (!$isMultipart) {
                $defaultContentType = $isJson ? [] : ['Content-Type: application/x-www-form-urlencoded'];
                curl_setopt($handle, CURLOPT_HTTPHEADER, array_merge(
                    ['Accept-Language: fa-IR', 'Accept-Encoding: identity'],
                    $defaultContentType,
                    $extraHeaders
                ));
            }
        }

        $raw = curl_exec($handle);
        if (!is_string($raw)) {
            $error = curl_error($handle);
            curl_close($handle);
            $this->fail('E2E HTTP server is unavailable: ' . $error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        $headerBlock = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $headers = [];
        foreach (preg_split('/\r\n|\n|\r/', trim($headerBlock)) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))][] = trim($value);
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /** @param array{headers:array<string,list<string>>} $response */
    private function header(array $response, string $name): string
    {
        return implode(', ', $response['headers'][strtolower($name)] ?? []);
    }

    private function extractCsrfToken(string $html): string
    {
        $matched = preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $html, $matches);
        if ($matched !== 1) {
            $matched = preg_match('/name="csrf-token"\s+content="([^"]+)"/', $html, $matches);
        }
        $this->assertSame(1, $matched, 'Rendered page does not expose a CSRF token.');
        $this->assertSame(64, strlen($matches[1] ?? ''), 'CSRF token has an invalid length.');
        $token = $matches[1] ?? null;
        $this->assertIsString($token);
        return $token;
    }

    private function resetSeedSecurityState(): void
    {
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $rows = $database->fetchAll(
            "SELECT id FROM users WHERE email IN ('user@chortke.ir','support@chortke.ir','admin@chortke.ir','superadmin@chortke.ir')"
        );
        $userIds = array_map(static fn(object $row): int => (int) $row->id, $rows);
        if ($userIds === []) {
            $this->fail('E2E seed users are missing.');
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $database->query("UPDATE users SET fraud_score = 0, is_blacklisted = 0, status = 'active' WHERE id IN ({$placeholders})", $userIds);
        $database->query("UPDATE user_scores SET score = 0 WHERE domain = 'fraud' AND user_id IN ({$placeholders})", $userIds);
        $database->query("DELETE FROM score_events WHERE entity_type = 'user' AND domain = 'fraud' AND entity_id IN ({$placeholders})", $userIds);
        $database->query("DELETE FROM user_sessions WHERE user_id IN ({$placeholders})", $userIds);

        $container = \Core\Application::getInstance()->container;
        $rateLimiter = $container->make(\Core\RateLimiter::class);
        $rateLimiter->clear('login_ip:' . hash('sha256', '127.0.0.1'));
        $cache = $container->make(\App\Contracts\CacheInterface::class);
        foreach (['user@chortke.ir', 'support@chortke.ir', 'admin@chortke.ir', 'superadmin@chortke.ir'] as $email) {
            $rateLimiter->clear('login_id:' . hash('sha256', $email));
            $cache->forget('login_attempts:' . hash('sha256', $email));
        }

        $redisConfig = config('redis', []);
        $this->assertIsArray($redisConfig);
        $redis = new \Redis();
        $redis->connect(str_value($redisConfig['host'] ?? '127.0.0.1'), int_value($redisConfig['port'] ?? 6379), 2.0);
        $password = str_value($redisConfig['password'] ?? '');
        if ($password !== '') {
            $redis->auth($password);
        }
        $prefix = str_value($redisConfig['prefix'] ?? '');
        foreach ($userIds as $userId) {
            $redis->del("score:user:{$userId}");
            if ($prefix !== '') {
                $redis->del($prefix . "score:user:{$userId}");
            }
        }
        $redis->close();
    }

    private function runtimeApplicationKey(): string
    {
        $runtimeEnv = parse_ini_file(base_path('.env'), false, INI_SCANNER_RAW);
        $key = is_array($runtimeEnv) && is_string($runtimeEnv['APP_KEY'] ?? null)
            ? trim($runtimeEnv['APP_KEY'], " \t\n\r\0\x0B\"'")
            : '';
        if (strlen($key) < 32) {
            $this->fail('Runtime APP_KEY is unavailable for token contract verification.');
        }
        return $key;
    }

    /** @return array{token:string,answer:int} */
    private function fetchMathCaptcha(): array
    {
        $response = $this->request('GET', '/captcha/refresh?type=math', [], [
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json',
        ]);
        $this->assertSame(200, $response['status']);
        $payload = $this->decodeJsonObject($response['body']);
        $question = is_string($payload['question'] ?? null) ? $payload['question'] : '';
        $token = is_string($payload['token'] ?? null) ? $payload['token'] : '';
        $matched = preg_match('/^(\d+)\s*([+\-*])\s*(\d+)\s*=\s*\?$/u', trim($question), $parts);
        $this->assertSame(1, $matched, 'Captcha refresh did not return a math question.');
        $this->assertRegExp('/^[a-f0-9]{32}$/', $token);
        $left = (int)($parts[1] ?? 0);
        $right = (int)($parts[3] ?? 0);
        $answer = match ($parts[2] ?? '+') {
            '-' => $left - $right,
            '*' => $left * $right,
            default => $left + $right,
        };
        return ['token' => $token, 'answer' => $answer];
    }

    private function totp(string $base32, int $slice): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper($base32)) as $character) {
            $position = strpos($alphabet, $character);
            if ($position === false) {
                throw new \RuntimeException('Invalid Base32 TOTP secret.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $secret = '';
        for ($offset = 0; $offset + 8 <= strlen($bits); $offset += 8) {
            $secret .= chr(int_value(bindec(substr($bits, $offset, 8))));
        }
        $counter = pack('N2', 0, $slice);
        $hash = hash_hmac('sha1', $counter, $secret, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function onePixelPng(): string
    {
        $decoded = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        if (!is_string($decoded)) {
            throw new \RuntimeException('Unable to decode PNG fixture.');
        }
        return $decoded;
    }

    private function createTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'chortke-upload-');
        if ($path === false || file_put_contents($path, $content) === false) {
            $this->fail('Unable to create upload fixture.');
        }
        return $path;
    }

    private function flushTestRedis(): void
    {
        $redisConfig = config('redis', []);
        $this->assertIsArray($redisConfig);
        $redis = new \Redis();
        $redis->connect(str_value($redisConfig['host'] ?? '127.0.0.1'), int_value($redisConfig['port'] ?? 6379), 2.0);
        $password = str_value($redisConfig['password'] ?? '');
        if ($password !== '') {
            $redis->auth($password);
        }
        $redis->select(int_value($redisConfig['database'] ?? 0));
        $redis->flushDB();
        $redis->close();
    }

    private function assertNoRuntimeDiagnostics(string $body): void
    {
        foreach (['Deprecated', 'Warning:', 'Fatal error', 'Stack trace', 'SQLSTATE['] as $diagnostic) {
            $this->assertStringNotContainsString($diagnostic, $body);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // REGRESSION: POST /investment/withdraw از میان استک واقعی HTTP
    //
    // این مسیر توسط AdvancedFraudMiddleware محافظت می‌شود (routes/user.php).
    // پیش‌تر آن میدل‌ویر فراخوانی کنترلر را داخل try خود قرار داده بود، پس هر
    // استثنای کنترلر در catch (\Throwable) گرفته می‌شد و به «۵۰۳ سرویس در
    // دسترس نیست» تبدیل می‌گشت — یعنی یک خطای کاملاً قابل‌انتظار دامنه به
    // خرابی زیرساخت ترجمه می‌شد. تست زیر تضمین می‌کند پاسخ واقعی HTTP دیگر
    // ۵۰۳ نیست و خطای دامنه با کد و پیام درست به کاربر می‌رسد.
    // ─────────────────────────────────────────────────────────────

    public function test_investment_withdraw_returns_a_domain_error_not_a_503(): void
    {
        $login = $this->login('/login', 'user@chortke.ir', '123456');
        $this->assertSame('/dashboard', (string) parse_url($this->header($login, 'location'), PHP_URL_PATH));

        $response = $this->request(
            'POST',
            '/investment/withdraw',
            ['withdrawal_type' => 'profit_only'],
            [
                'Content-Type: application/json',
                'X-Requested-With: XMLHttpRequest',
                'X-CSRF-TOKEN: ' . $this->currentCsrfToken('/investment'),
            ]
        );

        // هستهٔ رگرسیون: میدل‌ویر تقلب نباید خطای کنترلر را ببلعد و ۵۰۳ بسازد.
        $this->assertNotSame(503, $response['status'], 'Fraud middleware swallowed a controller exception into a 503.');
        $this->assertNotSame(500, $response['status'], 'A business/domain error must never surface as a server fault.');

        $payload = $this->decodeJsonObject($response['body']);
        $this->assertFalse($payload['success']);

        // کاربر seed سرمایه‌گذاری فعالی ندارد ⇒ خطای دامنه‌ای «یافت نشد».
        $this->assertSame(404, $response['status']);
        $this->assertSame('NOT_FOUND', $payload['error_code']);
        $this->assertSame('سرمایه‌گذاری فعالی ندارید.', $payload['message']);

        // پیام‌های شکست میدل‌ویر تقلب نباید در پاسخ ظاهر شوند.
        $this->assertStringNotContainsString('سرویس موقتاً در دسترس نیست', $response['body']);
        $this->assertNoRuntimeDiagnostics($response['body']);
    }

    /**
     * یک ورودی نامعتبر باید ۴۲۲ اعتبارسنجی بگیرد — این نشان می‌دهد درخواست
     * واقعاً به کنترلر می‌رسد و در لایهٔ میدل‌ویر متوقف یا مخدوش نمی‌شود.
     */
    public function test_investment_withdraw_rejects_an_invalid_withdrawal_type_with_422(): void
    {
        $login = $this->login('/login', 'user@chortke.ir', '123456');
        $this->assertSame('/dashboard', (string) parse_url($this->header($login, 'location'), PHP_URL_PATH));

        $response = $this->request(
            'POST',
            '/investment/withdraw',
            ['withdrawal_type' => 'not-a-real-type'],
            [
                'Content-Type: application/json',
                'X-Requested-With: XMLHttpRequest',
                'X-CSRF-TOKEN: ' . $this->currentCsrfToken('/investment'),
            ]
        );

        $this->assertSame(422, $response['status']);
        $this->assertNotSame(503, $response['status']);

        $payload = $this->decodeJsonObject($response['body']);
        $this->assertFalse($payload['success']);
        $this->assertArrayHasKey('withdrawal_type', $this->requireArray($payload['errors'] ?? []));
        $this->assertNoRuntimeDiagnostics($response['body']);
    }

    /**
     * مهمان نباید بتواند برداشت انجام دهد؛ باید به ورود هدایت شود (نه ۵۰۳).
     */
    public function test_guest_cannot_post_an_investment_withdrawal(): void
    {
        $response = $this->request('POST', '/investment/withdraw', ['withdrawal_type' => 'profit_only']);

        $this->assertNotSame(503, $response['status']);
        $this->assertContains($response['status'], [302, 401, 403, 419]);
    }

    /**
     * استخراج توکن CSRF جاری از یک صفحهٔ احرازشده.
     */
    private function currentCsrfToken(string $path): string
    {
        $page = $this->request('GET', $path);
        $this->assertSame(200, $page['status'], "{$path} was not reachable for CSRF extraction.");

        return $this->extractCsrfToken($page['body']);
    }
}
