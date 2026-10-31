<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\Region;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Apply dated evidence by branch slug; never turn identity verification into DOT accreditation. */
class PlannerEvidenceUpdatesSeeder extends Seeder
{
    public function run(): void
    {
        $manifest = json_decode(file_get_contents(database_path('data/planner-evidence-updates-2026-11-01.json')), true, 512, JSON_THROW_ON_ERROR);
        $checked = $manifest['checked_on'].' 12:00:00';

        DB::transaction(function () use ($manifest, $checked) {
            foreach ($manifest['destinations'] as $row) {
                $place = Destination::where('slug', $row['slug'])->where('name', $row['name'])->first();
                if (! $place) {
                    continue;
                }
                foreach ($row['fields'] as $field => $value) {
                    if ($field === 'planning_notes') {
                        $place->$field = $this->append($place->$field, $value);
                    } elseif (blank($place->$field) || $place->$field === $value) {
                        $place->$field = $value;
                    }
                }
                $place->planner_verification_source = $this->append($place->planner_verification_source, $row['source']);
                $place->save();
                foreach ($row['tags'] ?? [] as $tag) {
                    $place->tags()->firstOrCreate(['kind' => 'category', 'value' => $tag]);
                }
            }

            foreach ($manifest['restaurant_updates'] as $row) {
                $place = Restaurant::where('slug', $row['slug'])->where('name', $row['name'])->first();
                if (! $place) {
                    continue;
                }
                foreach ($row['fields'] as $field => $value) {
                    if ($field === 'description') {
                        $place->$field = $this->append($place->$field, $value);
                    } elseif (blank($place->$field) || $place->$field === $value) {
                        $place->$field = $value;
                    }
                }
                $place->planner_verified_at = $checked;
                $place->planner_verification_source = $this->append($place->planner_verification_source, $row['source']);
                $place->save();
            }

            foreach ($manifest['restaurants'] as $row) {
                $host = isset($row['host_destination_slug']) ? Destination::where('slug', $row['host_destination_slug'])->first() : null;
                if (isset($row['host_destination_slug']) && (! $host || $host->latitude === null || $host->longitude === null)) {
                    continue;
                }
                $place = Restaurant::firstOrNew(['slug' => $row['slug']]);
                if ($place->exists) {
                    continue; // Preserve any subsequent branch/operator corrections on repeated seeding.
                }
                $place->fill([
                    'name' => $row['name'],
                    'location' => $row['location'] ?? $host->location,
                    'region_id' => $host?->region_id ?? Region::firstOrCreate(['name' => 'Davao City'])->id,
                    'latitude' => $host?->latitude ?? $row['coordinates'][0],
                    'longitude' => $host?->longitude ?? $row['coordinates'][1],
                    'opening_hours' => $row['opening_hours'],
                    'description' => $row['description'],
                    'website_url' => $row['website_url'],
                    'host_destination_id' => $host?->id,
                    'is_accredited' => false,
                    'planner_verified_at' => $checked,
                    'planner_verification_source' => $row['source'],
                ])->save();
            }
        });
    }

    private function append(?string $existing, string $addition): string
    {
        $existing = trim((string) $existing);

        return str_contains($existing, $addition) ? $existing : trim($existing.' '.$addition);
    }
}
