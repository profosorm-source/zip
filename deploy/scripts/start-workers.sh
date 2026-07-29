#!/bin/bash
# Quick start script for development / testing

cd "$(dirname "$0")/../.."

echo "Starting Chortke background workers..."

# Start in background with nohup (for quick testing)
nohup php cli.php queue:work --daemon --sleep=1 --tries=3 > /tmp/chortke-queue.log 2>&1 &
echo $! > /tmp/chortke-queue.pid

nohup php cli.php outbox:publish --limit=100 --sleep=5 > /tmp/chortke-outbox.log 2>&1 &
echo $! > /tmp/chortke-outbox.pid

nohup php cli.php dlq:work --limit=50 --sleep=10 > /tmp/chortke-dlq.log 2>&1 &
echo $! > /tmp/chortke-dlq.pid

echo "Workers started. PIDs in /tmp/chortke-*.pid"
echo "Logs in /tmp/chortke-*.log"
echo "To stop: kill \$(cat /tmp/chortke-*.pid)"
