<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\SavedListing;
use App\Models\TouristAccount;
use App\Models\TouristSavedDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The account-scoped favorites list. Kept entirely separate from the
 * anonymous, visitor_token-keyed SavedListing feature -- these tests guard
 * both that the account version works on its own, and that it never touches
 * or interferes with the pre-existing anonymous one.
 */
class TouristSavedDestinationTest extends TestCase
{
    use RefreshDatabase;

    private function destination(): Destination
    {
        $region = Region::create(['name' => 'Davao City']);

        return Destination::create([
            'slug' => 'eden-nature-park', 'name' => 'Eden Nature Park', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range',
        ]);
    }

    private function tourist(string $alias = 'explorer1'): TouristAccount
    {
        return TouristAccount::create(['alias' => $alias, 'password_hash' => Hash::make('password123')]);
    }

    public function test_a_tourist_can_save_a_destination(): void
    {
        $destination = $this->destination();
        $this->actingAs($this->tourist(), 'tourist');

        $this->post(route('account.saved.toggle', ['destinations', $destination->id]))->assertRedirect();

        $this->assertSame(1, TouristSavedDestination::count());
    }

    public function test_toggling_again_removes_it(): void
    {
        $destination = $this->destination();
        $this->actingAs($this->tourist(), 'tourist');

        $this->post(route('account.saved.toggle', ['destinations', $destination->id]));
        $this->post(route('account.saved.toggle', ['destinations', $destination->id]));

        $this->assertSame(0, TouristSavedDestination::count());
    }

    public function test_a_duplicate_save_is_prevented_by_the_toggle_itself(): void
    {
        $destination = $this->destination();
        $this->actingAs($this->tourist(), 'tourist');

        $this->post(route('account.saved.toggle', ['destinations', $destination->id]));

        $this->assertDatabaseCount('tourist_saved_destinations', 1);
    }

    public function test_a_tourist_only_sees_their_own_saved_destinations(): void
    {
        $destination = $this->destination();
        $owner = $this->tourist('owner');
        $this->actingAs($owner, 'tourist');
        $this->post(route('account.saved.toggle', ['destinations', $destination->id]));

        // Clears the owner's flash toast ("Eden Nature Park is now in your
        // list") -- otherwise it would carry over into this next request and
        // make the page contain that name for a reason unrelated to what
        // this test is actually checking.
        session()->forget(['status', 'status_detail']);

        $other = $this->tourist('other');
        $this->actingAs($other, 'tourist');

        $this->get(route('account.saved'))->assertOk()->assertDontSee('Eden Nature Park');
    }

    public function test_a_guest_cannot_reach_the_account_saved_places_page(): void
    {
        $this->get(route('account.saved'))->assertRedirect(route('account.login'));
    }

    /**
     * The pre-existing anonymous heart button must keep working exactly as
     * before for a visitor with no tourist account -- this feature must not
     * interfere with it.
     */
    public function test_the_anonymous_save_flow_is_completely_unaffected(): void
    {
        $destination = $this->destination();

        $this->post(route('saved.toggle', ['destinations', $destination->id]))->assertRedirect();

        $this->assertSame(1, SavedListing::count());
        $this->assertSame(0, TouristSavedDestination::count());
    }

    /** Anonymous saves and account saves are deliberately never merged. */
    public function test_anonymous_saves_are_not_merged_into_a_new_account(): void
    {
        $destination = $this->destination();

        $this->post(route('saved.toggle', ['destinations', $destination->id]));

        $this->post(route('account.register'), [
            'alias' => 'explorer1', 'password' => 'password123', 'password_confirmation' => 'password123',
        ]);

        $this->assertSame(1, SavedListing::count());
        $this->assertSame(0, TouristSavedDestination::count());
    }
}
