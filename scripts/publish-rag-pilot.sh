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
# It also carries the built KB index - ENCRYPTED. The index holds the course
# materials verbatim (slides, exams, solutions) and this repository and its
# releases are public, so only ciphertext may leave this machine. The key lives
# in the macOS Keychain (tools/kb/seal.py keygen) and in the pilot .env.
#
#   KB_INDEX=<path without extension>   the index to ship (default: the build folder)
#   DRY_RUN=1                           build + check the tarball, skip git checks and upload
set -euo pipefail

REPO="lakhi/statsbot"
TAG="rag-pilot-latest"
ASSET="statsbot-rag-pilot.tgz"
FRONTEND_DIR="psy-lehrprojekt-frontend-client-main"
BACKEND_DIR="psy-lehrprojekt-backend-main"
BUILD_OUT="dist/lehrprojekt-client/browser"
PODS_LIST="https://console-openshift-console.web.univie.ac.at/k8s/ns/lehrprojeg67/pods"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# Outside the repository on purpose: nothing derived from the corpus is ever
# under version control here (tools/kb/README.md).
KB_INDEX="${KB_INDEX:-$(dirname "$ROOT")/statsbot-rag-build/index/kb-corpus}"
DRY_RUN="${DRY_RUN:-}"

# seal.py needs `cryptography`; any python3 that has it will do
KB_PY=("${KB_PY:-python3}")
if ! "${KB_PY[@]}" -c 'import cryptography' 2>/dev/null; then
  command -v uv >/dev/null || { echo "ERROR: ${KB_PY[*]} lacks 'cryptography' and uv is not installed" >&2; exit 1; }
  KB_PY=(uv run --no-project --quiet --with 'cryptography>=42' python)
fi

# --- 0. the index must exist, and must be sealable ---
if [ ! -f "$KB_INDEX.f32" ] || [ ! -f "$KB_INDEX.json" ]; then
  echo "ERROR: $KB_INDEX.{f32,json} missing." >&2
  echo "Build it: see $BACKEND_DIR/tools/kb/README.md" >&2
  exit 1
fi
read -r KB_FINGERPRINT < <("${KB_PY[@]}" - "$KB_INDEX.json" <<'PY'
import hashlib, json, sys
p = sys.argv[1]
m = json.load(open(p))
print(f"{m.get('doc_id')}@{m.get('built_at')} {m['count']} chunks x {m['dims']} dims, "
      f"sidecar sha256 {hashlib.sha256(open(p, 'rb').read()).hexdigest()[:12]}")
PY
)
echo "==> index: $KB_INDEX — $KB_FINGERPRINT"
"${KB_PY[@]}" "$BACKEND_DIR/tools/kb/seal.py" seal --help >/dev/null
if [ -z "${CORPUS_KEY:-}" ] && ! security find-generic-password -s statsbot-corpus-key -a statsbot >/dev/null 2>&1; then
  echo "ERROR: no corpus key. Create it once: ${KB_PY[*]} $BACKEND_DIR/tools/kb/seal.py keygen" >&2
  exit 1
fi

# --- 0b. the pod re-fetches the deploy script BY COMMIT, so that commit must be on GitHub ---
# The pod keeps its own copy of rag-pilot-deploy.sh from whenever it was installed.
# A release that relies on a newer deploy script is only safe if the paste line
# below replaces that copy first - and it can only fetch what has been pushed.
DEPLOY_SCRIPT="scripts/rag-pilot-deploy.sh"
DEPLOY_REV="$(git rev-parse HEAD)"
if [ -z "$DRY_RUN" ]; then
  git diff --quiet HEAD -- "$DEPLOY_SCRIPT" \
    || { echo "ERROR: $DEPLOY_SCRIPT has uncommitted changes — commit and push first" >&2; exit 1; }
  git fetch -q origin
  git branch -r --contains "$DEPLOY_REV" | grep -q . \
    || { echo "ERROR: HEAD ${DEPLOY_REV:0:7} is not on GitHub — push first (the pod fetches the deploy script by commit)" >&2; exit 1; }
fi
DEPLOY_SHA256="$(shasum -a 256 "$DEPLOY_SCRIPT" | awk '{print $1}')"
echo "==> deploy script pinned to ${DEPLOY_REV:0:7} (sha256 ${DEPLOY_SHA256:0:12}…)"

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
if [ -n "$DRY_RUN" ]; then
  echo "==> DRY_RUN: work dir kept at $work"
else
  trap 'rm -rf "$work"' EXIT
fi
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
    --exclude='./resources/kb' \
    --exclude='./storage/app/kb' \
    --exclude='./.venv-kb' \
    . | tar -xf - -C "$stage/backend"

# The index, sealed. It is copied under the stable name the pod expects, because
# the file name is bound into the ciphertext: a renamed file will not open.
mkdir -p "$work/plain" "$stage/backend/storage/app/kb"
cp "$KB_INDEX.f32" "$work/plain/kb-corpus.f32"
cp "$KB_INDEX.json" "$work/plain/kb-corpus.json"
"${KB_PY[@]}" "$BACKEND_DIR/tools/kb/seal.py" seal "$work/plain/kb-corpus.f32" "$work/plain/kb-corpus.json" \
    --out "$stage/backend/storage/app/kb" | sed 's/^/    /'

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

# Per-arm test URLs for internal testing: /rag-pilot-test/<slug>/ serves the same
# app, and the rag-pilot build reads the slug to pin the arm (src/app/test-arm.ts).
# Plain copies of index.html, so no Apache rewrite is involved - the base href
# /rag-pilot-test/ still resolves every asset from the parent directory.
TEST_SLUGS="no-rag non-salient salient"
for slug in $TEST_SLUGS; do
  mkdir -p "$stage/frontend/$slug"
  cp "$stage/frontend/index.html" "$stage/frontend/$slug/index.html"
done
echo "==> Test URL folders staged: $TEST_SLUGS"

# --- 2b. LEAK GUARD: nothing readable from the corpus may reach a public URL ---
# Last line of defence, after the excludes above: refuse on any vector file,
# unsealed sidecar, office/PDF document, or any of 25 chunk-text samples found
# anywhere in what is about to be uploaded.
leaks="$(find "$stage" -type f \( -name '*.f32' -o -name 'kb-*.json' -o -name 'chunks*.json' \
    -o -iname '*.pdf' -o -iname '*.pptx' -o -iname '*.docx' -o -iname '*.xlsx' -o -iname '*.jasp' \) \
    | sed "s#^$stage/##")"
[ -z "$leaks" ] || { echo "ERROR: LEAK GUARD — corpus files in the stage:" >&2; echo "$leaks" | sed 's/^/    /' >&2; exit 1; }

"${KB_PY[@]}" - "$KB_INDEX.json" > "$work/canaries.txt" <<'PY'
import json, random, sys
chunks = json.load(open(sys.argv[1]))["chunks"]
rng = random.Random(len(chunks))
texts = [" ".join(c["text"].split()) for c in chunks]
texts = [t for t in texts if len(t) >= 80]
for t in rng.sample(texts, min(25, len(texts))):
    start = rng.randrange(0, len(t) - 50)
    print(t[start:start + 50])
PY
[ -s "$work/canaries.txt" ] || { echo "ERROR: LEAK GUARD — could not sample canaries from $KB_INDEX.json" >&2; exit 1; }
if hits="$(grep -rlF -f "$work/canaries.txt" "$stage")"; then
  echo "ERROR: LEAK GUARD — course text found in:" >&2; echo "$hits" | sed "s#^$stage/#    #" >&2; exit 1
fi
echo "==> Leak guard passed: no corpus files, and none of $(wc -l < "$work/canaries.txt" | tr -d ' ') text samples appear in the stage"

tgz="$work/$ASSET"
COPYFILE_DISABLE=1 tar --no-mac-metadata -czf "$tgz" -C "$stage" . 2>/dev/null \
  || tar -czf "$tgz" -C "$stage" .
sha="$(shasum -a 256 "$tgz" | awk '{print $1}')"
echo "$sha  $ASSET" > "$tgz.sha256"
echo "==> Packaged $ASSET — sha256: $sha ($(wc -c < "$tgz") bytes)"

if [ -n "$DRY_RUN" ]; then
  echo "==> DRY_RUN: not uploading. Tarball: $tgz"
  exit 0
fi

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
 3. Paste ONE line. It replaces the pod's copy of the deploy script with the
    one this release needs (pinned to ${DEPLOY_REV:0:7}, hash-checked), then deploys:

    curl -fsSL https://raw.githubusercontent.com/$REPO/$DEPLOY_REV/$DEPLOY_SCRIPT -o /tmp/rag-pilot-deploy.sh && echo "$DEPLOY_SHA256  /tmp/rag-pilot-deploy.sh" | sha256sum -c - && cp /tmp/rag-pilot-deploy.sh /var/www/rag-pilot-deploy.sh && chmod +x /var/www/rag-pilot-deploy.sh && EXPECT=$sha bash /var/www/rag-pilot-deploy.sh

    The index in this release: $KB_FINGERPRINT
    The deploy log should show the same line after "index decrypted and checked".
    If it stops with "no CORPUS_KEY is set", paste the key ONCE (copy it here with
    \`security find-generic-password -s statsbot-corpus-key -a statsbot -w | pbcopy\`):

    read -rsp 'CORPUS_KEY: ' k && echo && printf 'CORPUS_KEY=%s\n' "\$k" >> /var/www/lehrprojekt-backend-rag/.env && unset k

    then paste the deploy line again.

 4. Only if the deploy log printed "WARNING: RAG_ENABLED" — turn retrieval on:

    sed -i 's/^RAG_ENABLED=.*/RAG_ENABLED=true/' /var/www/lehrprojekt-backend-rag/.env

 5. Re-seed the three-arm test sequence. This also releases every old
    allocation, and --with-unknown lets teammates who are not on the roster
    past the disclaimer:

    cd /var/www/lehrprojekt-backend-rag && php artisan study:seed-test --force --with-unknown

 6. Share the per-arm test URLs with the team:
    https://statsbot.univie.ac.at/rag-pilot-test/no-rag/
    https://statsbot.univie.ac.at/rag-pilot-test/non-salient/
    https://statsbot.univie.ac.at/rag-pilot-test/salient/
────────────────────────────────────────────────────────────────────────
EOF
