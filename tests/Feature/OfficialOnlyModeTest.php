<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use App\Models\TouristPreference;
use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Support\OfficialOnlyMode;
use App\Support\SampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * itinerary.official_only (env ITINERARY_OFFICIAL_ONLY, default false): when on, nothing without a genuine DOT record can be
 * recommended or planned; when off (the default) sample and unverified places take part exactly as before.
 */
class OfficialOnlyModeTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function region(): int
    {
        return Region::firstOrCreate(['name' => 'Davao City'])->id;
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $class */
    private function listing(string $class, string $kind, string $name, ?string $provenance, array $extra = [])
    {
        $row = $class::create($extra + [
            'slug' => str($name)->slug()->toString().'-'.(++$this->n), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region(),
            'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => 7.10 + $this->n / 1000, 'longitude' => 125.50,
        ]);

        if ($provenance !== null) {
            AccreditationRecord::create([
                'listing_kind' => $kind, 'listing_id' => $row->id, 'accreditation_number' => ($provenance === 'sample' ? 'SAMPLE-' : 'DOT-R11-').$kind.'-'.$row->id,
                'status' => 'Active', 'issue_date' => now()->subMonth(), 'expiration_date' => now()->addYear(), 'is_placeholder' => $provenance === 'sample',
            ]);
        }

        return $row;
    }

    private function destinations(): array
    {
        $extra = ['type' => 'Nature & Leisure', 'hours' => '8:00 AM–5:00 PM', 'distance_km' => 5];

        return [
            'official' => $this->listing(Destination::class, 'destination', 'Official Falls', 'official', $extra),
            'sample' => $this->listing(Destination::class, 'destination', 'Sample Falls', 'sample', $extra),
            'unverified' => $this->listing(Destination::class, 'destination', 'Unverified Falls', null, $extra),
        ];
    }

    private function preference(): TouristPreference
    {
        return TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'far', 'start_date' => now()->addDays(10)->toDateString(), 'arrival_time' => '08:00',
        ]);
    }

    public function test_the_flag_is_off_by_default(): void
    {
        $this->assertFalse(config('itinerary.official_only'));
    }

    public function test_off_every_provenance_is_a_candidate_and_on_only_the_official_one_is(): void
    {
        $d = $this->destinations();
        $pref = $this->preference();

        $ids = fn () => app(ContentBasedRecommendationService::class)->rank($pref)->pluck('destination.id')->all();

        config(['itinerary.official_only' => false]);
        $this->assertEqualsCanonicalizing([$d['official']->id, $d['sample']->id, $d['unverified']->id], $ids());

        config(['itinerary.official_only' => true]);
        $this->assertSame([$d['official']->id], $ids());
    }

    public function test_on_a_generated_trip_holds_no_sample_place_stay_meal_or_shop(): void
    {
        $this->destinations();
        $stays = ['official' => $this->listing(Accommodation::class, 'accommodation', 'Official Inn', 'official', ['type' => 'Hotel', 'price_per_night' => 2500]),
            'sample' => $this->listing(Accommodation::class, 'accommodation', 'Sample Inn', 'sample', ['type' => 'Hotel', 'price_per_night' => 2500])];
        $meals = ['official' => $this->listing(Restaurant::class, 'restaurant', 'Official Kitchen', 'official', ['cuisine' => 'Filipino']),
            'sample' => $this->listing(Restaurant::class, 'restaurant', 'Sample Kitchen', 'sample', ['cuisine' => 'Filipino'])];
        $shops = ['official' => $this->listing(SouvenirCenter::class, 'souvenir_center', 'Official Shop', 'official'),
            'sample' => $this->listing(SouvenirCenter::class, 'souvenir_center', 'Sample Shop', 'sample')];

        config(['itinerary.official_only' => true]);
        $trip = app(ItineraryGenerationService::class)->generate($this->preference()->load('activities', 'amenities'))->fresh('items');

        $sd = app(SampleData::class);
        $sd->flush();
        foreach ($trip->items as $item) {
            foreach ([['destination_id', 'destination'], ['accommodation_id', 'accommodation'], ['restaurant_id', 'restaurant'], ['souvenir_center_id', 'souvenir_center']] as [$col, $kind]) {
                if ($item->{$col}) {
                    $this->assertSame(SampleData::OFFICIAL, $sd->statusOf($kind, (int) $item->{$col}), "{$kind} #{$item->{$col}} on the trip must be official");
                }
            }
        }
        $this->assertNotEmpty($trip->items->whereNotNull('destination_id'), 'the official place is still planned');
        $this->assertNotContains($stays['sample']->id, $trip->items->pluck('accommodation_id')->all());
        $this->assertNotContains($meals['sample']->id, $trip->items->pluck('restaurant_id')->all());
        $this->assertNotContains($shops['sample']->id, $trip->items->pluck('souvenir_center_id')->all());
    }

    public function test_the_helper_narrows_any_kind_of_listing_only_when_on(): void
    {
        $o = $this->listing(TourOperator::class, 'tour_operator', 'Official Tours', 'official');
        $s = $this->listing(TourOperator::class, 'tour_operator', 'Sample Tours', 'sample');

        config(['itinerary.official_only' => false]);
        $this->assertCount(2, OfficialOnlyMode::apply(TourOperator::query())->get());

        config(['itinerary.official_only' => true]);
        $this->assertSame([$o->id], OfficialOnlyMode::apply(TourOperator::query())->pluck('id')->all());
        $this->assertNotContains($s->id, OfficialOnlyMode::apply(TourOperator::query())->pluck('id')->all());
    }
}
