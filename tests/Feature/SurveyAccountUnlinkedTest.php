<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\Region;
use App\Models\TouristAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Post-trial prep (f): an exit survey can never be joined to a tourist account through the shared preference record. */
class SurveyAccountUnlinkedTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): void
    {
        $region = Region::create(['name' => 'Davao City']);
        foreach ([['eden', 'Eden Nature Park', 7.0206, 125.4103], ['peoples', 'Peoples Park', 7.0731, 125.6128]] as [$slug, $name, $lat, $lng]) {
            Destination::create(['slug' => $slug, 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id, 'type' => 'Nature & Leisure',
                'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng, 'distance_km' => 12]);
        }
        $this->post('/plan', [
            'travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'place_of_origin' => 'Cebu City', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor', 'activities' => ['Nature'], 'amenities' => ['Parking Area'],
        ])->assertRedirect(route('plan.itinerary'));
    }

    private function survey(): ExitSurvey
    {
        $this->post('/exit-survey', ['overall_rating' => 4, 'would_recommend' => 'Yes'])->assertRedirect();

        return ExitSurvey::withoutGlobalScopes()->latest('submitted_at')->firstOrFail();
    }

    public function test_a_survey_without_an_account_still_links_to_its_trip(): void
    {
        $this->plan();
        $this->assertNotNull($this->survey()->preference_id, 'the recap still personalises for an anonymous visitor');
    }

    public function test_saving_the_trip_to_an_account_unlinks_the_earlier_survey(): void
    {
        $this->plan();
        $survey = $this->survey();
        $this->assertNotNull($survey->preference_id);

        $this->actingAs(TouristAccount::create(['alias' => 'explorer1', 'password_hash' => Hash::make('password123')]), 'tourist');
        $this->post(route('plan.itinerary.save'), ['title' => 'Trip'])->assertRedirect();

        $this->assertNull($survey->fresh()->preference_id);
    }

    public function test_a_survey_sent_after_saving_is_not_linked(): void
    {
        $this->plan();
        $this->actingAs(TouristAccount::create(['alias' => 'explorer2', 'password_hash' => Hash::make('password123')]), 'tourist');
        $this->post(route('plan.itinerary.save'), ['title' => 'Trip'])->assertRedirect();

        $this->assertNull($this->survey()->preference_id);
    }
}
