#!/bin/sh
# Watchdog for validator_daemon.php -- run this from cron every ~10 minutes.
# Does no validator work itself; just checks whether the daemon process is
# alive and restarts it if not. Exists because shared cPanel hosting has no
# systemd/process supervisor -- if the daemon gets OOM-killed, hits a host
# wall-clock/resource limit, or the box reboots, nothing else brings it back.
#
# cPanel cron entry -- call via `sh` explicitly rather than executing the
# path directly, so it still runs even if the FTP deploy step didn't
# preserve this file's execute bit (plain FTP transfers often don't):
#   */10 * * * * /bin/sh /var/www/115b4439-ea23-4122-87a6-9f5013856f3a/ResHome/bin/validator_watchdog.sh
#
# Putting the logic in a real script file (rather than inline in the cron
# command) avoids cPanel's cron UI mangling quotes/parentheses in a one-liner.

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_DIR" || exit 1
mkdir -p logs

# cron's PATH is often minimal and may not include php/pgrep -- resolve
# full paths once so this doesn't silently no-op under cron the way it
# might still "work" when run interactively from an SSH shell.
PHP_BIN="$(command -v php || echo /usr/bin/php)"
PGREP_BIN="$(command -v pgrep || echo /usr/bin/pgrep)"

LOG="$APP_DIR/logs/validator_daemon.log"
echo "$(date '+%Y-%m-%d %H:%M:%S') watchdog: check (php=$PHP_BIN pgrep=$PGREP_BIN)" >> "$LOG"

if ! "$PGREP_BIN" -f validator_daemon.php > /dev/null 2>&1; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') watchdog: daemon not running, restarting." >> "$LOG"
    nohup "$PHP_BIN" validator_daemon.php >> "$LOG" 2>&1 &
fi
