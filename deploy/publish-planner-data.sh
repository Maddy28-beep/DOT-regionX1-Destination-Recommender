#!/usr/bin/env bash
# Invoked from the reviewed, checksummed data-only bundle; no git pull or seeders.
set -euo pipefail
umask 077
bundle="$(cd -- "$(dirname -- "$0")" && pwd)"
app=/var/www/exploredvo
stamp="$(date -u +%Y%m%d-%H%M%S)"
backup="/var/backups/exploredvo/planner-data-$stamp"
cd "$bundle"
sha256sum --check SHA256SUMS
exec 9>/var/lock/exploredvo-planner-data.lock
flock -n 9 || { echo 'Another planner data publication is running.' >&2; exit 1; }
test -f "$app/artisan"
cd "$app"
sudo -u www-data php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $c=Illuminate\Support\Facades\DB::connection(); if ($c->getDriverName()!=="pgsql" || $c->getDatabaseName()!=="exploredvo") {fwrite(STDERR,"Unexpected production database; aborting.\n"); exit(1);} echo "Target confirmed: PostgreSQL exploredvo\n";'
mkdir -p "$backup"
sudo -u postgres pg_dump --format=custom exploredvo > "$backup/database.dump"
test -s "$backup/database.dump"
pg_restore --list "$backup/database.dump" > "$backup/database-contents.txt"
files=(
  app/Services/PlannerDataPatch.php
  app/Console/Commands/PublishPlannerData.php
  database/data/live-planner-patch-2026-11-01.json
  database/migrations/2026_10_31_200000_add_planner_verification_to_destinations.php
  database/migrations/2026_10_31_210000_add_verified_meal_venues.php
  database/migrations/2026_11_01_180000_expand_itinerary_item_notes.php
)
# Never silently overwrite a newer deployment's version of these files.
for file in "${files[@]}"; do
  if [[ -e "$app/$file" ]] && ! cmp -s "$bundle/$file" "$app/$file"; then
    echo "Existing file differs; review before publishing: $file" >&2; exit 1
  fi
done
for file in "${files[@]}"; do
  install -D -m 0644 "$bundle/$file" "$app/$file"
done
manifest=database/data/live-planner-patch-2026-11-01.json
# Preview checks the expected old field values against the current live DB.
sudo -u www-data php artisan planner:publish-data "$manifest" --no-ansi | tee "$backup/preview-before-migrations.json"
sudo -u www-data php artisan migrate --force \
  --path=database/migrations/2026_10_31_200000_add_planner_verification_to_destinations.php \
  --path=database/migrations/2026_10_31_210000_add_verified_meal_venues.php \
  --path=database/migrations/2026_11_01_180000_expand_itinerary_item_notes.php
sudo -u www-data php artisan planner:publish-data "$manifest" --no-ansi | tee "$backup/preview.json"
sudo -u www-data php artisan planner:publish-data "$manifest" --apply --no-ansi | tee "$backup/applied.json"
sudo -u www-data php artisan planner:publish-data "$manifest" --no-ansi | tee "$backup/after.json"
php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if ($r["inserted"] || $r["updated"] || $r["tags_added"]) {fwrite(STDERR,"Post-publication comparison failed\n");exit(1);}' "$backup/after.json"
curl --fail --silent --show-error --output /dev/null https://www.exploredavao.com/plan/start
echo "Planner data published and verified. Backup and reports: $backup"
echo 'New public-venue eligibility and audit behavior still depend on the matching planner code release.'
