<?php

declare(strict_types=1);
namespace Core;

/**
 * Request Handler
 * 
 * مدیریت درخواست‌های HTTP
 */
class Request
{
    public string $method = 'GET';
    public string $uri = '/';
    /** @var array<string, mixed> */
    public array $params = [];
    /** @var array<string, mixed> */
    public array $query = [];
    /** @var array<string, mixed> */
    public array $body = [];
    /** @var array<string, mixed> */
    public array $files = [];
    /** @var array<string, string> */
    public array $headers = [];
    // FIX C-6: کش محتوای php://input — stream فقط یک بار قابل خواندن است
    private string $rawInput = '';
    /** @var array<string, mixed>|null */
    private ?array $parsedBody = null;
    private ?\stdClass $user = null;
    /** @var array<string, mixed> */
    private array $attributes = [];
    private ?string $nonce = null; // HIGH-11 Fix: CSP Nonce for secure script execution

    /**
     * HIGH-11 Fix: Generate or retrieve a single-use nonce for CSP
     */
    public function nonce(): string
    {
        if ($this->nonce === null) {
            $this->nonce = bin2hex(random_bytes(16));
        }
        return $this->nonce;
    }

    public function setUser(\stdClass $user): void
    {
        $this->user = $user;
    }

    public function user(): ?\stdClass
    {
        return $this->user;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function getUser(): ?\stdClass
    {
        // M12 Fix: حذف ابهام و ایجاد مستعار (Alias) تمیز برای سازگاری ۱۰۰ درصدی با سایر بخش‌های نرم‌افزار
        return $this->user();
    }

    public function __construct() {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        // CORE-023: Support method override
        if ($method === 'POST') {
            $methodOverride = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $_POST['_method'] ?? 'POST';
            $method = strtoupper((string)$methodOverride);
        }
        
        $this->method  = $method;
        $this->uri     = $this->parseUri();
        $this->query   = $_GET;
        $this->files   = $_FILES;
        $this->headers = $this->parseHeaders();

        // CORE-021: Body size enforcement — دو لایه دفاعی
        //
        // ── لایه ۱: Content-Length header (اگر client ارسال کرده باشد) ──────
        // سریع‌ترین check — بلافاصله abort می‌کند بدون خواندن stream.
        // اما client می‌تواند این header را ارسال نکند یا دروغ بگوید.
        $maxBody = int_value(config('request.max_body_bytes', 12 * 1024 * 1024)); // 12 MB default; feature-level validators enforce stricter limits
        $contentLength = intval($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maxBody) {
            throw new \Core\Exceptions\PayloadTooLargeException();
        }

        // ── لایه ۲: stream_get_contents با byte limit ─────────────────────────
        // اصلاح اصلی (CORE-021-FIX):
        //   - file_get_contents('php://input') هیچ محدودیتی ندارد
        //     → attacker بدون Content-Length می‌تواند body نامحدود بفرستد
        //   - stream_get_contents با $length = $maxBody + 1 حداکثر یک byte
        //     بیش از حد مجاز می‌خواند تا overflow را تشخیص دهد
        //   - +1 ضروری است: اگر دقیقاً $maxBody بخوانیم، نمی‌دانیم stream
        //     تمام شده یا ادامه دارد
        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open php://input stream.');
        }
        $this->rawInput = (string) stream_get_contents($stream, $maxBody + 1);
        fclose($stream);

        if (strlen($this->rawInput) > $maxBody) {
            throw new \Core\Exceptions\PayloadTooLargeException();
        }

        // M11 Fix: انتقال کدهای سنگین و تکراری پارس بادی به متد parseBody جهت بارگذاری کاملاً Lazy (تنبل)
        $this->body = $_POST;
    }

public function isJson(): bool
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    return str_contains(strtolower((string)$contentType), 'application/json');
}

    /**
     * دریافت Method
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * دریافت URI
     */
    public function uri(): string
    {
        return $this->uri;
    }

    /** @param array<string, mixed> $data */
    public function setPost(array $data): self
    {
        $this->body = $data;
        $this->parsedBody = $data;
        return $this;
    }
 /**
     * گرفتن IP کاربر
     * FIX B-01: حذف HTTP_CLIENT_IP و HTTP_X_FORWARDED_FOR — هر دو جعل‌پذیرند.
     * فقط REMOTE_ADDR قابل اعتماد است.
     */
    public function ip(): string
    {
        return get_client_ip();
    }
	 /**
     * دریافت User-Agent
     */
    public function userAgent(): string
    {
        return mb_substr(get_user_agent(), 0, 500);
    }
    /**
     * بررسی Method
     */
    public function isMethod(string $method): bool
    {
        return strtoupper($this->method) === strtoupper((string)$method);
    }

    /**
     * بررسی GET
     */
    public function isGet(): bool
    {
        return $this->isMethod('GET');
    }

    /**
     * بررسی POST
     */
    public function isPost(): bool
    {
        return $this->isMethod('POST');
    }

public function get(?string $key = null, mixed $default = null): mixed
{
    return $this->query($key, $default);
}

public function post(?string $key = null, mixed $default = null): mixed
{
    return $this->body($key, $default);
}
    /**
     * دریافت پارامتر از URL
     * Route segments همیشه رشته هستند (توسط Router از urldecode ساخته می‌شوند)؛
     * لذا خروجی به‌صورت ?string مدل می‌شود و مقدارِ غیررشته‌ای به default برمی‌گردد.
     */
    public function param(string $key, ?string $default = null): ?string
    {
        $value = $this->params[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /**
     * تنظیم پارامترها (توسط Router)
     */
    /** @param array<string, mixed> $params */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * دریافت Query String
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        
        return $this->query[$key] ?? $default;
    }
/**
 * @param string|null $key
 * @return ($key is null ? array<string, mixed> : mixed)
 */
public function body(?string $key = null, mixed $default = null): mixed
{
    $data = $this->parseBody(); // JSON/Form
    if ($key === null) return $data;
    return $data[$key] ?? $default;
}

/**
 * پردازش بدنه درخواست (JSON یا فرم)
 */
/** @return array<string, mixed> */
private function parseBody(): array
{
    if ($this->parsedBody !== null) {
        return $this->parsedBody;
    }

    // Request body is snapshotted during construction; never re-read mutable
    // superglobals while processing the same request.
    $body = $this->body;

    // CORE-022: JSON parse failure validation
    if ($this->isJson()) {
        if (!empty($this->rawInput)) {
            $data = (array)(json_decode($this->rawInput, true) ?? []);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Core\Exceptions\ValidationException(['body' => 'Invalid JSON body'], 'Invalid JSON body');
            }
            $this->parsedBody = array_replace($body, is_array($data) ? $data : []);
            return $this->parsedBody;
        }
    }

    // CORE-023: Parse application/x-www-form-urlencoded for PUT/PATCH/DELETE
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (str_contains(strtolower((string)$contentType), 'application/x-www-form-urlencoded') 
        && in_array($this->method, ['PUT', 'PATCH', 'DELETE'])) {
        parse_str($this->rawInput, $parsedParams);
        /** @var array<string, mixed> $parsedParams */
        $this->parsedBody = array_replace($body, $parsedParams);
        return $this->parsedBody;
    }

    $this->parsedBody = $body;
    return $this->parsedBody;
}

    /**
     * دریافت Body (POST)
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        // M11 Fix: ارجاع متد به لایه تنبل پارس بادی جهت دسترسی سریع و ایمن
        $data = $this->parseBody();
        if ($key === null) {
            return $data;
        }
        
        return $data[$key] ?? $default;
    }

    /**
     * دریافت همه ورودی‌ها
     */
    /** @return array<string, mixed> */
    public function all(): array
    {
        // M11 Fix: ترکیب داده‌های Query و Body پارس‌شده به صورت کاملاً Lazy
        return array_merge($this->query, $this->parseBody());
    }

    /**
     * دریافت یک مقدار ورودی به‌صورت رشته‌ی امن.
     * مقدار غیراسکالر (array/object/null) به default برمی‌گردد؛ cast کور انجام نمی‌شود.
     */
    public function str(string $key, string $default = ''): string
    {
        $value = $this->all()[$key] ?? $default;
        return is_scalar($value) ? (string)$value : $default;
    }

    /**
     * دریافت یک مقدار ورودی به‌صورت عدد صحیحِ امن.
     * مقدارِ نامعتبر (غیرعددی یا غیراسکالر) به default برمی‌گردد.
     */
    public function int(string $key, int $default = 0): int
    {
        $value = $this->all()[$key] ?? $default;
        return is_numeric($value) ? (int)$value : $default;
    }

    /**
     * دریافت شماره صفحه معتبر (حداقل ۱) جهت جلوگیری از Offset منفی (Finding #18)
     */
    public function page(string $key = 'page', int $default = 1): int
    {
        $value = $this->int($key, $default);
        return max(1, $value);
    }

    /**
     * دریافت حد تعداد مجاز ردیف‌ها با سقف مشخص جهت جلوگیری از DoS و Memory Exhaustion (Finding #19)
     */
    public function limit(string $key = 'limit', int $default = 20, int $max = 100): int
    {
        $value = $this->int($key, $this->int('per_page', $default));
        return min($max, max(1, $value));
    }

    /**
     * دریافت یک مقدار ورودی به‌صورت عدد اعشاریِ امن.
     * مقدارِ نامعتبر (غیرعددی یا غیراسکالر) به default برمی‌گردد.
     */
    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->all()[$key] ?? $default;
        return is_numeric($value) ? (float)$value : $default;
    }

    /**
     * دریافت فقط فیلدهای مشخص
     */
    /**
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        $all = $this->all();
        $result = [];
        
        foreach ($keys as $key) {
            if (isset($all[$key])) {
                $result[$key] = $all[$key];
            }
        }
        
        return $result;
    }

    /**
     * دریافت فایل آپلودشده.
     *
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) ? $file : null;
    }

    /**
     * فایل‌های یک field را به شکل یکنواخت برمی‌گرداند؛ هم single upload و هم
     * ساختار ستونی استاندارد PHP برای `name="attachments[]"` پشتیبانی می‌شود.
     *
     * @return list<array<string, mixed>>
     */
    public function uploadedFiles(string $key): array
    {
        $file = $this->file($key);
        if ($file === null) {
            return [];
        }

        $names = $file['name'] ?? null;
        if (!is_array($names)) {
            return [$file];
        }

        $normalized = [];
        foreach (array_keys($names) as $index) {
            $entry = [];
            foreach (['name', 'full_path', 'type', 'tmp_name', 'error', 'size'] as $attribute) {
                $column = $file[$attribute] ?? null;
                if (is_array($column) && array_key_exists($index, $column)) {
                    $entry[$attribute] = $column[$index];
                }
            }
            if ($entry !== []) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * بررسی وجود حداقل یک فایل با UPLOAD_ERR_OK.
     */
    public function hasFile(string $key): bool
    {
        foreach ($this->uploadedFiles($key) as $file) {
            $error = $file['error'] ?? null;
            if ((is_int($error) || is_string($error)) && (int)$error === UPLOAD_ERR_OK) {
                return true;
            }
        }

        return false;
    }

    /**
     * دریافت Header
     */
    public function header(string $key, mixed $default = null): mixed
    {
        $key = strtolower((string)$key);
        return $this->headers[$key] ?? $default;
    }

    /**
     * دریافت داده JSON از php://input
     */
    /** @return array<string, mixed>|null */
    public function json(): ?array
    {
        // FIX C-6: از rawInput کش‌شده استفاده می‌کنیم
        $data = (array)(json_decode($this->rawInput, true) ?? []);
        return is_array($data) ? $data : null;
    }

    /**
     * بررسی درخواست Ajax
     */
    public function isAjax(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    public function isSecure(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https') {
            return true;
        }

        // فقط در صورتی که آی‌پی فرستنده جزو پروکسی‌های معتبر باشد، به هدر X-Forwarded-Proto اعتماد می‌کنیم
        $trustedProxies = (array)config('app.trusted_proxies', ['127.0.0.1']);
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

        $isTrusted = false;
        foreach ($trustedProxies as $proxy) {
            if (ip_in_range($clientIp, str_value($proxy))) {
                $isTrusted = true;
                break;
            }
        }

        if ($isTrusted) {
            if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse کردن URI
     */
    private function parseUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // حذف Query String
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        // استفاده از APP_BASE_PATH اگر مشخص شده باشد، در غیر این صورت از مسیر اسکریپت
        $basePath = config('app.base_path', '');
        if ($basePath === null || $basePath === '') {
            $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            $scriptDir  = dirname($scriptName);
            $basePath   = ($scriptDir === '/' || $scriptDir === '\\') ? '' : rtrim($scriptDir, '/');
            $basePath   = preg_replace('/\/public$/', '', $basePath);
        }

        if ($basePath !== '' && $basePath !== '/' && str_starts_with($uri, str_value($basePath))) {
            $uri = substr($uri, strlen(str_value($basePath)));
        }
        
        return '/' . trim($uri, '/');
    }

    /**
     * Parse کردن Headers
     */
    /** @return array<string, string> */
    private function parseHeaders(): array
    {
        $headers = [];
        
        foreach ((array)$_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[strtolower((string)$headerName)] = $value;
            }
        }
        
        return $headers;
    }

    /**
     * Validate کردن ورودی
     */
    /**
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    public function validate(array $rules): array
    {
        // Fix: سینک کردن صدا زدن متد با ساختار صحیح و اصلاح‌شده کلاس Validator
        $validator = new Validator($this->all(), $rules);
        
        return $validator->fails() ? $validator->errors() : [];
    }

    // ── Alias helpers (backward compatibility & PHPStan) ────────────────

    /** @return string HTTP method (GET, POST, …) */
    public function getMethod(): string
    {
        return strtoupper((string)$this->method());
    }

    /** آیا فایل‌های آپلودشده وجود دارند؟ */
    public function hasFiles(): bool
    {
        return !empty($_FILES);
    }

    /** همه فایل‌های آپلودشده */
    /** @return array<string, mixed> */
    public function files(): array
    {
        return $_FILES ?: [];
    }

    /** بدنه خام درخواست (raw input) */
    public function getRawBody(): string
    {
        return (string) file_get_contents('php://input');
    }
}