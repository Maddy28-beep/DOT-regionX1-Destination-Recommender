<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Paging through a catalogue list must show every place once. Rows that tie on the chosen sort (every unrated place
 * has rating 0) used to come back in any order, so a page could repeat a place and another could be missed. And a
 * sample place must not invite visitors to scan a QR code it does not have.
 */
class StableListSortAndSampleReviewTest extends TestCase
{
    use RefreshDatabase;

    private function place(string $slug, bool $sample): Destination
    {
        $d = Destination::create([
            'slug' => $slug, 'name' => ucfirst($slug), 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Wildlife', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);
        AccreditationRecord::create([
            'listing_kind' => 'destination', 'listing_id' => $d->id, 'accreditation_number' => ($sample ? 'SAMPLE-' : 'DOT-R11-').$d->id,
            'status' => 'Active', 'issue_date' => now()->subYear(), 'expiration_date' => now()->addYear(), 'is_placeholder' => $sample,
        ]);

        return $d;
    }

    /** @return array<int, string> the SQL of the ordered list query on $table run while loading $url */
    private function pageQueries(string $url, string $table): array
    {
        $queries = [];
        DB::listen(function ($q) use (&$queries, $table) {
            if (preg_match('/^select (\*|"'.$table.'"\.\*).*? from "'.$table.'" where .+ order by /is', $q->sql) && stripos($q->sql, 'as aggregate') === false) {
                $queries[] = $q->sql;
            }
        });

        $this->get($url)->assertOk();

        return $queries;
    }

    public function test_every_list_sort_ends_on_the_id_so_ties_keep_one_order(): void
    {
        $this->place('real-one', false);
        // An empty list never runs its row query, so each kind needs one row.
        $base = ['location' => 'Davao City', 'region_id' => Region::first()->id, 'is_accredited' => true, 'rating' => 0, 'review_count' => 0];
        Accommodation::create($base + ['slug' => 'stay', 'name' => 'Stay', 'type' => 'Hotel']);
        Restaurant::create($base + ['slug' => 'eat', 'name' => 'Eat', 'cuisine_type' => 'Cafe']);
        SouvenirCenter::create($base + ['slug' => 'shop', 'name' => 'Shop']);
        TourOperator::create($base + ['slug' => 'tours', 'name' => 'Tours']);
        Package::create($base + ['slug' => 'trip', 'name' => 'Trip', 'status' => 'live', 'price_per_pax' => 1000, 'duration_label' => '1 Day', 'price_tier' => 'Budget-Friendly', 'type' => 'Adventure']);

        $lists = [
            'destinations.index' => ['destinations', ['', 'rating', 'name', 'nearest']],
            'accommodations.index' => ['accommodations', ['', 'rating', 'name', 'price_low', 'price_high']],
            'restaurants.index' => ['restaurants', ['', 'rating', 'name']],
            'packages.index' => ['packages', ['', 'rating', 'name', 'price_low', 'price_high']],
            'souvenir-centers.index' => ['souvenir_centers', ['', 'rating', 'name']],
            'tour-operators.index' => ['tour_operators', ['', 'rating', 'name']],
        ];

        foreach ($lists as $route => [$table, $sorts]) {
            foreach ($sorts as $sort) {
                $queries = $this->pageQueries(route($route, $sort === '' ? [] : ['sort' => $sort]), $table);

                $this->assertNotEmpty($queries, "{$route} sort '{$sort}' ran no ordered list query.");
                foreach ($queries as $sql) {
                    $this->assertMatchesRegularExpression('/order by .*"id" asc( limit \d+ offset \d+)?$/i', $sql, "{$route} sort '{$sort}' has no id tiebreaker: {$sql}");
                }
            }
        }
    }

    public function test_paging_through_places_that_tie_shows_each_one_once(): void
    {
        foreach (range(1, 12) as $i) {
            $this->place('tied-'.$i, $i > 6);
        }

        $seen = [];
        foreach ([1, 2] as $page) {
            preg_match_all('/class="dcard__name"[^>]*>\s*([^<]+?)\s*</', $this->get(route('destinations.index', ['page' => $page]))->assertOk()->getContent(), $m);
            $seen = array_merge($seen, $m[1]);
        }

        $this->assertCount(12, $seen);
        $this->assertCount(12, array_unique($seen), 'No place repeats across pages.');
    }

    public function test_a_sample_place_asks_for_a_qr_scan_like_any_other_place(): void
    {
        $sample = $this->place('sample-place', true);

        $html = $this->get(route('destinations.show', $sample))->assertOk()->getContent();

        $this->assertStringContainsString('Scan the DOT QR code displayed at the entrance to leave a review.', $html);
        $this->assertStringNotContainsString('takes no reviews', $html);
    }
}
