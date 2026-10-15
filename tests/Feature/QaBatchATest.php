<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\AccreditationRecord;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\TouristPreference;
use Database\Seeders\AccreditationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Small fixes from the October QA pass. */
class QaBatchATest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $name, bool $accredited = true): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Nature',
            'is_accredited' => $accredited, 'rating' => 4.2, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);
    }

    public function test_the_trip_page_says_how_many_of_the_visits_are_simulated(): void
    {
        ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes', 'data_source' => 'demo']);
        ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'demo']);
        ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'real']);

        $pref = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'near',
        ]);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 1, 'generated_at' => now()]);

        $html = $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode(strip_tags($html)));

        $this->assertStringContainsString('3 visitation transactions (2 simulated for demonstration, 1 from real tourist surveys)', $text);
    }

    public function test_the_watchlist_lists_places_about_to_expire_even_when_many_have_already_expired(): void
    {
        $admin = AdminUser::create(['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);

        foreach (range(1, 6) as $n) {
            $d = $this->destination("Old Place {$n}", false);
            AccreditationRecord::create(['listing_kind' => 'destination', 'listing_id' => $d->id, 'accreditation_number' => "OLD-{$n}",
                'status' => 'Expired', 'expiration_date' => now()->subDays(10 + $n)]);
        }
        $soon = $this->destination('Soon Place');
        AccreditationRecord::create(['listing_kind' => 'destination', 'listing_id' => $soon->id, 'accreditation_number' => 'SOON-1',
            'status' => 'Expiring Soon', 'expiration_date' => now()->addDays(5)]);

        $this->actingAs($admin, 'admin')->get(route('admin.overview'))->assertOk()->assertSee('Soon Place');
    }

    public function test_the_demo_accreditation_seeder_keeps_every_destination_public(): void
    {
        foreach (range(1, 8) as $n) {
            $this->destination("Demo Place {$n}");
        }

        (new AccreditationSeeder())->run();

        $this->assertSame(8, AccreditationRecord::where('listing_kind', 'destination')->where('status', 'Active')->count());
        $this->assertSame(0, AccreditationRecord::where('listing_kind', 'destination')->where('status', 'Expired')->count());
    }
}
