<?php

namespace App\Console\Commands;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turns two reference places (Magsaysay Park and Wishing Island) into public catalogue listings, or creates them where they
 * do not exist yet (the live site never had them).
 *
 * The project owner asked for this on 24 November 2026. Like the other real places added that month, they are public
 * catalogue entries with no DOT accreditation claimed (the card shows "DOT accreditation not verified") and they stay out
 * of generated itineraries (itinerary_role = excluded) until visit details are verified. Unknown details (hours, fees,
 * visit length) stay blank. Their photographs come from the Wikimedia Commons batch: run CommonsBatchPhotoSeeder afterwards.
 *
 * Without --apply it only shows the plan. A live server also needs --allow-production.
 */
class PublishReferencePlaces extends Command
{
    protected $signature = 'destinations:publish-reference-places {--apply : Write the changes} {--allow-production : Apply on a live server}';

    protected $description = 'Make Magsaysay Park and Wishing Island public catalogue listings (dry run by default).';

    /** The reference-place note these records carry while they are hidden (see ReferencePlaceSeeder). */
    public const REFERENCE_NOTE = 'Reference place: not DOT-accredited and not shown to travelers. Recorded so published itineraries that mention it can be matched.';

    public const PLACES = [
        [
            'slug' => 'magsaysay-park',
            'name' => 'Magsaysay Park',
            'region' => 'Davao City',
            'location' => 'Ramon Magsaysay Avenue, Poblacion District, Davao City',
            'type' => 'Cultural Heritage',
            'description' => 'A waterfront park beside the Santa Ana Wharf, with a tall memorial to President Ramon Magsaysay, historical markers and views across to Samal. Fruit stalls selling durian and other Davao fruit line the street outside. Confirm current opening and any ongoing works before visiting.',
            'latitude' => 7.07446,
            'longitude' => 125.6256,
        ],
        [
            'slug' => 'wishing-island',
            'name' => 'Wishing Island',
            'region' => 'Island Garden City of Samal',
            'location' => 'Off Barangay Adecor, Kaputian District, Island Garden City of Samal, Davao del Norte',
            'type' => 'Beach & Leisure',
            'description' => 'A small rocky islet off the Samal shore near Pearl Farm, usually visited as a stop on island-hopping tours. Visitors climb up to a small grotto and cross to make a wish. Sea urchins surround the rocks, so it is not a swimming spot. Access is by boat only.',
            'latitude' => null,
            'longitude' => null,
        ],
    ];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('allow-production')) {
            $this->error('This change is applied locally unless you add --allow-production. Nothing changed.');

            return self::FAILURE;
        }

        $plan = [];
        foreach (self::PLACES as $place) {
            $region = Region::where('name', $place['region'])->first();
            if (! $region) {
                $this->error('Region not found: '.$place['region'].'. Nothing changed.');

                return self::FAILURE;
            }
            $matches = Destination::withoutGlobalScopes()->where('slug', $place['slug'])->orWhere('name', $place['name'])->get();
            if ($matches->count() > 1) {
                $this->error('More than one listing matches '.$place['name'].'. Nothing changed.');

                return self::FAILURE;
            }
            $destination = $matches->first();
            if ($destination && $destination->isArchived()) {
                $this->error($place['name'].' is archived; restore it in the admin first. Nothing changed.');

                return self::FAILURE;
            }
            // A record that already shows a description of its own was edited by someone: leave it alone.
            if ($destination && $destination->description !== self::REFERENCE_NOTE) {
                $this->line(sprintf('%-16s already a listing; left unchanged', $place['name']));

                continue;
            }
            $this->line(sprintf('%-16s %s', $place['name'], $destination ? 'reference place -> public listing' : 'new public listing'));
            $plan[] = [$place, $region, $destination];
        }

        $this->info(count($plan).' listings ready.');
        if (! $this->option('apply')) {
            $this->line('Dry run: nothing changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as [$place, $region, $destination]) {
                $values = [
                    'name' => $place['name'], 'location' => $place['location'], 'type' => $place['type'],
                    'description' => $place['description'], 'region_id' => $region->id,
                    // Legacy public-eligibility flag; no accreditation record is created, so the site says "not verified".
                    'is_accredited' => true,
                    // Kept out of generated itineraries until hours, fees and visit length are verified.
                    'itinerary_role' => 'excluded',
                ];
                if ($place['latitude'] !== null && ($destination?->latitude === null)) {
                    $values += ['latitude' => $place['latitude'], 'longitude' => $place['longitude']];
                }
                $destination
                    ? $destination->forceFill($values)->save()
                    : Destination::create(['slug' => $place['slug'], ...$values]);
            }
        });

        $this->info(count($plan).' listings published. Next: php artisan db:seed --class=CommonsBatchPhotoSeeder'.(app()->environment('production') ? ' --force' : ''));

        return self::SUCCESS;
    }
}
