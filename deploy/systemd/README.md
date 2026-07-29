# systemd units for Chortke (alternative to Supervisor)

## Install

```bash
sudo cp deploy/systemd/*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable chortke-queue chortke-outbox
sudo systemctl start chortke-queue chortke-outbox
sudo systemctl status chortke-queue
```

## Logs
journalctl -u chortke-outbox -f
