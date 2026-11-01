<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Add sourced trail records without overwriting later local/admin corrections. */
class PlannerBudgetHikingSeeder extends Seeder
{
    public function run(): void
    {
        $manifest = json_decode(file_get_contents(database_path('data/planner-budget-hiking-2026-11-01.json')), true, 512, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($manifest) {
            foreach ($manifest['places'] as $row) {
                if (Destination::where('slug', $row['slug'])->orWhere('name', $row['name'])->exists()) {
                    continue;
                }

                $place = Destination::create([
                    'slug' => $row['slug'], 'name' => $row['name'],
                    'region_id' => Region::firstOrCreate(['name' => $row['region']])->id,
                    'location' => $row['location'], 'type' => $row['type'],
                    'itinerary_role' => 'optional',
                    'latitude' => $row['coordinates'][0], 'longitude' => $row['coordinates'][1],
                    'price_tier' => $row['price_tier'],
                    'description' => $row['description'], 'planning_notes' => $row['planning_notes'],
                    'is_accredited' => false,
                    'planner_verified_at' => $manifest['checked_on'].' 12:00:00',
                    'planner_verification_source' => $row['source'],
                ]);

                foreach ($row['tags'] as $tag) {
                    $place->tags()->create(['kind' => 'category', 'value' => $tag]);
                }
            }
        });
    }
}
