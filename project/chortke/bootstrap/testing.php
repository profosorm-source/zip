<?php

declare(strict_types=1);

define('PHPUNIT_RUNNING', true);

// Tests must be self-contained and must never depend on a real .env file.
// Runtime configuration is injected here instead of committing credentials.
$GLOBALS['env'] = [
    'APP_NAME' => 'Chortke Test',
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'true',
    'APP_URL' => 'http://127.0.0.1:8090',
    'APP_BASE_PATH' => '',
    'APP_KEY' => 'testing-app-key-32-characters-long!!',
    'SECURITY_API_TOKEN_SECRET' => 'testing-security-token-secret-32!!',
    'APP_TIMEZONE' => 'Asia/Tehran',
    'DB_DRIVER' => 'mysql',
    'DB_HOST' => getenv('CHORTKE_TEST_DB_HOST') ?: '127.0.0.1',
    'DB_PORT' => getenv('CHORTKE_TEST_DB_PORT') ?: '3306',
    'DB_NAME' => getenv('CHORTKE_TEST_DB_NAME') ?: 'chortk',
    'DB_USER' => getenv('CHORTKE_TEST_DB_USER') ?: 'root',
    'DB_PASS' => getenv('CHORTKE_TEST_DB_PASS') !== false ? (string)getenv('CHORTKE_TEST_DB_PASS') : '',
    'REDIS_ENABLED' => 'true',
    'REDIS_HOST' => getenv('CHORTKE_TEST_REDIS_HOST') ?: '127.0.0.1',
    'REDIS_PORT' => getenv('CHORTKE_TEST_REDIS_PORT') ?: '6379',
    'REDIS_PASSWORD' => getenv('CHORTKE_TEST_REDIS_PASSWORD') !== false ? (string)getenv('CHORTKE_TEST_REDIS_PASSWORD') : '',
    'REDIS_TIMEOUT' => getenv('CHORTKE_TEST_REDIS_TIMEOUT') ?: '0.5',
    'SESSION_DRIVER' => 'file',
    'SESSION_FALLBACK_STORAGE' => 'file',
    'SESSION_HTTPONLY' => 'true',
];

// Child CLI commands spawned by integration tests must receive the same
// non-secret test configuration as the parent PHPUnit process.
foreach ($GLOBALS['env'] as $key => $value) {
    putenv($key . '=' . (string)$value);
}

// Disable session sending in CLI — but only if PHP hasn't already emitted
// any output (headers_sent() is the canonical guard, and PHP 8.4 also
// deprecated disabling session.use_only_cookies at runtime, so we just skip
// it when the runtime won't accept the change anyway).
if (!headers_sent()) {
    @ini_set('session.use_cookies', '0');
}

// Bootstrap app.php
require_once dirname(__DIR__) . '/bootstrap/app.php';

// Pin mandatory test runtime configuration in the config override layer as well
// as process env. PHPUnit process-isolation and random ordering must not depend
// on a previously loaded config file or on mutable global config caches.
config_set('app.env', 'testing');
config_set('app.key', (string)$GLOBALS['env']['APP_KEY']);
config_set('app.url', (string)$GLOBALS['env']['APP_URL']);
