<?php

/**
 * تنظیمات اتصال به حافظه نهان Redis
 */

return [
    'enabled'  => env('REDIS_ENABLED', true),
    'host'     => env('REDIS_HOST', '127.0.0.1'),
    'port'     => (int)env('REDIS_PORT', 6379),
    'password' => env('REDIS_PASSWORD', null),
    'db'       => (int)env('REDIS_DB', 0),
    'timeout'  => (float)env('REDIS_TIMEOUT', 1.5),
    'retry_interval' => (int)env('REDIS_RETRY_INTERVAL', 100), // ارتقای تاب‌آوری کلاستر ردیس جهت اتصال مجدد پایدار در زمان سوییچینگ نودها
    'prefix'   => env('REDIS_PREFIX', 'chortke'),
];
