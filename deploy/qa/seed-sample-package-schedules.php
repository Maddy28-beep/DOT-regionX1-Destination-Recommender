<?php

/*
 * One-off: gives two SAMPLE tour packages a day-by-day schedule so "Plan with this Package" can be shown at the trial.
 * NOT part of the deploy; copy it to the server, run it once, delete it.
 *
 *   php seed-sample-package-schedules.php            dry run: says what it would do, changes nothing
 *   php seed-sample-package-schedules.php --apply    inserts the rows (one transaction)
 *   php seed-sample-package-schedules.php --revert   removes exactly the rows this script inserted ("[SAMPLE]" descriptions)
 *
 * Touches only table package_itinerary_days, only for the two slugs below, and refuses to run on a package that already has
 * a schedule it did not write. Run from the app folder: cd /var/www/exploredvo && php /tmp/seed-sample-package-schedules.php --apply
 */

$mode = in_array('--apply', $argv, true) ? 'apply' : (in_array('--revert', $argv, true) ? 'revert' : 'dry');
chdir(getcwd());
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const SAMPLE_PREFIX = '[SAMPLE] ';

$schedules = [
    'samal-island-hopping-day-tour' => [
        1 => ['Island hopping day', SAMPLE_PREFIX.'Roundtrip ferry transfer to the Island Garden City of Samal, island hopping by boat, buffet lunch and snorkelling with a guide. Times and stops are illustrative, not a confirmed operator schedule.'],
    ],
    'davao-city-cultural-heritage-tour' => [
        1 => ['Davao City heritage half day', SAMPLE_PREFIX."Guided half-day visit to People's Park and a museum, with air-conditioned van transfers. Times and stops are illustrative, not a confirmed operator schedule."],
    ],
];

echo 'database: ', DB::connection()->getDatabaseName(), "  mode: $mode\n";
DB::beginTransaction();
try {
    foreach ($schedules as $slug => $days) {
        $package = DB::table('packages')->where('slug', $slug)->first();
        if (! $package) {
            throw new RuntimeException("package $slug not found");
        }
        $existing = DB::table('package_itinerary_days')->where('package_id', $package->id)->get();
        $foreign = $existing->filter(fn ($r) => ! str_starts_with((string) $r->description, SAMPLE_PREFIX));
        if ($foreign->isNotEmpty()) {
            throw new RuntimeException("package $slug already has a schedule written by someone else; refusing to touch it");
        }
        if ($mode === 'revert') {
            $n = DB::table('package_itinerary_days')->where('package_id', $package->id)->where('description', 'like', SAMPLE_PREFIX.'%')->delete();
            echo "$slug (#{$package->id}): removed $n sample day row(s)\n";
            continue;
        }
        if ($existing->isNotEmpty()) {
            echo "$slug (#{$package->id}): already seeded (".$existing->count()." row(s)); nothing to do\n";
            continue;
        }
        foreach ($days as $n => [$title, $description]) {
            echo "$slug (#{$package->id}): day $n \"$title\"\n";
            if ($mode === 'apply') {
                DB::table('package_itinerary_days')->insert(['package_id' => $package->id, 'day_number' => $n, 'title' => $title, 'description' => $description]);
            }
        }
    }
    $mode === 'dry' ? DB::rollBack() : DB::commit();
} catch (Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, 'FAILED, nothing changed: '.$e->getMessage()."\n");
    exit(1);
}
echo 'package_itinerary_days now has ', DB::table('package_itinerary_days')->count(), " row(s)\n";
