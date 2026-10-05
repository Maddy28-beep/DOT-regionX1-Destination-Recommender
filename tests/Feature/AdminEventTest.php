<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * DOT Admins manage the public events calendar themselves instead of through the seeder.
 */
class AdminEventTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'email' => 'a@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);
    }

    private function event(array $overrides = []): Event
    {
        return Event::create(array_merge([
            'title' => 'Kadayawan Festival', 'slug' => 'kadayawan-festival', 'category' => 'festival',
            'starts_on' => now()->addDays(10)->toDateString(), 'ends_on' => now()->addDays(12)->toDateString(),
            'location' => 'Rizal Park, Davao City',
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Davao Food Fair', 'category' => 'food', 'location' => 'SM Lanang',
            'starts_on' => now()->addDays(5)->toDateString(),
        ], $overrides);
    }

    public function test_a_guest_cannot_reach_the_events_console(): void
    {
        $this->get('/portal/admin/events')->assertRedirect('/portal/login');
        $this->post('/portal/admin/events', $this->payload())->assertRedirect('/portal/login');
        $this->assertSame(0, Event::count());
    }

    public function test_the_list_shows_upcoming_events_and_a_sidebar_entry(): void
    {
        $this->event();

        $this->actingAs($this->admin(), 'admin')->get('/portal/admin/events')->assertOk()
            ->assertSee('Kadayawan Festival')
            ->assertSee('Add Event')
            ->assertSee(route('admin.events.index'), false);
    }

    public function test_an_admin_can_add_an_event_that_reaches_the_public_calendar(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/portal/admin/events', $this->payload(['time_label' => '6:00 PM']))
            ->assertRedirect(route('admin.events.index'));

        $event = Event::sole();
        $this->assertSame('davao-food-fair', $event->slug);
        $this->assertSame('6:00 PM', $event->time_label);

        $this->get('/events')->assertOk()->assertSee('Davao Food Fair');
    }

    public function test_two_events_with_the_same_title_get_different_slugs(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->post('/portal/admin/events', $this->payload());
        $this->actingAs($admin, 'admin')->post('/portal/admin/events', $this->payload());

        $this->assertSame(['davao-food-fair', 'davao-food-fair-2'], Event::orderBy('id')->pluck('slug')->all());
    }

    public function test_validation_rejects_a_bad_category_and_an_end_before_the_start(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post('/portal/admin/events', $this->payload(['category' => 'parade']))
            ->assertSessionHasErrors('category');

        $this->actingAs($admin, 'admin')
            ->post('/portal/admin/events', $this->payload(['ends_on' => now()->subDay()->toDateString()]))
            ->assertSessionHasErrors('ends_on');

        $this->assertSame(0, Event::count());
    }

    public function test_an_admin_can_edit_an_event(): void
    {
        $event = $this->event();

        $this->actingAs($this->admin(), 'admin')
            ->put("/portal/admin/events/{$event->id}", $this->payload(['title' => 'Kadayawan 2026']))
            ->assertRedirect(route('admin.events.index'));

        $this->assertSame('Kadayawan 2026', $event->fresh()->title);
        $this->assertSame('kadayawan-festival', $event->fresh()->slug, 'The slug stays stable when the title changes.');
    }

    public function test_archiving_hides_an_event_from_the_public_and_it_can_be_restored(): void
    {
        $event = $this->event();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post("/portal/admin/events/{$event->id}/archive")->assertRedirect();
        $this->assertNotNull($event->fresh()->archived_at);
        $this->assertCount(0, $this->get('/events')->viewData('calendarEvents'));

        $this->actingAs($admin, 'admin')->get('/portal/admin/events?show=archived')->assertOk()->assertSee('Kadayawan Festival');

        $this->actingAs($admin, 'admin')->post("/portal/admin/events/{$event->id}/unarchive")->assertRedirect();
        $this->assertNull($event->fresh()->archived_at);
        $this->assertCount(1, $this->get('/events')->viewData('calendarEvents'));
    }

    public function test_past_events_have_their_own_tab(): void
    {
        $this->event();
        $this->event([
            'title' => 'Old Regatta', 'slug' => 'old-regatta',
            'starts_on' => now()->subDays(30)->toDateString(), 'ends_on' => now()->subDays(28)->toDateString(),
        ]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/portal/admin/events')->assertSee('Kadayawan Festival')->assertDontSee('Old Regatta');
        $this->actingAs($admin, 'admin')->get('/portal/admin/events?show=past')->assertSee('Old Regatta')->assertDontSee('Kadayawan Festival');
    }
}
