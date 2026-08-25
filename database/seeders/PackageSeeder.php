<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\Region;
use App\Models\Review;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $davaoCity = Region::where('name', 'Davao City')->first();
        $samal = Region::where('name', 'Island Garden City of Samal')->first();
        $davaoDelSur = Region::where('name', 'Davao del Sur')->first();
        $davaoOriental = Region::where('name', 'Davao Oriental')->first();

        $packages = [
            [
                'name' => 'Samal Island Hopping Day Tour', 'region' => $samal, 'type' => 'Beach & Island',
                'duration_label' => '1 Day', 'duration_days' => 1, 'price_per_pax' => 1500,
                'price_tier' => 'Mid-range', 'provider_name' => 'Davao Island Explorers', 'rating' => 4.6, 'review_count' => 52,
                'inclusions' => ['Roundtrip ferry transfers', 'Island hopping boat', 'Lunch buffet', 'Snorkeling gear', 'Tour guide'],
            ],
            [
                'name' => 'Eden Nature Park Day Adventure', 'region' => $davaoCity, 'type' => 'Nature & Adventure',
                'duration_label' => '1 Day', 'duration_days' => 1, 'price_per_pax' => 1200,
                'price_tier' => 'Mid-range', 'provider_name' => 'Highland Trails Davao', 'rating' => 4.5, 'review_count' => 38,
                'inclusions' => ['Entrance fee', 'Zipline session', 'Buffet lunch', 'Cable car ride'],
            ],
            [
                'name' => 'Mount Apo 3-Day Summit Trek', 'region' => $davaoDelSur, 'type' => 'Adventure & Hiking',
                'duration_label' => '3 Days, 2 Nights', 'duration_days' => 3, 'price_per_pax' => 6500,
                'price_tier' => 'Premium', 'provider_name' => 'Apo Summit Guides', 'rating' => 4.9, 'review_count' => 21,
                'inclusions' => ['Registered mountain guide', 'Porter service', 'Camping gear', 'Meals for 3 days', 'Environmental fees'],
            ],
            [
                'name' => 'Davao City Cultural Heritage Tour', 'region' => $davaoCity, 'type' => 'Cultural Heritage',
                'duration_label' => 'Half Day', 'duration_days' => 1, 'price_per_pax' => 800,
                'price_tier' => 'Budget-Friendly', 'provider_name' => 'Davao Heritage Walks', 'rating' => 4.3, 'review_count' => 29,
                'inclusions' => ["People's Park visit", 'Museum entrance', 'Local guide', 'Air-conditioned van transfers'],
            ],
            [
                'name' => 'Dahican Beach Surf & Chill Package', 'region' => $davaoOriental, 'type' => 'Beach & Surfing',
                'duration_label' => '2 Days, 1 Night', 'duration_days' => 2, 'price_per_pax' => 3200,
                'price_tier' => 'Mid-range', 'provider_name' => 'Mati Surf Co.', 'rating' => 4.7, 'review_count' => 17,
                'inclusions' => ['Overnight accommodation', 'Surfboard rental', '2 surf lessons', 'Breakfast'],
            ],
        ];

        foreach ($packages as $p) {
            $package = Package::create([
                'slug' => str($p['name'])->slug(),
                'name' => $p['name'],
                'location' => $p['region']?->name,
                'region_id' => $p['region']?->id,
                'type' => $p['type'],
                'duration_label' => $p['duration_label'],
                'duration_days' => $p['duration_days'],
                'description' => "{$p['name']} is a curated tour package covering DOT-accredited stops in {$p['region']?->name}.",
                'is_accredited' => true,
                'price_per_pax' => $p['price_per_pax'],
                'price_tier' => $p['price_tier'],
                'rating' => $p['rating'],
                'review_count' => $p['review_count'],
                'provider_name' => $p['provider_name'],
                'featured' => true,
            ]);

            foreach ($p['inclusions'] as $item) {
                $package->inclusions()->create(['item' => $item]);
            }

            Review::create([
                'listing_kind' => 'package',
                'listing_id' => $package->id,
                'author_name' => 'Traveler',
                'rating' => (int) round($p['rating']),
                'comment' => 'Well-organized tour, guide was very knowledgeable.',
            ]);
        }
    }
}
