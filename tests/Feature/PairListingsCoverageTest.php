<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyVisit;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Services\Recommendation\AprioriService;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

/**
 * surveys:generate-demo-samples --pair-listings gives each eligible destination a restaurant and a souvenir rule the
 * itinerary builder can actually use, as its own simulated batch. It must only pair what the builder would accept,
 * never touch accommodation or genuine rows, repeat from a seed, and leave nothing to add on a second pass.
 */
class PairListingsCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Region $city;

    private Region $south;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = Region::create(['name' => 'Davao City']);
        $this->south = Region::create(['name' => 'Davao del Sur']);
    }

    private function destination(string $name, float $lat, float $lng, string $role = 'sightseeing', ?Region $region = null): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => ($region ?? $this->city)->id,
            'type' => 'Nature & Leisure', 'itinerary_role' => $role, 'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10,
            'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng, 'distance_km' => 12,
        ]);
    }

    private function restaurant(string $name, float $lat, float $lng, array $extra = [], ?Region $region = null): Restaurant
    {
        return Restaurant::create($extra + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => ($region ?? $this->city)->id,
            'type' => 'Seafood', 'is_accredited' => true, 'rating' => 4.4, 'review_count' => 6, 'price_tier' => 'Mid-range',
            'latitude' => $lat, 'longitude' => $lng,
        ]);
    }

    private function shop(string $name, float $lat, float $lng, array $extra = []): SouvenirCenter
    {
        return SouvenirCenter::create($extra + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->city->id,
            'is_accredited' => true, 'rating' => 4.2, 'review_count' => 3, 'latitude' => $lat, 'longitude' => $lng,
        ]);
    }

    private function stay(): Accommodation
    {
        return Accommodation::create([
            'slug' => 'pair-inn', 'name' => 'Pair Inn', 'location' => 'Davao City', 'region_id' => $this->city->id, 'type' => 'Hotel',
            'is_accredited' => true, 'rating' => 4.0, 'review_count' => 3, 'latitude' => 7.0735, 'longitude' => 125.6130,
        ]);
    }

    /** Two destinations and the listings around the first one (People's Park), plus decoys the builder would refuse. */
    private function catalogue(): array
    {
        $park = $this->destination("People's Park", 7.0731, 125.6128);
        $this->destination('Far Falls', 7.30, 125.40);

        return [
            'park' => $park,
            'near_cafe' => $this->restaurant('Near Cafe', 7.0735, 125.6130),
            'far_cafe' => $this->restaurant('Far Cafe', 7.20, 125.6128), // about 14 km: beyond the meal radius
            'near_shop' => $this->shop('Near Shop', 7.0740, 125.6140),
            'far_shop' => $this->shop('Far Shop', 7.40, 125.6128), // about 36 km: beyond the souvenir radius
        ];
    }

    private function generate(array $options = []): string
    {
        Artisan::call('surveys:generate-demo-samples', $options);

        return Artisan::output();
    }

    private function pair(string $batch = 'pair-test', array $options = []): string
    {
        return $this->generate($options + ['--pair-listings' => true, '--batch' => $batch, '--apply' => true]);
    }

    private function batch(string $batch = 'pair-test')
    {
        return ExitSurvey::withoutGlobalScopes()->where('batch', $batch);
    }

    private function partners(string $batch, string $kind): array
    {
        return ExitSurveyVisit::whereIn('exit_survey_id', $this->batch($batch)->pluck('id'))->where('listing_kind', $kind)->pluck('listing_id')->unique()->values()->all();
    }

    // ---- safeguards ----------------------------------------------------------------------------------

    public function test_it_needs_an_explicit_batch_and_cannot_be_combined_with_the_other_coverage_mode(): void
    {
        $this->catalogue();

        $this->assertStringContainsString('explicit --batch', $this->generate(['--pair-listings' => true, '--apply' => true]));
        $this->assertStringContainsString('run them one at a time', $this->generate(['--pair-listings' => true, '--ensure-sample-coverage' => true, '--batch' => 'x', '--apply' => true]));
        $this->assertSame(0, ExitSurvey::withoutGlobalScopes()->count());
    }

    public function test_a_dry_run_writes_nothing_and_is_the_same_every_time(): void
    {
        $this->catalogue();

        $first = $this->generate(['--pair-listings' => true, '--batch' => 'pair-test', '--seed' => 5]);
        $second = $this->generate(['--pair-listings' => true, '--batch' => 'pair-test', '--seed' => 5]);

        $this->assertStringContainsString('Dry run: nothing written', $first);
        $this->assertSame($first, $second);
        $this->assertSame(0, ExitSurvey::withoutGlobalScopes()->count());
        $this->assertSame(0, ExitSurveyVisit::count());
    }

    public function test_an_existing_batch_is_never_added_to(): void
    {
        $this->catalogue();

        $this->pair();
        $count = $this->batch()->count();
        $this->assertGreaterThan(0, $count);

        $this->assertStringContainsString("Batch 'pair-test' already exists", $this->pair());
        $this->assertSame($count, $this->batch()->count());
    }

    // ---- provenance ----------------------------------------------------------------------------------

    public function test_every_row_is_simulated_and_in_its_own_batch_and_revert_removes_only_that_batch(): void
    {
        $this->catalogue();
        $real = ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes', 'data_source' => 'real', 'submitted_at' => now()]);
        $bare = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'demo', 'submitted_at' => now()]);
        $other = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'demo', 'batch' => 'demo-coverage-2026-11', 'submitted_at' => now()]);

        $this->pair();

        $rows = $this->batch()->get();
        $this->assertGreaterThan(0, $rows->count());
        $this->assertSame(['demo'], $rows->pluck('data_source')->unique()->values()->all());
        $this->assertSame(['pair-test'], $rows->pluck('batch')->unique()->values()->all());
        $this->assertSame('real', $real->fresh()->data_source);
        $this->assertNull($real->fresh()->batch);
        $this->assertNull($bare->fresh()->batch);
        $this->assertSame('demo-coverage-2026-11', $other->fresh()->batch);

        $this->generate(['--revert' => true, '--batch' => 'pair-test', '--apply' => true]);

        $this->assertSame(0, $this->batch()->count());
        $this->assertSame(0, ExitSurveyVisit::whereIn('exit_survey_id', $rows->pluck('id'))->count(), 'the batch took its visits with it');
        $this->assertNotNull($real->fresh());
        $this->assertNotNull($bare->fresh());
        $this->assertNotNull($other->fresh());
    }

    // ---- valid pairings ------------------------------------------------------------------------------

    public function test_a_destination_is_paired_with_its_nearest_valid_restaurant_and_souvenir_centre(): void
    {
        $c = $this->catalogue();
        $nearer = $this->restaurant('Nearer Cafe', 7.0732, 125.6129);

        $this->pair();

        $this->assertSame([$nearer->id], $this->partners('pair-test', 'restaurant'), 'the nearest valid restaurant, and only that');
        $this->assertSame([$c['near_shop']->id], $this->partners('pair-test', 'souvenir_center'));
    }

    public function test_it_never_pairs_a_listing_the_builder_would_refuse(): void
    {
        $c = $this->catalogue();
        $this->restaurant('Other Region Cafe', 7.0731, 125.6129, [], $this->south);
        $this->restaurant('Dinner Only', 7.0731, 125.6130, ['opening_hours' => '5:00 PM–10:00 PM']);
        $this->restaurant('Archived Cafe', 7.0731, 125.6131, ['archived_at' => now()]);
        $this->restaurant('Unaccredited Cafe', 7.0731, 125.6132, ['is_accredited' => false]);
        $this->shop('Hidden Shop', 7.0731, 125.6129, ['archived_at' => now()]);

        $this->pair();

        $this->assertSame([$c['near_cafe']->id], $this->partners('pair-test', 'restaurant'));
        $this->assertSame([$c['near_shop']->id], $this->partners('pair-test', 'souvenir_center'));
    }

    public function test_a_destination_with_nothing_in_range_is_left_without_a_forced_pairing(): void
    {
        $this->destination('Remote Reef', 5.50, 125.40);
        $this->destination('Remote Isle', 5.52, 125.52);
        $this->restaurant('Far Cafe', 7.20, 125.6128);
        $this->shop('Far Shop', 7.40, 125.6128);

        $out = $this->pair();

        $this->assertStringContainsString('Nothing to add', $out);
        $this->assertSame(0, $this->batch()->count());
    }

    public function test_a_destination_with_its_own_restaurant_gets_no_restaurant_pairing_but_still_a_souvenir_one(): void
    {
        $c = $this->catalogue();
        $c['park']->tags()->create(['kind' => 'amenity', 'value' => 'Restaurant']);

        $this->pair();

        $this->assertSame([], $this->partners('pair-test', 'restaurant'), 'the builder never consults a rule there');
        $this->assertSame([$c['near_shop']->id], $this->partners('pair-test', 'souvenir_center'));
    }

    public function test_only_the_destinations_the_planner_offers_are_targeted_and_accommodation_is_never_paired(): void
    {
        $c = $this->catalogue();
        $optional = $this->destination('Spa Garden', 7.0750, 125.6150, 'optional');
        $excluded = $this->destination('Members Club', 7.0760, 125.6160, 'excluded');
        $this->stay();

        $this->pair();

        $visited = ExitSurveyVisit::whereIn('exit_survey_id', $this->batch()->pluck('id'))->get();
        $destinations = $visited->where('listing_kind', 'destination')->pluck('listing_id')->unique();
        $this->assertTrue($destinations->contains($c['park']->id));
        $this->assertFalse($destinations->contains($optional->id));
        $this->assertFalse($destinations->contains($excluded->id));
        $this->assertSame([], $visited->whereNotIn('listing_kind', ['destination', 'restaurant', 'souvenir_center'])->all(), 'no stay is ever paired');
    }

    // ---- coverage and thresholds ---------------------------------------------------------------------

    public function test_the_pairing_clears_the_planners_thresholds_whatever_the_destinations_existing_popularity(): void
    {
        $c = $this->catalogue();

        // Twenty-five earlier surveys list the park alone: two co-visits would be 2 / 27, below the confidence of 0.1.
        foreach (range(1, 25) as $i) {
            $survey = ExitSurvey::create(['submitted_at' => now(), 'data_source' => 'demo']);
            ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $c['park']->id]);
        }

        $this->pair();

        $apriori = app(AprioriService::class);
        foreach (['restaurant' => $c['near_cafe'], 'souvenir_center' => $c['near_shop']] as $kind => $listing) {
            $rule = $apriori->suggestionsFor('destination', $c['park']->id, 5, $kind)->first();
            $this->assertNotNull($rule, "a usable {$kind} rule exists");
            $this->assertSame($listing->id, $rule['listing_id']);
            $this->assertGreaterThanOrEqual(2, $rule['co_count']);
            $this->assertGreaterThanOrEqual(0.1, $rule['confidence']);
        }
    }

    public function test_a_far_rule_does_not_count_as_usable_so_the_destination_still_gets_a_nearby_one(): void
    {
        $c = $this->catalogue();

        // The earlier random batches paired the park with a restaurant 14 km away, twice: Apriori mines it, the builder refuses it.
        foreach (range(1, 2) as $i) {
            $survey = ExitSurvey::create(['submitted_at' => now(), 'data_source' => 'demo', 'batch' => 'demo-samples-2026-11']);
            ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $c['park']->id]);
            ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'restaurant', 'listing_id' => $c['far_cafe']->id]);
        }

        $this->pair();

        $this->assertSame([$c['near_cafe']->id], $this->partners('pair-test', 'restaurant'));
    }

    public function test_a_second_pass_finds_nothing_to_add(): void
    {
        $this->catalogue();

        $this->pair('pair-one');
        $this->assertGreaterThan(0, $this->batch('pair-one')->count());

        $second = $this->pair('pair-two');

        $this->assertStringContainsString('Nothing to add', $second);
        $this->assertSame(0, $this->batch('pair-two')->count());
    }

    public function test_a_very_popular_destination_is_reported_not_forced(): void
    {
        $c = $this->catalogue();

        foreach (range(1, 80) as $i) {
            $survey = ExitSurvey::create(['submitted_at' => now(), 'data_source' => 'demo']);
            ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $c['park']->id]);
        }

        $out = $this->generate(['--pair-listings' => true, '--batch' => 'pair-test']);

        $this->assertStringContainsString('would need more than 6 each', $out);
        $this->assertStringContainsString("People's Park", $out);
        $this->assertSame([], $this->partners('pair-test', 'restaurant'));
    }

    // ---- the limits the pairing aims at are the ones the builder and Apriori use ---------------------

    public function test_the_pairing_limits_match_the_builder_and_apriori(): void
    {
        $builder = new ReflectionClass(ItineraryScheduleBuilder::class);
        $generator = new ReflectionClass(\App\Console\Commands\GenerateDemoSampleSurveys::class);

        $this->assertSame($builder->getConstant('MEAL_SEARCH_KM'), $generator->getConstant('PAIR_MEAL_KM'));
        $this->assertSame($builder->getConstant('SOUVENIR_MAX_KM'), $generator->getConstant('PAIR_SOUVENIR_KM'));

        // The planner's own cut-offs, measured rather than read: confidence exactly 0.1 and a co-count of 2 qualify,
        // a confidence just under it or a single co-visit does not.
        $park = $this->destination("People's Park", 7.0731, 125.6128);
        $cafe = $this->restaurant('Edge Cafe', 7.0735, 125.6130);
        $other = $this->restaurant('Edge Cafe Two', 7.0736, 125.6131);
        $this->assertSame(0.1, $generator->getConstant('PAIR_MIN_CONFIDENCE'));

        $survey = function (array $places) {
            $s = ExitSurvey::create(['submitted_at' => now(), 'data_source' => 'demo']);
            foreach ($places as [$kind, $id]) {
                ExitSurveyVisit::create(['exit_survey_id' => $s->id, 'listing_kind' => $kind, 'listing_id' => $id]);
            }
        };
        foreach (range(1, 2) as $i) {
            $survey([['destination', $park->id], ['restaurant', $cafe->id]]);
        }
        foreach (range(1, 17) as $i) {
            $survey([['destination', $park->id]]);
        }
        $survey([['destination', $park->id], ['restaurant', $other->id]]);

        $apriori = app(AprioriService::class);
        $this->assertSame([$cafe->id], $apriori->suggestionsFor('destination', $park->id, 5, 'restaurant')->pluck('listing_id')->all(),
            '2 of 20 visits is exactly 0.1 and qualifies; a single co-visit never does');

        $survey([['destination', $park->id]]);

        $this->assertSame([], $apriori->suggestionsFor('destination', $park->id, 5, 'restaurant')->pluck('listing_id')->all(),
            '2 of 21 visits is about 0.095, just under the confidence of 0.1');
    }

    // ---- actual rule utilization ---------------------------------------------------------------------

    public function test_the_itinerary_credits_the_paired_restaurant_and_souvenir_centre_and_not_before(): void
    {
        $c = $this->catalogue();
        // Whichever stop comes last needs a shop of its own, and whichever stop lunch follows a cafe: give the second place both.
        $fallsCafe = $this->restaurant('Falls Cafe', 7.3005, 125.4005);
        $fallsShop = $this->shop('Falls Shop', 7.3010, 125.4010);
        $plan = fn () => $this->post('/plan', [
            'travel_days' => 1, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Hotel', 'distance_pref' => 'moderate', 'activities' => ['Nature', 'Hiking'],
            'amenities' => ['Parking Area'], 'arrival_time' => '08:00', 'place_of_origin' => 'Cebu City',
        ]);

        $plan();
        $before = Itinerary::with('items')->latest('id')->first()->items->whereNotNull('rule_basis');
        $this->assertCount(0, $before, 'no rule exists yet, so nothing is credited');

        $this->pair();
        $plan();
        $after = Itinerary::with('items')->latest('id')->first()->items->whereNotNull('rule_basis');

        $this->assertTrue($after->contains(fn ($i) => in_array($i->restaurant_id, [$c['near_cafe']->id, $fallsCafe->id], true)), 'the lunch is credited to a paired restaurant');
        $this->assertTrue($after->contains(fn ($i) => in_array($i->souvenir_center_id, [$c['near_shop']->id, $fallsShop->id], true)), 'the souvenir stop is credited to a paired shop');
        $this->assertStringContainsString('simulated', (string) $after->first()->ruleExplanation(simulatedTransactions: 3, totalTransactions: 3));
    }
}
