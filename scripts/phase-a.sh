#!/bin/sh
# Phase A: discover live-vs-repo drift in the pod.
# Usage: curl -sL https://raw.githubusercontent.com/lakhi/statsbot/main/scripts/phase-a.sh | sh

LIVE=/var/www/lehrprojekt-backend
REPO=/tmp/statsbot-repo/psy-lehrprojekt-backend-main

echo "=== cloning repo ==="
cd /tmp
rm -rf statsbot-repo
git clone --depth 1 -q https://github.com/lakhi/statsbot statsbot-repo
echo "cloned: $(git -C statsbot-repo rev-parse --short HEAD)"

echo "===== STRUCTURAL DIFF (blank = identical) ====="
find "$LIVE" -type f \
  -not -path "*/vendor/*" \
  -not -path "*/node_modules/*" \
  -not -path "*/storage/*" \
  -not -path "*/cache/*" \
  -not -path "*/.git/*" \
  -not -name ".env" \
  | sed "s|$LIVE/||" | sort > /tmp/live-files.txt
find "$REPO" -type f \
  -not -path "*/vendor/*" \
  -not -path "*/node_modules/*" \
  -not -path "*/storage/*" \
  -not -path "*/cache/*" \
  -not -path "*/.git/*" \
  -not -name ".env" \
  | sed "s|$REPO/||" | sort > /tmp/repo-files.txt
echo "--- in LIVE but not REPO:"
comm -23 /tmp/live-files.txt /tmp/repo-files.txt
echo "--- in REPO but not LIVE:"
comm -13 /tmp/live-files.txt /tmp/repo-files.txt
echo "===== /STRUCTURAL DIFF ====="

grep -ohE '^[A-Za-z_][A-Za-z0-9_]*=' "$LIVE/.env" 2>/dev/null \
  | sed 's/=$//' | sort -u > /tmp/live-env-keys.txt
grep -ohE '^[A-Za-z_][A-Za-z0-9_]*=' "$REPO/.env.example" 2>/dev/null \
  | sed 's/=$//' | sort -u > /tmp/repo-env-keys.txt
echo "===== ENV KEYS in live .env but NOT in repo .env.example ====="
comm -23 /tmp/live-env-keys.txt /tmp/repo-env-keys.txt
echo "===== ENV KEYS in repo .env.example but NOT in live .env ====="
comm -13 /tmp/live-env-keys.txt /tmp/repo-env-keys.txt
echo "===== done ====="
