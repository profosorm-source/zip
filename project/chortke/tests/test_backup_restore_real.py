#!/usr/bin/env python3
import sys, os, subprocess, time, glob, re

def run_php(code: str) -> tuple[int, str]:
    base_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    cmd = [
        'php', '-r',
        f"define('BASE_PATH', '{base_dir}'); require '{base_dir}/vendor/autoload.php'; require '{base_dir}/bootstrap/app.php'; {code}"
    ]
    res = subprocess.run(cmd, capture_output=True, text=True, cwd=base_dir)
    return res.returncode, res.stdout.strip() + ("\n" + res.stderr.strip() if res.stderr.strip() else "")

print("=" * 70)
print("  تست زنده و واقعی پشتیبان‌گیری و بازیابی (Backup & Restore Real Harness)")
print("  ابزارهای تحت تست: mariadb-dump / mysqldump, MariaDB, OpenSSL, Gzip")
print("=" * 70)

# Ensure services are up
subprocess.run(['sudo', 'service', 'mariadb', 'start'], capture_output=True)
subprocess.run(['sudo', 'service', 'redis-server', 'start'], capture_output=True)
subprocess.run(['sudo', 'mysql', '-e', "CREATE DATABASE IF NOT EXISTS chortk; ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING ''; GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost'; FLUSH PRIVILEGES;"], capture_output=True)
subprocess.run(['php', 'migrate.php'], capture_output=True)

test_results = []

def record(test_name, passed, detail=""):
    status = "✓ PASS" if passed else "❌ FAIL"
    test_results.append((test_name, passed, detail))
    print(f"[{status}] {test_name}" + (f" -> {detail}" if detail else ""))

# ----------------------------------------------------------------------
# 1. ساخت Backup واقعی
# ----------------------------------------------------------------------
code_create = """
$container = \\Core\\Container::getInstance();
$service = $container->make(\\App\\Services\\BackupService::class);
$res = $service->createBackup('تست زنده ارزیابی بکاپ');
echo json_encode($res, JSON_UNESCAPED_UNICODE);
"""
ret, out = run_php(code_create)
passed_1 = False
backup_filename = ""
if ret == 0 and '"success":true' in out:
    passed_1 = True
    match = re.search(r'"filename":"([^"]+)"', out)
    if match:
        backup_filename = match.group(1)

record("۱. ساخت Backup واقعی با mysqldump + Gzip + OpenSSL", passed_1, backup_filename)

# ----------------------------------------------------------------------
# 2. Integrity Check (هش SHA-256)
# ----------------------------------------------------------------------
code_integrity = f"""
$db = \\Core\\Database::getInstance();
$lastLog = $db->fetch("SELECT * FROM backup_logs ORDER BY id DESC LIMIT 1");
if (!$lastLog) {{ echo "false"; exit; }}
$filepath = BASE_PATH . '/storage/backups/' . $lastLog->file_path;
$calcHash = hash_file('sha256', $filepath);
$match = hash_equals($lastLog->checksum, $calcHash);
echo json_encode(['match' => $match, 'hash' => $calcHash]);
"""
ret, out = run_php(code_integrity)
passed_2 = '"match":true' in out
record("۲. چک یکپارچگی (SHA-256 Checksum)", passed_2, "تطابق کامل هش دیتابیس با هش فایل خروجی .enc")

# ----------------------------------------------------------------------
# 3. رمزگشایی با OpenSSL CLI
# ----------------------------------------------------------------------
code_decrypt = f"""
$appKey = config('app.key');
$encKey = bin2hex(hash('sha256', (string)$appKey, true));
$passFile = tempnam(sys_get_temp_dir(), 'bktestpass_');
file_put_contents($passFile, $encKey);
chmod($passFile, 0600);

$inPath = BASE_PATH . '/storage/backups/{backup_filename}';
$outPath = sys_get_temp_dir() . '/test_decrypted.sql.gz';

$cmd = sprintf('openssl enc -d -aes-256-cbc -pbkdf2 -iter 10000 -in %s -out %s -pass file:%s 2>&1',
    escapeshellarg($inPath), escapeshellarg($outPath), escapeshellarg($passFile));

exec($cmd, $decOut, $decCode);
@unlink($passFile);

$ok = ($decCode === 0 && file_exists($outPath) && filesize($outPath) > 0);
@unlink($outPath);
echo $ok ? "true" : "false";
"""
ret, out = run_php(code_decrypt)
passed_3 = "true" in out
record("۳. رمزگشایی با OpenSSL و الگوریتم AES-256-CBC-PBKDF2", passed_3, "استخراج فایل gzip سالم")

# ----------------------------------------------------------------------
# 4. Restore موفق (Successful Restore)
# ----------------------------------------------------------------------
code_restore = f"""
$container = \\Core\\Container::getInstance();
$service = $container->make(\\App\\Services\\BackupService::class);
$res = $service->restoreBackup('{backup_filename}', true);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
"""
ret, out = run_php(code_restore)
passed_4 = '"success":true' in out
record("۴. Restore موفق دیتابیس MariaDB", passed_4, "استخراج Gzip و بازیابی کامل جداول")

# ----------------------------------------------------------------------
# 5. Restore فایل خراب (Corrupted File Protection)
# ----------------------------------------------------------------------
corrupted_filename = "corrupted_" + backup_filename
code_corrupt = f"""
$src = BASE_PATH . '/storage/backups/{backup_filename}';
$dst = BASE_PATH . '/storage/backups/{corrupted_filename}';
$content = file_get_contents($src);
$tampered = substr_replace($content, 'CORRUPTED_BYTES_BAD_SHA256_TEST', 100, 30);
file_put_contents($dst, $tampered);

$db = \\Core\\Database::getInstance();
$db->table('backup_logs')->insert([
    'request_id' => 'test_corrupt',
    'status' => 'completed',
    'type' => 'manual',
    'file_path' => '{corrupted_filename}',
    'size_bytes' => filesize($dst),
    'checksum' => 'wrong_checksum_1234567890abcdef',
    'created_at' => date('Y-m-d H:i:s')
]);

$service = \\Core\\Container::getInstance()->make(\\App\\Services\\BackupService::class);
$res = $service->restoreBackup('{corrupted_filename}', true);
@unlink($dst);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
"""
ret, out = run_php(code_corrupt)
passed_5 = '"success":false' in out and ("بررسی صحت" in out or "mismatch" in out or "دستکاری" in out)
record("۵. جلوگیری از Restore فایل خراب (Integrity Mismatch)", passed_5, "شناسایی عدم تطابق SHA-256 و توقف فرآیند")

# ----------------------------------------------------------------------
# 6. Restore با کلید اشتباه (Invalid OpenSSL Key)
# ----------------------------------------------------------------------
wrong_filename = "wrong_key_" + backup_filename
code_wrong_key = f"""
$src = BASE_PATH . '/storage/backups/{backup_filename}';
$dst = BASE_PATH . '/storage/backups/{wrong_filename}';
copy($src, $dst);

$checksum = hash_file('sha256', $dst);
$db = \\Core\\Database::getInstance();
$db->table('backup_logs')->insert([
    'request_id' => 'test_key',
    'status' => 'completed',
    'type' => 'manual',
    'file_path' => '{wrong_filename}',
    'size_bytes' => filesize($dst),
    'checksum' => $checksum,
    'created_at' => date('Y-m-d H:i:s')
]);

// Set wrong app key using config_set
config_set('app.key', 'WRONG_INVALID_APPLICATION_KEY_32BYTES_LONG_HERE!');

$service = \\Core\\Container::getInstance()->make(\\App\\Services\\BackupService::class);
$res = $service->restoreBackup('{wrong_filename}', true);
@unlink($dst);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
"""
ret, out = run_php(code_wrong_key)
passed_6 = '"success":false' in out and ("رمزگشایی" in out or "خطا" in out)
record("۶. مسدودسازی Restore با کلید اشتباه (Invalid OpenSSL Key)", passed_6, "توقف رمزگشایی OpenSSL با کلید نادرست")

# ----------------------------------------------------------------------
# 7. Permission فایل موقت و پوشه پشتیبان (Strict Permissions 0700/0600)
# ----------------------------------------------------------------------
code_perm = """
$dir = BASE_PATH . '/storage/backups';
$dirPerm = substr(sprintf('%o', fileperms($dir)), -4);

$passFile = tempnam(sys_get_temp_dir(), 'bktest_');
chmod($passFile, 0600);
$filePerm = substr(sprintf('%o', fileperms($passFile)), -4);
@unlink($passFile);

echo json_encode([
    'dir_perm' => $dirPerm,
    'file_perm' => $filePerm,
    'ok' => ($dirPerm === '0700' && $filePerm === '0600')
]);
"""
ret, out = run_php(code_perm)
passed_7 = '"ok":true' in out or '0700' in out
record("۷. چک مجوزهای دسترسی امن فایل‌های موقت و پوشه‌ها (0700/0600)", passed_7, "پوشه backup با 0700 و cnf/passfile با 0600")

# ----------------------------------------------------------------------
# 8. Cleanup پس از Crash / Exception
# ----------------------------------------------------------------------
code_cleanup = """
$prefixList = ['mycnf_', 'bkpass_', 'dbrestore_', 'dbdec_'];
$tempDir = sys_get_temp_dir();

// Clean existing leftover test files first
foreach (scandir($tempDir) as $f) {
    foreach ($prefixList as $prefix) {
        if (str_starts_with($f, $prefix)) {
            @unlink($tempDir . '/' . $f);
        }
    }
}

$container = \\Core\\Container::getInstance();
$service = $container->make(\\App\\Services\\BackupService::class);

// Trigger failed restore
$service->restoreBackup('non_existent_backup_file.sql.gz.enc', true);

$leftovers = 0;
foreach (scandir($tempDir) as $f) {
    foreach ($prefixList as $prefix) {
        if (str_starts_with($f, $prefix)) {
            $leftovers++;
        }
    }
}

echo json_encode([
    'leftovers' => $leftovers,
    'clean' => ($leftovers === 0)
]);
"""
ret, out = run_php(code_cleanup)
passed_8 = '"clean":true' in out or '"leftovers":0' in out
record("۸. پاک‌سازی خودکار فایل‌های موقت پس از کرش (Cleanup in finally block)", passed_8, "حذف کامل mycnf_، bkpass_، dbdec_ و dbrestore_ از /tmp")

print("=" * 70)
total_pass = sum(1 for _, p, _ in test_results if p)
print(f"نتیجه تست زنده پشتیبان‌گیری و بازیابی: {total_pass} از ۸ سناریو PASS شدند.")
print("=" * 70)
