#!/usr/bin/env bash
#
# One-command update for the cPanel deploy (no Node, no SSR).
#
# Resilient by design: individual steps warn instead of aborting, so the most
# important step — RE-EXPOSING the app's public/ folder into the web root — always
# runs. On the symlink layout (Option B in DEPLOY.md) every file in public/ is
# served through an individual symlink in public_html/; a `git pull` that adds a
# NEW public file (a logo, image, build chunk) does NOT create its symlink, so it
# 404s until this loop re-runs.
#
# Usage (from anywhere):
#   bash ~/droprsvp/deploy/deploy.sh
# Override paths if your layout differs:
#   APP_DIR=~/apps/droprsvp WEB_ROOT=~/public_html bash .../deploy.sh

set -uo pipefail   # NB: no -e — we handle failures per-step so the deploy finishes.

APP_DIR="${APP_DIR:-$HOME/droprsvp}"        # the git clone (app code + .env)
WEB_ROOT="${WEB_ROOT:-$HOME/public_html}"   # the web root (bridge index.php lives here)

# Resolve PHP (some hosts only expose a versioned binary).
PHP="php"
command -v "$PHP" >/dev/null 2>&1 || PHP="$(command -v php8.3 || command -v php8.2 || command -v php || true)"
[ -z "$PHP" ] && { echo "!! php not found on PATH — aborting."; exit 1; }

# Resolve composer: real binary, a local composer.phar, or skip.
resolve_composer() {
  if command -v composer >/dev/null 2>&1; then echo "composer"; return; fi
  for phar in "$APP_DIR/composer.phar" "$HOME/composer.phar" "./composer.phar"; do
    [ -f "$phar" ] && { echo "$PHP $phar"; return; }
  done
  echo ""
}

echo "==> App:  $APP_DIR"
echo "==> Web:  $WEB_ROOT"
echo "==> PHP:  $PHP"

cd "$APP_DIR" || { echo "!! cannot cd to $APP_DIR"; exit 1; }

echo "==> git pull"
git pull --ff-only || echo "!! git pull failed (continuing)"

COMPOSER="$(resolve_composer)"
if [ -n "$COMPOSER" ]; then
  echo "==> composer install ($COMPOSER)"
  $COMPOSER install --no-dev --optimize-autoloader || echo "!! composer install failed (continuing)"
else
  echo "!! composer not found (no 'composer' on PATH and no composer.phar) — skipping install."
  echo "   If code dependencies changed, install them manually, e.g.:"
  echo "     curl -sS https://getcomposer.org/installer | $PHP -- --install-dir=$HOME && $PHP $HOME/composer.phar install --no-dev -o"
fi

echo "==> migrate"
$PHP artisan migrate --force || echo "!! migrate failed (continuing)"

echo "==> storage:link"
$PHP artisan storage:link >/dev/null 2>&1 || true

echo "==> caches"
$PHP artisan config:cache || echo "!! config:cache failed (continuing)"
$PHP artisan route:cache || echo "!! route:cache failed (continuing)"

# --- Re-expose public assets (Option B / symlink layout) --------------------
# Idempotent: `ln -sfn` refreshes existing links and creates any new ones.
if [ -d "$WEB_ROOT" ] && [ "$WEB_ROOT" != "$APP_DIR/public" ]; then
  echo "==> Re-exposing public/ into $WEB_ROOT"
  cd "$WEB_ROOT" || { echo "!! cannot cd to $WEB_ROOT"; exit 1; }
  for f in "$APP_DIR"/public/*; do
    n=$(basename "$f")
    [ "$n" = "index.php" ] && continue   # keep the bridge front controller
    ln -sfn "$f" "$n"
  done
  # The glob above skips dotfiles, so .htaccess is never symlinked. Copy it so the
  # canonical-host + trailing-slash rules stay in sync with the repo on every deploy.
  cp -f "$APP_DIR/public/.htaccess" "$WEB_ROOT/.htaccess" && echo "==> Synced .htaccess"
  echo "==> Linked assets:"
  ls -la "$WEB_ROOT" | grep -E 'logo|og-default|favicon|apple-touch|build|storage|vector' || true
else
  echo "==> Skipping symlink loop (Option A: document root is the app's public/)."
fi

echo "==> Done."
