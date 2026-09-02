#!/usr/bin/env bash
#
# rag-pilot-teardown.sh — runs INSIDE the pod. Removes the RAG pilot stack.
#
#   bash /var/www/rag-pilot-teardown.sh              # files only (default)
#   DROP_TABLES=yes bash /var/www/rag-pilot-teardown.sh   # also drop rag_* tables
#
# Touches only the three pilot paths and, when asked, only tables whose name
# starts with rag_. It refuses to drop anything unprefixed, so the live
# students/history tables cannot be caught by it even by accident.
set -euo pipefail

APP="/var/www/lehrprojekt-backend-rag"
API_PUB="/var/www/html/rag-pilot-test-api"
WEB="/var/www/html/rag-pilot-test"
STATE="/var/www/.rag-pilot-sha"
LOG="/var/www/rag-pilot-deploy.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] teardown: $*" | tee -a "$LOG"; }

if [ "${DROP_TABLES:-no}" = "yes" ]; then
  [ -f "$APP/.env" ] || { echo "no pilot .env; cannot reach the database" >&2; exit 1; }
  grep -q '^DB_PREFIX=rag_' "$APP/.env" || { echo "ABORT: pilot .env is not rag_-prefixed" >&2; exit 1; }
  log "dropping rag_* tables"
  cat > /tmp/rag-drop.php <<'PHP'
<?php
// Drops ONLY tables whose name begins with rag_. argv[1] is the pilot .env.
$env = [];
foreach (file($argv[1]) as $l) {
    if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $l, $m)) {
        $env[$m[1]] = trim(trim($m[2]), "\"'");
    }
}
$p = new PDO("mysql:host={$env['DB_HOST']};dbname={$env['DB_DATABASE']}",
             $env['DB_USERNAME'], $env['DB_PASSWORD'],
             [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$p->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    if (strpos($t, 'rag_') !== 0) {   // belt and braces
        continue;
    }
    $p->exec("DROP TABLE `$t`");
    echo "dropped $t\n";
}
$p->exec('SET FOREIGN_KEY_CHECKS=1');
printf("live history=%d students=%d (untouched)\n",
    $p->query('SELECT COUNT(*) FROM history')->fetchColumn(),
    $p->query('SELECT COUNT(*) FROM students')->fetchColumn());
PHP
  php /tmp/rag-drop.php "$APP/.env"
  rm -f /tmp/rag-drop.php
fi

for d in "$APP" "$API_PUB" "$WEB"; do
  case "$d" in
    /var/www/html|/var/www/lehrprojekt-backend|/var/www|/) echo "refusing to remove $d" >&2; exit 1 ;;
  esac
  [ -d "$d" ] && rm -rf "$d" && log "removed $d"
done
rm -f "$STATE"

log "done. Live stack untouched: https://statsbot.univie.ac.at/"
