<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A DOT Admin can say how a destination is used in generated itineraries
 * (sightseeing, optional, or not a sightseeing stop) from the listing form.
 */
class AdminDestinationRoleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'email' => 'role-admin@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);
    }

    private function club(): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create([
            'slug' => 'country-club', 'name' => 'Country Club', 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => 'Sports & Recreation', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Country Club', 'location' => 'Davao City', 'type' => 'Sports & Recreation', 'is_accredited' => 1], $overrides);
    }

    public function test_the_form_offers_the_three_roles_with_the_current_one_selected(): void
    {
        $club = $this->club();
        $club->update(['itinerary_role' => 'excluded']);

        $this->actingAs($this->admin(), 'admin')->get(route('admin.listings.edit', ['type' => 'destinations', 'id' => $club->id]))
            ->assertOk()
            ->assertSee('Use in generated itineraries')
            ->assertSee('Sightseeing attraction')
            ->assertSee('Optional (only if the traveller asks)')
            ->assertSee('value="excluded" selected', false);
    }

    public function test_an_admin_can_change_the_role(): void
    {
        $club = $this->club();

        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.listings.update', ['type' => 'destinations', 'id' => $club->id]), $this->payload(['itinerary_role' => 'excluded']))
            ->assertRedirect();

        $this->assertSame('excluded', $club->fresh()->itinerary_role);
    }

    public function test_a_blank_or_missing_role_leaves_it_unchanged(): void
    {
        $club = $this->club();
        $club->update(['itinerary_role' => 'optional']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.listings.update', ['type' => 'destinations', 'id' => $club->id]), $this->payload(['itinerary_role' => '']))
            ->assertRedirect();
        $this->assertSame('optional', $club->fresh()->itinerary_role);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.listings.update', ['type' => 'destinations', 'id' => $club->id]), $this->payload())
            ->assertRedirect();
        $this->assertSame('optional', $club->fresh()->itinerary_role);
    }

    public function test_an_unknown_role_is_rejected(): void
    {
        $club = $this->club();

        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.listings.update', ['type' => 'destinations', 'id' => $club->id]), $this->payload(['itinerary_role' => 'vip']))
            ->assertSessionHasErrors('itinerary_role');
    }

    public function test_a_new_destination_defaults_to_sightseeing(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.listings.store', ['type' => 'destinations']), $this->payload(['name' => 'New Falls']))
            ->assertRedirect();

        $this->assertSame('sightseeing', Destination::where('name', 'New Falls')->value('itinerary_role'));
    }
}
