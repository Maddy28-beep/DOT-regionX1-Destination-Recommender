<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use App\Services\Recommendation\ThemeDayGrouper;
use App\Support\OmissionReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

/** The recorder's note-taking is made to fail on purpose. Real schedule code is untouched; only the note-taking methods throw. */
class NoteFailingBuilder extends ItineraryScheduleBuilder
{
    /** record = every note throws; key = the build's identity cannot be worked out. */
    public static string $mode = 'record';

    protected function recordAttempt(int|string $destinationId, int $day, string $cause, ?int $minutes = null, ?int $limit = null, bool $fresh = false): void
    {
        if (self::$mode === 'record') {
            throw new \RuntimeException('a note could not be taken');
        }
        parent::recordAttempt($destinationId, $day, $cause, $minutes, $limit, $fresh);
    }

    protected function attemptsKey(array $sequence, array $stayByNight): string
    {
        if (self::$mode === 'key') {
            throw new \RuntimeException('the build could not be identified');
        }

        return parent::attemptsKey($sequence, $stayByNight);
    }
}

/** A schedule that fails for a reason of its own: nothing to do with the notes. */
class FailingScheduleBuilder extends ItineraryScheduleBuilder
{
    public function build(Itinerary $itinerary, array $sequence, TouristPreference $preference, array $dayCapacities, array $origin, ?array $stay): void
    {
        throw new \DomainException('the schedule failed');
    }
}

/** Watches which stays each build is given and which build the notes are looked up for. */
class LookupSpyBuilder extends ItineraryScheduleBuilder
{
    /** @var array<int, array<int, int|null>> night => stay id, one entry per build */
    public static array $builds = [];

    /** @var array<int, array{stays: array<int, int|null>, found: array|null}> */
    public static array $lookups = [];

    public static int $call = 0;

    public function build(Itinerary $itinerary, array $sequence, TouristPreference $preference, array $dayCapacities, array $origin, ?array $stay): void
    {
        self::$builds[] = array_map(fn ($s) => $s['listing']->id ?? null, $stay ?? []);
        parent::build($itinerary, $sequence, $preference, $dayCapacities, $origin, $stay);

        // The first build "loses" the last two days' stops, as the real one does when they cannot be reached.
        if (self::$call++ === 0) {
            $itinerary->items()->whereIn('day_number', [3, 4])->where('kind', 'activity')->delete();
        }
    }

    public function recordedAttempts(array $sequence, ?array $stay, array $dayCapacities): ?array
    {
        $found = parent::recordedAttempts($sequence, $stay, $dayCapacities);
        self::$lookups[] = ['stays' => array_map(fn ($s) => $s['listing']->id ?? null, $stay ?? []), 'found' => $found];

        return $found;
    }
}

/**
 * Taking a note for the page can never stop a trip being built, a place whose note could not be taken is told in the neutral
 * words, and the notes shown are those of the build whose rows were kept. Also: the reasons "closed that day" and "no
 * sightseeing time that day" come out of the real schedule, and a one-day trip that left nothing out stores nothing.
 */
class ClarityRecorderIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- fixtures -------------------------------------------------------------------------------------------

    private function place(string $name, string $type, float $lat, float $lng, string $region = 'Davao City', float $rating = 4.5, ?string $hours = null): Destination
    {
        return Destination::create(['slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => $region, 'region_id' => Region::firstOrCreate(['name' => $region])->id, 'type' => $type,
            'is_accredited' => true, 'rating' => $rating, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng, 'hours' => $hours]);
    }

    private function inn(): Accommodation
    {
        return Accommodation::create(['slug' => 'sonreir', 'name' => 'Sonreir Apartelle and Inn', 'location' => 'Davao', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range', 'latitude' => 7.176, 'longitude' => 125.4693]);
    }

    private function preference(int $days, string $range, string $arrival, string $start = '2026-10-28'): TouristPreference
    {
        $preference = TouristPreference::create(['travel_days' => $days, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => $range,
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Cebu City', 'start_date' => $start, 'arrival_time' => $arrival]);
        PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => 'Beach & Island']);

        return $preference;
    }

    private function generate(TouristPreference $preference, ?float $lat = null, ?float $lng = null): Itinerary
    {
        return app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'), $lat, $lng)->fresh();
    }

    /** Four far-flung stops (ArrivalRuleTest): in two days three fit and one is turned away. */
    private function farFlungCatalogue(): void
    {
        foreach ([['Samal Island', 'Beach & Leisure', 7.1553, 125.708, 'Island Garden City of Samal', 4.4], ['Mount Apo Natural Park', 'Adventure & Hiking', 6.9847, 125.2725, 'Davao del Sur', 4.3],
            ['Bemwa Farm Fresh Inc.', 'Farm Tourism', 7.481914, 125.259499, 'Davao City', 4.2], ['Dahican Beach', 'Beach & Surfing', 6.9553, 126.2758, 'Davao Oriental', 4.1]] as [$name, $type, $lat, $lng, $region, $rating]) {
            $this->place($name, $type, $lat, $lng, $region, $rating);
        }
        $this->inn();

        $this->app->bind(ThemeDayGrouper::class, fn () => new class extends ThemeDayGrouper
        {
            public function __construct() {}

            public function group(array $sequence, array $dayCapacities, array $origin): ?array
            {
                $by = collect($sequence)->keyBy(fn ($e) => $e['row']['destination']->name);

                return ['sequence' => array_values(array_filter(array_map(fn ($n) => $by[$n] ?? null, ['Mount Apo Natural Park', 'Bemwa Farm Fresh Inc.', 'Samal Island', 'Dahican Beach']))), 'days' => [],
                    'summary' => ['regrouped' => true, 'mean_similarity_before' => 0.0, 'mean_similarity_after' => 0.0, 'distance_before_km' => 0.0, 'distance_after_km' => 0.0]];
            }
        });
    }

    /** @return list<array<string, mixed>> every schedule row, without the ids and timestamps that differ between two itineraries */
    private function rows(Itinerary $trip): array
    {
        return $trip->items()->orderBy('day_number')->orderBy('sort_order')->get()
            ->map(fn ($r) => $r->only(['day_number', 'sort_order', 'slot', 'kind', 'title', 'starts_at', 'ends_at', 'distance_km', 'travel_min_minutes', 'travel_max_minutes',
                'destination_id', 'accommodation_id', 'restaurant_id', 'souvenir_center_id', 'note']))->all();
    }

    private function unplaced(Itinerary $trip): array
    {
        return collect(data_get($trip->stay_plan, 'notices', []))->where('type', 'unplaced_destination')->values()->all();
    }

    // ---- M1: a failing note never stops a trip ---------------------------------------------------------------

    public function test_a_note_that_cannot_be_taken_does_not_stop_the_trip_or_change_one_row_of_it(): void
    {
        $this->farFlungCatalogue();

        $normal = $this->generate($this->preference(2, 'far', '06:00'));
        $this->assertNotEmpty($this->unplaced($normal), 'the fixture leaves a place out');
        $this->assertNotSame([], $this->unplaced($normal)[0]['attempts'], 'and a normal build says why');

        Exceptions::fake();
        NoteFailingBuilder::$mode = 'record';
        $this->app->bind(ItineraryScheduleBuilder::class, NoteFailingBuilder::class);
        $this->app->forgetInstance(ItineraryGenerationService::class);

        $failing = $this->generate($this->preference(2, 'far', '06:00'));

        $this->assertSame($this->rows($normal), $this->rows($failing), 'every schedule row is exactly what a normal build writes');
        $notices = $this->unplaced($failing);
        $this->assertCount(count($this->unplaced($normal)), $notices, 'the same places are still named');
        foreach ($notices as $notice) {
            $this->assertSame('not_recorded', $notice['reason']);
            $this->assertSame([], $notice['attempts']);
            $this->assertSame('It was not added to your plan.', OmissionReason::sentence($notice));
        }
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_a_build_that_cannot_be_identified_gives_the_neutral_words_and_the_same_trip(): void
    {
        $this->farFlungCatalogue();
        $normal = $this->generate($this->preference(2, 'far', '06:00'));

        Exceptions::fake();
        NoteFailingBuilder::$mode = 'key';
        $this->app->bind(ItineraryScheduleBuilder::class, NoteFailingBuilder::class);
        $this->app->forgetInstance(ItineraryGenerationService::class);
        $failing = $this->generate($this->preference(2, 'far', '06:00'));

        $this->assertSame($this->rows($normal), $this->rows($failing));
        $this->assertNotEmpty($this->unplaced($failing));
        foreach ($this->unplaced($failing) as $notice) {
            $this->assertSame('It was not added to your plan.', OmissionReason::sentence($notice), 'neither the store nor the lookup can say why');
        }
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_a_failure_of_the_schedule_itself_is_not_swallowed(): void
    {
        $this->farFlungCatalogue();
        $this->app->bind(ItineraryScheduleBuilder::class, FailingScheduleBuilder::class);
        $this->app->forgetInstance(ItineraryGenerationService::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('the schedule failed');

        $this->generate($this->preference(2, 'far', '06:00'));
    }

    // ---- M2: the notes shown belong to the build whose rows were kept -----------------------------------------

    private function settleCatalogue(): array
    {
        config(['dot.accreditation_test_grace_until' => null, 'itinerary.stays.force_mode' => null, 'itinerary.stays.transfer_penalty_minutes.far' => 30]);
        $region = Region::firstOrCreate(['name' => 'Davao City'])->id;
        foreach ([[3, 4.9], [5, 4.8], [7, 4.7], [9, 4.6], [100, 4.5], [102, 4.4], [104, 4.3], [106, 4.2]] as $i => [$km, $rating]) {
            Destination::create(['slug' => 'place-'.($i + 1), 'name' => 'Place '.($i + 1), 'location' => 'Davao City', 'region_id' => $region, 'type' => 'Nature & Leisure', 'is_accredited' => true,
                'rating' => $rating, 'review_count' => 20, 'latitude' => 7.07 + $km / 111.2, 'longitude' => 125.61, 'distance_km' => $km]);
        }
        foreach ([['Near Stay', 6], ['Far Stay', 103]] as [$name, $km]) {
            $s = Accommodation::create(['slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region, 'type' => 'Hotel', 'is_accredited' => true,
                'rating' => 4, 'review_count' => 10, 'latitude' => 7.07 + $km / 111.2, 'longitude' => 125.61]);
            AccreditationRecord::create(['listing_kind' => 'accommodation', 'listing_id' => $s->id, 'accreditation_number' => 'DOT-T-'.$s->id, 'issue_date' => '2024-01-01', 'expiration_date' => '2028-12-31', 'status' => 'Active']);
        }

        return TouristPreference::create(['travel_days' => 4, 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'far', 'travel_purpose' => 'Leisure',
            'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Davao City', 'start_date' => '2026-11-13', 'arrival_time' => '08:00', 'origin_lat' => 7.07, 'origin_lng' => 125.61, 'origin_label' => 'Start'])->toArray();
    }

    /** @return array<int, int|null> night => the stay the saved rows sleep at */
    private function savedNights(Itinerary $trip): array
    {
        return $trip->items()->where('kind', 'overnight')->orderBy('day_number')->get()->mapWithKeys(fn ($r) => [(int) $r->day_number => $r->accommodation_id])->all();
    }

    public function test_the_notes_are_those_of_the_build_that_was_kept_not_the_last_one_made(): void
    {
        $attributes = $this->settleCatalogue();
        LookupSpyBuilder::$builds = [];
        LookupSpyBuilder::$lookups = [];
        LookupSpyBuilder::$call = 0;
        $this->app->bind(ItineraryScheduleBuilder::class, LookupSpyBuilder::class);
        $this->app->forgetInstance(ItineraryGenerationService::class);

        $trip = $this->generate(TouristPreference::findOrFail($attributes['id']), 7.07, 125.61);

        // The stays were settled and the trip built again with them, and the guarded rebuild kept the FIRST build (the saved rows
        // still sleep where the first build did), so the build made last is not the one on the page.
        $builds = LookupSpyBuilder::$builds;
        $this->assertGreaterThanOrEqual(2, count($builds));
        $saved = $this->savedNights($trip);
        $this->assertSame($builds[0], $saved, 'the saved rows are those of the first build');
        $this->assertNotSame(end($builds), $saved, 'the build made last was refused');

        $lookup = end(LookupSpyBuilder::$lookups);
        $this->assertSame($saved, $lookup['stays'], 'the notes were looked up for the stays of the kept build, not of the refused one');
        $this->assertNotNull($lookup['found'], 'and that build had been recorded');

        $notices = $this->unplaced($trip);
        $this->assertNotEmpty($notices, 'the far places cannot all be placed');
        foreach ($notices as $notice) {
            $this->assertNotSame([], $notice['attempts'], $notice['name']);
            $this->assertSame($lookup['found'][$notice['destination_id']], $notice['attempts'], $notice['name'].': exactly what was recorded for the kept build');
        }
    }

    // ---- M2: reasons that come out of the real schedule ---------------------------------------------------------

    public function test_a_place_that_is_closed_on_every_day_of_the_trip_says_so(): void
    {
        // 2026-11-16 is a Monday: the trip runs Monday and Tuesday, and the place is shut on both.
        $this->place('Shut Falls', 'Beach & Leisure', 7.07, 125.61, 'Davao City', 4.9, 'Closed Mondays; Closed Tuesdays');
        $this->place('Open Park One', 'Beach & Leisure', 7.08, 125.62, 'Davao City', 4.5);
        $this->place('Open Park Two', 'Beach & Leisure', 7.09, 125.63, 'Davao City', 4.4);
        $this->inn();

        $trip = $this->generate($this->preference(2, 'moderate', '08:00', '2026-11-16'));

        $placed = $trip->items()->where('kind', 'activity')->whereNotNull('destination_id')->with('destination')->get()->pluck('destination.name')->all();
        $this->assertNotContains('Shut Falls', $placed);
        $notice = collect($this->unplaced($trip))->firstWhere('name', 'Shut Falls');
        $this->assertNotNull($notice, 'it is named, not dropped silently');
        $this->assertSame('closed_that_day', $notice['reason']);
        // Day 1: seen shut while looking ahead, and the day filled up with other places. Day 2 (the last): at the front of the queue,
        // shut even starting the day fresh, so it is set aside.
        $this->assertSame([
            ['day' => 1, 'cause' => 'closed_that_day', 'minutes' => null, 'limit' => null, 'fresh' => false],
            ['day' => 1, 'cause' => 'day_full', 'minutes' => null, 'limit' => 2, 'fresh' => false],
            ['day' => 2, 'cause' => 'closed_that_day', 'minutes' => null, 'limit' => null, 'fresh' => true],
        ], $notice['attempts']);
        $this->assertSame('It is closed on day 1; day 1 already held 2 places; it is closed on day 2, even starting the day fresh.', OmissionReason::sentence($notice));
    }

    public function test_a_day_with_no_sightseeing_time_after_a_late_arrival_is_recorded_as_such(): void
    {
        foreach ([['Zoo', 7.12, 125.64], ['Museum', 7.07, 125.61], ['Park', 7.15, 125.65]] as [$name, $lat, $lng]) {
            $this->place($name, 'Beach & Leisure', $lat, $lng);
        }
        $this->inn();
        $preference = $this->preference(2, 'moderate', '22:00')->load('activities', 'amenities');
        $generator = app(ItineraryGenerationService::class);
        $capacities = (new \ReflectionMethod($generator, 'dayCapacities'))->invoke($generator, $preference, 2);
        $this->assertSame([], $capacities[1], 'a 22:00 arrival leaves day 1 with no sightseeing slot');
        $this->assertCount(2, $capacities[2]);

        $stops = Destination::orderBy('id')->get()->map(fn ($d) => ['row' => ['destination' => $d->load('region')], 'distance_km' => null])->all();
        $itinerary = Itinerary::create(['preference_id' => $preference->id, 'total_days' => 2, 'generated_at' => now()]);
        $builder = app(ItineraryScheduleBuilder::class);
        $builder->forgetRecordedAttempts();
        $builder->build($itinerary, $stops, $preference, $capacities, ['lat' => 7.07, 'lng' => 125.61, 'label' => 'Start', 'region' => null], null);

        $placed = $itinerary->items()->where('kind', 'activity')->whereNotNull('destination_id')->pluck('destination_id')->all();
        $left = collect($stops)->map(fn ($s) => $s['row']['destination']->id)->diff($placed)->values();
        $this->assertCount(1, $left, 'two fit on day 2, the third has nowhere to go');

        $attempts = $builder->recordedAttempts($stops, null, $capacities)[$left[0]];
        $this->assertContains(['day' => 1, 'cause' => 'no_sightseeing_time_that_day', 'minutes' => null, 'limit' => 0, 'fresh' => false], $attempts);
        $this->assertContains(['day' => 2, 'cause' => 'day_full', 'minutes' => null, 'limit' => 2, 'fresh' => false], $attempts);
        $this->assertSame('Day 1 had no sightseeing time after your arrival; day 2 already held 2 places.', OmissionReason::sentence(['attempts' => $attempts]));
    }

    // ---- M2: a one-day trip that left nothing out stores nothing --------------------------------------------------

    public function test_a_one_day_trip_with_nothing_left_out_stores_no_stay_plan(): void
    {
        $this->place('Centre Wildlife Spot', 'Wildlife', 7.0731, 125.6128);
        $this->inn();
        $preference = $this->preference(1, 'moderate', '08:00');
        PreferenceActivity::where('preference_id', $preference->id)->update(['activity' => 'Wildlife']);

        $trip = $this->generate($preference);

        $this->assertSame(1, $trip->items()->where('kind', 'activity')->whereNotNull('destination_id')->count(), 'the one place is on the plan');
        $this->assertNull($trip->stay_plan, 'nothing was left out, so nothing is stored');
    }
}
