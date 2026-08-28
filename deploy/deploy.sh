#!/usr/bin/env bash
#
# One-command update for the cPanel deploy (no Node, no SSR).
#
# The important, easy-to-forget step is the last one: RE-EXPOSE the app's
# public/ folder into the web root. On the symlink layout (Option B in
# DEPLOY.md) every file in public/ is served through an individual symlink in
# public_html/. A `git pull` that adds a NEW public file (a logo, an image, a
# build chunk) does NOT create its symlink, so it 404s until this loop re-runs.
#
# Usage (from anywhere):
#   bash ~/droprsvp/deploy/deploy.sh
# Override paths if your layout differs:
#   APP_DIR=~/apps/droprsvp WEB_ROOT=~/public_html bash .../deploy.sh
#
# Option A (document root points straight at the app's public/) doesn't need the
# symlink loop — it's harmless there (WEB_ROOT just won't exist / is skipped).

set -euo pipefail

APP_DIR="${APP_DIR:-$HOME/droprsvp}"        # the git clone (app code + .env)
WEB_ROOT="${WEB_ROOT:-$HOME/public_html}"   # the web root (bridge index.php lives here)

echo "==> App:  $APP_DIR"
echo "==> Web:  $WEB_ROOT"

cd "$APP_DIR"

echo "==> git pull"
git pull --ff-only

echo "==> composer install"
composer install --no-dev --optimize-autoloader

echo "==> migrate"
php artisan migrate --force

echo "==> storage:link"
php artisan storage:link 2>/dev/null || true

echo "==> caches"
php artisan config:cache
php artisan route:cache

# --- Re-expose public assets (Option B / symlink layout) --------------------
# Idempotent: `ln -sfn` refreshes existing links and creates any new ones.
if [ -d "$WEB_ROOT" ] && [ "$WEB_ROOT" != "$APP_DIR/public" ]; then
  echo "==> Re-exposing public/ into $WEB_ROOT"
  cd "$WEB_ROOT"
  for f in "$APP_DIR"/public/*; do
    n=$(basename "$f")
    [ "$n" = "index.php" ] && continue   # keep the bridge front controller
    ln -sfn "$f" "$n"
  done
  echo "==> Linked assets:"
  ls -la "$WEB_ROOT" | grep -E 'logo|og-default|favicon|apple-touch|build|storage|vector' || true
else
  echo "==> Skipping symlink loop (Option A: document root is the app's public/)."
fi

echo "==> Done."
