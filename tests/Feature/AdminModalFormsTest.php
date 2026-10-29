<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Advisory;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Advisories, events and homepage slides: the same modal as the listings. Each form is still a page; the modal asks
 * for it on its own (?modal=1) and saves as JSON.
 */
class AdminModalFormsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create(['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
    }

    /** @return array<string, array{0: string}> */
    public static function forms(): array
    {
        return ['advisories' => ['advisories'], 'events' => ['events'], 'slides' => ['postcard-slides']];
    }

    #[DataProvider('forms')]
    public function test_each_form_comes_alone_for_the_modal_and_whole_as_a_page(string $area): void
    {
        $admin = $this->admin();

        $fragment = $this->actingAs($admin, 'admin')->get("/portal/admin/{$area}/create?modal=1")->assertOk()->getContent();
        $this->assertStringContainsString('<form method="POST"', $fragment);
        $this->assertStringContainsString('data-modal-close', $fragment, 'Cancel closes the modal.');
        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringNotContainsString('admin-sidebar', $fragment);

        $page = $this->actingAs($admin, 'admin')->get("/portal/admin/{$area}/create")->assertOk()->getContent();
        $this->assertStringContainsString('<html', $page);
        $this->assertStringContainsString('<form method="POST"', $page);
    }

    #[DataProvider('forms')]
    public function test_the_list_links_to_the_form_for_the_modal_to_open(string $area): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get("/portal/admin/{$area}")->assertOk()->getContent();

        $this->assertStringContainsString('data-modal-form', $html);
        $this->assertStringContainsString("/portal/admin/{$area}/create", $html, 'Add is a real link, so it works without JavaScript.');
    }

    public function test_the_dialog_and_its_script_are_in_the_admin_layout_once(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get('/portal/admin/events')->getContent();

        $this->assertSame(1, substr_count($html, 'id="adminModal"'));
        $this->assertSame(1, substr_count($html, 'admin-modal.js'));
    }

    public function test_an_event_saves_as_json_and_says_where_to_go(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->postJson('/portal/admin/events', [
            'title' => 'Kadayawan Night', 'category' => array_key_first(Event::CATEGORIES), 'starts_on' => '2026-11-20', 'location' => 'Davao City',
        ])->assertOk()->assertJson(['redirect' => route('admin.events.index')]);

        $this->assertDatabaseHas('events', ['title' => 'Kadayawan Night']);
        $response->assertSessionHas('status', 'Event added');
    }

    public function test_an_event_with_nothing_in_it_comes_back_as_json_errors(): void
    {
        $this->actingAs($this->admin(), 'admin')->postJson('/portal/admin/events', ['title' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['title', 'starts_on', 'location']);

        $this->assertDatabaseCount('events', 0);
    }

    public function test_an_advisory_saves_as_json_and_a_normal_post_still_redirects(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->postJson('/portal/admin/advisories', ['title' => 'Road closed', 'message' => 'Use the other road.', 'severity' => 'warning'])
            ->assertOk()->assertJson(['redirect' => route('admin.advisories.index')]);
        $this->assertDatabaseHas('advisories', ['title' => 'Road closed']);

        $this->actingAs($admin, 'admin')->post('/portal/admin/advisories', ['title' => 'Second', 'message' => 'Again.', 'severity' => 'info'])
            ->assertRedirect(route('admin.advisories.index'));
    }

    public function test_the_advisory_form_carries_the_script_that_fills_its_listing_list(): void
    {
        $fragment = $this->actingAs($this->admin(), 'admin')->get('/portal/admin/advisories/create?modal=1')->getContent();

        $this->assertStringContainsString('<script>', $fragment, 'The modal runs it after the form is loaded.');
        $this->assertStringContainsString('optionsByKind', $fragment);
    }

    public function test_editing_comes_filled_in_for_the_modal(): void
    {
        $admin = $this->admin();
        $event = Event::create(['slug' => 'e', 'title' => 'Old Fiesta', 'category' => array_key_first(Event::CATEGORIES), 'starts_on' => '2026-11-01', 'location' => 'Davao City']);
        $advisory = Advisory::create(['title' => 'Old notice', 'message' => 'M', 'severity' => 'info', 'admin_id' => $admin->id]);

        $this->assertStringContainsString('value="Old Fiesta"', $this->actingAs($admin, 'admin')->get("/portal/admin/events/{$event->id}/edit?modal=1")->getContent());
        $this->assertStringContainsString('value="Old notice"', $this->actingAs($admin, 'admin')->get("/portal/admin/advisories/{$advisory->id}/edit?modal=1")->getContent());
    }

    public function test_a_slide_form_posts_a_file_so_it_keeps_its_multipart_encoding(): void
    {
        $fragment = $this->actingAs($this->admin(), 'admin')->get('/portal/admin/postcard-slides/create?modal=1')->getContent();

        $this->assertStringContainsString('enctype="multipart/form-data"', $fragment);
    }

    public function test_a_slide_without_its_picture_comes_back_as_json_errors(): void
    {
        $this->actingAs($this->admin(), 'admin')->postJson('/portal/admin/postcard-slides', ['title' => 'No picture'])
            ->assertStatus(422);
    }
}
