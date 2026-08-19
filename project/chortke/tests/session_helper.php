<?php
/**
 * Session Helper — ایجاد مستقیم سشن برای تست‌های HTTP
 * 
 * این اسکریپت از CLI اجرا می‌شود (جایی که CAPTCHA bypass فعال است)
 * و یک سشن معتبر برای کاربر مشخص‌شده می‌سازد.
 * 
 * استفاده: php tests/session_helper.php <email> <password> [--admin]
 * خروجی: session_id (برای استفاده در cookie)
 */
require_once __DIR__ . '/../bootstrap/app.php';

$email = $argv[1] ?? '';
$password = $argv[2] ?? '123456';
$isAdmin = in_array('--admin', $argv ?? [], true);

if (!$email) {
    fwrite(STDERR, "Usage: php tests/session_helper.php <email> [password] [--admin]\n");
    exit(1);
}

// Find user
$user = \Core\Database::getInstance()->fetch(
    "SELECT id, email, role, status, email_verified_at, password FROM users WHERE email = ? LIMIT 1",
    [$email]
);

if (!$user) {
    fwrite(STDERR, "User not found: {$email}\n");
    exit(1);
}

// Verify password
if (!verify_user_password($password, $user->password)) {
    fwrite(STDERR, "Password verification failed for: {$email}\n");
    exit(1);
}

// Start session — suppress log noise
$session = \Core\Session::getInstance();
ob_start();
$session->start();
ob_end_clean();

// Set session data (same as AuthService::login would)
$session->set('user_id', (int)$user->id);
$session->set('user_role', $user->role);
$session->set('user_email', $user->email);
$session->set('_initiated', true);

// Mark as verified if applicable
$session->set('email_verified', $user->email_verified_at !== null);

// Write and close session
session_write_close();

// Output the session ID (clean, no extra output)
ob_start();
echo session_id();
$output = ob_get_clean();
if ($output === false) {
    $output = '';
}
// Strip any log lines
$lines = explode("\n", trim($output));
$cleanSid = '';
foreach ($lines as $line) {
    $line = trim((string)$line);
    if ($line !== '' && !str_starts_with($line, '[') && ctype_alnum($line)) {
        $cleanSid = $line;
        break;
    }
}
echo $cleanSid;
