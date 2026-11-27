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
# THE COURSE-MATERIAL INDEX ARRIVES ENCRYPTED. The release is public and the index
# holds the lecturers' slides, exams and solutions verbatim, so publish-rag-pilot.sh
# seals it (AES-256-GCM, tools/kb/seal.py) and this script opens it with the key in
# the pilot .env (CORPUS_KEY). Paste the key ONCE, without it touching the screen or
# the shell history:
#
#     read -rsp 'CORPUS_KEY: ' k && echo && printf 'CORPUS_KEY=%s\n' "$k" >> /var/www/lehrprojekt-backend-rag/.env && unset k
#
# Decryption and its checks run BEFORE anything is applied: a missing or wrong key
# aborts the deploy and the running stack keeps its current index.
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

if [ -f "$STATE" ] && [ "$(cat "$STATE")" = "$got" ]; then
  log "no change — $got already deployed; exiting"
  exit 0
fi

mkdir -p "$tmp/x" && tar xzf "$tmp/$ASSET" -C "$tmp/x"
for d in backend public-api frontend; do
  [ -d "$tmp/x/$d" ] || die "tarball missing $d/ — wrong asset?"
done

# --- 2b. open the sealed index, BEFORE anything is applied ---
# A failure here leaves the running stack (and its index) exactly as it was.
KB_DIR="$tmp/x/backend/storage/app/kb"
if ls "$KB_DIR"/*.enc >/dev/null 2>&1; then
  corpus_key="${CORPUS_KEY:-}"
  if [ -z "$corpus_key" ] && [ -f "$APP/.env" ]; then
    corpus_key="$(sed -n 's/^CORPUS_KEY=//p' "$APP/.env" | tail -n1)"
  fi
  [ -n "$corpus_key" ] || die "this release carries an encrypted index but no CORPUS_KEY is set. Paste it once:
    read -rsp 'CORPUS_KEY: ' k && echo && printf 'CORPUS_KEY=%s\n' \"\$k\" >> $APP/.env && unset k
then re-run this deploy."

  want_model="$( { sed -n 's/^AZURE_EMBED_MODEL=//p' "$APP/.env" 2>/dev/null || true; } | tail -n1)"
  want_dims="$( { sed -n 's/^AZURE_EMBED_DIMENSIONS=//p' "$APP/.env" 2>/dev/null || true; } | tail -n1)"

  cat > "$tmp/unseal.php" <<'PHP'
<?php
// Opens the index sealed by tools/kb/seal.py: b"SBKB1" | 12-byte nonce | ciphertext |
// 16-byte tag, AES-256-GCM, with the plaintext file NAME as additional data.
// The key comes from the environment, never from argv (argv shows up in `ps`).
// argv: dir, expected model, expected dims.
ini_set('memory_limit', '1G');
function fail(string $m, int $code) { fwrite(STDERR, $m."\n"); exit($code); }

$key = base64_decode((string) getenv('CORPUS_KEY'), true);
if ($key === false || strlen($key) !== 32) fail('CORPUS_KEY is not 32 base64-encoded bytes', 2);
[$dir, $model, $dims] = [$argv[1], $argv[2] ?: 'text-embedding-3-large', (int) ($argv[3] ?: 3072)];

foreach (glob("$dir/*.enc") as $f) {
    $name = basename($f, '.enc');
    $raw = file_get_contents($f);
    if (substr($raw, 0, 5) !== 'SBKB1' || strlen($raw) < 33) fail("$name: not a sealed index file", 3);
    $plain = openssl_decrypt(substr($raw, 17, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        substr($raw, 5, 12), substr($raw, -16), $name);
    if ($plain === false) fail("$name: decryption REFUSED - wrong CORPUS_KEY, or the file was altered or renamed", 4);
    file_put_contents("$dir/$name", $plain);
    unlink($f);
}

$pairs = glob("$dir/*.json");
if (! $pairs) fail('no index sidecar (.json) after decryption', 5);
foreach ($pairs as $j) {
    $base = substr($j, 0, -5);
    $meta = json_decode(file_get_contents($j), true);
    if (! is_array($meta)) fail(basename($j).': sidecar is not valid JSON', 5);
    if (($meta['model'] ?? '') !== $model) fail(basename($j).": built with {$meta['model']}, the pilot queries with $model", 6);
    if ((int) $meta['dims'] !== $dims) fail(basename($j).": {$meta['dims']} dims, the pilot queries with $dims", 6);
    if (count($meta['chunks'] ?? []) !== (int) $meta['count']) fail(basename($j).': chunk list and count disagree', 6);
    if (! is_file("$base.f32") || filesize("$base.f32") !== (int) $meta['count'] * (int) $meta['dims'] * 4) {
        fail(basename($base).'.f32: missing or the wrong size for '.$meta['count'].' x '.$meta['dims'], 6);
    }
    printf("%s: %s@%s, %d chunks x %d dims, sidecar sha256 %s\n", basename($base),
        $meta['doc_id'] ?? '?', $meta['built_at'] ?? '?', $meta['count'], $meta['dims'],
        substr(hash_file('sha256', $j), 0, 12));
}
PHP

  if unsealed="$(CORPUS_KEY="$corpus_key" php "$tmp/unseal.php" "$KB_DIR" "$want_model" "$want_dims" 2>&1)"; then
    log "index decrypted and checked: $unsealed"
  else
    die "index NOT installed — $unsealed"
  fi
  unset corpus_key
else
  log "NOTE: this release carries no index; the RAG arms will answer ungrounded until one is published"
fi

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
  grep -vE '^(APP_URL|DB_PREFIX|RAG_|AZURE_EMBED_|TUTOR_|STUDY_)=' "$LIVE_APP/.env" > "$APP/.env"
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
RAG_INDEX_PATH=/var/www/lehrprojekt-backend-rag/storage/app/kb/kb-corpus
RAG_TOP_K=3
RAG_MIN_SCORE=0.35

AZURE_EMBED_DEPLOYMENT=statsbot-embed-3-large
AZURE_EMBED_MODEL=text-embedding-3-large
AZURE_EMBED_API_VERSION=2024-10-21
AZURE_EMBED_DIMENSIONS=3072
AZURE_EMBED_TIMEOUT=10

TUTOR_STRUCTURED_OUTPUT=true
# Salient arm: at most this many citation chips per answer (preregistered: 3).
TUTOR_MAX_CITATIONS=3

# Small budget so a runaway test cannot spend much.
TOKEN_LIMIT=200000

# Three-arm trial. 'test' enables allocation against the test sequence and
# unlocks the reset endpoint; 'off' disables allocation entirely.
STUDY_PHASE=test
STUDY_ADMIN_UIDS=lakhia92,abhag93
# Lets /rag-pilot-test/<no-rag|non-salient|salient>/ pin the arm per message.
# Test stack only: the backend refuses it unless STUDY_PHASE is exactly 'test'.
STUDY_ARM_OVERRIDE=true
ENV
  chmod 600 "$APP/.env"
fi

# The template above is only written on a FIRST install; a redeploy keeps the old
# .env. Add the keys this release relies on when they are missing, so the per-arm
# test URLs do not fail as an unexplained 403. Never overwrite a value someone set
# on purpose: a deliberate RAG_ENABLED=false is reported below, not flipped.
ensure_env() {
  grep -q "^$1=" "$APP/.env" && return 0
  [ -z "$(tail -c1 "$APP/.env")" ] || echo >> "$APP/.env"
  echo "$1=$2" >> "$APP/.env"
  log ".env: added $1=$2"
}
ensure_env STUDY_ARM_OVERRIDE true
ensure_env TUTOR_MAX_CITATIONS 3

# A key handed in on the command line (first install) is kept for the next deploy.
# Not via ensure_env: that logs the value.
if [ -n "${CORPUS_KEY:-}" ] && ! grep -q '^CORPUS_KEY=' "$APP/.env"; then
  [ -z "$(tail -c1 "$APP/.env")" ] || echo >> "$APP/.env"
  printf 'CORPUS_KEY=%s\n' "$CORPUS_KEY" >> "$APP/.env"
  log ".env: added CORPUS_KEY (value not logged)"
fi

# The pilot started on the hyptest index; the corpus now installs under a stable
# name. Move the path only off that exact old default - any other value was set
# on purpose and is left alone.
OLD_INDEX="$APP/storage/app/kb/kb-hyptest-3large-3072"
NEW_INDEX="$APP/storage/app/kb/kb-corpus"
cur_index="$(sed -n 's/^RAG_INDEX_PATH=//p' "$APP/.env" | tail -n1)"
if [ "$cur_index" = "$OLD_INDEX" ] && [ -f "$NEW_INDEX.json" ]; then
  sed -i "s#^RAG_INDEX_PATH=.*#RAG_INDEX_PATH=$NEW_INDEX#" "$APP/.env"
  log ".env: RAG_INDEX_PATH moved from the hyptest pilot index to kb-corpus"
fi

rag_enabled="$(sed -n 's/^RAG_ENABLED=//p' "$APP/.env" | tail -n1)"
[ "$rag_enabled" = "true" ] \
  || log "WARNING: RAG_ENABLED=${rag_enabled:-<unset>} — both RAG arms answer UNGROUNDED until it is true"
study_phase="$(sed -n 's/^STUDY_PHASE=//p' "$APP/.env" | tail -n1)"
[ "$study_phase" = "test" ] \
  || log "WARNING: STUDY_PHASE=${study_phase:-<unset>} — the per-arm test URLs need exactly 'test' (else 403)"

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

# KbRetriever fails soft on purpose, so a broken index would otherwise only show
# as both RAG arms quietly answering like no-RAG. Say it here, loudly.
if kbstatus="$(cd "$APP" && php artisan kb:status 2>&1)"; then
  log "kb:status OK"
else
  log "WARNING: kb:status FAILED — the RAG arms are answering UNGROUNDED"
fi
echo "$kbstatus" | sed 's/^/    /' | tee -a "$LOG"

echo "$got" > "$STATE"
log "=== deploy done — RAG_ENABLED=$(grep -oP '(?<=^RAG_ENABLED=).*' "$APP/.env" || echo '?') ==="
cat <<EOF

Open:  https://statsbot.univie.ac.at/rag-pilot-test/
Live:  https://statsbot.univie.ac.at/            (unchanged)

Re-seed the three-arm test sequence (releases every old allocation; --with-unknown
lets teammates who are not on the roster past the disclaimer):
  cd $APP && php artisan study:seed-test --force --with-unknown

Per-arm test URLs:
  https://statsbot.univie.ac.at/rag-pilot-test/no-rag/
  https://statsbot.univie.ac.at/rag-pilot-test/non-salient/
  https://statsbot.univie.ac.at/rag-pilot-test/salient/

Toggle retrieval without redeploying (config is not cached):
  sed -i 's/^RAG_ENABLED=.*/RAG_ENABLED=true/'  $APP/.env
  sed -i 's/^RAG_ENABLED=.*/RAG_ENABLED=false/' $APP/.env
EOF
