<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\AccreditationRecord;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyVisit;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\TouristPreference;
use App\Services\Recommendation\AprioriService;
use App\Support\SampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A listing whose accreditation number is a placeholder is labelled as sample data wherever it is shown, and keeps
 * taking part in planning, recommendations and Apriori. Dashboards show real surveys by default.
 */
class SampleDataLabelsTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.groq.key' => null]);
    }

    private function dest(string $name, ?string $number = 'DOT-R11-REAL-0001', bool $placeholder = false): Destination
    {
        $d = Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Malagos, Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Wildlife', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Budget-Friendly', 'hours' => '8:00 AM–5:00 PM',
            'latitude' => 7.15, 'longitude' => 125.40,
        ]);

        if ($number !== null) {
            $this->record('destination', $d->id, $number, $placeholder);
        }

        return $d;
    }

    private function record(string $kind, int $id, string $number, bool $placeholder): AccreditationRecord
    {
        return AccreditationRecord::create([
            'listing_kind' => $kind, 'listing_id' => $id, 'accreditation_number' => $number, 'status' => 'Active',
            'issue_date' => now()->subMonths(3), 'expiration_date' => now()->addYear(), 'is_placeholder' => $placeholder,
        ]);
    }

    private function sample(string $name): Destination
    {
        return $this->dest($name, 'SAMPLE-000'.(++$this->n), true);
    }

    private function admin(): AdminUser
    {
        return AdminUser::create(['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
    }

    private function survey(string $source, array $places = [], int $rating = 4): ExitSurvey
    {
        $s = ExitSurvey::create(['overall_rating' => $rating, 'would_recommend' => 'Yes', 'data_source' => $source, 'submitted_at' => now()]);
        foreach ($places as [$kind, $id]) {
            ExitSurveyVisit::create(['exit_survey_id' => $s->id, 'listing_kind' => $kind, 'listing_id' => $id]);
        }

        return $s;
    }

    // ---- the migration's backfill

    public function test_backfill_flags_only_the_dot_xi_placeholder_numbers_and_is_safe_to_repeat(): void
    {
        $a = $this->dest('Alpha', 'DOT-XI-2025-0001');
        $b = $this->dest('Bravo', 'DOT-R11-HTL-00112-2021');
        $c = $this->dest('Charlie', 'SAMPLE-0001');

        $migration = require base_path('database/migrations/2026_10_30_000100_add_placeholder_flag_and_survey_batch.php');

        $this->assertSame(1, $migration->backfill());
        $this->assertSame(1, $migration->backfill(), 'Running it again changes nothing new.');
        $this->assertTrue((bool) AccreditationRecord::where('accreditation_number', 'DOT-XI-2025-0001')->value('is_placeholder'));
        $this->assertFalse((bool) AccreditationRecord::where('accreditation_number', 'DOT-R11-HTL-00112-2021')->value('is_placeholder'));
        $this->assertFalse((bool) AccreditationRecord::where('accreditation_number', 'SAMPLE-0001')->value('is_placeholder'), 'Only the DOT-XI numbers are backfilled; new samples set the flag themselves.');
    }

    // ---- which listings count as sample data

    public function test_only_a_listing_whose_every_record_is_a_placeholder_is_sample_data(): void
    {
        $registry = app(SampleData::class);
        $sample = $this->sample('Sample Falls');
        $real = $this->dest('Real Falls');
        $mixed = $this->sample('Mixed Falls');
        $none = $this->dest('Unrecorded Falls', null);

        $this->assertTrue($registry->isSample($sample));
        $this->assertFalse($registry->isSample($real));
        $this->assertFalse($registry->isSample($none));
        $this->assertTrue($registry->isSampleName('SAMPLE FALLS'));

        // A real DOT record arrives: the listing stops being sample data at once.
        $this->record('destination', $mixed->id, 'DOT-R11-NEW-0009', false);
        $this->assertFalse($registry->isSample($mixed));
    }

    // ---- badges on public pages

    public function test_a_sample_destination_page_says_sample_data_and_a_real_one_still_says_accredited(): void
    {
        $sample = $this->sample('Sample Falls');
        $real = $this->dest('Real Falls');

        $this->get(route('destinations.show', $sample))->assertOk()
            ->assertSee('Sample data, not DOT-verified')
            ->assertDontSee('DOT Accredited');

        // The sample place also appears as a nearby card on this page, and rightly carries its own label there.
        $this->get(route('destinations.show', $real))->assertOk()
            ->assertSee('title="DOT Accredited"', false)
            ->assertDontSee('dpost-badge-accredited is-sample', false);
    }

    public function test_a_sample_restaurant_page_and_the_list_cards_carry_the_label(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $cafe = Restaurant::create(['slug' => 'sample-cafe', 'name' => 'Sample Cafe', 'location' => 'Davao', 'region_id' => $region->id,
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range']);
        $this->record('restaurant', $cafe->id, 'SAMPLE-0099', true);
        $this->sample('Sample Falls');

        $this->get(route('restaurants.show', $cafe))->assertOk()->assertSee('Sample data, not DOT-verified');
        $this->get(route('destinations.index'))->assertOk()->assertSee('Sample data, not DOT-verified');
    }

    public function test_a_sample_listing_still_takes_part_in_apriori_suggestions(): void
    {
        $eden = $this->dest('Eden');
        $sample = $this->sample('Sample Falls');
        for ($i = 0; $i < 3; $i++) {
            $this->survey('real', [['destination', $eden->id], ['destination', $sample->id]]);
        }

        $found = app(AprioriService::class)->suggestionsFor('destination', $eden->id, 3);

        $this->assertSame(['Sample Falls'], $found->pluck('listing.name')->all());
    }

    // ---- chatbot

    public function test_the_chatbot_does_not_call_a_sample_place_dot_accredited(): void
    {
        $this->sample('Sample Falls');
        $this->dest('Real Falls');

        $sample = $this->postJson(route('chatbot.respond'), ['message' => 'Is Sample Falls accredited?'])->assertOk()->json('response');
        $real = $this->postJson(route('chatbot.respond'), ['message' => 'Is Real Falls accredited?'])->assertOk()->json('response');

        $this->assertStringContainsString('sample data', $sample);
        $this->assertStringContainsString('not verified by DOT', $sample);
        $this->assertStringNotContainsString('is DOT-accredited', $sample);
        $this->assertStringContainsString('Yes, Real Falls is DOT-accredited', $real);
    }

    public function test_a_sample_place_is_flagged_in_an_ordinary_answer_too(): void
    {
        $this->sample('Sample Falls');

        $reply = $this->postJson(route('chatbot.respond'), ['message' => 'What are the hours of Sample Falls?'])->assertOk()->json('response');

        $this->assertStringContainsString('This listing is sample data, not DOT-verified.', $reply);
    }

    // ---- per-rule labels

    public function test_the_admin_rules_page_marks_only_rules_that_involve_sample_data(): void
    {
        $eden = $this->dest('Eden');
        $eagle = $this->dest('Eagle Center', 'DOT-R11-REAL-0002');
        $sample = $this->sample('Sample Falls');
        $other = $this->dest('Other Park', 'DOT-R11-REAL-0003');

        for ($i = 0; $i < 2; $i++) {
            $this->survey('real', [['destination', $eden->id], ['destination', $eagle->id]]);
            $this->survey('real', [['destination', $other->id], ['destination', $sample->id]]);
        }

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.association-rules', ['source' => 'real']))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'Involves sample data</span>'), 'Other Park -> Sample Falls and Sample Falls -> Other Park, and no other rule.');
        $this->assertDoesNotMatchRegularExpression('/Eden.*?Eagle Center.*?Involves sample data/s', preg_replace('/Other Park.*$/s', '', $html));
    }

    private function pairingPage(Destination $stop, string $basis): string
    {
        $pref = TouristPreference::create(['travel_days' => 1, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Budget-Friendly', 'accommodation_pref' => 'Any', 'distance_pref' => 'far']);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 1, 'generated_at' => now()]);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 1, 'slot' => 'Morning', 'kind' => 'activity',
            'title' => 'Visit — '.$stop->name, 'starts_at' => '10:00:00', 'ends_at' => '12:00:00', 'destination_id' => $stop->id,
            'rule_basis' => $basis, 'rule_support' => 0.1, 'rule_confidence' => 0.5, 'rule_co_count' => 3]);

        return $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();
    }

    public function test_the_itinerary_marks_a_pairing_that_involves_sample_data(): void
    {
        $real = $this->dest('Real Falls');
        $sample = $this->sample('Sample Falls');

        $this->assertStringContainsString('Involves sample data', $this->pairingPage($sample, 'Real Falls'), 'The suggested stop is sample data.');
        $this->assertStringContainsString('Involves sample data', $this->pairingPage($real, 'Sample Falls'), 'The place the rule started from is sample data.');
        $this->assertStringNotContainsString('Involves sample data', $this->pairingPage($real, 'Real Falls'));
    }

    // ---- dashboards

    public function test_the_survey_dashboard_shows_real_responses_unless_asked_to_include_simulated_ones(): void
    {
        $this->survey('real', [], 5);
        $this->survey('real', [], 5);
        $this->survey('demo', [], 1);
        $this->survey('demo', [], 1);
        $this->survey('demo', [], 1);
        $admin = $this->admin();

        $default = $this->actingAs($admin, 'admin')->get(route('admin.exit-surveys'));
        $default->assertOk()->assertViewHas('count', 2)->assertViewHas('simulatedHidden', 3)->assertViewHas('includeSimulated', false);
        $this->assertSame(5.0, (float) $default->viewData('avgRatings')['Overall Satisfaction']);
        $default->assertSee('3 simulated demo surveys are left out');

        $with = $this->actingAs($admin, 'admin')->get(route('admin.exit-surveys', ['include_simulated' => 1]));
        $with->assertOk()->assertViewHas('count', 5)->assertViewHas('simulatedHidden', 0);
        $this->assertSame(2.6, (float) $with->viewData('avgRatings')['Overall Satisfaction']);
        $with->assertSee('include simulated demo surveys');
    }

    public function test_the_survey_report_follows_the_same_toggle(): void
    {
        $this->survey('real');
        $this->survey('demo');
        $this->survey('demo');
        $admin = $this->admin();
        $params = ['report_type' => 'Exit Survey Responses', 'from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()];

        $this->actingAs($admin, 'admin')->get(route('admin.reports', $params))->assertOk()->assertSee('1 exit survey response in the selected range');
        $this->actingAs($admin, 'admin')->get(route('admin.reports', $params + ['include_simulated' => 1]))->assertOk()->assertSee('3 exit survey responses in the selected range');
    }
}
