#!/usr/bin/env bash
#
# publish-rag-pilot.sh — build the RAG pilot stack and publish it to a GitHub release.
#
# Runs on your Mac (needs node + gh authed). Pull-based deploy, exactly like the
# production frontend: the pod fetches this asset and applies it via
# scripts/rag-pilot-deploy.sh.
#
# DELIBERATELY SEPARATE from publish-frontend.sh. That script's tag is
# `frontend-latest` and its pod-side counterpart extracts into /var/www/html —
# reusing either would overwrite production. Different tag, different asset,
# different deploy script, no shared state.
#
# The tarball carries three trees that land in three NEW directories:
#
#   backend/     -> /var/www/lehrprojekt-backend-rag/   (app; vendor/ copied on the pod)
#   public-api/  -> /var/www/html/rag-pilot-test-api/   (index.php + .htaccess)
#   frontend/    -> /var/www/html/rag-pilot-test/       (Angular, base-href /rag-pilot-test/)
#
# It also carries the built KB index, which is gitignored and therefore has no
# other route to the pod.
set -euo pipefail

REPO="lakhi/statsbot"
TAG="rag-pilot-latest"
ASSET="statsbot-rag-pilot.tgz"
FRONTEND_DIR="psy-lehrprojekt-frontend-client-main"
BACKEND_DIR="psy-lehrprojekt-backend-main"
BUILD_OUT="dist/lehrprojekt-client/browser"
INDEX_STEM="kb-hyptest-3large-3072"
PODS_LIST="https://console-openshift-console.web.univie.ac.at/k8s/ns/lehrprojeg67/pods"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# --- 0. the index must exist; it is a build artifact, not a repo file ---
idx="$BACKEND_DIR/storage/app/kb/$INDEX_STEM"
if [ ! -f "$idx.f32" ] || [ ! -f "$idx.json" ]; then
  echo "ERROR: $idx.{f32,json} missing." >&2
  echo "Rebuild it: see $BACKEND_DIR/tools/kb/README.md" >&2
  exit 1
fi
echo "==> index present: $(basename "$idx").f32 ($(wc -c < "$idx.f32") bytes)"

# --- 1. build the pilot frontend (base-href + test API URL) ---
echo "==> Building pilot frontend…"
(
  cd "$FRONTEND_DIR"
  npm ci
  npx ng build --configuration rag-pilot
)
[ -f "$FRONTEND_DIR/$BUILD_OUT/index.html" ] || { echo "ERROR: frontend build produced no index.html" >&2; exit 1; }
grep -q 'base href="/rag-pilot-test/"' "$FRONTEND_DIR/$BUILD_OUT/index.html" \
  || { echo "ERROR: built index.html lacks the /rag-pilot-test/ base href" >&2; exit 1; }
grep -rq 'rag-pilot-test-api' "$FRONTEND_DIR/$BUILD_OUT"/main-*.js \
  || { echo "ERROR: build does not point at the pilot API — wrong configuration?" >&2; exit 1; }
echo "==> Frontend OK (base-href and pilot API URL both present)"

# --- 2. assemble the tarball ---
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
stage="$work/stage"
mkdir -p "$stage/backend" "$stage/public-api" "$stage/frontend"

# backend app, minus everything the pod supplies or must not receive.
# bootstrap/cache/*.php matters more than it looks: it is Laravel's package-discovery
# manifest, it is gitignored (so it never shows in git status), and this tar reads the
# WORKING TREE, not git. A manifest built here lists dev providers such as Collision;
# the pod's vendor/ is installed --no-dev, so registering them fatals on boot. Ship no
# manifest and Laravel rebuilds a correct one from the pod's own installed.json.
tar -cf - -C "$BACKEND_DIR" \
    --exclude='./vendor' \
    --exclude='./node_modules' \
    --exclude='./.env' \
    --exclude='./.git' \
    --exclude='./storage/logs/*' \
    --exclude='./storage/framework/cache/data/*' \
    --exclude='./storage/framework/sessions/*' \
    --exclude='./storage/framework/views/*' \
    --exclude='./tools/kb/__pycache__' \
    --exclude='./composer.phar' \
    --exclude='./.phpunit.result.cache' \
    --exclude='./bootstrap/cache/*.php' \
    . | tar -xf - -C "$stage/backend"

# the built index (gitignored, so it can only travel this way)
mkdir -p "$stage/backend/storage/app/kb"
cp "$idx.f32" "$idx.json" "$stage/backend/storage/app/kb/"

# writable dirs Laravel needs but git does not carry
mkdir -p "$stage/backend/storage/framework/cache/data" \
         "$stage/backend/storage/framework/sessions" \
         "$stage/backend/storage/framework/views" \
         "$stage/backend/storage/logs" \
         "$stage/backend/bootstrap/cache"

# public entry point: same two-level reach-up as the live one, retargeted
cat > "$stage/public-api/index.php" <<'PHP'
<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Mirrors /var/www/html/lehrprojekt-backend/index.php, but reaches the PARALLEL
// application. Two levels up from here is /var/www/.
if (file_exists($maintenance = __DIR__.'/../../lehrprojekt-backend-rag/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../../lehrprojekt-backend-rag/vendor/autoload.php';

(require_once __DIR__.'/../../lehrprojekt-backend-rag/bootstrap/app.php')
    ->handleRequest(Request::capture());
PHP

# Laravel's rewrite rules. The Shibboleth block is inherited from the docroot
# .htaccess, so it is deliberately NOT repeated here.
cat > "$stage/public-api/.htaccess" <<'HTA'
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
HTA

cp -R "$FRONTEND_DIR/$BUILD_OUT"/. "$stage/frontend/"

tgz="$work/$ASSET"
COPYFILE_DISABLE=1 tar --no-mac-metadata -czf "$tgz" -C "$stage" . 2>/dev/null \
  || tar -czf "$tgz" -C "$stage" .
sha="$(shasum -a 256 "$tgz" | awk '{print $1}')"
echo "$sha  $ASSET" > "$tgz.sha256"
echo "==> Packaged $ASSET — sha256: $sha ($(wc -c < "$tgz") bytes)"

# --- 3. publish ---
if ! gh release view "$TAG" --repo "$REPO" >/dev/null 2>&1; then
  gh release create "$TAG" --repo "$REPO" \
     --title "RAG pilot (moving tag)" \
     --notes "Parallel test stack for the course-material grounding pilot. Not production." \
     --prerelease
fi
gh release upload "$TAG" "$tgz" "$tgz.sha256" --repo "$REPO" --clobber
echo "==> Uploaded to release $TAG"

cat <<EOF

────────────────────────────────────────────────────────────────────────
Next: apply it on the pod.

 1. Connect to the U:Wien VPN.
 2. Open $PODS_LIST
    and click the running zid-webproject-… pod → Terminal.
 3. Paste ONE line:

    EXPECT=$sha bash /var/www/rag-pilot-deploy.sh

 (First time only, install the deploy script first — see
  scripts/rag-pilot-deploy.sh header.)
────────────────────────────────────────────────────────────────────────
EOF
