<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use App\Support\ListingFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Each listing page has its own filters (rating, amenities and its own extras), and each one narrows the results. */
class ListingFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function region(): int
    {
        return Region::firstOrCreate(['name' => 'Davao City'])->id;
    }

    private function stay(string $name, array $extra = []): Accommodation
    {
        return Accommodation::create($extra + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region(),
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 4.2, 'review_count' => 3, 'price_tier' => 'Mid-range', 'price_per_night' => 2000,
        ]);
    }

    private function amenity(string $kind, int $id, string $key): void
    {
        DB::table('listing_amenities')->insert(['listing_kind' => $kind, 'listing_id' => $id, 'amenity' => $key]);
    }

    private function names($response): array
    {
        $data = $response->original->getData();
        $list = $data['accommodations'] ?? $data['destinations'] ?? $data['restaurants'] ?? $data['packages'] ?? $data['souvenirCenters'] ?? $data['tourOperators'];

        return collect($list->items())->pluck('name')->sort()->values()->all();
    }

    public function test_stays_filter_by_rating_amenity_classification_and_price(): void
    {
        $a = $this->stay('Alpha Inn', ['rating' => 4.6, 'dot_classification' => 'Hotel', 'price_per_night' => 1200]);
        $b = $this->stay('Beta Inn', ['rating' => 3.4, 'dot_classification' => 'Resort', 'price_per_night' => 5000]);
        $this->amenity('accommodation', $a->id, 'pool');
        $this->amenity('accommodation', $a->id, 'wifi');
        $this->amenity('accommodation', $b->id, 'wifi');

        $this->assertSame(['Alpha Inn', 'Beta Inn'], $this->names($this->get(route('accommodations.index'))));
        $this->assertSame(['Alpha Inn'], $this->names($this->get(route('accommodations.index', ['rating_min' => '4']))));
        $this->assertSame(['Alpha Inn'], $this->names($this->get(route('accommodations.index', ['amenity' => ['pool']]))));
        $this->assertSame(['Alpha Inn', 'Beta Inn'], $this->names($this->get(route('accommodations.index', ['amenity' => ['wifi']]))));
        $this->assertSame(['Alpha Inn'], $this->names($this->get(route('accommodations.index', ['amenity' => ['wifi', 'pool']]))), 'every ticked amenity is required');
        $this->assertSame(['Beta Inn'], $this->names($this->get(route('accommodations.index', ['stay_class' => ['Resort']]))));
        $this->assertSame(['Beta Inn'], $this->names($this->get(route('accommodations.index', ['price_min' => 3000]))));
    }

    public function test_open_now_leaves_out_a_closed_place(): void
    {
        $this->stay('Open Inn');
        $closed = $this->stay('Shut Inn');
        $closed->forceFill(['operating_status' => 'temporarily_closed'])->save();

        $this->assertSame(['Open Inn', 'Shut Inn'], $this->names($this->get(route('accommodations.index'))));
        $this->assertSame(['Open Inn'], $this->names($this->get(route('accommodations.index', ['open_now' => 1]))));
    }

    public function test_destinations_filter_by_facility_and_rating(): void
    {
        $mk = fn ($n, $r) => Destination::create([
            'slug' => str($n)->slug()->toString(), 'name' => $n, 'location' => 'Davao City', 'region_id' => $this->region(),
            'type' => 'Nature & Leisure', 'is_accredited' => true, 'rating' => $r, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);
        $park = $mk('Park One', 4.8);
        $mk('Park Two', 3.1);
        DB::table('destination_tags')->insert(['destination_id' => $park->id, 'kind' => 'amenity', 'value' => 'Parking Area']);

        $this->assertSame(['Park One'], $this->names($this->get(route('destinations.index', ['amenity' => ['parking']]))));
        $this->assertSame(['Park One'], $this->names($this->get(route('destinations.index', ['rating_min' => '4.5']))));
        $this->assertSame(['Park One', 'Park Two'], $this->names($this->get(route('destinations.index', ['rating_min' => '2.5']))), 'a rating that is not a choice is ignored');
    }

    public function test_dining_packages_souvenirs_and_operators_each_have_their_own_extras(): void
    {
        foreach ([['Marina Grill', 'Seafood'], ['Cafe Uno', 'Coffee']] as [$n, $c]) {
            Restaurant::create(['slug' => str($n)->slug()->toString(), 'name' => $n, 'location' => 'Davao City', 'region_id' => $this->region(), 'cuisine_type' => $c, 'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range']);
        }
        $this->assertSame(['Cafe Uno'], $this->names($this->get(route('restaurants.index', ['cuisine' => ['Coffee']]))));

        foreach ([['Hike Day', 1, 'Easy'], ['Long Trek', 5, 'Challenging']] as [$n, $d, $diff]) {
            Package::create(['slug' => str($n)->slug()->toString(), 'name' => $n, 'location' => 'Davao City', 'region_id' => $this->region(), 'duration_label' => "$d days", 'duration_days' => $d, 'difficulty' => $diff, 'is_accredited' => true, 'status' => Package::STATUS_LIVE, 'price_per_pax' => 1000, 'price_tier' => 'Mid-range', 'rating' => 4, 'review_count' => 1, 'type' => 'Adventure']);
        }
        $this->assertSame(['Hike Day', 'Long Trek'], $this->names($this->get(route('packages.index'))));
        $this->assertSame(['Long Trek'], $this->names($this->get(route('packages.index', ['days' => ['4-up']]))));
        $this->assertSame(['Hike Day'], $this->names($this->get(route('packages.index', ['difficulty' => ['Easy']]))));
        $this->assertSame(['Hike Day', 'Long Trek'], $this->names($this->get(route('packages.index', ['days' => ['1', '4-up']]))), 'two lengths are either/or');

        SouvenirCenter::create(['slug' => 'aldev', 'name' => 'Aldev', 'location' => 'Davao City', 'region_id' => $this->region(), 'is_accredited' => true, 'rating' => 4.7, 'review_count' => 1]);
        SouvenirCenter::create(['slug' => 'low', 'name' => 'Low Shop', 'location' => 'Davao City', 'region_id' => $this->region(), 'is_accredited' => true, 'rating' => 3.0, 'review_count' => 1]);
        $this->assertSame(['Aldev'], $this->names($this->get(route('souvenir-centers.index', ['rating_min' => '4.5']))));

        foreach ([['Apo Guides', 'Nature & Adventure'], ['Heritage Tours', 'Cultural Heritage']] as [$n, $s]) {
            TourOperator::create(['slug' => str($n)->slug()->toString(), 'name' => $n, 'location' => 'Davao City', 'region_id' => $this->region(), 'specialization' => $s, 'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range']);
        }
        $this->assertSame(['Heritage Tours'], $this->names($this->get(route('tour-operators.index', ['spec' => ['Cultural Heritage']]))));
    }

    public function test_the_panel_shows_each_pages_own_groups_with_counts_and_chips_for_what_is_set(): void
    {
        $a = $this->stay('Alpha Inn');
        $this->amenity('accommodation', $a->id, 'pool');

        $html = $this->get(route('accommodations.index', ['amenity' => ['pool'], 'rating_min' => '4']))->assertOk()->getContent();
        $this->assertStringContainsString('Price per night', $html);
        $this->assertStringContainsString('Guest rating', $html);
        $this->assertStringContainsString('name="amenity[]" value="pool" checked', preg_replace('/\s+/', ' ', $html));
        $this->assertStringContainsString('Rated 4+', $html);
        $this->assertStringContainsString('Swimming Pool', $html);

        $dining = $this->get(route('restaurants.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Price per night', $dining);
        $this->assertStringContainsString('Guest rating', $dining);
    }

    public function test_odd_input_is_ignored_not_an_error(): void
    {
        $this->stay('Alpha Inn');

        $this->get(route('accommodations.index', ['amenity' => 'pool', 'rating_min' => ['x'], 'stay_class' => [['a']], 'amenity2' => 1, 'open_now' => 'maybe']))->assertOk();
        $this->get(route('accommodations.index', ['amenity' => ['nonsense', str_repeat('a', 500)]]))->assertOk();

        $q = Accommodation::query();
        ListingFilters::apply($q, Request::create('/', 'GET', ['amenity' => ['nonsense']]), 'accommodation');
        $this->assertSame(1, $q->count(), 'an amenity this kind never offers is dropped, not required');
    }
}
