<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\DestinationTag;
use App\Models\ExitSurvey;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TouristPreference;
use App\Models\TouristVisit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a realistic volume of tourist visits and exit-survey visitation
 * records so Content-Based Recommendation has enough listing/preference
 * variety, and Association Rule Mining (Apriori) has enough transactions
 * to produce non-trivial support/confidence values instead of numbers
 * computed from a handful of rows.
 */
class RecommendationDataSeeder extends Seeder
{
    private array $pool = [];

    public function run(): void
    {
        $this->loadListingPool();
        $clusters = $this->buildClusters();

        $this->seedTripPlans(40);
        $this->seedVisits(40, $clusters);
        $this->seedExitSurveys(90, $clusters);
        $this->seedDestinationAmenities();
    }

    /**
     * The Amenities Score (AS) factor in the Destination Recommendation Score
     * (paper Equation 6) needs real "kind=amenity" tags to compare against a
     * tourist's selected preference_amenities — none existed before this.
     */
    private function seedDestinationAmenities(): void
    {
        $amenitySets = [
            'Samal Island' => ['Parking Area', 'Restaurant', 'Restroom', 'Swimming Pool'],
            'Eden Nature Park' => ['Parking Area', 'Restaurant', 'Restroom', 'Air Conditioning'],
            'Philippine Eagle Center' => ['Parking Area', 'Restroom', 'Accessibility Ramp'],
            "People's Park" => ['Parking Area', 'Restroom', 'Wi-Fi', 'Accessibility Ramp'],
            'Davao Crocodile Park' => ['Parking Area', 'Restaurant', 'Restroom'],
            'Malagos Garden Resort' => ['Parking Area', 'Restaurant', 'Restroom', 'Wi-Fi'],
            'Mount Apo Natural Park' => ['Parking Area', 'Restroom'],
            'Dahican Beach' => ['Parking Area', 'Restaurant', 'Restroom'],
        ];

        foreach ($amenitySets as $name => $amenities) {
            $id = $this->pool['destination'][$name] ?? null;
            if (! $id) {
                continue;
            }
            foreach ($amenities as $amenity) {
                DestinationTag::firstOrCreate([
                    'destination_id' => $id,
                    'kind' => 'amenity',
                    'value' => $amenity,
                ]);
            }
        }
    }

    private function loadListingPool(): void
    {
        $this->pool = [
            'destination' => Destination::pluck('id', 'name'),
            'accommodation' => Accommodation::pluck('id', 'name'),
            'restaurant' => Restaurant::pluck('id', 'name'),
            'souvenir_center' => SouvenirCenter::pluck('id', 'name'),
            'package' => Package::pluck('id', 'name'),
        ];
    }

    private function listing(string $kind, string $name): array
    {
        return ['listing_kind' => $kind, 'listing_id' => $this->pool[$kind][$name]];
    }

    /**
     * Four named co-visitation clusters, weighted like real Davao travel
     * patterns (beach-hopping around Samal is the dominant one, matching
     * the Samal Island / BlueJaz Beach Resort relationship already used
     * as the worked example in the paper's Apriori walkthrough).
     */
    private function buildClusters(): array
    {
        return [
            [
                'name' => 'samal',
                'weight' => 35,
                'anchor' => $this->listing('destination', 'Samal Island'),
                'anchor_partner' => $this->listing('accommodation', 'BlueJaz Beach Resort'),
                'anchor_partner_chance' => 75,
                'items' => [
                    $this->listing('accommodation', 'Pearl Farm Beach Resort'),
                    $this->listing('accommodation', 'Paradise Island Park & Beach Resort'),
                    $this->listing('restaurant', 'Marina Tuna Restaurant'),
                    $this->listing('package', 'Samal Island Hopping Day Tour'),
                ],
                'activities' => ['Beach & Island', 'Relaxation & Wellness'],
            ],
            [
                'name' => 'nature',
                'weight' => 25,
                'anchor' => $this->listing('destination', 'Eden Nature Park'),
                'anchor_partner' => $this->listing('destination', 'Malagos Garden Resort'),
                'anchor_partner_chance' => 55,
                'items' => [
                    $this->listing('destination', 'Philippine Eagle Center'),
                    $this->listing('destination', 'Davao Crocodile Park'),
                    $this->listing('package', 'Eden Nature Park Day Adventure'),
                ],
                'activities' => ['Nature & Adventure', 'Wildlife'],
            ],
            [
                'name' => 'city',
                'weight' => 20,
                'anchor' => $this->listing('destination', "People's Park"),
                'anchor_partner' => $this->listing('souvenir_center', 'Aldevinco Shopping Center'),
                'anchor_partner_chance' => 50,
                'items' => [
                    $this->listing('souvenir_center', 'Davao Local Products & Souvenir Center'),
                    $this->listing('souvenir_center', 'Kadayawan Souvenir Shop'),
                    $this->listing('restaurant', 'Kublai Khan Mongolian Restaurant'),
                    $this->listing('restaurant', 'Blue Post Boiling Crabs & Shrimp'),
                    $this->listing('package', 'Davao City Cultural Heritage Tour'),
                ],
                'activities' => ['Cultural Heritage', 'Shopping & Souvenirs', 'Food Tourism'],
            ],
            [
                'name' => 'adventure',
                'weight' => 20,
                'anchor' => $this->listing('destination', 'Mount Apo Natural Park'),
                'anchor_partner' => $this->listing('package', 'Mount Apo 3-Day Summit Trek'),
                'anchor_partner_chance' => 60,
                'items' => [
                    $this->listing('destination', 'Dahican Beach'),
                    $this->listing('package', 'Dahican Beach Surf & Chill Package'),
                ],
                'activities' => ['Hiking & Trekking', 'Beach & Island'],
            ],
        ];
    }

    private function pickCluster(array $clusters): array
    {
        $roll = mt_rand(1, array_sum(array_column($clusters, 'weight')));
        $cursor = 0;
        foreach ($clusters as $cluster) {
            $cursor += $cluster['weight'];
            if ($roll <= $cursor) {
                return $cluster;
            }
        }

        return $clusters[0];
    }

    /** Draw 2-4 listings from a cluster, always the anchor, the partner with the cluster's chance, then fill randomly. */
    private function drawFromCluster(array $cluster): array
    {
        $picked = [$cluster['anchor']];

        if (mt_rand(1, 100) <= $cluster['anchor_partner_chance']) {
            $picked[] = $cluster['anchor_partner'];
        }

        $extraPool = $cluster['items'];
        shuffle($extraPool);
        $extraCount = mt_rand(0, min(2, count($extraPool)));
        for ($i = 0; $i < $extraCount; $i++) {
            $picked[] = $extraPool[$i];
        }

        // de-duplicate by kind+id
        $seen = [];
        return array_values(array_filter($picked, function ($item) use (&$seen) {
            $key = $item['listing_kind'].':'.$item['listing_id'];
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        }));
    }

    /**
     * Survey answers with nobody attached to them.
     *
     * These used to be seeded as tourist accounts with names, emails and
     * password hashes. There are no accounts any more, and the recommender
     * never needed the person -- only the preferences -- so this seeds the
     * preferences directly.
     */
    private function seedTripPlans(int $count): void
    {
        $travelTypes = ['Solo', 'Couple', 'Family', 'Friends', 'Business'];
        $budgets = ['Budget-Friendly', 'Mid-range', 'Premium'];
        $accommodationPrefs = ['Any', 'Beach Resort', 'Hotel', 'Homestay', 'Hostel'];
        $distancePrefs = ['near', 'moderate', 'far'];
        $travelPurposes = ['Leisure', 'Business', 'Visiting Friends/Family', 'Educational', 'Medical', 'Religious/Pilgrimage', 'Other'];
        $visitorTypes = ['First-time Visitor', 'Returning Visitor', 'Regular / Local'];
        $activityOptions = ['Beach & Island', 'Nature & Adventure', 'Cultural Heritage', 'Wildlife', 'Food Tourism', 'Shopping & Souvenirs', 'Hiking & Trekking', 'Relaxation & Wellness'];

        for ($i = 0; $i < $count; $i++) {
            $preference = TouristPreference::create([
                'travel_days' => mt_rand(1, 7),
                'travel_type' => $travelTypes[array_rand($travelTypes)],
                'budget' => $budgets[array_rand($budgets)],
                'accommodation_pref' => $accommodationPrefs[array_rand($accommodationPrefs)],
                'distance_pref' => $distancePrefs[array_rand($distancePrefs)],
                'travel_purpose' => $travelPurposes[array_rand($travelPurposes)],
                'visitor_type' => $visitorTypes[array_rand($visitorTypes)],
            ]);

            foreach (collect($activityOptions)->shuffle()->take(mt_rand(1, 3)) as $activity) {
                $preference->activities()->create(['activity' => $activity]);
            }
        }
    }

    /**
     * Visits keyed to an anonymous browser token, matching what a real QR
     * scan records. One token stands in for one device, which is what the
     * daily-dedupe index and the Apriori transactions are grouped by.
     */
    private function seedVisits(int $visitorCount, array $clusters): void
    {
        for ($i = 0; $i < $visitorCount; $i++) {
            $token = (string) Str::uuid();
            $visitCount = mt_rand(2, 5);
            $cluster = $this->pickCluster($clusters);
            $items = $this->drawFromCluster($cluster);

            // occasionally blend in one item from a second cluster for realism
            if (mt_rand(1, 100) <= 30) {
                $second = $this->pickCluster($clusters);
                $items = array_merge($items, array_slice($this->drawFromCluster($second), 0, 1));
            }

            shuffle($items);

            // Deduped in PHP against the same key the unique index uses. A
            // firstOrCreate would not do: visit_date is a cast date, stored
            // with a time component, so matching it against a plain date
            // string silently misses and the insert hits the index.
            $seen = [];
            foreach (array_slice($items, 0, $visitCount) as $item) {
                $date = now()->subDays(mt_rand(1, 200))->toDateString();
                $key = $item['listing_kind'].':'.$item['listing_id'].':'.$date;

                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                TouristVisit::create([
                    'visitor_token' => $token,
                    'listing_kind' => $item['listing_kind'],
                    'listing_id' => $item['listing_id'],
                    'visit_date' => $date,
                    'source' => 'qr_scan',
                ]);
            }
        }
    }

    private function seedExitSurveys(int $count, array $clusters): void
    {
        $residencyTypes = ['Local Resident', 'Domestic Tourist', 'Domestic Tourist', 'Foreign Tourist'];
        $visitorTypes = ['First-time Visitor', 'First-time Visitor', 'Returning Visitor', 'Regular / Local'];
        $travelPurposes = ['Leisure', 'Leisure', 'Leisure', 'Business', 'Visiting Friends/Family', 'Educational'];
        $origins = ['Metro Manila', 'Cebu City', 'General Santos', 'Cagayan de Oro', 'Zamboanga City', 'Iloilo City', 'Quezon City', 'Tokyo, Japan', 'Seoul, South Korea', 'Sydney, Australia', 'Singapore', 'Davao City (local)'];
        $comments = [
            'Wonderful trip overall, the itinerary suggestions matched what we wanted to do.',
            'Great destinations, would visit again with family.',
            'Enjoyed the beach hopping, though transport between spots could be smoother.',
            'The recommended spots were spot-on for our interests.',
            'Good value for the price, staff at the accredited establishments were friendly.',
            'Loved the nature spots, very relaxing experience.',
            'Would recommend to friends looking for a Davao itinerary.',
            null, null,
        ];

        for ($i = 0; $i < $count; $i++) {
            $cluster = $this->pickCluster($clusters);
            $items = $this->drawFromCluster($cluster);

            $survey = ExitSurvey::create([
                'submitted_at' => now()->subDays(mt_rand(1, 210)),
                'residency_type' => $residencyTypes[array_rand($residencyTypes)],
                'visitor_type' => $visitorTypes[array_rand($visitorTypes)],
                'origin' => $origins[array_rand($origins)],
                'travel_purpose' => $travelPurposes[array_rand($travelPurposes)],
                'actual_days_stayed' => mt_rand(1, 7),
                'overall_rating' => mt_rand(3, 5),
                'destination_relevant' => mt_rand(3, 5),
                'itinerary_useful' => mt_rand(3, 5),
                'attractions_quality' => mt_rand(3, 5),
                'accommodation_rating' => mt_rand(3, 5),
                'transport_rating' => mt_rand(2, 5),
                'would_recommend' => mt_rand(1, 100) <= 85 ? 'Yes' : 'No',
                'comments' => $comments[array_rand($comments)],
            ]);

            foreach ($items as $item) {
                $survey->visits()->create([
                    'listing_kind' => $item['listing_kind'],
                    'listing_id' => $item['listing_id'],
                ]);
            }

            $activities = collect($cluster['activities'])->shuffle()->take(mt_rand(1, 2));
            foreach ($activities as $activity) {
                $survey->activities()->create(['activity' => $activity]);
            }
        }
    }
}
