#!/usr/bin/env bash
# ExploreDVO -- server-side update. Called by deploy/deploy.ps1; you normally never run this by hand.
#   Usage: bash server-update.sh /root/exploredvo-<sha>.tar.gz <sha>
# Stops at the first error (set -e), and does NOT hide errors.
set -Eeuo pipefail

PKG="${1:?package path missing}"
SHA="${2:-unknown}"
APP=/var/www/exploredvo
BACKUPS=/var/backups/exploredvo
STAGE=$(mktemp -d /root/exploredvo-stage.XXXXXX)
export COMPOSER_ALLOW_SUPERUSER=1

trap 'echo "!! FAILED on line $LINENO. The site may be half-updated. A backup is in $BACKUPS."; rm -rf "$STAGE"' ERR

as_www() { sudo -u www-data env HOME=/tmp "$@"; }

echo "[1/8] Database backup"
mkdir -p "$BACKUPS"
TS=$(date +%Y%m%d-%H%M%S)
sudo -u postgres pg_dump exploredvo | gzip > "$BACKUPS/pre-deploy-$TS-$SHA.sql.gz"
ls -1t "$BACKUPS"/pre-deploy-*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm -f   # keep the latest 10
echo "      saved $BACKUPS/pre-deploy-$TS-$SHA.sql.gz"

echo "[2/8] Unpacking $SHA"
tar -xzf "$PKG" -C "$STAGE"

echo "[3/8] Copying code (keeps .env, uploads, storage, vendor, the public/storage link)"
rsync -a --delete \
  --exclude='.env' --exclude='storage/' --exclude='vendor/' \
  --exclude='bootstrap/cache/' --exclude='public/storage' \
  "$STAGE"/ "$APP"/
# make sure runtime folders exist even on a first run
mkdir -p "$APP"/storage/{app/public,framework/{cache,sessions,views},logs} "$APP"/bootstrap/cache

echo "[4/8] PHP packages"
cd "$APP"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "[5/8] Permissions"
chown -R www-data:www-data "$APP"
chmod -R ug+rwX "$APP/storage" "$APP/bootstrap/cache"
[ -L "$APP/public/storage" ] || as_www php artisan storage:link

echo "[6/8] Database migrations (only adds new tables/columns)"
as_www php artisan migrate --force

# Similar-place swaps: load the stored embedding vectors (a shipped JSON file).
# Needs no Ollama or model on the server. Skipped on older code that predates the feature.
if as_www php artisan list --raw | grep -q '^embeddings:import'; then
  echo "      loading similar-place vectors"
  as_www php artisan embeddings:import
fi

echo "[7/8] Rebuilding caches and reloading PHP"
as_www php artisan config:cache
as_www php artisan route:cache
as_www php artisan view:cache
as_www php artisan cache:clear
systemctl reload php8.3-fpm

echo "[8/8] Checking the site"
BAD=0
for p in / /destinations /accommodations /plan/start /advisories /portal/login; do
  code=$(curl -s -o /dev/null -m 25 -w '%{http_code}' "http://127.0.0.1$p")
  printf '      %-16s %s\n' "$p" "$code"
  [ "$code" = "200" ] || BAD=1
done
rm -rf "$STAGE" "$PKG"
trap - ERR
if [ "$BAD" = "1" ]; then
  echo "!! One or more pages did not return 200. Check: tail -50 $APP/storage/logs/laravel.log"
  exit 2
fi
echo "OK -- $SHA is live."
