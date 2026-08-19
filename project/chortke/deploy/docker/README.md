# Chortke - Official Docker Setup (Production Ready)

This directory (`deploy/docker/`) contains the **canonical, clean, production-grade** Docker configuration for the Chortke platform.

## Files

- `Dockerfile` — PHP 8.4 CLI image with all required extensions (pdo_mysql, redis, bcmath, etc.) + Supervisor support.
- `docker-compose.yml` — Production multi-service setup:
  - `app` (web on port 8090)
  - `worker-queue`, `worker-outbox`, `worker-dlq` (dedicated background workers — best practice)
  - `db` (MariaDB)
  - `redis`
- `docker-entrypoint.sh` — Runs on container start:
  - Waits for database
  - Runs migrations (`php cli.php migrate --force`)
  - Seeds default admin + test users if users table is empty
- `supervisord.conf` — Optional single-container mode for workers
- `.env.docker.example` — Example environment variables for Docker

## Quick Start (Production)

```bash
cd /path/to/chortke

# 1. Copy and edit environment
cp deploy/docker/.env.docker.example .env
# Edit .env with strong passwords (DB_PASS, REDIS_PASSWORD, etc.)

# 2. Build and start (recommended)
docker compose -f deploy/docker/docker-compose.yml up -d --build

# 3. Check status
docker compose -f deploy/docker/docker-compose.yml ps
docker compose -f deploy/docker/docker-compose.yml logs -f app

# 4. Verify health
curl http://localhost:8090/health/live
curl http://localhost:8090/health/distributed
```

## Important Notes

- Workers run in separate containers (`worker-queue`, etc.) — this is the recommended production pattern.
- The entrypoint automatically runs migrations and creates basic users (`admin@chortke.ir`, `superadmin@chortke.ir`, `testuser@chortke.ir` with password `123456`).
- All sensitive values must come from `.env` (never hard-coded).
- For development you can still use the root `docker-compose.yml` or `docker-compose.dev.yml`.

## Single Container Mode (optional)

If you want everything (web + workers) in one container:

```bash
docker build -f deploy/docker/Dockerfile -t chortke .
docker run -p 8090:8090 --env-file .env chortke
```

(Supervisor inside the container will manage queue/outbox/dlq.)

## Next Steps After Docker

See the main `deploy/README.md` and the installer script (`deploy/install.sh`) for full server automation (cron, supervisor/systemd, external API keys, etc.).
