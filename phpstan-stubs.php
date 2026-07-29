<?php
/**
 * PHPStan Stub File — Global function type hints.
 * Only loaded by PHPStan, never at runtime.
 */
namespace {
    function app(): object { throw new \RuntimeException(); }

    function db(): object { throw new \RuntimeException(); }

    function logger(): object { throw new \RuntimeException(); }

    function client_ip(): string { return '127.0.0.1'; }

    function get_user_agent(): string { return ''; }

    function url(string $path = ''): string { return ''; }

    function config(string $key, mixed $default = null): mixed { return $default; }

    function cache(?string $key = null, mixed $default = null): mixed { return $default; }

    function setting(string $key, mixed $default = null): mixed { return $default; }

    function env(string $key, mixed $default = null): mixed { return $default; }
}
