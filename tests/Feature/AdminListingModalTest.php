<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The add / edit form in a modal: the same routes, answering with just the form when asked and with JSON on save.
 */
class AdminListingModalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create(['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
    }

    private function region(): Region
    {
        return Region::create(['name' => 'Davao City']);
    }

    public function test_the_form_alone_is_sent_when_the_modal_asks_for_it(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get('/portal/admin/listings/destinations/create?modal=1')->assertOk()->getContent();

        $this->assertStringContainsString('<form method="POST"', $html);
        $this->assertStringContainsString('data-modal-close', $html, 'Cancel closes the modal.');
        $this->assertStringNotContainsString('<html', $html, 'No page around it.');
        $this->assertStringNotContainsString('admin-sidebar', $html);
    }

    public function test_without_the_flag_the_full_page_form_still_works(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get('/portal/admin/listings/destinations/create')->assertOk()->getContent();

        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('Add New Destination', $html);
        $this->assertStringContainsString('<form method="POST"', $html);
    }

    public function test_the_edit_form_comes_filled_in_for_the_modal(): void
    {
        $destination = Destination::create(['slug' => 'd', 'name' => 'Edenic Falls', 'location' => 'Davao City', 'region_id' => $this->region()->id, 'type' => 'Nature']);

        $html = $this->actingAs($this->admin(), 'admin')->get("/portal/admin/listings/destinations/{$destination->id}/edit?modal=1")->assertOk()->getContent();

        $this->assertStringContainsString('value="Edenic Falls"', $html);
        $this->assertStringContainsString('name="_method" value="PUT"', $html);
        $this->assertStringNotContainsString('<html', $html);
    }

    public function test_saving_as_json_says_where_to_go_and_keeps_the_toast_for_that_page(): void
    {
        $region = $this->region();

        $response = $this->actingAs($this->admin(), 'admin')
            ->postJson('/portal/admin/listings/destinations', ['name' => 'New Place', 'region_id' => $region->id, 'location' => 'Davao City'])
            ->assertOk()
            ->assertJson(['redirect' => route('admin.listings.index', 'destinations')]);

        $this->assertDatabaseHas('destinations', ['name' => 'New Place']);
        $response->assertSessionHas('status', 'Destination created');
    }

    public function test_a_validation_failure_comes_back_as_json_errors_for_the_modal_to_show(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->postJson('/portal/admin/listings/destinations', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseCount('destinations', 0);
    }

    public function test_a_normal_post_still_redirects_to_the_list(): void
    {
        $region = $this->region();

        $this->actingAs($this->admin(), 'admin')
            ->post('/portal/admin/listings/destinations', ['name' => 'Another', 'region_id' => $region->id, 'location' => 'Davao City'])
            ->assertRedirect(route('admin.listings.index', 'destinations'));
    }

    public function test_the_list_links_to_the_form_and_carries_the_modal(): void
    {
        $destination = Destination::create(['slug' => 'd', 'name' => 'Edenic Falls', 'location' => 'Davao City', 'region_id' => $this->region()->id, 'type' => 'Nature']);

        $html = $this->actingAs($this->admin(), 'admin')->get('/portal/admin/listings/destinations')->assertOk()->getContent();

        $this->assertStringContainsString('id="adminModal"', $html);
        $this->assertStringContainsString(route('admin.listings.create', 'destinations'), $html, 'Add is a real link, so it works without JavaScript.');
        $this->assertStringContainsString(route('admin.listings.edit', ['destinations', $destination->id]), $html);
        $this->assertStringContainsString('admin-modal.js', $html);
    }

    public function test_a_column_with_nothing_in_it_is_left_out(): void
    {
        Destination::create(['slug' => 'd', 'name' => 'Edenic Falls', 'location' => 'Davao City', 'region_id' => $this->region()->id, 'type' => 'Nature']);

        $admin = $this->admin();
        $html = $this->actingAs($admin, 'admin')->get('/portal/admin/listings/destinations')->getContent();

        $this->assertStringNotContainsString('<th>Price Tier</th>', $html);
        $this->assertStringNotContainsString('<th>Rating</th>', $html);

        Destination::query()->update(['price_tier' => 'Mid-range', 'review_count' => 3, 'rating' => 4.5]);
        $html = $this->actingAs($admin, 'admin')->get('/portal/admin/listings/destinations')->getContent();

        $this->assertStringContainsString('<th>Price Tier</th>', $html);
        $this->assertStringContainsString('<th>Rating</th>', $html);
    }
}
