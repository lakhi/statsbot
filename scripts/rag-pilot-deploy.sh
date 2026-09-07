#!/usr/bin/env bash
#
# rag-pilot-deploy.sh — runs INSIDE the ZID OpenShift pod.
# Stands up the RAG pilot as a stack PARALLEL to production.
#
#   One-time install:
#     curl -fsSL https://raw.githubusercontent.com/lakhi/statsbot/rag-pilot/scripts/rag-pilot-deploy.sh -o /var/www/rag-pilot-deploy.sh
#     chmod +x /var/www/rag-pilot-deploy.sh
#
#   Deploy:
#     EXPECT=<sha256> bash /var/www/rag-pilot-deploy.sh
#
#   Deploy AND point the tutor at an open-weight model on the Foundry resource:
#     AZURE_CHAT_API_KEY=<foundry key> EXPECT=<sha256> bash /var/www/rag-pilot-deploy.sh
#
#   Without AZURE_CHAT_API_KEY the pilot keeps whatever chat model it already had
#   (on a first install, the live one), so the model swap is opt-in per deploy and
#   the key never has to live in the repo or the release asset.
#
# ISOLATION IS THE POINT. This script writes to exactly three NEW paths and
# nothing else:
#
#     /var/www/lehrprojekt-backend-rag/
#     /var/www/html/rag-pilot-test-api/
#     /var/www/html/rag-pilot-test/
#
# It never writes to /var/www/html itself, never to /var/www/lehrprojekt-backend,
# and it aborts before touching the database unless the effective table prefix is
# rag_. That last guard matters more than it looks: `artisan migrate` against an
# unprefixed connection would find the existing migrations table, conclude only
# the newest migration is pending, and ALTER THE LIVE history TABLE.
#
# It also prints the live history row count before and after, so isolation is
# demonstrated rather than asserted.
set -euo pipefail

REPO="lakhi/statsbot"
TAG="rag-pilot-latest"
ASSET="statsbot-rag-pilot.tgz"

LIVE_APP="/var/www/lehrprojekt-backend"
APP="/var/www/lehrprojekt-backend-rag"
API_PUB="/var/www/html/rag-pilot-test-api"
WEB="/var/www/html/rag-pilot-test"
STATE="/var/www/.rag-pilot-sha"
LOG="/var/www/rag-pilot-deploy.log"
BASE="https://github.com/${REPO}/releases/download/${TAG}"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }
die() { log "ABORT: $*"; exit 1; }

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

log "=== rag-pilot deploy start (pod $(hostname)) ==="

# --- 0. refuse to run if the live paths are not where we expect them ---
[ -d "$LIVE_APP" ]  || die "live app missing at $LIVE_APP — wrong pod?"
[ -d /var/www/html ] || die "docroot missing"
case "$APP$API_PUB$WEB" in
  *"/var/www/html "*|*" /var/www/html"*) die "refusing: a target resolves to the docroot" ;;
esac

# --- 1. a row count BEFORE we do anything, from the live app's own config ---
cat > "$tmp/count.php" <<'PHP'
<?php
// Counts rows in the LIVE tables using the LIVE app's own credentials.
// Read-only; never writes. argv[1] is the .env to read.
$env = [];
foreach (file($argv[1]) as $l) {
    if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $l, $m)) {
        $env[$m[1]] = trim(trim($m[2]), "\"'");
    }
}
try {
    $p = new PDO("mysql:host={$env['DB_HOST']};dbname={$env['DB_DATABASE']}",
                 $env['DB_USERNAME'], $env['DB_PASSWORD'],
                 [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    printf('history=%d students=%d',
        $p->query('SELECT COUNT(*) FROM history')->fetchColumn(),
        $p->query('SELECT COUNT(*) FROM students')->fetchColumn());
} catch (Exception $e) {
    echo 'unavailable';
}
PHP

livecount() { php "$tmp/count.php" "$LIVE_APP/.env" 2>/dev/null; }

before="$(livecount)"
log "LIVE tables before: $before"

# --- 2. download + integrity gate ---
log "downloading ${BASE}/${ASSET}"
curl -fsSL "${BASE}/${ASSET}" -o "$tmp/$ASSET"
got="$(sha256sum "$tmp/$ASSET" | awk '{print $1}')"
if [ -n "${EXPECT:-}" ]; then
  want="$EXPECT"; src="caller (EXPECT)"
else
  curl -fsSL "${BASE}/${ASSET}.sha256" -o "$tmp/$ASSET.sha256"
  want="$(awk '{print $1}' "$tmp/$ASSET.sha256")"; src="release .sha256"
fi
[ "$got" = "$want" ] || die "sha256 mismatch — got $got, expected $want (from $src)"
log "integrity OK ($src): $got"

# The early-exit must NOT skip a requested model change: the chat-model block lives
# further down, so bailing out here on an unchanged asset would silently ignore
# AZURE_CHAT_API_KEY on every deploy after the first.
if [ -f "$STATE" ] && [ "$(cat "$STATE")" = "$got" ] && [ -z "${AZURE_CHAT_API_KEY:-}" ]; then
  log "no change — $got already deployed; exiting"
  exit 0
fi

mkdir -p "$tmp/x" && tar xzf "$tmp/$ASSET" -C "$tmp/x"
for d in backend public-api frontend; do
  [ -d "$tmp/x/$d" ] || die "tarball missing $d/ — wrong asset?"
done

# --- 3. back up the PILOT stack only (never production) ---
if [ -d "$APP" ] || [ -d "$WEB" ]; then
  ts="$(date '+%Y%m%d-%H%M%S')"
  b="/var/www/rag-pilot-backup-${ts}.tgz"
  tar czf "$b" -C /var/www \
      $( [ -d "$APP" ]     && echo "lehrprojekt-backend-rag" ) \
      $( [ -d "$API_PUB" ] && echo "html/rag-pilot-test-api" ) \
      $( [ -d "$WEB" ]     && echo "html/rag-pilot-test" ) 2>/dev/null || true
  log "pilot backup: $b"
  ls -1t /var/www/rag-pilot-backup-*.tgz 2>/dev/null | tail -n +4 | while read -r old; do
    rm -f "$old" && log "pruned $old"
  done
fi

# --- 4. apply, preserving the pilot .env and vendor across redeploys ---
mkdir -p "$APP" "$API_PUB" "$WEB"
[ -f "$APP/.env" ] && cp "$APP/.env" "$tmp/env.keep"

# refresh code but keep vendor/ (large, and identical to live)
find "$APP" -mindepth 1 -maxdepth 1 ! -name vendor ! -name .env -exec rm -rf {} + 2>/dev/null || true
cp -R "$tmp/x/backend"/. "$APP/"
rm -rf "$WEB"/* && cp -R "$tmp/x/frontend"/. "$WEB/"
cp -R "$tmp/x/public-api"/. "$API_PUB/"
[ -f "$tmp/env.keep" ] && cp "$tmp/env.keep" "$APP/.env"

# Discard any package-discovery manifest that travelled in the tarball. It is built
# against whatever vendor/ existed on the machine that packaged it — typically with
# dev dependencies — while this pod's vendor/ is --no-dev. Laravel rebuilds it on the
# next boot from the pod's own vendor/composer/installed.json.
rm -f "$APP/bootstrap/cache/"*.php
log "files applied (package manifest cleared for local rediscovery)"

# vendor/ is not in the tarball: composer.lock is unchanged from production, so
# the live tree is byte-identical and copying it avoids any packagist egress.
if [ ! -d "$APP/vendor" ]; then
  log "copying vendor/ from the live app (read-only on live)…"
  cp -R "$LIVE_APP/vendor" "$APP/vendor"
fi

# --- 5. pilot .env: derived from live, with the pilot overrides appended ---
if [ ! -f "$APP/.env" ]; then
  log "creating pilot .env from the live one"
  grep -vE '^(APP_URL|DB_PREFIX|RAG_|AZURE_EMBED_|TUTOR_)=' "$LIVE_APP/.env" > "$APP/.env"
  cat >> "$APP/.env" <<'ENV'

# ---------------- RAG pilot overrides ----------------
APP_URL=https://statsbot.univie.ac.at/rag-pilot-test-api

# Table-prefix isolation. Every table this stack touches is rag_-prefixed, so the
# live students/history tables are never read or written. Changing this to an
# empty value would point the pilot at live study data.
DB_PREFIX=rag_

# Start UNGROUNDED: prove the parallel stack stands up before adding retrieval.
# Flip to true (no redeploy needed — config is not cached) for the grounded arm.
RAG_ENABLED=false
RAG_INDEX_PATH=/var/www/lehrprojekt-backend-rag/storage/app/kb/kb-hyptest-3large-3072
RAG_TOP_K=3
RAG_MIN_SCORE=0.35

AZURE_EMBED_DEPLOYMENT=statsbot-embed-3-large
AZURE_EMBED_MODEL=text-embedding-3-large
AZURE_EMBED_API_VERSION=2024-10-21
AZURE_EMBED_DIMENSIONS=3072
AZURE_EMBED_TIMEOUT=10

TUTOR_STRUCTURED_OUTPUT=json_schema

# Small budget so a runaway test cannot spend much.
TOKEN_LIMIT=200000
ENV
  chmod 600 "$APP/.env"
fi

# --- 5b. chat model, and the embedding split it forces -----------------------
# Adds or replaces one key in the pilot .env. Values contain URLs and base64 keys,
# so this rewrites by filter-and-append rather than sed, which would choke on / and &.
set_env() {
  local k="$1" v="$2" t
  t="$(mktemp)"
  grep -vE "^${k}=" "$APP/.env" > "$t" 2>/dev/null || true
  printf '%s=%s\n' "$k" "$v" >> "$t"
  cat "$t" > "$APP/.env"
  rm -f "$t"
}

# text-embedding-3-large can ONLY be deployed on an Azure OpenAI resource, while an
# open-weight chat model can ONLY be deployed on a Foundry (AIServices) one. Pin the
# embeddings to the live resource BEFORE the chat keys are repointed, or retrieval
# starts calling Foundry for embeddings and every search silently returns [].
live_ep="$(grep -E '^AZURE_ENDPOINT=' "$LIVE_APP/.env" | head -1 | cut -d= -f2-)"
live_key="$(grep -E '^AZURE_API_KEY=' "$LIVE_APP/.env" | head -1 | cut -d= -f2-)"
[ -n "$live_ep" ]  && set_env AZURE_EMBED_ENDPOINT "$live_ep"
[ -n "$live_key" ] && set_env AZURE_EMBED_API_KEY  "$live_key"

if [ -n "${AZURE_CHAT_API_KEY:-}" ]; then
  chat_dep="${AZURE_CHAT_DEPLOYMENT:-mistral-small-2503}"
  set_env AZURE_ENDPOINT          "${AZURE_CHAT_ENDPOINT:-https://statsboteval-llm-infra.cognitiveservices.azure.com}"
  set_env AZURE_DEPLOYMENT        "$chat_dep"
  set_env AZURE_MODEL             "$chat_dep"
  set_env AZURE_API_VERSION       "${AZURE_CHAT_API_VERSION:-2024-10-21}"
  set_env AZURE_API_KEY           "$AZURE_CHAT_API_KEY"
  # Open-weight models are not reasoning models; a stray reasoning_effort is rejected.
  set_env AZURE_REASONING_EFFORT  ""
  # They also refuse response_format json_schema outright (HTTP 400), so plain JSON.
  set_env TUTOR_STRUCTURED_OUTPUT "${TUTOR_STRUCTURED_OUTPUT:-json_object}"
  # Without a schema, sampling variance alone breaks the JSON and the two layers
  # collapse into one: 9/16 valid at 0.7 versus 16/16 at 0.3 on mistral-small-2503.
  set_env AZURE_TEMPERATURE       "${AZURE_TEMPERATURE:-0.3}"
  log "chat model -> ${chat_dep} (open-weight, Foundry); embeddings stay on the OpenAI resource"
else
  log "AZURE_CHAT_API_KEY not set - leaving the pilot chat model unchanged"
  log "  If you meant to set it: a bare 'VAR=value' on its own line sets a SHELL"
  log "  variable, which a child process cannot see. Put it on the SAME line as the"
  log "  command, or export it. Paste as ONE line - the pod terminal mangles \\ breaks."
fi
chmod 600 "$APP/.env"

chmod -R ug+rwX "$APP" "$API_PUB" "$WEB"
chmod -R ug+rwX "$APP/storage" "$APP/bootstrap/cache"

# --- 6. THE guard, then migrate ---
grep -q '^DB_PREFIX=rag_' "$APP/.env" || die "pilot .env lacks DB_PREFIX=rag_ — refusing to migrate"

# bootstrap/app.php does NOT register the Composer autoloader — artisan requires
# vendor/autoload.php itself, one line before it requires the bootstrap file. Booting
# the framework by hand has to do the same, or this fatals and the empty output is
# indistinguishable from a genuinely unprefixed connection.
# Streams are kept apart so $effective is stdout only, but BOTH are shown when the
# probe fails: depending on display_errors, PHP writes fatals to either one.
if (cd "$APP" && php -r '
  require "vendor/autoload.php";
  $app = require "bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  echo config("database.connections.".config("database.default").".prefix");
' >"$tmp/prefix.out" 2>"$tmp/prefix.err"); then
  effective="$(cat "$tmp/prefix.out")"
else
  log "prefix probe crashed:"
  cat "$tmp/prefix.out" "$tmp/prefix.err" | sed 's/^/    /' | tee -a "$LOG"
  die "could not resolve the effective table prefix — refusing to migrate"
fi
[ "$effective" = "rag_" ] || die "effective table prefix is \"${effective}\", expected rag_ — refusing to migrate"
log "table prefix verified: ${effective}"

(cd "$APP" && php artisan migrate --force --no-interaction) 2>&1 | tee -a "$LOG"

# --- 7. show the isolation, do not merely claim it ---
after="$(livecount)"
log "LIVE tables after:  $after"
[ "$before" = "$after" ] && log "ISOLATION OK — live row counts unchanged" \
                         || log "WARNING: live row counts CHANGED ($before -> $after) — investigate"

if (cd "$APP" && php -r '
  require "vendor/autoload.php";
  $app = require "bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  try { printf("rag_history=%d rag_students=%d",
        Illuminate\Support\Facades\DB::table("history")->count(),
        Illuminate\Support\Facades\DB::table("students")->count()); }
  catch (Exception $e) { echo "unavailable: ".$e->getMessage(); }
' >"$tmp/rag.out" 2>"$tmp/rag.err"); then
  ragcount="$(cat "$tmp/rag.out")"
else
  ragcount="probe failed: $(cat "$tmp/rag.out" "$tmp/rag.err" | tr '\n' ' ' | cut -c1-300)"
fi
log "PILOT tables: $ragcount"

echo "$got" > "$STATE"
log "=== deploy done — RAG_ENABLED=$(grep -oP '(?<=^RAG_ENABLED=).*' "$APP/.env" || echo '?') \
  model=$(grep -oP '(?<=^AZURE_DEPLOYMENT=).*' "$APP/.env" || echo '?') \
  structured=$(grep -oP '(?<=^TUTOR_STRUCTURED_OUTPUT=).*' "$APP/.env" || echo '?') ==="
cat <<EOF

Open:  https://statsbot.univie.ac.at/rag-pilot-test/
Live:  https://statsbot.univie.ac.at/            (unchanged)

Toggle retrieval without redeploying (config is not cached):
  sed -i 's/^RAG_ENABLED=.*/RAG_ENABLED=true/'  $APP/.env
  sed -i 's/^RAG_ENABLED=.*/RAG_ENABLED=false/' $APP/.env
EOF
