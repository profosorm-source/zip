#!/bin/bash
set -e

echo "=== Chortke Docker Entrypoint ==="

# Wait for DB
echo "Waiting for database..."
for i in {1..40}; do
    if php -r "
        try {
            \$pdo = new PDO('mysql:host='.\$_ENV['DB_HOST'].';port='.\$_ENV['DB_PORT'].';dbname='.\$_ENV['DB_NAME'], \$_ENV['DB_USER'], \$_ENV['DB_PASS'], [PDO::ATTR_TIMEOUT=>2]);
            echo 'DB ready';
            exit(0);
        } catch (Exception \$e) { exit(1); }
    " 2>/dev/null; then
        break
    fi
    echo "DB not ready, retry $i/40..."
    sleep 2
done

# Run migrations (idempotent)
echo "Running migrations..."
php cli.php migrate --force 2>/dev/null || echo "Migration step completed (or no pending migrations)"

# Seed minimal admin + test users if table is empty (safe)
echo "Seeding default users if needed..."
php -r '
try {
    $pdo = new PDO("mysql:host=".$_ENV["DB_HOST"].";port=".$_ENV["DB_PORT"].";dbname=".$_ENV["DB_NAME"], $_ENV["DB_USER"], $_ENV["DB_PASS"]);
    $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count == 0) {
        $hash = password_hash("123456", PASSWORD_DEFAULT);
        $pdo->exec("INSERT IGNORE INTO users (email, password, role, status, kyc_status, created_at, updated_at) VALUES
            (\"admin@chortke.ir\", \"$hash\", \"admin\", \"active\", \"verified\", NOW(), NOW()),
            (\"superadmin@chortke.ir\", \"$hash\", \"superadmin\", \"active\", \"verified\", NOW(), NOW()),
            (\"testuser@chortke.ir\", \"$hash\", \"user\", \"active\", \"unverified\", NOW(), NOW())
        ");
        echo "Default users created.\n";
    } else {
        echo "Users table has $count rows.\n";
    }
} catch (Exception $e) {
    echo "User seeding skipped: " . $e->getMessage() . "\n";
}
'

echo "=== Chortke container ready ==="
exec "$@"
