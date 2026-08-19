<?php

namespace App\Utils\Sentry;

/**
 * 🔍 StackTraceAnalyzer - تحلیل‌گر پیشرفته Stack Trace
 * 
 * قابلیت‌ها:
 * - تحلیل کامل stack trace
 * - شناسایی vendor vs app code
 * - استخراج context (کدهای اطراف خط خطا)
 * - تشخیص framework-specific errors
 */
class StackTraceAnalyzer
{
    private string $appBasePath;
    /** @var list<string> */
    private array $vendorPaths = ['/vendor/', '/node_modules/'];
    
    public function __construct() {
        $this->appBasePath = realpath(dirname(__DIR__, 3)) . '/';
    }

    /**
     * 🎯 Analyze - تحلیل کامل stack trace
     */
    /** @return array<string, mixed> */
    public function analyze(\Throwable $exception): array
    {
        $frames = [];
        $trace = $exception->getTrace();
        
        // اضافه کردن frame اصلی (محل وقوع exception)
        array_unshift($trace, [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'function' => '{main}',
            'class' => null,
            'type' => null,
            'args' => [],
        ]);

        foreach ((array)$trace as $index => $frame) {
            $analyzedFrame = $this->analyzeFrame($frame, $index);
            if ($analyzedFrame) {
                $frames[] = $analyzedFrame;
            }
        }

        return [
            'frames' => $frames,
            'frames_count' => count($frames),
            'app_frames_count' => count(array_filter($frames, fn(array $f) => (bool)($f['in_app'] ?? false))),
        ];
    }

    /**
     * 🔬 Analyze Frame - تحلیل یک frame
     */
    /**
     * @param array<string, mixed> $frame
     * @return array<string, mixed>|null
     */
    private function analyzeFrame(array $frame, int $index): ?array
    {
        $file = str_value($frame['file'] ?? '');
        $line = $frame['line'] ?? null;

        if ($file === '') {
            return null; // Internal functions بدون file
        }

        // مسیر نسبی
        $relativePath = $this->getRelativePath($file);
        
        // تشخیص app code vs vendor
        $inApp = !$this->isVendorCode($file);

        // خواندن context (خطوط اطراف)
        $context = $this->extractContext($file, $line === null ? null : int_value($line));

        return [
            'abs_path' => $file,
            'filename' => $relativePath,
            'line' => $line,
            'function' => $this->formatFunction($frame),
            'module' => $this->extractModule($file),
            'in_app' => $inApp,
            'context_line' => $context['line'] ?? null,
            'pre_context' => $context['pre'] ?? [],
            'post_context' => $context['post'] ?? [],
            'vars' => $this->sanitizeVars(is_array($frame['args'] ?? null) ? $frame['args'] : []),
        ];
    }

    /**
     * 📝 Extract Context - استخراج خطوط اطراف
     */
    /** @return array<string, mixed> */
    private function extractContext(string $file, ?int $line, int $contextLines = 5): array
    {
        if (!$line || !is_readable($file)) {
            return [];
        }

        try {
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
            $lineIndex = $line - 1; // 0-indexed

            if (!isset($lines[$lineIndex])) {
                return [];
            }

            $start = max(0, $lineIndex - $contextLines);
            $end = min(count($lines) - 1, $lineIndex + $contextLines);

            return [
                'line' => $lines[$lineIndex],
                'pre' => array_slice($lines, $start, $lineIndex - $start),
                'post' => array_slice($lines, $lineIndex + 1, $end - $lineIndex),
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 🔧 Format Function
     */
    /** @param array<string, mixed> $frame */
    private function formatFunction(array $frame): string
    {
        $function = str_value($frame['function'] ?? '');
        
        if (isset($frame['class'])) {
            $type = str_value($frame['type'] ?? '::');
            return str_value($frame['class']) . $type . $function;
        }
        
        return $function;
    }

    /**
     * 📦 Extract Module
     */
    private function extractModule(string $file): ?string
    {
        // استخراج namespace/module از مسیر فایل
        $relativePath = $this->getRelativePath($file);
        
        if (preg_match('#^app/([^/]+)/#', $relativePath, $matches)) {
            return $matches[1];
        }
        
        if (preg_match('#^vendor/([^/]+)/([^/]+)/#', $relativePath, $matches)) {
            return $matches[1] . '/' . $matches[2];
        }
        
        return null;
    }

    /**
     * 🧹 Sanitize Vars - پاکسازی متغیرها
     */
    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function sanitizeVars(array $vars): array
    {
        $sanitized = [];
        
        foreach ((array)$vars as $key => $value) {
            // Finding #8 Fix: Check variable name $key for sensitive keyword redaction
            if ($this->isSensitiveKey((string)$key)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $this->sanitizeValue($value, 0, (string)$key);
            }
        }
        
        return $sanitized;
    }

    /**
     * 🔒 Sanitize Value
     */
    private function sanitizeValue(mixed $value, int $depth = 0, ?string $parentKey = null): mixed
    {
        if ($depth > 3) {
            return '[Too Deep]';
        }

        if ($parentKey !== null && $this->isSensitiveKey($parentKey)) {
            return '[REDACTED]';
        }

        if (is_object($value)) {
            return '[Object ' . get_class($value) . ']';
        }

        if (is_array($value)) {
            if (count($value) > 10) {
                return '[Array(' . count($value) . ' items)]';
            }
            $sanitized = [];
            foreach ((array)$value as $k => $v) {
                if ($this->isSensitiveKey((string)$k)) {
                    $sanitized[$k] = '[REDACTED]';
                } else {
                    $sanitized[$k] = $this->sanitizeValue($v, $depth + 1, (string)$k);
                }
            }
            return $sanitized;
        }

        if (is_string($value)) {
            if ($parentKey !== null && $this->isSensitiveKey($parentKey)) {
                return '[REDACTED]';
            }
            return mb_substr($value, 0, 200); // محدود کردن طول
        }

        return $value;
    }

    /**
     * 🔐 Is Sensitive Key
     */
    private function isSensitiveKey(string $key): bool
    {
        $sensitive = ['password', 'token', 'secret', 'api_key', 'private'];
        $lowerKey = strtolower((string)$key);
        
        foreach ($sensitive as $word) {
            if (str_contains($lowerKey, $word)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 🏠 Get Relative Path
     */
    private function getRelativePath(string $file): string
    {
        if (str_starts_with($file, $this->appBasePath)) {
            return substr($file, strlen($this->appBasePath));
        }
        return $file;
    }

    /**
     * 📦 Is Vendor Code
     */
    private function isVendorCode(string $file): bool
    {
        foreach ($this->vendorPaths as $vendorPath) {
            if (str_contains($file, $vendorPath)) {
                return true;
            }
        }
        return false;
    }
}
