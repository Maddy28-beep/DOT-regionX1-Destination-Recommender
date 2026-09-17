<?php

namespace Database\Seeders;

use App\Models\Destination;
use Illuminate\Database\Seeder;

/**
 * Backfills category tags for the 17 destinations imported from the real
 * DOT accreditation list, which carried names, addresses, and accreditation
 * numbers but no activity tags -- unlike DestinationSeeder's 8 hand-written
 * entries, which have always had them.
 *
 * This matters beyond completeness: ContentBasedRecommendationService's
 * interestSimilarity() only gets a real per-destination signal from these
 * 'category' tags. Without them, a destination falls back to a coarse
 * type->interest guess, and a genuine mismatch is softened to a "neutral"
 * 0.5 rather than penalized -- so a tourist's stated interests (Wildlife,
 * Food Tourism, ...) had almost no power to separate a matching destination
 * from a merely popular one. "Food Tourism" specifically has no destination
 * TYPE that implies it at all (see TYPE_TO_INTEREST); the six Farm Tourism
 * listings below are given an explicit "Food Tasting" tag for exactly this
 * reason, since farm-tour-and-tasting is literally what they offer per the
 * itinerary schedule text already generated for them.
 *
 * Tags are drawn from each listing's own name/type/address only -- nothing
 * here is fabricated beyond what those already say (a golf club is tagged
 * "Golf", not "Wildlife").
 *
 * Idempotent: skips any destination that already has a 'category' tag, so
 * this is safe to include in DatabaseSeeder alongside a reseed.
 */
class DestinationCategoryTagSeeder extends Seeder
{
    /** @var array<string, array<int, string>> slug => category tag values */
    private const TAGS = [
        'imin-the-philippine-japan-historical-museum' => ['Cultural Heritage', 'Museum'],
        'smx-convention-center-davao' => ['Cultural Heritage'],

        'belviz-farm' => ['Nature', 'Farm Tour', 'Food Tasting'],
        'bemwa-farm-fresh-inc' => ['Nature', 'Farm Tour', 'Food Tasting'],
        'bukid-amara-tourism-agriventures-corporation' => ['Nature', 'Farm Tour', 'Food Tasting'],
        'damosa-land-inc-agriya-naturetainment' => ['Nature', 'Farm Tour', 'Food Tasting'],
        'isla-agri-ventures-inc' => ['Nature', 'Farm Tour', 'Food Tasting'],
        'lao-integrated-farms' => ['Nature', 'Farm Tour', 'Food Tasting'],

        'apo-golf-and-country-club' => ['Golf', 'Sports & Recreation'],
        'rancho-palos-verdes-golf-sports-club-inc' => ['Golf', 'Sports & Recreation'],
        'rancho-palos-verdes-golf-sports-club-inc-2' => ['Golf', 'Sports & Recreation'],
        'south-pacific-davao-country-club-inc' => ['Golf', 'Sports & Recreation'],

        'elysia-wellness-spa' => ['Relaxation', 'Spa'],
        'elysia-wellness-spa-2' => ['Relaxation', 'Spa'],
        'elysia-wellness-spa-3' => ['Relaxation', 'Spa'],

        'davao-crocodile-park-inc' => ['Wildlife', 'Conservation', 'Family Friendly'],
        'jkm-mini-zoo' => ['Wildlife', 'Family Friendly'],
    ];

    public function run(): void
    {
        $destinations = Destination::whereIn('slug', array_keys(self::TAGS))
            ->with('tags')
            ->get()
            ->keyBy('slug');

        foreach (self::TAGS as $slug => $tags) {
            $destination = $destinations->get($slug);

            if (! $destination || $destination->tags->where('kind', 'category')->isNotEmpty()) {
                continue;
            }

            foreach ($tags as $tag) {
                $destination->tags()->create(['kind' => 'category', 'value' => $tag]);
            }
        }

        $this->mergeRetiredCrocodileParkData($destinations->get('davao-crocodile-park-inc'));
    }

    /**
     * Carries the coordinates and price range from the retired hand-seeded
     * "Davao Crocodile Park" duplicate (see DestinationSeeder) onto the real
     * accredited record, so removing that duplicate does not also remove the
     * one thing it had that this record didn't: a real, surveyed position.
     * Guarded on latitude so a second run (or a future real DOT coordinate)
     * never overwrites it.
     */
    private function mergeRetiredCrocodileParkData(?Destination $destination): void
    {
        if (! $destination || $destination->latitude !== null) {
            return;
        }

        $destination->update([
            'latitude' => 7.1204,
            'longitude' => 125.6461,
            'price_tier' => $destination->price_tier ?? 'Mid-range',
            'entry_fee_min' => $destination->entry_fee_min ?? 300,
            'entry_fee_max' => $destination->entry_fee_max ?? 500,
        ]);
    }
}
