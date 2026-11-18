<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\TouristHealthCondition;
use App\Models\TouristHealthProfile;
use App\Models\TouristPreference;
use App\Services\Itineraries\ItineraryKeep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No accounts for travelers. A plan worth keeping is reached by a private link: the edit link carries on with it on any device,
 * the share link only shows it, and a new link replaces both. Only hashes of the secrets are stored.
 */
class ItineraryKeepTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): array
    {
        $pref = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Budget-Friendly', 'accommodation_pref' => 'Any', 'distance_pref' => 'far',
        ]);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 2, 'generated_at' => now()]);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 1, 'slot' => 'Morning', 'kind' => 'activity', 'title' => 'Eagle Center visit', 'starts_at' => '09:00:00']);

        return [$trip, $pref];
    }

    private function holding(Itinerary $trip, TouristPreference $pref): static
    {
        return $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id]);
    }

    public function test_there_is_no_traveler_sign_up_or_login(): void
    {
        $this->get('/account/register')->assertNotFound();
        $this->get('/account/login')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('Create a free account')->assertDontSee('data-login-open', false);
    }

    public function test_keeping_a_plan_makes_a_link_and_stores_only_hashes(): void
    {
        [$trip, $pref] = $this->plan();

        $this->holding($trip, $pref)->post(route('plan.keep'))->assertRedirect();

        $trip->refresh();
        $secret = session(ItineraryKeep::SESSION_KEY);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', $secret);
        $this->assertSame(hash('sha256', $secret), $trip->edit_token_hash);
        $this->assertNotSame($secret, $trip->edit_token_hash);
        $this->assertNotNull($trip->share_token_hash);
        $this->assertDatabaseMissing('itineraries', ['edit_token_hash' => $secret]);
    }

    public function test_the_page_shows_the_link_the_qr_code_and_the_print_option_right_after(): void
    {
        [$trip, $pref] = $this->plan();
        $this->holding($trip, $pref)->post(route('plan.keep'));
        $secret = session(ItineraryKeep::SESSION_KEY);

        $this->holding($trip, $pref)->withSession([ItineraryKeep::SESSION_KEY => $secret])->get(route('plan.itinerary'))->assertOk()
            ->assertSee(route('plan.kept.open', $secret), false)
            ->assertSee('QR code for your private link')
            ->assertSee('Print or save as PDF')
            ->assertDontSee('Create a free account');
    }

    public function test_the_edit_link_carries_on_with_the_plan_in_a_fresh_browser(): void
    {
        [$trip, $pref] = $this->plan();
        $this->holding($trip, $pref)->post(route('plan.keep'));
        $secret = session(ItineraryKeep::SESSION_KEY);

        $this->flushSession();
        $response = $this->get(route('plan.kept.open', $secret))->assertRedirect();

        $this->assertSame($trip->id, session(TripPlannerController::ITINERARY_KEY));
        $this->assertSame($pref->id, session(TripPlannerController::PREFERENCE_KEY));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('noindex', $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_a_wrong_or_malformed_secret_finds_nothing(): void
    {
        [$trip, $pref] = $this->plan();
        $this->holding($trip, $pref)->post(route('plan.keep'));

        $this->get(route('plan.kept.open', str_repeat('A', 48)))->assertNotFound();
        $this->get(route('plan.kept.shared', str_repeat('A', 48)))->assertNotFound();
        $this->get('/plan/saved/short')->assertNotFound();
        $this->get('/plan/saved/'.str_repeat('A', 47).'!')->assertNotFound();
    }

    public function test_the_share_link_only_shows_the_plan_and_the_edit_secret_does_not_work_as_a_share_link(): void
    {
        [$trip, $pref] = $this->plan();
        $this->holding($trip, $pref)->post(route('plan.keep'));
        $edit = session(ItineraryKeep::SESSION_KEY);
        $share = app(ItineraryKeep::class)->shareSecret($edit);

        $this->flushSession();
        $response = $this->get(route('plan.kept.shared', $share))->assertOk()->assertSee('Eagle Center visit')->assertSee('Print or save as PDF');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertNull(session(TripPlannerController::ITINERARY_KEY), 'a share link does not give the visitor the plan to edit');
        $this->assertStringContainsString('noindex', $response->getContent());

        $this->get(route('plan.kept.shared', $edit))->assertNotFound();
        $this->get(route('plan.kept.open', $share))->assertNotFound();
    }

    public function test_a_new_link_makes_the_old_ones_stop_working(): void
    {
        [$trip, $pref] = $this->plan();
        $this->holding($trip, $pref)->post(route('plan.keep'));
        $old = session(ItineraryKeep::SESSION_KEY);
        $oldShare = app(ItineraryKeep::class)->shareSecret($old);

        $this->holding($trip, $pref)->post(route('plan.keep.renew'))->assertRedirect();
        $new = session(ItineraryKeep::SESSION_KEY);

        $this->assertNotSame($old, $new);
        $this->get(route('plan.kept.open', $old))->assertNotFound();
        $this->get(route('plan.kept.shared', $oldShare))->assertNotFound();
        $this->get(route('plan.kept.open', $new))->assertRedirect();
    }

    public function test_keeping_a_plan_removes_the_health_answers_behind_it(): void
    {
        [$trip, $pref] = $this->plan();
        $profile = TouristHealthProfile::create(['preference_id' => $pref->id, 'consent' => true, 'consent_at' => now(), 'other_text' => 'asthma']);
        TouristHealthCondition::create(['health_profile_id' => $profile->id, 'condition' => 'mobility']);

        $this->holding($trip, $pref)->post(route('plan.keep'));

        $this->assertDatabaseCount('tourist_health_profiles', 0);
        $this->assertDatabaseCount('tourist_health_conditions', 0);
    }

    public function test_pressing_keep_again_does_not_replace_the_link(): void
    {
        [$trip, $pref] = $this->plan();
        $this->holding($trip, $pref)->post(route('plan.keep'));
        $secret = session(ItineraryKeep::SESSION_KEY);
        $hash = $trip->fresh()->edit_token_hash;

        $this->holding($trip, $pref)->withSession([ItineraryKeep::SESSION_KEY => $secret])->post(route('plan.keep'))->assertRedirect();

        $this->assertSame($hash, $trip->fresh()->edit_token_hash);
    }

    public function test_a_kept_plan_whose_link_this_browser_does_not_hold_says_so_and_offers_a_new_one(): void
    {
        [$trip, $pref] = $this->plan();
        $this->holding($trip, $pref)->post(route('plan.keep'));
        $this->flushSession();

        $this->holding($trip, $pref)->get(route('plan.itinerary'))->assertOk()
            ->assertSee('not stored where we can show it again')
            ->assertSee('Make a new link');
    }

    public function test_keeping_needs_a_plan_to_keep(): void
    {
        $this->post(route('plan.keep'))->assertNotFound();
    }
}
