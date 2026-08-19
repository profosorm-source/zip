# Supervisor Configuration for Chortke Distributed Workers

## Installation (Ubuntu/Debian)

```bash
sudo apt update
sudo apt install supervisor
sudo mkdir -p /var/log/chortke
sudo chown -R www-data:www-data /var/log/chortke
```

## Copy configs

```bash
sudo cp deploy/supervisor/*.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start chortke-queue:*
sudo supervisorctl start chortke-outbox
sudo supervisorctl start chortke-dlq
```

## Useful commands

```bash
sudo supervisorctl status
sudo supervisorctl restart chortke-queue:*
sudo supervisorctl tail -f chortke-outbox
sudo supervisorctl stop all
```

## Logs rotation

See ../logrotate/chortke.conf
