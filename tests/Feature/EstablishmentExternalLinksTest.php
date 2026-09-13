<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Establishment-managed external links (Official Website / Facebook /
 * Instagram / TikTok) -- optional fields an establishment fills in from its
 * own dashboard, shown on the public listing page only when present.
 */
class EstablishmentExternalLinksTest extends TestCase
{
    use RefreshDatabase;

    private function partner(): EstablishmentAccount
    {
        $listing = Destination::create([
            'slug' => 'link-test-falls', 'name' => 'Link Test Falls', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Waterfall', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0,
        ]);

        return EstablishmentAccount::create([
            'business_name' => 'Link Test Falls', 'listing_kind' => 'destination',
            'matched_listing_id' => $listing->id, 'portal_key' => (string) Str::uuid(),
            'email' => 'links@test.example.com', 'password_hash' => Hash::make('x'),
            'contact_person' => 'Tester', 'contact_number' => '09170000000',
            'status' => 'approved', 'submitted_at' => now(),
        ]);
    }

    public function test_an_establishment_can_save_all_four_links(): void
    {
        $partner = $this->partner();

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', [
                'website_url' => 'https://www.linktestfalls.example.com',
                'facebook_url' => 'https://facebook.com/linktestfalls',
                'instagram_url' => 'https://instagram.com/linktestfalls',
                'tiktok_url' => 'https://tiktok.com/@linktestfalls',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $listing = $partner->matchedListing()->first();
        $this->assertSame('https://www.linktestfalls.example.com', $listing->website_url);
        $this->assertSame('https://facebook.com/linktestfalls', $listing->facebook_url);
        $this->assertSame('https://instagram.com/linktestfalls', $listing->instagram_url);
        $this->assertSame('https://tiktok.com/@linktestfalls', $listing->tiktok_url);
    }

    public function test_all_four_links_are_optional(): void
    {
        $partner = $this->partner();

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', [])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $listing = $partner->matchedListing()->first();
        $this->assertNull($listing->website_url);
        $this->assertNull($listing->facebook_url);
    }

    /** A malformed URL must never be saved -- garbage in, refused, not stored. */
    public function test_a_malformed_url_is_rejected(): void
    {
        $partner = $this->partner();

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', [
                'website_url' => 'not a url',
            ])
            ->assertSessionHasErrors('website_url');

        $this->assertNull($partner->matchedListing()->first()->website_url);
    }

    /** A URL without http/https (e.g. javascript:) must never be saved. */
    public function test_a_non_http_scheme_is_rejected(): void
    {
        $partner = $this->partner();

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', [
                'facebook_url' => 'javascript:alert(1)',
            ])
            ->assertSessionHasErrors('facebook_url');

        $this->assertNull($partner->matchedListing()->first()->facebook_url);
    }

    public function test_saving_links_leaves_other_listing_fields_alone(): void
    {
        $partner = $this->partner();

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', [
                'description' => 'A quiet waterfall outside the city.',
                'website_url' => 'https://www.linktestfalls.example.com',
            ])
            ->assertSessionHasNoErrors();

        $listing = $partner->matchedListing()->first();
        $this->assertSame('A quiet waterfall outside the city.', $listing->description);
        $this->assertSame('https://www.linktestfalls.example.com', $listing->website_url);
    }

    public function test_public_page_shows_website_button_when_only_website_is_set(): void
    {
        $partner = $this->partner();
        $partner->matchedListing()->first()->forceFill(['website_url' => 'https://www.linktestfalls.example.com'])->save();

        $html = $this->get('/destinations/link-test-falls')->assertOk()->getContent();

        $this->assertStringContainsString('Visit Official Website', $html);
        $this->assertStringNotContainsString('Visit Facebook Page', $html);
    }

    public function test_public_page_shows_facebook_button_when_only_facebook_is_set(): void
    {
        $partner = $this->partner();
        $partner->matchedListing()->first()->forceFill(['facebook_url' => 'https://facebook.com/linktestfalls'])->save();

        $html = $this->get('/destinations/link-test-falls')->assertOk()->getContent();

        $this->assertStringNotContainsString('Visit Official Website', $html);
        $this->assertStringContainsString('Visit Facebook Page', $html);
    }

    public function test_public_page_shows_both_buttons_when_both_are_set(): void
    {
        $partner = $this->partner();
        $partner->matchedListing()->first()->forceFill([
            'website_url' => 'https://www.linktestfalls.example.com',
            'facebook_url' => 'https://facebook.com/linktestfalls',
        ])->save();

        $html = $this->get('/destinations/link-test-falls')->assertOk()->getContent();

        $this->assertStringContainsString('Visit Official Website', $html);
        $this->assertStringContainsString('Visit Facebook Page', $html);
    }

    public function test_public_page_hides_the_section_entirely_when_no_links_are_set(): void
    {
        $this->partner();

        $html = $this->get('/destinations/link-test-falls')->assertOk()->getContent();

        $this->assertStringNotContainsString('Visit Official Website', $html);
        $this->assertStringNotContainsString('Visit Facebook Page', $html);
        $this->assertStringNotContainsString('listing-links', $html);
    }

    public function test_links_open_in_a_new_tab_with_noopener_noreferrer(): void
    {
        $partner = $this->partner();
        $partner->matchedListing()->first()->forceFill([
            'website_url' => 'https://www.linktestfalls.example.com',
            'instagram_url' => 'https://instagram.com/linktestfalls',
        ])->save();

        $html = $this->get('/destinations/link-test-falls')->assertOk()->getContent();

        $this->assertStringContainsString('href="https://www.linktestfalls.example.com" target="_blank" rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('href="https://instagram.com/linktestfalls" target="_blank" rel="noopener noreferrer"', $html);
    }

    /** Check In Here must remain the real check-in action, never an external link. */
    public function test_check_in_here_still_points_at_the_check_in_route_not_an_external_link(): void
    {
        $partner = $this->partner();
        $listing = $partner->matchedListing()->first();
        $listing->forceFill(['website_url' => 'https://www.linktestfalls.example.com'])->save();

        $html = $this->get('/destinations/link-test-falls')->assertOk()->getContent();

        $this->assertStringContainsString('Check In Here', $html);
        $this->assertStringContainsString(route('check-in', ['type' => 'destinations', 'id' => $listing->id]), $html);
    }

    public function test_a_guest_cannot_update_the_listing(): void
    {
        $this->partner();

        $this->put('/portal/establishment/listing', [
            'website_url' => 'https://www.linktestfalls.example.com',
        ])->assertRedirect('/portal/login');
    }
}
