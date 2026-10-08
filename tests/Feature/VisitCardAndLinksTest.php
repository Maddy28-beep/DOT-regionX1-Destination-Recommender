<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\Region;
use App\Support\OpeningHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The destination "Plan your visit" card (open/closed pill, plan-to-spend wording, directions with a
 * save heart, "Find them online" tiles, a pointer to guides) and the DOT admin's four optional links.
 */
class VisitCardAndLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function place(array $extra = []): Destination
    {
        return Destination::create($extra + [
            'slug' => 'eden-nature-park', 'name' => 'Eden Nature Park', 'location' => 'Toril, Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Nature & Adventure',
            'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
            'hours' => '9:00 AM–5:00 PM', 'visit_duration' => '240 minutes', 'best_time' => 'Morning',
        ]);
    }

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin',
        ]);
    }

    private function manila(string $time): Carbon
    {
        return Carbon::parse('2026-10-09 '.$time, OpeningHours::TIMEZONE);
    }

    // ---- reading the hours and durations

    public function test_a_plain_daily_schedule_is_open_inside_the_window_and_closed_outside_it(): void
    {
        $this->assertTrue(OpeningHours::isOpenNow('9:00 AM–5:00 PM', $this->manila('09:00')));
        $this->assertTrue(OpeningHours::isOpenNow('9:00 AM–5:00 PM', $this->manila('16:59')));
        $this->assertFalse(OpeningHours::isOpenNow('9:00 AM–5:00 PM', $this->manila('17:00')));
        $this->assertFalse(OpeningHours::isOpenNow('9:00 AM–5:00 PM', $this->manila('08:59')));
    }

    public function test_it_uses_philippine_time_whatever_the_server_clock_says(): void
    {
        // 02:00 UTC is 10:00 in Manila
        $this->assertTrue(OpeningHours::isOpenNow('9:00 AM–5:00 PM', Carbon::parse('2026-10-09 02:00', 'UTC')));
        $this->assertFalse(OpeningHours::isOpenNow('9:00 AM–5:00 PM', Carbon::parse('2026-10-09 10:00', 'UTC')));
    }

    public function test_split_shifts_overnight_hours_and_round_the_clock_are_understood(): void
    {
        $split = '8:00 AM–12:00 PM; 1:00 PM–5:00 PM';
        $this->assertTrue(OpeningHours::isOpenNow($split, $this->manila('10:00')));
        $this->assertFalse(OpeningHours::isOpenNow($split, $this->manila('12:30')));

        $late = '6:00 PM–2:00 AM';
        $this->assertTrue(OpeningHours::isOpenNow($late, $this->manila('23:00')));
        $this->assertTrue(OpeningHours::isOpenNow($late, $this->manila('01:00')), 'The tail after midnight is still open.');
        $this->assertFalse(OpeningHours::isOpenNow($late, $this->manila('12:00')));

        $this->assertTrue(OpeningHours::isOpenNow('Open 24 hours', $this->manila('03:00')));
    }

    public function test_hours_that_are_not_a_plain_schedule_are_not_guessed_at(): void
    {
        foreach (['Mon–Sat 8:00 AM–5:00 PM', 'By arrangement', 'Contact establishment', '', null] as $text) {
            $this->assertNull(OpeningHours::isOpenNow($text, $this->manila('10:00')), (string) $text);
        }
    }

    public function test_minutes_become_plain_hours(): void
    {
        $this->assertSame('About 4 hours', OpeningHours::spendLabel('240 minutes'));
        $this->assertSame('About 1 hour', OpeningHours::spendLabel('60 minutes'));
        $this->assertSame('About 1.5 hours', OpeningHours::spendLabel('90 minutes'));
        $this->assertSame('About 45 minutes', OpeningHours::spendLabel('45 minutes'));
        $this->assertSame('2 days / 1 night', OpeningHours::spendLabel('2 days / 1 night'));
        $this->assertNull(OpeningHours::spendLabel(''));
    }

    // ---- the card

    public function test_the_card_shows_the_hours_pill_and_the_plan_to_spend_wording(): void
    {
        $place = $this->place();
        Carbon::setTestNow($this->manila('10:00'));

        $this->get(route('destinations.show', $place))->assertOk()
            ->assertSee('Before you go')
            ->assertSee('Plan your visit')
            ->assertSee('Open now')
            ->assertSee('About 4 hours')
            ->assertSee('Find DOT-accredited tour operators')
            ->assertSee(route('tour-operators.index'), false)
            ->assertSee('save-square', false);

        Carbon::setTestNow($this->manila('20:00'));
        $this->get(route('destinations.show', $place))->assertOk()->assertSee('Closed now')->assertDontSee('Open now');
    }

    public function test_no_pill_when_the_hours_cannot_be_read_and_closed_status_wins(): void
    {
        Carbon::setTestNow($this->manila('10:00'));

        $odd = $this->place(['hours' => 'By arrangement']);
        $this->get(route('destinations.show', $odd))->assertOk()
            ->assertSee('By arrangement')->assertDontSee('Open now')->assertDontSee('Closed now');

        $odd->forceFill(['hours' => '9:00 AM–5:00 PM', 'operating_status' => 'closed'])->save();
        $this->get(route('destinations.show', $odd))->assertOk()->assertSee('Closed now')->assertDontSee('Open now');
    }

    public function test_find_them_online_adapts_to_how_many_links_there_are(): void
    {
        $place = $this->place();

        $none = $this->get(route('destinations.show', $place))->getContent();
        $this->assertStringNotContainsString('Find them online', $none);

        $place->forceFill(['facebook_url' => 'https://www.facebook.com/eden'])->save();
        $one = $this->get(route('destinations.show', $place))->getContent();
        $this->assertStringContainsString('Facebook page', $one);
        $this->assertStringContainsString('is-single', $one);

        $place->forceFill(['website_url' => 'https://eden.example.com', 'instagram_url' => 'https://instagram.com/eden', 'tiktok_url' => 'https://tiktok.com/@eden'])->save();
        $four = $this->get(route('destinations.show', $place))->getContent();
        foreach (['<span>Website</span>', '<span>Facebook</span>', '<span>Instagram</span>', '<span>TikTok</span>'] as $label) {
            $this->assertStringContainsString($label, $four);
        }
        $this->assertSame(4, substr_count($four, 'target="_blank" rel="noopener noreferrer" class="find-online__link"'));
    }

    // ---- the DOT admin form

    public function test_the_admin_form_has_the_online_presence_section_for_every_listing_type(): void
    {
        $admin = $this->admin();

        foreach (['destinations', 'accommodations', 'restaurants', 'souvenir-centers', 'tour-operators', 'packages'] as $type) {
            $this->actingAs($admin, 'admin')->get("/portal/admin/listings/{$type}/create")->assertOk()
                ->assertSee('Online presence')
                ->assertSee('name="website_url"', false)
                ->assertSee('name="tiktok_url"', false);
        }
    }

    public function test_an_admin_can_save_change_and_clear_the_links(): void
    {
        $admin = $this->admin();
        $place = $this->place();
        $base = ['name' => 'Eden Nature Park', 'location' => 'Toril, Davao City'];

        $this->actingAs($admin, 'admin')->put("/portal/admin/listings/destinations/{$place->id}", $base + [
            'website_url' => 'https://eden.example.com', 'facebook_url' => 'https://www.facebook.com/eden',
            'instagram_url' => 'https://www.instagram.com/eden', 'tiktok_url' => 'https://www.tiktok.com/@eden',
        ])->assertRedirect();

        $place = $place->fresh();
        $this->assertSame('https://eden.example.com', $place->website_url);
        $this->assertSame('https://www.tiktok.com/@eden', $place->tiktok_url);

        $this->actingAs($admin, 'admin')->put("/portal/admin/listings/destinations/{$place->id}", $base + [
            'website_url' => '', 'facebook_url' => 'https://www.facebook.com/eden',
        ])->assertRedirect();

        $place = $place->fresh();
        $this->assertNull($place->website_url, 'An empty field clears the link.');
        $this->assertSame('https://www.facebook.com/eden', $place->facebook_url);
    }

    public function test_an_address_without_http_is_rejected_with_a_clear_message(): void
    {
        $admin = $this->admin();
        $place = $this->place();

        $this->actingAs($admin, 'admin')->put("/portal/admin/listings/destinations/{$place->id}", [
            'name' => 'Eden Nature Park', 'facebook_url' => 'www.facebook.com/eden', 'website_url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors(['facebook_url', 'website_url']);

        $this->assertStringContainsString('starting with http:// or https://', session('errors')->first('facebook_url'));
        $this->assertNull($place->fresh()->facebook_url);
    }

    public function test_the_save_heart_stays_a_single_form_on_the_card(): void
    {
        $place = $this->place();

        $html = $this->get(route('destinations.show', $place))->getContent();

        $this->assertSame(1, substr_count($html, 'save-form--square'));
    }
}
