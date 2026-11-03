#!/usr/bin/env bash
# ExploreDVO -- server-side update. Called by deploy/deploy.ps1; you normally never run this by hand.
#   Usage: bash server-update.sh /root/exploredvo-<sha>.tar.gz <sha> [--refresh-embeddings]
# A normal deploy changes code only: the stored embedding vectors (destination_embeddings, interest_embeddings) are
# left exactly as they are. Pass --refresh-embeddings (deploy.ps1 -RefreshEmbeddings) to load the vector file that
# ships with the package; that is the only thing that runs `embeddings:import`.
# Stops at the first error (set -e), and does NOT hide errors.
set -Eeuo pipefail

PKG="${1:?package path missing}"
SHA="${2:-unknown}"
REFRESH_EMBEDDINGS=0
case "${3:-}" in
  "") ;;
  --refresh-embeddings) REFRESH_EMBEDDINGS=1 ;;
  *) echo "!! Unknown option '${3}'. The only option is --refresh-embeddings. Nothing was changed." >&2; exit 64 ;;
esac
VECTORS=database/data/destination-embeddings.json
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
# Remember whether this package ships a different vector file than the server had (only used for a reminder below).
OLD_VECTORS=$(md5sum "$APP/$VECTORS" 2>/dev/null | cut -d' ' -f1 || true)
NEW_VECTORS=$(md5sum "$STAGE/$VECTORS" 2>/dev/null | cut -d' ' -f1 || true)
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

# Similar-place swaps: the stored embedding vectors (a shipped JSON file) are loaded only on request.
# Needs no Ollama or model on the server. Skipped on older code that predates the feature.
if [ "$REFRESH_EMBEDDINGS" = "1" ]; then
  if as_www php artisan list --raw | grep -q '^embeddings:import'; then
    echo "      loading similar-place vectors (--refresh-embeddings)"
    as_www php artisan embeddings:import
  fi
else
  echo "      embeddings: left as they are (code-only deploy)"
  if [ -n "$NEW_VECTORS" ] && [ "$OLD_VECTORS" != "$NEW_VECTORS" ]; then
    echo "      NOTE: this package ships a different vector file than the server had, and it was NOT loaded."
    echo "            If the vectors are meant to change, deploy again with -RefreshEmbeddings."
  fi
fi

echo "[7/8] Rebuilding caches and reloading PHP"
as_www php artisan config:cache
as_www php artisan route:cache
as_www php artisan view:cache
as_www php artisan cache:clear
systemctl reload php8.3-fpm

# Laravel's scheduler (daily accreditation-expiry sync, so expired places stop being recommended).
# One line in /etc/cron.d; rewritten every deploy so it can never go missing.
cat > /etc/cron.d/exploredvo-schedule <<'CRON'
* * * * * www-data cd /var/www/exploredvo && php artisan schedule:run >> /dev/null 2>&1
CRON
chmod 644 /etc/cron.d/exploredvo-schedule

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
