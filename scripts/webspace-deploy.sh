#!/usr/bin/env bash
#
# webspace-deploy.sh — runs INSIDE the ZID OpenShift webspace container.
# Pulls the published Angular build from the GitHub release and applies it to the docroot.
# Safe by design: integrity-gated, backs up before changing, idempotent, keeps a rollback window.
#
#   Manual (Option B):   EXPECT=<sha256> bash /var/www/webspace-deploy.sh
#   Cron  (future Opt A):              bash /var/www/webspace-deploy.sh   # verifies via published .sha256
#
# This file lives in the repo at scripts/webspace-deploy.sh and is copied once to /var/www/.
set -euo pipefail

REPO="lakhi/statsbot"
TAG="frontend-latest"
ASSET="statsbot-frontend.tgz"
DOCROOT="/var/www/html"
STATE="/var/www/.deployed-sha"
ACTIVE="/var/www/.active-bundle"
LOG="/var/www/deploy.log"
KEEP_BACKUPS=5
BASE="https://github.com/${REPO}/releases/download/${TAG}"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

log "=== deploy start (pod $(hostname)) ==="

# 1. Download the published build
log "downloading ${BASE}/${ASSET}"
curl -fsSL "${BASE}/${ASSET}" -o "$tmp/$ASSET"

# 2. Integrity gate
got="$(sha256sum "$tmp/$ASSET" | awk '{print $1}')"
if [ -n "${EXPECT:-}" ]; then
  want="$EXPECT"; src="caller (EXPECT)"
else
  curl -fsSL "${BASE}/${ASSET}.sha256" -o "$tmp/$ASSET.sha256"
  want="$(awk '{print $1}' "$tmp/$ASSET.sha256")"; src="release .sha256"
fi
if [ "$got" != "$want" ]; then
  log "ABORT: sha256 mismatch — got $got, expected $want (from $src)"
  exit 1
fi
log "integrity OK ($src): $got"

# 3. Idempotency — skip if this exact build is already live
if [ -f "$STATE" ] && [ "$(cat "$STATE")" = "$got" ]; then
  log "no change — build $got already deployed; exiting"
  exit 0
fi

# 4. Back up the current docroot (kept in /var/www, not web-served), prune to last $KEEP_BACKUPS
ts="$(date '+%Y%m%d-%H%M%S')"
backup="/var/www/html-backup-${ts}.tgz"
tar czf "$backup" -C /var/www html
log "backup created: $backup"
# prune old backups, keeping newest $KEEP_BACKUPS (timestamp names sort chronologically)
backups=(/var/www/html-backup-*.tgz)
if [ -e "${backups[0]}" ]; then
  printf '%s\n' "${backups[@]}" | sort -r | tail -n +$((KEEP_BACKUPS+1)) | while read -r old; do
    rm -f "$old" && log "pruned old backup: $old"
  done
fi

# 5. Apply (non-destructive: .htaccess + lehrprojekt-backend/ are not in the tarball, so untouched)
tar xzf "$tmp/$ASSET" -C "$DOCROOT" --warning=no-unknown-keyword
chmod -R ug+rwX "$DOCROOT"
log "extracted build into $DOCROOT"

# 6. Prune stale bundles — keep the active one + the immediately previous (grace window for caches)
active="$(grep -o 'main-[A-Z0-9]*\.js' "$DOCROOT/index.html" | head -1 || true)"
prev="$(cat "$ACTIVE" 2>/dev/null || true)"
log "active bundle: ${active:-<none>} (previous: ${prev:-<none>})"
if [ -n "$active" ]; then
  for f in "$DOCROOT"/main-*.js; do
    [ -e "$f" ] || continue
    b="$(basename "$f")"
    if [ "$b" != "$active" ] && [ "$b" != "${prev:-}" ]; then
      rm -f "$f" && log "pruned stale bundle: $b"
    fi
  done
  echo "$active" > "$ACTIVE"
fi

# 7. Record state
echo "$got" > "$STATE"
log "=== deploy done: now serving ${active:-<unknown>} (sha $got) ==="
