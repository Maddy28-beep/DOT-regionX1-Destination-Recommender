<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\Region;
use App\Models\TourOperator;
use Illuminate\Database\Seeder;

class TourOperatorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Must run after PackageSeeder (links each operator to its existing package
     * by provider_name) and before AccreditationSeeder (which accredits every
     * is_accredited tour operator this creates).
     */
    public function run(): void
    {
        $samal = Region::where('name', 'Island Garden City of Samal')->first();
        $davaoCity = Region::where('name', 'Davao City')->first();
        $davaoDelSur = Region::where('name', 'Davao del Sur')->first();
        $davaoOriental = Region::where('name', 'Davao Oriental')->first();

        $operators = [
            [
                'name' => 'Davao Island Explorers', 'region' => $samal, 'specialization' => 'Beach & Island',
                'description' => 'Island-hopping and beach tour specialist operating out of Island Garden City of Samal, running day trips to Samal Island and nearby beach destinations.',
                'rating' => 4.6, 'price_tier' => 'Mid-range', 'contact_number' => '0917-555-0101',
                'latitude' => 7.1553000, 'longitude' => 125.7080000,
            ],
            [
                'name' => 'Highland Trails Davao', 'region' => $davaoCity, 'specialization' => 'Nature & Adventure',
                'description' => 'Nature and adventure tour operator specializing in day trips to Eden Nature Park and other highland destinations around Davao City.',
                'rating' => 4.5, 'price_tier' => 'Mid-range', 'contact_number' => '0917-555-0102',
                'latitude' => null, 'longitude' => null,
            ],
            [
                'name' => 'Apo Summit Guides', 'region' => $davaoDelSur, 'specialization' => 'Adventure & Hiking',
                'description' => 'DOT-accredited mountaineering guide service specializing in multi-day Mount Apo summit treks, with certified guides and full trekking support.',
                'rating' => 4.9, 'price_tier' => 'Premium', 'contact_number' => '0917-555-0103',
                'latitude' => null, 'longitude' => null,
            ],
            [
                'name' => 'Davao Heritage Walks', 'region' => $davaoCity, 'specialization' => 'Cultural Heritage',
                'description' => 'Cultural heritage tour operator offering guided walking tours of historical and cultural sites across Davao City.',
                'rating' => 4.3, 'price_tier' => 'Budget-Friendly', 'contact_number' => '0917-555-0104',
                'latitude' => null, 'longitude' => null,
            ],
            [
                'name' => 'Mati Surf Co.', 'region' => $davaoOriental, 'specialization' => 'Beach & Surfing',
                'description' => 'Surf tour and beach getaway operator based in Mati City, Davao Oriental, running surf packages at Dahican Beach.',
                'rating' => 4.7, 'price_tier' => 'Mid-range', 'contact_number' => '0917-555-0105',
                'latitude' => null, 'longitude' => null,
            ],
        ];

        foreach ($operators as $o) {
            $tourOperator = TourOperator::create([
                'slug' => str($o['name'])->slug(),
                'name' => $o['name'],
                'location' => $o['region']?->name,
                'region_id' => $o['region']?->id,
                'specialization' => $o['specialization'],
                'description' => $o['description'],
                'is_accredited' => true,
                'rating' => $o['rating'],
                'review_count' => 0,
                'price_tier' => $o['price_tier'],
                'contact_number' => $o['contact_number'],
                'latitude' => $o['latitude'],
                'longitude' => $o['longitude'],
            ]);

            // PackageSeeder already seeded each package's provider_name to match
            // one of these operator names; link the real relationship now that
            // the operator row actually exists.
            Package::where('provider_name', $o['name'])->update(['tour_operator_id' => $tourOperator->id]);
        }
    }
}
