<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Advisory;
use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * DOT asked for a manual way to warn travelers about conditions at a place
 * ("Mt. Apo is closed this season") or platform-wide, separate from
 * accreditation status. These tests guard that only a DOT Admin can post one,
 * that it reaches the right audience (one listing vs. everyone), and that a
 * scheduled window is honored.
 */
class AdminAdvisoryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'email' => 'a@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);
    }

    private function seedDestination(): Destination
    {
        $region = Region::create(['name' => 'Davao City']);

        return Destination::create([
            'slug' => 'mt-apo', 'name' => 'Mount Apo', 'location' => 'Davao del Sur',
            'region_id' => $region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 5, 'price_tier' => 'Budget-Friendly',
            'latitude' => 6.99, 'longitude' => 125.27, 'distance_km' => 65,
        ]);
    }

    public function test_a_guest_cannot_reach_the_advisories_console(): void
    {
        $this->get('/portal/admin/advisories')->assertRedirect('/portal/login');
    }

    public function test_an_admin_can_post_a_general_advisory(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/portal/admin/advisories', [
                'title' => 'Peak Season Advisory',
                'message' => 'Expect higher foot traffic at popular sites this weekend.',
                'severity' => 'info',
            ])
            ->assertRedirect(route('admin.advisories.index'));

        $advisory = Advisory::sole();
        $this->assertNull($advisory->listing_kind);
        $this->assertNull($advisory->listing_id);
        $this->assertSame('info', $advisory->severity);
    }

    public function test_an_admin_can_post_an_advisory_against_a_specific_listing(): void
    {
        $destination = $this->seedDestination();

        $this->actingAs($this->admin(), 'admin')
            ->post('/portal/admin/advisories', [
                'title' => 'Mt. Apo Closed This Season',
                'message' => 'Closed to hikers per DOT advisory.',
                'severity' => 'warning',
                'listing_kind' => 'destination',
                'listing_id' => $destination->id,
            ])
            ->assertRedirect(route('admin.advisories.index'));

        $advisory = Advisory::sole();
        $this->assertSame('destination', $advisory->listing_kind);
        $this->assertSame($destination->id, $advisory->listing_id);
        $this->assertTrue($advisory->listing->is($destination));
    }

    public function test_a_listing_id_for_an_unknown_listing_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/portal/admin/advisories', [
                'title' => 'Bad Reference',
                'message' => 'Points at nothing.',
                'severity' => 'warning',
                'listing_kind' => 'destination',
                'listing_id' => 999,
            ])
            ->assertStatus(422);

        $this->assertSame(0, Advisory::count());
    }

    public function test_an_admin_can_update_an_advisory(): void
    {
        $advisory = Advisory::create([
            'title' => 'Old Title', 'message' => 'Old message.', 'severity' => 'info',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->put("/portal/admin/advisories/{$advisory->id}", [
                'title' => 'New Title',
                'message' => 'Updated message.',
                'severity' => 'danger',
            ])
            ->assertRedirect(route('admin.advisories.index'));

        $advisory->refresh();
        $this->assertSame('New Title', $advisory->title);
        $this->assertSame('danger', $advisory->severity);
    }

    public function test_an_admin_can_remove_an_advisory(): void
    {
        $advisory = Advisory::create([
            'title' => 'Gone Soon', 'message' => 'Will be removed.', 'severity' => 'info',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->delete("/portal/admin/advisories/{$advisory->id}")
            ->assertRedirect();

        $this->assertSame(0, Advisory::count());
    }

    public function test_a_general_advisory_appears_on_the_homepage(): void
    {
        Advisory::create([
            'title' => 'Peak Season Advisory', 'message' => 'Busy this weekend.', 'severity' => 'info',
        ]);

        $this->get('/')->assertOk()->assertSee('Peak Season Advisory');
    }

    public function test_a_listing_advisory_appears_only_on_that_listings_page(): void
    {
        $destination = $this->seedDestination();
        $other = Destination::create([
            'slug' => 'other-place', 'name' => 'Other Place', 'location' => 'Davao City',
            'region_id' => $destination->region_id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 4.0, 'review_count' => 3, 'price_tier' => 'Budget-Friendly',
            'latitude' => 7.05, 'longitude' => 125.6, 'distance_km' => 5,
        ]);

        Advisory::create([
            'title' => 'Mt. Apo Closed This Season', 'message' => 'Closed to hikers.', 'severity' => 'warning',
            'listing_kind' => 'destination', 'listing_id' => $destination->id,
        ]);

        $this->get(route('destinations.show', $destination))->assertOk()->assertSee('Mt. Apo Closed This Season');
        $this->get(route('destinations.show', $other))->assertOk()->assertDontSee('Mt. Apo Closed This Season');
        $this->get('/')->assertOk()->assertDontSee('Mt. Apo Closed This Season');
    }

    public function test_an_advisory_outside_its_scheduled_window_does_not_show(): void
    {
        Advisory::create([
            'title' => 'Not Yet Live', 'message' => 'Scheduled for later.', 'severity' => 'info',
            'starts_at' => now()->addWeek(),
        ]);

        Advisory::create([
            'title' => 'Already Over', 'message' => 'Expired notice.', 'severity' => 'info',
            'ends_at' => now()->subDay(),
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('Not Yet Live', $html);
        $this->assertStringNotContainsString('Already Over', $html);
    }
}
