# Chortke Production Deployment - Distributed Workers

## Quick Start (Development / Testing)

```bash
./deploy/scripts/start-workers.sh
./deploy/scripts/stop-workers.sh
```

## Production (Recommended: Supervisor)

See `supervisor/` directory.

## Alternative: systemd

See `systemd/` directory.

## Log Rotation

See `logrotate/chortke.conf`

## Monitoring

- Use `php cli.php distributed:health` manually or via cron.
- Prometheus metrics: `/metrics/distributed`
- HTTP health: `/health/distributed`

## Important Notes

- Always run workers as `www-data` (or the web user).
- Set `QUEUE_CONNECTION=redis` in .env for production.
- Monitor outbox pending count and failed_jobs.
- Restart workers after code deploys (supervisorctl restart).
