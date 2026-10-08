<?php

namespace Tests\Feature;

use App\Models\ExitSurvey;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\PreferenceActivity;
use App\Models\TouristAccount;
use App\Models\TouristHealthCondition;
use App\Models\TouristHealthProfile;
use App\Models\TouristPreference;
use App\Services\Privacy\AnonymousDataPurger;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Retention (RA 10173): anonymous trip data is deleted after a fixed time, and
 * everything that is not anonymous trip data -- or that someone chose to keep -- is not.
 */
class PrivacyPurgeTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-20 03:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function preference(string $createdDaysAgo = '0'): TouristPreference
    {
        return TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'accessibility_notes' => 'uses a wheelchair', 'origin_label' => 'My street, Davao City', 'origin_lat' => 7.1, 'origin_lng' => 125.5,
            'place_of_origin' => 'Cebu',
        ]);
    }

    private function itinerary(TouristPreference $preference, int $ageDays, ?string $accountId = null): Itinerary
    {
        $itinerary = Itinerary::create([
            'preference_id' => $preference->id, 'total_days' => 2, 'generated_at' => now()->subDays($ageDays), 'tourist_account_id' => $accountId,
        ]);
        ItineraryItem::create(['itinerary_id' => $itinerary->id, 'day_number' => 1, 'sort_order' => 1, 'slot' => 'Morning', 'kind' => 'baseline', 'title' => 'Arrival']);

        return $itinerary;
    }

    private function withHealth(TouristPreference $preference): TouristHealthProfile
    {
        $profile = TouristHealthProfile::create(['preference_id' => $preference->id, 'consent' => true, 'consent_at' => now(), 'other_text' => 'asthma']);
        TouristHealthCondition::create(['health_profile_id' => $profile->id, 'condition' => 'mobility']);

        return $profile;
    }

    public function test_old_anonymous_trips_are_deleted_with_their_stops_and_health_answers(): void
    {
        $pref = $this->preference();
        PreferenceActivity::create(['preference_id' => $pref->id, 'activity' => 'Wildlife']);
        $this->withHealth($pref);
        $itinerary = $this->itinerary($pref, 45);

        $counts = app(AnonymousDataPurger::class)->purge(now());

        $this->assertSame(1, $counts['itineraries']);
        $this->assertSame(1, $counts['preferences_deleted']);
        $this->assertDatabaseMissing('itineraries', ['id' => $itinerary->id]);
        $this->assertDatabaseMissing('tourist_preferences', ['id' => $pref->id]);
        $this->assertDatabaseCount('itinerary_items', 0);
        $this->assertDatabaseCount('preference_activities', 0);
        $this->assertDatabaseCount('tourist_health_profiles', 0);
        $this->assertDatabaseCount('tourist_health_conditions', 0);
    }

    public function test_recent_trips_are_kept(): void
    {
        $pref = $this->preference();
        $itinerary = $this->itinerary($pref, 5);

        $counts = app(AnonymousDataPurger::class)->purge(now());

        $this->assertSame(0, $counts['itineraries']);
        $this->assertDatabaseHas('itineraries', ['id' => $itinerary->id]);
        $this->assertDatabaseHas('tourist_preferences', ['id' => $pref->id]);
    }

    public function test_a_trip_saved_to_a_tourist_account_is_never_deleted(): void
    {
        $account = TouristAccount::create(['alias' => 'traveller1', 'password_hash' => 'x']);
        $pref = $this->preference();
        $itinerary = $this->itinerary($pref, 400, $account->id);

        app(AnonymousDataPurger::class)->purge(now());

        $this->assertDatabaseHas('itineraries', ['id' => $itinerary->id]);
        $this->assertDatabaseHas('tourist_preferences', ['id' => $pref->id]);
    }

    public function test_preferences_with_no_itinerary_such_as_the_demo_data_survive(): void
    {
        $demo = $this->preference();
        DB::table('tourist_preferences')->where('id', $demo->id)->update(['created_at' => now()->subDays(200)]);

        $counts = app(AnonymousDataPurger::class)->purge(now());

        $this->assertSame(0, $counts['preferences_deleted']);
        $this->assertDatabaseHas('tourist_preferences', ['id' => $demo->id]);
    }

    public function test_a_preference_an_exit_survey_still_needs_is_kept_but_scrubbed(): void
    {
        $pref = $this->preference();
        $this->withHealth($pref);
        $this->itinerary($pref, 60);
        $survey = ExitSurvey::create(['preference_id' => $pref->id, 'submitted_at' => now()->subDays(59), 'overall_rating' => 5, 'data_source' => 'real']);

        $counts = app(AnonymousDataPurger::class)->purge(now());

        $this->assertSame(1, $counts['preferences_scrubbed']);
        $this->assertSame(0, $counts['preferences_deleted']);
        $this->assertDatabaseHas('exit_surveys', ['id' => $survey->id]);
        $kept = TouristPreference::find($pref->id);
        $this->assertNotNull($kept);
        $this->assertNull($kept->accessibility_notes);
        $this->assertNull($kept->origin_label);
        $this->assertNull($kept->origin_lat);
        $this->assertNull($kept->place_of_origin);
        $this->assertDatabaseCount('tourist_health_profiles', 0);
        $this->assertSame('Solo', $kept->travel_type, 'the non-identifying trip details stay for the recap');
    }

    public function test_a_preference_shared_with_a_newer_itinerary_is_kept(): void
    {
        $pref = $this->preference();
        $this->itinerary($pref, 90);
        $recent = $this->itinerary($pref, 2);

        $counts = app(AnonymousDataPurger::class)->purge(now());

        $this->assertSame(1, $counts['itineraries']);
        $this->assertSame(0, $counts['preferences_deleted']);
        $this->assertDatabaseHas('itineraries', ['id' => $recent->id]);
        $this->assertDatabaseHas('tourist_preferences', ['id' => $pref->id]);
    }

    public function test_old_chatbot_questions_and_old_trial_surveys_go_but_real_surveys_stay(): void
    {
        DB::table('chatbot_logs')->insert([
            ['user_query' => 'old question', 'chatbot_response' => 'x', 'intent_detected' => 'x', 'created_at' => now()->subDays(40)],
            ['user_query' => 'new question', 'chatbot_response' => 'x', 'intent_detected' => 'x', 'created_at' => now()->subDays(2)],
        ]);
        $oldTest = ExitSurvey::create(['submitted_at' => now()->subDays(3), 'overall_rating' => 4, 'data_source' => 'test']);
        $newTest = ExitSurvey::create(['submitted_at' => now()->subHours(2), 'overall_rating' => 4, 'data_source' => 'test']);
        $real = ExitSurvey::create(['submitted_at' => now()->subDays(300), 'overall_rating' => 5, 'data_source' => 'real']);

        $counts = app(AnonymousDataPurger::class)->purge(now());

        $this->assertSame(1, $counts['chatbot_logs']);
        $this->assertSame(1, $counts['test_surveys']);
        $this->assertDatabaseHas('chatbot_logs', ['user_query' => 'new question']);
        $this->assertDatabaseMissing('exit_surveys', ['id' => $oldTest->id]);
        $this->assertDatabaseHas('exit_surveys', ['id' => $newTest->id]);
        $this->assertDatabaseHas('exit_surveys', ['id' => $real->id]);
    }

    public function test_a_dry_run_counts_but_deletes_nothing(): void
    {
        $pref = $this->preference();
        $itinerary = $this->itinerary($pref, 45);

        $this->artisan('privacy:purge-anonymous-data', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('itineraries', ['id' => $itinerary->id]);

        $counts = app(AnonymousDataPurger::class)->purge(now(), null, true);
        $this->assertSame(1, $counts['itineraries']);
        $this->assertDatabaseHas('itineraries', ['id' => $itinerary->id]);
    }

    public function test_the_command_honours_a_custom_number_of_days_and_is_scheduled_daily(): void
    {
        $pref = $this->preference();
        $itinerary = $this->itinerary($pref, 10);

        $this->artisan('privacy:purge-anonymous-data', ['--days' => 7])->assertSuccessful();
        $this->assertDatabaseMissing('itineraries', ['id' => $itinerary->id]);

        $scheduled = collect(app(Schedule::class)->events())->contains(fn ($e) => str_contains((string) $e->command, 'privacy:purge-anonymous-data'));
        $this->assertTrue($scheduled, 'the job must be on the daily schedule');
    }
}
