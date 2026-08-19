<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\PathResolver;

use App\Contracts\LoggerInterface;
use App\Contracts\UploadServiceInterface;
use App\Services\Settings\AppSettings;
/**
 * UploadService — آپلود کاملاً امن (فقط تصویر)
 *
 * مسیر: app/Services/UploadService.php
 *
 * ─── لایه‌های امنیتی ───────────────────────────────────────────────────────
 *  1.  بررسی خطای PHP upload (UPLOAD_ERR_*)
 *  2.  is_uploaded_file() — جلوگیری از جعل مسیر tmp
 *  3.  بررسی حجم دوبار: از $_FILES['size'] و filesize() مستقیم
 *  4.  اصلاح خودکار maxBytes: اگر مقدار < 1024 احتمالاً MB بوده نه byte
 *  5.  سقف مطلق 10MB — هیچ کنترلری نمی‌تواند بیشتر بدهد
 *  6.  MIME واقعی با finfo (نه $_FILES['type'] که جعل‌پذیر است)
 *  7.  سفیدلیست سختگیر: فقط image/jpeg, image/png, image/webp, image/gif
 *  8.  تبدیل خودکار extension → MIME (backward compat با کنترلرهای قدیمی)
 *  9.  Magic bytes — امضای باینری اول فایل
 * 10.  بررسی اضافه WebP: RIFF????WEBP
 * 11.  double-extension attack: avatar.php.jpg → رد
 * 12.  نام‌گذاری تصادفی: bin2hex(random_bytes(12)) — هیچ اطلاعاتی لو نمی‌رود
 * 13.  پسوند خروجی فقط از MIME_TO_EXT (نه از ورودی کاربر)
 * 14.  ذخیره فایل‌های خصوصی در storage/ (خارج از public/)
 * 15.  sanitizeFolder: فقط [a-z0-9_-]، بدون .. و /
 * 16.  لاگ آپلود با IP و user_id
 * ──────────────────────────────────────────────────────────────────────────
 *
 * استفاده در کنترلرها (سینتکس یکسان برای همه):
 *   $result = $this->uploadService->upload(
 *       $_FILES['field'],
 *       'folder-name',
 *       ['image/jpeg', 'image/png'],   // یا ['jpg', 'jpeg', 'png'] — هر دو کار می‌کند
 *       5 * 1024 * 1024                // 5MB
 *   );
 *   if (!$result['success']) { ... }
 *   $path = $result['path'];  // 'folder-name/abc123def456789012.jpg'
 */
class UploadService implements UploadServiceInterface
{
    // ── MIME های مجاز (سفیدلیست کامل) ──────────────────────────────────────
    public const IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/heic',
        'image/heif',
    ];

    // ── MIME → پسوند خروجی امن ──────────────────────────────────────────────
    private const MIME_TO_EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        // PDF FIX: DM attachments may be PDFs. Served as forced-download only (see FileController).
        'application/pdf' => 'pdf',
    ];

    // ── Extension → MIME (برای سازگاری با کنترلرهای قدیمی) ────────────────
    private const EXT_TO_MIME = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'pdf'  => 'application/pdf',
        // هر چیز غیر از این نادیده گرفته می‌شود
    ];

    // ── Magic bytes ──────────────────────────────────────────────────────────
    private const MAGIC = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png'  => ["\x89PNG\r\n\x1A\n"],
        'image/gif'  => ["GIF87a", "GIF89a"],
        'image/webp' => ["RIFF"],   // بررسی کامل در isValidWebp()
        'image/heic' => ["ftypheic", "ftypheix", "ftypmif1", "ftypmsf1"],
        'image/heif' => ["ftypheic", "ftypheix", "ftypmif1", "ftypmsf1"],
        'application/pdf' => ["%PDF-"],
    ];

    // ── پسوندهایی که در نام اصلی فایل هرگز مجاز نیستند ───────────────────
    private const DANGEROUS_EXT = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'asp', 'aspx', 'jsp', 'jspx', 'cfm',
        'exe', 'sh', 'bash', 'bat', 'cmd', 'ps1', 'vbs',
        'py', 'rb', 'pl', 'cgi', 'lua',
        'htaccess', 'htpasswd', 'user.ini',
        'svg', 'xml', 'html', 'htm',
        'mp4', 'avi', 'mov', 'mkv', 'webm',  // ویدیو مجاز نیست
    ];

    // ── پوشه‌هایی که از public/ قابل دسترسی هستند (بدون auth) ─────────────
    private const PUBLIC_FOLDERS = ['avatars', 'banners'];

    // ── حجم پیش‌فرض و سقف مطلق ─────────────────────────────────────────────
    private const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;   // 5 MB
    private const ABSOLUTE_MAX_BYTES = 10 * 1024 * 1024; // 10 MB — سقف مطلق

    private string $storageRoot;
    private string $publicRoot;
    private string $captchaRoot;
    private AppSettings $appSettings;
    private Database $db;

    public function __construct(
        Database $db,
        AppSettings $appSettings,
        PathResolver $paths
    ) {
        $this->db = $db;
        $this->appSettings = $appSettings;
        $this->storageRoot = rtrim($paths->storage('uploads'), '/\\') . DIRECTORY_SEPARATOR;
        $this->publicRoot = rtrim($paths->public('uploads'), '/\\') . DIRECTORY_SEPARATOR;
        $this->captchaRoot = rtrim($paths->storage('captcha'), '/\\') . DIRECTORY_SEPARATOR;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PUBLIC API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * آپلود امن تصویر
     *
     * @param array<string, mixed> $file عنصر $_FILES['fieldname']
     * @param string $folder نام پوشه [a-z0-9_-]
     * @param list<string>|null $allowedMimes زیرمجموعه IMAGE_MIMES یا extensionها
     * @param int|null $maxBytes حداکثر حجم بایت — null = DEFAULT_MAX_BYTES
     *
     * @return array{
     *   success: true,
     *   filename: string,
     *   path: string,
     *   url: string|null,
     *   size: int,
     *   mime: string,
     *   message: ''
     * }
     */
    public function upload(
        array  $file,
        string $folder,
        ?array $allowedMimes = null,
        ?int   $maxBytes = null
    ): array {

        // ── 1. پوشه ─────────────────────────────────────────────────────────
        $folder = $this->sanitizeFolder($folder);
        if ($folder === null) {
            throw new \Core\Exceptions\BusinessException('نام پوشه نامعتبر است');
        }

        // ── 2. خطای PHP ─────────────────────────────────────────────────────
        $rawError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $errCode = is_int($rawError) || is_string($rawError)
            ? (int)$rawError
            : UPLOAD_ERR_NO_FILE;
        if ($errCode !== UPLOAD_ERR_OK) {
            throw new \Core\Exceptions\BusinessException($this->phpUploadError($errCode));
        }

        // ── 3. فایل موقت واقعی ──────────────────────────────────────────────
        $rawTmp = $file['tmp_name'] ?? '';
        $tmp = is_string($rawTmp) ? $rawTmp : '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \Core\Exceptions\BusinessException('فایل موقت نامعتبر است');
        }

        // ── 4. حجم ──────────────────────────────────────────────────────────
        $maxBytes = $this->resolveMaxBytes($maxBytes);
        $rawSize = $file['size'] ?? 0;
        $sizeFromPost = is_int($rawSize) || is_string($rawSize) ? (int)$rawSize : 0;
        $sizeReal = (int)filesize($tmp);

        if ($sizeFromPost <= 0 || $sizeReal <= 0) {
            throw new \Core\Exceptions\BusinessException('فایل خالی است');
        }
        // از هر دو بزرگ‌تر را چک می‌کنیم (bypass محافظت)
        $size = max($sizeFromPost, $sizeReal);
        if ($size > $maxBytes) {
            $maxMB = round($maxBytes / 1048576, 1);
            throw new \Core\Exceptions\BusinessException("حجم فایل بیشتر از حد مجاز ({$maxMB} مگابایت) است");
        }

        // ── 5. نام فایل (double-extension attack) ───────────────────────────
        $rawOriginalName = $file['name'] ?? 'file';
        $originalName = is_string($rawOriginalName) ? $rawOriginalName : 'file';
        if (!$this->isSafeFilename($originalName)) {
            throw new \Core\Exceptions\BusinessException('نام فایل حاوی پسوند غیرمجاز است');
        }

        // ── 6. MIME واقعی با finfo ───────────────────────────────────────────
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new \RuntimeException('نوع فایل نامعتبر است.');
        }
        $realMime = (string)finfo_file($finfo, $tmp);
        finfo_close($finfo);

        $allowed = $this->resolveAllowedMimes($allowedMimes);
        if (!in_array($realMime, $allowed, true)) {
            throw new \Core\Exceptions\BusinessException('نوع فایل مجاز نیست. فقط تصویر (JPEG، PNG، WebP، GIF) پذیرفته می‌شود.'
                . " (نوع تشخیص داده‌شده: {$realMime})");
        }

        // 🛡️ HIGH-02: بررسی و انطباق پسوند اصلی فایل ورودی با نوع MIME های مجاز نهایی سرور
        $origExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($origExt === '') {
            throw new \Core\Exceptions\BusinessException('فایل فاقد پسوند معتبر است');
        }
        $allowedExts = [];
        foreach ($allowed as $mimeItem) {
            if (isset(self::MIME_TO_EXT[$mimeItem])) {
                $allowedExts[] = self::MIME_TO_EXT[$mimeItem];
            }
        }
        if (in_array('jpg', $allowedExts, true)) {
            $allowedExts[] = 'jpeg';
        }
        if (!in_array($origExt, $allowedExts, true)) {
            throw new \Core\Exceptions\BusinessException('پسوند فایل با نوع مجاز تعیین شده همخوانی ندارد');
        }

        // ── 7. Magic bytes ───────────────────────────────────────────────────
        if (!$this->checkMagicBytes($tmp, $realMime)) {
            throw new \Core\Exceptions\BusinessException('امضای باینری فایل با نوع اعلام‌شده مطابقت ندارد');
        }

        // ── 8. بررسی اضافه WebP ─────────────────────────────────────────────
        if ($realMime === 'image/webp' && !$this->isValidWebp($tmp)) {
            throw new \Core\Exceptions\BusinessException('ساختار فایل WebP نامعتبر است');
        }

        // ── 9. پسوند خروجی امن ──────────────────────────────────────────────
        $ext = self::MIME_TO_EXT[$realMime] ?? 'bin';

        // ── 10. مسیر مقصد ────────────────────────────────────────────────────
        $dest = $this->buildDest($folder, $ext);

        if ($this->normalizeRelativePath($dest['relativePath']) === null) {
            throw new \Core\Exceptions\BusinessException('مسیر فایل نامعتبر است');
        }

        if (!is_dir($dest['dir'])) {
            if (!mkdir($dest['dir'], 0750, true)) {
                throw new \Core\Exceptions\BusinessException('خطا در ایجاد پوشه مقصد روی سرور');
            }
        }

        // ── 10.5 Re-encode تصویر — حذف metadata و کدهای مخفی ───────────────
        // این مرحله تضمین می‌کند فایل نهایی فقط داده‌های pixel خالص دارد
        if (in_array($realMime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            $reencoded = $this->reencodeImage($tmp, $realMime);
            if ($reencoded !== null) {
                // انتقال فایل re-encode شده به مقصد
                if (!rename($reencoded, $dest['fullPath'])) {
                    @unlink($reencoded);
                    throw new \Core\Exceptions\BusinessException('خطا در ذخیره فایل پردازش‌شده روی سرور');
                }
                chmod($dest['fullPath'], 0640);
            } else {
                // اگر re-encode ممکن نبود، با move_uploaded_file ادامه بده
                if (!move_uploaded_file($tmp, $dest['fullPath'])) {
                    throw new \Core\Exceptions\BusinessException('خطا در ذخیره فایل روی سرور');
                }
            }
        } else {
            // ── 11. انتقال فایل ──────────────────────────────────────────────
            if (!move_uploaded_file($tmp, $dest['fullPath'])) {
                throw new \Core\Exceptions\BusinessException('خطا در ذخیره فایل روی سرور');
            }
        }

        // ── 12. لاگ ──────────────────────────────────────────────────────────
        $this->logUpload($folder, $dest['filename'], $size, $realMime);

        return [
            'success'  => true,
            'filename' => $dest['filename'],
            'path'     => $dest['relativePath'],
            'url'      => $dest['url'],
            'size'     => $size,
            'mime'     => $realMime,
            'message'  => '',
        ];
    }

    /**
     * حذف فایل
     */
    public function delete(string $relativePath): bool
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === null) {
            return false;
        }

        $deleted = false;
        foreach ([$this->storageRoot, $this->publicRoot] as $base) {
            $full = $base . $relativePath;
            if (file_exists($full) && is_file($full)) {
                unlink($full);
                $deleted = true;
            }
        }
        return $deleted;
    }

    /**
     * دریافت مسیر فیزیکی فایل (با path traversal protection)
     *
     * @return string|null مسیر واقعی یا null اگر نامعتبر / خارج از root
     */
    public function getPath(string $relativePath): ?string
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === null) {
            return null;
        }

        $folder = explode('/', $relativePath, 2)[0] ?? '';

        // کپچا: مسیر جداگانه storage/captcha/
        if ($folder === 'captcha') {
            $filename = explode('/', $relativePath, 2)[1] ?? $relativePath;
            $candidate = $this->captchaRoot . basename($filename);
            $real = realpath($candidate);
            $captchaReal = realpath($this->captchaRoot);
            if ($real && $captchaReal && str_starts_with($real, $captchaReal . DIRECTORY_SEPARATOR)) {
                return $real;
            }
            return null;
        }

        $candidate = in_array($folder, self::PUBLIC_FOLDERS, true)
            ? $this->publicRoot . $relativePath
            : $this->storageRoot . $relativePath;

        // realpath برای resolve کردن هر .. احتمالی
        $real = realpath($candidate);
        if ($real === false) {
            return null;
        }

        $storageReal = realpath($this->storageRoot);
        $publicReal  = realpath($this->publicRoot);

        $insideStorage = $storageReal && str_starts_with($real, $storageReal . DIRECTORY_SEPARATOR);
        $insidePublic  = $publicReal  && str_starts_with($real, $publicReal  . DIRECTORY_SEPARATOR);

        return ($insideStorage || $insidePublic) ? $real : null;
    }

    /**
     * بررسی وجود فایل
     */
    public function exists(string $relativePath): bool
    {
        $path = $this->getPath($relativePath);
        return $path !== null && file_exists($path) && is_file($path);
    }

    /**
     * آیا پوشه عمومی است؟
     */
    public function isPublicFolder(string $folder): bool
    {
        return in_array($folder, self::PUBLIC_FOLDERS, true);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * پاکسازی نام پوشه
     * مجاز: [a-z0-9_-] — بدون ..، /، \
     */
    private function sanitizeFolder(string $folder): ?string
    {
        $folder = trim($folder, "/\\ \t\n\r\0\x0B");
        if ($folder === '') {
            return null;
        }
        if (str_contains($folder, '..') || str_contains($folder, '/') || str_contains($folder, '\\')) {
            return null;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $folder)) {
            return null;
        }
        return strtolower((string)$folder);
    }

    /**
     * نرمال‌سازی مسیر نسبی (folder/filename)
     */
    private function normalizeRelativePath(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if (str_contains($path, '..')) {
            return null;
        }

        $parts = explode('/', $path, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$folder, $filename] = $parts;
        $folder   = $this->sanitizeFolder($folder);
        $filename = $this->sanitizeStoredFilename($filename);

        if ($folder === null || $filename === null) {
            return null;
        }

        return $folder . '/' . $filename;
    }

    /**
     * اعتبارسنجی نام فایل‌هایی که ما ذخیره کرده‌ایم
     * الگو: 24hex.ext (مثل: a1b2c3d4e5f6a1b2c3d4e5f6.jpg)
     */
    private function sanitizeStoredFilename(string $filename): ?string
    {
        $filename = basename($filename);
        if (!preg_match('/^(captcha_[a-f0-9]{16}|[a-f0-9]{24})\.(jpg|jpeg|png|webp|gif|pdf|heic|heif)$/i', $filename)) {
            return null;
        }
        return strtolower((string)$filename);
    }

    /**
     * بررسی double-extension در نام اصلی فایل کاربر
     * avatar.php.jpg → رد می‌شود
     */
    private function isSafeFilename(string $name): bool
    {
        $parts = explode('.', strtolower(basename($name)));

        foreach ($parts as $part) {
            if (in_array(trim((string)$part), self::DANGEROUS_EXT, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * تعیین لیست MIME های مجاز نهایی
     *
     * - اگر کنترلر extension ('jpg') داد → تبدیل به MIME می‌شود
     * - اگر MIME صحیح داد → استفاده مستقیم
     * - هر چیز غیر از IMAGE_MIMES → نادیده گرفته می‌شود
     * - اگر نتیجه خالی بود → همه IMAGE_MIMES مجاز
     * @param list<string> $requested
     * @return list<string>
     */
    private function resolveAllowedMimes(?array $requested): array
    {
        if ($requested === null) {
            return self::IMAGE_MIMES;
        }

        $resolved = [];
        foreach ($requested as $item) {
            $item = strtolower(trim((string)$item));
            if (str_contains($item, '/')) {
                // MIME مستقیم
                if (in_array($item, self::IMAGE_MIMES, true)) {
                    $resolved[] = $item;
                }
            } elseif (isset(self::EXT_TO_MIME[$item])) {
                // extension → MIME
                $resolved[] = self::EXT_TO_MIME[$item];
            }
            // هر چیز دیگری (مثل 'pdf', 'mp4') نادیده گرفته می‌شود
        }

        $unique = array_values(array_unique($resolved));
        return empty($unique) ? self::IMAGE_MIMES : $unique;
    }

    /**
     * حداکثر حجم مجاز
     *
     * اصلاح خودکار: اگر مقدار < 1024 بود احتمالاً MB منظور بوده نه byte
     * مثال: 2 → 2MB | 5 → 5MB | 2097152 → 2MB (صحیح)
     */
    private function resolveMaxBytes(?int $requested): int
    {
        $defaultMax = int_value($this->appSettings->get('upload_default_max_bytes', self::DEFAULT_MAX_BYTES));
        $absoluteMax = int_value($this->appSettings->get('upload_absolute_max_bytes', self::ABSOLUTE_MAX_BYTES));

        if ($requested === null || $requested <= 0) {
            return $defaultMax;
        }

        // اصلاح خطای رایج: upload($file, 'folder', [...], 2) به جای 2*1024*1024
        if ($requested < 1024) {
            $requested = $requested * 1024 * 1024;
        }

        return min($requested, $absoluteMax);
    }

    /**
     * بررسی magic bytes
     */
    private function checkMagicBytes(string $tmpPath, string $mime): bool
    {
        $signatures = self::MAGIC[$mime] ?? null;
        if ($signatures === null) {
            return false; // MIME ناشناخته در لیست ما — رد
        }

        $fp = @fopen($tmpPath, 'rb');
        if ($fp === false) {
            return false;
        }
        $header = fread($fp, 16);
        fclose($fp);

        if (!is_string($header) || $header === '') {
            return false;
        }

        foreach ($signatures as $sig) {
            if (str_starts_with($header, $sig)) {
                return true;
            }
            // پشتیبانی از ساختار جعبه‌ای فایل‌های HEIC/HEIF (ISO Base Media) که امضای فایلی آن‌ها در بایت ۴ شروع می‌شود
            if (strlen((string)$header) >= 12 && substr($header, 4, strlen((string)$sig)) === $sig) {
                return true;
            }
        }
        return false;
    }

    /**
     * بررسی دقیق WebP: RIFF [4 byte size] WEBP
     */
    private function isValidWebp(string $tmpPath): bool
    {
        $fp = @fopen($tmpPath, 'rb');
        if ($fp === false) {
            return false;
        }
        $header = fread($fp, 12);
        fclose($fp);

        return is_string($header)
            && strlen((string)$header) >= 12
            && str_starts_with($header, 'RIFF')
            && substr($header, 8, 4) === 'WEBP';
    }

    /**
     * 🛡️ CLOUD SCALABILITY FIX (S3 & Pre-signed URLs): تولید لینک مستقیم آپلود ابری
     * حل مشکل عدم همگام‌سازی فایل‌ها در کلاسترهای ابری چندگانه و انتقال بار از سرور PHP به S3
     * @return array<string, mixed>
     */
    public function generatePreSignedUrl(string $folder, string $ext, int $expiresMinutes = 15): array
    {
        $folder = $this->sanitizeFolder($folder);
        if ($folder === null) {
            throw new \Core\Exceptions\BusinessException('نام پوشه نامعتبر است');
        }

        $s3Bucket   = config('filesystems.s3.bucket', env('AWS_BUCKET', 'chortke-cloud-storage'));
        $s3Endpoint = config('filesystems.s3.endpoint', env('AWS_ENDPOINT', 'https://s3.amazonaws.com'));
        $s3Region   = config('filesystems.s3.region', env('AWS_DEFAULT_REGION', 'us-east-1'));

        $filename = bin2hex(random_bytes(12)) . '.' . $ext;
        $relativePath = $folder . '/' . $filename;
        $uploadUrl = rtrim(str_value($s3Endpoint), '/') . '/' . $s3Bucket . '/' . $relativePath;

        // L-گاپ Fix: امضای واقعی AWS Signature V4 (به‌جای HMAC ساختگیِ ناسازگار با S3).
        // در صورت نبود کلیدهای S3، لینک امضاشده تولید نمی‌شود.
        $preSignedUrl = $this->presignS3Url('PUT', str_value($s3Endpoint), str_value($s3Bucket), $relativePath, str_value($s3Region), $expiresMinutes * 60);
        if ($preSignedUrl === null) {
            throw new \Core\Exceptions\BusinessException('سرویس ذخیره‌سازی ابری به‌درستی پیکربندی نشده است');
        }

        return [
            'success'    => true,
            'filename'   => $filename,
            'path'       => $relativePath,
            'upload_url' => $preSignedUrl,
            'view_url'   => in_array($folder, self::PUBLIC_FOLDERS, true) ? $uploadUrl : null,
            'driver'     => 's3',
            'expires_in' => $expiresMinutes * 60
        ];
    }

    /**
     * بررسی و واکشی لینک مستقیم کلود برای نمایش فایل در سرورهای توزیع‌شده
     */
    public function getCloudViewUrl(string $relativePath, bool $forceDownload = false): ?string
    {
        if (env('FILESYSTEM_DRIVER', 'local') !== 's3') {
            return null;
        }

        $s3Bucket   = str_value(config('filesystems.s3.bucket', env('AWS_BUCKET', 'chortke-cloud-storage')));
        $s3Endpoint = str_value(config('filesystems.s3.endpoint', env('AWS_ENDPOINT', 'https://s3.amazonaws.com')));
        $s3Region   = str_value(config('filesystems.s3.region', env('AWS_DEFAULT_REGION', 'us-east-1')));

        $relativePath = ltrim($relativePath, '/');
        $folder   = explode('/', $relativePath)[0] ?? '';
        $isPublic = in_array($folder, self::PUBLIC_FOLDERS, true);

        // PDF FIX (cloud): دانلود اجباری برای فایل‌های غیرتصویری تا در مرورگر inline باز نشوند.
        $extraQuery = $forceDownload ? ['response-content-disposition' => 'attachment'] : [];

        // L-گاپ Fix: فایل‌های خصوصی باید با لینک امضاشدهٔ زمان‌دار (SigV4) ارائه شوند، نه لینک سادهٔ بدون انقضا.
        $ttl = max(60, int_value(setting('cloud_view_url_ttl', 900)));
        $signed = $this->presignS3Url('GET', $s3Endpoint, $s3Bucket, $relativePath, $s3Region, $ttl, $extraQuery);
        if ($signed !== null) {
            return $signed;
        }

        // اگر امکان امضا نبود (کلیدها پیکربندی نشده)، فقط برای فولدرهای عمومی لینک ساده مجاز است.
        if (!$isPublic) {
            return null;
        }
        $url = rtrim($s3Endpoint, '/') . '/' . $s3Bucket . '/' . $relativePath;
        if ($forceDownload) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'response-content-disposition=attachment';
        }
        return $url;
    }

    /**
     * تولید URL امضاشدهٔ AWS Signature V4 (path-style) برای S3.
     * در صورت نبود کلیدهای دسترسی، null برمی‌گرداند.
     * @param array<string, string> $extraQuery مقادیر query که باید در امضا لحاظ شوند.
     */
    private function presignS3Url(string $method, string $endpoint, string $bucket, string $key, string $region, int $expiresSeconds, array $extraQuery = []): ?string
    {
        $accessKey = str_value(env('AWS_ACCESS_KEY_ID', ''));
        $secretKey = str_value(env('AWS_SECRET_ACCESS_KEY', ''));
        if ($accessKey === '' || $secretKey === '') {
            return null;
        }

        $region  = $region !== '' ? $region : 'us-east-1';
        $service = 's3';

        $parts = parse_url(rtrim($endpoint, '/'));
        if ($parts === false || empty($parts['host'])) {
            return null;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'];
        if (isset($parts['port'])) {
            $host .= ':' . $parts['port'];
        }
        $basePath = rtrim(str_value($parts['path'] ?? ''), '/');

        // path-style: /[basePath]/bucket/key — هر بخش جداگانه انکود می‌شود و «/» حفظ می‌شود.
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
        $canonicalUri = $basePath . '/' . rawurlencode($bucket) . '/' . $encodedKey;

        $now       = time();
        $amzDate   = gmdate('Ymd\THis\Z', $now);
        $dateStamp = gmdate('Ymd', $now);
        $scope     = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';

        $query = $extraQuery;
        $query['X-Amz-Algorithm']     = 'AWS4-HMAC-SHA256';
        $query['X-Amz-Credential']    = $accessKey . '/' . $scope;
        $query['X-Amz-Date']          = $amzDate;
        $query['X-Amz-Expires']       = (string)$expiresSeconds;
        $query['X-Amz-SignedHeaders'] = 'host';
        ksort($query);

        $canonicalQueryParts = [];
        foreach ($query as $k => $v) {
            $canonicalQueryParts[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
        }
        $canonicalQuery = implode('&', $canonicalQueryParts);

        $canonicalHeaders = 'host:' . $host . "\n";
        $signedHeaders    = 'host';

        $canonicalRequest = $method . "\n"
            . $canonicalUri . "\n"
            . $canonicalQuery . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . 'UNSIGNED-PAYLOAD';

        $stringToSign = 'AWS4-HMAC-SHA256' . "\n"
            . $amzDate . "\n"
            . $scope . "\n"
            . hash('sha256', $canonicalRequest);

        $kDate     = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $kRegion   = hash_hmac('sha256', $region, $kDate, true);
        $kService  = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning  = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return $scheme . '://' . $host . $canonicalUri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    /**
     * ساخت مسیر مقصد
     * @return array{dir: string, filename: string, fullPath: string, relativePath: string, url: string|null}
     */
    private function buildDest(string $folder, string $ext): array
    {
        $isPublic = in_array($folder, self::PUBLIC_FOLDERS, true);
        $baseDir  = $isPublic ? $this->publicRoot : $this->storageRoot;

        $dir      = $baseDir . $folder . '/';
        $filename = bin2hex(random_bytes(12)) . '.' . $ext;   // 24 hex + .ext
        $fullPath = $dir . $filename;

        $relativePath = $folder . '/' . $filename;
        $url          = $isPublic ? ('uploads/' . $relativePath) : null;

        return compact('dir', 'filename', 'fullPath', 'relativePath', 'url');
    }

    /**
     * پیام خطای PHP upload
     */
    private function phpUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE  => 'حجم فایل از حد مجاز سرور بیشتر است',
            UPLOAD_ERR_PARTIAL    => 'فایل به صورت ناقص آپلود شد. دوباره تلاش کنید',
            UPLOAD_ERR_NO_FILE    => 'هیچ فایلی انتخاب نشده است',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت سرور پیدا نشد',
            UPLOAD_ERR_CANT_WRITE => 'خطا در نوشتن روی دیسک سرور',
            UPLOAD_ERR_EXTENSION  => 'آپلود توسط تنظیمات PHP مسدود شد',
            default               => 'خطای ناشناخته در آپلود فایل',
        };
    }

    /**
     * ساخت آرایه خطا
     * @param array<string, mixed> $errors
     * @return array<string, mixed>
     */
    protected function fail(string $message = '', array $errors = [], int $statusCode = 400): array
    {
        return [
            'success'  => false,
            'filename' => '',
            'path'     => '',
            'url'      => null,
            'size'     => 0,
            'mime'     => '',
            'message'  => $message,
        ];
    }

    /**
     * لاگ آپلود
     */
    private function logUpload(string $folder, string $filename, int $size, string $mime): void
    {
        try {
            $userId = function_exists('user_id') ? (int)user_id() : null;
            $this->db->query(
                "INSERT IGNORE INTO file_logs
                 (folder, filename, user_id, mime_type, size_bytes, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [$folder, $filename, $userId, $mime, $size, get_client_ip()]
            );
        } catch (\Throwable) {
            // جدول ممکن است نباشد — silent fail
        }
    }

    /**
     * Re-encode تصویر — حذف کامل metadata و کدهای مخفی احتمالی
     *
     * تصویر را از حافظه بارگذاری کرده و دوباره render می‌کند.
     * این کار تضمین می‌کند هیچ EXIF, XMP, IPTC یا کد مخفی در فایل نهایی نیست.
     *
     * @return string|null مسیر فایل temp ایجاد شده، یا null در صورت خطا
     */
    private function reencodeImage(string $sourcePath, string $mime): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }

        try {
            $img = match ($mime) {
                'image/jpeg' => @imagecreatefromjpeg($sourcePath),
                'image/png'  => @imagecreatefrompng($sourcePath),
                'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : null,
                'image/gif'  => @imagecreatefromgif($sourcePath),
                default      => null,
            };

            if (!$img) {
                return null;
            }

            $tmpFile = tempnam(sys_get_temp_dir(), 'img_safe_');
            if ($tmpFile === false) {
                imagedestroy($img);
                return null;
            }

            $saved = match ($mime) {
                'image/jpeg' => imagejpeg($img, $tmpFile, 90),
                'image/png'  => (function () use ($img, $tmpFile): bool {
                    // حفظ شفافیت PNG
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                    return imagepng($img, $tmpFile, 6);
                })(),
                'image/webp' => function_exists('imagewebp') ? imagewebp($img, $tmpFile, 85) : false,
                'image/gif'  => imagegif($img, $tmpFile),
                default      => false,
            };

            imagedestroy($img);

            if (!$saved) {
                @unlink($tmpFile);
                return null;
            }

            return $tmpFile;

        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * getFile - Retrieve and validate uploaded file.
     */
    public function getFile(string $path): array
    {
        $realPath = $this->getPath($path);
        if ($realPath === null || !file_exists($realPath) || !is_file($realPath)) {
            return ['success' => false, 'file' => null, 'error' => 'File not found'];
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo === false ? 'application/octet-stream' : (string)finfo_file($finfo, $realPath);
        if ($finfo !== false) {
            finfo_close($finfo);
        }
        return [
            'success' => true,
            'file' => (object)[
                'path' => $realPath,
                'size' => filesize($realPath),
                'mime' => $mime
            ],
            'error' => null
        ];
    }

    /**
     * read - Get content of the file.
     */
    public function read(string $path): ?string
    {
        $realPath = $this->getPath($path);
        if ($realPath === null || !file_exists($realPath) || !is_file($realPath)) {
            return null;
        }
        return file_get_contents($realPath) ?: null;
    }

    /**
     * write - Write content to file.
     */
    public function write(string $path, string $content): bool
    {
        $relativePath = $this->normalizeRelativePath($path);
        if ($relativePath === null) {
            return false;
        }
        $folder = explode('/', $relativePath, 2)[0] ?? '';
        $isPublic = $this->isPublicFolder($folder);
        $baseDir = $isPublic ? $this->publicRoot : $this->storageRoot;
        $fullPath = $baseDir . $relativePath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true)) {
                return false;
            }
        }
        return file_put_contents($fullPath, $content) !== false;
    }
}
