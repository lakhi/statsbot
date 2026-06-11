#!/usr/bin/env bash
#
# webspace-rollback.sh — runs INSIDE the ZID OpenShift webspace container.
# Restores the docroot from a backup tarball made by webspace-deploy.sh.
#
#   bash /var/www/webspace-rollback.sh         # restore the NEWEST backup
#   bash /var/www/webspace-rollback.sh <ts>    # restore html-backup-<ts>.tgz
#   bash /var/www/webspace-rollback.sh -l      # list available backups
#
# This file lives in the repo at scripts/webspace-rollback.sh and is copied once to /var/www/.
set -euo pipefail

DOCROOT="/var/www/html"
ACTIVE="/var/www/.active-bundle"
STATE="/var/www/.deployed-sha"
LOG="/var/www/deploy.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [rollback] $*" | tee -a "$LOG"; }

backups=(/var/www/html-backup-*.tgz)   # timestamp names sort chronologically

if [ "${1:-}" = "-l" ]; then
  echo "Available backups (newest first):"
  if [ -e "${backups[0]}" ]; then printf '%s\n' "${backups[@]}" | sort -r; else echo "  (none found)"; fi
  exit 0
fi

if [ -n "${1:-}" ]; then
  backup="/var/www/html-backup-${1}.tgz"
elif [ -e "${backups[0]}" ]; then
  backup="$(printf '%s\n' "${backups[@]}" | sort -r | head -1)"
else
  backup=""
fi
[ -n "$backup" ] && [ -f "$backup" ] || { log "ABORT: no backup found ($backup) — try -l to list"; exit 1; }

log "rolling back to $backup"
# Remove current bundles so the restored index.html references a present bundle, then restore.
rm -f "$DOCROOT"/main-*.js
tar xzf "$backup" -C /var/www --warning=no-unknown-keyword
chmod -R ug+rwX "$DOCROOT"

# Refresh markers from the restored state; clear deployed-sha so the next deploy re-applies.
active="$(grep -o 'main-[A-Z0-9]*\.js' "$DOCROOT/index.html" | head -1 || true)"
[ -n "$active" ] && echo "$active" > "$ACTIVE"
rm -f "$STATE"
log "rollback done: now serving ${active:-<unknown>} (from $backup)"
