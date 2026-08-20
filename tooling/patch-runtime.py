#!/usr/bin/env python3
"""Apply the minimal SQLite preview adapter to an extracted Chortke runtime.

This does not modify the authoritative source branch. It only makes the disposable
runtime usable when MariaDB/Docker packages cannot be downloaded in Arena.
"""
from pathlib import Path

p = Path(__file__).resolve().parents[1] / "runtime/core/Database.php"
s = p.read_text()

s = s.replace(
    "array{host: string, port: int|string, name: string, charset: string, user: string, pass: string, read?: array<string, mixed>}",
    "array{host: string, port: int|string, name: string, charset: string, user: string, pass: string, driver?: string, database?: string, read?: array<string, mixed>}",
)
s = s.replace(
    "$config['pass'] = (string)$config['pass'];\n\n        /** @var DbConfig $config */",
    "$config['pass'] = (string)$config['pass'];\n"
    "        $config['driver'] = isset($config['driver']) ? (string)$config['driver'] : 'mysql';\n"
    "        $config['database'] = isset($config['database']) ? (string)$config['database'] : '';\n\n"
    "        /** @var DbConfig $config */",
)
s = s.replace(
    "$dsn = \"mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset={$config['charset']};connect_timeout=2\";",
    "$isSqlite = ($config['driver'] ?? 'mysql') === 'sqlite';\n"
    "        $sqlitePath = $config['database'] ?? '';\n"
    "        $dsn = $isSqlite\n"
    "            ? 'sqlite:' . ($sqlitePath !== '' ? $sqlitePath : dirname(__DIR__) . '/storage/chortke.sqlite')\n"
    "            : \"mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset={$config['charset']};connect_timeout=2\";",
)
s = s.replace("$isPersistent = (PHP_SAPI !== 'cli');", "$isPersistent = !$isSqlite && (PHP_SAPI !== 'cli');")
for constant in ("INIT_COMMAND", "READ_TIMEOUT", "WRITE_TIMEOUT"):
    s = s.replace(
        rf"if (defined('\PDO::MYSQL_ATTR_{constant}'))",
        rf"if (!$isSqlite && defined('\PDO::MYSQL_ATTR_{constant}'))",
    )
p.write_text(s)
print(f"Patched disposable runtime: {p}")
