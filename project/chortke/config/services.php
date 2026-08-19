<?php

/**
 * پیکربندی خدمات و سرویس‌های جانبی (Third-party Services)
 */

return [

    // سیستم ارسال اعلان پوش
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'service_account_json' => env('FCM_SERVICE_ACCOUNT_JSON'),
        'endpoint' => env('FCM_ENDPOINT', 'https://fcm.googleapis.com/v1/projects/%s/messages:send'),
        'oauth_url' => env('FCM_OAUTH_URL', 'https://oauth2.googleapis.com/token'),
    ],

    // Telegram Bot API for operational alerts
    'telegram' => [
        'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
    ],

    // درگاه ارسال پیامک
    'sms' => [
        'enabled' => filter_var(env('SMS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'provider' => env('SMS_PROVIDER', 'kavenegar'),
        'api_key' => env('SMS_API_KEY', ''),
        'username' => env('SMS_USERNAME', ''),
        'from' => env('SMS_FROM', ''),
        'kavenegar_base_url' => env('KAVENEGAR_BASE_URL', 'https://api.kavenegar.com'),
        'melipayamak_base_url' => env('MELIPAYAMAK_BASE_URL', 'https://rest.payamak-panel.com'),
        'idehpayam_base_url' => env('IDEHPAYAM_BASE_URL', 'https://panel.idehpayam.com'),
    ],

    // سرویس استعلام بانکی وندار
    'vandar' => [
        'api_token' => env('VANDAR_API_TOKEN', ''),
        'business' => env('VANDAR_BUSINESS', ''),
        'base_url' => env('VANDAR_BASE_URL', 'https://api.vandar.io'),
        'timeout' => env('VANDAR_TIMEOUT', 10),
    ],

    // Blockchain provider endpoints (all configurable for failover/operations)
    'crypto' => [
        'tronscan_transaction_url' => env('TRONSCAN_TRANSACTION_URL', 'https://apilist.tronscan.org/api/transaction-info?hash=%s'),
        'trongrid_events_url' => env('TRONGRID_EVENTS_URL', 'https://api.trongrid.io/v1/transactions/%s/events'),
        'tronscan_status_url' => env('TRONSCAN_STATUS_URL', 'https://apilist.tronscan.org/api/system/status'),
        'trongrid_block_url' => env('TRONGRID_BLOCK_URL', 'https://api.trongrid.io/wallet/getnowblock'),
        'bscscan_url' => env('BSCSCAN_URL', 'https://api.bscscan.com/api?module=account&action=tokentx&txhash=%s'),
        'bscscan_fallback_url' => env('BSCSCAN_FALLBACK_URL', 'https://api-testnet.bscscan.com/api?module=account&action=tokentx&txhash=%s'),
        'toncenter_url' => env('TONCENTER_URL', 'https://toncenter.com/api/v2/getTransactions'),
        'toncenter_fallback_url' => env('TONCENTER_FALLBACK_URL', 'https://testnet.toncenter.com/api/v2/getTransactions'),
        'explorer_urls' => [
            'TRC20' => env('TRON_EXPLORER_URL', 'https://tronscan.org/#/transaction/'),
            'BNB20' => env('BSC_EXPLORER_URL', 'https://bscscan.com/tx/'),
            'TON' => env('TON_EXPLORER_URL', 'https://tonscan.org/tx/'),
            'SOL' => env('SOL_EXPLORER_URL', 'https://explorer.solana.com/tx/'),
        ],
    ],

    // Google reCAPTCHA verification
    'recaptcha' => [
        'verify_url' => env('RECAPTCHA_VERIFY_URL', 'https://www.google.com/recaptcha/api/siteverify'),
    ],

    // Google OAuth/JWKS
    'google' => [
        'jwks_url' => env('GOOGLE_JWKS_URL', 'https://www.googleapis.com/oauth2/v3/certs'),
        'timeout' => env('GOOGLE_JWKS_TIMEOUT', 10),
    ],

    // سرویس احراز هویت جیبیت (Jibit)
    'jibit' => [
        'api_key' => env('JIBIT_API_KEY'),
        'api_secret' => env('JIBIT_API_SECRET'),
        'base_url' => env('JIBIT_BASE_URL', 'https://api.jibit.ir/v1/'),
        'timeout' => env('JIBIT_TIMEOUT', 10),
    ],

    // سرویس هوش مصنوعی DeepFace
    'deepface' => [
        'api_url' => env('KYC_AI_SERVICE_URL'),
        'api_token' => env('KYC_AI_SERVICE_TOKEN'),
    ],

    // سرویس شناسایی موقعیت مکانی IP
    'geoip' => [
        'maxmind_license_key' => env('MAXMIND_LICENSE_KEY', ''),
    ],

];
