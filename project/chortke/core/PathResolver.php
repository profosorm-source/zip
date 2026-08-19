<?php

declare(strict_types=1);

namespace Core;

/**
 * Canonical filesystem path resolver.
 *
 * This class never generates URLs and never reads request headers. Every
 * returned path is contained under the configured application root.
 */
final class PathResolver
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        if ($basePath === '' || str_contains($basePath, "\0")) {
            throw new \InvalidArgumentException('Application base path must be a non-empty filesystem path.');
        }

        $realBasePath = realpath($basePath);
        if ($realBasePath === false || !is_dir($realBasePath)) {
            throw new \InvalidArgumentException('Application base path must reference an existing directory.');
        }

        $this->basePath = rtrim($realBasePath, DIRECTORY_SEPARATOR);
    }

    public function base(string $path = ''): string
    {
        return $this->resolveWithin($this->basePath, $path);
    }

    public function storage(string $path = ''): string
    {
        return $this->resolveWithin($this->basePath . DIRECTORY_SEPARATOR . 'storage', $path);
    }

    public function public(string $path = ''): string
    {
        return $this->resolveWithin($this->basePath . DIRECTORY_SEPARATOR . 'public', $path);
    }

    private function resolveWithin(string $root, string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Filesystem path must not contain null bytes.');
        }

        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '') {
            return rtrim($root, '/\\');
        }
        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            throw new \InvalidArgumentException('Filesystem path must be relative to its configured root.');
        }

        $segments = explode('/', $normalized);
        $safeSegments = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new \InvalidArgumentException('Filesystem path traversal is not allowed.');
            }
            $safeSegments[] = $segment;
        }

        return rtrim($root, '/\\')
            . ($safeSegments === [] ? '' : DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $safeSegments));
    }
}
