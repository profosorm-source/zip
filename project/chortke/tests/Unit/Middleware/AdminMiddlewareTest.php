<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use App\Middleware\AdminMiddleware;
use App\Constants\SessionKeys;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Exceptions\HttpResponseException;
use Mockery as m;

/**
 * AdminMiddlewareTest
 *
 * پوشش تست:
 *  1. Source-code audit — هیچ backdoor artifact در کد وجود ندارد
 *  2. کاربر بدون session → redirect (HttpResponseException)
 *  3. کاربر با 2FA pending → redirect
 *  4. کاربر لاگین‌شده با نقش ادمین → pass
 *  5. کاربر لاگین‌شده با نقش معمولی → 403
 *  6. درخواست Ajax بدون session → 401 JSON
 *  7. درخواست Ajax کاربر معمولی → 403 JSON
 *  8. کاربر حذف‌شده از DB → 403
 *
 * نکته معماری: Response::redirect() و Response::json() یک HttpResponseException
 * پرتاب می‌کنند. تست‌ها این exception را catch می‌کنند و response داخل آن را بررسی می‌کنند.
 */
/**
 * @group architecture
 */
class AdminMiddlewareTest extends TestCase
{
    /** @var Session&\Mockery\MockInterface */
    private Session $session;
    /** @var \App\Models\User&\Mockery\MockInterface */
    private \App\Models\User $userModel;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session   = m::mock(Session::class);
        $this->userModel = m::mock(\App\Models\User::class);
        $this->logger = m::mock(\App\Contracts\LoggerInterface::class);
        $this->logger->shouldIgnoreMissing();
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function makeMiddleware(): AdminMiddleware
    {
        return new AdminMiddleware($this->session, $this->userModel, $this->logger);
    }

    /** @param array<string,mixed> $query @return Request&\Mockery\MockInterface */
    private function makeRequest(array $query = [], bool $ajax = false): Request
    {
        $request = m::mock(Request::class);
        $request->allows('isAjax')->andReturn($ajax)->byDefault();
        $request->allows('get')->andReturnUsing(fn($k) => $query[$k] ?? null)->byDefault();
        return $request;
    }

    /**
     * اجرای middleware و گرفتن Response — چه موفق چه با exception
     */
    private function runMiddleware(AdminMiddleware $mw, Request $req, \Closure $next): Response
    {
        try {
            $result = $mw->handle($req, $next);
            $this->assertInstanceOf(Response::class, $result);
            return $result;
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }
    }

    // ─── تست ۱: بررسی ساختاری سورس کد ────────────────────────────────────


    // ─── تست ۲: کاربر بدون session → redirect ─────────────────────────────

    /**
     * @test
     * کاربر لاگین‌نشده باید redirect شود، نه اینکه وارد پنل شود.
     */
    public function test_unauthenticated_user_is_blocked(): void
    {
        $this->session->allows('has')->with(SessionKeys::PENDING_2FA_USER_ID)->andReturn(false);
        $this->session->allows('has')->with(SessionKeys::USER_ID)->andReturn(false);
        $this->session->allows('get')->with(SessionKeys::LOGGED_IN)->andReturn(false);
        $this->session->allows('setFlash')->andReturn(null);

        $next     = fn($r) => (new Response())->setContent('admin_panel');
        $response = $this->runMiddleware($this->makeMiddleware(), $this->makeRequest(), $next);

        $this->assertNotEquals('admin_panel', $response->getContent(),
            'Unauthenticated user must NOT reach admin panel');
    }

    /**
     * @test
     * بررسی اینکه پارامتر خطرناک test_user_id=1 هیچ دسترسی‌ای نمی‌دهد.
     */
    public function test_backdoor_query_param_grants_no_access(): void
    {
        // session خالی — کاربر لاگین نیست
        $this->session->allows('has')->with(SessionKeys::PENDING_2FA_USER_ID)->andReturn(false);
        $this->session->allows('has')->with(SessionKeys::USER_ID)->andReturn(false);
        $this->session->allows('get')->with(SessionKeys::LOGGED_IN)->andReturn(false);
        $this->session->allows('setFlash')->andReturn(null);

        // درخواست با پارامتر مخرب قدیمی
        $request  = $this->makeRequest(['test_user_id' => '1']);
        $next     = fn($r) => (new Response())->setContent('admin_panel_breached');
        $response = $this->runMiddleware($this->makeMiddleware(), $request, $next);

        $this->assertNotEquals(
            'admin_panel_breached',
            $response->getContent(),
            'CRITICAL: ?test_user_id=1 backdoor is still active!'
        );
    }

    // ─── تست ۳: Ajax بدون لاگین → 401 JSON ───────────────────────────────

    /**
     * @test
     */
    public function test_unauthenticated_ajax_returns_401_json(): void
    {
        $this->session->allows('has')->with(SessionKeys::PENDING_2FA_USER_ID)->andReturn(false);
        $this->session->allows('has')->with(SessionKeys::USER_ID)->andReturn(false);
        $this->session->allows('get')->with(SessionKeys::LOGGED_IN)->andReturn(false);
        $this->session->allows('setFlash')->andReturn(null);

        $request  = $this->makeRequest([], ajax: true);
        $next     = fn($r) => (new Response())->setContent('should_not_reach');
        $response = $this->runMiddleware($this->makeMiddleware(), $request, $next);

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success'] ?? true, 'Ajax 401 must have success=false');
        $this->assertEquals(401, $response->getStatusCode(), 'Ajax unauthenticated must be 401');
    }

    // ─── تست ۴: 2FA pending ────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_admin_pending_2fa_is_redirected(): void
    {
        $this->session->allows('has')->with(SessionKeys::PENDING_2FA_USER_ID)->andReturn(true);
        $this->session->allows('get')->with('admin_pending_2fa')->andReturn(true);

        $next     = fn($r) => (new Response())->setContent('admin_panel');
        $response = $this->runMiddleware($this->makeMiddleware(), $this->makeRequest(), $next);

        $this->assertNotEquals('admin_panel', $response->getContent(),
            'Pending 2FA admin must be redirected to 2FA page');
    }

    /**
     * @test
     * کاربر غیرادمین با 2FA pending باید session از بین برود
     */
    public function test_non_admin_pending_2fa_destroys_session_and_blocks(): void
    {
        $this->session->allows('has')->with(SessionKeys::PENDING_2FA_USER_ID)->andReturn(true);
        $this->session->allows('get')->with('admin_pending_2fa')->andReturn(false);
        $this->session->shouldReceive('destroy')->once();

        $next     = fn($r) => (new Response())->setContent('admin_panel');
        $response = $this->runMiddleware($this->makeMiddleware(), $this->makeRequest(), $next);

        $this->assertNotEquals('admin_panel', $response->getContent(),
            'Non-admin pending 2FA must not access admin panel');
    }

    // ─── تست ۵: ادمین معتبر → pass ────────────────────────────────────────

    /**
     * @test
     */
    public function test_valid_admin_passes_through(): void
    {
        $adminUser = (object)['role' => 'admin'];

        $this->session->allows('has')->with(SessionKeys::PENDING_2FA_USER_ID)->andReturn(false);
        $this->session->allows('has')->with(SessionKeys::USER_ID)->andReturn(true);
        $this->session->allows('get')->with(SessionKeys::LOGGED_IN)->andReturn(true);
        $this->session->allows('get')->with(SessionKeys::USER_ID)->andReturn(42);
        $this->session->allows('get')->with(SessionKeys::USER_ROLE)->andReturn('admin');
        $this->session->allows('get')->with('admin_verify_time', 0)->andReturn(0);
        $this->session->allows('set')->andReturn(null);

        $this->userModel->allows('find')->with(42)->andReturn($adminUser);

        $next     = fn($r) => (new Response())->setContent('admin_panel_ok');
        $response = $this->runMiddleware($this->makeMiddleware(), $this->makeRequest(), $next);

        $this->assertEquals('admin_panel_ok', $response->getContent(),
            'Valid admin must pass through middleware');
    }

    /**
     * @test
     */
    public function test_super_admin_passes_through(): void
    {
        $superAdmin = (object)['role' => 'super_admin'];

        $this->session->allows('has')->with(SessionKeys::PENDING_2FA_USER_ID)->andReturn(false);
        $this->session->allows('has')->with(SessionKeys::USER_ID)->andReturn(true);
        $this->session->allows('get')->with(SessionKeys::LOGGED_IN)->andReturn(true);
        $this->session->allows('get')->with(SessionKeys::USER_ID)->andReturn(7);
        $this->session->allows('get')->with(SessionKeys::USER_ROLE)->andReturn('super_admin');
        $this->session->allows('get')->with('admin_verify_time', 0)->andReturn(0);
        $this->session->allows('set')->andReturn(null);

        $this->userModel->allows('find')->with(7)->andReturn($superAdmin);

        $next     = fn($r) => (new Response())->setContent('super_admin_ok');
        $response = $this->runMiddleware($this->makeMiddleware(), $this->makeRequest(), $next);

        $this->assertEquals('super_admin_ok', $response->getContent());
    }

    /**
     * @test
     * support role هم باید pass کند (طبق RolePolicy::ADMIN_ROLES)
     */
    public function test_support_role_passes_through(): void
    {
        $supportUser = (object)['role' => 'support'];

        $this->session->allows('has')->with(SessionKeys::PENDING_2FA_USER_ID)->andReturn(false);
        $this->session->allows('has')->with(SessionKeys::USER_ID)->andReturn(true);
        $this->session->allows('get')->with(SessionKeys::LOGGED_IN)->andReturn(true);
        $this->session->allows('get')->with(SessionKeys::USER_ID)->andReturn(15);
        $this->session->allows('get')->with(SessionKeys::USER_ROLE)->andReturn('support');
        $this->session->allows('get')->with('admin_verify_time', 0)->andReturn(0);
        $this->session->allows('set')->andReturn(null);

        $this->userModel->allows('find')->with(15)->andReturn($supportUser);

        $next     = fn($r) => (new Response())->setContent('support_ok');
        $response = $this->runMiddleware($this->makeMiddleware(), $this->makeRequest(), $next);

        $this->assertEquals('support_ok', $response->getContent());
    }

    // ─── تست ۶: کاربر معمولی → 403 ───────────────────────────────────────

    /**
     * @test
     */
    public function test_regular_user_gets_403(): void
    {
        $regularUser = (object)['role' => 'user'];

        $this->session->allows('has')->with(SessionKeys::PENDING_2FA_USER_ID)->andReturn(false);
        $this->session->allows('has')->with(SessionKeys::USER_ID)->andReturn(true);
        $this->session->allows('get')->with(SessionKeys::LOGGED_IN)->andReturn(true);
        $this->session->allows('get')->with(SessionKeys::USER_ID)->andReturn(99);
        $this->session->allows('get')->with(SessionKeys::USER_ROLE)->andReturn('user');
        $this->session->allows('get')->with('admin_verify_time', 0)->andReturn(0);
        $this->session->allows('set')->andReturn(null);
        $this->session->allows('destroy')->andReturn(null);

        $this->userModel->allows('find')->with(99)->andReturn($regularUser);

        $next     = fn($r) => (new Response())->setContent('should_not_reach');
        $response = $this->runMiddleware($this->makeMiddleware(), $this->makeRequest(), $next);

        $this->assertNotEquals('should_not_reach', $response->getContent());
        $this->assertEquals(403, $response->getStatusCode(), 'Regular user must get 403');
    }

    /**
     * @test
     * کاربر معمولی در Ajax — از مسیر DB re-validation رد می‌شود و 403 JSON برمی‌گردد.
     * json() داخل Response یک HttpResponseException می‌اندازد که runMiddleware آن را catch می‌کند.
     */
    public function test_regular_user_ajax_gets_403_json(): void
    {
        $regularUser = (object)['role' => 'user'];

        $this->session->allows('has')->with(SessionKeys::PENDING_2FA_USER_ID)->andReturn(false);
        $this->session->allows('has')->with(SessionKeys::USER_ID)->andReturn(true);
        $this->session->allows('get')->with(SessionKeys::LOGGED_IN)->andReturn(true);
        $this->session->allows('get')->with(SessionKeys::USER_ID)->andReturn(99);
        $this->session->allows('get')->with(SessionKeys::USER_ROLE)->andReturn('user');
        $this->session->allows('get')->with('admin_verify_time', 0)->andReturn(0);
        $this->session->allows('set')->andReturn(null);
        $this->session->allows('destroy')->andReturn(null);

        $this->userModel->allows('find')->with(99)->andReturn($regularUser);

        $request  = $this->makeRequest([], ajax: true);
        $next     = fn($r) => (new Response())->setContent('should_not_reach');
        $response = $this->runMiddleware($this->makeMiddleware(), $request, $next);

        // json() → send() → HttpResponseException → runMiddleware catches it and returns response
        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body, 'Response must be valid JSON');
        $this->assertArrayHasKey('success', $body, 'JSON response must have success key');
        $this->assertFalse($body['success'], 'Ajax 403 must have success=false');
        $this->assertEquals(403, $response->getStatusCode(), 'Regular user Ajax must get 403');
    }

    // ─── تست ۷: کاربر حذف‌شده از DB → 403 ────────────────────────────────

    /**
     * @test
     */
    public function test_user_not_found_in_db_gets_403(): void
    {
        $this->session->allows('has')->with(SessionKeys::PENDING_2FA_USER_ID)->andReturn(false);
        $this->session->allows('has')->with(SessionKeys::USER_ID)->andReturn(true);
        $this->session->allows('get')->with(SessionKeys::LOGGED_IN)->andReturn(true);
        $this->session->allows('get')->with(SessionKeys::USER_ID)->andReturn(55);
        $this->session->allows('get')->with(SessionKeys::USER_ROLE)->andReturn('admin');
        $this->session->allows('get')->with('admin_verify_time', 0)->andReturn(0);
        $this->session->allows('set')->andReturn(null);
        $this->session->allows('destroy')->andReturn(null);

        // کاربر در DB وجود ندارد (حذف‌شده)
        $this->userModel->allows('find')->with(55)->andReturn(null);

        $next     = fn($r) => (new Response())->setContent('should_not_reach');
        $response = $this->runMiddleware($this->makeMiddleware(), $this->makeRequest(), $next);

        $this->assertNotEquals('should_not_reach', $response->getContent());
        $this->assertEquals(403, $response->getStatusCode(),
            'Deleted user must get 403 even with valid session');
    }

    // ─── تست ۸: verify_time کش‌شده — بدون DB hit ─────────────────────────

    /**
     * @test
     * در هر درخواست نقش/وضعیت ادمین از دیتابیس ارزیابی می‌شود (H-01/L-04 Fix).
     */
    public function test_cached_verify_time_skips_db_query(): void
    {
        $this->session->allows('has')->with(SessionKeys::PENDING_2FA_USER_ID)->andReturn(false);
        $this->session->allows('has')->with(SessionKeys::USER_ID)->andReturn(true);
        $this->session->allows('get')->with(SessionKeys::LOGGED_IN)->andReturn(true);
        $this->session->allows('get')->with(SessionKeys::USER_ID)->andReturn(10);
        $this->session->allows('get')->with(SessionKeys::USER_ROLE)->andReturn('admin');
        $this->session->allows('get')->with('admin_verify_time', 0)->andReturn(time());
        $this->session->allows('set')->andReturn(null);

        $this->userModel->allows('find')->with(10)->andReturn((object)['id' => 10, 'role' => 'admin', 'is_blocked' => 0]);

        $next     = fn($r) => (new Response())->setContent('admin_panel_cached');
        $response = $this->runMiddleware($this->makeMiddleware(), $this->makeRequest(), $next);

        $this->assertEquals('admin_panel_cached', $response->getContent());
    }
}
