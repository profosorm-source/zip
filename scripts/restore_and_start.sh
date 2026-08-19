#!/usr/bin/env bash
set -euo pipefail
source /home/user/.local/chortke-env.sh
mkdir -p /home/user/data/redis /home/user/data/mariadb /home/user/data/logs
if ! redis-cli ping >/dev/null 2>&1; then
  redis-server /home/user/.local/etc/redis.conf --daemonize yes
fi
if ! pgrep -x mariadbd >/dev/null; then
  /home/user/.local/mariadb/bin/mariadbd --defaults-file=/home/user/.local/etc/my.cnf --user="$(id -un)" \
    >> /home/user/data/logs/mariadb.log 2>&1 &
  for _ in $(seq 1 40); do
    [[ -S /home/user/data/mariadb/mysql.sock ]] && break
    sleep 0.3
  done
fi
echo "php=$(php -r 'echo PHP_VERSION;')"
echo "redis=$(redis-cli ping 2>/dev/null || echo down)"
echo "mariadb=$(test -S /home/user/data/mariadb/mysql.sock && echo up || echo down)"
