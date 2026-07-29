#!/bin/bash
echo "Stopping Chortke workers..."
kill $(cat /tmp/chortke-*.pid 2>/dev/null) 2>/dev/null || true
pkill -f "cli.php queue:work" || true
pkill -f "cli.php outbox:publish" || true
pkill -f "cli.php dlq:work" || true
echo "Stopped."
