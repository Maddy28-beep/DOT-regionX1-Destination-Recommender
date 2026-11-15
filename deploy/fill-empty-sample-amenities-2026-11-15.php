<?php

// Run from the live application root. Preview by default; --apply writes the reviewed batch.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv ?? [], true);
$batch = 'simulated-amenities-2026-11-15';
$targets = [
    'destination' => [App\Models\Destination::class, [50, 51, 53, 54, 55, 56, 58, 61, 64, 68, 71, 76, 79, 83, 88, 89, 90, 92, 94, 95, 25, 27, 28, 29, 34, 35, 40, 41]],
    'restaurant' => [App\Models\Restaurant::class, [3, 1]],
    'souvenir_center' => [App\Models\SouvenirCenter::class, [2]],
];
$store = app(App\Services\Amenities\AmenityStore::class);
if (! method_exists($store, 'displayNames')) {
    throw new RuntimeException('Live amenity display-name support is required.');
}
foreach (['destination_tags', 'listing_amenities'] as $table) {
    if (! Illuminate\Support\Facades\Schema::hasColumn($table, 'display_name')) {
        throw new RuntimeException('Missing display_name column: '.$table);
    }
}

$plan = [];
foreach ($targets as $kind => [$model, $ids]) {
    foreach ($ids as $id) {
        $listing = $model::findOrFail($id);
        if ($listing->archived_at !== null || ! app(App\Support\SampleData::class)->isSample($listing)) {
            throw new RuntimeException('Expected active sample listing: '.$kind.':'.$id);
        }
        if ($store->keys($kind, $listing) !== []) {
            continue;
        }
        $keys = match ($kind) {
            'restaurant' => ['parking', 'restroom', 'ac', 'outdoor'],
            'souvenir_center' => ['parking', 'restroom', 'ac', 'gcash'],
            default => in_array($listing->type, ['Cultural Heritage', 'Events & Conventions'], true)
                ? ['parking', 'restroom', 'info', 'ac']
                : ['parking', 'restroom', 'info', 'picnic'],
        };
        if (array_diff($keys, App\Support\Amenities::allowed($kind, $keys)) !== []) {
            throw new RuntimeException('Invalid simulated amenity profile.');
        }
        $plan[] = ['kind' => $kind, 'id' => $id, 'name' => $listing->name, 'keys' => $keys];
    }
}

$added = 0;
if ($apply && $plan !== []) {
    if (count($plan) !== 31) {
        throw new RuntimeException('Expected 31 empty samples; preview and review the changed dataset first.');
    }
    Illuminate\Support\Facades\DB::transaction(function () use ($plan, $targets, $store, $batch, &$added) {
        foreach ($plan as $row) {
            $listing = $targets[$row['kind']][0]::whereKey($row['id'])->lockForUpdate()->firstOrFail();
            if ($listing->name !== $row['name'] || $store->keys($row['kind'], $listing) !== []) {
                throw new RuntimeException('Listing changed since preview: '.$row['name']);
            }
            foreach ($row['keys'] as $key) {
                $identity = $row['kind'] === 'destination'
                    ? ['destination_id' => $row['id'], 'kind' => 'amenity', 'value' => App\Support\Amenities::label($key)]
                    : ['listing_kind' => $row['kind'], 'listing_id' => $row['id'], 'amenity' => $key];
                $class = $row['kind'] === 'destination' ? App\Models\DestinationTag::class : App\Models\ListingAmenity::class;
                $record = $class::firstOrCreate($identity);
                if (! $record->wasRecentlyCreated) {
                    throw new RuntimeException('Amenity already exists; refusing to relabel it.');
                }
                $record->display_name = App\Support\Amenities::label($key).' (simulated)';
                $record->save();
                $added++;
                Illuminate\Support\Facades\Log::info($batch, $row + ['amenity' => $key, 'provenance' => 'Invented test facility; not a verified property attribute']);
            }
        }
    });
}
echo json_encode(['mode' => $apply ? 'apply' : 'preview', 'batch' => $batch, 'listings' => count($plan), 'added' => $added, 'plan' => $plan], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
