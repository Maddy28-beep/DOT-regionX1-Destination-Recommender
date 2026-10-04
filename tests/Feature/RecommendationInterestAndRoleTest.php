<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ContentBasedRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a traveller asked for comes first, and places that are not somewhere to
 * sightsee (event venues, members' clubs, spas for someone who did not ask for
 * one) are never offered as a stop.
 */
class RecommendationInterestAndRoleTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $name, string $type, array $extra = []): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create($extra + [
            'slug' => str($name)->slug(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => $type, 'is_accredited' => true, 'rating' => 4.5, 'review_count' => 3, 'price_tier' => 'Mid-range',
        ]);
    }

    private function preference(array $interests): TouristPreference
    {
        $preference = TouristPreference::create([
            'travel_days' => 3, 'travel_type' => 'Solo', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'near', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
        ]);

        foreach ($interests as $interest) {
            $preference->activities()->create(['activity' => $interest]);
        }

        return $preference;
    }

    /** @return array<int, string> */
    private function ranked(TouristPreference $preference): array
    {
        return app(ContentBasedRecommendationService::class)->rank($preference)
            ->map(fn ($row) => $row['destination']->name)->all();
    }

    public function test_places_matching_the_picked_interest_rank_before_nearer_places_that_do_not(): void
    {
        // The nature park is mapped and near, so it wins on distance; the beach has no coordinates.
        $this->destination('Nature Park', 'Nature & Adventure', ['latitude' => 7.07, 'longitude' => 125.61]);
        $this->destination('Island Beach', 'Beach & Leisure');

        $this->assertSame('Island Beach', $this->ranked($this->preference(['Beach & Island']))[0]);
    }

    public function test_with_no_interest_picked_the_order_is_still_by_score(): void
    {
        $this->destination('Nature Park', 'Nature & Adventure', ['latitude' => 7.07, 'longitude' => 125.61]);
        $this->destination('Island Beach', 'Beach & Leisure');

        $this->assertSame('Nature Park', $this->ranked($this->preference([]))[0]);
    }

    public function test_an_event_venue_and_a_members_club_are_never_offered(): void
    {
        $this->destination('Island Beach', 'Beach & Leisure');
        $this->destination('Convention Center', 'Events & Conventions', ['itinerary_role' => 'excluded']);
        $this->destination('Country Club', 'Sports & Recreation', ['itinerary_role' => 'excluded']);

        $this->assertSame(['Island Beach'], $this->ranked($this->preference(['Beach & Island'])));
        $this->assertSame(['Island Beach'], $this->ranked($this->preference([])));
    }

    public function test_a_spa_is_offered_only_to_someone_who_asked_for_relaxation(): void
    {
        $this->destination('Island Beach', 'Beach & Leisure');
        $this->destination('Day Spa', 'Wellness & Spa', ['itinerary_role' => 'optional']);

        $this->assertNotContains('Day Spa', $this->ranked($this->preference(['Beach & Island'])));
        $this->assertNotContains('Day Spa', $this->ranked($this->preference([])));
        $this->assertContains('Day Spa', $this->ranked($this->preference(['Relaxation & Wellness'])));
    }

    public function test_the_default_role_is_sightseeing_and_does_not_change_visibility(): void
    {
        $this->destination('Island Beach', 'Beach & Leisure');
        $club = $this->destination('Country Club', 'Sports & Recreation', ['itinerary_role' => 'excluded']);

        $this->assertSame('sightseeing', Destination::where('slug', 'island-beach')->value('itinerary_role'));
        // Excluded from itineraries, but still an accredited, publicly visible listing.
        $this->assertTrue(Destination::publiclyVisible()->whereKey($club->id)->exists());
    }
}
