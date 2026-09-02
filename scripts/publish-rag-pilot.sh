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
# It carries CODE ONLY. The course-material index ships as a SEPARATE bundle that
# is never published here: this repo is public, and the index embeds the lecture
# notes verbatim. That bundle is written to dist/ for you to place somewhere the
# pod can reach privately, and the deploy script fetches it from a CORPUS_URL you
# supply. See "corpus" in the output below.
set -euo pipefail

REPO="lakhi/statsbot"
TAG="rag-pilot-latest"
ASSET="statsbot-rag-pilot.tgz"
CORPUS_ASSET="statsbot-rag-pilot-corpus.tgz"
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

# backend app, minus everything the pod supplies or must not receive
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
    . | tar -xf - -C "$stage/backend"

# NB: the index deliberately does NOT go in this tarball - see the header.
mkdir -p "$stage/backend/storage/app/kb"

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

# --- 2b. the corpus bundle: index only, NEVER published to this public repo ---
mkdir -p "$ROOT/dist"
corpus="$ROOT/dist/$CORPUS_ASSET"
COPYFILE_DISABLE=1 tar --no-mac-metadata -czf "$corpus" \
    -C "$(dirname "$idx")" "$(basename "$idx").f32" "$(basename "$idx").json" 2>/dev/null \
  || tar -czf "$corpus" -C "$(dirname "$idx")" "$(basename "$idx").f32" "$(basename "$idx").json"
corpus_sha="$(shasum -a 256 "$corpus" | awk '{print $1}')"
echo "$corpus_sha  $CORPUS_ASSET" > "$corpus.sha256"
echo "==> Corpus bundle (PRIVATE, not uploaded): $corpus"
echo "    sha256: $corpus_sha ($(wc -c < "$corpus") bytes)"

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
# Last line of defence: this release is PUBLIC. Refuse to upload if any lecture
# text slipped into the code tarball.
if tar tzf "$tgz" | grep -qE 'storage/app/kb/.+\.(f32|json)$|resources/kb/hyptest'; then
  echo "ERROR: the code tarball contains corpus files. Refusing to publish to a public release." >&2
  exit 1
fi

gh release upload "$TAG" "$tgz" "$tgz.sha256" --repo "$REPO" --clobber
echo "==> Uploaded to release $TAG"

cat <<EOF

────────────────────────────────────────────────────────────────────────
Next: apply it on the pod.

 1. Upload the corpus bundle somewhere the pod can fetch it privately:
        $corpus
    A u:cloud share link keeps the lecture notes on University
    infrastructure and needs no token on the pod. Any URL curl can reach
    works; add CORPUS_TOKEN=… if it needs an Authorization header.

 2. Connect to the U:Wien VPN.
 3. Open $PODS_LIST
    and click the running zid-webproject-… pod → Terminal.
 4. Paste ONE line:

    EXPECT=$sha CORPUS_URL='<your-url>' CORPUS_EXPECT=$corpus_sha bash /var/www/rag-pilot-deploy.sh

 (First time only, install the deploy script first — see
  scripts/rag-pilot-deploy.sh header.)
────────────────────────────────────────────────────────────────────────
EOF
