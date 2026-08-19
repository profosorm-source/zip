<?php
namespace Core;

/**
 * CSRF Protection
 *
 * از Container می‌خواند — نه app() مستقیم
 */
class CSRF
{
    private Session $session;
    private Request $request;
    private UrlGenerator $urlGenerator;

    public function __construct(Session $session, Request $request, UrlGenerator $urlGenerator) {
        $this->session = $session;
        $this->request = $request;
        $this->urlGenerator = $urlGenerator;
    }

    public function generateToken(): string
    {
        if (!$this->session->has('_csrf_token')) {
            $this->session->set('_csrf_token', bin2hex(random_bytes(32)));
        }
        $token = $this->session->get('_csrf_token');
        return is_string($token) ? $token : '';
    }

    // CORE-036: Action-specific CSRF tokens for enhanced security on destructive actions
    public function generateTokenFor(string $action): string
    {
        $key = '_csrf_token_' . hash('sha256', $action);
        if (!$this->session->has($key)) {
            $this->session->set($key, bin2hex(random_bytes(32)));
        }
        $token = $this->session->get($key);
        return is_string($token) ? $token : '';
    }

    public function verifyTokenFor(string $action, ?string $token): bool
    {
        $key = '_csrf_token_' . hash('sha256', $action);
        $sessionToken = $this->session->get($key);
        if (!$sessionToken || !$token) {
            return false;
        }
        $isValid = hash_equals(str_value($sessionToken), str_value($token));
        if ($isValid) {
            $this->session->remove($key); // One-time usage
        }
        return $isValid;
    }

    public function getToken(): ?string
    {
        $token = $this->session->get('_csrf_token');
        return is_string($token) ? $token : '';
    }

    public function verify(?string $token): bool
    {
        $sessionToken = $this->getToken();
        if (!$sessionToken || !$token) return false;
        
        // MED-04 Fix: Check Origin/Referer for sensitive requests
        if (!$this->validateOrigin()) {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /**
     * MED-04 Fix: Validate Origin or Referer against application URL
     */
    private function validateOrigin(): bool
    {
        if (PHP_SAPI === 'cli') return true;
        $appOrigin = strtolower($this->urlGenerator->origin());

        $origin = str_value($this->request->header('Origin'));
        $referer = str_value($this->request->header('Referer'));
        
        if (config('app.env') === 'production' && $origin === '' && $referer === '') {
            return false;
        }

        if ($origin !== '' && $this->originOf($origin) !== $appOrigin) {
            return false;
        }
        if ($referer !== '' && $this->originOf($referer) !== $appOrigin) {
            return false;
        }

        return true;
    }

    private function originOf(string $url): ?string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        if (!is_string($scheme) || !is_string($host)) {
            return null;
        }

        return strtolower($scheme . '://' . $host . (is_int($port) ? ':' . $port : ''));
    }

    public function check(): bool
    {
        if (!in_array($this->request->method(), ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            return true;
        }
        $tokenName = is_string(config('csrf.token_name')) ? (string) config('csrf.token_name') : '_token';
        // M16 Fix: پشتیبانی کامل از هدرهای کلاینت‌های Vue.js و Axios با چک کردن X-XSRF-TOKEN به عنوان جایگزین
        $token = is_string($this->request->input($tokenName)) ? (string) $this->request->input($tokenName)
                 : (is_string($this->request->header('X-CSRF-TOKEN')) ? (string) $this->request->header('X-CSRF-TOKEN')
                 : (is_string($this->request->header('X-XSRF-TOKEN')) ? (string) $this->request->header('X-XSRF-TOKEN')
                 // BACKWARD-COMPAT: Some legacy forms still use _token or csrf_token names.
                 : (is_string($this->request->input('_token')) ? (string) $this->request->input('_token')
                 : (is_string($this->request->input('csrf_token')) ? (string) $this->request->input('csrf_token')
                 : null))));
        return $this->verify($token);
    }

    public function validate(): void
    {
        if (!$this->check()) {
            if (function_exists('logger')) {
                try {
                    logger()->warning('CSRF token validation failed', [
                        'channel' => 'security',
                        'ip' => function_exists('get_client_ip') ? get_client_ip() : 'unknown',
                        'uri' => $this->request->uri(),
                        'method' => $this->request->method(),
                    ]);
                } catch (\Throwable $e) {
                    // ignore logging failure
                }
            }

            // ✅ Fix L1: پرتاب SecurityException به جای exit مستقیم
            // این امکان می‌دهد ExceptionHandler بتواند پاسخ متناسب را هندل کند
            throw new \Core\Exceptions\SecurityException(
                'CSRF token validation failed',
                403
            );
        }
    }

    public function regenerate(): string
    {
        $this->session->remove('_csrf_token');
        return $this->generateToken();
    }
}
