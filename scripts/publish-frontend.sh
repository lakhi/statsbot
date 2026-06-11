#!/usr/bin/env bash
#
# publish-frontend.sh — build the Angular frontend, package it, and publish it to the
# moving `frontend-latest` GitHub release. Prints the one-line command to paste into the
# live ZID OpenShift pod Terminal.
#
# Runs on your Mac (needs node + gh authed). Pull-based deploy: the pod fetches this build.
# See .claude/skills/deploy-frontend/SKILL.md for the full flow.
set -euo pipefail

REPO="lakhi/statsbot"
TAG="frontend-latest"
ASSET="statsbot-frontend.tgz"
FRONTEND_DIR="psy-lehrprojekt-frontend-client-main"
BUILD_OUT="dist/lehrprojekt-client/browser"
POD_TERMINAL="https://console-openshift-console.web.univie.ac.at/k8s/ns/lehrprojeg67/pods/zid-webproject-7f5f77ffb7-zfvt8/terminal"
PODS_LIST="https://console-openshift-console.web.univie.ac.at/k8s/ns/lehrprojeg67/pods"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT/$FRONTEND_DIR"

echo "==> Building frontend (npm ci && npm run build)…"
npm ci
npm run build

# --- structural sanity (the build itself is the real gate; this catches a broken output) ---
[ -f "$BUILD_OUT/index.html" ] || { echo "ERROR: $BUILD_OUT/index.html missing — build failed?" >&2; exit 1; }
main="$(grep -o 'main-[A-Z0-9]*\.js' "$BUILD_OUT/index.html" | head -1 || true)"
[ -n "$main" ] && [ -f "$BUILD_OUT/$main" ] || { echo "ERROR: index.html does not reference a present main-*.js" >&2; exit 1; }
echo "==> Build OK — index.html references $main"

# --- package (strip macOS xattrs so the container's tar stays quiet) ---
work="$(mktemp -d)"
tgz="$work/$ASSET"
COPYFILE_DISABLE=1 tar --no-mac-metadata -czf "$tgz" -C "$BUILD_OUT" . 2>/dev/null \
  || tar -czf "$tgz" -C "$BUILD_OUT" .
sha="$(shasum -a 256 "$tgz" | awk '{print $1}')"
echo "$sha  $ASSET" > "$tgz.sha256"
echo "==> Packaged $ASSET — sha256: $sha"

# --- publish: create the rolling release if missing, then clobber the asset ---
if ! gh release view "$TAG" --repo "$REPO" >/dev/null 2>&1; then
  echo "==> Creating rolling release '$TAG'…"
  gh release create "$TAG" --repo "$REPO" --title "Frontend (rolling latest)" \
    --notes "Rolling latest Angular build for statsbot.univie.ac.at. Asset is replaced on each publish by scripts/publish-frontend.sh."
fi
echo "==> Uploading asset (clobber)…"
gh release upload "$TAG" "$tgz" "$tgz.sha256" --repo "$REPO" --clobber

cat <<EOF

────────────────────────────────────────────────────────────
✅ Published build  $sha  to release '$TAG'.

NEXT — apply it on the live pod (Option B: you approve this live write):
  1. Connect to the U:Wien VPN.
  2. Open the pod Terminal:
       $POD_TERMINAL
     (The pod name changes when the pod restarts — if that link 404s, open
       $PODS_LIST
      and click the running zid-webproject-… pod → "Terminal" tab.)
  3. Paste this single line:

       EXPECT=$sha bash /var/www/webspace-deploy.sh

  4. To roll back if needed:  bash /var/www/webspace-rollback.sh
────────────────────────────────────────────────────────────
EOF
