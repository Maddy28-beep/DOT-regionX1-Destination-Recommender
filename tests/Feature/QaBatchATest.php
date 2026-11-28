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

    /**
     * The page no longer prints a total of visits; the disclosure sits on the stop whose pick rests on an association rule:
     * "Includes simulated demonstration trips" when some of the visits behind the rules are simulated, and "These are
     * simulated demonstration trips" when all of them are.
     */
    public function test_a_paired_stop_says_whether_the_visits_behind_it_are_simulated(): void
    {
        ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes', 'data_source' => 'demo']);
        ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'demo']);
        $real = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'real']);

        $stop = $this->destination('Paired Place');
        $pref = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'near',
        ]);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 1, 'generated_at' => now()]);
        $trip->items()->create([
            'day_number' => 1, 'sort_order' => 1, 'slot' => 'Morning', 'kind' => 'activity', 'title' => 'Visit Paired Place',
            'destination_id' => $stop->id, 'starts_at' => '09:00:00', 'ends_at' => '11:00:00',
            'rule_basis' => 'Another Place', 'rule_confidence' => 0.5, 'rule_support' => 0.1, 'rule_co_count' => 3,
        ]);
        $page = fn () => $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])
            ->get(route('plan.itinerary'))->assertOk();

        $page()->assertSee('Popular pairing with Another Place')->assertSee('Includes simulated demonstration trips.');

        // With only simulated visits recorded, the note is the stronger one.
        $real->delete();
        $page()->assertSee('These are simulated demonstration trips, not real traveller visits.');

        // With only real visits there is no note.
        ExitSurvey::query()->delete();
        ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'real']);
        $page()->assertDontSee('simulated demonstration trips');
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
