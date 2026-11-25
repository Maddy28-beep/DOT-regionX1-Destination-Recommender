<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Models\RestaurantFoodPreference;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportRestaurantFoodPreferences extends Command
{
    protected $signature = 'restaurants:food-preferences {--apply : Import the reviewed evidence} {--allow-production : Apply on a live server}';
    protected $description = 'Record reviewed restaurant food preferences; unknowns remain Not confirmed (dry run by default).';

    public function handle(): int
    {
        // A live server needs --allow-production (the project owner asked on 25 November 2026 for this on the live site).
        // Nothing is written without --apply, and the dry run shows the plan.
        if (!app()->environment(['local', 'testing']) && !$this->option('allow-production')) {
            $this->error('This evidence import is applied locally unless you add --allow-production. Nothing changed.');
            return self::FAILURE;
        }
        $entries = json_decode(file_get_contents(database_path('data/restaurant-food-preferences-2026-11-24.json')), true, flags: JSON_THROW_ON_ERROR);
        $plan = []; $seen = [];
        foreach ($entries as $entry) {
            if (isset($seen[$entry['slug']])) {
                $this->error('Duplicate restaurant identity; nothing changed.');
                return self::FAILURE;
            }
            $seen[$entry['slug']] = true;
            $restaurant = Restaurant::where('slug', $entry['slug'])->first();
            if (!$restaurant) continue;
            if ($restaurant->name !== $entry['name'] || $restaurant->location !== $entry['location']) {
                $this->error('Restaurant identity changed: '.$entry['name'].'. Nothing changed.');
                return self::FAILURE;
            }
            foreach (RestaurantFoodPreference::OPTIONS as $key => $label) {
                $record = $entry['preferences'][$key] ?? ['status' => 'not_confirmed'];
                if (!in_array($record['status'], ['confirmed', 'not_confirmed'], true)) {
                    $this->error('Invalid preference status. Nothing changed.');
                    return self::FAILURE;
                }
                if ($record['status'] === 'confirmed' && (
                    !filter_var($record['source_url'] ?? null, FILTER_VALIDATE_URL)
                    || !str_starts_with($record['source_url'], 'https://')
                    || blank($record['source_name'] ?? null) || blank($record['evidence'] ?? null)
                    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $record['checked_on'] ?? '')
                    || $record['checked_on'] > today()->toDateString()
                    || ($key === 'halal_certified' && (blank($record['certifier'] ?? null)
                        || blank($record['certificate_reference'] ?? null) || blank($record['coverage'] ?? null)
                        || ($record['valid_from'] ?? '9999-12-31') > today()->toDateString()
                        || ($record['expires_on'] ?? '') < today()->toDateString())))) {
                    $this->error('Missing or invalid evidence for '.$entry['name'].' / '.$key.'. Nothing changed.');
                    return self::FAILURE;
                }
                $existing = $restaurant->foodPreferences()->where('preference', $key)->first();
                // Never downgrade an independently maintained record or overwrite a later review.
                if ($existing && ($existing->status === 'confirmed' || $existing->source_url)) continue;
                $attributes = array_intersect_key($record, array_flip((new RestaurantFoodPreference)->getFillable()));
                unset($attributes['restaurant_id'], $attributes['preference']);
                if ($existing && $existing->status === $record['status'] && $record['status'] === 'not_confirmed') continue;
                $plan[] = [$restaurant->id, $key, $attributes];
            }
        }
        if ($this->option('apply')) {
            DB::transaction(function () use ($plan) {
                foreach ($plan as [$id, $key, $attributes]) {
                    RestaurantFoodPreference::updateOrCreate(['restaurant_id' => $id, 'preference' => $key], $attributes);
                }
            });
        }
        $this->info(count($plan).($this->option('apply') ? ' preference records saved.' : ' preference records ready (dry run).'));
        return self::SUCCESS;
    }
}
