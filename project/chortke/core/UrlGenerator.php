<?php

declare(strict_types=1);

namespace Core;

/**
 * Canonical application and asset URL generator.
 *
 * Origins come exclusively from validated configuration. Request Host headers
 * are never used to construct canonical links.
 */
final class UrlGenerator
{
    private string $applicationUrl;
    private ?string $assetUrl;

    public function __construct(
        string $applicationUrl,
        ?string $assetUrl = null,
        ?string $basePath = null,
        string $environment = 'production'
    ) {
        $this->applicationUrl = $this->normalizeConfiguredUrl($applicationUrl, 'Application URL');
        $this->assetUrl = $assetUrl === null || trim($assetUrl) === ''
            ? null
            : $this->normalizeConfiguredUrl($assetUrl, 'Asset URL');

        if ($environment === 'production') {
            foreach ([$this->applicationUrl, $this->assetUrl] as $configuredUrl) {
                if ($configuredUrl === null) {
                    continue;
                }
                $host = strtolower((string)parse_url($configuredUrl, PHP_URL_HOST));
                if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                    throw new \RuntimeException('Production canonical URLs must not use loopback hosts.');
                }
            }
        }

        $normalizedBasePath = $this->normalizeBasePath($basePath);
        if ($normalizedBasePath !== '') {
            $configuredPath = trim((string)(parse_url($this->applicationUrl, PHP_URL_PATH) ?: ''), '/');
            if ($configuredPath === '') {
                $this->applicationUrl .= '/' . $normalizedBasePath;
            } elseif ($configuredPath !== $normalizedBasePath) {
                throw new \InvalidArgumentException(
                    'Application URL path and configured application base path conflict.'
                );
            }
        }
    }

    public function base(): string
    {
        return $this->applicationUrl;
    }

    public function origin(): string
    {
        return $this->originOf($this->applicationUrl);
    }

    public function assetBase(): string
    {
        return $this->assetUrl ?? $this->applicationUrl;
    }

    public function assetOrigin(): string
    {
        return $this->originOf($this->assetUrl ?? $this->applicationUrl);
    }

    public function to(string $path = ''): string
    {
        return $this->appendRelativePath($this->applicationUrl, $path);
    }

    public function asset(string $path): string
    {
        return $this->appendRelativePath($this->assetUrl ?? $this->applicationUrl, $path);
    }

    private function originOf(string $url): string
    {
        $scheme = (string)parse_url($url, PHP_URL_SCHEME);
        $host = (string)parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        return $scheme . '://' . $host . (is_int($port) ? ':' . $port : '');
    }

    private function normalizeConfiguredUrl(string $url, string $label): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            throw new \InvalidArgumentException("{$label} must be a non-empty absolute URL.");
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            throw new \InvalidArgumentException("{$label} must use an absolute HTTP(S) origin.");
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException("{$label} must not contain credentials, query parameters, or fragments.");
        }

        return $url;
    }

    private function normalizeBasePath(?string $basePath): string
    {
        if ($basePath === null || trim($basePath) === '') {
            return '';
        }

        $basePath = trim(str_replace('\\', '/', $basePath), '/');
        if (
            $basePath === ''
            || str_contains($basePath, "\0")
            || in_array('..', explode('/', $basePath), true)
            || preg_match('/^[A-Za-z0-9._~\/-]+$/', $basePath) !== 1
        ) {
            throw new \InvalidArgumentException('Application base path is invalid.');
        }

        return $basePath;
    }

    private function appendRelativePath(string $baseUrl, string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('URL path must not contain null bytes.');
        }

        $path = str_replace('\\', '/', trim($path));
        $candidate = ltrim($path, '/');
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate) === 1 || str_starts_with($path, '//')) {
            throw new \InvalidArgumentException('URL generator accepts relative paths only.');
        }

        $configuredBasePath = trim((string)(parse_url($baseUrl, PHP_URL_PATH) ?: ''), '/');
        if (
            $configuredBasePath !== ''
            && ($candidate === $configuredBasePath || str_starts_with($candidate, $configuredBasePath . '/'))
        ) {
            $candidate = ltrim(substr($candidate, strlen($configuredBasePath)), '/');
        }

        return $baseUrl . '/' . $candidate;
    }
}
