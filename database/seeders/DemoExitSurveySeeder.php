<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\Package;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use Illuminate\Database\Seeder;

/**
 * DEMO DATA. Simulated exit surveys so Apriori has transactions to mine while
 * real surveys are being collected. Every row is written with
 * data_source = 'demo'; none of it is observed tourist behaviour and it must
 * not be presented as such.
 *
 * What is grounded in a source, and what is an assumption:
 *
 * From the PSA Household Survey on Domestic Visitors 2022 (public-use file),
 * the 316 trips reported with a Region XI destination (July-December 2021):
 *   - destination province mix: Davao del Sur 97, Davao Oriental 87,
 *     Davao del Norte 48, Davao Occidental 43, Davao de Oro 41
 *     (Davao City sits under Davao del Sur in the PSA codes)
 *   - main purpose: pleasure/vacation 144, visiting friends or relatives 120,
 *     medical 21, business 20, other 11
 *   - 199 of 316 trips were overnight (63%); of those, 1 night 65, 2 nights 49,
 *     3 nights 28, 4-5 nights 21, 6+ nights 36
 *   - where overnight pleasure visitors stayed: with relatives/friends 43 of 68,
 *     a resort 17, homestay 3, hotel 2, second home 2, other 1
 *
 * Assumptions (the survey does not record places visited, only the town):
 *   - how many places a basket holds, which places are popular, how strongly
 *     places in the same cluster travel together, and the rating skew.
 *
 * The clusters below are SOFT tendencies: a listing that shares a cluster with
 * something already in the basket is more likely to be picked, never certain.
 * That keeps confidences spread out the way real visit data is, instead of the
 * 100% rules a fixed anchor-and-partner scheme produced.
 *
 * Re-runnable: replaces this seeder's own demo rows and never touches real
 * surveys.   php artisan db:seed --class=DemoExitSurveySeeder
 */
class DemoExitSurveySeeder extends Seeder
{
    private const COUNT = 300;

    /** Fixed seed: the same demo data every run, so figures quoted in a defense stay quotable. */
    private const SEED = 2022;

    /** PSA HSDV 2022, Region XI trips by destination province. */
    private const PROVINCE_TRIPS = [
        'Davao del Sur' => 97, 'Davao Oriental' => 87, 'Davao del Norte' => 48,
        'Davao Occidental' => 43, 'Davao de Oro' => 41,
    ];

    /**
     * Blend between the PSA province mix and where this catalogue actually has
     * listings. Kept low: outside Davao City the catalogue holds one to three
     * destinations per province, so a heavy PSA weight would pile most picks
     * onto those few places (at 0.5, Dahican Beach alone took 18% of
     * destination visits).
     */
    private const PSA_BLEND = 0.2;

    /** Region names in this database that PSA files under another province (Davao City under Davao del Sur, Samal under Davao del Norte). */
    private const PSA_BUCKET_ALIASES = ['Davao City' => 'Davao del Sur', 'Island Garden City of Samal' => 'Davao del Norte'];

    /** Purpose => weight (PSA trips). */
    private const PURPOSES = ['Leisure' => 144, 'Visiting Friends/Family' => 120, 'Medical' => 21, 'Business' => 20, 'Other' => 11];

    /** Soft affinity groups (listing names). */
    private const CLUSTERS = [
        'samal' => [
            'destination' => ['Samal Island'],
            'accommodation' => ['BlueJaz Beach Resort', 'Pearl Farm Beach Resort', 'Paradise Island Park & Beach Resort'],
            'restaurant' => ['Marina Tuna Restaurant'],
            'package' => ['Samal Island Hopping Day Tour'],
            'activities' => ['Beach & Island', 'Relaxation & Wellness'],
        ],
        'nature' => [
            'destination' => ['Eden Nature Park', 'Malagos Garden Resort', 'Philippine Eagle Center', 'Davao Crocodile Park Inc', 'Davao Crocodile Park'],
            'package' => ['Eden Nature Park Day Adventure'],
            'activities' => ['Nature & Adventure', 'Wildlife'],
        ],
        'city' => [
            'destination' => ["People's Park"],
            'souvenir_center' => ['Aldevinco Shopping Center', 'Davao Local Products & Souvenir Center', 'Kadayawan Souvenir Shop'],
            'restaurant' => ['Kublai Khan Mongolian Restaurant', 'Blue Post Boiling Crabs & Shrimp'],
            'package' => ['Davao City Cultural Heritage Tour'],
            'activities' => ['Cultural Heritage', 'Shopping & Souvenirs', 'Food Tourism'],
        ],
        'adventure' => [
            'destination' => ['Mount Apo Natural Park', 'Dahican Beach'],
            'package' => ['Mount Apo 3-Day Summit Trek', 'Dahican Beach Surf & Chill Package'],
            'activities' => ['Hiking & Trekking', 'Beach & Island'],
        ],
    ];

    /** Extra popularity for well-known places; everything else weighs 1. */
    private const POPULAR_WEIGHT = 8;

    /** How much one shared cluster raises a listing's chance, per cluster-mate already in the basket. */
    private const AFFINITY = 2.5;

    /** @var array<string, array<int, array{id: int, name: string, bucket: ?string, weight: float}>> */
    private array $universe = [];

    /** @var array<string, array<int, string>> listing key => cluster names */
    private array $clusterOf = [];

    public function run(): void
    {
        mt_srand(self::SEED);

        $this->clearOwnRows();
        $this->buildUniverse();

        for ($i = 0; $i < self::COUNT; $i++) {
            $this->seedOne();
        }

        mt_srand(); // hand randomness back to whatever seeds next
    }

    private function clearOwnRows(): void
    {
        // visits and activities go with their survey (cascade).
        ExitSurvey::where('data_source', 'demo')->delete();
    }

    /* ------------------------------------------------------------------ */
    /* The pool of listings and their weights                              */
    /* ------------------------------------------------------------------ */

    private function buildUniverse(): void
    {
        $regionName = Region::pluck('name', 'id');

        $kinds = [
            'destination' => Destination::class,
            'accommodation' => Accommodation::class,
            'restaurant' => Restaurant::class,
            'souvenir_center' => SouvenirCenter::class,
            'package' => Package::class,
        ];

        $popular = [];
        foreach (self::CLUSTERS as $cluster => $members) {
            foreach ($members as $kind => $names) {
                if ($kind === 'activities') {
                    continue;
                }
                foreach ($names as $name) {
                    $popular[$kind][$name] = true;
                    $this->clusterOf[$kind.':'.$name][] = $cluster;
                }
            }
        }

        foreach ($kinds as $kind => $model) {
            $rows = [];
            foreach ($model::query()->get(['id', 'name', 'region_id']) as $row) {
                $bucket = $regionName[$row->region_id] ?? null;
                $bucket = self::PSA_BUCKET_ALIASES[$bucket] ?? $bucket;
                $rows[] = [
                    'id' => $row->id,
                    'name' => $row->name,
                    'bucket' => $bucket,
                    'weight' => isset($popular[$kind][$row->name]) ? (float) self::POPULAR_WEIGHT : 1.0,
                ];
            }
            $this->universe[$kind] = $this->applyProvinceMix($rows);
        }
    }

    /**
     * Scale weights so each province's total share of picks moves toward the
     * PSA mix (blended with the catalogue's own coverage, so a province with a
     * single listing does not dominate every basket).
     *
     * @param  array<int, array{id: int, name: string, bucket: ?string, weight: float}>  $rows
     */
    private function applyProvinceMix(array $rows): array
    {
        $totalTrips = array_sum(self::PROVINCE_TRIPS);
        $bucketWeight = [];
        $grand = 0.0;
        foreach ($rows as $r) {
            $bucketWeight[$r['bucket']] = ($bucketWeight[$r['bucket']] ?? 0) + $r['weight'];
            $grand += $r['weight'];
        }

        foreach ($rows as &$r) {
            $own = $bucketWeight[$r['bucket']] / max($grand, 1);
            $psa = isset(self::PROVINCE_TRIPS[$r['bucket']]) ? self::PROVINCE_TRIPS[$r['bucket']] / $totalTrips : $own;
            $target = self::PSA_BLEND * $psa + (1 - self::PSA_BLEND) * $own;
            // this listing's share of its province, times the province's target share
            $r['weight'] = ($r['weight'] / $bucketWeight[$r['bucket']]) * $target;
        }
        unset($r);

        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /* One survey                                                          */
    /* ------------------------------------------------------------------ */

    private function seedOne(): void
    {
        $purpose = $this->weighted(self::PURPOSES);
        $basket = $this->drawBasket($purpose);

        $overnight = mt_rand(1, 100) <= 63;
        $nights = $overnight ? $this->nights() : 0;
        $overall = $this->weighted([1 => 3, 2 => 7, 3 => 15, 4 => 35, 5 => 40]);

        $survey = ExitSurvey::create([
            'submitted_at' => now()->subDays(mt_rand(1, 210)),
            'residency_type' => $this->weighted(['Domestic Tourist' => 75, 'Local Resident' => 15, 'Foreign Tourist' => 10]),
            'visitor_type' => $purpose === 'Visiting Friends/Family'
                ? $this->weighted(['Returning Visitor' => 60, 'First-time Visitor' => 25, 'Regular / Local' => 15])
                : $this->weighted(['First-time Visitor' => 55, 'Returning Visitor' => 30, 'Regular / Local' => 15]),
            'origin' => $this->weighted([
                'Metro Manila' => 18, 'Cebu City' => 10, 'General Santos' => 9, 'Cagayan de Oro' => 8,
                'Zamboanga City' => 5, 'Iloilo City' => 4, 'Quezon City' => 8, 'Davao City (local)' => 14,
                'Tokyo, Japan' => 3, 'Seoul, South Korea' => 3, 'Sydney, Australia' => 2, 'Singapore' => 2,
            ]),
            'travel_purpose' => $purpose,
            'actual_days_stayed' => $overnight ? $nights + 1 : 1,
            'overall_rating' => $overall,
            'destination_relevant' => $this->near($overall),
            'itinerary_useful' => $this->near($overall),
            'attractions_quality' => $this->near($overall),
            'accommodation_rating' => $this->near($overall),
            'transport_rating' => $this->near($overall - 1),
            'would_recommend' => mt_rand(1, 100) <= ($overall >= 4 ? 92 : 40) ? 'Yes' : 'No',
            'comments' => null,
            'data_source' => 'demo',
        ]);

        foreach ($basket as [$kind, $id]) {
            $survey->visits()->create(['listing_kind' => $kind, 'listing_id' => $id]);
        }

        foreach ($this->activitiesFor($basket) as $activity) {
            $survey->activities()->create(['activity' => $activity]);
        }
    }

    /**
     * What this traveller visited, by purpose.
     *
     * @return array<int, array{0: string, 1: int}>  [listing_kind, listing_id]
     */
    private function drawBasket(string $purpose): array
    {
        $leisure = $purpose === 'Leisure';
        $vfr = $purpose === 'Visiting Friends/Family';

        $destinations = match (true) {
            $leisure => $this->weighted([1 => 20, 2 => 35, 3 => 28, 4 => 13, 5 => 4]),
            $vfr => $this->weighted([0 => 35, 1 => 35, 2 => 20, 3 => 10]),
            default => $this->weighted([0 => 55, 1 => 35, 2 => 10]),
        };

        // ~one in three overnight pleasure visitors paid for a stay (PSA: 22 of
        // 68 at a resort, hotel or homestay); the rest were with relatives.
        $accommodation = $leisure && mt_rand(1, 100) <= 20;
        $business = $purpose === 'Business' && mt_rand(1, 100) <= 30;

        $plan = array_filter([
            'destination' => $destinations,
            'accommodation' => ($accommodation || $business) ? 1 : 0,
            'restaurant' => mt_rand(1, 100) <= ($leisure ? 35 : 25) ? 1 : 0,
            'souvenir_center' => mt_rand(1, 100) <= ($vfr ? 20 : 14) ? 1 : 0,
            'package' => ($leisure && mt_rand(1, 100) <= 3) ? 1 : 0,
        ]);

        if ($plan === []) {
            $plan = ['restaurant' => 1];
        }

        $basket = [];
        $picked = [];

        // destinations first so the cluster they belong to can pull the rest
        foreach (['destination', 'accommodation', 'restaurant', 'souvenir_center', 'package'] as $kind) {
            for ($n = 0; $n < ($plan[$kind] ?? 0); $n++) {
                $choice = $this->pick($kind, $picked);
                if ($choice === null) {
                    continue;
                }
                $picked[$kind.':'.$choice['name']] = true;
                $basket[] = [$kind, $choice['id']];
            }
        }

        return $basket;
    }

    /** Weighted draw of one listing of a kind, not already in the basket, favouring cluster-mates. */
    private function pick(string $kind, array $picked): ?array
    {
        $inBasketClusters = [];
        foreach (array_keys($picked) as $key) {
            foreach ($this->clusterOf[$key] ?? [] as $cluster) {
                $inBasketClusters[$cluster] = ($inBasketClusters[$cluster] ?? 0) + 1;
            }
        }

        $weights = [];
        foreach ($this->universe[$kind] ?? [] as $i => $row) {
            if (isset($picked[$kind.':'.$row['name']])) {
                continue;
            }
            $shared = 0;
            foreach ($this->clusterOf[$kind.':'.$row['name']] ?? [] as $cluster) {
                $shared += $inBasketClusters[$cluster] ?? 0;
            }
            $weights[$i] = $row['weight'] * (1 + self::AFFINITY * $shared);
        }

        if ($weights === []) {
            return null;
        }

        return $this->universe[$kind][$this->weighted($weights)];
    }

    /** Activities implied by the clusters of what was visited, else a plausible leisure pair. */
    private function activitiesFor(array $basket): array
    {
        $names = [];
        foreach ($basket as [$kind, $id]) {
            foreach ($this->universe[$kind] as $row) {
                if ($row['id'] === $id) {
                    foreach ($this->clusterOf[$kind.':'.$row['name']] ?? [] as $cluster) {
                        $names = array_merge($names, self::CLUSTERS[$cluster]['activities']);
                    }
                }
            }
        }

        $names = array_values(array_unique($names));
        if ($names === []) {
            $names = ['Food Tourism', 'Cultural Heritage', 'Nature & Adventure'];
        }
        shuffle($names);

        return array_slice($names, 0, mt_rand(1, min(2, count($names))));
    }

    /** Nights stayed, PSA Region XI overnight trips: 1 -> 65, 2 -> 49, 3 -> 28, 4-5 -> 21, 6+ -> 36. */
    private function nights(): int
    {
        return match ($this->weighted(['one' => 65, 'two' => 49, 'three' => 28, 'four_five' => 21, 'six_plus' => 36])) {
            'one' => 1,
            'two' => 2,
            'three' => 3,
            'four_five' => mt_rand(4, 5),
            default => mt_rand(6, 7),
        };
    }

    /** A sub-rating within one point of the overall, clamped to 1-5. */
    private function near(int $overall): int
    {
        return max(1, min(5, $overall + mt_rand(-1, 1)));
    }

    /**
     * @param  array<int|string, int|float>  $weights  key => weight
     * @return int|string  the chosen key
     */
    private function weighted(array $weights): int|string
    {
        $total = array_sum($weights);
        $roll = mt_rand() / mt_getrandmax() * $total;
        $cursor = 0.0;
        foreach ($weights as $key => $weight) {
            $cursor += $weight;
            if ($roll <= $cursor) {
                return $key;
            }
        }

        return array_key_last($weights);
    }
}
