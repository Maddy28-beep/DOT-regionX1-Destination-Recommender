<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureVisitorToken;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Region;
use App\Models\SavedListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Saving places survived the removal of traveler accounts: the heart is kept
 * against an opaque browser token instead of a person, so a visitor still gets
 * a shortlist without the site knowing who they are.
 */
class SavedListingsTest extends TestCase
{
    use RefreshDatabase;

    private Region $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->region = Region::create(['name' => 'Davao City']);
    }

    private function destination(string $slug = 'eden-nature-park', string $name = 'Eden Nature Park'): Destination
    {
        return Destination::create([
            'slug' => $slug, 'name' => $name, 'location' => 'Davao City',
            'region_id' => $this->region->id, 'type' => 'Nature & Leisure',
            'is_accredited' => true, 'rating' => 4.5, 'review_count' => 4,
            'price_tier' => 'Mid-range',
        ]);
    }

    private function accommodation(): Accommodation
    {
        return Accommodation::create([
            'slug' => 'seaside-inn', 'name' => 'Seaside Inn', 'location' => 'Davao City',
            'region_id' => $this->region->id, 'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 4.2, 'review_count' => 3, 'price_tier' => 'Mid-range',
        ]);
    }

    public function test_saving_needs_no_account(): void
    {
        $destination = $this->destination();

        $this->from(route('destinations.show', $destination))
            ->post(route('saved.toggle', ['destinations', $destination->id]))
            ->assertRedirect(route('destinations.show', $destination))
            ->assertSessionHas('status');

        $this->assertSame(1, SavedListing::count());
    }

    /** The saved row records a browser, not a person. */
    public function test_a_saved_row_holds_no_personal_data(): void
    {
        $destination = $this->destination();

        $this->post(route('saved.toggle', ['destinations', $destination->id]));

        $saved = SavedListing::sole();

        $this->assertSame('destination', $saved->listing_kind);
        $this->assertSame($destination->id, $saved->listing_id);
        $this->assertNotEmpty($saved->visitor_token);
        $this->assertArrayNotHasKey('tourist_id', $saved->getAttributes());
    }

    public function test_posting_again_unsaves(): void
    {
        $destination = $this->destination();
        $token = (string) Str::uuid();

        $this->withCookie(EnsureVisitorToken::COOKIE, $token)
            ->post(route('saved.toggle', ['destinations', $destination->id]));
        $this->assertSame(1, SavedListing::count());

        $this->withCookie(EnsureVisitorToken::COOKIE, $token)
            ->post(route('saved.toggle', ['destinations', $destination->id]));
        $this->assertSame(0, SavedListing::count(), 'The heart must toggle, not stack up rows.');
    }

    /** The heart is toggled client-side over fetch, without a page reload -- that
     *  path asks for JSON instead of following the redirect a plain form submit gets. */
    public function test_an_ajax_toggle_gets_json_instead_of_a_redirect(): void
    {
        $destination = $this->destination();
        $token = (string) Str::uuid();

        // postJson() drops cookies by default (it mirrors a cross-origin
        // fetch, which doesn't send them either) -- withCredentials() opts
        // back in, matching how the real same-origin fetch() in app.js
        // sends the visitor_token cookie automatically.
        $this->withCredentials();

        $response = $this->withCookie(EnsureVisitorToken::COOKIE, $token)
            ->postJson(route('saved.toggle', ['destinations', $destination->id]));

        $response->assertOk()->assertJson(['saved' => true, 'name' => $destination->name]);
        $this->assertSame(1, SavedListing::count());

        $response = $this->withCookie(EnsureVisitorToken::COOKIE, $token)
            ->postJson(route('saved.toggle', ['destinations', $destination->id]));

        $response->assertOk()->assertJson(['saved' => false]);
        $this->assertSame(0, SavedListing::count());
    }

    /** One browser must never see another browser's list. */
    public function test_a_list_is_private_to_the_browser_that_built_it(): void
    {
        $destination = $this->destination();
        $mine = (string) Str::uuid();
        $theirs = (string) Str::uuid();

        $this->withCookie(EnsureVisitorToken::COOKIE, $theirs)
            ->post(route('saved.toggle', ['destinations', $destination->id]));

        // Checked by link rather than by name: the flash message from the
        // save above survives into the next request's session, so the name
        // alone would match text that is not a listing.
        $html = $this->withCookie(EnsureVisitorToken::COOKIE, $mine)
            ->get(route('saved.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('destinations.show', $destination), $html);
        $this->assertStringContainsString('Nothing saved yet', $html);
    }

    /** All four saveable types land on the one page, grouped. */
    public function test_the_page_lists_every_saved_type(): void
    {
        $token = (string) Str::uuid();
        $destination = $this->destination();
        $accommodation = $this->accommodation();

        $this->withCookie(EnsureVisitorToken::COOKIE, $token)
            ->post(route('saved.toggle', ['destinations', $destination->id]));
        $this->withCookie(EnsureVisitorToken::COOKIE, $token)
            ->post(route('saved.toggle', ['accommodations', $accommodation->id]));

        $html = $this->withCookie(EnsureVisitorToken::COOKIE, $token)
            ->get(route('saved.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Eden Nature Park', $html);
        $this->assertStringContainsString('Seaside Inn', $html);
    }

    /** A listing that lost accreditation must not be saveable. */
    public function test_cannot_save_a_delisted_place(): void
    {
        $destination = $this->destination();
        $destination->update(['is_accredited' => false]);

        $this->post(route('saved.toggle', ['destinations', $destination->id]))->assertNotFound();

        $this->assertSame(0, SavedListing::count());
    }

    public function test_an_unknown_type_is_not_saveable(): void
    {
        $this->post('/saved/tour-operators/1')->assertNotFound();
    }

    /** The card in a listing grid renders the heart, reflecting saved state. */
    public function test_the_heart_renders_on_a_listing_grid_and_reflects_state(): void
    {
        $destination = $this->destination();
        $token = (string) Str::uuid();

        $before = $this->withCookie(EnsureVisitorToken::COOKIE, $token)
            ->get(route('destinations.index'))->assertOk()->getContent();

        $this->assertStringContainsString('save-heart', $before, 'Every card needs a heart.');
        $this->assertStringNotContainsString('save-heart is-saved', $before);

        $this->withCookie(EnsureVisitorToken::COOKIE, $token)
            ->post(route('saved.toggle', ['destinations', $destination->id]));

        $after = $this->withCookie(EnsureVisitorToken::COOKIE, $token)
            ->get(route('destinations.index'))->assertOk()->getContent();

        $this->assertStringContainsString('save-heart is-saved', $after,
            'A saved place must come back with a filled heart.');
    }

    /**
     * A form nested inside an anchor is invalid markup and the browser would
     * hoist it out, which silently breaks the button. The heart has to be a
     * sibling of the card link.
     */
    public function test_the_heart_form_is_not_nested_inside_the_card_link(): void
    {
        $this->destination();

        $html = $this->get(route('destinations.index'))->assertOk()->getContent();

        $cardStart = strpos($html, '<a href');
        $this->assertNotFalse($cardStart);

        preg_match('/<a[^>]*class="dpost-card"[^>]*>(.*?)<\/a>/s', $html, $card);
        $this->assertNotEmpty($card, 'Could not find the card link.');
        $this->assertStringNotContainsString('<form', $card[1],
            'The heart form must sit outside the card anchor.');
    }
}
