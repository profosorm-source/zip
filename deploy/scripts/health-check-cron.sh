#!/bin/bash
# Run via cron every 5 minutes for alerting
cd /var/www/chortke
OUTPUT=$(php cli.php distributed:health 2>&1)
if echo "$OUTPUT" | grep -q "High pending\|High DLQ"; then
    echo "[$(date)] ALERT: $OUTPUT" | logger -t chortke-health
    # Add Slack/email webhook here if needed
fi
