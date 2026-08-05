#!/bin/sh
# Watchdog for validator_daemon.php -- run this from cron every ~10 minutes.
# Does no validator work itself; just checks whether the daemon process is
# alive and restarts it if not. Exists because shared cPanel hosting has no
# systemd/process supervisor -- if the daemon gets OOM-killed, hits a host
# wall-clock/resource limit, or the box reboots, nothing else brings it back.
#
# cPanel cron entry (adjust the path to match your app directory):
#   */10 * * * * /var/www/115b4439-ea23-4122-87a6-9f5013856f3a/ResHome/bin/validator_watchdog.sh
#
# Putting the logic in a real script file (rather than inline in the cron
# command) avoids cPanel's cron UI mangling quotes/parentheses in a one-liner.

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_DIR" || exit 1

if ! pgrep -f validator_daemon.php > /dev/null 2>&1; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') watchdog: daemon not running, restarting." >> logs/validator_daemon.log
    nohup php validator_daemon.php >> logs/validator_daemon.log 2>&1 &
fi
