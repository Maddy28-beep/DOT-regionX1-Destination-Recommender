<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\Review;
use App\Models\SouvenirCenter;
use Illuminate\Database\Seeder;

class DestinationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $regions = collect([
            'Davao City',
            'Island Garden City of Samal',
            'Davao del Norte',
            'Davao de Oro',
            'Davao Occidental',
            'Davao Oriental',
            'Davao del Sur',
        ])->mapWithKeys(fn ($name) => [$name => Region::create(['name' => $name])]);

        $destinations = [
            [
                'name' => 'Samal Island', 'region' => 'Island Garden City of Samal', 'type' => 'Beach & Leisure',
                'location' => 'Babak District, IGACOS', 'rating' => 4.7, 'review_count' => 128,
                'price_tier' => 'Mid-range', 'entry_fee_min' => 100, 'entry_fee_max' => 500,
                'distance_km' => 7.5, 'latitude' => 7.1553, 'longitude' => 125.7080,
                'tags' => ['Beach', 'Island Hopping', 'Family Friendly'],
            ],
            [
                'name' => 'Eden Nature Park', 'region' => 'Davao City', 'type' => 'Nature & Adventure',
                'location' => 'Toril, Davao City', 'rating' => 4.6, 'review_count' => 96,
                'price_tier' => 'Mid-range', 'entry_fee_min' => 150, 'entry_fee_max' => 450,
                'distance_km' => 32, 'latitude' => 7.0322, 'longitude' => 125.3739,
                'tags' => ['Nature', 'Cool Climate', 'Zipline'],
            ],
            [
                'name' => 'Philippine Eagle Center', 'region' => 'Davao City', 'type' => 'Wildlife',
                'location' => 'Malagos, Baguio District, Davao City', 'rating' => 4.8, 'review_count' => 142,
                'price_tier' => 'Budget-Friendly', 'entry_fee_min' => 150, 'entry_fee_max' => 150,
                'distance_km' => 28, 'latitude' => 7.1547, 'longitude' => 125.4064,
                'tags' => ['Wildlife', 'Conservation', 'Family Friendly'],
            ],
            [
                'name' => "People's Park", 'region' => 'Davao City', 'type' => 'Cultural Heritage',
                'location' => 'San Pedro St, Davao City', 'rating' => 4.4, 'review_count' => 210,
                'price_tier' => 'Free', 'entry_fee_min' => 0, 'entry_fee_max' => 0,
                'distance_km' => 3, 'latitude' => 7.0644, 'longitude' => 125.6079,
                'tags' => ['Cultural Heritage', 'City Center'],
            ],
            [
                'name' => 'Davao Crocodile Park', 'region' => 'Davao City', 'type' => 'Wildlife',
                'location' => 'Diversion Rd, Davao City', 'rating' => 4.3, 'review_count' => 87,
                'price_tier' => 'Mid-range', 'entry_fee_min' => 300, 'entry_fee_max' => 500,
                'distance_km' => 15, 'latitude' => 7.1204, 'longitude' => 125.6461,
                'tags' => ['Wildlife', 'Family Friendly'],
            ],
            [
                'name' => 'Malagos Garden Resort', 'region' => 'Davao City', 'type' => 'Nature & Leisure',
                'location' => 'Malagos, Baguio District, Davao City', 'rating' => 4.5, 'review_count' => 74,
                'price_tier' => 'Mid-range', 'entry_fee_min' => 100, 'entry_fee_max' => 350,
                'distance_km' => 30, 'latitude' => 7.1622, 'longitude' => 125.4106,
                'tags' => ['Nature', 'Chocolate Experience', 'Garden'],
            ],
            [
                'name' => 'Mount Apo Natural Park', 'region' => 'Davao del Sur', 'type' => 'Adventure & Hiking',
                'location' => 'Kapatagan, Digos, Davao del Sur', 'rating' => 4.9, 'review_count' => 63,
                'price_tier' => 'Budget-Friendly', 'entry_fee_min' => 500, 'entry_fee_max' => 1500,
                'distance_km' => 65, 'latitude' => 6.9847, 'longitude' => 125.2725,
                'tags' => ['Hiking', 'Highest Peak', 'Wildlife'],
            ],
            [
                'name' => 'Dahican Beach', 'region' => 'Davao Oriental', 'type' => 'Beach & Surfing',
                'location' => 'Mati City, Davao Oriental', 'rating' => 4.6, 'review_count' => 58,
                'price_tier' => 'Budget-Friendly', 'entry_fee_min' => 0, 'entry_fee_max' => 50,
                'distance_km' => 165, 'latitude' => 6.9553, 'longitude' => 126.2758,
                'tags' => ['Surfing', 'Beach', 'Turtle Sanctuary'],
            ],
        ];

        foreach ($destinations as $index => $d) {
            $destination = Destination::create([
                'slug' => str($d['name'])->slug(),
                'name' => $d['name'],
                'location' => $d['location'],
                'region_id' => $regions[$d['region']]->id,
                'type' => $d['type'],
                'description' => "{$d['name']} is a DOT-accredited destination in {$d['region']}, part of the Davao Region tourism circuit.",
                'is_accredited' => true,
                'rating' => $d['rating'],
                'review_count' => $d['review_count'],
                'price_tier' => $d['price_tier'],
                'entry_fee_min' => $d['entry_fee_min'],
                'entry_fee_max' => $d['entry_fee_max'],
                'distance_km' => $d['distance_km'],
                'latitude' => $d['latitude'],
                'longitude' => $d['longitude'],
                'featured' => $index < 6,
            ]);

            foreach ($d['tags'] as $tag) {
                $destination->tags()->create(['kind' => 'category', 'value' => $tag]);
            }

            Review::create([
                'listing_kind' => 'destination',
                'listing_id' => $destination->id,
                'author_name' => 'Traveler',
                'rating' => (int) round($d['rating']),
                'comment' => 'Wonderful spot, well worth the visit.',
            ]);
        }

        $accommodations = [
            ['name' => 'BlueJaz Beach Resort', 'region' => 'Island Garden City of Samal', 'rating' => 4.5, 'price_per_night' => 3500],
            ['name' => 'Pearl Farm Beach Resort', 'region' => 'Island Garden City of Samal', 'rating' => 4.7, 'price_per_night' => 8500],
            ['name' => 'Paradise Island Park & Beach Resort', 'region' => 'Island Garden City of Samal', 'rating' => 4.3, 'price_per_night' => 2800],
        ];

        foreach ($accommodations as $a) {
            Accommodation::create([
                'slug' => str($a['name'])->slug(),
                'name' => $a['name'],
                'location' => 'Island Garden City of Samal',
                'region_id' => $regions['Island Garden City of Samal']->id,
                'type' => 'Beach Resort',
                'is_accredited' => true,
                'rating' => $a['rating'],
                'review_count' => 40,
                'price_tier' => 'Mid-range',
                'price_per_night' => $a['price_per_night'],
            ]);
        }

        $restaurants = [
            ['name' => 'Marina Tuna Restaurant', 'cuisine_type' => 'Filipino Seafood', 'rating' => 4.6, 'review_count' => 55, 'price_tier' => 'Mid-range'],
            ['name' => 'Kublai Khan Mongolian Restaurant', 'cuisine_type' => 'Mongolian & Asian', 'rating' => 4.5, 'review_count' => 61, 'price_tier' => 'Mid-range'],
            ['name' => 'Blue Post Boiling Crabs & Shrimp', 'cuisine_type' => 'Seafood', 'rating' => 4.4, 'review_count' => 48, 'price_tier' => 'Mid-range'],
        ];

        foreach ($restaurants as $r) {
            Restaurant::create([
                'slug' => str($r['name'])->slug(),
                'name' => $r['name'],
                'location' => 'Davao City',
                'region_id' => $regions['Davao City']->id,
                'cuisine_type' => $r['cuisine_type'],
                'is_accredited' => true,
                'rating' => $r['rating'],
                'review_count' => $r['review_count'],
                'price_tier' => $r['price_tier'],
            ]);
        }

        $souvenirCenters = [
            ['name' => 'Davao Local Products & Souvenir Center', 'rating' => 4.4, 'review_count' => 33],
            ['name' => 'Aldevinco Shopping Center', 'rating' => 4.3, 'review_count' => 47],
            ['name' => 'Kadayawan Souvenir Shop', 'rating' => 4.2, 'review_count' => 29],
        ];

        foreach ($souvenirCenters as $s) {
            SouvenirCenter::create([
                'slug' => str($s['name'])->slug(),
                'name' => $s['name'],
                'location' => 'Davao City',
                'region_id' => $regions['Davao City']->id,
                'is_accredited' => true,
                'rating' => $s['rating'],
                'review_count' => $s['review_count'],
            ]);
        }
    }
}
