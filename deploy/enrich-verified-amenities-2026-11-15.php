<?php

// Run from the application root. Preview by default; --apply adds missing rows only.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv ?? [], true);
$targets = [
    3 => 'Paradise Island Park & Beach Resort',
    58 => 'Paradise Island Park and Beach Resort',
];
$sources = [
    'parking' => 'https://paradiseislandsamal.com/',
    'restaurant' => 'https://paradiseislandsamal.com/dining/',
    'wifi' => 'https://paradiseislandsamal.com/rooms/',
    'ac' => 'https://paradiseislandsamal.com/rooms/',
];
$plan = [];
foreach ($targets as $id => $name) {
    $listing = App\Models\Accommodation::findOrFail($id);
    if ($listing->name !== $name || $listing->archived_at !== null) {
        throw new RuntimeException('Listing identity/status mismatch: '.$id);
    }
    foreach ($sources as $key => $source) {
        if (! in_array($key, App\Support\Amenities::FOR_KIND['accommodation'], true)) {
            throw new RuntimeException('Unsupported amenity: '.$key);
        }
        $attributes = ['listing_kind' => 'accommodation', 'listing_id' => $id, 'amenity' => $key];
        if (! App\Models\ListingAmenity::where($attributes)->exists()) {
            $plan[] = ['attributes' => $attributes, 'name' => $name, 'source' => $source];
        }
    }
}

$added = 0;
if ($apply) {
    Illuminate\Support\Facades\DB::transaction(function () use ($plan, &$added) {
        foreach ($plan as $row) {
            $amenity = App\Models\ListingAmenity::firstOrCreate($row['attributes']);
            if ($amenity->wasRecentlyCreated) {
                $added++;
                Illuminate\Support\Facades\Log::info('verified-amenity-enrichment-2026-11-15', $row);
            }
        }
    });
}
echo json_encode(['mode' => $apply ? 'apply' : 'preview', 'planned' => count($plan), 'added' => $added, 'changes' => $plan], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
